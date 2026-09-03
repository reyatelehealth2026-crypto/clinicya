'use client';

import type { FormEvent } from 'react';

/**
 * QuickActionsSidebar.tsx — port of shop/order-detail.php's "⚡ Quick
 * Actions" card (PHP lines 917-992) AND the adjacent "🔧 เปลี่ยนสถานะ
 * (Manual)" card (PHP lines 994-1013) — both live in the same right-sidebar
 * column, so ported together in one file/component.
 *
 * 'use client' only because of the Cancel-order button's `onclick="return
 * confirm('ยกเลิกออเดอร์นี้?')"` (PHP line 985) — every other form here is a
 * plain progressively-enhanced `<form action={...}>` bound to a Server
 * Action, same as PHP's own POST forms.
 */

export type OrderStatusKey = 'pending' | 'confirmed' | 'paid' | 'shipping' | 'delivered' | 'cancelled';

export interface QuickActionsSidebarProps {
  status: OrderStatusKey;
  paymentStatus: string | null;
  shippingTracking: string | null;
  updateStatusAction: (formData: FormData) => void | Promise<void>;
  addTrackingAction: (formData: FormData) => void | Promise<void>;
}

function handleCancelSubmit(e: FormEvent<HTMLFormElement>) {
  // PHP: onclick="return confirm('ยกเลิกออเดอร์นี้?')" — blocks the POST when declined.
  if (!window.confirm('ยกเลิกออเดอร์นี้?')) {
    e.preventDefault();
  }
}

export function QuickActionsSidebar({ status, paymentStatus, shippingTracking, updateStatusAction, addTrackingAction }: QuickActionsSidebarProps) {
  const canCancel = status !== 'delivered' && status !== 'cancelled';

  return (
    <>
      <div className="od-detail-section">
        <div className="od-detail-section-hdr">
          <h4>⚡ Quick Actions</h4>
        </div>
        <div className="od-detail-section-body">
          <div style={{ marginBottom: 'var(--space-5)' }}>
            {status === 'pending' ? (
              <form action={updateStatusAction}>
                <input type="hidden" name="status" value="confirmed" />
                <button type="submit" className="od-btn-act od-btn-confirm">
                  <i className="fas fa-check" />
                  ยืนยันออเดอร์
                </button>
              </form>
            ) : null}

            {(status === 'confirmed' || status === 'pending') && paymentStatus !== 'paid' ? (
              <div
                style={{
                  padding: 'var(--space-3)',
                  background: 'var(--color-amber-50)',
                  border: '1px solid var(--color-amber-200)',
                  borderRadius: 'var(--radius-md)',
                  textAlign: 'center',
                  marginBottom: 'var(--space-2)',
                }}
              >
                <i className="fas fa-clock" style={{ color: 'var(--color-amber-500)', marginRight: 4 }} />
                <span style={{ fontSize: 'var(--text-sm)', color: 'var(--color-amber-700)' }}>รอลูกค้าชำระเงิน</span>
              </div>
            ) : null}

            {paymentStatus === 'paid' && status !== 'shipping' && status !== 'delivered' ? (
              <form action={addTrackingAction}>
                <div style={{ marginBottom: 'var(--space-2)' }}>
                  <input type="text" name="tracking" required placeholder="กรอกเลขพัสดุ เช่น TH123456789" className="od-form-ctrl" />
                </div>
                <button type="submit" className="od-btn-act od-btn-track">
                  <i className="fas fa-truck" />
                  ส่งเลขพัสดุ
                </button>
              </form>
            ) : null}

            {status === 'shipping' ? (
              <form action={updateStatusAction}>
                <input type="hidden" name="status" value="delivered" />
                <button type="submit" className="od-btn-act od-btn-done">
                  <i className="fas fa-box-open" />
                  ยืนยันส่งถึงแล้ว
                </button>
              </form>
            ) : null}

            {status === 'delivered' ? (
              <div
                style={{
                  padding: 'var(--space-4)',
                  background: 'var(--color-emerald-50)',
                  border: '1px solid var(--color-emerald-200)',
                  borderRadius: 'var(--radius-md)',
                  textAlign: 'center',
                }}
              >
                <i className="fas fa-check-circle" style={{ color: 'var(--color-emerald-500)', fontSize: 24, display: 'block', marginBottom: 'var(--space-2)' }} />
                <p style={{ fontWeight: 500, color: 'var(--color-emerald-700)', margin: 0 }}>ออเดอร์เสร็จสมบูรณ์</p>
              </div>
            ) : null}
          </div>

          {shippingTracking ? (
            <div style={{ padding: 'var(--space-3)', background: 'rgba(124,58,237,0.08)', borderRadius: 'var(--radius-md)', marginBottom: 'var(--space-4)' }}>
              <p style={{ fontSize: 'var(--text-sm)', color: 'var(--color-violet-700)', margin: 0 }}>
                <i className="fas fa-truck" style={{ marginRight: 4 }} />
                เลขพัสดุ: <strong style={{ fontFamily: 'var(--font-mono)' }}>{shippingTracking}</strong>
              </p>
            </div>
          ) : null}

          {canCancel ? (
            <div style={{ borderTop: '1px solid var(--color-slate-200)', paddingTop: 'var(--space-4)' }}>
              <form action={updateStatusAction} onSubmit={handleCancelSubmit}>
                <input type="hidden" name="status" value="cancelled" />
                <button type="submit" className="od-btn-cancel-order">
                  <i className="fas fa-times" style={{ marginRight: 'var(--space-2)' }} />
                  ยกเลิกออเดอร์
                </button>
              </form>
            </div>
          ) : null}
        </div>
      </div>

      <div className="od-detail-section">
        <div className="od-detail-section-hdr">
          <h4>🔧 เปลี่ยนสถานะ (Manual)</h4>
        </div>
        <div className="od-detail-section-body">
          <form action={updateStatusAction} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <select name="status" defaultValue={status} className="od-form-sel">
              <option value="pending">⏳ รอยืนยัน</option>
              <option value="confirmed">✅ ยืนยันแล้ว</option>
              <option value="paid">💰 ชำระแล้ว</option>
              <option value="shipping">🚚 กำลังจัดส่ง</option>
              <option value="delivered">📦 จัดส่งแล้ว</option>
              <option value="cancelled">❌ ยกเลิก</option>
            </select>
            <button type="submit" className="od-btn-save" style={{ width: '100%', justifyContent: 'center' }}>
              <i className="fas fa-save" />
              อัพเดท
            </button>
          </form>
        </div>
      </div>
    </>
  );
}
