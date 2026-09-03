'use client';

import { Modal } from '@/components/Modal';
import { createGroupAction, updateGroupAction } from '../actions';
import type { GroupDetailRow } from '../queries';

/**
 * GroupFormModal.tsx — client port of groups.php's #modal + openModal()/
 * editGroup() inline script (lines 156-209). One shared Modal component
 * for both "สร้างกลุ่มใหม่" (create) and "แก้ไขกลุ่ม" (edit) — the PHP
 * source likewise reuses a single `<form method="POST">` with hidden
 * `action`/`id` inputs swapped by JS.
 *
 * The `<form action={...}>` is bound straight to the real Server Action
 * (createGroupAction / updateGroupAction.bind(null, id)) — both end in a
 * real `redirect('/groups')` (see actions.ts), matching groups.php's own
 * `header('Location: groups.php'); exit;` full-navigation behavior. No
 * onSubmit/fetch plumbing needed here; the modal closes naturally because
 * the redirect remounts this component with `target` reset to null by the
 * parent (GroupsPanel).
 */
export type GroupModalTarget = 'create' | GroupDetailRow;

export function GroupFormModal({ target, onClose }: { target: GroupModalTarget | null; onClose: () => void }) {
  if (!target) {
    return null;
  }

  const isEdit = target !== 'create';
  const initial = isEdit ? target : null;
  const action = isEdit ? updateGroupAction.bind(null, target.id) : createGroupAction;

  return (
    <Modal open onClose={onClose} title={isEdit ? 'แก้ไขกลุ่ม' : 'สร้างกลุ่มใหม่'} size="sm">
      <form action={action} className="space-y-4">
        <div>
          <label htmlFor="grp-name" className="block text-sm font-medium mb-1">
            ชื่อกลุ่ม
          </label>
          <input
            id="grp-name"
            name="name"
            type="text"
            required
            defaultValue={initial?.name ?? ''}
            className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
          />
        </div>
        <div>
          <label htmlFor="grp-description" className="block text-sm font-medium mb-1">
            คำอธิบาย
          </label>
          <textarea
            id="grp-description"
            name="description"
            rows={3}
            defaultValue={initial?.description ?? ''}
            className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
          />
        </div>
        <div>
          <label htmlFor="grp-color" className="block text-sm font-medium mb-1">
            สี
          </label>
          <input
            id="grp-color"
            name="color"
            type="color"
            defaultValue={initial?.color ?? '#3B82F6'}
            className="w-full h-10 rounded-lg cursor-pointer"
          />
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg hover:bg-gray-50">
            ยกเลิก
          </button>
          <button type="submit" className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            บันทึก
          </button>
        </div>
      </form>
    </Modal>
  );
}
