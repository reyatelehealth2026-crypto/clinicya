'use client';

import { useEffect, useState } from 'react';
import { Modal } from '@/components/Modal';
import { TagChip } from '@/components/Badge';
import { assignTagAction, getUserTagsAction, removeTagAction, type UserTagRow } from '../actions';
import type { UserTagOption } from '../queries';

/**
 * TagModal — client port of users.php's #tagModal + its `openTagModal`/
 * `loadUserTags`/`assignTag`/`removeTag` inline `<script>` (users.php lines
 * 1352-1472). Opens without a full page reload, calls the assign/remove/
 * get-user-tags Server Actions directly (no `fetch('api/ajax_handler.php')`
 * needed — Next.js Server Actions are the RPC), and only ever re-fetches
 * this modal's own tag list on a successful mutation (matches the PHP
 * original's `loadUserTags(currentUserId)` call after each action — the
 * underlying page/table is NOT revalidated here, same as PHP).
 */
export interface TagModalProps {
  open: boolean;
  userId: number | null;
  userName: string;
  allTags: UserTagOption[];
  onClose: () => void;
}

export function TagModal({ open, userId, userName, allTags, onClose }: TagModalProps) {
  const [tags, setTags] = useState<UserTagRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedTagId, setSelectedTagId] = useState<number | ''>('');

  useEffect(() => {
    if (!open || userId === null) {
      return;
    }
    let cancelled = false;
    setLoading(true);
    setError(null);
    getUserTagsAction(userId)
      .then((result) => {
        if (!cancelled) {
          setTags(result);
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [open, userId]);

  async function reload() {
    if (userId === null) {
      return;
    }
    setLoading(true);
    try {
      setTags(await getUserTagsAction(userId));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด');
    } finally {
      setLoading(false);
    }
  }

  async function handleAssign() {
    if (userId === null || selectedTagId === '') {
      return;
    }
    try {
      await assignTagAction(userId, Number(selectedTagId));
      await reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด');
    }
  }

  async function handleRemove(tagId: number) {
    if (userId === null) {
      return;
    }
    try {
      await removeTagAction(userId, tagId);
      await reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด');
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="จัดการ Tags"
      size="sm"
      footer={
        <button type="button" onClick={onClose}>
          ปิด
        </button>
      }
    >
      <p>{userName}</p>

      <div style={{ marginBottom: 16 }}>
        <label style={{ display: 'block', fontSize: 14, fontWeight: 500, marginBottom: 8 }}>Tags ปัจจุบัน</label>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, minHeight: 32 }}>
          {loading ? (
            <span>กำลังโหลด...</span>
          ) : error ? (
            <span role="alert">{error}</span>
          ) : tags.length === 0 ? (
            <span>ยังไม่มี Tags</span>
          ) : (
            tags.map((tag) => (
              <TagChip key={tag.id} name={tag.name} color={tag.color} onRemove={() => handleRemove(tag.id)} />
            ))
          )}
        </div>
      </div>

      <div>
        <label style={{ display: 'block', fontSize: 14, fontWeight: 500, marginBottom: 8 }}>เพิ่ม Tag</label>
        <div style={{ display: 'flex', gap: 8 }}>
          <select
            aria-label="เลือก Tag"
            value={selectedTagId}
            onChange={(e) => setSelectedTagId(e.target.value === '' ? '' : Number(e.target.value))}
          >
            <option value="">-- เลือก Tag --</option>
            {allTags.map((tag) => (
              <option key={tag.id} value={tag.id}>
                {tag.name}
              </option>
            ))}
          </select>
          <button type="button" onClick={handleAssign} disabled={selectedTagId === ''}>
            เพิ่ม
          </button>
        </div>
      </div>
    </Modal>
  );
}
