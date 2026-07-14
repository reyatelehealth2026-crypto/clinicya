import { EmptyState } from '@/components/EmptyState';
import { requireTenantPageContext } from './_lib/session';

/**
 * /inbox — the "no conversation selected" main-pane state, port of
 * inbox-v2.php's `<?php else: ?>` branch for when `$selectedUser` is null
 * (lines 3720-3726, rendered when neither `?user=` nor `?user_id=` is in
 * the URL). The "conversation IS selected" branch belongs to mig-ui's
 * `/inbox/[userId]/page.tsx` — this route only ever renders the empty
 * state, since a real userId always resolves to that sibling dynamic route.
 *
 * Still gated the same as every other page under this layout
 * (requireTenantPageContext()) — layout.tsx's own gate covers the sidebar,
 * but each leaf page re-resolves its own session/db per this codebase's
 * established convention (see _lib/session.ts's doc comment); this page
 * has no further data to fetch beyond confirming the session is valid.
 */
export default async function InboxPage() {
  await requireTenantPageContext();

  return (
    <div className="flex-1 flex items-center justify-center">
      <EmptyState heading="เลือกแชทเพื่อเริ่มสนทนา" sub="Vibe Selling OS v2 - AI-Powered Pharmacy Assistant" />
    </div>
  );
}
