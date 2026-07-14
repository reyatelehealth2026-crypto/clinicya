/**
 * preview.ts — presentation-layer port of inbox-v2.php's getMessagePreview()
 * (lines 1169-1188) and formatThaiTime() (lines 1211-1233).
 *
 * IMPORTANT — these are NOT part of the JSON wire contract. The
 * `last_message_preview`/`last_message_type` fields returned by
 * classes/InboxService.php::getConversationsDelta() (and therefore by
 * ../_lib/query.ts and the Route Handler) are the RAW, un-truncated
 * `SUBSTRING(content, 1, 100)` + `message_type` values — getMessagePreview()
 * is only ever applied when RENDERING a row into HTML (inbox-v2.php line
 * 3125's SSR template, and ConversationLoader's own JS-side duplicate at
 * lines 11792-11801 for AJAX-appended rows). Likewise `last_message_at` is
 * returned raw over the wire; formatThaiTime() is a display-only transform
 * (inbox-v2.php line 3121 / ConversationLoader lines 11803-11830). So these
 * helpers belong to the UI layer (_components/ConversationListItem.tsx),
 * not to query.ts/route.ts.
 *
 * Both PHP functions run under PHP's process-wide `date_default_timezone_set
 * ('Asia/Bangkok')` (CLAUDE.md: "Timezone is always Asia/Bangkok"). This port
 * does the equivalent explicitly (fixed +7h offset, Bangkok has no DST) so it
 * is correct regardless of the Node process's own TZ — it never reads the
 * host machine's local time.
 *
 * getMessagePreview()'s PHP `mb_strlen`/`mb_substr` count Unicode CODEPOINTS
 * (not UTF-16 code units) — replicated via `Array.from()` so a message
 * containing astral-plane characters (e.g. some emoji) truncates at the same
 * boundary PHP does, not at a UTF-16 surrogate half.
 */

const BANGKOK_OFFSET_MS = 7 * 60 * 60 * 1000;

// Space separator ONLY (matches _lib/query.ts's `DATE_FORMAT(..., '%Y-%m-%d %H:%i:%s')`
// output exactly). Deliberately does NOT match a 'T' separator or a
// trailing 'Z'/offset — those are already-absolute ISO instants and must
// fall through to `new Date(input)` below instead, or a value that happens
// to be an already-absolute UTC instant would be misread as Bangkok-local
// and double-shifted by +7h.
const NAIVE_DATETIME_RE = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/;

/**
 * Resolves a datetime value to a true (UTC) epoch millisecond timestamp.
 *
 * A bare `YYYY-MM-DD HH:MM:SS` string (what MySQL/query.ts hands back — the
 * tenant connection runs `SET time_zone = '+07:00'`, so this is already
 * Bangkok wall-clock time with no zone suffix) is treated as Bangkok-local
 * and converted to a true UTC epoch by subtracting the fixed +7h offset.
 * Anything else (an ISO string with an explicit offset/`Z`, or a `Date`) is
 * parsed as an already-absolute instant.
 */
function toTrueEpochMs(input: string): number | null {
  const naive = NAIVE_DATETIME_RE.exec(input);
  if (naive) {
    const [, y, mo, d, h, mi, s] = naive;
    const bangkokWallClockAsUtcMs = Date.UTC(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi), Number(s));
    return bangkokWallClockAsUtcMs - BANGKOK_OFFSET_MS;
  }
  const parsed = new Date(input);
  return Number.isNaN(parsed.getTime()) ? null : parsed.getTime();
}

interface BangkokParts {
  y: number;
  mo: number; // 1-12
  d: number;
  h: number;
  mi: number;
  /** PHP date('w') semantics: 0 = Sunday .. 6 = Saturday. */
  dow: number;
}

/** Reads the Bangkok wall-clock calendar/time parts of a true epoch, without depending on host TZ. */
function bangkokParts(trueEpochMs: number): BangkokParts {
  const shifted = new Date(trueEpochMs + BANGKOK_OFFSET_MS);
  return {
    y: shifted.getUTCFullYear(),
    mo: shifted.getUTCMonth() + 1,
    d: shifted.getUTCDate(),
    h: shifted.getUTCHours(),
    mi: shifted.getUTCMinutes(),
    dow: shifted.getUTCDay(),
  };
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

const THAI_DAY_ABBREV = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

/**
 * Port of inbox-v2.php's formatThaiTime($datetime) (lines 1211-1233).
 *
 * @param datetime last_message_at as returned by query.ts (bare
 *   'YYYY-MM-DD HH:MM:SS', Bangkok wall-clock) or any Date-parseable string.
 * @param now injectable clock (epoch ms or Date) — defaults to the real
 *   current time; tests should always pass a fixed value.
 */
export function formatThaiTime(datetime: string | null | undefined, now: Date | number = new Date()): string {
  if (!datetime) {
    return '';
  }
  const epoch = toTrueEpochMs(datetime);
  if (epoch === null) {
    return '';
  }
  const nowEpoch = typeof now === 'number' ? now : now.getTime();
  const diffSeconds = Math.floor((nowEpoch - epoch) / 1000);

  const target = bangkokParts(epoch);
  const nowParts = bangkokParts(nowEpoch);

  if (target.y === nowParts.y && target.mo === nowParts.mo && target.d === nowParts.d) {
    return `${pad2(target.h)}:${pad2(target.mi)} น.`;
  }

  // "yesterday" = today's Bangkok calendar date minus 1 day (calendar
  // subtraction on the already-extracted Y/M/D parts, not a raw 24h diff —
  // mirrors PHP's date('Y-m-d', strtotime('-1 day')) comparison exactly).
  const yesterday = new Date(Date.UTC(nowParts.y, nowParts.mo - 1, nowParts.d - 1));
  if (target.y === yesterday.getUTCFullYear() && target.mo === yesterday.getUTCMonth() + 1 && target.d === yesterday.getUTCDate()) {
    return `เมื่อวาน ${pad2(target.h)}:${pad2(target.mi)}`;
  }

  if (diffSeconds < 604800) {
    return `${THAI_DAY_ABBREV[target.dow]} ${pad2(target.h)}:${pad2(target.mi)}`;
  }

  return `${pad2(target.d)}/${pad2(target.mo)} ${pad2(target.h)}:${pad2(target.mi)}`;
}

const TYPE_PREVIEW: Record<string, string> = {
  image: '📷 รูปภาพ',
  video: '🎥 วิดีโอ',
  audio: '🎵 เสียง',
  location: '📍 ตำแหน่งที่อยู่',
  file: '📄 ไฟล์',
  sticker: '😊 สติกเกอร์',
  flex: '📋 Flex',
};

const TRUNCATE_AT = 30;

/**
 * Port of inbox-v2.php's getMessagePreview($content, $type) (lines 1169-1188).
 * `content === null` (strict, matching PHP's `=== null`) short-circuits to
 * '' — an empty string content (not null) falls through to the type-based
 * branches same as PHP.
 */
export function getMessagePreview(content: string | null | undefined, type: string | null | undefined): string {
  if (content === null || content === undefined) {
    return '';
  }
  if (type !== null && type !== undefined && Object.prototype.hasOwnProperty.call(TYPE_PREVIEW, type)) {
    return TYPE_PREVIEW[type]!;
  }
  const codepoints = Array.from(content);
  return codepoints.length > TRUNCATE_AT ? `${codepoints.slice(0, TRUNCATE_AT).join('')}...` : content;
}
