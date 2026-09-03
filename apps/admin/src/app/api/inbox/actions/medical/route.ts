import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';

/**
 * POST /api/inbox/actions/medical — literal port of inbox-v2.php's
 * `case 'save_medical':` (lines 444-464), the same-page AJAX action gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original.
 *
 * ```php
 * case 'save_medical':
 *     $userId = intval($_POST['user_id'] ?? 0);
 *     $medicalConditions = trim($_POST['medical_conditions'] ?? '');
 *     $drugAllergies = trim($_POST['drug_allergies'] ?? '');
 *     $currentMedications = trim($_POST['current_medications'] ?? '');
 *     $stmt = $db->prepare("UPDATE users SET medical_conditions = ?, drug_allergies = ?, current_medications = ? WHERE id = ?");
 *     $stmt->execute([$medicalConditions, $drugAllergies, $currentMedications, $userId]);
 *
 *     $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'อัพเดทข้อมูลทางการแพทย์', [
 *         'user_id' => $userId,
 *         'entity_type' => 'user',
 *         'entity_id' => $userId,
 *         'new_value' => [
 *             'medical_conditions' => $medicalConditions,
 *             'drug_allergies' => $drugAllergies,
 *             'current_medications' => $currentMedications
 *         ]
 *     ]);
 *
 *     echo json_encode(['success' => true]);
 *     break;
 * ```
 *
 * Every field is `trim($_POST[...] ?? '')` — an absent/undefined field
 * becomes an empty string, NOT null/undefined and NOT "leave unchanged"
 * (this UPDATE always overwrites all three columns, even a partial payload
 * blanks out the fields it didn't include — matches PHP exactly).
 *
 * ActivityLogger::logData() calls ActivityLogger::log(TYPE_DATA='data',
 * ACTION_UPDATE='update', ...) (classes/ActivityLogger.php lines 171-174).
 * The options array omits `admin_id`/`admin_name`/`line_account_id`, so
 * ActivityLogger::log() falls back to `$_SESSION['admin_id'] ?? null` /
 * `$_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? null` /
 * `$_SESSION['current_bot_id'] ?? null` (lines 133-134, 142) — the direct TS
 * equivalents are `session.adminUserId`, `session.username`, and
 * `session.currentBotId ?? null`.
 *
 * No input validation (no `if (!userId)` guard) — matches PHP: a
 * missing/zero user_id is intval()'d to 0 and used as-is (an UPDATE ... WHERE
 * id = 0 simply matches zero rows; PHP does not check the affected-row
 * count either). Any resulting DB error surfaces via the outer try/catch
 * below, mirroring inbox-v2.php's own outer
 * `catch (Exception $e) { http_response_code(400); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }`
 * (lines 982-985).
 */

/** PHP's `intval($v ?? 0)` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/** `trim($v ?? '')` — coerces non-string inputs to string first (JSON bodies may carry non-strings). */
function trimOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value.trim();
  if (value === undefined || value === null) return '';
  return String(value).trim();
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;

  try {
    const body: Record<string, unknown> = await request.json().catch(() => ({}));
    const userId = intval(body.user_id ?? 0);
    const medicalConditions = trimOrEmpty(body.medical_conditions ?? '');
    const drugAllergies = trimOrEmpty(body.drug_allergies ?? '');
    const currentMedications = trimOrEmpty(body.current_medications ?? '');

    await db
      .updateTable('users')
      .set({
        medical_conditions: medicalConditions,
        drug_allergies: drugAllergies,
        current_medications: currentMedications,
      })
      .where('id', '=', userId)
      .execute();

    await db
      .insertInto('activity_logs')
      .values({
        log_type: 'data',
        action: 'update',
        description: 'อัพเดทข้อมูลทางการแพทย์',
        user_id: userId,
        entity_type: 'user',
        entity_id: userId,
        new_value: JSON.stringify({
          medical_conditions: medicalConditions,
          drug_allergies: drugAllergies,
          current_medications: currentMedications,
        }),
        admin_id: session.adminUserId,
        admin_name: session.username,
        line_account_id: session.currentBotId ?? null,
      })
      .execute();

    return NextResponse.json({ success: true });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: message }, { status: 400 });
  }
}
