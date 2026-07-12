jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

import { redirect } from 'next/navigation';
import type { TenantSession } from '@reya/auth';
import { requireAnalyticsPageContext } from './session';

const mockRedirect = redirect as unknown as jest.Mock;

const BASE_SESSION: TenantSession = {
  realm: 'tenant',
  sid: 'sid-123',
  adminUserId: 1,
  tenantId: 2,
  currentBotId: 1,
  role: 'admin',
  username: 'admin1',
  displayName: 'Admin One',
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-12T00:00:00.000Z',
  expiresAt: '2026-07-13T00:00:00.000Z',
};

describe('requireAnalyticsPageContext', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('resolves for an admin session without redirecting', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ session: BASE_SESSION, db: { fake: true } });
    const ctx = await requireAnalyticsPageContext();
    expect(ctx.session.role).toBe('admin');
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it('resolves for a super_admin session without redirecting', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ session: { ...BASE_SESSION, role: 'super_admin' }, db: {} });
    const ctx = await requireAnalyticsPageContext();
    expect(ctx.session.role).toBe('super_admin');
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it.each(['pharmacist', 'marketing', 'tech', 'staff'] as const)(
    'redirects to \'/\' (not the login page) for a %s session, matching analytics.php\'s header(\'Location: /\')',
    async (role) => {
      mockRequireTenantPageContext.mockResolvedValue({ session: { ...BASE_SESSION, role }, db: {} });
      await expect(requireAnalyticsPageContext()).rejects.toThrow();
      expect(mockRedirect).toHaveBeenCalledWith('/');
    }
  );

  it('propagates requireTenantPageContext\'s own login redirect when there is no session at all', async () => {
    mockRequireTenantPageContext.mockRejectedValue(new Error('unreachable — redirect() always throws'));
    await expect(requireAnalyticsPageContext()).rejects.toThrow();
    expect(mockRedirect).not.toHaveBeenCalled(); // the /auth/login redirect happened one layer down
  });
});
