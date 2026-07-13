import { buildPhpRequestUrl } from '@/lib/config'

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
    cache: 'no-store'
  })

  return parseResponse<T>(response)
}

/** POST a legacy PHP endpoint. See phpGet's doc comment for `endpointKey` semantics (default key here is `` `POST ${path}` ``). */
export async function phpPost<T>(path: string, body: Record<string, unknown>, endpointKey?: string) {
  const url = buildPhpRequestUrl(path, undefined, endpointKey ?? `POST ${path}`)

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(body)
  })

  return parseResponse<T>(response)
}
