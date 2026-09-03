/**
 * @jest-environment node
 */
import { makeFakeTenantDb, type QueryImpl } from './testHelpers/fakeTenantDb';

/**
 * imageResolver.test.ts — dedicated unit coverage for `getImageData()` and
 * `detectMimeType()`, run against the REAL implementation (no
 * `jest.mock('./imageResolver')` here — that's what `route.test.ts` does
 * for the route-level integration tests). `global.fetch` is replaced with
 * a per-test controllable mock (not a throw-guard) since this file
 * legitimately needs to exercise the LINE and generic-HTTP branches'
 * fetch() call sites.
 */

const mockFetch = jest.fn();
global.fetch = mockFetch as unknown as typeof fetch;

import { getImageData, detectMimeType } from './imageResolver';

function fakeResponse(opts: { status: number; body?: ArrayBuffer; contentType?: string | null }): Response {
  const headers = new Map<string, string>();
  if (opts.contentType) headers.set('content-type', opts.contentType);
  return {
    status: opts.status,
    headers: { get: (key: string) => headers.get(key.toLowerCase()) ?? null },
    arrayBuffer: async () => opts.body ?? new ArrayBuffer(0),
  } as unknown as Response;
}

function wireDb(queryImpl: QueryImpl = () => []) {
  return makeFakeTenantDb(queryImpl).db;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('getImageData() — branch 1: data:image/...;base64,... URLs', () => {
  it('parses a valid data URL with ZERO network calls (proves the branch is genuinely network-free, not just mocked)', async () => {
    const db = wireDb();
    const dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB';

    const result = await getImageData(db, 3, dataUrl);

    expect(result).toEqual({ success: true, base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB', mimeType: 'image/png' });
    expect(mockFetch).not.toHaveBeenCalled();
  });

  it("malformed data:image/ URL -> {success:false, error:'รูปแบบ data URL ไม่ถูกต้อง'}, still no network", async () => {
    const db = wireDb();

    const result = await getImageData(db, 3, 'data:image/pngbase64,notmatching');

    expect(result).toEqual({ success: false, error: 'รูปแบบ data URL ไม่ถูกต้อง' });
    expect(mockFetch).not.toHaveBeenCalled();
  });
});

describe('getImageData() — branch 2: LINE content URLs (api-data.line.me)', () => {
  const LINE_URL = 'https://api-data.line.me/v2/bot/message/12345/content';

  it("no channel_access_token row -> {success:false, error:'LINE access token not configured'}, no fetch", async () => {
    const db = wireDb(() => []);

    const result = await getImageData(db, 3, LINE_URL);

    expect(result).toEqual({ success: false, error: 'LINE access token not configured' });
    expect(mockFetch).not.toHaveBeenCalled();
  });

  it('fetches with Authorization: Bearer <token> and returns base64 + detected mime type on 200', async () => {
    const db = wireDb((sqlText) =>
      sqlText.includes('FROM line_accounts') ? [{ channel_access_token: 'line-channel-token' }] : []
    );
    const jpegBytes = new Uint8Array([0xff, 0xd8, 0xff, 0, 0, 0, 0, 0]).buffer;
    mockFetch.mockResolvedValue(fakeResponse({ status: 200, body: jpegBytes }));

    const result = await getImageData(db, 3, LINE_URL);

    expect(mockFetch).toHaveBeenCalledWith(
      LINE_URL,
      expect.objectContaining({ headers: { Authorization: 'Bearer line-channel-token' } })
    );
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.mimeType).toBe('image/jpeg');
      expect(Buffer.from(result.base64, 'base64')).toEqual(Buffer.from(jpegBytes));
    }
  });

  it("non-200 response -> {success:false, error:'Failed to download LINE image'} (English-only, distinct from the generic-HTTP branch's Thai messages)", async () => {
    const db = wireDb((sqlText) =>
      sqlText.includes('FROM line_accounts') ? [{ channel_access_token: 'line-channel-token' }] : []
    );
    mockFetch.mockResolvedValue(fakeResponse({ status: 403 }));

    const result = await getImageData(db, 3, LINE_URL);

    expect(result).toEqual({ success: false, error: 'Failed to download LINE image' });
  });
});

describe('getImageData() — branch 3 (PHP branch 5): generic http(s) URLs', () => {
  const HTTP_URL = 'https://cdn.example.com/uploads/photo.jpg?v=2';

  it('200 response -> base64 + detected mime type, no Authorization header sent', async () => {
    const db = wireDb();
    const pngBytes = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]).buffer;
    mockFetch.mockResolvedValue(fakeResponse({ status: 200, body: pngBytes, contentType: 'image/png' }));

    const result = await getImageData(db, 3, HTTP_URL);

    expect(mockFetch).toHaveBeenCalledWith(HTTP_URL, expect.objectContaining({ redirect: 'follow' }));
    const [, init] = mockFetch.mock.calls[0] as [string, { headers?: Record<string, string> }];
    expect(init.headers?.Authorization).toBeUndefined();
    expect(result).toEqual({ success: true, base64: Buffer.from(pngBytes).toString('base64'), mimeType: 'image/png' });
  });

  it("404 -> {success:false, error:'ไม่พบไฟล์รูปภาพ (404): photo.jpg?v=2'} (basename includes query string, matching PHP's raw-string basename())", async () => {
    const db = wireDb();
    mockFetch.mockResolvedValue(fakeResponse({ status: 404 }));

    const result = await getImageData(db, 3, HTTP_URL);

    expect(result).toEqual({ success: false, error: 'ไม่พบไฟล์รูปภาพ (404): photo.jpg?v=2' });
  });

  it("non-200, non-404 -> {success:false, error:'ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP 500)'}", async () => {
    const db = wireDb();
    mockFetch.mockResolvedValue(fakeResponse({ status: 500 }));

    const result = await getImageData(db, 3, HTTP_URL);

    expect(result).toEqual({ success: false, error: 'ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP 500)' });
  });

  it('empty body on a 200 -> treated as a failed download (HTTP 200 in the message)', async () => {
    const db = wireDb();
    mockFetch.mockResolvedValue(fakeResponse({ status: 200, body: new ArrayBuffer(0) }));

    const result = await getImageData(db, 3, HTTP_URL);

    expect(result).toEqual({ success: false, error: 'ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP 200)' });
  });

  it("fetch rejection -> {success:false, error:'ไม่สามารถเชื่อมต่อเพื่อดาวน์โหลดรูปภาพ: <message>'}", async () => {
    const db = wireDb();
    mockFetch.mockRejectedValue(new Error('getaddrinfo ENOTFOUND cdn.example.com'));

    const result = await getImageData(db, 3, HTTP_URL);

    expect(result).toEqual({
      success: false,
      error: 'ไม่สามารถเชื่อมต่อเพื่อดาวน์โหลดรูปภาพ: getaddrinfo ENOTFOUND cdn.example.com',
    });
  });
});

describe('detectMimeType()', () => {
  it('trusts a jpeg/png/gif/webp content-type header before sniffing magic bytes', () => {
    expect(detectMimeType(Buffer.from([0, 0, 0, 0]), 'image/jpeg; charset=binary')).toBe('image/jpeg');
    expect(detectMimeType(Buffer.from([0, 0, 0, 0]), 'image/png')).toBe('image/png');
    expect(detectMimeType(Buffer.from([0, 0, 0, 0]), 'image/gif')).toBe('image/gif');
    expect(detectMimeType(Buffer.from([0, 0, 0, 0]), 'image/webp')).toBe('image/webp');
  });

  it('falls back to magic-byte sniffing when no usable content-type header is present', () => {
    expect(detectMimeType(Buffer.from([0xff, 0xd8, 0xff, 1, 2, 3]))).toBe('image/jpeg');
    expect(detectMimeType(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))).toBe('image/png');
    expect(detectMimeType(Buffer.from('GIF89a...', 'latin1'))).toBe('image/gif');
    expect(detectMimeType(Buffer.concat([Buffer.from('RIFF', 'latin1'), Buffer.from([0, 0, 0, 0]), Buffer.from('WEBP', 'latin1')]))).toBe(
      'image/webp'
    );
  });

  it('defaults to image/jpeg when nothing matches', () => {
    expect(detectMimeType(Buffer.from('not an image at all'))).toBe('image/jpeg');
  });
});
