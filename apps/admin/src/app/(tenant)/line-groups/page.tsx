import type { Metadata } from 'next';
import { EmptyState } from '@/components/EmptyState';
import { requireTenantPageContext } from '../users/_lib/session';
import { formatNumber } from '../users/_lib/format';
import { getLineGroupsPageData } from './queries';
import { LineGroupRow } from './_components/LineGroupRow';

/**
 * (tenant)/line-groups/page.tsx — Server Component port of line-groups.php
 * (325 LOC, confirmed by reading the full file). Serves at the same clean
 * URL PHP does — `/line-groups`, with the flash-banner convention carried
 * over as `?message=`/`?error=` searchParams (see actions.ts's module doc).
 *
 * Access gate: line-groups.php has no page-specific role check beyond a
 * plain `require_once 'includes/header.php'` (grepped the full file for
 * isSuperAdmin/isAdmin/isStaff — zero hits).
 */
export const metadata: Metadata = { title: 'จัดการกลุ่ม LINE' };

interface LineGroupsPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function LineGroupsPage({ searchParams }: LineGroupsPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();
  const { groups, stats } = await getLineGroupsPageData(db, session.currentBotId);

  const message = first(params, 'message');
  const error = first(params, 'error');

  return (
    <div className="container mx-auto px-4 py-4">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">👥 จัดการกลุ่ม LINE</h1>
      </div>

      {message ? <div className="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{message}</div> : null}
      {error ? <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{error}</div> : null}

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-3xl font-bold text-blue-500">{formatNumber(stats.total)}</div>
          <div className="text-gray-500">กลุ่มทั้งหมด</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-3xl font-bold text-green-500">{formatNumber(stats.active)}</div>
          <div className="text-gray-500">กลุ่มที่ใช้งาน</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-3xl font-bold text-purple-500">{formatNumber(stats.totalMembers)}</div>
          <div className="text-gray-500">สมาชิกรวม</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-3xl font-bold text-orange-500">{formatNumber(stats.totalMessages)}</div>
          <div className="text-gray-500">ข้อความในกลุ่ม</div>
        </div>
      </div>

      {/* Groups List */}
      <div className="bg-white rounded-lg shadow">
        <div className="p-4 border-b flex justify-between items-center">
          <h3 className="font-bold">รายการกลุ่ม</h3>
          <span className="text-sm text-gray-500">{groups.length} กลุ่ม</span>
        </div>

        {groups.length === 0 ? (
          <EmptyState
            icon={<span>👥</span>}
            heading="ยังไม่มีกลุ่มที่บอทเข้าร่วม"
            sub="เมื่อมีคนเชิญบอทเข้ากลุ่ม จะแสดงที่นี่"
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left">กลุ่ม</th>
                  <th className="px-4 py-3 text-center">บอท</th>
                  <th className="px-4 py-3 text-center">สมาชิก</th>
                  <th className="px-4 py-3 text-center">ข้อความ</th>
                  <th className="px-4 py-3 text-center">สถานะ</th>
                  <th className="px-4 py-3 text-center">เข้าร่วมเมื่อ</th>
                  <th className="px-4 py-3 text-center">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                {groups.map((group) => (
                  <LineGroupRow key={group.id} group={group} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
