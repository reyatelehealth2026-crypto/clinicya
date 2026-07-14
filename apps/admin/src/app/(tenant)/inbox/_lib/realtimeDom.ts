/**
 * realtimeDom.ts — literal port of inbox-v2.php's `bumpConversationToTop()`
 * / `updateConversationPreview()` (lines ~11399-11494) as pure,
 * unit-testable functions operating on a passed-in `Document`.
 *
 * WHY DOM MANIPULATION AT ALL (not React state): _components/
 * ConversationListLoader.tsx (batch-1, already merged, off-limits to this
 * batch) owns the `#userList` conversation rows as its own internal React
 * state. This batch cannot reactively update that state without editing
 * that file, which is out of scope this round (see the brief). So — exactly
 * like PHP's own vanilla-JS realtime handler already does against its own
 * server-rendered `#userList` markup — this ports the SAME direct DOM
 * mutation technique against the SAME stable selectors
 * (`#userList`, `.user-item`, `[data-user-id]`, `.last-msg`, `.last-time`,
 * `.unread-badge`) that ConversationListItem.tsx's own module doc
 * explicitly confirms are load-bearing (read by FilterBar.tsx's filtering
 * too), not decorative — safe to depend on from outside code.
 *
 * DELIBERATE SIMPLIFICATIONS vs. PHP (both explicitly out of this round's
 * effort budget — see the brief):
 *  - No FLIP-animation transform/transition choreography (PHP lines
 *    11414-11448, a pure visual nicety). `insertBefore` is a plain,
 *    unanimated DOM move here.
 *  - The unread badge is a purely cosmetic client-side counter racing
 *    against the real `unread_count` the next full page load will show
 *    correctly — "parse current text, +1, re-render with a 9+ cap" is
 *    sufficient, no true-count tracking.
 */

/** Shape of the `message` sub-object this module reads — a loose pass-through, mirroring apps/worker/src/realtime/inboxRelay.ts's own `InboxUpdateMessage` posture (read only the couple of fields needed, don't reshape/whitelist the rest). */
export interface RealtimeConversationMessage {
  content?: unknown;
  user_id?: unknown;
  [key: string]: unknown;
}

const UNREAD_BADGE_CLASS = 'unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold';

/** `date('H:i')`-equivalent in Asia/Bangkok, plus the ` น.` suffix PHP's own `formatThaiTime(new Date())` always produces for "today" (this is always "now" here, so the "today" branch is the only reachable one — see inbox-v2.php lines 12040-12047 / _lib/preview.ts's `formatThaiTime` same-day branch). Same `Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Bangkok', ... })` pattern as api/inbox/actions/send-message/_lib/sendMessage.ts's `bangkokTimeHHmm()` — written locally, no shared import needed for two lines of formatting. */
function bangkokNowLabel(): string {
  const hhmm = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date());
  return `${hhmm} น.`;
}

function findConversationItem(doc: Document, userId: number | string): Element | null {
  const conversationList = doc.getElementById('userList');
  if (!conversationList) {
    return null;
  }
  // Mirrors PHP's `conversationList.querySelector(`[data-user-id="${userId}"]`)`
  // exactly — a no-op (not a throw) when the row isn't in the currently
  // loaded/visible page yet, same as PHP's own `if (!conversationItem) return;`.
  return conversationList.querySelector(`[data-user-id="${userId}"]`);
}

/**
 * Port of inbox-v2.php's `updateConversationPreview()` (lines 11458-11494).
 * Creates the `.unread-badge` inside the row's `.relative.flex-shrink-0`
 * avatar container if absent, otherwise increments its numeric textContent,
 * capping the DISPLAY at `'9+'` (matches ConversationListItem.tsx's own
 * `c.unread_count > 9 ? '9+' : c.unread_count` rendering). No-op when
 * `userId` isn't found in `#userList` at all.
 */
export function updateUnreadBadge(doc: Document, userId: number | string): void {
  const item = findConversationItem(doc, userId);
  if (!item) {
    return;
  }

  const existingBadge = item.querySelector('.unread-badge');
  if (!existingBadge) {
    const avatarContainer = item.querySelector('.relative.flex-shrink-0');
    if (!avatarContainer) {
      return;
    }
    const badge = doc.createElement('div');
    badge.className = UNREAD_BADGE_CLASS;
    badge.textContent = '1';
    avatarContainer.appendChild(badge);
    return;
  }

  // `parseInt('9+', 10) === 9` (stops at the first non-digit char) — same
  // truncating behavior as PHP's `parseInt(unreadBadge.textContent) || 0`,
  // so re-incrementing an already-capped '9+' badge behaves identically.
  const currentCount = Number.parseInt(existingBadge.textContent ?? '', 10) || 0;
  const newCount = currentCount + 1;
  existingBadge.textContent = newCount > 9 ? '9+' : String(newCount);
}

/**
 * Port of inbox-v2.php's `bumpConversationToTop()` (lines 11399-11451,
 * FLIP-animation stripped per this module's doc above) + inline
 * `updateConversationPreview()` (lines 11458-11494). Finds `#userList`, finds
 * `[data-user-id="${userId}"]` within it; no-op if either is missing. If the
 * row isn't already the first `.user-item`, moves it to the top via
 * `insertBefore`. Then updates `.last-msg` (only when `message.content` is a
 * non-empty/truthy value — mirrors PHP's `if (lastMsgEl && messageData.content)`
 * guard exactly, so an empty-string content does NOT clobber the existing
 * preview text), `.last-time` (current Bangkok HH:mm), and the unread badge
 * (via `updateUnreadBadge` above).
 */
export function bumpConversationToTop(doc: Document, userId: number | string, message: RealtimeConversationMessage): void {
  const item = findConversationItem(doc, userId);
  if (!item) {
    return;
  }

  const conversationList = doc.getElementById('userList');
  const firstItem = conversationList?.querySelector('.user-item') ?? null;
  if (conversationList && firstItem !== item) {
    conversationList.insertBefore(item, firstItem);
  }

  const lastMsgEl = item.querySelector('.last-msg');
  if (lastMsgEl && message.content) {
    lastMsgEl.textContent = String(message.content);
  }

  const lastTimeEl = item.querySelector('.last-time');
  if (lastTimeEl) {
    lastTimeEl.textContent = bangkokNowLabel();
  }

  updateUnreadBadge(doc, userId);
}
