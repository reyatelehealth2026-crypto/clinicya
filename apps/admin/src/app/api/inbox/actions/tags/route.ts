import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';

/**
 * POST /api/inbox/actions/tags — literal port of inbox-v2.php's
 * `case 'update_tags':` (lines 397-412), the same-page AJAX action gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original.
 *
 * ```php
 * case 'update_tags':
 *     $userId = intval($_POST['user_id'] ?? 0);
 *     $tagId = intval($_POST['tag_id'] ?? 0);
 *     $operation = $_POST['operation'] ?? 'add';
 *
 *     if ($operation === 'add') {
 *         $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')");
 *         $stmt->execute([$userId, $tagId]);
 *     } else {
 *         $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
 *         $stmt->execute([$userId, $tagId]);
 *     }
 *     $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?");
 *     $stmt->execute([$userId]);
 *     echo json_encode(['success' => true, 'tags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
 *     break;
 * ```
 *
 * BRANCHING IS LITERAL, NOT AN ENUM CHECK: PHP tests `=== 'add'`, so any
 * non-'add' value — 'remove', an empty string, garbage — falls into the
 * plain `else` (delete) branch. Reproduced the same way below: no allow-list,
 * just `operation === 'add' ? insert : delete`.
 *
 * NO ActivityLogger CALL — verified directly against inbox-v2.php's source,
 * there is no ActivityLogger invocation anywhere in the update_tags case
 * (unlike save_note/delete_note/save_medical, which each write one
 * activity_logs row). Do not add one here; the tags route.test.ts explicitly
 * asserts zero activity_logs writes for both operations.
 *
 * `line_account_id` IS DELIBERATELY OMITTED from the INSERT IGNORE below —
 * PHP's prepared statement never binds it either. The column is
 * `NOT NULL DEFAULT 1` in the tenant template schema, so omitting it lets
 * MySQL apply that default; this is the literal, byte-for-byte-correct port
 * of the PHP source, not an oversight, even though it looks like one might
 * expect this write to be tenant/line-account scoped like most other writes
 * in this app.
 *
 * `t.*` in PHP is the FULL user_tags row (all columns), not a curated
 * subset — reproduced via `.selectAll('t')`, NOT narrowed to
 * id/name/color (that narrower shape belongs to a different, unrelated
 * read-path query in (tenant)/inbox/[userId]/_lib/queries.ts).
 *
 * No input validation (no `if (!userId || !tagId)` guard) — matches PHP: a
 * missing/zero user_id or tag_id is intval()'d to 0 and used as-is; any
 * resulting DB error (e.g. FK violation) surfaces via the outer try/catch
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

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db } = auth.value;

  try {
    const body: Record<string, unknown> = await request.json().catch(() => ({}));
    const userId = intval(body.user_id ?? 0);
    const tagId = intval(body.tag_id ?? 0);
    // `$_POST['operation'] ?? 'add'` — isset()-based default, then a strict `=== 'add'` check.
    const operation = body.operation ?? 'add';

    if (operation === 'add') {
      await db
        .insertInto('user_tag_assignments')
        // line_account_id intentionally omitted — see module doc comment above.
        .values({ user_id: userId, tag_id: tagId, assigned_by: 'manual' })
        .ignore()
        .execute();
    } else {
      await db.deleteFrom('user_tag_assignments').where('user_id', '=', userId).where('tag_id', '=', tagId).execute();
    }

    const tags = await db
      .selectFrom('user_tags as t')
      .innerJoin('user_tag_assignments as uta', 'uta.tag_id', 't.id')
      .where('uta.user_id', '=', userId)
      .selectAll('t')
      .execute();

    return NextResponse.json({ success: true, tags });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: message }, { status: 400 });
  }
}
