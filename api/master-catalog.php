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
        "SELECT id, sku, barcode, name, name_en, manufacturer, category, generic_name, unit, pack_size, price, image_url
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

    // master_products may carry the full CNY price/unit array as JSON (product_price);
    // include it only if the column exists so we degrade gracefully on older schemas.
    $hasProductPrice = false;
    try {
        $hasProductPrice = (bool) $platformDb->query("SHOW COLUMNS FROM master_products LIKE 'product_price'")->fetch();
    } catch (\Throwable $e) {
        $hasProductPrice = false;
    }
    $ppSelect = $hasProductPrice ? ', product_price' : '';

    // Fetch master rows
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $platformDb->prepare(
        "SELECT id, sku, barcode, name, name_en, manufacturer, category, generic_name, unit, pack_size,
                price, sale_price, usage_instructions, warning, description, image_url{$ppSelect}
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

    // 2026-06-02: resolve master.category (string) → business_categories.id, create if missing.
    $catCache = [];
    $resolveCat = function (?string $name) use ($tenantDb, $lineAccountId, &$catCache): ?int {
        $name = trim((string) $name);
        if ($name === '') return null;
        $key = mb_strtolower($name);
        if (array_key_exists($key, $catCache)) return $catCache[$key];
        try {
            $s = $tenantDb->prepare('SELECT id FROM business_categories WHERE line_account_id = ? AND LOWER(name) = ? LIMIT 1');
            $s->execute([$lineAccountId, $key]);
            $id = (int) ($s->fetchColumn() ?: 0);
            if ($id === 0) {
                $tenantDb->prepare('INSERT INTO business_categories (line_account_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())')
                    ->execute([$lineAccountId, $name]);
                $id = (int) $tenantDb->lastInsertId();
            }
        } catch (\Throwable $e) { $id = 0; }
        return $catCache[$key] = ($id ?: null);
    };

    $checkStmt = $tenantDb->prepare('SELECT id FROM business_items WHERE line_account_id = ? AND sku = ? LIMIT 1');
    $insertStmt = $tenantDb->prepare(
        'INSERT INTO business_items
            (line_account_id, sku, barcode, name, name_en, manufacturer, category_id, generic_name, active_ingredient,
             unit, base_unit, strength, usage_instructions, default_usage_text, warnings, description, image_url, photo_path,
             price, sale_price, stock, is_active, created_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    // Existing SKUs: refresh drug detail + fill category/barcode if empty; never overwrite tenant price/stock/is_active.
    $refreshStmt = $tenantDb->prepare(
        'UPDATE business_items
            SET name = ?, name_en = ?, manufacturer = ?,
                category_id = COALESCE(category_id, ?),
                barcode = COALESCE(NULLIF(barcode, ""), ?),
                generic_name = ?, active_ingredient = ?,
                unit = COALESCE(NULLIF(unit, ""), ?), base_unit = COALESCE(NULLIF(base_unit, ""), ?),
                strength = ?, usage_instructions = ?, default_usage_text = ?, warnings = ?,
                description = ?, image_url = COALESCE(NULLIF(image_url, ""), ?),
                photo_path = COALESCE(NULLIF(photo_path, ""), ?),
                updated_at = NOW()
          WHERE id = ?'
    );

    // ── Multi-unit seeding ──────────────────────────────────────────────────────
    // The CNY master carries multiple units per product (e.g. ขวด=1, โหล=12) in the
    // product_price[] array. The branch shows units from product_units, which the old
    // import never populated. We seed product_units from the master's units, but only
    // for products that have none yet — never clobber a tenant's own unit edits.
    $unitCountStmt  = $tenantDb->prepare('SELECT COUNT(*) FROM product_units WHERE product_id = ? AND is_active = 1');
    $unitInsertStmt = $tenantDb->prepare(
        'INSERT INTO product_units
            (line_account_id, product_id, unit_name, factor, sale_price, is_base_unit,
             is_purchase_unit, is_sale_unit, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, NOW())
         ON DUPLICATE KEY UPDATE
            factor = VALUES(factor), sale_price = VALUES(sale_price),
            is_base_unit = VALUES(is_base_unit), is_active = 1, updated_at = NOW()'
    );

    // "ขวด[60ML]" → "ขวด"; clamp to product_units.unit_name length (VARCHAR 50)
    $cleanUnitName = static function (string $raw): string {
        $name = preg_replace('/\s*\[[^\]]*\]\s*/u', '', $raw);
        return mb_substr(trim((string) $name), 0, 50);
    };
    // Build the distinct unit list for one master row (multi-unit from product_price,
    // else a single base unit from the legacy `unit` column).
    $buildUnits = static function (array $m) use ($cleanUnitName): array {
        $pp = $m['product_price'] ?? null;
        $list = is_string($pp) ? json_decode($pp, true) : (is_array($pp) ? $pp : null);

        $rows = [];
        if (is_array($list) && !empty($list)) {
            $byUnit = [];
            foreach ($list as $r) {
                if (!is_array($r)) continue;
                $name = $cleanUnitName((string) ($r['unit'] ?? ''));
                if ($name === '') continue;
                $factor = (float) ($r['unit_num'] ?? 1) ?: 1.0;
                $price  = (float) ($r['price'] ?? 0);
                $isGen  = stripos((string) ($r['customer_group'] ?? ''), 'GEN') !== false;
                // Prefer the GEN (retail) price tier; otherwise keep the first seen.
                if (!isset($byUnit[$name]) || ($isGen && empty($byUnit[$name]['is_gen']))) {
                    $byUnit[$name] = ['unit_name' => $name, 'factor' => $factor, 'price' => $price ?: null, 'is_gen' => $isGen];
                }
            }
            $rows = array_values($byUnit);
        }
        if (empty($rows)) {
            $base = $cleanUnitName((string) ($m['unit'] ?? '')) ?: 'ชิ้น';
            $rows = [['unit_name' => $base, 'factor' => 1.0, 'price' => null]];
        }
        // smallest factor = base unit
        usort($rows, static fn ($a, $b) => $a['factor'] <=> $b['factor']);
        foreach ($rows as $i => &$r) { $r['is_base_unit'] = $i === 0 ? 1 : 0; }
        unset($r);
        return $rows;
    };
    $unitsSeeded = 0;

    try {
        $tenantDb->beginTransaction();
        foreach ($masters as $m) {
            $sku = (string) $m['sku'];
            $catId = $resolveCat($m['category'] ?? null);
            // price: user-entered default wins if set (>0), else master price
            $mPrice = (float) ($m['price'] ?? 0);
            $usePrice = $defaultPrice > 0 ? $defaultPrice : $mPrice;

            $checkStmt->execute([$lineAccountId, $sku]);
            $existingId = (int) ($checkStmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $refreshStmt->execute([
                    $m['name'], $m['name_en'] ?: null, $m['manufacturer'] ?: null,
                    $catId,
                    $m['barcode'] ?: null,
                    $m['generic_name'] ?: null, $m['generic_name'] ?: null,
                    $m['unit'] ?: null, $m['unit'] ?: null,
                    $m['pack_size'] ?: null, $m['usage_instructions'] ?: null, $m['usage_instructions'] ?: null,
                    $m['warning'] ?: null,
                    $m['description'] ?: null, $m['image_url'] ?: null, $m['image_url'] ?: null,
                    $existingId,
                ]);
                $productId = $existingId;
                $updated++;
            } else {
                $insertStmt->execute([
                    $lineAccountId, $sku, $m['barcode'] ?: null, $m['name'], $m['name_en'] ?: null, $m['manufacturer'] ?: null,
                    $catId, $m['generic_name'] ?: null, $m['generic_name'] ?: null,
                    $m['unit'] ?: null, $m['unit'] ?: null, $m['pack_size'] ?: null,
                    $m['usage_instructions'] ?: null, $m['usage_instructions'] ?: null, $m['warning'] ?: null,
                    $m['description'] ?: null, $m['image_url'] ?: null, $m['image_url'] ?: null,
                    $usePrice, (float) ($m['sale_price'] ?? 0) ?: null, $defaultStock, $activate ? 1 : 0,
                ]);
                $productId = (int) $tenantDb->lastInsertId();
                $inserted++;
            }

            // Seed units only when the product has none — keeps tenant customisations intact.
            $unitCountStmt->execute([$productId]);
            if ((int) $unitCountStmt->fetchColumn() === 0) {
                foreach ($buildUnits($m) as $u) {
                    $unitInsertStmt->execute([
                        $lineAccountId, $productId, $u['unit_name'], $u['factor'],
                        $u['price'], $u['is_base_unit'],
                    ]);
                    $unitsSeeded++;
                }
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
        'ok'          => true,
        'inserted'    => $inserted,
        'updated'     => $updated,
        'skipped'     => $skipped,
        'units_seeded'=> $unitsSeeded,
        'errors'      => $errors,
        'activated'   => $activate,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
