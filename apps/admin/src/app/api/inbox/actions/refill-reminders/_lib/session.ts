import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the /api/inbox/actions/refill-reminders
 * Route Handler (port of api/inbox-v2.php's
 * `case 'refill_reminders': case 'refill-reminders': case 'get_refill_reminders':`, lines ~1410-1438, backed by
 * `DrugRecommendEngineService::getRefillReminders()`).
 *
 * Same "who is logged in + which tenant DB" gate as
 * api/inbox/actions/get-admins/_lib/session.ts and every sibling
 * actions/{tags,notes,medical,send-message,assign-conversation}/_lib/session.ts
 * copy (all mirror inbox-v2.php's `require_once 'includes/header.php'` gate —
 * any authenticated admin, no page-specific role restriction). Returns a
 * JSON-shaped auth result rather than redirect()ing — this is a same-page
 * AJAX action endpoint (inbox-v2.php's action switch), consumed by
 * client-side `fetch()`, not a page render, so an unauthenticated/expired
 * session request must get back a JSON 401 the caller can react to, not an
 * HTML redirect response.
 *
 * Duplicated (not imported) from the sibling copies — same "every consumer
 * resolves its own session" rationale documented on get-admins/_lib/session.ts,
 * and this batch's ownership boundary keeps
 * api/inbox/actions/{medical-history,patient-profile,check-allergy,
 * prescription-history,refill-reminders}/** independently editable from
 * sibling action families without cross-imports (the deliberate exception
 * being cross-imports WITHIN this same batch — see e.g.
 * patient-profile/route.ts's own doc for the `getUserMedicalHistory`/
 * `getUserPrescriptionHistory` cross-imports, mirroring the precedent set by
 * Phase 4 batch 4a's drug-info -> max-discount import).
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
