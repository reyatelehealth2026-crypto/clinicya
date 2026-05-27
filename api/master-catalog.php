<?php
/**
 * api/master-catalog.php — browse + import from the platform-wide master product catalog.
 *
 * Data lives in `zrismpsz_reya_platform.master_products`. Every tenant can read
 * it; nobody can write to it from here (super_admin maintains via a separate
 * admin tool).
 *
 * Actions (auth required, scoped by $_SESSION['current_bot_id']):
 *
 *   GET  ?action=list&q=&page=&per_page=  (default per_page=50, max 200)
 *        → JSON {
 *            ok: true,
 *            total: int, page: int, per_page: int,
 *            items: [{
 *              id, sku, name, name_en, manufacturer, variant,
 *              generic_name, unit, pack_size, image_url,
 *              already_imported: bool
 *            }, ...]
 *          }
 *
 *   POST ?action=import
 *        body: { ids: [1,2,3,...], default_price: float|null, default_stock: int|null,
 *                activate: bool (default false) }
 *        → JSON { ok, inserted: int, updated: int, skipped: int, errors: [] }
 *        Imported items default to is_active=0 so the shop won't show zero-price
 *        products by accident — the user reviews + activates manually.
 *
 * @package Inventory
 * @version 1.0.0
 */
declare(strict_types=1);

@set_time_limit(60);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

// Tenant DB (where we write business_items)
$tenantDb = Database::getInstance()->getConnection();

// Platform DB (where master_products lives)
try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_platform_db', 'message' => $e->getMessage()]);
    exit;
}

// Resolve line_account_id (same logic as inventory-csv.php)
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try {
        $s = $tenantDb->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $s->execute([(int) $_SESSION['user_id']]);
        $lineAccountId = (int) ($s->fetchColumn() ?: 0);
    } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    try {
        $r = $tenantDb->query('SELECT id FROM line_accounts WHERE is_active=1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = (int) ($r['id'] ?? 0);
    } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_line_account']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── action=list ──────────────────────────────────────────────────────────────
if ($action === 'list') {
    $q        = trim((string) ($_GET['q'] ?? ''));
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $perPage  = min(200, max(10, (int) ($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $perPage;

    $where  = ['is_active = 1'];
    $params = [];
    if ($q !== '') {
        // Avoid FULLTEXT for very short Thai queries (MariaDB stopword + min length quirks)
        $where[] = '(name LIKE ? OR name_en LIKE ? OR sku LIKE ? OR generic_name LIKE ? OR manufacturer LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like];
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Total
    $countStmt = $platformDb->prepare("SELECT COUNT(*) FROM master_products {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Page
    $listStmt = $platformDb->prepare(
        "SELECT id, sku, name, name_en, manufacturer, variant, generic_name, unit, pack_size, image_url
         FROM master_products {$whereSql}
         ORDER BY name ASC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $listStmt->execute($params);
    $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Which of these SKUs already exist in this tenant's business_items?
    $skus = array_column($items, 'sku');
    $importedMap = [];
    if (!empty($skus)) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $own = $tenantDb->prepare(
            "SELECT sku FROM business_items WHERE line_account_id = ? AND sku IN ({$placeholders})"
        );
        $own->execute(array_merge([$lineAccountId], $skus));
        foreach ($own->fetchAll(PDO::FETCH_COLUMN) as $owned) {
            $importedMap[(string) $owned] = true;
        }
    }
    foreach ($items as &$it) {
        $it['already_imported'] = isset($importedMap[(string) $it['sku']]);
    }
    unset($it);

    echo json_encode([
        'ok'       => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
        'items'    => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── action=import ────────────────────────────────────────────────────────────
if ($action === 'import') {
    // Body may be JSON or form-encoded
    $raw  = file_get_contents('php://input');
    $body = [];
    if ($raw && ($json = json_decode($raw, true)) && is_array($json)) {
        $body = $json;
    } else {
        $body = $_POST;
    }

    $ids = $body['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));

    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'no_ids', 'message' => 'กรุณาเลือกอย่างน้อย 1 รายการ']);
        exit;
    }
    if (count($ids) > 1000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'too_many', 'message' => 'นำเข้าครั้งละไม่เกิน 1000 รายการ']);
        exit;
    }

    $defaultPrice = isset($body['default_price']) ? (float) $body['default_price'] : 0.0;
    $defaultStock = isset($body['default_stock']) ? (int)   $body['default_stock'] : 0;
    $activate     = !empty($body['activate']);

    // Fetch master rows
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $platformDb->prepare(
        "SELECT id, sku, name, name_en, manufacturer, variant, generic_name, unit, pack_size,
                usage_instructions, description, image_url
         FROM master_products WHERE id IN ({$placeholders}) AND is_active = 1"
    );
    $stmt->execute($ids);
    $masters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($masters)) {
        echo json_encode(['ok' => true, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []]);
        exit;
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    $checkStmt = $tenantDb->prepare('SELECT id FROM business_items WHERE line_account_id = ? AND sku = ? LIMIT 1');
    $insertStmt = $tenantDb->prepare(
        'INSERT INTO business_items
            (line_account_id, sku, name, name_en, manufacturer, dosage_form, generic_name, active_ingredient,
             unit, base_unit, strength, usage_instructions, default_usage_text, description, image_url, photo_path,
             price, sale_price, stock, is_active, created_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW())'
    );
    // For existing SKUs we only refresh the drug-detail fields; never touch
    // the tenant's price/stock/is_active (they may have customised those).
    $refreshStmt = $tenantDb->prepare(
        'UPDATE business_items
            SET name = ?, name_en = ?, manufacturer = ?, dosage_form = ?, generic_name = ?,
                active_ingredient = ?, unit = COALESCE(NULLIF(unit, ""), ?),
                base_unit = COALESCE(NULLIF(base_unit, ""), ?),
                strength = ?, usage_instructions = ?, default_usage_text = ?,
                description = ?, image_url = COALESCE(NULLIF(image_url, ""), ?),
                photo_path = COALESCE(NULLIF(photo_path, ""), ?),
                updated_at = NOW()
          WHERE id = ?'
    );

    try {
        $tenantDb->beginTransaction();
        foreach ($masters as $m) {
            $sku = (string) $m['sku'];

            $checkStmt->execute([$lineAccountId, $sku]);
            $existingId = (int) ($checkStmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $refreshStmt->execute([
                    $m['name'], $m['name_en'] ?: null, $m['manufacturer'] ?: null,
                    $m['variant'] ?: null, $m['generic_name'] ?: null, $m['generic_name'] ?: null,
                    $m['unit'] ?: null, $m['unit'] ?: null,
                    $m['pack_size'] ?: null, $m['usage_instructions'] ?: null, $m['usage_instructions'] ?: null,
                    $m['description'] ?: null, $m['image_url'] ?: null, $m['image_url'] ?: null,
                    $existingId,
                ]);
                $updated++;
            } else {
                $insertStmt->execute([
                    $lineAccountId, $sku, $m['name'], $m['name_en'] ?: null, $m['manufacturer'] ?: null,
                    $m['variant'] ?: null, $m['generic_name'] ?: null, $m['generic_name'] ?: null,
                    $m['unit'] ?: null, $m['unit'] ?: null, $m['pack_size'] ?: null,
                    $m['usage_instructions'] ?: null, $m['usage_instructions'] ?: null,
                    $m['description'] ?: null, $m['image_url'] ?: null, $m['image_url'] ?: null,
                    $defaultPrice, $defaultStock, $activate ? 1 : 0,
                ]);
                $inserted++;
            }
        }
        $tenantDb->commit();
    } catch (\Throwable $e) {
        if ($tenantDb->inTransaction()) $tenantDb->rollBack();
        error_log('[master-catalog] import: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'import_failed',
            'message' => $e->getMessage(),
            'inserted' => $inserted,
            'updated' => $updated,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'activated'=> $activate,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
