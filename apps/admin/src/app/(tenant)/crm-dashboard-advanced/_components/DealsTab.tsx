import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getDealsList, getCustomers } from '../queries';
import { DEAL_STAGES, stageLabel } from '../_lib/format';
import { AddDealModal, type DealCustomerOption } from './AddDealModal';

/**
 * DealsTab.tsx — Server Component port of deals-list.php's "All Deals"
 * (`loadDealsList()` -> `crmApi('deals', filters)` -> `getDealsList()`, an
 * unconditional stub that ALWAYS returns `{deals:[], total:0}` in real PHP
 * TODAY — see queries.ts's module doc). The search/stage filter form is
 * still rendered (it's real, visible UI in the PHP source) even though it
 * can never surface a result right now; this is a pre-existing PHP
 * limitation, not something to "fix" by implementing real filtering this
 * batch didn't ask for.
 */
export interface DealsTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  stage: string;
  search: string;
}

export async function DealsTab({ db, lineAccountId, stage, search }: DealsTabProps) {
  const result = getDealsList(); // always {deals:[], total:0} — no db/filters actually consulted, matching PHP
  const customersForModal = await getCustomers(db, lineAccountId, { limit: 100 });
  const customerOptions: DealCustomerOption[] = customersForModal.customers.map((c) => ({ id: c.id, display_name: c.display_name, line_user_id: c.line_user_id }));

  return (
    <div>
      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <form method="GET" className="flex items-center gap-2">
          <input type="hidden" name="tab" value="deals" />
          <input type="text" name="search" defaultValue={search} placeholder="Search deals..." className="border rounded-md text-sm px-3 py-1.5 w-64" />
          <select name="stage" defaultValue={stage} className="border rounded-md text-sm px-2 py-1.5">
            <option value="">All Stages</option>
            {DEAL_STAGES.map((s) => (
              <option key={s} value={s}>
                {stageLabel(s)}
              </option>
            ))}
          </select>
          <button type="submit" className="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm">
            Filter
          </button>
        </form>
        <AddDealModal customers={customerOptions} />
      </div>

      <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div className="text-center py-8 text-gray-400 text-sm">{result.deals.length === 0 ? 'No deals found' : null}</div>
      </div>
    </div>
  );
}
