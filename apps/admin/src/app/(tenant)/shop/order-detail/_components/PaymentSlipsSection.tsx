'use client';

import { useState, type FormEvent } from 'react';
import { resolveVerifyBanner } from '../_lib/verifyBanner';
import { SlipCard, type SlipCardSlip } from './SlipCard';
import { SlipModal } from './SlipModal';

/**
 * PaymentSlipsSection.tsx — port of shop/order-detail.php's "💳
 * หลักฐานการชำระเงิน" card (PHP lines 1015-1202): the payment-status banner,
 * the `?verify=` result banner, the slip list (or empty state), and the
 * approve/reject action buttons — orchestrating `SlipCard` + `SlipModal`
 * and owning the shared "which slip image is open in the modal" state.
 *
 * 'use client': owns modal-open state and the approve/reject buttons'
 * `confirm()` dialogs (PHP: `onclick="return confirm('ยืนยันการชำระเงิน?')"`
 * / `onclick="return confirm('ปฏิเสธหลักฐานนี้?')"`, lines 1188/1194).
 */

export interface PaymentSlipsSectionProps {
  paymentStatus: string | null;
  slips: SlipCardSlip[];
  orderGrandTotal: number;
  shopAccounts: string[];
  verifyReason?: string;
  verifySlipAction: (formData: FormData) => void | Promise<void>;
  approvePaymentAction: (formData: FormData) => void | Promise<void>;
  rejectPaymentAction: (formData: FormData) => void | Promise<void>;
}

const BANNER_COLORS: Record<string, { bg: string; border: string; text: string }> = {
  emerald: { bg: 'var(--color-emerald-50)', border: 'var(--color-emerald-200)', text: 'var(--color-emerald-700)' },
  rose: { bg: 'var(--color-rose-50)', border: 'var(--color-rose-200)', text: 'var(--color-rose-700)' },
  amber: { bg: 'var(--color-amber-50)', border: 'var(--color-amber-200)', text: 'var(--color-amber-700)' },
  slate: { bg: 'var(--color-slate-50)', border: 'var(--color-slate-200)', text: 'var(--color-slate-700)' },
};

function confirmSubmit(message: string) {
  return (e: FormEvent<HTMLFormElement>) => {
    if (!window.confirm(message)) {
      e.preventDefault();
    }
  };
}

export function PaymentSlipsSection({
  paymentStatus,
  slips,
  orderGrandTotal,
  shopAccounts,
  verifyReason,
  verifySlipAction,
  approvePaymentAction,
  rejectPaymentAction,
}: PaymentSlipsSectionProps) {
  const [modalSrc, setModalSrc] = useState<string | null>(null);
  const isPaid = paymentStatus === 'paid';
  const banner = verifyReason !== undefined ? resolveVerifyBanner(verifyReason) : null;
  const bannerColors = banner ? BANNER_COLORS[banner.color] : null;

  return (
    <div className="od-detail-section">
      <div className="od-detail-section-hdr">
        <h4>💳 หลักฐานการชำระเงิน</h4>
      </div>
      <div className="od-detail-section-body">
        <div
          style={{
            marginBottom: 'var(--space-4)',
            padding: 'var(--space-3)',
            borderRadius: 'var(--radius-md)',
            background: isPaid ? 'var(--color-emerald-50)' : 'var(--color-amber-50)',
            border: `1px solid ${isPaid ? 'var(--color-emerald-200)' : 'var(--color-amber-200)'}`,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: 'var(--text-sm)', fontWeight: 500, color: 'var(--color-dark-700)' }}>สถานะการชำระ:</span>
            <span
              style={{
                padding: '4px 12px',
                borderRadius: 'var(--radius-full)',
                fontSize: 'var(--text-xs)',
                fontWeight: 500,
                background: isPaid ? 'var(--color-emerald-500)' : 'var(--color-amber-500)',
                color: '#fff',
              }}
            >
              {isPaid ? '✅ ชำระแล้ว' : '⏳ รอชำระ'}
            </span>
          </div>
        </div>

        {banner && bannerColors ? (
          <div
            style={{
              marginBottom: 'var(--space-4)',
              padding: 'var(--space-3)',
              borderRadius: 'var(--radius-md)',
              background: bannerColors.bg,
              border: `1px solid ${bannerColors.border}`,
              color: bannerColors.text,
              fontSize: 'var(--text-sm)',
              fontWeight: 500,
            }}
          >
            {banner.message}
          </div>
        ) : null}

        {slips.length === 0 ? (
          <div style={{ textAlign: 'center', padding: 'var(--space-6)', background: 'var(--color-slate-50)', borderRadius: 'var(--radius-md)' }}>
            <i className="fas fa-receipt" style={{ fontSize: 36, color: 'var(--color-slate-300)', display: 'block', marginBottom: 'var(--space-2)' }} />
            <p style={{ color: 'var(--color-dark-500)', fontSize: 'var(--text-sm)', margin: 0 }}>ยังไม่มีหลักฐานการชำระเงิน</p>
          </div>
        ) : (
          <>
            <div>
              {slips.map((slip) => (
                <SlipCard
                  key={slip.id}
                  slip={slip}
                  orderGrandTotal={orderGrandTotal}
                  shopAccounts={shopAccounts}
                  verifySlipAction={verifySlipAction}
                  onOpenModal={setModalSrc}
                />
              ))}
            </div>

            {!isPaid ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-2)', marginTop: 'var(--space-4)' }}>
                <form action={approvePaymentAction} onSubmit={confirmSubmit('ยืนยันการชำระเงิน?')}>
                  <button type="submit" className="od-btn-approve" style={{ width: '100%' }}>
                    <i className="fas fa-check-circle" />
                    อนุมัติ
                  </button>
                </form>
                <form action={rejectPaymentAction} onSubmit={confirmSubmit('ปฏิเสธหลักฐานนี้?')}>
                  <button type="submit" className="od-btn-reject" style={{ width: '100%' }}>
                    <i className="fas fa-times-circle" />
                    ปฏิเสธ
                  </button>
                </form>
              </div>
            ) : null}
          </>
        )}
      </div>

      <SlipModal src={modalSrc} onClose={() => setModalSrc(null)} />
    </div>
  );
}
