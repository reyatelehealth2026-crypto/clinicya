import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { createBroadcastAction, deleteCampaignAction } from '../_lib/products-actions';
import {
  getBroadcastCampaignItems,
  getBroadcastCampaigns,
  getCatalogCategories,
  getInStockProducts,
  getProductBroadcastTags,
  type BroadcastCampaign,
  type BroadcastCampaignItem,
} from '../_lib/products-queries';
import { ProductsSendModal } from './ProductsSendModal';

/**
 * ProductsTab.tsx — Server Component port of includes/broadcast/products.php's
 * `renderBroadcastProducts($db, $currentBotId)` (the whole file — it's one big function,
 * invoked unconditionally at the bottom). Fetches products/tags/campaigns via
 * `../_lib/products-queries.ts`, renders the create-campaign form + campaigns list, and
 * delegates only the send-target modal to `./ProductsSendModal.tsx` ('use client' — see that
 * file's module doc).
 *
 * Two DOCUMENTED, MINOR UX simplifications (functionally inert — the underlying validation/
 * delete still works correctly, only a piece of pure client-side JS polish is dropped):
 *   1. The create-campaign form's live "เลือกแล้ว: N/10" counter + submit-button auto-disable
 *      (products.php's `updateSelection()`) is NOT reproduced — the checkboxes are a plain
 *      native multi-select and the >10 cap is enforced the same way it's ALWAYS truly enforced
 *      (server-side, in `createBroadcastAction`, which redirects with the exact Thai error).
 *   2. The delete button's native `confirm('ลบ Broadcast นี้?')` JS dialog (products.php's
 *      `onsubmit="return confirm(...)"`) is NOT reproduced — see `../_lib/products-actions.ts`'s
 *      `deleteCampaignAction` doc comment.
 * Both choices are what let `createBroadcastAction`'s `<form>` and the delete `<form>` stay
 * plain native Server Component `action={...}` bindings (see this batch's brief: only the
 * send-target modal is a required client split) instead of needing their own 'use client'
 * wrappers just for these two cosmetic behaviors.
 */

export interface ProductsTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  searchParams: Record<string, string | string[] | undefined>;
}

function first(searchParams: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = searchParams[key];
  return Array.isArray(v) ? v[0] : v;
}

const STATUS_COLOR: Record<string, string> = { draft: 'yellow', sent: 'green', scheduled: 'blue' };
const STATUS_LABEL: Record<string, string> = { draft: 'รอส่ง', sent: 'ส่งแล้ว', scheduled: 'ตั้งเวลา' };

/** `date('d/m/Y H:i', strtotime($campaign['created_at']))` — products.php line 348. */
function formatCampaignDate(value: Date): string {
  if (Number.isNaN(value.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(value);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}

function successMessage(success: string | undefined, count: string | undefined): string | null {
  if (success === 'created') return 'สร้าง Broadcast สำเร็จ!';
  if (success === 'sent') return `ส่ง Broadcast สำเร็จ!${count !== undefined ? ` (${count} คน)` : ''}`;
  if (success === 'deleted') return 'ลบ Broadcast สำเร็จ!';
  if (success !== undefined) return 'ดำเนินการสำเร็จ!';
  return null;
}

interface CampaignCardProps {
  campaign: BroadcastCampaign;
  items: BroadcastCampaignItem[];
  tags: Awaited<ReturnType<typeof getProductBroadcastTags>>;
}

function CampaignCard({ campaign, items, tags }: CampaignCardProps) {
  const color = STATUS_COLOR[campaign.status ?? ''] ?? 'gray';
  const label = STATUS_LABEL[campaign.status ?? ''] ?? campaign.status;
  const visibleItems = items.slice(0, 5);
  const extraCount = items.length - visibleItems.length;

  return (
    <div className="p-4 hover:bg-gray-50">
      <div className="flex justify-between items-start mb-3">
        <div>
          <h4 className="font-semibold">{campaign.name}</h4>
          <p className="text-xs text-gray-500">
            <i className="fas fa-calendar mr-1" aria-hidden="true" />
            {formatCampaignDate(campaign.createdAt)}
          </p>
        </div>
        <span className={`px-3 py-1 text-xs font-medium rounded-full bg-${color}-100 text-${color}-700`}>{label}</span>
      </div>

      <div className="flex flex-wrap gap-2 mb-3">
        {visibleItems.map((item) => (
          <div key={item.id} className="relative">
            {item.itemImage ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={item.itemImage} alt={item.itemName} className="w-14 h-14 rounded-lg object-cover border" />
            ) : (
              <div className="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center border">
                <i className="fas fa-image text-gray-300" aria-hidden="true" />
              </div>
            )}
          </div>
        ))}
        {extraCount > 0 ? (
          <div className="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center border text-gray-500 text-sm">
            +{extraCount}
          </div>
        ) : null}
      </div>

      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4 text-sm text-gray-500">
          <span>
            <i className="fas fa-box mr-1" aria-hidden="true" />
            {items.length} สินค้า
          </span>
          {campaign.autoTagEnabled ? (
            <span className="text-green-600">
              <i className="fas fa-tag mr-1" aria-hidden="true" />
              Auto Tag
            </span>
          ) : null}
        </div>

        <div className="flex items-center gap-2">
          {campaign.status !== 'sent' ? (
            <ProductsSendModal campaignId={campaign.id} campaignName={campaign.name} tags={tags} />
          ) : null}

          <a
            href={`/broadcast?tab=stats&id=${campaign.id}`}
            className="px-3 py-1.5 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600"
          >
            <i className="fas fa-chart-bar mr-1" aria-hidden="true" />
            สถิติ
          </a>

          <form action={deleteCampaignAction} className="inline">
            <input type="hidden" name="action" value="delete_campaign" />
            <input type="hidden" name="campaign_id" value={campaign.id} />
            <button type="submit" className="px-3 py-1.5 border border-red-300 text-red-500 text-sm rounded-lg hover:bg-red-50">
              <i className="fas fa-trash" aria-hidden="true" />
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}

export async function ProductsTab({ db, lineAccountId, searchParams }: ProductsTabProps) {
  const [products, , tags, campaigns] = await Promise.all([
    getInStockProducts(db, lineAccountId),
    getCatalogCategories(db, lineAccountId), // dead fetch, ported for parity — see products-queries.ts's module doc
    getProductBroadcastTags(db, lineAccountId),
    getBroadcastCampaigns(db, lineAccountId),
  ]);

  const campaignItems = await Promise.all(campaigns.map((c) => getBroadcastCampaignItems(db, c.id)));

  const success = first(searchParams, 'success');
  const count = first(searchParams, 'count');
  const error = first(searchParams, 'error');
  const banner = successMessage(success, count);

  return (
    <div>
      {banner ? (
        <div className="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
          <i className="fas fa-check-circle mr-2" aria-hidden="true" />
          {banner}
        </div>
      ) : null}
      {error ? (
        <div className="mb-4 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
          <i className="fas fa-exclamation-circle mr-2" aria-hidden="true" />
          {error}
        </div>
      ) : null}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-1">
          <div className="bg-white rounded-xl shadow">
            <div className="p-4 border-b">
              <h3 className="font-semibold flex items-center">
                <i className="fas fa-plus-circle text-green-500 mr-2" aria-hidden="true" />
                สร้าง Broadcast ใหม่
              </h3>
            </div>

            <form action={createBroadcastAction} className="p-4">
              <input type="hidden" name="action" value="create_broadcast" />

              <div className="mb-4">
                <label className="block text-sm font-medium mb-1" htmlFor="pb-name">
                  ชื่อ Broadcast *
                </label>
                <input
                  id="pb-name"
                  type="text"
                  name="name"
                  required
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none"
                  placeholder="เช่น โปรโมชั่นสินค้าใหม่"
                />
              </div>

              <div className="mb-4 p-3 bg-green-50 rounded-lg">
                <label className="flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    name="auto_tag_enabled"
                    value="1"
                    defaultChecked
                    className="w-4 h-4 text-green-500 rounded mr-3"
                  />
                  <div>
                    <span className="font-medium text-green-800">🏷️ Auto Tag</span>
                    <p className="text-xs text-green-600">ติด Tag อัตโนมัติเมื่อลูกค้ากดสนใจ</p>
                  </div>
                </label>
              </div>

              <div className="mb-4">
                <label className="block text-sm font-medium mb-1" htmlFor="pb-tag-prefix">
                  Prefix ของ Tag
                </label>
                <input
                  id="pb-tag-prefix"
                  type="text"
                  name="tag_prefix"
                  defaultValue="สนใจ_"
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none"
                />
              </div>

              <div className="mb-4">
                <label className="block text-sm font-medium mb-2">
                  เลือกสินค้า <span className="text-gray-400">(สูงสุด 10)</span>
                </label>
                <div className="max-h-64 overflow-y-auto border rounded-lg">
                  {products.length === 0 ? (
                    <div className="p-8 text-center text-gray-400">
                      <i className="fas fa-box-open text-3xl mb-2" aria-hidden="true" />
                      <p>ยังไม่มีสินค้า</p>
                    </div>
                  ) : (
                    products.map((product) => (
                      <label
                        key={product.id}
                        className="flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b last:border-b-0"
                      >
                        <input type="checkbox" name="products[]" value={product.id} className="w-4 h-4 text-green-500 rounded mr-3" />
                        {product.imageUrl ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={product.imageUrl} alt={product.name} className="w-12 h-12 rounded-lg object-cover mr-3" />
                        ) : (
                          <div className="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mr-3">
                            <i className="fas fa-image text-gray-300" aria-hidden="true" />
                          </div>
                        )}
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium truncate">{product.name}</p>
                          <p className="text-sm text-green-600 font-bold">
                            ฿{(product.salePrice ?? product.price ?? 0).toLocaleString('en-US')}
                          </p>
                        </div>
                      </label>
                    ))
                  )}
                </div>
              </div>

              <button type="submit" className="w-full px-4 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                <i className="fas fa-plus mr-2" aria-hidden="true" />
                สร้าง Broadcast
              </button>
            </form>
          </div>
        </div>

        <div className="lg:col-span-2">
          <div className="bg-white rounded-xl shadow">
            <div className="p-4 border-b flex justify-between items-center">
              <h3 className="font-semibold flex items-center">
                <i className="fas fa-list text-blue-500 mr-2" aria-hidden="true" />
                Broadcast Campaigns
              </h3>
              <span className="text-sm text-gray-500">{campaigns.length} รายการ</span>
            </div>

            <div className="divide-y">
              {campaigns.length === 0 ? (
                <div className="p-8 text-center text-gray-400">
                  <i className="fas fa-bullhorn text-4xl mb-3" aria-hidden="true" />
                  <p>ยังไม่มี Broadcast</p>
                </div>
              ) : (
                campaigns.map((campaign, i) => (
                  <CampaignCard key={campaign.id} campaign={campaign} items={campaignItems[i] ?? []} tags={tags} />
                ))
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
