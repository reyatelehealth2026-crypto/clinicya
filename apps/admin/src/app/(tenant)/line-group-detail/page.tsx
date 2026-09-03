import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../users/_lib/session';
import { formatDateTimeDMY, formatNumber } from '../users/_lib/format';
import { getLineGroupDetailPageData } from './queries';
import { truncateMb } from './_lib/text';

/**
 * (tenant)/line-group-detail/page.tsx — Server Component port of
 * line-group-detail.php (179 LOC, read-only, confirmed by reading the full
 * file). `?id=<line_groups.id>` query param — grep-verified `$groupId =
 * $_GET['id'] ?? 0;`, NOT a nested `/line-groups/[id]` dynamic segment: a
 * SEPARATE top-level route (this directory) mirroring the PHP file's own
 * separate filename, matching `/user-detail?id=N`'s established precedent.
 * Cross-links from still-PHP pages (line-groups.php's own `<a
 * href="line-group-detail.php?id=...">`) keep working at this same clean
 * path once flipped.
 *
 * Redirect-if-not-found (`header('Location: line-groups.php'); exit;`,
 * lines 24-27) becomes `redirect('/line-groups')`.
 *
 * Access gate: line-group-detail.php has no page-specific role check beyond
 * a plain `require_once 'includes/header.php'` (grepped the full file for
 * isSuperAdmin/isAdmin/isStaff — zero hits). No actions.ts — this page has
 * no POST handling anywhere in the PHP source.
 *
 * No `metadata`/`generateMetadata` export — same choice user-detail/page.tsx
 * (the other `?id=N` query-param detail route) already makes: PHP sets
 * `$pageTitle = 'กลุ่ม: ' . ($group['group_name'] ?: 'Unknown')` from the SAME
 * row this component already fetches for its own render, and Next has no
 * built-in way to hand a Server Component's already-fetched data to
 * `generateMetadata` — computing it there would mean a second, duplicate
 * `getLineGroupDetailPageData()` call (and thus a second round-trip of
 * every query in queries.ts) purely for the <title> tag. Falls back to the
 * (tenant) layout's default title instead, exactly as user-detail does.
 */

interface LineGroupDetailPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function LineGroupDetailPage({ searchParams }: LineGroupDetailPageProps) {
  const params = await searchParams;
  const groupId = Number.parseInt(first(params, 'id') ?? '', 10) || 0;
  if (!groupId) {
    redirect('/line-groups');
  }

  const { db } = await requireTenantPageContext();
  const data = await getLineGroupDetailPageData(db, groupId);
  if (!data) {
    redirect('/line-groups');
  }

  const { group, members, messages } = data;

  return (
    <div className="container mx-auto px-4 py-4">
      <div className="mb-6">
        <a href="/line-groups" className="text-green-600 hover:text-green-700">
          ← กลับไปรายการกลุ่ม
        </a>
      </div>

      {/* Group Info */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <div className="flex items-start gap-4">
          {group.pictureUrl ? (
            <img src={group.pictureUrl} alt="" className="w-20 h-20 rounded-full" />
          ) : (
            <div className="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center text-3xl text-gray-400">👥</div>
          )}

          <div className="flex-1">
            <h1 className="text-2xl font-bold">{group.groupName || 'Unknown Group'}</h1>
            <p className="text-gray-500">
              {group.groupType === 'room' ? 'Room' : 'Group'} • บอท: {group.botName ?? '-'}
            </p>

            <div className="flex gap-4 mt-3">
              <div>
                <span className="text-2xl font-bold text-blue-500">{formatNumber(group.memberCount)}</span>{' '}
                <span className="text-gray-500 text-sm">สมาชิก</span>
              </div>
              <div>
                <span className="text-2xl font-bold text-green-500">{formatNumber(group.totalMessages)}</span>{' '}
                <span className="text-gray-500 text-sm">ข้อความ</span>
              </div>
            </div>

            <div className="mt-3">
              {group.isActive ? (
                <span className="px-3 py-1 bg-green-100 text-green-600 rounded-full text-sm">Active</span>
              ) : (
                <span className="px-3 py-1 bg-red-100 text-red-600 rounded-full text-sm">Left</span>
              )}
              <span className="text-sm text-gray-500 ml-2">เข้าร่วมเมื่อ {formatDateTimeDMY(group.joinedAt)}</span>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Members */}
        <div className="bg-white rounded-lg shadow">
          <div className="p-4 border-b">
            <h3 className="font-bold">👥 สมาชิก ({members.length})</h3>
          </div>
          <div className="p-4 max-h-96 overflow-y-auto">
            {members.length === 0 ? (
              <p className="text-gray-500 text-center py-4">ยังไม่มีข้อมูลสมาชิก</p>
            ) : (
              <div className="space-y-2">
                {members.map((member) => (
                  <div key={member.id} className={`flex items-center gap-3 p-2 rounded-lg ${member.isActive ? 'bg-gray-50' : 'bg-red-50'}`}>
                    {member.pictureUrl ? (
                      <img src={member.pictureUrl} alt="" className="w-10 h-10 rounded-full" />
                    ) : (
                      <div className="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-400">👤</div>
                    )}
                    <div className="flex-1">
                      <div className="font-medium">{member.displayName || 'Unknown'}</div>
                      <div className="text-xs text-gray-500">
                        ข้อความ: {formatNumber(member.totalMessages)}
                        {member.lastMessageAt ? ` • ล่าสุด: ${formatDateDmHm(member.lastMessageAt)}` : ''}
                      </div>
                    </div>
                    {!member.isActive ? <span className="text-xs text-red-500">ออกแล้ว</span> : null}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Recent Messages */}
        <div className="bg-white rounded-lg shadow">
          <div className="p-4 border-b">
            <h3 className="font-bold">💬 ข้อความล่าสุด</h3>
          </div>
          <div className="p-4 max-h-96 overflow-y-auto">
            {messages.length === 0 ? (
              <p className="text-gray-500 text-center py-4">ยังไม่มีข้อความ</p>
            ) : (
              <div className="space-y-3">
                {messages.map((msg) => (
                  <div key={msg.id} className="border-b pb-2">
                    <div className="flex justify-between items-start">
                      <span className="font-medium text-sm">{msg.displayName || 'Unknown'}</span>
                      <span className="text-xs text-gray-400">{formatDateDmHm(msg.createdAt)}</span>
                    </div>
                    <p className="text-gray-600 text-sm mt-1">
                      {msg.messageType !== 'text' ? <span className="text-gray-400">[{msg.messageType}] </span> : null}
                      {truncateMb(msg.content ?? '', 100)}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Mirrors PHP's `date('d/m H:i', strtotime($ts))` — no year, unlike
 * users/_lib/format.ts's `formatDateTimeDMY` (`d/m/Y H:i`). Kept local to
 * this route rather than added to the shared format.ts (outside this
 * batch's allowed paths).
 */
function formatDateDmHm(value: Date | string): string {
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) {
    return '-';
  }
  const pad2 = (n: number) => String(n).padStart(2, '0');
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}
