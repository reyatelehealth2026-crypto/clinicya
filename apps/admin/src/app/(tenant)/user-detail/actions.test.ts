import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from '../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

import { addPointsAction, updateUserInfoAction } from './actions';

function fakeSession(currentBotId: number | null = 1): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 1,
    tenantId: 1,
    currentBotId,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  currentBotId: number | null = 1
): { db: Kysely<TenantDB>; queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: fakeSession(currentBotId) });
  return { db, queries };
}

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    fd.set(k, v);
  }
  return fd;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('updateUserInfoAction', () => {
  it('UPDATEs every editable column and redirects with ?updated=1', async () => {
    const { queries } = wireFakeDb();
    await expect(
      updateUserInfoAction(
        7,
        formData({
          display_name: 'Somsri',
          real_name: 'Somsri Real',
          member_id: 'PC10000',
          phone: '0801234567',
          email: 'a@b.com',
          birthday: '1990-01-01',
          gender: 'female',
          address: '123 ถนน',
          province: 'Bangkok',
          postal_code: '10110',
          note: 'note text',
        })
      )
    ).rejects.toThrow('REDIRECT:/user-detail?id=7&updated=1');

    expect(queries[0]?.sql).toContain('UPDATE users SET');
    expect(queries[0]?.sql).toContain('display_name');
    expect(queries[0]?.sql).toContain('note');
    expect(queries[0]?.params).toEqual([
      'Somsri',
      'Somsri Real',
      'PC10000',
      '0801234567',
      'a@b.com',
      '1990-01-01',
      'female',
      '123 ถนน',
      'Bangkok',
      '10110',
      'note text',
      7,
    ]);
    expect(mockRedirect).toHaveBeenCalledWith('/user-detail?id=7&updated=1');
  });

  it('stores an empty/missing member_id as NULL (not an empty string)', async () => {
    const { queries } = wireFakeDb();
    await expect(updateUserInfoAction(7, formData({}))).rejects.toThrow('REDIRECT:');
    expect(queries[0]?.params[2]).toBeNull();
  });
});

describe('addPointsAction', () => {
  it('does nothing (no DB writes) and still redirects when points is 0', async () => {
    const { queries } = wireFakeDb();
    await expect(addPointsAction(7, formData({ points: '0' }))).rejects.toThrow('REDIRECT:/user-detail?id=7&points_updated=1');
    expect(queries).toHaveLength(0);
  });

  it('adds points and recomputes member_tier for a positive delta', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM points_settings')) {
        return [{ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 0, isActive: 1 }];
      }
      if (sqlText.includes('SELECT points, total_points')) {
        return [{ points: 0, totalPoints: 1100 }];
      }
      return [];
    });

    await expect(addPointsAction(7, formData({ points: '100', description: 'โบนัส' }))).rejects.toThrow(
      'REDIRECT:/user-detail?id=7&points_updated=1'
    );

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.sql).toContain("'earn'");
    expect(insertQuery?.params).toEqual([7, 1, 100, 100, 'admin', null, 'โบนัส', null]);

    const tierUpdate = queries.find((q) => q.sql.includes('UPDATE users SET member_tier'));
    expect(tierUpdate?.params).toEqual(['silver', 7]); // default tiers: silver at 1000+
  });

  it('deducts points for a negative delta (deductPoints, reference_type=admin_deduct)', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
        return [{ totalPoints: 200, availablePoints: 200, usedPoints: 0 }];
      }
      if (sqlText.includes('SELECT points, total_points')) {
        return [{ points: 0, totalPoints: 200 }];
      }
      return [];
    });

    await expect(addPointsAction(7, formData({ points: '-50', description: 'หักแต้ม' }))).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.sql).toContain("'redeem'");
    expect(insertQuery?.params).toEqual([7, 1, -50, 150, 'admin_deduct', null, 'หักแต้ม']);
  });

  it('silently no-ops the deduction (still redirects, still recomputes tier) when available points are insufficient', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
        return [{ totalPoints: 10, availablePoints: 10, usedPoints: 0 }];
      }
      if (sqlText.includes('SELECT points, total_points')) {
        return [{ points: 0, totalPoints: 10 }];
      }
      return [];
    });

    await expect(addPointsAction(7, formData({ points: '-999' }))).rejects.toThrow('REDIRECT:/user-detail?id=7&points_updated=1');

    expect(queries.some((q) => q.sql.includes('INSERT INTO points_transactions'))).toBe(false);
    expect(queries.some((q) => q.sql.includes('UPDATE users SET available_points'))).toBe(false);
    // The tier recompute still runs unconditionally, mirroring user-detail.php's handler.
    expect(queries.some((q) => q.sql.includes('UPDATE users SET member_tier'))).toBe(true);
  });

  it('defaults description to "เพิ่มแต้มโดยแอดมิน" and line_account_id to session.currentBotId ?? 1', async () => {
    const { queries } = wireFakeDb(
      (sqlText) =>
        sqlText.includes('FROM points_settings') ? [{ pointsPerBaht: 0.001, minOrderForPoints: 0, pointsExpiryDays: 0, isActive: 1 }] : [],
      null
    );
    await expect(addPointsAction(7, formData({ points: '10' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO points_transactions'));
    expect(insertQuery?.params).toEqual([7, 1, 10, 10, 'admin', null, 'เพิ่มแต้มโดยแอดมิน', null]);
  });
});
