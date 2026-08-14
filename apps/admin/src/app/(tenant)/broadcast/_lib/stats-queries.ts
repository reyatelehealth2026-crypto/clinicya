import { sql, type Kysely, type RawBuilder } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * stats-queries.ts — read-side port of includes/broadcast/stats.php (307
 * LOC): per-campaign click analytics + the campaign picker grid. The whole
 * PHP page is read-only (no `$_POST`/mutation handling anywhere in the
 * source) — every query below is a 1:1 SQL port, read the full file before
 * touching this one.
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/**
 * `date('d/m/Y H:i', strtotime(...))` under this codebase's forced
 * Asia/Bangkok server timezone (stats.php line 189, the campaign-picker
 * card's timestamp) — same `Intl.DateTimeFormat('en-GB', {timeZone:
 * 'Asia/Bangkok', ...})` approach as
 * ../../activity-logs/_lib/format.ts's `formatLogTimestamp()`. Deliberately
 * PLAIN GREGORIAN, not Buddhist-era — stats.php uses PHP's `date()`, not a
 * `toLocaleDateString('th-TH')` client-side call (contrast with
 * ../../crm-dashboard-advanced/_lib/format.ts's Buddhist-calendar
 * `'th-TH'` helpers, which port a GENUINELY different PHP source that runs
 * client-side JS instead of `date()`).
 */
export function formatPickerDate(value: Date | string): string {
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}

/** `date('d/m H:i', strtotime($click['clicked_at']))` (stats.php line 295, the recent-clicks feed). Same Bangkok/Gregorian reasoning as `formatPickerDate()`. */
export function formatClickDate(value: Date | string): string {
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')} ${get('hour')}:${get('minute')}`;
}

// ---------------------------------------------------------------------------
// Campaign lookup — stats.php lines 15-18
// ---------------------------------------------------------------------------

export interface StatsCampaign {
  id: number;
  name: string;
  status: string | null;
  /**
   * `$campaign['total_sent']` (stats.php lines 221, 235) references a column
   * that does NOT exist on `broadcast_campaigns` — see packages/db's
   * generated `tenant-db.d.ts`'s `BroadcastCampaigns` interface: it has
   * `sent_count`, not `total_sent`. `SELECT *` therefore never populates
   * `total_sent`, so PHP's `$campaign['total_sent'] ?? 0` ALWAYS evaluates
   * to 0 for every real campaign — the "ส่งแล้ว" stat tile and the CTR%
   * tile (`totalClicks / totalSent * 100`, stats.php line 236) are
   * dead-in-practice, permanently showing 0 / 0.0% no matter how many
   * recipients a campaign actually reached. CONFIRMED FINDING (same
   * category as ../../settings/_components/ConsentTab.tsx's module doc) —
   * ported byte-for-byte, not "fixed": hardcoded 0 here, not `sent_count`.
   */
  totalSent: number;
  sentCount: number;
}

interface RawCampaignRow {
  id: number;
  name: string;
  status: string | null;
  sent_count: number | null;
}

/** stats.php lines 16-18: `SELECT * FROM broadcast_campaigns WHERE id = ?`. Returns null when no row (the `!$campaign` branch → "ไม่พบ Broadcast"). */
export async function getCampaignById(db: Kysely<TenantDB>, campaignId: number): Promise<StatsCampaign | null> {
  const result = await sql<RawCampaignRow>`
    SELECT id, name, status, sent_count FROM broadcast_campaigns WHERE id = ${campaignId}
  `.execute(db);
  const row = result.rows[0];
  if (!row) return null;
  return {
    id: Number(row.id),
    name: row.name,
    status: row.status,
    sentCount: Number(row.sent_count ?? 0),
    totalSent: 0, // see StatsCampaign.totalSent doc — confirmed dead column.
  };
}

// ---------------------------------------------------------------------------
// Items-by-click-count — stats.php lines 21-24
// ---------------------------------------------------------------------------

export interface StatsItem {
  id: number;
  itemName: string;
  itemImage: string | null;
  clickCount: number;
}

/** stats.php lines 22-24: `SELECT * FROM broadcast_items WHERE broadcast_id = ? ORDER BY click_count DESC`. */
export async function getCampaignItems(db: Kysely<TenantDB>, campaignId: number): Promise<StatsItem[]> {
  const result = await sql<{ id: number; item_name: string; item_image: string | null; click_count: number | null }>`
    SELECT id, item_name, item_image, click_count FROM broadcast_items
     WHERE broadcast_id = ${campaignId}
     ORDER BY click_count DESC
  `.execute(db);
  return result.rows.map((r) => ({
    id: Number(r.id),
    itemName: r.item_name,
    itemImage: r.item_image,
    clickCount: Number(r.click_count ?? 0),
  }));
}

// ---------------------------------------------------------------------------
// Recent clicks feed — stats.php lines 27-39
// ---------------------------------------------------------------------------

export interface StatsClick {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
  itemName: string;
  clickedAt: Date;
  tagAssigned: boolean;
}

/**
 * stats.php lines 27-39: `try { SELECT bc.*, u.display_name, u.picture_url,
 * bi.item_name FROM broadcast_clicks bc JOIN users u ON bc.user_id = u.id
 * JOIN broadcast_items bi ON bc.item_id = bi.id WHERE bc.broadcast_id = ?
 * ORDER BY bc.clicked_at DESC LIMIT 50 } catch (Exception $e) {}` — swallowed
 * to `[]` on any failure (e.g. an orphaned `broadcast_clicks` row whose
 * `user_id`/`item_id` no longer resolves — the inner JOINs would just
 * exclude that row rather than throw, but the try/catch is preserved
 * verbatim for parity regardless).
 */
export async function getRecentClicks(db: Kysely<TenantDB>, campaignId: number): Promise<StatsClick[]> {
  try {
    const result = await sql<{
      id: number;
      clicked_at: Date;
      tag_assigned: number | null;
      display_name: string | null;
      picture_url: string | null;
      item_name: string;
    }>`
      SELECT bc.id, bc.clicked_at, bc.tag_assigned, u.display_name, u.picture_url, bi.item_name
        FROM broadcast_clicks bc
        JOIN users u ON bc.user_id = u.id
        JOIN broadcast_items bi ON bc.item_id = bi.id
       WHERE bc.broadcast_id = ${campaignId}
       ORDER BY bc.clicked_at DESC
       LIMIT 50
    `.execute(db);
    return result.rows.map((r) => ({
      id: Number(r.id),
      displayName: r.display_name,
      pictureUrl: r.picture_url,
      itemName: r.item_name,
      clickedAt: r.clicked_at,
      tagAssigned: Boolean(r.tag_assigned),
    }));
  } catch {
    return [];
  }
}

// ---------------------------------------------------------------------------
// Campaign picker (campaignId=0 view) — stats.php lines 43-75
// ---------------------------------------------------------------------------

export interface StatsPickerEntry {
  id: number;
  name: string;
  status: string | null;
  createdAt: Date;
  sentCount: number;
  kind: 'campaign' | 'quick';
}

interface RawPickerRow {
  id: number;
  name: string;
  status: string | null;
  created_at: Date;
  sent_count: number | null;
  kind: 'campaign' | 'quick';
}

function mapPickerRow(r: RawPickerRow): StatsPickerEntry {
  return {
    id: Number(r.id),
    name: r.name,
    status: r.status,
    createdAt: r.created_at,
    sentCount: Number(r.sent_count ?? 0),
    kind: r.kind,
  };
}

/**
 * stats.php lines 47-75. Primary query UNION ALLs `broadcast_campaigns`
 * (`kind: 'campaign'`) with the legacy `broadcasts` table (`kind: 'quick'`,
 * `title AS name`, `COALESCE(sent_at, created_at) AS created_at`) — the
 * "Phase A bridge" so quick-send broadcasts also surface in Stats — ORDERed
 * by `created_at DESC LIMIT 50`. On any exception, PHP falls back to a
 * `broadcast_campaigns`-only query (its own comment: "Fallback when
 * `broadcasts` table doesn't exist on a fresh install").
 *
 * CURRENTLY-UNREACHABLE-BUT-PORTED-FOR-PARITY: `broadcasts` is NOT absent on
 * the committed tenant template (packages/db's generated `tenant-db.d.ts`
 * has a `Broadcasts` interface — the table exists unconditionally), so the
 * primary UNION ALL always succeeds on this schema and the catch/fallback
 * branch below is dead code today. Ported anyway, exactly as PHP's own
 * defensive try/catch does, per this batch's brief.
 */
export async function getCampaignPicker(db: Kysely<TenantDB>, lineAccountId: number): Promise<StatsPickerEntry[]> {
  try {
    const result = await sql<RawPickerRow>`
      SELECT id, name, status, created_at, sent_count, 'campaign' AS kind
        FROM broadcast_campaigns
       WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
      UNION ALL
      SELECT id, title AS name, status, COALESCE(sent_at, created_at) AS created_at, sent_count, 'quick' AS kind
        FROM broadcasts
       WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
       ORDER BY created_at DESC
       LIMIT 50
    `.execute(db);
    return result.rows.map(mapPickerRow);
  } catch {
    try {
      const result = await sql<RawPickerRow>`
        SELECT id, name, status, created_at, sent_count, 'campaign' AS kind
          FROM broadcast_campaigns
         WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
         ORDER BY created_at DESC
         LIMIT 50
      `.execute(db);
      return result.rows.map(mapPickerRow);
    } catch {
      return [];
    }
  }
}

/**
 * stats.php lines 171-179:
 *   $href = $kind === 'quick' ? 'broadcast.php?tab=send' : 'broadcast.php?tab=stats&id=' . (int)$c['id'];
 * `kind: 'quick'` rows (the legacy `broadcasts` table) have no per-item
 * click tracking yet, so they route to the Send tab's history instead of
 * this tab's own carousel-detail view. The Next port serves this page at
 * `/broadcast` (not `broadcast.php`), so the href is query-string-relative
 * — `?tab=send` / `?tab=stats&id=N` — resolving against the current page
 * exactly like PHP's own same-directory relative link did against
 * `broadcast.php`.
 */
export function pickerEntryHref(entry: Pick<StatsPickerEntry, 'id' | 'kind'>): string {
  return entry.kind === 'quick' ? '?tab=send' : `?tab=stats&id=${entry.id}`;
}

// ---------------------------------------------------------------------------
// Overall stats tiles (campaignId=0 view) — stats.php lines 77-135
// ---------------------------------------------------------------------------

export interface StatsOverall {
  totalCampaigns: number;
  sentCampaigns: number;
  totalClicks: number;
  totalSentUsers: number;
}

const ZERO_OVERALL: StatsOverall = { totalCampaigns: 0, sentCampaigns: 0, totalClicks: 0, totalSentUsers: 0 };

async function countScalar(db: Kysely<TenantDB>, query: RawBuilder<{ c: number }>): Promise<number> {
  const result = await query.execute(db);
  return Number(result.rows[0]?.c ?? 0);
}

/**
 * stats.php lines 85-135. An outer try/catch (any failure here leaves the
 * ALL-ZERO defaults, since `$overallStats['total_campaigns']`/
 * `['sent_campaigns']` are only assigned AFTER the inner
 * `broadcast_campaigns` counts succeed) wrapping two independently-guarded
 * inner blocks:
 *
 *  1. `totalCampaigns`/`sentCampaigns` start from `broadcast_campaigns`-only
 *     counts, then an INNER try adds the legacy `broadcasts` table's counts
 *     PLUS computes `total_sent_users` as ONE combined SUM query spanning
 *     BOTH tables. If that inner query throws (comment: "`broadcasts` table
 *     absent — keep campaign-only totals"), `totalCampaigns`/`sentCampaigns`
 *     KEEP their campaign-only base value (computed before the inner try),
 *     but `total_sent_users` has NO campaign-only fallback at all — it
 *     stays at its 0 default. This asymmetry is reproduced exactly.
 *  2. `total_clicks`: try `broadcast_clicks.line_account_id` directly
 *     (populated once a tenant has run the "Phase A" backfill migration);
 *     on failure, fall back to a JOIN through `broadcast_campaigns`. Per
 *     this batch's brief: `broadcast_clicks.line_account_id` IS present
 *     unconditionally on the committed schema (packages/db's `tenant-db.d.ts`
 *     `BroadcastClicks` interface has it as a required column), so branch 1
 *     is expected to always succeed here too — branch 2 is ported for the
 *     same defensive-parity reason as `getCampaignPicker()`'s fallback.
 */
export async function getOverallStats(db: Kysely<TenantDB>, lineAccountId: number): Promise<StatsOverall> {
  const overall: StatsOverall = { ...ZERO_OVERALL };

  try {
    let totalCampaigns = await countScalar(
      db,
      sql<{ c: number }>`
        SELECT COUNT(*) AS c FROM broadcast_campaigns
         WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
      `
    );
    let sentCampaigns = await countScalar(
      db,
      sql<{ c: number }>`
        SELECT COUNT(*) AS c FROM broadcast_campaigns
         WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND status = 'sent'
      `
    );

    try {
      totalCampaigns += await countScalar(
        db,
        sql<{ c: number }>`
          SELECT COUNT(*) AS c FROM broadcasts
           WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
        `
      );
      sentCampaigns += await countScalar(
        db,
        sql<{ c: number }>`
          SELECT COUNT(*) AS c FROM broadcasts
           WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND status = 'sent'
        `
      );

      const sumResult = await sql<{ total_sent_users: number | null }>`
        SELECT
            COALESCE((SELECT SUM(sent_count) FROM broadcast_campaigns
                       WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND status = 'sent'), 0)
          + COALESCE((SELECT SUM(sent_count) FROM broadcasts
                       WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND status = 'sent'), 0)
            AS total_sent_users
      `.execute(db);
      overall.totalSentUsers = Number(sumResult.rows[0]?.total_sent_users ?? 0);
    } catch {
      // `broadcasts` table absent — keep campaign-only totals (total_sent_users stays 0, see module doc).
    }

    overall.totalCampaigns = totalCampaigns;
    overall.sentCampaigns = sentCampaigns;

    try {
      overall.totalClicks = await countScalar(
        db,
        sql<{ c: number }>`SELECT COUNT(*) AS c FROM broadcast_clicks WHERE line_account_id = ${lineAccountId}`
      );
    } catch {
      overall.totalClicks = await countScalar(
        db,
        sql<{ c: number }>`
          SELECT COUNT(*) AS c FROM broadcast_clicks bc
          JOIN broadcast_campaigns bcm ON bcm.id = bc.broadcast_id
          WHERE bcm.line_account_id = ${lineAccountId} OR bcm.line_account_id IS NULL
        `
      );
    }
  } catch {
    return { ...ZERO_OVERALL };
  }

  return overall;
}

// ---------------------------------------------------------------------------
// CTR — stats.php line 236: `$sent > 0 ? ($totalClicks / $sent * 100) : 0`
// ---------------------------------------------------------------------------

export function computeCtr(totalClicks: number, totalSent: number): number {
  return totalSent > 0 ? (totalClicks / totalSent) * 100 : 0;
}
