import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  MedicationRemindersAddRequestSchema,
  MedicationRemindersAddResponseSchema,
  MedicationRemindersDeleteRequestSchema,
  MedicationRemindersDeleteResponseSchema,
  MedicationRemindersListQuerySchema,
  MedicationRemindersListResponseSchema,
  MedicationRemindersMarkTakenRequestSchema,
  MedicationRemindersMarkTakenResponseSchema,
} from '../src/medication-reminders';

const FIXTURES_DIR = join(__dirname, '../fixtures/medication-reminders');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('medication-reminders contracts — golden fixture round-trip', () => {
  it('list: ok, with computed adherence_percent', () => {
    const { request, response } = loadFixture('list-ok.json');
    expect(MedicationRemindersListQuerySchema.parse(request)).toBeTruthy();
    const parsed = MedicationRemindersListResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.reminders[0]?.adherence_percent).toBe(86);
      expect(parsed.reminders[0]?.reminder_times).toEqual(['08:00', '20:00']);
    }
  });

  it('list: no user resolved -> {success:true, reminders:[]}', () => {
    const { request, response } = loadFixture('list-no-user.json');
    expect(MedicationRemindersListQuerySchema.parse(request)).toBeTruthy();
    expect(MedicationRemindersListResponseSchema.parse(response)).toEqual({ success: true, reminders: [] });
  });

  it('add: ok', () => {
    const { request, response } = loadFixture('add-ok.json');
    expect(MedicationRemindersAddRequestSchema.parse(request)).toBeTruthy();
    expect(MedicationRemindersAddResponseSchema.parse(response)).toEqual({
      success: true,
      reminder_id: 11,
      message: 'เพิ่มการเตือนทานยาแล้ว',
    });
  });

  it('add: empty medication_name -> `error` key (not `message`), Thai validation string', () => {
    const { request, response } = loadFixture('add-missing-medication-name.json');
    expect(MedicationRemindersAddRequestSchema.parse(request)).toBeTruthy();
    const parsed = MedicationRemindersAddResponseSchema.parse(response);
    expect(parsed).toEqual({ success: false, error: 'กรุณาระบุชื่อยา' });
    expect('message' in parsed).toBe(false);
  });

  it('delete: ok — NO ownership check, always succeeds (see contract doc comment trap)', () => {
    const { request, response } = loadFixture('delete-ok.json');
    expect(MedicationRemindersDeleteRequestSchema.parse(request)).toBeTruthy();
    expect(MedicationRemindersDeleteResponseSchema.parse(response)).toEqual({ success: true, message: 'ลบการเตือนแล้ว' });
  });

  it('mark_taken: ok — DOES verify ownership first (asymmetric with delete)', () => {
    const { request, response } = loadFixture('mark-taken-ok.json');
    expect(MedicationRemindersMarkTakenRequestSchema.parse(request)).toBeTruthy();
    expect(MedicationRemindersMarkTakenResponseSchema.parse(response)).toEqual({
      success: true,
      message: 'บันทึกการทานยาแล้ว',
    });
  });

  it('mark_taken: ownership check misses -> {success:false, error:"Reminder not found"}', () => {
    const { request, response } = loadFixture('mark-taken-not-found.json');
    expect(MedicationRemindersMarkTakenRequestSchema.parse(request)).toBeTruthy();
    const parsed = MedicationRemindersMarkTakenResponseSchema.parse(response);
    expect(parsed).toEqual({ success: false, error: 'Reminder not found' });
    expect('message' in parsed).toBe(false);
  });
});
