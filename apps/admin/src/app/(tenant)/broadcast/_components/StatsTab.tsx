import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { ReactNode } from 'react';
import {
  computeCtr,
  formatClickDate,
  formatPickerDate,
  getCampaignById,
  getCampaignItems,
  getCampaignPicker,
  getOverallStats,
  getRecentClicks,
  pickerEntryHref,
  type StatsClick,
  type StatsItem,
  type StatsOverall,
  type StatsPickerEntry,
} from '../_lib/stats-queries';

/**
 * StatsTab — Server Component port of includes/broadcast/stats.php (307
 * LOC): per-campaign click analytics + the campaign picker grid. `await`-
 * invoked directly from page.tsx (`await StatsTab({db, lineAccountId,
 * campaignId})`), same direct-invocation convention as
 * ../../settings/_components/ConsentTab.tsx/GeneralTab.tsx —
 * `campaignId = Number(searchParams.id ?? 0)` on the caller's side matches
 * stats.php's own `(int)($_GET['id'] ?? 0)` (stats.php line 8).
 *
 * Three render branches, exactly as stats.php's own `<?php if
 * (!$campaignId): ... elseif (!$campaign): ... else: ... endif; ?>`
 * (lines 138-307):
 *   1. `campaignId === 0`  -> campaign-picker grid (4 overall-stat tiles +
 *      the UNION ALL broadcast_campaigns/broadcasts picker).
 *   2. `campaignId` set, campaign not found -> "ไม่พบ Broadcast" empty state.
 *   3. campaign found -> stat tiles (total_sent/clicks/item-count/CTR%) +
 *      items-by-click-count bar list + recent-clicks feed.
 *
 * This whole PHP page is 100% read-only (no `$_POST`/mutation handling
 * anywhere in stats.php) — this Server Component has no client island and no
 * Server Action, matching ../../settings/_components/ConsentTab.tsx's own
 * "ZERO Server Actions" precedent.
 */
export interface StatsTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number;
  campaignId: number;
}

export async function StatsTab({ db, lineAccountId, campaignId }: StatsTabProps): Promise<ReactNode> {
  if (!campaignId) {
    const [overall, picker] = await Promise.all([getOverallStats(db, lineAccountId), getCampaignPicker(db, lineAccountId)]);
    return <CampaignPickerView overall={overall} picker={picker} />;
  }

  const campaign = await getCampaignById(db, campaignId);
  if (!campaign) {
    return <CampaignNotFoundView />;
  }

  const [items, clicks] = await Promise.all([getCampaignItems(db, campaignId), getRecentClicks(db, campaignId)]);
  return <CampaignStatsView campaign={campaign} items={items} clicks={clicks} />;
}

// ---------------------------------------------------------------------------
// Branch 1: campaignId === 0 — stats.php lines 138-199
// ---------------------------------------------------------------------------

function CampaignPickerView({ overall, picker }: { overall: StatsOverall; picker: StatsPickerEntry[] }) {
  return (
    <div className="bg-white rounded-xl shadow p-6 mb-6">
      <h3 className="font-semibold mb-4">
        <i className="fas fa-chart-bar text-blue-500 mr-2" aria-hidden="true" />
        เลือก Campaign เพื่อดูสถิติ
      </h3>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-blue-50 rounded-lg p-4 text-center">
          <p className="text-3xl font-bold text-blue-600">{overall.totalCampaigns.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">Broadcasts ทั้งหมด</p>
        </div>
        <div className="bg-green-50 rounded-lg p-4 text-center">
          <p className="text-3xl font-bold text-green-600">{overall.sentCampaigns.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">ส่งแล้ว</p>
        </div>
        <div className="bg-amber-50 rounded-lg p-4 text-center">
          <p className="text-3xl font-bold text-amber-600">{overall.totalSentUsers.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">ผู้รับสะสม</p>
        </div>
        <div className="bg-purple-50 rounded-lg p-4 text-center">
          <p className="text-3xl font-bold text-purple-600">{overall.totalClicks.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">Total Clicks</p>
        </div>
      </div>

      {picker.length === 0 ? (
        <div className="text-center text-gray-400 py-8">
          <i className="fas fa-chart-pie text-4xl mb-3" aria-hidden="true" />
          <p>ยังไม่มี Campaign</p>
          <a href="?tab=products" className="text-green-500 hover:underline">
            สร้าง Broadcast ใหม่
          </a>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {picker.map((entry) => {
            const kindLabel = entry.kind === 'quick' ? 'Quick Send' : 'Catalog/Carousel';
            const kindCls = entry.kind === 'quick' ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-700';
            const statusSent = entry.status === 'sent';
            return (
              <a
                key={`${entry.kind}-${entry.id}`}
                href={pickerEntryHref(entry)}
                className="block p-4 border rounded-lg hover:bg-gray-50 transition"
              >
                <div className="flex items-center justify-between mb-2">
                  <h4 className="font-medium truncate">{entry.name}</h4>
                  <span
                    className={`px-2 py-1 text-xs rounded-full ${statusSent ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}
                  >
                    {statusSent ? 'ส่งแล้ว' : 'รอส่ง'}
                  </span>
                </div>
                <div className="flex items-center justify-between text-xs text-gray-500">
                  <span>{formatPickerDate(entry.createdAt)}</span>
                  <span className={`px-2 py-0.5 rounded ${kindCls}`}>{kindLabel}</span>
                </div>
                {entry.sentCount > 0 ? (
                  <p className="text-xs text-gray-400 mt-1">
                    <i className="fas fa-users mr-1" aria-hidden="true" />
                    {entry.sentCount.toLocaleString('en-US')} คน
                  </p>
                ) : null}
              </a>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Branch 2: campaign not found — stats.php lines 201-206
// ---------------------------------------------------------------------------

function CampaignNotFoundView() {
  return (
    <div className="bg-white rounded-xl shadow p-8 text-center">
      <i className="fas fa-exclamation-circle text-4xl text-gray-300 mb-4" aria-hidden="true" />
      <p className="text-gray-500">ไม่พบ Broadcast</p>
      <a href="?tab=stats" className="mt-4 inline-block text-green-500 hover:underline">
        กลับไปเลือก Campaign
      </a>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Branch 3: campaign found — stats.php lines 208-306
// ---------------------------------------------------------------------------

function CampaignStatsView({
  campaign,
  items,
  clicks,
}: {
  campaign: { id: number; name: string; totalSent: number };
  items: StatsItem[];
  clicks: StatsClick[];
}) {
  const totalClicks = items.reduce((sum, item) => sum + item.clickCount, 0);
  const ctr = computeCtr(totalClicks, campaign.totalSent);
  const maxClicks = items.length > 0 ? Math.max(...items.map((i) => i.clickCount)) || 1 : 1;

  return (
    <>
      <div className="mb-6">
        <a href="?tab=stats" className="text-green-500 hover:underline mb-2 inline-block">
          <i className="fas fa-arrow-left mr-1" aria-hidden="true" />
          กลับ
        </a>
        <h2 className="text-2xl font-bold">{campaign.name}</h2>
        <p className="text-gray-600">สถิติการคลิกและ Tags ที่ติด</p>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-xl shadow p-4 text-center">
          <p className="text-3xl font-bold text-blue-600">{campaign.totalSent.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">ส่งแล้ว</p>
        </div>
        <div className="bg-white rounded-xl shadow p-4 text-center">
          <p className="text-3xl font-bold text-green-600">{totalClicks.toLocaleString('en-US')}</p>
          <p className="text-gray-500 text-sm">คลิกทั้งหมด</p>
        </div>
        <div className="bg-white rounded-xl shadow p-4 text-center">
          <p className="text-3xl font-bold text-purple-600">{items.length}</p>
          <p className="text-gray-500 text-sm">สินค้า</p>
        </div>
        <div className="bg-white rounded-xl shadow p-4 text-center">
          <p className="text-3xl font-bold text-orange-600">{ctr.toFixed(1)}%</p>
          <p className="text-gray-500 text-sm">CTR</p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl shadow">
          <div className="p-4 border-b">
            <h3 className="font-semibold">📦 สินค้าที่มีคนสนใจ</h3>
          </div>
          <div className="p-4">
            {items.length === 0 ? (
              <p className="text-gray-400 text-center py-4">ไม่มีข้อมูล</p>
            ) : (
              <div className="space-y-3">
                {items.map((item) => {
                  const percentage = (item.clickCount / maxClicks) * 100;
                  return (
                    <div key={item.id}>
                      <div className="flex items-center justify-between mb-1">
                        <div className="flex items-center">
                          <img src={item.itemImage || 'https://via.placeholder.com/30'} className="w-8 h-8 rounded object-cover mr-2" alt="" />
                          <span className="text-sm">{item.itemName}</span>
                        </div>
                        <span className="font-medium">{item.clickCount.toLocaleString('en-US')}</span>
                      </div>
                      <div className="w-full bg-gray-200 rounded-full h-2">
                        <div className="bg-green-500 h-2 rounded-full" style={{ width: `${percentage}%` }} />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        <div className="bg-white rounded-xl shadow">
          <div className="p-4 border-b">
            <h3 className="font-semibold">👆 การคลิกล่าสุด</h3>
          </div>
          <div className="p-4 max-h-80 overflow-y-auto">
            {clicks.length === 0 ? (
              <p className="text-gray-400 text-center py-4">ยังไม่มีการคลิก</p>
            ) : (
              <div className="space-y-3">
                {clicks.map((click) => (
                  <div key={click.id} className="flex items-center">
                    <img src={click.pictureUrl || 'https://via.placeholder.com/40'} className="w-10 h-10 rounded-full object-cover" alt="" />
                    <div className="ml-3 flex-1">
                      <p className="text-sm font-medium">{click.displayName}</p>
                      <p className="text-xs text-gray-500">สนใจ: {click.itemName}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-xs text-gray-400">{formatClickDate(click.clickedAt)}</p>
                      {click.tagAssigned ? (
                        <span className="text-xs text-green-600">
                          <i className="fas fa-tag" aria-hidden="true" /> Tagged
                        </span>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
