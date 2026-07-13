import { describe, expect, it } from 'vitest';
import {
  extractSubdomain,
  hasExplicitAccountSignal,
  isRootHost,
  normalizeHost,
  normalizeRootTenantSlug,
  resolveTenant,
  type TenantRepository,
  type TenantRow,
} from '../src/resolveTenant';

const BASE_DOMAIN = 're-ya.com';

function repoWith(rows: Record<string, TenantRow>): TenantRepository {
  return {
    async findBySlug(slug: string) {
      return rows[slug] ?? null;
    },
  };
}

function throwingRepo(): TenantRepository {
  return {
    async findBySlug() {
      throw new Error('connection refused');
    },
  };
}

describe('normalizeHost', () => {
  it('lowercases, trims, and strips a trailing port', () => {
    expect(normalizeHost(' Tenant-0001.RE-YA.com:8443 ')).toBe('tenant-0001.re-ya.com');
  });

  it('returns "" for null/undefined/empty', () => {
    expect(normalizeHost(null)).toBe('');
    expect(normalizeHost(undefined)).toBe('');
    expect(normalizeHost('  ')).toBe('');
  });
});

describe('extractSubdomain', () => {
  it('extracts a valid subdomain label in front of the base domain', () => {
    expect(extractSubdomain('tenant-0001.re-ya.com', BASE_DOMAIN)).toBe('tenant-0001');
  });

  it('returns null for a reserved subdomain', () => {
    expect(extractSubdomain('www.re-ya.com', BASE_DOMAIN)).toBeNull();
    expect(extractSubdomain('api.re-ya.com', BASE_DOMAIN)).toBeNull();
    expect(extractSubdomain('shop.re-ya.com', BASE_DOMAIN)).toBeNull();
  });

  it('returns null for the bare root domain (no subdomain segment)', () => {
    expect(extractSubdomain('re-ya.com', BASE_DOMAIN)).toBeNull();
  });

  it('returns null for a completely unrelated host', () => {
    expect(extractSubdomain('evil.com', BASE_DOMAIN)).toBeNull();
    expect(extractSubdomain('tenant-0001.re-ya.com.evil.com', BASE_DOMAIN)).toBeNull();
  });

  it('is case-insensitive and lowercases the result', () => {
    expect(extractSubdomain('Tenant-0001.RE-YA.COM'.toLowerCase(), BASE_DOMAIN)).toBe('tenant-0001');
  });
});

describe('isRootHost', () => {
  it('is true for the bare base domain and its www alias', () => {
    expect(isRootHost('re-ya.com', BASE_DOMAIN)).toBe(true);
    expect(isRootHost('www.re-ya.com', BASE_DOMAIN)).toBe(true);
  });

  it('is false for any tenant or reserved subdomain', () => {
    expect(isRootHost('tenant-0001.re-ya.com', BASE_DOMAIN)).toBe(false);
    expect(isRootHost('api.re-ya.com', BASE_DOMAIN)).toBe(false);
  });
});

describe('hasExplicitAccountSignal', () => {
  it('is true when account/la/line_account_id is present + non-empty in query', () => {
    expect(hasExplicitAccountSignal({ account: '5' }, undefined)).toBe(true);
    expect(hasExplicitAccountSignal({ la: '5' }, undefined)).toBe(true);
    expect(hasExplicitAccountSignal({ line_account_id: '5' }, undefined)).toBe(true);
  });

  it('is true when present + non-empty in body only', () => {
    expect(hasExplicitAccountSignal(undefined, { account: '5' })).toBe(true);
  });

  it('is false when the keys are absent entirely', () => {
    expect(hasExplicitAccountSignal({}, {})).toBe(false);
    expect(hasExplicitAccountSignal(undefined, undefined)).toBe(false);
  });

  it('is false when present but empty-string', () => {
    expect(hasExplicitAccountSignal({ account: '' }, undefined)).toBe(false);
  });
});

describe('normalizeRootTenantSlug', () => {
  it('defaults to tenant-0001 when unset or empty', () => {
    expect(normalizeRootTenantSlug(undefined)).toBe('tenant-0001');
    expect(normalizeRootTenantSlug('')).toBe('tenant-0001');
  });

  it('trims + lowercases a provided value', () => {
    expect(normalizeRootTenantSlug('  Tenant-0009  ')).toBe('tenant-0009');
  });

  it('returns null when the value is whitespace-only (disables the root mapping)', () => {
    expect(normalizeRootTenantSlug('   ')).toBeNull();
  });
});

describe('resolveTenant', () => {
  const activeRow: TenantRow = { id: 1, status: 'active', displayName: 'Demo Pharmacy' };
  const pendingRow: TenantRow = { id: 2, status: 'pending_setup', displayName: 'New Pharmacy' };
  const suspendedRow: TenantRow = { id: 3, status: 'suspended', displayName: 'Suspended Pharmacy' };
  const terminatedRow: TenantRow = { id: 4, status: 'terminated', displayName: 'Terminated Pharmacy' };

  it('reserved subdomain -> no tenant', async () => {
    const repo = repoWith({});
    const result = await resolveTenant({ host: 'api.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'reserved_or_no_match' });
  });

  it('unknown slug -> 404-equivalent', async () => {
    const repo = repoWith({});
    const result = await resolveTenant({ host: 'ghost-tenant.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'not_found', slug: 'ghost-tenant' });
  });

  it('suspended tenant (non-root) -> 503-equivalent', async () => {
    const repo = repoWith({ 'suspended-shop': suspendedRow });
    const result = await resolveTenant({ host: 'suspended-shop.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({
      kind: 'suspended',
      tenantId: 3,
      slug: 'suspended-shop',
      displayName: 'Suspended Pharmacy',
      status: 'suspended',
    });
  });

  it('terminated tenant (non-root) -> 503-equivalent', async () => {
    const repo = repoWith({ 'gone-shop': terminatedRow });
    const result = await resolveTenant({ host: 'gone-shop.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toMatchObject({ kind: 'suspended', status: 'terminated' });
  });

  it('pending_setup (non-root) -> tenant resolves with demoMode true', async () => {
    const repo = repoWith({ 'new-shop': pendingRow });
    const result = await resolveTenant({ host: 'new-shop.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'tenant', tenantId: 2, slug: 'new-shop', isRoot: false, demoMode: true });
  });

  it('active tenant (non-root) -> tenant resolves with demoMode false', async () => {
    const repo = repoWith({ 'demo-shop': activeRow });
    const result = await resolveTenant({ host: 'demo-shop.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'tenant', tenantId: 1, slug: 'demo-shop', isRoot: false, demoMode: false });
  });

  it('root domain, no subdomain, no explicit signal -> resolves to REYA_ROOT_TENANT_SLUG default (tenant-0001)', async () => {
    const repo = repoWith({ 'tenant-0001': activeRow });
    const result = await resolveTenant({ host: 're-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'tenant', tenantId: 1, slug: 'tenant-0001', isRoot: true, demoMode: false });
  });

  it('www.<base> is treated the same as the bare root domain', async () => {
    const repo = repoWith({ 'tenant-0001': activeRow });
    const result = await resolveTenant({ host: 'www.re-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toMatchObject({ kind: 'tenant', isRoot: true });
  });

  it('root domain is exempt from suspension -> always falls through instead of 503', async () => {
    const repo = repoWith({ 'tenant-0001': suspendedRow });
    const result = await resolveTenant({ host: 're-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'root_status_glitch' });
  });

  it('root domain is exempt from termination too', async () => {
    const repo = repoWith({ 'tenant-0001': terminatedRow });
    const result = await resolveTenant({ host: 're-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'root_status_glitch' });
  });

  it('root domain is never demo-flagged, even if its row is pending_setup', async () => {
    const repo = repoWith({ 'tenant-0001': pendingRow });
    const result = await resolveTenant({ host: 're-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'tenant', tenantId: 2, slug: 'tenant-0001', isRoot: true, demoMode: false });
  });

  it('root domain WITH explicit account signal (query) -> does NOT pin the root tenant, falls through', async () => {
    const repo = repoWith({ 'tenant-0001': activeRow });
    const result = await resolveTenant(
      { host: 're-ya.com', query: { account: '42' } },
      repo,
      { baseDomain: BASE_DOMAIN }
    );
    expect(result).toEqual({ kind: 'none', reason: 'root_with_explicit_signal' });
  });

  it('root domain WITH explicit la signal (query) -> falls through', async () => {
    const repo = repoWith({ 'tenant-0001': activeRow });
    const result = await resolveTenant({ host: 're-ya.com', query: { la: '42' } }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'root_with_explicit_signal' });
  });

  it('root domain WITH explicit line_account_id signal (body) -> falls through', async () => {
    const repo = repoWith({ 'tenant-0001': activeRow });
    const result = await resolveTenant(
      { host: 're-ya.com', body: { line_account_id: '42' } },
      repo,
      { baseDomain: BASE_DOMAIN }
    );
    expect(result).toEqual({ kind: 'none', reason: 'root_with_explicit_signal' });
  });

  it('root domain with no configured default tenant row -> falls through, never 404s', async () => {
    const repo = repoWith({}); // 'tenant-0001' not provisioned
    const result = await resolveTenant({ host: 're-ya.com' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'root_tenant_unconfigured' });
  });

  it('a custom REYA_ROOT_TENANT_SLUG is honoured', async () => {
    const repo = repoWith({ 'tenant-0009': activeRow });
    const result = await resolveTenant({ host: 're-ya.com' }, repo, {
      baseDomain: BASE_DOMAIN,
      rootTenantSlug: 'tenant-0009',
    });
    expect(result).toMatchObject({ kind: 'tenant', slug: 'tenant-0009', isRoot: true });
  });

  it('repository failure -> fail-safe fall-through, never throws', async () => {
    const result = await resolveTenant({ host: 'demo-shop.re-ya.com' }, throwingRepo(), { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'lookup_error' });
  });

  it('port in the Host header does not change resolution', async () => {
    const repo = repoWith({ 'demo-shop': activeRow });
    const result = await resolveTenant({ host: 'demo-shop.re-ya.com:8443' }, repo, { baseDomain: BASE_DOMAIN });
    expect(result).toMatchObject({ kind: 'tenant', tenantId: 1 });
  });

  it('empty host -> none', async () => {
    const result = await resolveTenant({ host: '' }, repoWith({}), { baseDomain: BASE_DOMAIN });
    expect(result).toEqual({ kind: 'none', reason: 'empty_host' });
  });
});
