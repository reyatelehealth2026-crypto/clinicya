/**
 * Auto-reply matcher — TypeScript port of `webhook.php`'s `checkAutoReply()` (webhook.php:2062-2254)
 * and its helper `addShareButtonToFlex()` (webhook.php:2263-2309).
 * (docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 6 / Phase 12, risk #1 / #4.)
 *
 * SCOPE (see the mig-line porting brief): this file ports the PURE decision logic only —
 * matching a rule against inbound text, and building the LINE message object for a matched
 * rule. It does NOT fetch rules from a database, does NOT send anything, and does NOT perform
 * the `use_count`/`last_used_at` UPDATE side effect (see the `// DEFERRED:` comment below). The
 * DB fetch is captured only as documented SQL-string constants + an injectable fetcher type
 * (`AUTO_REPLY_RULES_SQL_SCOPED`, `AUTO_REPLY_RULES_SQL_GLOBAL`, `AutoReplyRuleFetcher`) — wiring
 * a real implementation is a future Phase 6 webhook Route Handler's job, not this file's.
 *
 * Zero @reya/* / @reya/line-internal dependencies by design (matches flex.ts's and api.ts's own
 * isolation notes) — this file does not import from ./flex or ./api, and nothing here touches a
 * live database, HTTP client, or Next.js route.
 *
 * PARITY NOTES (read before touching this file):
 *
 * 1. Exact-once-match short-circuit (the "landmine" called out in the porting brief): PHP's
 *    `foreach ($rules as $rule) { ...; if ($matched) { ...build...; return $message; } }` returns
 *    IMMEDIATELY on the first rule whose `match_type` test passes — even if building that rule's
 *    message then fails (bad Flex JSON -> `$message` stays null -> `return null`). It NEVER falls
 *    through to try a lower-priority rule that would also have matched. `resolveAutoReply()` below
 *    preserves this exactly: `matchAutoReplyRule()` finds the first matching rule, and
 *    `buildAutoReplyReply()` is called exactly once on it, with its result (even `null`) returned
 *    as-is. See `tests/auto-reply.test.ts`'s dedicated short-circuit test and the
 *    `reply-flex-invalid-json-no-fallback` fixture, which pairs a matching-but-bad-JSON rule with
 *    a second, lower-priority rule that WOULD also have matched.
 *
 * 2. `match_type` values: the `auto_replies.match_type` column is declared
 *    `enum('exact','contains','starts_with','regex')` in
 *    database/install_complete_latest.sql:483-505 (checked against this exact line range, NOT the
 *    unrelated `auto_reply_rules` table at line 510, which uses different column names —
 *    `response_type`/`response_content` instead of `reply_type`/`reply_content` — and is not what
 *    `checkAutoReply()` reads). `checkAutoReply()`'s `switch` statement additionally handles a
 *    `'all'` case (webhook.php:2093-2095) that the base schema's enum does not declare — ported
 *    faithfully anyway, since the switch is what actually runs regardless of what the enum
 *    currently allows a fresh INSERT to contain.
 *
 * 3. PHP's `mb_strtolower()` / `mb_stripos()` (exact/contains/starts_with) do NOT trim whitespace.
 *    `'สวัสดี '` (trailing space) does NOT exact-match `'สวัสดี'`. See the
 *    `match-exact-trailing-whitespace-no-trim` fixture, which pins this.
 *
 * 4. `?? null` / `?? false` / `?? 'default'` chains below mirror PHP's `??` (null-coalescing,
 *    substitutes only on null/unset — never on `0`/`''`/`false`) 1:1 with TS's own `??` operator,
 *    which has identical semantics. PHP's bare truthiness (`if ($x)`, `empty($x)`) is different —
 *    it additionally treats `0`, `''`, `'0'`, `false`, and `[]` as falsy — so those call sites use
 *    the local `phpTruthy()`/`phpFalsy()` helpers instead (see flex.ts's identical helpers; not
 *    imported from there — see the isolation note above).
 */

// ---------------------------------------------------------------------------------------------
// Injectable DB-fetch contract (NOT implemented here — see module doc point above).
// ---------------------------------------------------------------------------------------------

/**
 * Byte-for-byte copy of the tenant-scoped SELECT at webhook.php:2066 (`$lineAccountId` truthy
 * branch): rows for this `line_account_id` OR global rules (`line_account_id IS NULL`), ranked
 * account-specific-first via `ORDER BY line_account_id DESC` (non-NULL sorts before NULL in the
 * database's DESC ordering), then by `priority DESC` within each group.
 */
export const AUTO_REPLY_RULES_SQL_SCOPED =
  'SELECT * FROM auto_replies WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY line_account_id DESC, priority DESC';

/**
 * Byte-for-byte copy of the no-account-given SELECT at webhook.php:2069 (`$lineAccountId` falsy
 * branch): every active rule regardless of account, ordered by `priority DESC` only.
 */
export const AUTO_REPLY_RULES_SQL_GLOBAL = 'SELECT * FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC';

/**
 * Documents the contract a future live wiring must satisfy: given a `lineAccountId` (or `null`
 * for the global-only query), return the already-DB-sorted `AutoReplyRuleRow[]` — i.e. run
 * `AUTO_REPLY_RULES_SQL_SCOPED` (with `lineAccountId`) or `AUTO_REPLY_RULES_SQL_GLOBAL` (without)
 * exactly as webhook.php:2064-2071 does, fetched as plain associative rows. Deliberately NOT
 * implemented in this package (no live DB access — see the "Do not" list in the porting brief);
 * `matchAutoReplyRule` / `resolveAutoReply` below take the already-fetched, already-sorted array
 * as a plain parameter.
 */
export type AutoReplyRuleFetcher = (lineAccountId: number | null) => Promise<AutoReplyRuleRow[]>;

// ---------------------------------------------------------------------------------------------
// AutoReplyRuleRow — mirrors the `auto_replies` table row exactly (database/install_complete_
// latest.sql:483-505). Types reflect what the project's DB driver actually returns for this
// schema under this project's connection settings (see modules/Core/Database.php): numeric
// columns come back as native PHP types (int/float), not stringified.
// ---------------------------------------------------------------------------------------------

export type AutoReplyMatchType = 'exact' | 'contains' | 'starts_with' | 'regex' | 'all';

export interface AutoReplyRuleRow {
  id: number;
  line_account_id: number | null;
  keyword: string;
  match_type: AutoReplyMatchType;
  reply_type: string;
  reply_content: string;
  alt_text: string | null;
  sender_name: string | null;
  sender_icon: string | null;
  /** Raw JSON text (or null) — decoded internally by `buildAutoReplyReply()`, mirroring PHP's
   *  `json_decode($rule['quick_reply'], true)`. Not pre-parsed here, matching the DB row shape. */
  quick_reply: string | null;
  enable_share: boolean | number | null;
  share_button_label: string | null;
  /** Documentation only — already filtered by `WHERE is_active = 1` in both SQL constants above
   *  before a row ever reaches `matchAutoReplyRule()`/`buildAutoReplyReply()`; neither function
   *  reads this field. */
  is_active: boolean | number;
  /** Documentation only — already applied via `ORDER BY ... priority DESC` in both SQL constants
   *  above; `matchAutoReplyRule()` trusts the given array order as-is and never re-sorts it. */
  priority: number;
}

// ---------------------------------------------------------------------------------------------
// PHP-semantics helpers (local copies — see the module doc's isolation note; flex.ts has its own
// identical `phpFalsy`/`phpTruthy` pair for the same reason).
// ---------------------------------------------------------------------------------------------

/** Mirrors PHP's falsy set for `empty($x)` / bare `if ($x)`: null/undefined, false, 0, '', '0', []. */
function phpFalsy(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === 0 || value === '') return true;
  if (value === '0') return true;
  if (Array.isArray(value) && value.length === 0) return true;
  return false;
}

/** Mirrors PHP's `!empty($x)` / bare `if ($x)` truthiness check. */
function phpTruthy(value: unknown): boolean {
  return !phpFalsy(value);
}

/** Mirrors PHP's `mb_strtolower()` closely enough for this module's purposes (Thai has no case,
 *  so it is unaffected either way; ASCII/Latin case-folding is what `match_type = 'exact'` and
 *  `'contains'`/`'starts_with'` actually rely on in practice). */
function mbStrToLower(value: string): string {
  return value.toLowerCase();
}

/** Mirrors PHP's `mb_stripos($haystack, $needle)`: case-insensitive substring search, returning
 *  the 0-based index of the first match, or `false` when `$needle` is not found. */
function mbStriPos(haystack: string, needle: string): number | false {
  const index = haystack.toLowerCase().indexOf(needle.toLowerCase());
  return index === -1 ? false : index;
}

/**
 * Detects an unescaped `/` inside `pattern` — mirroring PHP's PCRE hard-delimiter behavior.
 *
 * PHP builds the actual delimited pattern as `'/' . $rule['keyword'] . '/i'` (webhook.php:2085):
 * `/` is PCRE's DELIMITER here, not a literal character, so the FIRST unescaped `/` inside
 * `pattern` closes the pattern early — everything after it (including PHP's own appended `/i`
 * suffix) is then reinterpreted as a PCRE *modifier* string, not pattern body. A modifier string
 * can never legally contain a `/` character, and there is always at least one `/` left over in
 * that trailing region (either the rest of `pattern` after the early close, or PHP's own
 * appended `/i` suffix, or both) — so the modifier parse ALWAYS fails ("Unknown modifier" /
 * PREG_INTERNAL_ERROR) and `preg_match()` ALWAYS returns `false` for any such pattern. A `/` only
 * counts as escaped when it is preceded by an ODD number of consecutive backslashes (`\/` escapes
 * it; `\\/` does not — the backslash itself is what's escaped there). Verified byte-for-byte
 * against `php -r` for an unescaped `/` (e.g. keyword `500mg/tab`, matching text containing that
 * literal substring — real PHP returns `bool(false)`), an escaped `\/` (compiles fine, real PHP
 * matches normally), and an escaped-backslash-then-`/` (`a\\/b` — the `/` is unescaped again and
 * DOES break, real PHP returns `false`) — see the build report.
 */
function hasUnescapedForwardSlash(pattern: string): boolean {
  let backslashRun = 0;
  for (const ch of pattern) {
    if (ch === '\\') {
      backslashRun++;
      continue;
    }
    if (ch === '/' && backslashRun % 2 === 0) {
      return true;
    }
    backslashRun = 0;
  }
  return false;
}

/**
 * Mirrors PHP's `preg_match('/' . $pattern . '/i', $text)` INCLUDING its failure mode: PHP's
 * `preg_match()` returns `false` (and only emits a non-fatal `E_WARNING`) when `$pattern` fails
 * to compile — it never throws. JS's `new RegExp(pattern)` throws a `SyntaxError` for the
 * equivalent malformed pattern instead, so the try/catch below is what makes
 * `matchAutoReplyRule()` behave like PHP's non-throwing `false` return (task brief: "make an
 * invalid pattern behave as PHP does: a non-match, not a thrown error").
 *
 * DELIMITER PARITY: unlike PHP, JS's `new RegExp()` takes a bare pattern body with no surrounding
 * delimiter, so an unescaped `/` inside `pattern` is a perfectly ordinary, VALID regex character
 * to `RegExp` — it would happily compile and (for realistic keywords like dosages, fractions, or
 * URL fragments containing `/`) MATCH, where real PHP's `preg_match('/' . pattern . '/i', text)`
 * always fails to compile and returns `false` for the exact same input (see
 * `hasUnescapedForwardSlash()` above). The `hasUnescapedForwardSlash()` guard below closes that
 * gap by short-circuiting to `false` before ever constructing a `RegExp` — reproducing PHP's
 * always-false-on-unescaped-slash outcome instead of JS's would-otherwise-match one.
 *
 * SECURITY: `pattern` is `rule.keyword` — a raw, user-controlled regex *pattern body* reaching
 * this call with ZERO escaping, validation, or sandboxing, exactly like PHP's own
 * `preg_match('/' . $rule['keyword'] . '/i', $text)` at webhook.php:2085. Any admin-DB-level
 * writer of an `auto_replies` row (or a compromised admin panel) can plant a
 * catastrophic-backtracking pattern (classic ReDoS, e.g. `(a+)+$`) that hangs whatever process
 * evaluates it against attacker-controlled `text`. This is DELIBERATE parity with the PHP source,
 * not an oversight — the porting brief requires matching PHP's behavior exactly, including its
 * total lack of pattern validation/sandboxing. Hardening (a pattern allowlist, a regex-engine
 * timeout, precompilation checks at admin-save time, etc.) is explicitly out of scope for this
 * port and must be designed separately by whoever wires `matchAutoReplyRule()` to a live,
 * DB-backed, network-facing caller.
 */
function safeRegexTest(pattern: string, text: string): boolean {
  if (hasUnescapedForwardSlash(pattern)) {
    // See hasUnescapedForwardSlash()'s doc comment: real PHP always returns false here.
    return false;
  }
  try {
    return new RegExp(pattern, 'i').test(text);
  } catch {
    return false;
  }
}

/** Mirrors PHP's `json_decode($json, true)`: parses to plain JS values, returning `null` on
 *  malformed input instead of throwing (PHP's `json_decode()` never throws by default — it just
 *  returns `null`, indistinguishable from a literal JSON `null` input, which is also how the PHP
 *  source treats it: both hit the same falsy `if (!$flexContent)` / `if ($quickReply)` branches). */
function jsonDecode(raw: string): unknown {
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

/**
 * Mirrors PHP's `urlencode()` exactly: UTF-8 byte-wise percent-encoding per RFC 1866
 * (application/x-www-form-urlencoded) — space becomes `+` (NOT `%20`), the unreserved set is
 * exactly `[A-Za-z0-9\-_.]` (narrower than `encodeURIComponent()`'s RFC 3986 set — notably `!`,
 * `~`, `'`, `(`, `)`, `*` are all percent-encoded by `urlencode()` but left bare by
 * `encodeURIComponent()`), and hex digits are uppercase. Verified byte-for-byte against
 * `php -r 'echo urlencode($x);'` for Thai multibyte text, `+`, `&`, `=`, whitespace, and emoji —
 * see the build report. `encodeURIComponent()` is NOT a drop-in replacement for `urlencode()`.
 */
function phpUrlEncode(input: string): string {
  const bytes = Buffer.from(input, 'utf-8');
  let out = '';
  for (const byte of bytes) {
    if (
      (byte >= 0x30 && byte <= 0x39) || // 0-9
      (byte >= 0x41 && byte <= 0x5a) || // A-Z
      (byte >= 0x61 && byte <= 0x7a) || // a-z
      byte === 0x2d || // -
      byte === 0x5f || // _
      byte === 0x2e // .
    ) {
      out += String.fromCharCode(byte);
    } else if (byte === 0x20) {
      out += '+';
    } else {
      out += '%' + byte.toString(16).toUpperCase().padStart(2, '0');
    }
  }
  return out;
}

/**
 * Structural clone via JSON round-trip — sufficient for Flex JSON (no Date/Map/function/
 * undefined values ever legitimately appear in a `json_decode()`'d Flex tree). Used so
 * `addShareButtonToFlex()` never mutates the caller's object in place: PHP arrays are
 * copy-on-write value types (the PHP function's in-place `$flexContent['footer']['contents'][] =
 * ...` mutations never touch the CALLER's original array), whereas JS objects are references —
 * without this clone, mutating nested `footer.contents` here would leak back into whatever object
 * the caller passed in, which the PHP source never does.
 */
function deepCloneJson<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

// ---------------------------------------------------------------------------------------------
// matchAutoReplyRule() — port of the `switch ($rule['match_type'])` block inside checkAutoReply()
// (webhook.php:2076-2096), factored out as its own pure function per the porting brief. Does NOT
// build a message and does NOT sort `rules` — callers (SQL constants above) own sort order.
// ---------------------------------------------------------------------------------------------

/**
 * Returns the FIRST rule in `rules` (in the given array order — never re-sorted) whose
 * `match_type` test passes against `text`, or `null` if none match. Mirrors PHP's
 * `foreach ($rules as $rule) { switch (...) { ... } if ($matched) { ... } }` matching loop
 * exactly, for all five `match_type` values (see PARITY NOTE 2 on `'all'`).
 */
export function matchAutoReplyRule(rules: AutoReplyRuleRow[], text: string): AutoReplyRuleRow | null {
  for (const rule of rules) {
    let matched = false;

    switch (rule.match_type) {
      case 'exact':
        matched = mbStrToLower(text) === mbStrToLower(rule.keyword);
        break;
      case 'contains':
        matched = mbStriPos(text, rule.keyword) !== false;
        break;
      case 'starts_with':
        matched = mbStriPos(text, rule.keyword) === 0;
        break;
      case 'regex':
        matched = safeRegexTest(rule.keyword, text);
        break;
      case 'all':
        // Match all messages - ตอบทุกข้อความ
        matched = true;
        break;
      default:
        // No PHP `default:` arm existed either — an unrecognized match_type simply leaves
        // $matched at its initialized `false`.
        matched = false;
    }

    if (matched) return rule;
  }

  return null;
}

// ---------------------------------------------------------------------------------------------
// Flex JSON local types + addShareButtonToFlex() — port of webhook.php:2263-2309.
// ---------------------------------------------------------------------------------------------

/**
 * Deliberately loose — `reply_content` is arbitrary admin-authored Flex JSON (`json_decode()`'d),
 * not a piece-by-piece constructed template like flex.ts's `FlexBubble`. Only `type`, `footer`,
 * and (for carousels) `contents` are structurally significant to `addShareButtonToFlex()`; every
 * other key passes through untouched, exactly as PHP's associative-array manipulation does.
 */
export type FlexJsonRecord = Record<string, unknown>;

export interface FlexFooterLike extends FlexJsonRecord {
  contents: unknown[];
}

export interface FlexBubbleLike extends FlexJsonRecord {
  type: string;
  footer?: FlexFooterLike;
}

export interface FlexCarouselLike extends FlexJsonRecord {
  type: string;
  contents: FlexBubbleLike[];
}

export type FlexBubbleOrCarousel = FlexBubbleLike | FlexCarouselLike | FlexJsonRecord;

/** PHP: `if (!isset($flexContent['footer'])) { $flexContent['footer'] = [...] }` then
 *  unconditionally `$flexContent['footer']['contents'][] = $shareButton` — which auto-vivifies
 *  `contents` as a fresh array even if an existing footer object happens to lack that key. Both
 *  branches are ported here. */
function ensureFooterContents(bubble: FlexBubbleLike): unknown[] {
  if (!isRecord(bubble.footer)) {
    bubble.footer = { type: 'box', layout: 'vertical', contents: [], paddingAll: 'lg' };
  } else if (!Array.isArray(bubble.footer.contents)) {
    bubble.footer.contents = [];
  }
  return bubble.footer.contents;
}

/**
 * Port of `addShareButtonToFlex()` (webhook.php:2263-2309). `liffShareId` is passed explicitly
 * (no PHP-constant idiom in TS — PHP reads the global `LIFF_SHARE_ID` constant directly inside
 * the function body; here the caller resolves that value and passes it in).
 *
 * `label` has no default value here (unlike PHP's optional 4th parameter,
 * `$label = '📤 แชร์ให้เพื่อน'`) — every internal caller (`buildAutoReplyReply()`) already resolves
 * `rule.share_button_label ?? '📤 แชร์ให้เพื่อน'` before calling this, so the PHP-side default
 * effectively lives at that call site instead. This keeps the required `liffShareId` parameter
 * last without an awkward "default value followed by a required parameter" signature.
 *
 * Pure: returns a deep-cloned, modified copy — never mutates the `flexContent` the caller passed
 * in (see `deepCloneJson()`'s doc comment for why that clone is necessary in TS but not in PHP).
 */
export function addShareButtonToFlex(
  flexContent: FlexBubbleOrCarousel,
  ruleId: number,
  label: string,
  liffShareId: string
): FlexBubbleOrCarousel {
  const content = deepCloneJson(flexContent);
  const shareUrl = `https://liff.line.me/${liffShareId}?rule=${ruleId}`;

  const shareButton = {
    type: 'button',
    action: {
      type: 'uri',
      label,
      uri: shareUrl,
    },
    style: 'secondary',
    color: '#3B82F6',
    height: 'sm',
    margin: 'sm',
  };

  // Handle bubble
  if (isRecord(content) && content.type === 'bubble') {
    const bubble = content as FlexBubbleLike;
    ensureFooterContents(bubble).push(shareButton);
    return bubble;
  }
  // Handle carousel
  if (isRecord(content) && content.type === 'carousel' && Array.isArray((content as FlexCarouselLike).contents)) {
    const carousel = content as FlexCarouselLike;
    for (const bubble of carousel.contents) {
      ensureFooterContents(bubble).push(shareButton);
    }
    return carousel;
  }

  return content;
}

// ---------------------------------------------------------------------------------------------
// LineAutoReplyMessage — mirrors the PHP `$message` array built by checkAutoReply().
// ---------------------------------------------------------------------------------------------

export interface LineAutoReplySender {
  name: string;
  iconUrl?: string;
}

export interface LineQuickReplyMessageAction {
  type: 'message';
  label: string;
  text: string;
}

export interface LineQuickReplyUriAction {
  type: 'uri';
  label: string;
  uri: string;
}

export interface LineQuickReplyPostbackAction {
  type: 'postback';
  label: string;
  data: string;
  displayText?: string;
}

export interface LineQuickReplyDatetimepickerAction {
  type: 'datetimepicker';
  label: string;
  data: string;
  mode: string;
  initial?: string;
  min?: string;
  max?: string;
}

export interface LineQuickReplySimpleAction {
  type: 'camera' | 'cameraRoll' | 'location';
  label: string;
}

/** Named `AutoReplyQuickReplyAction` (not `LineQuickReplyAction`) to avoid colliding with the
 *  pre-existing, differently-shaped `LineQuickReplyAction` interface exported from ./flex —
 *  both are re-exported together via a wildcard barrel (src/index.ts), so this file's export
 *  must not shadow the sibling file's name. */
export type AutoReplyQuickReplyAction =
  | LineQuickReplyMessageAction
  | LineQuickReplyUriAction
  | LineQuickReplyPostbackAction
  | LineQuickReplyDatetimepickerAction
  | LineQuickReplySimpleAction;

export interface LineQuickReplyActionItem {
  type: 'action';
  imageUrl?: string;
  action: AutoReplyQuickReplyAction;
}

export interface LineQuickReplyBlock {
  items: LineQuickReplyActionItem[];
}

export interface LineAutoReplyTextMessage {
  type: 'text';
  text: string;
  sender?: LineAutoReplySender;
  quickReply?: LineQuickReplyBlock;
}

export interface LineAutoReplyFlexMessage {
  type: 'flex';
  altText: string;
  contents: FlexBubbleOrCarousel;
  sender?: LineAutoReplySender;
  quickReply?: LineQuickReplyBlock;
}

export type LineAutoReplyMessage = LineAutoReplyTextMessage | LineAutoReplyFlexMessage;

export interface AutoReplyBuildConfig {
  /** Equivalent of PHP's `defined('LIFF_SHARE_ID') && LIFF_SHARE_ID` truthiness check, collapsed
   *  into one value: `null`/`undefined`/`''` means "not configured" (PHP: constant undefined OR
   *  defined-but-falsy), a non-empty string means "configured". */
  liffShareId?: string | null;
}

// ---------------------------------------------------------------------------------------------
// buildQuickReplyActions() — port of the `foreach ($qrItems as $item) { ... switch ($actionType)
// { ... } }` block inside checkAutoReply() (webhook.php:2159-2251), covering all 6 action-shape
// branches (message / uri / postback / datetimepicker / camera+cameraRoll+location / share) plus
// the implicit `default:` (falls back to the 'message' shape, same as PHP).
// ---------------------------------------------------------------------------------------------

interface QuickReplyRawItem {
  label?: unknown;
  text?: unknown;
  type?: unknown;
  imageUrl?: unknown;
  uri?: unknown;
  data?: unknown;
  displayText?: unknown;
  mode?: unknown;
  initial?: unknown;
  min?: unknown;
  max?: unknown;
  shareText?: unknown;
}

function buildQuickReplyActions(qrItems: unknown[]): LineQuickReplyActionItem[] {
  const quickReplyActions: LineQuickReplyActionItem[] = [];

  itemLoop: for (const rawItem of qrItems) {
    const item = (isRecord(rawItem) ? rawItem : {}) as QuickReplyRawItem;

    // Skip items without label
    if (phpFalsy(item.label)) {
      continue;
    }
    const label = item.label as string;

    const draft: Record<string, unknown> = { type: 'action' };

    // Add icon if exists
    if (phpTruthy(item.imageUrl)) {
      draft.imageUrl = item.imageUrl;
    }

    const actionType = (item.type as string | undefined) ?? 'message';

    switch (actionType) {
      case 'message':
        draft.action = {
          type: 'message',
          label,
          text: (item.text as string | undefined) ?? label,
        };
        break;

      case 'uri':
        // Skip if no URI provided
        if (phpFalsy(item.uri)) {
          continue itemLoop;
        }
        draft.action = {
          type: 'uri',
          label,
          uri: item.uri as string,
        };
        break;

      case 'postback': {
        const action: Record<string, unknown> = {
          type: 'postback',
          label,
          data: (item.data as string | undefined) ?? '',
        };
        if (phpTruthy(item.displayText)) {
          action.displayText = item.displayText;
        }
        draft.action = action;
        break;
      }

      case 'datetimepicker': {
        const action: Record<string, unknown> = {
          type: 'datetimepicker',
          label,
          data: (item.data as string | undefined) ?? '',
          mode: (item.mode as string | undefined) ?? 'datetime',
        };
        if (phpTruthy(item.initial)) action.initial = item.initial;
        if (phpTruthy(item.min)) action.min = item.min;
        if (phpTruthy(item.max)) action.max = item.max;
        draft.action = action;
        break;
      }

      case 'camera':
      case 'cameraRoll':
      case 'location':
        draft.action = { type: actionType, label };
        break;

      case 'share': {
        // Share button - ใช้ LINE URI Scheme
        const shareText = (item.shareText as string | undefined) ?? 'มาดูสิ่งนี้สิ!';
        const encodedText = phpUrlEncode(shareText);
        draft.action = {
          type: 'uri',
          label,
          uri: `https://line.me/R/share?text=${encodedText}`,
        };
        break;
      }

      default:
        draft.action = {
          type: 'message',
          label,
          text: (item.text as string | undefined) ?? label,
        };
    }

    quickReplyActions.push(draft as unknown as LineQuickReplyActionItem);
  }

  return quickReplyActions;
}

// ---------------------------------------------------------------------------------------------
// buildAutoReplyReply() — port of the message-construction half of checkAutoReply()
// (webhook.php:2103-2253, i.e. everything after the `use_count` UPDATE through `return $message`).
// ---------------------------------------------------------------------------------------------

/**
 * Builds the LINE message object for an already-matched rule: text-vs-Flex branch, the
 * share-button injection (gated on `rule.enable_share` AND `config.liffShareId`), the optional
 * `sender_name`/`sender_icon` override (icon only applies when name is present), and the
 * `quick_reply` block. Returns `null` in exactly the case PHP's `if (!$message) return null;`
 * fires: `reply_type !== 'text'` AND `reply_content` fails to `json_decode()` (or decodes to a
 * JSON falsy value — `null`, `0`, `''`, `[]`, `false`).
 */
export function buildAutoReplyReply(rule: AutoReplyRuleRow, config: AutoReplyBuildConfig = {}): LineAutoReplyMessage | null {
  let message: LineAutoReplyMessage | null = null;

  if (rule.reply_type === 'text') {
    message = { type: 'text', text: rule.reply_content };
  } else {
    // Flex Message
    const flexContent = jsonDecode(rule.reply_content);
    if (phpTruthy(flexContent)) {
      const altText = rule.alt_text ?? rule.keyword ?? 'ข้อความ';

      let contents = flexContent as FlexBubbleOrCarousel;

      // Add share button if enabled
      const enableShare = rule.enable_share ?? false;
      if (phpTruthy(enableShare) && phpTruthy(config.liffShareId)) {
        const shareLabel = rule.share_button_label ?? '📤 แชร์ให้เพื่อน';
        contents = addShareButtonToFlex(contents, rule.id, shareLabel, config.liffShareId as string);
      }

      message = {
        type: 'flex',
        altText,
        contents,
      };
    }
  }

  if (!message) return null;

  // Add Sender if exists
  const senderName = rule.sender_name ?? null;
  const senderIcon = rule.sender_icon ?? null;
  if (phpTruthy(senderName)) {
    message.sender = { name: senderName as string };
    if (phpTruthy(senderIcon)) {
      message.sender.iconUrl = senderIcon as string;
    }
  }

  // Add Quick Reply if exists (Full Featured)
  const quickReply = rule.quick_reply ?? null;
  if (phpTruthy(quickReply)) {
    const qrItems = jsonDecode(quickReply as string);
    if (phpTruthy(qrItems) && Array.isArray(qrItems)) {
      const quickReplyActions = buildQuickReplyActions(qrItems);
      if (quickReplyActions.length > 0) {
        message.quickReply = { items: quickReplyActions };
      }
    }
  }

  return message;
}

// ---------------------------------------------------------------------------------------------
// resolveAutoReply() — pure top-level composition, equivalent to checkAutoReply() minus the DB
// fetch (webhook.php:2064-2072, replaced by the already-fetched/sorted `rules` parameter) and
// minus the use_count/last_used_at UPDATE (see // DEFERRED: below).
// ---------------------------------------------------------------------------------------------

// DEFERRED: webhook.php:2097-2102 unconditionally attempts
//   `UPDATE auto_replies SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?`
// inside a try/catch (failures are logged via logWebhookException() and swallowed) as soon as a
// rule matches, BEFORE building its reply message. This analytics side effect is intentionally
// NOT ported here: this package has zero live-DB coupling by design (see the porting brief's "Do
// not" list), and there is no live caller yet to wire it into. A future Phase 6 webhook Route
// Handler / worker processor that calls `resolveAutoReply()` against a real, already-matched rule
// must re-add the equivalent `UPDATE auto_replies SET use_count = use_count + 1, last_used_at =
// NOW() WHERE id = ?` (or an equivalent typed-query-builder call) itself — ideally fired for `matchAutoReplyRule()`'s
// return value specifically, not gated on `buildAutoReplyReply()` succeeding (PHP fires the UPDATE
// even when the subsequent build fails and the overall reply ends up `null`).

/**
 * Pure composition of `matchAutoReplyRule()` + `buildAutoReplyReply()`, preserving the
 * exact-once-match short-circuit (see PARITY NOTE 1 above): finds the first rule in `rules`
 * whose `match_type` test passes, calls `buildAutoReplyReply()` on it EXACTLY ONCE, and returns
 * that result as-is — including `null` — without ever trying a second, lower-priority rule.
 */
export function resolveAutoReply(
  rules: AutoReplyRuleRow[],
  text: string,
  config: AutoReplyBuildConfig = {}
): LineAutoReplyMessage | null {
  const rule = matchAutoReplyRule(rules, text);
  if (!rule) return null;
  return buildAutoReplyReply(rule, config);
}
