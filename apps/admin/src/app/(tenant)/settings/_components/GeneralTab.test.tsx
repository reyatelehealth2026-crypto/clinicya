import { render, screen } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { GeneralTab } from './GeneralTab';

const originalOdooEnv = process.env.ODOO_INTEGRATION_ENABLED;

afterEach(() => {
  if (originalOdooEnv === undefined) {
    delete process.env.ODOO_INTEGRATION_ENABLED;
  } else {
    process.env.ODOO_INTEGRATION_ENABLED = originalOdooEnv;
  }
});

describe('GeneralTab', () => {
  it('renders the shop_name field pre-filled from a shop_settings row', async () => {
    const { db } = makeFakeTenantDb(() => [{ shop_name: 'ร้านยา CNY' }]);
    const element = await GeneralTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.getByDisplayValue('ร้านยา CNY')).toBeInTheDocument();
  });

  it('hides the Odoo order-data-source block when ODOO_INTEGRATION_ENABLED is unset, matching $isOdooMode\'s gate', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    const { db } = makeFakeTenantDb(() => []);
    const element = await GeneralTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.queryByText('แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย')).not.toBeInTheDocument();
  });

  it('shows the Odoo order-data-source block when ODOO_INTEGRATION_ENABLED=true', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = 'true';
    const { db } = makeFakeTenantDb(() => []);
    const element = await GeneralTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.getByText('แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย')).toBeInTheDocument();
  });
});
