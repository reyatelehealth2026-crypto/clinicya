import { NextResponse } from 'next/server';

/**
 * cors.ts — shared CORS helpers for every `/api/miniapp/**` Route Handler
 * (both mig-api-reads' and mig-api-writes' lanes consume this unmodified —
 * contractNote point 3, "CORS").
 *
 * Every `/api/miniapp/**` response — success AND error — carries:
 *   Access-Control-Allow-Origin: *
 *   Access-Control-Allow-Methods: GET, POST, OPTIONS
 *   Access-Control-Allow-Headers: Content-Type
 * and OPTIONS is always answered 204, no body.
 *
 * This is a strictly-safe normalization even where a source PHP file was
 * inconsistent (wishlist.php never declared an OPTIONS branch at all, and
 * omitted OPTIONS from its own `Access-Control-Allow-Methods` header) —
 * standardizing here only affects the CORS preflight, never a real GET/POST
 * response body, so no mini-app client behavior changes.
 */

const MINIAPP_CORS_HEADERS: Readonly<Record<string, string>> = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
};

/** The literal CORS header map every /api/miniapp/** response sets. */
export function miniappCorsHeaders(): Readonly<Record<string, string>> {
  return MINIAPP_CORS_HEADERS;
}

/** Applies the standard miniapp CORS headers to an existing NextResponse (mutates + returns it). */
export function withMiniappCors(response: NextResponse): NextResponse {
  for (const [key, value] of Object.entries(MINIAPP_CORS_HEADERS)) {
    response.headers.set(key, value);
  }
  return response;
}

/** `NextResponse.json()` + the standard miniapp CORS headers — the shape every action handler returns. */
export function miniappJson(body: unknown, init?: { status?: number }): NextResponse {
  return withMiniappCors(NextResponse.json(body, { status: init?.status ?? 200 }));
}

/** Answers a CORS preflight OPTIONS request: 204, no body, CORS headers set. Export every route's `OPTIONS` handler as this. */
export function handleMiniappOptions(): NextResponse {
  return withMiniappCors(new NextResponse(null, { status: 204 }));
}

/** @deprecated alias of handleMiniappOptions — kept for call sites written against the earlier name. */
export const miniappPreflight = handleMiniappOptions;
