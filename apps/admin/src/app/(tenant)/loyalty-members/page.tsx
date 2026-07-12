import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { getLoyaltyMembersData } from './queries';
import { MembersListClient } from './_components/MembersListClient';

/**
 * (tenant)/loyalty-members/page.tsx — Server Component port of
 * loyalty-members.php ("สมาชิกเบอร์ (สะสมแต้ม)" — phone-only loyalty members
 * with no LINE link, `users.line_user_id LIKE 'offline:%'`). Serves at the
 * same clean URL PHP does — `/loyalty-members`, with `?q=` search preserved.
 *
 * Access gate: loyalty-members.php has no page-specific role check beyond
 * `includes/header.php`'s generic "must be logged in" requirement (confirmed
 * by reading the full 328-line source) — reuses users/_lib/session's
 * requireTenantPageContext(), same cross-route-import convention as
 * activity-logs and user-detail.
 *
 * MUTATION FOUND (flagged per this batch's brief — changes this page's risk
 * profile from pure-read to has-a-mutation): the orchestrator's page-level
 * grep for `$_POST`/`action=`/`REQUEST_METHOD` on loyalty-members.php itself
 * returned zero matches because the mutation is a client-side
 * `fetch('api/points-claim.php', {action:'give_by_phone'})` call to a
 * SEPARATE existing PHP endpoint, not inline POST handling in
 * loyalty-members.php. Ported as `giveByPhoneAction` in actions.ts (+
 * `memberDetailAction` for the read-only `member_detail` fetch) — see
 * actions.ts and _lib/pointsClaim.ts for the full port + explicitly deferred
 * parts (the LINE Flex receipt push, out of reach without packages/line).
 */
export interface LoyaltyMembersPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export const metadata: Metadata = { title: 'สมาชิกเบอร์ (สะสมแต้ม)' };

export default async function LoyaltyMembersPage({ searchParams }: LoyaltyMembersPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();

  const lineAccountId = session.currentBotId ?? 0;
  const searchRaw = params.q;
  const search = (Array.isArray(searchRaw) ? searchRaw[0] : searchRaw) ?? '';
  const trimmedSearch = search.trim();

  const { stats, members } = await getLoyaltyMembersData(db, lineAccountId, trimmedSearch);

  return (
    <div className="max-w-5xl mx-auto px-3 py-4">
      <MembersListClient
        members={members}
        search={trimmedSearch}
        betweenHeaderAndList={
          <>
            {/* Overview */}
            <div className="grid grid-cols-3 gap-2 mb-4">
              <div className="bg-white rounded-xl border p-3 text-center">
                <div className="text-2xl font-extrabold text-gray-800">{stats.total.toLocaleString()}</div>
                <div className="text-[11px] text-gray-500 mt-0.5">สมาชิกเบอร์ทั้งหมด</div>
              </div>
              <div className="bg-white rounded-xl border p-3 text-center">
                <div className="text-2xl font-extrabold text-emerald-600">{stats.points.toLocaleString()}</div>
                <div className="text-[11px] text-gray-500 mt-0.5">แต้มคงเหลือรวม</div>
              </div>
              <div className="bg-white rounded-xl border p-3 text-center">
                <div className="text-2xl font-extrabold text-amber-600">{stats.today.toLocaleString()}</div>
                <div className="text-[11px] text-gray-500 mt-0.5">เพิ่มวันนี้</div>
              </div>
            </div>

            {/* Search */}
            <form method="get" className="flex gap-2 mb-3">
              <div className="relative flex-1">
                <input
                  type="text"
                  name="q"
                  defaultValue={trimmedSearch}
                  placeholder="ค้นหาด้วยเบอร์หรือชื่อ"
                  className="w-full border rounded-lg pl-3 pr-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none"
                />
              </div>
              <button type="submit" className="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium">
                ค้นหา
              </button>
              {trimmedSearch !== '' ? (
                <a href="/loyalty-members" className="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 self-center">
                  ล้าง
                </a>
              ) : null}
            </form>
          </>
        }
      />
    </div>
  );
}
