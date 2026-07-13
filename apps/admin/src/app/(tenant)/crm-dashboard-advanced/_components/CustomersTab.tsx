import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { Pagination } from '@/components/Pagination';
import { getCustomers } from '../queries';
import { CustomersTable } from './CustomersTable';

const CUSTOMERS_PER_PAGE = 50;

/**
 * CustomersTab.tsx — Server Component port of customers-list.php
 * (`loadCustomersList()` -> `crmApi('customers', filters)`). The PHP
 * source's tag filter `<select id="customer-tag-filter">` starts with only
 * an "All Tags" option and NOTHING ever populates it further (no
 * `crmApi()`/fetch call anywhere in customers-list.php touches tags) — a
 * dead, permanently-empty dropdown in production. Omitted here rather than
 * reproducing a filter that has never worked.
 */
export interface CustomersTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  search: string;
  page: number;
}

export async function CustomersTab({ db, lineAccountId, search, page }: CustomersTabProps) {
  const offset = (page - 1) * CUSTOMERS_PER_PAGE;
  const result = await getCustomers(db, lineAccountId, { search, limit: CUSTOMERS_PER_PAGE, offset });
  const totalPages = Math.max(1, Math.ceil(result.total / CUSTOMERS_PER_PAGE));

  return (
    <div>
      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <form method="GET" className="flex items-center gap-2">
          <input type="hidden" name="tab" value="customers" />
          <input type="text" name="search" defaultValue={search} placeholder="Search customers..." className="border rounded-md text-sm px-3 py-1.5 w-64" />
          <button type="submit" className="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm">
            Search
          </button>
        </form>
        <a href="/crm-dashboard-advanced?tab=customers" className="px-3 py-1.5 rounded-md bg-slate-900 text-white text-xs font-medium">
          ⟳ Refresh
        </a>
      </div>

      <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <CustomersTable customers={result.customers} />
        <div className="border-t border-gray-200 p-3">
          <Pagination currentPage={page} totalPages={totalPages} perPage={CUSTOMERS_PER_PAGE} basePath="/crm-dashboard-advanced" queryParams={{ tab: 'customers', search: search || undefined }} total={result.total} />
        </div>
      </div>
    </div>
  );
}
