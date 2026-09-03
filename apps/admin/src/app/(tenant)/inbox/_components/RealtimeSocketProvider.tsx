'use client';

import type { ReactNode } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useRealtimeSocket } from '../_lib/useRealtimeSocket';
import { bumpConversationToTop, type RealtimeConversationMessage } from '../_lib/realtimeDom';

/**
 * RealtimeSocketProvider — mounts once per tenant/account at
 * `(tenant)/inbox/layout.tsx` (wraps the whole `/inbox` route subtree, both
 * `/inbox` and `/inbox/[userId]`). Renders no UI of its own; it exists
 * purely to open the realtime socket connection (via `useRealtimeSocket`)
 * and apply the two live-update mechanisms this batch's brief specifies,
 * each chosen specifically because it requires ZERO edits to the two
 * batch-1 files that own their own frozen internal state
 * (ConversationListLoader.tsx, `[userId]/page.tsx`):
 *
 *  1. Conversation-list preview/unread updates — direct, targeted DOM
 *     manipulation via `realtimeDom.bumpConversationToTop()` against the
 *     same stable selectors PHP's own realtime handler already depends on
 *     (see that module's doc). Runs for every `new_message` event,
 *     unconditionally.
 *
 *  2. Open-thread message append — `router.refresh()` (Next.js Server
 *     Component re-render from fresh DB data) when the incoming message's
 *     `user_id` matches the currently-open `/inbox/[userId]` route segment.
 *     `[userId]/page.tsx` is a pure async Server Component with no
 *     client-side frozen state of its own, so a refresh is safe — zero risk
 *     of stale/duplicate messages.
 *
 *     TRADE-OFF (called out per the brief, not silently accepted): this
 *     refresh also remounts `LoadOlderMessagesButton.tsx`, whose own
 *     client-side "loaded older messages" state (`olderMessages`/`cursor`/
 *     `hasMore`, all local `useState`) resets to its initial props on every
 *     such refresh. A pharmacist who had clicked "load older" and then
 *     receives a new incoming message on that same open thread will see
 *     their previously-loaded older messages collapse back to just the
 *     initial SSR'd page. Accepted for this round — fixing it would require
 *     editing `[userId]/**`, which is out of this batch's allowed paths.
 *
 * `conversation_update` events are intentionally NOT wired to any DOM
 * mutation this round — `new_message` already carries everything
 * `bumpConversationToTop` needs (see inboxRelay.ts's own doc: the relay
 * unconditionally emits both events off of every Redis message, so
 * `new_message` alone is sufficient here without double-applying the same
 * update twice).
 */
export interface RealtimeSocketProviderProps {
  lineAccountId: number;
  children: ReactNode;
}

function coerceMessage(payload: unknown): RealtimeConversationMessage | null {
  if (typeof payload !== 'object' || payload === null) {
    return null;
  }
  return payload as RealtimeConversationMessage;
}

export function RealtimeSocketProvider({ lineAccountId, children }: RealtimeSocketProviderProps) {
  const pathname = usePathname();
  const router = useRouter();

  useRealtimeSocket({
    lineAccountId,
    onNewMessage: (payload) => {
      const message = coerceMessage(payload);
      // Guard mirrors PHP's own `if (data && data.user_id)` guard before
      // calling bumpConversationToTop (inbox-v2.php line 11383).
      if (!message || message.user_id === undefined || message.user_id === null || message.user_id === '') {
        return;
      }
      const userId = String(message.user_id);

      bumpConversationToTop(document, userId, message);

      // Same `/inbox/{id}` segment derivation ConversationListLoader.tsx's
      // own `activeUserId` already uses — duplicated here deliberately (see
      // this batch's brief) rather than importing from that off-limits file.
      const activeUserId = pathname?.startsWith('/inbox/') ? pathname.slice('/inbox/'.length) : null;
      if (activeUserId !== null && activeUserId === userId) {
        router.refresh();
      }
    },
  });

  return <>{children}</>;
}
