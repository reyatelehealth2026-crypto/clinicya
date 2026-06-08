<?php
/**
 * miniapp-bootstrap.php — resolve the LIFF id + line_account for the CURRENT host.
 *
 * The Mini App is one static bundle served on each tenant's own subdomain
 * (e.g. clinicya.re-ya.com/miniapp/). Before liff.init() the app must know WHICH
 * LIFF id to use — that depends on the tenant, which is determined by the host.
 * config/database.php resolves the subdomain → tenant DB, so we just read the
 * tenant's primary (default / first with a real LIFF) channel here.
 *
 * MUST be called on the tenant's own host (window.location.origin), NOT the
 * baked API base — that's how the host disambiguates the tenant.
 *
 * 2026-06-02
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Also honour ?la= for explicit deep links (root-domain entry without a subdomain).
require_once __DIR__ . '/../bootstrap/route_by_account.php';

function bsReal($liff): bool
{
    $s = trim((string) $liff);
    return $s !== '' && stripos($s, 'PENDING') !== 0;
}

try {
    $db = Database::getInstance()->getConnection();

    $cands = $db->query(
        "SELECT id, name, channel_id, liff_id, is_default
         FROM line_accounts WHERE is_active = 1
         ORDER BY is_default DESC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Prefer a channel that actually has a real LIFF id; else first active.
    $chosen = null;
    foreach ($cands as $c) {
        if (bsReal($c['liff_id'] ?? '')) { $chosen = $c; break; }
    }
    if (!$chosen && $cands) {
        $chosen = $cands[0];
    }

    if (!$chosen) {
        echo json_encode(['success' => false, 'liff_id' => '', 'line_account_id' => 0]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'liff_id' => (string) ($chosen['liff_id'] ?? ''),
        'line_account_id' => (int) $chosen['id'],
        'channel_id' => (string) ($chosen['channel_id'] ?? ''),
        'name' => (string) ($chosen['name'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[miniapp-bootstrap] ' . $e->getMessage());
    echo json_encode(['success' => false, 'liff_id' => '', 'line_account_id' => 0]);
}
