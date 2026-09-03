const mockRequirePublicTenantContext = jest.fn();
jest.mock('@/lib/tenant/publicTenantPageContext', () => ({
  requirePublicTenantContext: () => mockRequirePublicTenantContext(),
}));

const mockIncrementArticleViewCount = jest.fn();
jest.mock('./_lib/mutations', () => ({
  incrementArticleViewCount: (...args: unknown[]) => mockIncrementArticleViewCount(...args),
}));

import { incrementViewCountAction } from './actions';

describe('incrementViewCountAction', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('resolves its own db via requirePublicTenantContext and delegates to incrementArticleViewCount', async () => {
    const fakeDb = { marker: 'fake-db' };
    mockRequirePublicTenantContext.mockResolvedValue({ db: fakeDb, session: { tenantId: 1 } });

    await incrementViewCountAction(42);

    expect(mockRequirePublicTenantContext).toHaveBeenCalledTimes(1);
    expect(mockIncrementArticleViewCount).toHaveBeenCalledWith(fakeDb, 42);
  });

  it('fires once per call — two calls increment twice, matching "not deduped" legacy behavior', async () => {
    mockRequirePublicTenantContext.mockResolvedValue({ db: {}, session: { tenantId: 1 } });

    await incrementViewCountAction(7);
    await incrementViewCountAction(7);

    expect(mockIncrementArticleViewCount).toHaveBeenCalledTimes(2);
  });
});
