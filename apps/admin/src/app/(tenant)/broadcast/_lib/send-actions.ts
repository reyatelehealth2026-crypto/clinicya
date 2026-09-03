'use server';

import { sql, type Kysely } from 'kysely';
import { redirect } from 'next/navigation';
import type { TenantDB } from '@reya/db';
import type { LineMessage } from '@reya/line';
import { requireTenantPageContext } from '../../users/_lib/session';
import { resolveCurrentBotId } from './send-queries';
import { executeBroadcastSend, type SendTabTargetType } from './broadcastFanout';

/**
 * send-actions.ts — Server Actions for includes/broadcast/send.php's two POST handlers.
 * Read the full 865-line source before touching this file.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * Two DELIBERATE omissions (per this batch's brief — grep-checked by mig-verify, see the
 * acceptance criteria):
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. send.php lines 16-25, the "Auto DB Migration for target_group_id" block
 *    (`SHOW COLUMNS ... ALTER TABLE broadcasts MODIFY target_group_id VARCHAR(255)`) — NOT
 *    ported. The committed tenant template + generated `tenant-db.d.ts` already type
 *    `broadcasts.target_group_id` as `string | null` (VARCHAR(255)), so the runtime ALTER is
 *    a dead legacy shim against the schema this port targets. CLAUDE.md also explicitly
 *    discourages page-load auto-ALTER for new features ("Auto-create tables" convention
 *    note) — reproducing it here would be adding a NEW instance of the discouraged pattern,
 *    not preserving an existing one this port must match.
 *
 * 2. send.php lines 117-129, the "Trigger process of any scheduled broadcasts in background"
 *    fire-and-forget `@file_get_contents($triggerUrl, ...)` GET to
 *    `api/process_scheduled_broadcasts.php` on EVERY page view — NOT ported. This is a
 *    real-send-triggering side effect (it processes and SENDS any due scheduled broadcasts)
 *    fired unconditionally whenever send.php merely renders. Reproducing it as an SSR-render-
 *    time fetch here would risk double/uncontrolled firing (React Strict Mode double-
 *    invocation, ISR/on-demand revalidation, Next's aggressive Link-hover prefetching all
 *    re-invoking a Server Component render) — none of which PHP's single-request-per-view
 *    model is exposed to. Scheduled-broadcast processing is Phase 10 / mig-worker's cron-
 *    manifest territory (a real interval-based cron job, not a page-view side effect), not
 *    this page port's to own.
 *
 * The send tab's `id="recipientPreview"` live-count fetch (`api/count_recipients.php`) is
 * likewise not reproduced as a working endpoint — see `_components/SendComposeForm.tsx`'s
 * module doc: that endpoint does not exist anywhere in this repo (verified: no file, no git
 * history), so it is already a silently-broken no-op in real production. Not this file's
 * concern (no server action backs it), noted here for completeness.
 */

// ---------------------------------------------------------------------------
// action=cancel_scheduled — send.php lines 27-37
// ---------------------------------------------------------------------------

/**
 * Port of send.php lines 27-37:
 *   $broadcastId = (int) ($_POST['broadcast_id'] ?? 0);
 *   if ($broadcastId) {
 *       UPDATE broadcasts SET status = 'failed' WHERE id = ? AND status = 'scheduled'
 *         AND (line_account_id = ? OR line_account_id IS NULL);
 *   }
 *   header('Location: broadcast.php?tab=send&cancelled=1'); exit;
 *
 * The WHERE clause is the load-bearing guard this batch's acceptance criteria specifically
 * checks: it only ever flips a row that is CURRENTLY `status = 'scheduled'` (never re-cancels
 * an already-sent/failed broadcast) AND belongs to the current bot (or a legacy NULL-scoped
 * row) — a broadcast_id for a different bot's row is silently a no-op UPDATE (0 rows
 * affected), matching PHP exactly (PHP never checks affected-row count either).
 *
 * `$broadcastId` falsy (0/missing) skips the UPDATE entirely (PHP's `if ($broadcastId)`
 * guard) but STILL redirects with `cancelled=1` — ported as-is, not "fixed" into a no-op
 * redirect or an error.
 */
export async function cancelScheduledAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = await resolveCurrentBotId(db, session.currentBotId);

  const broadcastId = Number.parseInt(String(formData.get('broadcast_id') ?? '0'), 10) || 0;
  if (broadcastId) {
    await sql`
      UPDATE broadcasts SET status = 'failed'
      WHERE id = ${broadcastId} AND status = 'scheduled' AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
    `.execute(db);
  }

  redirect('/broadcast?tab=send&cancelled=1');
}

// ---------------------------------------------------------------------------
// action=send — send.php lines 40-116
// ---------------------------------------------------------------------------

type MessageType = 'text' | 'image' | 'flex';

/** send.php lines 47-64: builds `$messages` (the LINE wire payload) AND `$content` (the raw
 * value persisted to `broadcasts.content`) per message_type. */
function buildMessages(
  messageType: MessageType,
  title: string,
  content: string
): { messages: LineMessage[]; content: string } {
  if (messageType === 'text') {
    return { messages: [{ type: 'text', text: content }], content };
  }
  if (messageType === 'image') {
    return {
      messages: [{ type: 'image', originalContentUrl: content, previewImageUrl: content }],
      content,
    };
  }
  if (messageType === 'flex') {
    // json_decode() returns null on invalid JSON without throwing — ported the same way
    // (PHP would then post a `{type:'flex', altText, contents: null}` payload to LINE, which
    // LINE itself rejects; not "fixed" into a client-side validation error here).
    let flexJson: unknown = null;
    try {
      flexJson = JSON.parse(content);
    } catch {
      flexJson = null;
    }
    return { messages: [{ type: 'flex', altText: title, contents: flexJson }], content };
  }
  // message_type outside {text,image,flex}: PHP's `else` branch — $content = '', $messages = [].
  return { messages: [], content: '' };
}

function first(formData: FormData, key: string): string {
  const v = formData.get(key);
  return typeof v === 'string' ? v : '';
}

/**
 * Port of send.php lines 40-116 (`action === 'send'`). Two send_mode branches:
 *
 *   - 'schedule' (lines 79-92): INSERTs `status = 'scheduled'` with `scheduled_at`, sent_count
 *     0, and returns WITHOUT ever calling any `@reya/line` function — the LINE API is never
 *     touched for a scheduled broadcast (only the future cron worker touches it — see this
 *     file's module doc, omission #2). Redirects to `?tab=send&scheduled=1`.
 *   - 'now' (default, lines 94-116): calls `executeBroadcastSend()` (this file's
 *     `broadcastFanout.ts`), INSERTs `status = 'sent'` with the real `sentCount`, writes an
 *     `activity_logs` row (`ActivityLogger::logMessage(ACTION_SEND, 'ส่ง Broadcast: ' .
 *     $title, [...])`), then redirects to `?tab=send&sent=N`.
 *
 * `target_group_id` storage (lines 68-71): when `target_type === 'tag'`, the selected tag ids
 * are JSON-encoded into the `target_group_id` column (`json_encode(array_values($tagIds))`,
 * or `null` if none selected) — a deliberate column-repurposing PHP already does (NOT the
 * `target_group_id` <select> value in that case). For every OTHER target_type, PHP overwrites
 * `$targetGroupId` with `$result['targetGroupId']` returned by
 * `BroadcastHelper::executeBroadcastSend()` — which is only ever non-null for the `narrowcast`
 * branch (this batch's `executeBroadcastSend()` doesn't implement that branch — see
 * broadcastFanout.ts's module doc), so `targetGroupId` is always `null` here for
 * database/all/segment/group, matching what real PHP produces on this batch's reachable
 * target types today.
 */
export async function sendBroadcastAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = await resolveCurrentBotId(db, session.currentBotId);

  const title = first(formData, 'title');
  const messageTypeRaw = first(formData, 'message_type');
  const messageType: MessageType =
    messageTypeRaw === 'image' || messageTypeRaw === 'flex' ? messageTypeRaw : 'text';
  const targetTypeRaw = first(formData, 'target_type');
  const targetType: SendTabTargetType =
    targetTypeRaw === 'all' || targetTypeRaw === 'segment' || targetTypeRaw === 'tag' || targetTypeRaw === 'group'
      ? targetTypeRaw
      : 'database';
  const sendMode = first(formData, 'send_mode') === 'schedule' ? 'schedule' : 'now';
  const scheduledAtRaw = first(formData, 'scheduled_at');

  const rawContent =
    messageType === 'text'
      ? first(formData, 'content')
      : messageType === 'image'
        ? first(formData, 'image_url')
        : first(formData, 'flex_content');
  const { messages, content } = buildMessages(messageType, title, rawContent);

  const tagIds = formData
    .getAll('tag_ids[]')
    .map((v) => Number.parseInt(String(v), 10))
    .filter((n) => Number.isFinite(n) && n > 0);
  const segmentIdRaw = first(formData, 'segment_id');
  const segmentId = segmentIdRaw !== '' ? Number.parseInt(segmentIdRaw, 10) : null;
  const targetGroupIdRaw = first(formData, 'target_group_id');
  const targetGroupIdSelect = targetGroupIdRaw !== '' ? targetGroupIdRaw : null;

  // send.php lines 67-71: tag_ids JSON-encoded into target_group_id when target_type==='tag'.
  const tagTargetGroupId = targetType === 'tag' ? (tagIds.length > 0 ? JSON.stringify(tagIds) : null) : null;

  if (sendMode === 'schedule' && scheduledAtRaw !== '') {
    const scheduledAtDb = new Date(scheduledAtRaw);
    await sql`
      INSERT INTO broadcasts (line_account_id, title, message_type, content, target_type, target_group_id, sent_count, status, scheduled_at, created_at)
      VALUES (${currentBotId}, ${title}, ${messageType}, ${content}, ${targetType}, ${targetType === 'tag' ? tagTargetGroupId : null}, 0, 'scheduled', ${scheduledAtDb}, NOW())
    `.execute(db);
    // send.php lines 84-88: ActivityLogger::logMessage(ACTION_SEND, 'ตั้งเวลา Broadcast: ...')
    // is NOT reproduced — best-effort ActivityLogger audit writes on the SCHEDULE path are
    // out of scope for this batch, matching this migration's established "audit writes are a
    // documented, flagged gap" precedent (see (tenant)/user-detail/actions.ts's module doc).
    // The SENT path below DOES write activity_logs, matching sendMessage.ts's own precedent
    // for a real user-facing send action.
    redirect('/broadcast?tab=send&scheduled=1');
  }

  const result = await executeBroadcastSend({
    db,
    currentBotId,
    lineOptions: { channelAccessToken: await resolveChannelAccessToken(db, currentBotId) },
    targetType,
    messages,
    segmentId,
    tagIds,
    targetGroupId: targetGroupIdSelect,
  });
  const sentCount = result.sentCount;
  // send.php lines 100-102: `if ($targetType !== 'tag') { $targetGroupId = $result['targetGroupId']; }`
  // — for 'tag' the earlier JSON-encoded tag_ids value stands; for every other target type,
  // executeBroadcastSend()'s own targetGroupId wins (non-null only for 'group').
  const finalTargetGroupId = targetType === 'tag' ? tagTargetGroupId : result.targetGroupId;

  const insertResult = await sql`
    INSERT INTO broadcasts (line_account_id, title, message_type, content, target_type, target_group_id, sent_count, status, sent_at, created_at)
    VALUES (${currentBotId}, ${title}, ${messageType}, ${content}, ${targetType}, ${finalTargetGroupId}, ${sentCount}, 'sent', NOW(), NOW())
  `.execute(db);
  const broadcastId = Number(insertResult.insertId ?? 0);

  await db
    .insertInto('activity_logs')
    .values({
      log_type: 'message',
      action: 'send',
      description: `ส่ง Broadcast: ${title}`,
      admin_id: session.adminUserId,
      admin_name: session.username,
      entity_type: 'broadcast',
      entity_id: broadcastId,
      new_value: JSON.stringify({ target_type: targetType, sent_count: sentCount, message_type: messageType }),
      line_account_id: currentBotId,
    })
    .execute();

  redirect(`/broadcast?tab=send&sent=${sentCount}`);
}

/**
 * `LineAccountManager::getLineAPI($currentBotId)` — resolves the channel access token for the
 * bot the send is scoped to. No config-constant fallback (same documented divergence as
 * api/inbox/actions/send-message/_lib/sendMessage.ts's own `LineAccountTokenRow` lookup): a
 * missing/invalid currentBotId throws rather than silently sending through some default
 * channel. Kept private/inline here (not exported) since only sendBroadcastAction needs it.
 */
async function resolveChannelAccessToken(db: Kysely<TenantDB>, currentBotId: number | null): Promise<string> {
  const result = await sql<{ channel_access_token: string }>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${currentBotId} LIMIT 1
  `.execute(db);
  const token = result.rows[0]?.channel_access_token;
  if (!token) {
    throw new Error('ไม่พบการเชื่อมต่อ LINE OA (line_account_id ไม่ถูกต้อง)');
  }
  return token;
}
