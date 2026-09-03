import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { sendMessage as lineApiSendMessage, type SendMessageResult } from '@reya/line';

/**
 * sendMessage.ts — literal port of inbox-v2.php's `case 'send_message':`
 * (lines 236-336), backed by `classes/LineAPI.php::sendMessage()` (now
 * `@reya/line`'s `sendMessage()`) and `classes/ActivityLogger.php::logMessage()`.
 *
 * SCOPE (see the brief): the LINE-platform branch only. inbox-v2.php lines
 * 250-274 additionally handle `platform === 'facebook'` (routes through
 * `FacebookMessengerAPI::sendTextMessage()`, a real send) and
 * `platform === 'tiktok'` (a bare `throw new Exception("ยังไม่รองรับการตอบ
 * TikTok จากหน้านี้")`). NEITHER is ported here — there is no `@reya/facebook`
 * package yet — so both platforms collapse to a single, explicitly-labeled
 * "not yet migrated" error (`UNSUPPORTED_PLATFORM_MESSAGE` below) instead of
 * silently misrouting a Facebook/TikTok user's message through the LINE
 * Messaging API or pretending to send. This is an intentional, documented
 * Next-side limitation, NOT a byte-for-byte port of PHP's per-platform
 * strings — flagged in the build report for a future `@reya/facebook` batch.
 *
 * `userId`/`message`/`replyToId` are assumed already PHP-cast + validated by
 * the caller (route.ts) — this function's precondition is `userId > 0` and
 * `message` a non-empty trimmed string, matching inbox-v2.php lines 237-240
 * ("Invalid data") having already run before this point in the PHP source.
 */

export interface SendMessageActionResult {
  status: number;
  body: Record<string, unknown>;
}

interface SendMessageUserRow {
  id: number;
  line_user_id: string;
  line_account_id: number | null;
  platform: string | null;
  reply_token: string | null;
  /**
   * `reply_token_expires` read via `DATE_FORMAT(..., '%Y-%m-%d %H:%i:%s')`
   * as a raw `YYYY-MM-DD HH:MM:SS` string — NOT the Kysely-typed
   * `Generated<Date | null>` column read as a JS `Date` + `.toISOString()`.
   * This pool's mysql2 client sets `SET time_zone='+07:00'` on the MySQL
   * session (see CLAUDE.md) but does NOT set mysql2's own driver-side
   * `timezone` option, so a raw `Date` hydrated from this column would be
   * ambiguous relative to the Node process's own `TZ` env var — DATE_FORMAT()
   * sidesteps that entirely by never producing a JS Date in the first place.
   * Fed straight into `SendMessageParams.tokenExpires`, which
   * `@reya/line`'s `parseTokenExpiryMs()` already documents it expects in
   * exactly this bare "YYYY-MM-DD HH:MM:SS" shape.
   */
  reply_token_expires_str: string | null;
}

interface LineAccountTokenRow {
  channel_access_token: string;
}

function errorResult(status: number, error: string): SendMessageActionResult {
  return { status, body: { success: false, error } };
}

/**
 * Thai error for facebook/tiktok platform users — see the module doc above
 * for why this is a NEW message and not a port of PHP's two distinct
 * per-platform branches.
 */
const UNSUPPORTED_PLATFORM_MESSAGE =
  'ยังไม่รองรับการส่งข้อความ Facebook/TikTok จากหน้านี้ (ยังไม่ได้ย้ายมา Next.js)';

/** Mirrors PHP's `(int)$value` / `intval($value)` cast on a decoded JSON body value (number or string). */
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

/** `date('H:i')` in Asia/Bangkok — inbox-v2.php line 969's `'time' => date('H:i')` (server runs in Asia/Bangkok, CLAUDE.md). */
function bangkokTimeHHmm(): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date());
}

export async function sendMessageAction(
  db: Kysely<TenantDB>,
  session: TenantSession,
  userId: number,
  message: string,
  replyToId: number | null
): Promise<SendMessageActionResult> {
  // inbox-v2.php line 243: `SELECT * FROM users WHERE id = ?` — narrowed to
  // the columns this action actually reads (see SendMessageUserRow doc for
  // why reply_token_expires is DATE_FORMAT'd instead of `SELECT *`).
  const userRows = await sql<SendMessageUserRow>`
    SELECT id, line_user_id, line_account_id, platform, reply_token,
      DATE_FORMAT(reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM users WHERE id = ${userId}
  `.execute(db);
  const user = userRows.rows[0];
  if (!user) {
    // inbox-v2.php line 247: `throw new Exception("User not found")`. PHP's
    // outer catch (lines 980-982) turns every thrown Exception into a flat
    // HTTP 400 — this Route Handler deliberately returns the more correct
    // 404 instead (per brief), keeping PHP's literal error TEXT.
    return errorResult(404, 'User not found');
  }

  const userPlatform = user.platform ?? 'line'; // inbox-v2.php line 249: `$user['platform'] ?? 'line'`
  if (userPlatform === 'facebook' || userPlatform === 'tiktok') {
    return errorResult(400, UNSUPPORTED_PLATFORM_MESSAGE);
  }

  // inbox-v2.php lines 305-306: `$lineManager->getLineAPI($user['line_account_id'])`.
  // classes/LineAccountManager.php::getLineAPI() falls back to a
  // config-constant-backed `new LineAPI()` when no `line_accounts` row
  // matches — Next has no such legacy config fallback, so a missing/invalid
  // `line_account_id` is an explicit 400 here instead of silently sending
  // through some default channel token.
  const lineAccountRows = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${user.line_account_id}
  `.execute(db);
  const lineAccount = lineAccountRows.rows[0];
  if (!lineAccount) {
    return errorResult(400, 'ไม่พบการเชื่อมต่อ LINE OA สำหรับผู้ใช้นี้ (line_account_id ไม่ถูกต้อง)');
  }

  // inbox-v2.php lines 307-315: `$line->sendMessage($user['line_user_id'], $message, $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId)`.
  const result: SendMessageResult = await lineApiSendMessage(
    {
      userId: user.line_user_id,
      messages: message,
      replyToken: user.reply_token,
      tokenExpires: user.reply_token_expires_str,
      internalUserId: user.id,
      onReplyTokenUsed: async () => {
        // classes/LineAPI.php::sendMessage() clears the single-use reply
        // token itself via clearReplyToken($db, $userId) internally.
        // @reya/line has zero @reya/db dependency (packages/line/src/api.ts's
        // module doc), so that side effect is this injected callback.
        await db
          .updateTable('users')
          .set({ reply_token: null, reply_token_expires: null })
          .where('id', '=', userId)
          .execute();
      },
    },
    { channelAccessToken: lineAccount.channel_access_token }
  );

  if (result.code !== 200) {
    // inbox-v2.php: `throw new Exception("LINE API Error (HTTP " . ($result['code'] ?? 0) . ", " . ($result['method'] ?? 'push') . "): " . (isset($result['body']) ? json_encode($result['body'], JSON_UNESCAPED_UNICODE) : 'no body'))`.
    // @reya/line's SendMessageResult always carries code/method/body, so the
    // `?? 0`/`?? 'push'`/`isset()` PHP fallbacks are unreachable here.
    // JSON.stringify() handles Unicode natively — no JSON_UNESCAPED_UNICODE equivalent needed.
    return errorResult(400, `LINE API Error (HTTP ${result.code}, ${result.method}): ${JSON.stringify(result.body)}`);
  }

  // inbox-v2.php lines 339-347: adminName resolution from `$_SESSION['admin_user']`
  // (username, else display_name, else 'Admin'). TenantSession carries
  // `.username` directly (not nested), so this collapses to one line.
  const adminName = session.username || 'Admin';

  // inbox-v2.php lines 349-361: the `SHOW COLUMNS ... LIKE 'sent_by'`
  // defensive branch is dead code on the current committed schema (`sent_by`
  // always exists — packages/db/src/generated/tenant-db.d.ts's Messages
  // interface) and is NOT replicated, matching api/inbox/messages/route.ts's
  // own "unreachable dead code, not replicated" precedent.
  const insertResult = await db
    .insertInto('messages')
    .values({
      line_account_id: user.line_account_id,
      user_id: userId,
      direction: 'outgoing',
      message_type: 'text',
      content: message,
      sent_by: `admin:${adminName}`,
      reply_to_id: replyToId,
      created_at: sql<Date>`NOW()`,
      is_read: 0,
    })
    .executeTakeFirstOrThrow();
  const msgId = Number(insertResult.insertId ?? 0);

  // mb_substr($message, 0, 100) equivalent — first 100 Unicode code points.
  // `Array.from()` splits by code point (correctly handling surrogate
  // pairs/astral characters), unlike a naive `.slice(0, 100)` over raw
  // UTF-16 code units.
  const truncatedMessage = Array.from(message).slice(0, 100).join('');

  // Literal port of `ActivityLogger::logMessage(ActivityLogger::ACTION_SEND, 'ส่งข้อความถึงลูกค้า', [...])`
  // -> `log(TYPE_MESSAGE, ACTION_SEND, ...)`. log_type is 'message'
  // (TYPE_MESSAGE), action is 'send' (ACTION_SEND) — NOT 'data'/'update'.
  await db
    .insertInto('activity_logs')
    .values({
      log_type: 'message',
      action: 'send',
      description: 'ส่งข้อความถึงลูกค้า',
      user_id: userId,
      admin_id: session.adminUserId,
      admin_name: session.username,
      entity_type: 'message',
      entity_id: msgId,
      new_value: JSON.stringify({ message: truncatedMessage }),
      line_account_id: user.line_account_id,
    })
    .execute();

  return {
    status: 200,
    body: {
      success: true,
      message_id: msgId,
      content: message,
      time: bangkokTimeHHmm(),
      sent_by: `admin:${adminName}`,
      method: result.method,
      method_label: result.method === 'reply' ? '✓ Reply (ฟรี)' : '💰 Push',
    },
  };
}
