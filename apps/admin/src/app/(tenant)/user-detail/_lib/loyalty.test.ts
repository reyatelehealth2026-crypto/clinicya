import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  addPoints,
  calculateTier,
  deductPoints,
  getPointsHistory,
  getUserPoints,
  getUserTier,
  recomputeAndPersistMemberTier,
  updateUserTierColumn,
  type TierDef,
} from './loyalty';

describe('getUserPoints', () => {
  it('uses the points_transactions aggregate when it has a non-zero available balance', async () => {
    const { db } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('FROM points_transactions')
        ? [{ totalPoints: 150, availablePoints: 100, usedPoints: 50 }]
        : []
    );
    await expect(getUserPoints(db, 1)).resolves.toEqual({ totalPoints: 150, availablePoints: 100, usedPoints: 50 });
  });

  it('falls back to the users table when the aggregate is zero, using its available_points column', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions')) {
        return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0 }];
      }
      if (sqlText.includes('FROM users')) {
        return [{ totalPoints: 500, availablePoints: 300, usedPoints: 200, points: 0 }];
      }
      return [];
    });
    await expect(getUserPoints(db, 1)).resolves.toEqual({ totalPoints: 500, availablePoints: 300, usedPoints: 200 });
  });

  it('uses the legacy `points` column as a last-resort fallback when available_points is also empty', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions')) {
        return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0 }];
      }
      if (sqlText.includes('FROM users')) {
        return [{ totalPoints: null, availablePoints: null, usedPoints: 0, points: 77 }];
      }
      return [];
    });
    await expect(getUserPoints(db, 1)).resolves.toEqual({ totalPoints: 77, availablePoints: 77, usedPoints: 0 });
  });

  it('falls through to the (clamped) aggregate when the users-table fallback also has no positive balance', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions')) {
        return [{ totalPoints: 0, availablePoints: -5, usedPoints: 0 }];
      }
      if (sqlText.includes('FROM users')) {
        return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0, points: 0 }];
      }
      return [];
    });
    await expect(getUserPoints(db, 1)).resolves.toEqual({ totalPoints: 0, availablePoints: 0, usedPoints: 0 });
  });

  it('returns all-zero defaults when the aggregate query returns no row at all', async () => {
    const { db } = makeFakeTenantDb(() => []);
    await expect(getUserPoints(db, 1)).resolves.toEqual({ totalPoints: 0, availablePoints: 0, usedPoints: 0 });
  });
});

describe('getPointsHistory', () => {
  it('queries points_transactions ordered by created_at desc with the given limit', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, type: 'earn', points: 10, description: 'x', createdAt: new Date() },
    ]);
    const history = await getPointsHistory(db, 5, 5);
    expect(history).toHaveLength(1);
    expect(queries[0]?.sql).toContain('ORDER BY created_at DESC');
    expect(queries[0]?.params).toEqual([5, 5]);
  });
});

describe('addPoints', () => {
  it('is a no-op returning false for zero or negative points', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await expect(addPoints(db, 1, 0, null, null, null, 1)).resolves.toBe(false);
    await expect(addPoints(db, 1, -5, null, null, null, 1)).resolves.toBe(false);
    expect(queries).toHaveLength(0);
  });

  it('adds to both total_points and available_points, and inserts an earn points_transactions row with balance_after = new available balance', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
        return [{ totalPoints: 50, availablePoints: 50, usedPoints: 0 }];
      }
      if (sqlText.includes('FROM points_settings')) {
        return [{ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 0, isActive: 1 }];
      }
      return [];
    });

    const ok = await addPoints(db, 1, 100, 'admin', null, 'ทดสอบ', 1);
    expect(ok).toBe(true);

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE users'));
    expect(updateQuery?.sql).toContain('total_points = total_points +');
    expect(updateQuery?.sql).toContain('available_points = available_points +');
    expect(updateQuery?.params).toEqual([100, 100, 1]);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.sql).toContain("'earn'");
    // user_id, line_account_id, points, balance_after(=50+100), reference_type, reference_id, description, expires_at(null - pointsExpiryDays=0)
    expect(insertQuery?.params).toEqual([1, 1, 100, 150, 'admin', null, 'ทดสอบ', null]);
  });

  it('sets a non-null expires_at when points_expiry_days > 0', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
        return [{ totalPoints: 0, availablePoints: 0, usedPoints: 0 }];
      }
      if (sqlText.includes('FROM points_settings')) {
        return [{ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 365, isActive: 1 }];
      }
      return [];
    });
    await addPoints(db, 1, 10, null, null, null, 1);
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    const expiresAt = insertQuery?.params.at(-1);
    expect(typeof expiresAt).toBe('string');
    expect(expiresAt).not.toBeNull();
  });

  it('defaults the description to "Earned N points" when none is given', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('FROM points_settings') ? [{ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 0, isActive: 1 }] : []
    );
    await addPoints(db, 1, 20, null, null, null, 1);
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.params).toContain('Earned 20 points');
  });
});

describe('deductPoints', () => {
  it('is a no-op returning false for zero/negative points', async () => {
    const { db } = makeFakeTenantDb(() => []);
    await expect(deductPoints(db, 1, 0, null, null, null, 1)).resolves.toBe(false);
  });

  it('returns false (no write) when the user does not have enough available points', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('FROM points_transactions') && sqlText.includes('SUM') ? [{ totalPoints: 10, availablePoints: 10, usedPoints: 0 }] : []
    );
    const ok = await deductPoints(db, 1, 50, 'admin_deduct', null, null, 1);
    expect(ok).toBe(false);
    expect(queries.some((q) => q.sql.includes('UPDATE') || q.sql.includes('INSERT'))).toBe(false);
  });

  it('deducts available_points, credits used_points, and inserts a redeem row with negative points', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('FROM points_transactions') && sqlText.includes('SUM') ? [{ totalPoints: 100, availablePoints: 100, usedPoints: 0 }] : []
    );
    const ok = await deductPoints(db, 1, 30, 'admin_deduct', null, 'หักแต้ม', 1);
    expect(ok).toBe(true);

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE users'));
    expect(updateQuery?.sql).toContain('available_points = available_points -');
    expect(updateQuery?.sql).toContain('used_points = used_points +');
    expect(updateQuery?.params).toEqual([30, 30, 1]);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.sql).toContain("'redeem'");
    expect(insertQuery?.sql).not.toContain('expires_at');
    expect(insertQuery?.params).toEqual([1, 1, -30, 70, 'admin_deduct', null, 'หักแต้ม']);
  });
});

describe('calculateTier', () => {
  const tiers: TierDef[] = [
    { tierCode: 'bronze', tierName: 'Bronze', minPoints: 0, color: '#CD7F32', icon: '🥉', discountPercent: 0 },
    { tierCode: 'silver', tierName: 'Silver', minPoints: 1000, color: '#C0C0C0', icon: '🥈', discountPercent: 3 },
    { tierCode: 'gold', tierName: 'Gold', minPoints: 5000, color: '#FFD700', icon: '🥇', discountPercent: 5 },
  ];

  it('picks the highest tier whose min_points the given points satisfy', () => {
    expect(calculateTier(tiers, 0).tierCode).toBe('bronze');
    expect(calculateTier(tiers, 999).tierCode).toBe('bronze');
    expect(calculateTier(tiers, 1000).tierCode).toBe('silver');
    expect(calculateTier(tiers, 4999).tierCode).toBe('silver');
    expect(calculateTier(tiers, 5000).tierCode).toBe('gold');
    expect(calculateTier(tiers, 999999).tierCode).toBe('gold');
  });

  it('reports the next tier name/points-to-next for a non-max tier', () => {
    const result = calculateTier(tiers, 500);
    expect(result.nextTierCode).toBe('silver');
    expect(result.pointsToNext).toBe(500);
    expect(result.progressPercent).toBe(50);
  });

  it('reports "Max Level" with no next tier at the top tier', () => {
    const result = calculateTier(tiers, 10000);
    expect(result.nextTierCode).toBeNull();
    expect(result.nextTierName).toBe('Max Level');
    expect(result.progressPercent).toBe(100);
  });
});

describe('getUserTier', () => {
  it('uses total_points (falling back to points) as the tier basis', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [{ points: 10, totalPoints: 6000 }];
      }
      if (sqlText.includes('FROM tier_settings')) {
        return [];
      }
      return [];
    });
    const tier = await getUserTier(db, 1, 1);
    expect(tier.tierCode).toBe('gold'); // default tiers: gold at 5000+
  });
});

describe('updateUserTierColumn / recomputeAndPersistMemberTier', () => {
  it('writes users.member_tier from the computed tier code', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await updateUserTierColumn(db, 1, 'gold');
    expect(queries[0]?.sql).toContain('UPDATE users SET member_tier');
    expect(queries[0]?.params).toEqual(['gold', 1]);
  });

  it('swallows a DB error (e.g. member_tier column missing on this tenant) without throwing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('Unknown column member_tier');
    });
    await expect(updateUserTierColumn(db, 1, 'gold')).resolves.toBeUndefined();
  });

  it('recomputeAndPersistMemberTier recomputes from users.total_points and writes member_tier in one call', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT points, total_points')) {
        return [{ points: 0, totalPoints: 1500 }];
      }
      return [];
    });
    await recomputeAndPersistMemberTier(db, 1, 1);
    const updateQuery = queries.find((q) => q.sql.includes('UPDATE users SET member_tier'));
    expect(updateQuery?.params).toEqual(['silver', 1]); // default tiers: silver at 1000+
  });
});
