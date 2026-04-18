<?php
/**
 * JSON API — ซิงค์ catalog จาก Odoo ทีละช่วง (สำหรับ progress bar ใน inventory/?tab=catalog-sync)
 * action=range_step เท่านั้น (โหลดช่วงตัวเลข)
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized — กรุณาเข้าสู่ระบบใหม่']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($raw)) {
    $raw = $_POST;
}

$action = (string) ($raw['action'] ?? '');
if ($action !== 'range_step') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
    exit;
}

if (file_exists(__DIR__ . '/../classes/OdooProductService.php')) {
    require_once __DIR__ . '/../classes/OdooProductService.php';
} else {
    echo json_encode(['success' => false, 'message' => 'OdooProductService missing']);
    exit;
}

$db = Database::getInstance()->getConnection();
$currentBotId = (int) ($_SESSION['current_bot_id'] ?? 1);
$cacheTable = 'odoo_products_cache';

$hasDrugTypeCol = false;
try {
    $check = $db->query("SHOW COLUMNS FROM {$cacheTable} LIKE 'drug_type'");
    $hasDrugTypeCol = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $hasDrugTypeCol = false;
}

$syncStart = max(1, (int) ($raw['sync_start'] ?? 1));
$syncLimit = (int) ($raw['sync_limit'] ?? 100);
if (!in_array($syncLimit, [100, 200, 500], true)) {
    $syncLimit = 100;
}
$processed = max(0, (int) ($raw['processed'] ?? 0));

if ($processed >= $syncLimit) {
    echo json_encode([
        'success' => true,
        'done' => true,
        'processed' => $processed,
        'total' => $syncLimit,
        'label_th' => 'เสร็จสิ้น',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$chunk = min(50, $syncLimit - $processed);
$cursor = $syncStart + $processed;

try {
    $service = new OdooProductService($db, $currentBotId);

    $upsertCols = "(line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at";
    $upsertVals = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()";
    $upsertUpd = "product_id=VALUES(product_id), sku=VALUES(sku), name=VALUES(name), generic_name=VALUES(generic_name), barcode=VALUES(barcode), category=VALUES(category), list_price=VALUES(list_price), online_price=VALUES(online_price), saleable_qty=VALUES(saleable_qty), is_active=VALUES(is_active), last_synced_at=NOW()";

    if ($hasDrugTypeCol) {
        $upsertCols .= ', drug_type';
        $upsertVals .= ', ?';
        $upsertUpd .= ', drug_type=VALUES(drug_type)';
    }
    $upsertCols .= ')';
    $upsertVals .= ')';

    $upsertSql = "INSERT INTO {$cacheTable} {$upsertCols} VALUES {$upsertVals} ON DUPLICATE KEY UPDATE {$upsertUpd}";
    $upsertStmt = $db->prepare($upsertSql);

    $bindProduct = function (array $p) use ($upsertStmt, $currentBotId, $service, $hasDrugTypeCol) {
        $drugType = $hasDrugTypeCol ? $service->deriveDrugType($p) : null;
        $bindings = [
            $currentBotId,
            (string) ($p['product_id'] ?? ''),
            (string) ($p['product_code'] ?? ''),
            (string) ($p['sku'] ?? ''),
            (string) ($p['name'] ?? ''),
            (string) ($p['generic_name'] ?? ''),
            (string) ($p['barcode'] ?? ''),
            (string) ($p['category'] ?? ''),
            (float) ($p['list_price'] ?? 0),
            (float) ($p['online_price'] ?? 0),
            (float) ($p['saleable_qty'] ?? 0),
            !empty($p['active']) ? 1 : 0,
        ];
        if ($hasDrugTypeCol) {
            $bindings[] = $drugType;
        }
        $upsertStmt->execute($bindings);
    };

    $result = $service->getProductsByRange($cursor, $chunk);
    $products = $result['products'] ?? [];
    $fetched = count($products);
    $saved = 0;
    foreach ($products as $p) {
        $bindProduct($p);
        $saved++;
    }

    $newProcessed = $processed + $chunk;
    $done = $newProcessed >= $syncLimit;
    $lastCode = $cursor + $chunk - 1;
    $labelTh = sprintf('กำลังซิงค์รหัสสินค้า %04d – %04d (ดึงได้ %d แถว, บันทึก %d)', $cursor, $lastCode, $fetched, $saved);

    echo json_encode([
        'success' => true,
        'done' => $done,
        'processed' => $newProcessed,
        'total' => $syncLimit,
        'cursor_start' => $cursor,
        'chunk_size' => $chunk,
        'fetched' => $fetched,
        'saved' => $saved,
        'label_th' => $labelTh,
        'summary_th' => $done
            ? sprintf(
                'โหลดช่วงรหัส %d – %d เสร็จแล้ว',
                $syncStart,
                $syncStart + $syncLimit - 1
            )
            : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
