'use client';

import { useSearchParams } from 'next/navigation';

/**
 * ChannelSwitcher — LINE / Facebook Messenger / TikTok tabs, port of
 * inbox-v2.php's `#platformSwitcher` (lines 2973-2996), gated on
 * `users.platform` existing (see (tenant)/inbox/_lib/filterOptions.ts's
 * module doc for why that gate is always-true on this codebase's current
 * tenant template, same simplification (tenant)/users/queries.ts already
 * documents for its own column-existence probes).
 *
 * Plain `<a href>` (not next/link's `<Link>`) — this codebase's admin app
 * has no existing `next/link` usage anywhere (every nav, including
 * (tenant)/layout.tsx's own primary rail, uses plain anchors), and a soft
 * client-side transition buys nothing extra here: (tenant)/inbox/layout.tsx
 * is a Server Component and, per Next.js's App Router design, layouts
 * never receive `searchParams` (only `page.tsx` files do) — so switching
 * `?platform=` can NEVER re-run the SSR'd initial-200-row fetch in
 * layout.tsx, whether the navigation is a hard reload or a soft Link
 * transition. The actual data correction for a non-LINE platform happens
 * client-side in ConversationListLoader.tsx (it reads the URL on mount via
 * useSearchParams() and, if it differs from the platform it was
 * server-rendered for, discards its seeded rows and re-fetches page 1 for
 * the requested platform through this Route Handler) — that component's
 * own module doc has the full explanation. This component only needs
 * useSearchParams() to know which tab to highlight as active.
 */

export interface ChannelSwitcherProps {
  /** Conversation counts for the two non-LINE channels — LINE's own count isn't shown as a badge in PHP either. */
  counts: { facebook: number; tiktok: number };
}

type ChannelKey = 'line' | 'facebook' | 'tiktok';

const CHANNEL_TABS: readonly { key: ChannelKey; label: string; bg: string }[] = [
  { key: 'line', label: 'LINE', bg: '#06C755' },
  { key: 'facebook', label: 'Messenger', bg: '#0084FF' },
  { key: 'tiktok', label: 'TikTok', bg: '#111827' },
];

export function ChannelSwitcher({ counts }: ChannelSwitcherProps) {
  const searchParams = useSearchParams();
  const requested = searchParams?.get('platform');
  const active: ChannelKey = requested === 'facebook' || requested === 'tiktok' ? requested : 'line';

  return (
    <div className="p-2 border-b bg-white flex gap-1.5" id="platformSwitcher">
      {CHANNEL_TABS.map((tab) => {
        const isActive = active === tab.key;
        const count = tab.key === 'facebook' ? counts.facebook : tab.key === 'tiktok' ? counts.tiktok : 0;
        return (
          <a
            key={tab.key}
            href={tab.key === 'line' ? '/inbox' : `/inbox?platform=${tab.key}`}
            aria-current={isActive ? 'page' : undefined}
            className={`flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 rounded-lg text-xs font-semibold transition${
              isActive ? ' text-white shadow-sm' : ' bg-gray-100 text-gray-500 hover:bg-gray-200'
            }`}
            style={isActive ? { background: tab.bg } : undefined}
          >
            <span>{tab.label}</span>
            {tab.key !== 'line' && count > 0 ? (
              <span
                className={`inline-flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full text-[10px] font-bold${
                  isActive ? ' bg-white/30 text-white' : ' bg-blue-500 text-white'
                }`}
              >
                {count}
              </span>
            ) : null}
          </a>
        );
      })}
    </div>
  );
}
