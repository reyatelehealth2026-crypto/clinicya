/**
 * lineMarkAsRead.ts — a small, locally-scoped, fetch-based port of
 * classes/LineAPI.php::markAsRead() (lines 1163-1207):
 *
 * ```php
 * public function markAsRead($markAsReadToken)
 * {
 *     $url = 'https://api.line.me/v2/bot/chat/markAsRead';
 *     $headers = [
 *         'Content-Type: application/json',
 *         'Authorization: Bearer ' . $this->channelAccessToken
 *     ];
 *     $data = ['markAsReadToken' => $markAsReadToken];
 *     // ...curl POST $data to $url with $headers...
 *     if ($httpCode === 200) {
 *         return ['success' => true];
 *     }
 *     return ['success' => false, 'error' => $error ?: 'HTTP ' . $httpCode, 'response' => json_decode($response, true)];
 * }
 * ```
 *
 * NOT added to `@reya/line` (packages/line/src/api.ts) — that package's own
 * module doc explicitly lists `markAsRead` among the endpoints "deferred to
 * Phase 6 proper" (mig-line's territory), and packages/line is a
 * zero-`@reya/*`-dependency, cross-team-owned package this batch must not
 * touch. This helper is kept local to this Route Handler's own `_lib/`
 * instead, using the same dependency-injectable `fetchImpl` test convention
 * `@reya/line`'s own api.ts uses (see its `LineFetch`/`defaultFetch`) so
 * this action's tests never make a real network call either. A future
 * Phase 6 batch may choose to hoist this into `@reya/line` once that
 * package takes ownership of markAsRead — this file's shape (injectable
 * transport, `LineApiOptions`-style options bag) is deliberately kept close
 * to that package's own conventions to make that future hoist mechanical.
 */

/** Minimal shape this file needs from a fetch response — mirrors @reya/line's own LineHttpResponse. */
export interface LineMarkAsReadHttpResponse {
  readonly status: number;
}

export interface LineMarkAsReadRequestInit {
  method: 'POST';
  headers: Record<string, string>;
  body: string;
}

/** Injectable transport signature. Tests supply a mock satisfying this; production code doesn't need to. */
export type LineMarkAsReadFetch = (
  url: string,
  init: LineMarkAsReadRequestInit
) => Promise<LineMarkAsReadHttpResponse>;

export interface LineMarkAsReadOptions {
  /**
   * Channel access token. Always sourced from the `line_accounts` DB row
   * for the account in question, never a hardcoded constant (CLAUDE.md:
   * "Always pass token + secret from the `line_accounts` DB row, not from
   * constants").
   */
  channelAccessToken: string;
  /** Injectable HTTP transport. Defaults to the real global-fetch-backed implementation below. */
  fetchImpl?: LineMarkAsReadFetch;
}

export interface LineMarkAsReadResult {
  success: boolean;
  /**
   * curl_error($ch) equivalent (a transport-level failure message), or
   * `'HTTP {code}'` when the call completed but wasn't a 200 — mirrors
   * classes/LineAPI.php::markAsRead()'s `$error ?: 'HTTP ' . $httpCode`
   * fallback chain. Absent on success.
   */
  error?: string;
}

const LINE_MARK_AS_READ_URL = 'https://api.line.me/v2/bot/chat/markAsRead';

/**
 * Real (non-stubbed) default transport — calls the runtime's global `fetch`
 * (available in Node 18+ without imports). Read via `globalThis` rather
 * than a bare `fetch` identifier for the same reason @reya/line's own
 * `defaultFetch` does: this file's ambient types don't declare one.
 */
const defaultFetch: LineMarkAsReadFetch = (url, init) => {
  const runtimeFetch = (globalThis as unknown as { fetch?: LineMarkAsReadFetch }).fetch;
  if (typeof runtimeFetch !== 'function') {
    throw new Error(
      '[mark-as-read-on-line] global fetch is unavailable in this runtime; pass an explicit fetchImpl.'
    );
  }
  return runtimeFetch(url, init);
};

/**
 * Port of classes/LineAPI.php::markAsRead(). PHP guards against an empty
 * token itself (`if (empty($markAsReadToken))`) — the only caller of this
 * function (`markAsReadOnLine.ts`) never invokes it with an empty token
 * (mirrors the PHP call site, which only reaches `$lineApi->markAsRead(...)`
 * after the `empty($messages)` early-return has already run, and the SQL
 * feeding `$messages` filters `mark_as_read_token IS NOT NULL`), so that
 * guard is not duplicated here.
 */
export async function lineMarkAsRead(
  markAsReadToken: string,
  options: LineMarkAsReadOptions
): Promise<LineMarkAsReadResult> {
  const fetchImpl = options.fetchImpl ?? defaultFetch;

  try {
    const response = await fetchImpl(LINE_MARK_AS_READ_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${options.channelAccessToken}`,
      },
      body: JSON.stringify({ markAsReadToken }),
    });

    if (response.status === 200) {
      return { success: true };
    }
    return { success: false, error: `HTTP ${response.status}` };
  } catch (error) {
    // Mirrors curl_exec() returning false + curl_error($ch) populated on a
    // transport-level failure (DNS, TLS, connection refused, timeout, ...)
    // — PHP's markAsRead() never throws for this, it just returns
    // ['success' => false, 'error' => $error]; reproduced the same way
    // here instead of letting the rejection propagate to the caller.
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message };
  }
}
