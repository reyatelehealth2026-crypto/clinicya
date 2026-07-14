import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { Tabs } from '@/components/Tabs';
import { requireTenantPageContext } from '../users/_lib/session';
import { WelcomeTab } from './_components/WelcomeTab';
import { EmailTab } from './_components/EmailTab';
import { NotYetMigratedTab } from './_components/NotYetMigratedTab';

/**
 * (tenant)/settings/page.tsx — Server Component shell/hub port of root
 * `/settings.php` (941 LOC), the consolidated tab-based settings page
 * linked from `includes/header.php`'s admin nav and already known to
 * `apps/admin/src/nav/manifest.ts` (href `/settings`, `servedBy:'php'`,
 * frozen this round — not touched here).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * SCOPE CORRECTION (carried over from this batch's brief, repeated here
 * because it changes which PHP file is the actual source of truth):
 * ══════════════════════════════════════════════════════════════════════════
 * `includes/settings/settings.php` (562 LOC) — the file this round's
 * original hand-off named — is DEAD, ORPHANED code: zero `include`/
 * `require` of it anywhere in the repo (grepped), not linked from
 * `includes/header.php`'s nav. The genuinely LIVE hub real tenants hit is
 * root `/settings.php` (941 LOC), modeled here instead. Read in full before
 * writing this file.
 *
 * `$tabs` whitelist (settings.php lines 33-46) — the array `getActiveTab()`
 * validates `?tab=` against via `isset($tabs[$tab])`:
 *
 *   'line', 'platform', 'general', 'shop_tax', 'welcome', 'notifications',
 *   'consent'                                    <- SETTINGS_TABS, in order
 *
 *   // 'liff', 'vibe-selling', 'telegram', 'email', 'quick-access'
 *                                                 <- CODE-PRESENT but
 *   COMMENTED OUT of the live array — their PHP handlers/partials
 *   (includes/settings/{liff,vibe-selling,telegram,email,quick-access}.php)
 *   still exist and still work if reached directly, but `getActiveTab()`'s
 *   whitelist check makes them unreachable via the tab nav / `?tab=` in
 *   real production today.
 *
 * `getActiveTab($tabs, 'line')` semantics (includes/components/tabs.php
 * lines 336-351), mirrored by `resolveActiveTab()` below: `?tab=` must be a
 * whitelisted key or the page falls back to the explicit default ('line',
 * itself the first key) — never a different, unrelated tab's content.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * `email` — deliberately ROUTABLE here despite NOT being nav-visible
 * ══════════════════════════════════════════════════════════════════════════
 * Per this batch's brief: `/settings?tab=email` renders the REAL EmailTab
 * (a working port), even though real PHP's `?tab=email` — `email` being
 * commented out of `$tabs` — falls back to `line`-tab content instead. This
 * is an intentional, documented ONE-SIDED divergence (do not "fix" it by
 * making Next also fall back for `email`; do not add an `email` pill to the
 * nav either — SETTINGS_TABS stays the 7-entry live whitelist so the nav UI
 * matches PHP's exactly). `ROUTABLE_TAB_KEYS`/`resolveActiveTab()` below
 * therefore recognize 8 keys (the 7 nav tabs + `email`), while
 * `SETTINGS_TABS` (which drives the `<Tabs>` nav pills) stays at 7.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * Tab-registration convention established here for later settings batches
 * ══════════════════════════════════════════════════════════════════════════
 * `SETTINGS_TABS` ordered array + `switch (activeTab)` dispatch in this
 * `page.tsx` — same pattern (tenant)/crm-dashboard-advanced/page.tsx already
 * uses. settingsConsentTax appends `case 'consent':`/`case 'shop_tax':` to
 * the switch below (its own `_components/{ConsentTab,ShopTaxTab}.tsx`, not
 * touched by this batch) rather than editing any other entry. Later batches
 * (line/platform/general/notifications/quick-access, then telegram/liff/
 * vibe-selling) replace their own `NotYetMigratedTab` case with a real one
 * the same way.
 *
 * Every other currently-live-but-unported tab key
 * (line/platform/general/notifications) renders `NotYetMigratedTab` — an
 * explicit "ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม" placeholder linking back to
 * `/settings.php?tab=X` — NEVER a silent fallback to `welcome`'s or any
 * other tab's content. `quick-access` is not part of `SETTINGS_TABS`/
 * `ROUTABLE_TAB_KEYS` at all (dead per the live `$tabs` whitelist — see
 * above) but `NotYetMigratedTab` itself still supports it as a prop value
 * for direct component-level test coverage (its own module doc explains
 * why).
 *
 * Access gate: root settings.php has no page-specific role check beyond
 * `includes/auth_check.php`'s generic "must be logged in" requirement
 * (confirmed by reading the full 941-line source) — reuses
 * `users/_lib/session`'s `requireTenantPageContext()`, the established
 * convention for pages with no narrower role gate (see that page's module
 * doc, and (tenant)/crm-dashboard-advanced/page.tsx's identical note).
 */
export interface SettingsPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const SETTINGS_TABS = [
  { key: 'line', label: 'LINE Accounts', icon: <i className="fab fa-line" aria-hidden="true" /> },
  { key: 'platform', label: 'การเชื่อมต่อแพลตฟอร์ม', icon: <i className="fas fa-plug" aria-hidden="true" /> },
  { key: 'general', label: 'ข้อมูลร้าน', icon: <i className="fas fa-store" aria-hidden="true" /> },
  { key: 'shop_tax', label: 'ข้อมูลร้าน / ใบกำกับภาษี', icon: <i className="fas fa-file-invoice" aria-hidden="true" /> },
  { key: 'welcome', label: 'ข้อความต้อนรับ', icon: <i className="fas fa-hand-sparkles" aria-hidden="true" /> },
  { key: 'notifications', label: 'การแจ้งเตือน', icon: <i className="fas fa-bell" aria-hidden="true" /> },
  { key: 'consent', label: 'Consent', icon: <i className="fas fa-shield-alt" aria-hidden="true" /> },
] as const;

type NavTabKey = (typeof SETTINGS_TABS)[number]['key'];

/** The 7 nav-visible keys + `email` (content-routable, not nav-visible — see module doc). */
const ROUTABLE_TAB_KEYS = [...SETTINGS_TABS.map((t) => t.key), 'email'] as const;
type RoutableTabKey = NavTabKey | 'email';

const DEFAULT_TAB: RoutableTabKey = 'line';
const PAGE_TITLE = 'ตั้งค่าระบบ';

function resolveActiveTab(tabParam: string | undefined): RoutableTabKey {
  if (tabParam && (ROUTABLE_TAB_KEYS as readonly string[]).includes(tabParam)) {
    return tabParam as RoutableTabKey;
  }
  return DEFAULT_TAB;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export async function generateMetadata(): Promise<Metadata> {
  // PHP always sets `$pageTitle = 'ตั้งค่าระบบ';` regardless of `$activeTab`
  // (settings.php line 49) — no per-tab title, unlike crm-dashboard-advanced.
  return { title: PAGE_TITLE };
}

export default async function SettingsPage({ searchParams }: SettingsPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();
  const activeTab = resolveActiveTab(first(params, 'tab'));
  const message = first(params, 'message');
  const error = first(params, 'error');

  // Resolved by direct invocation (`await Tab({...})`), not JSX — matches
  // (tenant)/crm-dashboard-advanced/page.tsx's and (tenant)/dashboard/
  // page.tsx's established convention (see either file's doc comment for
  // why: plain react-dom under @testing-library/react can't await a nested
  // async function component reached via JSX the way Next.js's RSC
  // renderer can).
  let tabContent: ReactNode;
  switch (activeTab) {
    case 'welcome':
      tabContent = await WelcomeTab({ db, currentBotId: session.currentBotId });
      break;
    case 'email':
      tabContent = await EmailTab({ db });
      break;
    case 'line':
    case 'platform':
    case 'general':
    case 'notifications':
      tabContent = NotYetMigratedTab({ tabKey: activeTab });
      break;
    // settingsConsentTax appends real cases here:
    //   case 'consent':
    //     tabContent = await ConsentTab({ db, ... });
    //     break;
    //   case 'shop_tax':
    //     tabContent = await ShopTaxTab({ db, ... });
    //     break;
    default: {
      // Interim safety net for 'consent'/'shop_tax' only, reached until
      // settingsConsentTax appends its own cases above — a minimal, honest
      // placeholder (NOT NotYetMigratedTab, whose 5-key union deliberately
      // does not include these two) and NEVER a silent fallback to
      // welcome's/any other tab's content.
      const tabMeta = SETTINGS_TABS.find((t) => t.key === activeTab);
      tabContent = (
        <div className="bg-white rounded-xl shadow-sm p-10 text-center text-gray-500">
          <p className="text-lg font-semibold text-gray-700">{tabMeta?.label ?? activeTab} — ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม</p>
          <a href={`/settings.php?tab=${activeTab}`} className="inline-block mt-3 text-emerald-600 underline">
            ไปที่หน้าเดิม
          </a>
        </div>
      );
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
        <div>
          <h1 className="text-lg font-semibold text-gray-800">{PAGE_TITLE}</h1>
        </div>
      </div>

      {message ? (
        <div role="status" className="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl flex items-center gap-3">
          <i className="fas fa-check-circle text-xl" aria-hidden="true" />
          <span>{message}</span>
        </div>
      ) : null}

      {error ? (
        <div role="alert" className="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3">
          <i className="fas fa-exclamation-circle text-xl" aria-hidden="true" />
          <span>{error}</span>
        </div>
      ) : null}

      <Tabs tabs={SETTINGS_TABS.map((t) => ({ key: t.key, label: t.label, icon: t.icon }))} activeTab={activeTab} basePath="/settings" />

      <div className="mt-4">{tabContent}</div>
    </div>
  );
}
