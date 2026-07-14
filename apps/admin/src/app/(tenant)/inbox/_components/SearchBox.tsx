'use client';

import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import type { ConversationRow } from '@/app/api/inbox/conversations/_lib/query';
import { ConversationListItem } from './ConversationListItem';

/**
 * SearchBox — port of inbox-v2.php's search `<input id="userSearch">`
 * (lines 2960-2971) + its `oninput="debouncedSearch(this.value)"` handler,
 * which ultimately calls `performHybridSearch()`.
 *
 * SIMPLIFICATION vs PHP: PHP's real search is a "hybrid" call (a different,
 * richer endpoint than plain getConversations, plus it drives BOTH an
 * autocomplete dropdown AND re-filters the main `#userList`, coordinating
 * with applyFilters() — inbox-v2.php lines 7962-7979). This batch's brief
 * scopes SearchBox to "debounced, calls the Route Handler's `search`
 * param" — so this calls THIS batch's own /api/inbox/conversations route
 * with `?search=`, and renders matches as a self-contained autocomplete
 * dropdown (mirroring the `#searchAutocomplete` container PHP already has
 * sitting right below the input, inbox-v2.php lines 2966-2969), rather than
 * replacing the main list. Combining search with the FilterBar dropdowns is
 * the documented-but-deferred capability noted on route.ts / FilterBar.tsx.
 */

const DEBOUNCE_MS = 300;
export const SEARCH_RESULTS_LIMIT = 200;

export function SearchBox() {
  const searchParams = useSearchParams();
  const platform = searchParams?.get('platform') ?? 'line';

  const [query, setQuery] = useState('');
  const [results, setResults] = useState<ConversationRow[] | null>(null);
  const [loading, setLoading] = useState(false);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
    }

    const trimmed = query.trim();
    if (trimmed === '') {
      abortRef.current?.abort();
      setResults(null);
      setLoading(false);
      return undefined;
    }

    timerRef.current = setTimeout(() => {
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;
      setLoading(true);

      const params = new URLSearchParams({ search: trimmed, limit: String(SEARCH_RESULTS_LIMIT) });
      if (platform !== 'line') {
        params.set('platform', platform);
      }

      fetch(`/api/inbox/conversations?${params.toString()}`, { signal: controller.signal })
        .then((res) => res.json())
        .then((body: { success: boolean; data?: { conversations: ConversationRow[] } }) => {
          setResults(body.success && body.data ? body.data.conversations : []);
        })
        .catch((err: unknown) => {
          if (!(err instanceof DOMException) || err.name !== 'AbortError') {
            setResults([]);
          }
        })
        .finally(() => setLoading(false));
    }, DEBOUNCE_MS);

    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }
    };
  }, [query, platform]);

  return (
    <div className="p-2 border-b">
      <div className="relative">
        <input
          type="text"
          id="userSearch"
          placeholder="🔍 ค้นหาชื่อ, ข้อความ, แท็ก..."
          className="w-full px-3 py-2 bg-gray-100 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 outline-none pr-8"
          autoComplete="off"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        {results !== null ? (
          <div
            id="searchAutocomplete"
            className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-80 overflow-y-auto z-50"
          >
            {loading ? (
              <div className="p-3 text-xs text-gray-400 text-center">กำลังค้นหา...</div>
            ) : results.length === 0 ? (
              <div className="p-3 text-xs text-gray-400 text-center">ไม่พบผลลัพธ์</div>
            ) : (
              results.map((conversation) => <ConversationListItem key={conversation.id} conversation={conversation} />)
            )}
          </div>
        ) : null}
      </div>
    </div>
  );
}
