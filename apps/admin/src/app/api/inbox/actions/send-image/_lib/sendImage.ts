import { existsSync, promises as fs } from 'node:fs';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { sendMessage as lineApiSendMessage, type SendMessageResult } from '@reya/line';

/**
 * sendImage.ts — literal port of inbox-v2.php's `case 'send_image':` (lines 736-834), backed by
 * `classes/LineAPI.php::sendMessage()` (now `@reya/line`'s `sendMessage()`) and
 * `classes/ActivityLogger.php::logMessage()`. Read the full case body before editing this file.
 *
 * VALIDATION ORDER (mirrors PHP exactly): user_id -> file presence -> MIME type -> file size ->
 * `users` row lookup ("User not found") -> [file written to disk] -> `line_accounts` row lookup
 * (Next-side addition, see below) -> LINE send -> DB writes -> response. Every validation error
 * is a flat HTTP 400 `{success:false, error}`, matching inbox-v2.php's outer
 * `catch (Exception $e) { http_response_code(400); echo json_encode(['success'=>false,'error'=>...]); }`
 * (lines 982-985) — this function returns that shape directly (not by throwing) for every
 * expected validation branch, same as send-message/_lib/sendMessage.ts's own precedent.
 *
 * LINE ACCOUNT LOOKUP (Next-side addition): inbox-v2.php lines 774-775 call
 * `classes/LineAccountManager.php::getLineAPI($user['line_account_id'])`, which silently falls
 * back to a legacy config-constant-backed `new LineAPI()` when no `line_accounts` row matches —
 * Next has no such fallback, so a missing/invalid `line_account_id` is an explicit 400 here
 * instead of silently sending through some default channel token (same decision as
 * send-message/_lib/sendMessage.ts and dispense/_lib/flexSend.ts). Deliberate consistency
 * addition beyond the literal PHP source: the file that was ALREADY WRITTEN to disk by that point
 * is cleaned up in this branch too (best-effort, same `@unlink`-style swallow as the
 * LINE-failure branch below) — see this file's own inline comment at the call site and the
 * runbook for why.
 *
 * FILE UPLOAD: writes to `<repo-root>/uploads/chat_images/` — the SAME on-disk directory
 * inbox-v2.php writes to (`__DIR__ . '/uploads/chat_images/'`, __DIR__ being the repo root where
 * inbox-v2.php lives), so both stacks keep serving/reading the same files during strangler
 * coexistence. See resolveChatImagesUploadDir()'s own doc comment for the resolution strategy
 * (same env-override + walk-up-to-pnpm-workspace.yaml approach as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts's resolveSlipsUploadDir()).
 * `image_url` is built from the INCOMING request's own scheme+host (`origin`, computed by
 * route.ts from `new URL(request.url)`), never a hardcoded BASE_URL constant.
 */

export interface SendImageActionResult {
  status: number;
  body: Record<string, unknown>;
}

interface SendImageUserRow {
  id: number;
  line_user_id: string;
  line_account_id: number | null;
  reply_token: string | null;
  /** DATE_FORMAT'd 'YYYY-MM-DD HH:MM:SS' string — see send-message/_lib/sendMessage.ts's own doc for why. */
  reply_token_expires_str: string | null;
}

interface LineAccountTokenRow {
  channel_access_token: string;
}

function errorResult(status: number, error: string): SendImageActionResult {
  return { status, body: { success: false, error } };
}

const ALLOWED_IMAGE_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

/** Mirrors PHP's `(int)$value` / `intval($value)` cast on a `FormData.get()` value (string | File | null). */
export function phpIntCast(value: unknown): number {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? Math.trunc(value) : 0;
  }
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/**
 * Resolves `<repo-root>/uploads/chat_images/`. An explicit `INBOX_CHAT_IMAGES_UPLOAD_DIR` env
 * override wins if set (for deployments where the Node process's cwd isn't physically under this
 * repo tree at all). Otherwise walks upward from `process.cwd()` looking for
 * `pnpm-workspace.yaml` (the repo-root marker) — same strategy as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts's resolveSlipsUploadDir().
 */
function resolveChatImagesUploadDir(): string {
  const override = process.env.INBOX_CHAT_IMAGES_UPLOAD_DIR;
  if (override && override.trim() !== '') {
    return override;
  }
  let dir = process.cwd();
  for (let i = 0; i < 12; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return path.join(dir, 'uploads', 'chat_images');
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      break;
    }
    dir = parent;
  }
  // Documented limitation: no pnpm-workspace.yaml found anywhere in the ancestry chain — fall back to
  // cwd-relative rather than failing outright. Set INBOX_CHAT_IMAGES_UPLOAD_DIR explicitly in any
  // deployment where this matters.
  return path.join(process.cwd(), 'uploads', 'chat_images');
}

/** `pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg'`. */
function phpFileExtensionOrJpg(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  const ext = dot > -1 ? base.slice(dot + 1) : '';
  return ext !== '' ? ext : 'jpg';
}

/** Stand-in for PHP's `uniqid()` — an opaque, sufficiently-unique-per-call token for the filename. */
function uniqueToken(): string {
  return randomBytes(8).toString('hex');
}

/** `date('H:i')` in Asia/Bangkok — inbox-v2.php line 823's `'time' => date('H:i')` (server runs in Asia/Bangkok, CLAUDE.md). */
function bangkokTimeHHmm(): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date());
}

export async function sendImageAction(
  db: Kysely<TenantDB>,
  session: TenantSession,
  form: FormData,
  origin: string
): Promise<SendImageActionResult> {
  // inbox-v2.php lines 737-739: `$userId = intval($_POST['user_id'] ?? 0); if (!$userId) throw new Exception("User ID required");`
  const userId = phpIntCast(form.get('user_id'));
  if (!userId) {
    return errorResult(400, 'User ID required');
  }

  // inbox-v2.php lines 740-742: `if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw ...`
  const file = form.get('image');
  if (!(file instanceof File) || file.size === 0) {
    return errorResult(400, 'No image uploaded');
  }

  // inbox-v2.php lines 744-748.
  const mime = file.type || '';
  if (!ALLOWED_IMAGE_MIME_TYPES.has(mime)) {
    return errorResult(400, 'Invalid image type. Allowed: JPG, PNG, GIF, WEBP');
  }

  // inbox-v2.php lines 750-752.
  if (file.size > MAX_IMAGE_BYTES) {
    return errorResult(400, 'Image too large. Max 10MB');
  }

  // inbox-v2.php lines 754-758: `SELECT line_user_id, line_account_id, reply_token, reply_token_expires FROM users WHERE id = ?`.
  const userRows = await sql<SendImageUserRow>`
    SELECT id, line_user_id, line_account_id, reply_token,
      DATE_FORMAT(reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM users WHERE id = ${userId}
  `.execute(db);
  const user = userRows.rows[0];
  if (!user) {
    return errorResult(400, 'User not found');
  }

  // inbox-v2.php lines 760-771: mkdir + pathinfo ext-or-jpg + 'chat_' . time() . '_' . uniqid() . '.' . ext.
  const uploadDir = resolveChatImagesUploadDir();
  await fs.mkdir(uploadDir, { recursive: true });

  const ext = phpFileExtensionOrJpg(file.name || '');
  const filename = `chat_${Math.floor(Date.now() / 1000)}_${uniqueToken()}.${ext}`;
  const filepath = path.join(uploadDir, filename);

  const buffer = Buffer.from(await file.arrayBuffer());
  await fs.writeFile(filepath, buffer);

  // inbox-v2.php lines 773-775: `$protocol . $host . '/uploads/chat_images/' . $filename` — here,
  // `origin` is computed by route.ts from the incoming request's own `new URL(request.url)`,
  // never a hardcoded BASE_URL.
  const imageUrl = `${origin}/uploads/chat_images/${filename}`;

  // classes/LineAccountManager.php::getLineAPI($user['line_account_id']) — see this file's module
  // doc for why a missing/invalid `line_accounts` row is a hard 400 here (Next has no legacy
  // config fallback), and why the just-written file is cleaned up in this branch.
  const lineAccountRows = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${user.line_account_id}
  `.execute(db);
  const lineAccount = lineAccountRows.rows[0];
  if (!lineAccount) {
    await fs.unlink(filepath).catch(() => {});
    return errorResult(400, 'ไม่พบการเชื่อมต่อ LINE OA สำหรับผู้ใช้นี้ (line_account_id ไม่ถูกต้อง)');
  }

  // inbox-v2.php lines 777-787: reply-first (free if a valid reply_token exists; else falls back to push).
  const result: SendMessageResult = await lineApiSendMessage(
    {
      userId: user.line_user_id,
      messages: [{ type: 'image', originalContentUrl: imageUrl, previewImageUrl: imageUrl }],
      replyToken: user.reply_token,
      tokenExpires: user.reply_token_expires_str,
      internalUserId: user.id,
      onReplyTokenUsed: async () => {
        // classes/LineAPI.php::sendMessage() clears the single-use reply token itself via
        // clearReplyToken($db, $userId) internally — @reya/line has zero @reya/db dependency, so
        // that side effect is this injected callback (same pattern as send-message/_lib/sendMessage.ts).
        await db.updateTable('users').set({ reply_token: null, reply_token_expires: null }).where('id', '=', userId).execute();
      },
    },
    { channelAccessToken: lineAccount.channel_access_token }
  );

  if (result.code !== 200) {
    // inbox-v2.php lines 829-833: `@unlink($filepath); throw new Exception("Failed to send image via LINE (HTTP " . ... . "): " . ...);`
    await fs.unlink(filepath).catch(() => {});
    return errorResult(400, `Failed to send image via LINE (HTTP ${result.code}, ${result.method}): ${JSON.stringify(result.body)}`);
  }

  // inbox-v2.php lines 789-793: adminName resolution from `$_SESSION['admin_user']` (username,
  // else display_name, else 'Admin'). TenantSession carries `.username` directly (not nested).
  const adminName = session.username || 'Admin';

  // inbox-v2.php lines 795-806: the `SHOW COLUMNS ... LIKE 'sent_by'` defensive branch is dead
  // code on the current committed schema (`sent_by` always exists —
  // packages/db/src/generated/tenant-db.d.ts's Messages interface) and is NOT replicated, matching
  // send-message/_lib/sendMessage.ts's own "unreachable dead code, not replicated" precedent.
  //
  // 7-column insert (NO `reply_to_id` column, unlike send-message's own insert — this action has
  // no `reply_to_id` concept in the PHP source at all).
  const insertResult = await db
    .insertInto('messages')
    .values({
      line_account_id: user.line_account_id,
      user_id: userId,
      direction: 'outgoing',
      message_type: 'image',
      content: imageUrl,
      sent_by: `admin:${adminName}`,
      created_at: sql<Date>`NOW()`,
      is_read: 0,
    })
    .executeTakeFirstOrThrow();
  const msgId = Number(insertResult.insertId ?? 0);

  // Literal port of `ActivityLogger::logMessage(ActivityLogger::ACTION_SEND, 'ส่งรูปภาพถึงลูกค้า', [
  //   'user_id' => $userId, 'entity_type' => 'message', 'entity_id' => $msgId,
  //   'line_account_id' => $user['line_account_id']
  // ])` (inbox-v2.php lines 810-815) -> `log(TYPE_MESSAGE, ACTION_SEND, ...)`.
  //
  // DELIBERATELY no `new_value` key here (unlike send-message/_lib/sendMessage.ts's own insert,
  // which DOES set one from the message text) — this PHP call site never passes a `new_value`
  // option, so `activity_logs.new_value` is left at its column default (NULL). Do not copy
  // send-message's `new_value` field over by reflex.
  await db
    .insertInto('activity_logs')
    .values({
      log_type: 'message',
      action: 'send',
      description: 'ส่งรูปภาพถึงลูกค้า',
      user_id: userId,
      admin_id: session.adminUserId,
      admin_name: session.username,
      entity_type: 'message',
      entity_id: msgId,
      line_account_id: user.line_account_id,
    })
    .execute();

  // inbox-v2.php lines 817-823 — NO `method`/`method_label` keys (unlike send_message's response envelope).
  return {
    status: 200,
    body: {
      success: true,
      message_id: msgId,
      image_url: imageUrl,
      time: bangkokTimeHHmm(),
      sent_by: `admin:${adminName}`,
    },
  };
}
