/**
 * @jest-environment node
 */
jest.mock('@reya/db', () => ({
  getMasterDb: jest.fn(),
  getTenantDb: jest.fn(),
}));
jest.mock('./_lib/lookup', () => ({
  resolveLineAccountByLiffId: jest.fn(),
}));

import { getMasterDb } from '@reya/db';
import { RESOLVE_LINE_ACCOUNT_CACHE_CONTROL } from '@reya/contracts';
import { resolveLineAccountByLiffId } from './_lib/lookup';
import { GET, OPTIONS } from './route';

const mockGetMasterDb = getMasterDb as jest.MockedFunction<typeof getMasterDb>;
const mockLookup = resolveLineAccountByLiffId as jest.MockedFunction<typeof resolveLineAccountByLiffId>;

function req(search: string): Request {
  return new Request(`https://re-ya.com/api/miniapp/resolve-line-account${search}`);
}

describe('GET /api/miniapp/resolve-line-account', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetMasterDb.mockReturnValue({ __fakeMasterDb: true } as never);
  });

  it('success -> 200, CORS + Cache-Control headers, body verbatim', async () => {
    mockLookup.mockResolvedValue({ success: true, line_account_id: 12, tenant_id: 3, tenant_slug: 'tenant-0003' });

    const res = await GET(req('?liff_id=2008477880-wmRN2Aln'));

    expect(res.status).toBe(200);
    expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
    expect(res.headers.get('Cache-Control')).toBe(RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
    expect(await res.json()).toEqual({
      success: true,
      line_account_id: 12,
      tenant_id: 3,
      tenant_slug: 'tenant-0003',
    });
    expect(mockLookup).toHaveBeenCalledWith('2008477880-wmRN2Aln', {
      master: { __fakeMasterDb: true },
      getTenantDb: expect.any(Function),
    });
  });

  it('invalid_liff_id -> 400, Cache-Control still set', async () => {
    mockLookup.mockResolvedValue({ success: false, error: 'invalid_liff_id' });

    const res = await GET(req('?liff_id='));

    expect(res.status).toBe(400);
    expect(res.headers.get('Cache-Control')).toBe(RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
    expect(await res.json()).toEqual({ success: false, error: 'invalid_liff_id' });
  });

  it('not_found -> 404', async () => {
    mockLookup.mockResolvedValue({ success: false, error: 'not_found' });
    const res = await GET(req('?liff_id=2008477880-wmRN2Aln'));
    expect(res.status).toBe(404);
  });

  it('platform_unavailable -> 503', async () => {
    mockLookup.mockResolvedValue({ success: false, error: 'platform_unavailable' });
    const res = await GET(req('?liff_id=2008477880-wmRN2Aln'));
    expect(res.status).toBe(503);
  });

  it('missing liff_id query param -> treated as empty string, passed through to the lookup (which rejects it)', async () => {
    mockLookup.mockResolvedValue({ success: false, error: 'invalid_liff_id' });
    await GET(req(''));
    expect(mockLookup).toHaveBeenCalledWith('', expect.anything());
  });
});

describe('OPTIONS /api/miniapp/resolve-line-account', () => {
  it('204, CORS headers, Cache-Control also present (PHP sets it before its own OPTIONS short-circuit)', async () => {
    const res = OPTIONS();
    expect(res.status).toBe(204);
    expect(res.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
    expect(res.headers.get('Cache-Control')).toBe(RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
  });
});
