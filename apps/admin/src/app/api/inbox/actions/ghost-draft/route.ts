import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { generateDraft, isGhostDraftConfigured, loadGhostDraftCredentials } from './_lib/ghostDraft';

/**
 * POST /api/inbox/actions/ghost-draft — port of api/inbox-v2.php's
 * `case 'ghost_draft': case 'ghost-draft': case 'generate_draft':` (lines
 * ~428-469):
 *
 * ```php
 * case 'ghost_draft':
 * case 'ghost-draft':
 * case 'generate_draft':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $lastMessage = $_POST['message'] ?? $body['message'] ?? '';
 *     $context = $_POST['context'] ?? $body['context'] ?? [];
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (empty($lastMessage)) { sendError('Message is required'); }
 *     $ghostDraft = loadService('PharmacyGhostDraftService', $db, $lineAccountId);
 *     if (!$ghostDraft) { sendError('Ghost draft service not available', 503); }
 *     if (!$ghostDraft->isConfigured()) { sendError('AI API key not configured', 503); }
 *     if (is_string($context)) { $context = json_decode($context, true) ?? []; }
 *     $result = $ghostDraft->generateDraft($userId, $lastMessage, $context);
 *     sendResponse(['success' => $result['success'] ?? false, 'data' => $result]);
 *     break;
 * ```
 *
 * Reads a JSON body (`{ user_id, message, context? }`) via `request.json()`
 * — this is a new endpoint, not bound to PHP's `$_POST` shape (same
 * "new endpoint, not bound to PHP $_POST shape" precedent as
 * `../send-message/route.ts`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `!$userId` HAS NO PRIOR `<= 0` GUARD HERE — negative ints ARE reachable
 * ═══════════════════════════════════════════════════════════════════════
 * Unlike `../customer-health/route.ts`/`../classify-customer/route.ts`
 * (both of which have `if ($userId <= 0) { sendError('Invalid user ID'); }`
 * BEFORE their own textually-unreachable `!$userId` check), `ghost_draft`'s
 * ONLY user-id guard is the bare `if (!$userId) { sendError('User ID is
 * required'); }` — PHP's `!$userId` is true ONLY when the int is exactly
 * `0` (`!(-5)` is `false` in PHP, since any non-zero int is truthy). A
 * negative `user_id` therefore genuinely reaches `generateDraft()` here —
 * ported as a bare `if (!userId)` check below, no `<= 0` guard added.
 *
 * "AI API key not configured" 503 — the SHORT string, no Thai suffix.
 * Distinct from the imageAnalyzer builder's `analyze-*` routes' longer
 * `'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI
 * Settings'` (that string belongs to `PharmacyImageAnalyzerService`'s own
 * `case 'analyze_prescription':` branch in inbox-v2.php — a DIFFERENT
 * service class's DIFFERENT 503 message). Do not conflate the two.
 *
 * "Ghost draft service not available" 503 — PHP's `loadService()` guard has
 * no Next analogue (a static TypeScript import either compiles and is
 * present in the bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc. Not fabricated
 * here.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * RESPONSE ENVELOPE IS UNCONDITIONALLY HTTP 200
 * ═══════════════════════════════════════════════════════════════════════
 * `sendResponse(['success' => $result['success'] ?? false, 'data' => $result])`
 * passes NO explicit status code — even when `generateDraft()` fails
 * internally (`result.success === false`), the HTTP status stays 200; the
 * failure is only visible via `body.success === false` / `body.data.error`.
 * This is a REAL behavioral difference from the imageAnalyzer builder's
 * `analyze-*` routes (which 400 on failure) — preserved exactly here, NOT
 * "fixed" to be consistent with that sibling family.
 *
 * `context` parsing: `if (is_string($context)) { $context = json_decode($context,
 * true) ?? []; }` — when the client sends `context` as a JSON-encoded
 * string rather than a native object, it is parsed; a parse failure falls
 * back to `{}` (PHP's `?? []`), same as an invalid/absent context.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` cast on a decoded JSON body value (number or string) — same helper pattern as `../send-message/_lib/sendMessage.ts`'s `phpIntCast()`, duplicated locally per this batch's "no cross-directory imports beyond the named ones" boundary. */
function phpIntCast(value: unknown): number {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? Math.trunc(value) : 0;
  }
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP `empty($v)` for a scalar read off a decoded JSON body: `''`/`'0'`/`0`/`null`/`undefined`/`false` are all "empty". */
function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === '0' || value === 0) return true;
  if (Array.isArray(value) && value.length === 0) return true;
  return false;
}

/** `if (is_string($context)) { $context = json_decode($context, true) ?? []; }` */
function parseContext(value: unknown): Record<string, unknown> {
  if (typeof value === 'string') {
    try {
      const parsed: unknown = JSON.parse(value);
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
    } catch {
      return {};
    }
  }
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  let raw: unknown;
  try {
    raw = await request.json();
  } catch {
    raw = {};
  }
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = phpIntCast(body.user_id);
  const lastMessage = typeof body.message === 'string' ? body.message : body.message === undefined || body.message === null ? '' : String(body.message);

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }
  if (phpEmpty(lastMessage)) {
    return NextResponse.json({ success: false, error: 'Message is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const credentials = await loadGhostDraftCredentials(db, lineAccountId);
  if (!isGhostDraftConfigured(credentials)) {
    return NextResponse.json({ success: false, error: 'AI API key not configured' }, { status: 503 });
  }

  const context = parseContext(body.context);

  const result = await generateDraft(db, lineAccountId, userId, lastMessage, context, credentials);

  return NextResponse.json({ success: result.success ?? false, data: result });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
