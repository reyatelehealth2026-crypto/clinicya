import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (...args: unknown[]) => mockRevalidatePath(...args),
}));

import { createTemplateAction, updateTemplateAction, deleteTemplateAction } from './actions';

beforeEach(() => {
  jest.clearAllMocks();
});

describe('createTemplateAction', () => {
  it('INSERTs the four raw fields in PHP column order and revalidates /templates', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db });

    const result = await createTemplateAction({ name: 'ทักทาย', category: 'ทั่วไป', messageType: 'text', content: 'สวัสดี' });

    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('INSERT INTO templates');
    expect(queries[0]?.sql).toContain('name');
    expect(queries[0]?.sql).toContain('category');
    expect(queries[0]?.sql).toContain('message_type');
    expect(queries[0]?.sql).toContain('content');
    expect(queries[0]?.params).toEqual(['ทักทาย', 'ทั่วไป', 'text', 'สวัสดี']);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/templates');
  });
});

describe('updateTemplateAction', () => {
  it('UPDATEs by id with the four fields, id bound last (matching `WHERE id=?`)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db });

    const result = await updateTemplateAction(7, { name: 'A', category: 'B', messageType: 'flex', content: '{}' });

    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('UPDATE templates SET');
    expect(queries[0]?.params).toEqual(['A', 'B', 'flex', '{}', 7]);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/templates');
  });
});

describe('deleteTemplateAction', () => {
  it('DELETEs by id and revalidates /templates', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db });

    const result = await deleteTemplateAction(9);

    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('DELETE FROM templates WHERE id');
    expect(queries[0]?.params).toEqual([9]);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/templates');
  });
});
