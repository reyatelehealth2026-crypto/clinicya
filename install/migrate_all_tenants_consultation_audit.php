<?php
/**
 * Multi-tenant runner: create the consultation_audit table in EVERY DB.
 *
 * Telepharmacy compliance (issue #15 / PR #22): append-only, hash-chained
 * audit trail for AI consultations. The ConsultationAudit service lazily
 * auto-creates this table on first use; this runner creates it explicitly
 * across all tenants so the audit trail is provably in place before first use.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. Safe to re-run.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_consultation_audit.php
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

const CONSULTATION_AUDIT_DDL = "CREATE TABLE IF NOT EXISTS consultation_audit (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    line_account_id INT NULL,
    session_id      BIGINT UNSIGNED NULL,
    user_id         BIGINT NULL,
    actor_type      ENUM('customer','ai','pharmacist','system') NOT NULL,
    actor_id        INT NULL,
    event_type      VARCHAR(40) NOT NULL,
    payload         JSON NULL,
    content_hash    CHAR(64) NOT NULL,
    prev_hash       CHAR(64) NULL,
    created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_session (session_id, id),
    KEY idx_account_created (line_account_id, created_at),
    KEY idx_user (user_id),
    KEY idx_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only, hash-chained audit trail for AI tele-pharmacy consultations (PDPA)'";

function connectDb(string $dbName): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

echo "=== consultation_audit — ALL TENANTS ===\n\n";

$dbNames = [];
try {
    $platform = connectDb(PLATFORM_DB_NAME);
    $stmt = $platform->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME'
    );
    $stmt->execute([TENANT_DB_PREFIX . '%']);
    $dbNames = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (\Throwable $e) {
    echo "! Could not enumerate tenant DBs via platform: {$e->getMessage()}\n";
}

if (defined('DB_NAME') && !in_array(DB_NAME, $dbNames, true)) {
    array_unshift($dbNames, DB_NAME);
}

if (!$dbNames) {
    echo "No databases found to migrate.\n";
    exit(1);
}

$ok = 0;
$created = 0;
$failed = 0;
foreach ($dbNames as $dbName) {
    try {
        $pdo = connectDb($dbName);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$dbName, 'consultation_audit']);
        $existed = (bool) $stmt->fetchColumn();

        $pdo->exec(CONSULTATION_AUDIT_DDL);
        $ok++;
        if (!$existed) {
            $created++;
        }
        echo sprintf("  %-40s %s\n", $dbName, $existed ? 'already present' : 'CREATED');
    } catch (\Throwable $e) {
        $failed++;
        echo sprintf("  %-40s FAILED: %s\n", $dbName, $e->getMessage());
    }
}

echo "\nDone. DBs: " . count($dbNames) . " | ok: {$ok} (created {$created}) | failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
