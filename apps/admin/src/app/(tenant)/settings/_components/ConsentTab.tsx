import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getConsentPageData, formatConsentLogTimestamp, type ConsentLogRow, type DataAccessLogRow } from '../_lib/consent-queries';

/**
 * ConsentTab — Server Component port of includes/settings/consent.php (242
 * LOC): a read-only PDPA consent/audit-log dashboard. ZERO Server Actions —
 * there is no POST/mutation surface anywhere on this tab (confirmed: no
 * `case` in settings.php's `$_POST['action']` switch touches consent data at
 * all).
 *
 * Sub-tab toggle ("📝 Consent Logs" vs "👁️ Data Access Logs") — PHP's own
 * `showConsentTab()` inline `<script>` (consent.php lines 225-242) is a pure
 * `classList` show/hide with NO data refetch. This batch's allowed-paths
 * list has no room for a separate 'use client' island file (unlike
 * ./WelcomeTab.tsx's ./WelcomeMessageForm.tsx split — a client boundary
 * needs its own module, `'use client'` is file-scoped), so the toggle is
 * implemented here with ZERO JavaScript instead: a standard "CSS radio
 * tabs" pattern (`<input type="radio" className="peer">` + a
 * `peer-checked:` Tailwind variant on the two panels), keeping this
 * component a plain Server Component. The two radio inputs, the nav, and
 * both panels are direct siblings of one shared parent (required for the
 * CSS sibling-combinator `peer-checked:` variant to apply at all) — the only
 * accepted, documented simplification versus PHP: the nav *label*'s
 * active/inactive highlight color is static (matching PHP's initial
 * pre-JS-run state: Consent Logs styled active, Data Access Logs styled
 * inactive) rather than dynamically swapping, because the `<label>`s live
 * inside a `<nav>` wrapper and are therefore NOT direct siblings of the
 * radio inputs (only the panels are). The functionally important behavior —
 * which table's rows are visible, no page reload, no refetch — works
 * correctly via pure CSS.
 *
 * See ../_lib/consent-queries.ts's module doc for the CONFIRMED FINDING that
 * this tab's error banner (not the stats+tables view) is the page's
 * PERMANENT state on any tenant DB built from the committed schema
 * (`admin_users`, joined by the data_access_logs query, is a platform-level
 * table absent from every tenant DB) — replicated faithfully here, not
 * "fixed".
 */
export interface ConsentTabProps {
  db: Kysely<TenantDB>;
}

const CONSENT_TYPE_LABELS: Record<string, string> = {
  privacy_policy: '🔒 นโยบายความเป็นส่วนตัว',
  terms_of_service: '📋 ข้อตกลงการใช้งาน',
  health_data: '💊 ข้อมูลสุขภาพ',
  marketing: '📢 การตลาด',
};

function actionBadge(action: ConsentLogRow['action']) {
  if (action === 'accept') return <span className="text-green-600">✅ ยอมรับ</span>;
  if (action === 'withdraw') return <span className="text-red-600">❌ ถอนความยินยอม</span>;
  if (action === 'update') return <span className="text-blue-600">🔄 อัพเดท</span>;
  return action;
}

export async function ConsentTab({ db }: ConsentTabProps) {
  const { error, stats, recentLogs, accessLogs } = await getConsentPageData(db);

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h2 className="text-xl font-bold">🔒 Consent Management</h2>
          <p className="text-gray-500">จัดการความยินยอมและ Audit Log ตาม PDPA</p>
        </div>
        <div className="flex gap-2">
          <a href="/privacy-policy.php" target="_blank" rel="noreferrer" className="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200">
            📄 Privacy Policy
          </a>
          <a href="/terms-of-service.php" target="_blank" rel="noreferrer" className="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200">
            📋 Terms of Service
          </a>
        </div>
      </div>

      {error ? (
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
          <p>❌ {error}</p>
          <p className="text-sm mt-2">กรุณารัน migration ก่อน</p>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div className="bg-white rounded-xl shadow p-4">
              <div className="text-3xl font-bold text-blue-600">{stats.totalConsented.toLocaleString('en-US')}</div>
              <div className="text-gray-500 text-sm">ผู้ใช้ที่ยินยอมแล้ว</div>
            </div>
            <div className="bg-white rounded-xl shadow p-4">
              <div className="text-3xl font-bold text-green-600">{(stats.byType.privacy_policy ?? 0).toLocaleString('en-US')}</div>
              <div className="text-gray-500 text-sm">ยอมรับ Privacy Policy</div>
            </div>
            <div className="bg-white rounded-xl shadow p-4">
              <div className="text-3xl font-bold text-purple-600">{(stats.byType.terms_of_service ?? 0).toLocaleString('en-US')}</div>
              <div className="text-gray-500 text-sm">ยอมรับ Terms of Service</div>
            </div>
            <div className="bg-white rounded-xl shadow p-4">
              <div className="text-3xl font-bold text-orange-600">{(stats.byType.health_data ?? 0).toLocaleString('en-US')}</div>
              <div className="text-gray-500 text-sm">ยินยอมข้อมูลสุขภาพ</div>
            </div>
          </div>

          <div className="bg-white rounded-xl shadow">
            {/* CSS-only sub-tab toggle — see module doc. */}
            <input type="radio" id="consent-subtab-consent" name="consentSubTab" defaultChecked className="sr-only" />
            <input type="radio" id="consent-subtab-access" name="consentSubTab" className="peer sr-only" />

            <div className="border-b">
              <nav className="flex -mb-px">
                <label htmlFor="consent-subtab-consent" className="consent-tab-btn px-6 py-3 border-b-2 border-blue-500 text-blue-600 font-medium cursor-pointer">
                  📝 Consent Logs
                </label>
                <label htmlFor="consent-subtab-access" className="consent-tab-btn px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700 cursor-pointer">
                  👁️ Data Access Logs
                </label>
              </nav>
            </div>

            <div className="p-4 peer-checked:hidden">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวลา</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ใช้</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">การกระทำ</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวอร์ชัน</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {recentLogs.map((log) => (
                      <tr key={log.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm text-gray-500">{formatConsentLogTimestamp(log.createdAt)}</td>
                        <td className="px-4 py-3">
                          <div className="font-medium">{log.displayName || 'Unknown'}</div>
                          <div className="text-xs text-gray-400">{(log.lineUserId || '').slice(0, 10)}...</div>
                        </td>
                        <td className="px-4 py-3 text-sm">{CONSENT_TYPE_LABELS[log.consentType] ?? log.consentType}</td>
                        <td className="px-4 py-3 text-sm">{actionBadge(log.action)}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">v{log.consentVersion}</td>
                        <td className="px-4 py-3 text-xs text-gray-400">{log.ipAddress || '-'}</td>
                      </tr>
                    ))}
                    {recentLogs.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                          ยังไม่มีข้อมูล Consent Log
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="p-4 hidden peer-checked:block">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวลา</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">การกระทำ</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ข้อมูลที่เข้าถึง</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {accessLogs.map((log: DataAccessLogRow) => (
                      <tr key={log.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm text-gray-500">{formatConsentLogTimestamp(log.createdAt)}</td>
                        <td className="px-4 py-3 font-medium">{log.adminName}</td>
                        <td className="px-4 py-3 text-sm">{log.action}</td>
                        <td className="px-4 py-3 text-sm">
                          <span className="text-gray-500">{log.resourceType}</span>
                          {log.targetUser ? <span className="text-blue-600"> ({log.targetUser})</span> : null}
                        </td>
                        <td className="px-4 py-3 text-xs text-gray-400">{log.ipAddress || '-'}</td>
                      </tr>
                    ))}
                    {accessLogs.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">
                          ยังไม่มีข้อมูล Access Log
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
