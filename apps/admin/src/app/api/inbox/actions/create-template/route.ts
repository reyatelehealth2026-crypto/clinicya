import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { loadTemplateService } from './_lib/createTemplate';

/**
 * POST /api/inbox/actions/create-template — literal port of
 * `api/inbox-v2.php`'s `case 'create_template':` (lines 2237-2270):
 *
 * ```php
 * case 'create_template':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $name = $_POST['name'] ?? $body['name'] ?? '';
 *     $content = $_POST['content'] ?? $body['content'] ?? '';
 *     $category = $_POST['category'] ?? $body['category'] ?? '';
 *     $quickReply = $_POST['quick_reply'] ?? $body['quick_reply'] ?? null;
 *
 *     if (empty($name) || empty($content)) {
 *         sendError('Name and content are required');
 *     }
 *
 *     $templateService = loadService('TemplateService', $db, $lineAccountId);
 *     if (!$templateService) {
 *         sendError('Template service not available', 503);
 *     }
 *
 *     try {
 *         // If quickReply is empty string, set to null
 *         if ($quickReply === '')
 *             $quickReply = null;
 *
 *         $templateId = $templateService->createTemplate($name, $content, $category, $adminId, $quickReply);
 *         sendResponse(['success' => true, 'message' => 'Template created successfully', 'id' => $templateId]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to create template: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * Body: JSON only — `$_POST` is dead for every real JSON caller (see
 * `../customer-crm/route.ts`'s doc for the same rationale, spelled out at
 * length there; do not attempt a form-encoded path). `name`/`content`/
 * `category` default to `''` when absent from the body (`?? ''`);
 * `quick_reply` defaults to `null` (`?? null`).
 *
 * `empty($name) || empty($content)` runs on the RAW (pre-`trim()`) value —
 * PHP `empty()` on a string is `true` for exactly `''` and the literal
 * string `'0'` (NOT a generic falsy/whitespace check) — `isEmptyPhpString()`
 * below replicates precisely those two cases. This is a SEPARATE, earlier
 * check than `_lib/createTemplate.ts`'s own post-`trim()` `empty()` check
 * inside `createTemplate()` itself: a whitespace-only name (`'   '`) passes
 * THIS check (non-empty, non-`'0'` string) but still throws from inside the
 * service after trimming, surfacing as the generic `'Failed to create
 * template: ...'` 400 below instead of this route's own `'Name and content
 * are required'` 400.
 *
 * `loadTemplateService()` — the mockable port of PHP's `loadService(
 * 'TemplateService', $db, $lineAccountId)` — see `_lib/createTemplate.ts`'s
 * module doc for why the `503` branch it backs is defensive-only, never
 * reachable on real traffic, and only exercised via an explicit
 * `jest.mock` in `route.test.ts`.
 *
 * `$quickReply === '' -> null` coercion happens INSIDE PHP's own `try`
 * block, after the service-availability check — ported at the same point
 * here, immediately before calling `service.createTemplate(...)`.
 *
 * `created_by` binds `session.adminUserId` unconditionally — same
 * precedent as `../add-customer-note/route.ts` (`TenantSession.adminUserId`
 * is always a `number`, so PHP's own `$adminId` — which CAN be `null` —
 * always has a real value here).
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

/** PHP `empty($v)` on a value already known to be a `string` — true for `''` or the literal `'0'`. */
function isEmptyPhpString(value: string): boolean {
  return value === '' || value === '0';
}

/** `$body['key'] ?? ''` — non-string JSON values are coerced the way PHP's weakly-typed `string` parameter would coerce a scalar; not a real caller path, kept only so an unexpected type never throws before validation runs. */
function stringOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === undefined || value === null) return '';
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  return '';
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const name = stringOrEmpty(body.name);
  const content = stringOrEmpty(body.content);
  const category = stringOrEmpty(body.category);
  const rawQuickReply = body.quick_reply ?? null;

  if (isEmptyPhpString(name) || isEmptyPhpString(content)) {
    return NextResponse.json({ success: false, error: 'Name and content are required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const service = loadTemplateService(db, lineAccountId);
  if (!service) {
    return NextResponse.json({ success: false, error: 'Template service not available' }, { status: 503 });
  }

  try {
    // If quickReply is empty string, set to null — matches PHP's own
    // `if ($quickReply === '') $quickReply = null;` inside the try block.
    const quickReply = rawQuickReply === '' ? null : rawQuickReply === null ? null : String(rawQuickReply);

    const id = await service.createTemplate(name, content, category, session.adminUserId, quickReply);
    return NextResponse.json({ success: true, message: 'Template created successfully', id });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to create template: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
