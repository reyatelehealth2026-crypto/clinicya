import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getTickets } from '../queries';
import { TicketsTable } from './TicketsTable';

/**
 * TicketsTab.tsx — Server Component port of tickets-list.php's "All
 * Tickets" (`loadTicketsList()` -> `crmApi('tickets', filters)`). The
 * search input (`#all-tickets-search`) in the PHP source is wired to
 * `loadTicketsList()` on Enter, but `loadTicketsList()` never actually
 * reads `#all-tickets-search`'s value into its filters object (confirmed by
 * reading the full function — only `status` and a fixed `limit:50` are
 * sent) — a dead search box in production. Rendered here as a plain
 * (non-functional-in-PHP-too) text field, consistent with "preserve
 * behavior, not markup".
 */
export interface TicketsTabProps {
  db: Kysely<TenantDB>;
  status: string;
}

const STATUS_OPTIONS = ['open', 'pending', 'resolved', 'closed'];

export async function TicketsTab({ db, status }: TicketsTabProps) {
  const result = await getTickets(db, { status: status || null, limit: 50 });

  return (
    <div>
      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <form method="GET" className="flex items-center gap-2">
          <input type="hidden" name="tab" value="tickets" />
          <input type="text" name="search" placeholder="Search tickets..." className="border rounded-md text-sm px-3 py-1.5 w-64" disabled title="Not functional in the PHP source either — loadTicketsList() never reads this field" />
          <select name="status" defaultValue={status} className="border rounded-md text-sm px-2 py-1.5">
            <option value="">All Status</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
          <button type="submit" className="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm">
            Filter
          </button>
        </form>
      </div>

      <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <TicketsTable tickets={result.tickets} />
      </div>
    </div>
  );
}
