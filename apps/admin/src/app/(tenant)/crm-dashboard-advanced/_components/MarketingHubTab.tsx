import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getCampaigns, getSegments } from '../queries';
import { CampaignsTable } from './CampaignsTable';
import { SegmentsList } from './SegmentsList';

/**
 * MarketingHubTab.tsx — Server Component port of marketing-hub.php
 * (`loadMarketingData()` -> `crmApi('campaigns')` + `crmApi('segments')`).
 *
 * "Total Sent"/"Open Rate"/"Conversion" stat cards in the PHP source are
 * NEVER populated by any JS — only `#marketing-active` is ever written
 * (`renderCampaignsTable()`'s last line: `document.getElementById
 * ('marketing-active').textContent = campaigns.filter(c =>
 * c.is_active).length`). The other 3 stay at their static HTML placeholder
 * text ('--'/'--%'/'--%') forever in production. Mirrored as static
 * placeholders here too — not fabricated from any real query.
 *
 * "Quick Broadcast" is a plain `<a href="broadcast.php">` link in the PHP
 * source — no client JS, reproduced as a plain link.
 */
export interface MarketingHubTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
}

export async function MarketingHubTab({ db, lineAccountId }: MarketingHubTabProps) {
  const [campaigns, segments] = await Promise.all([getCampaigns(db, lineAccountId, {}), getSegments(db, lineAccountId)]);
  const activeCount = campaigns.filter((c) => c.is_active).length;

  return (
    <div>
      <div className="grid grid-cols-4 gap-3 mb-4">
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Active Campaigns</div>
          <div className="text-xl font-mono font-semibold text-purple-600">{activeCount}</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Total Sent</div>
          <div className="text-xl font-mono font-semibold">--</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Open Rate</div>
          <div className="text-xl font-mono font-semibold text-blue-600">--%</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] uppercase text-gray-500 mb-1">Conversion</div>
          <div className="text-xl font-mono font-semibold text-green-600">--%</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2">
          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm flex items-center justify-between">
              <span>Drip Campaigns</span>
              <a href="/drip-campaigns" className="text-xs text-blue-600 hover:underline">
                Manage All
              </a>
            </div>
            <CampaignsTable campaigns={campaigns} />
          </div>
        </div>

        <div>
          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Segments</div>
            <div className="p-3">
              <SegmentsList segments={segments} />
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden mt-4">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Quick Broadcast</div>
            <div className="p-3">
              <a href="/broadcast" className="block text-center px-4 py-2 rounded-md bg-slate-900 text-white text-sm font-medium">
                📣 Send Broadcast
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
