<?php
/**
 * User Addresses API
 *
 * Multi-address management for the LINE Mini App profile (primary + up to
 * 3 secondary addresses per user).
 *
 * Endpoints:
 *   GET  ?action=list&line_user_id=...&line_account_id=...
 *        → { success, addresses: [ {label,name,phone,address,subdistrict,district,province,postcode}, ... ] }
 *
 *   POST { action: 'upsert', line_user_id, line_account_id, label,
 *          name, phone, address, subdistrict, district, province, postcode }
 *        → { success, message, address }
 *
 *   POST { action: 'delete', line_user_id, line_account_id, label }
 *        → { success, message }
 *
 * Replaces the legacy member-tier modal — that UI is now an address book.
 */

// CRITICAL: error handling FIRST
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// `const` at file scope is NOT hoisted in PHP — must declare BEFORE the
// dispatch switch that uses it (functions ARE hoisted, so the order below is
// dispatch → helper functions → fine, but the const must come first).
const VALID_LABELS = ['primary', 'secondary_1', 'secondary_2', 'secondary_3'];

$pdo = Database::getInstance()->getConnection();
createUserAddressesTable($pdo);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'list':
                listAddresses($pdo);
                break;
            default:
                jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'upsert':
                upsertAddress($pdo, $input);
                break;
            case 'delete':
                deleteAddress($pdo, $input);
                break;
            default:
                jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
        }
    } else {
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (Throwable $e) {
    error_log('user-addresses error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal error'], 500);
}

// ─── Helpers ─────────────────────────────────────────────────────────────

function createUserAddressesTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_user_id VARCHAR(50) NOT NULL,
        line_account_id INT DEFAULT 0,
        label VARCHAR(20) NOT NULL,
        name VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        subdistrict VARCHAR(100) DEFAULT NULL,
        district VARCHAR(100) DEFAULT NULL,
        province VARCHAR(100) DEFAULT NULL,
        postcode VARCHAR(10) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_label (line_user_id, line_account_id, label),
        INDEX idx_line_user (line_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function listAddresses(PDO $pdo): void
{
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = (int) ($_GET['line_account_id'] ?? 0);

    if ($lineUserId === '') {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT label, name, phone, address, subdistrict, district, province, postcode, updated_at
         FROM user_addresses
         WHERE line_user_id = ? AND line_account_id = ?
         ORDER BY FIELD(label, 'primary','secondary_1','secondary_2','secondary_3')"
    );
    $stmt->execute([$lineUserId, $lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'success'   => true,
        'addresses' => $rows,
    ]);
}

function upsertAddress(PDO $pdo, array $input): void
{
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);
    $label = $input['label'] ?? '';

    if ($lineUserId === '') {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    if (!in_array($label, VALID_LABELS, true)) {
        jsonResponse(['success' => false, 'error' => 'Invalid label'], 400);
        return;
    }

    $cleanText = function ($v, int $max): ?string {
        if ($v === null) return null;
        $v = trim((string) $v);
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    };

    $name        = $cleanText($input['name'] ?? null, 255);
    $phone       = $cleanText($input['phone'] ?? null, 20);
    $address     = $cleanText($input['address'] ?? null, 2000);
    $subdistrict = $cleanText($input['subdistrict'] ?? null, 100);
    $district    = $cleanText($input['district'] ?? null, 100);
    $province    = $cleanText($input['province'] ?? null, 100);
    $postcode    = $cleanText($input['postcode'] ?? null, 10);

    // Require at least one meaningful field
    if (!$name && !$address && !$phone) {
        jsonResponse(['success' => false, 'error' => 'At least one of name/phone/address is required'], 400);
        return;
    }

    $pdo->prepare("
        INSERT INTO user_addresses
            (line_user_id, line_account_id, label, name, phone, address, subdistrict, district, province, postcode)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            phone = VALUES(phone),
            address = VALUES(address),
            subdistrict = VALUES(subdistrict),
            district = VALUES(district),
            province = VALUES(province),
            postcode = VALUES(postcode),
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $lineUserId, $lineAccountId, $label,
        $name, $phone, $address, $subdistrict, $district, $province, $postcode,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'บันทึกที่อยู่แล้ว',
        'address' => [
            'label'       => $label,
            'name'        => $name,
            'phone'       => $phone,
            'address'     => $address,
            'subdistrict' => $subdistrict,
            'district'    => $district,
            'province'    => $province,
            'postcode'    => $postcode,
        ],
    ]);
}

function deleteAddress(PDO $pdo, array $input): void
{
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);
    $label = $input['label'] ?? '';

    if ($lineUserId === '' || !in_array($label, VALID_LABELS, true)) {
        jsonResponse(['success' => false, 'error' => 'Invalid request'], 400);
        return;
    }
    // Refuse to delete primary outright — empty it instead via upsert.
    if ($label === 'primary') {
        jsonResponse(['success' => false, 'error' => 'ลบที่อยู่หลักไม่ได้ — ใช้แก้ไขแทน'], 400);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM user_addresses WHERE line_user_id = ? AND line_account_id = ? AND label = ?");
    $stmt->execute([$lineUserId, $lineAccountId, $label]);

    jsonResponse([
        'success' => true,
        'message' => 'ลบที่อยู่สำรองแล้ว',
        'deleted' => $stmt->rowCount(),
    ]);
}

function jsonResponse(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
