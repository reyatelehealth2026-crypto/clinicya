'use client';

import { formatDateTimeDMY, formatNumber } from '../../users/_lib/format';
import { leaveGroupAction } from '../actions';
import type { LineGroupRow as LineGroupRowData } from '../queries';

/**
 * LineGroupRow.tsx — one `<tr>` from line-groups.php's table (lines
 * 198-252), including its two row actions:
 *
 *   - "ดูรายละเอียด" -> /line-group-detail?id=<id> (plain link, unchanged)
 *   - "ส่งข้อความ" (send_message) -> OUT OF SCOPE this batch (see below)
 *   - "ออกจากกลุ่ม" (leave_group) -> leaveGroupAction, DB-only (see actions.ts)
 *
 * Both action buttons are PHP-gated on `$group['is_active']` (`<?php if
 * ($group['is_active']): ?>`), reproduced with the same `isActive` check.
 *
 * send_message OUT OF SCOPE (Phase 6 follow-up, not silently dropped): it is
 * 100% a LINE API push (`LineAPI::pushMessage()`) with ZERO database
 * side-effect of its own (confirmed by reading the full PHP handler, lines
 * 55-76) — there is no DB-only equivalent to fall back to the way
 * leave_group has one. Rendered here as a disabled button with a title
 * pointing at the still-live PHP page, per the brief's "present-but-disabled,
 * not silently dropped" option (same convention as user-detail's Odoo-card
 * stub, which links back to the .php file rather than omitting the feature).
 */
export function LineGroupRow({ group }: { group: LineGroupRowData }) {
  function handleLeaveSubmit(e: React.FormEvent) {
    if (
      !window.confirm(
        `ต้องการให้บอทออกจากกลุ่ม "${group.groupName ?? ''}" หรือไม่?\n\nหมายเหตุ: บอทจะไม่สามารถกลับเข้ากลุ่มได้เอง ต้องให้สมาชิกเชิญใหม่`
      )
    ) {
      e.preventDefault();
    }
  }

  return (
    <tr className="border-b hover:bg-gray-50">
      <td className="px-4 py-3">
        <div className="flex items-center">
          {group.pictureUrl ? (
            <img src={group.pictureUrl} alt="" className="w-10 h-10 rounded-full mr-3" />
          ) : (
            <div className="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center mr-3 text-gray-400">👥</div>
          )}
          <div>
            <div className="font-medium">{group.groupName || 'Unknown'}</div>
            <div className="text-xs text-gray-400">{group.groupType === 'room' ? 'Room' : 'Group'}</div>
          </div>
        </div>
      </td>
      <td className="px-4 py-3 text-center">
        <span className="text-sm text-gray-600">{group.botName ?? '-'}</span>
      </td>
      <td className="px-4 py-3 text-center">
        <span className="font-medium">{formatNumber(group.memberCount)}</span>
      </td>
      <td className="px-4 py-3 text-center">
        <span className="text-gray-600">{formatNumber(group.totalMessages)}</span>
      </td>
      <td className="px-4 py-3 text-center">
        {group.isActive ? (
          <span className="px-2 py-1 bg-green-100 text-green-600 rounded-full text-xs">Active</span>
        ) : (
          <span className="px-2 py-1 bg-red-100 text-red-600 rounded-full text-xs">Left</span>
        )}
      </td>
      <td className="px-4 py-3 text-center text-sm text-gray-500">{formatDateTimeDMY(group.joinedAt)}</td>
      <td className="px-4 py-3 text-center">
        <div className="flex justify-center items-center gap-2">
          <a href={`/line-group-detail?id=${group.id}`} className="text-blue-500 hover:text-blue-700" title="ดูรายละเอียด">
            👁
          </a>
          {group.isActive ? (
            <>
              <button
                type="button"
                disabled
                title="ส่งข้อความ — ฟีเจอร์นี้ยังอยู่บนระบบเดิม (/line-groups.php) จนกว่าจะย้าย LINE API มาที่ Phase 6"
                className="text-gray-300 cursor-not-allowed"
              >
                ✉
              </button>
              <form action={leaveGroupAction} onSubmit={handleLeaveSubmit}>
                <input type="hidden" name="group_id" value={group.id} />
                <button type="submit" className="text-red-500 hover:text-red-700" title="ออกจากกลุ่ม">
                  ⏏
                </button>
              </form>
            </>
          ) : null}
        </div>
      </td>
    </tr>
  );
}
