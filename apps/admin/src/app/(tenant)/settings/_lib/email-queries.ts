import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * email-queries.ts — port of includes/settings/email.php's read block
 * (lines 7-28):
 *
 *   try { CREATE TABLE IF NOT EXISTS email_settings (...) } catch (Exception $e) {}
 *   $emailSettings = [];
 *   try {
 *       $stmt = $db->query("SELECT * FROM email_settings WHERE id = 1");
 *       $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
 *   } catch (Exception $e) {}
 *
 * `email_settings` IS present in database/migration_2026-05-25_tenant_template.sql
 * (line ~4380: `id` PK, `line_account_id` NOT NULL DEFAULT 1, smtp_host/
 * smtp_port/smtp_user/smtp_pass/smtp_secure/from_email/from_name) and in the
 * generated packages/db/src/generated/tenant-db.d.ty (`EmailSettings`) — no
 * missing-table gap here (unlike welcome_settings). The page-load
 * `CREATE TABLE IF NOT EXISTS` guard is NOT reproduced (nothing to guard
 * against on the committed schema; database/** is out of scope regardless).
 *
 * The read itself is UNSCOPED by tenant (`WHERE id = 1`, no
 * `line_account_id` filter at all) — a single global settings row per
 * tenant DB (each tenant has its own database under ADR-001, so "id=1" is
 * already tenant-scoped one level up, at the connection). Reproduced
 * verbatim: no `currentBotId`/`lineAccountId` parameter on this function.
 *
 * Uses the raw `sql` tagged-template escape hatch, not Kysely's typed
 * `.selectFrom()` builder, for consistency with the rest of this codebase's
 * tenant-DB reads (no CamelCasePlugin is registered on the shared
 * `Kysely<TenantDB>` instance — see (tenant)/users/actions.ts's module doc —
 * so even though `email_settings` DOES have generated snake_case-named
 * fields that would technically type-check with the builder, every other
 * settings-adjacent read in this repo goes through `sql` directly; this
 * file does the same rather than being the one file using a different
 * access pattern).
 */

export interface EmailSettings {
  smtpHost: string;
  smtpPort: number;
  smtpUser: string;
  smtpPass: string;
  smtpSecure: string;
  fromEmail: string;
  fromName: string;
}

/** Matches the PHP form's own HTML `value="..."` fallbacks for a genuinely empty table (no row yet). */
export const DEFAULT_EMAIL_SETTINGS: EmailSettings = {
  smtpHost: '',
  smtpPort: 587,
  smtpUser: '',
  smtpPass: '',
  smtpSecure: 'tls',
  fromEmail: '',
  fromName: 'Notification',
};

interface EmailSettingsRow {
  id: number;
  line_account_id: number | null;
  smtp_host: string | null;
  smtp_port: number | null;
  smtp_user: string | null;
  smtp_pass: string | null;
  smtp_secure: string | null;
  from_email: string | null;
  from_name: string | null;
}

export async function getEmailSettings(db: Kysely<TenantDB>): Promise<EmailSettings> {
  try {
    const result = await sql<EmailSettingsRow>`SELECT * FROM email_settings WHERE id = 1`.execute(db);
    const row = result.rows[0];
    if (!row) {
      return DEFAULT_EMAIL_SETTINGS;
    }
    return {
      smtpHost: row.smtp_host ?? '',
      smtpPort: row.smtp_port ?? 587,
      smtpUser: row.smtp_user ?? '',
      smtpPass: row.smtp_pass ?? '',
      smtpSecure: row.smtp_secure ?? 'tls',
      fromEmail: row.from_email ?? '',
      fromName: row.from_name ?? 'Notification',
    };
  } catch {
    // Mirrors PHP's `catch (Exception $e) {}` leaving `$emailSettings = []`.
    return DEFAULT_EMAIL_SETTINGS;
  }
}
