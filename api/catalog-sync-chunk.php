<?php
/**
 * JSON API — ซิงค์ Odoo catalog ทีละขั้น (progress bar ใน inventory/?tab=catalog-sync)
 *
 * action:
 *   range_step       — โหลดช่วงรหัส (sync_start + processed …)
 *   incremental_step — incremental ตาม state (ทีละกลุ่ม 50 รหัส)
 *   resync_step      — re-sync รหัสที่มีใน cache (ทีละรหัสต่อคำขอ Odoo; จัดกลุ่มต่อ HTTP นี้)
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
$allowed = ['range_step', 'incremental_step', 'resync_step'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action', 'allowed' => $allowed]);
    exit;
}

if (!file_exists(__DIR__ . '/../classes/OdooProductService.php')) {
    echo json_encode(['success' => false, 'message' => 'OdooProductService missing']);
    exit;
}
require_once __DIR__ . '/../classes/OdooProductService.php';

$db = Database::getInstance()->getConnection();
$currentBotId = (int) ($_SESSION['current_bot_id'] ?? 1);
$cacheTable = 'odoo_products_cache';
$stateTable = 'odoo_products_sync_state';

$hasDrugTypeCol = false;
try {
    $check = $db->query("SHOW COLUMNS FROM {$cacheTable} LIKE 'drug_type'");
    $hasDrugTypeCol = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $hasDrugTypeCol = false;
}

/**
 * @return array{0: Closure, 1: OdooProductService}
 */
function catalog_sync_chunk_create_binder(PDO $db, int $lineAccountId, string $cacheTable, bool $hasDrugTypeCol): array
{
    $service = new OdooProductService($db, $lineAccountId);

    $upsertCols = '(line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at';
    $upsertVals = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()';
    $upsertUpd = 'product_id=VALUES(product_id), sku=VALUES(sku), name=VALUES(name), generic_name=VALUES(generic_name), barcode=VALUES(barcode), category=VALUES(category), list_price=VALUES(list_price), online_price=VALUES(online_price), saleable_qty=VALUES(saleable_qty), is_active=VALUES(is_active), last_synced_at=NOW()';

    if ($hasDrugTypeCol) {
        $upsertCols .= ', drug_type';
        $upsertVals .= ', ?';
        $upsertUpd .= ', drug_type=VALUES(drug_type)';
    }
    $upsertCols .= ')';
    $upsertVals .= ')';

    $upsertSql = "INSERT INTO {$cacheTable} {$upsertCols} VALUES {$upsertVals} ON DUPLICATE KEY UPDATE {$upsertUpd}";
    $upsertStmt = $db->prepare($upsertSql);

    $bindProduct = function (array $p) use ($upsertStmt, $lineAccountId, $service, $hasDrugTypeCol) {
        $drugType = $hasDrugTypeCol ? $service->deriveDrugType($p) : null;
        $bindings = [
            $lineAccountId,
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

    return [$bindProduct, $service];
}

try {
    [$bindProduct, $service] = catalog_sync_chunk_create_binder($db, $currentBotId, $cacheTable, $hasDrugTypeCol);

    if ($action === 'range_step') {
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
                ? sprintf('โหลดช่วงรหัส %d – %d เสร็จแล้ว', $syncStart, $syncStart + $syncLimit - 1)
                : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'incremental_step') {
        $incrementalLimit = (int) ($raw['incremental_limit'] ?? 100);
        if (!in_array($incrementalLimit, [50, 100, 200], true)) {
            $incrementalLimit = 100;
        }
        $syncMaxCode = max(100, (int) ($raw['sync_max_code'] ?? 9999));
        $processed = max(0, (int) ($raw['processed'] ?? 0));
        $jobOffsetStart = (int) ($raw['job_offset_start'] ?? 0);

        if ($processed === 0) {
            $stateStmt = $db->prepare("SELECT next_offset FROM {$stateTable} WHERE line_account_id = ? LIMIT 1");
            $stateStmt->execute([$currentBotId]);
            $jobOffsetStart = max(1, (int) $stateStmt->fetchColumn());
        } elseif ($jobOffsetStart <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ต้องมี job_offset_start หลังขั้นแรก']);
            exit;
        }

        if ($processed >= $incrementalLimit) {
            echo json_encode([
                'success' => true,
                'done' => true,
                'processed' => $processed,
                'total' => $incrementalLimit,
                'label_th' => 'รอบ incremental นี้ประมวลผลครบแล้ว',
                'skip_duplicate' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $chunk = min(50, $incrementalLimit - $processed);
        $cursor = $jobOffsetStart + $processed;

        $result = $service->getProductsByRange($cursor, $chunk);
        $products = $result['products'] ?? [];
        $fetched = count($products);
        $saved = 0;
        foreach ($products as $p) {
            $bindProduct($p);
            $saved++;
        }

        $newProcessed = $processed + $chunk;
        $done = $newProcessed >= $incrementalLimit;

        if ($done) {
            $nextOffset = $jobOffsetStart + $incrementalLimit;
            if ($nextOffset > $syncMaxCode) {
                $nextOffset = 1;
            }
            $saveState = $db->prepare(
                "INSERT INTO {$stateTable} (line_account_id, next_offset, last_incremental_sync_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE next_offset=VALUES(next_offset), last_incremental_sync_at=NOW()"
            );
            $saveState->execute([$currentBotId, $nextOffset]);
        }

        $lastCode = $cursor + $chunk - 1;
        $labelTh = sprintf(
            'Incremental: รหัส %04d – %04d (ดึง %d / บันทึก %d) — ความคืบหน้า %d / %d',
            $cursor,
            $lastCode,
            $fetched,
            $saved,
            $newProcessed,
            $incrementalLimit
        );

        echo json_encode([
            'success' => true,
            'done' => $done,
            'processed' => $newProcessed,
            'total' => $incrementalLimit,
            'job_offset_start' => $jobOffsetStart,
            'next_offset' => $done ? ($jobOffsetStart + $incrementalLimit > $syncMaxCode ? 1 : $jobOffsetStart + $incrementalLimit) : null,
            'fetched' => $fetched,
            'saved' => $saved,
            'label_th' => $labelTh,
            'summary_th' => $done
                ? sprintf(
                    'Incremental เสร็จ: ช่วง %d – %d | รอบถัดไปเริ่มที่ %d',
                    $jobOffsetStart,
                    $jobOffsetStart + $incrementalLimit - 1,
                    $jobOffsetStart + $incrementalLimit > $syncMaxCode ? 1 : $jobOffsetStart + $incrementalLimit
                )
                : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'resync_step') {
        $queueKey = 'catalog_resync_queue_' . $currentBotId;
        if (!empty($raw['reset_queue']) && (string) $raw['reset_queue'] === '1') {
            unset($_SESSION[$queueKey]);
        }

        if (empty($_SESSION[$queueKey]) || !is_array($_SESSION[$queueKey])) {
            $existingStmt = $db->prepare(
                "SELECT DISTINCT product_code FROM {$cacheTable}
                 WHERE line_account_id = ? AND product_code <> ''
                 ORDER BY product_code ASC LIMIT 500"
            );
            $existingStmt->execute([$currentBotId]);
            $_SESSION[$queueKey] = $existingStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $queue = $_SESSION[$queueKey];
        $total = count($queue);
        $offset = max(0, (int) ($raw['offset'] ?? 0));

        if ($total === 0) {
            unset($_SESSION[$queueKey]);
            echo json_encode([
                'success' => true,
                'done' => true,
                'total' => 0,
                'offset' => 0,
                'label_th' => 'ไม่มี product_code ใน cache',
                'summary_th' => 'ไม่มีรายการให้ re-sync',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($offset >= $total) {
            unset($_SESSION[$queueKey]);
            echo json_encode([
                'success' => true,
                'done' => true,
                'total' => $total,
                'offset' => $offset,
                'label_th' => 'ครบทุกรหัสแล้ว',
                'summary_th' => sprintf('อัพเดตครบ %d รหัสจาก cache', $total),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ทีละรหัสต่อคำขอ Odoo (1 รหัส = 1 ครั้งเรียก API ภายในรอบนี้)
        $code = (string) $queue[$offset];
        $numeric = (int) ltrim($code, '0');
        $fetched = 0;
        $saved = 0;
        if ($numeric > 0) {
            $result = $service->getProductsByRange($numeric, 1);
            $products = $result['products'] ?? [];
            $fetched = count($products);
            foreach ($products as $p) {
                $bindProduct($p);
                $saved++;
            }
        }

        $newOffset = $offset + 1;
        $done = $newOffset >= $total;
        if ($done) {
            unset($_SESSION[$queueKey]);
        }

        $labelTh = sprintf(
            'Re-sync รหัส %s (%d / %d) — ดึง Odoo %d แถว, บันทึก %d',
            $code,
            $newOffset,
            $total,
            $fetched,
            $saved
        );

        echo json_encode([
            'success' => true,
            'done' => $done,
            'offset' => $newOffset,
            'total' => $total,
            'current_code' => $code,
            'fetched' => $fetched,
            'saved' => $saved,
            'label_th' => $labelTh,
            'summary_th' => $done ? sprintf('อัพเดตครบ %d รหัสจาก cache', $total) : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
