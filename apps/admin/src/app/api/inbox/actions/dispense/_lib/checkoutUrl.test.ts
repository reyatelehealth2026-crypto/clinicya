/**
 * @jest-environment node
 */
import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import { reyaAppendLiffContextParams, reyaIsRealLiffId, reyaLiffUrlOrOa, reyaOaChatUrl } from './checkoutUrl';

describe('reyaIsRealLiffId', () => {
  it('false for null/undefined/empty/whitespace-only', () => {
    expect(reyaIsRealLiffId(null)).toBe(false);
    expect(reyaIsRealLiffId(undefined)).toBe(false);
    expect(reyaIsRealLiffId('')).toBe(false);
    expect(reyaIsRealLiffId('   ')).toBe(false);
  });

  it('false for a PENDING* placeholder, case-insensitively', () => {
    expect(reyaIsRealLiffId('PENDING-1234')).toBe(false);
    expect(reyaIsRealLiffId('pending-abc')).toBe(false);
    expect(reyaIsRealLiffId('Pending_setup')).toBe(false);
  });

  it('true for a genuine liff id', () => {
    expect(reyaIsRealLiffId('1234567890-abcdefgh')).toBe(true);
  });
});

describe('reyaOaChatUrl', () => {
  it("returns '' when basic_id is missing/empty", () => {
    expect(reyaOaChatUrl({ basic_id: null })).toBe('');
    expect(reyaOaChatUrl({ basic_id: '' })).toBe('');
  });

  it('builds the LINE deep link from a basic_id', () => {
    expect(reyaOaChatUrl({ basic_id: '@abc1234' })).toBe('https://line.me/R/ti/p/%40abc1234');
  });
});

describe('reyaAppendLiffContextParams', () => {
  it('appends la= and liff_id= with "?" when the URL has no existing query string', () => {
    expect(reyaAppendLiffContextParams('https://liff.line.me/1234-abc/shop', 9, '1234-abc')).toBe(
      'https://liff.line.me/1234-abc/shop?la=9&liff_id=1234-abc'
    );
  });

  it('appends with "&" when the URL already has a query string', () => {
    expect(reyaAppendLiffContextParams('https://liff.line.me/1234-abc/order?id=42', 9, '1234-abc')).toBe(
      'https://liff.line.me/1234-abc/order?id=42&la=9&liff_id=1234-abc'
    );
  });

  it('preserves a URL fragment, inserting the params before it', () => {
    expect(reyaAppendLiffContextParams('https://liff.line.me/1234-abc/shop#section', 9, '1234-abc')).toBe(
      'https://liff.line.me/1234-abc/shop?la=9&liff_id=1234-abc#section'
    );
  });
});

describe('reyaLiffUrlOrOa', () => {
  it('returns the LIFF deep link (with la=/liff_id= context) when line_accounts.liff_id is real', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 9, liff_id: '1234-real', basic_id: '@shop', name: 'Shop' }]);
    const url = await reyaLiffUrlOrOa(db, 9, '/orders');
    expect(url).toBe('https://liff.line.me/1234-real/orders?la=9&liff_id=1234-real');
  });

  it('falls back to the OA chat URL when liff_id is a PENDING placeholder', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 9, liff_id: 'PENDING-x', basic_id: '@shop', name: 'Shop' }]);
    const url = await reyaLiffUrlOrOa(db, 9, '/orders');
    expect(url).toBe('https://line.me/R/ti/p/%40shop');
  });

  it("returns '' when there is no line_accounts row at all", async () => {
    const { db } = makeFakeTenantDb(() => []);
    const url = await reyaLiffUrlOrOa(db, 9, '/orders');
    expect(url).toBe('');
  });

  it("returns '' when liff_id is unreal AND basic_id is missing", async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 9, liff_id: null, basic_id: null, name: 'Shop' }]);
    const url = await reyaLiffUrlOrOa(db, 9, '/orders');
    expect(url).toBe('');
  });

  it('a DB error resolving the line_accounts row is swallowed, returning ""', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('db exploded');
    });
    const url = await reyaLiffUrlOrOa(db, 9, '/orders');
    expect(url).toBe('');
  });
});
