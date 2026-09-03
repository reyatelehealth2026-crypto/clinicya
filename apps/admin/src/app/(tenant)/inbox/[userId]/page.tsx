import { redirect } from 'next/navigation';
import { INITIAL_MESSAGES_LIMIT } from '../../../api/inbox/messages/_lib/query';
import { requireTenantPageContext } from './_lib/session';
import { getInboxThreadPageData } from './_lib/queries';
import { ChatHeader } from './_components/ChatHeader';
import { MessageBubble } from './_components/MessageBubble';
import { LoadOlderMessagesButton } from './_components/LoadOlderMessagesButton';

/**
 * /inbox/[userId] — Server Component port of inbox-v2.php's "CENTER: Chat
 * Area" pane (lines 3174-3538) for a specific conversation: chat header
 * (avatar/name/tags) + the latest 300 messages, SSR'd, oldest-first — plus
 * a client "load older" affordance above them wired to the cursor Route
 * Handler this batch also owns (see LoadOlderMessagesButton.tsx's doc for
 * why that's new UI, not a literal PHP port).
 *
 * A dynamic `[userId]` SEGMENT (not `?user_id=N`) is a deliberate departure
 * from earlier phase-2 pages' "same query-param shape as the PHP page"
 * convention (see user-detail's page.tsx doc) — this is explicit in the
 * brief's own deliverable path, not a guess.
 *
 * SCOPE — this file owns exactly the chat pane described above.
 * `(tenant)/inbox/layout.tsx` (conversation-list sidebar chrome) and
 * `(tenant)/inbox/page.tsx` (the `/inbox` no-selection empty state) are
 * conversationList's exclusive territory (see the brief's allowed-paths) —
 * NOT created or touched here. Once that layout exists, this page nests
 * under it automatically via Next's file-based routing; until then it also
 * renders correctly nested directly under `(tenant)/layout.tsx` alone
 * (verified by this file's own page.test.tsx, which renders it standalone).
 *
 * DEFERRED, NOT SILENTLY DROPPED (see the brief's "Do not" list): the
 * page-load mark-as-read side effect (`UPDATE messages SET is_read = 1 ...`,
 * inbox-v2.php line 1123) — unread badges will not clear on open in this
 * batch. Every other inbox-v2.php action (send/dispense/tag/note/AI
 * copilot, ~29 total) is out of scope per the plan's "actions ทีละ ~5"
 * phasing; this page is reads-only.
 */

interface InboxThreadPageProps {
  params: Promise<{ userId: string }>;
}

export default async function InboxThreadPage({ params }: InboxThreadPageProps) {
  const { userId: userIdParam } = await params;
  const userId = Number.parseInt(userIdParam, 10);
  if (!Number.isInteger(userId) || userId <= 0) {
    redirect('/inbox');
  }

  const { db } = await requireTenantPageContext();
  const data = await getInboxThreadPageData(db, userId);
  if (!data) {
    redirect('/inbox');
  }

  const { selectedUser, userTags, messages } = data;
  const pictureUrl = selectedUser.picture_url;
  const oldestMessageId = messages.length > 0 ? (messages[0]?.id ?? null) : null;
  const hasMoreInitially = messages.length === INITIAL_MESSAGES_LIMIT;

  return (
    <div id="chatArea" className="flex-1 flex flex-col bg-slate-100 min-w-0">
      <ChatHeader
        user={{
          pictureUrl,
          displayName: selectedUser.display_name,
          customDisplayName: selectedUser.custom_display_name,
        }}
        tags={userTags}
      />
      <div id="chatBox" className="flex-1 overflow-y-auto p-4 space-y-3 chat-scroll">
        <LoadOlderMessagesButton
          userId={userId}
          oldestMessageId={oldestMessageId}
          initialHasMore={hasMoreInitially}
          pictureUrl={pictureUrl}
        />
        {messages.map((m) => (
          <MessageBubble key={m.id} message={m} pictureUrl={pictureUrl} />
        ))}
      </div>
    </div>
  );
}
