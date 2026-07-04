<?php
/**
 * api/pharmacy-outlook-export.php — machine-readable export of the
 * "Pharmacy Outlook" cross-tenant anonymized aggregate.
 *
 * Phase 4 (Scale & Ecosystem Lock-in): a read-only JSON twin of
 * admin/pharmacy-outlook.php, for platform-team tooling (spreadsheets,
 * BI dashboards, scheduled reports) that need the raw numbers rather
 * than the rendered HTML page.
 *
 * PDPA safety: this endpoint does NOT re-implement or bypass any
 * aggregation logic — it calls PharmacyOutlook::buildReport(), the exact
 * same call the HTML page makes, so the same min-cohort suppression
 * (PharmacyOutlook::MIN_COHORT) always applies. No per-tenant or
 * per-customer data is ever selected by PharmacyOutlook, and none is
 * added here. Shaping into the JSON envelope is done by the pure
 * PharmacyOutlook::toExportArray() helper, which exposes only a
 * suppressed-bucket COUNT — never the suppressed bucket names.
 *
 * GET params (both optional; default = current calendar month):
 *   from=YYYY-MM-DD
 *   to=YYYY-MM-DD
 *
 * Auth: requires $_SESSION['platform_user_id'] (Platform Owner team only).
 * Never linked from tenant-facing admin pages or exposed to tenant users.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PharmacyOutlook.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'platform_owner_only']);
    exit;
}

$today = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$fromDate = $_GET['from'] ?? $today->format('Y-m-01');
$toDate   = $_GET['to'] ?? $today->format('Y-m-d');

$isValidDate = static fn (string $d): bool => (bool) DateTime::createFromFormat('Y-m-d', $d)
    && DateTime::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;

if (!$isValidDate($fromDate) || !$isValidDate($toDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_date_range']);
    exit;
}

try {
    $outlook = new PharmacyOutlook(Database::platform()->getConnection());
    $report = $outlook->buildReport($fromDate, $toDate);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'report_generation_failed']);
    exit;
}

echo json_encode([
    'success' => true,
    'period' => [
        'from' => $fromDate,
        'to'   => $toDate,
    ],
    'min_cohort' => PharmacyOutlook::MIN_COHORT,
    'data' => PharmacyOutlook::toExportArray($report),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
