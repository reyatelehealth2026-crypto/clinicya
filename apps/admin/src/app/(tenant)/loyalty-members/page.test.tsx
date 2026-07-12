import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

// AddPointsModal/MemberDetailModal ('use client') call useRouter() — no real Next App
// Router context exists under next/jest's plain jsdom render.
jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));

// actions.ts (imported transitively via MembersListClient -> AddPointsModal) imports
// next/cache's revalidatePath at module scope — the real module pulls in Next server
// internals that need a global TextEncoder not present under jsdom (same issue
// users/page.test.tsx's own doc comment flags for the identical import).
jest.mock('next/cache', () => ({
  revalidatePath: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import LoyaltyMembersPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7, tenantId: 1, adminUserId: 3 } });
}

describe('LoyaltyMembersPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the title, stat cards, and every member row', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('COUNT(*) AS total')) return [{ total: 2, points: 150, today: 1 }];
      if (sqlText.includes('SELECT id, display_name')) {
        return [
          { id: 1, display_name: 'สมศรี', real_name: null, first_name: null, last_name: null, phone: '0812345678', available_points: 100, total_points: 100, created_at: new Date() },
          { id: 2, display_name: null, real_name: 'สมชาย ใจดี', first_name: null, last_name: null, phone: '0898765432', available_points: 50, total_points: 50, created_at: new Date() },
        ];
      }
      return [];
    });

    const element = await LoyaltyMembersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: 'สมาชิกเบอร์ (สะสมแต้ม)' })).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument(); // total stat
    expect(screen.getByText('150')).toBeInTheDocument(); // points stat
    expect(screen.getByText('สมศรี')).toBeInTheDocument();
    expect(screen.getByText('สมชาย ใจดี')).toBeInTheDocument();
    expect(screen.getByText(/0812345678/)).toBeInTheDocument();
  });

  it('shows the empty state when there are no members and no search', async () => {
    wireDb(() => []);
    const element = await LoyaltyMembersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText(/ยังไม่มีสมาชิกเบอร์/)).toBeInTheDocument();
  });

  it('shows the "not found" empty state when searching with no matches', async () => {
    wireDb(() => []);
    const element = await LoyaltyMembersPage({ searchParams: Promise.resolve({ q: 'nobody' }) });
    render(element);
    expect(screen.getByText('ไม่พบสมาชิกที่ค้นหา')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ล้าง' })).toHaveAttribute('href', '/loyalty-members');
  });

  it('renders zeroed stats and an empty list without querying when there is no current bot', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: null, tenantId: 1, adminUserId: 3 } });
    const element = await LoyaltyMembersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(queries).toHaveLength(0);
    expect(screen.getByText(/ยังไม่มีสมาชิกเบอร์/)).toBeInTheDocument();
  });
});
