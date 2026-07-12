'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../users/_lib/session';
import { addPoints, deductPoints, recomputeAndPersistMemberTier } from './_lib/loyalty';

/**
 * actions.ts — Server Actions for /user-detail's two POST handlers
 * (user-detail.php lines 22-75):
 *
 *   - updateUserInfoAction -> action==='update_info': UPDATE users SET
 *     display_name/real_name/member_id/phone/email/birthday/gender/address/
 *     province/postal_code/note WHERE id=?, mirrored exactly. `note` is a
 *     single free-text column on the user row — there is no separate notes
 *     CRUD list on this page in the actual PHP source.
 *   - addPointsAction -> action==='add_points': reproduces
 *     LoyaltyPoints::addPoints()/deductPoints() + TierService::
 *     updateUserTier() byte-for-byte (see ./_lib/loyalty.ts's module doc for
 *     the one flagged, behavior-neutral simplification).
 *
 * Both are bound to their `<form action={...}>` with the userId pre-applied
 * via `.bind(null, userId)` (the standard Next.js pattern for passing extra
 * args to a form-bound Server Action alongside the FormData Next supplies) —
 * see page.tsx for the call sites.
 *
 * Intentional gap (flagged, not silently dropped): addPointsAction does not
 * write an ActivityLogger/TenantActivity audit row. classes/LoyaltyPoints.php's
 * addPoints() itself does write a best-effort `TenantActivity::log(...)`
 * entry (guarded by `@is_file(...)`); that write is NOT reproduced here —
 * ActivityLogger/TenantActivity audit writes are out of scope for this batch
 * per the brief.
 */

export async function updateUserInfoAction(userId: number, formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();

  const displayName = String(formData.get('display_name') ?? '').trim();
  const realName = String(formData.get('real_name') ?? '').trim();
  const memberIdRaw = String(formData.get('member_id') ?? '').trim();
  const phone = String(formData.get('phone') ?? '').trim();
  const email = String(formData.get('email') ?? '').trim();
  const birthdayRaw = String(formData.get('birthday') ?? '');
  const birthday = birthdayRaw !== '' ? birthdayRaw : null;
  const genderRaw = String(formData.get('gender') ?? '');
  const gender = genderRaw !== '' ? genderRaw : null;
  const address = String(formData.get('address') ?? '').trim();
  const province = String(formData.get('province') ?? '').trim();
  const postalCode = String(formData.get('postal_code') ?? '').trim();
  const note = String(formData.get('note') ?? '').trim();
  const memberId = memberIdRaw !== '' ? memberIdRaw : null;

  await sql`
    UPDATE users SET
      display_name = ${displayName}, real_name = ${realName}, member_id = ${memberId}, phone = ${phone},
      email = ${email}, birthday = ${birthday}, gender = ${gender}, address = ${address},
      province = ${province}, postal_code = ${postalCode}, note = ${note}
    WHERE id = ${userId}
  `.execute(db);

  redirect(`/user-detail?id=${userId}&updated=1`);
}

export async function addPointsAction(userId: number, formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const lineAccountId = session.currentBotId ?? 1;

  const points = Number.parseInt(String(formData.get('points') ?? '0'), 10) || 0;
  const descriptionRaw = String(formData.get('description') ?? '').trim();
  const description = descriptionRaw !== '' ? descriptionRaw : 'เพิ่มแต้มโดยแอดมิน';

  if (points !== 0) {
    try {
      if (points > 0) {
        await addPoints(db, userId, points, 'admin', null, description, lineAccountId);
      } else {
        await deductPoints(db, userId, Math.abs(points), 'admin_deduct', null, description, lineAccountId);
      }
      await recomputeAndPersistMemberTier(db, userId, lineAccountId);
    } catch (err) {
      // Mirrors user-detail.php's `catch (Exception $e) { error_log(...) }` — never surfaces to the user.
      console.error('Points adjustment error:', err);
    }
  }

  redirect(`/user-detail?id=${userId}&points_updated=1`);
}
