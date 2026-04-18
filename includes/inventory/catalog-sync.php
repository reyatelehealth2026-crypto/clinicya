<?php
/**
 * Tab: catalog-sync — โหลดรายการสินค้าหลัก
 * Ref: docs/ODOO_PRODUCT_SYNC_PHP.md §12.2
 *
 * Workbench-style page สำหรับโหลด/อัพเดตรายการสินค้าหลักจาก Odoo (`ineco_gc/get_product`)
 * ลง cache `odoo_products_cache`
 *
 * Panels:
 *   1. สถานะการเชื่อมต่อ    — test connection
 *   2. โหลดช่วงตัวเลข      — POST action=odoo_sync_cache (sync_start, sync_limit)
 *   3. โหลดเฉพาะที่เปลี่ยน  — POST action=odoo_sync_incremental
 *   4. อัพเดตที่โหลดแล้ว    — POST action=odoo_resync_existing (ใหม่)
 *   5. สรุป cache          — last_synced_at, total, active, next_offset
 *   6. Drug type rules     — link ไปหน้าจัดการ rules (ถ้ามี)
 *
 * หมายเหตุ: ไม่มี bulk storefront toggle ใน tab นี้ — ไปที่ tab=storefront แทน
 */

if (file_exists(__DIR__ . '/../../classes/OdooProductService.php')) {
    require_once __DIR__ . '/../../classes/OdooProductService.php';
}

$currentBotId = (int) ($_SESSION['current_bot_id'] ?? 1);
$cacheTable   = 'odoo_products_cache';
$stateTable   = 'odoo_products_sync_state';

// ─── Ensure cache tables exist ────────────────────────────────────────────────
$prepError = null;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS {$cacheTable} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT NOT NULL,
        product_id VARCHAR(64) DEFAULT NULL,
        product_code VARCHAR(64) NOT NULL,
        sku VARCHAR(100) DEFAULT NULL,
        name VARCHAR(255) DEFAULT NULL,
        generic_name VARCHAR(255) DEFAULT NULL,
        barcode VARCHAR(100) DEFAULT NULL,
        category VARCHAR(150) DEFAULT NULL,
        list_price DECIMAL(12,2) DEFAULT 0,
        online_price DECIMAL(12,2) DEFAULT 0,
        saleable_qty DECIMAL(12,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        last_synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_line_product_code (line_account_id, product_code),
        INDEX idx_line_name (line_account_id, name),
        INDEX idx_line_sku (line_account_id, sku),
        INDEX idx_line_category (line_account_id, category),
        INDEX idx_line_updated (line_account_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS {$stateTable} (
        line_account_id INT NOT NULL PRIMARY KEY,
        next_offset INT NOT NULL DEFAULT 1,
        last_incremental_sync_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    $prepError = 'ไม่สามารถเตรียมตาราง cache: ' . $e->getMessage();
}

// ─── Check migration (storefront_enabled column) ──────────────────────────────
$hasStorefrontCol = false;
try {
    $check = $db->query("SHOW COLUMNS FROM {$cacheTable} LIKE 'storefront_enabled'");
    $hasStorefrontCol = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $hasStorefrontCol = false;
}
$hasDrugTypeCol = false;
try {
    $check = $db->query("SHOW COLUMNS FROM {$cacheTable} LIKE 'drug_type'");
    $hasDrugTypeCol = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $hasDrugTypeCol = false;
}

// ─── Sync params ───────────────────────────────────────────────────────────────
$syncStart        = max(1,   (int) ($_GET['sync_start']        ?? $_POST['sync_start']        ?? 1));
$syncLimit        = (int)         ($_GET['sync_limit']         ?? $_POST['sync_limit']         ?? 100);
$incrementalLimit = (int)         ($_GET['incremental_limit']  ?? $_POST['incremental_limit']  ?? 100);
$syncMaxCode      = max(100, (int)($_GET['sync_max_code']      ?? $_POST['sync_max_code']      ?? 9999));

if (!in_array($syncLimit,        [100, 200, 500], true)) $syncLimit        = 100;
if (!in_array($incrementalLimit, [50, 100, 200],  true)) $incrementalLimit = 100;

// ─── POST handlers ─────────────────────────────────────────────────────────────
$postAction = $_POST['action'] ?? '';
$message    = $_SESSION['catalog_sync_message'] ?? null;
$errorMsg   = $_SESSION['catalog_sync_error']   ?? null;
unset($_SESSION['catalog_sync_message'], $_SESSION['catalog_sync_error']);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($postAction, ['odoo_sync_cache', 'odoo_sync_incremental', 'odoo_resync_existing'], true)
    && !$prepError
) {
    try {
        $service = new OdooProductService($db, $currentBotId);

        // Build upsert (include drug_type ถ้า migration รันแล้ว)
        $upsertCols = "(line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at";
        $upsertVals = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()";
        $upsertUpd  = "product_id=VALUES(product_id), sku=VALUES(sku), name=VALUES(name), generic_name=VALUES(generic_name), barcode=VALUES(barcode), category=VALUES(category), list_price=VALUES(list_price), online_price=VALUES(online_price), saleable_qty=VALUES(saleable_qty), is_active=VALUES(is_active), last_synced_at=NOW()";

        if ($hasDrugTypeCol) {
            $upsertCols .= ", drug_type";
            $upsertVals .= ", ?";
            $upsertUpd  .= ", drug_type=VALUES(drug_type)";
        }
        $upsertCols .= ")";
        $upsertVals .= ")";
        // ⚠️ ห้ามใส่ storefront_enabled / featured_order / admin_overrides ใน UPDATE
        //    เก็บการตัดสินใจของ admin (toggle/pin/แก้ราคา/แก้ชื่อ) ไม่ให้ sync ครั้งถัดไปเขียนทับ

        $upsertSql = "INSERT INTO {$cacheTable} {$upsertCols} VALUES {$upsertVals} ON DUPLICATE KEY UPDATE {$upsertUpd}";
        $upsertStmt = $db->prepare($upsertSql);

        $bindProduct = function (array $p) use ($upsertStmt, $currentBotId, $service, $hasDrugTypeCol) {
            $drugType = $hasDrugTypeCol ? $service->deriveDrugType($p) : null;
            $bindings = [
                $currentBotId,
                (string) ($p['product_id']   ?? ''),
                (string) ($p['product_code'] ?? ''),
                (string) ($p['sku']          ?? ''),
                (string) ($p['name']         ?? ''),
                (string) ($p['generic_name'] ?? ''),
                (string) ($p['barcode']      ?? ''),
                (string) ($p['category']     ?? ''),
                (float)  ($p['list_price']   ?? 0),
                (float)  ($p['online_price'] ?? 0),
                (float)  ($p['saleable_qty'] ?? 0),
                !empty($p['active']) ? 1 : 0,
            ];
            if ($hasDrugTypeCol) {
                $bindings[] = $drugType;
            }
            $upsertStmt->execute($bindings);
        };

        $fetched = 0;
        $saved   = 0;

        // ─── Action: odoo_sync_cache ──────────────────────────────────────
        if ($postAction === 'odoo_sync_cache') {
            $cursor = $syncStart;
            $remaining = $syncLimit;
            while ($remaining > 0) {
                $chunk  = min(50, $remaining);
                $result = $service->getProductsByRange($cursor, $chunk);
                $products = $result['products'] ?? [];
                $fetched += count($products);
                foreach ($products as $p) {
                    $bindProduct($p);
                    $saved++;
                }
                $cursor    += $chunk;
                $remaining -= $chunk;
            }
            $_SESSION['catalog_sync_message'] = "โหลดช่วง {$syncStart}-" . ($syncStart + $syncLimit - 1) . " สำเร็จ: ดึง {$fetched} รายการ บันทึก {$saved} รายการ";
        }

        // ─── Action: odoo_sync_incremental ────────────────────────────────
        if ($postAction === 'odoo_sync_incremental') {
            $stateStmt = $db->prepare("SELECT next_offset FROM {$stateTable} WHERE line_account_id = ? LIMIT 1");
            $stateStmt->execute([$currentBotId]);
            $offsetStart = max(1, (int) $stateStmt->fetchColumn());

            $cursor    = $offsetStart;
            $remaining = $incrementalLimit;
            while ($remaining > 0) {
                $chunk    = min(50, $remaining);
                $result   = $service->getProductsByRange($cursor, $chunk);
                $products = $result['products'] ?? [];
                $fetched += count($products);
                foreach ($products as $p) {
                    $bindProduct($p);
                    $saved++;
                }
                $cursor    += $chunk;
                $remaining -= $chunk;
            }

            $nextOffset = $offsetStart + $incrementalLimit;
            if ($nextOffset > $syncMaxCode) {
                $nextOffset = 1;
            }
            $saveState = $db->prepare("INSERT INTO {$stateTable}
                (line_account_id, next_offset, last_incremental_sync_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE next_offset=VALUES(next_offset), last_incremental_sync_at=NOW()");
            $saveState->execute([$currentBotId, $nextOffset]);

            $_SESSION['catalog_sync_message'] = "Incremental: ช่วง {$offsetStart}-" . ($offsetStart + $incrementalLimit - 1) . " | ดึง {$fetched} | บันทึก {$saved} | รอบถัดไปเริ่มรหัส {$nextOffset}";
        }

        // ─── Action: odoo_resync_existing ─────────────────────────────────
        if ($postAction === 'odoo_resync_existing') {
            // Re-sync เฉพาะ code ที่เคย sync แล้วใน cache
            $existingStmt = $db->prepare("SELECT DISTINCT product_code FROM {$cacheTable} WHERE line_account_id = ? AND product_code <> '' ORDER BY product_code ASC LIMIT 500");
            $existingStmt->execute([$currentBotId]);
            $existingCodes = $existingStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($existingCodes as $code) {
                $numeric = (int) ltrim($code, '0');
                if ($numeric <= 0) continue;
                $result   = $service->getProductsByRange($numeric, 1);
                $products = $result['products'] ?? [];
                $fetched += count($products);
                foreach ($products as $p) {
                    $bindProduct($p);
                    $saved++;
                }
            }
            $_SESSION['catalog_sync_message'] = "อัพเดตที่โหลดแล้ว: ตรวจสอบ " . count($existingCodes) . " code | ดึง {$fetched} | บันทึก {$saved} รายการ";
        }
    } catch (Exception $e) {
        $_SESSION['catalog_sync_error'] = 'โหลดไม่สำเร็จ: ' . $e->getMessage();
    }

    // Redirect กันส่ง POST ซ้ำ
    $redirectParams = array_merge($_GET, [
        'tab'               => 'catalog-sync',
        'sync_start'        => $syncStart,
        'sync_limit'        => $syncLimit,
        'incremental_limit' => $incrementalLimit,
        'sync_max_code'     => $syncMaxCode,
    ]);
    unset($redirectParams['_']);

    if (!empty($_POST['__ajax']) && (string) $_POST['__ajax'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        $okMsg = $_SESSION['catalog_sync_message'] ?? null;
        $errMsg = $_SESSION['catalog_sync_error'] ?? null;
        unset($_SESSION['catalog_sync_message'], $_SESSION['catalog_sync_error']);
        echo json_encode([
            'success' => $errMsg === null || $errMsg === '',
            'message' => $okMsg,
            'error' => $errMsg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo "<script>window.location.href='?" . http_build_query($redirectParams) . "';</script>";
    exit;
}

// ─── Stats / Info ──────────────────────────────────────────────────────────────
$totalCached   = 0;
$activeCached  = 0;
$storefrontCnt = 0;
$lastSyncedAt  = null;
$lastIncrAt    = null;
$nextOffset    = 1;

if (!$prepError) {
    try {
        $statStmt = $db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(is_active = 1) AS active_cnt
                " . ($hasStorefrontCol ? ", SUM(storefront_enabled = 1) AS sf_cnt" : "") . "
             FROM {$cacheTable}
             WHERE line_account_id = ?"
        );
        $statStmt->execute([$currentBotId]);
        $s = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalCached   = (int) ($s['total']      ?? 0);
        $activeCached  = (int) ($s['active_cnt'] ?? 0);
        $storefrontCnt = (int) ($s['sf_cnt']     ?? 0);

        $syncStmt = $db->prepare("SELECT MAX(last_synced_at) FROM {$cacheTable} WHERE line_account_id = ?");
        $syncStmt->execute([$currentBotId]);
        $lastSyncedAt = $syncStmt->fetchColumn() ?: null;

        $stateStmt = $db->prepare("SELECT next_offset, last_incremental_sync_at FROM {$stateTable} WHERE line_account_id = ? LIMIT 1");
        $stateStmt->execute([$currentBotId]);
        $st = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nextOffset = max(1, (int) ($st['next_offset'] ?? 1));
        $lastIncrAt = $st['last_incremental_sync_at'] ?? null;
    } catch (Exception $e) {
        $prepError = 'อ่านข้อมูล cache ไม่ได้: ' . $e->getMessage();
    }
}

// ─── Test connection config ────────────────────────────────────────────────────
$odooConfigured = false;
try {
    $probe = new OdooProductService($db, $currentBotId);
    $odooConfigured = $probe->isConfigured();
} catch (Exception $e) {
    $odooConfigured = false;
}

$fmtDate = function ($v) {
    return $v ? date('d/m/Y H:i', strtotime($v)) : '—';
};

$catalogSyncChunkUrl = '../api/catalog-sync-chunk.php';
$catalogSyncFormAction = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/inventory/index.php', ENT_QUOTES, 'UTF-8');
?>
<div class="space-y-4">
    <!-- ─── Migration warning ─────────────────────────────────────────────── -->
    <?php if (!$hasStorefrontCol || !$hasDrugTypeCol): ?>
        <div class="bg-yellow-50 border border-yellow-300 rounded-xl p-4 text-sm">
            <div class="font-semibold text-yellow-800 mb-1">
                <i class="fas fa-exclamation-triangle mr-1"></i>แนะนำให้รัน migration ก่อนใช้งานเต็ม
            </div>
            <div class="text-yellow-700">
                ยังไม่พบคอลัมน์ <?= !$hasStorefrontCol ? '<code>storefront_enabled</code>' : '' ?>
                <?= (!$hasStorefrontCol && !$hasDrugTypeCol) ? ', ' : '' ?>
                <?= !$hasDrugTypeCol ? '<code>drug_type</code>' : '' ?>
                ใน <code>odoo_products_cache</code>
            </div>
            <div class="mt-2 bg-white rounded px-3 py-2 font-mono text-xs text-gray-700 inline-block">
                mysql -u &lt;user&gt; -p &lt;db&gt; &lt; database/migration_storefront_split.sql
            </div>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-700">
            <i class="fas fa-check-circle mr-1"></i><?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
            <i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>
    <?php if ($prepError): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
            <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($prepError) ?>
        </div>
    <?php endif; ?>

    <!-- ─── Panel 1: Connection + Cache Summary ───────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Connection status -->
        <?php
        $cfgBaseUrl   = defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : '';
        $cfgApiUser   = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : '';
        $cfgUserToken = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '';
        $cfgEnv       = defined('ODOO_ENVIRONMENT') ? ODOO_ENVIRONMENT : '';
        $baseUrlOk    = $cfgBaseUrl !== '';
        $apiUserOk    = $cfgApiUser !== '';
        $tokenOk      = $cfgUserToken !== '';
        ?>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm font-semibold text-gray-700">
                    <i class="fas fa-plug mr-1 text-gray-400"></i>สถานะการเชื่อมต่อ
                </div>
                <?php if ($odooConfigured): ?>
                    <span class="flex items-center gap-1 text-xs text-green-600">
                        <span class="h-2 w-2 rounded-full bg-green-500"></span>
                        พร้อมใช้งาน
                    </span>
                <?php else: ?>
                    <span class="flex items-center gap-1 text-xs text-red-600">
                        <span class="h-2 w-2 rounded-full bg-red-500"></span>
                        ยังไม่ได้ตั้งค่า
                    </span>
                <?php endif; ?>
            </div>
            <div class="text-xs space-y-1">
                <div>
                    <span class="text-gray-500">ENV:</span>
                    <code class="<?= $cfgEnv === 'production' ? 'text-green-700' : 'text-red-600 font-semibold' ?>"><?= htmlspecialchars($cfgEnv ?: '—') ?></code>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fas <?= $baseUrlOk ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-500' ?>"></i>
                    <span class="text-gray-500">Endpoint:</span>
                    <code class="text-gray-700 truncate"><?= htmlspecialchars($cfgBaseUrl ?: '(ว่าง)') ?></code>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fas <?= $apiUserOk ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-500' ?>"></i>
                    <span class="text-gray-500">API User:</span>
                    <code class="text-gray-700 truncate"><?= $apiUserOk ? htmlspecialchars(substr($cfgApiUser, 0, 20)) . '…' : '(ว่าง)' ?></code>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fas <?= $tokenOk ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-500' ?>"></i>
                    <span class="text-gray-500">Token:</span>
                    <code class="text-gray-700"><?= $tokenOk ? 'set (' . strlen($cfgUserToken) . ' ตัวอักษร)' : '(ว่าง)' ?></code>
                </div>
            </div>
            <?php if (!$odooConfigured): ?>
                <a href="/install/check_odoo_config.php"
                   class="mt-3 block text-center px-3 py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 text-sm font-medium">
                    <i class="fas fa-wrench mr-1"></i>เปิด Diagnostic แก้ config
                </a>
            <?php else: ?>
                <a href="/install/check_odoo_config.php?test=1"
                   class="mt-3 block text-center px-3 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-sm">
                    <i class="fas fa-bolt mr-1"></i>ทดสอบเรียก API (code 0001)
                </a>
            <?php endif; ?>
        </div>

        <!-- Cache summary -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm font-semibold text-gray-700 mb-3">
                <i class="fas fa-database mr-1 text-gray-400"></i>สรุปรายการใน cache
            </div>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="bg-gray-50 rounded-lg p-2">
                    <div class="text-[10px] text-gray-500 uppercase">ทั้งหมด</div>
                    <div class="text-lg font-bold text-gray-800"><?= number_format($totalCached) ?></div>
                </div>
                <div class="bg-green-50 rounded-lg p-2">
                    <div class="text-[10px] text-green-600 uppercase">Active</div>
                    <div class="text-lg font-bold text-green-700"><?= number_format($activeCached) ?></div>
                </div>
                <?php if ($hasStorefrontCol): ?>
                    <div class="bg-blue-50 rounded-lg p-2">
                        <div class="text-[10px] text-blue-600 uppercase">หน้าร้าน</div>
                        <div class="text-lg font-bold text-blue-700"><?= number_format($storefrontCnt) ?></div>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-50 rounded-lg p-2">
                        <div class="text-[10px] text-gray-400 uppercase">หน้าร้าน</div>
                        <div class="text-lg font-bold text-gray-400">—</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-xs text-gray-500 mt-3 space-y-1">
                <div>โหลดล่าสุด: <b><?= $fmtDate($lastSyncedAt) ?></b></div>
                <div>Incremental ล่าสุด: <b><?= $fmtDate($lastIncrAt) ?></b></div>
                <div>รอบถัดไปเริ่มรหัส: <b><?= number_format($nextOffset) ?></b></div>
            </div>
        </div>

        <!-- Related links -->
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm font-semibold text-gray-700 mb-3">
                <i class="fas fa-link mr-1 text-gray-400"></i>หน้าที่เกี่ยวข้อง
            </div>
            <div class="space-y-2 text-sm">
                <a href="?tab=storefront" class="flex items-center justify-between p-2 rounded-lg hover:bg-gray-50 text-blue-600">
                    <span><i class="fas fa-store mr-1"></i>จัดการสินค้าหน้าร้าน</span>
                    <i class="fas fa-arrow-right text-xs"></i>
                </a>
                <?php if ($hasStorefrontCol): ?>
                    <a href="?tab=storefront&price_filter=zero&storefront_status=enabled"
                       class="flex items-center justify-between p-2 rounded-lg hover:bg-amber-50 text-amber-700">
                        <span><i class="fas fa-exclamation-triangle mr-1"></i>ตรวจสินค้าราคา 0 ที่เปิดขายอยู่</span>
                        <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                <?php endif; ?>
                <a href="?tab=stock" class="flex items-center justify-between p-2 rounded-lg hover:bg-gray-50 text-gray-700">
                    <span><i class="fas fa-boxes mr-1"></i>สต็อกสินค้า</span>
                    <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- ─── Panel 2: Range sync ─────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow p-5">
        <div class="text-sm font-semibold text-gray-700 mb-3">
            <i class="fas fa-download mr-1 text-blue-500"></i>โหลดช่วงตัวเลข (sync ทีละช่วง)
        </div>
        <form id="catalogSyncFormRange" method="POST" class="flex flex-wrap items-end gap-3" action="<?= $catalogSyncFormAction ?>">
            <input type="hidden" name="tab" value="catalog-sync">
            <div>
                <label class="text-xs text-gray-500 block mb-1">เริ่มรหัสสินค้า (1–9999)</label>
                <input type="number" name="sync_start" min="1" max="9999" value="<?= (int) $syncStart ?>"
                       class="px-3 py-2 border rounded-lg w-32 text-sm">
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1">จำนวน</label>
                <select name="sync_limit" class="px-3 py-2 border rounded-lg text-sm">
                    <?php foreach ([100, 200, 500] as $n): ?>
                        <option value="<?= $n ?>" <?= $syncLimit === $n ? 'selected' : '' ?>><?= $n ?> รายการ</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="action" value="odoo_sync_cache"
                    class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium">
                <i class="fas fa-sync mr-1"></i>โหลดทันที
            </button>
            <div class="text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>ถ้า 200 รายการใช้เวลาประมาณ 30–60 วินาที
            </div>
        </form>
    </div>

    <!-- ─── Panel 3: Incremental ─────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow p-5">
        <div class="text-sm font-semibold text-gray-700 mb-3">
            <i class="fas fa-bolt mr-1 text-emerald-500"></i>โหลดเฉพาะที่เปลี่ยนล่าสุด (incremental)
        </div>
        <p class="text-xs text-gray-500 mb-3">
            โหลดจากรอบถัดไป (<b><?= number_format($nextOffset) ?></b>) วนไปจนถึง <b><?= number_format($syncMaxCode) ?></b> แล้วเริ่มใหม่ที่ 1
        </p>
        <p class="text-xs text-emerald-700 mb-3">
            <i class="fas fa-bolt mr-1"></i>ซิงค์ผ่าน <code class="bg-emerald-50 px-1 rounded">api/catalog-sync-chunk.php</code> ทีละกลุ่ม (ไม่ POST ยาวไปที่หน้า inventory — แก้ปัญหา HTTP 404)
        </p>
        <form id="catalogSyncFormIncremental" method="POST" class="flex flex-wrap items-end gap-3" action="<?= $catalogSyncFormAction ?>">
            <input type="hidden" name="tab" value="catalog-sync">
            <div>
                <label class="text-xs text-gray-500 block mb-1">จำนวน</label>
                <select name="incremental_limit" class="px-3 py-2 border rounded-lg text-sm">
                    <?php foreach ([50, 100, 200] as $n): ?>
                        <option value="<?= $n ?>" <?= $incrementalLimit === $n ? 'selected' : '' ?>><?= $n ?> รายการ</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1">รหัสสูงสุดก่อนวนกลับ</label>
                <input type="number" name="sync_max_code" min="100" value="<?= (int) $syncMaxCode ?>"
                       class="px-3 py-2 border rounded-lg w-28 text-sm">
            </div>
            <button type="submit" name="action" value="odoo_sync_incremental"
                    class="px-5 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 text-sm font-medium">
                <i class="fas fa-forward mr-1"></i>เริ่ม incremental
            </button>
        </form>
    </div>

    <!-- ─── Panel 4: Re-sync existing ────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow p-5">
        <div class="text-sm font-semibold text-gray-700 mb-3">
            <i class="fas fa-redo mr-1 text-purple-500"></i>อัพเดตรายการที่โหลดแล้วทั้งหมด (re-sync existing)
        </div>
        <p class="text-xs text-gray-500 mb-3">
            โหลดใหม่เฉพาะ <code>product_code</code> ที่เคยมีใน cache (สูงสุด 500 code ต่อรอบ) —
            เหมาะสำหรับอัพเดตราคา/สต็อกทั้งหมดที่ขายอยู่
        </p>
        <p class="text-xs text-purple-800 mb-3">
            <i class="fas fa-code-branch mr-1"></i>ยิง Odoo <b>ทีละรหัส</b> ตามลำดับใน cache — แสดงความคืบหน้า 1/500, 2/500 …
        </p>
        <form id="catalogSyncFormResync" method="POST" action="<?= $catalogSyncFormAction ?>">
            <input type="hidden" name="tab" value="catalog-sync">
            <button type="submit" name="action" value="odoo_resync_existing"
                    class="px-5 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-medium">
                <i class="fas fa-refresh mr-1"></i>อัพเดต code ที่มีอยู่ทั้งหมด
            </button>
            <span class="ml-3 text-xs text-gray-500">
                <i class="fas fa-shield-alt mr-1"></i><b>ไม่แตะ</b> <code>storefront_enabled</code> / <code>featured_order</code> — เก็บการตัดสินใจของ admin
            </span>
        </form>
    </div>
</div>

<!-- Overlay ซิงค์ — progress ชัดเจน + แจ้ง timeout -->
<div id="catalogSyncOverlay" class="hidden fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/50 p-4" aria-hidden="true">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-200">
        <div class="flex items-center gap-2 text-slate-800 font-semibold mb-1">
            <i class="fas fa-cloud-download-alt text-blue-500"></i>
            <span id="catalogSyncOverlayTitle">กำลังโหลดจาก Odoo</span>
        </div>
        <p class="text-xs text-slate-500 mb-4" id="catalogSyncOverlayHint">
            แต่ละช่วงรอสูงสุด 3 นาที — ถ้าเครือข่ายช้าอาจต้องรอนานขึ้น ห้ามปิดหน้าจนกว่าจะเสร็จ
        </p>
        <div class="h-3 w-full rounded-full bg-slate-100 overflow-hidden mb-2">
            <div id="catalogSyncProgressBar" class="h-full rounded-full bg-gradient-to-r from-blue-500 to-emerald-500 transition-[width] duration-300 ease-out" style="width: 0%"></div>
        </div>
        <div class="flex justify-between text-[11px] text-slate-500 mb-3">
            <span id="catalogSyncProgressPct">0%</span>
            <span id="catalogSyncProgressCount">0 / 0</span>
        </div>
        <p class="text-sm text-slate-700 min-h-[3rem] leading-relaxed" id="catalogSyncStatusLine">กำลังเตรียม…</p>
        <p class="text-xs text-amber-700 mt-2 hidden" id="catalogSyncTimeoutNote"></p>
        <div class="mt-4 flex justify-end">
            <button type="button" id="catalogSyncOverlayClose" class="hidden px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">
                ปิด
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var CHUNK_URL = <?= json_encode($catalogSyncChunkUrl, JSON_UNESCAPED_UNICODE) ?>;
    var PER_CHUNK_MS = 180000;
    var RESYNC_STEP_MS = 120000;

    function qs(id) { return document.getElementById(id); }

    function showOverlay(show) {
        var el = qs('catalogSyncOverlay');
        if (!el) return;
        el.classList.toggle('hidden', !show);
        el.setAttribute('aria-hidden', show ? 'false' : 'true');
    }

    function setProgress(pct, processed, total, line, hint) {
        pct = Math.max(0, Math.min(100, pct));
        var bar = qs('catalogSyncProgressBar');
        var p = qs('catalogSyncProgressPct');
        var c = qs('catalogSyncProgressCount');
        var s = qs('catalogSyncStatusLine');
        var note = qs('catalogSyncTimeoutNote');
        if (bar) bar.style.width = pct + '%';
        if (p) p.textContent = Math.round(pct) + '%';
        if (c) c.textContent = (processed != null && total != null) ? (processed + ' / ' + total) : '';
        if (s && line) s.textContent = line;
        if (note) { note.textContent = hint || ''; note.classList.toggle('hidden', !hint); }
    }

    function abortableFetch(url, options, ms) {
        var ctrl = new AbortController();
        var t = setTimeout(function () { ctrl.abort(); }, ms);
        var p = fetch(url, Object.assign({}, options, { signal: ctrl.signal }));
        return p.finally(function () { clearTimeout(t); });
    }

    async function runRangeSyncChunked(form) {
        if (!confirm('โหลดจาก Odoo — ยืนยัน?\n(จะซิงค์ทีละช่วงและแสดงความคืบหน้า — ห้ามปิดหน้า)')) return;

        var fd = new FormData(form);
        var syncStart = parseInt(String(fd.get('sync_start') || '1'), 10) || 1;
        var syncLimit = parseInt(String(fd.get('sync_limit') || '100'), 10) || 100;

        qs('catalogSyncOverlayTitle').textContent = 'โหลดช่วงตัวเลขจาก Odoo';
        qs('catalogSyncOverlayHint').textContent = 'แต่ละกลุ่มสูงสุด 50 รหัส — รอแต่ละกลุ่มได้สูงสุด ' + (PER_CHUNK_MS / 60000) + ' นาที';
        qs('catalogSyncOverlayClose').classList.add('hidden');
        showOverlay(true);
        setProgress(0, 0, syncLimit, 'กำลังเริ่ม…', '');

        var processed = 0;
        var guard = 0;
        try {
            while (processed < syncLimit && guard++ < 400) {
                var res = await abortableFetch(CHUNK_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        action: 'range_step',
                        sync_start: syncStart,
                        sync_limit: syncLimit,
                        processed: processed
                    })
                }, PER_CHUNK_MS);

                var data = await res.json().catch(function () { return {}; });
                if (!res.ok || !data.success) {
                    throw new Error(data.message || ('HTTP ' + res.status));
                }
                processed = data.processed;
                var pct = (processed / syncLimit) * 100;
                setProgress(pct, processed, syncLimit, data.label_th || '', '');
                if (data.done) {
                    setProgress(100, syncLimit, syncLimit, data.summary_th || 'เสร็จแล้ว', '');
                    break;
                }
            }
            if (guard >= 400) {
                throw new Error('หยุดการซิงค์ — เกินจำนวนรอบที่อนุญาต กรุณาแจ้งผู้ดูแลระบบ');
            }
            setTimeout(function () { window.location.reload(); }, 600);
        } catch (e) {
            var msg = (e && e.name === 'AbortError')
                ? 'หมดเวลารอแต่ละช่วง — Odoo ตอบช้าหรือเครือข่ายขัดข้อง ลองลดจำนวนต่อครั้ง หรือลองใหม่ภายหลัง'
                : ((e && e.message) ? e.message : 'เกิดข้อผิดพลาด');
            setProgress(0, 0, syncLimit, msg, msg);
            qs('catalogSyncOverlayClose').classList.remove('hidden');
        }
    }

    async function runIncrementalChunked(form) {
        if (!confirm('โหลด incremental จาก Odoo — ยืนยัน?\n(ยิง API ทีละกลุ่มเหมือนโหลดช่วง — แสดงความคืบหน้า)')) return;

        var fd = new FormData(form);
        var incrementalLimit = parseInt(String(fd.get('incremental_limit') || '100'), 10) || 100;
        var syncMaxCode = parseInt(String(fd.get('sync_max_code') || '9999'), 10) || 9999;

        qs('catalogSyncOverlayTitle').textContent = 'โหลด incremental';
        qs('catalogSyncOverlayHint').textContent = 'แต่ละกลุ่มสูงสุด 50 รหัส — รอแต่ละกลุ่มได้สูงสุด ' + (PER_CHUNK_MS / 60000) + ' นาที';
        qs('catalogSyncOverlayClose').classList.add('hidden');
        showOverlay(true);

        var processed = 0;
        var jobOffsetStart = 0;
        var guard = 0;
        try {
            while (processed < incrementalLimit && guard++ < 80) {
                var body = {
                    action: 'incremental_step',
                    incremental_limit: incrementalLimit,
                    sync_max_code: syncMaxCode,
                    processed: processed
                };
                if (jobOffsetStart > 0) {
                    body.job_offset_start = jobOffsetStart;
                }
                var res = await abortableFetch(CHUNK_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body)
                }, PER_CHUNK_MS);
                var data = await res.json().catch(function () { return {}; });
                if (!res.ok || !data.success) {
                    throw new Error(data.message || ('HTTP ' + res.status));
                }
                if (data.job_offset_start) {
                    jobOffsetStart = data.job_offset_start;
                }
                processed = data.processed;
                var pct = (processed / incrementalLimit) * 100;
                setProgress(pct, processed, incrementalLimit, data.label_th || '', '');
                if (data.done) {
                    setProgress(100, incrementalLimit, incrementalLimit, data.summary_th || data.label_th || 'เสร็จแล้ว', '');
                    break;
                }
            }
            if (guard >= 80) {
                throw new Error('หยุด — เกินจำนวนรอบที่กำหนด');
            }
            setTimeout(function () { window.location.reload(); }, 700);
        } catch (e) {
            var msg = (e && e.name === 'AbortError')
                ? 'หมดเวลารอแต่ละช่วง — Odoo ตอบช้า ลองใหม่หรือลดจำนวนรายการต่อรอบ'
                : ((e && e.message) ? e.message : 'เกิดข้อผิดพลาด');
            setProgress(0, 0, incrementalLimit, msg, msg);
            qs('catalogSyncOverlayClose').classList.remove('hidden');
        }
    }

    async function runResyncChunked(form) {
        if (!confirm('อัพเดตรายการที่มีใน cache — ยืนยัน?\n(ยิง Odoo ทีละรหัสสินค้า สูงสุด 500 รหัส — อาจใช้เวลานาน)')) return;

        qs('catalogSyncOverlayTitle').textContent = 'Re-sync รายการที่มีอยู่';
        qs('catalogSyncOverlayHint').textContent = 'แต่ละรหัส = 1 คำขอไป Odoo — รอต่อขั้นได้สูงสุด ' + (RESYNC_STEP_MS / 60000) + ' นาที';
        qs('catalogSyncOverlayClose').classList.add('hidden');
        showOverlay(true);

        var offset = 0;
        var total = 1;
        var guard = 0;
        try {
            while (guard++ < 600) {
                var res = await abortableFetch(CHUNK_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        action: 'resync_step',
                        offset: offset,
                        reset_queue: offset === 0 ? '1' : '0'
                    })
                }, RESYNC_STEP_MS);
                var data = await res.json().catch(function () { return {}; });
                if (!res.ok || !data.success) {
                    throw new Error(data.message || ('HTTP ' + res.status));
                }
                total = data.total != null ? data.total : total;
                offset = data.offset != null ? data.offset : offset;
                var pct = total > 0 ? (offset / total) * 100 : 0;
                setProgress(pct, offset, total, data.label_th || '', '');
                if (data.done) {
                    setProgress(100, total, total, data.summary_th || data.label_th || 'เสร็จแล้ว', '');
                    break;
                }
            }
            if (guard >= 600) {
                throw new Error('หยุด — เกินจำนวนรอบ (500+)');
            }
            setTimeout(function () { window.location.reload(); }, 700);
        } catch (e) {
            var msg = (e && e.name === 'AbortError')
                ? 'หมดเวลารอแต่ละรหัส — ลองใหม่ภายหลัง'
                : ((e && e.message) ? e.message : 'เกิดข้อผิดพลาด');
            setProgress(0, 0, total, msg, msg);
            qs('catalogSyncOverlayClose').classList.remove('hidden');
        }
    }

    document.getElementById('catalogSyncFormRange')?.addEventListener('submit', function (e) {
        e.preventDefault();
        runRangeSyncChunked(this);
    });
    document.getElementById('catalogSyncFormIncremental')?.addEventListener('submit', function (e) {
        e.preventDefault();
        runIncrementalChunked(this);
    });
    document.getElementById('catalogSyncFormResync')?.addEventListener('submit', function (e) {
        e.preventDefault();
        runResyncChunked(this);
    });
    document.getElementById('catalogSyncOverlayClose')?.addEventListener('click', function () {
        showOverlay(false);
    });
})();

async function testOdooConnection() {
    const el = document.getElementById('testResult');
    if (!el) return;
    el.innerHTML = '<span class="text-gray-500"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังทดสอบ...</span>';
    try {
        // ใช้ endpoint ที่มีอยู่แล้ว (cny-api-proxy) — หรือสร้าง test endpoint ใหม่ได้
        // สำหรับตอนนี้ส่ง probe ผ่าน fetch ตัวเองเลย
        const res = await fetch(window.location.pathname + '?tab=catalog-sync&_probe=1', {
            method: 'HEAD',
            credentials: 'same-origin',
        });
        if (res.ok) {
            el.innerHTML = '<span class="text-green-600"><i class="fas fa-check-circle mr-1"></i>หน้าทำงานปกติ (ลองกดโหลดรายการดู)</span>';
        } else {
            el.innerHTML = '<span class="text-red-600"><i class="fas fa-times-circle mr-1"></i>HTTP ' + res.status + '</span>';
        }
    } catch (err) {
        el.innerHTML = '<span class="text-red-600"><i class="fas fa-times-circle mr-1"></i>' + err.message + '</span>';
    }
}
</script>
