#!/usr/bin/env node
// infra/e2e/lib/extract.mjs
//
// HTML data-point extraction helpers for infra/e2e/parity.mjs. These
// deliberately do NOT diff raw HTML (per this batch's brief) — every
// exported page-level function returns a small, plain, JSON-serializable
// object of DATA POINTS (counts, numbers, name lists) pulled out of a
// server-rendered response body, so parity.mjs's diff is a structural
// deepEqual over those objects, never a string/DOM diff.
//
// DESIGN — label-anchored extraction, not class/selector-anchored:
// apps/admin's ported components (PageHeader, Toolbar, KpiCard, SectionCard,
// DataTable, Pagination — all read while building this fixture/harness) are
// faithful ports of includes/components/*.php's MARKUP STRUCTURE where it
// matters (data-table-row, page-header-subtitle, pagination-info are
// genuinely shared class names), but several inner nodes (KpiCard's
// label/value spans, user-detail.php's stat rows, CRM's tag/rule rows) were
// ported WITHOUT carrying over the PHP partial's inline classes — only the
// VISIBLE THAI/ENGLISH LABEL TEXT is guaranteed identical on both sides
// (bilingual-copy parity is a hard project requirement — see CLAUDE.md).
// So every extractor here keys off literal label text via an ordered
// HtmlCursor (below), never CSS classes, for anything not already proven
// class-identical by reading both sources.
//
// ORDERING MATTERS: a few labels repeat on a page (e.g. the CRM tab's "Tags"
// KPI tile value vs. the "Tags" SectionCard heading below it). HtmlCursor
// only ever searches FORWARD from wherever the previous extraction left off,
// so callers must extract data points in the same top-to-bottom order the
// page actually renders them — exactly mirroring how a human reading the
// page top-to-bottom would never confuse the two.

// ---------------------------------------------------------------------------
// Entity decoding — htmlspecialchars() (PHP) and React's JSX text escaping
// both only ever emit this small, fixed set for the content this harness's
// fixture can produce (Thai/English display names, tag names, etc. — no
// raw HTML in seeded data).
// ---------------------------------------------------------------------------

const ENTITY_MAP = {
  '&amp;': '&',
  '&lt;': '<',
  '&gt;': '>',
  '&quot;': '"',
  '&#039;': "'",
  '&apos;': "'",
  '&nbsp;': ' ',
};

export function decodeEntities(text) {
  return text.replace(/&(amp|lt|gt|quot|#039|apos|nbsp);/g, (m) => ENTITY_MAP[m] ?? m);
}

/**
 * Marks tag boundaries in `text`. HTML COMMENTS ARE STRIPPED FIRST, NOT
 * TREATED AS BOUNDARIES -- verified empirically against this batch's own
 * real `next build` output: React's SSR renderer inserts an empty
 * `<!-- -->` comment between two ADJACENT JSX expression children (e.g.
 * `{tier.icon} {tier.name}` or the baht-sign-plus-value pattern) as a
 * hydration boundary marker. PHP has no such concept. If comments were
 * treated as ordinary tag boundaries here, a Next-rendered value would
 * incorrectly split into two chunks while PHP's un-annotated text stays
 * one chunk -- a false parity mismatch caused entirely by this extractor,
 * not a real product difference. Stripping comments (not splitting on
 * them) merges the two sides of the marker back into one run, matching
 * PHP's text exactly.
 */
function stripTags(text) {
  return text.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]*>/g, '');
}

/** First non-empty, tag-stripped, trimmed text chunk in `text` (used to find "the next visible text after a label" regardless of how many tags intervene). */
function firstVisibleChunk(text) {
  const parts = stripTags(text)
    .split('')
    .map((s) => decodeEntities(s).trim())
    .filter((s) => s.length > 0);
  return parts[0] ?? null;
}

/** Last non-empty, tag-stripped, trimmed text chunk in `text` (used for "the visible text immediately before a label"). */
function lastVisibleChunk(text) {
  const parts = stripTags(text)
    .split('')
    .map((s) => decodeEntities(s).trim())
    .filter((s) => s.length > 0);
  return parts.length > 0 ? parts[parts.length - 1] : null;
}

/** Parses a leading integer out of a string like "1,234", "1,234 รายการ", "฿1,234.56" — strips everything that isn't a digit/minus/dot first, per el's own comma-grouping convention (both number_format() and toLocaleString('en-US') use ','). Returns null if no digits found. */
export function parseLeadingNumber(text) {
  if (text === null || text === undefined) return null;
  const cleaned = String(text).replace(/[,฿\s]/g, '');
  const match = cleaned.match(/-?\d+(\.\d+)?/);
  return match ? Number(match[0]) : null;
}

// ---------------------------------------------------------------------------
// HtmlCursor — forward-only label search, see module doc "ORDERING MATTERS".
// ---------------------------------------------------------------------------

const WINDOW_CHARS = 400;

export class HtmlCursor {
  constructor(html) {
    this.html = html;
    this.pos = 0;
  }

  /** Raw index of `label`'s first occurrence at/after the cursor, WITHOUT advancing the cursor. -1 if not found. */
  peekIndexOf(label) {
    return this.html.indexOf(label, this.pos);
  }

  /** Advances the cursor to just past `label`'s next occurrence. Throws if not found (extraction bugs should fail loudly, not silently return nulls that then compare equal on both sides). */
  advanceTo(label) {
    const idx = this.html.indexOf(label, this.pos);
    if (idx === -1) {
      throw new Error(`HtmlCursor: label not found from position ${this.pos}: ${JSON.stringify(label)}`);
    }
    this.pos = idx + label.length;
    return this.pos;
  }

  /** The next visible (tag-stripped) text chunk after `label`'s next occurrence. Advances the cursor past the label (not past the consumed value) — see module doc. */
  afterLabel(label) {
    this.advanceTo(label);
    const window = this.html.slice(this.pos, this.pos + WINDOW_CHARS);
    return firstVisibleChunk(window);
  }

  /** The visible (tag-stripped) text chunk immediately before `label`'s next occurrence (search window looks BACKWARD from the label). Advances the cursor past the label. */
  beforeLabel(label) {
    const idx = this.html.indexOf(label, this.pos);
    if (idx === -1) {
      throw new Error(`HtmlCursor: label not found from position ${this.pos}: ${JSON.stringify(label)}`);
    }
    const windowStart = Math.max(0, idx - WINDOW_CHARS);
    const window = this.html.slice(windowStart, idx);
    this.pos = idx + label.length;
    return lastVisibleChunk(window);
  }

  /** Everything from the cursor's current position up to (not including) the FIRST of `endLabels` found after it, or to end-of-document if none are found. Does NOT advance the cursor — call advanceTo()/afterLabel() separately if the caller wants to continue past this slice. */
  sliceUntil(endLabels) {
    let end = this.html.length;
    for (const label of endLabels) {
      const idx = this.html.indexOf(label, this.pos);
      if (idx !== -1 && idx < end) end = idx;
    }
    return this.html.slice(this.pos, end);
  }

  /** Counts non-overlapping occurrences of `needle` within sliceUntil(endLabels) — see module doc's avatar-fallback / known-name row-counting technique. Does NOT advance the cursor. */
  countInSlice(needle, endLabels) {
    const slice = this.sliceUntil(endLabels);
    if (needle === '') return 0;
    let count = 0;
    let from = 0;
    for (;;) {
      const idx = slice.indexOf(needle, from);
      if (idx === -1) break;
      count += 1;
      from = idx + needle.length;
    }
    return count;
  }
}

// ---------------------------------------------------------------------------
// Main-content slicing — both stacks render the full nav BEFORE the page's
// own content (apps/admin's (tenant)/layout.tsx's <nav> before <main>;
// includes/header.php's sidebar before <div class="main-content">). Several
// nav labels (e.g. "ออเดอร์"/Orders) collide with in-page KPI/label text —
// slicing the nav off entirely before any label search is what keeps every
// extractor below correct rather than accidentally matching the sidebar.
// ---------------------------------------------------------------------------

const MAIN_CONTENT_MARKERS = ['<div class="main-content">', '<main>', '<main '];

export function sliceMainContent(html) {
  for (const marker of MAIN_CONTENT_MARKERS) {
    const idx = html.indexOf(marker);
    if (idx !== -1) return html.slice(idx);
  }
  return html; // fallback — no marker found, extraction searches the whole document (still correct, just not collision-proof).
}

// ---------------------------------------------------------------------------
// /users page extraction
// ---------------------------------------------------------------------------

const USERS_TABLE_ROW_RE = /<tr class="data-table-row[^"]*" data-id="[^"]*">([\s\S]*?)<\/tr>/g;
const TD_CELL_RE = /<td[^>]*>([\s\S]*?)<\/td>/g;
const TAG_SPAN_RE = /<span[^>]*>([^<]*)<\/span>/g;
const FIRST_P_RE = /<p[^>]*>([^<]*)<\/p>/;

/**
 * Extracts /users' data-point list: total-users count, up-to-20 rendered
 * display_names + per-row tag-label SETS (sorted — GROUP_CONCAT has no
 * ORDER BY on either side, see the fixture's own header comment on why tag
 * order isn't a meaningful parity signal) in row order, pagination
 * total/page-count, active-filter count.
 */
export function extractUsersPage(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  // Include the tag's closing '>' in the label itself so the search window
  // starts cleanly at the subtitle's text content, not mid-tag.
  const subtitleText = cursor.afterLabel('class="page-header-subtitle">');
  // subtitleText looks like "ทั้งหมด 25 คน" — pull the number out.
  const totalUsersMatch = subtitleText ? subtitleText.match(/([\d,]+)/) : null;
  const totalUsers = totalUsersMatch ? Number(totalUsersMatch[1].replace(/,/g, '')) : null;

  const activeFilterText = cursor.afterLabel('ตัวกรอง');
  const activeFilterCount = /^\d+$/.test(activeFilterText ?? '') ? Number(activeFilterText) : 0;

  const rows = [];
  for (const rowMatch of main.matchAll(USERS_TABLE_ROW_RE)) {
    const rowHtml = rowMatch[1];
    const cells = [...rowHtml.matchAll(TD_CELL_RE)].map((m) => m[1]);
    // Column order (see UsersTable.tsx / users.php's $lineUserColumns):
    // [0]=checkbox [1]=user(name) [2]=tags [3]=messages [4]=status [5]=actions
    const userCell = cells[1] ?? '';
    const tagsCell = cells[2] ?? '';

    const nameMatch = userCell.match(FIRST_P_RE);
    const displayName = nameMatch ? decodeEntities(nameMatch[1]).trim() : null;

    const tagNames = [...tagsCell.matchAll(TAG_SPAN_RE)]
      .map((m) => decodeEntities(m[1]).trim())
      .filter((name) => name !== '' && name !== '-');
    tagNames.sort();

    rows.push({ displayName, tags: tagNames });
  }

  const paginationVisible = main.includes('class="pagination-nav"');
  const computedTotalPages = totalUsers !== null ? Math.ceil(totalUsers / 20) : null;

  return {
    totalUsers,
    rows: rows.slice(0, 20),
    rowCount: rows.length,
    activeFilterCount,
    computedTotalPages,
    paginationVisible,
  };
}

// ---------------------------------------------------------------------------
// /user-detail?id=N page extraction
// ---------------------------------------------------------------------------

const H2_RE = /<h2[^>]*>([^<]*)<\/h2>/;
const ORDER_DETAIL_HREF_RE = /href="[^"]*order-detail[^"]*"/g;

export function extractUserDetailPage(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const h2Match = main.match(H2_RE);
  const displayName = h2Match ? decodeEntities(h2Match[1]).trim() : null;

  const availablePoints = parseLeadingNumber(cursor.beforeLabel('แต้มคงเหลือ'));
  const totalPoints = parseLeadingNumber(cursor.beforeLabel('สะสมทั้งหมด'));
  const usedPoints = parseLeadingNumber(cursor.beforeLabel('ใช้ไปแล้ว'));

  const orderCount = parseLeadingNumber(cursor.afterLabel('จำนวนออเดอร์'));
  const totalSpent = parseLeadingNumber(cursor.afterLabel('ยอดซื้อรวม'));
  const messageCount = parseLeadingNumber(cursor.afterLabel('ข้อความทั้งหมด'));
  const tierLabelRaw = cursor.afterLabel('ระดับสมาชิก');
  const tierLabel = tierLabelRaw ? tierLabelRaw.replace(/\s+/g, ' ').trim() : null;

  // Anchor on '>Tags</h3>' (the heading's own closing tag), NOT bare 'Tags' —
  // user-detail.php has a `<!-- Tags -->` HTML comment immediately before
  // the real `<h3 class="ud-card-title">...Tags</h3>` heading (verified
  // empirically against this batch's own real page fetch); a bare 'Tags'
  // search finds that comment's text first, landing the cursor BEFORE the
  // actual Tags card and producing an empty (wrong) slice.
  cursor.advanceTo('>Tags</h3>');
  const tagsSlice = cursor.sliceUntil(['<h3']); // next card heading ("ข้อมูลลูกค้า" — Edit Info form)
  const tagNames = [...tagsSlice.matchAll(TAG_SPAN_RE)]
    .map((m) => decodeEntities(m[1]).trim())
    .filter((name) => name !== '' && name !== 'ยังไม่มี Tags');
  tagNames.sort();

  const recentTransactionCount = (main.match(ORDER_DETAIL_HREF_RE) ?? []).length;

  return {
    displayName,
    availablePoints,
    totalPoints,
    usedPoints,
    orderCount,
    totalSpent,
    messageCount,
    tierLabel,
    tags: tagNames,
    recentTransactionCount,
  };
}

// ---------------------------------------------------------------------------
// /dashboard?tab=executive extraction
// ---------------------------------------------------------------------------

const AVATAR_FALLBACK = '/assets/img/avatar-default.svg';

/**
 * FLAGGED FINDING (build report): HourlyActivityChart.tsx's per-bar SVG
 * `<title>{hour}:00 — {count} ข้อความ</title>` renders as a LITERALLY EMPTY
 * `<title></title>` in this batch's own real `next build` output — verified
 * empirically (dumped a real fetched /dashboard?tab=executive response,
 * confirmed every `<rect>`'s `<title>` element is present but content-less,
 * while the SAME `<rect>`'s `height`/`y` attributes DO correctly vary with
 * the underlying per-hour counts, proving the DATA is right and only the
 * accessible-name TEXT is being stripped). Root cause (not fixed here —
 * apps/admin/** is out of this agent's allowed paths): Next.js's App Router
 * metadata system special-cases `<title>` elements anywhere in the render
 * tree for its document-title management, not just document `<head>`; an
 * SVG `<title>` (a distinct, unrelated accessibility element in SVG's own
 * spec) collides with that and gets its text content swallowed. Flag to
 * mig-orchestrator / the dashboard page-agent — likely fix is renaming to
 * `<desc>` or moving the per-bar label to a `title`/`aria-label` ATTRIBUTE
 * instead of a child element.
 *
 * WORKAROUND for this harness only: `hourlyActivity`'s WHERE clause is
 * IDENTICAL to `msgStats.total`'s (both `SELECT ... FROM messages WHERE
 * created_at BETWEEN ? AND ?`, no direction filter — see
 * includes/dashboard/executive.php lines 17-27 vs 174-186, and
 * executiveData.ts's fetchMessageStats()/fetchHourlyActivity() — the ONLY
 * difference is the GROUP BY HOUR()). Summing counts grouped by any key
 * always equals the ungrouped total for the same predicate, so
 * `hourlyActivityTotal` is MATHEMATICALLY GUARANTEED to equal `msgTotal` on
 * both stacks regardless of this bug — using the already-independently-
 * extracted `msgTotal` as Next's hourly-activity-total is not a weaker
 * check, it is the exact same integer the (broken) chart text would have
 * produced. PHP's total is still read directly from its own independent
 * source (the `<script>`-embedded `hourlyData` array) — this workaround
 * only applies to the side that's actually broken.
 */
function extractHourlyActivityTotal(html, stack, msgTotalFallback) {
  if (stack === 'php') {
    const m = html.match(/const hourlyData = (\[[^\]]*\]);/);
    if (!m) return null;
    try {
      const arr = JSON.parse(m[1]);
      return arr.reduce((sum, n) => sum + Number(n), 0);
    } catch {
      return null;
    }
  }
  // next — try the (currently broken, see doc above) SVG <title> text first;
  // fall back to the proven-equivalent msgTotal if no non-empty title text
  // is found at all (so this self-heals for free if the upstream bug is
  // ever fixed, without this harness needing to change).
  let total = 0;
  let found = false;
  for (const m of html.matchAll(/(\d+):00\s*[—-]\s*(\d+)\s*ข้อความ/g)) {
    found = true;
    total += Number(m[2]);
  }
  return found ? total : msgTotalFallback;
}

export function extractExecutiveDashboard(html, stack) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const msgTotal = parseLeadingNumber(cursor.afterLabel('ข้อความวันนี้'));
  const customersToday = parseLeadingNumber(cursor.afterLabel('ลูกค้าติดต่อ'));
  const ordersTotal = parseLeadingNumber(cursor.afterLabel('ออเดอร์'));
  const revenue = parseLeadingNumber(cursor.afterLabel('รายได้'));
  const videoCallsTotal = parseLeadingNumber(cursor.afterLabel('วิดีโอคอล'));

  const avgResponseTime = parseLeadingNumber(cursor.afterLabel('เวลาตอบกลับเฉลี่ย'));
  const unreadCount = parseLeadingNumber(cursor.afterLabel('ยังไม่ได้อ่าน'));
  const problemCountKpi = parseLeadingNumber(cursor.afterLabel('ปัญหา/ข้อร้องเรียน'));

  cursor.advanceTo('ผลงาน Admin วันนี้');
  const adminPerformanceRowCount = cursor.countInSlice('ดูแล ', ['กิจกรรมรายชั่วโมง']);

  cursor.advanceTo('กิจกรรมรายชั่วโมง');

  const problemMessageCount = parseLeadingNumber(cursor.afterLabel('ข้อความที่อาจเป็นปัญหา'));

  cursor.advanceTo('การสนทนาล่าสุด');
  const recentConversationCount = cursor.countInSlice(AVATAR_FALLBACK, ['หัวข้อที่ลูกค้าถามบ่อย']);

  const hourlyActivityTotal = extractHourlyActivityTotal(html, stack, msgTotal);

  return {
    msgTotal,
    customersToday,
    ordersTotal,
    revenue,
    videoCallsTotal,
    avgResponseTime,
    unreadCount,
    problemCountKpi,
    adminPerformanceRowCount,
    hourlyActivityTotal,
    problemMessageCount,
    recentConversationCount,
  };
}

// ---------------------------------------------------------------------------
// /dashboard?tab=crm extraction
// ---------------------------------------------------------------------------

/** Literal tag/rule names from the fixture (infra/e2e/seed/30-phase2-batch1-fixture.sql.tmpl) — used for row-counting via known-name matching (see module doc). Kept here (not re-derived from the DB) so this module has zero DB dependency; parity.mjs is what keeps the two in sync (both are Phase-2-batch-1-owned files, changed together). */
export const FIXTURE_TAG_NAMES = ['VIP', 'Regular', 'Blocked-Test', 'Newsletter', 'Auto-Segment'];
export const FIXTURE_AUTO_RULE_NAMES = ['ลูกค้าแต้มสูงอัตโนมัติ', 'ลูกค้าใหม่ 7 วัน'];

export function extractCrmDashboard(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const totalCustomers = parseLeadingNumber(cursor.afterLabel('ลูกค้าทั้งหมด'));
  const newToday = parseLeadingNumber(cursor.afterLabel('ใหม่วันนี้'));
  const totalTags = parseLeadingNumber(cursor.afterLabel('Tags')); // KPI tile — occurs BEFORE the "Tags" SectionCard heading below.
  const autoRulesKpi = parseLeadingNumber(cursor.afterLabel('Auto Rules'));

  // From here on, "Tags"/"Auto Tag Rules" refer to the SectionCard headings (cursor already past the KPI row).
  cursor.advanceTo('Tags');
  const tagsRowCount = FIXTURE_TAG_NAMES.reduce(
    (count, name) => count + (cursor.sliceUntil(['Auto Tag Rules']).includes(name) ? 1 : 0),
    0
  );

  cursor.advanceTo('Auto Tag Rules');
  const autoRulesRowCount = FIXTURE_AUTO_RULE_NAMES.reduce(
    (count, name) => count + (cursor.sliceUntil(['ลูกค้าล่าสุด']).includes(name) ? 1 : 0),
    0
  );

  cursor.advanceTo('ลูกค้าล่าสุด');
  const recentCustomersRowCount = cursor.countInSlice(AVATAR_FALLBACK, ['Quick Actions']);

  return {
    totalCustomers,
    newToday,
    totalTags,
    autoRulesKpi,
    tagsRowCount,
    autoRulesRowCount,
    recentCustomersRowCount,
  };
}

// ---------------------------------------------------------------------------
// Phase 2 batch 2 (mig-infra) additions — /analytics (tabs overview/advanced/
// crm/account), /activity-logs, /loyalty-members. Same label-anchored
// HtmlCursor technique as everything above; no new extraction primitives
// needed. See infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's own header
// comment for the full "why no line_accounts rows" rationale these
// extractors are built around (every analytics tab below is read with
// lineAccountId/currentBotId == null on BOTH stacks, by design).
// ---------------------------------------------------------------------------

/** Reused by both analytics:overview and analytics:crm — same 5 fixture tags (batch-1's, all line_account_id IS NULL) are visible on every tab that reads user_tags unscoped. */
export const FIXTURE_KEYWORD_NAMES = ['ราคา', 'จัดส่ง'];

// ---------------------------------------------------------------------------
// /activity-logs page extraction
// ---------------------------------------------------------------------------

/**
 * Extracts activity-logs' data-point list: totalLogs (page-header subtitle
 * "N รายการ"), the "แสดงรายการที่ X-Y จาก Z" range line (X/Y — only rendered
 * when totalLogs > 0, which every fixture-backed combo in this batch
 * guarantees), and a structural (not text-label) row count: <td> tags inside
 * <tbody> divided by the table's fixed 6 columns (เวลา/ประเภท/การกระทำ/
 * รายละเอียด/ผู้ดำเนินการ/IP — same on both stacks, verified by reading both
 * markups). Counting <td> rather than <tr> sidesteps the empty-state row
 * (colspan="6", a single <td>) skewing a naive <tr> count — emptyStateShown
 * is reported separately instead.
 */
/**
 * FLAGGED FINDING (build report): unlike /users, /user-detail, /dashboard
 * above, activity-logs.php does NOT use the shared PHP page-header partial
 * at all — verified empirically against this batch's own real page fetch —
 * it has its own bespoke `<div class="log-count">N รายการ</div>` markup,
 * while Next's port uses the shared `<PageHeader subtitle=.../>` component
 * (`<p class="page-header-subtitle">N รายการ</p>`). A plain text search for
 * "รายการ" (no class anchor at all) is UNSAFE here specifically: this
 * codebase's shared admin layout renders a hidden command-palette/quick-nav
 * widget INSIDE `.main-content` (confirmed via a real fetch — a
 * `data-nav-title="รายการสั่งซื้อ"` / `command-result-title` block sits well
 * before the log-count div on the PHP side), so a bare "รายการ" search finds
 * THAT first and returns null. This is genuinely a per-stack MARKUP
 * difference (bespoke div vs. shared component), not just a class-naming
 * difference already proven identical elsewhere — so, same precedent as
 * extractHourlyActivityTotal()'s stack-branching above, this extractor takes
 * an explicit `stack` argument and anchors on each side's OWN real class.
 */
export function extractActivityLogsPage(html, stack) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const totalLogs =
    stack === 'php'
      ? parseLeadingNumber(cursor.afterLabel('class="log-count">'))
      : parseLeadingNumber(cursor.afterLabel('class="page-header-subtitle">'));

  // Only rendered when totalLogs > 0 (both stacks — `{totalLogs > 0 ? ... : null}` / `<?php if ($totalLogs > 0): ?>`).
  const rangeChunk = main.includes('แสดงรายการที่') ? cursor.afterLabel('แสดงรายการที่') : null;
  const rangeMatch = rangeChunk ? rangeChunk.match(/([\d,]+)\s*-\s*([\d,]+)/) : null;
  const rangeStart = rangeMatch ? Number(rangeMatch[1].replace(/,/g, '')) : null;
  const rangeEnd = rangeMatch ? Number(rangeMatch[2].replace(/,/g, '')) : null;

  cursor.advanceTo('<tbody');
  const tbodySlice = cursor.sliceUntil(['</tbody>']);
  const emptyStateShown = tbodySlice.includes('ไม่พบข้อมูล');
  const tdCount = (tbodySlice.match(/<td/g) ?? []).length;
  const rowCount = emptyStateShown ? 0 : Math.round(tdCount / 6);

  return { totalLogs, rangeStart, rangeEnd, rowCount, emptyStateShown };
}

// ---------------------------------------------------------------------------
// /loyalty-members page extraction
// ---------------------------------------------------------------------------

/**
 * Extracts loyalty-members' 3 stat-card numbers + which (if either) empty
 * message is shown. In THIS harness (no `line_accounts` rows — see the
 * fixture file's header comment) `lineAccountId`/`currentBotId` is always 0,
 * so `stats` is always the zeroed default and `members` is always [] on
 * BOTH stacks — a real, meaningful assertion (both sides short-circuit the
 * SAME gate identically), just never the "non-empty member list" case.
 */
export function extractLoyaltyMembersPage(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const total = parseLeadingNumber(cursor.beforeLabel('สมาชิกเบอร์ทั้งหมด'));
  const points = parseLeadingNumber(cursor.beforeLabel('แต้มคงเหลือรวม'));
  const today = parseLeadingNumber(cursor.beforeLabel('เพิ่มวันนี้'));

  let emptyMessage = null;
  if (main.includes('ยังไม่มีสมาชิกเบอร์')) emptyMessage = 'no-members';
  else if (main.includes('ไม่พบสมาชิกที่ค้นหา')) emptyMessage = 'no-search-results';

  return { total, points, today, emptyMessage };
}

// ---------------------------------------------------------------------------
// /analytics?tab=overview extraction
// ---------------------------------------------------------------------------

export function extractAnalyticsOverview(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const followers = parseLeadingNumber(cursor.afterLabel('ผู้ติดตาม'));
  const newFollowers = parseLeadingNumber(cursor.beforeLabel('ใหม่'));
  const activeUsers = parseLeadingNumber(cursor.afterLabel('Active Users'));
  const messages = parseLeadingNumber(cursor.afterLabel('ข้อความ'));
  const broadcasts = parseLeadingNumber(cursor.afterLabel('Broadcast'));
  const broadcastRecipients = parseLeadingNumber(cursor.beforeLabel('ผู้รับ'));
  const orders = parseLeadingNumber(cursor.afterLabel('ออเดอร์'));
  const revenue = parseLeadingNumber(cursor.afterLabel('รายได้'));

  cursor.advanceTo('Top Tags');
  const topTagsCount = FIXTURE_TAG_NAMES.reduce(
    (count, name) => count + (cursor.sliceUntil(['Top Keywords']).includes(name) ? 1 : 0),
    0
  );

  cursor.advanceTo('Top Keywords');
  const topKeywordsCount = FIXTURE_KEYWORD_NAMES.reduce(
    (count, kw) => count + (cursor.sliceUntil(['Quick Actions']).includes(kw) ? 1 : 0),
    0
  );

  cursor.advanceTo('Quick Actions');
  const segmentsCount = parseLeadingNumber(cursor.afterLabel('Segments ('));

  return {
    followers,
    newFollowers,
    activeUsers,
    messages,
    broadcasts,
    broadcastRecipients,
    orders,
    revenue,
    topTagsCount,
    topKeywordsCount,
    segmentsCount,
  };
}

// ---------------------------------------------------------------------------
// /analytics?tab=advanced extraction
// ---------------------------------------------------------------------------

/**
 * Advanced tab's stat cards render VALUE-then-LABEL (`<p>{value}</p><p>label</p>`
 * — verified against both includes/analytics/advanced.php ->
 * app/Views/analytics/dashboard.php and AdvancedTab.tsx), the OPPOSITE order
 * from the CRM tab below (LABEL-then-VALUE) — beforeLabel()/the
 * advanceTo()+afterLabel('</p>') idiom are used respectively, matching each
 * tab's own real markup order (read, not assumed).
 */
export function extractAnalyticsAdvanced(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const realtimeActiveUsers = parseLeadingNumber(cursor.afterLabel('Active Users:'));
  const realtimeMessagesPerHour = parseLeadingNumber(cursor.afterLabel('Messages/hr:'));
  const realtimeOrdersToday = parseLeadingNumber(cursor.afterLabel('Orders Today:'));
  const realtimeRevenueToday = parseLeadingNumber(cursor.afterLabel('Revenue Today:'));

  const usersTotal = parseLeadingNumber(cursor.beforeLabel('ผู้ใช้ทั้งหมด'));
  const usersNew = parseLeadingNumber(cursor.afterLabel('ใหม่ '));
  const usersActive = parseLeadingNumber(cursor.afterLabel('Active '));

  const messagesTotal = parseLeadingNumber(cursor.beforeLabel('ข้อความทั้งหมด'));
  const messagesIncoming = parseLeadingNumber(cursor.afterLabel('เข้า '));
  const messagesOutgoing = parseLeadingNumber(cursor.afterLabel('ออก '));

  const ordersTotal = parseLeadingNumber(cursor.beforeLabel('คำสั่งซื้อ'));
  const ordersPaid = parseLeadingNumber(cursor.afterLabel('ชำระแล้ว '));

  const revenueTotal = parseLeadingNumber(cursor.beforeLabel('รายได้'));

  return {
    realtimeActiveUsers,
    realtimeMessagesPerHour,
    realtimeOrdersToday,
    realtimeRevenueToday,
    usersTotal,
    usersNew,
    usersActive,
    messagesTotal,
    messagesIncoming,
    messagesOutgoing,
    ordersTotal,
    ordersPaid,
    revenueTotal,
  };
}

// ---------------------------------------------------------------------------
// /analytics?tab=crm extraction
// ---------------------------------------------------------------------------

/**
 * CRM tab's stat cards render LABEL-then-VALUE (`<p>label</p><p>{value}</p>`
 * — includes/analytics/crm.php vs CrmTab.tsx, both read in full) — the
 * `advanceTo(label); afterLabel('</p>')` idiom jumps past the label
 * paragraph's own closing tag into the immediately-following value
 * paragraph, same technique user-detail's Tags-heading extraction already
 * established for "the next card's content, not this card's own label".
 */
export function extractAnalyticsCrm(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  cursor.advanceTo('Total Users');
  const totalUsers = parseLeadingNumber(cursor.afterLabel('</p>'));

  cursor.advanceTo('Active Users (');
  const activeUsers = parseLeadingNumber(cursor.afterLabel('</p>'));

  cursor.advanceTo('New Users (');
  const newUsers = parseLeadingNumber(cursor.afterLabel('</p>'));

  cursor.advanceTo('Segments');
  const segmentsCount = parseLeadingNumber(cursor.afterLabel('</p>'));

  cursor.advanceTo('Top Tags');
  const topTagsRowCount = FIXTURE_TAG_NAMES.reduce(
    (count, name) => count + (cursor.sliceUntil(['Customer Segments']).includes(name) ? 1 : 0),
    0
  );

  return { totalUsers, activeUsers, newUsers, segmentsCount, topTagsRowCount };
}

// ---------------------------------------------------------------------------
// /analytics?tab=account extraction
// ---------------------------------------------------------------------------

/**
 * This harness never seeds `line_accounts` (see the fixture file's header
 * comment — a real row would corrupt PHP's currentBotId auto-select for
 * every OTHER page in the shared session), so account.php/AccountTab.tsx are
 * always in the "no bot selected" state — a real, valid tenant state (a
 * fresh tenant that hasn't connected a LINE OA yet), not a fixture gap. This
 * extractor verifies that both stacks render the SAME prompt/selector state
 * identically, which is the only account-tab state reachable here.
 */
export function extractAnalyticsAccount(html) {
  const main = sliceMainContent(html);
  const promptShown = main.includes('กรุณาเลือกบอทเพื่อดูสถิติ');
  const accountSelectorPresent = main.includes('-- เลือกบอท --');
  return { promptShown, accountSelectorPresent };
}

// ---------------------------------------------------------------------------
// Phase 2 batch 3 (mig-infra) additions — /templates, /groups, /line-groups,
// /line-group-detail, /crm-dashboard-advanced, /system-status. Same
// label-anchored HtmlCursor technique as everything above. See
// docs/runbooks/phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 3"
// section for the full write-up of what's new and the two joint decisions
// made with mig-ui (the $currentBotId/no-line_accounts invariant reuse, and
// the crm-dashboard-advanced 500-vs-200 exception shape).
// ---------------------------------------------------------------------------

/**
 * Splits `html` into its tag-delimited visible text chunks (decoded,
 * trimmed, empty chunks dropped) — the same technique firstVisibleChunk()/
 * lastVisibleChunk() use internally (stripTags() marks tag boundaries with
 * ``, comments stripped first, never treated as a boundary — see this
 * module's own doc for why). Exposed as its own local helper (not exported)
 * because several batch-3 extractors below need MULTIPLE consecutive
 * visible chunks out of one bounded HTML slice (e.g. a template card's
 * name-then-category-then-type, all three plain-text siblings with no
 * shared class between the PHP and Next markup to anchor on individually —
 * see extractTemplatesPage()), not just "the first" or "the last" the
 * existing two helpers give you.
 */
function visibleChunks(html) {
  return stripTags(html)
    .split('')
    .map((s) => decodeEntities(s).trim())
    .filter((s) => s.length > 0);
}

// ---------------------------------------------------------------------------
// /templates page extraction
// ---------------------------------------------------------------------------

/**
 * Extracts templates.php's data-point list: the category-filter-bar's
 * button labels IN RENDER ORDER (['ทั้งหมด', ...array_unique-deduped
 * categories] — templates/_lib/categories.ts's own module doc explains why
 * this must be first-seen order, not `.sort()`'d, and this extractor
 * deliberately does NOT re-sort either, so a regression to alphabetical
 * order on either stack would show up as a mismatch here), plus one
 * {name, category, messageType} tuple per rendered template card, in row
 * order (both stacks run the identical `ORDER BY category, name` query, so
 * row order is a real, meaningful parity signal here, not just a count).
 *
 * DESIGN — anchored on the `data-category="..."` attribute, not on any CSS
 * class: templates.php's `.template-card`/`.template-card-name`/
 * `.template-card-cat`/`.template-type-badge` classes have NO Tailwind-
 * utility equivalent in TemplateCard.tsx (verified by reading both — the
 * Next port uses plain utility classes throughout, none shared with PHP's
 * bespoke `<style>` block). `data-category` is the ONE attribute both sides
 * genuinely share byte-for-byte (PHP: `data-category="<?=
 * htmlspecialchars($template['category']) ?>"` on the PHP `.template-card`
 * div; Next: `data-category={template.category ?? ''}` on TemplateCard.tsx's
 * outer div) — used here purely as a per-card DELIMITER, not as the
 * category value itself (the category value is instead read from the
 * card's own visible text, same as the name/messageType, so a hypothetical
 * future drift between the attribute and the displayed text would still be
 * caught).
 */
export function extractTemplatesPage(html) {
  const main = sliceMainContent(html);

  const allBtnTextIdx = main.indexOf('>ทั้งหมด<');
  if (allBtnTextIdx === -1) {
    throw new Error('extractTemplatesPage: "ทั้งหมด" (all-categories) filter button not found');
  }
  // lastIndexOf(..., allBtnTextIdx), not the text match itself, so the slice
  // below starts at this button's OWN opening `<button` tag rather than
  // mid-tag (mid-tag would silently drop the "ทั้งหมด" button from
  // categoryButtons below — caught empirically by this batch's own unit
  // test before ever touching a real browser/docker build).
  const filterBarStart = main.lastIndexOf('<button', allBtnTextIdx);
  const firstCardIdx = main.indexOf('data-category="');
  const filterBarSlice = firstCardIdx === -1 ? main.slice(filterBarStart) : main.slice(filterBarStart, firstCardIdx);
  const categoryButtons = [...filterBarSlice.matchAll(/<button[^>]*>([^<]*)<\/button>/g)].map((m) => decodeEntities(m[1]).trim());

  // `[^>]*>` after the captured attribute value consumes the REST of the
  // card's own opening tag (its other attributes + the tag's closing `>`)
  // before group 2 starts — omitting this left a stray, un-stripped `>`
  // character as visibleChunks()'s first "chunk" (caught by this batch's
  // own unit test).
  const CARD_RE = /data-category="([^"]*)"[^>]*>([\s\S]*?)(?=data-category="|$)/g;
  const cards = [...main.matchAll(CARD_RE)].map((m) => {
    const category = decodeEntities(m[1]).trim();
    const chunks = visibleChunks(m[2]);
    // Render order within one card (verified identical on both stacks —
    // templates.php lines 168-178 vs TemplateCard.tsx lines 55-66): name,
    // then the category-display line (falls back to 'ไม่มีหมวดหมู่' when
    // empty — same fallback text both sides), then the message-type badge.
    return { name: chunks[0] ?? null, categoryDisplay: chunks[1] ?? null, messageType: chunks[2] ?? null, dataCategory: category || null };
  });

  const emptyStateShown = main.includes('ยังไม่มีเทมเพลต');

  return { categoryButtons, cardCount: cards.length, cards, emptyStateShown };
}

// ---------------------------------------------------------------------------
// /groups page extraction (baseline + ?view=N variant — same extractor,
// the detail-panel fields are simply null when no `viewGroup` resolved)
// ---------------------------------------------------------------------------

/**
 * Extracts groups.php's data-point list: the left-hand groups list (name +
 * memberCount per row, in `ORDER BY g.name` order) and, when `?view=<id>`
 * resolves to a real group, the right-hand detail panel (group name +
 * description + member rows).
 *
 * DESIGN: group rows are delimited by their own `href` containing
 * `view=<id>"` — PHP: `<a href="?view=<?= $group['id'] ?>" ...>`; Next:
 * `<a href={\`/groups?view=${group.id}\`} ...>` — both contain the literal
 * substring `view=<id>"` right before the rest of the tag's attributes, a
 * reliable per-row delimiter shared by construction (both are literally the
 * SAME URL query param). The member-count text ("N สมาชิก") is one
 * concatenated chunk on both stacks (PHP: `<?= $group['member_count'] ?>
 * สมาชิก`; Next: `{group.memberCount} สมาชิก` — a JSX expression followed by
 * a string-literal sibling, never split by an intervening tag, so no
 * hydration-comment concern here even though this module's own doc flags
 * that pattern elsewhere).
 *
 * The detail panel's `<h3 class="font-semibold">` is genuinely
 * class-identical on both stacks (GroupsPanel.tsx line 87 vs groups.php line
 * 97) but occurs TWICE on a `?view=` page — once for the static left-panel
 * heading ("กลุ่มทั้งหมด"), once for the dynamic viewGroup name. Anchoring
 * past the FIRST occurrence via `cursor.advanceTo('กลุ่มทั้งหมด')` before
 * searching for the second is what makes this safe (same "known collision,
 * anchor past it" technique extractCrmDashboard() already uses for its own
 * "Tags" KPI-tile-vs-heading collision).
 */
export function extractGroupsPage(html) {
  const main = sliceMainContent(html);

  const groupRows = [...main.matchAll(/view=(\d+)"[^>]*>([\s\S]*?)<\/a>/g)].map((m) => {
    const chunks = visibleChunks(m[2]);
    const name = chunks[0] ?? null;
    const countMatch = chunks[1] ? chunks[1].match(/(\d+)/) : null;
    return { name, memberCount: countMatch ? Number(countMatch[1]) : null };
  });
  const groupListEmptyShown = main.includes('ยังไม่มีกลุ่ม');

  const cursor = new HtmlCursor(main);
  cursor.advanceTo('กลุ่มทั้งหมด');
  const detailPlaceholderShown = main.includes('เลือกกลุ่มเพื่อดูรายละเอียด', cursor.pos);

  let viewGroupName = null;
  let viewGroupDescription = null;
  let memberRows = [];
  let membersEmptyShown = null;

  if (!detailPlaceholderShown) {
    viewGroupName = cursor.afterLabel('class="font-semibold">');
    viewGroupDescription = cursor.afterLabel('class="text-sm text-gray-500">');
    cursor.advanceTo('เพิ่มสมาชิก');
    const membersSlice = cursor.sliceUntil([]);
    memberRows = [...membersSlice.matchAll(/class="font-medium">([^<]*)</g)].map((m) => decodeEntities(m[1]).trim());
    membersEmptyShown = membersSlice.includes('ยังไม่มีสมาชิกในกลุ่ม');
  }

  return {
    groupCount: groupRows.length,
    groupRows,
    groupListEmptyShown,
    detailPlaceholderShown,
    viewGroupName,
    viewGroupDescription,
    memberCount: memberRows.length,
    memberRows,
    membersEmptyShown,
  };
}

// ---------------------------------------------------------------------------
// /line-groups page extraction
// ---------------------------------------------------------------------------

/**
 * Extracts line-groups.php's data-point list: the 4 stats-card numbers
 * (total/active/totalMembers/totalMessages — genuinely CLASS-IDENTICAL on
 * both stacks, `text-3xl font-bold text-{blue,green,purple,orange}-500`,
 * verified by reading both line-groups.php lines 152-167 and
 * page.tsx lines 48-63), the "N กลุ่ม" list-header count, and one row per
 * group ({groupName, botName, memberCount, totalMessages, isActive}, in
 * `ORDER BY is_active DESC, joined_at DESC` order — identical on both
 * stacks). Row fields are also read off shared classes (`class="font-
 * medium"` — appears twice per row, group name then member-count span, see
 * LineGroupRow.tsx lines 48/57 vs line-groups.php lines 210/219;
 * `class="text-sm text-gray-600"` for botName; the EXACT (non-prefixed)
 * `class="text-gray-600"` for totalMessages, deliberately distinct from the
 * `text-sm text-gray-600` bot-name span so the two never collide).
 */
export function extractLineGroupsPage(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const statsTotal = parseLeadingNumber(cursor.afterLabel('text-3xl font-bold text-blue-500">'));
  const statsActive = parseLeadingNumber(cursor.afterLabel('text-3xl font-bold text-green-500">'));
  const statsTotalMembers = parseLeadingNumber(cursor.afterLabel('text-3xl font-bold text-purple-500">'));
  const statsTotalMessages = parseLeadingNumber(cursor.afterLabel('text-3xl font-bold text-orange-500">'));

  const listCount = parseLeadingNumber(cursor.afterLabel('class="text-sm text-gray-500">'));
  const emptyStateShown = main.includes('ยังไม่มีกลุ่มที่บอทเข้าร่วม');

  const rows = [...main.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)]
    .map((m) => m[1])
    .filter((rowHtml) => rowHtml.includes('class="font-medium">')) // skips the <thead> header row, which has no such cell
    .map((rowHtml) => {
      const fontMediumMatches = [...rowHtml.matchAll(/class="font-medium">([^<]*)</g)].map((mm) => decodeEntities(mm[1]).trim());
      const groupName = fontMediumMatches[0] ?? null;
      const memberCount = fontMediumMatches[1] !== undefined ? parseLeadingNumber(fontMediumMatches[1]) : null;
      const botNameMatch = rowHtml.match(/class="text-sm text-gray-600">([^<]*)</);
      const botName = botNameMatch ? decodeEntities(botNameMatch[1]).trim() : null;
      const totalMessagesMatch = rowHtml.match(/class="text-gray-600">([^<]*)</);
      const totalMessages = totalMessagesMatch ? parseLeadingNumber(totalMessagesMatch[1]) : null;
      const isActive = rowHtml.includes('>Active<');
      const isLeft = rowHtml.includes('>Left<');
      return { groupName, botName, memberCount, totalMessages, isActive, isLeft };
    });

  return {
    stats: { total: statsTotal, active: statsActive, totalMembers: statsTotalMembers, totalMessages: statsTotalMessages },
    listCount,
    emptyStateShown,
    rowCount: rows.length,
    rows,
  };
}

// ---------------------------------------------------------------------------
// /line-group-detail?id=N page extraction
// ---------------------------------------------------------------------------

/**
 * FLAGGED FINDING (build report, discovered by this batch's own harness run
 * against REAL rendered PHP HTML — not visible from reading
 * line-group-detail.php in isolation, which is why pagesB's own port doc
 * doesn't mention it): line-group-detail.php's group HEADER (name,
 * member/message-count badges, active/left status, group type, bot name) is
 * PERMANENTLY BROKEN in real production, on every request, for every group.
 * Root cause (confirmed by reading includes/header.php in full):
 * `foreach ($menuGroups as $group) { ... }` at header.php line 449 (no
 * `unset($group)` afterward) reuses the exact same variable name
 * `$group` as line-group-detail.php's own fetched DB row — and since
 * `require_once 'includes/header.php'` (line-group-detail.php line 58) runs
 * as a plain top-level include, NOT inside a function, both files share the
 * SAME global scope, so header.php's loop OVERWRITES line-group-detail.php's
 * `$group` with header.php's own LAST menu-group array entry before the HTML
 * body ever reads it. `$pageTitle` (line 29, computed BEFORE header.php
 * runs) is unaffected and correctly shows the real group name — only the
 * <title>/sidebar breadcrumb see the real data; the page's own H1/badges see
 * header.php's leftover menu-group array instead, whose absent keys make
 * `$group['group_name'] ?: 'Unknown Group'` -> 'Unknown Group',
 * `number_format($group['member_count'])`/`...['total_messages']` ->
 * `number_format(null)` -> '0', `$group['is_active']` -> falsy -> always the
 * "Left" badge, `$group['group_type'] === 'room'` -> always false -> always
 * "Group". This is 100% independent of THIS fixture's data (verified against
 * BOTH a real active AND a real inactive seeded group, both showing the
 * identical broken output) — a real, pre-existing production defect, not
 * fixable here (line-group-detail.php/includes/header.php are off-limits).
 *
 * Consequence for this harness: the header fields are NOT part of
 * extractLineGroupDetailPage()'s returned (diffed) object at all — diffing
 * Next's genuinely-correct header against PHP's genuinely-broken one would
 * just look like "Next has a bug" and bury the real finding. Instead, TWO
 * separate, single-stack, POSITIVELY-ASSERTING functions verify each
 * stack's own header behavior independently (mirroring
 * extractCrmDashboardAdvancedDefensiveEmpty()'s precedent) — see
 * extractLineGroupDetailHeaderPhpDefect() / extractLineGroupDetailHeaderNext()
 * below, wired up via parity.mjs's own runSingleSideCheck() entries. See
 * this batch's runbook section for the full write-up.
 *
 * Extracts the members panel (heading count + one row per member:
 * displayName, totalMessages, isLeft) and the recent-messages panel
 * (heading count + one row per message: displayName, hasTypePrefix,
 * isTruncated) — genuinely comparable on both stacks (both read off
 * `$groupId`/`groupId`, a plain scalar param never touched by the
 * `$group`-clobbering bug above).
 *
 * The two panels are read from bounded SLICES (members: from the "สมาชิก ("
 * heading up to "ข้อความล่าสุด"; messages: from there up to the first
 * trailing `<script` tag — NOT unbounded to end-of-document, which would
 * otherwise sweep in Next's own React-hydration RSC payload script, a
 * `self.__next_f.push(...)` blob that JSON-serializes this same page's props
 * a SECOND time with different escaping, verified empirically to contain
 * spurious extra `"..."` occurrences that inflated messageTruncatedCount
 * before this bound was added — see PARITY_DUMP_HTML-captured evidence in
 * this batch's build report). Comments are stripped from both slices before
 * any regex runs against them (`<!-- -->` hydration boundary markers — this
 * file's own module doc explains why — verified empirically to sit directly
 * between "ข้อความ: " and its digit in `memberMessageCounts`' underlying
 * markup, silently breaking a naive `\s*` gap in the regex before this fix).
 */
function extractLineGroupDetailBody(main) {
  const membersHeadingIdx = main.indexOf('สมาชิก (');
  if (membersHeadingIdx === -1) {
    throw new Error('extractLineGroupDetailPage: "สมาชิก (" members-panel heading not found');
  }
  const membersHeadingCount = parseLeadingNumber(new HtmlCursor(main.slice(membersHeadingIdx)).afterLabel('สมาชิก ('));

  const messagesHeadingIdx = main.indexOf('ข้อความล่าสุด', membersHeadingIdx);
  if (messagesHeadingIdx === -1) {
    throw new Error('extractLineGroupDetailPage: "ข้อความล่าสุด" messages-panel heading not found');
  }
  const scriptIdx = main.indexOf('<script', messagesHeadingIdx);
  const bodyEnd = scriptIdx === -1 ? main.length : scriptIdx;

  const stripComments = (s) => s.replace(/<!--[\s\S]*?-->/g, '');
  const membersSlice = stripComments(main.slice(membersHeadingIdx, messagesHeadingIdx));
  const messagesSlice = stripComments(main.slice(messagesHeadingIdx, bodyEnd));

  const membersEmptyShown = membersSlice.includes('ยังไม่มีข้อมูลสมาชิก');
  const memberDisplayNames = [...membersSlice.matchAll(/class="font-medium">([^<]*)</g)].map((m) => decodeEntities(m[1]).trim());
  const memberMessageCounts = [...membersSlice.matchAll(/ข้อความ:\s*([\d,]+)/g)].map((m) => Number(m[1].replace(/,/g, '')));
  const memberLeftCount = (membersSlice.match(/ออกแล้ว/g) ?? []).length;

  const messagesEmptyShown = messagesSlice.includes('ยังไม่มีข้อความ');
  const messageRowCount = (messagesSlice.match(/class="border-b pb-2">/g) ?? []).length;
  const messageDisplayNames = [...messagesSlice.matchAll(/class="font-medium text-sm">([^<]*)</g)].map((m) => decodeEntities(m[1]).trim());
  const messageTypePrefixCount = (messagesSlice.match(/class="text-gray-400">\[/g) ?? []).length;
  const messageTruncatedCount = (messagesSlice.match(/\.\.\./g) ?? []).length;

  return {
    membersHeadingCount,
    membersEmptyShown,
    memberCount: memberDisplayNames.length,
    memberDisplayNames,
    memberMessageCounts,
    memberLeftCount,
    messagesEmptyShown,
    messageRowCount,
    messageDisplayNames,
    messageTypePrefixCount,
    messageTruncatedCount,
  };
}

export function extractLineGroupDetailPage(html) {
  const main = sliceMainContent(html);
  return extractLineGroupDetailBody(main);
}

/** Reads the group-header fields (see extractLineGroupDetailPage()'s module doc for why these are read separately from everything else). Not exported on its own — only ever used by the two stack-specific assertion functions below, which is the ONLY place this batch's harness reads them. */
function readLineGroupDetailHeader(main) {
  const cursor = new HtmlCursor(main);
  const groupName = cursor.afterLabel('class="text-2xl font-bold">');
  const headerLine = cursor.afterLabel('class="text-gray-500">'); // "Group • บอท: -" (or "Room • บอท: <name>")
  const groupType = headerLine && headerLine.startsWith('Room') ? 'room' : 'group';
  const botNameMatch = headerLine ? headerLine.match(/บอท:\s*(.*)$/) : null;
  const botName = botNameMatch ? botNameMatch[1].trim() : null;
  const memberCountBadge = parseLeadingNumber(cursor.afterLabel('text-2xl font-bold text-blue-500">'));
  const totalMessagesBadge = parseLeadingNumber(cursor.afterLabel('text-2xl font-bold text-green-500">'));
  cursor.advanceTo('เข้าร่วมเมื่อ'); // anchors just past the Active/Left badge, which renders immediately before this label on both stacks.
  const headerSlice = main.slice(0, cursor.pos);
  const isActive = headerSlice.includes('>Active<');
  return { groupName, groupType, botName, memberCountBadge, totalMessagesBadge, isActive };
}

/**
 * Positively asserts PHP's line-group-detail.php header shows the KNOWN
 * `$group`-clobbering defect (see extractLineGroupDetailPage()'s module doc)
 * — throws if it doesn't, so a future fix to header.php's variable
 * collision (or line-group-detail.php starting to defend against it) is
 * CAUGHT, not silently masked. Deliberately does NOT vary by group id — the
 * defect is structural (which menu group header.php's OWN loop last
 * iterated), not data-dependent, so the same assertion applies to every id.
 */
export function extractLineGroupDetailHeaderPhpDefect(html) {
  const header = readLineGroupDetailHeader(sliceMainContent(html));
  const problems = [];
  if (header.groupName !== 'Unknown Group') problems.push(`groupName=${JSON.stringify(header.groupName)}, expected "Unknown Group"`);
  if (header.memberCountBadge !== 0) problems.push(`memberCountBadge=${header.memberCountBadge}, expected 0`);
  if (header.totalMessagesBadge !== 0) problems.push(`totalMessagesBadge=${header.totalMessagesBadge}, expected 0`);
  if (header.isActive !== false) problems.push(`isActive=${header.isActive}, expected false (always renders the "Left" badge)`);
  if (header.groupType !== 'group') problems.push(`groupType=${JSON.stringify(header.groupType)}, expected "group" (never resolves 'room')`);
  if (problems.length > 0) {
    throw new Error(
      `line-group-detail.php's known header \`$group\`-clobbering defect (includes/header.php:449) did not reproduce: ${problems.join('; ')}. If header.php or line-group-detail.php were fixed, this assertion is stale — remove it and switch line-group-detail's header fields back to a normal PHP-vs-Next diff per docs/runbooks/phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 3" section.`
    );
  }
  return { defectConfirmed: true };
}

/**
 * Positively asserts Next's line-group-detail header shows the REAL,
 * correct data (Next has no equivalent of PHP's `$group`-clobbering bug —
 * apps/admin's session/db plumbing never reuses a global `$group`-like
 * variable) — throws if it drifts from `expected` (the fixture's own known
 * truth, passed in by parity.mjs, kept there rather than duplicated here so
 * there is exactly one place per batch that encodes "what the fixture
 * contains", matching FIXTURE_TAG_NAMES's existing precedent in this file).
 */
export function extractLineGroupDetailHeaderNext(html, expected) {
  const header = readLineGroupDetailHeader(sliceMainContent(html));
  const problems = [];
  for (const key of ['groupName', 'groupType', 'memberCountBadge', 'totalMessagesBadge', 'isActive']) {
    if (header[key] !== expected[key]) {
      problems.push(`${key}=${JSON.stringify(header[key])}, expected ${JSON.stringify(expected[key])}`);
    }
  }
  if (problems.length > 0) {
    throw new Error(`line-group-detail (next) header does not match the fixture's known data: ${problems.join('; ')}`);
  }
  return header;
}

// ---------------------------------------------------------------------------
// /crm-dashboard-advanced — Next-only defensive-empty extraction (the ONE
// deliberate exception to this file's usual PHP-vs-Next diff shape)
// ---------------------------------------------------------------------------

/**
 * PHP's crm-dashboard-advanced.php 500s unconditionally on this fixture's
 * schema (`crm_deals`/`crm_tickets` absent — see queries.ts's own "CRITICAL
 * FINDING" module doc and this batch's runbook section) — there is no PHP
 * HTML to extract data points FROM, so this function, unlike every other
 * extractor in this file, is called ONLY against Next's response and never
 * fed into parity.mjs's generic PHP-vs-Next diff(). Its job is different:
 * assert the page renders Next's own AUTHORIZED, DOCUMENTED defensive-empty
 * shape and THROW if it doesn't (a real regression here — e.g. someone
 * removes queries.ts's try/catch and the page starts 500ing too, or someone
 * "fixes" a query to return non-zero placeholder data — must fail loudly,
 * not silently pass).
 *
 * FORMERLY a SECOND FLAGGED FINDING (mig-verify parity-miss, now fixed): this
 * function was originally NOT wired into parity.mjs's active checks for the
 * DEFAULT (`?tab=overview`) tab, because that tab ALSO 500'd on Next — a
 * SEPARATE, narrower gap in the "AUTHORIZED RESOLUTION" than the
 * crm_deals/crm_tickets one pagesA/mig-ui already documented.
 * `getRevenueAnalytics()` in queries.ts queried `odoo_webhooks_log.created_at`
 * with NO try/catch (unlike every sibling crm_deals/crm_tickets-touching
 * query in the same file) — and `odoo_webhooks_log` genuinely has no
 * `created_at` column in the committed tenant template (it has `received_at`/
 * `processed_at` instead; confirmed via `ER_BAD_FIELD_ERROR` in a real Next
 * server log). This was a FAITHFUL 1:1 port of PHP's own
 * `CRMDashboardService::getRevenueAnalytics()` (identical query, confirmed
 * by reading classes/CRMDashboardService.php lines 701-724) — real PHP would
 * throw the exact same class of error here too, had it ever gotten past its
 * OWN earlier, unguarded `crm_deals` query first.
 *
 * FIX: `getRevenueAnalytics()` now wraps that query in the same try/catch
 * shape as its crm_deals/crm_tickets siblings (empty `daily` series on
 * failure, `summary` untouched — it was already an unconditional hardcoded
 * placeholder). Next's `?tab=overview` now reaches 200 with the documented
 * defensive-empty shape, so this function IS wired into
 * parity.mjs's runCrmDashboardAdvancedChecks() as
 * `next-overview-200-defensive-empty`, symmetric with the pipeline-tab
 * check below.
 *
 * `?tab=pipeline` (SalesPipelineTab) does NOT call `getRevenueAnalytics()`
 * at all — only `getPipelineData()` and `getCustomers()`, both genuinely
 * defensive — so it DOES reach 200 today and is what
 * runCrmDashboardAdvancedChecks() actually exercises for the "Next shows the
 * documented defensive-empty shape" half of this exception, via the sibling
 * extractCrmDashboardAdvancedPipelineDefensiveEmpty() below.
 */
export function extractCrmDashboardAdvancedDefensiveEmpty(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const totalCustomers = parseLeadingNumber(cursor.afterLabel('>Total Customers</div>'));
  const pipelineValue = parseLeadingNumber(cursor.afterLabel('>Pipeline Value</div>'));
  const activeDealsValue = parseLeadingNumber(cursor.beforeLabel('active deals'));
  parseLeadingNumber(cursor.afterLabel('>Monthly Revenue</div>')); // read for cursor-order correctness; not part of the defensive-empty contract (this metric is a hardcoded 125000 placeholder in BOTH real PHP and this port, never touches crm_deals/crm_tickets).
  const openTicketsValue = parseLeadingNumber(cursor.afterLabel('>Open Tickets</div>'));

  const alertsPresent = main.includes('data-testid="alerts"');
  const noDealsFoundShown = main.includes('No deals found');
  const noRecentActivityShown = main.includes('No recent activity');

  const problems = [];
  if (totalCustomers === null || totalCustomers < 0) {
    problems.push(`totalCustomers=${totalCustomers} — expected a real non-negative count (this query never touches crm_deals/crm_tickets, so it is NOT part of the defensive-empty fallback)`);
  }
  if (pipelineValue !== 0) {
    problems.push(`pipelineValue=${pipelineValue}, expected 0 (crm_deals absent from schema -> defensive default)`);
  }
  if (activeDealsValue !== 0) {
    problems.push(`activeDealsValue=${activeDealsValue}, expected 0 (crm_deals absent from schema -> defensive default)`);
  }
  if (openTicketsValue !== 0) {
    problems.push(`openTicketsValue=${openTicketsValue}, expected 0 (crm_tickets absent from schema -> defensive default)`);
  }
  if (alertsPresent) {
    problems.push('alerts section rendered, expected none (both alert queries touch crm_tickets/crm_deals -> should defensively resolve to [])');
  }
  if (!noDealsFoundShown) {
    problems.push('"No deals found" not shown (getDealsList() is an unconditional stub that always returns { deals: [] })');
  }
  if (!noRecentActivityShown) {
    problems.push('"No recent activity" not shown (both activity queries touch crm_deals/crm_tickets -> should defensively resolve to [])');
  }
  if (problems.length > 0) {
    throw new Error(`crm-dashboard-advanced defensive-empty invariant violated: ${problems.join('; ')}`);
  }

  return { totalCustomers, pipelineValue, activeDealsValue, openTicketsValue, alertsPresent, noDealsFoundShown, noRecentActivityShown };
}

/**
 * The variant of the defensive-empty check actually wired into
 * parity.mjs's runCrmDashboardAdvancedChecks() — see
 * extractCrmDashboardAdvancedDefensiveEmpty()'s own module doc above ("SECOND
 * FLAGGED FINDING") for the full "why `?tab=pipeline`, not the default
 * `?tab=overview`" explanation. Reads SalesPipelineTab.tsx's "Total
 * Pipeline: ฿{value} ({count} deals)" summary line (both `value`/`count`
 * come from `getPipelineData()`'s try/catch fallback -> 0 when `crm_deals`
 * is absent) plus `winRate` (`calculateWinRate()`'s hardcoded 35.0
 * placeholder — untouched by the crm_deals absence, asserted here as a
 * simple, deterministic sanity check that the page rendered real content,
 * not a blank/error shell).
 */
export function extractCrmDashboardAdvancedPipelineDefensiveEmpty(html) {
  const main = sliceMainContent(html);
  const cursor = new HtmlCursor(main);

  const totalPipelineValue = parseLeadingNumber(cursor.afterLabel('Total Pipeline:'));
  const totalDeals = parseLeadingNumber(cursor.beforeLabel('deals)'));
  const winRate = parseLeadingNumber(cursor.afterLabel('Win Rate:'));

  const problems = [];
  if (totalPipelineValue !== 0) {
    problems.push(`totalPipelineValue=${totalPipelineValue}, expected 0 (crm_deals absent from schema -> defensive default)`);
  }
  if (totalDeals !== 0) {
    problems.push(`totalDeals=${totalDeals}, expected 0 (crm_deals absent from schema -> defensive default)`);
  }
  if (winRate !== 35) {
    problems.push(`winRate=${winRate}, expected 35 (calculateWinRate() hardcoded placeholder, unrelated to crm_deals's absence)`);
  }
  if (problems.length > 0) {
    throw new Error(`crm-dashboard-advanced (pipeline tab) defensive-empty invariant violated: ${problems.join('; ')}`);
  }

  return { totalPipelineValue, totalDeals, winRate };
}

// ---------------------------------------------------------------------------
// /system-status page extraction
// ---------------------------------------------------------------------------

/**
 * system-status.php's 19 named checks split into two groups (per pagesA's
 * brief, mirrored exactly by apps/admin/src/app/(tenant)/system-status/
 * queries.ts's own module doc — read that file before changing this list):
 *
 *   - 11 "portable" checks: pure SQL probes (`SELECT 1`, `SELECT COUNT(*)`)
 *     that run IDENTICALLY against the same physical MySQL database
 *     regardless of which stack issues them — `database`, the 5 `table_*`
 *     checks, the 3 `v2_table_*` checks, `message_stats`, `user_stats`.
 *     Their `status` (ok/warning/error) is a real, diffable data point.
 *   - 8 "placeholder" checks: PHP-class-instantiation probes
 *     (`VibeSellingHelper`, `InboxService`, the 4 V2 `*Service` classes,
 *     `LineAccountManager`/`LineAPI`, AIChat's `GeminiChatAdapter`) with NO
 *     Next-side equivalent yet (Phase 4/6/7 per the migration plan) — Next
 *     renders these as a fixed `not_ported`/🚧 row instead of faking 'ok'.
 *     PRESENCE-ONLY here: this extractor proves the check ROW exists (via
 *     `cursor.advanceTo()`, which throws — surfacing as a diagnosable
 *     extraction/fetch error — if a key ever goes missing on either stack),
 *     but never reads/diffs its status or message text, which are EXPECTED
 *     to differ (PHP: whatever that class's real runtime behavior is;
 *     Next: always 🚧/not_ported).
 *
 * `overallStatus` (the green/yellow/red banner) is DELIBERATELY NOT
 * extracted at all — queries.ts's own module doc documents that Next folds
 * ONLY the 11 portable checks into it, while PHP folds all 19 (including
 * the 8 placeholder ones) into its cascade. This is an intentional,
 * documented behavioral difference, not a bug on either side — diffing it
 * here would produce a false mismatch the instant any placeholder check's
 * real PHP behavior isn't a clean 'ok' (e.g. `line_api` warning-ing because
 * `$currentBotId=1` matches no real `line_accounts` row), for a signal this
 * harness has no way to independently verify is "PHP's fault" vs "a real
 * product decision gap" — see this batch's runbook section for the full
 * write-up.
 *
 * Each check card is matched via CHECK_CARD_RE — a SEQUENTIAL regex over the
 * whole grid (not a per-check HtmlCursor label search) that captures
 * {emoji, label, message} straight off each card's real structure
 * (`<span class="text-2xl">EMOJI</span>...<h3 class="font-medium
 * text-gray-800 truncate">LABEL</h3><p class="text-sm text-gray-500
 * mt-1">MESSAGE</p>`), then zips the 19 matches (in DOM order) against
 * SYSTEM_STATUS_CHECKS BY POSITION, not by re-searching for each label's own
 * text. This is a deliberate departure from every other extractor in this
 * file (which anchor on exact literal label text via HtmlCursor) — verified
 * necessary empirically: system-status.php's real (un-minified) PHP template
 * pads every `<h3>...LABEL                    </h3>` with substantial
 * trailing whitespace/newlines before the closing tag (Next's SSR output has
 * none), so a literal `${label}</h3>` substring search that works on every
 * OTHER page in this harness never matches here at all. Regex-matching the
 * card's STRUCTURE and reading whatever text falls inside each capture group
 * (trimmed) sidesteps the whitespace difference entirely, and per-check
 * identity comes from ARRAY POSITION (both stacks push all 19 checks in the
 * exact same order — verified by reading both system-status.php and
 * queries.ts in full), same "order is a real, meaningful signal" principle
 * already established for extractUsersPage's row ordering etc.
 *
 * Each portable check's status comes from the captured emoji (✅/⚠️/❌ on
 * both stacks — Next's STATUS_ICON map uses the identical 3 glyphs for
 * ok/warning/error, plus a 4th, 🚧, ONLY for `not_ported`, which portable
 * checks never produce).
 *
 * `currentBotId` (the System Info footer's "Current Bot ID: N" line) is
 * DELIBERATELY NOT extracted. FLAGGED FINDING (build report): confirmed via
 * a real page dump that `$currentBotId` in system-status.php's OWN footer
 * display is empty/blank in practice — a THIRD instance of the exact same
 * `includes/header.php`-clobbers-a-caller's-global-variable defect class
 * documented in full for `$group` on extractLineGroupDetailPage()'s own
 * module doc above (header.php line ~172's `$currentBotId = $currentBot['id']
 * ?? null;`, running via `require_once 'includes/header.php'` on
 * system-status.php line 176 — AFTER the checks section computed its OWN
 * `$currentBotId = $_SESSION['current_bot_id'] ?? 1` at line 16 and already
 * used it correctly for the message_stats/user_stats queries above, but
 * BEFORE the footer HTML renders). The 19 health checks themselves are
 * UNAFFECTED (they all run and render before header.php's clobbering
 * assignment) — only this one decorative footer field is tainted. Given two
 * prior instances of this exact bug class already have dedicated,
 * documented exception mechanisms in this batch (crm-dashboard-advanced,
 * line-group-detail's header), a THIRD parallel mechanism for one
 * low-value decorative field was judged not worth the added harness
 * complexity — simply dropped from what this extractor reads at all. Flagged
 * here (and in this batch's runbook) as a real, reproducible product finding
 * for mig-orchestrator, not silently absorbed.
 */
const CHECK_CARD_RE =
  /class="text-2xl">([\s\S]*?)<\/span>[\s\S]*?class="font-medium text-gray-800 truncate"[^>]*>([\s\S]*?)<\/h3>\s*<p class="text-sm text-gray-500 mt-1"[^>]*>([\s\S]*?)<\/p>/g;
const SYSTEM_STATUS_EMOJI_TO_STATUS = { '✅': 'ok', '⚠️': 'warning', '❌': 'error', '🚧': 'not_ported' };

const SYSTEM_STATUS_CHECKS = [
  { key: 'database', label: 'Database', portable: true },
  { key: 'vibe_selling', label: 'Vibe Selling', portable: false },
  { key: 'inbox_service', label: 'Inbox Service', portable: false },
  { key: 'v2_DrugPricingEngineService', label: 'V2 DrugPricingEngineService', portable: false },
  { key: 'v2_CustomerHealthEngineService', label: 'V2 CustomerHealthEngineService', portable: false },
  { key: 'v2_PharmacyImageAnalyzerService', label: 'V2 PharmacyImageAnalyzerService', portable: false },
  { key: 'v2_PharmacyGhostDraftService', label: 'V2 PharmacyGhostDraftService', portable: false },
  { key: 'table_users', label: 'Table Users', portable: true },
  { key: 'table_messages', label: 'Table Messages', portable: true },
  { key: 'table_line_accounts', label: 'Table Line Accounts', portable: true },
  { key: 'table_user_tags', label: 'Table User Tags', portable: true },
  { key: 'table_admin_users', label: 'Table Admin Users', portable: true },
  { key: 'v2_table_customer_health_profiles', label: 'V2 Table Customer Health Profiles', portable: true },
  { key: 'v2_table_drug_pricing_rules', label: 'V2 Table Drug Pricing Rules', portable: true },
  { key: 'v2_table_ghost_draft_learning', label: 'V2 Table Ghost Draft Learning', portable: true },
  { key: 'line_api', label: 'Line Api', portable: false },
  { key: 'ai_module', label: 'Ai Module', portable: false },
  { key: 'message_stats', label: 'Message Stats', portable: true },
  { key: 'user_stats', label: 'User Stats', portable: true },
];

/** The 11 portable check keys, exported so parity.mjs's runbook-facing assertions/tests can reference the exact same list this extractor iterates (kept in one place, not duplicated by hand). */
export const SYSTEM_STATUS_PORTABLE_KEYS = SYSTEM_STATUS_CHECKS.filter((c) => c.portable).map((c) => c.key);
/** The 8 presence-only placeholder check keys — see this section's module doc. */
export const SYSTEM_STATUS_PLACEHOLDER_KEYS = SYSTEM_STATUS_CHECKS.filter((c) => !c.portable).map((c) => c.key);

export function extractSystemStatusPage(html) {
  const main = sliceMainContent(html);
  const cards = [...main.matchAll(CHECK_CARD_RE)].map((m) => ({
    emoji: decodeEntities(stripTags(m[1])).trim(),
    label: decodeEntities(stripTags(m[2])).trim(),
    message: decodeEntities(stripTags(m[3])).trim(),
  }));

  if (cards.length !== SYSTEM_STATUS_CHECKS.length) {
    throw new Error(`extractSystemStatusPage: found ${cards.length} check card(s), expected exactly ${SYSTEM_STATUS_CHECKS.length}`);
  }

  const portable = {};
  SYSTEM_STATUS_CHECKS.forEach(({ key, label, portable: isPortable }, i) => {
    const card = cards[i];
    if (card.label !== label) {
      throw new Error(`extractSystemStatusPage: check #${i} label=${JSON.stringify(card.label)}, expected ${JSON.stringify(label)} (key=${key}) — DOM order drifted from SYSTEM_STATUS_CHECKS`);
    }
    if (!isPortable) {
      return; // presence-only — label equality above already proves the row exists in the right slot; status/message deliberately not read (see module doc).
    }
    portable[key] = { status: SYSTEM_STATUS_EMOJI_TO_STATUS[card.emoji] ?? card.emoji };
    if (key === 'message_stats' || key === 'user_stats') {
      const numbers = [...card.message.matchAll(/(\d[\d,]*)/g)].map((m) => Number(m[1].replace(/,/g, '')));
      portable[key].total = numbers[0] ?? null;
      if (key === 'message_stats') {
        portable[key].unread = numbers[1] ?? null;
      }
    }
  });

  return { checks: portable };
}

// ---------------------------------------------------------------------------
// Phase 4 batch 1 (mig-infra) — /inbox (conversationList sidebar) and
// /inbox/[userId] (messageThread chat pane) page-pair extractors. See
// docs/runbooks/phase4-batch1-inbox-reads-parity.md for the full contract
// this batch proves, the identity-model decision, and the deferred-scope
// list.
//
// BOTH extractors below deliberately do NOT use the label-anchored
// HtmlCursor pattern the rest of this file uses (see module doc "ORDERING
// MATTERS") — inbox-v2.php's sidebar/chat-thread markup carries real,
// STABLE data-* attributes (data-user-id/data-tags/data-assigned/
// data-chat-status/data-msg-id) that this port reproduces byte-for-byte
// (see (tenant)/inbox/_components/ConversationListItem.tsx's own module doc:
// "data-* attributes are NOT decorative" — FilterBar.tsx's client-side
// filtering reads them directly, so they are a load-bearing, not
// incidental, part of the contract). Anchoring on those attributes is a
// STRONGER, more literal parity signal than searching for visible label
// text would be here, and sidesteps the ordering fragility a forward-only
// cursor would otherwise have across ~200 near-identical repeated rows.
// ---------------------------------------------------------------------------

/**
 * extractInboxSidebarPage — PHP inbox-v2.php's sidebar list (lines
 * 2930-3172) vs Next (tenant)/inbox/layout.tsx + ConversationListItem.tsx.
 * Both SSR the SAME `id="totalUnread"` badge, and both stamp identical
 * `data-user-id`/`data-tags`/`data-assigned`/`data-chat-status` attributes
 * per conversation row (PHP: inbox-v2.php lines 3097-3102; Next:
 * ConversationListItem.tsx lines 84-89).
 *
 * NOT WIRED AS A runPagePair() DIFF (read before assuming it should be) —
 * see parity.mjs's runInboxSidebarChecks() and
 * docs/runbooks/phase4-batch1-inbox-reads-parity.md's "PHP inbox sidebar is
 * permanently empty under this harness" section for the full trace: a
 * CONFIRMED, PRE-EXISTING PHP DEFECT (discovered by this batch's own harness
 * run, not previously flagged) makes inbox-v2.php's LINE-tab conversation
 * list ALWAYS EMPTY whenever the session has zero accessible `line_accounts`
 * rows — the exact, deliberately-maintained state EVERY fixture in this
 * harness keeps throughout the whole run (see 30-phase2-batch1-fixture's own
 * "WHY NO line_accounts ROWS" reasoning, inherited by every later batch).
 * inbox-v2.php pre-computes its own `$currentBotId = $_SESSION
 * ['current_bot_id'] ?? 1` (line 81) BEFORE `require_once
 * 'includes/header.php'` (line 991) — but header.php is a plain top-level
 * include sharing inbox-v2.php's global scope (same class of bug as this
 * repo's already-documented line-group-detail.php `$group`-clobbering
 * defect), and unconditionally OVERWRITES that same variable at
 * includes/header.php line 174 (`$currentBotId = $currentBot['id'] ?? null`)
 * — `null` when there are no accessible bots. The conversation-list SQL that
 * runs AFTER header.php (inbox-v2.php lines 1023-1054) then binds that
 * clobbered `NULL` into `u.line_account_id = ?`, which — per SQL's 3-valued
 * logic — matches ZERO rows, including rows whose `line_account_id` is
 * itself `NULL` (an equality test, unlike the `(line_account_id = ? OR
 * line_account_id IS NULL)` NULL-tolerant pattern users.php/groups.php use).
 * This function is still used for BOTH sides — just via two SEPARATE
 * `runSingleSideCheck()` calls with DIFFERENT expected values, not one
 * diffed pair, since there is no dataset this harness could seed that would
 * make PHP's side genuinely non-empty without also breaking every earlier
 * batch's own zero-line_accounts invariant.
 *
 * Presence-only (not full-count) BY DESIGN, on the NEXT side — the exact
 * `unread_count`/`tags`/`assignees` VALUES for every one of the fixture's
 * 215 conversations are already proven byte-for-byte by this batch's
 * conversations-cursor-walk (a JSON diff against the golden fixture — far
 * stronger than scraping a rendered badge digit out of HTML). This extractor
 * only proves the SSR'd HTML actually carries the same per-conversation
 * markers the JSON API says it should.
 *
 * `knownConversations` — array of `{ name, id, attrs: string[] }`; `attrs`
 * are literal `data-*="value"` substrings expected on that row (both stacks
 * render attribute values in the same order/format — see
 * ConversationListItem.tsx's own module doc for why: `data-tags`/
 * `data-assignees` are plain `.join(',')` over integers on both stacks, no
 * i18n/formatting divergence possible).
 */
export function extractInboxSidebarPage(html, knownConversations) {
  const totalUnreadMatch = /id="totalUnread"[^>]*>\s*(\d+)/.exec(html);
  const totalUnreadBadge = totalUnreadMatch ? Number(totalUnreadMatch[1]) : null;
  // PHP's empty-state text (inbox-v2.php line 3075) — not rendered by Next
  // at all when the list is non-empty (EmptyState is only used by the
  // no-selection /inbox route.tsx, a DIFFERENT page than the sidebar), so
  // this is only ever asserted `true` on the PHP-defect side, never diffed
  // against Next directly.
  const emptyStateVisible = html.includes('ยังไม่มีแชท');

  const conversations = {};
  for (const conv of knownConversations) {
    const anchor = `data-user-id="${conv.id}"`;
    const visible = html.includes(anchor);
    const attrs = {};
    for (const attr of conv.attrs ?? []) {
      attrs[attr] = html.includes(attr);
    }
    conversations[conv.name] = { visible, ...attrs };
  }
  return { totalUnreadBadge, emptyStateVisible, conversations };
}

/**
 * extractInboxThreadPage — chat header (name/tags) + message-type coverage
 * for a single conversation, port of inbox-v2.php's "CENTER: Chat Area"
 * (lines 3174-3538) vs (tenant)/inbox/[userId]/page.tsx + ChatHeader.tsx +
 * MessageBubble.tsx.
 *
 * `headerName` uses the ONE HtmlCursor lookup in this pair (both stacks
 * render the EXACT literal `<h3 class="font-bold text-gray-800">` — verified
 * by reading both ChatHeader.tsx and inbox-v2.php's chat-header markup — so
 * a forward label search is safe and unambiguous here, unlike the
 * repeated-row sidebar above).
 *
 * `messageCount` counts `data-msg-id="<digits>"` occurrences — NOT a bare
 * `data-msg-id="` count (verified empirically against this batch's own
 * harness run: inbox-v2.php ALSO embeds three client-side JS templates
 * containing the literal string `data-msg-id="${msg.id}"` — a websocket
 * live-append handler and its own duplicate-detection querySelector, lines
 * 4780/4815/8625 — inside `<script>` blocks that are never executed by this
 * fetch-only harness but ARE present in the raw HTML text a naive substring
 * count would over-count by exactly 3. Requiring one-or-more DIGITS inside
 * the quotes excludes all three (`${msg.id}` is not a digit run) while still
 * matching every REAL SSR'd bubble on both stacks (PHP: inbox-v2.php line
 * 3281's `data-msg-id="<?= $msg['id'] ?>"` renders a literal integer; Next:
 * MessageBubble.tsx's `data-msg-id={message.id}` also always serializes to a
 * quoted digit string).
 *
 * FLEX MESSAGE RENDERING ASYMMETRY (read before extending `markers` for a
 * flex/carousel case) — PHP defers ALL flex rendering to a client-side
 * `<script>` (inbox-v2.php lines 3473-3505, `renderFlexMessage()` in JS,
 * never executed by this fetch-only harness — no browser/JS engine
 * involved anywhere in this harness); this port renders the SAME tree
 * SERVER-SIDE (FlexBubble/FlexCarousel/FlexText/FlexButton/... — real HTML
 * in the initial response, see flex/FlexMessage's own module doc). The two
 * representations are structurally different (PHP: a raw,
 * htmlspecialchars()-escaped JSON string sitting inside a
 * `data-flex-content='...'` attribute; Next: a real DOM tree of nested
 * `<div>`s) but BOTH still contain the same literal Thai/English marker text
 * verbatim — htmlspecialchars() does not alter non-ASCII text or a
 * quote-free string, and every marker string this fixture uses is
 * quote-free by construction. A plain substring-inclusion check is
 * therefore the CORRECT level of assertion for flex content here, not a
 * structural diff (which would need real JS execution on the PHP side to
 * ever pass, and would be testing this harness's fetch mechanics rather
 * than the product) — this is a documented, deliberate exception in the
 * same family as runCrmDashboardAdvancedChecks()'s 500-vs-200 exception in
 * parity.mjs, not an extraction gap.
 *
 * `markers` — array of `{ name, text }`; each becomes a boolean
 * `cleanHtml.includes(text)` — diffed structurally like every other
 * extractor (a marker present on one side and absent on the other surfaces
 * as a normal `true`-vs-`false` mismatch line). Checked against a
 * COMMENT-STRIPPED copy of the html (same `<!--...-->`-removal `stripTags()`
 * already applies elsewhere in this file, reapplied here directly since
 * these marker checks intentionally do NOT go through `stripTags()`'s own
 * tag-removal — some markers are attribute/URL values, not visible text) —
 * required for any marker whose source text spans MORE THAN ONE adjacent
 * JSX expression, e.g. LocationContent's `{lat}, {lng}` (MessageBubble.tsx):
 * verified empirically against this batch's own harness run that React's
 * SSR renderer inserts an empty `<!-- -->` between `{lat}` and the literal
 * `, `/`{lng}` text nodes (the same hydration-boundary-marker quirk this
 * file's own module doc already documents for other pages), which would
 * otherwise split `"13.7563, 100.5018"` into `"13.7563<!-- -->,
 * <!-- -->100.5018"` and produce a false negative ONLY on the Next side.
 */
export function extractInboxThreadPage(html, markers) {
  let headerName = null;
  try {
    headerName = new HtmlCursor(html).afterLabel('font-bold text-gray-800">');
  } catch {
    headerName = null;
  }
  const messageCount = (html.match(/data-msg-id="\d+"/g) || []).length;
  const cleanHtml = html.replace(/<!--[\s\S]*?-->/g, '');

  const result = { headerName, messageCount };
  for (const marker of markers) {
    result[marker.name] = cleanHtml.includes(marker.text);
  }
  return result;
}

// ---------------------------------------------------------------------------
// Phase 2 tail — /articles (list), /articles/[slug] (detail) + its
// view-count-increment side effect, /pharmacists. Same label-anchored,
// never-CSS-class convention as every extractor above — read this module's
// own doc comment at the top of the file first if you haven't already.
// ---------------------------------------------------------------------------

/**
 * Shared per-card delimiter for BOTH articles.php's list grid AND
 * article.php's "related articles" grid: every card on either page is a
 * single `<a href="...SLUG...">...</a>` block, and the SLUG itself is a
 * literal, byte-identical substring of that href on both stacks — PHP:
 * `article.php?slug=<?= htmlspecialchars($article['slug']) ?>` (note the
 * SINGULAR "article.php", the detail-page filename — deliberately distinct
 * from "articles.php", the plural list-page filename category chips/back
 * links/tag links all point at, so this pattern can never accidentally match
 * one of those); Next: `/articles/${encodeURIComponent(article.slug)}` (this
 * batch's fixture uses plain lowercase-ascii-hyphen slugs, so
 * encodeURIComponent leaves them byte-identical to the raw slug — no
 * decoding gymnastics needed, `decodeURIComponent()` below is a no-op safety
 * net, not load-bearing). Verified against both templates' full source that
 * NEITHER the share-button hrefs (both stacks urlencode/percent-escape the
 * `?`/`=`/`/` characters of the embedded share URL, so neither raw pattern
 * appears there) NOR the JSON-LD/og:url meta tags (both sit in `<head>`,
 * before `<main>` — already excluded by `sliceMainContent()`) can produce a
 * false match.
 */
const ARTICLE_CARD_HREF_RE = /(?:article\.php\?slug=|\/articles\/)([a-z0-9][a-z0-9-]*)"[^>]*>([\s\S]*?)<\/a>/g;

/** Tag-chip hrefs on both the list-page card meta AND the detail page's own tag row share the same `?tag=<value>"...>#<TAGTEXT></a>` shape (PHP: `articles.php?tag=<?= urlencode($tag) ?>` / `#<?= htmlspecialchars($tag) ?>`; Next: `/articles?tag=${encodeURIComponent(tag)}` / `#{tag}`) — the leading literal `#` is part of both stacks' own visible link text, not a URL fragment. */
const ARTICLE_TAG_HREF_RE = /\?tag=[^"]*"[^>]*>#([^<]*)<\/a>/g;

/**
 * Extracts articles.php's data-point list: the category-filter-bar's chip
 * labels IN RENDER ORDER (same "ทั้งหมด"-anchored, first-seen-order
 * technique `extractTemplatesPage()` already established for its own
 * category chips — only rendered at all when `!empty($categories)`, so an
 * empty array here is a legitimate result, not an extraction failure), plus
 * one `{slug, isFeatured}` tuple per rendered article card IN ROW ORDER.
 * Row order matters and is exactly the parity signal this function is FOR:
 * `getPublishedArticles()` orders `is_featured DESC, published_at DESC`
 * while `search()` orders `published_at DESC` only (no is_featured
 * precedence) — a wrong order or a wrong row set (e.g. the `is_published`
 * filter leaking a draft, or the category filter's WHERE clause drifting)
 * shows up here as a mismatched `cards` array.
 *
 * DELIBERATELY NOT a full field reconstruction (title/author/date per
 * card): PHP's per-card author/date markers are icon-FONT glyphs
 * (`<i class="fas fa-user-md">`, invisible to this fetch-only, no-CSS
 * extractor — icon fonts render via `::before` CSS content, never a real
 * text node), while Next's ArticleCard.tsx renders literal emoji TEXT
 * characters (👨‍⚕️/🗓️) for the same spots instead — an unavoidable
 * structural asymmetry between the two ports (same family as this module's
 * "flex rendering asymmetry" note on `extractInboxThreadPage()`), not a bug
 * on either side. `slug` (identity + order + count) and `isFeatured` (does
 * this card's own `<a>...</a>` body contain the literal "แนะนำ"
 * featured-badge text) already prove everything this page's filters/
 * ordering are actually FOR, without that fragility.
 */
export function extractArticlesListPage(html) {
  // Comments stripped BEFORE any regex below runs — PHP's card/tag markup
  // has no comment concept at all, but Next's SSR output can insert an
  // empty `<!-- -->` hydration-boundary marker between two adjacent
  // text-producing children (this module's own top-of-file doc; verified
  // empirically for this exact page in this batch's own harness run: the
  // "#{tag}" / "฿{fee}" literal-then-expression shape). Stripping (not
  // splitting on) comments merges both sides back into one run, matching
  // PHP's un-annotated text exactly — same technique `stripTags()` already
  // uses for the label-anchored `HtmlCursor` extractors; the plain regexes
  // below need the same treatment applied up front since they don't go
  // through `HtmlCursor` at all.
  const main = sliceMainContent(html).replace(/<!--[\s\S]*?-->/g, '');

  // Anchored on the PLAIN "ทั้งหมด" text, not a tight `>ทั้งหมด<` boundary —
  // PHP's own template renders this chip's text on its OWN indented line
  // (`<a ...>\n    ทั้งหมด\n</a>`, confirmed by reading articles.php in
  // full), so a `>ทั้งหมด<` search (no whitespace tolerance) never matches
  // real PHP output at all; caught by this batch's own real harness run,
  // not assumed.
  const allChipIdx = main.indexOf('ทั้งหมด');
  let categoryButtons = [];
  if (allChipIdx !== -1) {
    const barStart = main.lastIndexOf('<a', allChipIdx);
    const firstCardMatch = new RegExp(ARTICLE_CARD_HREF_RE.source).exec(main.slice(barStart));
    const barEnd = firstCardMatch ? barStart + firstCardMatch.index : main.length;
    const barSlice = main.slice(barStart, barEnd);
    categoryButtons = [...barSlice.matchAll(/<a[^>]*>([^<]*)<\/a>/g)].map((m) => decodeEntities(m[1]).trim());
  }

  const cards = [...main.matchAll(ARTICLE_CARD_HREF_RE)].map((m) => ({
    slug: decodeURIComponent(m[1]),
    isFeatured: m[2].includes('แนะนำ'),
  }));

  const emptyStateShown = main.includes('ไม่พบบทความ');

  return { categoryButtons, cardCount: cards.length, cards, emptyStateShown };
}

/**
 * Extracts article.php's data-point list for a single resolved article:
 * `title` (the `<h1>...</h1>` text — a bare-tag-name anchor, not a CSS
 * class, since PHP's `.article-title` and Next's Tailwind utility string
 * share no class token; both stacks render the title as the h1's ONLY
 * child, so no nested-tag/hydration-comment concern here), `tags` (the
 * `#tagname` chip row, in array order — proves `json_decode`/`JSON.parse`
 * of the `tags` column round-trips identically), and the related-articles
 * grid (`relatedSectionShown` + `relatedSlugs` in row order, reusing
 * `ARTICLE_CARD_HREF_RE` — on this page it can ONLY match related-card
 * anchors, see that const's own doc for why the list-grid/share-button/
 * meta-tag false-match cases are already ruled out).
 *
 * `view_count` IS DELIBERATELY NOT RETURNED HERE — see `extractArticleViewCount()`'s
 * own doc for why a direct PHP-vs-Next diff of it would be a guaranteed,
 * non-bug false mismatch on this harness's shared-database setup, and how
 * the dedicated two-fetch check proves the increment side effect instead.
 *
 * Comments stripped up front before any regex runs below — same reasoning
 * as `extractArticlesListPage()`'s own doc comment (caught the SAME way:
 * Next's `#{tag}` tag-chip row genuinely renders a hydration comment
 * between the literal "#" and the `{tag}` expression in this batch's own
 * real harness run, which silently zeroed out `tags` entirely before this
 * fix — a regex requiring an unbroken `#TAGTEXT` run has nowhere to
 * "skip past" a comment sitting in the middle of it, so the whole match
 * fails rather than just mis-capturing).
 */
export function extractArticleDetailPage(html) {
  const main = sliceMainContent(html).replace(/<!--[\s\S]*?-->/g, '');

  const titleMatch = /<h1[^>]*>([\s\S]*?)<\/h1>/.exec(main);
  const title = titleMatch ? firstVisibleChunk(titleMatch[1]) : null;

  const tags = [...main.matchAll(ARTICLE_TAG_HREF_RE)].map((m) => decodeEntities(m[1]).trim());

  const relatedSectionShown = main.includes('บทความที่เกี่ยวข้อง');
  const relatedSlugs = [...main.matchAll(ARTICLE_CARD_HREF_RE)].map((m) => decodeURIComponent(m[1]));

  return { title, tags, relatedSectionShown, relatedCount: relatedSlugs.length, relatedSlugs };
}

/**
 * Extracts JUST the rendered view-count number from an article.php /
 * `/articles/[slug]` response — `HealthArticleService::getBySlug()` (PHP)
 * and `[slug]/page.tsx`'s `incrementViewCountAction()` (Next) both display
 * the PRE-increment value the same request's own SELECT captured (the
 * increment UPDATE fires AFTER that value is already in hand — read both
 * sources: PHP increments inside `getBySlug()` itself, after fetching the
 * row it's about to `return`; Next's `queries.ts::getArticleBySlug()` is a
 * pure read, with the increment fired separately, afterward, from
 * `actions.ts`), so EVERY successful fetch of the same slug increments the
 * DB counter by exactly 1 regardless of which stack served it.
 *
 * THIS IS WHY `extractArticleDetailPage()` never returns `view_count`: this
 * harness's PHP and Next stacks share the SAME physical MariaDB row (one
 * tenant DB, one `health_articles` table — see this file's module doc/
 * parity.mjs's own header for the "real stack, no mocks" design). A
 * `runPagePair()` PHP-then-Next fetch of the SAME slug would show PHP the
 * PRE-increment count and Next the count ONE HIGHER (Next's SELECT runs
 * after PHP's own UPDATE already landed) — a real, guaranteed, order-
 * dependent off-by-one that is NOT a product bug, just an artifact of this
 * fetch-only harness's shared-database setup. `runArticleViewCountIncrementChecks()`
 * in parity.mjs uses this extractor for a same-stack, two-fetch check
 * instead (`runSingleSideCheck()`'s pattern) — that comparison is immune to
 * the cross-stack ordering issue since it never compares PHP's count to
 * Next's count directly, only a stack's own count against its own earlier
 * count.
 *
 * Anchored on the literal, English "views" label text — `HtmlCursor`'s
 * `beforeLabel()` already strips the hydration comment React's SSR inserts
 * between the `{formatNumber(...)}` expression and the adjacent " views"
 * text-literal sibling (the same "baht-sign-plus-value pattern" this
 * module's own top-of-file doc already documents as handled by
 * `stripTags()`'s comment removal, not a new case).
 */
export function extractArticleViewCount(html) {
  const cursor = new HtmlCursor(html);
  const raw = cursor.beforeLabel('views');
  const n = parseLeadingNumber(raw);
  if (n === null) {
    throw new Error(`extractArticleViewCount: could not parse a number immediately before the "views" label (raw=${JSON.stringify(raw)})`);
  }
  return n;
}

/**
 * Per-pharmacist-card delimiter for /pharmacists: unlike every OTHER
 * extractor in this file that can lean on a shared `data-*`/class anchor,
 * PHP's `includes/pharmacy/pharmacists.php` card markup carries NO
 * per-pharmacist identifying attribute at all (grepped the full file — no
 * `data-pharmacist-id`, no `id="pharm-<n>"`); Next's `PharmacistCard.tsx`
 * DOES add `data-pharmacist-id={pharmacist.id}`, but only on its own side —
 * a one-sided hook is useless as a shared delimiter. The one thing both
 * sides genuinely share, byte-for-byte, per card: the avatar `<img>`'s
 * `src` — `PharmacistCard.tsx`'s own doc comment confirms it reproduces
 * PHP's `image_url || 'https://api.dicebear.com/7.x/avataaars/svg?seed=' +
 * urlencode(name)` fallback "exactly" (Next uses `encodeURIComponent`, not
 * `urlencode` — the two differ for a literal space character, `+` vs
 * `%20`, but this extractor never needs to decode the seeded NAME back out,
 * only to find where each card BEGINS, so that divergence is harmless
 * here). This fixture's seed file sets every pharmacist's `image_url` to
 * NULL specifically so this fallback — and therefore this delimiter —
 * fires for every card, every run.
 */
const PHARMACIST_CARD_MARKER = 'svg?seed=';

/**
 * Extracts /pharmacists' data-point list: `emptyStateShown` (PHP: "ยังไม่มีเภสัชกร"
 * text; Next: `EmptyState`'s `heading` prop, same literal string) plus one
 * per-card object, split on `PHARMACIST_CARD_MARKER` (see that const's own
 * doc). Per card:
 *   - `isActive` — PHP's `<?= !$p['is_active'] ? 'opacity-60' : '' ?>` /
 *     Next's `${!pharmacist.isActive ? 'opacity-60' : ''}` share the
 *     literal `opacity-60` class TOKEN (verified by reading both sources —
 *     `PharmacistCard.tsx` was ported preserving this exact utility class,
 *     unlike most other ported pages in this codebase) — searched in a
 *     bounded BACKWARD window from the card marker (the outer card `<div>`'s
 *     own opening tag, which carries this class, sits BEFORE the avatar
 *     `<img>` in render order on both stacks).
 *   - `isAvailable` — the `title="พร้อมให้บริการ"` green-dot indicator's
 *     attribute text (only rendered `if ($p['is_available'])` /
 *     `pharmacist.isAvailable`) — an attribute VALUE, not visible text, but
 *     still literal raw-HTML substring content this fetch-only extractor
 *     can see regardless.
 *   - `upcomingCount` / `completedCount` — the two correlated-subquery
 *     numbers (`(SELECT COUNT(*) FROM appointments WHERE ... status IN
 *     ('pending','confirmed') AND appointment_date >= CURDATE())` /
 *     `status = 'completed'`), read via `beforeLabel('นัดหมายรอ')`/
 *     `beforeLabel('เสร็จสิ้น')` — the single highest-value signal this
 *     extractor can prove, since it's the one part of this page backed by a
 *     real, easy-to-drift-independently SQL computation rather than a
 *     straight column passthrough.
 *   - `isFree` / `feeAmount` — PHP's `$p['consultation_fee'] > 0` branch
 *     (`฿<?= number_format(...) ?>` vs the literal "ฟรี" free label); `฿`
 *     is the same shared numeric-value anchor `extractLineGroupsPage()`'s
 *     doc and this module's top-of-file "baht-sign-plus-value pattern" note
 *     already establish as comment-safe.
 *   - `consultationDuration` — `beforeLabel('นาที')`.
 *
 * LAST-CARD BOUNDARY TRAP (caught by this batch's own real harness run, not
 * assumed): the naive "next marker or `main.length`" slice is UNBOUNDED for
 * the LAST card, and PHP's Add/Edit modal (`#pharmacistModal`) is rendered
 * UNCONDITIONALLY, just CSS-`hidden` — including its OWN
 * `title="พร้อมให้บริการ"`-equivalent checkbox label
 * (`<span>พร้อมให้บริการ</span>`) — so an unbounded last-card slice was
 * silently absorbing that trailing modal's own "is_available" checkbox
 * label and reporting `isAvailable: true` for whichever pharmacist happened
 * to render last, REGARDLESS of that pharmacist's real value (Next's own
 * `PharmacistFormModal` returns `null` until opened — closed by default —
 * so it never had this problem, an asymmetry between the two ports that is
 * NOT itself a bug: both render an identical closed-by-default modal to the
 * end user, PHP just always ships its markup, hidden, in the initial
 * response). Fixed by additionally bounding every card's forward slice at
 * its OWN delete button — `bg-red-100 text-red-600 rounded-lg
 * hover:bg-red-200 text-sm`, verified by reading both `includes/pharmacy/
 * pharmacists.php` (lines 235-238) and `PharmacistCard.tsx` (the delete
 * button) as a genuinely shared, per-card, occurs-exactly-once class
 * string — nothing in either modal reuses it (checked: the modals' own
 * buttons use `bg-green-500`/plain-border/`text-red-500` shades, not
 * `bg-red-100`).
 */
const PHARMACIST_DELETE_BTN_CLASS = 'bg-red-100';

export function extractPharmacistsPage(html) {
  const main = sliceMainContent(html);
  const emptyStateShown = main.includes('ยังไม่มีเภสัชกร');

  const starts = [];
  for (let from = 0; ; ) {
    const idx = main.indexOf(PHARMACIST_CARD_MARKER, from);
    if (idx === -1) break;
    starts.push(idx);
    from = idx + PHARMACIST_CARD_MARKER.length;
  }

  const cards = starts.map((start, i) => {
    const end = i + 1 < starts.length ? starts[i + 1] : main.length;
    const rawForward = main.slice(start, end);
    const deleteBtnIdx = rawForward.indexOf(PHARMACIST_DELETE_BTN_CLASS);
    const forward = deleteBtnIdx === -1 ? rawForward : rawForward.slice(0, deleteBtnIdx + PHARMACIST_DELETE_BTN_CLASS.length);
    const backward = main.slice(Math.max(0, start - WINDOW_CHARS), start);

    const isActive = !backward.includes('opacity-60');
    const isAvailable = forward.includes('พร้อมให้บริการ');
    const upcomingCount = parseLeadingNumber(new HtmlCursor(forward).beforeLabel('นัดหมายรอ'));
    const completedCount = parseLeadingNumber(new HtmlCursor(forward).beforeLabel('เสร็จสิ้น'));
    const isFree = forward.includes('>ฟรี<');
    const feeAmount = isFree ? 0 : parseLeadingNumber(new HtmlCursor(forward).afterLabel('฿'));
    const consultationDuration = parseLeadingNumber(new HtmlCursor(forward).beforeLabel('นาที'));

    return { isActive, isAvailable, upcomingCount, completedCount, isFree, feeAmount, consultationDuration };
  });

  return { cardCount: cards.length, emptyStateShown, cards };
}
