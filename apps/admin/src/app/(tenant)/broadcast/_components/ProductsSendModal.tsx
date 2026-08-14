'use client';

import { useState } from 'react';
import { sendProductBroadcastAction } from '../_lib/products-actions';
import type { BroadcastProductTag } from '../_lib/products-queries';

/**
 * ProductsSendModal.tsx — 'use client' port of products.php's `#sendModal` (lines 418-473)
 * plus `openSendModal()`/`closeSendModal()`/`toggleTagSelect()` (the inline `<script>`
 * accompanying it). Renders BOTH the per-campaign "ส่ง" trigger button AND the modal itself —
 * `ProductsTab.tsx` (server) instantiates one of these per non-`sent` campaign card, passing
 * that campaign's id/name/available tags as props, so this component alone owns the
 * open/closed + all-vs-tags state that button needs.
 *
 * Unlike `SendComposeForm.tsx`'s form (a native `<form action={sendBroadcastAction}>`
 * binding), this one calls `sendProductBroadcastAction()` directly as an async function from
 * an `onSubmit` handler: `sendProductBroadcastAction` returns `{ error }` on a THROWN PHP
 * exception path (`ไม่พบ Campaign` / `ไม่มีสินค้าใน Campaign` / a send failure) instead of
 * redirecting, specifically so this modal can show that error inline without leaving the page
 * — the modal, already a client boundary, is exactly where capturing a returned value is easy
 * (see `../_lib/products-actions.ts`'s module doc for why `createBroadcastAction`/
 * `deleteCampaignAction`, which have no client-side caller, instead redirect with `?error=`).
 * On success the action calls `redirect()`, which Next.js's Server Action RPC honors as a
 * client-side navigation even when the action was invoked directly (not via a `<form
 * action={fn}>` binding) — no manual `router.push()` needed here.
 */
export interface ProductsSendModalProps {
  campaignId: number;
  campaignName: string;
  tags: BroadcastProductTag[];
}

export function ProductsSendModal({ campaignId, campaignName, tags }: ProductsSendModalProps) {
  const [open, setOpen] = useState(false);
  const [targetType, setTargetType] = useState<'all' | 'tags'>('all');
  const [selectedTagIds, setSelectedTagIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  function toggleTag(tagId: number) {
    setSelectedTagIds((prev) => (prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]));
  }

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setPending(true);
    const fd = new FormData();
    fd.set('action', 'send_broadcast');
    fd.set('campaign_id', String(campaignId));
    fd.set('target_type', targetType);
    for (const id of selectedTagIds) {
      fd.append('target_tags[]', String(id));
    }
    const result = await sendProductBroadcastAction(fd);
    if (result?.error) {
      setError(result.error);
      setPending(false);
    }
    // No `else`: a successful call redirect()s, which unmounts this component.
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="px-3 py-1.5 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600"
      >
        <i className="fas fa-paper-plane mr-1" aria-hidden="true" />
        ส่ง
      </button>

      {open ? (
        <div
          className="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
          onClick={(e) => e.target === e.currentTarget && setOpen(false)}
        >
          <div className="bg-white rounded-xl w-full max-w-md mx-4 shadow-xl">
            <form onSubmit={handleSubmit}>
              <div className="p-4 border-b">
                <h3 className="font-semibold">📤 ส่ง Broadcast</h3>
                <p className="text-sm text-gray-500">{campaignName}</p>
              </div>

              <div className="p-4 space-y-4">
                <div>
                  <label className="block text-sm font-medium mb-2">ส่งถึง</label>
                  <div className="space-y-2">
                    <label className="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                      <input
                        type="radio"
                        name="target_type"
                        value="all"
                        checked={targetType === 'all'}
                        onChange={() => setTargetType('all')}
                        className="mr-3"
                      />
                      <div>
                        <span className="font-medium">ผู้ติดตามทั้งหมด</span>
                        <p className="text-xs text-gray-500">ส่งถึงทุกคนที่ติดตาม LINE OA</p>
                      </div>
                    </label>
                    <label className="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                      <input
                        type="radio"
                        name="target_type"
                        value="tags"
                        checked={targetType === 'tags'}
                        onChange={() => setTargetType('tags')}
                        className="mr-3"
                      />
                      <div>
                        <span className="font-medium">เฉพาะ Tag ที่เลือก</span>
                        <p className="text-xs text-gray-500">ส่งเฉพาะลูกค้าที่มี Tag</p>
                      </div>
                    </label>
                  </div>
                </div>

                {targetType === 'tags' ? (
                  <div>
                    <label className="block text-sm font-medium mb-2">เลือก Tags</label>
                    <div className="max-h-40 overflow-y-auto border rounded-lg p-2 space-y-1">
                      {tags.map((tag) => (
                        <label key={tag.id} className="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                          <input
                            type="checkbox"
                            checked={selectedTagIds.includes(tag.id)}
                            onChange={() => toggleTag(tag.id)}
                            className="mr-2"
                          />
                          <span className="w-3 h-3 rounded-full mr-2" style={{ backgroundColor: tag.color ?? '#999' }} />
                          <span className="text-sm">{tag.name}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                ) : null}

                {error ? (
                  <p role="alert" className="text-sm text-red-600">
                    {error}
                  </p>
                ) : null}
              </div>

              <div className="p-4 border-t flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  ยกเลิก
                </button>
                <button
                  type="submit"
                  disabled={pending}
                  className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50"
                >
                  <i className="fas fa-paper-plane mr-2" aria-hidden="true" />
                  ส่ง Broadcast
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  );
}
