import type { ConversationRow } from '@/app/api/inbox/conversations/_lib/query';
import { formatThaiTime, getMessagePreview } from '@/app/api/inbox/conversations/_lib/preview';

/**
 * ConversationListItem — one row in the inbox left-pane conversation list.
 * Port of inbox-v2.php's per-row markup (lines 3097-3151, the SSR `<a>` for
 * each `$user`) AND ConversationLoader's `createConversationElement()` JS
 * template (lines 11715-11790, for AJAX-appended rows) — both PHP templates
 * are close enough (see this file's module-level notes below) that one
 * component safely stands in for both call sites: (tenant)/inbox/layout.tsx
 * (SSR, initial 200 rows) and _components/ConversationListLoader.tsx
 * (client, cursor-walk-appended + platform-switch-refetched rows).
 *
 * No 'use client' directive — this has no hooks/browser-only APIs, so it
 * works as-is from both a Server Component (layout.tsx) and a Client
 * Component (ConversationListLoader.tsx) tree.
 *
 * `data-*` attributes are NOT decorative — _components/FilterBar.tsx's
 * applyFilters()-equivalent client-side DOM filtering (inbox-v2.php lines
 * 7962-8051) reads them directly (`data-user-id`, `data-name`,
 * `data-chat-status`, `data-tags`, `data-assigned`, `data-assignees`), and
 * the `.user-item`/`.unread-badge` class names are also matched literally
 * by that same filtering code. Do not rename any of these without updating
 * FilterBar.tsx in lockstep.
 *
 * KNOWN SIMPLIFICATION vs PHP: the SSR-only single-assignee case
 * (inbox-v2.php line 3140) shows the assignee's actual display name,
 * because that SSR-only prefetch (lines 1075-1084) joins admin_users for
 * username/display_name. Our canonical `ConversationRow.assignees` (the
 * same shape the API/cursor-walk path returns — see _lib/query.ts's module
 * doc for why SSR and the API share one data shape) only carries raw
 * admin_id integers, matching what ConversationLoader's own JS template
 * already falls back to for AJAX-appended rows (lines 11779-11783: generic
 * "มอบหมายแล้ว" text, not a name, for exactly this reason — no name data on
 * the wire). This component always uses that generic-text form, so it is
 * IDENTICAL between the initial SSR paint and every later-loaded row
 * (PHP's initial paint and later-loaded rows visibly differ on this one
 * point; ours does not, which is strictly closer to how the AJAX path
 * already behaves in production).
 *
 * NOT ported (out of this batch's scope — see build report): the
 * `sla-warning` class (AnalyticsService::getConversationsExceedingSLA() is
 * a different service, not part of the read path this batch ports).
 */

const FALLBACK_AVATAR =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Ccircle cx='20' cy='20' r='20' fill='%23e5e7eb'/%3E%3Cpath d='M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z' fill='%239ca3af'/%3E%3C/svg%3E";

interface ChatStatusBadge {
  icon: string;
  color: string;
  bg: string;
}

/** Verbatim port of inbox-v2.php's $chatStatusBadges array (lines 3087-3093). */
const CHAT_STATUS_BADGES: Record<string, ChatStatusBadge> = {
  pending: { icon: '🔴', color: '#EF4444', bg: '#FEE2E2' },
  completed: { icon: '🟢', color: '#10B981', bg: '#D1FAE5' },
  shipping: { icon: '📦', color: '#F59E0B', bg: '#FEF3C7' },
  tracking: { icon: '🚚', color: '#3B82F6', bg: '#DBEAFE' },
  billing: { icon: '💰', color: '#8B5CF6', bg: '#EDE9FE' },
};

export interface ConversationListItemProps {
  conversation: ConversationRow;
  isActive?: boolean;
  /** Injectable clock for formatThaiTime — defaults to render time (matches PHP's time() at request time / the browser's Date.now() for AJAX rows). */
  now?: Date;
}

export function ConversationListItem({ conversation: c, isActive = false, now }: ConversationListItemProps) {
  const displayName = c.display_name ?? '';
  const preview = getMessagePreview(c.last_message_preview, c.last_message_type);
  const time = formatThaiTime(c.last_message_at, now);
  const tagIds = c.tags.map((t) => t.id).join(',');
  const assigneeIds = c.assignees.join(',');
  const isAssigned = c.assignees.length > 0;
  const badge = c.chat_status ? CHAT_STATUS_BADGES[c.chat_status] : undefined;

  return (
    <a
      href={`/inbox/${c.id}`}
      className={`user-item block p-3 border-b border-gray-50 cursor-pointer hover:bg-gray-50${isActive ? ' active' : ''}`}
      data-user-id={c.id}
      data-name={displayName.toLowerCase()}
      data-chat-status={c.chat_status ?? ''}
      data-tags={tagIds}
      data-assigned={isAssigned ? '1' : '0'}
      data-assignees={assigneeIds}
      tabIndex={0}
    >
      <div className="flex items-center gap-3">
        <div className="relative flex-shrink-0">
          {/* eslint-disable-next-line @next/next/no-img-element -- external/data-URI avatar, not a static asset next/image can optimize */}
          <img
            src={c.picture_url || FALLBACK_AVATAR}
            alt=""
            className="w-10 h-10 rounded-full object-cover border-2 border-white shadow"
            loading="lazy"
          />
          {c.unread_count > 0 ? (
            <div className="unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold">
              {c.unread_count > 9 ? '9+' : c.unread_count}
            </div>
          ) : null}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex justify-between items-baseline">
            <h3 className="text-sm font-semibold text-gray-800 truncate">{displayName}</h3>
            <span className="last-time text-[10px] text-gray-400">{time}</span>
          </div>
          <p className="last-msg text-xs text-gray-500 truncate">{preview}</p>
          <div className="flex items-center gap-1 mt-1 flex-wrap">
            {badge ? (
              <span
                className="chat-status-badge"
                style={{ background: badge.bg, color: badge.color, border: `1px solid ${badge.color}30` }}
              >
                {badge.icon}
              </span>
            ) : null}
            {isAssigned ? (
              <span className="text-[9px] px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full">
                {c.assignees.length === 1 ? 'มอบหมายแล้ว' : `${c.assignees.length} คน`}
              </span>
            ) : null}
          </div>
        </div>
      </div>
    </a>
  );
}
