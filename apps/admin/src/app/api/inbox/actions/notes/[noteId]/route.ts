import { NextResponse } from 'next/server';
import { resolveInboxApiContext } from '../_lib/session';

/**
 * DELETE /api/inbox/actions/notes/[noteId] — literal port of inbox-v2.php's
 * `case 'delete_note':` (lines 431-442), the same-page AJAX action gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original.
 *
 * ```php
 * case 'delete_note':
 *     $noteId = intval($_POST['note_id'] ?? 0);
 *     $stmt = $db->prepare("DELETE FROM user_notes WHERE id = ?");
 *     $stmt->execute([$noteId]);
 *
 *     $activityLogger->logData(ActivityLogger::ACTION_DELETE, 'ลบโน้ตลูกค้า', [
 *         'entity_type' => 'user_note',
 *         'entity_id' => $noteId
 *     ]);
 *
 *     echo json_encode(['success' => true]);
 *     break;
 * ```
 *
 * ROUTING SHAPE DEVIATES FROM PHP DELIBERATELY: PHP reads `$_POST['note_id']`
 * (a same-page AJAX POST body field); this port takes `noteId` from the
 * dynamic route segment instead — a DELETE-by-id sub-resource route is the
 * more idiomatic Next.js shape, and this batch's brief favors decomposing
 * routing/markup over transliterating it, while preserving DB-level
 * behavior byte-for-byte.
 *
 * PERMISSIVE-BY-DESIGN: PHP does NOT check whether the DELETE actually
 * matched a row (no 404, no rowCount() check) — it always responds
 * `{success: true}} even if `note_id` matched zero rows. Preserved literally
 * below: this handler never inspects the delete's affected-row count and
 * always returns `{success: true}` (modulo the outer try/catch's own
 * unrelated DB-error path).
 *
 * ASYMMETRY WITH save_note IS DELIBERATE, NOT A BUG: unlike save_note's
 * logData() options (which include `user_id`), delete_note's options array
 * has NO `user_id` key at all — the activity_logs.user_id column stays NULL
 * for this specific call. Reproduced below by simply never including a
 * `user_id` key in the `.values({...})` object (Kysely then omits that
 * column from the compiled INSERT entirely, so MySQL applies its nullable
 * default of NULL) — do not "fix" this by adding one back in.
 *
 * ActivityLogger::logData() calls ActivityLogger::log(TYPE_DATA='data',
 * ACTION_DELETE='delete', ...) (classes/ActivityLogger.php lines 171-174).
 * The options array omits `admin_id`/`admin_name`/`line_account_id`, so
 * ActivityLogger::log() falls back to `$_SESSION['admin_id'] ?? null` /
 * `$_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? null` /
 * `$_SESSION['current_bot_id'] ?? null` (lines 133-134, 142) — the direct TS
 * equivalents are `session.adminUserId`, `session.username`, and
 * `session.currentBotId ?? null`.
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

interface RouteParams {
  params: Promise<{ noteId: string }>;
}

export async function DELETE(_request: Request, { params }: RouteParams): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;

  try {
    const { noteId: noteIdParam } = await params;
    const noteId = intval(noteIdParam);

    await db.deleteFrom('user_notes').where('id', '=', noteId).execute();

    // No `user_id` key — see module doc comment above (asymmetry with save_note is deliberate).
    await db
      .insertInto('activity_logs')
      .values({
        log_type: 'data',
        action: 'delete',
        description: 'ลบโน้ตลูกค้า',
        entity_type: 'user_note',
        entity_id: noteId,
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
