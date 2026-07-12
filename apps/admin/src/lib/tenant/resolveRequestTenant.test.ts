import { describe, expect, it } from '@jest/globals';
import type { TenantRepository, TenantRow } from '@reya/tenant';
import { resolveRequestTenant } from './resolveRequestTenant';

const BASE_DOMAIN = 're-ya.com';

function repoWith(rows: Record<string, TenantRow>): TenantRepository {
  return {
    async findBySlug(slug: string) {
      return rows[slug] ?? null;
    },
  };
}

describe('resolveRequestTenant', () => {
  it("kind: 'tenant' — active tenant subdomain resolves and is not root", async () => {
    const repo = repoWith({
      'tenant-0002': { id: 2, status: 'active', displayName: 'Test Pharmacy' },
    });

    const result = await resolveRequestTenant('tenant-0002.re-ya.com', {}, { repo, config: { baseDomain: BASE_DOMAIN } });

    expect(result).toEqual({ kind: 'tenant', tenantId: 2, slug: 'tenant-0002', isRoot: false, demoMode: false });
  });

  it("kind: 'not_found' — subdomain looks like a tenant slug but no row exists", async () => {
    const repo = repoWith({});

    const result = await resolveRequestTenant('tenant-9999.re-ya.com', {}, { repo, config: { baseDomain: BASE_DOMAIN } });

    expect(result).toEqual({ kind: 'not_found', slug: 'tenant-9999' });
  });

  it("kind: 'suspended' — suspended tenant on a non-root subdomain", async () => {
    const repo = repoWith({
      'tenant-0003': { id: 3, status: 'suspended', displayName: 'Suspended Pharmacy' },
    });

    const result = await resolveRequestTenant('tenant-0003.re-ya.com', {}, { repo, config: { baseDomain: BASE_DOMAIN } });

    expect(result).toEqual({
      kind: 'suspended',
      tenantId: 3,
      slug: 'tenant-0003',
      displayName: 'Suspended Pharmacy',
      status: 'suspended',
    });
  });

  it("kind: 'none' — reserved subdomain never treated as a tenant slug", async () => {
    const repo = repoWith({});

    // 'www.re-ya.com' is deliberately NOT used here — it's special-cased as a root-host alias
    // (falls through to the default root tenant slug), not a plain reserved-subdomain rejection.
    const result = await resolveRequestTenant('shop.re-ya.com', {}, { repo, config: { baseDomain: BASE_DOMAIN } });

    expect(result).toEqual({ kind: 'none', reason: 'reserved_or_no_match' });
  });

  it("kind: 'none' — root domain with an explicit LINE-account signal falls through to routeByLineAccount", async () => {
    const repo = repoWith({
      'tenant-0001': { id: 1, status: 'active', displayName: 'Root Tenant' },
    });

    const result = await resolveRequestTenant(
      're-ya.com',
      { account: '42' },
      { repo, config: { baseDomain: BASE_DOMAIN } }
    );

    expect(result).toEqual({ kind: 'none', reason: 'root_with_explicit_signal' });
  });

  it('constructing the default repo (createMasterTenantRepository()) does no I/O — safe to build without a live DB', () => {
    // Deliberately does NOT call resolveRequestTenant() without an injected repo: doing so would reach
    // findBySlug() -> getMasterDb() -> mysql2 createPool(), which can attempt a real socket. This only
    // checks that constructing the repository object itself (resolveRequestTenant's default-parameter
    // path) is synchronous and side-effect-free.
    const { createMasterTenantRepository } = jest.requireActual<typeof import('@reya/tenant')>('@reya/tenant');
    expect(() => createMasterTenantRepository()).not.toThrow();
  });
});
