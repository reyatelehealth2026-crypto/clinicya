import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  WishlistListQuerySchema,
  WishlistListResponseSchema,
  WishlistRemoveRequestSchema,
  WishlistRemoveResponseSchema,
  WishlistToggleRequestSchema,
  WishlistToggleResponseSchema,
} from '../src/wishlist';

const FIXTURES_DIR = join(__dirname, '../fixtures/wishlist');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('wishlist contracts — golden fixture round-trip', () => {
  it('list: ok, with items + count', () => {
    const { request, response } = loadFixture('list-ok.json');
    expect(WishlistListQuerySchema.parse(request)).toBeTruthy();
    const parsed = WishlistListResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
  });

  it('list: no user resolved -> {success:true, items:[]} with NO count key', () => {
    const { request, response } = loadFixture('list-no-user.json');
    expect(WishlistListQuerySchema.parse(request)).toBeTruthy();
    const parsed = WishlistListResponseSchema.parse(response);
    expect(parsed).toEqual({ success: true, items: [] });
    expect('count' in parsed).toBe(false);
  });

  it('toggle: add', () => {
    const { request, response } = loadFixture('toggle-add.json');
    expect(WishlistToggleRequestSchema.parse(request)).toBeTruthy();
    expect(WishlistToggleResponseSchema.parse(response)).toEqual({
      success: true,
      is_favorite: true,
      message: 'เพิ่มรายการโปรดแล้ว',
    });
  });

  it('toggle: remove', () => {
    const { request, response } = loadFixture('toggle-remove.json');
    expect(WishlistToggleRequestSchema.parse(request)).toBeTruthy();
    expect(WishlistToggleResponseSchema.parse(response)).toEqual({
      success: true,
      is_favorite: false,
      message: 'ลบออกจากรายการโปรดแล้ว',
    });
  });

  it('toggle: missing params -> `error` key, not `message` (ad hoc envelope, not the flat one)', () => {
    const { request, response } = loadFixture('toggle-missing-params.json');
    expect(WishlistToggleRequestSchema.parse(request)).toBeTruthy();
    const parsed = WishlistToggleResponseSchema.parse(response);
    expect(parsed).toEqual({ success: false, error: 'Missing user or product' });
    expect('message' in parsed).toBe(false);
  });

  it('remove: ok', () => {
    const { request, response } = loadFixture('remove-ok.json');
    expect(WishlistRemoveRequestSchema.parse(request)).toBeTruthy();
    expect(WishlistRemoveResponseSchema.parse(response)).toEqual({ success: true, message: 'ลบออกจากรายการโปรดแล้ว' });
  });
});
