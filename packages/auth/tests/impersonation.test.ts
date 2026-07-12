import { describe, expect, it } from 'vitest';
import { writeSuperAdminAudit } from '../src/impersonation';
import { makeTestDb } from './helpers/makeTestDb';

describe('writeSuperAdminAudit', () => {
  it('INSERTs the exact super_admin_audit column list with metadata JSON-encoded', async () => {
    const { db, pool } = makeTestDb('master', () => ({ insertId: 1, affectedRows: 1 }));

    await writeSuperAdminAudit(db, {
      platformUserId: 9,
      tenantId: 42,
      action: 'switch_tenant_in',
      metadata: { tenant_slug: 'demo-shop', tenant_display_name: 'Demo Pharmacy', tenant_status: 'active', reason: null },
    });

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(
        /INSERT INTO super_admin_audit\s+\(platform_user_id, tenant_id, action, ip_address, user_agent, request_method, request_uri, metadata, created_at\)/
      ),
      [
        9,
        42,
        'switch_tenant_in',
        null,
        null,
        null,
        null,
        JSON.stringify({ tenant_slug: 'demo-shop', tenant_display_name: 'Demo Pharmacy', tenant_status: 'active', reason: null }),
      ],
      expect.any(Function)
    );
  });

  it('stores NULL metadata (not "{}") when metadata is omitted — mirrors PHP\'s `$metadata ? json_encode(...) : null`', async () => {
    const { db, pool } = makeTestDb('master', () => ({ insertId: 2, affectedRows: 1 }));

    await writeSuperAdminAudit(db, {
      platformUserId: 9,
      tenantId: 42,
      action: 'switch_tenant_out',
    });

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.any(String),
      [9, 42, 'switch_tenant_out', null, null, null, null, null],
      expect.any(Function)
    );
  });

  it('accepts a null tenantId (platform-wide action)', async () => {
    const { db, pool } = makeTestDb('master', () => ({ insertId: 3, affectedRows: 1 }));

    await writeSuperAdminAudit(db, { platformUserId: 9, tenantId: null, action: 'platform_login' });

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.any(String),
      [9, null, 'platform_login', null, null, null, null, null],
      expect.any(Function)
    );
  });

  it('propagates a DB failure (never silently swallowed like the PHP error_log fallback)', async () => {
    const { db } = makeTestDb('master', () => {
      throw new Error('connection lost');
    });

    await expect(writeSuperAdminAudit(db, { platformUserId: 1, tenantId: null, action: 'x' })).rejects.toThrow(
      'connection lost'
    );
  });
});
