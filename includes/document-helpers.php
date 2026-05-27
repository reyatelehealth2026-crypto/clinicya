<?php
/**
 * Document helpers — shared utilities for the accounting document suite.
 * เครื่องมือพื้นฐานสำหรับเอกสารบัญชี (ใบเสนอราคา / ใบกำกับภาษี ฯลฯ)
 *
 * Functions:
 *   genDocNumber(PDO $db, int $lineAccountId, string $docType, ?DateTimeInterface $when = null): string
 *       Atomic per-tenant/per-month/per-type running number.
 *       Uses SELECT ... FOR UPDATE inside a transaction to prevent races.
 *
 *   calcVAT(float $subtotal, float $vatRate = 7.00, bool $vatInclusive = false): array
 *       Returns ['base' => ..., 'vat' => ..., 'total' => ...] all rounded to 2dp.
 *
 *   formatThaiDate(string $isoDate, bool $short = true): string
 *       "2026-05-24" -> "24 พ.ค. 2569" (short) or "24 พฤษภาคม 2569" (long).
 *
 *   docTypeLabel(string $docType): string  — Thai display label
 *   docStatusLabel(string $status): string — Thai display label
 *   docStatusBadge(string $status): string — HTML span with Tailwind colours
 *
 * @package Documents
 * @version 1.0.0
 */

declare(strict_types=1);

if (!defined('REYA_DOCUMENT_TYPES')) {
    define('REYA_DOCUMENT_TYPES', [
        'QT'  => ['label' => 'ใบเสนอราคา',          'label_en' => 'Quotation',           'prefix' => 'QT',  'group' => 'sales'],
        'BL'  => ['label' => 'ใบวางบิล',             'label_en' => 'Billing Note',        'prefix' => 'BL',  'group' => 'sales'],
        'INV' => ['label' => 'ใบแจ้งหนี้',           'label_en' => 'Invoice',             'prefix' => 'INV', 'group' => 'sales'],
        'RE'  => ['label' => 'ใบเสร็จรับเงิน',       'label_en' => 'Receipt',             'prefix' => 'RE',  'group' => 'sales'],
        'TAX' => ['label' => 'ใบกำกับภาษี',          'label_en' => 'Tax Invoice',         'prefix' => 'TAX', 'group' => 'sales'],
        'DN'  => ['label' => 'ใบเพิ่มหนี้',          'label_en' => 'Debit Note',          'prefix' => 'DN',  'group' => 'sales'],
        'CN'  => ['label' => 'ใบลดหนี้',             'label_en' => 'Credit Note',         'prefix' => 'CN',  'group' => 'sales'],
        'PO'  => ['label' => 'ใบสั่งซื้อ',           'label_en' => 'Purchase Order',      'prefix' => 'PO',  'group' => 'purchase'],
        'GR'  => ['label' => 'ใบรับสินค้า',          'label_en' => 'Goods Receipt',       'prefix' => 'GR',  'group' => 'purchase'],
        'DNP' => ['label' => 'ใบเพิ่มหนี้ (ซื้อ)',   'label_en' => 'Debit Note (P)',      'prefix' => 'DNP', 'group' => 'purchase'],
        'CNP' => ['label' => 'ใบลดหนี้ (ซื้อ)',      'label_en' => 'Credit Note (P)',     'prefix' => 'CNP', 'group' => 'purchase'],
    ]);
}

/**
 * Generate the next document number atomically for a tenant + type + current month.
 *
 * Pattern: {PREFIX}-{YYMM}-{seq:4d}  e.g. INV-2605-0042
 * Year-month is Buddhist-tail (พ.ศ.) per spec: 2569 -> "26", May -> "05".
 *
 * This MUST be called inside the caller's own PDO transaction OR will start one
 * for itself. Uses SELECT ... FOR UPDATE on document_sequences row to serialise.
 *
 * @throws InvalidArgumentException when doc_type is unknown
 * @throws RuntimeException on DB failure
 */
function genDocNumber(PDO $db, int $lineAccountId, string $docType, ?DateTimeInterface $when = null): string
{
    $docType = strtoupper(trim($docType));
    if (!isset(REYA_DOCUMENT_TYPES[$docType])) {
        throw new InvalidArgumentException("Unknown doc_type: {$docType}");
    }
    if ($lineAccountId <= 0) {
        throw new InvalidArgumentException('lineAccountId must be > 0');
    }

    $now = $when ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
    // Buddhist year (พ.ศ.) tail — last 2 digits, then month.
    $buddhistYear = (int)$now->format('Y') + 543;
    $yearMonth = sprintf('%02d%02d', $buddhistYear % 100, (int)$now->format('n'));

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }

    try {
        // Ensure row exists (UNIQUE on tenant+type+month). Use INSERT IGNORE so a
        // concurrent inserter doesn't crash us — we'll SELECT FOR UPDATE next.
        // NB: `year_month` is a reserved MySQL keyword (used in INTERVAL … YEAR_MONTH),
        // so it MUST be backtick-quoted in every reference or the parser rejects it.
        $stmt = $db->prepare(
            'INSERT IGNORE INTO document_sequences (line_account_id, doc_type, `year_month`, last_seq)
             VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$lineAccountId, $docType, $yearMonth]);

        // Lock & read.
        $stmt = $db->prepare(
            'SELECT id, last_seq FROM document_sequences
             WHERE line_account_id = ? AND doc_type = ? AND `year_month` = ?
             FOR UPDATE'
        );
        $stmt->execute([$lineAccountId, $docType, $yearMonth]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('document_sequences row missing after INSERT IGNORE');
        }
        $nextSeq = ((int)$row['last_seq']) + 1;

        $stmt = $db->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?');
        $stmt->execute([$nextSeq, (int)$row['id']]);

        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return sprintf('%s-%s-%04d', $docType, $yearMonth, $nextSeq);
}

/**
 * Calculate VAT.
 *
 * @param float $subtotal     Pre-VAT base (or VAT-inclusive total when $vatInclusive=true)
 * @param float $vatRate      Percent, e.g. 7.00
 * @param bool  $vatInclusive When true, $subtotal already includes VAT — back-calc it out
 * @return array{base: float, vat: float, total: float}
 */
function calcVAT(float $subtotal, float $vatRate = 7.00, bool $vatInclusive = false): array
{
    $rate = max(0.0, $vatRate) / 100.0;
    if ($vatInclusive) {
        $base = $subtotal / (1 + $rate);
        $vat  = $subtotal - $base;
        $total = $subtotal;
    } else {
        $base = $subtotal;
        $vat  = $subtotal * $rate;
        $total = $subtotal + $vat;
    }
    return [
        'base'  => round($base, 2),
        'vat'   => round($vat, 2),
        'total' => round($total, 2),
    ];
}

/**
 * Format an ISO date (Y-m-d) as Thai Buddhist-era date.
 *   "2026-05-24" -> "24 พ.ค. 2569" (short) or "24 พฤษภาคม 2569" (long)
 *
 * Returns the input string untouched on parse failure.
 */
function formatThaiDate(string $isoDate, bool $short = true): string
{
    if ($isoDate === '' || $isoDate === '0000-00-00') {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($isoDate, new DateTimeZone('Asia/Bangkok'));
    } catch (Throwable $e) {
        return $isoDate;
    }
    static $shortMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];
    static $longMonths = [
        1 => 'มกราคม',  2 => 'กุมภาพันธ์', 3 => 'มีนาคม',  4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน',   7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม',    11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $month = (int)$dt->format('n');
    $day   = (int)$dt->format('j');
    $year  = (int)$dt->format('Y') + 543;
    $label = $short ? $shortMonths[$month] : $longMonths[$month];
    return sprintf('%d %s %d', $day, $label, $year);
}

function docTypeLabel(string $docType): string
{
    $docType = strtoupper(trim($docType));
    return REYA_DOCUMENT_TYPES[$docType]['label'] ?? $docType;
}

function docStatusLabel(string $status): string
{
    static $map = [
        'pending_approval' => 'รออนุมัติ',
        'approved'         => 'อนุมัติ',
        'cancelled'        => 'ยกเลิก',
    ];
    return $map[$status] ?? $status;
}

/**
 * Render a Tailwind-styled status badge (no <?php tags around it).
 */
function docStatusBadge(string $status): string
{
    static $classes = [
        'pending_approval' => 'bg-amber-100 text-amber-800 border-amber-200',
        'approved'         => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'cancelled'        => 'bg-rose-100 text-rose-800 border-rose-200',
    ];
    $cls = $classes[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
    $label = htmlspecialchars(docStatusLabel($status), ENT_QUOTES, 'UTF-8');
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border ' . $cls . '">' . $label . '</span>';
}

/**
 * Format a money amount as Thai-locale string with 2 decimals.
 */
function formatMoney(float $amount): string
{
    return number_format($amount, 2, '.', ',');
}

/**
 * Compute one line-item totals consistently across API + UI.
 *   line_total = (qty * unit_price) * (1 - discount_percent/100) - discount_amount
 * Returns rounded 2dp.
 */
function computeLineTotal(float $qty, float $unitPrice, float $discountPercent = 0.0, float $discountAmount = 0.0): float
{
    $gross = $qty * $unitPrice;
    if ($discountPercent > 0) {
        $gross -= $gross * ($discountPercent / 100.0);
    }
    $gross -= $discountAmount;
    return round(max(0.0, $gross), 2);
}
