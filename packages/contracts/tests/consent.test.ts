import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ConsentSaveRequestSchema, ConsentSaveResponseSchema } from '../src/consent';

const FIXTURES_DIR = join(__dirname, '../fixtures/consent');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('consent contracts — golden fixture round-trip (action=save only)', () => {
  it('save: ok, existing user', () => {
    const { request, response } = loadFixture('save-ok.json');
    expect(ConsentSaveRequestSchema.parse(request)).toBeTruthy();
    expect(ConsentSaveResponseSchema.parse(response)).toEqual({ success: true, message: 'Consent saved', user_id: 42 });
  });

  it('save: ok, brand-new user auto-created', () => {
    const { request, response } = loadFixture('save-new-user.json');
    expect(ConsentSaveRequestSchema.parse(request)).toBeTruthy();
    const parsed = ConsentSaveResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, message: 'Consent saved' });
  });

  it('save: missing line_user_id -> flat failure', () => {
    const { request, response } = loadFixture('save-missing-line-user-id.json');
    expect(ConsentSaveRequestSchema.parse(request)).toBeTruthy();
    expect(ConsentSaveResponseSchema.parse(response)).toEqual({ success: false, message: 'LINE User ID required' });
  });
});
