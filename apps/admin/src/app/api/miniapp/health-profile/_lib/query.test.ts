/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { getHealthProfileAction } from './query';

describe('getHealthProfileAction', () => {
  it('missing line_user_id -> Missing line_user_id, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await getHealthProfileAction(db, null, 1);
    expect(result).toEqual({ success: false, error: 'Missing line_user_id' });
    expect(queries).toHaveLength(0);
  });

  it('existing profile with allergies + medications -> full nested shape, completion_percent computed', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM user_health_profiles')) {
        return [
          {
            id: 1,
            name: 'สมชาย',
            age: 34,
            gender: 'male',
            weight: '68.50',
            height: '172.00',
            blood_type: 'O',
            medical_conditions: '["diabetes","hypertension"]',
            updated_at: '2026-07-01 10:15:22',
          },
        ];
      }
      if (sqlText.includes('FROM user_drug_allergies')) {
        return [
          {
            id: 5,
            line_user_id: 'U1',
            line_account_id: 1,
            drug_name: 'Penicillin',
            drug_id: null,
            reaction_type: 'rash',
            reaction_notes: null,
            severity: 'severe',
            created_at: '2026-03-01 08:00:00',
          },
        ];
      }
      if (sqlText.includes('FROM user_current_medications')) {
        return [
          {
            id: 9,
            line_user_id: 'U1',
            line_account_id: 1,
            medication_name: 'Metformin',
            product_id: null,
            dosage: '500mg',
            frequency: 'BID',
            start_date: '2026-01-15',
            notes: null,
            is_active: 1,
            created_at: '2026-01-15 09:30:00',
            updated_at: '2026-01-15 09:30:00',
          },
        ];
      }
      return [];
    });

    const result = await getHealthProfileAction(db, 'U1', 1);

    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.profile.personal_info).toEqual({
        name: 'สมชาย',
        age: 34,
        gender: 'male',
        // Raw DECIMAL pass-through (mig-verify Phase 3 batch 1 fix) — PHP
        // never casts $profile['weight']/['height'], so the port must not
        // either. See query.ts's own comment at this field.
        weight: '68.50',
        height: '172.00',
        blood_type: 'O',
      });
      expect(result.profile.medical_conditions).toEqual(['diabetes', 'hypertension']);
      expect(result.profile.allergies).toHaveLength(1);
      expect(result.profile.medications).toHaveLength(1);
      // 6 personal fields + conditions + allergies + medications = 9/9 -> 100
      expect(result.profile.completion_percent).toBe(100);
    }
  });

  it('no existing row -> auto-INSERTs an empty profile, returns all-null/unknown shape, completion_percent 0', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM user_health_profiles')) return [];
      return [];
    });

    const result = await getHealthProfileAction(db, 'Ubrandnew', 1);

    expect(result).toEqual({
      success: true,
      profile: {
        personal_info: { name: null, age: null, gender: null, weight: null, height: null, blood_type: 'unknown' },
        medical_conditions: [],
        allergies: [],
        medications: [],
        completion_percent: 0,
        updated_at: null,
      },
    });
    expect(queries.some((q) => q.sql.includes('INSERT INTO user_health_profiles'))).toBe(true);
  });

  it('a thrown DB error anywhere in the flow -> Database error', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('connection lost');
    });
    const result = await getHealthProfileAction(db, 'U1', 1);
    expect(result).toEqual({ success: false, error: 'Database error' });
  });

  it('malformed medical_conditions JSON decodes to [] rather than throwing', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM user_health_profiles')) {
        return [
          {
            id: 1,
            name: null,
            age: null,
            gender: null,
            weight: null,
            height: null,
            blood_type: 'unknown',
            medical_conditions: 'not-json',
            updated_at: null,
          },
        ];
      }
      return [];
    });

    const result = await getHealthProfileAction(db, 'U1', 1);
    expect(result).toMatchObject({ success: true, profile: { medical_conditions: [] } });
  });
});
