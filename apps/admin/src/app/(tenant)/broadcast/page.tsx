import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { Tabs } from '@/components/Tabs';
import { requireTenantPageContext } from '../users/_lib/session';
import { SendTab } from './_components/SendTab';
import { ProductsTab } from './_components/ProductsTab';
import { CatalogTab } from './_components/CatalogTab';
import { StatsTab } from './_components/StatsTab';

/**
 * (tenant)/broadcast/page.tsx — Server Component tab-hub port of root `broadcast.php`
 * (114 LOC), the consolidated "Broadcast" page linked from `includes/header.php`'s admin nav.
 * Read the full source before touching this file.
 *
 * `BROADCAST_TABS` mirrors broadcast.php lines 45-50's `$tabs` array EXACTLY, same order:
 *   'send' => 'ส่งข้อความ', 'catalog' => 'Catalog Builder', 'products' => 'สินค้า + Auto Tag',
 *   'stats' => 'สถิติ'
 * Default tab is `'send'` (`getActiveTab($tabs, 'send')`, broadcast.php line 53).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * `resolveCurrentBotId()` — broadcast.php lines 33-42, EXACTLY
 * ══════════════════════════════════════════════════════════════════════════
 *   $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *   if (!$currentBotId) {
 *       $defaultAccount = (new LineAccountManager($db))->getDefaultAccount();
 *       if ($defaultAccount) { $currentBotId = $defaultAccount['id']; $_SESSION['current_bot_id'] = $currentBotId; }
 *   }
 * This is a SIMPLER 2-tier algorithm than `(tenant)/settings/_lib/shop-tax-queries.ts`'s
 * `resolveLineAccountId()` (a materially different 4-tier one: session -> `?line_account_id`
 * query param -> `admin_users.line_account_id` lookup -> first active `line_accounts` row) —
 * per this batch's brief, that helper is deliberately NOT reused here; this is a small,
 * independent, purpose-built port of broadcast.php's own (much simpler) fallback.
 *
 * `LineAccountManager::getDefaultAccount()` (classes/LineAccountManager.php:71-80) loads
 * `SELECT * FROM line_accounts WHERE is_active = 1` (NO `ORDER BY`) and returns the first row
 * with `is_default = 1`, falling back to `$this->accounts[0]` — i.e. whichever row MySQL
 * happens to return first for an unordered `SELECT`. `ORDER BY is_default DESC, id ASC LIMIT 1`
 * below is the deterministic Next-side equivalent: it always finds a true `is_default = 1` row
 * first (matching PHP's own explicit loop), and among non-default rows (or when none is
 * default) picks the lowest `id` — a well-defined, stable choice PHP's unordered fallback only
 * happens to match today. Documented intentional determinism upgrade, not a behavior PHP
 * itself guarantees. The `$_SESSION['current_bot_id'] = $currentBotId;` write-back is not
 * reproduced (this is a pure read helper — same convention as every other `resolveXxxId`
 * helper in this codebase).
 *
 * Access gate: broadcast.php has no page-specific role check beyond `includes/header.php`'s
 * generic "must be logged in" requirement (confirmed by reading the full 114-line source) —
 * reuses `users/_lib/session`'s `requireTenantPageContext()`, the established convention for
 * pages with no narrower role gate (see (tenant)/settings/page.tsx's / (tenant)/
 * crm-dashboard-advanced/page.tsx's identical note).
 *
 * Tab dispatch: resolved by direct invocation (`await Tab({db, lineAccountId, searchParams})`),
 * not JSX — same convention (tenant)/settings/page.tsx and (tenant)/crm-dashboard-advanced/
 * page.tsx already use (see either file's doc comment for why: plain react-dom under
 * @testing-library/react can't await a nested async function component reached via JSX the
 * way Next.js's RSC renderer can). `CatalogTab`/`StatsTab` are owned and appended by the
 * broadcastCatalogStats batch (their own `_components/{CatalogTab,StatsTab}.tsx` +
 * `_lib/{catalog,stats}-*.ts`) — this file only imports and dispatches to them by name; a
 * known, deliberate cross-batch compile dependency (see this batch's brief) until that
 * batch's files land in the same round.
 */
export interface BroadcastPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const BROADCAST_TABS = [
  { key: 'send', label: 'ส่งข้อความ', icon: <i className="fas fa-paper-plane" aria-hidden="true" /> },
  { key: 'catalog', label: 'Catalog Builder', icon: <i className="fas fa-layer-group" aria-hidden="true" /> },
  { key: 'products', label: 'สินค้า + Auto Tag', icon: <i className="fas fa-box" aria-hidden="true" /> },
  { key: 'stats', label: 'สถิติ', icon: <i className="fas fa-chart-bar" aria-hidden="true" /> },
] as const;

type TabKey = (typeof BROADCAST_TABS)[number]['key'];
const DEFAULT_TAB: TabKey = 'send';
const PAGE_TITLE = 'Broadcast';

function resolveActiveTab(tabParam: string | undefined): TabKey {
  return (BROADCAST_TABS.some((t) => t.key === tabParam) ? tabParam : DEFAULT_TAB) as TabKey;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

/**
 * Port of broadcast.php lines 33-42 — see this file's module doc. Kept private to this page
 * (not exported/shared) since the two mutation files under `_lib/{send,products}-actions.ts`
 * each independently re-resolve the SAME 2-tier fallback themselves — Server Actions run as
 * separate invocations, not fed props from this render, so they cannot share this closure.
 */
async function resolveCurrentBotId(db: Kysely<TenantDB>, sessionCurrentBotId: number | null): Promise<number | null> {
  if (sessionCurrentBotId) {
    return sessionCurrentBotId;
  }
  const result = await sql<{ id: number }>`
    SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1
  `.execute(db);
  const row = result.rows[0];
  return row ? Number(row.id) : null;
}

export async function generateMetadata(): Promise<Metadata> {
  return { title: PAGE_TITLE };
}

export default async function BroadcastPage({ searchParams }: BroadcastPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();
  const activeTab = resolveActiveTab(first(params, 'tab'));
  const lineAccountId = await resolveCurrentBotId(db, session.currentBotId);

  let tabContent: ReactNode;
  switch (activeTab) {
    case 'catalog':
      // CatalogTab's own signature is `{db, lineAccountId: number}` (no `searchParams` —
      // catalog.php's builder has no `?id=`/read-params of its own) — owned by
      // broadcastCatalogStats, matched here as-is, never edited.
      tabContent = await CatalogTab({ db, lineAccountId: lineAccountId ?? 0 });
      break;
    case 'products':
      tabContent = await ProductsTab({ db, lineAccountId, searchParams: params });
      break;
    case 'stats':
      // StatsTab's own signature takes a pre-derived `campaignId: number`, not `searchParams`
      // — `(int)($_GET['id'] ?? 0)` (stats.php line 8) resolved here, matching its module doc.
      tabContent = await StatsTab({ db, lineAccountId: lineAccountId ?? 0, campaignId: Number(first(params, 'id') ?? '0') || 0 });
      break;
    case 'send':
    default:
      tabContent = await SendTab({ db, lineAccountId, searchParams: params });
      break;
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
        <div>
          <h1 className="text-lg font-semibold text-gray-800">{PAGE_TITLE}</h1>
          <p className="text-sm text-gray-500">ส่งข้อความถึงลูกค้าแบบ Broadcast</p>
        </div>
        <a
          href="/flex-builder.php"
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white bg-emerald-500 hover:bg-emerald-600"
        >
          <i className="fas fa-magic" aria-hidden="true" />
          Flex Builder
        </a>
      </div>

      <div className="mb-4">
        <a
          href="/templates"
          className="inline-flex items-center gap-2 px-3.5 py-2 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
        >
          <i className="fas fa-file-alt" aria-hidden="true" />
          Templates
        </a>
      </div>

      <Tabs tabs={BROADCAST_TABS.map((t) => ({ key: t.key, label: t.label, icon: t.icon }))} activeTab={activeTab} basePath="/broadcast" />

      <div className="mt-4">{tabContent}</div>
    </div>
  );
}
