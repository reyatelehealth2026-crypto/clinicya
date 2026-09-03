import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { loadTemplateService, type UpdateTemplatePayload } from './_lib/updateTemplate';

/**
 * POST /api/inbox/actions/update-template — literal port of
 * `api/inbox-v2.php`'s `case 'update_template':` (lines 2277-2325):
 *
 * ```php
 * case 'update_template':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $id = (int) ($_POST['id'] ?? $body['id'] ?? 0);
 *
 *     if (!$id) {
 *         sendError('Template ID is required');
 *     }
 *
 *     $data = [];
 *     if (isset($_POST['name']) || isset($body['name']))
 *         $data['name'] = $_POST['name'] ?? $body['name'];
 *     if (isset($_POST['content']) || isset($body['content']))
 *         $data['content'] = $_POST['content'] ?? $body['content'];
 *     if (isset($_POST['category']) || isset($body['category']))
 *         $data['category'] = $_POST['category'] ?? $body['category'];
 *     if (isset($_POST['quick_reply']) || isset($body['quick_reply'])) {
 *         $val = $_POST['quick_reply'] ?? $body['quick_reply'];
 *         $data['quick_reply'] = ($val === '') ? null : $val;
 *     }
 *
 *     if (empty($data)) {
 *         sendError('No data to update');
 *     }
 *
 *     $templateService = loadService('TemplateService', $db, $lineAccountId);
 *     if (!$templateService) {
 *         sendError('Template service not available', 503);
 *     }
 *
 *     try {
 *         $success = $templateService->updateTemplate($id, $data);
 *         if ($success) {
 *             sendResponse(['success' => true, 'message' => 'Template updated successfully']);
 *         } else {
 *             sendError('Failed to update template');
 *         }
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to update template: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `id = (int)(body.id ?? 0)`; falsy -> 400 `'Template ID is required'`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * PAYLOAD BUILD — PHP `isset()` SEMANTICS EXACTLY, PER FIELD, INDEPENDENTLY
 * ═══════════════════════════════════════════════════════════════════════
 * `$_POST` is dead for every real JSON caller (see `../customer-crm/route.ts`'s
 * doc for the same rationale) — only `isset($body[key])` matters here. PHP's
 * `isset()` on an array key is `false` for BOTH a genuinely absent key AND a
 * key present with an explicit `null` value — `hasIssetKey()` below
 * replicates that exactly (`key in body && body[key] !== null`), NOT a bare
 * `key in body` check. This applies independently to `name`, `content`,
 * `category`, and `quick_reply`.
 *
 * `quick_reply` carries one extra rule, applied ONLY when the `isset()`
 * check above already passed (so `body.quick_reply` is guaranteed non-null
 * at this point): an empty-string value (`''`, exactly) is coerced to
 * `null` before being placed in the payload. The two cases this produces
 * are genuinely, easily-swapped opposites:
 *   - `{quick_reply: ''}`   -> `isset()` TRUE (not null) -> payload gets
 *      `quick_reply: null` -> the UPDATE's `SET quick_reply = ?` runs with
 *      a `null` param -> the column is CLEARED.
 *   - `{quick_reply: null}` -> `isset()` FALSE (PHP's own null-is-unset
 *      rule) -> the key is NEVER added to the payload at all -> the column
 *      is NOT included in the `SET` clause -> left COMPLETELY UNTOUCHED.
 * `route.test.ts` has a dedicated test proving both directions.
 *
 * `empty($data)` on the assembled payload -> 400 `'No data to update'` —
 * checked BEFORE the service-availability gate, matching PHP's own
 * statement order.
 *
 * `loadTemplateService()` — the mockable port of PHP's `loadService(
 * 'TemplateService', $db, $lineAccountId)` — see `_lib/updateTemplate.ts`'s
 * module doc for why the `503` branch it backs is defensive-only, never
 * reachable on real traffic, and only exercised via an explicit
 * `jest.mock` in `route.test.ts`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `$success === false` -> 400 `'Failed to update template'` — NOT 404
 * ═══════════════════════════════════════════════════════════════════════
 * `updateTemplate()` returns `false` (rather than throwing) exactly when
 * `getById($id)` (scoped to `line_account_id`) finds no row — a template
 * that doesn't exist, or exists but belongs to a different LINE account.
 * PHP's `sendError('Failed to update template')` uses `sendError()`'s
 * DEFAULT status code, which is `400`, NOT `404` — an easy status code to
 * get wrong when porting a "not found" condition. `route.test.ts` asserts
 * this explicitly.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * 2-tier convention across this whole `api/inbox/actions/*` family (see
 * e.g. `../poll/route.ts`, `../get-admins/route.ts`), NOT PHP's own broader
 * `$_SESSION`/`$_GET`/`$_POST` resolution chain for `$lineAccountId` at the
 * top of `api/inbox-v2.php`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

/** PHP's `(int) $v` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/** PHP `isset($body[key])` — true only when the key is PRESENT AND its value is not `null`. */
function hasIssetKey(body: Record<string, unknown>, key: string): boolean {
  return key in body && body[key] !== null;
}

/** `$body[key]` for a key already known to be `isset()` (present, non-null) — non-string JSON values are coerced the way PHP's weakly-typed `string` parameter would; not a real caller path. */
function toStringValue(value: unknown): string {
  if (typeof value === 'string') return value;
  return String(value);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const id = intval(body.id ?? 0);
  if (!id) {
    return NextResponse.json({ success: false, error: 'Template ID is required' }, { status: 400 });
  }

  const data: UpdateTemplatePayload = {};
  if (hasIssetKey(body, 'name')) {
    data.name = toStringValue(body.name);
  }
  if (hasIssetKey(body, 'content')) {
    data.content = toStringValue(body.content);
  }
  if (hasIssetKey(body, 'category')) {
    data.category = toStringValue(body.category);
  }
  if (hasIssetKey(body, 'quick_reply')) {
    const val = body.quick_reply;
    data.quick_reply = val === '' ? null : toStringValue(val);
  }

  if (Object.keys(data).length === 0) {
    return NextResponse.json({ success: false, error: 'No data to update' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const service = loadTemplateService(db, lineAccountId);
  if (!service) {
    return NextResponse.json({ success: false, error: 'Template service not available' }, { status: 503 });
  }

  try {
    const success = await service.updateTemplate(id, data);
    if (success) {
      return NextResponse.json({ success: true, message: 'Template updated successfully' });
    }
    // Not-found (scoped to line_account_id) -> PHP's `sendError()` DEFAULT
    // status, 400 — NOT 404. See module doc.
    return NextResponse.json({ success: false, error: 'Failed to update template' }, { status: 400 });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to update template: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
