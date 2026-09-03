import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the POST /api/inbox/actions/send-image Route
 * Handler. Ports the auth gate that guards inbox-v2.php's `case 'send_image':` (lines 736-834),
 * the same-page AJAX action gated on `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original
 * (inbox-v2.php line ~991) — that gate itself runs only after `includes/header.php` has already
 * required a logged-in admin session for the page. This Route Handler is reachable directly (not
 * wrapped by `(tenant)/layout.tsx`, which only gates page renders), so it must perform that check
 * itself.
 *
 * Requires any of the six TenantRole values — `case 'send_image':` has no page-specific role
 * restriction beyond "any authenticated admin" (same as send_message/dispense).
 *
 * Duplicated (not imported) from send-message/_lib/session.ts, dispense/_lib/session.ts, etc. —
 * established repo precedent ("every consumer resolves its own session", see those files' own
 * doc comments) and this batch's ownership boundary (mediaSend owns
 * apps/admin/src/app/api/inbox/actions/{send-image,upload-for-analysis,send-pdf}/** exclusively)
 * keeps this folder independently editable without reaching into already-merged sibling-action
 * territory.
 */

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export interface InboxApiContext {
  session: TenantSession & { tenantId: number };
  db: Kysely<TenantDB>;
}

export type InboxApiAuthResult = { ok: true; value: InboxApiContext } | { ok: false; status: 401 };

export async function resolveInboxApiContext(): Promise<InboxApiAuthResult> {
  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;

  const rawSession = await getSession(sid, 'tenant');
  const tenantSession = rawSession && rawSession.realm === 'tenant' ? rawSession : null;

  const result = requireRole<TenantSession>(tenantSession, TENANT_ROLES);
  if (!result.ok || result.value.tenantId === null) {
    return { ok: false, status: 401 };
  }

  const session = result.value as TenantSession & { tenantId: number };
  const db = await getTenantDb(session.tenantId);
  return { ok: true, value: { session, db } };
}
