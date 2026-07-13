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

import { leaveGroupAction } from './actions';

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  return fd;
}

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
  return { queries };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('leaveGroupAction', () => {
  it('redirects to bare /line-groups (no message) when the group id does not resolve, matching PHP\'s `if ($group)` guard', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(leaveGroupAction(formData({ group_id: '999' }))).rejects.toThrow('REDIRECT:/line-groups');
    expect(mockRedirect).toHaveBeenCalledWith('/line-groups');
    // Only the SELECT ran — no UPDATE was attempted for a nonexistent group.
    expect(queries).toHaveLength(1);
  });

  it('updates is_active=0, left_at=NOW() and redirects with a success ?message= on the happy path (LINE API call deferred, not attempted)', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM line_groups WHERE id')) {
        return [{ id: 3, lineAccountId: 7, groupId: 'Cxxxx', groupType: 'group', groupName: 'หมอดี คลินิก' }];
      }
      return [];
    });

    await expect(leaveGroupAction(formData({ group_id: '3' }))).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE line_groups'));
    expect(updateQuery?.sql).toContain('is_active = 0');
    expect(updateQuery?.sql).toContain('left_at = NOW()');
    expect(updateQuery?.params).toEqual([3]);

    expect(mockRedirect).toHaveBeenCalledWith(`/line-groups?message=${encodeURIComponent('ออกจากกลุ่ม หมอดี คลินิก แล้ว')}`);
  });

  it('falls back to an empty group name in the success message when group_name is null', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('FROM line_groups WHERE id') ? [{ id: 3, lineAccountId: 7, groupId: 'C1', groupType: 'room', groupName: null }] : []
    );
    await expect(leaveGroupAction(formData({ group_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/line-groups?message=${encodeURIComponent('ออกจากกลุ่ม  แล้ว')}`);
  });

  it('redirects with a ?error= message when the DB update itself throws', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM line_groups WHERE id')) {
        return [{ id: 3, lineAccountId: 7, groupId: 'C1', groupType: 'group', groupName: 'X' }];
      }
      if (sqlText.includes('UPDATE line_groups')) {
        throw new Error('DB write failed');
      }
      return [];
    });
    mockRequireTenantPageContext.mockResolvedValue({ db });

    await expect(leaveGroupAction(formData({ group_id: '3' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/line-groups?error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });

  it('treats a missing/non-numeric group_id as 0, matching PHP\'s `$_POST[\'group_id\'] ?? 0`', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(leaveGroupAction(formData({}))).rejects.toThrow('REDIRECT:/line-groups');
    expect(queries[0]?.params).toEqual([0]);
  });
});
