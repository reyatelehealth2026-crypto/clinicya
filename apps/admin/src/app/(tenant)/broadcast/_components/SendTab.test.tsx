import { render, screen } from '@testing-library/react';

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

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { SendTab } from './SendTab';

function fakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []) {
  return makeFakeTenantDb(queryImpl).db;
}

describe('SendTab', () => {
  it('renders the compose form, templates, and a "ยังไม่มีประวัติการส่ง" empty state with no history rows', async () => {
    const db = fakeDb();
    const element = await SendTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('สร้างข้อความใหม่')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีประวัติการส่ง')).toBeInTheDocument();
  });

  it('renders the ?sent= success banner with the formatted count', async () => {
    const db = fakeDb();
    const element = await SendTab({ db, lineAccountId: 9, searchParams: { sent: '150' } });
    render(element);
    expect(screen.getByText('ส่ง Broadcast สำเร็จ!')).toBeInTheDocument();
    expect(screen.getByText('ส่งถึงผู้รับ 150 คน')).toBeInTheDocument();
  });

  it('renders the ?scheduled=1 banner', async () => {
    const db = fakeDb();
    const element = await SendTab({ db, lineAccountId: 9, searchParams: { scheduled: '1' } });
    render(element);
    expect(screen.getByText('ตั้งเวลา Broadcast สำเร็จ!')).toBeInTheDocument();
  });

  it('renders the ?cancelled=1 banner', async () => {
    const db = fakeDb();
    const element = await SendTab({ db, lineAccountId: 9, searchParams: { cancelled: '1' } });
    render(element);
    expect(screen.getByText('ยกเลิก Broadcast ที่ตั้งเวลาแล้ว')).toBeInTheDocument();
  });

  it('renders history rows with a cancel form for scheduled items and a sent-count line for sent items', async () => {
    const db = fakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcasts b')) {
        return [
          {
            id: 1,
            title: 'แคมเปญตั้งเวลา',
            message_type: 'text',
            status: 'scheduled',
            sent_count: 0,
            sent_at: null,
            scheduled_at: new Date('2026-09-01T03:00:00Z'),
          },
          {
            id: 2,
            title: 'แคมเปญส่งแล้ว',
            message_type: 'text',
            status: 'sent',
            sent_count: 300,
            sent_at: new Date('2026-08-01T03:00:00Z'),
            scheduled_at: null,
          },
        ];
      }
      return [];
    });
    const element = await SendTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('แคมเปญตั้งเวลา')).toBeInTheDocument();
    expect(screen.getByText('แคมเปญส่งแล้ว')).toBeInTheDocument();
    expect(screen.getByText('300 คน')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ยกเลิก/ })).toBeInTheDocument();
  });

  it('renders a "โหลดเพิ่ม" link when hasMore, pointing at the next hist_page', async () => {
    const rows = Array.from({ length: 11 }, (_, i) => ({
      id: i + 1,
      title: `B${i + 1}`,
      message_type: 'text',
      status: 'sent',
      sent_count: 1,
      sent_at: new Date(),
      scheduled_at: null,
    }));
    const db = fakeDb((sqlText) => (sqlText.includes('FROM broadcasts b') ? rows : []));
    const element = await SendTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    const loadMore = screen.getByRole('link', { name: /โหลดเพิ่ม/ });
    expect(loadMore).toHaveAttribute('href', '/broadcast?tab=send&hist_page=2');
  });
});
