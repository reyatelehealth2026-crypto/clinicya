/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

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

// Frozen reference instant so "created_at"/"expires_at" wall-clock math is
// deterministic. `toMysqlDateTimeString()` reads local wall-clock getters
// (getHours/getMinutes/...) — CI/this test runner's process TZ is UTC, so
// the expected strings below are this instant's UTC clock face.
const FIXED_NOW = new Date('2026-08-14T12:00:00.000Z').getTime();

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ now: FIXED_NOW });
});

afterEach(() => {
  jest.useRealTimers();
});

describe('POST /api/inbox/actions/save-pending-order', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, items: [{ id: 1 }] }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" when user_id is missing/falsy, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ items: [{ id: 1 }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Items are required" when items is empty/missing, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ user_id: 5, items: [] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Items are required' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: single INSERT ... ON DUPLICATE KEY UPDATE into user_states, JSON-stringified state_data, expires_at = now + 30 minutes', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));

    const res = await POST(
      req({
        user_id: 10,
        items: [{ sku: 'A', qty: 2 }],
        subtotal: 100,
        discount: 10,
        total: 90,
      })
    );
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      message: 'Pending order saved',
      expires_at: '2026-08-14 12:30:00',
    });

    expect(queries).toHaveLength(1);
    const [insertQuery] = queries;
    expect(insertQuery.sql).toContain('insert into `user_states`');
    expect(insertQuery.sql.toLowerCase()).toContain('on duplicate key update');
    expect(queries.some((q) => /delete/i.test(q.sql))).toBe(false); // no DELETE anywhere — SHOW-KEYS branch dropped

    const expectedStateData = JSON.stringify({
      items: [{ sku: 'A', qty: 2 }],
      subtotal: 100,
      discount: 10,
      total: 90,
      created_at: '2026-08-14 12:00:00',
      line_account_id: 7, // session.currentBotId
    });
    expect(insertQuery.params).toContain(10); // user_id
    expect(insertQuery.params).toContain('pending_order');
    expect(insertQuery.params).toContain(expectedStateData);
    expect(insertQuery.params).toContain('2026-08-14 12:30:00'); // expires_at
    // state='pending_order', state_data, expires_at appear twice each (INSERT columns + ON DUPLICATE KEY UPDATE).
    expect(insertQuery.params.filter((p: unknown) => p === 'pending_order')).toHaveLength(2);
    expect(insertQuery.params.filter((p: unknown) => p === expectedStateData)).toHaveLength(2);
    expect(insertQuery.params.filter((p: unknown) => p === '2026-08-14 12:30:00')).toHaveLength(2);
  });

  it('DB failure -> 400 {success:false, error: "Failed to save pending order: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 10, items: [{ sku: 'A' }] }));

    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body).toEqual({ success: false, error: 'Failed to save pending order: boom' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
