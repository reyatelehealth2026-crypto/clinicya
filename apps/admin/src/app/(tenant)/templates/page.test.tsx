import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));

jest.mock('next/cache', () => ({
  revalidatePath: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import TemplatesPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
}

describe('TemplatesPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the title and every template card', async () => {
    wireDb(() => [
      { id: 1, name: 'ทักทาย', category: 'ทั่วไป', messageType: 'text', content: 'สวัสดีครับ', createdAt: new Date() },
      { id: 2, name: 'โปร', category: 'โปรโมชั่น', messageType: 'flex', content: '{"type":"flex"}', createdAt: new Date() },
    ]);

    const element = await TemplatesPage();
    render(element);

    expect(screen.getByRole('heading', { name: 'Template Library' })).toBeInTheDocument();
    expect(screen.getByText('ทักทาย')).toBeInTheDocument();
    expect(screen.getByText('โปร')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ทั้งหมด' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ทั่วไป' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'โปรโมชั่น' })).toBeInTheDocument();
  });

  it('shows the empty state when there are no templates', async () => {
    wireDb(() => []);
    const element = await TemplatesPage();
    render(element);
    expect(screen.getByText('ยังไม่มีเทมเพลต')).toBeInTheDocument();
  });

  it('never scopes the templates query by line_account_id', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db });
    const element = await TemplatesPage();
    render(element);
    expect(queries).toHaveLength(1);
    expect(queries[0]?.params).toEqual([]);
  });
});
