import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { toNumber } from './_lib/numeric';

/**
 * queries.ts — data assembly for /pharmacists, ported from
 * includes/pharmacy/pharmacists.php lines 101-131 (the "Get pharmacists" +
 * "Get schedules for each pharmacist" blocks). Written as raw `sql`
 * fragments (not Kysely's typed `.selectFrom()` builder), matching this
 * codebase's established house style — see templates/queries.ts's /
 * users/queries.ts's `getUsersListPage()` doc comment for the full
 * rationale (no CamelCasePlugin on the shared Kysely<TenantDB> instance).
 *
 * ORDER BY branch (lines 104-106):
 *
 *   $cols = $db->query("SHOW COLUMNS FROM pharmacists")->fetchAll(...);
 *   $hasSortOrder = in_array('sort_order', $cols);
 *   $orderBy = $hasSortOrder ? "ORDER BY sort_order ASC, name ASC" : "ORDER BY name ASC";
 *
 * VERIFIED (not guessed) against the committed tenant schema — both
 * database/migration_2026-05-25_tenant_template.sql's `CREATE TABLE
 * pharmacists` (no `sort_order` column anywhere in its column list) and
 * packages/db/src/generated/tenant-db.d.ts's `Pharmacists` interface
 * (codegen'd straight off that schema — also no `sort_order` field) agree:
 * this tenant template's `pharmacists` table has never had a `sort_order`
 * column, so `$hasSortOrder` is always false for every tenant provisioned
 * from the current template, and the query always takes the `ORDER BY name
 * ASC` branch. That branch is hard-coded below rather than reproducing
 * PHP's runtime `SHOW COLUMNS` probe (a per-request schema introspection
 * query with no equivalent typed-Kysely story) — if a future migration adds
 * `sort_order` back, this file needs a matching update, same as any other
 * schema-shape assumption baked into a raw `sql` fragment elsewhere in this
 * codebase.
 *
 * Appointment count subqueries (lines 108-111) are reproduced as correlated
 * subqueries in the SAME SELECT, not as separate queries, to match the PHP
 * source's own single-query shape exactly.
 *
 * Schedules/holidays (lines 117-130) are reproduced as PHP's own foreach
 * loop does — one `pharmacist_schedules` query and one `pharmacist_holidays`
 * query PER pharmacist (issued in parallel across pharmacists here via
 * Promise.all, not serially like PHP's foreach, since nothing depends on
 * ordering between pharmacists) — not batched into a single `WHERE
 * pharmacist_id IN (...)` query, because the holidays sub-query's `LIMIT 5`
 * is a per-pharmacist "top 5 upcoming" limit (line 123); collapsing that
 * into one IN()-query would need a window function to reproduce correctly,
 * which is unnecessary risk for a page this size.
 */

export interface PharmacistScheduleRow {
  id: number;
  pharmacistId: number;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
  isAvailable: number;
}

export interface PharmacistHolidayRow {
  id: number;
  pharmacistId: number;
  holidayDate: string;
  reason: string | null;
}

export interface PharmacistRow {
  id: number;
  title: string;
  name: string;
  specialty: string;
  licenseNo: string | null;
  hospital: string | null;
  bio: string | null;
  imageUrl: string | null;
  rating: number;
  reviewCount: number;
  consultationFee: number;
  consultationDuration: number;
  isAvailable: number;
  isActive: number;
  completedCount: number;
  upcomingCount: number;
  schedules: PharmacistScheduleRow[];
  holidays: PharmacistHolidayRow[];
}

interface RawPharmacistRow {
  id: number;
  title: string | null;
  name: string;
  specialty: string | null;
  licenseNo: string | null;
  hospital: string | null;
  bio: string | null;
  imageUrl: string | null;
  rating: string | number | null;
  reviewCount: number | string | null;
  consultationFee: string | number | null;
  consultationDuration: number | string | null;
  isAvailable: number | null;
  isActive: number | null;
  completedCount: number | string | null;
  upcomingCount: number | string | null;
}

interface RawScheduleRow {
  id: number;
  pharmacistId: number;
  dayOfWeek: number;
  startTime: string;
  endTime: string;
  isAvailable: number | null;
}

interface RawHolidayRow {
  id: number;
  pharmacistId: number;
  holidayDate: string;
  reason: string | null;
}

async function getSchedulesForPharmacist(db: Kysely<TenantDB>, pharmacistId: number): Promise<PharmacistScheduleRow[]> {
  const result = await sql<RawScheduleRow>`
    SELECT id, pharmacist_id AS pharmacistId, day_of_week AS dayOfWeek, start_time AS startTime,
      end_time AS endTime, is_available AS isAvailable
    FROM pharmacist_schedules
    WHERE pharmacist_id = ${pharmacistId}
  `.execute(db);
  return result.rows.map((row) => ({ ...row, isAvailable: toNumber(row.isAvailable) }));
}

/** Ported from line 123: `... AND holiday_date >= CURDATE() ORDER BY holiday_date LIMIT 5`. */
async function getUpcomingHolidaysForPharmacist(db: Kysely<TenantDB>, pharmacistId: number): Promise<PharmacistHolidayRow[]> {
  const result = await sql<RawHolidayRow>`
    SELECT id, pharmacist_id AS pharmacistId, holiday_date AS holidayDate, reason
    FROM pharmacist_holidays
    WHERE pharmacist_id = ${pharmacistId} AND holiday_date >= CURDATE()
    ORDER BY holiday_date
    LIMIT 5
  `.execute(db);
  return result.rows;
}

export async function getPharmacistsData(db: Kysely<TenantDB>): Promise<PharmacistRow[]> {
  const result = await sql<RawPharmacistRow>`
    SELECT
      p.id, p.title, p.name, p.specialty, p.license_no AS licenseNo, p.hospital, p.bio,
      p.image_url AS imageUrl, p.rating, p.review_count AS reviewCount,
      p.consultation_fee AS consultationFee, p.consultation_duration AS consultationDuration,
      p.is_available AS isAvailable, p.is_active AS isActive,
      (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = p.id AND status = 'completed') AS completedCount,
      (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = p.id AND status IN ('pending','confirmed') AND appointment_date >= CURDATE()) AS upcomingCount
    FROM pharmacists p
    ORDER BY name ASC
  `.execute(db);

  const pharmacists: PharmacistRow[] = result.rows.map((row) => ({
    id: row.id,
    title: row.title ?? '',
    name: row.name,
    specialty: row.specialty ?? '',
    licenseNo: row.licenseNo,
    hospital: row.hospital,
    bio: row.bio,
    imageUrl: row.imageUrl,
    rating: toNumber(row.rating),
    reviewCount: toNumber(row.reviewCount),
    consultationFee: toNumber(row.consultationFee),
    consultationDuration: toNumber(row.consultationDuration ?? 15),
    isAvailable: toNumber(row.isAvailable),
    isActive: toNumber(row.isActive),
    completedCount: toNumber(row.completedCount),
    upcomingCount: toNumber(row.upcomingCount),
    schedules: [],
    holidays: [],
  }));

  await Promise.all(
    pharmacists.map(async (p) => {
      const [schedules, holidays] = await Promise.all([
        getSchedulesForPharmacist(db, p.id),
        getUpcomingHolidaysForPharmacist(db, p.id),
      ]);
      p.schedules = schedules;
      p.holidays = holidays;
    })
  );

  return pharmacists;
}
