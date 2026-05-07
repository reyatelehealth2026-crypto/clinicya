<?php
/**
 * Broadcast link redirect endpoint.
 *
 * Public URL hit by LINE message recipients. Resolves the short token,
 * records a click row, then 302s to the original URL.
 *
 * Query parameters:
 *   t  – required, hex token from broadcast_links
 *   u  – optional, LINE user id (line_user_id) for attribution
 *
 * Responses:
 *   302 → original_url (success)
 *   404 → invalid/unknown token
 *   503 → DB unavailable
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BroadcastLinkTracker.php';

$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
if ($token === '' || !preg_match('/^[a-f0-9]{8,32}$/i', $token)) {
    http_response_code(404);
    echo 'Invalid link';
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('broadcast_redirect: DB error: ' . $e->getMessage());
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

$tracker = new BroadcastLinkTracker($db);
$link = $tracker->resolve($token);
if (!$link) {
    http_response_code(404);
    echo 'Link not found';
    exit;
}

// Resolve identity (best-effort).
$lineUserId = isset($_GET['u']) ? trim((string)$_GET['u']) : null;
$userId = null;
$lineAccountId = null;

if ($lineUserId) {
    try {
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userId = (int)$row['id'];
            $lineAccountId = $row['line_account_id'] !== null ? (int)$row['line_account_id'] : null;
        }
    } catch (Exception $e) {
        // ignore — recordClick still works without identity
    }
}

if ($lineAccountId === null) {
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM broadcast_campaigns WHERE id = ?");
        $stmt->execute([(int)$link['campaign_id']]);
        $val = $stmt->fetchColumn();
        $lineAccountId = $val !== false && $val !== null ? (int)$val : null;
    } catch (Exception $e) {}
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
if ($ip && strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

$tracker->recordClick($link, $userId, $lineUserId, $lineAccountId, $ua, $ip);

// Cache-busting redirect: prevent intermediaries from caching a permanent
// redirect that would skip the click log on subsequent taps.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $link['original_url'], true, 302);
exit;
