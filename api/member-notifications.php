<?php
/**
 * Member Notifications Preferences API
 *
 * Actions (POST JSON or form-urlencoded):
 *   action=opt_in          — subscribe LINE user to OA push (legacy)
 *   action=opt_out         — unsubscribe (legacy)
 *   action=status          — query master enabled flag (legacy)
 *   action=get_preferences — return all 7 per-category preferences
 *   action=set_preference  — update one category: { category, enabled }
 *   action=set_preferences — bulk update: { preferences: { category: bool, ... } }
 *
 * Categories: order_updates, promotions, appointment_reminders,
 *             med_reminders, health_tips, price_alerts, restock_alerts
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
// Route root-domain (Mini App / LIFF) requests to the tenant DB by line_account_id.
require_once dirname(__DIR__) . '/bootstrap/route_by_account.php';

const NOTIFICATION_CATEGORIES = [
    'order_updates',
    'promotions',
    'appointment_reminders',
    'med_reminders',
    'health_tips',
    'price_alerts',
    'restock_alerts',
];

// health_tips defaults OFF (matches screenshot 3). Everything else defaults ON.
const DEFAULT_PREFERENCES = [
    'order_updates' => 1,
    'promotions' => 1,
    'appointment_reminders' => 1,
    'med_reminders' => 1,
    'health_tips' => 0,
    'price_alerts' => 1,
    'restock_alerts' => 1,
];

function jsonFail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function ensureSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `member_notification_preferences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_user_id` VARCHAR(64) NOT NULL,
            `line_account_id` INT NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_account` (`line_user_id`, `line_account_id`),
            INDEX `idx_account` (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Add per-category columns idempotently
    $stmt = $db->query("SHOW COLUMNS FROM `member_notification_preferences`");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    foreach (NOTIFICATION_CATEGORIES as $col) {
        if (!in_array($col, $existing, true)) {
            $default = DEFAULT_PREFERENCES[$col];
            $db->exec(
                "ALTER TABLE `member_notification_preferences`
                 ADD COLUMN `{$col}` TINYINT(1) NOT NULL DEFAULT {$default}"
            );
        }
    }
}

function fetchPreferences(PDO $db, string $lineUserId, int $lineAccountId): array
{
    $cols = '`enabled`, `' . implode('`, `', NOTIFICATION_CATEGORIES) . '`';
    $stmt = $db->prepare(
        "SELECT {$cols} FROM member_notification_preferences
         WHERE line_user_id = ? AND line_account_id = ? LIMIT 1"
    );
    $stmt->execute([$lineUserId, $lineAccountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'enabled' => true,
            'preferences' => array_map('boolval', DEFAULT_PREFERENCES),
        ];
    }

    $prefs = [];
    foreach (NOTIFICATION_CATEGORIES as $cat) {
        $prefs[$cat] = isset($row[$cat]) ? (bool) $row[$cat] : (bool) DEFAULT_PREFERENCES[$cat];
    }
    return [
        'enabled' => (bool) $row['enabled'],
        'preferences' => $prefs,
    ];
}

// Parse input
$raw = file_get_contents('php://input') ?: '';
$input = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
$input = array_merge($_GET, $_POST, $input);

$action = $input['action'] ?? '';
$lineUserId = trim((string) ($input['line_user_id'] ?? ''));
$lineAccountId = isset($input['line_account_id']) ? (int) $input['line_account_id'] : 0;

$validActions = ['opt_in', 'opt_out', 'status', 'get_preferences', 'set_preference', 'set_preferences'];

if ($lineUserId === '') {
    jsonFail('Missing line_user_id');
}
if ($lineAccountId <= 0) {
    jsonFail('Missing or invalid line_account_id');
}
if (!in_array($action, $validActions, true)) {
    jsonFail('Invalid action');
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    jsonFail('Database connection failed', 500);
}

try {
    ensureSchema($db);
} catch (Exception $e) {
    jsonFail('Schema init failed: ' . $e->getMessage(), 500);
}

try {
    if ($action === 'status') {
        $data = fetchPreferences($db, $lineUserId, $lineAccountId);
        echo json_encode(['success' => true, 'enabled' => $data['enabled'], 'message' => 'ok']);
        exit;
    }

    if ($action === 'opt_in' || $action === 'opt_out') {
        $enabled = $action === 'opt_in' ? 1 : 0;
        $stmt = $db->prepare(
            'INSERT INTO member_notification_preferences (line_user_id, line_account_id, enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        );
        $stmt->execute([$lineUserId, $lineAccountId, $enabled]);
        echo json_encode([
            'success' => true,
            'enabled' => (bool) $enabled,
            'message' => $enabled ? 'เปิดรับการแจ้งเตือนแล้ว' : 'ปิดรับการแจ้งเตือนแล้ว',
        ]);
        exit;
    }

    if ($action === 'get_preferences') {
        $data = fetchPreferences($db, $lineUserId, $lineAccountId);
        echo json_encode([
            'success' => true,
            'enabled' => $data['enabled'],
            'preferences' => $data['preferences'],
            'message' => 'ok',
        ]);
        exit;
    }

    if ($action === 'set_preference') {
        $category = (string) ($input['category'] ?? '');
        if (!in_array($category, NOTIFICATION_CATEGORIES, true)) {
            jsonFail('Invalid category');
        }
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $stmt = $db->prepare(
            "INSERT INTO member_notification_preferences (line_user_id, line_account_id, `{$category}`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `{$category}` = VALUES(`{$category}`)"
        );
        $stmt->execute([$lineUserId, $lineAccountId, $enabled]);
        $data = fetchPreferences($db, $lineUserId, $lineAccountId);
        echo json_encode([
            'success' => true,
            'preferences' => $data['preferences'],
            'message' => 'บันทึกการตั้งค่าแล้ว',
        ]);
        exit;
    }

    if ($action === 'set_preferences') {
        $payload = $input['preferences'] ?? [];
        if (!is_array($payload) || empty($payload)) {
            jsonFail('Missing preferences map');
        }
        $assignments = [];
        $values = [];
        foreach ($payload as $cat => $val) {
            if (!in_array($cat, NOTIFICATION_CATEGORIES, true)) continue;
            $assignments[] = "`{$cat}` = VALUES(`{$cat}`)";
            $values[$cat] = !empty($val) ? 1 : 0;
        }
        if (empty($values)) jsonFail('No valid categories supplied');

        $cols = '`line_user_id`, `line_account_id`, `' . implode('`, `', array_keys($values)) . '`';
        $placeholders = '?, ?' . str_repeat(', ?', count($values));
        $sql = "INSERT INTO member_notification_preferences ({$cols})
                VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE " . implode(', ', $assignments);
        $params = array_merge([$lineUserId, $lineAccountId], array_values($values));
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $data = fetchPreferences($db, $lineUserId, $lineAccountId);
        echo json_encode([
            'success' => true,
            'preferences' => $data['preferences'],
            'message' => 'บันทึกการตั้งค่าแล้ว',
        ]);
        exit;
    }
} catch (Exception $e) {
    jsonFail('Operation failed: ' . $e->getMessage(), 500);
}
