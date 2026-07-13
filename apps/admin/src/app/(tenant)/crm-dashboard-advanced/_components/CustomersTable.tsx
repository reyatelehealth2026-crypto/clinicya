'use client';

import { useState } from 'react';
import { DataTable, type DataTableColumn } from '@/components/DataTable';
import { Customer360Modal } from './Customer360Modal';
import { formatCustomerDate } from '../_lib/format';
import type { CustomerRow } from '../queries';

/**
 * CustomersTable.tsx — client island port of customers-list.php's table
 * (`renderCustomersTable()`, lines 72-112) + `openCustomer360()`. Pagination
 * itself is rendered server-side by the parent CustomersTab (a plain
 * `Pagination` link bar needs no client JS) — this component owns only the
 * one thing that genuinely needs client state: which customer's 360 modal
 * is open.
 */
export function CustomersTable({ customers }: { customers: CustomerRow[] }) {
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const columns: DataTableColumn<CustomerRow>[] = [
    {
      key: 'customer',
      label: 'Customer',
      render: (c) => (
        <div>
          <p className="font-medium text-sm">{c.display_name || 'Unknown'}</p>
          <p className="text-xs text-gray-500">{c.line_user_id || ''}</p>
        </div>
      ),
    },
    {
      key: 'tags',
      label: 'Tags',
      render: (c) =>
        c.tags ? (
          <div className="flex flex-wrap gap-1">
            {c.tags.split(',').map((t) => (
              <span key={t} className="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">
                {t.trim()}
              </span>
            ))}
          </div>
        ) : (
          '-'
        ),
    },
    {
      key: 'deals',
      label: 'Deals',
      render: (c) => <span className={`text-xs px-1.5 py-0.5 rounded ${c.deals_count > 0 ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'}`}>{c.deals_count}</span>,
    },
    {
      key: 'tickets',
      label: 'Tickets',
      render: (c) => <span className={`text-xs px-1.5 py-0.5 rounded ${c.tickets_count > 0 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500'}`}>{c.tickets_count}</span>,
    },
    { key: 'joined', label: 'Joined', render: (c) => <span className="text-sm text-gray-500">{formatCustomerDate(c.created_at)}</span> },
    {
      key: 'actions',
      label: 'Actions',
      render: (c) => (
        <button type="button" onClick={() => setSelectedId(c.id)} className="px-2 py-1 rounded bg-gray-100 text-gray-600 text-xs">
          👁
        </button>
      ),
    },
  ];

  return (
    <>
      <DataTable columns={columns} rows={customers} emptyContent="No customers found" />
      <Customer360Modal customerId={selectedId} onClose={() => setSelectedId(null)} />
    </>
  );
}
