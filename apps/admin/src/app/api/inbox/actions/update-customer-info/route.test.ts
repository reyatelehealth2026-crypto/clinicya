/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { ALLOWED_FIELDS, toNullable } from './_lib/updateCustomerInfo';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 42,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 0, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/update-customer-info', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, field: 'phone', value: '0812345678' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID or field" for a missing user_id, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ field: 'phone', value: 'x' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID or field' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID or field" for a non-whitelisted field (e.g. is_blocked), no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 1, field: 'is_blocked', value: '1' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID or field' });
    expect(queries).toHaveLength(0);
  });

  it('the ALLOWED_FIELDS whitelist is exactly the 12-field PHP array, verbatim order', () => {
    expect(ALLOWED_FIELDS).toEqual([
      'display_name',
      'phone',
      'address',
      'email',
      'real_name',
      'birthday',
      'province',
      'postal_code',
      'district',
      'gender',
      'note',
      'member_id',
    ]);
  });

  it('display_name special-cases to custom_display_name (prevents webhook LINE-API overwrite)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 10, field: 'display_name', value: 'คุณสมชาย' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Customer info updated successfully' });

    expect(queries).toHaveLength(1);
    const updateQuery = queries[0]!;
    expect(updateQuery.sql.toLowerCase()).toContain('update');
    expect(updateQuery.sql).toContain('custom_display_name');
    expect(updateQuery.sql).not.toMatch(/set\s+`?display_name`?\s*=/i);
    expect(updateQuery.params).toEqual(['คุณสมชาย', 10]);
  });

  it('a plain whitelisted field (e.g. phone) writes to its own identically-named column', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 10, field: 'phone', value: '0812345678' }));
    expect(res.status).toBe(200);

    expect(queries).toHaveLength(1);
    const updateQuery = queries[0]!;
    expect(updateQuery.sql).toContain('phone');
    expect(updateQuery.params).toEqual(['0812345678', 10]);
  });

  it("PHP's `$value ?: null` — a bound value of the literal string '0' becomes NULL, not the string '0'", async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 10, field: 'member_id', value: '0' }));
    expect(res.status).toBe(200);

    const updateQuery = queries[0]!;
    expect(updateQuery.params).toEqual([null, 10]);
  });

  it('an empty string (after trim) also becomes NULL', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 10, field: 'note', value: '   ' }));
    expect(res.status).toBe(200);

    const updateQuery = queries[0]!;
    expect(updateQuery.params).toEqual([null, 10]);
  });

  it("toNullable() — the exact PHP `?:` truthiness rule, not a generic empty/whitespace check: only '' and '0' become null", () => {
    expect(toNullable('')).toBeNull();
    expect(toNullable('0')).toBeNull();
    expect(toNullable(' ')).toBe(' '); // a single space is truthy in PHP
    expect(toNullable('00')).toBe('00'); // '00' is truthy in PHP (only the exact string '0' is falsy)
    expect(toNullable('phone value')).toBe('phone value');
  });

  it('DB failure -> 400 {success:false, error: "Failed to update customer info: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, field: 'phone', value: 'x' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update customer info: boom' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
