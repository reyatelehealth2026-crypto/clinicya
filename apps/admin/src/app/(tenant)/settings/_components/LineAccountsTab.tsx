import type { Kysely } from 'kysely';
import type { ReactNode } from 'react';
import type { TenantDB } from '@reya/db';
import { getLineAccounts, buildLineWebhookUrl, type LineAccountRow, type LineBotMode } from '../_lib/line-queries';
import { setDefaultLineAccountAction } from '../_lib/line-actions';
import { LineAccountsPanel, LineAccountActionButtons, CopyWebhookButton, AddLineAccountButton } from './LineAccountsPanel';

/**
 * LineAccountsTab — Server Component port of `includes/settings/line.php`
 * (525 LOC), settings.php's DEFAULT tab (there is no explicit `case 'line':`
 * — it falls through from `default: include 'includes/settings/line.php';`
 * at settings.php line 934). Fetches the account list via
 * `../_lib/line-queries.ts`'s `getLineAccounts()` — the EXACT query
 * `LineAccountManager::getAllAccounts()` runs (line.php lines 30-31), no
 * WHERE/tenant-scoping beyond the already-tenant-scoped `db` connection, no
 * `currentBotId` filter: LINE accounts are not `current_bot_id`-scoped data,
 * they ARE the list of bots a tenant can switch between.
 *
 * Renders the account-card grid itself (matches line.php lines 63-174
 * structurally: avatar/name/basic_id header, active/mode/LIFF badges,
 * Channel ID, readonly webhook URL input, 4-button actions row) plus the
 * empty state (lines 160-173) when there are zero accounts. Direct
 * invocation (`await LineAccountsTab({ db })`), matching ../_components/ConsentTab.tsx's
 * / page.tsx's established convention for this settings hub (see either
 * file's own doc for why — plain react-dom under @testing-library/react
 * can't `await` a nested async Server Component reached via JSX the way
 * Next.js's RSC renderer can).
 *
 * The interactive pieces this Server Component canNOT own (modal state,
 * copy-webhook, test-connection, edit/create form) are small Client
 * Components imported from ./LineAccountsPanel.tsx and embedded directly as
 * children within this component's server-rendered markup — see that file's
 * own module doc for the "one shared Provider wraps Server Component
 * children" composition pattern. The 4th action button (⭐ set-default) is
 * the one exception: it stays a plain server-rendered `<form
 * action={setDefaultLineAccountAction}>` here, mirroring line.php's own
 * zero-JS `<form method="POST">` (lines 144-150) almost verbatim.
 *
 * NOT reproduced here: line.php's own per-tab `?success=` banner (lines
 * 55-60) — superseded by (tenant)/settings/page.tsx's shared `?message=`/
 * `?error=` banner (rendered once, above every tab's content, for all 7 live
 * tabs) — see ../_lib/line-actions.ts's module doc for the redirect
 * convention this implies.
 */
export interface LineAccountsTabProps {
  db: Kysely<TenantDB>;
}

const BOT_MODE_INFO: Record<LineBotMode, { icon: string; label: string; color: string }> = {
  shop: { icon: '🛒', label: 'ร้านค้า', color: 'purple' },
  general: { icon: '💬', label: 'ทั่วไป', color: 'blue' },
  auto_reply_only: { icon: '🤖', label: 'Auto Reply', color: 'orange' },
};

function modeInfoFor(botMode: LineBotMode | null): { icon: string; label: string; color: string } {
  const mode = botMode ?? 'shop';
  return BOT_MODE_INFO[mode] ?? { icon: '❓', label: mode, color: 'gray' };
}

function LineAccountCard({ account }: { account: LineAccountRow }): ReactNode {
  const modeInfo = modeInfoFor(account.bot_mode);
  const isDefault = Boolean(account.is_default);
  const isActive = Boolean(account.is_active);
  const webhookUrl = buildLineWebhookUrl(account.id);

  return (
    <div className={`account-card bg-white rounded-2xl shadow-lg overflow-hidden ${isDefault ? 'ring-2 ring-green-500' : ''}`}>
      {/* Header */}
      <div className="p-5 bg-gradient-to-r from-green-500 to-emerald-600 text-white">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            {account.picture_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- matches line.php's plain <img>, avatar URL is tenant-provided remote content
              <img src={account.picture_url} alt="" className="w-14 h-14 rounded-full border-2 border-white shadow" />
            ) : (
              <div className="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center">
                <i className="fab fa-line text-3xl" aria-hidden="true" />
              </div>
            )}
            <div>
              <h3 className="font-bold text-lg">{account.name}</h3>
              <p className="text-green-100 text-sm">{account.basic_id || 'ไม่มี Basic ID'}</p>
            </div>
          </div>
          {isDefault ? <span className="px-3 py-1 bg-yellow-400 text-yellow-900 text-xs font-bold rounded-full">⭐ หลัก</span> : null}
        </div>
      </div>

      {/* Status badges */}
      <div className="px-5 py-3 bg-gray-50 flex flex-wrap gap-2">
        <span className={`px-2 py-1 text-xs rounded-full ${isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
          {isActive ? '✓ Active' : '✗ Inactive'}
        </span>
        <span className={`px-2 py-1 text-xs rounded-full bg-${modeInfo.color}-100 text-${modeInfo.color}-700`}>
          {modeInfo.icon} {modeInfo.label}
        </span>
        {account.liff_id ? <span className="px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-700">📱 LIFF</span> : null}
      </div>

      {/* Info */}
      <div className="p-5 space-y-3 text-sm">
        <div className="flex justify-between">
          <span className="text-gray-500">Channel ID</span>
          <span className="font-mono text-gray-700">{account.channel_id || '-'}</span>
        </div>
        {account.liff_id ? (
          <div className="flex justify-between">
            <span className="text-gray-500">LIFF ID</span>
            <span className="font-mono text-green-600 text-xs">{account.liff_id}</span>
          </div>
        ) : null}
        <div>
          <span className="text-gray-500 text-xs">Webhook URL:</span>
          <div className="flex mt-1">
            <input
              type="text"
              readOnly
              value={webhookUrl}
              id={`webhook_${account.id}`}
              className="flex-1 text-xs bg-gray-100 border-0 rounded-l px-2 py-1.5 font-mono"
            />
            <CopyWebhookButton webhookUrl={webhookUrl} />
          </div>
        </div>
      </div>

      {/* Actions */}
      <div className="px-5 pb-5 grid grid-cols-4 gap-2">
        <LineAccountActionButtons account={account} />
        {!isDefault ? (
          <form action={setDefaultLineAccountAction} className="contents">
            <input type="hidden" name="id" value={account.id} />
            <button type="submit" className="p-2 bg-yellow-50 text-yellow-600 rounded-lg hover:bg-yellow-100 text-center" title="ตั้งเป็นหลัก">
              <i className="fas fa-star" aria-hidden="true" />
            </button>
          </form>
        ) : (
          <div className="p-2 bg-yellow-100 text-yellow-600 rounded-lg text-center">
            <i className="fas fa-star" aria-hidden="true" />
          </div>
        )}
      </div>
    </div>
  );
}

export async function LineAccountsTab({ db }: LineAccountsTabProps): Promise<ReactNode> {
  const accounts = await getLineAccounts(db);

  return (
    <LineAccountsPanel>
      <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
          <h2 className="text-xl font-bold text-gray-800">บัญชี LINE Official Account</h2>
          <p className="text-gray-600">จัดการบัญชี LINE OA และตั้งค่าต่างๆ</p>
        </div>
        <div className="flex items-center gap-2">
          <a
            href="/help/line-setup.html"
            target="_blank"
            rel="noreferrer"
            className="px-5 py-2.5 bg-white border border-green-500 text-green-600 rounded-lg hover:bg-green-50 transition inline-flex items-center"
          >
            <i className="fas fa-book-open mr-2" aria-hidden="true" />
            คู่มือเชื่อม LINE
          </a>
          <AddLineAccountButton>
            <i className="fas fa-plus mr-2" aria-hidden="true" />
            เพิ่มบัญชี LINE
          </AddLineAccountButton>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
        {accounts.map((account) => (
          <LineAccountCard key={account.id} account={account} />
        ))}

        {accounts.length === 0 ? (
          <div className="col-span-full">
            <div className="text-center py-16 bg-white rounded-2xl shadow">
              <div className="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i className="fab fa-line text-4xl text-green-500" aria-hidden="true" />
              </div>
              <h3 className="text-xl font-semibold text-gray-700 mb-2">ยังไม่มีบัญชี LINE</h3>
              <p className="text-gray-500 mb-6">เริ่มต้นเพิ่มบัญชี LINE Official Account แรกของคุณ</p>
              <AddLineAccountButton className="px-6 py-3 bg-green-500 text-white rounded-xl hover:bg-green-600 shadow-lg">
                <i className="fas fa-plus mr-2" aria-hidden="true" />
                เพิ่มบัญชีแรก
              </AddLineAccountButton>
            </div>
          </div>
        ) : null}
      </div>
    </LineAccountsPanel>
  );
}
