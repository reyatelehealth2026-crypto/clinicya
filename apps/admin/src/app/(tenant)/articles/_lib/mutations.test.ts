import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { incrementArticleViewCount } from './mutations';

describe('incrementArticleViewCount', () => {
  it('runs UPDATE health_articles SET view_count = view_count + 1 WHERE id = ?', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    await incrementArticleViewCount(db, 42);
    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain('UPDATE health_articles');
    expect(queries[0]?.sql).toContain('view_count = view_count + 1');
    expect(queries[0]?.params).toEqual([42]);
  });

  it('swallows a query error instead of throwing, matching PHP\'s catch (PDOException $e) {}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(incrementArticleViewCount(db, 42)).resolves.toBeUndefined();
  });
});
