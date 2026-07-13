import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getTickets, getTicketStats, getCustomers } from '../queries';
import { TicketsTable } from './TicketsTable';
import { CreateTicketModal } from './CreateTicketModal';
import type { DealCustomerOption } from './AddDealModal';

/**
 * ServiceCenterTab.tsx — Server Component port of service-center.php
 * (`loadServiceData()` -> `crmApi('tickets', filters)` + `crmApi('ticket_stats')`).
 * The status/priority `<select onchange="loadServiceData()">` filters are
 * ported as a plain GET form (query-param driven, `?tab=service&status=&priority=`)
 * — same "no client JS needed" convention as apps/admin/src/components/
 * Toolbar.tsx.
 */
export interface ServiceCenterTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  status: string;
  priority: string;
}

const STATUS_OPTIONS = ['open', 'pending', 'resolved', 'closed'];
const PRIORITY_OPTIONS = ['urgent', 'high', 'medium', 'low'];

export async function ServiceCenterTab({ db, lineAccountId, status, priority }: ServiceCenterTabProps) {
  const [ticketsResult, stats, customersForModal] = await Promise.all([
    getTickets(db, { status: status || null, priority: priority || null, limit: 50 }),
    getTicketStats(db),
    getCustomers(db, lineAccountId, { limit: 100 }),
  ]);
  const customerOptions: DealCustomerOption[] = customersForModal.customers.map((c) => ({ id: c.id, display_name: c.display_name, line_user_id: c.line_user_id }));

  return (
    <div>
      <div className="grid grid-cols-4 gap-3 mb-4">
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Open Tickets</div>
          <div className="text-xl font-mono font-semibold text-blue-600">{stats.byStatus.open ?? 0}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">In Progress</div>
          <div className="text-xl font-mono font-semibold text-yellow-600">{stats.byStatus.pending ?? 0}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">SLA Breach</div>
          <div className="text-xl font-mono font-semibold text-red-600">{stats.breachedSla + stats.approachingSla}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Resolved Today</div>
          <div className="text-xl font-mono font-semibold text-green-600">{stats.byStatus.resolved ?? 0}</div>
        </div>
      </div>

      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <form method="GET" className="flex items-center gap-2">
          <input type="hidden" name="tab" value="service" />
          <select name="status" defaultValue={status} className="border rounded-md text-sm px-2 py-1.5">
            <option value="">All Status</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
          <select name="priority" defaultValue={priority} className="border rounded-md text-sm px-2 py-1.5">
            <option value="">All Priority</option>
            {PRIORITY_OPTIONS.map((p) => (
              <option key={p} value={p}>
                {p}
              </option>
            ))}
          </select>
          <button type="submit" className="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm">
            Filter
          </button>
        </form>
        <CreateTicketModal customers={customerOptions} />
      </div>

      <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <TicketsTable tickets={ticketsResult.tickets} showSla />
      </div>
    </div>
  );
}
