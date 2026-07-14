import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));
jest.mock('nodemailer', () => ({
  __esModule: true,
  default: { createTransport: jest.fn(() => ({ sendMail: jest.fn() })) },
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import SettingsPage, { generateMetadata } from './page';

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7, tenantId: 1, adminUserId: 3 } });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('SettingsPage', () => {
  it('defaults to the "line" tab (not-yet-migrated placeholder) when ?tab= is absent', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('LINE Accounts — ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม')).toBeInTheDocument();
  });

  it('defaults to the "line" tab for an unrecognized ?tab= value, never a different tab\'s content', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'not-a-real-tab' }) });
    render(element);
    expect(screen.getByText('LINE Accounts — ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม')).toBeInTheDocument();
    // The "ข้อความต้อนรับ" nav pill always renders (all 7 tabs are always
    // listed) — what must NOT render is welcome's actual <h2> tab content.
    expect(screen.queryByRole('heading', { name: 'ข้อความต้อนรับ' })).not.toBeInTheDocument();
  });

  it.each(['line', 'platform', 'general', 'notifications'])(
    'renders the NotYetMigratedTab placeholder (linking back to /settings.php?tab=%s) for the live-but-unported "%s" tab',
    async (tab) => {
      wireFakeDb();
      const element = await SettingsPage({ searchParams: Promise.resolve({ tab }) });
      render(element);
      const links = screen.getAllByRole('link').filter((a) => a.getAttribute('href') === `/settings.php?tab=${tab}`);
      expect(links.length).toBeGreaterThan(0);
    }
  );

  it('renders the real WelcomeTab content for ?tab=welcome', async () => {
    wireFakeDb(() => {
      throw new Error("Table 'tenant.welcome_settings' doesn't exist");
    });
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'welcome' }) });
    render(element);
    expect(screen.getByRole('heading', { name: 'ข้อความต้อนรับ' })).toBeInTheDocument();
  });

  it('renders the real EmailTab content for ?tab=email even though email has no nav pill', async () => {
    wireFakeDb(() => []);
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'email' }) });
    render(element);
    expect(screen.getByRole('heading', { name: /ตั้งค่า Email\/SMTP/ })).toBeInTheDocument();
    // Not shown as a nav pill (SETTINGS_TABS stays the 7-entry live whitelist).
    expect(screen.queryByRole('link', { name: /Email\/SMTP/ })).not.toBeInTheDocument();
  });

  it('renders the 7-tab nav with exactly the live PHP whitelist, in order, no "email" pill', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({}) });
    render(element);

    for (const [key, label] of [
      ['line', 'LINE Accounts'],
      ['platform', 'การเชื่อมต่อแพลตฟอร์ม'],
      ['general', 'ข้อมูลร้าน'],
      ['shop_tax', 'ข้อมูลร้าน / ใบกำกับภาษี'],
      ['welcome', 'ข้อความต้อนรับ'],
      ['notifications', 'การแจ้งเตือน'],
      ['consent', 'Consent'],
    ]) {
      // Exact match (not a regex substring match) — "ข้อมูลร้าน" (general) is
      // itself a substring of "ข้อมูลร้าน / ใบกำกับภาษี" (shop_tax), so a
      // substring matcher would ambiguously match both.
      const link = screen.getByRole('link', { name: label });
      expect(link).toHaveAttribute('href', `/settings?tab=${key}`);
    }
  });

  it('renders a non-crashing interim placeholder (not welcome\'s content) for ?tab=consent before settingsConsentTax lands', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'consent' }) });
    expect(() => render(element)).not.toThrow();
    expect(screen.queryByRole('heading', { name: 'ข้อความต้อนรับ' })).not.toBeInTheDocument();
  });

  it('renders a non-crashing interim placeholder for ?tab=shop_tax before settingsConsentTax lands', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'shop_tax' }) });
    expect(() => render(element)).not.toThrow();
    expect(screen.queryByRole('heading', { name: 'ข้อความต้อนรับ' })).not.toBeInTheDocument();
  });

  it('renders a success banner when ?message= is present', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'welcome', message: 'บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!' }) });
    render(element);
    expect(screen.getByRole('status')).toHaveTextContent('บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!');
  });

  it('renders an error banner when ?error= is present', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({ tab: 'welcome', error: 'เกิดข้อผิดพลาด: boom' }) });
    render(element);
    expect(screen.getByRole('alert')).toHaveTextContent('เกิดข้อผิดพลาด: boom');
  });

  it('renders neither banner when message/error are absent', async () => {
    wireFakeDb();
    const element = await SettingsPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});

describe('generateMetadata', () => {
  it('always returns the fixed page title, regardless of ?tab=', async () => {
    expect(await generateMetadata()).toEqual({ title: 'ตั้งค่าระบบ' });
  });
});
