'use client';

import { useEffect, useState } from 'react';
import { Modal } from '@/components/Modal';
import { getCustomer360Action } from '../actions';
import { formatMoney } from '../_lib/format';
import type { Customer360 } from '../queries';

/**
 * Customer360Modal.tsx — client island port of crm-dashboard-advanced.php's
 * page-shell `#customer360Modal` (lines 631-652) +
 * `openCustomer360()`/`loadCustomer360Data()`/`renderCustomer360Content()`
 * (lines 774-817). Lazy-loads via `getCustomer360Action` when opened, same
 * as the PHP source's `crmApi('customer_360', {customer_id})` call.
 *
 * The PHP source's tab strip (Profile/Orders/Timeline/Financial) is
 * decorative — none of the 3 non-Profile tab buttons have an onclick handler
 * (confirmed by reading the full `renderCustomer360Content()` template
 * literal), so only the Profile content they all currently render is
 * reproduced.
 */
export function Customer360Modal({ customerId, onClose }: { customerId: number | null; onClose: () => void }) {
  const [data, setData] = useState<Customer360 | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (customerId === null) {
      setData(null);
      return;
    }
    setLoading(true);
    getCustomer360Action(customerId)
      .then((result) => setData(result))
      .finally(() => setLoading(false));
  }, [customerId]);

  return (
    <Modal open={customerId !== null} onClose={onClose} title={data?.display_name || 'Customer'} size="lg">
      {loading || !data ? (
        <div className="text-center py-8 text-gray-400">Loading…</div>
      ) : (
        <div>
          <p className="text-sm text-gray-500 mb-4">
            LINE ID: {data.line_user_id} | Customer Ref: {data.id}
          </p>
          <div className="flex gap-1 mb-4 border-b border-gray-200">
            <span className="px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600">Profile</span>
            <span className="px-4 py-2 text-sm font-medium text-gray-400">Orders ({data.orders_count})</span>
            <span className="px-4 py-2 text-sm font-medium text-gray-400">Timeline</span>
            <span className="px-4 py-2 text-sm font-medium text-gray-400">Financial</span>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div className="bg-gray-50 p-4 rounded-md">
              <h4 className="font-semibold mb-3">Contact Info</h4>
              <p className="text-sm text-gray-600 mb-1">Phone: {data.phone || '-'}</p>
              <p className="text-sm text-gray-600 mb-1">Email: {data.email || '-'}</p>
              <p className="text-sm text-gray-600">LINE: {data.line_user_id || '-'}</p>
            </div>
            <div className="bg-gray-50 p-4 rounded-md">
              <h4 className="font-semibold mb-3">Customer Stats</h4>
              <p className="text-sm text-gray-600 mb-1">Total Orders: {data.orders_count}</p>
              <p className="text-sm text-gray-600 mb-1">Total Spent: ฿{formatMoney(data.total_spent)}</p>
              <p className="text-sm text-gray-600">Deals: {data.deals_count} · Tickets: {data.tickets_count}</p>
            </div>
          </div>
          {data.tags.length > 0 ? (
            <div className="mt-4 flex flex-wrap gap-2">
              {data.tags.map((t) => (
                <span key={t.id} className="text-xs px-2 py-1 rounded-full text-white" style={{ backgroundColor: t.color ?? '#3B82F6' }}>
                  {t.name}
                </span>
              ))}
            </div>
          ) : null}
        </div>
      )}
    </Modal>
  );
}
