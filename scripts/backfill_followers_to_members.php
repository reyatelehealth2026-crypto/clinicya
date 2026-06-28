<?php
/**
 * backfill_followers_to_members.php — promote existing followers who never
 * registered into members (member_id + is_registered + registered_at + tier),
 * matching the new "follow = member" behaviour. NO welcome points.
 *
 * Runs across every tenant DB + the legacy DB. Idempotent (skips already-members).
 *
 * Usage: php scripts/backfill_followers_to_members.php           (dry-run)
 *        php scripts/backfill_followers_to_members.php --apply
 */
declare(strict_types=1);
@set_time_limit(0);
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

$APPLY = in_array('--apply', $argv ?? [], true);

function connectDb(string $dbName): PDO
{
    return new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function nextMemberId(PDO $db, $lineAccountId): string
{
    $prefix = 'M';
    $year = date('y');
    $stmt = $db->prepare("SELECT member_id FROM users WHERE member_id LIKE ? AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY member_id DESC LIMIT 1");
    $stmt->execute([$prefix . $year . '%', $lineAccountId]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($last && preg_match('/^M\d{2}(\d{5})$/', (string) $last['member_id'], $m)) ? (intval($m[1]) + 1) : 1;
    return $prefix . $year . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

function backfillOne(PDO $db, bool $apply): string
{
    if (!$db->query("SHOW TABLES LIKE 'account_followers'")->fetch() || !$db->query("SHOW TABLES LIKE 'users'")->fetch()) {
        return 'skipped (no users/account_followers)';
    }
    $cols = array_flip($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN));
    if (!isset($cols['is_registered']) || !isset($cols['member_id'])) {
        return 'skipped (no membership columns)';
    }

    // Followers who have not been registered as members yet.
    $rows = $db->query("
        SELECT DISTINCT u.id, COALESCE(u.line_account_id, 0) AS la
        FROM users u
        JOIN account_followers af ON af.user_id = u.id AND af.is_following = 1
        WHERE (u.is_registered IS NULL OR u.is_registered = 0)
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return 'nothing to backfill';
    }
    if (!$apply) {
        return 'would upgrade ' . count($rows) . ' follower(s)';
    }

    $extra = '';
    if (isset($cols['registered_at'])) {
        $extra .= ', registered_at = NOW()';
    }
    if (isset($cols['member_tier'])) {
        $extra .= ", member_tier = 'bronze'";
    }
    $upd = $db->prepare("UPDATE users SET member_id = ?, is_registered = 1{$extra} WHERE id = ?");

    $done = 0;
    foreach ($rows as $r) {
        $memberId = nextMemberId($db, (int) $r['la']);
        $upd->execute([$memberId, (int) $r['id']]);
        $done++;
    }
    return "MIGRATED ({$done} upgraded)";
}

echo ($APPLY ? "=== APPLY ===" : "=== DRY RUN ===") . "\n\n";

$dbNames = [];
try {
    $platform = connectDb(PLATFORM_DB_NAME);
    $stmt = $platform->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME');
    $stmt->execute([TENANT_DB_PREFIX . '%']);
    $dbNames = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (\Throwable $e) {
    echo "! enumerate tenants: {$e->getMessage()}\n";
}
if (defined('DB_NAME') && !in_array(DB_NAME, $dbNames, true)) {
    array_unshift($dbNames, DB_NAME);
}

$total = 0;
foreach ($dbNames as $dbName) {
    try {
        $status = backfillOne(connectDb($dbName), $APPLY);
        if (preg_match('/(\d+) upgraded/', $status, $m)) {
            $total += (int) $m[1];
        }
        echo sprintf("  [%-26s] %s\n", $dbName, $status);
    } catch (\Throwable $e) {
        echo sprintf("  [%-26s] ERROR: %s\n", $dbName, $e->getMessage());
    }
}
echo "\n=== " . ($APPLY ? "Done: {$total} upgraded" : "Re-run with --apply") . " ===\n";
