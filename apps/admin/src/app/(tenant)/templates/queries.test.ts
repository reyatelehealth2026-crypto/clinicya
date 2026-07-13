import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getTemplatesData } from './queries';

describe('getTemplatesData', () => {
  it('selects all templates ordered by category, name with NO line_account_id filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, name: 'A', category: 'FAQ', messageType: 'text', content: 'hi', createdAt: new Date() },
    ]);
    await getTemplatesData(db);
    expect(queries[0]?.sql).toContain('FROM templates');
    expect(queries[0]?.sql).toContain('ORDER BY category, name');
    expect(queries[0]?.sql).not.toContain('line_account_id');
    expect(queries[0]?.params).toEqual([]);
  });

  it('derives categories via extractCategoryFilters from the returned rows', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, name: 'A', category: 'FAQ', messageType: 'text', content: '', createdAt: new Date() },
      { id: 2, name: 'B', category: 'FAQ', messageType: 'text', content: '', createdAt: new Date() },
      { id: 3, name: 'C', category: null, messageType: 'text', content: '', createdAt: new Date() },
      { id: 4, name: 'D', category: 'โปรโมชั่น', messageType: 'flex', content: '', createdAt: new Date() },
    ]);
    const result = await getTemplatesData(db);
    expect(result.templates).toHaveLength(4);
    expect(result.categories).toEqual(['FAQ', 'โปรโมชั่น']);
  });

  it('returns empty templates/categories when the table is empty', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getTemplatesData(db);
    expect(result).toEqual({ templates: [], categories: [] });
  });
});
