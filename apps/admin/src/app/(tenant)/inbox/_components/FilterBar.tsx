'use client';

import { useCallback } from 'react';

/**
 * FilterBar — the 4 filter dropdowns (status/tag/chatStatus/assignee), port
 * of inbox-v2.php's filter `<select>`s (lines 3007-3054) and their shared
 * handler `applyFilters()` (lines 7962-8051).
 *
 * CRITICAL: this is a literal, client-side, DOM-attribute port —
 * `applyFilters()` never calls the server; it re-evaluates visibility of
 * every already-rendered `#userList .user-item` row purely from that row's
 * `data-*` attributes (set by ConversationListItem — do not change either
 * file without the other). No fetch/XHR of any kind happens here, matching
 * this batch's brief exactly ("replicate this client-side-filter model
 * exactly rather than inventing server-side filtering PHP doesn't have").
 *
 * SIMPLIFICATION vs PHP: `applyFilters()` has an "if there's an active
 * search, re-run the search instead" branch (inbox-v2.php lines 7975-7979)
 * that defers to `performHybridSearch()`. That coupling is not reproduced
 * here — SearchBox.tsx owns its own results rendering independently (see
 * its module doc). This component always runs the plain DOM-filter branch
 * (inbox-v2.php lines 7981-8043) regardless of whether a search is active,
 * which is the literal behavior this batch's brief asks to test ("zero
 * network calls fired") and the documented combined-search+filter reload
 * is explicitly deferred (see route.ts's module doc).
 */

export interface FilterBarTag {
  id: number;
  name: string;
}

export interface FilterBarAdmin {
  id: number;
  username: string;
  display_name: string | null;
}

export interface FilterBarProps {
  tags: FilterBarTag[];
  admins: FilterBarAdmin[];
  /** mirrors `$_SESSION['admin_id'] ?? 0` (inbox-v2.php line 7967). */
  currentAdminId: number;
}

/** Verbatim port of inbox-v2.php's $chatStatus <option> list (lines 3028-3034). */
const CHAT_STATUS_OPTIONS: readonly { value: string; label: string }[] = [
  { value: 'pending', label: '🔴 ต้องดำเนินการ' },
  { value: 'completed', label: '🟢 ดำเนินการแล้ว' },
  { value: 'shipping', label: '📦 รอจัดส่ง' },
  { value: 'tracking', label: '🚚 ติดตามสถานะ' },
  { value: 'billing', label: '💰 ติดตามบิล' },
];

const SELECT_CLASS = 'flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-teal-500 outline-none';

export function FilterBar({ tags, admins, currentAdminId }: FilterBarProps) {
  const applyFilters = useCallback(() => {
    const status = (document.getElementById('filterStatus') as HTMLSelectElement | null)?.value ?? '';
    const tag = (document.getElementById('filterTag') as HTMLSelectElement | null)?.value ?? '';
    const chatStatus = (document.getElementById('filterChatStatus') as HTMLSelectElement | null)?.value ?? '';
    const assignee = (document.getElementById('filterAssignee') as HTMLSelectElement | null)?.value ?? '';

    const items = document.querySelectorAll<HTMLElement>('#userList .user-item');
    items.forEach((item) => {
      let show = true;

      // Filter by read/assigned status.
      if (status === 'unread') {
        show = show && item.querySelector('.unread-badge') !== null;
      } else if (status === 'assigned') {
        show = show && item.dataset.assigned === '1';
      }

      // Filter by tag.
      if (tag) {
        const itemTags = (item.dataset.tags ?? '').split(',').filter((t) => t !== '');
        show = show && itemTags.includes(tag);
      }

      // Filter by chat status (work status) — exact match.
      if (chatStatus) {
        show = show && (item.dataset.chatStatus ?? '') === chatStatus;
      }

      // Filter by assignee.
      if (assignee) {
        const isAssigned = item.dataset.assigned === '1';
        const itemAssignees = item.dataset.assignees ? item.dataset.assignees.split(',').map((a) => a.trim()) : [];

        if (assignee === 'me') {
          show = show && itemAssignees.includes(String(currentAdminId));
        } else if (assignee === 'unassigned') {
          show = show && !isAssigned;
        } else {
          show = show && itemAssignees.includes(assignee);
        }
      }

      item.classList.toggle('filter-hidden', !show);
      item.style.display = show ? '' : 'none';
    });
  }, [currentAdminId]);

  return (
    <div className="p-2 border-b bg-gray-50 space-y-2">
      <div className="flex gap-2">
        <select id="filterStatus" onChange={applyFilters} className={SELECT_CLASS} defaultValue="">
          <option value="">ทุกสถานะ</option>
          <option value="unread">ยังไม่อ่าน</option>
          <option value="assigned">มอบหมายแล้ว</option>
        </select>

        <select id="filterTag" onChange={applyFilters} className={SELECT_CLASS} defaultValue="">
          <option value="">ทุกแท็ก</option>
          {tags.map((tag) => (
            <option key={tag.id} value={tag.id}>
              {tag.name}
            </option>
          ))}
        </select>
      </div>

      <div className="flex gap-2">
        <select id="filterChatStatus" onChange={applyFilters} className={SELECT_CLASS} defaultValue="">
          <option value="">ทุกสถานะงาน</option>
          {CHAT_STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      <div className="flex gap-2">
        <select id="filterAssignee" onChange={applyFilters} className={SELECT_CLASS} defaultValue="">
          <option value="">ทุกคน</option>
          <option value="me">มอบหมายให้ฉัน</option>
          <option value="unassigned">ยังไม่มอบหมาย</option>
          {admins.map((admin) => (
            <option key={admin.id} value={admin.id}>
              {admin.display_name || admin.username}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}
