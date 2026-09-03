'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';

/**
 * welcome-actions.ts — Server Action for settings.php's `action ===
 * 'save_welcome'` handler (settings.php lines 484-516), reached via
 * welcome.php's `<form id="welcomeForm" method="POST"
 * action="settings.php?tab=welcome">`:
 *
 *   $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *   $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
 *   $messageType = $_POST['message_type'] ?? 'text';
 *   $textContent = $_POST['text_content'] ?? '';
 *   $flexContent = $_POST['flex_content'] ?? '';
 *   try {
 *       $exists = SELECT id FROM welcome_settings
 *           WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL);
 *       if ($exists) { UPDATE ... WHERE id = ?; } else { INSERT ...; }
 *       $success = 'บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!';
 *       $activityLogger->logData(...); // NOT reproduced, see below
 *   } catch (Exception $e) {
 *       $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
 *   }
 *   $activeTab = 'welcome'; // falls through to re-render the SAME request,
 *                           // no redirect() in real PHP.
 *
 * Next has no "re-render the same POST response" primitive for a
 * `<form action={serverAction}>` submission the way PHP's single-request
 * model does — mirrors (tenant)/line-groups/actions.ts's and
 * (tenant)/user-detail/actions.ts's established convention instead:
 * redirect back to `/settings?tab=welcome` with a `?message=`/`?error=`
 * search param carrying the exact same Thai banner text PHP would have
 * shown, so the re-fetched page shows the just-written row (via
 * ./welcome-queries.ts's getWelcomeSettings()) plus the flash banner.
 *
 * CONFIRMED FINDING (see welcome-queries.ts's module doc for detail):
 * `welcome_settings` does not exist on the committed tenant schema, so on a
 * fresh tenant DB the `try` block below ALWAYS throws on the `SELECT id ...`
 * probe — exactly like real PHP's `catch (Exception $e)` — and this action
 * ALWAYS redirects down the `?error=` path in practice. This is the
 * documented, intentional "missing-table degrade" behavior this batch's
 * brief calls out, not a bug to fix (database/** is out of scope; do not
 * add a CREATE TABLE migration here).
 *
 * `redirect()` is deliberately called OUTSIDE the try/catch below (same
 * reasoning documented in (tenant)/line-groups/actions.ts's module doc:
 * `redirect()` works by throwing a special Next-internal error to abort
 * rendering — wrapping it in the same try would require detecting and
 * re-throwing that internal error to avoid mis-reporting a successful write
 * as a failure).
 *
 * Intentional gap (flagged, not silently dropped): PHP's
 * `$activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าข้อความต้อนรับ', ...)`
 * audit write is NOT reproduced here — ActivityLogger/TenantActivity
 * best-effort audit writes are out of scope for this batch, matching every
 * other ported Phase 2 action (see (tenant)/users/actions.ts's own note).
 */

const SUCCESS_MESSAGE = 'บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!';

export async function saveWelcomeSettingsAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId;

  const isEnabled = formData.get('is_enabled') !== null ? 1 : 0;
  const messageTypeRaw = String(formData.get('message_type') ?? 'text');
  const messageType = messageTypeRaw === 'flex' ? 'flex' : 'text';
  const textContent = String(formData.get('text_content') ?? '');
  const flexContent = String(formData.get('flex_content') ?? '');

  let errorMessage: string | null = null;
  try {
    const existing = await sql<{ id: number }>`
      SELECT id FROM welcome_settings
      WHERE line_account_id = ${currentBotId} OR (line_account_id IS NULL AND ${currentBotId} IS NULL)
    `.execute(db);
    const row = existing.rows[0];

    if (row) {
      await sql`
        UPDATE welcome_settings
        SET is_enabled = ${isEnabled}, message_type = ${messageType}, text_content = ${textContent}, flex_content = ${flexContent}
        WHERE id = ${row.id}
      `.execute(db);
    } else {
      await sql`
        INSERT INTO welcome_settings (line_account_id, is_enabled, message_type, text_content, flex_content)
        VALUES (${currentBotId}, ${isEnabled}, ${messageType}, ${textContent}, ${flexContent})
      `.execute(db);
    }
  } catch (err) {
    errorMessage = err instanceof Error ? err.message : String(err);
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=welcome&error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${errorMessage}`)}`);
  }

  redirect(`/settings?tab=welcome&message=${encodeURIComponent(SUCCESS_MESSAGE)}`);
}
