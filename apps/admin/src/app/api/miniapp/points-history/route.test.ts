/**
 * @jest-environment node
 */
jest.mock('@/lib/miniapp/tenant', () => ({
  resolveMiniappTenantContext: jest.fn(),
  TENANT_UNRESOLVED_RESPONSE: { success: false, error: 'tenant_unresolved' },
  TENANT_UNRESOLVED_STATUS: 400,
}));
jest.mock('./_lib/query', () => ({
  getPointsHistoryAction: jest.fn(),
}));

import type { NextRequest } from 'next/server';
import { resolveMiniappTenantContext } from '@/lib/miniapp/tenant';
import { getPointsHistoryAction } from './_lib/query';
import { GET, OPTIONS } from './route';

const mockResolveTenant = resolveMiniappTenantContext as jest.MockedFunction<typeof resolveMiniappTenantContext>;
const mockGetHistory = getPointsHistoryAction as jest.MockedFunction<typeof getPointsHistoryAction>;

function req(search: string): NextRequest {
  const url = `https://re-ya.com/api/miniapp/points-history${search}`;
  return { nextUrl: new URL(url), headers: new Headers(), url } as unknown as NextRequest;
}

const FAKE_DB = { __fakeTenantDb: true };

describe('GET /api/miniapp/points-history', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('tenant_unresolved -> 400, query.action logic never reaches the DB call', async () => {
    mockResolveTenant.mockResolvedValue({ ok: false });

    const res = await GET(req('?line_user_id=U1'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockGetHistory).not.toHaveBeenCalled();
  });

  it('resolves tenant, forwards parsed params, wraps result at 200', async () => {
    mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
    mockGetHistory.mockResolvedValue({
      success: true,
      user: { name: 'A', total_points: 1, available_points: 1, used_points: 0 },
      history: [],
    });

    const res = await GET(req('?line_user_id=U1&line_account_id=2&limit=5'));

    expect(res.status).toBe(200);
    expect(mockGetHistory).toHaveBeenCalledWith(FAKE_DB, 'U1', 2, 5);
    expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });

  it('defaults limit to 20 and line_account_id to null when omitted', async () => {
    mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
    mockGetHistory.mockResolvedValue({ success: false, error: 'Missing line_user_id' });

    await GET(req(''));

    expect(mockGetHistory).toHaveBeenCalledWith(FAKE_DB, null, null, 20);
  });

  it('a failure response from the action (e.g. User not found) is still HTTP 200', async () => {
    mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
    mockGetHistory.mockResolvedValue({ success: false, error: 'User not found' });

    const res = await GET(req('?line_user_id=Uno'));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: false, error: 'User not found' });
  });

  it('an explicit unsupported action -> 400 Invalid action, tenant resolution never attempted', async () => {
    const res = await GET(req('?action=redeem&line_user_id=U1'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid action' });
    expect(mockResolveTenant).not.toHaveBeenCalled();
  });
});

describe('OPTIONS /api/miniapp/points-history', () => {
  it('204 with CORS headers', () => {
    const res = OPTIONS();
    expect(res.status).toBe(204);
    expect(res.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});
