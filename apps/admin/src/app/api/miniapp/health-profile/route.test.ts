/**
 * @jest-environment node
 */
jest.mock('@/lib/miniapp/tenant', () => ({
  resolveMiniappTenantContext: jest.fn(),
  TENANT_UNRESOLVED_RESPONSE: { success: false, error: 'tenant_unresolved' },
  TENANT_UNRESOLVED_STATUS: 400,
}));
jest.mock('./_lib/query', () => ({
  getHealthProfileAction: jest.fn(),
}));

import type { NextRequest } from 'next/server';
import { resolveMiniappTenantContext } from '@/lib/miniapp/tenant';
import { getHealthProfileAction } from './_lib/query';
import { GET, OPTIONS } from './route';

const mockResolveTenant = resolveMiniappTenantContext as jest.MockedFunction<typeof resolveMiniappTenantContext>;
const mockGetProfile = getHealthProfileAction as jest.MockedFunction<typeof getHealthProfileAction>;

const FAKE_DB = { __fakeTenantDb: true };

function req(search: string): NextRequest {
  const url = `https://re-ya.com/api/miniapp/health-profile${search}`;
  return { nextUrl: new URL(url), headers: new Headers(), url } as unknown as NextRequest;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
});

describe('GET /api/miniapp/health-profile', () => {
  it('action != get -> 400 Invalid action, tenant resolution never attempted', async () => {
    const res = await GET(req('?action=get_allergies&line_user_id=U1'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid action' });
    expect(mockResolveTenant).not.toHaveBeenCalled();
  });

  it('missing action param -> 400 Invalid action', async () => {
    const res = await GET(req('?line_user_id=U1'));
    expect(res.status).toBe(400);
  });

  it('tenant_unresolved -> 400 tenant_unresolved (distinct from Invalid action)', async () => {
    mockResolveTenant.mockResolvedValue({ ok: false });
    const res = await GET(req('?action=get&line_user_id=U1'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockGetProfile).not.toHaveBeenCalled();
  });

  it('success -> 200, CORS headers set, forwards parsed params', async () => {
    mockGetProfile.mockResolvedValue({
      success: true,
      profile: {
        personal_info: { name: null, age: null, gender: null, weight: null, height: null, blood_type: 'unknown' },
        medical_conditions: [],
        allergies: [],
        medications: [],
        completion_percent: 0,
        updated_at: null,
      },
    });

    const res = await GET(req('?action=get&line_user_id=U1&line_account_id=2'));

    expect(res.status).toBe(200);
    expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
    expect(mockGetProfile).toHaveBeenCalledWith(FAKE_DB, 'U1', 2);
  });

  it('Missing line_user_id from the action layer -> 400', async () => {
    mockGetProfile.mockResolvedValue({ success: false, error: 'Missing line_user_id' });
    const res = await GET(req('?action=get'));
    expect(res.status).toBe(400);
  });

  it('Database error from the action layer -> 500', async () => {
    mockGetProfile.mockResolvedValue({ success: false, error: 'Database error' });
    const res = await GET(req('?action=get&line_user_id=U1'));
    expect(res.status).toBe(500);
  });

  it('line_account_id defaults to 0 when omitted', async () => {
    mockGetProfile.mockResolvedValue({ success: false, error: 'Missing line_user_id' });
    await GET(req('?action=get'));
    expect(mockGetProfile).toHaveBeenCalledWith(FAKE_DB, null, 0);
  });
});

describe('OPTIONS /api/miniapp/health-profile', () => {
  it('204 with CORS headers', () => {
    const res = OPTIONS();
    expect(res.status).toBe(204);
    expect(res.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});
