import { createHmac } from 'node:crypto';
import { makeFakeTenantDb, type RecordedQuery } from '../../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

import {
  saveFacebookAccountAction,
  deleteFacebookAccountAction,
  testFacebookConnectionAction,
  saveTiktokAccountAction,
  deleteTiktokAccountAction,
  testTiktokConnectionAction,
} from './platform-actions';

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  return fd;
}

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
  return { queries };
}

function jsonResponse(status: number, body: unknown): Response {
  return {
    status,
    json: async () => body,
  } as unknown as Response;
}

const originalFetch = global.fetch;
let mockFetch: jest.Mock;

beforeEach(() => {
  jest.clearAllMocks();
  mockFetch = jest.fn();
  global.fetch = mockFetch as unknown as typeof fetch;
});

afterAll(() => {
  global.fetch = originalFetch;
});

describe('saveFacebookAccountAction', () => {
  it('rejects when required fields are missing, redirecting with the exact PHP validation message', async () => {
    wireFakeDb(() => []);
    await expect(saveFacebookAccountAction(formData({ name: '' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('เกิดข้อผิดพลาด: กรุณากรอกชื่อเพจ, Page ID และ Page Access Token')}`
    );
  });

  it('INSERTs a new account when fb_id is 0/absent and redirects with the "เพิ่ม..." success message', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(
      saveFacebookAccountAction(
        formData({ name: 'CNY Page', page_id: '123', app_id: 'a', app_secret: 's', page_access_token: 'tok', verify_token: 'v' })
      )
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO facebook_accounts'));
    expect(insertQuery?.params).toEqual(['CNY Page', '123', 'a', 's', 'tok', 'v', 0]);
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('เพิ่มการเชื่อมต่อ Facebook Messenger สำเร็จ')}`
    );
  });

  it('UPDATEs the existing account when fb_id > 0 and redirects with the "อัปเดต..." success message', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(
      saveFacebookAccountAction(
        formData({
          fb_id: '7',
          name: 'CNY Page',
          page_id: '123',
          app_id: 'a',
          app_secret: 's',
          page_access_token: 'tok',
          verify_token: 'v',
          is_active: 'on',
        })
      )
    ).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE facebook_accounts'));
    expect(updateQuery?.params).toEqual(['CNY Page', '123', 'a', 's', 'tok', 'v', 1, 7]);
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('อัปเดตการเชื่อมต่อ Facebook Messenger สำเร็จ')}`
    );
  });

  it('redirects with a ?error= message when the write throws', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(
      saveFacebookAccountAction(formData({ name: 'X', page_id: '1', page_access_token: 't' }))
    ).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=platform&error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });
});

describe('deleteFacebookAccountAction', () => {
  it('DELETEs by fb_id and redirects with the success message', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(deleteFacebookAccountAction(formData({ fb_id: '9' }))).rejects.toThrow('REDIRECT:');
    const deleteQuery = queries.find((q) => q.sql.includes('DELETE FROM facebook_accounts'));
    expect(deleteQuery?.params).toEqual([9]);
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('ลบการเชื่อมต่อ Facebook Messenger แล้ว')}`
    );
  });

  it('redirects with the "ลบไม่สำเร็จ" error prefix when the DELETE throws', async () => {
    wireFakeDb(() => {
      throw new Error('locked');
    });
    await expect(deleteFacebookAccountAction(formData({ fb_id: '9' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=platform&error=${encodeURIComponent('ลบไม่สำเร็จ: locked')}`);
  });
});

describe('testFacebookConnectionAction', () => {
  const ROW = {
    id: 3,
    name: 'CNY Page',
    page_id: '111',
    app_id: 'appid',
    app_secret: 'appsecret',
    page_access_token: 'pagetoken',
    verify_token: 'vt',
    is_active: 1,
  };

  it('redirects with "ทดสอบไม่สำเร็จ" when the account row is not found', async () => {
    wireFakeDb(() => []);
    await expect(testFacebookConnectionAction(formData({ fb_id: '999' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('ทดสอบไม่สำเร็จ: ไม่พบเพจที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)')}`
    );
  });

  it('calls graph.facebook.com/v19.0/debug_token when both app_id and app_secret are set', async () => {
    wireFakeDb(() => [ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { data: { is_valid: true, profile_id: '111', scopes: ['pages_messaging'] } }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');

    const calledUrl = mockFetch.mock.calls[0]?.[0] as string;
    expect(calledUrl).toContain('graph.facebook.com/v19.0/debug_token');
    expect(calledUrl).toContain('access_token=' + encodeURIComponent('appid|appsecret'));
  });

  it('falls back to graph.facebook.com/v19.0/me?fields=name when app_id/app_secret are unset', async () => {
    wireFakeDb(() => [{ ...ROW, app_id: '', app_secret: '' }]);
    mockFetch.mockResolvedValue(jsonResponse(200, { name: 'CNY Page' }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');

    const calledUrl = mockFetch.mock.calls[0]?.[0] as string;
    expect(calledUrl).toContain('graph.facebook.com/v19.0/me?fields=name');
    expect(calledUrl).not.toContain('debug_token');
  });

  it('succeeds with a "pages_messaging" scope note when the token is valid and matches the page id', async () => {
    wireFakeDb(() => [ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { data: { is_valid: true, profile_id: '111', scopes: ['pages_messaging', 'pages_show_list'] } }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');
    const [, call] = mockRedirect.mock.calls[0]!;
    void call;
    expect(mockRedirect.mock.calls[0]![0]).toContain(encodeURIComponent('มีสิทธิ์ pages_messaging'));
  });

  it('errors when the valid token belongs to a different page id', async () => {
    wireFakeDb(() => [ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { data: { is_valid: true, profile_id: '999', scopes: [] } }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('Token ใช้ได้ แต่เป็นของเพจอื่น (Page ID 999) — ต้องตรงกับ 111')}`
    );
  });

  it('errors with the debug_token error message when is_valid is false', async () => {
    wireFakeDb(() => [ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { data: { is_valid: false, error: { message: 'expired' } } }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=platform&error=${encodeURIComponent('เชื่อมต่อไม่สำเร็จ: expired')}`);
  });

  it('succeeds via the /me fallback shape when it returns a name', async () => {
    wireFakeDb(() => [{ ...ROW, app_id: '', app_secret: '' }]);
    mockFetch.mockResolvedValue(jsonResponse(200, { name: 'CNY Page' }));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('เชื่อมต่อ Facebook สำเร็จ: CNY Page')}`
    );
  });

  it('redirects with "เชื่อมต่อไม่สำเร็จ" (not "ทดสอบไม่สำเร็จ") on a network/fetch failure', async () => {
    wireFakeDb(() => [ROW]);
    mockFetch.mockRejectedValue(new Error('network down'));

    await expect(testFacebookConnectionAction(formData({ fb_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=platform&error=${encodeURIComponent('เชื่อมต่อไม่สำเร็จ: network down')}`);
  });

  it('never calls graph.facebook.com from a live network (fetch is mocked)', () => {
    expect(global.fetch).toBe(mockFetch);
  });
});

describe('saveTiktokAccountAction', () => {
  it('rejects when required fields are missing, redirecting with the exact PHP validation message', async () => {
    wireFakeDb(() => []);
    await expect(saveTiktokAccountAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('เกิดข้อผิดพลาด: กรุณากรอกชื่อร้าน, Shop ID และ Access Token')}`
    );
  });

  it('INSERTs a new account when tt_id is 0/absent', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(
      saveTiktokAccountAction(formData({ name: 'CNY Shop', shop_id: '1', app_key: 'k', app_secret: 's', access_token: 'tok' }))
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO tiktok_shop_accounts'));
    expect(insertQuery?.params).toEqual(['CNY Shop', '1', 'k', 's', 'tok', '', '', 0]);
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('เพิ่มการเชื่อมต่อ TikTok Shop สำเร็จ')}`
    );
  });

  it('UPDATEs the existing account when tt_id > 0', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(
      saveTiktokAccountAction(formData({ tt_id: '4', name: 'CNY Shop', shop_id: '1', access_token: 'tok', is_active: 'on' }))
    ).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE tiktok_shop_accounts'));
    expect(updateQuery?.params).toEqual(['CNY Shop', '1', '', '', 'tok', '', '', 1, 4]);
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('อัปเดตการเชื่อมต่อ TikTok Shop สำเร็จ')}`
    );
  });
});

describe('deleteTiktokAccountAction', () => {
  it('DELETEs by tt_id and redirects with the success message', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(deleteTiktokAccountAction(formData({ tt_id: '5' }))).rejects.toThrow('REDIRECT:');
    const deleteQuery = queries.find((q) => q.sql.includes('DELETE FROM tiktok_shop_accounts'));
    expect(deleteQuery?.params).toEqual([5]);
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=platform&message=${encodeURIComponent('ลบการเชื่อมต่อ TikTok Shop แล้ว')}`);
  });
});

describe('testTiktokConnectionAction', () => {
  const TT_ROW = {
    id: 4,
    name: 'CNY Shop',
    shop_id: 'shop1',
    app_key: 'appkey',
    app_secret: 'appsecret',
    access_token: 'accesstoken',
    refresh_token: null,
    shop_cipher: null,
    is_active: 1,
  };

  it('redirects with "ทดสอบไม่สำเร็จ" when the account row is not found', async () => {
    wireFakeDb(() => []);
    await expect(testTiktokConnectionAction(formData({ tt_id: '999' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('ทดสอบไม่สำเร็จ: ไม่พบร้านที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)')}`
    );
  });

  it('signs a GET to /customer_service/conversations with HMAC-SHA256(path + sorted-key-concatenated params, app_secret)', async () => {
    wireFakeDb(() => [TT_ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { code: 0, data: {} }));

    await expect(testTiktokConnectionAction(formData({ tt_id: '4' }))).rejects.toThrow('REDIRECT:');

    const calledUrl = new URL(mockFetch.mock.calls[0]?.[0] as string);
    expect(calledUrl.origin).toBe('https://open-api.tiktokglobalshop.com');
    expect(calledUrl.pathname).toBe('/customer_service/conversations');
    expect(calledUrl.searchParams.get('page_size')).toBe('1');
    expect(calledUrl.searchParams.has('cursor')).toBe(false);

    // Independently recompute TikTokShopAPI::signRequest()'s algorithm: ksort the
    // signature params (everything except access_token/sign), concatenate key+value
    // with no delimiter, HMAC-SHA256 with app_secret, hex digest.
    const signParams: Record<string, string> = {};
    for (const [key, value] of calledUrl.searchParams.entries()) {
      if (key === 'access_token' || key === 'sign') continue;
      signParams[key] = value;
    }
    const sortedKeys = Object.keys(signParams).sort();
    const paramStr = sortedKeys.map((k) => k + signParams[k]).join('');
    const expectedSign = createHmac('sha256', TT_ROW.app_secret).update('/customer_service/conversations' + paramStr).digest('hex');

    expect(calledUrl.searchParams.get('sign')).toBe(expectedSign);
    expect(calledUrl.searchParams.get('app_key')).toBe('appkey');
    expect(calledUrl.searchParams.get('access_token')).toBe('accesstoken');
    expect(calledUrl.searchParams.get('version')).toBe('202309');
  });

  it('includes shop_cipher in both the request and the signature when set', async () => {
    wireFakeDb(() => [{ ...TT_ROW, shop_cipher: 'ciph3r' }]);
    mockFetch.mockResolvedValue(jsonResponse(200, { code: 0 }));

    await expect(testTiktokConnectionAction(formData({ tt_id: '4' }))).rejects.toThrow('REDIRECT:');

    const calledUrl = new URL(mockFetch.mock.calls[0]?.[0] as string);
    expect(calledUrl.searchParams.get('shop_cipher')).toBe('ciph3r');
  });

  it('redirects with a success message when TikTok returns code=0', async () => {
    wireFakeDb(() => [TT_ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { code: 0, data: {} }));

    await expect(testTiktokConnectionAction(formData({ tt_id: '4' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&message=${encodeURIComponent('เชื่อมต่อ TikTok Shop สำเร็จ: CNY Shop')}`
    );
  });

  it('redirects with the TikTok-provided message when the API call fails (non-zero code)', async () => {
    wireFakeDb(() => [TT_ROW]);
    mockFetch.mockResolvedValue(jsonResponse(200, { code: 10012, message: 'invalid access_token' }));

    await expect(testTiktokConnectionAction(formData({ tt_id: '4' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('เชื่อมต่อไม่สำเร็จ: invalid access_token')}`
    );
  });

  it('falls back to the generic checklist message when the API failure has no message', async () => {
    wireFakeDb(() => [TT_ROW]);
    mockFetch.mockResolvedValue(jsonResponse(500, {}));

    await expect(testTiktokConnectionAction(formData({ tt_id: '4' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=platform&error=${encodeURIComponent('เชื่อมต่อไม่สำเร็จ: ตรวจสอบ Access Token / App Key / Shop Cipher')}`
    );
  });

  it('never calls open-api.tiktokglobalshop.com from a live network (fetch is mocked)', () => {
    expect(global.fetch).toBe(mockFetch);
  });
});
