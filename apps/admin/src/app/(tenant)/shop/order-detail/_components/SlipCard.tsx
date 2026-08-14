'use client';

import { useRef, useState } from 'react';
import { computeSlipDisplay } from '../_lib/slipDisplay';
import { formatDateTimeDMY, formatMoney } from '../_lib/format';
import { decodeSlipQrFromImage } from './qrDecode';

/**
 * SlipCard.tsx — port of one iteration of the payment-slips `foreach`
 * (shop/order-detail.php lines 1062-1180): the slip image + status badge +
 * expand button, amount/admin_note footer, and the GhostX-result panel
 * (`if ($tr) ... elseif ($vErr) ... elseif (!empty($qrPayload) ...) ...
 * elseif (empty($qrPayload)) ...`), including the client-side "decode QR
 * from image, then submit" button (PHP's `decodeSlipAndVerify()` inline
 * script, lines 1293-1311 — now `./qrDecode.ts`'s `decodeSlipQrFromImage()`).
 *
 * 'use client': the QR-decode button and the modal-open callback both need
 * browser APIs / event handlers.
 */

export interface SlipCardSlip {
  id: number;
  status: 'approved' | 'pending' | 'rejected' | null;
  adminNote: string | null;
  createdAt: Date | string;
  amount: string | number | null;
  imageSrc: string;
  verifyRef: string | null;
  qrPayload: string | null;
  verifyData: string | null;
}

export interface SlipCardProps {
  slip: SlipCardSlip;
  orderGrandTotal: number;
  shopAccounts: string[];
  verifySlipAction: (formData: FormData) => void | Promise<void>;
  onOpenModal: (src: string) => void;
}

const STATUS_META: Record<'approved' | 'pending' | 'rejected', { cardClass: string; badge: string; badgeBg: string }> = {
  approved: { cardClass: 'od-slip-card-approved', badge: '✅ อนุมัติแล้ว', badgeBg: 'var(--color-emerald-500)' },
  rejected: { cardClass: 'od-slip-card-rejected', badge: '❌ ปฏิเสธ', badgeBg: 'var(--color-rose-500)' },
  pending: { cardClass: 'od-slip-card-pending', badge: '⏳ รอตรวจสอบ', badgeBg: 'var(--color-amber-500)' },
};

export function SlipCard({ slip, orderGrandTotal, shopAccounts, verifySlipAction, onOpenModal }: SlipCardProps) {
  const [decoding, setDecoding] = useState(false);
  const formRef = useRef<HTMLFormElement>(null);
  const qrInputRef = useRef<HTMLInputElement>(null);

  const meta = STATUS_META[slip.status ?? 'pending'] ?? STATUS_META.pending;
  const isApproved = slip.status === 'approved';

  const display = computeSlipDisplay(
    { verifyRef: slip.verifyRef, qrPayload: slip.qrPayload, verifyData: slip.verifyData },
    orderGrandTotal,
    shopAccounts
  );

  async function handleDecodeAndVerify() {
    setDecoding(true);
    try {
      const qr = await decodeSlipQrFromImage(slip.imageSrc);
      if (qr) {
        if (qrInputRef.current) qrInputRef.current.value = qr;
        formRef.current?.requestSubmit();
        return;
      }
      window.alert('ถอด QR จากรูปไม่สำเร็จ — ลองเปิดรูปเต็ม (ขยาย) แล้วลองใหม่ หรือกดอนุมัติเพื่อยืนยันเอง');
    } catch (e) {
      window.alert('เกิดข้อผิดพลาดในการถอด QR: ' + (e instanceof Error ? e.message : String(e)));
    }
    setDecoding(false);
  }

  return (
    <div className={`od-slip-card ${meta.cardClass}`}>
      <div style={{ position: 'relative', background: 'var(--color-slate-100)' }}>
        <img
          src={slip.imageSrc}
          alt="payment slip"
          style={{ width: '100%', maxHeight: 256, objectFit: 'contain', cursor: 'pointer', display: 'block' }}
          onClick={() => onOpenModal(slip.imageSrc)}
        />
        <div style={{ position: 'absolute', top: 8, right: 8 }}>
          <span
            style={{
              padding: '4px 10px',
              borderRadius: 'var(--radius-full)',
              fontSize: 'var(--text-xs)',
              fontWeight: 500,
              background: meta.badgeBg,
              color: '#fff',
              boxShadow: 'var(--shadow-glass)',
            }}
          >
            {meta.badge}
          </span>
        </div>
        <button
          onClick={() => onOpenModal(slip.imageSrc)}
          style={{
            position: 'absolute',
            bottom: 8,
            right: 8,
            padding: '4px 10px',
            background: 'rgba(0,0,0,0.5)',
            color: '#fff',
            border: 'none',
            borderRadius: 'var(--radius-sm)',
            fontSize: 'var(--text-xs)',
            cursor: 'pointer',
          }}
        >
          <i className="fas fa-expand" style={{ marginRight: 4 }} />
          ขยาย
        </button>
      </div>

      <div style={{ padding: 'var(--space-3)', background: '#fff' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 'var(--text-sm)' }}>
          <span style={{ color: 'var(--color-dark-500)' }}>
            <i className="fas fa-clock" style={{ marginRight: 4 }} />
            {formatDateTimeDMY(slip.createdAt)}
          </span>
          {slip.amount ? <span style={{ fontWeight: 500, color: 'var(--color-emerald-600)' }}>฿{formatMoney(slip.amount)}</span> : null}
        </div>
        {slip.adminNote ? (
          <p style={{ fontSize: 'var(--text-xs)', color: 'var(--color-dark-500)', margin: '4px 0 0' }}>
            <i className="fas fa-sticky-note" style={{ marginRight: 4 }} />
            {slip.adminNote}
          </p>
        ) : null}

        <div style={{ marginTop: 8, paddingTop: 8, borderTop: '1px dashed var(--color-slate-200)', fontSize: 'var(--text-xs)' }}>
          {display.transfer ? (
            <>
              <div style={{ fontWeight: 600, color: 'var(--color-dark-700)', marginBottom: 4 }}>
                <i className="fas fa-shield-alt" style={{ marginRight: 4, color: '#6366f1' }} />
                ผลตรวจสลิป (GhostX)
              </div>
              <div style={{ color: 'var(--color-dark-600)', lineHeight: 1.8 }}>
                <div>
                  ยอดในสลิป: <b>฿{formatMoney(display.transfer.amount ?? 0)}</b>{' '}
                  {display.amountOk ? (
                    <span style={{ color: 'var(--color-emerald-600)' }}>✓ ตรง</span>
                  ) : (
                    <span style={{ color: 'var(--color-rose-500)' }}>✗ ออเดอร์ ฿{formatMoney(orderGrandTotal)}</span>
                  )}
                </div>
                {display.transfer.toAccountNo ? (
                  <div>
                    เข้าบัญชี: <b>{display.transfer.toAccountNo}</b>{' '}
                    {display.accountOk ? (
                      <span style={{ color: 'var(--color-emerald-600)' }}>✓ ตรงบัญชีร้าน</span>
                    ) : (
                      <span style={{ color: 'var(--color-rose-500)' }}>✗ ไม่ตรงบัญชีร้าน</span>
                    )}
                  </div>
                ) : null}
                {display.transfer.fromName ? <div>จาก: {display.transfer.fromName}</div> : null}
                {display.transfer.transactionRef ? (
                  <div style={{ color: 'var(--color-dark-400)' }}>
                    Ref: {display.transfer.transactionRef}
                    {display.transfer.transactionDateTime ? ` · ${formatDateTimeDMY(display.transfer.transactionDateTime)}` : ''}
                  </div>
                ) : null}
              </div>

              {display.verifyRef ? (
                <div style={{ color: 'var(--color-emerald-600)', fontWeight: 600, marginTop: 4 }}>✅ ยืนยันแล้ว — อนุมัติการชำระแล้ว</div>
              ) : display.amountOk ? (
                <>
                  <div style={{ color: 'var(--color-emerald-600)', fontWeight: 600, marginTop: 4 }}>
                    ✓ GhostX ยืนยันสลิปจริง + ยอดตรง — พร้อมอนุมัติ
                    {display.transfer.toAccountNo && !display.accountOk ? ' (บัญชีปลายทางต่างจากที่ตั้งค่า โปรดตรวจดู)' : ''}
                  </div>
                  {!isApproved ? (
                    <form action={verifySlipAction} style={{ margin: '6px 0 0' }}>
                      <input type="hidden" name="slip_id" value={slip.id} />
                      <button
                        type="submit"
                        style={{
                          width: '100%',
                          padding: '6px 10px',
                          background: '#059669',
                          color: '#fff',
                          border: 'none',
                          borderRadius: 'var(--radius-sm)',
                          fontSize: 'var(--text-xs)',
                          fontWeight: 600,
                          cursor: 'pointer',
                        }}
                      >
                        <i className="fas fa-check-circle" style={{ marginRight: 4 }} />
                        อนุมัติ (GhostX + ยอดตรง)
                      </button>
                    </form>
                  ) : null}
                </>
              ) : (
                <>
                  <div style={{ color: 'var(--color-rose-500)', marginTop: 4 }}>⚠️ ยอดในสลิปไม่ตรงกับออเดอร์ — ตรวจสอบก่อนอนุมัติ</div>
                  {!isApproved ? (
                    <form action={verifySlipAction} style={{ margin: '6px 0 0' }}>
                      <input type="hidden" name="slip_id" value={slip.id} />
                      <button
                        type="submit"
                        style={{
                          width: '100%',
                          padding: '6px 10px',
                          background: '#6366f1',
                          color: '#fff',
                          border: 'none',
                          borderRadius: 'var(--radius-sm)',
                          fontSize: 'var(--text-xs)',
                          fontWeight: 500,
                          cursor: 'pointer',
                        }}
                      >
                        <i className="fas fa-rotate-right" style={{ marginRight: 4 }} />
                        ประเมินซ้ำ
                      </button>
                    </form>
                  ) : null}
                </>
              )}
            </>
          ) : display.ghostxError ? (
            <>
              <div style={{ fontWeight: 600, color: 'var(--color-rose-600)', marginBottom: 2 }}>
                <i className="fas fa-triangle-exclamation" style={{ marginRight: 4 }} />
                GhostX ตรวจสลิปไม่ผ่าน
              </div>
              <div style={{ color: 'var(--color-dark-600)' }}>
                ข้อความจาก GhostX: <b>{display.ghostxError}</b>
              </div>
              <div style={{ color: 'var(--color-dark-400)', marginTop: 2 }}>
                มักเกิดจากรูปไม่ใช่สลิปโอนสำเร็จ / QR ไม่มีรหัสอ้างอิงรายการ — ตรวจรูปกับลูกค้า หรือกดอนุมัติเพื่อยืนยันเอง
              </div>
              {display.qrPayload && !isApproved ? (
                <form action={verifySlipAction} style={{ margin: '6px 0 0' }}>
                  <input type="hidden" name="slip_id" value={slip.id} />
                  <button
                    type="submit"
                    style={{
                      width: '100%',
                      padding: '6px 10px',
                      background: '#6366f1',
                      color: '#fff',
                      border: 'none',
                      borderRadius: 'var(--radius-sm)',
                      fontSize: 'var(--text-xs)',
                      fontWeight: 500,
                      cursor: 'pointer',
                    }}
                  >
                    <i className="fas fa-rotate-right" style={{ marginRight: 4 }} />
                    ลองตรวจกับ GhostX อีกครั้ง
                  </button>
                </form>
              ) : null}
            </>
          ) : display.qrPayload && !isApproved ? (
            <form action={verifySlipAction} style={{ margin: 0 }}>
              <input type="hidden" name="slip_id" value={slip.id} />
              <button
                type="submit"
                style={{
                  width: '100%',
                  padding: '6px 10px',
                  background: '#6366f1',
                  color: '#fff',
                  border: 'none',
                  borderRadius: 'var(--radius-sm)',
                  fontSize: 'var(--text-xs)',
                  fontWeight: 500,
                  cursor: 'pointer',
                }}
              >
                <i className="fas fa-qrcode" style={{ marginRight: 4 }} />
                ตรวจสอบกับ GhostX
              </button>
            </form>
          ) : !display.qrPayload ? (
            <>
              <div style={{ color: 'var(--color-dark-400)', marginBottom: 6 }}>
                <i className="fas fa-info-circle" style={{ marginRight: 4 }} />
                ยังไม่มีข้อมูล QR (ลูกค้าอัปผ่านช่องที่ไม่ถอด QR หรือถอดไม่ติด) — ถอดจากรูปได้
              </div>
              {!isApproved ? (
                <form ref={formRef} action={verifySlipAction} style={{ margin: 0 }}>
                  <input type="hidden" name="slip_id" value={slip.id} />
                  <input type="hidden" name="qr_data" ref={qrInputRef} />
                  <button
                    type="button"
                    disabled={decoding}
                    onClick={handleDecodeAndVerify}
                    style={{
                      width: '100%',
                      padding: '6px 10px',
                      background: '#6366f1',
                      color: '#fff',
                      border: 'none',
                      borderRadius: 'var(--radius-sm)',
                      fontSize: 'var(--text-xs)',
                      fontWeight: 500,
                      cursor: 'pointer',
                    }}
                  >
                    <i className={decoding ? 'fas fa-spinner fa-spin' : 'fas fa-qrcode'} style={{ marginRight: 4 }} />
                    {decoding ? 'กำลังถอด QR...' : 'ถอด QR จากรูป & ตรวจสอบ'}
                  </button>
                </form>
              ) : null}
            </>
          ) : null}
        </div>
      </div>
    </div>
  );
}
