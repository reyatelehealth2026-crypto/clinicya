import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { GeneralSettingsForm } from './GeneralSettingsForm';
import type { GeneralSettingsView } from '../_lib/general-queries';

function makeSettings(overrides: Partial<GeneralSettingsView> = {}): GeneralSettingsView {
  return {
    shopName: 'LINE Shop',
    shopLogo: '',
    welcomeMessage: 'ยินดีต้อนรับ!',
    shopAddress: '',
    shopEmail: '',
    shippingFee: 50,
    freeShippingMin: 500,
    bankAccounts: [],
    promptpayNumber: '',
    contactPhone: '',
    isOpen: true,
    codEnabled: false,
    codFee: 0,
    autoConfirmPayment: false,
    orderDataSource: 'shop',
    lineId: '',
    facebookUrl: '',
    instagramUrl: '',
    ...overrides,
  };
}

describe('GeneralSettingsForm', () => {
  it('renders a form with id="settings-general-form" and a hidden tab=general field', () => {
    render(<GeneralSettingsForm settings={makeSettings()} showOdooOrderSource={false} />);
    const form = document.getElementById('settings-general-form');
    expect(form).toBeInTheDocument();
    expect(form?.tagName).toBe('FORM');
    const tabInput = form?.querySelector('input[name="tab"]') as HTMLInputElement;
    expect(tabInput.value).toBe('general');
  });

  it('pre-fills text fields from the passed-in settings', () => {
    render(<GeneralSettingsForm settings={makeSettings({ shopName: 'ร้านยา CNY', contactPhone: '0812345678' })} showOdooOrderSource={false} />);
    expect(screen.getByDisplayValue('ร้านยา CNY')).toBeInTheDocument();
    expect(screen.getByDisplayValue('0812345678')).toBeInTheDocument();
  });

  it('pre-checks is_open/cod_enabled/auto_confirm_payment from settings', () => {
    render(
      <GeneralSettingsForm
        settings={makeSettings({ isOpen: false, codEnabled: true, autoConfirmPayment: true })}
        showOdooOrderSource={false}
      />
    );
    expect((document.querySelector('input[name="is_open"]') as HTMLInputElement).checked).toBe(false);
    expect((document.querySelector('input[name="cod_enabled"]') as HTMLInputElement).checked).toBe(true);
    expect((document.querySelector('input[name="auto_confirm_payment"]') as HTMLInputElement).checked).toBe(true);
  });

  it('renders one bank row per existing bank account, with name/account/holder fields', () => {
    render(
      <GeneralSettingsForm
        settings={makeSettings({ bankAccounts: [{ name: 'KBank', account: '111-1', holder: 'CNY A' }] })}
        showOdooOrderSource={false}
      />
    );
    expect(screen.getByDisplayValue('KBank')).toBeInTheDocument();
    expect(screen.getByDisplayValue('111-1')).toBeInTheDocument();
    expect(screen.getByDisplayValue('CNY A')).toBeInTheDocument();
  });

  it('adds a new blank bank row when "เพิ่มบัญชี" is clicked', () => {
    render(<GeneralSettingsForm settings={makeSettings()} showOdooOrderSource={false} />);
    expect(document.querySelectorAll('input[name="bank_name[]"]')).toHaveLength(0);

    fireEvent.click(screen.getByRole('button', { name: /เพิ่มบัญชี/ }));
    expect(document.querySelectorAll('input[name="bank_name[]"]')).toHaveLength(1);

    fireEvent.click(screen.getByRole('button', { name: /เพิ่มบัญชี/ }));
    expect(document.querySelectorAll('input[name="bank_name[]"]')).toHaveLength(2);
  });

  it('removes a bank row when its remove button is clicked', () => {
    render(
      <GeneralSettingsForm
        settings={makeSettings({
          bankAccounts: [
            { name: 'KBank', account: '111-1', holder: 'A' },
            { name: 'SCB', account: '222-2', holder: 'B' },
          ],
        })}
        showOdooOrderSource={false}
      />
    );
    expect(document.querySelectorAll('input[name="bank_name[]"]')).toHaveLength(2);

    fireEvent.click(screen.getAllByLabelText('ลบบัญชีธนาคาร')[0]!);
    expect(document.querySelectorAll('input[name="bank_name[]"]')).toHaveLength(1);
    expect(screen.getByDisplayValue('SCB')).toBeInTheDocument();
  });

  it('previewing a logo URL clears the file input value and shows the URL as the preview image', () => {
    render(<GeneralSettingsForm settings={makeSettings()} showOdooOrderSource={false} />);
    const urlInput = document.querySelector('input[name="shop_logo"]') as HTMLInputElement;

    fireEvent.change(urlInput, { target: { value: 'https://example.com/logo.png' } });

    const preview = document.querySelector('img[alt=""]') as HTMLImageElement;
    expect(preview.src).toBe('https://example.com/logo.png');
  });

  it('does not render the Odoo order-data-source radio block when showOdooOrderSource is false', () => {
    render(<GeneralSettingsForm settings={makeSettings()} showOdooOrderSource={false} />);
    expect(screen.queryByText('แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย')).not.toBeInTheDocument();
    expect(document.querySelector('input[name="order_data_source"]')).not.toBeInTheDocument();
  });

  it('renders the Odoo order-data-source radio block when showOdooOrderSource is true, checking "shop" by default', () => {
    render(<GeneralSettingsForm settings={makeSettings({ orderDataSource: 'shop' })} showOdooOrderSource />);
    expect(screen.getByText('แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย')).toBeInTheDocument();

    const radios = document.querySelectorAll('input[name="order_data_source"]') as NodeListOf<HTMLInputElement>;
    expect(radios).toHaveLength(2);
    const shopRadio = Array.from(radios).find((r) => r.value === 'shop')!;
    const odooRadio = Array.from(radios).find((r) => r.value === 'odoo')!;
    expect(shopRadio.checked).toBe(true);
    expect(odooRadio.checked).toBe(false);
  });

  it('checks the "odoo" radio when orderDataSource is "odoo"', () => {
    render(<GeneralSettingsForm settings={makeSettings({ orderDataSource: 'odoo' })} showOdooOrderSource />);
    const radios = document.querySelectorAll('input[name="order_data_source"]') as NodeListOf<HTMLInputElement>;
    const odooRadio = Array.from(radios).find((r) => r.value === 'odoo')!;
    expect(odooRadio.checked).toBe(true);
  });
});
