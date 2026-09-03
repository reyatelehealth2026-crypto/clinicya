import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { Pagination } from '@/components/Pagination';
import { requireTenantPageContext } from './_lib/session';
import { isOdooIntegrationEnabled } from './_lib/odoo';
import {
  parseOrdersListFilters,
  getOrdersListPage,
  getStatusCounts,
  getPendingSlipOrderIds,
  getDispenseCount,
  getDispenseRecords,
  getShopOrderDataSource,
  type RawSearchParams,
} from './queries';
import { TypeChips } from './_components/TypeChips';
import { StatusChips } from './_components/StatusChips';
import { PendingSlipBanner } from './_components/PendingSlipBanner';
import { OrderCard } from './_components/OrderCard';
import { DispenseCard } from './_components/DispenseCard';

/**
 * /shop/orders — Server Component port of shop/orders.php's "Transactions
 * mode" GET render path (lines 205-486, 568-845). Route matches
 * nav/manifest.ts's 'orders' entry (`href: '/shop/orders'`, key 'orders')
 * and shop/orders.php's own clean-URL path verbatim.
 *
 * BREADCRUMB / PAGE TITLE — shop/orders.php calls renderPageHeader() with
 * two DIFFERENT title/subtitle pairs depending on mode (line 141-146 for
 * Odoo mode: title 'รายการคำสั่งซื้อ', subtitle 'โหมด Odoo (Read-only)'; line
 * 229-234 for transactions mode: title 'รายการ/คำสั่งซื้อ', no subtitle) —
 * both reproduced exactly, per branch, below.
 *
 * ODOO MODE (shop_settings.order_data_source === 'odoo' AND the global
 * ODOO_INTEGRATION_ENABLED kill-switch) is OUT OF SCOPE this batch — same
 * precedent as users.php's Odoo tab: this renders an explicit EmptyState
 * stub linking back to the still-live PHP page (shop/orders.php itself,
 * which keeps serving its own Odoo branch — lines 27-203 — unmodified),
 * rather than porting, faking, or silently dropping that gate.
 *
 * EFFICIENCY NOTE (not a behavior change): shop/orders.php's PHP source
 * unconditionally runs the full orders-list query, status-counts query, AND
 * pending-slip query EVERY request, even when `?view=dispense` is active —
 * only `$dispenseRecords` itself is conditionally fetched (line 453's `if
 * ($viewDispense)`); the order-list MARKUP is what's actually gated (lines
 * 585/746's `if (!$viewDispense): ... endif;`), so those extra queries have
 * zero effect on rendered output. This port only fetches what the active
 * branch actually renders — same visible result, fewer DB round-trips.
 */

interface ShopOrdersPageProps {
  searchParams: Promise<RawSearchParams>;
}

const BREADCRUMB = [{ label: 'ร้านค้า' }, { label: 'คำสั่งซื้อ' }];

export default async function ShopOrdersPage({ searchParams }: ShopOrdersPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();

  // shop/orders.php line 21: $currentBotId = $_SESSION['current_bot_id'] ?? 1
  // — used for the orderDataSource lookup, every tenant-scoped query below,
  // and (in actions.ts) the tenant-guarded UPDATE + line_accounts lookup.
  const currentBotId = session.currentBotId ?? 1;
  // shop/orders.php line 340: $botIdForQuery = $currentBotId ?? ... ?? null
  // — always equal to $currentBotId in practice (see queries.ts's
  // buildOrdersWhereExpr doc for why the PHP fallbacks are dead code).
  const botIdForQuery = currentBotId;

  const orderDataSource = await getShopOrderDataSource(db, currentBotId);
  const isOdooMode = orderDataSource === 'odoo' && isOdooIntegrationEnabled();

  if (isOdooMode) {
    return (
      <div>
        <PageHeader title="รายการคำสั่งซื้อ" subtitle="โหมด Odoo (Read-only)" breadcrumb={BREADCRUMB} />
        <EmptyState
          heading="หน้าโหมด Odoo ยังอยู่บนระบบเดิม"
          sub="หน้านี้แสดงคำสั่งซื้อจากข้อมูลที่รับเข้าจาก Odoo และปิดการแก้ไขสถานะในหลังบ้านชั่วคราว — ฟีเจอร์นี้จะถูกย้ายมาที่นี่ใน Phase 8 (ชุด Odoo) ตอนนี้ใช้หน้าเดิมต่อไปก่อน"
          cta={{ label: 'เปิดหน้าคำสั่งซื้อโหมด Odoo (PHP)', href: '/shop/orders.php' }}
        />
      </div>
    );
  }

  const filters = parseOrdersListFilters(params);
  const dispenseCount = await getDispenseCount(db, botIdForQuery);

  if (filters.viewDispense) {
    const dispenseRecords = await getDispenseRecords(db, botIdForQuery);
    return (
      <div>
        <PageHeader title="รายการ/คำสั่งซื้อ" breadcrumb={BREADCRUMB} />
        <TypeChips typeFilter={filters.type} statusFilter={filters.status} viewDispense dispenseCount={dispenseCount} />

        <div className="mb-6">
          <span className="inline-block px-4 py-2 rounded-lg text-sm font-medium bg-emerald-500 text-white">
            💊 รายการจ่ายยา ({dispenseRecords.length})
          </span>
        </div>

        {dispenseRecords.length === 0 ? (
          <EmptyState heading="ยังไม่มีรายการจ่ายยา" />
        ) : (
          dispenseRecords.map((record) => <DispenseCard key={record.id} record={record} />)
        )}
      </div>
    );
  }

  const [{ orders, totalOrders, totalPages, page, perPage }, statusCounts, pendingSlipOrderIds] = await Promise.all([
    getOrdersListPage(db, filters, botIdForQuery),
    getStatusCounts(db, botIdForQuery),
    getPendingSlipOrderIds(db, botIdForQuery),
  ]);
  const pendingSlipsCount = pendingSlipOrderIds.length;
  const pendingSlipIdSet = new Set(pendingSlipOrderIds);

  return (
    <div>
      <PageHeader title="รายการ/คำสั่งซื้อ" breadcrumb={BREADCRUMB} />

      <TypeChips typeFilter={filters.type} statusFilter={filters.status} viewDispense={false} dispenseCount={dispenseCount} />

      {pendingSlipsCount > 0 ? <PendingSlipBanner pendingSlipsCount={pendingSlipsCount} typeFilter={filters.type} /> : null}

      <StatusChips
        statusFilter={filters.status}
        typeFilter={filters.type}
        pendingSlip={filters.pendingSlip}
        statusCounts={statusCounts}
        pendingSlipsCount={pendingSlipsCount}
      />

      {orders.length === 0 ? (
        <EmptyState heading="ยังไม่มีคำสั่งซื้อ" sub="คำสั่งซื้อจาก LINE Shop จะปรากฏที่นี่" />
      ) : (
        <>
          <div className="mb-3 text-sm text-slate-500">
            แสดง {orders.length} จาก {totalOrders.toLocaleString()} รายการ
          </div>
          {orders.map((order) => (
            <OrderCard key={order.id} order={order} hasPendingSlip={pendingSlipIdSet.has(order.id)} />
          ))}
        </>
      )}

      {totalPages > 1 ? (
        <Pagination
          currentPage={page}
          totalPages={totalPages}
          perPage={perPage}
          basePath="/shop/orders"
          queryParams={{
            status: filters.status || undefined,
            type: filters.type || undefined,
            pending_slip: filters.pendingSlip ? '1' : undefined,
          }}
        />
      ) : null}
    </div>
  );
}
