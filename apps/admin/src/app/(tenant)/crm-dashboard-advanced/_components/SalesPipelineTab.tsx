import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getPipelineData, getCustomers } from '../queries';
import { formatMoney } from '../_lib/format';
import { KanbanBoard } from './KanbanBoard';
import { AddDealModal, type DealCustomerOption } from './AddDealModal';

/**
 * SalesPipelineTab.tsx — Server Component port of sales-pipeline.php
 * (`loadPipelineData()` -> `crmApi('pipeline')`). The salesperson/source
 * filters (lines 15-32) are populated dynamically in the PHP source
 * (`<!-- Populated dynamically -->` for salesperson) or are fixed literal
 * options (source) but BOTH filters are cosmetic in the real PHP page —
 * `onchange="loadPipelineData()"` re-fetches the SAME unfiltered
 * `crmApi('pipeline')` call with no filter params ever actually sent
 * (confirmed: `loadPipelineData()` takes no arguments and its
 * `crmApi('pipeline')` call passes none) — so the dropdowns visually exist
 * but have never filtered anything. Reproduced as the same non-functional
 * source dropdown (informational only) and the salesperson dropdown is
 * omitted (it renders empty in PHP too — "Populated dynamically" never
 * actually fires from anywhere in the reachable code path).
 */
export interface SalesPipelineTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
}

export async function SalesPipelineTab({ db, lineAccountId }: SalesPipelineTabProps) {
  const [pipeline, customersForModal] = await Promise.all([getPipelineData(db), getCustomers(db, lineAccountId, { limit: 100 })]);
  const customerOptions: DealCustomerOption[] = customersForModal.customers.map((c) => ({ id: c.id, display_name: c.display_name, line_user_id: c.line_user_id }));

  return (
    <div>
      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="text-sm text-gray-500">
            Total Pipeline: <span className="font-semibold text-gray-800">฿{formatMoney(pipeline.totalValue)}</span> ({pipeline.totalDeals} deals)
          </div>
          <span className="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">Win Rate: {pipeline.winRate.toFixed(1)}%</span>
        </div>
        <AddDealModal customers={customerOptions} />
      </div>

      <KanbanBoard stages={pipeline.stages} />
    </div>
  );
}
