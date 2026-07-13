/**
 * executiveLogic.ts — pure, DB-free logic ported from
 * includes/dashboard/executive.php: the problem-message keyword list, the
 * top-issues keyword tag cloud counter, and the KPI-tile accent/label/alert
 * threshold rules. Kept separate from executiveData.ts (the DB-touching
 * fetch layer) so it can be unit tested in isolation, per the brief.
 */

/**
 * Verbatim port of executive.php line 100's `$problemKeywords` array (order
 * matters only in that it must be the exact same 10 keywords — the query
 * ORs them together, order is not observable in the result).
 */
export const PROBLEM_KEYWORDS = [
  'ปัญหา',
  'ไม่พอใจ',
  'ช้า',
  'แย่',
  'ผิด',
  'เสีย',
  'ไม่ได้',
  'รอนาน',
  'ไม่ตอบ',
  'complaint',
  'problem',
] as const;

/**
 * Pure predicate mirroring the SQL `m.content LIKE '%keyword%' OR …` filter
 * executive.php builds (line ~103-111). The real query path
 * (executiveData.ts) still does this filtering in SQL via the same
 * PROBLEM_KEYWORDS list — this function exists so the keyword logic itself
 * is testable without a DB, and as a single source of truth for "does this
 * content count as a problem message".
 *
 * MySQL's `LIKE` under this codebase's `utf8mb4_unicode_ci` collation is
 * case-INsensitive; PHP's Thai keywords are case-invariant anyway, but the
 * two English keywords ('complaint'/'problem') are not, so this lower-cases
 * both sides to match MySQL's collation behaviour exactly.
 */
export function isProblemMessageContent(content: string | null | undefined): boolean {
  if (!content) {
    return false;
  }
  const lower = content.toLowerCase();
  return PROBLEM_KEYWORDS.some((keyword) => lower.includes(keyword.toLowerCase()));
}

/** Verbatim port of executive.php lines 195-204's `$issueKeywords` array (insertion order matters — see computeTopIssues). */
export const TOP_ISSUE_KEYWORDS = ['สินค้า', 'ราคา', 'จัดส่ง', 'ชำระเงิน', 'คืนสินค้า', 'สอบถาม', 'แนะนำ', 'ปัญหา'] as const;

export interface TopIssue {
  keyword: string;
  count: number;
}

/**
 * Port of executive.php's top-issues counter (lines 189-217):
 *   foreach ($messages as $msg) foreach ($issueKeywords as $keyword => &$count)
 *     if (strpos($msg, $keyword) !== false) $count++;
 *   arsort($issueKeywords); array_slice($issueKeywords, 0, 5, true);
 *
 * `strpos` is case-sensitive substring search — matched here with a plain
 * (case-sensitive) `.includes()`, not the lower-cased comparison
 * isProblemMessageContent() uses (different PHP source, different
 * semantics: TOP_ISSUE_KEYWORDS are all Thai, where case doesn't apply).
 *
 * PHP 8+ sort functions are stability-guaranteed, and Array.prototype.sort
 * has been stable in all evergreen JS engines since ES2019 — a descending
 * sort over the keywords in their original insertion order reproduces
 * arsort()'s tie-break behaviour exactly. The returned array keeps ALL top-5
 * slots (including zero-count ones, if fewer than 5 keywords matched
 * anything) — callers should filter to `count > 0` at render time, exactly
 * like executive.php's `<?php if ($cnt > 0): ?>` guard around each chip.
 */
export function computeTopIssues(messageContents: readonly (string | null | undefined)[]): TopIssue[] {
  const counts = new Map<string, number>(TOP_ISSUE_KEYWORDS.map((keyword) => [keyword, 0]));

  for (const content of messageContents) {
    if (!content) {
      continue;
    }
    for (const keyword of TOP_ISSUE_KEYWORDS) {
      if (content.includes(keyword)) {
        counts.set(keyword, (counts.get(keyword) ?? 0) + 1);
      }
    }
  }

  return TOP_ISSUE_KEYWORDS.map((keyword) => ({ keyword, count: counts.get(keyword) ?? 0 }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 5);
}

export type ResponseAccent = 'emerald' | 'amber' | 'rose';

export interface ResponseTimeStyle {
  accent: ResponseAccent;
  label: string;
}

/**
 * Port of executive.php line 219-220:
 *   $responseAccent = $avgResponseTime <= 5 ? 'emerald' : ($avgResponseTime <= 15 ? 'amber' : 'rose');
 *   $responseLabel  = $avgResponseTime <= 5 ? 'ดีมาก' : ($avgResponseTime <= 15 ? 'พอใช้' : 'ต้องปรับปรุง');
 */
export function responseTimeStyle(avgResponseTimeMinutes: number): ResponseTimeStyle {
  if (avgResponseTimeMinutes <= 5) {
    return { accent: 'emerald', label: 'ดีมาก' };
  }
  if (avgResponseTimeMinutes <= 15) {
    return { accent: 'amber', label: 'พอใช้' };
  }
  return { accent: 'rose', label: 'ต้องปรับปรุง' };
}

export interface CountAlertStyle {
  accent: 'rose' | 'emerald';
  /** True => render with the alert (red border/background) tile modifier. */
  alert: boolean;
}

/**
 * Shared threshold for both the "unread" tile (`$unreadAccent = $unread > 0
 * ? 'rose' : 'emerald'`, `$unreadAttrs = $unread > 0 ? [...alert] : []`) and
 * the "problem count" tile (identical `$problemAccent`/`$problemAttrs`
 * shape one function down in executive.php) — both are `count > 0 ?
 * rose+alert : emerald+no-alert`, so one function covers both call sites.
 */
export function countAlertStyle(count: number): CountAlertStyle {
  return count > 0 ? { accent: 'rose', alert: true } : { accent: 'emerald', alert: false };
}
