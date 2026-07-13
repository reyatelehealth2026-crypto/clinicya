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

import { createGroupAction, updateGroupAction, deleteGroupAction, addMemberAction, removeMemberAction } from './actions';

function wireFakeDb(): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(() => []);
  mockRequireTenantPageContext.mockResolvedValue({ db });
  return { queries };
}

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  return fd;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('createGroupAction', () => {
  it('INSERTs name/description/color and redirects to bare /groups (no ?view=)', async () => {
    const { queries } = wireFakeDb();
    await expect(createGroupAction(formData({ name: 'VIP', description: 'desc', color: '#3B82F6' }))).rejects.toThrow('REDIRECT:/groups');
    expect(queries[0]?.sql).toContain('INSERT INTO groups');
    expect(queries[0]?.params).toEqual(['VIP', 'desc', '#3B82F6']);
    expect(mockRedirect).toHaveBeenCalledWith('/groups');
  });
});

describe('updateGroupAction', () => {
  it('UPDATEs by id and redirects to bare /groups', async () => {
    const { queries } = wireFakeDb();
    await expect(updateGroupAction(5, formData({ name: 'A', description: 'B', color: '#fff' }))).rejects.toThrow('REDIRECT:/groups');
    expect(queries[0]?.sql).toContain('UPDATE groups SET');
    expect(queries[0]?.params).toEqual(['A', 'B', '#fff', 5]);
  });
});

describe('deleteGroupAction', () => {
  it('DELETEs by id and redirects to bare /groups', async () => {
    const { queries } = wireFakeDb();
    await expect(deleteGroupAction(9)).rejects.toThrow('REDIRECT:/groups');
    expect(queries[0]?.sql).toContain('DELETE FROM groups WHERE id');
    expect(queries[0]?.params).toEqual([9]);
  });
});

describe('addMemberAction', () => {
  it('INSERT IGNOREs (user_id, group_id) WITHOUT setting line_account_id, and redirects with ?view=', async () => {
    const { queries } = wireFakeDb();
    await expect(addMemberAction(3, formData({ user_id: '42' }))).rejects.toThrow('REDIRECT:/groups?view=3');
    expect(queries[0]?.sql).toContain('INSERT IGNORE INTO user_groups (user_id, group_id)');
    expect(queries[0]?.sql).not.toContain('line_account_id');
    expect(queries[0]?.params).toEqual([42, 3]);
    expect(mockRedirect).toHaveBeenCalledWith('/groups?view=3');
  });
});

describe('removeMemberAction', () => {
  it('DELETEs by (user_id, group_id) and redirects with ?view=', async () => {
    const { queries } = wireFakeDb();
    await expect(removeMemberAction(3, 42)).rejects.toThrow('REDIRECT:/groups?view=3');
    expect(queries[0]?.sql).toContain('DELETE FROM user_groups WHERE user_id');
    expect(queries[0]?.params).toEqual([42, 3]);
  });
});
