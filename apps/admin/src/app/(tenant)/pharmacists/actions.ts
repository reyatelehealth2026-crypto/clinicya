'use server';

import { sql } from 'kysely';
import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from '../users/_lib/session';
import { logPharmacyActivity } from './_lib/activityLog';

/**
 * actions.ts — Server Actions for includes/pharmacy/pharmacists.php's four
 * POST actions (lines 11-99):
 *
 *   - savePharmacistAction   -> action==='save_pharmacist' (lines 14-64):
 *     upsert `pharmacists` by `id` presence, then unconditionally
 *     DELETE+re-INSERT `pharmacist_schedules` for the pharmacist whenever a
 *     `schedules` payload is present (a REPLACE, not an append — line 48's
 *     `DELETE FROM pharmacist_schedules WHERE pharmacist_id = ?` runs first,
 *     every time), then logs activity.
 *   - deletePharmacistAction -> action==='delete_pharmacist' (lines 66-83):
 *     blocked with the exact Thai guard message when a pending/confirmed
 *     future appointment exists, else DELETE + log activity.
 *   - addHolidayAction       -> action==='add_holiday' (lines 85-92).
 *   - deleteHolidayAction    -> action==='delete_holiday' (lines 94-97).
 *
 * All four raw values are written exactly as PHP receives/trims them from
 * $_POST — see each function's own inline comments for the exact
 * trim()/cast rules ported.
 *
 * revalidatePath('/pharmacists') stands in for PHP's own re-render-in-place
 * (this tab has no `header('Location: ...')` redirect anywhere — a normal
 * `<form method="POST">` submit just POSTs back to the same `pharmacy.php`
 * URL and PHP re-renders the tab with fresh data), matching templates.php's
 * / user-detail.php's own established `revalidatePath` vs. `redirect()`
 * choice (see templates/actions.ts's module doc): this page's own mutations
 * are all triggered from a modal opened over the grid, not a full-page
 * form navigating between two different views.
 *
 * Activity logging — see _lib/activityLog.ts's module doc for the full
 * writeup. Only save_pharmacist and delete_pharmacist's SUCCESS path call
 * `$activityLogger->logPharmacy(...)` in the actual PHP source;
 * add_holiday/delete_holiday do not. This batch's brief explicitly
 * requires activity logging on EVERY mutating action anyway (stated twice,
 * worded as an addition — "must ALSO insert" — not as a description of
 * existing PHP behavior), so addHolidayAction/deleteHolidayAction below
 * call logPharmacyActivity too: a deliberate, flagged extension beyond
 * strict 1:1 PHP parity for those two branches only.
 */

export interface PharmacistActionResult {
  success: boolean;
  /** Only set when success === false (delete_pharmacist's pending-appointment guard). */
  error?: string;
}

export interface SavePharmacistResult extends PharmacistActionResult {
  id: number;
}

export interface PharmacistScheduleFormEntry {
  start: string;
  end: string;
}

/** Keyed by day_of_week (0=Sunday ... 6=Saturday) — see _lib/format.ts's DAY_NAMES_TH doc. */
export type PharmacistSchedulesForm = Record<number, PharmacistScheduleFormEntry>;

export interface SavePharmacistInput {
  /** Falsy/absent -> INSERT (create); truthy -> UPDATE (edit) — mirrors PHP's `if ($id)` (line 28). */
  id?: number;
  /** `trim($_POST['title'] ?? 'ภก.')` (line 17) — defaults to 'ภก.' only when the field is entirely absent, NOT when it's an empty string (the "ไม่ระบุ" option is a valid, intentionally-empty value). */
  title?: string;
  name: string;
  specialty?: string;
  licenseNo?: string;
  hospital?: string;
  bio?: string;
  imageUrl?: string;
  consultationFee?: number | string;
  consultationDuration?: number | string;
  /** `isset($_POST['is_available'])` (line 25) — an unchecked checkbox never appears in $_POST at all. */
  isAvailable: boolean;
  isActive: boolean;
  /** `isset($_POST['schedules'])` (line 47) — when provided, ALWAYS triggers the delete-then-insert replace, even if every day is empty. */
  schedules?: PharmacistSchedulesForm;
}

export async function savePharmacistAction(input: SavePharmacistInput): Promise<SavePharmacistResult> {
  const { db, session } = await requireTenantPageContext();

  const title = (input.title ?? 'ภก.').trim();
  const name = input.name.trim();
  const specialty = (input.specialty ?? '').trim();
  const licenseNo = (input.licenseNo ?? '').trim();
  const hospital = (input.hospital ?? '').trim();
  const bio = (input.bio ?? '').trim();
  const imageUrl = (input.imageUrl ?? '').trim();
  const consultationFee = Number(input.consultationFee ?? 0);
  const consultationDuration = Math.trunc(Number(input.consultationDuration ?? 15));
  const isAvailable = input.isAvailable ? 1 : 0;
  const isActive = input.isActive ? 1 : 0;

  let id = input.id && input.id > 0 ? input.id : 0;

  if (id) {
    await sql`
      UPDATE pharmacists SET
        name = ${name}, title = ${title}, specialty = ${specialty}, license_no = ${licenseNo},
        hospital = ${hospital}, bio = ${bio}, image_url = ${imageUrl},
        consultation_fee = ${consultationFee}, consultation_duration = ${consultationDuration},
        is_available = ${isAvailable}, is_active = ${isActive}
      WHERE id = ${id}
    `.execute(db);
  } else {
    const insertResult = await sql`
      INSERT INTO pharmacists
        (name, title, specialty, license_no, hospital, bio, image_url, consultation_fee, consultation_duration, is_available, is_active)
      VALUES (${name}, ${title}, ${specialty}, ${licenseNo}, ${hospital}, ${bio}, ${imageUrl},
        ${consultationFee}, ${consultationDuration}, ${isAvailable}, ${isActive})
    `.execute(db);
    id = Number(insertResult.insertId ?? 0);
  }

  // Save schedules (lines 46-56) — REPLACE, not append: delete every existing
  // row for this pharmacist first, then re-insert only the days with both a
  // start and end time.
  if (input.schedules) {
    await sql`DELETE FROM pharmacist_schedules WHERE pharmacist_id = ${id}`.execute(db);
    for (const [dayKey, times] of Object.entries(input.schedules)) {
      if (times && times.start && times.end) {
        await sql`
          INSERT INTO pharmacist_schedules (pharmacist_id, day_of_week, start_time, end_time, is_available)
          VALUES (${id}, ${Number(dayKey)}, ${times.start}, ${times.end}, 1)
        `.execute(db);
      }
    }
  }

  // Log activity (lines 58-64). PHP QUIRK, preserved literally: by this
  // point `id` has ALWAYS been reassigned to a truthy value — either the
  // pre-existing id (update branch) or `lastInsertId()` (insert branch) — so
  // PHP's own `$id ? ACTION_UPDATE : ACTION_CREATE` ternary is, in practice,
  // always truthy. Every save_pharmacist request logs as
  // ACTION_UPDATE/'แก้ไขข้อมูลเภสัชกร', even when creating a brand-new
  // pharmacist. This is a latent bug in the PHP source, not a design choice
  // — reproduced here rather than "fixed", per this migration's
  // preserve-behavior-not-markup mandate (see _lib/activityLog.ts's module
  // doc for the sibling correction on which actions log at all).
  await logPharmacyActivity(db, session, {
    action: id ? 'update' : 'create',
    description: id ? 'แก้ไขข้อมูลเภสัชกร' : 'เพิ่มเภสัชกรใหม่',
    entityId: id,
    newValue: { name, license_no: licenseNo, specialty },
  });

  revalidatePath('/pharmacists');
  return { success: true, id };
}

/**
 * Ported from lines 66-83. The guard query (line 69) is byte-for-byte:
 * `SELECT COUNT(*) FROM appointments WHERE pharmacist_id = ? AND status IN
 * ('pending','confirmed') AND appointment_date >= CURDATE()`. On a positive
 * count, PHP sets `$error` and returns without deleting or logging anything
 * — reproduced here as an early return with no DB write.
 */
export async function deletePharmacistAction(id: number): Promise<PharmacistActionResult> {
  const { db, session } = await requireTenantPageContext();

  const guard = await sql<{ count: number | string }>`
    SELECT COUNT(*) AS count FROM appointments
    WHERE pharmacist_id = ${id} AND status IN ('pending','confirmed') AND appointment_date >= CURDATE()
  `.execute(db);
  const pendingCount = Number(guard.rows[0]?.count ?? 0);

  if (pendingCount > 0) {
    return { success: false, error: 'ไม่สามารถลบได้ เนื่องจากมีนัดหมายที่รอดำเนินการ' };
  }

  await sql`DELETE FROM pharmacists WHERE id = ${id}`.execute(db);

  await logPharmacyActivity(db, session, {
    action: 'delete',
    description: 'ลบเภสัชกร',
    entityId: id,
  });

  revalidatePath('/pharmacists');
  return { success: true };
}

export interface AddHolidayInput {
  pharmacistId: number;
  holidayDate: string;
  reason?: string;
}

/**
 * Ported from lines 85-92 — the INSERT itself is byte-for-byte identical to
 * PHP's. PHP's add_holiday branch never calls the activity logger; the
 * `logPharmacyActivity` call below is a deliberate extension beyond that —
 * see _lib/activityLog.ts's "DELIBERATE DECISION" doc section for why.
 */
export async function addHolidayAction(input: AddHolidayInput): Promise<PharmacistActionResult> {
  const { db, session } = await requireTenantPageContext();
  const reason = (input.reason ?? '').trim();

  await sql`
    INSERT INTO pharmacist_holidays (pharmacist_id, holiday_date, reason)
    VALUES (${input.pharmacistId}, ${input.holidayDate}, ${reason})
  `.execute(db);

  await logPharmacyActivity(db, session, {
    action: 'create',
    description: 'เพิ่มวันหยุดเภสัชกร',
    entityId: input.pharmacistId,
  });

  revalidatePath('/pharmacists');
  return { success: true };
}

/**
 * Ported from lines 94-97 — the DELETE itself is byte-for-byte identical to
 * PHP's (`DELETE FROM pharmacist_holidays WHERE id = ?`, scoped only by
 * holiday id). PHP's delete_holiday branch never calls the activity logger
 * and never even looks up which pharmacist a holiday belongs to (PHP's own
 * $_POST only ever carries `holiday_id`). This port's only caller
 * (HolidayModal, always rendered scoped to one open pharmacist) already has
 * that pharmacist's id in hand, so `pharmacistId` is accepted here as a
 * second argument purely to populate the new activity_logs row's
 * `entity_id` — see _lib/activityLog.ts's "DELIBERATE DECISION" doc section
 * for why this log call exists at all. It plays NO part in the DELETE's own
 * WHERE clause, so it cannot change which row gets deleted.
 */
export async function deleteHolidayAction(holidayId: number, pharmacistId: number): Promise<PharmacistActionResult> {
  const { db, session } = await requireTenantPageContext();
  await sql`DELETE FROM pharmacist_holidays WHERE id = ${holidayId}`.execute(db);

  await logPharmacyActivity(db, session, {
    action: 'delete',
    description: 'ลบวันหยุดเภสัชกร',
    entityId: pharmacistId,
  });

  revalidatePath('/pharmacists');
  return { success: true };
}
