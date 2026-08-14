import {
  sendMessage as lineSendMessage,
  pushMessage as linePushMessage,
  type LineApiOptions,
  type SendMessageResult,
} from '@reya/line';

/**
 * _lib/lineNotify.ts — literal port of shop/orders.php's own inline
 * LINE-send dispatch, present VERBATIM at both POST branches (update_status:
 * lines 293-298; approve_payment: lines 323-328):
 *
 *   if (method_exists($line, 'sendMessage')) {
 *       $line->sendMessage($lineUserId, $msg, $replyToken ?? null, $replyTokenExpires ?? null, $db);
 *   } else {
 *       $line->pushMessage($lineUserId, $msg);
 *   }
 *
 * `@reya/line`'s `sendMessage` export is a real function reference at import
 * time in production, so `typeof dispatcher.sendMessage === 'function'` is
 * always true there — this conditional is dead code in real traffic, the
 * same way PHP's own `method_exists()` check against a `LineAPI` instance
 * that always defines `sendMessage()` is dead code there too. Replicated
 * (not silently collapsed to a bare `sendMessage()` call) per this batch's
 * brief: "preserve ... exactly as written".
 *
 * `dispatcher` is injectable (defaults to the real `@reya/line` exports)
 * purely for test ergonomics — it lets a test force the `method_exists ===
 * false` branch deterministically (`dispatcher: { sendMessage: undefined,
 * pushMessage: stub }`) without fighting Jest module-mock mutation
 * semantics. Production callers (actions.ts) never pass a third argument.
 *
 * NOTE: `@reya/line`'s own `sendMessage()` ALREADY performs the
 * reply-token-first / push-fallback logic PHP's `LineAPI::sendMessage()`
 * does internally (see packages/line/src/api.ts's own module doc) — this
 * function does not re-implement that. It only reproduces the OUTER
 * method_exists-vs-not branch shop/orders.php itself contains.
 */
export interface NotifyOrderByLineParams {
  userId: string;
  message: string;
  /** `$order['reply_token'] ?? null` (PHP line 295/326). */
  replyToken: string | null;
  /** `$order['reply_token_expires'] ?? null` — a bare "YYYY-MM-DD HH:MM:SS" string (Asia/Bangkok local), see queries fetching it via DATE_FORMAT(). */
  tokenExpires: string | null;
  internalUserId?: number | null;
}

export interface LineNotifyDispatcher {
  sendMessage?: typeof lineSendMessage;
  pushMessage: typeof linePushMessage;
}

const defaultDispatcher: LineNotifyDispatcher = { sendMessage: lineSendMessage, pushMessage: linePushMessage };

export async function notifyOrderByLine(
  params: NotifyOrderByLineParams,
  options: LineApiOptions,
  dispatcher: LineNotifyDispatcher = defaultDispatcher
): Promise<SendMessageResult> {
  if (typeof dispatcher.sendMessage === 'function') {
    return dispatcher.sendMessage(
      {
        userId: params.userId,
        messages: params.message,
        replyToken: params.replyToken,
        tokenExpires: params.tokenExpires,
        internalUserId: params.internalUserId ?? null,
      },
      options
    );
  }
  // method_exists($line, 'sendMessage') === false branch — dead in
  // production, exercised in tests via an injected dispatcher.
  const pushResult = await dispatcher.pushMessage(params.userId, params.message, options);
  return { ...pushResult, method: 'push' };
}
