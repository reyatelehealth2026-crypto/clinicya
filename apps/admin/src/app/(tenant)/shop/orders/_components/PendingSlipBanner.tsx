/**
 * PendingSlipBanner.tsx — React port of shop/orders.php's `.slip-alert`
 * banner (lines 587-601). Server Component, only rendered by the caller
 * when `pendingSlipsCount > 0` (mirrors PHP's own `if ($pendingSlipsCount >
 * 0): ... endif;` gate at lines 587/601) — this component itself does not
 * re-check the count, matching PendingSlipBanner's sibling components
 * (StatusChips does its own `pendingSlipsCount > 0` check internally for
 * its OWN pending-slip chip; this banner is a visually distinct element and
 * the caller gates it explicitly, see page.tsx).
 *
 * href preserves `type` only (line 596), same rule as StatusChips' own
 * pending-slip chip.
 */
export interface PendingSlipBannerProps {
  pendingSlipsCount: number;
  typeFilter: string;
}

export function PendingSlipBanner({ pendingSlipsCount, typeFilter }: PendingSlipBannerProps) {
  const href = `/shop/orders?pending_slip=1${typeFilter ? `&type=${typeFilter}` : ''}`;
  return (
    <div className="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between gap-3 flex-wrap">
      <div className="flex items-center gap-3">
        <i className="fas fa-receipt text-xl text-amber-500" aria-hidden="true" />
        <div>
          <p className="font-semibold text-amber-700 m-0">มีสลิปรอตรวจสอบ {pendingSlipsCount} รายการ</p>
          <p className="text-sm text-amber-600 m-0">กรุณาตรวจสอบและอนุมัติสลิปการชำระเงิน</p>
        </div>
      </div>
      <a href={href} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-amber-500 text-white hover:bg-amber-600">
        <i className="fas fa-eye" aria-hidden="true" />
        ดูรายการ
      </a>
    </div>
  );
}
