import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { pushMessage, type LineApiCallResult, type LineMessage } from '@reya/line';
import { buildFileFlexMessage, buildPaymentDbText, buildPaymentFlexMessage } from './flexTemplates';

/**
 * sendBatchMessages.ts — literal port of `api/inbox-v2.php`'s
 * `case 'send_batch_messages':` (lines 3169-3487), backed by
 * `classes/LineAPI.php::pushMessage()` (now `@reya/line`'s `pushMessage()`)
 * ONLY — this action NEVER attempts `sendMessage()`'s reply-token-first
 * path, NEVER calls `replyMessage()`. Read the full case body before
 * editing this file.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SAFETY-CRITICAL: this is the ONLY action in this batch that sends real
 * LINE messages, and it does so via `pushMessage()` — a real push against
 * the account's push-message quota, unconditionally, no reply-token
 * fallback path at all. See `route.test.ts`'s module-boundary
 * `jest.mock('@reya/line', ...)` for how every test in this suite is kept
 * from ever reaching a live network call.
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ```php
 * case 'send_batch_messages':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $messages = $_POST['messages'] ?? $body['messages'] ?? [];
 *     $lineUserId = $_POST['line_user_id'] ?? $body['line_user_id'] ?? '';
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (is_string($messages)) { $messages = json_decode($messages, true) ?? []; }
 *     if (empty($messages)) { sendError('Messages array is required'); }
 *     if (count($messages) > 5) { sendError('Maximum 5 messages allowed per batch'); }
 *     if (empty($lineUserId)) {
 *         $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ? AND line_account_id = ?");
 *         $stmt->execute([$userId, $lineAccountId]);
 *         $lineUserId = $stmt->fetchColumn();
 *     }
 *     if (empty($lineUserId)) { sendError('LINE User ID not found for user'); }
 *     try {
 *         $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
 *         $stmt->execute([$lineAccountId]);
 *         $account = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if (!$account || empty($account['channel_access_token'])) { sendError('LINE account token not found'); }
 *         $lineAPI = new LineAPI($account['channel_access_token']);
 *
 *         $lineMessages = []; $dbRecords = [];
 *         foreach ($messages as $msg) {
 *             $type = $msg['type'] ?? 'text';
 *             $content = $msg['content'] ?? $msg['text'] ?? '';
 *             if ($type === 'text') {
 *                 $content = trim($content);
 *                 if ($content === '{{PAYMENT_TEMPLATE_V1}}') { $type = 'payment'; }
 *                 if ($type === 'text' && !empty($content)) {
 *                     $lineMessages[] = ['type' => 'text', 'text' => $content];
 *                     $dbRecords[] = ['type' => 'text', 'content' => $content];
 *                 }
 *             }
 *             if ($type === 'image') {
 *                 if (!empty($msg['originalContentUrl']) && !empty($msg['previewImageUrl'])) { ... }
 *             } elseif ($type === 'file') {
 *                 if (!empty($msg['originalContentUrl'])) { ... }
 *             } elseif ($type === 'payment') {
 *                 ... // NO guard at all
 *             }
 *         }
 *         if (empty($lineMessages)) { sendError('No valid messages to send'); }
 *         $result = $lineAPI->pushMessage($lineUserId, $lineMessages);
 *         if ($result['code'] !== 200) { sendError('Failed to send messages via LINE: ' . ($result['body']['message'] ?? 'Unknown error')); }
 *
 *         $insertStmt = $db->prepare("INSERT INTO messages (user_id, line_account_id, direction, message_type, content, is_read, sent_by, created_at) VALUES (?, ?, 'outgoing', ?, ?, 1, ?, NOW())");
 *         $savedCount = 0;
 *         foreach ($dbRecords as $record) {
 *             $insertStmt->execute([$userId, $lineAccountId, $record['type'], $record['content'], $adminId]);
 *             $savedCount++;
 *         }
 *         $updateStmt = $db->prepare("UPDATE users SET last_message_at = NOW() WHERE id = ?");
 *         $updateStmt->execute([$userId]);
 *         sendResponse(['success' => true, 'message' => "Sent {$savedCount} messages successfully", 'count' => $savedCount]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Error sending batch messages: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * VALIDATION ORDER (all `sendError()` calls below `exit()` immediately in
 * PHP — none of them are ever routed through the case-level `catch` that
 * follows; only a genuinely unexpected exception reaches it):
 *   userId truthy -> messages non-empty (after string->JSON parse) ->
 *   count(messages) <= 5 -> lineUserId resolved (param or DB lookup) ->
 *   [inside try] line_accounts token found -> build lineMessages/dbRecords
 *   -> lineMessages non-empty -> pushMessage() returns HTTP 200 -> DB writes
 *   -> response.
 *
 * ★ PRE-EXISTING PHP BUSINESS-LOGIC QUIRK — preserved verbatim, NOT a bug
 * to fix ★  `$adminId` (from `$_SESSION['admin_id'] ?? $_GET['admin_id'] ??
 * $_POST['admin_id'] ?? null`, computed once near the top of
 * `api/inbox-v2.php`, line ~72) is written RAW into `messages.sent_by` here
 * — `$insertStmt->execute([..., $adminId])` — UNLIKE every other action in
 * this whole `api/inbox/actions/*` family (`send-message`, `send-image`,
 * `send-pdf`, `dispense`, ...), which all format `sent_by` as the STRING
 * `"admin:{$adminName}"`. This is a genuine, confirmed PHP inconsistency in
 * the original source, not a Next-side omission — reproduced exactly as
 * `String(session.adminUserId)` (the `session.currentBotId ?? 1`-style
 * simplification of the `$_SESSION`/`$_GET`/`$_POST` admin-id fallback
 * chain, per this batch's brief) or `null`, never `"admin:..."`. Flagged
 * loudly here and in the runbook so a future cleanup pass does not
 * "helpfully" homogenize it without a deliberate decision to do so.
 *
 * `messages.is_read` is hardcoded to `1` on every inserted row (`VALUES
 * (..., 1, ...)`) — DIFFERENT from `send-image`/`send-pdf` (mediaSend's own
 * batch this round), whose inserts use `is_read: 0`. Reproduced exactly:
 * this function always inserts `is_read: 1`.
 */

export interface SendBatchMessagesResult {
  status: number;
  body: Record<string, unknown>;
}

function errorResult(status: number, error: string): SendBatchMessagesResult {
  return { status, body: { success: false, error } };
}

/** Mirrors PHP's `(int)$value` / `intval($value)` loose cast on a decoded JSON body value. */
export function phpIntCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP's `(float)$value` / `floatval($value)` — leading-numeric parse, non-numeric/null/undefined -> 0 (never NaN). */
function phpFloatCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'string') {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }
  return 0;
}

/** PHP's `!empty($v)` on a decoded-JSON scalar: false for undefined/null/''/0/'0'/false/[] . */
function phpTruthy(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return false;
  if (value === '' || value === 0 || value === '0') return false;
  if (Array.isArray(value) && value.length === 0) return false;
  return true;
}

/** `$msg[key] ?? ''` narrowed to a string — a present-but-non-string JSON value is coerced the same loose way PHP's `!empty()`/`trim()` calls would treat it. */
function stringField(msg: Record<string, unknown>, key: string): string {
  const v = msg[key];
  if (typeof v === 'string') return v;
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  return '';
}

/** `$msg[$keys[0]] ?? $msg[$keys[1]] ?? ... ?? ''` — PHP's `??` chain across multiple keys, isset()-based (a `null` value skips to the next key; any other present value, including `''`/`0`, wins immediately). */
function coalesceStringField(msg: Record<string, unknown>, keys: string[]): string {
  for (const key of keys) {
    if (msg[key] !== undefined && msg[key] !== null) {
      return stringField(msg, key);
    }
  }
  return '';
}

/**
 * PHP's `number_format($value, 2)` — fixed to 2 decimals WITH thousands
 * separators (e.g. `1234.5` -> `"1,234.50"`). `toLocaleString()` is
 * deliberately avoided here (locale-dependent grouping/decimal characters);
 * this is a manual, locale-independent port matching PHP's own
 * `number_format()` default `.`/`,` separators exactly.
 */
export function phpNumberFormat(value: number, decimals = 2): string {
  const fixed = value.toFixed(decimals);
  const negative = fixed.startsWith('-');
  const [intPart, decPart] = (negative ? fixed.slice(1) : fixed).split('.');
  const withCommas = (intPart ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return (negative ? '-' : '') + withCommas + (decPart !== undefined ? `.${decPart}` : '');
}

interface LineUserIdRow {
  line_user_id: string | null;
}

interface LineAccountTokenRow {
  channel_access_token: string | null;
}

type DbRecordType = 'text' | 'image' | 'file';
interface DbRecord {
  type: DbRecordType;
  content: string;
}

/**
 * Builds the LINE message array + parallel DB-record array from the raw
 * request `messages` array — literal port of the `foreach ($messages as
 * $msg) { ... }` loop (inbox-v2.php lines 3220-3339). The two-stage
 * `if ($type === 'text') { ... }` THEN a separate `if/elseif` chain on the
 * (possibly-mutated) `$type` is preserved exactly as two sequential `if`
 * statements below — this is what allows a `type: 'text'` item whose
 * content is the magic `'{{PAYMENT_TEMPLATE_V1}}'` string to fall through
 * into the payment branch within the SAME loop iteration.
 */
function buildLineMessagesAndDbRecords(rawMessages: unknown[]): { lineMessages: LineMessage[]; dbRecords: DbRecord[] } {
  const lineMessages: LineMessage[] = [];
  const dbRecords: DbRecord[] = [];

  for (const raw of rawMessages) {
    const msg: Record<string, unknown> = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

    // `$type = $msg['type'] ?? 'text';` — isset()-based: only an ABSENT/null `type` key defaults
    // to `'text'`; any other present value (even a non-string) is used as-is and then compared
    // with strict `===` below, so a non-string/unrecognized type value matches none of the
    // branches and is silently dropped — NOT coerced to 'text'.
    let type: unknown = msg.type === undefined || msg.type === null ? 'text' : msg.type;
    let content = coalesceStringField(msg, ['content', 'text']);

    if (type === 'text') {
      content = content.trim();

      // Magic Payment Template — switches type to trigger Flex generation below.
      if (content === '{{PAYMENT_TEMPLATE_V1}}') {
        type = 'payment';
      }

      // Only add if still text (wasn't switched) and not empty.
      if (type === 'text' && content !== '') {
        lineMessages.push({ type: 'text', text: content });
        dbRecords.push({ type: 'text', content });
      }
    }

    if (type === 'image') {
      const originalContentUrl = stringField(msg, 'originalContentUrl');
      const previewImageUrl = stringField(msg, 'previewImageUrl');
      if (phpTruthy(originalContentUrl) && phpTruthy(previewImageUrl)) {
        lineMessages.push({ type: 'image', originalContentUrl, previewImageUrl });
        dbRecords.push({ type: 'image', content: originalContentUrl });
      }
    } else if (type === 'file') {
      const originalContentUrl = stringField(msg, 'originalContentUrl');
      if (phpTruthy(originalContentUrl)) {
        const fileNameRaw = stringField(msg, 'fileName');
        const fileName = phpTruthy(fileNameRaw) ? fileNameRaw : 'File';
        const fileUrl = originalContentUrl;

        lineMessages.push(buildFileFlexMessage(fileName, fileUrl));

        // `json_encode(['url' => $fileUrl, 'name' => $fileName], JSON_UNESCAPED_UNICODE)`.
        const fileContent = JSON.stringify({ url: fileUrl, name: fileName });
        dbRecords.push({ type: 'file', content: fileContent });
      }
    } else if (type === 'payment') {
      // NO required-field guard at all — always produces a message, amount defaults to 0.00.
      const amount = phpNumberFormat(phpFloatCast(msg.amount ?? 0));
      lineMessages.push(buildPaymentFlexMessage(amount));
      // Saved as message_type='text' to DB, NOT 'payment' — see module doc.
      dbRecords.push({ type: 'text', content: buildPaymentDbText(amount) });
    }
  }

  return { lineMessages, dbRecords };
}

export async function sendBatchMessagesAction(
  db: Kysely<TenantDB>,
  session: TenantSession,
  lineAccountId: number,
  userId: number,
  rawMessages: unknown[],
  lineUserIdParam: string
): Promise<SendBatchMessagesResult> {
  // inbox-v2.php lines 3191-3193: `if (count($messages) > 5) sendError(...)`. Runs BEFORE the
  // lineUserId lookup — order preserved.
  if (rawMessages.length > 5) {
    return errorResult(400, 'Maximum 5 messages allowed per batch');
  }

  // inbox-v2.php lines 3196-3200: resolve line_user_id from the DB only when not supplied.
  let lineUserId = lineUserIdParam;
  if (!phpTruthy(lineUserId)) {
    const rows = await sql<LineUserIdRow>`
      SELECT line_user_id FROM users WHERE id = ${userId} AND line_account_id = ${lineAccountId}
    `.execute(db);
    lineUserId = rows.rows[0]?.line_user_id ?? '';
  }
  if (!phpTruthy(lineUserId)) {
    return errorResult(400, 'LINE User ID not found for user');
  }

  // inbox-v2.php lines 3205-3210: `SELECT channel_access_token FROM line_accounts WHERE id = ?`.
  const accountRows = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${lineAccountId}
  `.execute(db);
  const account = accountRows.rows[0];
  if (!account || !phpTruthy(account.channel_access_token)) {
    return errorResult(400, 'LINE account token not found');
  }

  const { lineMessages, dbRecords } = buildLineMessagesAndDbRecords(rawMessages);

  // inbox-v2.php lines 3341-3343.
  if (lineMessages.length === 0) {
    return errorResult(400, 'No valid messages to send');
  }

  // inbox-v2.php line 3345: `$lineAPI->pushMessage($lineUserId, $lineMessages)` — a real push,
  // NEVER a reply-token attempt (this action has no reply-token concept at all, unlike
  // send-message/send-image/send-pdf's `sendMessage()` reply-first dispatcher).
  const result: LineApiCallResult = await pushMessage(lineUserId, lineMessages, {
    channelAccessToken: account.channel_access_token as string,
  });

  if (result.code !== 200) {
    // inbox-v2.php line 3348: `'Failed to send messages via LINE: ' . ($result['body']['message'] ?? 'Unknown error')`
    // — a DIFFERENT literal format from mediaSend's own LINE-error string this same round
    // (`'Failed to send image via LINE (HTTP {code}, {method}): {json body}'`) — not unified.
    const body = result.body as { message?: unknown } | null | undefined;
    const bodyMessage = body && typeof body.message === 'string' ? body.message : 'Unknown error';
    return errorResult(400, `Failed to send messages via LINE: ${bodyMessage}`);
  }

  // inbox-v2.php lines 3453-3467: one INSERT per dbRecords entry.
  // ★ sent_by is the RAW admin id (String(...) or null) — NOT 'admin:{name}' — see module doc's
  // "PRE-EXISTING PHP BUSINESS-LOGIC QUIRK" section. is_read is hardcoded 1 (NOT 0).
  const sentBy = session.adminUserId !== null && session.adminUserId !== undefined ? String(session.adminUserId) : null;

  let savedCount = 0;
  for (const record of dbRecords) {
    await db
      .insertInto('messages')
      .values({
        user_id: userId,
        line_account_id: lineAccountId,
        direction: 'outgoing',
        message_type: record.type,
        content: record.content,
        is_read: 1,
        sent_by: sentBy,
        created_at: sql<Date>`NOW()`,
      })
      .execute();
    savedCount++;
  }

  // inbox-v2.php lines 3470-3471.
  await db.updateTable('users').set({ last_message_at: sql<Date>`NOW()` }).where('id', '=', userId).execute();

  // inbox-v2.php lines 3473-3477.
  return {
    status: 200,
    body: {
      success: true,
      message: `Sent ${savedCount} messages successfully`,
      count: savedCount,
    },
  };
}
