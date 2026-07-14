import type { ReactNode } from 'react';
import { fetchConversationsPage } from '@/app/api/inbox/conversations/_lib/query';
import { requireTenantPageContext } from './_lib/session';
import { getInboxFilterOptions } from './_lib/filterOptions';
import { SearchBox } from './_components/SearchBox';
import { ChannelSwitcher } from './_components/ChannelSwitcher';
import { FilterBar } from './_components/FilterBar';
import { ConversationListLoader } from './_components/ConversationListLoader';

/**
 * (tenant)/inbox/layout.tsx — persistent left-pane sidebar wrapping every
 * `/inbox` route (`/inbox` itself — page.tsx below — and
 * `/inbox/[userId]`, mig-ui's territory). Port of inbox-v2.php's sidebar
 * markup (lines 2930-3172): header, search box, LINE/Facebook/TikTok
 * channel switcher, 4 filter dropdowns, new-followers count chip, and the
 * conversation list itself.
 *
 * SSRs the unfiltered first page (limit=200, no search/filters/since —
 * inbox-v2.php lines 993-1167's SSR query is deliberately UNFILTERED; see
 * api/inbox/conversations/route.ts's module doc) via
 * `fetchConversationsPage()` directly (NOT the Route Handler's own
 * `getConversationsDelta()` wrapper — see _lib/query.ts's "ARCHITECTURE
 * NOTE" for why: that wrapper's internal 100-row cap must NOT leak into the
 * real 200-row initial paint).
 *
 * PLATFORM: always fetches the LINE platform's first page — Next.js layouts
 * never receive `searchParams` (only `page.tsx` does), so this can't itself
 * honor a `?platform=` on the URL. ConversationListLoader.tsx corrects this
 * client-side after mount (reads the URL via `useSearchParams()`, refetches
 * page 1 for the requested platform if it differs) — see that component's
 * module doc for the full rationale.
 *
 * NOT ported (out of this batch's scope, see build report): the "ให้แต้ม
 * (ขายหน้าร้าน)" give-points button (inbox-v2.php lines 2998-3005, opens a
 * checkout/points-claim modal — a write flow outside this read-only batch's
 * component list) and the sound-toggle/live-indicator controls (lines
 * 2949-2956, wired to the websocket real-time layer another stream owns
 * this round).
 */
export default async function InboxLayout({ children }: { children: ReactNode }) {
  const { db, session } = await requireTenantPageContext();
  const accountId = session.currentBotId ?? 1;

  const [filterOptions, initialPage] = await Promise.all([
    getInboxFilterOptions(db, session.currentBotId),
    fetchConversationsPage(db, accountId, { limit: 200 }),
  ]);

  return (
    <div className="h-full min-h-[calc(100vh-4rem)] flex bg-white overflow-hidden">
      <div id="inboxSidebar" className="w-72 flex-shrink-0 bg-white border-r flex flex-col">
        <div className="p-3 border-b flex items-center justify-between" style={{ background: 'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)' }}>
          <h2 className="text-white font-bold flex items-center gap-2">
            <span>Inbox</span>
            <span id="totalUnread" className="text-xs bg-white/20 px-2 py-0.5 rounded-full">
              {initialPage.conversations.length}
            </span>
          </h2>
        </div>

        <SearchBox />

        <ChannelSwitcher counts={filterOptions.platformCounts} />

        <FilterBar tags={filterOptions.allTagsForFilter} admins={filterOptions.allAdmins} currentAdminId={session.adminUserId} />

        <div className="px-2 py-2 border-b bg-white">
          <button
            type="button"
            disabled
            className="w-full flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-xs font-semibold border border-amber-200 bg-amber-50 text-amber-800"
            title="เร็ว ๆ นี้ — ยังไม่รองรับการสลับไปดูรายชื่อนี้"
          >
            <span>🆕 เพิ่งแอด · ยังไม่ทัก</span>
            {filterOptions.uncontactedFollowerCount > 0 ? (
              <span className="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold">
                {filterOptions.uncontactedFollowerCount}
              </span>
            ) : null}
          </button>
        </div>

        <ConversationListLoader
          initialConversations={initialPage.conversations}
          initialCursor={initialPage.next_cursor}
          initialHasMore={initialPage.has_more}
          initialPlatform="line"
        />
      </div>

      <div className="flex-1 min-w-0 flex flex-col">{children}</div>
    </div>
  );
}
