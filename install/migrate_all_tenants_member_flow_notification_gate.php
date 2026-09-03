<?php
/**
 * Multi-tenant runner: NotificationGate + Member Flex flow schema, EVERY DB.
 *
 * รวมงานของสอง migration ไว้ในรอบเดียว เพราะ cron ชุดเดียวกันใช้ทั้งคู่
 * Applies both migrations in one pass — the same reminder crons need both:
 *   database/migration_2026-09-02_notification_gate.sql   (PR #82)
 *   database/migration_2026-09-03_member_flex_flow.sql    (PR #83)
 *
 * Idempotent: ตรวจ information_schema ก่อนทุก DDL ข้ามตาราง/คอลัมน์ที่ไม่มี
 * และทน DB ที่ migrate ไปแล้วบางส่วน — รันซ้ำได้ปลอดภัย
 *
 * ทุก statement เป็น additive ล้วน (เพิ่มตาราง/คอลัมน์/index) ไม่ต้อง rollback
 *
 * Run once on server:
 *   /usr/local/bin/php install/migrate_all_tenants_member_flow_notification_gate.php
 *   /usr/local/bin/php install/migrate_all_tenants_member_flow_notification_gate.php --dry-run
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';
const EXTRA_DB_NAMES   = ['zrismpsz_demo'];

$DRY_RUN = in_array('--dry-run', $argv, true);

function connectDb(string $dbName): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $s->execute([$db, $table]);
    return (bool) $s->fetchColumn();
}

function columnType(PDO $pdo, string $db, string $table, string $column): ?string
{
    $s = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $s->execute([$db, $table, $column]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function indexExists(PDO $pdo, string $db, string $table, string $index): bool
{
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $s->execute([$db, $table, $index]);
    return (bool) $s->fetchColumn();
}

/** DDL ของ notification_log — ตรงกับ database/migration_2026-09-02_notification_gate.sql */
function notificationLogDdl(): string
{
    return "CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'บัญชี LINE ที่เป็นเจ้าของข้อความ',
  `user_id` int(11) NOT NULL COMMENT 'ลูกค้าปลายทาง (users.id)',
  `line_user_id` varchar(50) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'คีย์ใน NotificationGate::POLICY เช่น medication_dose',
  `dedupe_key` varchar(191) DEFAULT NULL COMMENT 'คีย์กันส่งซ้ำ ภายใน 24 ชม.',
  `decision` enum('sent','skipped','failed') NOT NULL,
  `reason` varchar(40) NOT NULL COMMENT 'ok | pref_off | quiet_hours | duplicate | daily_cap | no_line_user | send_failed',
  `detail` text DEFAULT NULL COMMENT 'ข้อความ error เมื่อส่งไม่สำเร็จ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_daily_cap` (`user_id`, `decision`, `created_at`),
  KEY `idx_dedupe` (`user_id`, `event_type`, `dedupe_key`, `created_at`),
  KEY `idx_account_time` (`line_account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ทุกการตัดสินใจส่ง/ไม่ส่งของ NotificationGate — หลักฐาน PDPA'";
}

/**
 * ทำงานทั้งสอง migration กับหนึ่ง DB
 *
 * @return array{0:string[],1:string[]} [applied, skipped]
 */
function migrateDb(PDO $pdo, string $db, bool $dryRun): array
{
    $applied = [];
    $skipped = [];

    $run = static function (string $label, string $sql) use ($pdo, $dryRun, &$applied) {
        if ($dryRun) {
            $applied[] = 'WOULD:' . $label;
            return;
        }
        $pdo->exec($sql);
        $applied[] = $label;
    };

    // ---- PR #82: notification_log -------------------------------------
    if (tableExists($pdo, $db, 'notification_log')) {
        $skipped[] = 'notification_log (exists)';
    } else {
        $run('notification_log', notificationLogDdl());
    }

    // ---- PR #82: quiet hours on user_notification_preferences ---------
    if (!tableExists($pdo, $db, 'user_notification_preferences')) {
        $skipped[] = 'quiet_hours (no user_notification_preferences)';
    } else {
        // ใส่ AFTER restock_alerts เฉพาะเมื่อคอลัมน์นั้นมีจริงใน tenant นี้
        $after = columnType($pdo, $db, 'user_notification_preferences', 'restock_alerts') !== null
            ? ' AFTER `restock_alerts`'
            : '';

        $hasStart = columnType($pdo, $db, 'user_notification_preferences', 'quiet_hours_start') !== null;
        if ($hasStart) {
            $skipped[] = 'quiet_hours_start (exists)';
        } else {
            $run('quiet_hours_start', "ALTER TABLE `user_notification_preferences`
                ADD COLUMN `quiet_hours_start` time DEFAULT '21:00:00'
                COMMENT 'เริ่มช่วงห้ามรบกวน'" . $after);
        }

        if (columnType($pdo, $db, 'user_notification_preferences', 'quiet_hours_end') !== null) {
            $skipped[] = 'quiet_hours_end (exists)';
        } else {
            // quiet_hours_start เพิ่งถูกเพิ่มด้านบนในโหมดจริง จึงอ้าง AFTER ได้
            $afterStart = ($hasStart || !$dryRun) ? ' AFTER `quiet_hours_start`' : $after;
            $run('quiet_hours_end', "ALTER TABLE `user_notification_preferences`
                ADD COLUMN `quiet_hours_end` time DEFAULT '08:00:00'
                COMMENT 'สิ้นสุดช่วงห้ามรบกวน'" . $afterStart);
        }
    }

    // ---- PR #83: medication_taken_history ------------------------------
    // schema มาจาก tenant template — ถ้า tenant ไหนไม่มีตารางนี้ ให้ข้ามและ log
    // ห้ามสร้างตารางเอง
    if (!tableExists($pdo, $db, 'medication_taken_history')) {
        $skipped[] = 'member_flex_flow (no medication_taken_history)';
        return [$applied, $skipped];
    }

    // MemberPostbackRouter::logAdherence เขียน line_account_id เสมอ
    // tenant template มีคอลัมน์นี้ (int(11) NOT NULL DEFAULT 1) แต่ DB legacy
    // บางตัวสร้างก่อนหน้านั้นจึงยังไม่มี — เติมให้ตรงเทมเพลต ไม่งั้นปุ่ม
    // "ทานแล้ว" จะ throw เฉพาะ DB นั้น
    if (columnType($pdo, $db, 'medication_taken_history', 'line_account_id') !== null) {
        $skipped[] = 'mth.line_account_id (exists)';
    } else {
        $after = columnType($pdo, $db, 'medication_taken_history', 'id') !== null
            ? ' AFTER `id`'
            : '';
        $run('mth.line_account_id', "ALTER TABLE `medication_taken_history`
            ADD COLUMN `line_account_id` int(11) NOT NULL DEFAULT 1
            COMMENT 'บัญชี LINE เจ้าของรายการ — ให้ตรงกับ tenant template'" . $after);
    }

    $statusType = columnType($pdo, $db, 'medication_taken_history', 'status');
    if ($statusType === null) {
        $skipped[] = 'status enum (no column)';
    } elseif (stripos($statusType, "'snoozed'") !== false) {
        $skipped[] = 'status enum (has snoozed)';
    } else {
        $run('status+snoozed', "ALTER TABLE `medication_taken_history`
            MODIFY COLUMN `status` ENUM('taken','skipped','missed','snoozed') DEFAULT 'taken'
            COMMENT 'taken=ทานแล้ว, skipped=ข้าม, missed=ไม่ตอบ, snoozed=ขอเลื่อน'");
    }

    if (columnType($pdo, $db, 'medication_taken_history', 'snooze_until') !== null) {
        $skipped[] = 'snooze_until (exists)';
    } else {
        $after = columnType($pdo, $db, 'medication_taken_history', 'taken_at') !== null
            ? ' AFTER `taken_at`'
            : '';
        $run('snooze_until', "ALTER TABLE `medication_taken_history`
            ADD COLUMN `snooze_until` DATETIME NULL DEFAULT NULL
            COMMENT 'เวลาที่ต้องเตือนซ้ำ (เฉพาะ status=snoozed)'" . $after);
    }

    $indexes = [
        'idx_mth_snooze'        => ['status', 'snooze_until'],
        'idx_mth_user_taken'    => ['user_id', 'taken_at'],
        'idx_mth_reminder_slot' => ['reminder_id', 'scheduled_time', 'taken_at'],
    ];
    foreach ($indexes as $name => $cols) {
        if (indexExists($pdo, $db, 'medication_taken_history', $name)) {
            $skipped[] = $name . ' (exists)';
            continue;
        }
        $missing = [];
        foreach ($cols as $c) {
            // snooze_until เพิ่งถูกเพิ่มด้านบนในโหมดจริง จึงถือว่ามีแล้ว
            if ($c === 'snooze_until' && !$dryRun) {
                continue;
            }
            if (columnType($pdo, $db, 'medication_taken_history', $c) === null) {
                $missing[] = $c;
            }
        }
        if ($missing) {
            $skipped[] = $name . ' (no column: ' . implode(',', $missing) . ')';
            continue;
        }
        $colList = '`' . implode('`, `', $cols) . '`';
        $run($name, "ALTER TABLE `medication_taken_history` ADD INDEX `$name` ($colList)");
    }

    return [$applied, $skipped];
}

echo "=== NotificationGate + Member Flex flow — ALL TENANTS ===\n";
echo $DRY_RUN ? "MODE: DRY RUN (no writes)\n\n" : "MODE: APPLY\n\n";

// Enumerate every tenant database + demo/legacy DB.
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

$extras = EXTRA_DB_NAMES;
if (defined('DB_NAME')) {
    $extras[] = DB_NAME;
}
foreach ($extras as $extra) {
    if ($extra && !in_array($extra, $dbNames, true)) {
        array_unshift($dbNames, $extra);
    }
}

if (!$dbNames) {
    echo "No databases found to migrate.\n";
    exit(1);
}

echo 'Databases to process: ' . count($dbNames) . "\n\n";

$migrated = 0;
$untouched = 0;
$failed = 0;
foreach ($dbNames as $dbName) {
    try {
        [$applied, $skipped] = migrateDb(connectDb($dbName), $dbName, $DRY_RUN);
        if ($applied) {
            $migrated++;
            $tag = 'MIGRATED';
        } else {
            $untouched++;
            $tag = 'SKIPPED ';
        }
        printf(
            "  [%-24s] %s %s\n",
            $dbName,
            $tag,
            $applied ? implode(', ', $applied) : '(' . count($skipped) . ' already current)'
        );
        // เตือนเฉพาะกรณีข้ามเพราะ schema ขาด ไม่ใช่ข้ามเพราะทำไปแล้ว
        foreach ($skipped as $s) {
            if (strpos($s, '(no ') !== false) {
                printf("  %-26s ! %s\n", '', $s);
            }
        }
    } catch (\Throwable $e) {
        $failed++;
        printf("  [%-24s] FAILED   %s\n", $dbName, $e->getMessage());
    }
}

echo "\n=== Done: {$migrated} migrated, {$untouched} already current, {$failed} failed, "
    . count($dbNames) . " total ===\n";
exit($failed > 0 ? 1 : 0);
