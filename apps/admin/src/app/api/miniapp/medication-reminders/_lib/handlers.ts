import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * handlers.ts — the four action handlers ported from `api/medication-reminders.php` (338 lines, read in
 * full): `list` (GET, also the default action), `add` (POST), `delete` (POST), `mark_taken` (POST).
 * `update`/`history`/`adherence`/`from_order` are explicitly OUT of scope — zero line-mini-app callers.
 *
 * See `packages/contracts/src/medication-reminders.ts`'s doc comment for the full "subtle traps" list —
 * every one of them is replicated verbatim here, not "fixed":
 *   - `delete` has NO existence/ownership pre-check, always returns `{success:true}`.
 *   - `mark_taken` DOES verify ownership first (asymmetric with `delete`).
 *   - the top-level catch leaks the raw exception message (`error: e.message`), not sanitized
 *     (replicated in `route.ts`'s try/catch, not here).
 *
 * TABLE AUTO-CREATE (`CREATE TABLE IF NOT EXISTS medication_reminders` / `medication_taken_history`) is
 * NOT ported — both tables are confirmed present, unconditionally, in the committed tenant template.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

interface UserIdRow {
  id: number;
}

export async function resolveUserId(db: Kysely<TenantDB>, lineUserId: string): Promise<number | null> {
  if (!lineUserId) return null;
  const result = await sql<UserIdRow>`SELECT id FROM users WHERE line_user_id = ${lineUserId} LIMIT 1`.execute(db);
  return result.rows[0]?.id ?? null;
}

/**
 * mysql2 hydrates DATETIME/TIMESTAMP columns as JS `Date` objects (no `dateStrings: true` configured) —
 * formatted to a MySQL-shaped string here, same fix applied by every sibling `_lib` file this batch.
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function asDateString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

interface ReminderRow {
  id: number;
  user_id: number;
  line_user_id: string | null;
  line_account_id: number | null;
  medication_name: string;
  dosage: string | null;
  frequency: string | null;
  reminder_times: string | null;
  start_date: string | Date | null;
  end_date: string | Date | null;
  notes: string | null;
  is_active: number;
  product_id: number | null;
  order_id: number | null;
  created_at: string | Date;
  updated_at: string | Date;
  taken_count_7d: number | string;
  missed_count_7d: number | string;
}

/** `json_decode($reminder['reminder_times'], true) ?: []` — malformed/absent JSON silently becomes `[]`. */
function parseReminderTimes(raw: string | null): string[] {
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as string[]) : [];
  } catch {
    return [];
  }
}

function toReminderItem(row: ReminderRow): Record<string, unknown> {
  const taken = Number(row.taken_count_7d);
  const missed = Number(row.missed_count_7d);
  const total = taken + missed;
  const adherencePercent = total > 0 ? Math.round((taken / total) * 100) : 100;

  return {
    id: row.id,
    user_id: row.user_id,
    line_user_id: row.line_user_id,
    line_account_id: row.line_account_id,
    medication_name: row.medication_name,
    dosage: row.dosage,
    frequency: row.frequency,
    reminder_times: parseReminderTimes(row.reminder_times),
    start_date: asDateString(row.start_date),
    end_date: asDateString(row.end_date),
    notes: row.notes,
    is_active: row.is_active,
    product_id: row.product_id,
    order_id: row.order_id,
    created_at: asDateTimeString(row.created_at),
    updated_at: asDateTimeString(row.updated_at),
    taken_count_7d: taken,
    missed_count_7d: missed,
    adherence_percent: adherencePercent,
  };
}

// ---------------------------------------------------------------------------
// action=list (also the default when `action` is omitted)
// ---------------------------------------------------------------------------

export async function handleList(db: Kysely<TenantDB>, userId: number | null, lineUserId: string): Promise<ActionResult> {
  if (!userId && !lineUserId) {
    return { status: 200, body: { success: true, reminders: [] } };
  }

  const result = await sql<ReminderRow>`
    SELECT r.*,
           (SELECT COUNT(*) FROM medication_taken_history h
             WHERE h.reminder_id = r.id AND h.status = 'taken'
               AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as taken_count_7d,
           (SELECT COUNT(*) FROM medication_taken_history h
             WHERE h.reminder_id = r.id AND h.status = 'missed'
               AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as missed_count_7d
    FROM medication_reminders r
    WHERE r.user_id = ${userId} AND r.is_active = 1
    ORDER BY r.created_at DESC
  `.execute(db);

  return { status: 200, body: { success: true, reminders: result.rows.map(toReminderItem) } };
}

// ---------------------------------------------------------------------------
// action=add
// ---------------------------------------------------------------------------

export interface AddFields {
  medication_name?: unknown;
  dosage?: unknown;
  frequency?: unknown;
  reminder_times?: unknown;
  start_date?: unknown;
  end_date?: unknown;
  notes?: unknown;
  product_id?: unknown;
  order_id?: unknown;
}

function todayYmd(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export async function handleAdd(
  db: Kysely<TenantDB>,
  userId: number | null,
  lineUserId: string,
  lineAccountId: number | null,
  fields: AddFields
): Promise<ActionResult> {
  if (!userId) {
    return { status: 200, body: { success: false, error: 'User not found' } };
  }

  const medicationName = typeof fields.medication_name === 'string' ? fields.medication_name : '';
  if (medicationName === '') {
    return { status: 200, body: { success: false, error: 'กรุณาระบุชื่อยา' } };
  }

  const dosage = typeof fields.dosage === 'string' ? fields.dosage : '';
  const frequency = typeof fields.frequency === 'string' && fields.frequency !== '' ? fields.frequency : 'daily';
  const reminderTimes = Array.isArray(fields.reminder_times) && fields.reminder_times.length > 0 ? fields.reminder_times : ['08:00'];
  const startDate = typeof fields.start_date === 'string' && fields.start_date !== '' ? fields.start_date : todayYmd();
  const endDate = typeof fields.end_date === 'string' && fields.end_date !== '' ? fields.end_date : null;
  const notes = typeof fields.notes === 'string' ? fields.notes : '';
  const productId = fields.product_id === undefined || fields.product_id === null || fields.product_id === '' ? null : Number(fields.product_id);
  const orderId = fields.order_id === undefined || fields.order_id === null || fields.order_id === '' ? null : Number(fields.order_id);

  const result = await sql<never>`
    INSERT INTO medication_reminders
      (user_id, line_user_id, line_account_id, medication_name, dosage, frequency,
       reminder_times, start_date, end_date, notes, product_id, order_id)
    VALUES (${userId}, ${lineUserId}, ${lineAccountId}, ${medicationName}, ${dosage}, ${frequency},
            ${JSON.stringify(reminderTimes)}, ${startDate}, ${endDate}, ${notes}, ${productId}, ${orderId})
  `.execute(db);

  return {
    status: 200,
    body: { success: true, reminder_id: Number(result.insertId ?? 0), message: 'เพิ่มการเตือนทานยาแล้ว' },
  };
}

// ---------------------------------------------------------------------------
// action=delete — NO existence/ownership pre-check, always succeeds (see module doc comment)
// ---------------------------------------------------------------------------

export async function handleDelete(db: Kysely<TenantDB>, userId: number | null, reminderId: unknown): Promise<ActionResult> {
  if (!userId) {
    return { status: 200, body: { success: false, error: 'User not found' } };
  }

  await sql`UPDATE medication_reminders SET is_active = 0 WHERE id = ${reminderId} AND user_id = ${userId}`.execute(db);

  return { status: 200, body: { success: true, message: 'ลบการเตือนแล้ว' } };
}

// ---------------------------------------------------------------------------
// action=mark_taken — DOES verify ownership first (asymmetric with delete, see module doc comment)
// ---------------------------------------------------------------------------

interface ReminderExistsRow {
  id: number;
}

export async function handleMarkTaken(
  db: Kysely<TenantDB>,
  userId: number | null,
  reminderId: unknown,
  scheduledTime: unknown,
  status: unknown,
  notes: unknown
): Promise<ActionResult> {
  if (!userId) {
    return { status: 200, body: { success: false, error: 'User not found' } };
  }

  const owned = await sql<ReminderExistsRow>`SELECT id FROM medication_reminders WHERE id = ${reminderId} AND user_id = ${userId}`.execute(db);
  if (owned.rows.length === 0) {
    return { status: 200, body: { success: false, error: 'Reminder not found' } };
  }

  const statusValue = typeof status === 'string' && status !== '' ? status : 'taken';
  const scheduledTimeValue = typeof scheduledTime === 'string' && scheduledTime !== '' ? scheduledTime : null;
  const notesValue = typeof notes === 'string' ? notes : '';

  await sql`
    INSERT INTO medication_taken_history (reminder_id, user_id, scheduled_time, status, notes)
    VALUES (${reminderId}, ${userId}, ${scheduledTimeValue}, ${statusValue}, ${notesValue})
  `.execute(db);

  return {
    status: 200,
    body: { success: true, message: statusValue === 'taken' ? 'บันทึกการทานยาแล้ว' : 'บันทึกแล้ว' },
  };
}
