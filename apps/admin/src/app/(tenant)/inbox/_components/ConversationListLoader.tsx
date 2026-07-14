'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { usePathname, useSearchParams } from 'next/navigation';
import type { ConversationPlatform, ConversationRow } from '@/app/api/inbox/conversations/_lib/query';
import { ConversationListItem } from './ConversationListItem';

/**
 * ConversationListLoader — the `#userList` container + background
 * cursor-walk, port of inbox-v2.php's `ConversationLoader` class
 * (lines 11524-11700: `init()`/`autoLoadAll()`/`loadMore()`/
 * `handleIntersection()`) plus the "Load More Sentinel" markup it manages
 * (lines 3155-3170) and the SSR conversation rows themselves
 * (lines 3070-3153) — this component owns BOTH the initially-SSR'd rows
 * (seeded via props, so first paint is server-rendered HTML, not a client
 * fetch waterfall) and every subsequently-appended row, so there is exactly
 * one `#userList`/`.user-item` DOM tree for FilterBar/SearchBox to operate
 * on, matching PHP's single real DOM list.
 *
 * PLATFORM-SWITCH HANDLING (see also ChannelSwitcher.tsx's module doc):
 * (tenant)/inbox/layout.tsx can never read `?platform=` (Next.js layouts
 * don't receive `searchParams`), so it always seeds this component with the
 * LINE platform's first page (`initialPlatform` is always `'line'`). This
 * component reads the CURRENT platform reactively via `useSearchParams()`
 * (a hook, works from any client component regardless of the layout/page
 * split) and, whenever it differs from `initialPlatform` — on mount (a
 * direct/hard navigation to `?platform=facebook`) or later (clicking a
 * ChannelSwitcher tab) — discards the seeded rows and re-fetches page 1 for
 * the requested platform, then resumes the normal background walk. A
 * monotonic `generation` counter guards against a slow in-flight request
 * from an abandoned platform still appending stale rows after the switch.
 *
 * `MAX_BATCHES`/`BATCH_DELAY_MS`/limit=200 mirror ConversationLoader's own
 * `maxBatches`/`batchDelay` safety limits exactly (inbox-v2.php lines
 * 11532, 11576) — including inheriting the `getConversationsDelta()`
 * internal 100-row-per-batch cap (see api/inbox/conversations/_lib/
 * query.ts's module doc): each `limit=200` batch call actually returns at
 * most 101 rows, exactly like production.
 */

export interface ConversationListLoaderProps {
  initialConversations: ConversationRow[];
  initialCursor: string | null;
  initialHasMore: boolean;
  /** The platform layout.tsx actually fetched `initialConversations` for — always 'line', see module doc. */
  initialPlatform: ConversationPlatform;
}

const BATCH_LIMIT = 200;
export const MAX_BATCHES = 50;
export const BATCH_DELAY_MS = 100;

interface ConversationsPageBody {
  success: boolean;
  data?: { conversations: ConversationRow[]; next_cursor: string | null; has_more: boolean };
}

function activePlatformFrom(searchParams: URLSearchParams | null): ConversationPlatform {
  const raw = searchParams?.get('platform');
  return raw === 'facebook' || raw === 'tiktok' ? raw : 'line';
}

export function ConversationListLoader({ initialConversations, initialCursor, initialHasMore, initialPlatform }: ConversationListLoaderProps) {
  const searchParams = useSearchParams();
  const pathname = usePathname();
  const platform = activePlatformFrom(searchParams);

  const [conversations, setConversations] = useState<ConversationRow[]>(initialConversations);
  const [hasMore, setHasMore] = useState(initialHasMore);
  const [loading, setLoading] = useState(false);

  const cursorRef = useRef(initialCursor);
  const hasMoreRef = useRef(initialHasMore);
  const loadingRef = useRef(false);
  const generationRef = useRef(0);
  const isFirstRunRef = useRef(true);
  const sentinelRef = useRef<HTMLDivElement | null>(null);

  const activeUserId = pathname?.startsWith('/inbox/') ? pathname.slice('/inbox/'.length) : null;

  const fetchPage = useCallback(async (forPlatform: ConversationPlatform, cursor: string | null): Promise<ConversationsPageBody['data'] | null> => {
    const params = new URLSearchParams({ limit: String(BATCH_LIMIT) });
    if (cursor) {
      params.set('cursor', cursor);
    }
    if (forPlatform !== 'line') {
      params.set('platform', forPlatform);
    }
    const res = await fetch(`/api/inbox/conversations?${params.toString()}`);
    const body = (await res.json()) as ConversationsPageBody;
    return body.success && body.data ? body.data : null;
  }, []);

  const runAutoLoad = useCallback(
    async (forPlatform: ConversationPlatform, startCursor: string | null, startHasMore: boolean, generation: number) => {
      let cursor = startCursor;
      let more = startHasMore;
      let batchCount = 0;

      while (more && batchCount < MAX_BATCHES) {
        if (generation !== generationRef.current) {
          return;
        }
        batchCount += 1;
        loadingRef.current = true;
        setLoading(true);

        const page = await fetchPage(forPlatform, cursor);
        if (generation !== generationRef.current) {
          return;
        }
        loadingRef.current = false;
        setLoading(false);

        if (!page || page.conversations.length === 0) {
          more = false;
          hasMoreRef.current = false;
          setHasMore(false);
          break;
        }

        setConversations((prev) => [...prev, ...page.conversations]);
        cursor = page.next_cursor;
        more = page.has_more;
        cursorRef.current = cursor;
        hasMoreRef.current = more;
        setHasMore(more);

        if (more) {
          // eslint-disable-next-line no-await-in-loop
          await new Promise((resolve) => setTimeout(resolve, BATCH_DELAY_MS));
        }
      }
    },
    [fetchPage]
  );

  // Single effect covers: (a) the normal case (mount on the platform layout.tsx
  // already fetched) — just resume the background walk from where SSR left
  // off; (b) mounting directly on / switching to a different platform —
  // discard the seeded rows and reload page 1 for it, then resume the walk.
  useEffect(() => {
    const generation = (generationRef.current += 1);
    const isFirstRun = isFirstRunRef.current;
    isFirstRunRef.current = false;

    if (isFirstRun && platform === initialPlatform) {
      if (initialHasMore) {
        void runAutoLoad(platform, initialCursor, initialHasMore, generation);
      }
      return;
    }

    setConversations([]);
    setHasMore(true);
    hasMoreRef.current = true;
    cursorRef.current = null;
    setLoading(true);

    void (async () => {
      const page = await fetchPage(platform, null);
      if (generation !== generationRef.current) {
        return;
      }
      setLoading(false);
      if (!page) {
        setConversations([]);
        setHasMore(false);
        hasMoreRef.current = false;
        return;
      }
      setConversations(page.conversations);
      cursorRef.current = page.next_cursor;
      hasMoreRef.current = page.has_more;
      setHasMore(page.has_more);
      if (page.has_more) {
        void runAutoLoad(platform, page.next_cursor, page.has_more, generation);
      }
    })();
    // Deliberately platform-only: initialCursor/initialHasMore/initialPlatform
    // are this component's seed props (read once via refs/isFirstRunRef, not
    // meant to re-trigger the effect if the parent re-renders with the same
    // seed), and fetchPage/runAutoLoad are stable useCallback references.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [platform]);

  // IntersectionObserver fallback for manual scroll (inbox-v2.php lines
  // 11548-11558). Defensive `typeof` guard: jsdom/older browsers may not
  // implement it, and the background auto-load walk above already covers
  // every row up to MAX_BATCHES on its own.
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined' || !sentinelRef.current) {
      return undefined;
    }
    const generation = generationRef.current;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting || !hasMoreRef.current || loadingRef.current) {
          return;
        }
        void (async () => {
          loadingRef.current = true;
          setLoading(true);
          const page = await fetchPage(platform, cursorRef.current);
          if (generation !== generationRef.current) {
            return;
          }
          loadingRef.current = false;
          setLoading(false);
          if (!page) {
            return;
          }
          setConversations((prev) => [...prev, ...page.conversations]);
          cursorRef.current = page.next_cursor;
          hasMoreRef.current = page.has_more;
          setHasMore(page.has_more);
        })();
      },
      { rootMargin: '200px', threshold: 0 }
    );
    observer.observe(sentinelRef.current);
    return () => observer.disconnect();
  }, [fetchPage, platform]);

  return (
    <div id="userList" className="flex-1 overflow-y-auto chat-scroll" tabIndex={0}>
      {conversations.length === 0 && !loading ? (
        <div className="p-6 text-center text-gray-400">
          <p className="text-sm">ยังไม่มีแชท</p>
        </div>
      ) : (
        conversations.map((conversation) => (
          <ConversationListItem key={conversation.id} conversation={conversation} isActive={activeUserId === String(conversation.id)} />
        ))
      )}
      <div
        id="loadMoreSentinel"
        ref={sentinelRef}
        data-cursor={cursorRef.current ?? ''}
        data-has-more={hasMore ? 'true' : 'false'}
        className="p-4 text-center"
      >
        {loading ? (
          <p className="text-xs text-gray-400 mt-1">กำลังโหลดเพิ่มเติม...</p>
        ) : hasMore ? (
          <p className="text-xs text-gray-400">โหลดแล้ว {conversations.length} รายการ</p>
        ) : (
          <p className="text-xs text-gray-400">โหลดครบ {conversations.length} รายการ</p>
        )}
      </div>
    </div>
  );
}
