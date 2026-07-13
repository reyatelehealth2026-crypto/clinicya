/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { getPointsHistoryAction } from './query';

describe('getPointsHistoryAction', () => {
  it('missing line_user_id -> Missing line_user_id, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await getPointsHistoryAction(db, null, null, 20);
    expect(result).toEqual({ success: false, error: 'Missing line_user_id' });
    expect(queries).toHaveLength(0);
  });

  it('user not found (both scoped and unscoped queries empty) -> User not found', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => []);
    const result = await getPointsHistoryAction(db, 'Unomatch', 1, 20);
    expect(result).toEqual({ success: false, error: 'User not found' });
  });

  it('line_account_id scoped lookup hits -> uses that row, never falls back to the unscoped query', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('AND line_account_id')) {
        return [
          {
            id: 1,
            line_account_id: 1,
            total_points: 100,
            available_points: 80,
            used_points: 20,
            points: 0,
            display_name: 'A',
          },
        ];
      }
      if (sqlText.includes('FROM points_transactions')) return [];
      return [];
    });

    const result = await getPointsHistoryAction(db, 'U1', 1, 20);
    expect(result).toMatchObject({ success: true, user: { name: 'A', available_points: 80 } });
    // Only one `FROM users` query (the scoped one) should have run — never the unscoped fallback.
    const userQueries = queries.filter((q) => q.sql.includes('FROM users'));
    expect(userQueries).toHaveLength(1);
  });

  it('line_account_id scoped lookup misses -> falls back to the unscoped query (legacy rows)', async () => {
    let scopedCalls = 0;
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('AND line_account_id')) {
        scopedCalls++;
        return [];
      }
      if (sqlText.includes('FROM users')) {
        return [
          {
            id: 2,
            line_account_id: null,
            total_points: 50,
            available_points: 50,
            used_points: 0,
            points: 0,
            display_name: 'Legacy',
          },
        ];
      }
      return [];
    });

    const result = await getPointsHistoryAction(db, 'Ulegacy', 1, 20);
    expect(scopedCalls).toBe(1);
    expect(result).toMatchObject({ success: true, user: { name: 'Legacy' } });
  });

  it('available_points empty but legacy `points` column has a value -> both available_points and total_points fall back to it', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [
          {
            id: 3,
            line_account_id: null,
            total_points: 0,
            available_points: 0,
            used_points: 5,
            points: 77,
            display_name: 'Fallback',
          },
        ];
      }
      return [];
    });

    const result = await getPointsHistoryAction(db, 'U3', null, 20);
    expect(result).toMatchObject({
      success: true,
      user: { available_points: 77, total_points: 77, used_points: 5 },
    });
  });

  it('formats history rows: numeric coercion + d/m/Y H:i formatted_date', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [
          {
            id: 4,
            line_account_id: null,
            total_points: 10,
            available_points: 10,
            used_points: 0,
            points: 0,
            display_name: 'X',
          },
        ];
      }
      if (sqlText.includes('FROM points_transactions')) {
        return [
          {
            id: 99,
            type: 'earn',
            points: 10,
            balance_after: 10,
            description: 'order bonus',
            reference_type: 'order',
            reference_id: 5,
            created_at: '2026-07-10 14:32:05',
          },
        ];
      }
      return [];
    });

    const result = await getPointsHistoryAction(db, 'U4', null, 20);
    expect(result).toMatchObject({
      success: true,
      history: [
        {
          id: 99,
          type: 'earn',
          points: 10,
          balance_after: 10,
          formatted_date: '10/07/2026 14:32',
        },
      ],
    });
    const historyQuery = queries.find((q) => q.sql.includes('FROM points_transactions'));
    expect(historyQuery?.params).toContain(20);
  });

  it('history rows with a null reference_id round-trip as null (not 0/NaN)', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [
          {
            id: 5,
            line_account_id: null,
            total_points: 1,
            available_points: 1,
            used_points: 0,
            points: 0,
            display_name: 'Y',
          },
        ];
      }
      if (sqlText.includes('FROM points_transactions')) {
        return [
          {
            id: 1,
            type: 'adjust',
            points: 1,
            balance_after: 1,
            description: null,
            reference_type: null,
            reference_id: null,
            created_at: '2026-01-01 00:00:00',
          },
        ];
      }
      return [];
    });

    const result = await getPointsHistoryAction(db, 'U5', null, 20);
    expect(result).toMatchObject({ success: true, history: [{ reference_id: null, description: null }] });
  });
});
