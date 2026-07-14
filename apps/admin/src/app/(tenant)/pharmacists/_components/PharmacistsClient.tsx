'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { EmptyState } from '@/components/EmptyState';
import { PharmacistCard } from './PharmacistCard';
import { PharmacistFormModal, type PharmacistModalTarget } from './PharmacistFormModal';
import { HolidayModal } from './HolidayModal';
import { deletePharmacistAction } from '../actions';
import type { PharmacistRow } from '../queries';

/**
 * PharmacistsClient.tsx — client island for /pharmacists, combining
 * includes/pharmacy/pharmacists.php's header/add-button (lines 136-144),
 * pharmacist grid + Edit/Holiday/Delete actions (lines 146-242), and the two
 * modals (lines 244-363) into one component — same
 * TemplateCard/TemplatesClient/TemplateFormModal precedent templates.php's
 * port established (see that page's TemplatesClient.tsx module doc), not
 * `apps/admin/src/components/DataTable.tsx` (the PHP source renders a card
 * grid here, not a tabular layout — DataTable is the wrong shape for this
 * page).
 *
 * FLAGGED GAP (intentional, not silently dropped): PHP's `pharmacy.php` hub
 * captures each POST handler's `$success`/`$error` string (all 5-6 of
 * `includes/pharmacy/pharmacists.php`'s own success messages —
 * 'อัพเดทข้อมูลเภสัชกรสำเร็จ!'/'เพิ่มเภสัชกรสำเร็จ!'/'ลบเภสัชกรสำเร็จ!'/
 * 'เพิ่มวันหยุดสำเร็จ!'/'ลบวันหยุดสำเร็จ!', plus the delete-guard `$error`)
 * and fires a real, non-blocking `fireToast()` banner for it after the
 * same-page re-render (`includes/components/toast.php`). This page's four
 * Server Actions do NOT reproduce that — `apps/admin/src/components/**` has
 * no shared Toast component yet (same "no shared Toast component yet, does
 * not invent one" gap `line-groups/actions.ts` and `templates/
 * TemplateCard.tsx` already flag for their own pages), and this batch's
 * brief/acceptance criteria say nothing about toast parity (unlike the
 * activity-log requirement, which is explicit — see actions.ts's module
 * doc). What DOES already happen here: every action's actual DATA effect is
 * immediately visible without a toast — `router.refresh()` re-renders the
 * card grid (save/delete) or the open HolidayModal's own list
 * (add/delete-holiday) with the fresh row set, so the admin sees the
 * result, just not a separate confirmation banner — and the one BLOCKING
 * case (delete's pending-appointment guard) already surfaces its exact Thai
 * message via `window.alert()` below (tested). A future pass wiring a real
 * shared Toast component (or the `?message=` searchParams convention
 * `line-groups.php`'s port uses) could close this gap without touching
 * this file's data logic.
 */
export function PharmacistsClient({ pharmacists }: { pharmacists: PharmacistRow[] }) {
  const router = useRouter();
  const [modalTarget, setModalTarget] = useState<PharmacistModalTarget | null>(null);
  const [holidayPharmacistId, setHolidayPharmacistId] = useState<number | null>(null);

  const holidayPharmacist = pharmacists.find((p) => p.id === holidayPharmacistId) ?? null;

  function handleSaved() {
    setModalTarget(null);
    router.refresh();
  }

  async function handleDelete(id: number) {
    const result = await deletePharmacistAction(id);
    if (!result.success) {
      window.alert(result.error);
      return;
    }
    router.refresh();
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <div>
          <p className="text-gray-500">จัดการข้อมูลเภสัชกรและตารางเวลา</p>
        </div>
        <button
          type="button"
          onClick={() => setModalTarget({ mode: 'create' })}
          className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm font-medium"
        >
          + เพิ่มเภสัชกร
        </button>
      </div>

      {pharmacists.length === 0 ? (
        // No `cta` here: EmptyState's cta is an <a href> (Server Component,
        // no onClick) — "เพิ่มเภสัชกรคนแรก" needs to open the client-side
        // create modal, which the header button above already provides.
        <EmptyState heading="ยังไม่มีเภสัชกร" />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {pharmacists.map((pharmacist) => (
            <PharmacistCard
              key={pharmacist.id}
              pharmacist={pharmacist}
              onEdit={() => setModalTarget({ mode: 'edit', pharmacist })}
              onHoliday={() => setHolidayPharmacistId(pharmacist.id)}
              onDelete={() => handleDelete(pharmacist.id)}
            />
          ))}
        </div>
      )}

      <PharmacistFormModal target={modalTarget} onClose={() => setModalTarget(null)} onSaved={handleSaved} />
      <HolidayModal pharmacist={holidayPharmacist} onClose={() => setHolidayPharmacistId(null)} onChanged={() => router.refresh()} />
    </div>
  );
}
