import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { loadTemplateService } from './_lib/deleteTemplate';

/**
 * POST /api/inbox/actions/delete-template — literal port of
 * `api/inbox-v2.php`'s `case 'delete_template':` (lines 2329-2359):
 *
 * ```php
 * case 'delete_template':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $id = (int) ($_POST['id'] ?? $body['id'] ?? 0);
 *
 *     if (!$id) {
 *         sendError('Template ID is required');
 *     }
 *
 *     $templateService = loadService('TemplateService', $db, $lineAccountId);
 *     if (!$templateService) {
 *         sendError('Template service not available', 503);
 *     }
 *
 *     try {
 *         $success = $templateService->deleteTemplate($id);
 *         if ($success) {
 *             sendResponse(['success' => true, 'message' => 'Template deleted successfully']);
 *         } else {
 *             sendError('Failed to delete template');
 *         }
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to delete template: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * Body: JSON only — `$_POST` is dead for every real JSON caller (see
 * `../customer-crm/route.ts`'s doc for the same rationale). `id = (int)
 * (body.id ?? 0)`; falsy -> 400 `'Template ID is required'`.
 *
 * `loadTemplateService()` — the mockable port of PHP's `loadService(
 * 'TemplateService', $db, $lineAccountId)` — see `_lib/deleteTemplate.ts`'s
 * module doc for why the `503` branch it backs is defensive-only, never
 * reachable on real traffic, and only exercised via an explicit
 * `jest.mock` in `route.test.ts`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `$success === false` -> 400 `'Failed to delete template'` — NOT 404
 * ═══════════════════════════════════════════════════════════════════════
 * `deleteTemplate()` returns `false` (rather than throwing) exactly when
 * `getById($id)` (scoped to `line_account_id`) finds no row — a template
 * that doesn't exist, or exists but belongs to a different LINE account —
 * and in that case NO `DELETE` is ever issued. PHP's `sendError('Failed to
 * delete template')` uses `sendError()`'s DEFAULT status code, which is
 * `400`, NOT `404` — the same easy-to-get-wrong gotcha as
 * `../update-template/route.ts`'s own not-found branch. `route.test.ts`
 * asserts this explicitly, including that no `DELETE` query was issued.
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

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const service = loadTemplateService(db, lineAccountId);
  if (!service) {
    return NextResponse.json({ success: false, error: 'Template service not available' }, { status: 503 });
  }

  try {
    const success = await service.deleteTemplate(id);
    if (success) {
      return NextResponse.json({ success: true, message: 'Template deleted successfully' });
    }
    // Not-found (scoped to line_account_id) -> PHP's `sendError()` DEFAULT
    // status, 400 — NOT 404. See module doc.
    return NextResponse.json({ success: false, error: 'Failed to delete template' }, { status: 400 });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to delete template: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
