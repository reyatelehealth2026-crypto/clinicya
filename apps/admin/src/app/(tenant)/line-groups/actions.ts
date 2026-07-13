'use server';

import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../users/_lib/session';
import { getLineGroupForLeave, markLineGroupLeft } from './queries';

/**
 * actions.ts — Server Action for line-groups.php's `action==='leave_group'`
 * handler (lines 24-53):
 *
 *   $group = SELECT * FROM line_groups WHERE id = ?;
 *   if ($group) {
 *     $line = LineAccountManager($db)->getLineAPI($group['line_account_id']);
 *     $result = $group['group_type']==='group' ? $line->leaveGroup(...) : $line->leaveRoom(...);
 *     UPDATE line_groups SET is_active = 0, left_at = NOW() WHERE id = ?;
 *     $message = "ออกจากกลุ่ม {$group['group_name']} แล้ว";
 *   }
 *
 * OUT OF SCOPE (Phase 6 follow-up, not silently dropped): the real outbound
 * LINE API call (`LineAPI::leaveGroup()`/`leaveRoom()` via
 * `LineAccountManager::getLineAPI()`). `classes/LineAPI.php` and
 * `classes/LineAccountManager.php` are not part of `packages/line` yet (that
 * package does not exist until Phase 6 per the migration plan), and this
 * batch's allowed paths explicitly exclude touching either PHP class. This
 * action performs ONLY the DB-side effect (`is_active=0, left_at=NOW()`) —
 * the bot's LINE-side membership in the group/room is UNCHANGED until the
 * still-live PHP page (or a future Phase 6 port) actually calls the LINE
 * Messaging API. Do not read this page's "Left" badge as proof the bot
 * really left the LINE group.
 *
 * PHP's local `$message`/`$error` (rendered as plain colored `<div>` flash
 * banners, lines 138-148 — no session, no shared Toast component) become a
 * `?message=`/`?error=` searchParam after a `redirect()`, the same
 * convention user-detail.php's `updated`/`points_updated` flags already
 * establish (see that page's module doc) — apps/admin/src/components/** has
 * no shared Toast component yet, so this does not invent one.
 */
export async function leaveGroupAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const groupDbId = Number.parseInt(String(formData.get('group_id') ?? ''), 10) || 0;

  const group = await getLineGroupForLeave(db, groupDbId);
  if (!group) {
    // Mirrors PHP: no `if ($group)` body runs, so neither $message nor $error
    // is ever set — the page just re-renders with both empty.
    redirect('/line-groups');
  }

  // `redirect()` is deliberately called OUTSIDE the try/catch below (it works
  // by throwing a special Next.js-internal error to abort rendering) —
  // wrapping it in the same try would require detecting and re-throwing that
  // internal error to avoid mis-reporting a successful DB write as a failure.
  let errorMessage: string | null = null;
  try {
    await markLineGroupLeft(db, groupDbId);
  } catch (err) {
    errorMessage = err instanceof Error ? err.message : String(err);
  }

  if (errorMessage !== null) {
    redirect(`/line-groups?error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${errorMessage}`)}`);
  }

  const name = group.groupName || '';
  redirect(`/line-groups?message=${encodeURIComponent(`ออกจากกลุ่ม ${name} แล้ว`)}`);
}
