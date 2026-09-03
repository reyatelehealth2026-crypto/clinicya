import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  AddressesDeleteRequestSchema,
  AddressesDeleteResponseSchema,
  AddressesListQuerySchema,
  AddressesListResponseSchema,
  AddressesUpsertRequestSchema,
  AddressesUpsertResponseSchema,
} from '../src/addresses';

const FIXTURES_DIR = join(__dirname, '../fixtures/addresses');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('addresses contracts — golden fixture round-trip (no PHP original; see addresses.ts doc comment)', () => {
  it('list: ok, two saved slots', () => {
    const { request, response } = loadFixture('list-ok.json');
    expect(AddressesListQuerySchema.parse(request)).toBeTruthy();
    const parsed = AddressesListResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.addresses).toHaveLength(2);
      expect(parsed.addresses[0]?.label).toBe('primary');
    }
  });

  it('list: no rows yet -> {success:true, addresses:[]}', () => {
    const { request, response } = loadFixture('list-empty.json');
    expect(AddressesListQuerySchema.parse(request)).toBeTruthy();
    expect(AddressesListResponseSchema.parse(response)).toEqual({ success: true, message: '', addresses: [] });
  });

  it('upsert: ok, upsert-by-label via the UNIQUE(line_user_id, line_account_id, label) key', () => {
    const { request, response } = loadFixture('upsert-ok.json');
    expect(AddressesUpsertRequestSchema.parse(request)).toBeTruthy();
    const parsed = AddressesUpsertResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.address.label).toBe('primary');
    }
  });

  it('delete: ok, idempotent regardless of whether a row matched', () => {
    const { request, response } = loadFixture('delete-ok.json');
    expect(AddressesDeleteRequestSchema.parse(request)).toBeTruthy();
    expect(AddressesDeleteResponseSchema.parse(response)).toEqual({ success: true, message: 'ลบที่อยู่แล้ว' });
  });
});
