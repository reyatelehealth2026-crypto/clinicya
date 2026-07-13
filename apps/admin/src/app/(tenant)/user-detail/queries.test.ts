import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getUserDetailPageData, getUserTransactions } from './queries';

function wireUser(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    lineUserId: 'U123',
    displayName: 'Somsri',
    realName: null,
    memberId: null,
    phone: null,
    email: null,
    birthday: null,
    gender: null,
    address: null,
    province: null,
    postalCode: null,
    note: null,
    pictureUrl: null,
    statusMessage: null,
    isBlocked: 0,
    createdAt: new Date('2026-01-01'),
    weight: null,
    height: null,
    bloodType: null,
    medicalConditions: null,
    drugAllergies: null,
    lineAccountId: 1,
    ...overrides,
  };
}

describe('getUserDetailPageData', () => {
  it('returns null when the user id does not resolve to a row (mirrors the PHP redirect-to-users.php path)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    await expect(getUserDetailPageData(db, 999, 1)).resolves.toBeNull();
  });

  it('assembles user + tags + transactions + stats + points + tier + shop name for an existing user', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) {
        return [wireUser()];
      }
      if (sqlText.includes('FROM user_tags ut')) {
        return [{ id: 1, name: 'VIP', color: '#ff0000' }];
      }
      if (sqlText.includes('FROM user_tags\n') || sqlText.includes('FROM user_tags\nWHERE')) {
        return [{ id: 2, name: 'ทดสอบ', color: '#00ff00' }];
      }
      if (sqlText.includes('FROM transactions') && sqlText.includes('ORDER BY created_at DESC')) {
        return [{ id: 10, orderNumber: 'ORD-1', createdAt: new Date(), status: 'paid', grandTotal: '250.00', shippingName: 'Somsri' }];
      }
      if (sqlText.includes('FROM transaction_items')) {
        return [{ productName: 'Paracetamol', quantity: 2 }];
      }
      if (sqlText.includes('COUNT(*) AS cnt')) {
        return [{ cnt: 3, total: 900 }];
      }
      if (sqlText.includes('FROM messages')) {
        return [{ count: 42 }];
      }
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
        return [{ totalPoints: 500, availablePoints: 400, usedPoints: 100 }];
      }
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('ORDER BY created_at DESC')) {
        return [{ id: 1, type: 'earn', points: 100, description: 'x', createdAt: new Date() }];
      }
      if (sqlText.includes('SELECT points, total_points')) {
        return [{ points: 0, totalPoints: 500 }];
      }
      if (sqlText.includes('FROM tier_settings')) {
        return [];
      }
      if (sqlText.includes('FROM shop_settings')) {
        return [{ shopName: 'Reya Pharmacy' }];
      }
      return [];
    });

    const data = await getUserDetailPageData(db, 1, 1);
    expect(data).not.toBeNull();
    expect(data!.user.displayName).toBe('Somsri');
    expect(data!.orderCount).toBe(3);
    expect(data!.totalSpent).toBe(900);
    expect(data!.messageCount).toBe(42);
    expect(data!.points).toEqual({ totalPoints: 500, availablePoints: 400, usedPoints: 100 });
    expect(data!.shopName).toBe('Reya Pharmacy');
    expect(data!.transactions).toHaveLength(1);
    expect(data!.transactions[0]?.items).toEqual([{ productName: 'Paracetamol', quantity: 2 }]);
    expect(data!.health.hasUserHealth).toBe(false);
  });
});

describe('getUserTransactions', () => {
  it('fetches up to 3 items per order (LIMIT 3), one items query per order row', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions')) {
        return [
          { id: 1, orderNumber: 'A', createdAt: new Date(), status: 'paid', grandTotal: '10.00', shippingName: null },
          { id: 2, orderNumber: 'B', createdAt: new Date(), status: 'pending', grandTotal: '20.00', shippingName: null },
        ];
      }
      return [];
    });
    const rows = await getUserTransactions(db, 1);
    expect(rows).toHaveLength(2);
    const itemQueries = queries.filter((q) => q.sql.includes('FROM transaction_items'));
    expect(itemQueries).toHaveLength(2);
    expect(itemQueries[0]?.sql).toContain('LIMIT 3');
    expect(itemQueries.map((q) => q.params[0])).toEqual([1, 2]);
  });
});
