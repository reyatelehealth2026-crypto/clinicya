import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  APPOINTMENT_ID_FORMAT_REGEX,
  AppointmentsAvailableSlotsQuerySchema,
  AppointmentsAvailableSlotsResponseSchema,
  AppointmentsBookRequestSchema,
  AppointmentsBookResponseSchema,
  AppointmentsCancelRequestSchema,
  AppointmentsCancelResponseSchema,
  AppointmentsMyAppointmentsQuerySchema,
  AppointmentsMyAppointmentsResponseSchema,
  AppointmentsPharmacistsQuerySchema,
  AppointmentsPharmacistsResponseSchema,
} from '../src/appointments';

const FIXTURES_DIR = join(__dirname, '../fixtures/appointments');

function loadFixture(name: string): { request: unknown; response: unknown; status: number } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('appointments contracts — golden fixture round-trip', () => {
  it('pharmacists: ok, one row with every optional field populated, one with nulls defaulted', () => {
    const fx = loadFixture('pharmacists-ok.json');
    expect(AppointmentsPharmacistsQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = AppointmentsPharmacistsResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, message: 'OK' });
    if (parsed.success) {
      expect(parsed.pharmacists).toHaveLength(2);
      // Defaulted fields — never null on the wire, even for the "blank profile" row.
      expect(parsed.pharmacists[1]).toMatchObject({ title: '', specialty: 'เภสัชกร', rating: 5, review_count: 0 });
      expect(parsed.pharmacists.every((p) => Array.isArray(p.insurances) && p.insurances.length === 0)).toBe(true);
    }
  });

  it('available_slots: ok — normal weekday, one booked slot', () => {
    const fx = loadFixture('available-slots-ok.json');
    expect(AppointmentsAvailableSlotsQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = AppointmentsAvailableSlotsResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, message: 'OK' });
  });

  it('available_slots: SUBTLE TRAP — holiday branch overrides message to วันหยุด, never OK', () => {
    const fx = loadFixture('available-slots-holiday.json');
    const parsed = AppointmentsAvailableSlotsResponseSchema.parse(fx.response);
    expect(parsed).toEqual({ success: true, message: 'วันหยุด', slots: [] });
    // A port that regressed to the naive "OK" message would fail this specific assertion.
    expect(parsed).not.toMatchObject({ message: 'OK' });
  });

  it('available_slots: SUBTLE TRAP — no-schedule branch overrides message to ไม่มีตารางในวันนี้, never OK', () => {
    const fx = loadFixture('available-slots-no-schedule.json');
    const parsed = AppointmentsAvailableSlotsResponseSchema.parse(fx.response);
    expect(parsed).toEqual({ success: true, message: 'ไม่มีตารางในวันนี้', slots: [] });
  });

  it('book: ok — appointment_id matches the APT+15-digit format', () => {
    const fx = loadFixture('book-ok.json');
    expect(AppointmentsBookRequestSchema.parse(fx.request)).toBeTruthy();
    const parsed = AppointmentsBookResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, message: 'จองนัดหมายสำเร็จ!' });
    if (parsed.success) {
      expect(parsed.appointment_id).toMatch(APPOINTMENT_ID_FORMAT_REGEX);
      expect(parsed.appointment_id).toHaveLength(18); // 'APT' + 15 digits
    }
  });

  it('book: slot already taken -> flat failure', () => {
    const fx = loadFixture('book-slot-taken.json');
    const parsed = AppointmentsBookResponseSchema.parse(fx.response);
    expect(parsed).toEqual({ success: false, message: 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น' });
  });

  it('my_appointments: ok — upcoming/past split, all is the unsplit union', () => {
    const fx = loadFixture('my-appointments-ok.json');
    expect(AppointmentsMyAppointmentsQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = AppointmentsMyAppointmentsResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, message: 'OK' });
    if (parsed.success) {
      expect(parsed.upcoming).toHaveLength(1);
      expect(parsed.past).toHaveLength(1);
      expect(parsed.all).toHaveLength(2);
      expect(parsed.upcoming[0]?.status).toBe('confirmed');
      expect(parsed.past[0]?.status).toBe('completed');
    }
  });

  it('cancel: ok', () => {
    const fx = loadFixture('cancel-ok.json');
    expect(AppointmentsCancelRequestSchema.parse(fx.request)).toBeTruthy();
    expect(AppointmentsCancelResponseSchema.parse(fx.response)).toEqual({
      success: true,
      message: 'ยกเลิกนัดหมายสำเร็จ',
    });
  });

  it('cancel: DEAD-CODE-PRESERVED — cancelling 5 minutes before the appointment still succeeds (the "2 hours" comment is not enforced anywhere)', () => {
    const fx = loadFixture('cancel-dead-code-hours-gate-not-enforced.json');
    const parsed = AppointmentsCancelResponseSchema.parse(fx.response);
    expect(parsed).toEqual({ success: true, message: 'ยกเลิกนัดหมายสำเร็จ' });
  });

  it('cancel: already-passed appointment -> the ONE real gate that is enforced', () => {
    const fx = loadFixture('cancel-already-passed.json');
    const parsed = AppointmentsCancelResponseSchema.parse(fx.response);
    expect(parsed).toEqual({ success: false, message: 'ไม่สามารถยกเลิกนัดหมายที่ผ่านไปแล้ว' });
  });
});
