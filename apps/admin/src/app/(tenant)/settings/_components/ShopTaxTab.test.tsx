import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ShopTaxInfoView } from '../_lib/shop-tax-queries';

const mockSaveShopTaxInfoAction = jest.fn();
jest.mock('../_lib/shop-tax-actions', () => ({
  saveShopTaxInfoAction: (...args: unknown[]) => mockSaveShopTaxInfoAction(...args),
}));

import { ShopTaxTab } from './ShopTaxTab';

// Mirrors DEFAULT_SHOP_TAX_INFO (../_lib/shop-tax-queries.ts) — the no-row
// default shape, including defaultVatRate: '7' (not '7.00' — PHP's own
// `(string)7.00` cast on this hardcoded-default path drops the trailing
// zeros; see that constant's own doc).
const EMPTY_DATA: ShopTaxInfoView = {
  businessName: '',
  businessNameEn: '',
  taxId: '',
  branchCode: '00000',
  address: '',
  phone: '',
  email: '',
  logoUrl: '',
  authorizedSigner: '',
  signerPosition: '',
  isVatRegistered: false,
  defaultVatRate: '7',
};

const POPULATED_DATA: ShopTaxInfoView = {
  businessName: 'บริษัท เรยา เฮลธ์ จำกัด',
  businessNameEn: 'REYA Health Co., Ltd.',
  taxId: '0105566123456',
  branchCode: '00001',
  address: '123 ถนนสุขุมวิท',
  phone: '02-123-4567',
  email: 'contact@reya.com',
  logoUrl: 'https://cdn.example.com/logo.png',
  authorizedSigner: 'นาย ก. ขีดเส้น',
  signerPosition: 'กรรมการผู้จัดการ',
  isVatRegistered: true,
  defaultVatRate: '7.50',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('ShopTaxTab — rendering', () => {
  it('renders the default-row (empty) form values, unchecked VAT checkbox', () => {
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    expect(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด')).toHaveValue('');
    expect(screen.getByPlaceholderText('00000 = สำนักงานใหญ่')).toHaveValue('00000');
    expect(screen.getByRole('checkbox', { name: /จดทะเบียนภาษีมูลค่าเพิ่ม/ })).not.toBeChecked();
  });

  it('pre-fills every field from a populated initialData row, VAT checkbox checked', () => {
    render(<ShopTaxTab initialData={POPULATED_DATA} />);
    expect(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด')).toHaveValue('บริษัท เรยา เฮลธ์ จำกัด');
    expect(screen.getByPlaceholderText('REYA Health Co., Ltd.')).toHaveValue('REYA Health Co., Ltd.');
    expect(screen.getByPlaceholderText('0105566123456')).toHaveValue('0105566123456');
    expect(screen.getByPlaceholderText('00000 = สำนักงานใหญ่')).toHaveValue('00001');
    expect(screen.getByPlaceholderText('02-123-4567')).toHaveValue('02-123-4567');
    expect(screen.getByPlaceholderText('contact@yourshop.com')).toHaveValue('contact@reya.com');
    expect(screen.getByPlaceholderText('https://your-cdn.com/logo.png')).toHaveValue('https://cdn.example.com/logo.png');
    expect(screen.getByPlaceholderText('นาย ก. ขีดเส้น')).toHaveValue('นาย ก. ขีดเส้น');
    expect(screen.getByPlaceholderText('กรรมการผู้จัดการ')).toHaveValue('กรรมการผู้จัดการ');
    expect(screen.getByRole('checkbox', { name: /จดทะเบียนภาษีมูลค่าเพิ่ม/ })).toBeChecked();
    expect(screen.getByDisplayValue('7.50')).toBeInTheDocument();
  });

  it('renders no banner initially', () => {
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});

describe('ShopTaxTab — submit', () => {
  function fillAndSubmit(overrides: Partial<Record<string, string>> = {}) {
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    fireEvent.change(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด'), {
      target: { value: overrides.business_name ?? 'ร้านยา ทดสอบ' },
    });
    fireEvent.click(screen.getByRole('button', { name: /บันทึก/ }));
  }

  it('calls saveShopTaxInfoAction with the form payload, is_vat_registered=0 when unchecked', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: true, message: 'บันทึกข้อมูลกิจการสำเร็จ — เอกสารใหม่จะแสดงข้อมูลนี้' });
    fillAndSubmit();

    await screen.findByRole('status');

    expect(mockSaveShopTaxInfoAction).toHaveBeenCalledWith(
      expect.objectContaining({
        business_name: 'ร้านยา ทดสอบ',
        is_vat_registered: 0,
      })
    );
  });

  it('sends is_vat_registered=1 when the checkbox is checked before submit', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: true, message: 'ok' });
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    fireEvent.change(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด'), { target: { value: 'ร้านยา ทดสอบ' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /จดทะเบียนภาษีมูลค่าเพิ่ม/ }));
    fireEvent.click(screen.getByRole('button', { name: /บันทึก/ }));

    await screen.findByRole('status');
    expect(mockSaveShopTaxInfoAction).toHaveBeenCalledWith(expect.objectContaining({ is_vat_registered: 1 }));
  });

  it('shows the success banner (showAlert("ok", ...) equivalent) on success, no navigation', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: true, message: 'บันทึกข้อมูลกิจการสำเร็จ — เอกสารใหม่จะแสดงข้อมูลนี้' });
    fillAndSubmit();

    const banner = await screen.findByRole('status');
    expect(banner).toHaveTextContent('บันทึกข้อมูลกิจการสำเร็จ — เอกสารใหม่จะแสดงข้อมูลนี้');
  });

  it('shows the error banner using result.message when success=false', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: false, message: 'ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน' });
    fillAndSubmit();

    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน');
  });

  it('falls back to result.error, then a generic message, when result.message is absent', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: false, error: 'boom' });
    fillAndSubmit();
    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('boom');
  });

  it('shows a network-error-style banner when the action call itself rejects', async () => {
    mockSaveShopTaxInfoAction.mockRejectedValue(new Error('fetch failed'));
    fillAndSubmit();
    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('เครือข่ายมีปัญหา: fetch failed');
  });

  it('does NOT reset or repopulate the form from the returned data on success (matches PHP: json.data is fetched but never applied)', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({
      success: true,
      message: 'ok',
      data: { ...EMPTY_DATA, businessName: 'ชื่อที่เซิร์ฟเวอร์ส่งกลับมา' },
    });
    fillAndSubmit({ business_name: 'ร้านยา ทดสอบ' });
    await screen.findByRole('status');

    expect(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด')).toHaveValue('ร้านยา ทดสอบ');
  });
});

describe('ShopTaxTab — banner auto-hide', () => {
  beforeEach(() => {
    jest.useFakeTimers({ legacyFakeTimers: false });
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('auto-hides the success banner after 4000ms (matches PHP\'s setTimeout(..., 4000) for type="ok")', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: true, message: 'ok banner' });
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    fireEvent.change(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด'), { target: { value: 'ร้านยา ทดสอบ' } });
    fireEvent.click(screen.getByRole('button', { name: /บันทึก/ }));

    await screen.findByRole('status');
    expect(screen.getByRole('status')).toBeInTheDocument();

    await act(async () => {
      await jest.advanceTimersByTimeAsync(4000);
    });
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('does NOT auto-hide the error banner', async () => {
    mockSaveShopTaxInfoAction.mockResolvedValue({ success: false, message: 'err banner' });
    render(<ShopTaxTab initialData={EMPTY_DATA} />);
    fireEvent.change(screen.getByPlaceholderText('เช่น บริษัท เรยา เฮลธ์ จำกัด'), { target: { value: 'ร้านยา ทดสอบ' } });
    fireEvent.click(screen.getByRole('button', { name: /บันทึก/ }));

    await screen.findByRole('alert');
    await act(async () => {
      await jest.advanceTimersByTimeAsync(10000);
    });
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });
});
