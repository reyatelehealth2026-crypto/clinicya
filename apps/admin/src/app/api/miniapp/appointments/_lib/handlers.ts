import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getExistingAppointmentColumns } from './columns';
import { intval, phpEmpty, strOrEmpty } from './phpCompat';
import { addDaysToDateString, dayOfWeek, formatHm, nowInBangkok, pseudoUtcFromDateAndTime, todayInBangkok } from './bangkokTime';

/**
 * handlers.ts — the five action handlers ported from api/appointments.php (762 lines, read in full):
 * `pharmacists` (GET), `available_slots` (GET), `book` (POST), `my_appointments` (GET), `cancel`
 * (POST). Every branch mirrors api/appointments.php's control flow 1:1 — see the doc comments below
 * and packages/contracts/src/appointments.ts's own extensive doc comment for the verified schema facts
 * this file depends on (DYNAMIC-COLUMN VERIFICATION, the `duration` column bug, INSURANCES,
 * DEAD-CODE-PRESERVED). Do not "fix" any of the quirks flagged inline — they are real PHP behavior on
 * the committed tenant template, verified, not bugs introduced by this port.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/appointments.php's local `jsonResponse($success, $message, $data)` — `[...]` spread AFTER `message`, so a `message` key inside `data` overrides the outer one (see the SUBTLE TRAP doc comment in packages/contracts/src/appointments.ts). Always HTTP 200 (the PHP file never calls http_response_code()). */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

function asDateString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function addMinutes(d: Date, minutes: number): Date {
  const copy = new Date(d);
  copy.setUTCMinutes(copy.getUTCMinutes() + minutes);
  return copy;
}

/** `'APT' . date('ymdHis') . rand(100, 999)` — see packages/contracts/src/appointments.ts's `APPOINTMENT_ID_FORMAT_REGEX` doc comment. */
function generateAppointmentId(now: Date = nowInBangkok()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  const yy = pad(now.getUTCFullYear() % 100);
  const mm = pad(now.getUTCMonth() + 1);
  const dd = pad(now.getUTCDate());
  const hh = pad(now.getUTCHours());
  const ii = pad(now.getUTCMinutes());
  const ss = pad(now.getUTCSeconds());
  const rand = Math.floor(Math.random() * 900) + 100; // rand(100, 999)
  return `APT${yy}${mm}${dd}${hh}${ii}${ss}${rand}`;
}

// ---------------------------------------------------------------------------
// action=pharmacists
// ---------------------------------------------------------------------------

interface PharmacistRow {
  id: number;
  name: string;
  title: string | null;
  specialty: string | null;
  sub_specialty: string | null;
  hospital: string | null;
  license_no: string | null;
  bio: string | null;
  consulting_areas: string | null;
  work_experience: string | null;
  image_url: string | null;
  rating: string | number | null;
  review_count: number | null;
  consultation_fee: string | number | null;
  consultation_duration: number | null;
  is_available: number | null;
  is_active: number | null;
  line_account_id: number | null;
}

/**
 * `SHOW COLUMNS FROM pharmacists` is SKIPPED-AS-NO-OP here: every optional column
 * `handleGetPharmacists()` checks for is unconditionally present on the committed tenant template
 * (verified against packages/db/src/generated/tenant-db.d.ts's `Pharmacists` interface — see
 * packages/contracts/src/appointments.ts's DYNAMIC-COLUMN VERIFICATION doc comment for the full
 * column-by-column citation). Always selects the full column set + `WHERE is_active = 1` unconditionally.
 */
export async function handlePharmacists(db: Kysely<TenantDB>): Promise<ActionResult> {
  try {
    const result = await sql<PharmacistRow>`
      SELECT id, name, title, specialty, sub_specialty, hospital, license_no, bio, consulting_areas,
             work_experience, image_url, rating, review_count, consultation_fee, consultation_duration,
             is_available, is_active, line_account_id
      FROM pharmacists
      WHERE is_active = 1
      ORDER BY id DESC
    `.execute(db);

    const pharmacists: Record<string, unknown>[] = [];
    for (const p of result.rows) {
      let caseCount = 0;
      try {
        const countResult = await sql<{ cnt: number }>`
          SELECT COUNT(*) as cnt FROM appointments WHERE pharmacist_id = ${p.id} AND status = 'completed'
        `.execute(db);
        caseCount = Number(countResult.rows[0]?.cnt ?? 0);
      } catch {
        // appointments table may not exist — matches PHP's catch (Exception $e) {}.
      }

      pharmacists.push({
        id: p.id,
        name: p.name,
        // Defaulted when the DB value is NULL — PHP's `isset()` is false for a null column value too.
        title: p.title ?? '',
        specialty: p.specialty ?? 'เภสัชกร',
        is_available: p.is_available ?? 1,
        rating: p.rating ?? 5.0,
        review_count: p.review_count ?? 0,
        consultation_fee: p.consultation_fee ?? 0,
        consultation_duration: p.consultation_duration ?? 15,
        // Selected but never defaulted — raw passthrough.
        sub_specialty: p.sub_specialty,
        hospital: p.hospital,
        license_no: p.license_no,
        bio: p.bio,
        consulting_areas: p.consulting_areas,
        work_experience: p.work_experience,
        image_url: p.image_url,
        is_active: p.is_active,
        line_account_id: p.line_account_id,
        case_count: caseCount,
        // `pharmacist_insurances`/`insurances` tables CONFIRMED ABSENT from the committed tenant
        // template — always [] (mirrors PHP's swallowed try/catch around that JOIN).
        insurances: [] as unknown[],
      });
    }

    return ok(true, 'OK', { pharmacists });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return ok(false, `Error: ${message}`, { pharmacists: [] });
  }
}

// ---------------------------------------------------------------------------
// action=available_slots
// ---------------------------------------------------------------------------

const DEFAULT_SCHEDULE_BY_DAY: Record<number, { start_time: string; end_time: string } | null> = {
  0: null, // Sunday - closed
  1: { start_time: '09:00:00', end_time: '17:00:00' },
  2: { start_time: '09:00:00', end_time: '17:00:00' },
  3: { start_time: '09:00:00', end_time: '17:00:00' },
  4: { start_time: '09:00:00', end_time: '17:00:00' },
  5: { start_time: '09:00:00', end_time: '17:00:00' },
  6: { start_time: '09:00:00', end_time: '12:00:00' },
};

export async function handleAvailableSlots(db: Kysely<TenantDB>, query: Record<string, unknown>): Promise<ActionResult> {
  const pharmacistIdRaw = query.pharmacist_id;
  if (phpEmpty(pharmacistIdRaw)) {
    return ok(false, 'Missing pharmacist_id');
  }
  const pharmacistId = intval(pharmacistIdRaw);
  // `$_GET['date'] ?? date('Y-m-d')` — only a genuinely missing/non-string value falls back to today;
  // an explicit empty string is treated the same as missing here (a minor, deliberate simplification
  // vs PHP's `new DateTime('')`, which parses as "now" — not realistically reachable, line-mini-app
  // always sends a real `Y-m-d` string).
  const date = typeof query.date === 'string' && query.date !== '' ? query.date : todayInBangkok();

  const selectedDate = pseudoUtcFromDateAndTime(date, '00:00');
  const today = pseudoUtcFromDateAndTime(todayInBangkok(), '00:00');
  const maxDate = pseudoUtcFromDateAndTime(addDaysToDateString(todayInBangkok(), 30), '00:00');

  if (selectedDate < today) {
    return ok(false, 'ไม่สามารถจองวันที่ผ่านมาแล้ว');
  }
  if (selectedDate > maxDate) {
    return ok(false, 'สามารถจองล่วงหน้าได้ไม่เกิน 30 วัน');
  }

  // pharmacists.consultation_duration verified present — SKIPPED-AS-NO-OP (see handlePharmacists()'s
  // doc comment). Queried directly; PHP's try/catch around this query structurally cannot fire against
  // this template (table + column both present), so the "pharmacist not found" check below is always
  // reached, matching real behavior exactly.
  const pharmacistResult = await sql<{ id: number; consultation_duration: number | null }>`
    SELECT id, consultation_duration FROM pharmacists WHERE id = ${pharmacistId}
  `.execute(db);
  const pharmacist = pharmacistResult.rows[0];
  if (!pharmacist) {
    return ok(false, 'ไม่พบข้อมูลเภสัชกร');
  }
  const duration = pharmacist.consultation_duration ? Number(pharmacist.consultation_duration) : 15;

  const selectedDayOfWeek = dayOfWeek(selectedDate);

  try {
    const holidayResult = await sql<{ id: number }>`
      SELECT id FROM pharmacist_holidays WHERE pharmacist_id = ${pharmacistId} AND holiday_date = ${date}
    `.execute(db);
    if (holidayResult.rows.length > 0) {
      // SUBTLE TRAP — see packages/contracts/src/appointments.ts: `data.message` overrides the 'OK' argument.
      return ok(true, 'OK', { slots: [], message: 'วันหยุด' });
    }
  } catch {
    // Table doesn't exist — continue.
  }

  let schedule: { start_time: string; end_time: string } | null = null;
  try {
    const scheduleResult = await sql<{ start_time: string; end_time: string }>`
      SELECT start_time, end_time FROM pharmacist_schedules
      WHERE pharmacist_id = ${pharmacistId} AND day_of_week = ${selectedDayOfWeek} AND is_available = 1
    `.execute(db);
    schedule = scheduleResult.rows[0] ?? null;
  } catch {
    // Table doesn't exist.
  }

  if (!schedule) {
    schedule = DEFAULT_SCHEDULE_BY_DAY[selectedDayOfWeek] ?? null;
  }

  if (!schedule) {
    // SUBTLE TRAP — see packages/contracts/src/appointments.ts: `data.message` overrides the 'OK' argument.
    return ok(true, 'OK', { slots: [], message: 'ไม่มีตารางในวันนี้' });
  }

  // PRESERVED BUG: `duration` is NOT a real column on `appointments` (only `duration_minutes` is — see
  // packages/contracts/src/appointments.ts's DYNAMIC-COLUMN VERIFICATION doc comment). This query
  // throws "Unknown column 'duration'" against the committed template every time and is swallowed
  // below, exactly like the PHP original — meaning `bookedSlots` is ALWAYS `[]` in practice, so the
  // "already booked" flag below never actually fires. NOT fixed here; `handleBook()` still separately
  // prevents an exact-slot double-book at insert time via a different query that doesn't reference this
  // column, so this is a display-only bug, not a data-integrity one.
  let bookedSlots: Array<{ appointment_time: string; duration: number | null }> = [];
  try {
    const bookedResult = await sql<{ appointment_time: string; duration: number | null }>`
      SELECT appointment_time, duration FROM appointments
      WHERE pharmacist_id = ${pharmacistId} AND appointment_date = ${date} AND status NOT IN ('cancelled', 'no_show')
    `.execute(db);
    bookedSlots = bookedResult.rows;
  } catch {
    // duration column doesn't exist on the committed template -> always throws -> always [].
  }

  const slots: Array<{ time: string; available: boolean }> = [];
  let cursor = pseudoUtcFromDateAndTime(date, schedule.start_time.slice(0, 5));
  const endTime = pseudoUtcFromDateAndTime(date, schedule.end_time.slice(0, 5));
  const now = nowInBangkok();
  const isToday = date === todayInBangkok();

  while (cursor < endTime) {
    const slotEnd = addMinutes(cursor, duration);
    if (slotEnd > endTime) break;

    if (isToday && cursor <= now) {
      cursor = addMinutes(cursor, duration);
      continue;
    }

    let isBooked = false;
    for (const booked of bookedSlots) {
      const bookedStart = pseudoUtcFromDateAndTime(date, booked.appointment_time.slice(0, 5));
      const bookedDuration = booked.duration ?? duration;
      const bookedEnd = addMinutes(bookedStart, bookedDuration);
      if (cursor < bookedEnd && slotEnd > bookedStart) {
        isBooked = true;
        break;
      }
    }

    slots.push({ time: formatHm(cursor), available: !isBooked });
    cursor = addMinutes(cursor, duration);
  }

  return ok(true, 'OK', { slots, duration });
}

// ---------------------------------------------------------------------------
// action=book
// ---------------------------------------------------------------------------

/** PHP's `$data['line_account_id'] ?? 1` — only a genuinely missing/null value triggers the default (unlike `empty()`, `0`/`''` are kept as-is). */
function lineAccountIdOrDefault(value: unknown, fallback: number): number {
  return value === undefined || value === null ? fallback : intval(value);
}

export async function handleBook(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(data.line_user_id);
  const lineAccountId = lineAccountIdOrDefault(data.line_account_id, 1);
  const pharmacistIdRaw = data.pharmacist_id;
  const date = strOrEmpty(data.date);
  const time = strOrEmpty(data.time);
  const symptoms = typeof data.symptoms === 'string' ? data.symptoms : '';
  const type = typeof data.type === 'string' ? data.type : 'scheduled';

  if (phpEmpty(lineUserId)) {
    return ok(false, 'กรุณาเข้าสู่ระบบ');
  }
  if (phpEmpty(pharmacistIdRaw) || phpEmpty(date) || phpEmpty(time)) {
    return ok(false, 'ข้อมูลไม่ครบถ้วน');
  }
  const pharmacistId = intval(pharmacistIdRaw);

  const userResult = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  const user = userResult.rows[0];
  if (!user) {
    return ok(false, 'ไม่พบข้อมูลผู้ใช้');
  }

  // pharmacists.consultation_fee/consultation_duration/is_active verified present — SKIPPED-AS-NO-OP.
  let duration = 15;
  let consultationFee = 0;
  const pharmacistResult = await sql<{ id: number; consultation_fee: string | number | null; consultation_duration: number | null }>`
    SELECT id, consultation_fee, consultation_duration FROM pharmacists WHERE id = ${pharmacistId} AND is_active = 1
  `.execute(db);
  const pharmacist = pharmacistResult.rows[0];
  if (!pharmacist) {
    return ok(false, 'ไม่พบข้อมูลเภสัชกร');
  }
  if (pharmacist.consultation_duration) duration = Number(pharmacist.consultation_duration);
  if (pharmacist.consultation_fee) consultationFee = Number(pharmacist.consultation_fee);

  const endTimeStr = (() => {
    const pad = (n: number) => String(n).padStart(2, '0');
    const end = addMinutes(pseudoUtcFromDateAndTime(date, time.slice(0, 5)), duration);
    return `${pad(end.getUTCHours())}:${pad(end.getUTCMinutes())}:${pad(end.getUTCSeconds())}`;
  })();

  const slotTakenResult = await sql<{ id: number }>`
    SELECT id FROM appointments
    WHERE pharmacist_id = ${pharmacistId} AND appointment_date = ${date} AND appointment_time = ${time}
      AND status NOT IN ('cancelled', 'no_show')
  `.execute(db);
  if (slotTakenResult.rows.length > 0) {
    return ok(false, 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น');
  }

  const userConflictResult = await sql<{ id: number }>`
    SELECT id FROM appointments
    WHERE user_id = ${user.id} AND appointment_date = ${date} AND appointment_time = ${time}
      AND status NOT IN ('cancelled', 'no_show')
  `.execute(db);
  if (userConflictResult.rows.length > 0) {
    return ok(false, 'คุณมีนัดหมายในเวลานี้แล้ว');
  }

  const appointmentId = generateAppointmentId();

  try {
    // GENUINE runtime introspection (not skipped) — see columns.ts's doc comment. On the committed
    // template only `line_account_id` is present of the seven optional columns below, so the effective
    // INSERT is `(user_id, pharmacist_id, appointment_date, appointment_time, status, line_account_id)`
    // — `appointment_id`/`end_time`/`duration`/`type`/`symptoms`/`consultation_fee` are silently
    // dropped, exactly like the real PHP.
    const existingCols = await getExistingAppointmentColumns(db);
    const insertCols: string[] = ['user_id', 'pharmacist_id', 'appointment_date', 'appointment_time', 'status'];
    const insertVals: unknown[] = [user.id, pharmacistId, date, time, 'confirmed'];

    if (existingCols.has('line_account_id')) {
      insertCols.push('line_account_id');
      insertVals.push(lineAccountId);
    }
    if (existingCols.has('appointment_id')) {
      insertCols.push('appointment_id');
      insertVals.push(appointmentId);
    }
    if (existingCols.has('end_time')) {
      insertCols.push('end_time');
      insertVals.push(endTimeStr);
    }
    if (existingCols.has('duration')) {
      insertCols.push('duration');
      insertVals.push(duration);
    }
    if (existingCols.has('type')) {
      insertCols.push('type');
      insertVals.push(type);
    }
    if (existingCols.has('symptoms')) {
      insertCols.push('symptoms');
      insertVals.push(symptoms);
    }
    if (existingCols.has('consultation_fee')) {
      insertCols.push('consultation_fee');
      insertVals.push(consultationFee);
    }

    const columnsSql = sql.join(insertCols.map((c) => sql.ref(c)));
    const valuesSql = sql.join(insertVals);
    const insertResult = await sql`INSERT INTO appointments (${columnsSql}) VALUES (${valuesSql})`.execute(db);
    const newId = Number(insertResult.insertId ?? 0);

    return ok(true, 'จองนัดหมายสำเร็จ!', {
      appointment_id: appointmentId,
      id: newId,
      date,
      time,
      duration,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return ok(false, `เกิดข้อผิดพลาด: ${message}`);
  }
}

// ---------------------------------------------------------------------------
// action=my_appointments
// ---------------------------------------------------------------------------

interface AppointmentJoinRow {
  id: number;
  line_account_id: number | null;
  user_id: number;
  pharmacist_id: number | null;
  appointment_type: string | null;
  appointment_date: string | Date;
  appointment_time: string;
  duration_minutes: number | null;
  status: string | null;
  notes: string | null;
  reminder_sent: number | null;
  created_at: string | Date;
  updated_at: string | Date;
  reminder_10min_sent: number | null;
  reminder_now_sent: number | null;
  cancelled_reason: string | null;
  pharmacist_name: string;
  pharmacist_title: string | null;
  specialty: string | null;
  pharmacist_image: string | null;
}

function normalizeAppointmentRow(row: AppointmentJoinRow): Record<string, unknown> {
  return {
    ...row,
    appointment_date: asDateString(row.appointment_date) ?? row.appointment_date,
    created_at: asDateTimeString(row.created_at) ?? row.created_at,
    updated_at: asDateTimeString(row.updated_at) ?? row.updated_at,
  };
}

const TERMINAL_STATUSES = new Set(['completed', 'cancelled', 'no_show']);

export async function handleMyAppointments(db: Kysely<TenantDB>, query: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(query.line_user_id);
  const status = strOrEmpty(query.status);
  const limit = Math.min(query.limit === undefined ? 20 : intval(query.limit), 50);

  if (phpEmpty(lineUserId)) {
    return ok(false, 'Missing line_user_id');
  }

  const userResult = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  const user = userResult.rows[0];
  if (!user) {
    return ok(false, 'ไม่พบข้อมูลผู้ใช้');
  }

  const rows = status
    ? (
        await sql<AppointmentJoinRow>`
          SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title, p.specialty, p.image_url as pharmacist_image
          FROM appointments a JOIN pharmacists p ON a.pharmacist_id = p.id
          WHERE a.user_id = ${user.id} AND a.status = ${status}
          ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT ${limit}
        `.execute(db)
      ).rows
    : (
        await sql<AppointmentJoinRow>`
          SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title, p.specialty, p.image_url as pharmacist_image
          FROM appointments a JOIN pharmacists p ON a.pharmacist_id = p.id
          WHERE a.user_id = ${user.id}
          ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT ${limit}
        `.execute(db)
      ).rows;

  const normalized = rows.map(normalizeAppointmentRow);
  const today = todayInBangkok();
  const upcoming: Record<string, unknown>[] = [];
  const past: Record<string, unknown>[] = [];

  for (const apt of normalized) {
    const aptDate = String(apt.appointment_date);
    const aptStatus = String(apt.status ?? '');
    if (aptDate >= today && !TERMINAL_STATUSES.has(aptStatus)) {
      upcoming.push(apt);
    } else {
      past.push(apt);
    }
  }

  return ok(true, 'OK', { upcoming, past, all: normalized });
}

// ---------------------------------------------------------------------------
// action=cancel
// ---------------------------------------------------------------------------

interface AppointmentFullRow {
  id: number;
  user_id: number;
  status: string | null;
  appointment_date: string | Date;
  appointment_time: string;
  [key: string]: unknown;
}

export async function handleCancel(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const appointmentIdRaw = data.appointment_id;
  const lineUserId = strOrEmpty(data.line_user_id);
  const reason = strOrEmpty(data.reason);

  if (phpEmpty(appointmentIdRaw) || phpEmpty(lineUserId)) {
    return ok(false, 'ข้อมูลไม่ครบถ้วน');
  }

  const userResult = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  const user = userResult.rows[0];
  if (!user) {
    return ok(false, 'ไม่พบข้อมูลผู้ใช้');
  }

  // GENUINE runtime introspection (not skipped) — see columns.ts's doc comment. `appointment_id` is
  // absent on the committed template, so `hasAptIdCol` is always false there and the lookup always
  // takes the `WHERE id = ?` branch using `intval($appointmentId)` — which in real traffic IS the
  // numeric row id (line-mini-app's AppointmentsClient.tsx passes `apt.id`, never the unpersisted
  // generated string).
  const existingCols = await getExistingAppointmentColumns(db);
  const hasAptIdCol = existingCols.has('appointment_id');

  const appointmentResult = hasAptIdCol
    ? await sql<AppointmentFullRow>`
        SELECT * FROM appointments WHERE (appointment_id = ${strOrEmpty(appointmentIdRaw)} OR id = ${intval(appointmentIdRaw)}) AND user_id = ${user.id}
      `.execute(db)
    : await sql<AppointmentFullRow>`
        SELECT * FROM appointments WHERE id = ${intval(appointmentIdRaw)} AND user_id = ${user.id}
      `.execute(db);
  const appointment = appointmentResult.rows[0];
  if (!appointment) {
    return ok(false, 'ไม่พบนัดหมายนี้');
  }

  if (appointment.status === 'completed' || appointment.status === 'cancelled') {
    return ok(false, 'ไม่สามารถยกเลิกนัดหมายนี้ได้');
  }

  const appointmentDateStr = asDateString(appointment.appointment_date) ?? '';
  const appointmentDateTime = pseudoUtcFromDateAndTime(appointmentDateStr, appointment.appointment_time.slice(0, 5));
  const now = nowInBangkok();

  // DEAD-CODE-PRESERVED: PHP computes `$hoursUntil` here but never actually compares it against a
  // threshold anywhere — see packages/contracts/src/appointments.ts's DEAD CODE PRESERVED doc comment.
  // The ONLY real gate is the one below (appointment already in the past).
  if (appointmentDateTime <= now) {
    return ok(false, 'ไม่สามารถยกเลิกนัดหมายที่ผ่านไปแล้ว');
  }

  const cancelledByExists = existingCols.has('cancelled_by');
  const cancelledReasonExists = existingCols.has('cancelled_reason');

  const setFragments = [sql`status = 'cancelled'`, sql`updated_at = NOW()`];
  if (cancelledByExists) {
    setFragments.push(sql`cancelled_by = ${'user'}`);
  }
  if (cancelledReasonExists) {
    setFragments.push(sql`cancelled_reason = ${reason}`);
  }

  await sql`UPDATE appointments SET ${sql.join(setFragments)} WHERE id = ${appointment.id}`.execute(db);

  return ok(true, 'ยกเลิกนัดหมายสำเร็จ');
}
