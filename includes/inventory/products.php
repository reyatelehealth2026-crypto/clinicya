<?php
/**
 * Inventory Products Tab - จัดการสินค้า/บริการ
 * Tab content for inventory/index.php
 * Moved from shop/products.php
 *
 * UI rollout (ui-rollout branch, Archetype A — List/CRUD):
 *  Structural markup is now routed through the reusable partials in
 *  includes/components/ (page-header, toolbar, data-table, empty-state,
 *  pagination, modal, toast). All PHP business logic / SQL / POST handlers
 *  are preserved verbatim — only presentation changed.
 */

// Reusable partials (Archetype A)
require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/toolbar.php';
require_once __DIR__ . '/../components/data-table.php';
require_once __DIR__ . '/../components/empty-state.php';
require_once __DIR__ . '/../components/pagination.php';
require_once __DIR__ . '/../components/modal.php';
require_once __DIR__ . '/../components/toast.php';

// Initialize UnifiedShop
if (file_exists(__DIR__ . '/../../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../../classes/UnifiedShop.php';
}
if (file_exists(__DIR__ . '/../../classes/OdooProductService.php')) {
    require_once __DIR__ . '/../../classes/OdooProductService.php';
}
if (file_exists(__DIR__ . '/../shop-data-source.php')) {
    require_once __DIR__ . '/../shop-data-source.php';
}

$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$orderDataSource = function_exists('getShopOrderDataSource') ? getShopOrderDataSource($db, $currentBotId) : 'shop';
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

/**
 * Inventory-shared styles used by stats tiles, badge pills, sync card,
 * modal grid, promo card, and page banners. Kept inline (heredoc) so the
 * file is self-contained — Phase 2 sweep can elevate these into a shared
 * partial once additional list/CRUD pages need them.
 */
if (!function_exists('getInventorySharedStyles')) {
    function getInventorySharedStyles() {
        return <<<CSS
<style>
/* Stat tile (Archetype A — list/CRUD shoulder area) */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3, 12px);
}
@media (min-width: 768px) {
    .stat-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}

.stat-tile {
    display: flex;
    align-items: center;
    gap: var(--space-3, 12px);
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-4, 16px);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}

.stat-tile-icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md, 12px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.stat-tile-icon-primary { background: var(--color-primary-50); color: var(--color-primary-600); }
.stat-tile-icon-emerald { background: var(--color-emerald-50); color: var(--color-emerald-600); }
.stat-tile-icon-amber   { background: var(--color-amber-50);   color: var(--color-amber-600); }
.stat-tile-icon-rose    { background: var(--color-rose-50);    color: var(--color-rose-600); }

.stat-tile-body { flex: 1 1 auto; min-width: 0; }
.stat-tile-label { font-size: var(--text-xs, 12px); color: var(--color-dark-500); }
.stat-tile-value { font-size: var(--text-2xl, 24px); font-weight: 700; color: var(--color-dark-800); }

/* Page banners (light, used for flash messages in the markup as well as toasts) */
.page-banner {
    padding: var(--space-3, 12px) var(--space-4, 16px);
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px);
    border: 1px solid transparent;
}
.page-banner-success { background: var(--color-emerald-50); border-color: var(--color-emerald-100); color: var(--color-emerald-700); }
.page-banner-error   { background: var(--color-rose-50);    border-color: var(--color-rose-100);    color: var(--color-rose-700); }
.page-banner-info    { background: var(--color-primary-50); border-color: var(--color-primary-100); color: var(--color-primary-700); }

/* Sync card (Odoo branch) */
.sync-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-3, 12px) var(--space-4, 16px);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.sync-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2, 8px);
}
.sync-label {
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-500);
}
.sync-input {
    height: 36px;
    padding: 0 12px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-sm, 8px);
    font-size: var(--text-sm, 14px);
    background: var(--color-slate-50);
    color: var(--color-dark-800);
}
.sync-input[type="number"] { width: 110px; }
.sync-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px);
    font-weight: 600;
    border: none;
    cursor: pointer;
    color: #ffffff;
}
.sync-btn-primary { background: var(--color-primary-600); }
.sync-btn-primary:hover { background: var(--color-primary-700); }
.sync-btn-success { background: var(--color-emerald-600); }
.sync-btn-success:hover { background: var(--color-emerald-700); }

/* Badge pills used in tables / cards (replaces .bg-green-100, .bg-red-100 mini chips) */
.badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 12px);
    font-weight: 600;
    line-height: 1.6;
}
.badge-pill-success { background: var(--color-emerald-100); color: var(--color-emerald-700); }
.badge-pill-warning { background: var(--color-amber-100);   color: var(--color-amber-700); }
.badge-pill-danger  { background: var(--color-rose-100);    color: var(--color-rose-700); }
.badge-pill-neutral { background: var(--color-slate-100);   color: var(--color-dark-500); }
.badge-pill-violet  { background: rgba(124, 58, 237, 0.12); color: var(--color-violet-600); }

/* Modal field primitives — local to inventory until Archetype C lands */
.modal-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-4, 16px);
}
@media (min-width: 768px) {
    .modal-grid { grid-template-columns: 1fr 1fr; }
}
.modal-col {
    display: flex;
    flex-direction: column;
    gap: var(--space-3, 12px);
}
.modal-row-2,
.modal-row-3 {
    display: grid;
    gap: var(--space-3, 12px);
}
.modal-row-2 { grid-template-columns: 1fr 1fr; }
.modal-row-3 { grid-template-columns: 1fr 1fr 1fr; }

.field { display: flex; flex-direction: column; gap: 4px; }
.field-label {
    font-size: var(--text-xs, 12px);
    font-weight: 500;
    color: var(--color-dark-700);
}
.field-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-sm, 8px);
    font-size: var(--text-sm, 14px);
    background: #ffffff;
    color: var(--color-dark-800);
    transition: all var(--transition-fast, 150ms ease);
}
.field-input:focus {
    outline: none;
    border-color: var(--color-primary-400);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}
.field-input-readonly { background: var(--color-slate-100); cursor: not-allowed; }

.field-checkbox {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2, 8px);
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-800);
}
.field-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--color-primary-600);
}

.promo-card {
    background: linear-gradient(135deg, var(--color-amber-50), var(--color-amber-100));
    border: 1px solid var(--color-amber-200);
    border-radius: var(--radius-md, 12px);
    padding: var(--space-3, 12px);
}
.promo-card-title {
    font-size: var(--text-xs, 12px);
    font-weight: 700;
    color: var(--color-amber-700);
    margin: 0 0 var(--space-2, 8px) 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.promo-card-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

/* Modal footer buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px);
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all var(--transition-fast, 150ms ease);
}
.btn-secondary {
    background: #ffffff;
    border-color: var(--color-slate-200);
    color: var(--color-dark-700);
}
.btn-secondary:hover { background: var(--color-slate-50); }
.btn-primary {
    background: var(--color-primary-600);
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(79, 70, 229, 0.2);
}
.btn-primary:hover { background: var(--color-primary-700); }

/* Spacing helper used between top-level sections */
.space-y-4 > * + * { margin-top: var(--space-4, 16px); }

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .stat-tile {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
}
.dark .stat-tile-label { color: var(--color-slate-400); }
.dark .stat-tile-value { color: var(--color-slate-100); }
.dark .stat-tile-icon-primary { background: rgba(99, 102, 241, 0.15); color: var(--color-primary-300); }
.dark .stat-tile-icon-emerald { background: rgba(16, 185, 129, 0.15); color: var(--color-emerald-300); }
.dark .stat-tile-icon-amber   { background: rgba(245, 158, 11, 0.15); color: var(--color-amber-300); }
.dark .stat-tile-icon-rose    { background: rgba(244, 63, 94, 0.15);  color: var(--color-rose-300); }

.dark .page-banner-success { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.25); color: var(--color-emerald-300); }
.dark .page-banner-error   { background: rgba(244, 63, 94, 0.12);  border-color: rgba(244, 63, 94, 0.25);  color: var(--color-rose-300); }
.dark .page-banner-info    { background: rgba(99, 102, 241, 0.12); border-color: rgba(99, 102, 241, 0.25); color: var(--color-primary-300); }

.dark .sync-card {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
}
.dark .sync-label { color: var(--color-slate-400); }
.dark .sync-input {
    background: var(--color-dark-900);
    border-color: var(--color-dark-700);
    color: var(--color-slate-100);
}

.dark .badge-pill-success { background: rgba(16, 185, 129, 0.15); color: var(--color-emerald-300); }
.dark .badge-pill-warning { background: rgba(245, 158, 11, 0.15); color: var(--color-amber-300); }
.dark .badge-pill-danger  { background: rgba(244, 63, 94, 0.15);  color: var(--color-rose-300); }
.dark .badge-pill-neutral { background: var(--color-dark-700);    color: var(--color-slate-400); }
.dark .badge-pill-violet  { background: rgba(124, 58, 237, 0.2);  color: #c4b5fd; }

.dark .field-label { color: var(--color-slate-300); }
.dark .field-input {
    background: var(--color-dark-900);
    border-color: var(--color-dark-700);
    color: var(--color-slate-100);
}
.dark .field-input-readonly { background: var(--color-dark-700); }
.dark .field-checkbox { color: var(--color-slate-100); }

.dark .promo-card {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), rgba(245, 158, 11, 0.15));
    border-color: rgba(245, 158, 11, 0.3);
}
.dark .promo-card-title { color: var(--color-amber-300); }

.dark .btn-secondary {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    color: var(--color-slate-100);
}
.dark .btn-secondary:hover { background: var(--color-dark-700); }
.dark .btn-primary { background: var(--color-primary-500); }
.dark .btn-primary:hover { background: var(--color-primary-600); }
</style>
CSS;
    }
}

if ($isOdooMode) {
    $cacheTable = 'shop_products';
    $syncStateTable = 'odoo_products_sync_state';
    $odooError = null;

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

        $db->exec("CREATE TABLE IF NOT EXISTS {$syncStateTable} (
            line_account_id INT NOT NULL PRIMARY KEY,
            next_offset INT NOT NULL DEFAULT 1,
            last_incremental_sync_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Idempotent schema extensions so locally-created items can use the same form fields.
        $extraColumns = [
            "image_url"          => "ADD COLUMN image_url VARCHAR(500) DEFAULT NULL",
            "photo_path"         => "ADD COLUMN photo_path VARCHAR(500) DEFAULT NULL",
            "description"        => "ADD COLUMN description TEXT DEFAULT NULL",
            "manufacturer"       => "ADD COLUMN manufacturer VARCHAR(255) DEFAULT NULL",
            "usage_instructions" => "ADD COLUMN usage_instructions TEXT DEFAULT NULL",
            "unit"               => "ADD COLUMN unit VARCHAR(50) DEFAULT NULL",
            "base_unit"          => "ADD COLUMN base_unit VARCHAR(50) DEFAULT NULL",
            "name_en"            => "ADD COLUMN name_en VARCHAR(255) DEFAULT NULL",
            "sale_price"         => "ADD COLUMN sale_price DECIMAL(12,2) DEFAULT NULL",
            "is_featured"        => "ADD COLUMN is_featured TINYINT(1) DEFAULT 0",
            "is_flash_sale"      => "ADD COLUMN is_flash_sale TINYINT(1) DEFAULT 0",
            "is_choice"          => "ADD COLUMN is_choice TINYINT(1) DEFAULT 0",
            "flash_sale_end"     => "ADD COLUMN flash_sale_end DATETIME DEFAULT NULL",
            "is_local"           => "ADD COLUMN is_local TINYINT(1) DEFAULT 0",
        ];
        try {
            $existing = [];
            $colStmt = $db->query("SHOW COLUMNS FROM {$cacheTable}");
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $existing[$c['Field']] = true;
            }
            foreach ($extraColumns as $name => $sql) {
                if (!isset($existing[$name])) {
                    try { $db->exec("ALTER TABLE {$cacheTable} {$sql}"); } catch (Exception $e) {}
                }
            }
        } catch (Exception $e) { /* non-fatal */ }
    } catch (Exception $e) {
        $odooError = 'ไม่สามารถเตรียมตาราง cache ได้: ' . $e->getMessage();
    }

    $syncStart = max(1, (int) ($_GET['sync_start'] ?? 1));
    $syncLimit = (int) ($_GET['sync_limit'] ?? 100);
    if (!in_array($syncLimit, [100, 200, 500], true)) {
        $syncLimit = 100;
    }
    $incrementalLimit = (int) ($_GET['incremental_limit'] ?? 100);
    if (!in_array($incrementalLimit, [50, 100, 200], true)) {
        $incrementalLimit = 100;
    }
    $syncMaxCode = max(100, (int) ($_GET['sync_max_code'] ?? 9999));

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array(($_POST['action'] ?? ''), ['odoo_sync_cache', 'odoo_sync_incremental'], true)
        && !$odooError
    ) {
        $action = $_POST['action'];
        $syncStart = max(1, (int) ($_POST['sync_start'] ?? 1));
        $syncLimit = (int) ($_POST['sync_limit'] ?? 100);
        if (!in_array($syncLimit, [100, 200, 500], true)) {
            $syncLimit = 100;
        }
        $incrementalLimit = (int) ($_POST['incremental_limit'] ?? 100);
        if (!in_array($incrementalLimit, [50, 100, 200], true)) {
            $incrementalLimit = 100;
        }
        $syncMaxCode = max(100, (int) ($_POST['sync_max_code'] ?? 9999));

        try {
            $service = new OdooProductService($db, $currentBotId);
            $upsertStmt = $db->prepare("INSERT INTO {$cacheTable}
                (line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    sku = VALUES(sku),
                    name = VALUES(name),
                    generic_name = VALUES(generic_name),
                    barcode = VALUES(barcode),
                    category = VALUES(category),
                    list_price = VALUES(list_price),
                    online_price = VALUES(online_price),
                    saleable_qty = VALUES(saleable_qty),
                    is_active = VALUES(is_active),
                    last_synced_at = NOW()");

            $savedCount = 0;
            $fetchedCount = 0;

            if ($action === 'odoo_sync_incremental') {
                $stateStmt = $db->prepare("SELECT next_offset FROM {$syncStateTable} WHERE line_account_id = ? LIMIT 1");
                $stateStmt->execute([(int) $currentBotId]);
                $nextOffset = (int) $stateStmt->fetchColumn();
                if ($nextOffset <= 0) {
                    $nextOffset = 1;
                }
                $syncStart = $nextOffset;
                $syncLimit = $incrementalLimit;
            }

            $cursor = $syncStart;
            $remaining = $syncLimit;

            while ($remaining > 0) {
                $chunkSize = min(50, $remaining);
                $result = $service->getProductsByRange($cursor, $chunkSize);
                $chunkProducts = $result['products'] ?? [];
                $fetchedCount += count($chunkProducts);

                foreach ($chunkProducts as $product) {
                    $upsertStmt->execute([
                        (int) $currentBotId,
                        (string) ($product['product_id'] ?? ''),
                        (string) ($product['product_code'] ?? ''),
                        (string) ($product['sku'] ?? ''),
                        (string) ($product['name'] ?? ''),
                        (string) ($product['generic_name'] ?? ''),
                        (string) ($product['barcode'] ?? ''),
                        (string) ($product['category'] ?? ''),
                        (float) ($product['list_price'] ?? 0),
                        (float) ($product['online_price'] ?? 0),
                        (float) ($product['saleable_qty'] ?? 0),
                        !empty($product['active']) ? 1 : 0,
                    ]);
                    $savedCount++;
                }

                $cursor += $chunkSize;
                $remaining -= $chunkSize;
            }

            if ($action === 'odoo_sync_incremental') {
                $nextOffset = $syncStart + $syncLimit;
                if ($nextOffset > $syncMaxCode) {
                    $nextOffset = 1;
                }

                $saveStateStmt = $db->prepare("INSERT INTO {$syncStateTable} (line_account_id, next_offset, last_incremental_sync_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        next_offset = VALUES(next_offset),
                        last_incremental_sync_at = NOW()");
                $saveStateStmt->execute([(int) $currentBotId, (int) $nextOffset]);

                $_SESSION['odoo_sync_message'] = "Incremental sync สำเร็จ: ช่วง {$syncStart}-" . ($syncStart + $syncLimit - 1) . " | ดึง {$fetchedCount} รายการ | บันทึก {$savedCount} รายการ | รอบถัดไปเริ่ม {$nextOffset}";
            } else {
                $_SESSION['odoo_sync_message'] = "Sync สำเร็จ: ดึง {$fetchedCount} รายการ และบันทึก cache {$savedCount} รายการ";
            }
        } catch (Exception $e) {
            $_SESSION['odoo_sync_error'] = 'Sync ไม่สำเร็จ: ' . $e->getMessage();
        }

        $redirectParams = array_merge($_GET, [
            'tab' => 'products',
            'sync_start' => $syncStart,
            'sync_limit' => $syncLimit,
            'incremental_limit' => $incrementalLimit,
            'sync_max_code' => $syncMaxCode,
            'page' => 1,
        ]);
        unset($redirectParams['_']);
        echo "<script>window.location.href='?" . http_build_query($redirectParams) . "';</script>";
        exit;
    }

    // Local CRUD on shop_products (allows creating/editing items directly using the same form)
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array(($_POST['action'] ?? ''), ['local_create', 'local_update', 'local_delete', 'local_toggle', 'local_bulk_activate', 'local_bulk_deactivate', 'local_bulk_delete'], true)
        && !$odooError
    ) {
        $localAction = $_POST['action'];
        try {
            if ($localAction === 'local_create' || $localAction === 'local_update') {
                $productCode = trim((string) ($_POST['product_code'] ?? ''));
                if ($productCode === '') {
                    $sku = trim((string) ($_POST['sku'] ?? ''));
                    $productCode = $sku !== '' ? $sku : ('LOC-' . time() . '-' . random_int(100, 999));
                }

                $fields = [
                    'line_account_id'    => (int) $currentBotId,
                    'product_code'       => $productCode,
                    'sku'                => $_POST['sku'] ?? null,
                    'name'               => $_POST['name'] ?? '',
                    'name_en'            => $_POST['name_en'] ?? null,
                    'generic_name'       => $_POST['generic_name'] ?? null,
                    'barcode'            => $_POST['barcode'] ?? null,
                    'category'           => $_POST['category'] ?? null,
                    'manufacturer'       => $_POST['manufacturer'] ?? null,
                    'description'        => $_POST['description'] ?? null,
                    'usage_instructions' => $_POST['usage_instructions'] ?? null,
                    'image_url'          => $_POST['image_url'] ?? null,
                    'list_price'         => (float) ($_POST['price'] ?? 0),
                    'online_price'       => $_POST['sale_price'] !== '' && $_POST['sale_price'] !== null ? (float) $_POST['sale_price'] : 0,
                    'sale_price'         => $_POST['sale_price'] !== '' && $_POST['sale_price'] !== null ? (float) $_POST['sale_price'] : null,
                    'saleable_qty'       => (float) ($_POST['stock'] ?? 0),
                    'base_unit'          => $_POST['base_unit'] ?? null,
                    'unit'               => $_POST['unit'] ?? null,
                    'is_active'          => isset($_POST['is_active']) ? 1 : 0,
                    'is_featured'        => isset($_POST['is_featured']) ? 1 : 0,
                    'is_flash_sale'      => isset($_POST['is_flash_sale']) ? 1 : 0,
                    'is_choice'          => isset($_POST['is_choice']) ? 1 : 0,
                    'flash_sale_end'     => !empty($_POST['flash_sale_end']) ? $_POST['flash_sale_end'] : null,
                    'is_local'           => 1,
                ];

                if ($localAction === 'local_create') {
                    $cols = array_keys($fields);
                    $placeholders = implode(',', array_fill(0, count($cols), '?'));
                    $sql = "INSERT INTO {$cacheTable} (" . implode(',', $cols) . ") VALUES ({$placeholders})";
                    $stmt = $db->prepare($sql);
                    $stmt->execute(array_values($fields));
                    $_SESSION['odoo_sync_message'] = 'สร้างสินค้าใหม่สำเร็จ';
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($id > 0) {
                        unset($fields['line_account_id']); // don't change owner on update
                        $sets = implode('=?, ', array_keys($fields)) . '=?';
                        $sql = "UPDATE {$cacheTable} SET {$sets} WHERE id=? AND line_account_id=?";
                        $stmt = $db->prepare($sql);
                        $params = array_values($fields);
                        $params[] = $id;
                        $params[] = (int) $currentBotId;
                        $stmt->execute($params);
                        $_SESSION['odoo_sync_message'] = 'แก้ไขสินค้าสำเร็จ';
                    }
                }
            } elseif ($localAction === 'local_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $db->prepare("DELETE FROM {$cacheTable} WHERE id=? AND line_account_id=?");
                    $stmt->execute([$id, (int) $currentBotId]);
                    $_SESSION['odoo_sync_message'] = 'ลบสินค้าสำเร็จ';
                }
            } elseif ($localAction === 'local_toggle') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE {$cacheTable} SET is_active = NOT is_active WHERE id=? AND line_account_id=?");
                    $stmt->execute([$id, (int) $currentBotId]);
                }
            } elseif (in_array($localAction, ['local_bulk_activate', 'local_bulk_deactivate', 'local_bulk_delete'], true)) {
                $ids = $_POST['selected_ids'] ?? [];
                $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), function ($v) { return $v > 0; }));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    if ($localAction === 'local_bulk_delete') {
                        $sql = "DELETE FROM {$cacheTable} WHERE id IN ({$placeholders}) AND line_account_id=?";
                    } else {
                        $val = $localAction === 'local_bulk_activate' ? 1 : 0;
                        $sql = "UPDATE {$cacheTable} SET is_active={$val} WHERE id IN ({$placeholders}) AND line_account_id=?";
                    }
                    $stmt = $db->prepare($sql);
                    $params = $ids;
                    $params[] = (int) $currentBotId;
                    $stmt->execute($params);
                    $_SESSION['odoo_sync_message'] = 'อัปเดตสินค้าที่เลือกสำเร็จ (' . count($ids) . ' รายการ)';
                }
            }
        } catch (Exception $e) {
            $_SESSION['odoo_sync_error'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }

        $redirectParams = array_merge($_GET, ['tab' => 'products']);
        unset($redirectParams['_']);
        echo "<script>window.location.href='?" . http_build_query($redirectParams) . "';</script>";
        exit;
    }

    $odooSyncMessage = $_SESSION['odoo_sync_message'] ?? null;
    $odooSyncError = $_SESSION['odoo_sync_error'] ?? null;
    unset($_SESSION['odoo_sync_message'], $_SESSION['odoo_sync_error']);

    if (!$odooError) {
        try {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM {$cacheTable} WHERE line_account_id = ?");
            $countStmt->execute([(int) $currentBotId]);
            $cachedTotal = (int) $countStmt->fetchColumn();

            if ($cachedTotal === 0) {
                $service = new OdooProductService($db, $currentBotId);
                $upsertStmt = $db->prepare("INSERT INTO {$cacheTable}
                    (line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        sku = VALUES(sku),
                        name = VALUES(name),
                        generic_name = VALUES(generic_name),
                        barcode = VALUES(barcode),
                        category = VALUES(category),
                        list_price = VALUES(list_price),
                        online_price = VALUES(online_price),
                        saleable_qty = VALUES(saleable_qty),
                        is_active = VALUES(is_active),
                        last_synced_at = NOW()");

                $seedResult = $service->getProductsByRange(1, 50);
                $seedProducts = $seedResult['products'] ?? [];

                $seedResult2 = $service->getProductsByRange(51, 50);
                $seedProducts = array_merge($seedProducts, $seedResult2['products'] ?? []);

                $seedSaved = 0;
                foreach ($seedProducts as $product) {
                    $upsertStmt->execute([
                        (int) $currentBotId,
                        (string) ($product['product_id'] ?? ''),
                        (string) ($product['product_code'] ?? ''),
                        (string) ($product['sku'] ?? ''),
                        (string) ($product['name'] ?? ''),
                        (string) ($product['generic_name'] ?? ''),
                        (string) ($product['barcode'] ?? ''),
                        (string) ($product['category'] ?? ''),
                        (float) ($product['list_price'] ?? 0),
                        (float) ($product['online_price'] ?? 0),
                        (float) ($product['saleable_qty'] ?? 0),
                        !empty($product['active']) ? 1 : 0,
                    ]);
                    $seedSaved++;
                }

                if ($seedSaved > 0 && !$odooSyncMessage) {
                    $odooSyncMessage = "โหลดข้อมูลเริ่มต้นอัตโนมัติแล้ว {$seedSaved} รายการ";
                }
            }
        } catch (Exception $e) {
            $odooError = $e->getMessage();
        }
    }

    $searchFilter = trim($_GET['search'] ?? '');
    $categoryFilter = trim($_GET['category'] ?? '');
    $statusFilter = $_GET['status'] ?? 'all';
    if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
        $statusFilter = 'all';
    }

    $sortBy = $_GET['sort'] ?? 'updated_at';
    $sortDir = strtoupper($_GET['dir'] ?? 'DESC');
    $allowedSorts = ['updated_at', 'name', 'product_code', 'sku', 'list_price', 'online_price', 'saleable_qty'];
    if (!in_array($sortBy, $allowedSorts, true)) {
        $sortBy = 'updated_at';
    }
    $sortDir = $sortDir === 'ASC' ? 'ASC' : 'DESC';

    $viewMode = $_GET['view'] ?? 'table';
    if (!in_array($viewMode, ['table', 'grid', 'json'], true)) {
        $viewMode = 'table';
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 50);
    if (!in_array($perPage, [20, 50, 100, 200], true)) {
        $perPage = 50;
    }
    $offset = ($page - 1) * $perPage;

    $baseWhere = " FROM {$cacheTable} oc LEFT JOIN cny_products cp ON cp.sku = oc.product_code WHERE oc.line_account_id = ?";
    $queryParams = [(int) $currentBotId];

    if ($categoryFilter !== '') {
        $baseWhere .= " AND category = ?";
        $queryParams[] = $categoryFilter;
    }
    if ($statusFilter === 'active') {
        $baseWhere .= " AND is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $baseWhere .= " AND is_active = 0";
    }
    if ($searchFilter !== '') {
        $baseWhere .= " AND (name LIKE ? OR sku LIKE ? OR product_code LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)";
        $like = "%{$searchFilter}%";
        $queryParams[] = $like;
        $queryParams[] = $like;
        $queryParams[] = $like;
        $queryParams[] = $like;
        $queryParams[] = $like;
    }

    $totalProducts = 0;
    $odooProducts = [];
    $lastSyncedAt = null;
    $lastIncrementalSyncedAt = null;
    $nextIncrementalOffset = 1;
    $categories = [];

    if (!$odooError) {
        try {
            $countStmt = $db->prepare("SELECT COUNT(*)" . $baseWhere);
            $countStmt->execute($queryParams);
            $totalProducts = (int) $countStmt->fetchColumn();

            $dataStmt = $db->prepare("SELECT *" . $baseWhere . " ORDER BY {$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}");
            $dataStmt->execute($queryParams);
            $odooProducts = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $syncStmt = $db->prepare("SELECT MAX(last_synced_at) FROM {$cacheTable} WHERE line_account_id = ?");
            $syncStmt->execute([(int) $currentBotId]);
            $lastSyncedAt = $syncStmt->fetchColumn();

            $syncStateStmt = $db->prepare("SELECT next_offset, last_incremental_sync_at FROM {$syncStateTable} WHERE line_account_id = ? LIMIT 1");
            $syncStateStmt->execute([(int) $currentBotId]);
            $syncState = $syncStateStmt->fetch(PDO::FETCH_ASSOC);
            if ($syncState) {
                $nextIncrementalOffset = max(1, (int) ($syncState['next_offset'] ?? 1));
                $lastIncrementalSyncedAt = $syncState['last_incremental_sync_at'] ?? null;
            }

            $catStmt = $db->prepare("SELECT DISTINCT category FROM {$cacheTable} WHERE line_account_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category ASC");
            $catStmt->execute([(int) $currentBotId]);
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $odooError = $e->getMessage();
        }
    }

    $totalPages = max(1, (int) ceil(max(1, $totalProducts) / $perPage));
    $lastSyncedText = $lastSyncedAt ? date('d/m/Y H:i', strtotime($lastSyncedAt)) : '-';
    $lastIncrementalSyncedText = $lastIncrementalSyncedAt ? date('d/m/Y H:i', strtotime($lastIncrementalSyncedAt)) : '-';

    if (!function_exists('buildOdooCacheQuery')) {
        function buildOdooCacheQuery($overrides = [])
        {
            $params = array_merge($_GET, $overrides);
            $params['tab'] = 'products';
            unset($params['_']);
            return http_build_query($params);
        }
    }

    // Pre-build sort URLs for the data-table partial
    $odooSortHrefs = [];
    foreach (['sku', 'product_code', 'name', 'list_price', 'online_price', 'saleable_qty'] as $sortKey) {
        $newDir = ($sortBy === $sortKey && $sortDir === 'ASC') ? 'DESC' : 'ASC';
        $odooSortHrefs[$sortKey] = '?' . buildOdooCacheQuery(['sort' => $sortKey, 'dir' => $newDir, 'page' => 1]);
    }

    // Emit partial styles
    echo getPageHeaderStyles();
    echo getToolbarStyles();
    echo getDataTableStyles();
    echo getEmptyStateStyles();
    echo getPaginationStyles();
    echo getModalStyles();
    echo getToastStyles();
    echo getInventorySharedStyles();
    ?>
    <div class="space-y-4">
        <?php
        // Page header — title + sync timestamps as subtitle + primary action.
        $headerSubtitle = 'สามารถ เพิ่ม/แก้ไข/ลบ สินค้าได้โดยตรง และยังรองรับการ sync จากระบบหลัก  ·  ข้อมูลล่าสุด sync เมื่อ: '
            . $lastSyncedText . '  ·  Incremental ล่าสุด: ' . $lastIncrementalSyncedText
            . '  ·  รอบถัดไปเริ่มรหัส: ' . number_format($nextIncrementalOffset);
        echo renderPageHeader(
            'จัดการคลังสินค้า',
            $headerSubtitle,
            ['label' => 'เพิ่มสินค้า', 'icon' => 'fas fa-plus', 'onclick' => 'openOdooProductModal()', 'variant' => 'success']
        );
        ?>

        <?php if ($odooSyncMessage || $odooSyncError || $odooError): ?>
            <div class="space-y-2">
            <?php if ($odooSyncMessage): ?>
                <div class="page-banner page-banner-success"><?= htmlspecialchars($odooSyncMessage) ?></div>
            <?php endif; ?>
            <?php if ($odooSyncError): ?>
                <div class="page-banner page-banner-error"><?= htmlspecialchars($odooSyncError) ?></div>
            <?php endif; ?>
            <?php if ($odooError): ?>
                <div class="page-banner page-banner-error">ไม่สามารถโหลดข้อมูลสินค้าได้: <?= htmlspecialchars($odooError) ?></div>
            <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Sync toolbar (POST form) -->
        <div class="sync-card">
            <form method="POST" class="sync-form">
                <input type="hidden" name="tab" value="products">
                <label class="sync-label">เริ่มรหัสสินค้า</label>
                <input type="number" name="sync_start" min="1" value="<?= (int) $syncStart ?>" class="sync-input">

                <label class="sync-label">จำนวน</label>
                <select name="sync_limit" class="sync-input">
                    <?php foreach ([100, 200, 500] as $size): ?>
                        <option value="<?= $size ?>" <?= $syncLimit === $size ? 'selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="action" value="odoo_sync_cache" class="sync-btn sync-btn-primary">
                    <i class="fas fa-sync"></i><span>Sync รายการ</span>
                </button>

                <label class="sync-label">Incremental</label>
                <select name="incremental_limit" class="sync-input">
                    <?php foreach ([50, 100, 200] as $size): ?>
                        <option value="<?= $size ?>" <?= $incrementalLimit === $size ? 'selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="sync_max_code" min="100" value="<?= (int) $syncMaxCode ?>" class="sync-input" title="รหัสสูงสุดก่อนวนกลับ">

                <button type="submit" name="action" value="odoo_sync_incremental" class="sync-btn sync-btn-success">
                    <i class="fas fa-bolt"></i><span>Sync เฉพาะที่เปลี่ยนล่าสุด</span>
                </button>
            </form>
        </div>

        <?php
        // Build toolbar config (search + category + status + per_page + view chips + reset)
        $categoryOptions = [];
        foreach ($categories as $catName) {
            $categoryOptions[] = ['value' => (string) $catName, 'label' => (string) $catName, 'selected' => $categoryFilter === (string) $catName];
        }
        $statusOptions = [
            ['value' => 'all', 'label' => 'ทุกสถานะ', 'selected' => $statusFilter === 'all'],
            ['value' => 'active', 'label' => 'active', 'selected' => $statusFilter === 'active'],
            ['value' => 'inactive', 'label' => 'inactive', 'selected' => $statusFilter === 'inactive'],
        ];
        $perPageOptions = [];
        foreach ([20, 50, 100, 200] as $size) {
            $perPageOptions[] = ['value' => (string) $size, 'label' => $size . '/หน้า', 'selected' => $perPage === $size];
        }
        $viewChips = [
            ['href' => '?' . buildOdooCacheQuery(['view' => 'grid']), 'icon' => 'fas fa-th', 'label' => 'Grid', 'active' => $viewMode === 'grid', 'tone' => 'primary'],
            ['href' => '?' . buildOdooCacheQuery(['view' => 'table']), 'icon' => 'fas fa-table', 'label' => 'Table', 'active' => $viewMode === 'table', 'tone' => 'primary'],
            ['href' => '?' . buildOdooCacheQuery(['view' => 'json']), 'icon' => 'fas fa-code', 'label' => 'JSON', 'active' => $viewMode === 'json', 'tone' => 'primary'],
        ];
        $rangeStart = number_format($totalProducts > 0 ? ($offset + 1) : 0);
        $rangeEnd = number_format(min($offset + $perPage, $totalProducts));
        $rangeTotal = number_format($totalProducts);
        echo renderToolbar([
            'method' => 'GET',
            'hiddenFields' => [
                'tab' => 'products',
                'sort' => $sortBy,
                'dir' => $sortDir,
                'view' => $viewMode,
            ],
            'search' => [
                'name' => 'search',
                'value' => $searchFilter,
                'placeholder' => 'ค้นหา SKU/รหัส/ชื่อ/บาร์โค้ด/generic',
            ],
            'selects' => [
                ['name' => 'category', 'value' => $categoryFilter, 'placeholder' => 'ทุกหมวดหมู่', 'options' => $categoryOptions],
                ['name' => 'status', 'value' => $statusFilter, 'options' => $statusOptions],
                ['name' => 'per_page', 'value' => (string) $perPage, 'options' => $perPageOptions],
            ],
            'resetHref' => ($searchFilter !== '' || $categoryFilter !== '' || $statusFilter !== 'all') ? '?tab=products' : null,
            'meta' => 'แสดง ' . $rangeStart . '-' . $rangeEnd . ' จาก ' . $rangeTotal . ' รายการ',
            'chips' => $viewChips,
            'chipGroupLabel' => 'มุมมอง:',
        ]);
        ?>

        <?php if ($viewMode === 'json'): ?>
            <div class="data-table-card" style="padding: var(--space-4, 16px);">
                <pre style="font-size: var(--text-xs, 12px); overflow: auto; max-height: 540px; margin: 0; color: var(--color-dark-800);"><?= htmlspecialchars(json_encode($odooProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        <?php elseif ($viewMode === 'grid'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php if (empty($odooProducts)): ?>
                    <div class="col-span-full data-table-card">
                        <?= renderEmptyState('fas fa-box-open', 'ยังไม่มีข้อมูลใน cache', 'กรุณากด Sync รายการ') ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($odooProducts as $product): ?>
                        <div class="data-table-card" style="padding: var(--space-4, 16px);">
                            <div style="font-weight:600;color:var(--color-dark-800);font-size:var(--text-base,16px);line-height:1.4;margin-bottom:var(--space-1,4px);"><?= htmlspecialchars((string) ($product['name'] ?? '-')) ?></div>
                            <div style="font-size:var(--text-xs,12px);color:var(--color-dark-500);margin-bottom:var(--space-3,12px);"><?= htmlspecialchars((string) ($product['generic_name'] ?? '-')) ?></div>

                            <div style="display:flex;flex-wrap:wrap;gap:var(--space-2,8px);margin-bottom:var(--space-3,12px);font-size:var(--text-xs,12px);">
                                <span style="padding:4px 8px;background:var(--color-slate-100);border-radius:var(--radius-sm,8px);">SKU: <?= htmlspecialchars((string) ($product['sku'] ?? '-')) ?></span>
                                <span style="padding:4px 8px;background:var(--color-slate-100);border-radius:var(--radius-sm,8px);">รหัส: <?= htmlspecialchars((string) ($product['product_code'] ?? '-')) ?></span>
                            </div>

                            <div style="border:1px solid var(--color-slate-200);border-radius:var(--radius-md,12px);padding:var(--space-3,12px);font-size:var(--text-sm,14px);margin-bottom:var(--space-3,12px);">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:var(--color-dark-500);">Product ID</span><span style="font-weight:600;color:var(--color-dark-700);"><?= htmlspecialchars((string) ($product['product_id'] ?? '-')) ?></span></div>
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:var(--color-dark-500);">หมวดหมู่</span><span style="font-weight:600;color:var(--color-dark-700);"><?= htmlspecialchars((string) ($product['category'] ?? '-')) ?></span></div>
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:var(--color-dark-500);">ราคาปกติ</span><span style="font-weight:600;color:var(--color-emerald-600);">฿<?= number_format((float) ($product['list_price'] ?? 0), 2) ?></span></div>
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:var(--color-dark-500);">ออนไลน์</span><span style="font-weight:600;color:var(--color-primary-600);">฿<?= number_format((float) ($product['online_price'] ?? 0), 2) ?></span></div>
                                <div style="display:flex;justify-content:space-between;"><span style="color:var(--color-dark-500);">สต็อก</span><span style="font-weight:600;color:var(--color-dark-700);"><?= number_format((float) ($product['saleable_qty'] ?? 0)) ?></span></div>
                            </div>

                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <?php if (!empty($product['is_active'])): ?>
                                    <span class="badge-pill badge-pill-success">active</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-pill-neutral">inactive</span>
                                <?php endif; ?>
                                <span style="font-size:var(--text-xs,12px);color:var(--color-slate-400);">Sync: <?= !empty($product['last_synced_at']) ? htmlspecialchars(date('d/m H:i', strtotime($product['last_synced_at']))) : '-' ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:4px;margin-top:var(--space-3,12px);padding-top:var(--space-3,12px);border-top:1px solid var(--color-slate-100);">
                                <button type="button" onclick='editOdooProduct(<?= json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="data-table-row-action"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('ลบสินค้านี้?')">
                                    <input type="hidden" name="action" value="local_delete">
                                    <input type="hidden" name="id" value="<?= (int) ($product['id'] ?? 0) ?>">
                                    <button type="submit" class="data-table-row-action data-table-row-action-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            // Build columns for the Odoo data-table
            $odooEmpty = renderEmptyState('fas fa-box-open', 'ยังไม่มีข้อมูลใน cache', 'กรุณากด Sync รายการ หรือกด เพิ่มสินค้า');
            $odooColumns = [
                [
                    'key' => 'image', 'label' => 'รูป', 'align' => 'center', 'width' => '56px',
                    'render' => function ($product) {
                        $ph = $product['photo_path'] ?? $product['image_url'] ?? '';
                        if ($ph) {
                            return '<img src="' . htmlspecialchars($ph) . '" loading="lazy" style="width:36px;height:36px;object-fit:contain;border-radius:6px;" onerror="this.outerHTML=\'<span style=&quot;color:var(--color-slate-300);&quot;><i class=&quot;fas fa-capsules&quot;></i></span>\'">';
                        }
                        return '<span style="color:var(--color-slate-300);"><i class="fas fa-capsules"></i></span>';
                    },
                ],
                [
                    'key' => 'sku', 'label' => 'SKU', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['sku'],
                    'sortDir' => $sortBy === 'sku' ? $sortDir : null,
                    'render' => function ($p) { return '<span style="font-family:var(--font-mono);font-size:var(--text-xs,12px);">' . htmlspecialchars((string) ($p['sku'] ?? '-')) . '</span>'; },
                ],
                [
                    'key' => 'product_code', 'label' => 'รหัสสินค้า', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['product_code'],
                    'sortDir' => $sortBy === 'product_code' ? $sortDir : null,
                    'render' => function ($p) {
                        $out = htmlspecialchars((string) ($p['product_code'] ?? '-'));
                        if (!empty($p['is_local'])) {
                            $out .= ' <span class="badge-pill badge-pill-violet">local</span>';
                        }
                        return $out;
                    },
                ],
                [
                    'key' => 'name', 'label' => 'ชื่อสินค้า', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['name'],
                    'sortDir' => $sortBy === 'name' ? $sortDir : null,
                    'render' => function ($p) { return htmlspecialchars((string) ($p['name'] ?? '-')); },
                ],
                [
                    'key' => 'category', 'label' => 'หมวดหมู่',
                    'render' => function ($p) { return htmlspecialchars((string) ($p['category'] ?? '-')); },
                ],
                [
                    'key' => 'list_price', 'label' => 'List Price', 'align' => 'right', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['list_price'],
                    'sortDir' => $sortBy === 'list_price' ? $sortDir : null,
                    'render' => function ($p) { return '฿' . number_format((float) ($p['list_price'] ?? 0), 2); },
                ],
                [
                    'key' => 'online_price', 'label' => 'Online Price', 'align' => 'right', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['online_price'],
                    'sortDir' => $sortBy === 'online_price' ? $sortDir : null,
                    'render' => function ($p) { return '฿' . number_format((float) ($p['online_price'] ?? 0), 2); },
                ],
                [
                    'key' => 'saleable_qty', 'label' => 'คงเหลือ', 'align' => 'center', 'sortable' => true,
                    'sortHref' => $odooSortHrefs['saleable_qty'],
                    'sortDir' => $sortBy === 'saleable_qty' ? $sortDir : null,
                    'render' => function ($p) { return number_format((float) ($p['saleable_qty'] ?? 0)); },
                ],
                [
                    'key' => 'is_active', 'label' => 'สถานะ', 'align' => 'center',
                    'render' => function ($p) {
                        return !empty($p['is_active'])
                            ? '<span class="badge-pill badge-pill-success">active</span>'
                            : '<span class="badge-pill badge-pill-neutral">inactive</span>';
                    },
                ],
                [
                    'key' => 'actions', 'label' => 'จัดการ', 'align' => 'center',
                    'render' => function ($product) {
                        $id = (int) ($product['id'] ?? 0);
                        $payload = json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                        $toggleTitle = !empty($product['is_active']) ? 'ปิดขาย' : 'เปิดขาย';
                        $toggleIcon = !empty($product['is_active']) ? 'fa-eye-slash' : 'fa-eye';
                        $out = '<div class="data-table-row-actions">';
                        $out .= '<button type="button" onclick=\'editOdooProduct(' . $payload . ')\' class="data-table-row-action" title="แก้ไข"><i class="fas fa-edit"></i></button>';
                        $out .= '<form method="POST" style="display:inline;"><input type="hidden" name="action" value="local_toggle"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="data-table-row-action" title="' . $toggleTitle . '"><i class="fas ' . $toggleIcon . '"></i></button></form>';
                        $out .= '<form method="POST" style="display:inline;" onsubmit="return confirm(\'ลบสินค้านี้ออกจาก cache?\')"><input type="hidden" name="action" value="local_delete"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="data-table-row-action data-table-row-action-danger" title="ลบ"><i class="fas fa-trash"></i></button></form>';
                        $out .= '</div>';
                        return $out;
                    },
                ],
            ];
            echo renderDataTable($odooColumns, $odooProducts, ['emptyContent' => $odooEmpty]);
            ?>
        <?php endif; ?>

        <?php
        // Pagination
        $stripped = array_merge($_GET, ['tab' => 'products']);
        unset($stripped['page'], $stripped['_']);
        $odooBaseUrl = '?' . (empty($stripped) ? '' : http_build_query($stripped) . '&');
        echo renderPagination($page, $totalPages, $perPage, $odooBaseUrl, [
            'total' => $totalProducts,
            'offset' => $offset,
            'showInfo' => true,
        ]);
        ?>
    </div>

    <?php
    // Product modal (Odoo branch)
    $odooModalBody = '
        <div class="modal-grid">
            <div class="modal-col">
                <div class="modal-row-2">
                    <div class="field">
                        <label class="field-label">รหัสสินค้า (product_code)</label>
                        <input type="text" name="product_code" id="odoo_product_code" class="field-input" placeholder="เว้นว่างจะใช้ SKU หรือ auto LOC-...">
                    </div>
                    <div class="field">
                        <label class="field-label">SKU</label>
                        <input type="text" name="sku" id="odoo_sku" class="field-input">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">บาร์โค้ด</label>
                    <input type="text" name="barcode" id="odoo_barcode" class="field-input">
                </div>

                <div class="field">
                    <label class="field-label">ชื่อสินค้า *</label>
                    <input type="text" name="name" id="odoo_name" required class="field-input">
                </div>

                <div class="field">
                    <label class="field-label">ชื่อภาษาอังกฤษ</label>
                    <input type="text" name="name_en" id="odoo_name_en" class="field-input" placeholder="English name">
                </div>

                <div class="field">
                    <label class="field-label">ชื่อสามัญ / Generic Name</label>
                    <input type="text" name="generic_name" id="odoo_generic_name" class="field-input" placeholder="เช่น IBUPROFEN 100 MG/5 ML">
                </div>

                <div class="modal-row-2">
                    <div class="field">
                        <label class="field-label">หมวดหมู่</label>
                        <input type="text" name="category" id="odoo_category" list="odooCategoryList" class="field-input" placeholder="พิมพ์หรือเลือก">
                        <datalist id="odooCategoryList">';
    foreach ($categories as $catName) {
        $odooModalBody .= '<option value="' . htmlspecialchars((string) $catName) . '"></option>';
    }
    $odooModalBody .= '
                        </datalist>
                    </div>
                    <div class="field">
                        <label class="field-label">ผู้ผลิต</label>
                        <input type="text" name="manufacturer" id="odoo_manufacturer" class="field-input">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">รายละเอียด / สรรพคุณ</label>
                    <textarea name="description" id="odoo_description" rows="2" class="field-input" placeholder="สรรพคุณ, คุณสมบัติ"></textarea>
                </div>

                <div class="field">
                    <label class="field-label">วิธีใช้</label>
                    <textarea name="usage_instructions" id="odoo_usage_instructions" rows="2" class="field-input" placeholder="วิธีรับประทาน, ขนาดยา"></textarea>
                </div>
            </div>

            <div class="modal-col">
                <div class="field">
                    <label class="field-label">URL รูปภาพ</label>
                    <input type="url" name="image_url" id="odoo_image_url" class="field-input" placeholder="https://...">
                </div>

                <div class="modal-row-2">
                    <div class="field">
                        <label class="field-label">ราคา (list_price) *</label>
                        <input type="number" name="price" id="odoo_price" required min="0" step="0.01" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">ราคาออนไลน์/ลด</label>
                        <input type="number" name="sale_price" id="odoo_sale_price" min="0" step="0.01" class="field-input">
                    </div>
                </div>

                <div class="modal-row-3">
                    <div class="field">
                        <label class="field-label">Stock (saleable_qty)</label>
                        <input type="number" name="stock" id="odoo_stock" value="0" step="0.01" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">หน่วยนับ</label>
                        <input type="text" name="base_unit" id="odoo_base_unit" class="field-input" placeholder="ขวด, กล่อง">
                    </div>
                    <div class="field">
                        <label class="field-label">หน่วยจำนวน</label>
                        <input type="text" name="unit" id="odoo_unit" class="field-input" placeholder="ขวด[ 60ML ]">
                    </div>
                </div>

                <div class="field-checkbox">
                    <input type="checkbox" name="is_active" id="odoo_is_active" checked>
                    <label for="odoo_is_active">เปิดขาย</label>
                </div>

                <div class="promo-card">
                    <h4 class="promo-card-title"><i class="fas fa-star"></i>ตั้งค่าโปรโมชั่น</h4>
                    <div class="promo-card-options">
                        <div class="field-checkbox">
                            <input type="checkbox" name="is_featured" id="odoo_is_featured">
                            <label for="odoo_is_featured"><i class="fas fa-thumbs-up" style="color:var(--color-amber-500);"></i>สินค้าแนะนำ</label>
                        </div>
                        <div class="field-checkbox">
                            <input type="checkbox" name="is_flash_sale" id="odoo_is_flash_sale">
                            <label for="odoo_is_flash_sale"><i class="fas fa-bolt" style="color:var(--color-rose-500);"></i>Flash Sale</label>
                        </div>
                        <div class="field-checkbox">
                            <input type="checkbox" name="is_choice" id="odoo_is_choice">
                            <label for="odoo_is_choice"><i class="fas fa-award" style="color:var(--color-primary-500);"></i>Choice</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    ';
    $odooModalFooter = '
        <button type="button" data-modal-close="odooProductModal" class="btn btn-secondary">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i>บันทึก</button>
    ';
    echo renderModal('odooProductModal', 'เพิ่มสินค้า', $odooModalBody, $odooModalFooter, [
        'size' => 'xl',
        'formOpen' => '<form method="POST" id="odooProductForm"><input type="hidden" name="action" id="odooFormAction" value="local_create"><input type="hidden" name="id" id="odooFormId">',
        'formClose' => '</form>',
    ]);
    echo renderToastContainer();
    ?>

    <script>
    function openOdooProductModal() {
        document.getElementById('odooFormAction').value = 'local_create';
        document.getElementById('odooFormId').value = '';
        var title = document.getElementById('odooProductModal_title');
        if (title) title.textContent = 'เพิ่มสินค้า';
        document.getElementById('odooProductForm').reset();
        document.getElementById('odoo_is_active').checked = true;
        document.getElementById('odoo_stock').value = 0;
        if (window.openModalShell) window.openModalShell('odooProductModal');
    }
    function closeOdooProductModal() {
        if (window.closeModalShell) window.closeModalShell('odooProductModal');
    }
    function editOdooProduct(p) {
        document.getElementById('odooFormAction').value = 'local_update';
        document.getElementById('odooFormId').value = p.id || '';
        var title = document.getElementById('odooProductModal_title');
        if (title) title.textContent = 'แก้ไขสินค้า';

        document.getElementById('odoo_product_code').value = p.product_code || '';
        document.getElementById('odoo_sku').value = p.sku || '';
        document.getElementById('odoo_barcode').value = p.barcode || '';
        document.getElementById('odoo_name').value = p.name || '';
        document.getElementById('odoo_name_en').value = p.name_en || '';
        document.getElementById('odoo_generic_name').value = p.generic_name || '';
        document.getElementById('odoo_category').value = p.category || '';
        document.getElementById('odoo_manufacturer').value = p.manufacturer || '';
        document.getElementById('odoo_description').value = p.description || '';
        document.getElementById('odoo_usage_instructions').value = p.usage_instructions || '';
        document.getElementById('odoo_image_url').value = p.image_url || '';
        document.getElementById('odoo_price').value = p.list_price || '';
        document.getElementById('odoo_sale_price').value = (p.sale_price != null ? p.sale_price : (p.online_price || ''));
        document.getElementById('odoo_stock').value = p.saleable_qty != null ? p.saleable_qty : 0;
        document.getElementById('odoo_base_unit').value = p.base_unit || '';
        document.getElementById('odoo_unit').value = p.unit || '';
        document.getElementById('odoo_is_active').checked = String(p.is_active) === '1' || p.is_active === 1 || p.is_active === true;
        document.getElementById('odoo_is_featured').checked = String(p.is_featured) === '1' || p.is_featured === 1;
        document.getElementById('odoo_is_flash_sale').checked = String(p.is_flash_sale) === '1' || p.is_flash_sale === 1;
        document.getElementById('odoo_is_choice').checked = String(p.is_choice) === '1' || p.is_choice === 1;
        if (window.openModalShell) window.openModalShell('odooProductModal');
    }
    // Fire any flash messages as toasts (in addition to the inline banner above).
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($odooSyncMessage): ?>
        if (window.fireToast) window.fireToast(<?= json_encode($odooSyncMessage) ?>, 'success');
        <?php endif; ?>
        <?php if ($odooSyncError): ?>
        if (window.fireToast) window.fireToast(<?= json_encode($odooSyncError) ?>, 'error');
        <?php endif; ?>
        <?php if ($odooError): ?>
        if (window.fireToast) window.fireToast(<?= json_encode('ไม่สามารถโหลดข้อมูลสินค้าได้: ' . $odooError) ?>, 'error');
        <?php endif; ?>
    });
    </script>
    <?php
    return;
}

$shop = new UnifiedShop($db, null, $currentBotId);
$tablesExist = $shop->isReady();
$useBusinessItems = $shop->isV25();
$productsTable = $shop->getItemsTable() ?? 'products';
$categoriesTable = $shop->getCategoriesTable() ?? 'product_categories';

// Create tables if not exist
if (!$tablesExist) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS product_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            image_url VARCHAR(500),
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            category_id INT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            sale_price DECIMAL(10,2) NULL,
            image_url VARCHAR(500),
            item_type ENUM('physical','digital','service','booking','content') DEFAULT 'physical',
            delivery_method ENUM('shipping','email','line','download','onsite') DEFAULT 'shipping',
            stock INT DEFAULT 0,
            sku VARCHAR(100),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("INSERT INTO product_categories (name, sort_order) VALUES
            ('สินค้าทั่วไป', 1), ('สินค้าแนะนำ', 2), ('โปรโมชั่น', 3)");

        $tablesExist = true;
        $productsTable = 'products';
        $categoriesTable = 'product_categories';
    } catch (Exception $e) {
        $error = "ไม่สามารถสร้างตารางได้: " . $e->getMessage();
    }
}

// Item types
$itemTypes = [
    'physical' => ['icon' => '📦', 'label' => 'สินค้าจัดส่ง'],
    'digital' => ['icon' => '🎮', 'label' => 'สินค้าดิจิทัล'],
    'service' => ['icon' => '💆', 'label' => 'บริการ'],
    'booking' => ['icon' => '📅', 'label' => 'จองคิว'],
    'content' => ['icon' => '📚', 'label' => 'เนื้อหา']
];

// Check columns
$hasItemType = false;
$hasNewColumns = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'item_type'");
    $hasItemType = $stmt->rowCount() > 0;
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'barcode'");
    $hasNewColumns = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $cols = ['category_id', 'name', 'description', 'price', 'sale_price', 'image_url', 'stock', 'sku', 'is_active'];
        $data = [
            $_POST['category_id'] ?: null,
            $_POST['name'],
            $_POST['description'],
            (float)$_POST['price'],
            $_POST['sale_price'] ? (float)$_POST['sale_price'] : null,
            $_POST['image_url'],
            (int)$_POST['stock'],
            $_POST['sku'] ?: null,
            isset($_POST['is_active']) ? 1 : 0
        ];

        // Extended product fields
        $cols = array_merge($cols, ['barcode', 'manufacturer', 'generic_name', 'usage_instructions', 'unit', 'name_en', 'base_unit']);
        $data = array_merge($data, [
            $_POST['barcode'] ?: null,
            $_POST['manufacturer'] ?: null,
            $_POST['generic_name'] ?: null,
            $_POST['usage_instructions'] ?: null,
            $_POST['unit'] ?: null,
            $_POST['name_en'] ?: null,
            $_POST['base_unit'] ?: null
        ]);

        if ($hasItemType) {
            $cols = array_merge($cols, ['item_type', 'delivery_method']);
            $data = array_merge($data, [$_POST['item_type'] ?? 'physical', $_POST['delivery_method'] ?? 'shipping']);
        }

        // Promotion settings
        $cols = array_merge($cols, ['is_featured', 'is_flash_sale', 'is_choice', 'flash_sale_end']);
        $data = array_merge($data, [
            isset($_POST['is_featured']) ? 1 : 0,
            isset($_POST['is_flash_sale']) ? 1 : 0,
            isset($_POST['is_choice']) ? 1 : 0,
            !empty($_POST['flash_sale_end']) ? $_POST['flash_sale_end'] : null
        ]);

        if ($action === 'create') {
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare("INSERT INTO {$productsTable} (" . implode(',', $cols) . ") VALUES ({$placeholders})");
        } else {
            $sets = implode('=?, ', $cols) . '=?';
            $data[] = $_POST['id'];
            $stmt = $db->prepare("UPDATE {$productsTable} SET {$sets} WHERE id=?");
        }
        $stmt->execute($data);

    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id = ?");
        $stmt->execute([$_POST['id']]);

    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);

    } elseif ($action === 'bulk_deactivate') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 0 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }

    } elseif ($action === 'bulk_activate') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 1 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }

    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }

    } elseif ($action === 'deactivate_out_of_stock') {
        $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 0 AND is_active = 1");
        $_SESSION['bulk_message'] = 'ปิดสินค้าที่หมด stock แล้ว ' . $stmt->rowCount() . ' รายการ';

    } elseif ($action === 'deactivate_low_stock') {
        $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 5 AND is_active = 1");
        $_SESSION['bulk_message'] = 'ปิดสินค้าที่ stock น้อย แล้ว ' . $stmt->rowCount() . ' รายการ';
    }

    // Use JavaScript redirect since headers already sent by header.php
    $redirectUrl = '?tab=products&' . http_build_query(array_diff_key($_GET, ['tab' => '']));
    echo "<script>window.location.href = '{$redirectUrl}';</script>";
    exit;
}

if (!$tablesExist):
    echo getEmptyStateStyles();
    echo getInventorySharedStyles();
    ?>
<div class="data-table-card"><?= renderEmptyState('fas fa-exclamation-triangle', 'ระบบร้านค้ายังไม่พร้อมใช้งาน') ?></div>
<?php return; endif;

// Get categories
$categories = [];
try {
    $stmt = $db->query("SELECT * FROM {$categoriesTable} WHERE is_active = 1 ORDER BY sort_order");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

// Filters
$categoryFilter = $_GET['category'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$stockFilter = $_GET['stock_filter'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortDir = $_GET['dir'] ?? 'DESC';

// Validate sort
$allowedSorts = ['id', 'name', 'price', 'stock', 'created_at', 'sku'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'created_at';
$sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100, 200])) $perPage = 50;

// Count total
$countSql = "SELECT COUNT(*) FROM {$productsTable} p WHERE 1=1";
$params = [];

if ($categoryFilter) {
    $countSql .= " AND p.category_id = ?";
    $params[] = (int)$categoryFilter;
}
if ($typeFilter && $hasItemType) {
    $countSql .= " AND p.item_type = ?";
    $params[] = $typeFilter;
}
if ($stockFilter) {
    switch ($stockFilter) {
        case 'low': $countSql .= " AND p.stock > 0 AND p.stock <= 5"; break;
        case 'out': $countSql .= " AND p.stock <= 0"; break;
        case 'inactive': $countSql .= " AND p.is_active = 0"; break;
    }
}
if ($searchFilter) {
    if ($hasNewColumns) {
        $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    } else {
        $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    }
}

$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalProducts = $stmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);
$offset = ($page - 1) * $perPage;

// Get products
$sql = "SELECT p.*, c.name as category_name FROM {$productsTable} p
        LEFT JOIN {$categoriesTable} c ON p.category_id = c.id
        WHERE 1=1";
$params = [];

if ($categoryFilter) {
    $sql .= " AND p.category_id = ?";
    $params[] = (int)$categoryFilter;
}
if ($typeFilter && $hasItemType) {
    $sql .= " AND p.item_type = ?";
    $params[] = $typeFilter;
}
if ($stockFilter) {
    switch ($stockFilter) {
        case 'low': $sql .= " AND p.stock > 0 AND p.stock <= 5"; break;
        case 'out': $sql .= " AND p.stock <= 0"; break;
        case 'inactive': $sql .= " AND p.is_active = 0"; break;
    }
}
if ($searchFilter) {
    if ($hasNewColumns) {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    } else {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    }
}

$sql .= " ORDER BY p.{$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Stats
$statsStmt = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN stock > 0 AND stock <= 5 AND is_active = 1 THEN 1 ELSE 0 END) as low_stock_active,
    SUM(CASE WHEN stock <= 0 AND is_active = 1 THEN 1 ELSE 0 END) as out_of_stock_active
    FROM {$productsTable}");
$stats = $statsStmt->fetch();

// Build query string helper
function buildProductQuery($overrides = []) {
    $params = array_merge($_GET, $overrides);
    $params['tab'] = 'products';
    unset($params['_']);
    return http_build_query($params);
}

function productSortLink($column, $label) {
    $sortBy = $_GET['sort'] ?? 'created_at';
    $sortDir = $_GET['dir'] ?? 'DESC';
    $newDir = ($sortBy === $column && $sortDir === 'ASC') ? 'DESC' : 'ASC';
    return '?' . buildProductQuery(['sort' => $column, 'dir' => $newDir, 'page' => 1]);
}

// Emit partial styles + the inventory-shared local styles
echo getPageHeaderStyles();
echo getToolbarStyles();
echo getDataTableStyles();
echo getEmptyStateStyles();
echo getPaginationStyles();
echo getModalStyles();
echo getToastStyles();
echo getInventorySharedStyles();

$bulkMessage = $_SESSION['bulk_message'] ?? null;
unset($_SESSION['bulk_message']);
?>

<div class="space-y-4">
    <?php
    echo renderPageHeader(
        'จัดการสินค้า',
        'เพิ่ม / แก้ไข / ลบ / จัดเรียง / ค้นหา สินค้าทั้งหมดในร้านค้า',
        ['label' => 'เพิ่มสินค้า', 'icon' => 'fas fa-plus', 'onclick' => 'openProductModal()', 'variant' => 'success']
    );
    ?>

    <!-- Stats Cards -->
    <div class="stat-grid">
        <div class="stat-tile">
            <div class="stat-tile-icon stat-tile-icon-emerald"><i class="fas fa-box"></i></div>
            <div class="stat-tile-body">
                <div class="stat-tile-label">สินค้าทั้งหมด</div>
                <div class="stat-tile-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon stat-tile-icon-primary"><i class="fas fa-check-circle"></i></div>
            <div class="stat-tile-body">
                <div class="stat-tile-label">เปิดขาย</div>
                <div class="stat-tile-value"><?= number_format($stats['active']) ?></div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon stat-tile-icon-amber"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-tile-body">
                <div class="stat-tile-label">สินค้าใกล้หมด</div>
                <div class="stat-tile-value"><?= number_format($stats['low_stock']) ?></div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon stat-tile-icon-rose"><i class="fas fa-times-circle"></i></div>
            <div class="stat-tile-body">
                <div class="stat-tile-label">สินค้าหมด</div>
                <div class="stat-tile-value"><?= number_format($stats['out_of_stock']) ?></div>
            </div>
        </div>
    </div>

    <?php
    // Toolbar: search / category / per_page / sort & filter chips / categories link
    $categoryOptions = [];
    foreach ($categories as $cat) {
        $categoryOptions[] = ['value' => (string) $cat['id'], 'label' => (string) $cat['name'], 'selected' => $categoryFilter == $cat['id']];
    }
    $perPageOptions = [];
    foreach ([20, 50, 100, 200] as $size) {
        $perPageOptions[] = ['value' => (string) $size, 'label' => $size . '/หน้า', 'selected' => $perPage == $size];
    }
    // Sort + filter chips
    $sortChips = [
        ['href' => '?' . buildProductQuery(['sort' => 'price', 'dir' => ($sortBy == 'price' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]),
            'icon' => 'fas fa-baht-sign', 'label' => 'ราคา', 'active' => $sortBy == 'price', 'tone' => 'success'],
        ['href' => '?' . buildProductQuery(['sort' => 'stock', 'dir' => ($sortBy == 'stock' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]),
            'icon' => 'fas fa-boxes-stacked', 'label' => 'สต็อก', 'active' => $sortBy == 'stock', 'tone' => 'primary'],
        ['href' => '?' . buildProductQuery(['sort' => 'name', 'dir' => ($sortBy == 'name' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]),
            'icon' => 'fas fa-font', 'label' => 'ชื่อ', 'active' => $sortBy == 'name', 'tone' => 'primary'],
        ['href' => '?' . buildProductQuery(['sort' => 'created_at', 'dir' => 'DESC', 'page' => 1]),
            'icon' => 'fas fa-clock', 'label' => 'ล่าสุด', 'active' => $sortBy == 'created_at', 'tone' => 'warning'],
        ['href' => '?' . buildProductQuery(['stock_filter' => 'low', 'page' => 1]),
            'icon' => 'fas fa-exclamation-triangle', 'label' => 'ใกล้หมด', 'active' => $stockFilter == 'low', 'tone' => 'warning'],
        ['href' => '?' . buildProductQuery(['stock_filter' => 'out', 'page' => 1]),
            'icon' => 'fas fa-times-circle', 'label' => 'หมด', 'active' => $stockFilter == 'out', 'tone' => 'danger'],
        ['href' => '?' . buildProductQuery(['stock_filter' => 'inactive', 'page' => 1]),
            'icon' => 'fas fa-eye-slash', 'label' => 'ปิดขาย', 'active' => $stockFilter == 'inactive', 'tone' => 'neutral'],
    ];
    if ($stockFilter) {
        $sortChips[] = ['href' => '?' . buildProductQuery(['stock_filter' => '']),
            'icon' => 'fas fa-times', 'label' => 'ล้างตัวกรอง', 'active' => false, 'tone' => 'neutral'];
    }
    // Bulk top-level (deactivate by stock)
    $bulkActions = [];
    if ($stats['out_of_stock_active'] > 0) {
        $bulkActions[] = ['label' => 'ปิดสินค้าหมด (' . number_format($stats['out_of_stock_active']) . ')', 'icon' => 'fas fa-ban', 'tone' => 'warning',
            'onclick' => "if(confirm('ปิดสินค้าที่หมด stock ทั้งหมด?')){var f=document.createElement('form');f.method='POST';f.innerHTML='<input type=\"hidden\" name=\"action\" value=\"deactivate_out_of_stock\">';document.body.appendChild(f);f.submit();}"];
    }
    if ($stats['low_stock_active'] > 0) {
        $bulkActions[] = ['label' => 'ปิดสินค้าใกล้หมด (' . number_format($stats['low_stock_active']) . ')', 'icon' => 'fas fa-exclamation-triangle', 'tone' => 'warning',
            'onclick' => "if(confirm('ปิดสินค้าที่ stock น้อยกว่า 5?')){var f=document.createElement('form');f.method='POST';f.innerHTML='<input type=\"hidden\" name=\"action\" value=\"deactivate_low_stock\">';document.body.appendChild(f);f.submit();}"];
    }
    $bulkActions[] = ['label' => 'หมวดหมู่', 'icon' => 'fas fa-folder', 'tone' => 'neutral', 'onclick' => "window.location.href='/shop/categories'"];

    echo renderToolbar([
        'method' => 'GET',
        'hiddenFields' => [
            'tab' => 'products',
            'sort' => $sortBy,
            'dir' => $sortDir,
        ],
        'search' => [
            'name' => 'search',
            'value' => $searchFilter,
            'placeholder' => 'ค้นหา SKU / ชื่อ…',
        ],
        'selects' => [
            ['name' => 'category', 'value' => $categoryFilter, 'placeholder' => 'ทุกหมวดหมู่', 'options' => $categoryOptions],
            ['name' => 'per_page', 'value' => (string) $perPage, 'options' => $perPageOptions],
        ],
        'resetHref' => ($searchFilter || $categoryFilter) ? '?tab=products' : null,
        'chips' => $sortChips,
        'chipGroupLabel' => 'จัดเรียง & กรอง:',
        'bulkInfo' => '<span id="selectedCount">0</span> รายการที่เลือก:',
        'bulkActions' => array_merge(
            $bulkActions,
            [
                ['label' => 'เปิดขาย', 'icon' => 'fas fa-check', 'tone' => 'success', 'onclick' => "bulkAction('bulk_activate')"],
                ['label' => 'ปิดขาย', 'icon' => 'fas fa-eye-slash', 'tone' => 'neutral', 'onclick' => "bulkAction('bulk_deactivate')"],
                ['label' => 'ลบ', 'icon' => 'fas fa-trash', 'tone' => 'danger', 'onclick' => "bulkAction('bulk_delete')"],
            ]
        ),
        'bulkContainerId' => 'selectionActions',
    ]);
    ?>

    <!-- Hidden form for bulk actions -->
    <form id="bulkForm" method="POST" style="display:none;">
        <input type="hidden" name="action" id="bulkAction">
        <div id="bulkIds"></div>
    </form>

    <?php
    // Products data-table
    $emptyContent = renderEmptyState('fas fa-box-open', 'ไม่พบสินค้า');
    $columns = [
        [
            'key' => 'image', 'label' => 'รูป', 'align' => 'center', 'width' => '64px',
            'render' => function ($product) {
                if (!empty($product['image_url'])) {
                    return '<div style="width:44px;height:44px;border-radius:var(--radius-sm,8px);overflow:hidden;background:var(--color-slate-100);"><img src="' . htmlspecialchars($product['image_url']) . '" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.src=\'https://via.placeholder.com/48?text=No\'"></div>';
                }
                return '<div style="width:44px;height:44px;border-radius:var(--radius-sm,8px);background:var(--color-slate-100);display:flex;align-items:center;justify-content:center;color:var(--color-slate-300);"><i class="fas fa-image"></i></div>';
            },
        ],
        [
            'key' => 'sku', 'label' => 'SKU', 'sortable' => true,
            'sortHref' => productSortLink('sku', 'SKU'),
            'sortDir' => $sortBy === 'sku' ? $sortDir : null,
            'render' => function ($product) {
                if (!empty($product['sku'])) {
                    return '<span style="font-family:var(--font-mono);font-size:var(--text-xs,12px);font-weight:500;color:var(--color-primary-700);background:var(--color-primary-50);padding:2px 6px;border-radius:6px;">' . htmlspecialchars($product['sku']) . '</span>';
                }
                return '';
            },
        ],
        [
            'key' => 'name', 'label' => 'ชื่อสินค้า', 'sortable' => true,
            'sortHref' => productSortLink('name', 'ชื่อสินค้า'),
            'sortDir' => $sortBy === 'name' ? $sortDir : null,
            'render' => function ($product) {
                return '<div style="font-weight:500;color:var(--color-dark-800);">' . htmlspecialchars($product['name']) . '</div>';
            },
        ],
        [
            'key' => 'category', 'label' => 'หมวดหมู่',
            'render' => function ($product) {
                return '<span style="font-size:var(--text-xs,12px);color:var(--color-dark-500);">' . htmlspecialchars($product['category_name'] ?? '-') . '</span>';
            },
        ],
        [
            'key' => 'price', 'label' => 'ราคา', 'align' => 'right', 'sortable' => true,
            'sortHref' => productSortLink('price', 'ราคา'),
            'sortDir' => $sortBy === 'price' ? $sortDir : null,
            'render' => function ($product) {
                if (!empty($product['sale_price'])) {
                    return '<div style="color:var(--color-rose-500);font-weight:700;">฿' . number_format($product['sale_price'], 2) . '</div>'
                        . '<div style="font-size:var(--text-xs,12px);color:var(--color-slate-400);text-decoration:line-through;">฿' . number_format($product['price'], 2) . '</div>';
                }
                return '<div style="color:var(--color-emerald-600);font-weight:700;">฿' . number_format($product['price'], 2) . '</div>';
            },
        ],
        [
            'key' => 'stock', 'label' => 'Stock', 'align' => 'center', 'sortable' => true,
            'sortHref' => productSortLink('stock', 'Stock'),
            'sortDir' => $sortBy === 'stock' ? $sortDir : null,
            'render' => function ($product) {
                if ($product['stock'] <= 0) {
                    return '<span class="badge-pill badge-pill-danger">หมด</span>';
                }
                if ($product['stock'] <= 5) {
                    return '<span class="badge-pill badge-pill-warning">' . (int) $product['stock'] . '</span>';
                }
                return '<span style="color:var(--color-dark-800);font-weight:500;">' . number_format($product['stock']) . '</span>';
            },
        ],
        [
            'key' => 'is_active', 'label' => 'สถานะ', 'align' => 'center',
            'render' => function ($product) {
                return $product['is_active']
                    ? '<span class="badge-pill badge-pill-success">เปิด</span>'
                    : '<span class="badge-pill badge-pill-neutral">ปิด</span>';
            },
        ],
        [
            'key' => 'actions', 'label' => 'จัดการ', 'align' => 'center', 'width' => '112px',
            'render' => function ($product) {
                $id = (int) $product['id'];
                $payload = json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT);
                $toggleIcon = $product['is_active'] ? 'fa-eye-slash' : 'fa-eye';
                $out = '<div class="data-table-row-actions">';
                $out .= '<button onclick=\'editProduct(' . $payload . ')\' class="data-table-row-action"><i class="fas fa-edit"></i></button>';
                $out .= '<form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="data-table-row-action"><i class="fas ' . $toggleIcon . '"></i></button></form>';
                $out .= '<form method="POST" onsubmit="return confirm(\'ลบสินค้านี้?\')" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="data-table-row-action data-table-row-action-danger"><i class="fas fa-trash"></i></button></form>';
                $out .= '</div>';
                return $out;
            },
        ],
    ];
    echo renderDataTable($columns, $products, [
        'emptyContent' => $emptyContent,
        'selectable' => true,
        'rowCheckboxClass' => 'product-checkbox',
        'selectOnChange' => 'updateSelection()',
        'selectAllOnChange' => 'toggleSelectAll()',
    ]);
    ?>

    <?php
    // Pagination strip
    $stripped = array_merge($_GET, ['tab' => 'products']);
    unset($stripped['page'], $stripped['_']);
    $shopBaseUrl = '?' . (empty($stripped) ? '' : http_build_query($stripped) . '&');
    echo renderPagination($page, max(1, (int) $totalPages), $perPage, $shopBaseUrl, [
        'total' => (int) $totalProducts,
        'offset' => $offset,
        'showInfo' => true,
    ]);
    ?>
</div>

<?php
// Product Modal (Local / Shop branch)
$shopModalBody = '
    <div class="modal-grid">
        <div class="modal-col">
            <div class="modal-row-2">
                <div class="field">
                    <label class="field-label">รหัสสินค้า (SKU)</label>
                    <input type="text" name="sku" id="sku" class="field-input">
                </div>
                <div class="field">
                    <label class="field-label">บาร์โค้ด</label>
                    <input type="text" name="barcode" id="barcode" class="field-input">
                </div>
            </div>

            <div class="field">
                <label class="field-label">ชื่อสินค้า *</label>
                <input type="text" name="name" id="name" required class="field-input">
            </div>

            <div class="field">
                <label class="field-label">ชื่อภาษาอังกฤษ</label>
                <input type="text" name="name_en" id="name_en" class="field-input" placeholder="English name">
            </div>

            <div class="field">
                <label class="field-label">ชื่อสามัญ / Generic Name</label>
                <input type="text" name="generic_name" id="generic_name" class="field-input" placeholder="เช่น IBUPROFEN 100 MG/5 ML">
            </div>

            <div class="modal-row-2">
                <div class="field">
                    <label class="field-label">หมวดหมู่</label>
                    <select name="category_id" id="category_id" class="field-input">
                        <option value="">-- เลือก --</option>';
foreach ($categories as $cat) {
    $shopModalBody .= '<option value="' . (int) $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
}
$shopModalBody .= '
                    </select>
                </div>
                <div class="field">
                    <label class="field-label">ผู้ผลิต</label>
                    <input type="text" name="manufacturer" id="manufacturer" class="field-input">
                </div>
            </div>

            <div class="field">
                <label class="field-label">รายละเอียด / สรรพคุณ</label>
                <textarea name="description" id="description" rows="2" class="field-input" placeholder="สรรพคุณ, คุณสมบัติ"></textarea>
            </div>

            <div class="field">
                <label class="field-label">วิธีใช้</label>
                <textarea name="usage_instructions" id="usage_instructions" rows="2" class="field-input" placeholder="วิธีรับประทาน, ขนาดยา"></textarea>
            </div>
        </div>

        <div class="modal-col">
            <div class="field">
                <label class="field-label">URL รูปภาพ</label>
                <input type="url" name="image_url" id="image_url" class="field-input" placeholder="https://...">
            </div>

            <div class="modal-row-2">
                <div class="field">
                    <label class="field-label">ราคา *</label>
                    <input type="number" name="price" id="price" required min="0" step="0.01" class="field-input">
                </div>
                <div class="field">
                    <label class="field-label">ราคาลด</label>
                    <input type="number" name="sale_price" id="sale_price" min="0" step="0.01" class="field-input">
                </div>
            </div>

            <div class="modal-row-3">
                <div class="field">
                    <label class="field-label">Stock คงเหลือ</label>
                    <input type="number" id="stock" value="0" readonly class="field-input field-input-readonly" title="Stock จะเปลี่ยนผ่านการรับสินค้า/ขาย/ปรับ Stock เท่านั้น">
                </div>
                <div class="field">
                    <label class="field-label">หน่วยนับ</label>
                    <input type="text" name="base_unit" id="base_unit" class="field-input" placeholder="ขวด, กล่อง, แผง">
                </div>
                <div class="field">
                    <label class="field-label">หน่วยจำนวน</label>
                    <input type="text" name="unit" id="unit" class="field-input" placeholder="ขวด[ 60ML ]">
                </div>
            </div>

            <div class="field-checkbox">
                <input type="checkbox" name="is_active" id="is_active" checked>
                <label for="is_active">เปิดขาย</label>
            </div>

            <div class="promo-card">
                <h4 class="promo-card-title"><i class="fas fa-star"></i>ตั้งค่าโปรโมชั่น</h4>
                <div class="promo-card-options">
                    <div class="field-checkbox">
                        <input type="checkbox" name="is_featured" id="is_featured">
                        <label for="is_featured"><i class="fas fa-thumbs-up" style="color:var(--color-amber-500);"></i>สินค้าแนะนำ</label>
                    </div>
                    <div class="field-checkbox">
                        <input type="checkbox" name="is_flash_sale" id="is_flash_sale">
                        <label for="is_flash_sale"><i class="fas fa-bolt" style="color:var(--color-rose-500);"></i>Flash Sale</label>
                    </div>
                    <div class="field-checkbox">
                        <input type="checkbox" name="is_choice" id="is_choice">
                        <label for="is_choice"><i class="fas fa-award" style="color:var(--color-primary-500);"></i>Choice</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
';
$shopModalFooter = '
    <button type="button" data-modal-close="productModal" class="btn btn-secondary">ยกเลิก</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i>บันทึก</button>
';
echo renderModal('productModal', 'เพิ่มสินค้า', $shopModalBody, $shopModalFooter, [
    'size' => 'xl',
    'formOpen' => '<form method="POST" id="productForm"><input type="hidden" name="action" id="formAction" value="create"><input type="hidden" name="id" id="formId">',
    'formClose' => '</form>',
]);
echo renderToastContainer();
?>

<script>
function openProductModal() {
    document.getElementById('formAction').value = 'create';
    var title = document.getElementById('productModal_title');
    if (title) title.textContent = 'เพิ่มสินค้า';
    document.getElementById('productForm').reset();
    document.getElementById('is_active').checked = true;
    if (window.openModalShell) window.openModalShell('productModal');
}

function closeProductModal() {
    if (window.closeModalShell) window.closeModalShell('productModal');
}

function editProduct(product) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = product.id;
    var title = document.getElementById('productModal_title');
    if (title) title.textContent = 'แก้ไขสินค้า';

    document.getElementById('sku').value = product.sku || '';
    document.getElementById('name').value = product.name || '';
    document.getElementById('description').value = product.description || '';
    document.getElementById('price').value = product.price || '';
    document.getElementById('sale_price').value = product.sale_price || '';
    document.getElementById('stock').value = product.stock || 0;
    document.getElementById('image_url').value = product.image_url || '';
    document.getElementById('category_id').value = product.category_id || '';
    document.getElementById('is_active').checked = product.is_active == 1;

    if (document.getElementById('barcode')) document.getElementById('barcode').value = product.barcode || '';
    if (document.getElementById('unit')) document.getElementById('unit').value = product.unit || '';
    if (document.getElementById('base_unit')) document.getElementById('base_unit').value = product.base_unit || '';
    if (document.getElementById('name_en')) document.getElementById('name_en').value = product.name_en || '';
    if (document.getElementById('generic_name')) document.getElementById('generic_name').value = product.generic_name || '';
    if (document.getElementById('usage_instructions')) document.getElementById('usage_instructions').value = product.usage_instructions || '';
    if (document.getElementById('manufacturer')) document.getElementById('manufacturer').value = product.manufacturer || '';
    if (document.getElementById('is_featured')) document.getElementById('is_featured').checked = product.is_featured == 1;
    if (document.getElementById('is_flash_sale')) document.getElementById('is_flash_sale').checked = product.is_flash_sale == 1;
    if (document.getElementById('is_choice')) document.getElementById('is_choice').checked = product.is_choice == 1;
    if (window.openModalShell) window.openModalShell('productModal');
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = selectAll.checked);
    updateSelection();
}

function updateSelection() {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    const count = checked.length;
    var countEl = document.getElementById('selectedCount');
    if (countEl) countEl.textContent = count;
    var actions = document.getElementById('selectionActions');
    if (actions) actions.style.display = count === 0 ? 'none' : '';
}

function bulkAction(action) {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    if (checked.length === 0) return;

    const confirmMsg = {
        'bulk_activate': 'เปิดขายสินค้าที่เลือก?',
        'bulk_deactivate': 'ปิดขายสินค้าที่เลือก?',
        'bulk_delete': 'ลบสินค้าที่เลือก? การกระทำนี้ไม่สามารถย้อนกลับได้'
    };

    if (!confirm(confirmMsg[action])) return;

    document.getElementById('bulkAction').value = action;
    const idsContainer = document.getElementById('bulkIds');
    idsContainer.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.value;
        idsContainer.appendChild(input);
    });
    document.getElementById('bulkForm').submit();
}

// Initial state for bulk-actions zone (hide until something is selected).
document.addEventListener('DOMContentLoaded', function () {
    var actions = document.getElementById('selectionActions');
    if (actions) actions.style.display = 'none';
    <?php if ($bulkMessage): ?>
    if (window.fireToast) window.fireToast(<?= json_encode($bulkMessage) ?>, 'success');
    <?php endif; ?>
});
</script>
