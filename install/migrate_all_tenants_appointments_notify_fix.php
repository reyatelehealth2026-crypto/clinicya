<?php
/**
 * install/migrate_all_tenants_appointments_notify_fix.php  (CLI only)
 *
 * Applies database/migration_2026-07-06_appointments_status_and_notify_unique.sql
 * to EVERY tenant DB (zrismpsz_reya_t_%) + the legacy demo DB.
 *
 *   (1) appointments.status  → add 'in_progress' to the ENUM
 *   (2) notification_settings → de-dupe rows + add UNIQUE KEY (line_account_id)
 *
 * Idempotent & guarded: skips DBs without the table, skips work already done,
 * never fatals a whole run because of one schema. Safe to re-run.
 *
 * Run:  php install/migrate_all_tenants_appointments_notify_fix.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require __DIR__ . '/../config/config.php';

$NEW_ENUM = "enum('pending','confirmed','in_progress','completed','cancelled','no_show')";

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$schemas = $pdo->query(
    "SELECT SCHEMA_NAME FROM information_schema.schemata
      WHERE SCHEMA_NAME LIKE 'zrismpsz_reya_t_%' OR SCHEMA_NAME = 'zrismpsz_demo'
      ORDER BY SCHEMA_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$stats = [
    'schemas' => 0, 'enum_added' => 0, 'enum_ok' => 0, 'appt_absent' => 0,
    'ns_dedup' => 0, 'uniq_added' => 0, 'uniq_ok' => 0, 'ns_absent' => 0, 'errors' => 0,
];

foreach ($schemas as $sch) {
    $stats['schemas']++;
    echo "== {$sch} ==\n";

    // Select the schema so multi-table DELETE (which needs a default DB) works.
    try {
        $pdo->exec("USE `{$sch}`");
    } catch (\Throwable $e) {
        echo "   USE failed: " . $e->getMessage() . " — skip\n";
        $stats['errors']++;
        continue;
    }

    // ---- (1) appointments.status ENUM ----
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE TABLE_SCHEMA = '{$sch}' AND TABLE_NAME = 'appointments'
                AND COLUMN_NAME = 'status'"
        )->fetchColumn();

        if ($col === false) {
            echo "   appointments: absent — skip\n";
            $stats['appt_absent']++;
        } elseif (strpos($col, 'in_progress') !== false) {
            echo "   status ENUM: already has in_progress\n";
            $stats['enum_ok']++;
        } else {
            $pdo->exec("ALTER TABLE `{$sch}`.`appointments`
                        MODIFY COLUMN `status` {$NEW_ENUM} DEFAULT 'pending'");
            echo "   status ENUM: + in_progress  ✓\n";
            $stats['enum_added']++;
        }
    } catch (\Throwable $e) {
        echo "   ENUM ERROR: " . $e->getMessage() . "\n";
        $stats['errors']++;
    }

    // ---- (2) notification_settings dedupe + unique key ----
    try {
        $has = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE TABLE_SCHEMA = '{$sch}' AND TABLE_NAME = 'notification_settings'"
        )->fetchColumn();

        if (!$has) {
            echo "   notification_settings: absent — skip\n";
            $stats['ns_absent']++;
        } else {
            $del = $pdo->exec(
                "DELETE n1 FROM `notification_settings` n1
                   JOIN `notification_settings` n2
                     ON n1.line_account_id = n2.line_account_id AND n1.id < n2.id"
            );
            if ($del > 0) {
                echo "   notification_settings: removed {$del} duplicate row(s)\n";
                $stats['ns_dedup'] += $del;
            }

            $uniq = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.statistics
                  WHERE TABLE_SCHEMA = '{$sch}' AND TABLE_NAME = 'notification_settings'
                    AND INDEX_NAME = 'unique_account'"
            )->fetchColumn();

            if ($uniq > 0) {
                echo "   unique_account: already present\n";
                $stats['uniq_ok']++;
            } else {
                $pdo->exec("ALTER TABLE `{$sch}`.`notification_settings`
                            ADD UNIQUE KEY `unique_account` (`line_account_id`)");
                echo "   unique_account: added  ✓\n";
                $stats['uniq_added']++;
            }
        }
    } catch (\Throwable $e) {
        echo "   NS ERROR: " . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

echo "\nDONE " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
