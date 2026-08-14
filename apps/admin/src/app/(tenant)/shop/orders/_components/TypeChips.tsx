import { TRANSACTION_TYPES } from '../_lib/constants';

/**
 * TypeChips.tsx — React port of shop/orders.php's `.order-type-bar` (lines
 * 568-583): "ทุกประเภท" + one chip per TRANSACTION_TYPES key + the 💊 จ่ายยา
 * (dispense) tab chip. Server Component — every link is a plain `<a href>`,
 * matching PHP's own full-page-reload navigation exactly (no client JS
 * needed here; only the pending-order "ยืนยัน" button elsewhere on this page
 * needs a Client Component).
 *
 * href preservation is REPRODUCED PER-LINK, not generalized, because PHP
 * itself is inconsistent about what each link keeps:
 *   - "ทุกประเภท" -> literal `href="?"` (line 570): drops EVERY param,
 *     including `status`.
 *   - each type chip -> `?type=X` + `&status=Y` IF a status filter is
 *     active (line 572) — status IS preserved here.
 *   - the dispense chip -> literal `?view=dispense` (line 575): drops
 *     everything, including type/status.
 * None of these ever carry `page` or `pending_slip` forward — switching tab
 * always resets pagination and the pending-slip filter, matching PHP.
 */
export interface TypeChipsProps {
  typeFilter: string;
  statusFilter: string;
  viewDispense: boolean;
  dispenseCount: number;
}

const BASE_CHIP =
  'inline-flex items-center gap-1 px-3.5 py-1.5 rounded-lg text-sm border bg-white border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors';
const ACTIVE_CHIP = 'bg-primary-600 border-primary-600 text-white hover:bg-primary-600';
const DISPENSE_ACTIVE_CHIP = 'bg-emerald-500 border-emerald-500 text-white hover:bg-emerald-500';

export function TypeChips({ typeFilter, statusFilter, viewDispense, dispenseCount }: TypeChipsProps) {
  const allActive = !typeFilter && !viewDispense;

  return (
    <div className="flex flex-wrap gap-2 mb-4">
      <a href="/shop/orders" className={`${BASE_CHIP} ${allActive ? ACTIVE_CHIP : ''}`}>
        ทุกประเภท
      </a>

      {Object.entries(TRANSACTION_TYPES).map(([key, type]) => {
        const href = `/shop/orders?type=${key}${statusFilter ? `&status=${statusFilter}` : ''}`;
        const active = typeFilter === key;
        return (
          <a key={key} href={href} className={`${BASE_CHIP} ${active ? ACTIVE_CHIP : ''}`}>
            {type.icon} {type.label}
          </a>
        );
      })}

      <a href="/shop/orders?view=dispense" className={`${BASE_CHIP} ${viewDispense ? DISPENSE_ACTIVE_CHIP : ''}`}>
        💊 จ่ายยา
        {dispenseCount > 0 ? (
          <span className="ml-1 px-2 py-0.5 rounded-full text-xs bg-emerald-600 text-white">{dispenseCount}</span>
        ) : null}
      </a>
    </div>
  );
}
