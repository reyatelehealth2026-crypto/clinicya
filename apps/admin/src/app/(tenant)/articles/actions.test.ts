const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
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

  it('resolves its own db via requireTenantPageContext and delegates to incrementArticleViewCount', async () => {
    const fakeDb = { marker: 'fake-db' };
    mockRequireTenantPageContext.mockResolvedValue({ db: fakeDb, session: { tenantId: 1 } });

    await incrementViewCountAction(42);

    expect(mockRequireTenantPageContext).toHaveBeenCalledTimes(1);
    expect(mockIncrementArticleViewCount).toHaveBeenCalledWith(fakeDb, 42);
  });

  it('fires once per call — two calls increment twice, matching "not deduped" legacy behavior', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ db: {}, session: { tenantId: 1 } });

    await incrementViewCountAction(7);
    await incrementViewCountAction(7);

    expect(mockIncrementArticleViewCount).toHaveBeenCalledTimes(2);
  });
});
