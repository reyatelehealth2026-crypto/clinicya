import { describe, expect, it } from 'vitest';
import type { TenantDB } from '@reya/db';
import { canAccessBot, requireRole } from '../src/rbac';
import type { PlatformSession, TenantSession } from '../src/types';
import { makeTestDb } from './helpers/makeTestDb';

function tenantSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid-tenant-1',
    adminUserId: 1,
    tenantId: 42,
    currentBotId: null,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: '2026-07-12T00:00:00.000Z',
    lastSeenAt: '2026-07-12T00:00:00.000Z',
    expiresAt: '2026-07-13T00:00:00.000Z',
    ...overrides,
  };
}

function platformSession(overrides: Partial<PlatformSession> = {}): PlatformSession {
  return {
    realm: 'platform',
    sid: 'sid-platform-1',
    platformUserId: 9,
    platformRole: 'support',
    email: 'owner@reya-platform.example',
    name: 'Platform Owner',
    impersonatedTenantId: null,
    createdAt: '2026-07-12T00:00:00.000Z',
    lastSeenAt: '2026-07-12T00:00:00.000Z',
    expiresAt: '2026-07-13T00:00:00.000Z',
    ...overrides,
  };
}

describe('requireRole', () => {
  it('returns session_expired for a null session', () => {
    const result = requireRole<TenantSession>(null, ['admin']);
    expect(result).toEqual({ ok: false, error: { code: 'session_expired' } });
  });

  it('allows a TenantSession whose role is in the allow-list', () => {
    const session = tenantSession({ role: 'pharmacist' });
    const result = requireRole(session, ['admin', 'pharmacist']);
    expect(result).toEqual({ ok: true, value: session });
  });

  it('forbids a TenantSession whose role is NOT in the allow-list', () => {
    const session = tenantSession({ role: 'staff' });
    const result = requireRole(session, ['admin', 'super_admin']);
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.error.code).toBe('forbidden');
    }
  });

  it('allows a PlatformSession whose platformRole is in the allow-list (reads .platformRole, not .role)', () => {
    const session = platformSession({ platformRole: 'readonly' });
    const result = requireRole(session, ['readonly', 'support']);
    expect(result).toEqual({ ok: true, value: session });
  });

  it('forbids a PlatformSession whose platformRole is NOT in the allow-list', () => {
    const session = platformSession({ platformRole: 'readonly' });
    const result = requireRole(session, ['super_admin']);
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.error.code).toBe('forbidden');
    }
  });
});

describe('canAccessBot (admin_bot_access ACL — mirrors AdminAuth::canAccessBot())', () => {
  it('super_admin short-circuits true without ever querying admin_bot_access', async () => {
    let queried = false;
    const { db } = makeTestDb<TenantDB>('tenant_db', () => {
      queried = true;
      return [];
    });

    const allowed = await canAccessBot(db, 1, 'super_admin', 99, 'can_view');
    expect(allowed).toBe(true);
    expect(queried).toBe(false);
  });

  it('denies when no admin_bot_access row exists for (admin_id, line_account_id)', async () => {
    const { db } = makeTestDb<TenantDB>('tenant_db', () => []);
    const allowed = await canAccessBot(db, 1, 'admin', 99, 'can_view');
    expect(allowed).toBe(false);
  });

  it('denies when the row exists but can_view=0', async () => {
    const { db } = makeTestDb<TenantDB>('tenant_db', () => [
      { can_view: 0, can_edit: 1, can_broadcast: 1, can_manage_users: 1, can_manage_shop: 1, can_view_analytics: 1 },
    ]);
    const allowed = await canAccessBot(db, 1, 'admin', 99, 'can_view');
    expect(allowed).toBe(false);
  });

  it('allows when the row exists and can_view=1', async () => {
    const { db, pool } = makeTestDb<TenantDB>('tenant_db', () => [
      { can_view: 1, can_edit: 0, can_broadcast: 0, can_manage_users: 0, can_manage_shop: 0, can_view_analytics: 0 },
    ]);
    const allowed = await canAccessBot(db, 1, 'admin', 99, 'can_view');
    expect(allowed).toBe(true);
    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/FROM admin_bot_access/),
      [1, 99],
      expect.any(Function)
    );
  });

  it('checks an arbitrary permission column (can_manage_shop) when the row grants it', async () => {
    const { db } = makeTestDb<TenantDB>('tenant_db', () => [
      { can_view: 1, can_edit: 0, can_broadcast: 0, can_manage_users: 0, can_manage_shop: 1, can_view_analytics: 0 },
    ]);
    const allowed = await canAccessBot(db, 1, 'staff', 99, 'can_manage_shop');
    expect(allowed).toBe(true);
  });

  it('defaults the permission parameter to can_view', async () => {
    const { db } = makeTestDb<TenantDB>('tenant_db', () => [
      { can_view: 1, can_edit: 0, can_broadcast: 0, can_manage_users: 0, can_manage_shop: 0, can_view_analytics: 0 },
    ]);
    const allowed = await canAccessBot(db, 1, 'staff', 99);
    expect(allowed).toBe(true);
  });
});
