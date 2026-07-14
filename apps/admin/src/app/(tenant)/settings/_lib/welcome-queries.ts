import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * welcome-queries.ts — port of includes/settings/welcome.php's read block
 * (lines 7-31):
 *
 *   $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *   try {
 *       $stmt = $db->prepare("SELECT * FROM welcome_settings
 *           WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL) LIMIT 1");
 *       $stmt->execute([$currentBotId, $currentBotId]);
 *       $welcomeSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
 *   } catch (PDOException $e) {
 *       $welcomeSettings = [];
 *   }
 *   if (!$welcomeSettings) {
 *       $welcomeSettings = [ is_enabled=>0, message_type=>'text', text_content=>'<default greeting>', flex_content=>'' ];
 *   }
 *   $isEnabled = $welcomeSettings['is_enabled'] ?? 0;
 *   $messageType = $welcomeSettings['message_type'] ?? 'text';
 *   $textContent = $welcomeSettings['text_content'] ?? '';
 *   $flexContent = $welcomeSettings['flex_content'] ?? '';
 *
 * IMPORTANT — this page's OWN local `$currentBotId` (`?? null`) shadows
 * settings.php's outer `$currentBotId = $_SESSION['current_bot_id'] ?? 1`
 * (the outer default is 1, this partial's is null) — PHP `include` shares
 * the caller's scope, so this reassignment is real for the duration of
 * rendering this tab. Mirrored by passing `session.currentBotId` (already
 * `number | null`, the Next equivalent of the raw, un-defaulted
 * `$_SESSION['current_bot_id']`) straight through — NOT defaulted to 1.
 *
 * CONFIRMED FINDING: `welcome_settings` does not exist anywhere in
 * database/migration_2026-05-25_tenant_template.sql (zero `CREATE TABLE`
 * matches) or in the generated packages/db/src/generated/tenant-db.d.ts, and
 * is not auto-created by any PHP file (grepped). On the committed schema the
 * `try` block above ALWAYS throws (unknown table), so `getWelcomeSettings()`
 * ALWAYS returns the hardcoded defaults in practice — this is the normal
 * path on a fresh tenant DB, not a rare edge case. Replicated here with the
 * same shape: any query failure (including "table doesn't exist") falls
 * back to the exact same default object PHP's `if (!$welcomeSettings)`
 * block produces. Do NOT "fix" this by adding a CREATE TABLE migration —
 * database/** is out of scope for this batch and the degrade behavior is
 * the point being ported, not a bug.
 *
 * Uses the raw `sql` tagged-template escape hatch, not Kysely's typed
 * `.selectFrom()` builder — `welcome_settings` isn't even in the generated
 * TenantDB schema (see above), so there is no typed table to build against;
 * matches the rest of this codebase's established convention for
 * untyped/defensive tenant-DB reads (see (tenant)/crm-dashboard-advanced/
 * queries.ts's module doc and (tenant)/users/queries.ts's own note on why
 * `Kysely<TenantDB>` here has no CamelCasePlugin).
 */

export interface WelcomeSettings {
  isEnabled: boolean;
  messageType: 'text' | 'flex';
  textContent: string;
  flexContent: string;
}

/** Verbatim port of welcome.php's hardcoded default greeting (lines 19-25). */
export const DEFAULT_WELCOME_SETTINGS: WelcomeSettings = {
  isEnabled: false,
  messageType: 'text',
  textContent:
    'สวัสดีค่ะ ยินดีต้อนรับ! 🎉\n\nขอบคุณที่เพิ่มเราเป็นเพื่อน\nหากต้องการความช่วยเหลือ สามารถพิมพ์ข้อความมาได้เลยค่ะ',
  flexContent: '',
};

interface WelcomeSettingsRow {
  id: number;
  line_account_id: number | null;
  is_enabled: number | null;
  message_type: string | null;
  text_content: string | null;
  flex_content: string | null;
}

export async function getWelcomeSettings(db: Kysely<TenantDB>, currentBotId: number | null): Promise<WelcomeSettings> {
  try {
    const result = await sql<WelcomeSettingsRow>`
      SELECT * FROM welcome_settings
      WHERE line_account_id = ${currentBotId} OR (line_account_id IS NULL AND ${currentBotId} IS NULL)
      LIMIT 1
    `.execute(db);

    const row = result.rows[0];
    if (!row) {
      // Mirrors `$stmt->fetch(...) ?: []` -> `if (!$welcomeSettings)` -> defaults.
      return DEFAULT_WELCOME_SETTINGS;
    }

    // Mirrors the PER-FIELD `?? ` fallbacks PHP applies to a REAL row (NOT
    // the full hardcoded-greeting default block, which only fires when no
    // row was found at all).
    return {
      isEnabled: Boolean(row.is_enabled ?? 0),
      messageType: row.message_type === 'flex' ? 'flex' : 'text',
      textContent: row.text_content ?? '',
      flexContent: row.flex_content ?? '',
    };
  } catch {
    // Mirrors `catch (PDOException $e) { $welcomeSettings = []; }` -> defaults.
    return DEFAULT_WELCOME_SETTINGS;
  }
}
