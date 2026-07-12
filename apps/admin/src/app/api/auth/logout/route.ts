import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
import { logout, TENANT_SESSION_COOKIE, PLATFORM_SESSION_COOKIE, type Realm } from '@reya/auth';

/**
 * POST /api/auth/logout — mirror of classes/AdminAuth.php::logout() /
 * admin/platform-login.php's logout handling, but realm-agnostic: a browser
 * can hold BOTH a tenant (`reya_sid`) and a platform (`reya_platform_sid`)
 * session cookie at once (e.g. logged into a tenant earlier, then later
 * logged into the platform super-admin panel in the same browser), so this
 * calls @reya/auth's `logout()` once per realm whose cookie is actually
 * present. `logout()` is documented idempotent (safe even against an
 * already-invalid sid — see its own doc comment in packages/auth/src/session.ts),
 * so no presence check beyond "cookie has a value" is required before calling it.
 *
 * Regardless of which realm(s) were present, all three cookies this shell
 * ever sets during login (`reya_sid`, `reya_platform_sid`, `PHPSESSID` — see
 * the login Route Handler) are unconditionally cleared here; clearing an
 * absent cookie is a no-op. This also clears the PHPSESSID cookie the login
 * Route Handler sets equal to the Node sid — a legacy PHP page load after
 * logout must not still present a (now-destroyed) session id.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const requestUrl = new URL(request.url);
  const cookieStore = await cookies();

  const realmCookies: Array<{ realm: Realm; name: string }> = [
    { realm: 'tenant', name: TENANT_SESSION_COOKIE },
    { realm: 'platform', name: PLATFORM_SESSION_COOKIE },
  ];

  for (const { realm, name } of realmCookies) {
    const sid = cookieStore.get(name)?.value;
    if (sid) {
      await logout(sid, realm);
    }
  }

  cookieStore.delete(TENANT_SESSION_COOKIE);
  cookieStore.delete(PLATFORM_SESSION_COOKIE);
  cookieStore.delete('PHPSESSID');

  const loginUrl = new URL('/auth/login', requestUrl.origin);
  return NextResponse.redirect(loginUrl, { status: 303 });
}
