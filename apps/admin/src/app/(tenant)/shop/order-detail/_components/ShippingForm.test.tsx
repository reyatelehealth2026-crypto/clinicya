import { render, screen } from '@testing-library/react';
import { ShippingForm } from './ShippingForm';

describe('ShippingForm', () => {
  it('renders the LIFF read-only info box when delivery_info has any of name/phone/address', () => {
    render(
      <ShippingForm
        deliveryInfo={{ name: 'สมชาย ใจดี', phone: '0812345678', fullAddress: '123 ถนนสุขุมวิท' }}
        shippingName="สมชาย ใจดี"
        shippingPhone="0812345678"
        shippingAddress="123 ถนนสุขุมวิท"
        shippingTracking={null}
        updateShippingAction={() => {}}
      />
    );

    expect(screen.getByText('จาก LIFF')).toBeInTheDocument();
    expect(screen.getByText('สมชาย ใจดี')).toBeInTheDocument();
  });

  it('omits the LIFF box entirely when delivery_info is empty', () => {
    render(
      <ShippingForm
        deliveryInfo={{}}
        shippingName=""
        shippingPhone=""
        shippingAddress=""
        shippingTracking={null}
        updateShippingAction={() => {}}
      />
    );
    expect(screen.queryByText('จาก LIFF')).not.toBeInTheDocument();
  });

  it('pre-fills the editable form fields from the current shipping_* values', () => {
    render(
      <ShippingForm
        deliveryInfo={{}}
        shippingName="ผู้รับ B"
        shippingPhone="0898887777"
        shippingAddress="456 ถนน"
        shippingTracking={null}
        updateShippingAction={() => {}}
      />
    );
    expect(screen.getByDisplayValue('ผู้รับ B')).toBeInTheDocument();
    expect(screen.getByDisplayValue('0898887777')).toBeInTheDocument();
    expect(screen.getByDisplayValue('456 ถนน')).toBeInTheDocument();
  });

  it('shows the tracking number box only when shippingTracking is set', () => {
    const { rerender } = render(
      <ShippingForm
        deliveryInfo={{}}
        shippingName=""
        shippingPhone=""
        shippingAddress=""
        shippingTracking={null}
        updateShippingAction={() => {}}
      />
    );
    expect(screen.queryByText(/เลขพัสดุ/)).not.toBeInTheDocument();

    rerender(
      <ShippingForm
        deliveryInfo={{}}
        shippingName=""
        shippingPhone=""
        shippingAddress=""
        shippingTracking="TH999888777"
        updateShippingAction={() => {}}
      />
    );
    expect(screen.getByText('TH999888777')).toBeInTheDocument();
  });
});
