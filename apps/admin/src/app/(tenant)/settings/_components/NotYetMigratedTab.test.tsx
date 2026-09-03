import { render, screen } from '@testing-library/react';
import { NotYetMigratedTab, type NotYetMigratedTabKey } from './NotYetMigratedTab';

const CASES: Array<[NotYetMigratedTabKey, string]> = [
  ['line', 'LINE Accounts'],
  ['platform', 'การเชื่อมต่อแพลตฟอร์ม'],
  ['general', 'ข้อมูลร้าน'],
  ['notifications', 'การแจ้งเตือน'],
  ['quick-access', 'Quick Access'],
];

describe('NotYetMigratedTab', () => {
  it.each(CASES)('renders the not-yet-migrated message + a link back to /settings.php?tab=%s for key "%s"', (tabKey, label) => {
    render(<NotYetMigratedTab tabKey={tabKey} />);
    // Exact match on the full heading string — avoids ambiguity with the CTA
    // link's own text, which also contains `label` as a substring.
    expect(screen.getByText(`${label} — ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม`)).toBeInTheDocument();
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', `/settings.php?tab=${tabKey}`);
    expect(link).toHaveTextContent(label);
  });

  it('never renders welcome-tab or email-tab content', () => {
    render(<NotYetMigratedTab tabKey="general" />);
    expect(screen.queryByText(/ข้อความต้อนรับ/)).not.toBeInTheDocument();
    expect(screen.queryByText(/SMTP/)).not.toBeInTheDocument();
  });
});
