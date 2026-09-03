import { render, screen } from '@testing-library/react';
import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { ConsentTab } from './ConsentTab';

describe('ConsentTab', () => {
  it('renders the red error banner (and NOT the stats/tables) when a query throws', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM data_access_logs dal')) {
        throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
      }
      return [];
    });

    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByText(/Table 'reya_tenant_0001\.admin_users' doesn't exist/)).toBeInTheDocument();
    expect(screen.getByText('กรุณารัน migration ก่อน')).toBeInTheDocument();
    expect(screen.queryByText('ผู้ใช้ที่ยินยอมแล้ว')).not.toBeInTheDocument();
  });

  it('renders stat cards with formatted numbers on the happy path', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('COUNT(DISTINCT user_id)')) return [{ total: 1234 }];
      if (sqlText.includes('GROUP BY consent_type')) {
        return [
          { consent_type: 'privacy_policy', count: 900 },
          { consent_type: 'terms_of_service', count: 800 },
          { consent_type: 'health_data', count: 50 },
        ];
      }
      return [];
    });

    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByText('1,234')).toBeInTheDocument();
    expect(screen.getByText('900')).toBeInTheDocument();
    expect(screen.getByText('800')).toBeInTheDocument();
    expect(screen.getByText('50')).toBeInTheDocument();
  });

  it('renders the empty-state colspan rows when recentLogs/accessLogs are both empty (0 rows, no error)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByText('ยังไม่มีข้อมูล Consent Log')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีข้อมูล Access Log')).toBeInTheDocument();
  });

  it('renders a populated consent_logs row, with the mapped consent-type label and action badge', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM consent_logs cl')) {
        return [
          {
            id: 1,
            created_at: '2026-07-10 10:30:00',
            display_name: 'สมชาย ใจดี',
            line_user_id: 'U1234567890abcdef1234567890abcdef',
            consent_type: 'privacy_policy',
            action: 'accept',
            consent_version: '1.0',
            ip_address: '203.0.113.5',
          },
        ];
      }
      return [];
    });

    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByText('สมชาย ใจดี')).toBeInTheDocument();
    expect(screen.getByText(/นโยบายความเป็นส่วนตัว/)).toBeInTheDocument();
    // Exact match — the stat cards above also render "ยอมรับ Privacy Policy" /
    // "ยอมรับ Terms of Service", so a substring matcher would be ambiguous.
    expect(screen.getByText('✅ ยอมรับ')).toBeInTheDocument();
    expect(screen.getByText('v1.0')).toBeInTheDocument();
  });

  it('renders a populated data_access_logs row, falling back a NULL admin_name to "System"', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM data_access_logs dal')) {
        return [
          {
            id: 1,
            created_at: '2026-07-11 09:00:00',
            admin_name: null,
            action: 'export_data',
            resource_type: 'consent_logs',
            target_user: null,
            ip_address: null,
          },
        ];
      }
      return [];
    });

    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByText('System')).toBeInTheDocument();
    expect(screen.getByText('export_data')).toBeInTheDocument();
    expect(screen.getByText('consent_logs')).toBeInTheDocument();
  });

  it('renders the Privacy Policy / Terms of Service header links regardless of error state', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await ConsentTab({ db });
    render(element);

    expect(screen.getByRole('link', { name: /Privacy Policy/ })).toHaveAttribute('href', '/privacy-policy.php');
    expect(screen.getByRole('link', { name: /Terms of Service/ })).toHaveAttribute('href', '/terms-of-service.php');
  });
});
