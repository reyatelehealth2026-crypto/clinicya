import { DataTable, type DataTableColumn } from '@/components/DataTable';
import type { CampaignRow } from '../queries';

/**
 * CampaignsTable.tsx — port of marketing-hub.php's campaigns table
 * (`renderCampaignsTable()`, lines 108-136). `viewCampaign(campaignId)` in
 * the PHP source is a plain `window.location.href =
 * 'drip-campaigns.php?id=' + campaignId` navigation — reproduced as a plain
 * `<a>` link, needing no client JS (Server Component).
 */
export function CampaignsTable({ campaigns }: { campaigns: CampaignRow[] }) {
  const columns: DataTableColumn<CampaignRow>[] = [
    { key: 'name', label: 'Campaign', render: (c) => <span className="font-medium">{c.name}</span> },
    {
      key: 'status',
      label: 'Status',
      render: (c) => (
        <span className={`text-xs px-2 py-0.5 rounded ${c.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
          {c.is_active ? 'Active' : 'Inactive'}
        </span>
      ),
    },
    { key: 'active_users', label: 'Active Users', render: (c) => c.active_users },
    { key: 'steps', label: 'Steps', render: (c) => c.step_count },
    {
      key: 'actions',
      label: 'Actions',
      render: (c) => (
        <a href={`/drip-campaigns?id=${c.id}`} className="px-2 py-1 rounded bg-gray-100 text-gray-600 text-xs inline-block">
          👁
        </a>
      ),
    },
  ];

  return <DataTable columns={columns} rows={campaigns} emptyContent="No campaigns" />;
}
