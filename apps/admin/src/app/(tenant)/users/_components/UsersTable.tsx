'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { DataTable, type DataTableColumn } from '@/components/DataTable';
import { EmptyState } from '@/components/EmptyState';
import { UserStatusBadge } from '@/components/Badge';
import { bulkAssignTagAction, bulkRemoveTagAction } from '../actions';
import { formatNumber } from '../_lib/format';
import type { UsersListRow, UserTagOption } from '../queries';
import { TagModal } from './TagModal';

/**
 * UsersTable — client port of users.php's LINE-tab `<table>` + checkbox
 * selection + bulk-actions bar + per-row tag-modal trigger (users.php lines
 * 1225-1331 for markup, 1377-1586 for the `<script>` this replaces).
 * Column set/order/content is a direct port of `$lineUserColumns`
 * (users.php lines 1260-1322): user (avatar+name+line id), tags, message
 * count, status, row actions (detail/chat/tags).
 */
export interface UsersTableProps {
  users: UsersListRow[];
  allTags: UserTagOption[];
}

export function UsersTable({ users, allTags }: UsersTableProps) {
  const router = useRouter();
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [bulkTagId, setBulkTagId] = useState<number | ''>('');
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkMessage, setBulkMessage] = useState<string | null>(null);
  const [tagModalUser, setTagModalUser] = useState<{ id: number; name: string } | null>(null);

  function openTagModal(userId: number, userName: string) {
    setTagModalUser({ id: userId, name: userName || 'Unknown' });
  }

  async function runBulk(kind: 'assign' | 'remove') {
    if (bulkTagId === '' || selectedIds.length === 0) {
      return;
    }
    setBulkBusy(true);
    setBulkMessage(null);
    try {
      const userIds = selectedIds.map((id) => Number(id));
      const action = kind === 'assign' ? bulkAssignTagAction : bulkRemoveTagAction;
      const result = await action(userIds, Number(bulkTagId));
      setBulkMessage(
        kind === 'assign' ? `เพิ่ม Tag สำเร็จ ${result.count} คน` : `ลบ Tag จาก ${result.count} คนสำเร็จ`
      );
      router.refresh();
    } catch (err) {
      setBulkMessage(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด');
    } finally {
      setBulkBusy(false);
    }
  }

  const columns: DataTableColumn<UsersListRow>[] = [
    {
      key: 'user',
      label: 'ผู้ใช้',
      render: (u) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <img
            src={u.pictureUrl || 'https://via.placeholder.com/40'}
            alt=""
            style={{ width: 40, height: 40, borderRadius: 9999, objectFit: 'cover' }}
          />
          <div>
            <p style={{ fontWeight: 500, margin: 0 }}>{u.displayName || 'Unknown'}</p>
            <p style={{ fontSize: 12, color: 'var(--color-dark-500)', margin: 0 }}>{(u.lineUserId ?? '').slice(0, 15)}...</p>
          </div>
        </div>
      ),
    },
    {
      key: 'tags',
      label: 'Tags',
      render: (u) => {
        if (!u.tags) {
          return <span style={{ color: 'var(--color-slate-400)', fontSize: 12 }}>-</span>;
        }
        return (
          <>
            {u.tags.split(', ').map((tagName) => (
              <span key={tagName} className="table-tag-chip">
                {tagName}
              </span>
            ))}
          </>
        );
      },
    },
    {
      key: 'messages',
      label: 'ข้อความ',
      align: 'center',
      render: (u) => <span style={{ fontWeight: 500 }}>{formatNumber(u.messageCount)}</span>,
    },
    {
      key: 'status',
      label: 'สถานะ',
      align: 'center',
      render: (u) => <UserStatusBadge isBlocked={!!u.isBlocked} />,
    },
    {
      key: 'actions',
      label: 'Actions',
      align: 'center',
      render: (u) => (
        <div style={{ display: 'inline-flex', gap: 4 }}>
          <a href={`/user-detail?id=${u.id}`} className="data-table-row-action" title="ดูรายละเอียด">
            รายละเอียด
          </a>
          <a href={`/messages?user=${u.id}`} className="data-table-row-action" title="ดูแชท">
            แชท
          </a>
          <button
            type="button"
            className="data-table-row-action"
            title="จัดการ Tags"
            onClick={() => openTagModal(u.id, u.displayName ?? '')}
          >
            Tags
          </button>
        </div>
      ),
    },
  ];

  return (
    <>
      {selectedIds.length > 0 ? (
        <div className="bulk-actions-bar">
          <span>
            เลือกแล้ว <b>{selectedIds.length}</b> คน
          </span>
          <select
            aria-label="เลือก Tag สำหรับกลุ่ม"
            value={bulkTagId}
            onChange={(e) => setBulkTagId(e.target.value === '' ? '' : Number(e.target.value))}
            disabled={bulkBusy}
          >
            <option value="">-- เลือก Tag --</option>
            {allTags.map((tag) => (
              <option key={tag.id} value={tag.id}>
                {tag.name}
              </option>
            ))}
          </select>
          <button type="button" onClick={() => runBulk('assign')} disabled={bulkBusy || bulkTagId === ''}>
            เพิ่ม Tag
          </button>
          <button type="button" onClick={() => runBulk('remove')} disabled={bulkBusy || bulkTagId === ''}>
            ลบ Tag
          </button>
          <button type="button" onClick={() => setSelectedIds([])} disabled={bulkBusy}>
            ยกเลิก
          </button>
          {bulkMessage ? <span role="status">{bulkMessage}</span> : null}
        </div>
      ) : null}

      <DataTable
        columns={columns}
        rows={users}
        selectable
        onSelectionChange={setSelectedIds}
        emptyContent={<EmptyState heading="ไม่พบผู้ใช้" />}
      />

      <TagModal
        open={tagModalUser !== null}
        userId={tagModalUser?.id ?? null}
        userName={tagModalUser?.name ?? ''}
        allTags={allTags}
        onClose={() => setTagModalUser(null)}
      />
    </>
  );
}
