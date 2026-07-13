import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * handlers.ts — port of `api/consent.php::handleSaveConsent()` ONLY (action=save; read `api/consent.php`
 * in full, 327 lines, before touching this file). `check`/`withdraw`/`history` are out of scope — zero
 * line-mini-app callers.
 *
 * See `packages/contracts/src/consent.ts`'s doc comment for the two flagged deviations this file makes:
 *   1. Tenant resolution uses the standard `resolveMiniappTenantContext()`/`withMiniappTenant()` helper
 *      (route.ts's concern, not this file's) — a deliberate deviation from `api/consent.php`, which is
 *      conspicuously missing `require_once bootstrap/route_by_account.php`.
 *   2. `ActivityLogger::logConsent()` (PHP calls this once per consent type, INSIDE the same transaction
 *      as the `user_consents` upserts) is DELIBERATELY NOT PORTED — no Next-side writer exists yet.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/consent.php's local `jsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

interface ExistingUserRow {
  id: number;
}

async function findUserIdByLineUserId(db: Kysely<TenantDB>, lineUserId: string): Promise<number | null> {
  const result = await sql<ExistingUserRow>`SELECT id FROM users WHERE line_user_id = ${lineUserId} LIMIT 1`.execute(db);
  return result.rows[0]?.id ?? null;
}

interface DefaultLineAccountRow {
  id: number;
}

/** `SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1` — falls back to `1` if none. */
async function resolveDefaultLineAccountId(db: Kysely<TenantDB>): Promise<number> {
  const result = await sql<DefaultLineAccountRow>`
    SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1
  `.execute(db);
  return result.rows[0]?.id ?? 1;
}

async function createUser(db: Kysely<TenantDB>, lineUserId: string): Promise<number> {
  const lineAccountId = await resolveDefaultLineAccountId(db);
  const result = await sql<never>`
    INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (${lineAccountId}, ${lineUserId}, 'LIFF User')
  `.execute(db);
  return Number(result.insertId ?? 0);
}

interface ShopSettingsVersions {
  privacy_policy_version: string | null;
  terms_version: string | null;
}

/** Column-existence-guarded read, matching PHP's `SHOW COLUMNS ... LIKE` + best-effort try/catch. */
async function getConsentVersions(db: Kysely<TenantDB>): Promise<ShopSettingsVersions> {
  const defaults: ShopSettingsVersions = { privacy_policy_version: '1.0', terms_version: '1.0' };
  try {
    const hasCol = await sql<{ Field: string }>`SHOW COLUMNS FROM shop_settings LIKE 'privacy_policy_version'`.execute(db);
    if (hasCol.rows.length === 0) return defaults;
    const result = await sql<ShopSettingsVersions>`SELECT privacy_policy_version, terms_version FROM shop_settings LIMIT 1`.execute(db);
    return result.rows[0] ?? defaults;
  } catch {
    return defaults;
  }
}

/** PHP's `$accepted ? 1 : 0` — truthy check across boolean/0/1/string inputs. */
function isAccepted(value: unknown): boolean {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') return value !== '' && value !== '0';
  return Boolean(value);
}

async function upsertConsent(
  db: Kysely<TenantDB>,
  userId: number,
  consentType: string,
  version: string,
  accepted: boolean,
  ipAddress: string | null,
  userAgent: string | null
): Promise<void> {
  await sql`
    INSERT INTO user_consents (user_id, consent_type, consent_version, is_accepted, accepted_at, ip_address, user_agent)
    VALUES (${userId}, ${consentType}, ${version}, ${accepted ? 1 : 0}, NOW(), ${ipAddress}, ${userAgent})
    ON DUPLICATE KEY UPDATE
      consent_version = VALUES(consent_version),
      is_accepted = VALUES(is_accepted),
      accepted_at = IF(VALUES(is_accepted) = 1, NOW(), accepted_at),
      withdrawn_at = IF(VALUES(is_accepted) = 0, NOW(), NULL),
      ip_address = VALUES(ip_address),
      user_agent = VALUES(user_agent),
      updated_at = NOW()
  `.execute(db);
}

/** Best-effort, column-existence-guarded — matches PHP's own separately-caught try/catch block. */
async function updateUserConsentFlags(db: Kysely<TenantDB>, userId: number, consents: Record<string, unknown>): Promise<void> {
  try {
    const hasCol = await sql<{ Field: string }>`SHOW COLUMNS FROM users LIKE 'consent_privacy'`.execute(db);
    if (hasCol.rows.length === 0) return;

    await sql`
      UPDATE users SET
        consent_privacy = ${isAccepted(consents.privacy_policy) ? 1 : 0},
        consent_terms = ${isAccepted(consents.terms_of_service) ? 1 : 0},
        consent_health_data = ${isAccepted(consents.health_data) ? 1 : 0},
        consent_date = NOW()
      WHERE id = ${userId}
    `.execute(db);
  } catch {
    // Ignore if columns don't exist — matches PHP's catch (Exception $e) {}.
  }
}

export async function handleSaveConsent(
  db: Kysely<TenantDB>,
  lineUserId: string,
  consents: Record<string, unknown>,
  ipAddress: string | null,
  userAgent: string | null
): Promise<ActionResult> {
  if (!lineUserId) {
    return ok(false, 'LINE User ID required');
  }

  let userId = await findUserIdByLineUserId(db, lineUserId);
  if (userId === null) {
    userId = await createUser(db, lineUserId);
  }

  const versions = await getConsentVersions(db);
  const versionMap: Record<string, string> = {
    privacy_policy: versions.privacy_policy_version ?? '1.0',
    terms_of_service: versions.terms_version ?? '1.0',
    health_data: '1.0',
    marketing: '1.0',
  };

  await db.transaction().execute(async (trx) => {
    for (const [type, rawAccepted] of Object.entries(consents)) {
      const version = versionMap[type] ?? '1.0';
      await upsertConsent(trx, userId as number, type, version, isAccepted(rawAccepted), ipAddress, userAgent);
    }

    // NOTE: ActivityLogger::logConsent() (PHP calls this here, per consent type, INSIDE this same
    // transaction — a throw here rolls back the whole save) is deliberately NOT ported. See this file's
    // module doc comment + packages/contracts/src/consent.ts's doc comment for the full decision writeup.

    await updateUserConsentFlags(trx, userId as number, consents);
  });

  return ok(true, 'Consent saved', { user_id: userId });
}
