import { NextResponse, type NextRequest } from 'next/server';
import { resolveRequestTenant } from './lib/tenant/resolveRequestTenant';

/**
 * proxy.ts — Next-side wiring for @reya/tenant's resolveTenant(), mirroring
 * bootstrap/resolve_subdomain.php's 404/503/demo/root-domain gating exactly
 * (plan §1.3). All resolution LOGIC lives in @reya/tenant (already built +
 * tested in batch 1) — this file is purely: pull host/query off the request,
 * call resolveRequestTenant(), map the typed result to headers or an HTTP
 * response.
 *
 * FILE NAME — deviates from the brief's literal `apps/admin/middleware.ts`.
 * Verified directly against the installed Next.js version (16.2.10, matching
 * frontend/'s `^16.2.4` pin) via https://nextjs.org/docs/app/guides/upgrading/version-16
 * (fetched live, `lastUpdated: 2026-05-13`, frontmatter `version: 16.2.10` —
 * i.e. documentation for the EXACT version installed here, not a newer/older
 * major):
 *
 *   "The `middleware` filename is deprecated, and has been renamed to `proxy`
 *    ... The `edge` runtime is NOT supported in `proxy`. The `proxy` runtime
 *    is `nodejs`, and it cannot be configured. If you want to continue using
 *    the `edge` runtime, keep using `middleware`."
 *
 * In other words, on the version actually installed here:
 *   - `middleware.ts` still exists but now defaults to the EDGE runtime (the
 *     brief's `export const config = { runtime: 'nodejs' }` pattern was a
 *     Next 15.5-era opt-in — that config key is no longer honored by `proxy`,
 *     and Edge cannot run mysql2 at all, which is the hard constraint this
 *     file exists to satisfy).
 *   - `proxy.ts` (this file) is the ONLY way to get Node.js-runtime request
 *     interception on this Next version, and it gets Node.js UNCONDITIONALLY
 *     — there is no `export const config = { runtime: ... }` to write; Next
 *     16 proxy.ts docs/blog examples never show one.
 * This is flagged back to mig-orc per the brief's own instruction to surface
 * (not silently work around) any place the plan's assumptions don't match
 * the installed tool's real, verified behavior.
 */

// Skip static assets — tenant resolution only matters for actual page/API
// requests; running it against every JS/CSS/image request would be a wasted
// master-DB round trip per asset. (`matcher` is unaffected by the
// runtime-config deprecation above — only the `runtime` key was removed.)
export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon\\.ico).*)'],
};

function notFoundResponse(slug: string): NextResponse {
  const html = `<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ไม่พบร้านค้า / Shop not found</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem;color:#1f2937;">
<h1 style="font-size:1.5rem;margin-bottom:.5rem;">ไม่พบร้านค้านี้</h1>
<p style="color:#6b7280;margin:0 0 .25rem;">This shop could not be found.</p>
<p style="color:#9ca3af;font-size:.875rem;">(${slug})</p>
</body>
</html>`;
  return new NextResponse(html, {
    status: 404,
    headers: { 'content-type': 'text/html; charset=utf-8' },
  });
}

function suspendedResponse(displayName: string): NextResponse {
  const html = `<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ร้านค้าถูกระงับชั่วคราว / Shop suspended</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem;color:#1f2937;">
<h1 style="font-size:1.5rem;margin-bottom:.5rem;">ร้านค้านี้ถูกระงับการใช้งานชั่วคราว</h1>
<p style="color:#6b7280;margin:0 0 .25rem;">This shop (${displayName}) is temporarily suspended.</p>
<p style="color:#9ca3af;font-size:.875rem;">กรุณาติดต่อผู้ดูแลระบบ / Please contact support.</p>
</body>
</html>`;
  return new NextResponse(html, {
    status: 503,
    headers: { 'content-type': 'text/html; charset=utf-8' },
  });
}

export async function proxy(request: NextRequest): Promise<NextResponse> {
  const host = request.headers.get('host');
  const query = Object.fromEntries(request.nextUrl.searchParams.entries());

  const result = await resolveRequestTenant(host, query);

  switch (result.kind) {
    case 'tenant': {
      const headers = new Headers(request.headers);
      headers.set('x-tenant-id', String(result.tenantId));
      headers.set('x-tenant-slug', result.slug);
      headers.set('x-tenant-is-root', String(result.isRoot));
      headers.set('x-tenant-demo-mode', String(result.demoMode));
      return NextResponse.next({ request: { headers } });
    }
    case 'not_found':
      return notFoundResponse(result.slug);
    case 'suspended':
      return suspendedResponse(result.displayName);
    case 'none':
      // Reserved subdomain, unmatched host, or root-domain request carrying an
      // explicit LINE-account signal — routeByLineAccount() is a downstream
      // Route Handler's concern (webhook/checkout/member/ai-chat), not proxy's.
      return NextResponse.next();
  }
}
