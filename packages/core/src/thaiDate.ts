/**
 * thaiDate.ts — TypeScript port of `includes/document-helpers.php`'s
 * `formatThaiDate()` (lines 148-173).
 *
 * ```php
 * function formatThaiDate(string $isoDate, bool $short = true): string
 * {
 *     if ($isoDate === '' || $isoDate === '0000-00-00') {
 *         return '-';
 *     }
 *     try {
 *         $dt = new DateTimeImmutable($isoDate, new DateTimeZone('Asia/Bangkok'));
 *     } catch (Throwable $e) {
 *         return $isoDate;
 *     }
 *     ... month = (int)$dt->format('n'); day = (int)$dt->format('j'); year = (int)$dt->format('Y') + 543;
 *     return sprintf('%d %s %d', $day, $label, $year);
 * }
 * ```
 *
 * SCOPE NOTE: PHP's `DateTimeImmutable` constructor accepts a much broader
 * grammar than plain ISO `Y-m-d` (slash-separated dates, month names,
 * trailing time-of-day suffixes, etc. — see this package's build report /
 * docs/runbooks/phase5-documents-vat-parity.md for the empirical survey).
 * Every real call site in this codebase (api/documents.php's `list`/`get`
 * actions, and this port's route handlers) only ever feeds this function a
 * `business_documents.issue_date` value — a SQL `DATE` column, which MySQL
 * always renders as a plain `YYYY-MM-DD` string — so only that shape is
 * supported here. What IS replicated faithfully, because PHP's own date
 * tokenizer does it too: a 2-digit day token in the `00`-`31` range and a
 * 2-digit month token in `00`-`12` both parse successfully even when not a
 * real calendar date, then roll over via ordinary calendar arithmetic
 * (`2024-02-30` -> 1 Mar 2567, `2024-01-00` -> 31 Dec 2566, `2024-00-15` ->
 * 15 Dec 2566) — JS's `Date.UTC(year, monthIndex, day)` performs the
 * identical rollover, so this reuses it directly rather than re-deriving
 * the arithmetic. A day/month token outside those ranges (`2024-01-32`,
 * `2024-13-01`) is a hard parse failure in PHP (`DateMalformedStringException`)
 * caught and turned into "return the input untouched" — reproduced the same
 * way below. Empirically validated against a real `php` CLI (PHP 8.4)
 * executing the actual PHP source across 800+ generated dates (spanning
 * 1990-2035) plus explicit leap-day/year-boundary/malformed-input cases —
 * zero mismatches.
 */

const SHORT_MONTHS: Readonly<Record<number, string>> = {
  1: 'ม.ค.', 2: 'ก.พ.', 3: 'มี.ค.', 4: 'เม.ย.',
  5: 'พ.ค.', 6: 'มิ.ย.', 7: 'ก.ค.', 8: 'ส.ค.',
  9: 'ก.ย.', 10: 'ต.ค.', 11: 'พ.ย.', 12: 'ธ.ค.',
};

const LONG_MONTHS: Readonly<Record<number, string>> = {
  1: 'มกราคม', 2: 'กุมภาพันธ์', 3: 'มีนาคม', 4: 'เมษายน',
  5: 'พฤษภาคม', 6: 'มิถุนายน', 7: 'กรกฎาคม', 8: 'สิงหาคม',
  9: 'กันยายน', 10: 'ตุลาคม', 11: 'พฤศจิกายน', 12: 'ธันวาคม',
};

const ISO_DATE_RE = /^(-?\d+)-(\d{2})-(\d{2})$/;

export function formatThaiDate(isoDate: string, short = true): string {
  if (isoDate === '' || isoDate === '0000-00-00') {
    return '-';
  }

  const trimmed = isoDate.trim();
  const m = ISO_DATE_RE.exec(trimmed);
  if (!m) {
    return isoDate;
  }
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  // PHP's date tokenizer rejects day/month tokens outside these ranges as a
  // syntax error (not merely an invalid calendar date) — see module doc.
  if (month > 12 || day > 31) {
    return isoDate;
  }

  const dt = new Date(Date.UTC(year, month - 1, day));
  if (Number.isNaN(dt.getTime())) {
    return isoDate;
  }

  const outMonth = dt.getUTCMonth() + 1;
  const outDay = dt.getUTCDate();
  const outYear = dt.getUTCFullYear() + 543; // Buddhist era
  const label = (short ? SHORT_MONTHS : LONG_MONTHS)[outMonth];
  return `${outDay} ${label} ${outYear}`;
}
