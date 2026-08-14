'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { updateOrderStatusAction } from '../actions';

/**
 * ConfirmOrderButton.tsx — Client Component island for shop/orders.php's
 * per-order "ยืนยัน" (confirm) button (lines 702-711): a `<form
 * method="POST">` whose `onsubmit` runs a `confirm()` dialog before
 * submitting `action=update_status&status=confirmed`. This is the ONLY
 * interactive element on this page's order-list render path — everything
 * else is a plain server-rendered `<a href>` link, so this is the only
 * component here that needs to be a Client Component (same "keep almost
 * everything a Server Component, isolate the one interactive bit" pattern
 * pharmacists/_components/PharmacistCard.tsx's own delete-confirm button
 * establishes).
 *
 * Confirm text is ported verbatim: `ยืนยันออเดอร์ #{order_number}?
 * จะส่งข้อความ LINE ถึงลูกค้า` (line 703, PHP's `htmlspecialchars(...,
 * ENT_QUOTES)` on the order number is a no-op for `window.confirm()`'s
 * plain-text dialog — no HTML entities to escape there).
 *
 * `router.refresh()` after the action call mirrors TemplatesClient.tsx's
 * own established pattern (belt-and-suspenders alongside actions.ts's own
 * `revalidatePath('/shop/orders')`, which already invalidates this route's
 * Server Component cache — the explicit refresh() just forces THIS
 * client-rendered tree to pick it up immediately without waiting for the
 * next natural navigation).
 */
export interface ConfirmOrderButtonProps {
  orderId: number;
  orderNumber: string;
}

export function ConfirmOrderButton({ orderId, orderNumber }: ConfirmOrderButtonProps) {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function handleClick() {
    if (!window.confirm(`ยืนยันออเดอร์ #${orderNumber}? จะส่งข้อความ LINE ถึงลูกค้า`)) {
      return;
    }
    setPending(true);
    try {
      await updateOrderStatusAction({ orderId, status: 'confirmed' });
      router.refresh();
    } finally {
      setPending(false);
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={pending}
      className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-60"
    >
      <i className="fas fa-check" aria-hidden="true" />
      ยืนยัน
    </button>
  );
}
