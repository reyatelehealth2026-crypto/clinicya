'use server';

import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from '../users/_lib/session';
import { giveByPhone, getMemberDetail, type GiveByPhoneResult, type MemberDetailResult } from './_lib/pointsClaim';

/**
 * actions.ts — Server Actions for loyalty-members.php's two client-side
 * `fetch('api/points-claim.php', {...})` calls (lines 236-275, 277-319):
 * `give_by_phone` (a real mutation the orchestrator's page-level grep missed
 * — see _lib/pointsClaim.ts's module doc) and `member_detail` (read-only).
 * Both required an authenticated admin session in the PHP source
 * (`if (empty($_SESSION['admin_user'])) { ... }`) — reproduced here via
 * requireTenantPageContext(), the same cross-import convention user-detail's
 * actions.ts already establishes.
 */

export interface GiveByPhoneFormInput {
  phone: string;
  name: string;
  userId: string;
  amount: string;
  points: string;
}

export async function giveByPhoneAction(input: GiveByPhoneFormInput): Promise<GiveByPhoneResult> {
  const { db, session } = await requireTenantPageContext();
  const lineAccountId = session.currentBotId;
  if (!lineAccountId) {
    return { success: false, message: 'Missing line_account_id' };
  }

  const result = await giveByPhone(db, {
    lineAccountId,
    adminUserId: session.adminUserId,
    phone: input.phone,
    name: input.name,
    userId: Number.parseInt(input.userId, 10) || 0,
    amount: input.amount !== '' ? Number.parseFloat(input.amount) : 0,
    points: input.points !== '' ? Number.parseInt(input.points, 10) : 0,
    paymentMethod: '',
  });

  if (result.success) {
    // Mirrors loyalty-members.php's `location.reload()` after a successful give_by_phone —
    // refreshes the member list + overview stat cards.
    revalidatePath('/loyalty-members');
  }
  return result;
}

export async function memberDetailAction(userId: number): Promise<MemberDetailResult> {
  const { db, session } = await requireTenantPageContext();
  const lineAccountId = session.currentBotId ?? 0;
  return getMemberDetail(db, lineAccountId, userId);
}
