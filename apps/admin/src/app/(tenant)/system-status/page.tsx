import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { getSystemStatus, type CheckStatus, type OverallStatus, type StatusCheck } from './queries';

/**
 * (tenant)/system-status/page.tsx — Server Component port of
 * system-status.php ("เช็คสถานะระบบ Inbox V2"). Serves at the same clean URL
 * PHP does — `/system-status`, no query params.
 *
 * Access gate: system-status.php has no page-specific role check beyond
 * `includes/auth_check.php`'s generic "must be logged in" requirement
 * (confirmed by reading the full 295-line source) — reuses
 * users/_lib/session's requireTenantPageContext(), same cross-route-import
 * convention as loyalty-members/activity-logs/user-detail.
 *
 * `$currentBotId = $_SESSION['current_bot_id'] ?? 1` (system-status.php line
 * 16) — mirrored exactly below, including the literal `?? 1` fallback (most
 * other Phase 2 pages fall back to null/0, this one specifically falls back
 * to bot id 1).
 *
 * No mutation to port — the PHP page is reload-only (its one "action" is a
 * `location.reload()` button), so there is no actions.ts for this route.
 *
 * SIMPLIFICATIONS flagged in the build report (system-status.php's own
 * "System Info" footer, lines 271-292, reports PHP-runtime facts that don't
 * exist on this side of the migration):
 *   - `phpversion()` -> Node.js version (`process.version`) — there is no PHP
 *     runtime for this page after cutover.
 *   - `memory_get_usage()` (PHP process memory) -> `process.memoryUsage().heapUsed`
 *     (closest Node analog to "this execution's own memory", vs. `.rss`
 *     which would include the whole Next.js server process).
 */
export const metadata: Metadata = { title: 'เช็คสถานะระบบ' };

const STATUS_ICON: Record<CheckStatus, string> = { ok: '✅', warning: '⚠️', error: '❌', not_ported: '🚧' };

const OVERALL_BANNER: Record<OverallStatus, { icon: string; heading: string; className: string; headingClassName: string }> = {
  healthy: { icon: '✅', heading: 'ระบบทำงานปกติ', className: 'bg-green-50 border border-green-200', headingClassName: 'text-green-700' },
  degraded: { icon: '⚠️', heading: 'ระบบทำงานได้บางส่วน', className: 'bg-yellow-50 border border-yellow-200', headingClassName: 'text-yellow-700' },
  critical: { icon: '❌', heading: 'ระบบมีปัญหา', className: 'bg-red-50 border border-red-200', headingClassName: 'text-red-700' },
};

function labelForKey(key: string): string {
  // Mirrors PHP's `ucwords(str_replace('_', ' ', $key))`.
  return key
    .split('_')
    .map((w) => (w.length > 0 ? w[0]!.toUpperCase() + w.slice(1) : w))
    .join(' ');
}

function nowInBangkok(pattern: 'datetime' | 'time'): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).formatToParts(new Date());
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  const time = `${get('hour')}:${get('minute')}:${get('second')}`;
  if (pattern === 'time') return time;
  return `${get('day')}/${get('month')}/${get('year')} ${time}`;
}

function CheckCard({ check }: { check: StatusCheck }) {
  return (
    <div className="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow" data-testid={`check-${check.key}`}>
      <div className="flex items-start gap-3">
        <span className="text-2xl">{STATUS_ICON[check.status]}</span>
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-gray-800 truncate">{labelForKey(check.key)}</h3>
          <p className="text-sm text-gray-500 mt-1">{check.message}</p>
        </div>
      </div>
    </div>
  );
}

export default async function SystemStatusPage() {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  const { checks, overallStatus } = await getSystemStatus(db, currentBotId);
  const banner = OVERALL_BANNER[overallStatus];

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 flex items-center gap-3">
          <span className="text-3xl">🔍</span>
          เช็คสถานะระบบ Inbox V2
        </h1>
        <p className="text-gray-500 mt-1">ตรวจสอบสถานะการทำงานของระบบทั้งหมด</p>
      </div>

      <div className={`mb-6 p-4 rounded-xl ${banner.className}`}>
        <div className="flex items-center gap-3">
          <span className="text-4xl">{banner.icon}</span>
          <div>
            <h2 className={`text-lg font-semibold ${banner.headingClassName}`} data-testid="overall-heading">
              {banner.heading}
            </h2>
            <p className="text-sm text-gray-600">ตรวจสอบเมื่อ: {nowInBangkok('datetime')}</p>
          </div>
          <div className="ml-auto">
            <a href="/system-status" className="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 flex items-center gap-2">
              🔄 รีเฟรช
            </a>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {checks.map((check) => (
          <CheckCard key={check.key} check={check} />
        ))}
      </div>

      <div className="mt-6 bg-white rounded-xl border p-4">
        <h3 className="font-semibold text-gray-800 mb-3">🚀 Quick Actions</h3>
        <div className="flex flex-wrap gap-3">
          <a href="/inbox-v2" className="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 flex items-center gap-2">
            📥 เปิด Inbox V2
          </a>
          <a href="/inbox" className="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 flex items-center gap-2">
            📥 เปิด Inbox V1
          </a>
          <a href="/settings?tab=vibe-selling" className="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 flex items-center gap-2">
            ⚙️ ตั้งค่า Vibe Selling
          </a>
          <a href="/dev-dashboard" className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 flex items-center gap-2">
            💻 Dev Dashboard
          </a>
        </div>
      </div>

      <div className="mt-6 bg-gray-50 rounded-xl border p-4">
        <h3 className="font-semibold text-gray-800 mb-3">📋 System Info</h3>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <span className="text-gray-500">Node.js Version:</span> <span className="font-medium">{process.version}</span>
          </div>
          <div>
            <span className="text-gray-500">Current Bot ID:</span> <span className="font-medium">{currentBotId}</span>
          </div>
          <div>
            <span className="text-gray-500">Server Time:</span> <span className="font-medium">{nowInBangkok('time')}</span>
          </div>
          <div>
            <span className="text-gray-500">Memory Usage:</span>{' '}
            <span className="font-medium">{(process.memoryUsage().heapUsed / 1024 / 1024).toFixed(2)} MB</span>
          </div>
        </div>
      </div>
    </div>
  );
}
