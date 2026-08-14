import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the
 * GET /api/inbox/actions/get-chat-content Route Handler (port of
 * `api/inbox-v2.php`'s `case 'get_chat_content':`, lines 3057-3163).
 *
 * `api/inbox-v2.php` itself has NO hard auth check ahead of its action
 * switch (it only reads `$_SESSION['current_bot_id']`/`$_SESSION['admin_id']`
 * opportunistically, lines 71-72) — but every already-merged Route Handler
 * ported from this same PHP file (`poll`, `get-admins`, `send-message`,
 * `get-assignment`, `mark-all-read`, ...) gates on a valid tenant session and
 * returns a JSON 401 rather than proceeding unauthenticated. This file
 * follows that established batch convention (see `poll/_lib/session.ts`'s
 * own doc comment, the canonical citation for this precedent), not a
 * literal PHP auth check. Requires any of the six TenantRole values —
 * `get_chat_content` has no page-specific role restriction in the PHP
 * source either.
 *
 * Duplicated (not imported) from every sibling action folder's own
 * `_lib/session.ts` copy — same "every consumer resolves its own session" rationale
 * documented on `poll/_lib/session.ts` and `get-assignment/_lib/session.ts`,
 * and this batch's ownership boundary keeps
 * `api/inbox/actions/{get-chat-content,send-batch-messages,upload-batch-file}/**`
 * independently editable from every other action family (including the
 * sibling mediaSend batch's `{send-image,upload-for-analysis,send-pdf}/**`
 * this same round) without cross-imports.
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
