import { render, screen } from '@testing-library/react';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

jest.mock('./_lib/crmData', () => ({
  fetchCrmData: jest.fn(),
}));

import { fetchCrmData, type CrmData } from './_lib/crmData';
import { CrmTab } from './crm';

const mockFetchCrmData = fetchCrmData as jest.MockedFunction<typeof fetchCrmData>;

const FAKE_DB = {} as Kysely<TenantDB>;

const EMPTY_DATA: CrmData = {
  stats: { totalCustomers: 0, newToday: 0, new7Days: 0, totalTags: 0, autoRules: 0 },
  tags: [],
  autoRules: [],
  recentCustomers: [],
};

describe('CrmTab', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('passes currentBotId straight through to fetchCrmData (crm.php scopes every query by it)', async () => {
    mockFetchCrmData.mockResolvedValue(EMPTY_DATA);
    await CrmTab({ db: FAKE_DB, currentBotId: 7 });
    expect(mockFetchCrmData).toHaveBeenCalledWith(FAKE_DB, 7);
  });

  it('supports a null currentBotId (super-admin / no-bot-scope session)', async () => {
    mockFetchCrmData.mockResolvedValue(EMPTY_DATA);
    await CrmTab({ db: FAKE_DB, currentBotId: null });
    expect(mockFetchCrmData).toHaveBeenCalledWith(FAKE_DB, null);
  });

  it('renders the 4 KPI tiles with formatted values', async () => {
    mockFetchCrmData.mockResolvedValue({
      ...EMPTY_DATA,
      stats: { totalCustomers: 1234, newToday: 5, new7Days: 42, totalTags: 8, autoRules: 3 },
    });
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    expect(screen.getByTestId('kpi-total-customers')).toHaveTextContent('1,234');
    expect(screen.getByTestId('kpi-total-customers')).toHaveTextContent('+42 ใน 7 วัน');
    expect(screen.getByTestId('kpi-new-today')).toHaveTextContent('5');
    expect(screen.getByTestId('kpi-total-tags')).toHaveTextContent('8');
    expect(screen.getByTestId('kpi-auto-rules')).toHaveTextContent('3');
  });

  it('renders the empty state for tags, auto rules, and recent customers when all are empty', async () => {
    mockFetchCrmData.mockResolvedValue(EMPTY_DATA);
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    expect(screen.getByTestId('tags-empty')).toBeInTheDocument();
    expect(screen.getByTestId('auto-rules-empty')).toBeInTheDocument();
    expect(screen.getByTestId('recent-customers-empty')).toBeInTheDocument();
  });

  it('renders populated tags with the Auto/System badge and customer count', async () => {
    mockFetchCrmData.mockResolvedValue({
      ...EMPTY_DATA,
      tags: [
        { id: 1, name: 'VIP', color: '#3B82F6', tagType: 'manual', customerCount: 12 },
        { id: 2, name: 'สนใจโปรโมชั่น', color: '#F59E0B', tagType: 'auto', customerCount: 4 },
      ],
    });
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    const list = screen.getByTestId('tags-list');
    expect(list).toHaveTextContent('VIP');
    expect(list).toHaveTextContent('12');
    expect(list).toHaveTextContent('สนใจโปรโมชั่น');
    expect(list).toHaveTextContent('Auto');
    expect(screen.queryByTestId('tags-empty')).not.toBeInTheDocument();
  });

  it('renders populated auto rules with Active/Inactive status', async () => {
    mockFetchCrmData.mockResolvedValue({
      ...EMPTY_DATA,
      autoRules: [
        { id: 1, ruleName: 'ทักครั้งแรก', isActive: true, triggerType: 'first_message', tagColor: '#3B82F6', tagName: 'New Lead' },
        { id: 2, ruleName: 'ไม่มีกิจกรรม 30 วัน', isActive: false, triggerType: 'inactivity', tagColor: '#EF4444', tagName: 'Dormant' },
      ],
    });
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    const list = screen.getByTestId('auto-rules-list');
    expect(list).toHaveTextContent('ทักครั้งแรก');
    expect(list).toHaveTextContent('Active');
    expect(list).toHaveTextContent('ไม่มีกิจกรรม 30 วัน');
    expect(list).toHaveTextContent('Inactive');
  });

  it('renders recent customers, splitting the GROUP_CONCAT tags string into chips', async () => {
    mockFetchCrmData.mockResolvedValue({
      ...EMPTY_DATA,
      recentCustomers: [{ id: 55, displayName: 'คุณดี', pictureUrl: null, tags: 'VIP, สนใจโปรโมชั่น' }],
    });
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    const list = screen.getByTestId('recent-customers-list');
    expect(list).toHaveTextContent('คุณดี');
    expect(list).toHaveTextContent('VIP');
    expect(list).toHaveTextContent('สนใจโปรโมชั่น');
  });

  it('renders "ไม่มี tag" for a recent customer with no tags', async () => {
    mockFetchCrmData.mockResolvedValue({
      ...EMPTY_DATA,
      recentCustomers: [{ id: 56, displayName: 'คุณอี', pictureUrl: null, tags: null }],
    });
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    expect(screen.getByTestId('recent-customers-list')).toHaveTextContent('ไม่มี tag');
  });

  it('renders the 8 static quick-action links verbatim from crm.php', async () => {
    mockFetchCrmData.mockResolvedValue(EMPTY_DATA);
    const element = await CrmTab({ db: FAKE_DB, currentBotId: 1 });
    render(element);

    const rail = screen.getByTestId('quick-actions-list');
    const links = rail.querySelectorAll('a');
    expect(links).toHaveLength(8);
    expect(rail).toHaveTextContent('ดูลูกค้าทั้งหมด');
    expect(rail).toHaveTextContent('Broadcast');
    expect(screen.getByRole('link', { name: 'จัดการ Tags' })).toHaveAttribute('href', 'user-tags.php');
  });
});
