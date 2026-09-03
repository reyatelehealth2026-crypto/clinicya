'use client';

import { DataTable, type DataTableColumn } from '@/components/DataTable';
import { ticketStatusBadgeClass, ticketPriorityBadgeClass, formatShortDate, formatSla } from '../_lib/format';
import type { TicketRow } from '../queries';

/**
 * TicketsTable.tsx — shared client island backing BOTH service-center.php's
 * ticket table (`renderTicketsTable()`, includes an SLA column) and
 * tickets-list.php's "All Tickets" table (`renderAllTicketsTable()`, no SLA
 * column) — the two partials are otherwise near-identical row renderers, so
 * `showSla` toggles the one real column difference between them rather than
 * duplicating the table.
 *
 * `viewTicket()` in BOTH PHP sources is a bare `alert('View ticket: ' +
 * ticketId)` stub (no ticket-detail modal exists anywhere in the page) —
 * mirrored literally.
 */
export function TicketsTable({ tickets, showSla = false }: { tickets: TicketRow[]; showSla?: boolean }) {
  const columns: DataTableColumn<TicketRow>[] = [
    { key: 'id', label: 'Ticket', render: (t) => <span className="font-mono text-xs">#{t.id}</span> },
    { key: 'customer', label: 'Customer', render: (t) => <span className="text-sm">{t.customer_name || 'Unknown'}</span> },
    { key: 'subject', label: 'Subject', render: (t) => <span className="font-medium text-sm">{t.subject}</span> },
    {
      key: 'status',
      label: 'Status',
      render: (t) => <span className={`text-xs px-2 py-0.5 rounded ${ticketStatusBadgeClass(t.status)}`}>{t.status}</span>,
    },
    {
      key: 'priority',
      label: 'Priority',
      render: (t) => <span className={`text-xs px-2 py-0.5 rounded ${ticketPriorityBadgeClass(t.priority)}`}>{t.priority}</span>,
    },
    ...(showSla
      ? ([
          {
            key: 'sla',
            label: 'SLA',
            render: (t: TicketRow) => {
              const sla = formatSla(t.sla_deadline);
              return <span className={`text-sm ${sla.breached ? 'text-red-600 font-medium' : 'text-gray-500'}`}>{sla.text}</span>;
            },
          },
        ] as DataTableColumn<TicketRow>[])
      : []),
    { key: 'created', label: 'Created', render: (t) => <span className="text-sm text-gray-500">{formatShortDate(t.created_at)}</span> },
    {
      key: 'actions',
      label: 'Actions',
      render: (t) => (
        <button type="button" onClick={() => alert('View ticket: ' + t.id)} className="px-2 py-1 rounded bg-gray-100 text-gray-600 text-xs">
          👁
        </button>
      ),
    },
  ];

  return <DataTable columns={columns} rows={tickets} emptyContent="No tickets found" />;
}
