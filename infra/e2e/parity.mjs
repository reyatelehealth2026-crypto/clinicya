#!/usr/bin/env node
// infra/e2e/parity.mjs
//
// Phase 2 batch 1 (mig-infra) — data-point parity harness for the three
// page-pairs this batch ports: PHP /users (LINE tab) vs Next /users; PHP
// /user-detail.php?id=N vs Next /user-detail?id=N; PHP /dashboard?tab=
// executive|crm vs Next /dashboard?tab=executive|crm.
//
// Phase 2 batch 2 (mig-infra) EXTENDS this same harness (same file, same
// process, same single JSON-line output — not a second script) with THREE
// more page-pairs: PHP /analytics?tab={overview|advanced|crm|account} vs
// Next /analytics?tab=...; PHP /activity-logs.php vs Next /activity-logs;
// PHP /loyalty-members.php vs Next /loyalty-members. Every batch-2 page/
// filter-combo list below follows the exact same top-level-array +
// runPagePair()-per-entry shape batch-1 already established (USERS_FILTER_
// COMBOS / USER_DETAIL_IDS / DASHBOARD_TABS) — see docs/runbooks/
// phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 2" section for
// the full write-up of what's new.
//
// Phase 2 batch 3 (mig-infra) EXTENDS this same harness AGAIN with SIX more
// page-pairs: PHP /templates.php vs Next /templates; PHP /groups.php?view=N
// vs Next /groups?view=N; PHP /line-groups.php vs Next /line-groups; PHP
// /line-group-detail.php?id=N vs Next /line-group-detail?id=N; PHP
// /crm-dashboard-advanced.php vs Next /crm-dashboard-advanced (a DELIBERATE
// exception to the usual PHP-vs-Next diff shape — see
// runCrmDashboardAdvancedChecks() below); PHP /system-status.php vs Next
// /system-status (an 11-portable/8-placeholder split — see
// extractSystemStatusPage()'s own doc in lib/extract.mjs). Same top-level-
// array + runPagePair()-per-entry shape as every batch before it. See
// docs/runbooks/phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 3"
// section for the full write-up of what's new, including the two decisions
// made jointly with mig-ui (the $currentBotId/no-line_accounts invariant
// reuse for groups.php/line-groups.php, and the crm-dashboard-advanced
// 500-vs-200 exception mechanism).
//
// Phase 4 batch 1 (mig-infra) EXTENDS this same harness AGAIN — same file,
// same process, same single JSON-line output — with the read-only /inbox
// surface (conversationList's /api/inbox/conversations + /api/inbox/messages
// Route Handlers; messageThread's /inbox + /inbox/[userId] pages): TWO new
// JSON cursor-pagination contract walks (runConversationsCursorWalk(),
// runMessagesCursorWalk() — neither has a PHP side to diff against, since
// they test the NEW Next-only Route Handlers' own pagination contract, not a
// PHP-vs-Next comparison) PLUS one ordinary runPagePair() page-pair entry
// (inbox-thread:id=7001) PLUS runInboxSidebarChecks() (`/inbox` itself — a
// DELIBERATE EXCEPTION to the usual PHP-vs-Next diff shape, same family as
// runCrmDashboardAdvancedChecks(): a confirmed, pre-existing PHP defect
// (discovered by this batch's own harness run) makes inbox-v2.php's
// conversation list permanently empty under this harness's own
// zero-line_accounts invariant — see that function's own doc), all using the
// SAME identity model (tenant Host header + session cookie) every entry
// above already uses — deliberately NOT api-parity.mjs's unauthenticated
// root-domain model, since /inbox is tenant-scoped and admin-session-gated
// exactly like /users, not line_account_id-resolved like /api/miniapp. See
// docs/runbooks/phase4-batch1-inbox-reads-parity.md for the full contract,
// the identity-model rationale (written up there so a later inbox-actions
// batch doesn't have to re-litigate it), the golden-dataset fixture's shape,
// and this batch's explicit deferred-scope list.
//
// Phase 2 tail (mig-infra) EXTENDS this same harness AGAIN — same file, same
// process, same single JSON-line output — with the two new URL surfaces this
// round's page-builder agents own: PHP /articles.php (list) + /article.php?slug=X
// (detail) vs Next /articles + /articles/[slug] (articlesCms brief — a URL-SHAPE
// change, the two top-level PHP files fold into one nested Next route tree);
// PHP /pharmacy.php?tab=pharmacists vs Next /pharmacists (pharmacistsDirectory
// brief — the Next port sources from the LIVE tab partial
// includes/pharmacy/pharmacists.php, not the dead 301-redirect stub at the
// repo-root pharmacists.php). Same top-level-array + runPagePair()-per-entry
// shape as every batch before it, PLUS one dedicated two-fetch
// runSingleSideCheck() pair proving article.php's/[slug]/page.tsx's
// view-count-increment side effect (see runArticleViewCountIncrementChecks()'s
// own doc for why this is NOT an ordinary runPagePair() diff). See
// docs/runbooks/phase2-batch1-users-dashboard-parity.md's "Phase 2 tail"
// section for the full write-up, including the ACCESS-MODEL DEVIATION flagged
// for mig-orchestrator (articles.php/article.php are public/unauthenticated in
// PHP; the Next port sits behind (tenant)'s session gate) — an open item this
// round's placeholder routes.json entries do NOT resolve, only document.
//
// Phase 2 settings batch 1 (mig-infra) EXTENDS this same harness AGAIN with
// FOUR new page-pairs: PHP /settings.php?tab=welcome vs Next /settings?tab=
// welcome; PHP /settings.php?tab=email vs Next /settings?tab=email (a
// DELIBERATE one-sided-assertion EXCEPTION, same family as
// runCrmDashboardAdvancedChecks()/runInboxSidebarChecks() — see
// runSettingsEmailChecks() below); PHP /settings.php?tab=consent vs Next
// /settings?tab=consent; PHP /settings.php?tab=shop_tax vs Next /settings?
// tab=shop_tax. Root /settings.php (941 LOC — NOT the dead, unreferenced
// 562-LOC duplicate at includes/settings/settings.php, zero includes/
// requires anywhere in the repo, confirmed by grep) is the live hub; only
// 4 of its 7 live-whitelisted tabs (line/platform/general/shop_tax/welcome/
// notifications/consent) are ported this batch — see
// infra/nginx/routes.json's own `/settings` entry note and docs/runbooks/
// phase2-settings-tabs-batch1-parity.md for the full write-up, including
// TWO confirmed, still-live findings this batch's fixture/extractors work
// around rather than "fix" (welcome_settings table absent from the
// committed schema; PHP's own `?tab=email` is unreachable via its `$tabs`
// whitelist, always falling back to the LINE tab's markup instead), PLUS
// ONE finding that started as a real, confirmed FAIL during this harness's
// own development (a one-field mismatch in apps/admin's shop-tax default
// value, `default_vat_rate`) and was fixed by settingsConsentTax before
// this batch's final acceptance run — see extractSettingsShopTaxTab()'s own
// doc in lib/extract.mjs for the confirmed-fixed write-up.
//
// SCOPE (read before trusting the PASS line — same documented-limits
// pattern infra/e2e/run.mjs already uses): this proves DATA-POINT PARITY
// against ONE seeded tenant/dataset, on the REAL stack (a genuine MariaDB +
// Redis + php:8.2-apache container set, a genuine `next build` +
// `next start` standalone server) — it is NOT a live-traffic shadow test,
// does NOT prove parity across every tenant's real production data, and
// does NOT gate on pixel/HTML equality (see infra/e2e/lib/extract.mjs's
// module doc for why: it diffs a small EXTRACTION LIST of data points per
// page, never raw HTML). See docs/runbooks/phase2-batch1-users-dashboard-
// parity.md for the full write-up, the exact extraction lists, and how
// mig-orchestrator flips infra/nginx/routes.json's upstream once this batch's
// pages have ramped through canary.
//
// Single command to run this harness:
//   node infra/e2e/parity.mjs
//
// Reuses infra/e2e/docker-compose.yml (mariadb+redis+php — Phase 1 batch 3,
// UNMODIFIED) and infra/e2e/lib/harness-common.mjs (compose lifecycle,
// secrets, SQL exec — shared with run.mjs, see that module's header
// comment) for the PHP side, then additionally builds + starts apps/admin's
// `next build` standalone server as a plain host child process (no Docker
// image for apps/admin exists yet — that's a later phase) wired to the SAME
// MariaDB/Redis via the same 127.0.0.1 host ports run.mjs already
// documents the "port gotcha" for.
//
// Because infra/e2e/docker-compose.yml's container names/ports are fixed
// (not templated per-project), this harness and run.mjs CANNOT run
// concurrently — same pre-existing constraint run.mjs already has with
// itself. Sequential use only; mig-verify's own run order already reflects
// this (this brief's acceptance runs LAST among the three batch-1 briefs).
//
// The Next side is fetched via a REAL `next build` + `next start
// --standalone`-equivalent server — never mocked, never stubbed. If a page
// this batch depends on doesn't exist yet (apps/admin/** is owned by the
// two concurrent page-porting agents, not this one), that page's fetch
// fails loudly with a diagnosable step/mismatch — see runPagePair() below —
// rather than being silently skipped or faked.
//
// Always tears down (docker compose down -v + kill the Next child process)
// in a finally block, even on a thrown error.

import { spawn } from 'node:child_process';
import { cpSync, existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  REPO_ROOT,
  HarnessError,
  createStepTracker,
  run,
  makeComposeArgs,
  composeUp,
  composeDown,
  waitContainerHealthy,
  execSql,
  querySql,
  parseLocalConfigPhp,
  generateSecrets,
  generatePhpBcryptHash,
  waitHttpReachable,
  httpRequest,
} from './lib/harness-common.mjs';
import {
  extractUsersPage,
  extractUserDetailPage,
  extractExecutiveDashboard,
  extractCrmDashboard,
  extractActivityLogsPage,
  extractLoyaltyMembersPage,
  extractAnalyticsOverview,
  extractAnalyticsAdvanced,
  extractAnalyticsCrm,
  extractAnalyticsAccount,
  extractTemplatesPage,
  extractGroupsPage,
  extractLineGroupsPage,
  extractLineGroupDetailPage,
  extractLineGroupDetailHeaderPhpDefect,
  extractLineGroupDetailHeaderNext,
  extractCrmDashboardAdvancedDefensiveEmpty,
  extractCrmDashboardAdvancedPipelineDefensiveEmpty,
  extractSystemStatusPage,
  extractInboxSidebarPage,
  extractInboxThreadPage,
  extractArticlesListPage,
  extractArticleDetailPage,
  extractArticleViewCount,
  extractPharmacistsPage,
  extractSettingsWelcomeTab,
  extractSettingsEmailPhpFallback,
  extractSettingsEmailTab,
  extractSettingsConsentTab,
  extractSettingsShopTaxTab,
} from './lib/extract.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-parity';

// ---------------------------------------------------------------------------
// Host-side addresses — see docker-compose.yml's port comments / run.mjs's
// "port gotcha" note. This harness's Node process, AND the Next standalone
// server it spawns, both run on the HOST (not inside the compose network),
// so both reach MariaDB/Redis/PHP the same way run.mjs's Node runner does.
// ---------------------------------------------------------------------------
const MARIADB_HOST = '127.0.0.1';
const REDIS_HOST_URL = 'redis://127.0.0.1:16379';
const PHP_BASE_URL = 'http://127.0.0.1:18092';
const NEXT_PORT = 3210;
const NEXT_BASE_URL = `http://127.0.0.1:${NEXT_PORT}`;
const PROBE_PATH = '/system-status.php'; // see infra/e2e/probe-page.md — same sequencing-trap probe run.mjs uses.

const MASTER_DB_NAME = 'zrismpsz_reya_platform';
const TENANT_SLUG = 'e2e-parity-harness';
const TENANT_HOST = `${TENANT_SLUG}.re-ya.com`; // Host header for EVERY page/login request on BOTH stacks — see module doc.
const ADMIN_USERNAME = 'e2e_parity_admin';

const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';
// Applied in order — batch 2's fixture is additive on top of batch 1's, and
// batch 3's is additive on top of both (same tenant DB, same
// seedDatabase() call), never a replacement. See
// infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's and
// infra/e2e/seed/60-phase2-batch3-fixture.sql.tmpl's own header comments for
// why each is a new numbered file rather than an append to the previous one.
const FIXTURE_FILES = [
  '30-phase2-batch1-fixture.sql.tmpl',
  '40-phase2-batch2-fixture.sql.tmpl',
  '60-phase2-batch3-fixture.sql.tmpl',
  '70-phase4-batch1-inbox-fixture.sql.tmpl',
  '75-phase2-tail-articles-pharmacists-fixture.sql.tmpl',
  '75-phase2-settings-batch1-fixture.sql.tmpl',
];

const ADMIN_DIR = path.join(REPO_ROOT, 'apps/admin');
// Computed by buildAdmin() below (not a fixed path) — see that function's
// own comment for why: Next's `output: 'standalone'` bundle layout depends
// on its own inferred *workspace* root, not just this repo's root.
let ADMIN_STANDALONE_DIR = path.join(ADMIN_DIR, '.next/standalone/apps/admin');

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;
const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

// ---------------------------------------------------------------------------
// PAGE LIST — config-driven per the brief ("the two page agents are building
// concurrently — your runner must not assume their pages exist while you
// develop; make the page list config-driven so mig-verify runs it after all
// builds land"). Nothing below hardcodes a page's existence; every entry is
// just fetch-and-extract instructions, run through runPagePair()'s uniform
// try/catch so a missing/broken page fails loudly as ITS OWN entry, never
// aborting the other entries or the harness as a whole.
// ---------------------------------------------------------------------------

const USERS_FILTER_COMBOS = [
  { name: 'baseline', qs: '' },
  { name: 'search', qs: 'search=Sakura' },
  { name: 'tag', qs: 'tag=1' },
  { name: 'tier', qs: 'tier=gold' },
  { name: 'points', qs: 'points=500-1000' },
  { name: 'activity', qs: 'activity=inactive' },
  { name: 'purchase-purchased', qs: 'purchase=purchased' },
  { name: 'purchase-never', qs: 'purchase=never' },
  { name: 'status', qs: 'status=blocked' },
];

// user 1 = rich (tags + points_transactions + transactions + video call),
// user 2 = medium (1 tag, 1 transaction, points_transactions earn-only),
// user 11 = empty/new (no tags, no transactions in either table, no
// points_transactions, no loyalty_points row) — see the fixture file's
// per-user comments (infra/e2e/seed/30-phase2-batch1-fixture.sql.tmpl).
const USER_DETAIL_IDS = [1, 2, 11];

const DASHBOARD_TABS = ['executive', 'crm'];

// ---------------------------------------------------------------------------
// Phase 2 batch 2 page/filter configs — same "top-level array, looped with
// runPagePair()" shape as the batch-1 configs above (config-driven per the
// brief: mig-verify runs this harness only after both mig-ui page agents
// have landed, so nothing here may assume a page already exists while this
// file is being developed — runPagePair()'s own try/catch is what makes a
// still-missing route fail as its own {ok:false} entry instead of aborting).
// ---------------------------------------------------------------------------

const BANGKOK_TZ = 'Asia/Bangkok';

/** 'YYYY-MM-DD' for (now + offsetDays) in Asia/Bangkok — mirrors apps/admin's analytics/_lib/period.ts's own daysAgoInBangkok() helper, reimplemented here (not imported — this file has no dependency on apps/admin's source, only on its BUILT OUTPUT via HTTP, same as every other extraction in this harness). */
function bangkokDateString(offsetDays, now = new Date()) {
  const shifted = new Date(now.getTime() + offsetDays * 86_400_000);
  return new Intl.DateTimeFormat('en-CA', { timeZone: BANGKOK_TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(shifted);
}

/**
 * Every activity-logs combo below applies a `date_to` bound of "yesterday"
 * (Asia/Bangkok) — see infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's
 * activity_logs section header comment for why: classes/AdminAuth.php's
 * login() writes a log_type='auth'/action='login' row to `activity_logs` on
 * every PHP login (this harness's own `phpLogin()` triggers it), but Next's
 * `internal/session-bridge.php` 'login-sync' path does not — a genuine,
 * dated-"today" backend asymmetry that would otherwise make PHP's totalLogs
 * exactly 1 higher than Next's on every combo. Bounding date_to to
 * yesterday excludes that row on both stacks while including every
 * fixture-seeded row (all dated 2-21 days ago, never "today").
 */
function activityLogsDateBounds(now = new Date()) {
  return { from: bangkokDateString(-25, now), to: bangkokDateString(-1, now) };
}
function activityLogsNarrowBounds(now = new Date()) {
  return { from: bangkokDateString(-12, now), to: bangkokDateString(-6, now) };
}

/**
 * One entry per activity-logs.php filter branch actually read from
 * classes/ActivityLogger.php / queries.ts's buildWhere()/countLogs()
 * (type, action, search, date_from/date_to) plus pagination — see
 * infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's own comments for which
 * fixture rows each combo is expected to intersect. `qs(bounds, narrow)` is
 * a function (not a static string) because the date bound is computed once
 * per run from the real wall clock, never hardcoded.
 */
const ACTIVITY_LOGS_COMBOS = [
  { name: 'baseline', qs: (b) => `date_from=${b.from}&date_to=${b.to}` },
  { name: 'type', qs: (b) => `type=pharmacy&date_from=${b.from}&date_to=${b.to}` },
  { name: 'action', qs: (b) => `action=login&date_from=${b.from}&date_to=${b.to}` },
  { name: 'search', qs: (b) => `search=BATCH2SEARCHMARK&date_from=${b.from}&date_to=${b.to}` },
  { name: 'date-range', qs: (_b, n) => `date_from=${n.from}&date_to=${n.to}` },
  { name: 'combined', qs: (b) => `type=consent&action=login&date_from=${b.from}&date_to=${b.to}` },
  { name: 'page2', qs: (b) => `date_from=${b.from}&date_to=${b.to}&page=2` },
];

/** loyalty-members.php's only filter is `?q=` (search). Both combos assert the SAME empty-state parity in this harness — see extract.mjs's extractLoyaltyMembersPage doc for why (no `line_accounts` rows seeded, so `lineAccountId`/`currentBotId` is always 0 on both stacks and the underlying query never runs). */
const LOYALTY_MEMBERS_SEARCHES = [
  { name: 'baseline', qs: '' },
  { name: 'search', qs: 'q=ทดสอบ' },
];

/** analytics.php's 4 tabs — each fetched with an explicit `?tab=` on both stacks (same convention DASHBOARD_TABS above already uses). */
const ANALYTICS_TABS = [
  { name: 'overview', qs: 'tab=overview', extractPhp: extractAnalyticsOverview, extractNext: extractAnalyticsOverview },
  { name: 'advanced', qs: 'tab=advanced', extractPhp: extractAnalyticsAdvanced, extractNext: extractAnalyticsAdvanced },
  { name: 'crm', qs: 'tab=crm', extractPhp: extractAnalyticsCrm, extractNext: extractAnalyticsCrm },
  { name: 'account', qs: 'tab=account', extractPhp: extractAnalyticsAccount, extractNext: extractAnalyticsAccount },
];

// ---------------------------------------------------------------------------
// Phase 2 batch 3 page/filter configs — same "top-level array, looped with
// runPagePair()" shape as every config above. See
// infra/e2e/seed/60-phase2-batch3-fixture.sql.tmpl for the exact fixture
// rows each combo below exercises.
// ---------------------------------------------------------------------------

/** templates.php has no query-param filters at all (its category filter is 100% client-side JS, both on the PHP page and in TemplatesClient.tsx) — one baseline fetch is the whole surface. */
const TEMPLATES_VARIANTS = [{ name: 'baseline', qs: '' }];

/**
 * groups.php's only query param is `?view=<group id>`. `view-empty` (group
 * id 1) and `view-members` (group id 2) are BOTH real, distinct detail-panel
 * states from 60-phase2-batch3-fixture.sql.tmpl (an empty group and a
 * >=2-member group respectively) — not the same state fetched twice.
 */
const GROUPS_VARIANTS = [
  { name: 'baseline', qs: '' },
  { name: 'view-empty', qs: 'view=1' },
  { name: 'view-members', qs: 'view=2' },
];

/** line-groups.php has no query-param filters (its actions are all POST-only mutations, out of this read-only harness's scope) — one baseline fetch. */
const LINE_GROUPS_VARIANTS = [{ name: 'baseline', qs: '' }];

/**
 * line-group-detail.php's only param is `?id=<line_groups id>`. id=1 is the
 * ACTIVE group with seeded members/messages (mixed is_active, a non-'text'
 * message, a >100-char truncated message); id=2 is the INACTIVE/left group
 * with NO members/messages rows, exercising both empty-state branches — see
 * 60-phase2-batch3-fixture.sql.tmpl's own per-table comments.
 */
const LINE_GROUP_DETAIL_IDS = [1, 2];

/**
 * FLAGGED FINDING (discovered by this batch's own harness run, not by
 * pagesB — see extractLineGroupDetailPage()'s module doc in
 * infra/e2e/lib/extract.mjs for the full root-cause trace):
 * line-group-detail.php's group HEADER is permanently broken in real
 * production — `includes/header.php`'s own `foreach ($menuGroups as
 * $group)` (line 449, no `unset()` afterward) clobbers line-group-detail.php's
 * own fetched `$group` row in the shared global scope, since both files are
 * plain top-level includes, not functions. Every request to this page shows
 * "Unknown Group" / 0 members / 0 messages / the "Left" badge / "Group"
 * (never "Room"), regardless of the real group. This is 100% independent of
 * the requested id — verified against BOTH a real active group (id 1) AND a
 * real inactive one (id 2) in this fixture, both rendering the identical
 * broken output. The MEMBERS/MESSAGES panels are unaffected (keyed off the
 * plain scalar `$groupId`, never touched by the collision) and remain a
 * normal PHP-vs-Next diff via the `line-group-detail:id=N` entries below.
 *
 * The header itself is proven via TWO separate, positively-asserting,
 * single-stack checks per id (mirroring runCrmDashboardAdvancedChecks()'s
 * precedent) rather than a diff — diffing Next's genuinely-correct header
 * against PHP's genuinely-broken one would just look like "Next is wrong"
 * and bury the real finding. This lookup table is the ONE place this
 * batch's fixture's real header values are encoded (same "kept in one
 * place, not duplicated" precedent as FIXTURE_TAG_NAMES in extract.mjs) —
 * used only by the Next-side assertion; the PHP-side assertion needs no
 * fixture data at all (the defect is the same broken constant regardless of
 * id).
 */
const LINE_GROUP_DETAIL_EXPECTED_HEADER = {
  1: { groupName: 'กลุ่มร้านขายยา A', groupType: 'group', memberCountBadge: 3, totalMessagesBadge: 42, isActive: true },
  2: { groupName: 'ห้องสนทนาลูกค้า B', groupType: 'room', memberCountBadge: 1, totalMessagesBadge: 5, isActive: false },
};

// ---------------------------------------------------------------------------
// Phase 4 batch 1 config — golden-dataset constants mirrored from
// infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl's own "GOLDEN
// DATASET CONSTANTS" footer comment (keep both in sync if that file's shape
// ever changes) plus the marker lists the HTML/JSON extractors below check.
// ---------------------------------------------------------------------------

const INBOX_HERO_ID = 7001;
const INBOX_HERO_TOTAL_MESSAGES = 130;
const INBOX_TAGGED_ID = 7002;
const INBOX_ASSIGNED_STATUS_ID = 7003;
const INBOX_MULTI_ASSIGNEE_ID = 7004;
const INBOX_PENDING_STATUS_ID = 7005;
const INBOX_UNREAD_ID = 7006;
const INBOX_FILLER_START = 7007;
const INBOX_FILLER_END = 7215;
const INBOX_TOTAL_CONVERSATIONS = 1 + 5 + (INBOX_FILLER_END - INBOX_FILLER_START + 1); // 215

/** Sidebar page-pair (inbox:baseline) — which conversations to spot-check and which literal data-* attribute(s) each must carry. See extractInboxSidebarPage()'s own module doc in lib/extract.mjs for why data-* attributes, not visible text. */
const INBOX_SIDEBAR_KNOWN_CONVERSATIONS = [
  { name: 'hero', id: INBOX_HERO_ID, attrs: [] },
  { name: 'tagged', id: INBOX_TAGGED_ID, attrs: ['data-tags="1"'] },
  { name: 'multiAssignee', id: INBOX_MULTI_ASSIGNEE_ID, attrs: ['data-assigned="1"'] },
  { name: 'pendingStatus', id: INBOX_PENDING_STATUS_ID, attrs: ['data-chat-status="pending"'] },
];

/** Thread page-pair (inbox-thread:id=7001) — literal marker substrings expected somewhere in BOTH stacks' raw HTML for HERO's 13 marked messages. See extractInboxThreadPage()'s own module doc for the flex-rendering-asymmetry rationale these markers rely on. Kept in the exact same string form the fixture generator used (infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl) — not re-derived here. */
const INBOX_THREAD_HTML_MARKERS = [
  { name: 'plainText', text: 'INBOXB1-PLAINTEXT-001' },
  { name: 'quickReplyText', text: 'INBOXB1-QRTEXT-002' },
  { name: 'quickReplyLabel1', text: 'INBOXB1-QRLABEL-ดูสินค้า' },
  { name: 'quickReplyLabel2', text: 'INBOXB1-QRLABEL-ติดต่อเรา' },
  { name: 'textAsVideoSrc', text: 'api/line_content.php?id=/uploads/line_videos/inboxb1-demo-clip.mp4' },
  { name: 'imageIdSrc', text: 'api/line_content.php?id=778899' },
  { name: 'imageAbsoluteSrc', text: 'https://picsum.photos/seed/inboxb1demo/400/300' },
  { name: 'stickerJsonSrc', text: 'https://stickershop.line-scdn.net/stickershop/v1/sticker/52002734/android/sticker.png' },
  { name: 'stickerLegacySrc', text: 'https://stickershop.line-scdn.net/stickershop/v1/sticker/183892/android/sticker.png' },
  { name: 'flexBubbleText', text: 'INBOXB1-FLEXBUBBLE-สินค้าแนะนำวันนี้' },
  { name: 'carouselBubble1Text', text: 'INBOXB1-CAROUSEL-BUBBLE1-พาราเซตามอล' },
  { name: 'carouselBubble2Text', text: 'INBOXB1-CAROUSEL-BUBBLE2-วิตามินซี' },
  { name: 'carouselBtn1Label', text: 'INBOXB1-CAROUSEL-BTN1-สั่งซื้อ' },
  { name: 'carouselBtn2Label', text: 'INBOXB1-CAROUSEL-BTN2-เพิ่มลงตะกร้า' },
  { name: 'fileNameMarker', text: 'INBOXB1-FILE-ใบรับรองยา.pdf' },
  { name: 'videoAbsoluteSrc', text: 'https://example-media.invalid/videos/inboxb1-clip2.mp4' },
  { name: 'audioIdSrc', text: 'api/line_content.php?id=990011' },
  { name: 'locationAddress', text: 'INBOXB1-LOCATION-ร้านขายยาทดสอบ กรุงเทพฯ' },
  { name: 'locationLatLng', text: '13.7563, 100.5018' },
];

/** GET /api/inbox/messages?user_id=7001 cursor walk — one predicate per marked message this batch's brief requires coverage of (13 total — the CLAUDE.md-list's two gaps, location + text-as-video, included). Operates on the raw JSON row (message_type/content), NOT on rendered HTML — this is the Next-only Route Handler contract walk, not a page-pair diff. */
const INBOX_MESSAGE_TYPE_CHECKS = [
  { name: 'plainText', match: (m) => m.message_type === 'text' && m.content.includes('INBOXB1-PLAINTEXT-001') },
  { name: 'quickReplyText', match: (m) => m.message_type === 'text' && m.content.includes('INBOXB1-QRTEXT-002') },
  { name: 'textAsVideo', match: (m) => m.message_type === 'text' && m.content.includes('/uploads/line_videos/inboxb1-demo-clip.mp4') },
  { name: 'imageIdForm', match: (m) => m.message_type === 'image' && m.content === 'ID:778899' },
  { name: 'imageAbsoluteForm', match: (m) => m.message_type === 'image' && m.content.startsWith('https://picsum.photos/seed/inboxb1demo/') },
  { name: 'stickerJsonForm', match: (m) => m.message_type === 'sticker' && m.content.includes('"stickerId":"52002734"') },
  { name: 'stickerLegacyForm', match: (m) => m.message_type === 'sticker' && m.content === 'Sticker: 183892' },
  { name: 'flexBubble', match: (m) => m.message_type === 'flex' && m.content.includes('INBOXB1-FLEXBUBBLE-') },
  {
    name: 'flexCarousel',
    match: (m) =>
      m.message_type === 'flex' &&
      m.content.includes('"type":"carousel"') &&
      m.content.includes('INBOXB1-CAROUSEL-BUBBLE1-') &&
      m.content.includes('INBOXB1-CAROUSEL-BUBBLE2-'),
  },
  { name: 'file', match: (m) => m.message_type === 'file' && m.content.includes('INBOXB1-FILE-') },
  { name: 'video', match: (m) => m.message_type === 'video' && m.content.includes('inboxb1-clip2.mp4') },
  { name: 'audio', match: (m) => m.message_type === 'audio' && m.content === 'ID:990011' },
  { name: 'location', match: (m) => m.message_type === 'location' && m.content.includes('INBOXB1-LOCATION-') },
];

// ---------------------------------------------------------------------------
// Phase 2 tail config — golden-dataset constants mirrored from
// infra/e2e/seed/75-phase2-tail-articles-pharmacists-fixture.sql.tmpl's own
// "GOLDEN DATASET CONSTANTS" footer comment (keep both in sync if that
// file's shape ever changes).
// ---------------------------------------------------------------------------

/** health_article_categories.id for 'โรคทั่วไปและการดูแลสุขภาพ' — 2 published articles (7601, 7602). */
const ARTICLE_CATEGORY_ID = 7501;
/** Literal marker embedded in ONLY health_articles.id=7602's excerpt — same "ASCII marker inside Thai text" technique ACTIVITY_LOGS_COMBOS's own search combo already established, avoiding any Thai-substring collision ambiguity. */
const ARTICLE_SEARCH_TERM = 'PHASE2TAILSEARCHMARK';
/** health_articles.slug for id=7601 — the featured, published article-detail:slug=... target (has tags + exactly one related-article match, id 7602). */
const ARTICLE_DETAIL_SLUG = 'phase2-tail-featured-article';

/**
 * /articles list variants — same "top-level array, looped with
 * runPagePair()" shape as TEMPLATES_VARIANTS/GROUPS_VARIANTS above. `qs` is
 * the SAME literal query string on both stacks (articles.php's `$_GET['category']`/
 * `$_GET['q']` and the Next port's `_lib/params.ts` read the identical param
 * names — confirmed by reading both) — unlike USERS_FILTER_COMBOS, no
 * per-stack qs translation is needed here.
 */
const ARTICLES_LIST_VARIANTS = [
  { name: 'baseline', qs: '' },
  { name: `category=${ARTICLE_CATEGORY_ID}`, qs: `category=${ARTICLE_CATEGORY_ID}` },
  { name: `search=${ARTICLE_SEARCH_TERM}`, qs: `q=${encodeURIComponent(ARTICLE_SEARCH_TERM)}` },
];

// ---------------------------------------------------------------------------
// Database seeding — mirrors run.mjs's seedDatabase()/seedAdminUser() shape
// (same shared helpers, see harness-common.mjs), PLUS this batch's own
// 15-plan-and-tenant.sql.tmpl (own tenant slug — see that file's header
// comment for why it's not a reuse of run.mjs's 10-*) and the two data-point
// fixtures in FIXTURE_FILES (30-phase2-batch1-fixture.sql.tmpl +
// 40-phase2-batch2-fixture.sql.tmpl, applied in order onto the SAME tenant DB).
// ---------------------------------------------------------------------------

function seedDatabase(env, secrets, dbCreds) {
  const rootPw = secrets.mariadbRootPassword;

  const masterDbSql = readFileSync(path.join(SEED_DIR, '00-master-db.sql'), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, masterDbSql, [], 'seed_master_db_create');
  execSql(
    tracker,
    composeArgs,
    env,
    rootPw,
    `GRANT ALL PRIVILEGES ON \`${MASTER_DB_NAME}\`.* TO '${dbCreds.user}'@'%'; FLUSH PRIVILEGES;`,
    [],
    'seed_master_db_grant'
  );
  markOk('seed_master_db_create');
  markOk('seed_master_db_grant');

  for (const relPath of MASTER_MIGRATIONS) {
    const content = readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    execSql(tracker, composeArgs, env, rootPw, `USE \`${MASTER_DB_NAME}\`;\n${content}`, [], 'seed_master_migrations');
  }
  markOk('seed_master_migrations', MASTER_MIGRATIONS);

  const appDbSql = readFileSync(path.join(SEED_DIR, '05-app-db.sql.tmpl'), 'utf8').replaceAll('__APP_DB_NAME__', dbCreds.name);
  execSql(tracker, composeArgs, env, rootPw, appDbSql, [], 'seed_app_db_create');
  const templateContent = readFileSync(path.join(REPO_ROOT, TENANT_TEMPLATE), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${templateContent}`, [], 'seed_app_db_template');
  markOk('seed_app_db_create');
  markOk('seed_app_db_template');

  const planTenantSql = readFileSync(path.join(SEED_DIR, '15-plan-and-tenant.sql.tmpl'), 'utf8').replaceAll(
    '__APP_DB_NAME__',
    dbCreds.name
  );
  execSql(tracker, composeArgs, env, rootPw, planTenantSql, [], 'seed_plan_and_tenant');
  markOk('seed_plan_and_tenant');

  for (const fixtureFile of FIXTURE_FILES) {
    const fixtureSql = readFileSync(path.join(SEED_DIR, fixtureFile), 'utf8');
    execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSql}`, [], 'seed_fixture');
  }
  markOk('seed_fixture', FIXTURE_FILES);

  const tenantId = querySql(
    tracker,
    composeArgs,
    env,
    rootPw,
    `SELECT id FROM tenants WHERE slug = '${TENANT_SLUG}' LIMIT 1;`,
    MASTER_DB_NAME,
    'seed_lookup_tenant_id'
  );
  if (!tenantId || Number.isNaN(Number(tenantId))) {
    fail('seed_lookup_tenant_id', 'Could not read back the seeded tenants.id', { raw: tenantId });
  }
  markOk('seed_lookup_tenant_id', tenantId);
  return Number(tenantId);
}

function seedAdminUser(env, secrets, dbCreds, passwordHash) {
  const rootPw = secrets.mariadbRootPassword;
  const escapedHash = passwordHash.replaceAll("'", "''");
  const sql = readFileSync(path.join(SEED_DIR, '20-admin-user.sql.tmpl'), 'utf8')
    .replaceAll('__ADMIN_USERNAME__', ADMIN_USERNAME)
    .replaceAll('__ADMIN_PASSWORD_HASH__', escapedHash);
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${sql}`, [], 'seed_admin_user');
  markOk('seed_admin_user');
}

/** Same sequencing-trap probe run.mjs uses — includes/auth_check.php's `new AdminAuth($db)` auto-creates admin_users/admin_bot_access/admin_activity_log on first hit. Unauthenticated, so also doubles as a control that the probe page gates on auth at all. */
async function fireThrowawayProbeRequest() {
  const resp = await httpRequest({ url: `${PHP_BASE_URL}${PROBE_PATH}`, headers: { Host: TENANT_HOST } });
  markOk('throwaway_probe_request', resp.status);
  return resp;
}

// ---------------------------------------------------------------------------
// apps/admin — real `next build` (always, for determinism — a stale
// standalone bundle left over from a previous checkout state would be a
// silent parity-harness footgun) + standalone server as a plain child
// process on the host.
// ---------------------------------------------------------------------------

/**
 * Finds the real `apps/admin/server.js` inside a `next build --output
 * standalone` bundle. Usually that's exactly `.next/standalone/apps/admin/
 * server.js` (ADMIN_STANDALONE_DIR's own default above) — but Next infers
 * its OWN workspace/turbopack root by walking UP from cwd looking for a
 * lockfile, and when this repo is checked out somewhere deeply nested under
 * a directory that itself contains an unrelated stray lockfile (observed
 * empirically in this environment: a `/tmp/package-lock.json` with no
 * relation to this repo, several directories above REPO_ROOT — Next's own
 * "Warning: Next.js inferred your workspace root" text names the exact
 * file), Next picks THAT ancestor as root instead, and mirrors this repo's
 * FULL path under `.next/standalone/` (e.g.
 * `.next/standalone/tmp/.../apps/admin/server.js`) rather than collapsing
 * it to just `apps/admin/server.js`. Fixing this properly is a one-line
 * `turbopack.root` add to apps/admin/next.config.ts — outside this agent's
 * allowed paths (apps/admin/** is mig-ui's) — so this harness instead
 * SEARCHES for wherever `server.js` actually landed, rather than assuming a
 * fixed relative path. Never searches inside `node_modules` (Next itself
 * ships several unrelated files literally named `server.js` there).
 */
function findAdminServerJs(standaloneRoot) {
  const preferred = path.join(standaloneRoot, 'apps/admin/server.js');
  if (existsSync(preferred)) {
    return preferred;
  }
  if (!existsSync(standaloneRoot)) {
    return null;
  }
  for (const rel of readdirSync(standaloneRoot, { recursive: true })) {
    if (rel.split(path.sep).includes('node_modules')) continue;
    if (rel.endsWith(path.join('apps', 'admin', 'server.js'))) {
      return path.join(standaloneRoot, rel);
    }
  }
  return null;
}

function buildAdmin() {
  console.error('[parity] pnpm --filter admin run build ...');
  const result = run('pnpm', ['--filter', 'admin', 'run', 'build'], { stdio: 'inherit' });
  if (result.status !== 0) {
    fail('build_admin', `pnpm --filter admin run build exited ${result.status}`);
  }
  const standaloneRoot = path.join(ADMIN_DIR, '.next/standalone');
  const serverEntry = findAdminServerJs(standaloneRoot);
  if (!serverEntry) {
    fail(
      'build_admin',
      `next build did not produce an apps/admin/server.js anywhere under ${standaloneRoot} — is next.config.ts's output:'standalone' still set?`
    );
  }
  ADMIN_STANDALONE_DIR = path.dirname(serverEntry);
  markOk('build_admin', { serverEntry });
}

/** Next's `output: 'standalone'` does NOT copy `.next/static` into the standalone bundle (documented Next.js behavior) — this harness's own responsibility to copy it before starting the server, every build. */
function prepareStandaloneStatic() {
  const src = path.join(ADMIN_DIR, '.next/static');
  const dest = path.join(ADMIN_STANDALONE_DIR, '.next/static');
  cpSync(src, dest, { recursive: true, force: true });
  markOk('prepare_standalone_static');
}

function startNextServer(env) {
  console.error('[parity] starting apps/admin standalone server ...');
  const child = spawn('node', ['server.js'], {
    cwd: ADMIN_STANDALONE_DIR,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (d) => {
    stdout += d.toString();
  });
  child.stderr.on('data', (d) => {
    stderr += d.toString();
  });
  // 20000 chars (not 4000) — only ever printed on a FAIL, and a too-short
  // tail was observed empirically (this batch's own build report) to cut
  // off the actual uncaught-error stack trace behind a real bug, leaving
  // only its defensively-caught SIBLING queries' console.error() output
  // visible and the real cause invisible.
  child.getLogs = () => ({ stdout: stdout.slice(-20000), stderr: stderr.slice(-20000) });
  markOk('start_next_server', { pid: child.pid });
  return child;
}

// ---------------------------------------------------------------------------
// Login — PHP via a REAL POST to auth/login.php (plain username/password
// form, no CSRF token — verified by reading that file); Next via a REAL
// POST to /api/auth/login (the interface contract's own Route Handler).
// Both requests (and every page fetch below) carry Host: TENANT_HOST so
// BOTH stacks resolve the SAME tenant via subdomain routing — a stricter,
// more production-like test than run.mjs's own bridge harness, which
// deliberately relies on PHP's legacy-DB-fallback instead (see that
// script's own comments); safe here because this fixture's tenant row's
// db_name equals the SAME physical database run.mjs also uses that pattern
// for, so either resolution path lands on identical data.
// ---------------------------------------------------------------------------

function firstSetCookieValue(headers, cookieName) {
  const raw = headers['set-cookie'];
  if (!raw) return null;
  const list = Array.isArray(raw) ? raw : [raw];
  for (const entry of list) {
    const m = entry.match(new RegExp(`^${cookieName}=([^;]+)`));
    if (m) return m[1];
  }
  return null;
}

async function phpLogin(password) {
  const resp = await httpRequest({
    url: `${PHP_BASE_URL}/auth/login.php`,
    method: 'POST',
    headers: {
      Host: TENANT_HOST,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({ username: ADMIN_USERNAME, password }).toString(),
  });
  const sid = firstSetCookieValue(resp.headers, 'PHPSESSID');
  if (!sid || resp.status < 300 || resp.status >= 400 || !(resp.headers.location || '').includes('dashboard')) {
    fail('php_login', 'auth/login.php did not respond with a PHPSESSID cookie + redirect to dashboard', {
      status: resp.status,
      location: resp.headers.location,
      sidPresent: sid !== null,
    });
  }
  markOk('php_login');
  return sid;
}

async function nextLogin(password) {
  const resp = await httpRequest({
    url: `${NEXT_BASE_URL}/api/auth/login`,
    method: 'POST',
    headers: {
      Host: TENANT_HOST,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({ realm: 'tenant', username: ADMIN_USERNAME, password }).toString(),
  });
  const sid = firstSetCookieValue(resp.headers, 'reya_sid');
  if (!sid || resp.status < 300 || resp.status >= 400 || !(resp.headers.location || '').includes('/dashboard')) {
    fail('next_login', 'POST /api/auth/login did not respond with a reya_sid cookie + redirect to /dashboard', {
      status: resp.status,
      location: resp.headers.location,
      sidPresent: sid !== null,
    });
  }
  markOk('next_login');
  return sid;
}

async function fetchPhpPage(pathAndQuery, sid) {
  const resp = await httpRequest({
    url: `${PHP_BASE_URL}${pathAndQuery}`,
    headers: { Host: TENANT_HOST, Cookie: `PHPSESSID=${sid}` },
  });
  return resp;
}

async function fetchNextPage(pathAndQuery, sid) {
  const resp = await httpRequest({
    url: `${NEXT_BASE_URL}${pathAndQuery}`,
    headers: { Host: TENANT_HOST, Cookie: `reya_sid=${sid}` },
  });
  return resp;
}

function assertAuthedOk(resp, label) {
  if (resp.status !== 200) {
    throw new Error(`${label}: expected 200, got ${resp.status} (location=${resp.headers.location ?? 'n/a'})`);
  }
}

/**
 * fetchNextJson — same tenant-Host + session-cookie identity model as
 * fetchNextPage() above (see this file's module doc's Phase 4 batch 1
 * paragraph for why: /api/inbox/** is tenant-scoped + admin-session-gated,
 * not api-parity.mjs's unauthenticated root-domain model), for the two new
 * JSON Route Handlers this batch's cursor walks call directly. Throws
 * loudly and diagnosably (never returns a half-parsed/undefined shape) on a
 * non-200 status, invalid JSON, or `{success:false}` — this is what makes
 * "the route doesn't exist yet" fail as a clear, attributable error instead
 * of a confusing downstream TypeError deep inside a walk loop.
 */
async function fetchNextJson(pathAndQuery, sid) {
  const resp = await httpRequest({
    url: `${NEXT_BASE_URL}${pathAndQuery}`,
    headers: { Host: TENANT_HOST, Cookie: `reya_sid=${sid}` },
  });
  if (resp.status !== 200) {
    throw new Error(`${pathAndQuery}: expected 200, got ${resp.status} — body(0..300)=${resp.text.slice(0, 300)}`);
  }
  let json;
  try {
    json = JSON.parse(resp.text);
  } catch (err) {
    throw new Error(`${pathAndQuery}: response body was not valid JSON (${err.message}) — body(0..300)=${resp.text.slice(0, 300)}`);
  }
  if (json.success !== true) {
    throw new Error(`${pathAndQuery}: {success:false} response — ${JSON.stringify(json).slice(0, 300)}`);
  }
  return json;
}

// ---------------------------------------------------------------------------
// Diffing — plain structural deepEqual over the small JSON-safe objects
// infra/e2e/lib/extract.mjs's extractors return. Never touches raw HTML.
// ---------------------------------------------------------------------------

function diffValues(php, next, path, out) {
  if (Array.isArray(php) || Array.isArray(next)) {
    const a = JSON.stringify(php ?? null);
    const b = JSON.stringify(next ?? null);
    if (a !== b) out.push(`${path}: php=${a} next=${b}`);
    return;
  }
  if (php !== null && typeof php === 'object' && next !== null && typeof next === 'object') {
    const keys = new Set([...Object.keys(php), ...Object.keys(next)]);
    for (const key of keys) {
      diffValues(php[key], next[key], path ? `${path}.${key}` : key, out);
    }
    return;
  }
  if (php !== next) {
    out.push(`${path}: php=${JSON.stringify(php)} next=${JSON.stringify(next)}`);
  }
}

function diff(php, next) {
  const out = [];
  diffValues(php, next, '', out);
  return out;
}

/**
 * Runs one page-pair: fetches PHP + Next, extracts, diffs. NEVER throws —
 * any failure (fetch error, non-200, extraction throwing because a label it
 * expected isn't in the HTML at all) is caught and turned into
 * `{page, ok:false, mismatches:[...]}` so ONE broken/missing page can never
 * abort the other page-pairs or skip teardown (acceptance criterion: a
 * deliberately-broken Next build must still tear down cleanly and report
 * FAIL with a diagnosable mismatch, not hang/crash).
 */
async function runPagePair(name, fetchBoth, extractBoth) {
  let phpResp = null;
  let nextResp = null;
  try {
    ({ phpResp, nextResp } = await fetchBoth());
    assertAuthedOk(phpResp, `${name} (php)`);
    assertAuthedOk(nextResp, `${name} (next)`);
    const { phpData, nextData } = extractBoth(phpResp.text, nextResp.text);
    const mismatches = diff(phpData, nextData);
    if (mismatches.length > 0) {
      dumpHtmlIfEnabled(name, phpResp, nextResp);
    }
    return { page: name, ok: mismatches.length === 0, mismatches, phpData, nextData };
  } catch (err) {
    // Dump whatever we managed to fetch even when EXTRACTION itself threw
    // (not just on a value mismatch) — an extraction error is exactly the
    // case a human most needs the raw HTML for (a label an extractor
    // expected genuinely isn't there), so PARITY_DUMP_HTML=1 covers both
    // failure shapes, not just mismatches.
    dumpHtmlIfEnabled(name, phpResp, nextResp);
    return { page: name, ok: false, mismatches: [`extraction/fetch error: ${err && err.message ? err.message : String(err)}`] };
  }
}

function dumpHtmlIfEnabled(name, phpResp, nextResp) {
  if (!process.env.PARITY_DUMP_HTML) return;
  const safe = name.replace(/[^a-zA-Z0-9_.=-]/g, '_');
  if (phpResp) writeFileSync(`/tmp/parity-debug-${safe}-php.html`, phpResp.text);
  if (nextResp) writeFileSync(`/tmp/parity-debug-${safe}-next.html`, nextResp.text);
}

/**
 * Phase 2 batch 3 — the ONE narrowly-scoped variant of runPagePair() this
 * harness needs, for crm-dashboard-advanced ONLY (see this module's own
 * header comment + docs/runbooks/phase2-batch1-users-dashboard-parity.md's
 * "Phase 2 batch 3" section for the full "why"). Unlike runPagePair(),
 * this fetches and asserts against ONE stack only — there is no PHP HTML to
 * diff against Next's here (PHP 500s, see runCrmDashboardAdvancedChecks()
 * below), so assertAuthedOk()'s "both sides must be 200" assumption does not
 * apply to this page. Same NEVER-THROWS contract as runPagePair() (any
 * failure becomes `{page, ok:false, mismatches:[...]}`, never aborts the
 * run or skips teardown) — `assertAndExtract` is expected to throw on a
 * failed assertion, which this function catches and reports exactly like
 * runPagePair() catches a fetch/extraction error.
 */
async function runSingleSideCheck(name, fetchOne, assertAndExtract) {
  let resp = null;
  try {
    resp = await fetchOne();
    const data = assertAndExtract(resp);
    return { page: name, ok: true, mismatches: [], data };
  } catch (err) {
    if (process.env.PARITY_DUMP_HTML && resp && typeof resp.text === 'string') {
      const safe = name.replace(/[^a-zA-Z0-9_.=-]/g, '_');
      writeFileSync(`/tmp/parity-debug-${safe}.html`, resp.text);
    }
    return { page: name, ok: false, mismatches: [`assertion error: ${err && err.message ? err.message : String(err)}`] };
  }
}

/**
 * Phase 2 batch 3 — crm-dashboard-advanced's deliberate exception to the
 * harness's usual PHP-vs-Next diff shape (per pagesA's CRITICAL FINDING,
 * jointly resolved with mig-ui — see both queries.ts's and page.tsx's own
 * module docs in apps/admin/src/app/(tenant)/crm-dashboard-advanced/, and
 * this batch's runbook section): `crm_deals`/`crm_tickets` are absent from
 * the committed tenant template, so PHP's own crm-dashboard-advanced.php
 * throws an uncaught PDOException on THIS SAME fixture schema and returns
 * 500 — a pre-existing PHP defect, not a harness bug, not fixable here
 * (database/** is outside this agent's allowed paths). Returns THREE
 * `pages`-shaped entries, each independently `ok`/`mismatches`:
 *
 *   1. `crm-dashboard-advanced:php-500-expected` — POSITIVELY asserts PHP
 *      still returns exactly 500 on its default (`?tab=overview`) landing
 *      tab (not silently skipped, not merely "!== 200" — a future PHP fix
 *      that starts returning e.g. a 302 would also fail this, on purpose:
 *      any status other than the documented 500 is a signal worth
 *      investigating). This is what makes the check "catch a real fix to
 *      CRMDashboardService.php" per this batch's acceptance criteria.
 *   2. `crm-dashboard-advanced:next-overview-200-defensive-empty` —
 *      RE-WIRED (mig-verify parity-miss fix, per this batch's runbook
 *      section 13.3): previously asserted a 500 here too, because
 *      `getRevenueAnalytics()` queried `odoo_webhooks_log.created_at`, a
 *      column absent from the committed tenant template, with NO try/catch
 *      (unlike every sibling crm_deals/crm_tickets query in queries.ts) — a
 *      faithful 1:1 port of PHP's own identical, equally-unguarded query
 *      (classes/CRMDashboardService.php lines 701-724). queries.ts now wraps
 *      that query the same way as its siblings (empty `daily` series on
 *      failure, `summary` placeholder untouched), so Next's default
 *      `?tab=overview` reaches 200 with the documented defensive-empty shape
 *      — asserted via extractCrmDashboardAdvancedDefensiveEmpty(), symmetric
 *      with check #3 below.
 *   3. `crm-dashboard-advanced:next-pipeline-200-defensive-empty` — proves
 *      the AUTHORIZED RESOLUTION pattern genuinely works where it IS
 *      applied: `?tab=pipeline` (SalesPipelineTab) never calls
 *      `getRevenueAnalytics()` at all, only the properly-defended
 *      `getPipelineData()`/`getCustomers()`, so it reaches 200 with the
 *      documented defensive-empty shape today
 *      (extractCrmDashboardAdvancedPipelineDefensiveEmpty() in
 *      infra/e2e/lib/extract.mjs — throws on any violation).
 *
 * See extractCrmDashboardAdvancedDefensiveEmpty()'s own module doc in
 * infra/e2e/lib/extract.mjs for the full field-by-field defensive-empty
 * contract, and this batch's runbook section for the complete write-up.
 */
async function runCrmDashboardAdvancedChecks(phpSid, nextSid) {
  const phpCheck = await runSingleSideCheck(
    'crm-dashboard-advanced:php-500-expected',
    () => fetchPhpPage('/crm-dashboard-advanced.php', phpSid),
    (resp) => {
      if (resp.status !== 500) {
        throw new Error(
          `expected PHP crm-dashboard-advanced.php to return 500 (crm_deals/crm_tickets absent from the committed tenant template — see CRMDashboardService.php's getExecutiveOverview(), an uncaught PDOException) but got ${resp.status}. If CRMDashboardService.php now guards these queries (or the schema gained crm_deals/crm_tickets), this exception is stale — update/remove runCrmDashboardAdvancedChecks() in infra/e2e/parity.mjs per docs/runbooks/phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 3" section, and switch this page to a normal runPagePair() PHP-vs-Next diff.`
        );
      }
      return { status: resp.status };
    }
  );

  const nextOverviewCheck = await runSingleSideCheck(
    'crm-dashboard-advanced:next-overview-200-defensive-empty',
    () => fetchNextPage('/crm-dashboard-advanced', nextSid),
    (resp) => {
      assertAuthedOk(resp, 'crm-dashboard-advanced (next, default overview tab)');
      return extractCrmDashboardAdvancedDefensiveEmpty(resp.text);
    }
  );

  const nextPipelineCheck = await runSingleSideCheck(
    'crm-dashboard-advanced:next-pipeline-200-defensive-empty',
    () => fetchNextPage('/crm-dashboard-advanced?tab=pipeline', nextSid),
    (resp) => {
      assertAuthedOk(resp, 'crm-dashboard-advanced?tab=pipeline (next)');
      return extractCrmDashboardAdvancedPipelineDefensiveEmpty(resp.text);
    }
  );

  return [phpCheck, nextOverviewCheck, nextPipelineCheck];
}

/**
 * Phase 4 batch 1 — /inbox sidebar checks. NOT a runPagePair() diff — see
 * extractInboxSidebarPage()'s own module doc in lib/extract.mjs for the full
 * trace of WHY: a confirmed, pre-existing PHP defect (discovered by this
 * batch's own harness run) makes inbox-v2.php's LINE-tab conversation list
 * ALWAYS EMPTY under the exact zero-`line_accounts` state every fixture in
 * this harness deliberately maintains throughout the whole run
 * (`includes/header.php` line 174 clobbers inbox-v2.php's own line-81
 * `$currentBotId` to `NULL` via shared top-level-include scope, and the
 * conversation list's `u.line_account_id = ?` is an equality test, not the
 * NULL-tolerant pattern most other pages use). Same "two positively-asserting
 * single-stack checks, never a diff of a genuinely-broken PHP page against a
 * genuinely-correct Next one" shape as runCrmDashboardAdvancedChecks() /
 * the line-group-detail header-defect entries above — see
 * docs/runbooks/phase4-batch1-inbox-reads-parity.md for the full write-up.
 *
 * If a future PHP fix resolves the clobbering (e.g. inbox-v2.php stops
 * relying on a pre-header.php $currentBotId, or header.php stops
 * unconditionally overwriting it), the `inbox:php-empty-currentbotid-clobbered`
 * check below will start failing ITS OWN assertion (PHP no longer
 * genuinely empty) — that failure is the signal to delete this exception and
 * switch back to a normal runPagePair() diff, per the same forward-looking
 * pattern runCrmDashboardAdvancedChecks()'s own error message already uses.
 */
async function runInboxSidebarChecks(phpSid, nextSid) {
  const phpCheck = await runSingleSideCheck(
    'inbox:php-empty-currentbotid-clobbered',
    () => fetchPhpPage('/inbox-v2.php', phpSid),
    (resp) => {
      assertAuthedOk(resp, 'inbox-v2.php (php, baseline)');
      const data = extractInboxSidebarPage(resp.text, INBOX_SIDEBAR_KNOWN_CONVERSATIONS);
      if (data.totalUnreadBadge !== 0 || !data.emptyStateVisible) {
        throw new Error(
          `expected inbox-v2.php's sidebar to be confirmed-empty (totalUnreadBadge=0, "ยังไม่มีแชท" visible) per the $currentBotId-clobbering defect (see this function's own doc), got totalUnreadBadge=${data.totalUnreadBadge} emptyStateVisible=${data.emptyStateVisible}. If includes/header.php or inbox-v2.php changed how $currentBotId is resolved, this exception may be stale — update/remove runInboxSidebarChecks() per docs/runbooks/phase4-batch1-inbox-reads-parity.md and switch /inbox back to a normal runPagePair() diff.`
        );
      }
      for (const conv of INBOX_SIDEBAR_KNOWN_CONVERSATIONS) {
        if (data.conversations[conv.name]?.visible) {
          throw new Error(`expected conversation ${conv.name} (id=${conv.id}) to be ABSENT from PHP's confirmed-empty sidebar, but it was visible`);
        }
      }
      return data;
    }
  );

  const nextCheck = await runSingleSideCheck(
    'inbox:next-baseline',
    () => fetchNextPage('/inbox', nextSid),
    (resp) => {
      assertAuthedOk(resp, '/inbox (next, baseline)');
      const data = extractInboxSidebarPage(resp.text, INBOX_SIDEBAR_KNOWN_CONVERSATIONS);
      const mismatches = [];
      if (data.totalUnreadBadge !== 200) {
        mismatches.push(`totalUnreadBadge expected 200 (SSR cap), got ${data.totalUnreadBadge}`);
      }
      for (const conv of INBOX_SIDEBAR_KNOWN_CONVERSATIONS) {
        const row = data.conversations[conv.name];
        if (!row?.visible) {
          mismatches.push(`conversation ${conv.name} (id=${conv.id}) expected visible, was not`);
          continue;
        }
        for (const attr of conv.attrs ?? []) {
          if (!row[attr]) {
            mismatches.push(`conversation ${conv.name} (id=${conv.id}) expected attribute ${attr}, was not present`);
          }
        }
      }
      if (mismatches.length > 0) {
        throw new Error(mismatches.join('; '));
      }
      return data;
    }
  );

  return [phpCheck, nextCheck];
}

/**
 * Phase 2 tail — the dedicated view-count-increment check for
 * article.php/`/articles/[slug]`. NOT an ordinary runPagePair() diff — see
 * extractArticleViewCount()'s own doc in infra/e2e/lib/extract.mjs for the
 * full "why": both `HealthArticleService::getBySlug()` (PHP) and
 * `[slug]/page.tsx` (Next) display the PRE-increment view_count their own
 * request's SELECT captured, then fire the increment afterward — so on this
 * harness's SHARED physical database (one MariaDB tenant DB, fetched by both
 * stacks), a plain PHP-then-Next runPagePair() diff of `view_count` would be
 * a GUARANTEED off-by-one mismatch (Next's SELECT always runs after PHP's
 * own increment already landed), not a real product bug. Comparing a
 * stack's OWN count across two of its OWN consecutive fetches sidesteps that
 * cross-stack ordering entirely — the assertion is "did fetching the article
 * again increment the counter by exactly 1", proven independently, once per
 * stack, using `runSingleSideCheck()`'s established pattern (each `fetchOne`
 * performs BOTH of its own stack's two fetches internally and returns them
 * together — `assertAndExtract()` must stay synchronous, per that helper's
 * own contract, so the async work happens entirely inside `fetchOne`).
 */
async function runArticleViewCountIncrementChecks(phpSid, nextSid) {
  const phpCheck = await runSingleSideCheck(
    'article-detail:view-count-increment php',
    async () => {
      const first = await fetchPhpPage(`/article.php?slug=${ARTICLE_DETAIL_SLUG}`, phpSid);
      const second = await fetchPhpPage(`/article.php?slug=${ARTICLE_DETAIL_SLUG}`, phpSid);
      return { first, second, text: second.text };
    },
    (resp) => {
      assertAuthedOk(resp.first, 'article.php view-count (php, first fetch)');
      assertAuthedOk(resp.second, 'article.php view-count (php, second fetch)');
      const firstCount = extractArticleViewCount(resp.first.text);
      const secondCount = extractArticleViewCount(resp.second.text);
      if (secondCount !== firstCount + 1) {
        throw new Error(
          `expected view_count to increment by exactly 1 between two consecutive PHP fetches of the same slug, got first=${firstCount} second=${secondCount}`
        );
      }
      return { firstCount, secondCount };
    }
  );

  const nextCheck = await runSingleSideCheck(
    'article-detail:view-count-increment next',
    async () => {
      const first = await fetchNextPage(`/articles/${ARTICLE_DETAIL_SLUG}`, nextSid);
      const second = await fetchNextPage(`/articles/${ARTICLE_DETAIL_SLUG}`, nextSid);
      return { first, second, text: second.text };
    },
    (resp) => {
      assertAuthedOk(resp.first, '/articles/[slug] view-count (next, first fetch)');
      assertAuthedOk(resp.second, '/articles/[slug] view-count (next, second fetch)');
      const firstCount = extractArticleViewCount(resp.first.text);
      const secondCount = extractArticleViewCount(resp.second.text);
      if (secondCount !== firstCount + 1) {
        throw new Error(
          `expected view_count to increment by exactly 1 between two consecutive Next fetches of the same slug, got first=${firstCount} second=${secondCount}`
        );
      }
      return { firstCount, secondCount };
    }
  );

  return [phpCheck, nextCheck];
}

/**
 * Phase 4 batch 1 — GET /api/inbox/conversations cursor-pagination contract
 * walk. Unlike every runPagePair()/runSingleSideCheck() entry above, this
 * has NO PHP side to diff against — it proves the NEW Next-only Route
 * Handler's own pagination contract against the golden dataset (215
 * conversations, infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl),
 * following the same "config-driven, page/filter combos in a loop" spirit
 * as every USERS_FILTER_COMBOS-style array above, just expressed as a
 * while-loop because a cursor walk's page COUNT isn't known up front.
 *
 * Same NEVER-THROWS contract as runPagePair()/runSingleSideCheck() (any
 * failure — including "the route 404s because conversationList's code
 * doesn't exist yet" — becomes `{page, ok:false, mismatches:[...]}`, never
 * aborts the run or skips teardown); fetchNextJson() is what turns a
 * missing-route 404 into a loud, attributable Error this function's own
 * try/catch then reports as this step's mismatch.
 *
 * Asserts, in order: (1) the envelope shape (`data.conversations` array,
 * `next_cursor`/`has_more`/`count`); (2) the documented 100-row-per-page
 * internal cap (api/inbox/conversations/_lib/query.ts's own "ARCHITECTURE
 * NOTE" — a confirmed PHP quirk this port preserves, not a bug); (3) no
 * duplicate ids across pages and a strictly non-increasing
 * `last_message_at` across the WHOLE walk (proves the cursor genuinely
 * advances rather than looping/skipping); (4) the walk terminates with
 * `has_more:false` + `next_cursor:null`; (5) the total distinct id count
 * equals the fixture's own golden total (215) and covers every expected id;
 * (6) a targeted re-fetch (`limit=10`, the 10 most-recent conversations)
 * spot-checks each badge satellite's enrichment fields (tags/assigned_to/
 * assignment_status/assignees/chat_status/unread_count) against the exact
 * values the fixture seeded — this is the strong, byte-for-byte proof the
 * lighter-weight inbox:baseline HTML page-pair deliberately defers to (see
 * extractInboxSidebarPage()'s own module doc in lib/extract.mjs).
 */
async function runConversationsCursorWalk(nextSid) {
  const name = 'inbox-conversations-cursor-walk';
  try {
    const seenIds = new Set();
    const seenTimestamps = [];
    let cursor = null;
    let pageCount = 0;
    const MAX_PAGES = 10; // ceil(215/100) = 3 expected — generous guard against an infinite loop if the cursor never advances.
    for (;;) {
      pageCount++;
      if (pageCount > MAX_PAGES) {
        throw new Error(`did not terminate within ${MAX_PAGES} pages (has_more kept returning true) — possible cursor-advance bug`);
      }
      const qs = cursor ? `limit=100&cursor=${encodeURIComponent(cursor)}` : 'limit=100';
      const json = await fetchNextJson(`/api/inbox/conversations?${qs}`, nextSid);
      const data = json.data;
      if (!data || !Array.isArray(data.conversations)) {
        throw new Error(`page ${pageCount}: data.conversations was not an array — ${JSON.stringify(data).slice(0, 200)}`);
      }
      if (pageCount < 3 && data.conversations.length !== 100) {
        throw new Error(`page ${pageCount}: expected exactly 100 rows (documented internal cap), got ${data.conversations.length}`);
      }
      if (data.has_more === true && data.conversations.length !== 100) {
        throw new Error(`page ${pageCount}: has_more=true but only ${data.conversations.length} rows returned (expected exactly 100)`);
      }
      for (const conv of data.conversations) {
        if (seenIds.has(conv.id)) {
          throw new Error(`duplicate conversation id ${conv.id} returned across pages — cursor did not advance correctly`);
        }
        seenIds.add(conv.id);
        seenTimestamps.push(conv.last_message_at);
      }
      if (!data.has_more) {
        if (data.next_cursor !== null) {
          throw new Error(`has_more=false but next_cursor=${JSON.stringify(data.next_cursor)}, expected null`);
        }
        break;
      }
      if (!data.next_cursor) {
        throw new Error(`has_more=true but next_cursor is falsy: ${JSON.stringify(data.next_cursor)}`);
      }
      cursor = data.next_cursor;
    }

    for (let i = 1; i < seenTimestamps.length; i++) {
      if (seenTimestamps[i] > seenTimestamps[i - 1]) {
        throw new Error(`ordering violated at index ${i}: ${seenTimestamps[i - 1]} then ${seenTimestamps[i]} (expected non-increasing last_message_at across the whole walk)`);
      }
    }

    if (seenIds.size !== INBOX_TOTAL_CONVERSATIONS) {
      throw new Error(`expected exactly ${INBOX_TOTAL_CONVERSATIONS} distinct conversations across the whole walk, got ${seenIds.size}`);
    }
    const expectedIds = [INBOX_HERO_ID, INBOX_TAGGED_ID, INBOX_ASSIGNED_STATUS_ID, INBOX_MULTI_ASSIGNEE_ID, INBOX_PENDING_STATUS_ID, INBOX_UNREAD_ID];
    for (let id = INBOX_FILLER_START; id <= INBOX_FILLER_END; id++) expectedIds.push(id);
    for (const id of expectedIds) {
      if (!seenIds.has(id)) {
        throw new Error(`expected conversation id ${id} missing from the cursor walk`);
      }
    }

    // Badge-satellite enrichment spot check — a fresh, small-limit request
    // covering exactly the 6 most-recent conversations (HERO + the 5 badge
    // satellites — see the fixture's own "CONVERSATION LAYOUT" header
    // comment for the ordering guarantee).
    const spot = await fetchNextJson('/api/inbox/conversations?limit=10', nextSid);
    const byId = Object.fromEntries(spot.data.conversations.map((c) => [c.id, c]));
    const hero = byId[INBOX_HERO_ID];
    const tagged = byId[INBOX_TAGGED_ID];
    const assignedStatus = byId[INBOX_ASSIGNED_STATUS_ID];
    const multiAssignee = byId[INBOX_MULTI_ASSIGNEE_ID];
    const pendingStatus = byId[INBOX_PENDING_STATUS_ID];
    const unread = byId[INBOX_UNREAD_ID];
    const spotMismatches = [];
    if (!hero || hero.unread_count !== 0) {
      spotMismatches.push(`hero(${INBOX_HERO_ID}).unread_count expected 0, got ${hero?.unread_count}`);
    }
    if (!tagged || !(tagged.tags ?? []).some((t) => t.id === 1 && t.name === 'VIP')) {
      spotMismatches.push(`tagged(${INBOX_TAGGED_ID}).tags expected to include {id:1,name:'VIP'}, got ${JSON.stringify(tagged?.tags)}`);
    }
    if (!assignedStatus || assignedStatus.assigned_to !== 1 || assignedStatus.assignment_status !== 'active') {
      spotMismatches.push(
        `assignedStatus(${INBOX_ASSIGNED_STATUS_ID}).assigned_to/assignment_status expected 1/active, got ${assignedStatus?.assigned_to}/${assignedStatus?.assignment_status}`
      );
    }
    if (!multiAssignee || !(multiAssignee.assignees ?? []).includes(1)) {
      spotMismatches.push(`multiAssignee(${INBOX_MULTI_ASSIGNEE_ID}).assignees expected to include 1, got ${JSON.stringify(multiAssignee?.assignees)}`);
    }
    if (!pendingStatus || pendingStatus.chat_status !== 'pending') {
      spotMismatches.push(`pendingStatus(${INBOX_PENDING_STATUS_ID}).chat_status expected 'pending', got ${pendingStatus?.chat_status}`);
    }
    if (!unread || unread.unread_count !== 2) {
      spotMismatches.push(`unread(${INBOX_UNREAD_ID}).unread_count expected 2, got ${unread?.unread_count}`);
    }
    if (spotMismatches.length > 0) {
      throw new Error(`badge-satellite spot checks failed: ${spotMismatches.join('; ')}`);
    }

    return { page: name, ok: true, mismatches: [], data: { totalConversations: seenIds.size, pages: pageCount } };
  } catch (err) {
    return { page: name, ok: false, mismatches: [err && err.message ? err.message : String(err)] };
  }
}

/**
 * Phase 4 batch 1 — GET /api/inbox/messages cursor-pagination contract walk
 * for HERO (user_id=7001, 130 messages spanning every marked message
 * type/form this batch's brief lists). Same "no PHP side, NEVER THROWS"
 * shape as runConversationsCursorWalk() above — see that function's own doc
 * for the rationale.
 *
 * Asserts, in order: (1) the envelope shape; (2) each page's `messages` are
 * strictly ascending by `id` (the documented "reverse DESC-fetched rows to
 * ascending" contract — classes/InboxService.php::getMessagesCursor() lines
 * 769-831, ported literally in api/inbox/messages/_lib/query.ts); (3) the
 * documented `max(1,min(100,limit))` cap — this walk requests limit=20, well
 * under the cap, so every non-final page must return exactly 20 rows; (4)
 * `next_cursor` equals the oldest (`min`) id returned on that page, matching
 * the `id < cursor` WHERE-clause contract; (5) no duplicate ids across
 * pages; (6) the total distinct id count equals HERO's golden total (130);
 * (7) every one of the 13 marked message types/forms (INBOX_MESSAGE_TYPE_CHECKS)
 * was seen at least once somewhere across the whole walk.
 */
async function runMessagesCursorWalk(nextSid) {
  const name = 'inbox-messages-cursor-walk';
  try {
    const seenIds = new Set();
    const matchedMarkers = new Set();
    let cursor = null;
    let pageCount = 0;
    const MAX_PAGES = 20; // ceil(130/20) = 7 expected.
    for (;;) {
      pageCount++;
      if (pageCount > MAX_PAGES) {
        throw new Error(`did not terminate within ${MAX_PAGES} pages (has_more kept returning true) — possible cursor-advance bug`);
      }
      const qs = cursor
        ? `user_id=${INBOX_HERO_ID}&limit=20&cursor=${encodeURIComponent(cursor)}`
        : `user_id=${INBOX_HERO_ID}&limit=20`;
      const json = await fetchNextJson(`/api/inbox/messages?${qs}`, nextSid);
      const data = json.data;
      if (!data || !Array.isArray(data.messages)) {
        throw new Error(`page ${pageCount}: data.messages was not an array — ${JSON.stringify(data).slice(0, 200)}`);
      }
      for (let i = 1; i < data.messages.length; i++) {
        if (!(data.messages[i].id > data.messages[i - 1].id)) {
          throw new Error(`page ${pageCount}: messages not strictly ascending by id at index ${i} (${data.messages[i - 1].id} then ${data.messages[i].id})`);
        }
      }
      if (data.has_more === true && data.messages.length !== 20) {
        throw new Error(`page ${pageCount}: has_more=true but only ${data.messages.length} rows returned (expected exactly 20, the requested+documented cap)`);
      }
      for (const m of data.messages) {
        if (seenIds.has(m.id)) {
          throw new Error(`duplicate message id ${m.id} across pages — cursor did not advance correctly`);
        }
        seenIds.add(m.id);
        for (const marker of INBOX_MESSAGE_TYPE_CHECKS) {
          if (!matchedMarkers.has(marker.name) && marker.match(m)) {
            matchedMarkers.add(marker.name);
          }
        }
      }
      if (!data.has_more) {
        if (data.next_cursor !== null) {
          throw new Error(`has_more=false but next_cursor=${JSON.stringify(data.next_cursor)}, expected null`);
        }
        break;
      }
      if (!data.next_cursor) {
        throw new Error(`has_more=true but next_cursor is falsy: ${JSON.stringify(data.next_cursor)}`);
      }
      const minIdThisPage = Math.min(...data.messages.map((m) => m.id));
      if (String(data.next_cursor) !== String(minIdThisPage)) {
        throw new Error(`page ${pageCount}: next_cursor=${data.next_cursor} does not equal this page's own minimum id ${minIdThisPage}`);
      }
      cursor = data.next_cursor;
    }

    if (seenIds.size !== INBOX_HERO_TOTAL_MESSAGES) {
      throw new Error(`expected exactly ${INBOX_HERO_TOTAL_MESSAGES} messages for user_id=${INBOX_HERO_ID}, got ${seenIds.size}`);
    }
    const missingMarkers = INBOX_MESSAGE_TYPE_CHECKS.filter((m) => !matchedMarkers.has(m.name)).map((m) => m.name);
    if (missingMarkers.length > 0) {
      throw new Error(`missing message-type coverage across the whole walk: ${missingMarkers.join(', ')}`);
    }

    return { page: name, ok: true, mismatches: [], data: { totalMessages: seenIds.size, pages: pageCount, markersCovered: [...matchedMarkers] } };
  } catch (err) {
    return { page: name, ok: false, mismatches: [err && err.message ? err.message : String(err)] };
  }
}

/**
 * Phase 2 settings batch 1 — /settings?tab=email. NOT a runPagePair() diff —
 * same family as runCrmDashboardAdvancedChecks()/runInboxSidebarChecks()
 * above: PHP and Next are EXPECTED to render two genuinely different things
 * here (PHP falls back to the LINE tab's markup; Next renders a real,
 * working EmailTab), so diffing their extracted data points against each
 * other would either always "mismatch" on an intentional, documented
 * divergence or require force-fitting two unrelated shapes into one
 * comparable object. Two independent runSingleSideCheck() calls instead —
 * see extractSettingsEmailPhpFallback()/extractSettingsEmailTab()'s own
 * module doc in infra/e2e/lib/extract.mjs for the full "why" (root
 * /settings.php's `$tabs` whitelist has 'email' commented out;
 * getActiveTab() silently falls back to 'line' for any unrecognized tab
 * key).
 *
 * Returns TWO `pages`-shaped entries (`settings:email-php-line-fallback`,
 * `settings:email-next-real`) rather than the single `settings:email` name
 * this batch's brief used loosely — deliberate, documented in
 * docs/runbooks/phase2-settings-tabs-batch1-parity.md's "settings:email is
 * two entries, not one" section: forcing this into one entry would mean
 * either (a) only checking one side (losing coverage), or (b) baking a
 * cross-side assertion into a single fetchOne()/assertAndExtract() pair,
 * which runSingleSideCheck()'s own contract doesn't support (assertAndExtract
 * runs synchronously against ONE already-fetched response, see that
 * function's own signature above) without changing that shared helper's
 * signature — out of this batch's allowed-paths (append-only, no helper
 * signature changes). Two independently-`ok`/`mismatches` entries is exactly
 * the same shape crm-dashboard-advanced's 3-entries-for-1-page and
 * /inbox-sidebar's 2-entries-for-1-page precedents already use.
 */
async function runSettingsEmailChecks(phpSid, nextSid) {
  const phpCheck = await runSingleSideCheck(
    'settings:email-php-line-fallback',
    () => fetchPhpPage('/settings.php?tab=email', phpSid),
    (resp) => {
      assertAuthedOk(resp, 'settings.php?tab=email (php)');
      return extractSettingsEmailPhpFallback(resp.text);
    }
  );

  const nextCheck = await runSingleSideCheck(
    'settings:email-next-real',
    () => fetchNextPage('/settings?tab=email', nextSid),
    (resp) => {
      assertAuthedOk(resp, 'settings?tab=email (next)');
      return extractSettingsEmailTab(resp.text);
    }
  );

  return [phpCheck, nextCheck];
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  const dbCreds = parseLocalConfigPhp(tracker);
  const secrets = generateSecrets();

  const composeEnv = {
    ...process.env,
    E2E_MARIADB_ROOT_PASSWORD: secrets.mariadbRootPassword,
    E2E_APP_DB_NAME: dbCreds.name,
    E2E_APP_DB_USER: dbCreds.user,
    E2E_APP_DB_PASSWORD: dbCreds.pass,
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret, // unused by this harness's own logic, but infra/e2e/docker-compose.yml's php service requires it (${...:?}).
  };

  let result = 'FAIL';
  let nextProc = null;
  const pages = [];

  try {
    composeUp(tracker, composeArgs, composeEnv, 'compose_up');
    await waitContainerHealthy(tracker, 'e2e-mariadb', 'mariadb_healthy');
    await waitContainerHealthy(tracker, 'e2e-redis', 'redis_healthy');

    seedDatabase(composeEnv, secrets, dbCreds);

    await waitHttpReachable(tracker, `${PHP_BASE_URL}/`, 'php_reachable');
    await fireThrowawayProbeRequest();

    const passwordHash = generatePhpBcryptHash(tracker, composeArgs, composeEnv, secrets.adminPassword, 'generate_php_hash');
    seedAdminUser(composeEnv, secrets, dbCreds, passwordHash);

    buildAdmin();
    prepareStandaloneStatic();

    const nextEnv = {
      ...process.env,
      NODE_ENV: 'production',
      PORT: String(NEXT_PORT),
      HOSTNAME: '127.0.0.1',
      DB_HOST: MARIADB_HOST,
      DB_USER: dbCreds.user,
      DB_PASS: dbCreds.pass,
      REDIS_URL: REDIS_HOST_URL,
      NEXT_TELEMETRY_DISABLED: '1',
    };
    nextProc = startNextServer(nextEnv);
    await waitHttpReachable(tracker, `${NEXT_BASE_URL}/api/health`, 'next_reachable');

    const phpSid = await phpLogin(secrets.adminPassword);
    const nextSid = await nextLogin(secrets.adminPassword);
    markOk('login_both_stacks');

    // --- /users (LINE tab), one page-pair entry per exercised filter combo ---
    for (const combo of USERS_FILTER_COMBOS) {
      const phpQs = combo.qs ? `?tab=line&${combo.qs}` : '?tab=line';
      const nextQs = combo.qs ? `?${combo.qs}` : '';
      pages.push(
        await runPagePair(
          `users:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/users.php${phpQs}`, phpSid),
            nextResp: await fetchNextPage(`/users${nextQs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractUsersPage(phpHtml), nextData: extractUsersPage(nextHtml) })
        )
      );
    }

    // --- /user-detail?id=N ---
    for (const id of USER_DETAIL_IDS) {
      pages.push(
        await runPagePair(
          `user-detail:id=${id}`,
          async () => ({
            phpResp: await fetchPhpPage(`/user-detail.php?id=${id}`, phpSid),
            nextResp: await fetchNextPage(`/user-detail?id=${id}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractUserDetailPage(phpHtml), nextData: extractUserDetailPage(nextHtml) })
        )
      );
    }

    // --- /dashboard?tab=executive|crm ---
    for (const tab of DASHBOARD_TABS) {
      pages.push(
        await runPagePair(
          `dashboard:tab=${tab}`,
          async () => ({
            phpResp: await fetchPhpPage(`/dashboard.php?tab=${tab}`, phpSid),
            nextResp: await fetchNextPage(`/dashboard?tab=${tab}`, nextSid),
          }),
          (phpHtml, nextHtml) =>
            tab === 'executive'
              ? {
                  phpData: extractExecutiveDashboard(phpHtml, 'php'),
                  nextData: extractExecutiveDashboard(nextHtml, 'next'),
                }
              : { phpData: extractCrmDashboard(phpHtml), nextData: extractCrmDashboard(nextHtml) }
        )
      );
    }

    // --- Phase 2 batch 2: /analytics?tab={overview|advanced|crm|account} ---
    for (const tab of ANALYTICS_TABS) {
      pages.push(
        await runPagePair(
          `analytics:tab=${tab.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/analytics.php?${tab.qs}`, phpSid),
            nextResp: await fetchNextPage(`/analytics?${tab.qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: tab.extractPhp(phpHtml), nextData: tab.extractNext(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 2: /activity-logs (5 filter branches + pagination) ---
    const activityLogsNow = new Date();
    const activityLogsBounds = activityLogsDateBounds(activityLogsNow);
    const activityLogsNarrow = activityLogsNarrowBounds(activityLogsNow);
    for (const combo of ACTIVITY_LOGS_COMBOS) {
      const qs = combo.qs(activityLogsBounds, activityLogsNarrow);
      pages.push(
        await runPagePair(
          `activity-logs:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/activity-logs.php?${qs}`, phpSid),
            nextResp: await fetchNextPage(`/activity-logs?${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractActivityLogsPage(phpHtml, 'php'), nextData: extractActivityLogsPage(nextHtml, 'next') })
        )
      );
    }

    // --- Phase 2 batch 2: /loyalty-members (?q= search) ---
    for (const combo of LOYALTY_MEMBERS_SEARCHES) {
      const phpQs = combo.qs ? `?${combo.qs}` : '';
      const nextQs = combo.qs ? `?${combo.qs}` : '';
      pages.push(
        await runPagePair(
          `loyalty-members:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/loyalty-members.php${phpQs}`, phpSid),
            nextResp: await fetchNextPage(`/loyalty-members${nextQs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractLoyaltyMembersPage(phpHtml), nextData: extractLoyaltyMembersPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 3: /templates (no query-param filters) ---
    for (const variant of TEMPLATES_VARIANTS) {
      const qs = variant.qs ? `?${variant.qs}` : '';
      pages.push(
        await runPagePair(
          `templates:${variant.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/templates.php${qs}`, phpSid),
            nextResp: await fetchNextPage(`/templates${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractTemplatesPage(phpHtml), nextData: extractTemplatesPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 3: /groups (baseline + ?view=N) ---
    for (const variant of GROUPS_VARIANTS) {
      const qs = variant.qs ? `?${variant.qs}` : '';
      pages.push(
        await runPagePair(
          `groups:${variant.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/groups.php${qs}`, phpSid),
            nextResp: await fetchNextPage(`/groups${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractGroupsPage(phpHtml), nextData: extractGroupsPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 3: /line-groups (no query-param filters) ---
    for (const variant of LINE_GROUPS_VARIANTS) {
      const qs = variant.qs ? `?${variant.qs}` : '';
      pages.push(
        await runPagePair(
          `line-groups:${variant.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/line-groups.php${qs}`, phpSid),
            nextResp: await fetchNextPage(`/line-groups${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractLineGroupsPage(phpHtml), nextData: extractLineGroupsPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 3: /line-group-detail?id=N (members/messages diff) ---
    for (const id of LINE_GROUP_DETAIL_IDS) {
      pages.push(
        await runPagePair(
          `line-group-detail:id=${id}`,
          async () => ({
            phpResp: await fetchPhpPage(`/line-group-detail.php?id=${id}`, phpSid),
            nextResp: await fetchNextPage(`/line-group-detail?id=${id}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractLineGroupDetailPage(phpHtml), nextData: extractLineGroupDetailPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 3: /line-group-detail?id=N header — the PHP
    // `$group`-clobbering defect exception (see LINE_GROUP_DETAIL_EXPECTED_HEADER's
    // own doc above + extractLineGroupDetailPage()'s module doc in lib/extract.mjs) ---
    for (const id of LINE_GROUP_DETAIL_IDS) {
      pages.push(
        await runSingleSideCheck(
          `line-group-detail:php-header-defect id=${id}`,
          () => fetchPhpPage(`/line-group-detail.php?id=${id}`, phpSid),
          (resp) => {
            assertAuthedOk(resp, `line-group-detail header (php) id=${id}`);
            return extractLineGroupDetailHeaderPhpDefect(resp.text);
          }
        )
      );
      pages.push(
        await runSingleSideCheck(
          `line-group-detail:next-header id=${id}`,
          () => fetchNextPage(`/line-group-detail?id=${id}`, nextSid),
          (resp) => {
            assertAuthedOk(resp, `line-group-detail header (next) id=${id}`);
            return extractLineGroupDetailHeaderNext(resp.text, LINE_GROUP_DETAIL_EXPECTED_HEADER[id]);
          }
        )
      );
    }

    // --- Phase 2 batch 3: /crm-dashboard-advanced — 500-vs-200 exception (see runCrmDashboardAdvancedChecks()'s own doc) ---
    pages.push(...(await runCrmDashboardAdvancedChecks(phpSid, nextSid)));

    // --- Phase 2 batch 3: /system-status (no query-param filters) ---
    pages.push(
      await runPagePair(
        'system-status:baseline',
        async () => ({
          phpResp: await fetchPhpPage('/system-status.php', phpSid),
          nextResp: await fetchNextPage('/system-status', nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractSystemStatusPage(phpHtml), nextData: extractSystemStatusPage(nextHtml) })
      )
    );

    // --- Phase 4 batch 1: /inbox (sidebar) — two single-side checks, not a
    // diff (PHP is confirmed-empty here — see runInboxSidebarChecks()'s own doc) ---
    pages.push(...(await runInboxSidebarChecks(phpSid, nextSid)));

    // --- Phase 4 batch 1: /inbox-v2.php?user=N vs /inbox/N (HERO thread) ---
    pages.push(
      await runPagePair(
        `inbox-thread:id=${INBOX_HERO_ID}`,
        async () => ({
          phpResp: await fetchPhpPage(`/inbox-v2.php?user=${INBOX_HERO_ID}`, phpSid),
          nextResp: await fetchNextPage(`/inbox/${INBOX_HERO_ID}`, nextSid),
        }),
        (phpHtml, nextHtml) => ({
          phpData: extractInboxThreadPage(phpHtml, INBOX_THREAD_HTML_MARKERS),
          nextData: extractInboxThreadPage(nextHtml, INBOX_THREAD_HTML_MARKERS),
        })
      )
    );

    // --- Phase 4 batch 1: JSON cursor-pagination contract walks (no PHP side — see each function's own doc) ---
    pages.push(await runConversationsCursorWalk(nextSid));
    pages.push(await runMessagesCursorWalk(nextSid));

    // --- Phase 2 tail: /articles (baseline + ?category=N + ?q=<search term>) ---
    for (const variant of ARTICLES_LIST_VARIANTS) {
      const qs = variant.qs ? `?${variant.qs}` : '';
      pages.push(
        await runPagePair(
          `articles:${variant.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/articles.php${qs}`, phpSid),
            nextResp: await fetchNextPage(`/articles${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractArticlesListPage(phpHtml), nextData: extractArticlesListPage(nextHtml) })
        )
      );
    }

    // --- Phase 2 tail: /articles/[slug] detail (title/tags/related — view_count excluded, see below) ---
    pages.push(
      await runPagePair(
        `article-detail:slug=${ARTICLE_DETAIL_SLUG}`,
        async () => ({
          phpResp: await fetchPhpPage(`/article.php?slug=${ARTICLE_DETAIL_SLUG}`, phpSid),
          nextResp: await fetchNextPage(`/articles/${ARTICLE_DETAIL_SLUG}`, nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractArticleDetailPage(phpHtml), nextData: extractArticleDetailPage(nextHtml) })
      )
    );

    // --- Phase 2 tail: article.php's/[slug]/page.tsx's view-count-increment side effect — dedicated two-fetch, single-stack checks (see runArticleViewCountIncrementChecks()'s own doc for why this is not an ordinary diff) ---
    pages.push(...(await runArticleViewCountIncrementChecks(phpSid, nextSid)));

    // --- Phase 2 tail: /pharmacists (PHP: pharmacy.php?tab=pharmacists live tab partial; Next: /pharmacists) ---
    pages.push(
      await runPagePair(
        'pharmacists:baseline',
        async () => ({
          phpResp: await fetchPhpPage('/pharmacy.php?tab=pharmacists', phpSid),
          nextResp: await fetchNextPage('/pharmacists', nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractPharmacistsPage(phpHtml), nextData: extractPharmacistsPage(nextHtml) })
      )
    );

    // --- Phase 2 settings batch 1: /settings?tab={welcome,email,consent,shop_tax} ---
    pages.push(
      await runPagePair(
        'settings:welcome',
        async () => ({
          phpResp: await fetchPhpPage('/settings.php?tab=welcome', phpSid),
          nextResp: await fetchNextPage('/settings?tab=welcome', nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractSettingsWelcomeTab(phpHtml), nextData: extractSettingsWelcomeTab(nextHtml) })
      )
    );

    // settings:email is a DELIBERATE one-sided-assertion exception (PHP's
    // ?tab=email always falls back to the LINE tab's markup — see
    // runSettingsEmailChecks()'s own doc above) — TWO entries, not a diff.
    pages.push(...(await runSettingsEmailChecks(phpSid, nextSid)));

    pages.push(
      await runPagePair(
        'settings:consent',
        async () => ({
          phpResp: await fetchPhpPage('/settings.php?tab=consent', phpSid),
          nextResp: await fetchNextPage('/settings?tab=consent', nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractSettingsConsentTab(phpHtml), nextData: extractSettingsConsentTab(nextHtml) })
      )
    );

    pages.push(
      await runPagePair(
        'settings:shop-tax',
        async () => ({
          phpResp: await fetchPhpPage('/settings.php?tab=shop_tax', phpSid),
          nextResp: await fetchNextPage('/settings?tab=shop_tax', nextSid),
        }),
        (phpHtml, nextHtml) => ({ phpData: extractSettingsShopTaxTab(phpHtml), nextData: extractSettingsShopTaxTab(nextHtml) })
      )
    );

    result = pages.every((p) => p.ok) ? 'PASS' : 'FAIL';
    if (result === 'PASS') {
      markOk('all_pages_matched', pages.map((p) => p.page));
    } else {
      fail(
        'page_parity',
        `${pages.filter((p) => !p.ok).length} of ${pages.length} page(s) did not match`,
        pages.filter((p) => !p.ok).map((p) => ({ page: p.page, mismatches: p.mismatches }))
      );
    }
  } catch (err) {
    result = 'FAIL';
    if (!(err instanceof HarnessError)) {
      tracker.setFailedAt('unexpected_error');
      steps.unexpected_error = { ok: false, message: String(err && err.stack ? err.stack : err) };
    }
  } finally {
    if (nextProc) {
      try {
        const logs = nextProc.getLogs ? nextProc.getLogs() : null;
        if (result !== 'PASS' && logs) {
          console.error('[parity] next server logs (tail):', logs.stdout, logs.stderr);
        }
        nextProc.kill('SIGTERM');
      } catch {
        // best-effort — teardown must never throw past this point.
      }
    }
    composeDown(composeArgs, composeEnv);
  }

  // Strip the (potentially large) phpData/nextData debugging payloads off
  // PASSING pages before printing — keep them on failing pages, where
  // they're the diagnosable evidence mig-verify needs.
  const printedPages = pages.map((p) => (p.ok ? { page: p.page, ok: p.ok, mismatches: p.mismatches } : p));

  const output = { result, pages: printedPages, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
