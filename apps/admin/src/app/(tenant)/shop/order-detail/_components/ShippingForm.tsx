/**
 * ShippingForm.tsx — port of shop/order-detail.php's "ข้อมูลจัดส่ง" card
 * (PHP lines 855-912): the read-only LIFF `delivery_info` panel + the
 * editable shipping form bound to `update_shipping`, plus the tracking
 * number display when `shipping_tracking` is set. No client interactivity
 * (a plain `<form action={updateShippingAction}>`), so this stays a Server
 * Component — matches PHP's own plain HTML form + POST.
 */

export interface DeliveryInfo {
  name?: string;
  phone?: string;
  fullAddress?: string;
}

export interface ShippingFormProps {
  deliveryInfo: DeliveryInfo;
  shippingName: string;
  shippingPhone: string;
  shippingAddress: string;
  shippingTracking: string | null;
  updateShippingAction: (formData: FormData) => void | Promise<void>;
}

export function ShippingForm({
  deliveryInfo,
  shippingName,
  shippingPhone,
  shippingAddress,
  shippingTracking,
  updateShippingAction,
}: ShippingFormProps) {
  const hasLiffInfo = Boolean(deliveryInfo.name || deliveryInfo.phone || deliveryInfo.fullAddress);

  return (
    <div className="od-detail-section">
      <div className="od-detail-section-hdr">
        <h4>
          <i className="fas fa-truck" style={{ color: 'var(--color-primary-500)', marginRight: 'var(--space-2)' }} />
          ข้อมูลจัดส่ง
        </h4>
      </div>
      <div className="od-detail-section-body">
        {hasLiffInfo ? (
          <div className="od-liff-info-box">
            <div style={{ display: 'flex', alignItems: 'center', marginBottom: 'var(--space-2)' }}>
              <span
                style={{
                  padding: '2px 8px',
                  background: 'var(--color-primary-600)',
                  color: '#fff',
                  fontSize: 'var(--text-xs)',
                  borderRadius: 'var(--radius-sm)',
                  marginRight: 'var(--space-2)',
                }}
              >
                จาก LIFF
              </span>
              <span style={{ fontSize: 'var(--text-sm)', color: 'var(--color-primary-600)' }}>ข้อมูลที่ลูกค้ากรอกตอนสั่งซื้อ</span>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-3)', fontSize: 'var(--text-sm)' }}>
              {deliveryInfo.name ? (
                <div>
                  <span style={{ color: 'var(--color-dark-500)' }}>ผู้รับ:</span> <span style={{ fontWeight: 500 }}>{deliveryInfo.name}</span>
                </div>
              ) : null}
              {deliveryInfo.phone ? (
                <div>
                  <span style={{ color: 'var(--color-dark-500)' }}>โทร:</span> <span style={{ fontWeight: 500 }}>{deliveryInfo.phone}</span>
                </div>
              ) : null}
              {deliveryInfo.fullAddress ? (
                <div style={{ gridColumn: '1/-1' }}>
                  <span style={{ color: 'var(--color-dark-500)' }}>ที่อยู่:</span>{' '}
                  <span style={{ fontWeight: 500 }}>{deliveryInfo.fullAddress}</span>
                </div>
              ) : null}
            </div>
          </div>
        ) : null}

        <form action={updateShippingAction}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)', marginBottom: 'var(--space-4)' }}>
            <div>
              <label className="od-form-lbl">ชื่อผู้รับ</label>
              <input type="text" name="shipping_name" defaultValue={shippingName} className="od-form-ctrl" />
            </div>
            <div>
              <label className="od-form-lbl">เบอร์โทร</label>
              <input type="text" name="shipping_phone" defaultValue={shippingPhone} className="od-form-ctrl" />
            </div>
          </div>
          <div style={{ marginBottom: 'var(--space-4)' }}>
            <label className="od-form-lbl">ที่อยู่จัดส่ง</label>
            <textarea name="shipping_address" rows={3} defaultValue={shippingAddress} className="od-form-area" />
          </div>
          <button type="submit" className="od-btn-save">
            <i className="fas fa-save" />
            บันทึกที่อยู่
          </button>
        </form>

        {shippingTracking ? (
          <div style={{ marginTop: 'var(--space-4)', padding: 'var(--space-3) var(--space-4)', background: 'rgba(124,58,237,0.08)', borderRadius: 'var(--radius-md)' }}>
            <p style={{ fontSize: 'var(--text-sm)', color: 'var(--color-violet-600)', margin: 0 }}>
              <i className="fas fa-truck" style={{ marginRight: 'var(--space-2)' }} />
              เลขพัสดุ: <strong style={{ fontFamily: 'var(--font-mono)' }}>{shippingTracking}</strong>
            </p>
          </div>
        ) : null}
      </div>
    </div>
  );
}
