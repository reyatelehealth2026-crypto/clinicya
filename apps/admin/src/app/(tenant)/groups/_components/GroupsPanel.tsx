'use client';

import { useState } from 'react';
import { GroupFormModal, type GroupModalTarget } from './GroupFormModal';
import { deleteGroupAction, addMemberAction, removeMemberAction } from '../actions';
import type { GroupRow, UserOptionRow, GroupDetailRow, GroupMemberRow } from '../queries';

/**
 * GroupsPanel.tsx — client island for /groups, covering groups.php's whole
 * interactive body (lines 62-209): the groups list + "+" button (create
 * modal), the view-group detail panel (edit modal, delete-with-confirm,
 * add-member form, per-member remove button).
 *
 * `?view=<id>` navigation itself stays a plain server-rendered `<a
 * href="?view=...">` (see page.tsx) — only the two modals + the delete
 * confirm() need client state; the group list and the "which group is
 * currently selected" highlight are driven entirely by the URL, exactly
 * like groups.php's own `$_GET['view']`.
 */
export function GroupsPanel({
  groups,
  allUsers,
  viewGroupId,
  viewGroup,
  members,
}: {
  groups: GroupRow[];
  allUsers: UserOptionRow[];
  viewGroupId: number | null;
  viewGroup: GroupDetailRow | null;
  members: GroupMemberRow[];
}) {
  const [modalTarget, setModalTarget] = useState<GroupModalTarget | null>(null);

  function handleDeleteSubmit(e: React.FormEvent) {
    if (!window.confirm('คุณแน่ใจหรือไม่ที่จะลบ?')) {
      e.preventDefault();
    }
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {/* Groups List */}
      <div className="lg:col-span-1">
        <div className="bg-white rounded-xl shadow">
          <div className="p-4 border-b flex justify-between items-center">
            <h3 className="font-semibold">กลุ่มทั้งหมด</h3>
            <button
              type="button"
              onClick={() => setModalTarget('create')}
              className="px-3 py-1 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600"
              aria-label="สร้างกลุ่มใหม่"
            >
              +
            </button>
          </div>
          <div className="divide-y max-h-96 overflow-y-auto">
            {groups.length === 0 ? (
              <p className="p-4 text-gray-500 text-center">ยังไม่มีกลุ่ม</p>
            ) : (
              groups.map((group) => (
                <a
                  key={group.id}
                  href={`/groups?view=${group.id}`}
                  className={`flex items-center p-4 hover:bg-gray-50 ${viewGroupId === group.id ? 'bg-green-50' : ''}`}
                >
                  <div className="w-4 h-4 rounded-full mr-3" style={{ backgroundColor: group.color ?? '#3B82F6' }} />
                  <div className="flex-1">
                    <p className="font-medium">{group.name}</p>
                    <p className="text-xs text-gray-500">{group.memberCount} สมาชิก</p>
                  </div>
                </a>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Group Details */}
      <div className="lg:col-span-2">
        {viewGroup ? (
          <div className="bg-white rounded-xl shadow">
            <div className="p-4 border-b flex justify-between items-center">
              <div className="flex items-center">
                <div className="w-6 h-6 rounded-full mr-3" style={{ backgroundColor: viewGroup.color ?? '#3B82F6' }} />
                <div>
                  <h3 className="font-semibold">{viewGroup.name}</h3>
                  <p className="text-sm text-gray-500">{viewGroup.description}</p>
                </div>
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setModalTarget(viewGroup)}
                  className="px-3 py-1 border rounded-lg hover:bg-gray-50"
                  aria-label="แก้ไขกลุ่ม"
                >
                  แก้ไข
                </button>
                <form action={deleteGroupAction.bind(null, viewGroup.id)} onSubmit={handleDeleteSubmit}>
                  <button type="submit" className="px-3 py-1 border border-red-300 text-red-500 rounded-lg hover:bg-red-50">
                    ลบ
                  </button>
                </form>
              </div>
            </div>

            {/* Add Member */}
            <div className="p-4 border-b">
              <form action={addMemberAction.bind(null, viewGroup.id)} className="flex space-x-2">
                <select
                  name="user_id"
                  required
                  defaultValue=""
                  className="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                >
                  <option value="">-- เลือกผู้ใช้ --</option>
                  {allUsers.map((user) => (
                    <option key={user.id} value={user.id}>
                      {user.displayName}
                    </option>
                  ))}
                </select>
                <button type="submit" className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                  เพิ่มสมาชิก
                </button>
              </form>
            </div>

            {/* Members List */}
            <div className="divide-y max-h-80 overflow-y-auto">
              {members.length === 0 ? (
                <p className="p-4 text-gray-500 text-center">ยังไม่มีสมาชิกในกลุ่ม</p>
              ) : (
                members.map((member) => (
                  <div key={member.id} className="flex items-center p-4 hover:bg-gray-50">
                    <img
                      src={member.pictureUrl || 'https://via.placeholder.com/40'}
                      alt=""
                      className="w-10 h-10 rounded-full mr-3"
                    />
                    <div className="flex-1">
                      <p className="font-medium">{member.displayName}</p>
                    </div>
                    <form action={removeMemberAction.bind(null, viewGroup.id, member.id)}>
                      <button type="submit" className="text-red-500 hover:text-red-700" aria-label={`นำ ${member.displayName} ออกจากกลุ่ม`}>
                        ×
                      </button>
                    </form>
                  </div>
                ))
              )}
            </div>
          </div>
        ) : (
          <div className="bg-white rounded-xl shadow p-8 text-center text-gray-500">
            <p>เลือกกลุ่มเพื่อดูรายละเอียด</p>
          </div>
        )}
      </div>

      <GroupFormModal target={modalTarget} onClose={() => setModalTarget(null)} />
    </div>
  );
}
