/**
 * format.ts — pure, DB-free helpers ported from the small formatting
 * functions repeated across the 9 includes/dashboard/crm-advanced/*.php
 * partials: stage/status/priority labels+badge colors (each partial redeclares
 * its own `stageBadges`/`statusBadges`/`priorityBadges` JS object literally —
 * consolidated here once since every occurrence across the 9 files is
 * byte-identical), `formatSla()` (service-center.php lines 218-224), and the
 * `toLocaleString('th-TH', ...)` date formatting used by sales-pipeline.php's
 * `formatDate()`, customers-list.php's `formatDate()`, and
 * service-center.php/tickets-list.php's inline `toLocaleDateString('th-TH')`
 * calls.
 *
 * `'th-TH'` is JS's Thai locale, whose default calendar is the Buddhist
 * calendar (Node's built-in ICU includes it) — this is a REAL behavior of
 * the PHP source (all of this is rendered by the browser's `Intl` via
 * client-side JS in the original, not PHP's `date()`), reproduced here
 * server-side with the same `Intl.DateTimeFormat('th-TH', ...)` call rather
 * than "fixed" to Gregorian (contrast with apps/admin/src/app/(tenant)/users/
 * _lib/format.ts, which correctly does NOT Buddhist-convert because users.php
 * genuinely uses plain Gregorian `date('d/m/Y', ...)` — a different PHP
 * source with different real behavior).
 */

export type DealStage = 'lead' | 'qualified' | 'proposal' | 'negotiation' | 'closed_won' | 'closed_lost';
export type TicketStatus = 'open' | 'pending' | 'resolved' | 'closed';
export type TicketPriority = 'urgent' | 'high' | 'medium' | 'low';

export const DEAL_STAGES: DealStage[] = ['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];

/** Ported from CRMDashboardService::getPipelineData()'s `$stageLabels`. */
export const STAGE_LABEL_TH: Record<DealStage, string> = {
  lead: 'New Leads',
  qualified: 'Qualified',
  proposal: 'Proposal',
  negotiation: 'Negotiation',
  closed_won: 'Closed Won',
  closed_lost: 'Closed Lost',
};

/** Same 6 stages, but the short label sales-pipeline.php's deal-detail modal uses (`stageLabels`). */
export const STAGE_DETAIL_LABEL: Record<DealStage, string> = {
  lead: 'New Lead',
  qualified: 'Qualified',
  proposal: 'Proposal',
  negotiation: 'Negotiation',
  closed_won: 'Closed Won',
  closed_lost: 'Closed Lost',
};

interface BadgeStyle {
  bg: string;
  text: string;
}

/** Tailwind color pairs matching the PHP page's own `.badge-*` CSS classes (badge-gray/blue/purple/yellow/green/red). */
const BADGE_COLOR: Record<string, BadgeStyle> = {
  gray: { bg: 'bg-gray-100', text: 'text-gray-600' },
  blue: { bg: 'bg-blue-100', text: 'text-blue-700' },
  purple: { bg: 'bg-purple-100', text: 'text-purple-700' },
  yellow: { bg: 'bg-yellow-100', text: 'text-yellow-700' },
  green: { bg: 'bg-green-100', text: 'text-green-700' },
  red: { bg: 'bg-red-100', text: 'text-red-700' },
};

const STAGE_BADGE_COLOR: Record<DealStage, keyof typeof BADGE_COLOR> = {
  lead: 'gray',
  qualified: 'blue',
  proposal: 'purple',
  negotiation: 'yellow',
  closed_won: 'green',
  closed_lost: 'red',
};

const STATUS_BADGE_COLOR: Record<TicketStatus, keyof typeof BADGE_COLOR> = {
  open: 'blue',
  pending: 'yellow',
  resolved: 'green',
  closed: 'gray',
};

const PRIORITY_BADGE_COLOR: Record<TicketPriority, keyof typeof BADGE_COLOR> = {
  urgent: 'red',
  high: 'red',
  medium: 'yellow',
  low: 'gray',
};

export function stageBadgeClass(stage: string): string {
  const color = BADGE_COLOR[STAGE_BADGE_COLOR[stage as DealStage] ?? 'gray']!;
  return `${color.bg} ${color.text}`;
}

export function ticketStatusBadgeClass(status: string): string {
  const color = BADGE_COLOR[STATUS_BADGE_COLOR[status as TicketStatus] ?? 'gray']!;
  return `${color.bg} ${color.text}`;
}

export function ticketPriorityBadgeClass(priority: string): string {
  const color = BADGE_COLOR[PRIORITY_BADGE_COLOR[priority as TicketPriority] ?? 'gray']!;
  return `${color.bg} ${color.text}`;
}

export function stageLabel(stage: string): string {
  return STAGE_LABEL_TH[stage as DealStage] ?? stage;
}

/** Mirrors JS's `(value || 0).toLocaleString()` used throughout for baht amounts (e.g. sales-pipeline.php's deal-value spans). */
export function formatMoney(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString('en-US') : '0';
}

export function formatCount(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? Math.trunc(n).toLocaleString('en-US') : '0';
}

/** Ported from sales-pipeline.php's `formatDate()` — `toLocaleDateString('th-TH', {day:'numeric', month:'short'})`. */
export function formatDealDate(value: string | Date | null | undefined): string {
  if (!value) return '';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short' }).format(d);
}

/** Ported from service-center.php's `formatDate()` / tickets-list.php's inline `toLocaleDateString('th-TH')` (no options -> locale default numeric date). */
export function formatShortDate(value: string | Date | null | undefined): string {
  if (!value) return '';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat('th-TH').format(d);
}

/** Ported from customers-list.php's `formatDate()` — `{day:'numeric', month:'short', year:'2-digit'}`. */
export function formatCustomerDate(value: string | Date | null | undefined): string {
  if (!value) return '';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }).format(d);
}

export interface SlaFormatResult {
  text: string;
  breached: boolean;
}

/**
 * Ported from service-center.php's `formatSla()` + `isSlaBreached()`
 * (lines 213-224). `hoursLeft` uses `Math.floor(diffMs / 3600000)` exactly —
 * mirrored with the same truncation-toward-zero-on-positive-diff semantics.
 */
export function formatSla(slaDeadline: string | Date | null | undefined, now: Date = new Date()): SlaFormatResult {
  if (!slaDeadline) {
    return { text: '-', breached: false };
  }
  const deadline = slaDeadline instanceof Date ? slaDeadline : new Date(slaDeadline);
  if (Number.isNaN(deadline.getTime())) {
    return { text: '-', breached: false };
  }
  const diffMs = deadline.getTime() - now.getTime();
  if (diffMs < 0) {
    return { text: 'BREACHED', breached: true };
  }
  const hours = Math.floor(diffMs / 3600000);
  return { text: `${hours}h left`, breached: false };
}

/**
 * PHP's `empty($x)` truthiness check for the subset of value shapes this
 * batch's mutations receive (string/number/null/undefined) — 0, '0', '',
 * null, and undefined are all "empty". Used by createDeal/createTicket/
 * addTicketInteraction's required-field validation (CRMDashboardService.php
 * lines 256-260, 448-452, 530-534), which use PHP's `empty()`, NOT `isset()`.
 */
export function isPhpEmpty(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return true;
  if (value === 0 || value === '0' || value === '') return true;
  return false;
}

/**
 * PHP's `isset($x)` check (true for any non-null value, INCLUDING falsy
 * values like 0/''/false) — used by updateDeal/updateTicket's per-field
 * "include in UPDATE" gate (CRMDashboardService.php lines 299-304, 499-504),
 * which is deliberately more permissive than `empty()`.
 */
export function isPhpIsset(value: unknown): boolean {
  return value !== null && value !== undefined;
}
