import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
import { getTenantDb } from '@reya/db';
import { login, runWithTenantDb, type AuthResult, type LoginInput, type LoginValue, type Realm } from '@reya/auth';

/**
 * POST /api/auth/login — consumes @reya/auth's login() exactly per the
 * interface contract: sets the returned cookie via next/headers cookies()
 * using the returned SessionCookieDescriptor as-is, then redirects into
 * (tenant) or (platform) based on session.realm. bridgeSynced:false is a
 * non-blocking soft warning (surfaced via a query param), never a hard
 * failure — login() itself already succeeded on the Node side.
 *
 * Tenant-realm integration requirement surfaced by packages/auth's
 * tenantDbContext.ts (admin_users lives per-tenant, ADR-001; LoginInput for
 * realm='tenant' carries no tenantId): resolve tenantId from the
 * `x-tenant-id` header proxy.ts already set on this request, fetch that
 * tenant's DB via @reya/db's getTenantDb(), and wrap the login() call in
 * runWithTenantDb() so login() can read the ambient context it requires. A
 * request that reached this far never has a 'not_found'/'suspended' tenant —
 * proxy.ts already short-circuited those with its own 404/503 response —
 * but a missing/malformed x-tenant-id (e.g. a tenant-realm submission from a
 * root/reserved-subdomain page) is handled as a clean redirect-with-error,
 * not a thrown 500.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const requestUrl = new URL(request.url);
  const formData = await request.formData();

  const realm: Realm = formData.get('realm') === 'platform' ? 'platform' : 'tenant';
  const password = String(formData.get('password') ?? '');

  if (realm === 'tenant') {
    const tenantIdHeader = request.headers.get('x-tenant-id');
    const tenantId = tenantIdHeader !== null ? Number(tenantIdHeader) : NaN;

    if (!Number.isFinite(tenantId)) {
      return redirectWithError('not_found', realm, requestUrl);
    }

    const input: LoginInput = { realm: 'tenant', username: String(formData.get('username') ?? ''), password };
    const db = await getTenantDb(tenantId);
    const result = await runWithTenantDb({ tenantId, db }, () => login(input));
    return buildLoginResponse(result, realm, requestUrl);
  }

  const input: LoginInput = { realm: 'platform', email: String(formData.get('email') ?? ''), password };
  const result = await login(input);
  return buildLoginResponse(result, realm, requestUrl);
}

function redirectWithError(errorCode: string, realm: Realm, requestUrl: URL): NextResponse {
  const loginUrl = new URL('/auth/login', requestUrl.origin);
  loginUrl.searchParams.set('error', errorCode);
  loginUrl.searchParams.set('realm', realm);
  return NextResponse.redirect(loginUrl, { status: 303 });
}

async function buildLoginResponse(result: AuthResult<LoginValue>, realm: Realm, requestUrl: URL): Promise<NextResponse> {
  if (!result.ok) {
    return redirectWithError(result.error.code, realm, requestUrl);
  }

  const destination = new URL(result.value.session.realm === 'platform' ? '/platform' : '/dashboard', requestUrl.origin);
  if (!result.value.bridgeSynced) {
    // Non-blocking: the (tenant)/(platform) layout can render this as a soft notice, never a hard failure.
    // IMPORTANT: bridgeSynced:false means the internal/session-bridge.php POST failed, so PHP's
    // $_SESSION for this sid is still empty even though — per the PHPSESSID cookie set below,
    // unconditionally — the browser now holds a PHPSESSID cookie *naming* that (empty) session. A
    // subsequent legacy PHP page load will still bounce to auth/login.php until a later successful
    // bridge sync (e.g. switchBot()/switchTenant()) populates that session server-side. There is no
    // retry mechanism in this batch; this is a documented, accepted gap, not something fixed here.
    destination.searchParams.set('bridgeWarning', '1');
  }

  const cookieStore = await cookies();
  cookieStore.set(result.value.cookie.name, result.value.cookie.value, result.value.cookie);
  // Also set PHPSESSID = the same sid, UNCONDITIONALLY of bridgeSynced (see comment above), so a
  // browser that just logged in via Next can immediately load a legacy PHP page under the bridged
  // $_SESSION — internal/session-bridge.php reuses the Node opaque sid as the PHP session id via
  // session_id($sid) (see its own doc comment, and packages/auth/src/types.ts's
  // BridgeSyncPayload.sid doc comment). Attributes are read off the SAME returned descriptor (not
  // re-hardcoded) so they can never drift from the Node cookie's env-dependent `secure` flag.
  cookieStore.set('PHPSESSID', result.value.cookie.value, {
    httpOnly: true,
    sameSite: 'lax',
    secure: result.value.cookie.secure,
    path: '/',
    maxAge: result.value.cookie.maxAge,
  });

  return NextResponse.redirect(destination, { status: 303 });
}
