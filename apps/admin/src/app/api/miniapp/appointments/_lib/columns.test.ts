/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { getExistingAppointmentColumns } from './columns';

describe('getExistingAppointmentColumns', () => {
  it('parses SHOW COLUMNS FROM appointments into a Set of Field names', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SHOW COLUMNS FROM appointments')) {
        // The committed tenant template's real column list (see this file's own doc comment) — no
        // appointment_id/end_time/duration/type/symptoms/consultation_fee/cancelled_by.
        return [
          { Field: 'id' },
          { Field: 'line_account_id' },
          { Field: 'user_id' },
          { Field: 'pharmacist_id' },
          { Field: 'appointment_type' },
          { Field: 'appointment_date' },
          { Field: 'appointment_time' },
          { Field: 'duration_minutes' },
          { Field: 'status' },
          { Field: 'notes' },
          { Field: 'reminder_sent' },
          { Field: 'created_at' },
          { Field: 'updated_at' },
          { Field: 'reminder_10min_sent' },
          { Field: 'reminder_now_sent' },
          { Field: 'cancelled_reason' },
        ];
      }
      return [];
    });

    const columns = await getExistingAppointmentColumns(db);

    expect(columns.has('line_account_id')).toBe(true);
    expect(columns.has('cancelled_reason')).toBe(true);
    expect(columns.has('appointment_id')).toBe(false);
    expect(columns.has('end_time')).toBe(false);
    expect(columns.has('duration')).toBe(false);
    expect(columns.has('type')).toBe(false);
    expect(columns.has('symptoms')).toBe(false);
    expect(columns.has('consultation_fee')).toBe(false);
    expect(columns.has('cancelled_by')).toBe(false);
  });

  it('returns an empty Set on a DB error (best-effort, mirrors PHP swallowing the check)', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('boom');
    });

    const columns = await getExistingAppointmentColumns(db);
    expect(columns.size).toBe(0);
  });
});
