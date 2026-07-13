import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { calculatePoints, addPoints, getUserPoints, loadPointsSettings } from './loyalty';

describe('calculatePoints', () => {
  it('floors amount * pointsPerBaht', () => {
    expect(calculatePoints({ pointsPerBaht: 0.1, minOrderForPoints: 0, pointsExpiryDays: 365, isActive: 1 }, 99)).toBe(9);
  });
  it('returns 0 when settings are inactive', () => {
    expect(calculatePoints({ pointsPerBaht: 1, minOrderForPoints: 0, pointsExpiryDays: 365, isActive: 0 }, 100)).toBe(0);
  });
  it('returns 0 when amount is below the minimum order threshold', () => {
    expect(calculatePoints({ pointsPerBaht: 1, minOrderForPoints: 500, pointsExpiryDays: 365, isActive: 1 }, 100)).toBe(0);
  });
});

describe('loadPointsSettings', () => {
  it('returns the default settings on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    const settings = await loadPointsSettings(db, 1);
    expect(settings).toEqual({ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 365, isActive: 1 });
  });
});

describe('getUserPoints', () => {
  it('reads from points_transactions when it has a non-zero available balance', async () => {
    const { db } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('FROM points_transactions') ? [{ totalPoints: 100, availablePoints: 80, usedPoints: 20 }] : []
    );
    const result = await getUserPoints(db, 1);
    expect(result).toEqual({ totalPoints: 100, availablePoints: 80, usedPoints: 20 });
  });

  it('falls back to the users table when points_transactions has zero available points', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions')) return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0 }];
      if (sqlText.includes('FROM users')) return [{ totalPoints: 50, availablePoints: 50, usedPoints: 0, points: null }];
      return [];
    });
    const result = await getUserPoints(db, 1);
    expect(result.availablePoints).toBe(50);
  });

  it('never returns a negative availablePoints', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('FROM points_transactions') ? [{ totalPoints: 0, availablePoints: -10, usedPoints: 0 }] : []));
    const result = await getUserPoints(db, 1);
    expect(result.availablePoints).toBe(0);
  });
});

describe('addPoints', () => {
  it('no-ops for points <= 0, matching `if ($points <= 0) return false;`', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await addPoints(db, 1, 0, 'claim', null, null, 1);
    expect(result.ok).toBe(false);
    expect(queries).toHaveLength(0);
  });

  it('updates users.total_points/available_points, writes a tier update, and inserts a points_transactions row', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0 }];
      if (sqlText.includes('FROM users WHERE id')) return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0, points: 0 }];
      if (sqlText.includes('FROM tier_settings')) return [];
      return [];
    });
    const result = await addPoints(db, 42, 20, 'claim', 9001, 'test', 7);
    expect(result).toEqual({ ok: true, newBalance: 20 });

    expect(queries.some((q) => q.sql.includes('UPDATE users SET total_points') && q.params.includes(20))).toBe(true);
    expect(queries.some((q) => q.sql.includes('UPDATE users SET member_tier'))).toBe(true);
    expect(queries.some((q) => q.sql.includes("INSERT INTO points_transactions") && q.sql.includes("'earn'") && q.params.includes(20))).toBe(true);
  });
});
