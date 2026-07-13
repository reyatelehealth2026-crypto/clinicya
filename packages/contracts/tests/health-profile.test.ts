import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  HEALTH_PROFILE_GET_STATUS,
  HealthProfileGetQuerySchema,
  HealthProfileGetResponseSchema,
} from '../src/health-profile';
// mig-api (Phase 3 batch 2, wt-phase3b2) — write-action imports, added in a separate statement rather
// than folded into the reads-lane import block above, to keep this append additive.
import {
  HEALTH_PROFILE_ADD_ALLERGY_STATUS,
  HEALTH_PROFILE_ADD_MEDICATION_STATUS,
  HEALTH_PROFILE_REMOVE_ALLERGY_STATUS,
  HEALTH_PROFILE_REMOVE_MEDICATION_STATUS,
  HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS,
  HEALTH_PROFILE_UPDATE_PERSONAL_STATUS,
  HealthProfileAddAllergyRequestSchema,
  HealthProfileAddAllergyResponseSchema,
  HealthProfileAddMedicationRequestSchema,
  HealthProfileAddMedicationResponseSchema,
  HealthProfileRemoveAllergyRequestSchema,
  HealthProfileRemoveAllergyResponseSchema,
  HealthProfileRemoveMedicationRequestSchema,
  HealthProfileRemoveMedicationResponseSchema,
  HealthProfileUpdateMedicalHistoryRequestSchema,
  HealthProfileUpdateMedicalHistoryResponseSchema,
  HealthProfileUpdatePersonalRequestSchema,
  HealthProfileUpdatePersonalResponseSchema,
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

describe('health-profile contracts — write actions (mig-api, Phase 3 batch 2) — golden fixture round-trip', () => {
  it('update_personal: ok', () => {
    const fx = loadFixture('update-personal-ok.json');
    expect(HealthProfileUpdatePersonalRequestSchema.parse(fx.request)).toBeTruthy();
    expect(HealthProfileUpdatePersonalResponseSchema.parse(fx.response)).toEqual({
      success: true,
      message: 'บันทึกข้อมูลส่วนตัวแล้ว',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS.ok);
  });

  it('update_personal: invalid age -> 400', () => {
    const fx = loadFixture('update-personal-invalid-age.json');
    expect(HealthProfileUpdatePersonalResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'Invalid age',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS['Invalid age']);
  });

  it('update_medical_history: ok, unknown condition keys silently dropped server-side', () => {
    const fx = loadFixture('update-medical-history-ok.json');
    expect(HealthProfileUpdateMedicalHistoryRequestSchema.parse(fx.request)).toBeTruthy();
    expect(HealthProfileUpdateMedicalHistoryResponseSchema.parse(fx.response)).toEqual({
      success: true,
      message: 'บันทึกประวัติการแพทย์แล้ว',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS.ok);
  });

  it('add_allergy: ok, returns the new allergy row', () => {
    const fx = loadFixture('add-allergy-ok.json');
    expect(HealthProfileAddAllergyRequestSchema.parse(fx.request)).toBeTruthy();
    const parsed = HealthProfileAddAllergyResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, allergy: { drug_name: 'Penicillin' } });
    expect(fx.status).toBe(HEALTH_PROFILE_ADD_ALLERGY_STATUS.ok);
  });

  it('add_allergy: duplicate -> 400 with the Thai duplicate message, not the generic one', () => {
    const fx = loadFixture('add-allergy-duplicate.json');
    expect(HealthProfileAddAllergyResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_ADD_ALLERGY_STATUS['ยานี้มีอยู่ในรายการแพ้ยาแล้ว']);
  });

  it('remove_allergy: ok — SUBTLE TRAP: request carries no line_account_id at all, matching the PHP DELETE (id + line_user_id only)', () => {
    const fx = loadFixture('remove-allergy-ok.json');
    expect(HealthProfileRemoveAllergyRequestSchema.parse(fx.request)).toBeTruthy();
    expect('line_account_id' in (fx.request as Record<string, unknown>)).toBe(false);
    expect(HealthProfileRemoveAllergyResponseSchema.parse(fx.response)).toEqual({
      success: true,
      message: 'ลบข้อมูลการแพ้ยาแล้ว',
    });
  });

  it('add_medication: ok, no existing medications -> no interactions keys on the response at all', () => {
    const fx = loadFixture('add-medication-no-interactions.json');
    expect(HealthProfileAddMedicationRequestSchema.parse(fx.request)).toBeTruthy();
    const parsed = HealthProfileAddMedicationResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, medication: { medication_name: 'Metformin' } });
    expect('interactions' in fx.response!).toBe(false);
    expect('has_interactions' in fx.response!).toBe(false);
    expect(fx.status).toBe(HEALTH_PROFILE_ADD_MEDICATION_STATUS.ok);
  });

  it('add_medication: SCHEMA-ONLY (not reachable against the real committed template — see fixture description) — interactions found shape', () => {
    const fx = loadFixture('add-medication-with-interactions.json');
    const parsed = HealthProfileAddMedicationResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, has_interactions: true });
    if (parsed.success && 'interactions' in parsed) {
      expect(parsed.interactions).toHaveLength(1);
    }
  });

  it('remove_medication: ok — soft delete, same no-line_account_id-predicate pattern as remove_allergy', () => {
    const fx = loadFixture('remove-medication-ok.json');
    expect(HealthProfileRemoveMedicationRequestSchema.parse(fx.request)).toBeTruthy();
    expect(HealthProfileRemoveMedicationResponseSchema.parse(fx.response)).toEqual({
      success: true,
      message: 'ลบยาออกจากรายการแล้ว',
    });
    expect(fx.status).toBe(HEALTH_PROFILE_REMOVE_MEDICATION_STATUS.ok);
  });
});
