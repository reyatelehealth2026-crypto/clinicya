import { render, screen } from '@testing-library/react';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

jest.mock('./_lib/executiveData', () => ({
  fetchExecutiveData: jest.fn(),
}));

import { fetchExecutiveData, type ExecutiveData } from './_lib/executiveData';
import { ExecutiveTab } from './executive';

const mockFetchExecutiveData = fetchExecutiveData as jest.MockedFunction<typeof fetchExecutiveData>;

const FAKE_DB = {} as Kysely<TenantDB>;

const BASE_DATA: ExecutiveData = {
  messageStats: { total: 120, incoming: 70, outgoing: 50, unread: 3 },
  customersToday: 40,
  newCustomers: 5,
  orderStats: { total: 12, pending: 2, completed: 9, revenue: 15800 },
  avgResponseTime: 4,
  videoStats: { total: 3, completed: 2, avgDuration: 300 },
  problemMessages: [],
  adminPerformance: [],
  recentConversations: [],
  hourlyActivity: new Array(24).fill(0),
  topIssueSourceMessages: [],
};

describe('ExecutiveTab', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the 5 primary KPI tiles with PHP-matching formatted values', async () => {
    mockFetchExecutiveData.mockResolvedValue(BASE_DATA);

    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: '2026-07-12' });
    render(element);

    expect(screen.getByTestId('kpi-messages-today')).toHaveTextContent('120');
    expect(screen.getByTestId('kpi-messages-today')).toHaveTextContent('รับ 70 / ส่ง 50');
    expect(screen.getByTestId('kpi-customers-contacted')).toHaveTextContent('40');
    expect(screen.getByTestId('kpi-customers-contacted')).toHaveTextContent('+5 ใหม่');
    expect(screen.getByTestId('kpi-orders')).toHaveTextContent('12');
    expect(screen.getByTestId('kpi-revenue')).toHaveTextContent('฿15,800');
    expect(screen.getByTestId('kpi-video-calls')).toHaveTextContent('3');
    expect(screen.getByTestId('kpi-video-calls')).toHaveTextContent('5.0 นาที'); // 300s / 60 = 5.0
  });

  it('drives the response-time tile accent from avgResponseTime (emerald/ดีมาก at 4 minutes)', async () => {
    mockFetchExecutiveData.mockResolvedValue({ ...BASE_DATA, avgResponseTime: 4 });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const tile = screen.getByTestId('kpi-avg-response-time');
    expect(tile).toHaveAttribute('data-accent', 'emerald');
    expect(tile).toHaveTextContent('ดีมาก');
  });

  it('flips the response-time tile to rose/ต้องปรับปรุง above 15 minutes', async () => {
    mockFetchExecutiveData.mockResolvedValue({ ...BASE_DATA, avgResponseTime: 25 });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const tile = screen.getByTestId('kpi-avg-response-time');
    expect(tile).toHaveAttribute('data-accent', 'rose');
    expect(tile).toHaveTextContent('ต้องปรับปรุง');
  });

  it('renders the unread tile in alert style when unread > 0', async () => {
    mockFetchExecutiveData.mockResolvedValue({ ...BASE_DATA, messageStats: { ...BASE_DATA.messageStats, unread: 7 } });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const tile = screen.getByTestId('kpi-unread');
    expect(tile).toHaveAttribute('data-alert', 'true');
    expect(tile).toHaveAttribute('data-accent', 'rose');
  });

  it('renders the unread tile without alert style when unread is 0', async () => {
    mockFetchExecutiveData.mockResolvedValue({ ...BASE_DATA, messageStats: { ...BASE_DATA.messageStats, unread: 0 } });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const tile = screen.getByTestId('kpi-unread');
    expect(tile).toHaveAttribute('data-alert', 'false');
    expect(tile).toHaveAttribute('data-accent', 'emerald');
  });

  it('derives the problem-count tile from problemMessages.length and alerts when > 0', async () => {
    mockFetchExecutiveData.mockResolvedValue({
      ...BASE_DATA,
      problemMessages: [
        { id: 1, userId: 10, content: 'มีปัญหามาก', timeHm: '10:30', displayName: 'คุณเอ', pictureUrl: null },
        { id: 2, userId: 11, content: 'ไม่พอใจ', timeHm: '11:00', displayName: 'คุณบี', pictureUrl: null },
      ],
    });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const tile = screen.getByTestId('kpi-problem-count');
    expect(tile).toHaveTextContent('2');
    expect(tile).toHaveAttribute('data-alert', 'true');
    expect(screen.getByTestId('problem-messages-list')).toBeInTheDocument();
    expect(screen.queryByTestId('problem-messages-empty')).not.toBeInTheDocument();
  });

  it('renders the empty state for admin performance, problem messages, and recent conversations when all are empty', async () => {
    mockFetchExecutiveData.mockResolvedValue(BASE_DATA);
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    expect(screen.getByTestId('admin-performance-empty')).toBeInTheDocument();
    expect(screen.getByTestId('problem-messages-empty')).toBeInTheDocument();
    expect(screen.getByTestId('recent-conversations-empty')).toBeInTheDocument();
    expect(screen.getByTestId('top-issues-empty')).toBeInTheDocument();
  });

  it('renders admin performance rows and recent conversations when present', async () => {
    mockFetchExecutiveData.mockResolvedValue({
      ...BASE_DATA,
      adminPerformance: [{ adminName: 'เภสัชกร A', messagesSent: 42, customersHandled: 9 }],
      recentConversations: [{ id: 1, displayName: 'คุณซี', pictureUrl: null, lineUserId: 'U1', messageCount: 4, lastMessageHm: '09:15', lastMessage: 'สวัสดีครับ' }],
    });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    expect(screen.getByTestId('admin-performance-list')).toHaveTextContent('เภสัชกร A');
    expect(screen.getByTestId('admin-performance-list')).toHaveTextContent('42');
    expect(screen.getByTestId('recent-conversations-list')).toHaveTextContent('คุณซี');
    expect(screen.getByTestId('recent-conversations-list')).toHaveTextContent('4 ข้อความ');
  });

  it('renders only non-zero top issues, sorted descending', async () => {
    mockFetchExecutiveData.mockResolvedValue({
      ...BASE_DATA,
      topIssueSourceMessages: ['ถามราคาสินค้า', 'ถามราคา', 'จัดส่งช้า'],
    });
    const element = await ExecutiveTab({ db: FAKE_DB, dateParam: undefined });
    render(element);

    const list = screen.getByTestId('top-issues-list');
    expect(list).toHaveTextContent('ราคา');
    expect(list).toHaveTextContent('สินค้า');
    expect(list).toHaveTextContent('จัดส่ง');
    expect(list).not.toHaveTextContent('ชำระเงิน');
    expect(screen.queryByTestId('top-issues-empty')).not.toBeInTheDocument();
  });

  it('passes dateStart/dateEnd derived from dateParam through to fetchExecutiveData', async () => {
    mockFetchExecutiveData.mockResolvedValue(BASE_DATA);
    await ExecutiveTab({ db: FAKE_DB, dateParam: '2026-02-01' });

    expect(mockFetchExecutiveData).toHaveBeenCalledWith(FAKE_DB, '2026-02-01 00:00:00', '2026-02-01 23:59:59');
  });
});
