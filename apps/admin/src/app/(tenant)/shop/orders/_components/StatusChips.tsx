import { ORDER_STATUS_KEYS, ORDER_STATUSES, STATUS_FILTER_ACTIVE_CLASS } from '../_lib/constants';

/**
 * StatusChips.tsx — React port of shop/orders.php's `.status-filter-bar`
 * (lines 603-624): the "ทั้งหมด" chip, the conditional "รอตรวจสลิป"
 * (pending-slip) chip, and one chip per ORDER_STATUSES key. Server
 * Component — plain `<a href>` links, same full-page-reload navigation as
 * PHP.
 *
 * "ทั้งหมด"'s active color is a SPECIAL-CASED emerald-500 (line 606: literal
 * `--color-emerald-500`), NOT drawn from any per-status color map — reproduced
 * as its own literal class, not derived from STATUS_FILTER_ACTIVE_CLASS.
 *
 * href preservation, per PHP: every chip here preserves `type` (if set),
 * NEVER `status` (switching status/pending-slip always starts from the
 * current type filter, dropping the previous status/pending-slip choice —
 * lines 604, 610, 618), and never `page`.
 */
export interface StatusChipsProps {
  statusFilter: string;
  typeFilter: string;
  pendingSlip: boolean;
  statusCounts: Record<string, number>;
  pendingSlipsCount: number;
}

const BASE_CHIP =
  'inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm border bg-white border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors';
const ALL_ACTIVE_CHIP = 'bg-emerald-500 border-emerald-500 text-white hover:bg-emerald-500';
const PENDING_SLIP_ACTIVE_CHIP = 'bg-amber-500 border-amber-500 text-white hover:bg-amber-500';

function typeSuffix(typeFilter: string, leading: '?' | '&'): string {
  return typeFilter ? `${leading}type=${typeFilter}` : '';
}

export function StatusChips({ statusFilter, typeFilter, pendingSlip, statusCounts, pendingSlipsCount }: StatusChipsProps) {
  const totalCount = Object.values(statusCounts).reduce((sum, c) => sum + c, 0);
  const allActive = !statusFilter && !pendingSlip;

  return (
    <div className="flex flex-wrap gap-2 mb-6">
      <a href={`/shop/orders${typeSuffix(typeFilter, '?')}`} className={`${BASE_CHIP} ${allActive ? ALL_ACTIVE_CHIP : ''}`}>
        ทั้งหมด <span className="text-xs ml-1">({totalCount})</span>
      </a>

      {pendingSlipsCount > 0 ? (
        <a
          href={`/shop/orders?pending_slip=1${typeSuffix(typeFilter, '&')}`}
          className={`${BASE_CHIP} ${pendingSlip ? PENDING_SLIP_ACTIVE_CHIP : ''}`}
        >
          <i className="fas fa-receipt" aria-hidden="true" /> รอตรวจสลิป
          <span className="ml-1 px-2 py-0.5 rounded-full text-xs bg-amber-600 text-white">{pendingSlipsCount}</span>
        </a>
      ) : null}

      {ORDER_STATUS_KEYS.map((key) => {
        const status = ORDER_STATUSES[key];
        const active = statusFilter === key;
        return (
          <a
            key={key}
            href={`/shop/orders?status=${key}${typeSuffix(typeFilter, '&')}`}
            className={`${BASE_CHIP} ${active ? STATUS_FILTER_ACTIVE_CLASS[key] : ''}`}
          >
            {status.label} <span className="text-xs ml-1">({statusCounts[key] ?? 0})</span>
          </a>
        );
      })}
    </div>
  );
}
