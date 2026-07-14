import { sql } from 'kysely';
import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';

/**
 * POST /api/inbox/actions/notes — literal port of inbox-v2.php's
 * `case 'save_note':` (lines 414-429), the same-page AJAX action gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original.
 *
 * ```php
 * case 'save_note':
 *     $userId = intval($_POST['user_id'] ?? 0);
 *     $note = trim($_POST['note'] ?? '');
 *     $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_at) VALUES (?, ?, NOW())");
 *     $stmt->execute([$userId, $note]);
 *     $noteId = $db->lastInsertId();
 *
 *     $activityLogger->logData(ActivityLogger::ACTION_CREATE, 'เพิ่มโน้ตลูกค้า', [
 *         'user_id' => $userId,
 *         'entity_type' => 'user_note',
 *         'entity_id' => $noteId,
 *         'new_value' => ['note' => mb_substr($note, 0, 100)]
 *     ]);
 *
 *     echo json_encode(['success' => true, 'id' => $noteId]);
 *     break;
 * ```
 *
 * `line_account_id` IS DELIBERATELY OMITTED from the INSERT below — PHP's
 * prepared statement never binds it either. The column is
 * `NOT NULL DEFAULT 1` in the tenant template schema, so omitting it lets
 * MySQL apply that default; this is the literal, byte-for-byte-correct port
 * of the PHP source, not an oversight.
 *
 * ActivityLogger::logData() calls ActivityLogger::log(TYPE_DATA='data',
 * ACTION_CREATE='create', ...) (classes/ActivityLogger.php lines 171-174).
 * The options array above omits `admin_id`/`admin_name`/`line_account_id`,
 * so ActivityLogger::log() falls back to
 * `$_SESSION['admin_id'] ?? null` / `$_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? null`
 * / `$_SESSION['current_bot_id'] ?? null` (lines 133-134, 142) — the direct
 * TS equivalents are `session.adminUserId`, `session.username`, and
 * `session.currentBotId ?? null`.
 *
 * No input validation (no `if (!userId)` guard) — matches PHP: a
 * missing/zero user_id is intval()'d to 0 and an empty note trims to '';
 * both are used as-is. Any resulting DB error surfaces via the outer
 * try/catch below, mirroring inbox-v2.php's own outer
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

/** `mb_substr($note, 0, 100)` — code-point-safe truncation (not UTF-16-code-unit slicing, which would split astral code points like emoji mid-character). */
function mbSubstr100(value: string): string {
  return Array.from(value).slice(0, 100).join('');
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
    const note = trimOrEmpty(body.note ?? '');

    const insertResult = await db
      .insertInto('user_notes')
      // line_account_id intentionally omitted — see module doc comment above.
      .values({ user_id: userId, note, created_at: sql`NOW()` })
      .executeTakeFirstOrThrow();
    const noteId = Number(insertResult.insertId ?? 0);

    await db
      .insertInto('activity_logs')
      .values({
        log_type: 'data',
        action: 'create',
        description: 'เพิ่มโน้ตลูกค้า',
        user_id: userId,
        entity_type: 'user_note',
        entity_id: noteId,
        new_value: JSON.stringify({ note: mbSubstr100(note) }),
        admin_id: session.adminUserId,
        admin_name: session.username,
        line_account_id: session.currentBotId ?? null,
      })
      .execute();

    return NextResponse.json({ success: true, id: noteId });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: message }, { status: 400 });
  }
}
