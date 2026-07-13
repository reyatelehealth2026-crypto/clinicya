import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  isValidLiffId,
  LIFF_ID_MAX_LENGTH,
  RESOLVE_LINE_ACCOUNT_CACHE_CONTROL,
  RESOLVE_LINE_ACCOUNT_STATUS,
  ResolveLineAccountQuerySchema,
  ResolveLineAccountResponseSchema,
} from '../src/resolve-line-account';

const FIXTURES_DIR = join(__dirname, '../fixtures/resolve-line-account');

function loadFixture(name: string): { request: unknown; response: unknown; status: number; cacheControl?: string } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('resolve-line-account contracts — golden fixture round-trip', () => {
  it('ok-fast-path: success shape, 200, cache header preserved', () => {
    const fx = loadFixture('ok-fast-path.json');
    expect(ResolveLineAccountQuerySchema.parse(fx.request)).toBeTruthy();
    expect(ResolveLineAccountResponseSchema.parse(fx.response)).toEqual({
      success: true,
      line_account_id: 12,
      tenant_id: 3,
      tenant_slug: 'tenant-0003',
    });
    expect(fx.status).toBe(RESOLVE_LINE_ACCOUNT_STATUS.ok);
    expect(fx.cacheControl).toBe(RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
  });

  it('invalid-liff-id: 400, error union member', () => {
    const fx = loadFixture('invalid-liff-id.json');
    expect(ResolveLineAccountResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'invalid_liff_id',
    });
    expect(fx.status).toBe(RESOLVE_LINE_ACCOUNT_STATUS.invalid_liff_id);
    // The fixture's own request also demonstrates the real validator agrees: empty liff_id is invalid.
    expect(isValidLiffId((fx.request as { liff_id: string }).liff_id)).toBe(false);
  });

  it('not-found: 404', () => {
    const fx = loadFixture('not-found.json');
    expect(ResolveLineAccountResponseSchema.parse(fx.response)).toEqual({ success: false, error: 'not_found' });
    expect(fx.status).toBe(RESOLVE_LINE_ACCOUNT_STATUS.not_found);
  });

  it('platform-unavailable: 503', () => {
    const fx = loadFixture('platform-unavailable.json');
    expect(ResolveLineAccountResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'platform_unavailable',
    });
    expect(fx.status).toBe(RESOLVE_LINE_ACCOUNT_STATUS.platform_unavailable);
  });

  it('every fixture in this directory carries the unconditional Cache-Control header (PHP session cache-limiter value, not the source file\'s own header() literal — see src/resolve-line-account.ts module doc)', () => {
    for (const name of [
      'ok-fast-path.json',
      'invalid-liff-id.json',
      'not-found.json',
      'platform-unavailable.json',
    ]) {
      const fx = loadFixture(name);
      expect(fx.cacheControl).toBe('no-store, no-cache, must-revalidate');
    }
  });
});

describe('isValidLiffId — mirrors rla_valid_liff_id()', () => {
  it('accepts a realistic LIFF id', () => {
    expect(isValidLiffId('2008477880-wmRN2Aln')).toBe(true);
  });

  it('rejects empty string', () => {
    expect(isValidLiffId('')).toBe(false);
  });

  it(`rejects a string longer than ${LIFF_ID_MAX_LENGTH} chars`, () => {
    expect(isValidLiffId('a'.repeat(LIFF_ID_MAX_LENGTH + 1))).toBe(false);
  });

  it('rejects characters outside [A-Za-z0-9-]', () => {
    expect(isValidLiffId('2008477880_wmRN2Aln')).toBe(false); // underscore
    expect(isValidLiffId('2008477880-wmRN2Aln;DROP TABLE')).toBe(false);
  });
});
