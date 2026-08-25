import { buildPhpRequestUrl } from '@/lib/config'

/**
 * The LIFF access token for the current session, if LIFF is available.
 *
 * PHASE 6. The PHP loyalty endpoints identify the caller from a `line_user_id`
 * REQUEST PARAMETER, which anyone can change to read or mutate another member's
 * points. `includes/liff-auth.php` can verify a bearer token against LINE and
 * reject a mismatch — the AI-chat endpoints already use it — but the loyalty
 * calls never sent one, so it had nothing to verify.
 *
 * Attaching it here starts the flow. The server verifies it whenever it is
 * present and rejects a mismatch immediately; refusing requests that omit it
 * entirely is a separate switch (LIFF_STRICT_AUTH) a tenant flips once this
 * client is deployed and the logs show tokens arriving.
 *
 * Never throws: a browser outside LIFF, or a not-yet-initialised SDK, simply
 * produces no header and the call behaves exactly as before.
 */
async function liffAuthHeader(): Promise<Record<string, string>> {
  if (typeof window === 'undefined') return {}

  try {
    const liff = (window as unknown as { liff?: { isLoggedIn?: () => boolean; getAccessToken?: () => string | null } }).liff
    if (!liff?.getAccessToken) return {}
    if (typeof liff.isLoggedIn === 'function' && !liff.isLoggedIn()) return {}

    const token = liff.getAccessToken()
    return token ? { Authorization: `Bearer ${token}` } : {}
  } catch {
    return {}
  }
}

async function parseResponse<T>(response: Response): Promise<T> {
  // Read as text first so we can give a useful error even when the server
  // returns JSON body with a wrong content-type header (common with PHP).
  const text = await response.text()
  try {
    return JSON.parse(text) as T
  } catch {
    const contentType = response.headers.get('content-type') || 'unknown'
    const snippet = text.slice(0, 200).replace(/\s+/g, ' ').trim()
    throw new Error(
      `PHP API returned non-JSON response (status=${response.status}, content-type=${contentType}): ${snippet}`
    )
  }
}

/**
 * GET a legacy PHP endpoint.
 *
 * `endpointKey` is optional and purely additive: pass it to make this call
 * site independently overridable via `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES`
 * (see config.ts's `resolveEndpointTarget` / `buildPhpRequestUrl`). When
 * omitted, the lookup key defaults to `` `GET ${path}` `` — since the
 * override map is empty until explicitly configured, every existing call
 * site (none of which pass this param) resolves exactly as before the
 * override map existed.
 */
export async function phpGet<T>(
  path: string,
  params?: Record<string, string | number | undefined>,
  endpointKey?: string
) {
  const url = buildPhpRequestUrl(path, params, endpointKey ?? `GET ${path}`)

  const response = await fetch(url, {
    method: 'GET',
    cache: 'no-store',
    headers: await liffAuthHeader()
  })

  return parseResponse<T>(response)
}

/** POST a legacy PHP endpoint. See phpGet's doc comment for `endpointKey` semantics (default key here is `` `POST ${path}` ``). */
export async function phpPost<T>(path: string, body: Record<string, unknown>, endpointKey?: string) {
  const url = buildPhpRequestUrl(path, undefined, endpointKey ?? `POST ${path}`)

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(await liffAuthHeader())
    },
    body: JSON.stringify(body)
  })

  return parseResponse<T>(response)
}
