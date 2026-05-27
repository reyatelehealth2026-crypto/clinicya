<?php
/**
 * Shop Tax Info API — per-tenant business identity printed on tax documents.
 * API ตั้งค่าข้อมูลกิจการสำหรับเอกสารทางภาษี
 *
 *   GET  ?action=get   → returns current shop_tax_info row (or empty defaults)
 *   POST ?action=save  → upsert per-tenant row
 *
 * @package Documents
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance()->getConnection();
$logger = ActivityLogger::getInstance($db);

// Resolve current tenant. Mirror header.php precedence with 3-tier fallback.
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
if ($lineAccountId <= 0 && isset($_GET['line_account_id'])) {
    $lineAccountId = (int)$_GET['line_account_id'];
}
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $lineAccountId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) { /* ignore */ }
}
if ($lineAccountId <= 0) {
    try {
        $row = $db->query('SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $lineAccountId = (int)($row['id'] ?? 0);
    } catch (\Throwable $e) { /* ignore */ }
}
if ($lineAccountId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'no_line_account', 'message' => 'ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน']);
    exit;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0) ?: null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function shop_tax_input(): array
{
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== ''
        && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $d = json_decode($raw, true);
        if (is_array($d)) {
            return $d;
        }
    }
    return $_POST;
}

switch ($action) {

    case 'get':
    {
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $stmt = $db->prepare('SELECT * FROM shop_tax_info WHERE line_account_id = ?');
        $stmt->execute([$lineAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = [
                'line_account_id'    => $lineAccountId,
                'business_name'      => '',
                'business_name_en'   => '',
                'tax_id'             => '',
                'branch_code'        => '00000',
                'address'            => '',
                'phone'              => '',
                'email'              => '',
                'logo_url'           => '',
                'authorized_signer'  => '',
                'signer_position'    => '',
                'is_vat_registered'  => 0,
                'default_vat_rate'   => 7.00,
            ];
        }
        echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    case 'save':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $i = shop_tax_input();

        $stmt = $db->prepare(
            "INSERT INTO shop_tax_info
                (line_account_id, business_name, business_name_en, tax_id, branch_code, address,
                 phone, email, logo_url, authorized_signer, signer_position,
                 is_vat_registered, default_vat_rate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                business_name = VALUES(business_name),
                business_name_en = VALUES(business_name_en),
                tax_id = VALUES(tax_id),
                branch_code = VALUES(branch_code),
                address = VALUES(address),
                phone = VALUES(phone),
                email = VALUES(email),
                logo_url = VALUES(logo_url),
                authorized_signer = VALUES(authorized_signer),
                signer_position = VALUES(signer_position),
                is_vat_registered = VALUES(is_vat_registered),
                default_vat_rate = VALUES(default_vat_rate)"
        );
        $stmt->execute([
            $lineAccountId,
            substr((string)($i['business_name']     ?? ''), 0, 255),
            substr((string)($i['business_name_en']  ?? ''), 0, 255),
            substr((string)($i['tax_id']            ?? ''), 0, 20),
            substr((string)($i['branch_code']       ?? '00000'), 0, 20) ?: '00000',
            (string)($i['address']                  ?? ''),
            substr((string)($i['phone']             ?? ''), 0, 50),
            substr((string)($i['email']             ?? ''), 0, 100),
            substr((string)($i['logo_url']          ?? ''), 0, 500),
            substr((string)($i['authorized_signer'] ?? ''), 0, 255),
            substr((string)($i['signer_position']   ?? ''), 0, 100),
            !empty($i['is_vat_registered']) ? 1 : 0,
            (float)($i['default_vat_rate'] ?? 7.00),
        ]);

        $logger->logData('update', 'ปรับข้อมูลภาษีของร้าน', [
            'entity_type'    => 'shop_tax_info',
            'line_account_id'=> $lineAccountId,
        ]);

        $stmt = $db->prepare('SELECT * FROM shop_tax_info WHERE line_account_id = ?');
        $stmt->execute([$lineAccountId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        exit;
}
