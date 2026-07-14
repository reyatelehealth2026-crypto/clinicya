'use client';

import { useState } from 'react';
import type { MessageRowJson } from '../../../../api/inbox/messages/_lib/query';
import { MessageBubble } from './MessageBubble';

/**
 * LoadOlderMessagesButton — client "load older messages" trigger, wired to
 * the cursor-paginated `GET /api/inbox/messages` Route Handler
 * (`../../../../api/inbox/messages/route.ts`).
 *
 * NOT a port of any existing inbox-v2.php UI — PHP's `getMessagesCursor()`
 * API action exists (classes/InboxService.php) but no client JS in
 * inbox-v2.php ever calls it for the message THREAD pane (only the
 * conversation LIST has a wired-up cursor loader, `ConversationLoader`,
 * lines 11520+). The center pane's PHP source only ever SSRs the latest 300
 * and stops there. This is genuinely new UI enabled by an endpoint the PHP
 * source already shipped but never wired up.
 *
 * Design: each click fetches the next older page and PREPENDS it above
 * whatever this component has already loaded (older messages arrive in
 * older-first-of-the-batch, newer-last-of-the-batch order — i.e. already
 * ascending within the page — so prepending each new page keeps the whole
 * accumulated list in correct ascending order). `page.tsx` renders this
 * component ABOVE its own server-rendered initial-300 list, so the visual
 * order top-to-bottom is: [load-older button] [older messages, oldest
 * first] [initial 300, oldest first].
 */
export function LoadOlderMessagesButton({
  userId,
  oldestMessageId,
  initialHasMore,
  pictureUrl,
}: {
  userId: number;
  /** id of the oldest message currently on screen (page.tsx's initial-300 SSR) — the starting cursor. `null` when the conversation has no messages at all. */
  oldestMessageId: number | null;
  /** Optimistic heuristic from page.tsx: `messages.length === 300` (a full page — there MIGHT be more; the first fetch here authoritatively confirms/corrects it). */
  initialHasMore: boolean;
  /** The other party's LINE picture_url, forwarded to each loaded MessageBubble's avatar (same as page.tsx passes to its own SSR'd bubbles). */
  pictureUrl?: string | null;
}) {
  const [olderMessages, setOlderMessages] = useState<MessageRowJson[]>([]);
  const [cursor, setCursor] = useState<string | null>(oldestMessageId !== null ? String(oldestMessageId) : null);
  const [hasMore, setHasMore] = useState(initialHasMore && oldestMessageId !== null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadOlder() {
    if (cursor === null || loading) return;
    setLoading(true);
    setError(null);
    try {
      const url = `/api/inbox/messages?user_id=${userId}&cursor=${encodeURIComponent(cursor)}&limit=50`;
      const res = await fetch(url);
      const json = (await res.json()) as
        | { success: true; data: { messages: MessageRowJson[]; next_cursor: string | null; has_more: boolean } }
        | { success: false; error: string };

      if (!json.success) {
        setError(json.error || 'โหลดข้อความเก่าไม่สำเร็จ');
        return;
      }

      setOlderMessages((prev) => [...json.data.messages, ...prev]);
      setCursor(json.data.next_cursor);
      setHasMore(json.data.has_more);
    } catch {
      setError('โหลดข้อความเก่าไม่สำเร็จ — กรุณาลองใหม่');
    } finally {
      setLoading(false);
    }
  }

  return (
    <>
      {hasMore ? (
        <div className="text-center py-2">
          <button
            type="button"
            onClick={loadOlder}
            disabled={loading}
            className="text-xs text-teal-600 hover:text-teal-700 disabled:opacity-60"
          >
            {loading ? 'กำลังโหลด...' : 'โหลดข้อความเก่ากว่านี้'}
          </button>
        </div>
      ) : null}
      {error ? (
        <p role="alert" className="text-center text-xs text-red-500 py-1">
          {error}
        </p>
      ) : null}
      {olderMessages.map((m) => (
        <MessageBubble key={m.id} message={m} pictureUrl={pictureUrl} />
      ))}
    </>
  );
}
