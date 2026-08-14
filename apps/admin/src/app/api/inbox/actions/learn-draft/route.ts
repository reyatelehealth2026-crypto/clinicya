import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { learnFromEdit } from './_lib/learnDraft';

/**
 * POST /api/inbox/actions/learn-draft — port of api/inbox-v2.php's
 * `case 'learn_draft': case 'learn-draft':` (lines ~475-512):
 *
 * ```php
 * case 'learn_draft':
 * case 'learn-draft':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $originalDraft = $_POST['original_draft'] ?? $body['original_draft'] ?? '';
 *     $finalMessage = $_POST['final_message'] ?? $body['final_message'] ?? '';
 *     $context = $_POST['context'] ?? $body['context'] ?? [];
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (empty($originalDraft) || empty($finalMessage)) {
 *         sendError('Original draft and final message are required');
 *     }
 *     $ghostDraft = loadService('PharmacyGhostDraftService', $db, $lineAccountId);
 *     if (!$ghostDraft) { sendError('Ghost draft service not available', 503); }
 *     if (is_string($context)) { $context = json_decode($context, true) ?? []; }
 *     $success = $ghostDraft->learnFromEdit($userId, $originalDraft, $finalMessage, $context);
 *     sendResponse(['success' => $success, 'message' => $success ? 'Learning data saved successfully' : 'Failed to save learning data']);
 *     break;
 * ```
 *
 * Reads a JSON body (`{ user_id, original_draft, final_message, context? }`)
 * via `request.json()` — same "new endpoint, not bound to PHP $_POST shape"
 * precedent as `../ghost-draft/route.ts`/`../send-message/route.ts`.
 *
 * NO `isConfigured()` GATE HERE: `learnFromEdit()` is plain SQL + a
 * Levenshtein edit-distance calculation — no AI/network call at all (see
 * `_lib/learnDraft.ts`'s module doc) — matching PHP's own `case
 * 'learn_draft':`, which never calls `$ghostDraft->isConfigured()`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `!$userId` HAS NO PRIOR `<= 0` GUARD HERE — same as `../ghost-draft/route.ts`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's ONLY user-id guard is the bare `if (!$userId) { sendError('User ID
 * is required'); }` — true only when the int is exactly `0`. A negative
 * `user_id` genuinely reaches `learnFromEdit()`.
 *
 * `empty($originalDraft) || empty($finalMessage)` — PHP `empty()` string
 * semantics: `''`, `null`/missing, AND the exact literal string `'0'` are
 * ALL considered empty (not just `''`) — replicated below via `phpEmpty()`.
 *
 * "Ghost draft service not available" 503 — PHP's `loadService()` guard has
 * no Next analogue (a static TypeScript import either compiles and is
 * present in the bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc. Not fabricated
 * here.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * RESPONSE ENVELOPE — ALWAYS 200, NO `data` KEY AT ALL
 * ═══════════════════════════════════════════════════════════════════════
 * `sendResponse(['success' => $success, 'message' => ...])` — unlike EVERY
 * other sibling action in this batch, there is no `data` key whatsoever;
 * the body is exactly `{success, message}`, and the HTTP status is always
 * 200 regardless of whether `$success` is `true` or `false`.
 *
 * `context` parsing: identical `is_string($context)` -> `json_decode(...) ??
 * []` handling as `../ghost-draft/route.ts` — a JSON-encoded string body is
 * decoded, a decode failure (or non-object result) falls back to `{}`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` cast on a decoded JSON body value — duplicated locally, same pattern as `../ghost-draft/route.ts`/`../send-message/_lib/sendMessage.ts`'s `phpIntCast()`. */
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

/** Coerces a decoded-JSON-body scalar to a string, matching how PHP would use a non-string `$_POST`/body value as-is (e.g. in string concatenation/DB binding). */
function toStringField(value: unknown): string {
  if (typeof value === 'string') return value;
  return value === undefined || value === null ? '' : String(value);
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
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const rawOriginalDraft = body.original_draft;
  const rawFinalMessage = body.final_message;
  if (phpEmpty(rawOriginalDraft) || phpEmpty(rawFinalMessage)) {
    return NextResponse.json({ success: false, error: 'Original draft and final message are required' }, { status: 400 });
  }

  const originalDraft = toStringField(rawOriginalDraft);
  const finalMessage = toStringField(rawFinalMessage);
  const context = parseContext(body.context);

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const success = await learnFromEdit(db, lineAccountId, userId, originalDraft, finalMessage, context);

  return NextResponse.json({ success, message: success ? 'Learning data saved successfully' : 'Failed to save learning data' });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
