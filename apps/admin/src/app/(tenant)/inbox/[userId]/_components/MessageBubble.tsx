import type { ReactNode } from 'react';
import type { MessageRow, MessageRowJson } from '../../../../api/inbox/messages/_lib/query';
import { QuickReplyPreview, type QuickReplyItem } from './QuickReplyPreview';
import { FlexMessage } from './flex';

/**
 * MessageBubble — port of inbox-v2.php's per-message SSR renderer (lines
 * 3272-3538: the `foreach ($messages as $msg)` block). Renders EVERY
 * message type the PHP SSR branches on, including the two gaps CLAUDE.md's
 * headline list omits:
 *  - `location` (lines 3411-3454) — SSR-only, entirely absent from the
 *    client-side `renderSingleMessage()` JS.
 *  - text-content-that-is-actually-a-video-URL (lines 3289-3299) — a
 *    `text`-typed message whose `content` matches `/uploads/line_videos/`
 *    or a `.mp4|.mov|.avi|.webm` extension re-renders through the SAME
 *    video branch as a literal `video`-typed message.
 *
 * Also ports the embedded-LINE-quickReply preview strip for `text` messages
 * (lines 3302-3365, display-only — see QuickReplyPreview.tsx) and the
 * `flex`/`file`/`sticker` malformed-JSON fallbacks PHP's `json_decode(...,
 * true)` + `??` chains produce (never a crash).
 *
 * `message` accepts either the SSR-shaped row (`created_at: Date`, from
 * getInitialMessages()) or the JSON-wire-shaped row (`created_at: string`,
 * from the cursor Route Handler via LoadOlderMessagesButton) — both flow
 * through the exact same rendering path, matching that "load older" results
 * and the initial 300 look identical.
 */

const IMAGE_SRC_ID_RE = /ID:\s*(\d+)/;
const ABSOLUTE_URL_RE = /^https?:\/\//;
const TEXT_AS_VIDEO_RE = /\.(mp4|mov|avi|webm)$/i;
const STICKER_ID_RE = /Sticker:\s*(\d+)/;
const LOCATION_RE = /\[location\]\s*(.+?)\s*\(([0-9.-]+),\s*([0-9.-]+)\)/;

const AVATAR_FALLBACK_SVG =
  "data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 28 28%22%3E%3Ccircle cx=%2214%22 cy=%2214%22 r=%2214%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M14 15.4c2.3 0 4.2-1.9 4.2-4.2s-1.9-4.2-4.2-4.2-4.2 1.9-4.2 4.2 1.9 4.2 4.2 4.2zm0 2.1c-2.8 0-8.4 1.4-8.4 4.2v2.1h16.8v-2.1c0-2.8-5.6-4.2-8.4-4.2z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E";

/** `content` matching `ID:\d+` proxies through that id; otherwise a bare (non-http) value IS the id — used by image/audio (inbox-v2.php lines 3369-3373, 3458-3462; ID: match wins even over an absolute-URL-looking value). */
function resolveIdFirstSrc(content: string): string {
  const idMatch = IMAGE_SRC_ID_RE.exec(content);
  if (idMatch) return `api/line_content.php?id=${idMatch[1]}`;
  if (!ABSOLUTE_URL_RE.test(content)) return `api/line_content.php?id=${content}`;
  return content;
}

/** video's branch ORDER differs from image/audio (inbox-v2.php lines 3396-3403): an absolute http(s) URL wins even if it also happens to contain `ID:\d+`. */
function resolveVideoSrc(content: string): string {
  if (ABSOLUTE_URL_RE.test(content)) return content;
  const idMatch = IMAGE_SRC_ID_RE.exec(content);
  if (idMatch) return `api/line_content.php?id=${idMatch[1]}`;
  return `api/line_content.php?id=${content}`;
}

function capitalizeFirst(value: string): string {
  return value.length === 0 ? '' : value[0]!.toUpperCase() + value.slice(1);
}

/** `date('H:i', strtotime($msg['created_at']))` — HH:MM, no seconds, no re-timezoning. Deliberately reads raw wall-clock digits (from the MySQL string, or via a `Date`'s LOCAL getters) rather than reinterpreting through an explicit Asia/Bangkok Intl formatter — the tenant DB connection already runs `SET time_zone='+07:00'`, and mysql2 hydrates DATETIME columns using the process's own local Date constructor, so the original wall-clock digits round-trip correctly through local getters regardless of the host's real OS timezone; reprojecting through a DIFFERENT explicit zone would double-shift them (see apps/admin/src/app/api/inbox/conversations/_lib/preview.ts's doc comment for the exact bug class this avoids). */
function formatMessageTime(value: Date | string): string {
  if (typeof value === 'string') {
    const match = /^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/.exec(value);
    if (match) return `${match[1]}:${match[2]}`;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return '';
    return `${String(parsed.getHours()).padStart(2, '0')}:${String(parsed.getMinutes()).padStart(2, '0')}`;
  }
  if (Number.isNaN(value.getTime())) return '';
  return `${String(value.getHours()).padStart(2, '0')}:${String(value.getMinutes()).padStart(2, '0')}`;
}

/** getSenderBadge($sentBy, 'outgoing') — inbox-v2.php lines 1190-1209. Only ever shown for outgoing messages. */
function SenderBadge({ sentBy }: { sentBy: string | null }) {
  if (!sentBy) {
    return (
      <span className="sender-badge admin">
        <i className="fas fa-user-shield" aria-hidden="true" /> Admin
      </span>
    );
  }
  if (sentBy.startsWith('admin:')) {
    return (
      <span className="sender-badge admin">
        <i className="fas fa-user-shield" aria-hidden="true" /> {sentBy.slice(6)}
      </span>
    );
  }
  if (sentBy === 'ai' || sentBy.startsWith('ai:')) {
    return (
      <span className="sender-badge ai">
        <i className="fas fa-robot" aria-hidden="true" /> AI
      </span>
    );
  }
  if (sentBy === 'bot' || sentBy.startsWith('bot:') || sentBy.startsWith('system:')) {
    return (
      <span className="sender-badge bot">
        <i className="fas fa-cog" aria-hidden="true" /> Bot
      </span>
    );
  }
  return <span className="sender-badge">{sentBy}</span>;
}

function withLineBreaks(text: string): ReactNode[] {
  return text.split('\n').flatMap((line, i, arr) =>
    i < arr.length - 1
      ? [line, <br key={i} />]
      : [line]
  );
}

interface ParsedTextMessage {
  textContent: string;
  quickReplyItems: QuickReplyItem[];
}

/** JSON-embedded LINE `{type:'text', text, quickReply:{items}}` message object (inbox-v2.php lines 3302-3320) — falls back to the raw content whenever it isn't that exact shape (including "isn't JSON at all"). */
function parseTextMessage(content: string): ParsedTextMessage {
  try {
    const parsed = JSON.parse(content) as { type?: string; text?: string; quickReply?: { items?: QuickReplyItem[] } } | null;
    if (parsed && typeof parsed === 'object' && parsed.type === 'text') {
      const items = parsed.quickReply?.items;
      return {
        textContent: parsed.text ?? content,
        quickReplyItems: Array.isArray(items) ? items : [],
      };
    }
  } catch {
    // not JSON — fall through to raw content, matching PHP's json_decode()->null path.
  }
  return { textContent: content, quickReplyItems: [] };
}

function parseStickerId(content: string): string | null {
  try {
    const parsed = JSON.parse(content) as { stickerId?: string | number } | null;
    if (parsed && typeof parsed === 'object' && parsed.stickerId !== undefined && parsed.stickerId !== null) {
      return String(parsed.stickerId);
    }
  } catch {
    // fall through to the regex form
  }
  const match = STICKER_ID_RE.exec(content);
  return match ? match[1]! : null;
}

function parseFileMessage(content: string): { fileName: string; fileUrl: string } {
  try {
    const parsed = JSON.parse(content) as { name?: string; url?: string } | null;
    return { fileName: parsed?.name ?? 'File', fileUrl: parsed?.url ?? '#' };
  } catch {
    return { fileName: 'File', fileUrl: '#' };
  }
}

function TextContent({ content, isMe }: { content: string; isMe: boolean }) {
  const { textContent, quickReplyItems } = parseTextMessage(content);
  return (
    <>
      <div className={`chat-bubble ${isMe ? 'chat-outgoing' : 'chat-incoming'}`}>{withLineBreaks(textContent)}</div>
      {quickReplyItems.length > 0 ? <QuickReplyPreview items={quickReplyItems} /> : null}
    </>
  );
}

function ImageContent({ content }: { content: string }) {
  return (
    // eslint-disable-next-line @next/next/no-img-element -- structural port of a plain preview <img>, not next/image.
    <img
      src={resolveIdFirstSrc(content)}
      alt=""
      className="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90"
      loading="lazy"
    />
  );
}

function StickerContent({ content }: { content: string }) {
  const stickerId = parseStickerId(content);
  if (!stickerId) {
    return <div className="bg-white rounded-lg border p-2 text-xs text-gray-500">😊 Sticker</div>;
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={`https://stickershop.line-scdn.net/stickershop/v1/sticker/${stickerId}/android/sticker.png`}
      alt=""
      className="w-20"
    />
  );
}

function VideoContent({ content }: { content: string }) {
  return (
    <div className="video-message rounded-xl overflow-hidden max-w-[300px] border shadow-sm bg-black">
      <video controls preload="metadata" className="w-full" style={{ maxHeight: 400 }}>
        <source src={resolveVideoSrc(content)} type="video/mp4" />
        เบราว์เซอร์ของคุณไม่รองรับการเล่นวิดีโอ
      </video>
    </div>
  );
}

function LocationContent({ content }: { content: string }) {
  const match = LOCATION_RE.exec(content);
  if (!match) {
    return (
      <div className="p-3 text-center text-gray-500">
        <i className="fas fa-map-marker-alt text-2xl mb-2" aria-hidden="true" />
        <p className="text-sm">📍 Location</p>
      </div>
    );
  }
  const [, addressRaw, lat, lng] = match;
  const address = addressRaw!.trim();
  return (
    <div className="location-message bg-white rounded-xl border shadow-sm overflow-hidden max-w-[300px]">
      <a href={`https://www.google.com/maps?q=${lat},${lng}`} target="_blank" rel="noreferrer" className="block hover:opacity-90">
        <div className="w-full h-32 bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
          <div className="text-center">
            <i className="fas fa-map-marker-alt text-red-500 text-4xl mb-2" aria-hidden="true" />
            <p className="text-xs text-gray-600">คลิกเพื่อดูแผนที่</p>
          </div>
        </div>
        <div className="p-3">
          <div className="flex items-start gap-2">
            <i className="fas fa-map-marker-alt text-red-500 mt-1" aria-hidden="true" />
            <div className="flex-1">
              {address ? <p className="text-sm text-gray-800 font-medium">{address}</p> : null}
              <p className="text-xs text-gray-500 mt-1">
                {lat}, {lng}
              </p>
              <p className="text-xs text-teal-600 mt-1">
                <i className="fas fa-external-link-alt mr-1" aria-hidden="true" />
                เปิดใน Google Maps
              </p>
            </div>
          </div>
        </div>
      </a>
    </div>
  );
}

function AudioContent({ content }: { content: string }) {
  return (
    <div className="audio-message bg-white rounded-xl border shadow-sm p-3 max-w-[300px]">
      <div className="flex items-center gap-3">
        <i className="fas fa-volume-up text-teal-600 text-xl" aria-hidden="true" />
        <audio controls preload="metadata" className="flex-1">
          <source src={resolveIdFirstSrc(content)} type="audio/mpeg" />
          เบราว์เซอร์ของคุณไม่รองรับการเล่นเสียง
        </audio>
      </div>
    </div>
  );
}

function FileContent({ content }: { content: string }) {
  const { fileName, fileUrl } = parseFileMessage(content);
  return (
    <a
      href={fileUrl}
      target="_blank"
      rel="noreferrer"
      className="file-message bg-white rounded-xl border shadow-sm p-3 max-w-[300px] hover:bg-gray-50 block"
    >
      <div className="flex items-center gap-3">
        <i className="fas fa-file-pdf text-red-500 text-2xl" aria-hidden="true" />
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-gray-800 truncate">{fileName}</p>
          <p className="text-xs text-teal-600 mt-1">
            <i className="fas fa-download mr-1" aria-hidden="true" />
            ดาวน์โหลด
          </p>
        </div>
      </div>
    </a>
  );
}

function FlexContent({ content }: { content: string }) {
  return (
    <div className="flex-message-container">
      <FlexMessage content={content} />
    </div>
  );
}

function UnknownContent({ messageType }: { messageType: string }) {
  return (
    <div className="bg-white rounded-lg border p-3 text-xs text-gray-500">
      <i className="fas fa-file-alt mr-1" aria-hidden="true" />
      {capitalizeFirst(messageType)}
    </div>
  );
}

function renderContent(effectiveType: string, content: string, isMe: boolean): ReactNode {
  switch (effectiveType) {
    case 'text':
      return <TextContent content={content} isMe={isMe} />;
    case 'image':
      return <ImageContent content={content} />;
    case 'sticker':
      return <StickerContent content={content} />;
    case 'video':
      return <VideoContent content={content} />;
    case 'location':
      return <LocationContent content={content} />;
    case 'audio':
      return <AudioContent content={content} />;
    case 'flex':
      return <FlexContent content={content} />;
    case 'file':
      return <FileContent content={content} />;
    default:
      return <UnknownContent messageType={effectiveType} />;
  }
}

export function MessageBubble({
  message,
  pictureUrl,
}: {
  message: MessageRow | MessageRowJson;
  /** The OTHER party's LINE picture_url — only shown next to incoming messages (inbox-v2.php lines 3282-3285). */
  pictureUrl?: string | null;
}) {
  const isMe = message.direction === 'outgoing';
  const rawType = message.message_type ?? '';
  const content = message.content ?? '';

  // "Check if text content is actually a video URL" (inbox-v2.php lines 3289-3299).
  const isVideoUrl = rawType === 'text' && (content.includes('/uploads/line_videos/') || TEXT_AS_VIDEO_RE.test(content));
  const effectiveType = isVideoUrl ? 'video' : rawType;

  return (
    <div className={`message-item flex ${isMe ? 'justify-end' : 'justify-start'} group`} data-msg-id={message.id}>
      {!isMe ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={pictureUrl || AVATAR_FALLBACK_SVG}
          alt=""
          className="w-8 h-8 rounded-full self-end mr-2 flex-shrink-0"
        />
      ) : null}
      <div
        className="msg-content-wrapper"
        style={{ maxWidth: '70%', display: 'flex', flexDirection: 'column', alignItems: isMe ? 'flex-end' : 'flex-start' }}
      >
        {renderContent(effectiveType, content, isMe)}
        <div className="msg-meta flex items-center gap-1 mt-1">
          <span>{formatMessageTime(message.created_at)}</span>
          {isMe ? <SenderBadge sentBy={message.sent_by} /> : null}
        </div>
      </div>
    </div>
  );
}
