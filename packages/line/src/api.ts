// api.ts — TypeScript port of classes/LineAPI.php's webhook-signature verification and the
// "smart" reply-token-first / push-fallback sender (docs/plans/2026-07-12-nextjs-full-migration-plan.md
// Phase 6, risk #1). Scope for this pass (see the mig-line brief): validateSignature(),
// replyMessage(), pushMessage(), and the sendMessage() dispatcher only. Rich menus, multicast/
// narrowcast/broadcast, LIFF helpers, getMessageContent/group/room helpers, markAsRead, and
// quota/insight endpoints are explicitly deferred to Phase 6 proper.
//
// Zero @reya/* dependencies by design (matches packages/contracts' isolation pattern) — this
// package must never gain a build-order dependency on packages/db or packages/tenant just to
// satisfy LineAPI.php::clearReplyToken()'s raw `UPDATE users SET reply_token = NULL ...` side
// effect. That side effect is exposed below as an injectable callback (`onReplyTokenUsed`)
// instead: the webhook worker (Phase 6) supplies a closure that does the real UPDATE.
//
// The HTTP transport is a real (non-stubbed) implementation that calls the runtime's global
// `fetch` by default, but is fully dependency-injectable via `LineApiOptions.fetchImpl` so tests
// exercise zero live network calls (see tests/api.test.ts).

import { createHmac, timingSafeEqual } from 'node:crypto';

// ---------------------------------------------------------------------------------------------
// Message types — deliberately local (not imported from ./flex) so this file stays independently
// buildable regardless of what packages/line/src/flex.ts (owned by the flexTemplates builder)
// looks like. Flex-specific structural validation lives over there; this is the generic
// Messaging API envelope shape every message object (text, flex, image, ...) satisfies.
// ---------------------------------------------------------------------------------------------

/** A single LINE Messaging API message object — `{ type: "text" | "flex" | ..., ... }`. */
export interface LineMessage {
  type: string;
  [key: string]: unknown;
}

/**
 * What callers may pass as "the message(s) to send" — mirrors classes/LineAPI.php's dynamic
 * `$messages` parameter, which accepts a bare string, a single associative-array message, or an
 * array of message objects. See `normalizeReplyOrPushMessages()` (replyMessage()/pushMessage()'s
 * own `is_array()` check) and `normalizeSendMessages()` (sendMessage()'s extra
 * `isset($messages['type'])` pre-wrap) below for the exact porting of each function's branching —
 * they differ from each other, matching PHP.
 */
export type LineMessageInput = string | LineMessage | LineMessage[];

/** Raw result of a single LINE API call — mirrors PHP's `['code' => int, 'body' => mixed]`. */
export interface LineApiCallResult {
  code: number;
  body: unknown;
}

export type SendMethod = 'reply' | 'push';

/** Result of `sendMessage()` — mirrors PHP's `['code' => ..., 'body' => ..., 'method' => 'reply'|'push']`. */
export interface SendMessageResult extends LineApiCallResult {
  method: SendMethod;
}

// ---------------------------------------------------------------------------------------------
// Injectable HTTP transport
// ---------------------------------------------------------------------------------------------

/**
 * Minimal shape this package needs from a fetch response. Deliberately not the DOM `Response`
 * type — this package's tsconfig has no "dom" lib (matching packages/contracts/packages/auth),
 * and @types/node here does not ambiently declare `fetch`/`Response` either, so we own a small
 * structural type instead of reaching for global ambient types that may or may not exist.
 */
export interface LineHttpResponse {
  readonly status: number;
  json(): Promise<unknown>;
}

export interface LineHttpRequestInit {
  method: 'POST';
  headers: Record<string, string>;
  body: string;
}

/** Injectable transport signature. Tests supply a mock satisfying this; production code doesn't need to. */
export type LineFetch = (url: string, init: LineHttpRequestInit) => Promise<LineHttpResponse>;

const DEFAULT_API_ENDPOINT = 'https://api.line.me/v2/bot';

/**
 * Real (non-stubbed) default transport — calls the runtime's global `fetch` (available in
 * Node 18+ without imports). Read via `globalThis` rather than a bare `fetch` identifier because
 * this package's @types/node does not declare one ambiently; the cast is narrow and contained
 * entirely inside this function, so no `any` reaches an exported signature.
 */
const defaultFetch: LineFetch = (url, init) => {
  const runtimeFetch = (globalThis as unknown as { fetch?: LineFetch }).fetch;
  if (typeof runtimeFetch !== 'function') {
    throw new Error(
      '[@reya/line] global fetch is unavailable in this runtime; pass an explicit fetchImpl in LineApiOptions.'
    );
  }
  return runtimeFetch(url, init);
};

export interface LineApiOptions {
  /**
   * Channel access token. Always sourced from the `line_accounts` DB row for the account in
   * question, never a hardcoded constant (CLAUDE.md: "Always pass token + secret from the
   * `line_accounts` DB row, not from constants").
   */
  channelAccessToken: string;
  /** Overrides the LINE API base — default matches LineAPI.php's `$apiEndpoint`. Mainly for tests. */
  apiEndpoint?: string;
  /** Injectable HTTP transport. Defaults to the real global-fetch-backed implementation above. */
  fetchImpl?: LineFetch;
}

/**
 * Normalizes `$messages` the way classes/LineAPI.php::replyMessage()/::pushMessage() do it
 * *themselves* (lines 82-84, 99-101) — i.e. when either is called directly, not via
 * sendMessage():
 *   if (!is_array($messages)) { $messages = [['type' => 'text', 'text' => $messages]]; }
 * PHP's `is_array()` is true for both an indexed list of messages AND a single associative-array
 * message (`['type' => 'text', 'text' => '...']`) — PHP has no separate "object" type, so a lone
 * message hash is passed straight through as-is, unwrapped, and `json_encode()` serializes it as
 * a JSON *object* under `"messages"`, not a one-element array. TS does distinguish `LineMessage`
 * (single object) from `LineMessage[]` (array) where PHP can't, so both are passed through here
 * unchanged to reproduce that same wire shape byte-for-byte: only a bare string gets wrapped.
 * This matters for direct callers such as webhook.php:2051's
 * `$line->replyMessage($replyToken, ['type' => 'text', 'text' => '...'])`, which puts a single
 * object (not an array) on the wire — see normalizeSendMessages() below for the *different*
 * (array-wrapping) normalization sendMessage() itself performs before ever calling these.
 */
function normalizeReplyOrPushMessages(messages: LineMessageInput): LineMessage | LineMessage[] {
  if (typeof messages === 'string') {
    return [{ type: 'text', text: messages }];
  }
  return messages;
}

/**
 * Normalizes the polymorphic `$messages` argument the way classes/LineAPI.php::sendMessage()
 * does, in sendMessage() itself, before delegating to replyMessage()/pushMessage() (lines
 * 123-129):
 *   - bare string           -> `[{ type: 'text', text: $messages }]`
 *   - single message object -> `[$messages]`               (PHP: `isset($messages['type'])`)
 *   - array of messages     -> passed through unchanged
 * This wrap happens exactly once, here, so sendMessage()'s wire format for a lone message object
 * stays array-wrapped exactly like PHP's — independent of normalizeReplyOrPushMessages() above,
 * which replyMessage()/pushMessage() apply on their own and which does *not* wrap a lone object.
 * Passing the array this function returns into replyMessage()/pushMessage() is safe: their own
 * normalization is a no-op on an input that's already an array.
 */
function normalizeSendMessages(messages: LineMessageInput): LineMessage[] {
  if (typeof messages === 'string') {
    return [{ type: 'text', text: messages }];
  }
  if (Array.isArray(messages)) {
    return messages;
  }
  return [messages];
}

async function sendRequest(
  endpoint: string,
  data: Record<string, unknown>,
  options: LineApiOptions
): Promise<LineApiCallResult> {
  const fetchImpl = options.fetchImpl ?? defaultFetch;
  const url = `${options.apiEndpoint ?? DEFAULT_API_ENDPOINT}${endpoint}`;

  const response = await fetchImpl(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${options.channelAccessToken}`,
    },
    body: JSON.stringify(data),
  });

  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    // Mirrors PHP's json_decode() returning null on an empty/invalid body instead of throwing.
    body = null;
  }

  return { code: response.status, body };
}

// ---------------------------------------------------------------------------------------------
// Signature verification — classes/LineAPI.php:959 validateSignature()
// ---------------------------------------------------------------------------------------------

/**
 * Timing-safe string comparison matching PHP's `hash_equals()` contract used at
 * classes/LineAPI.php:962: constant-time for equal-length inputs, and — critically —
 * `false`, never a thrown exception, when lengths differ. `crypto.timingSafeEqual()` throws a
 * RangeError on a length mismatch, so a naive direct port would crash on tampered/garbage
 * signatures instead of rejecting them; the length check below is what prevents that.
 */
function constantTimeStringEqual(a: string, b: string): boolean {
  const bufA = Buffer.from(a, 'utf8');
  const bufB = Buffer.from(b, 'utf8');
  if (bufA.length !== bufB.length) {
    return false;
  }
  return timingSafeEqual(bufA, bufB);
}

/**
 * Verifies a LINE webhook signature. Exact port of classes/LineAPI.php:959-963:
 *   $hash = base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
 *   return hash_equals($hash, $signature);
 *
 * `body` accepts the raw request body as either the string PHP works with or a Buffer (a Route
 * Handler reading the raw bytes off the request stream) — `createHmac().update()` accepts both
 * and, given the same underlying UTF-8 bytes, hashes them identically to PHP's byte-string HMAC.
 */
export function validateSignature(body: string | Buffer, signature: string, channelSecret: string): boolean {
  const expected = createHmac('sha256', channelSecret).update(body).digest('base64');
  return constantTimeStringEqual(expected, signature);
}

// ---------------------------------------------------------------------------------------------
// reply / push — classes/LineAPI.php:80-108
// ---------------------------------------------------------------------------------------------

/** Port of classes/LineAPI.php::replyMessage() — POST /message/reply. Single-use: the reply token cannot be reused. */
export async function replyMessage(
  replyToken: string,
  messages: LineMessageInput,
  options: LineApiOptions
): Promise<LineApiCallResult> {
  return sendRequest(
    '/message/reply',
    { replyToken, messages: normalizeReplyOrPushMessages(messages) },
    options
  );
}

/** Port of classes/LineAPI.php::pushMessage() — POST /message/push. Costs against the account's push quota. */
export async function pushMessage(
  userId: string,
  messages: LineMessageInput,
  options: LineApiOptions
): Promise<LineApiCallResult> {
  return sendRequest(
    '/message/push',
    { to: userId, messages: normalizeReplyOrPushMessages(messages) },
    options
  );
}

// ---------------------------------------------------------------------------------------------
// sendMessage — the smart reply-first / push-fallback dispatcher, classes/LineAPI.php:121-161
// ---------------------------------------------------------------------------------------------

/** Info handed to the reply-token-clear callback — mirrors LineAPI.php::clearReplyToken()'s two lookup keys. */
export interface ReplyTokenClearInfo {
  /** LINE user ID the token was issued for — always present. */
  lineUserId: string;
  /**
   * Internal `users.id` PK, when the caller has it. Mirrors clearReplyToken()'s branching:
   * PHP prefers `UPDATE users SET ... WHERE id = ?` when `$internalUserId` is given, and falls
   * back to `WHERE line_user_id = ?` otherwise.
   */
  internalUserId?: string | number | null;
}

/**
 * Injectable replacement for classes/LineAPI.php::clearReplyToken()'s direct
 * `UPDATE users SET reply_token = NULL, reply_token_expires = NULL WHERE ...` side effect.
 * packages/line has zero @reya/* dependencies this round, so it cannot call packages/db itself —
 * the Phase 6 webhook worker supplies a closure here that performs the real UPDATE.
 *
 * Invoked exactly once per sendMessage() call whenever a non-empty reply token was supplied
 * (whether the reply attempt then succeeded, failed, or the token had already expired) — never
 * when no token was supplied at all, since there is nothing to clear in that case. This preserves
 * the reply-token economy: a token is single-use no matter which branch consumed it.
 */
export type ClearReplyTokenCallback = (info: ReplyTokenClearInfo) => void | Promise<void>;

export interface SendMessageParams {
  /** LINE user ID — push target if reply is unavailable/expired/fails. */
  userId: string;
  messages: LineMessageInput;
  /** Reply token from the webhook event, if any. Falsy/empty is treated as "no token" (push-only). */
  replyToken?: string | null;
  /**
   * `users.reply_token_expires` value, if known — a DB DATETIME string (Asia/Bangkok local time,
   * no offset) or a full ISO 8601 timestamp. Falsy/empty means "no expiry recorded", matching
   * PHP's `if ($tokenExpires) { ... }` guard, and the token is treated as still valid.
   */
  tokenExpires?: string | null;
  /** Internal `users.id`, forwarded to `onReplyTokenUsed` — see ReplyTokenClearInfo. */
  internalUserId?: string | number | null;
  /** See ClearReplyTokenCallback. Optional — omit if the caller doesn't need the side effect (e.g. pure dry-run/shadow-mode decision diffing, where nothing is actually cleared). */
  onReplyTokenUsed?: ClearReplyTokenCallback;
}

/**
 * LINE reply tokens expire ~30 seconds after the webhook event that carried them. DB timestamps
 * in this codebase are always Asia/Bangkok local time with no explicit offset (CLAUDE.md:
 * "Timezone is always Asia/Bangkok (+07:00)"), matching PHP's strtotime() running under the
 * server's Asia/Bangkok default timezone — so a bare "YYYY-MM-DD HH:MM:SS" (or "...THH:MM:SS")
 * string is normalized to that offset before parsing, rather than trusting the host's local
 * timezone (which may not be Asia/Bangkok at all, e.g. in CI).
 */
function parseTokenExpiryMs(tokenExpires: string): number {
  const trimmed = tokenExpires.trim();
  // Already carries an explicit zone (Z, or a numeric +HH:MM/-HH:MM offset) — parse as-is.
  if (/(?:[zZ]|[+-]\d{2}:?\d{2})$/.test(trimmed)) {
    return Date.parse(trimmed);
  }
  const isoLike = trimmed.includes('T') ? trimmed : trimmed.replace(' ', 'T');
  return Date.parse(`${isoLike}+07:00`);
}

/**
 * Mirrors PHP: `strtotime($tokenExpires) > time()`. `strtotime()` returning `false` on
 * unparseable input coerces to `0` in a numeric comparison, i.e. always "expired" — replicated
 * here via the `Number.isNaN` guard.
 */
function isReplyTokenStillValid(tokenExpires: string, nowMs: number): boolean {
  const expiresMs = parseTokenExpiryMs(tokenExpires);
  if (Number.isNaN(expiresMs)) {
    return false;
  }
  return expiresMs > nowMs;
}

/**
 * Smart send — exact port of classes/LineAPI.php:121-161 (`sendMessage`):
 *   1. If a non-empty, non-expired reply token was supplied, try replyMessage() first (free).
 *   2. Either way (success, failure, or expiry), clear the single-use token exactly once via
 *      `onReplyTokenUsed` — but only ever when a token was actually supplied.
 *   3. If the reply attempt returned HTTP 200, return it with `method: 'reply'` — pushMessage()
 *      is never called.
 *   4. Otherwise (no token / expired token / reply attempt returned non-200), fall back to
 *      pushMessage() and return it with `method: 'push'`.
 */
export async function sendMessage(
  params: SendMessageParams,
  options: LineApiOptions
): Promise<SendMessageResult> {
  const { userId, messages, replyToken, tokenExpires, internalUserId, onReplyTokenUsed } = params;

  // Pre-wrap exactly like PHP's sendMessage() (lines 123-129) does before ever calling
  // replyMessage()/pushMessage() — this is what keeps sendMessage()'s wire format for a lone
  // message object array-wrapped regardless of how normalizeReplyOrPushMessages() treats a
  // direct replyMessage()/pushMessage() call (see that function's doc comment above).
  const normalizedMessages = normalizeSendMessages(messages);

  if (replyToken) {
    const hasExpiry = tokenExpires !== null && tokenExpires !== undefined && tokenExpires !== '';
    const isValid = hasExpiry ? isReplyTokenStillValid(tokenExpires, Date.now()) : true;

    if (isValid) {
      const result = await replyMessage(replyToken, normalizedMessages, options);
      await onReplyTokenUsed?.({ lineUserId: userId, internalUserId: internalUserId ?? null });

      if (result.code === 200) {
        return { ...result, method: 'reply' };
      }
      // Reply failed (non-200) — fall through to push below.
    } else {
      // Token already expired — clear it, then fall through to push without ever attempting reply.
      await onReplyTokenUsed?.({ lineUserId: userId, internalUserId: internalUserId ?? null });
    }
  }

  const pushResult = await pushMessage(userId, normalizedMessages, options);
  return { ...pushResult, method: 'push' };
}
