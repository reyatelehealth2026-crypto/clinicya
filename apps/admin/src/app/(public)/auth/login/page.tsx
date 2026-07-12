import { headers } from 'next/headers';
import type { Realm } from '@reya/auth';

interface LoginPageProps {
  searchParams: Promise<{ error?: string; realm?: string }>;
}

const ERROR_COPY_TH: Record<string, string> = {
  invalid_credentials: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
  account_inactive: 'บัญชีนี้ถูกระงับการใช้งาน',
  not_found: 'ไม่พบบัญชีผู้ใช้',
  session_expired: 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง',
  bridge_unreachable: 'ไม่สามารถเชื่อมต่อระบบเดิมได้ กรุณาลองใหม่',
};

const ERROR_COPY_EN: Record<string, string> = {
  invalid_credentials: 'Incorrect username/email or password.',
  account_inactive: 'This account has been deactivated.',
  not_found: 'Account not found.',
  session_expired: 'Your session expired — please sign in again.',
  bridge_unreachable: 'Could not reach the legacy bridge. Please try again.',
};

/**
 * (public)/auth/login/page.tsx — bilingual (TH primary / EN secondary) login
 * form. Realm is derived server-side from the `x-tenant-id` header
 * proxy.ts sets (a resolved tenant subdomain -> 'tenant' realm; its absence,
 * e.g. on the reserved `admin`/`platform` subdomains -> 'platform' realm),
 * with an explicit `?realm=` query override (used by the (tenant)/(platform)
 * layouts' redirect-to-login-on-forbidden path). POSTs to
 * /api/auth/login (apps/admin/src/app/api/auth/login/route.ts).
 */
export default async function LoginPage({ searchParams }: LoginPageProps) {
  const hdrs = await headers();
  const params = await searchParams;

  const hasTenantHeader = hdrs.get('x-tenant-id') !== null;
  const realm: Realm = params.realm === 'platform' || params.realm === 'tenant' ? params.realm : hasTenantHeader ? 'tenant' : 'platform';

  const errorCode = params.error;
  const errorTh = errorCode ? ERROR_COPY_TH[errorCode] ?? 'เกิดข้อผิดพลาด กรุณาลองใหม่' : null;
  const errorEn = errorCode ? ERROR_COPY_EN[errorCode] ?? 'Something went wrong — please try again.' : null;

  return (
    <main style={{ maxWidth: 360, margin: '4rem auto', fontFamily: 'system-ui, sans-serif' }}>
      <h1 lang="th" style={{ fontSize: '1.5rem', marginBottom: '0.125rem' }}>
        เข้าสู่ระบบ
      </h1>
      <p lang="en" style={{ color: '#6b7280', marginTop: 0, marginBottom: '1.5rem' }}>
        Sign in to Reya Admin
      </p>

      {errorTh ? (
        <div role="alert" style={{ color: '#b91c1c', marginBottom: '1rem' }}>
          <p lang="th" style={{ margin: 0 }}>
            {errorTh}
          </p>
          <p lang="en" style={{ margin: 0, fontSize: '0.875rem' }}>
            {errorEn}
          </p>
        </div>
      ) : null}

      <form action="/api/auth/login" method="POST">
        <input type="hidden" name="realm" value={realm} />

        {realm === 'platform' ? (
          <label style={{ display: 'block', marginBottom: '0.75rem' }}>
            <span lang="th">อีเมล</span> <span lang="en">/ Email</span>
            <input type="email" name="email" required autoComplete="email" style={{ display: 'block', width: '100%' }} />
          </label>
        ) : (
          <label style={{ display: 'block', marginBottom: '0.75rem' }}>
            <span lang="th">ชื่อผู้ใช้</span> <span lang="en">/ Username</span>
            <input type="text" name="username" required autoComplete="username" style={{ display: 'block', width: '100%' }} />
          </label>
        )}

        <label style={{ display: 'block', marginBottom: '1rem' }}>
          <span lang="th">รหัสผ่าน</span> <span lang="en">/ Password</span>
          <input
            type="password"
            name="password"
            required
            autoComplete="current-password"
            style={{ display: 'block', width: '100%' }}
          />
        </label>

        <button type="submit" style={{ width: '100%' }}>
          <span lang="th">เข้าสู่ระบบ</span> <span lang="en">/ Sign in</span>
        </button>
      </form>
    </main>
  );
}
