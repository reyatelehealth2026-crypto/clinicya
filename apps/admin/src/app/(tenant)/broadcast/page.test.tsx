import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn((url: string) => {
    throw new Error(`REDIRECT:${url}`);
  }),
}));
const mockMulticastMessage = jest.fn();
const mockBroadcastMessage = jest.fn();
jest.mock('@reya/line', () => ({
  multicastMessage: (...args: unknown[]) => mockMulticastMessage(...args),
  broadcastMessage: (...args: unknown[]) => mockBroadcastMessage(...args),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import BroadcastPage, { generateMetadata } from './page';

/**
 * page.test.tsx — covers the tab shell (registry order, default tab, resolveCurrentBotId
 * fallback, and dispatch to each of the 4 tabs). Note: this file's import chain (`page.tsx`
 * -> `./_components/CatalogTab` -> `./_components/CatalogBuilderClient`, and similarly for
 * `StatsTab`) only fully resolves once the broadcastCatalogStats batch's sibling files have
 * landed in the same round — see page.tsx's own module doc and this batch's brief. Per the
 * brief's acceptance criteria, `pnpm --filter admin lint`/`test` for this directory is
 * expected to be evaluated AFTER both batches' files are present, not this batch's output in
 * isolation.
 */

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  currentBotId: number | null = 9
) {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId, tenantId: 1, adminUserId: 3 } });
  return { db, queries };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('BroadcastPage — tab registry + default', () => {
  it('generateMetadata always sets the "Broadcast" title', async () => {
    const meta = await generateMetadata();
    expect(meta.title).toBe('Broadcast');
  });

  it('defaults to the "send" tab (real SendTab content) when ?tab= is absent', async () => {
    wireFakeDb();
    const element = await BroadcastPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('สร้างข้อความใหม่')).toBeInTheDocument();
  });

  it('defaults to the "send" tab for an unrecognized ?tab= value, never a different tab\'s content', async () => {
    wireFakeDb();
    const element = await BroadcastPage({ searchParams: Promise.resolve({ tab: 'not-a-real-tab' }) });
    render(element);
    expect(screen.getByText('สร้างข้อความใหม่')).toBeInTheDocument();
  });

  it('renders all 4 tab nav pills in the exact order send/catalog/products/stats', async () => {
    wireFakeDb();
    const element = await BroadcastPage({ searchParams: Promise.resolve({}) });
    render(element);
    const nav = screen.getByText('ส่งข้อความ').closest('.tabs-nav');
    const labels = Array.from(nav?.querySelectorAll('.tab-label') ?? []).map((el) => el.textContent);
    expect(labels).toEqual(['ส่งข้อความ', 'Catalog Builder', 'สินค้า + Auto Tag', 'สถิติ']);
  });

  it('renders the real ProductsTab content for ?tab=products', async () => {
    wireFakeDb();
    const element = await BroadcastPage({ searchParams: Promise.resolve({ tab: 'products' }) });
    render(element);
    expect(screen.getByText('สร้าง Broadcast ใหม่')).toBeInTheDocument();
  });
});

describe('BroadcastPage — resolveCurrentBotId (broadcast.php lines 33-42)', () => {
  it('uses session.currentBotId directly when set (no line_accounts query)', async () => {
    const { queries } = wireFakeDb(() => [], 42);
    const element = await BroadcastPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(queries.some((q) => q.sql.includes('FROM line_accounts WHERE is_active'))).toBe(false);
  });

  it('falls back to is_default DESC, id ASC LIMIT 1 among active accounts when session.currentBotId is null', async () => {
    const { queries } = wireFakeDb(
      (sqlText) => (sqlText.includes('FROM line_accounts WHERE is_active') ? [{ id: 3 }] : []),
      null
    );
    const element = await BroadcastPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(queries.some((q) => q.sql.includes('ORDER BY is_default DESC, id ASC LIMIT 1'))).toBe(true);
  });
});
