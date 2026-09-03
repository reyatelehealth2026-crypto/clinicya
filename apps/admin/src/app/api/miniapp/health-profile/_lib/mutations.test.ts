/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import {
  addAllergyAction,
  addMedicationAction,
  removeAllergyAction,
  removeMedicationAction,
  updateMedicalHistoryAction,
  updatePersonalAction,
} from './mutations';

describe('updatePersonalAction', () => {
  it('missing line_user_id -> 400, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await updatePersonalAction(db, {});
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing line_user_id' } });
    expect(queries).toHaveLength(0);
  });

  it('age out of [0,150] -> 400 Invalid age, before any DB write', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await updatePersonalAction(db, { line_user_id: 'U1', age: 200 });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Invalid age' } });
    expect(queries).toHaveLength(0);
  });

  it('invalid gender -> 400 Invalid gender', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>();
    const result = await updatePersonalAction(db, { line_user_id: 'U1', gender: 'robot' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Invalid gender' } });
  });

  it('unrecognised blood_type silently falls back to unknown (not rejected)', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>(() => ({ insertId: 0, affectedRows: 1 }));
    const result = await updatePersonalAction(db, { line_user_id: 'U1', blood_type: 'Z' });
    expect(result.status).toBe(200);
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO user_health_profiles'));
    expect(insertQuery!.params).toContain('unknown');
  });

  it('success -> 200, ON DUPLICATE KEY UPDATE issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>(() => ({ insertId: 0, affectedRows: 1 }));
    const result = await updatePersonalAction(db, { line_user_id: 'U1', line_account_id: 1, name: 'สมชาย', age: 34, gender: 'male' });
    expect(result).toEqual({ status: 200, body: { success: true, message: 'บันทึกข้อมูลส่วนตัวแล้ว' } });
    expect(queries[0]!.sql).toContain('ON DUPLICATE KEY UPDATE');
  });

  it('DB error -> 500', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('connection lost');
    });
    const result = await updatePersonalAction(db, { line_user_id: 'U1' });
    expect(result).toEqual({ status: 500, body: { success: false, error: 'Database error' } });
  });
});

describe('updateMedicalHistoryAction', () => {
  it('unknown condition keys are silently dropped, not rejected', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>(() => ({ insertId: 0, affectedRows: 1 }));
    const result = await updateMedicalHistoryAction(db, {
      line_user_id: 'U1',
      conditions: ['diabetes', 'not_a_real_condition', 'hypertension'],
    });
    expect(result.status).toBe(200);
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO user_health_profiles'));
    const jsonParam = insertQuery!.params.find((p) => typeof p === 'string' && p.startsWith('['));
    expect(JSON.parse(jsonParam as string)).toEqual(['diabetes', 'hypertension']);
  });

  it('missing line_user_id -> 400', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>();
    const result = await updateMedicalHistoryAction(db, {});
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing line_user_id' } });
  });
});

describe('addAllergyAction', () => {
  it('missing drug_name -> 400 Missing required fields', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>();
    const result = await addAllergyAction(db, { line_user_id: 'U1' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing required fields' } });
  });

  it('duplicate drug_name for the same user+account -> 400 with the Thai duplicate message', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT id FROM user_drug_allergies')) return [{ id: 5 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await addAllergyAction(db, { line_user_id: 'U1', line_account_id: 1, drug_name: 'Penicillin' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว' } });
  });

  it('invalid reaction_type/severity fall back to defaults, insert succeeds', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT id FROM user_drug_allergies')) return [];
      if (sqlText.includes('INSERT INTO user_drug_allergies')) return { insertId: 12, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await addAllergyAction(db, {
      line_user_id: 'U1',
      line_account_id: 1,
      drug_name: 'Penicillin',
      reaction_type: 'nonsense',
      severity: 'nonsense',
    });
    expect(result.status).toBe(200);
    expect(result.body).toMatchObject({
      success: true,
      allergy: { id: 12, drug_name: 'Penicillin', reaction_type: 'other', severity: 'moderate' },
    });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO user_drug_allergies'));
    expect(insertQuery!.params).toContain('other');
    expect(insertQuery!.params).toContain('moderate');
  });
});

describe('removeAllergyAction', () => {
  it('SUBTLE TRAP: DELETE has no line_account_id predicate — only id + line_user_id', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>(() => ({ affectedRows: 1 }));
    const result = await removeAllergyAction(db, { line_user_id: 'U1', allergy_id: 12 });
    expect(result).toEqual({ status: 200, body: { success: true, message: 'ลบข้อมูลการแพ้ยาแล้ว' } });
    expect(queries[0]!.sql).toContain('DELETE FROM user_drug_allergies WHERE id = ? AND line_user_id = ?');
    expect(queries[0]!.sql).not.toContain('line_account_id');
  });

  it('missing allergy_id -> 400', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await removeAllergyAction(db, { line_user_id: 'U1' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing required fields' } });
    expect(queries).toHaveLength(0);
  });

  it('DB error -> 500, still succeeds without a rowCount check on the happy path (no existence verification)', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('boom');
    });
    const result = await removeAllergyAction(db, { line_user_id: 'U1', allergy_id: 999999 });
    expect(result).toEqual({ status: 500, body: { success: false, error: 'Database error' } });
  });
});

describe('addMedicationAction', () => {
  it('no existing medications -> checkMedicationInteractions short-circuits to [], no interactions/has_interactions keys on the response', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT medication_name, product_id FROM user_current_medications')) return [];
      if (sqlText.includes('INSERT INTO user_current_medications')) return { insertId: 30, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await addMedicationAction(db, { line_user_id: 'U1', medication_name: 'Metformin', dosage: '500mg', frequency: 'BID' });
    expect(result.status).toBe(200);
    expect(result.body).toEqual({
      success: true,
      message: 'เพิ่มยาที่ใช้ประจำแล้ว',
      medication: { id: 30, medication_name: 'Metformin', dosage: '500mg', frequency: 'BID' },
    });
    expect('interactions' in result.body).toBe(false);
    expect('has_interactions' in result.body).toBe(false);
    expect(queries.some((q) => q.sql.includes("SHOW TABLES LIKE 'drug_interactions'"))).toBe(false);
  });

  it('PRESERVED SCHEMA-MISMATCH BUG: existing medications + drug_interactions table present -> the di.drug1_id/di.drug2_id JOIN throws and is swallowed, interactions always []', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT medication_name, product_id FROM user_current_medications')) {
        return [{ medication_name: 'Warfarin', product_id: null }];
      }
      if (sqlText.includes("SHOW TABLES LIKE 'drug_interactions'")) return [{ Tables_in_x: 'drug_interactions' }];
      if (sqlText.includes('FROM drug_interactions di')) {
        throw new Error("Unknown column 'di.drug1_id' in 'on clause'");
      }
      if (sqlText.includes('INSERT INTO user_current_medications')) return { insertId: 31, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await addMedicationAction(db, { line_user_id: 'U1', medication_name: 'Metformin' });
    expect(result.status).toBe(200);
    expect('interactions' in result.body).toBe(false);
    expect('has_interactions' in result.body).toBe(false);
  });

  it('missing medication_name -> 400', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>();
    const result = await addMedicationAction(db, { line_user_id: 'U1' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing required fields' } });
  });
});

describe('removeMedicationAction', () => {
  it('soft delete: UPDATE is_active = 0, no line_account_id predicate', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>(() => ({ affectedRows: 1 }));
    const result = await removeMedicationAction(db, { line_user_id: 'U1', medication_id: 30 });
    expect(result).toEqual({ status: 200, body: { success: true, message: 'ลบยาออกจากรายการแล้ว' } });
    expect(queries[0]!.sql).toContain('UPDATE user_current_medications SET is_active = 0 WHERE id = ? AND line_user_id = ?');
  });

  it('missing medication_id -> 400', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>();
    const result = await removeMedicationAction(db, { line_user_id: 'U1' });
    expect(result).toEqual({ status: 400, body: { success: false, error: 'Missing required fields' } });
  });
});
