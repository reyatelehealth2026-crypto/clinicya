/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

const mockDispenseAction = jest.fn();
jest.mock('./_lib/dispense', () => ({
  dispenseAction: (...args: unknown[]) => mockDispenseAction(...args),
}));

import { POST } from './route';

function req(body: unknown, url = 'https://tenant-0002.re-ya.com/api/inbox/actions/dispense'): NextRequest {
  return { json: async () => body, url } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 7,
    tenantId: 1,
    currentBotId: 3,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: '2026-07-01T00:00:00.000Z',
    lastSeenAt: '2026-07-14T00:00:00.000Z',
    expiresAt: '2026-07-15T00:00:00.000Z',
    ...overrides,
  };
}

function wireFakeDb(sessionOverrides: Partial<TenantSession> = {}) {
  const { db } = makeFakeTenantDb(() => []);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return db;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/dispense', () => {
  it('401 JSON when unauthenticated, dispenseAction never called', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, items: '[]' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockDispenseAction).not.toHaveBeenCalled();
  });

  it.each([[{}], [{ user_id: 0 }], [{ user_id: null }], [{ user_id: 'abc' }]])(
    '400 "User ID is required" for body=%j, dispenseAction never called',
    async (body) => {
      wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(mockDispenseAction).not.toHaveBeenCalled();
    }
  );

  it('an unparseable JSON body falls back to {} (still 400 "User ID is required")', async () => {
    wireFakeDb();
    const badReq = { json: async () => { throw new Error('bad json'); }, url: 'https://tenant.re-ya.com/api/inbox/actions/dispense' } as unknown as NextRequest;
    const res = await POST(badReq);
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
  });

  it('delegates to dispenseAction with the parsed body, session, userId, and origin derived from request.url', async () => {
    const db = wireFakeDb();
    mockDispenseAction.mockResolvedValue({ status: 200, body: { success: true, order_number: 'DIS123', dispense_id: 9 } });

    const body = { user_id: 42, items: '[{"product_id":1,"qty":1}]', payment_method: 'cash' };
    const res = await POST(req(body, 'https://tenant-0002.re-ya.com/api/inbox/actions/dispense'));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, order_number: 'DIS123', dispense_id: 9 });

    expect(mockDispenseAction).toHaveBeenCalledTimes(1);
    const [calledDb, calledSession, calledUserId, calledBody, calledOrigin] = mockDispenseAction.mock.calls[0];
    expect(calledDb).toBe(db);
    expect(calledSession.username).toBe('pharmacist1');
    expect(calledUserId).toBe(42);
    expect(calledBody).toEqual(body);
    expect(calledOrigin).toBe('https://tenant-0002.re-ya.com');
  });

  it('an error thrown by dispenseAction becomes a flat 400 with the thrown message', async () => {
    wireFakeDb();
    mockDispenseAction.mockRejectedValue(new Error('No items to dispense'));

    const res = await POST(req({ user_id: 42, items: '[]' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No items to dispense' });
  });

  it('a non-Error throw is stringified into the error field', async () => {
    wireFakeDb();
    mockDispenseAction.mockRejectedValue('stringly failure');

    const res = await POST(req({ user_id: 42, items: '[]' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'stringly failure' });
  });
});
