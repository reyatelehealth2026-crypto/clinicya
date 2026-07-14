import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (...args: unknown[]) => mockRevalidatePath(...args),
}));

import { savePharmacistAction, deletePharmacistAction, addHolidayAction, deleteHolidayAction } from './actions';

const SESSION = { adminUserId: 42, username: 'admin1', currentBotId: 7 };

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const handle = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db: handle.db, session: SESSION });
  return handle;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('savePharmacistAction', () => {
  it('INSERTs a new pharmacist (no id), replaces schedules (delete-then-insert, non-empty days only), and logs one activity_logs row', async () => {
    const { queries } = wireDb((sqlText) => {
      if (sqlText.includes('INSERT INTO pharmacists')) {
        return { insertId: 501, affectedRows: 1 };
      }
      return { affectedRows: 1 };
    });

    const result = await savePharmacistAction({
      name: 'สมหญิง',
      title: 'ภญ.',
      specialty: 'เภสัชกรคลินิก',
      licenseNo: 'LIC-9',
      hospital: 'รพ.บี',
      bio: '',
      imageUrl: '',
      consultationFee: 200,
      consultationDuration: 20,
      isAvailable: true,
      isActive: true,
      schedules: {
        0: { start: '', end: '' }, // empty day -> skipped
        1: { start: '09:00', end: '17:00' },
        2: { start: '09:00', end: '' }, // only one side filled -> skipped
      },
    });

    expect(result).toEqual({ success: true, id: 501 });

    const insertPharmacist = queries.find((q) => q.sql.includes('INSERT INTO pharmacists'));
    expect(insertPharmacist?.params).toEqual(['สมหญิง', 'ภญ.', 'เภสัชกรคลินิก', 'LIC-9', 'รพ.บี', '', '', 200, 20, 1, 1]);

    const deleteIdx = queries.findIndex((q) => q.sql.includes('DELETE FROM pharmacist_schedules'));
    const insertScheduleIdx = queries.findIndex((q) => q.sql.includes('INSERT INTO pharmacist_schedules'));
    expect(deleteIdx).toBeGreaterThanOrEqual(0);
    expect(insertScheduleIdx).toBeGreaterThan(deleteIdx); // replace, not append: delete runs before any insert

    const scheduleInserts = queries.filter((q) => q.sql.includes('INSERT INTO pharmacist_schedules'));
    expect(scheduleInserts).toHaveLength(1); // only day 1 had both start AND end
    // `is_available` is a literal `1` in the SQL text (mirrors PHP's own
    // `VALUES (?, ?, ?, ?, 1)`, line 49), not a bound param.
    expect(scheduleInserts[0]?.sql).toMatch(/VALUES\s*\(\?, \?, \?, \?, 1\)/);
    expect(scheduleInserts[0]?.params).toEqual([501, 1, '09:00', '17:00']);

    const logQuery = queries.find((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(logQuery).toBeDefined();
    expect(logQuery?.params).toEqual(['pharmacy', 'update', 'แก้ไขข้อมูลเภสัชกร', 42, 'admin1', 'pharmacist', 501, JSON.stringify({ name: 'สมหญิง', license_no: 'LIC-9', specialty: 'เภสัชกรคลินิก' }), 7]);
    // Exactly one activity_logs INSERT per save.
    expect(queries.filter((q) => q.sql.includes('INSERT INTO activity_logs'))).toHaveLength(1);

    expect(mockRevalidatePath).toHaveBeenCalledWith('/pharmacists');
  });

  it('UPDATEs an existing pharmacist by id, still logs action=\'update\' (PHP quirk: `$id` is always truthy by the logging line, on BOTH create and update paths — see actions.ts module doc)', async () => {
    const { queries } = wireDb(() => ({ affectedRows: 1 }));

    const result = await savePharmacistAction({
      id: 7,
      name: 'สมชาย',
      title: 'ภก.',
      isAvailable: false,
      isActive: false,
    });

    expect(result).toEqual({ success: true, id: 7 });
    const updateQuery = queries.find((q) => q.sql.includes('UPDATE pharmacists SET'));
    expect(updateQuery?.params).toEqual(['สมชาย', 'ภก.', '', '', '', '', '', 0, 15, 0, 0, 7]);

    // No `schedules` key on input -> no delete/insert on pharmacist_schedules at all.
    expect(queries.some((q) => q.sql.includes('pharmacist_schedules'))).toBe(false);

    const logQuery = queries.find((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(logQuery?.params).toEqual(['pharmacy', 'update', 'แก้ไขข้อมูลเภสัชกร', 42, 'admin1', 'pharmacist', 7, JSON.stringify({ name: 'สมชาย', license_no: '', specialty: '' }), 7]);
  });
});

describe('deletePharmacistAction', () => {
  it('is blocked with the exact Thai guard message when a pending/confirmed future appointment exists, and writes NO rows', async () => {
    const { queries } = wireDb((sqlText) => {
      if (sqlText.includes('FROM appointments')) {
        return [{ count: 2 }];
      }
      return { affectedRows: 1 };
    });

    const result = await deletePharmacistAction(3);

    expect(result).toEqual({ success: false, error: 'ไม่สามารถลบได้ เนื่องจากมีนัดหมายที่รอดำเนินการ' });
    expect(queries.some((q) => q.sql.includes('DELETE FROM pharmacists'))).toBe(false);
    expect(queries.some((q) => q.sql.includes('activity_logs'))).toBe(false);
    expect(mockRevalidatePath).not.toHaveBeenCalled();

    const guardQuery = queries.find((q) => q.sql.includes('FROM appointments'));
    expect(guardQuery?.sql).toContain("status IN ('pending','confirmed')");
    expect(guardQuery?.sql).toContain('appointment_date >= CURDATE()');
    expect(guardQuery?.params).toEqual([3]);
  });

  it('deletes the row and logs one activity_logs row when there is no pending appointment', async () => {
    const { queries } = wireDb((sqlText) => {
      if (sqlText.includes('FROM appointments')) {
        return [{ count: 0 }];
      }
      return { affectedRows: 1 };
    });

    const result = await deletePharmacistAction(3);

    expect(result).toEqual({ success: true });
    const deleteQuery = queries.find((q) => q.sql.includes('DELETE FROM pharmacists'));
    expect(deleteQuery?.params).toEqual([3]);

    const logQueries = queries.filter((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(logQueries).toHaveLength(1);
    expect(logQueries[0]?.params).toEqual(['pharmacy', 'delete', 'ลบเภสัชกร', 42, 'admin1', 'pharmacist', 3, null, 7]);

    expect(mockRevalidatePath).toHaveBeenCalledWith('/pharmacists');
  });
});

describe('addHolidayAction', () => {
  it('INSERTs into pharmacist_holidays and logs exactly one activity_logs row (PHP\'s add_holiday branch never logs — this is a deliberate extension per the brief\'s "every mutating action" acceptance criterion; see actions.ts module doc)', async () => {
    const { queries } = wireDb(() => ({ affectedRows: 1 }));

    const result = await addHolidayAction({ pharmacistId: 5, holidayDate: '2026-08-15', reason: '  ลาพักร้อน  ' });

    expect(result).toEqual({ success: true });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO pharmacist_holidays'));
    expect(insertQuery?.params).toEqual([5, '2026-08-15', 'ลาพักร้อน']); // reason trimmed, per PHP's trim($_POST['reason'] ?? '')

    const logQueries = queries.filter((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(logQueries).toHaveLength(1);
    expect(logQueries[0]?.params).toEqual(['pharmacy', 'create', 'เพิ่มวันหยุดเภสัชกร', 42, 'admin1', 'pharmacist', 5, null, 7]);

    expect(mockRevalidatePath).toHaveBeenCalledWith('/pharmacists');
  });
});

describe('deleteHolidayAction', () => {
  it('DELETEs by holiday id only (matching PHP\'s WHERE clause exactly) and logs exactly one activity_logs row keyed to the passed-in pharmacistId', async () => {
    const { queries } = wireDb(() => ({ affectedRows: 1 }));

    const result = await deleteHolidayAction(21, 5);

    expect(result).toEqual({ success: true });
    const deleteQuery = queries.find((q) => q.sql.includes('DELETE FROM pharmacist_holidays'));
    expect(deleteQuery?.params).toEqual([21]); // pharmacistId never appears in the DELETE itself

    const logQueries = queries.filter((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(logQueries).toHaveLength(1);
    expect(logQueries[0]?.params).toEqual(['pharmacy', 'delete', 'ลบวันหยุดเภสัชกร', 42, 'admin1', 'pharmacist', 5, null, 7]);

    expect(mockRevalidatePath).toHaveBeenCalledWith('/pharmacists');
  });
});
