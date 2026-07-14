import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getPharmacistsData } from './queries';

describe('getPharmacistsData', () => {
  it('selects pharmacists ORDER BY name ASC (no sort_order column on this tenant template) with correlated appointment-count subqueries, and normalizes DECIMAL/COUNT strings to numbers', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) {
        return [
          {
            id: 1,
            title: 'ภก.',
            name: 'สมชาย',
            specialty: 'เภสัชกรคลินิก',
            licenseNo: 'LIC-001',
            hospital: 'รพ.เอ',
            bio: null,
            imageUrl: null,
            rating: '4.5',
            reviewCount: 10,
            consultationFee: '100.00',
            consultationDuration: 15,
            isAvailable: 1,
            isActive: 1,
            completedCount: '3',
            upcomingCount: '2',
          },
        ];
      }
      if (sqlText.includes('FROM pharmacist_schedules')) {
        return [{ id: 11, pharmacistId: 1, dayOfWeek: 1, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 }];
      }
      if (sqlText.includes('FROM pharmacist_holidays')) {
        return [{ id: 21, pharmacistId: 1, holidayDate: '2026-08-01', reason: 'ลาพักร้อน' }];
      }
      return [];
    });

    const result = await getPharmacistsData(db);

    const mainQuery = queries.find((q) => q.sql.includes('FROM pharmacists p'));
    expect(mainQuery?.sql).toContain('ORDER BY name ASC');
    expect(mainQuery?.sql).not.toContain('sort_order');
    expect(mainQuery?.sql).toContain("status = 'completed'");
    expect(mainQuery?.sql).toContain("status IN ('pending','confirmed')");
    expect(mainQuery?.sql).toContain('appointment_date >= CURDATE()');

    expect(result).toHaveLength(1);
    expect(result[0]).toMatchObject({
      id: 1,
      title: 'ภก.',
      name: 'สมชาย',
      specialty: 'เภสัชกรคลินิก',
      licenseNo: 'LIC-001',
      rating: 4.5,
      reviewCount: 10,
      consultationFee: 100,
      consultationDuration: 15,
      isAvailable: 1,
      isActive: 1,
      completedCount: 3,
      upcomingCount: 2,
    });
    expect(result[0]?.schedules).toEqual([{ id: 11, pharmacistId: 1, dayOfWeek: 1, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 }]);
    expect(result[0]?.holidays).toEqual([{ id: 21, pharmacistId: 1, holidayDate: '2026-08-01', reason: 'ลาพักร้อน' }]);
  });

  it('issues one pharmacist_schedules + one pharmacist_holidays sub-query PER pharmacist, scoped by pharmacist_id, holidays filtered to >= CURDATE() with LIMIT 5', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) {
        return [
          { id: 1, title: 'ภก.', name: 'A', specialty: null, licenseNo: null, hospital: null, bio: null, imageUrl: null, rating: null, reviewCount: null, consultationFee: null, consultationDuration: null, isAvailable: 1, isActive: 1, completedCount: 0, upcomingCount: 0 },
          { id: 2, title: 'ภญ.', name: 'B', specialty: null, licenseNo: null, hospital: null, bio: null, imageUrl: null, rating: null, reviewCount: null, consultationFee: null, consultationDuration: null, isAvailable: 1, isActive: 1, completedCount: 0, upcomingCount: 0 },
        ];
      }
      return [];
    });

    await getPharmacistsData(db);

    const scheduleQueries = queries.filter((q) => q.sql.includes('FROM pharmacist_schedules'));
    const holidayQueries = queries.filter((q) => q.sql.includes('FROM pharmacist_holidays'));
    expect(scheduleQueries).toHaveLength(2);
    expect(holidayQueries).toHaveLength(2);
    expect(holidayQueries.every((q) => q.sql.includes('holiday_date >= CURDATE()') && q.sql.includes('LIMIT 5'))).toBe(true);
    expect(scheduleQueries.map((q) => q.params)).toEqual(expect.arrayContaining([[1], [2]]));
    expect(holidayQueries.map((q) => q.params)).toEqual(expect.arrayContaining([[1], [2]]));
  });

  it('returns [] with no per-row sub-queries when there are no pharmacists', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await getPharmacistsData(db);
    expect(result).toEqual([]);
    expect(queries).toHaveLength(1);
  });

  it('defaults null numeric/text fields the same way PHP\'s `?? 0` / `?: ""` fallbacks do (consultationDuration -> 15, other numerics -> 0)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) {
        return [
          {
            id: 9,
            title: null,
            name: 'C',
            specialty: null,
            licenseNo: null,
            hospital: null,
            bio: null,
            imageUrl: null,
            rating: null,
            reviewCount: null,
            consultationFee: null,
            consultationDuration: null,
            isAvailable: null,
            isActive: null,
            completedCount: null,
            upcomingCount: null,
          },
        ];
      }
      return [];
    });

    const result = await getPharmacistsData(db);
    expect(result[0]).toMatchObject({
      title: '',
      specialty: '',
      rating: 0,
      reviewCount: 0,
      consultationFee: 0,
      consultationDuration: 15,
      isAvailable: 0,
      isActive: 0,
      completedCount: 0,
      upcomingCount: 0,
    });
  });
});
