import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  HEALTH_PROFILE_GET_STATUS,
  HealthProfileGetQuerySchema,
  HealthProfileGetResponseSchema,
} from '../src/health-profile';

const FIXTURES_DIR = join(__dirname, '../fixtures/health-profile');

function loadFixture(name: string): { request: unknown; response: unknown; status: number } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('health-profile contracts — action=get — golden fixture round-trip', () => {
  it('get-ok: populated profile with allergies + medications', () => {
    const fx = loadFixture('get-ok.json');
    expect(HealthProfileGetQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = HealthProfileGetResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.profile.allergies).toHaveLength(1);
      expect(parsed.profile.medications).toHaveLength(1);
      expect(parsed.profile.completion_percent).toBeGreaterThan(0);
    }
    expect(fx.status).toBe(HEALTH_PROFILE_GET_STATUS.ok);
  });

  it('get-empty-new-profile: auto-created row on first call, all fields null/unknown, completion_percent 0', () => {
    const fx = loadFixture('get-empty-new-profile.json');
    const parsed = HealthProfileGetResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({
      success: true,
      profile: {
        personal_info: { name: null, blood_type: 'unknown' },
        medical_conditions: [],
        allergies: [],
        medications: [],
        completion_percent: 0,
        updated_at: null,
      },
    });
  });

  it('get-missing-line-user-id: 400', () => {
    const fx = loadFixture('get-missing-line-user-id.json');
    expect(HealthProfileGetResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'Missing line_user_id',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_GET_STATUS['Missing line_user_id']);
  });

  it('get-database-error: 500', () => {
    const fx = loadFixture('get-database-error.json');
    expect(HealthProfileGetResponseSchema.parse(fx.response)).toEqual({ success: false, error: 'Database error' });
    expect(fx.status).toBe(HEALTH_PROFILE_GET_STATUS['Database error']);
  });
});
