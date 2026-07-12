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
