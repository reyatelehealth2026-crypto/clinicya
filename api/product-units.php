<?php
/**
 * Product Units API (admin) — CRUD for product_units per tenant.
 * Powers /products.php Tab 5 and the unit dropdown in product add/edit.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

// Columns MUST match the live/canonical product_units schema
// (unit_name/unit_code/factor/product_id), not the never-applied draft that
// used name/conversion_ratio/sub_unit_id. See migration_2026-05-24 note.
reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'product_units',
    'entity_type' => 'product_unit',
    'columns'     => ['product_id', 'unit_name', 'unit_code', 'factor', 'cost_price', 'sale_price', 'barcode', 'is_base_unit', 'is_purchase_unit', 'is_sale_unit', 'is_active'],
    'required'    => ['product_id', 'unit_name'],
    'nullable'    => ['unit_code', 'barcode', 'cost_price', 'sale_price'],
    'integers'    => ['product_id'],
    'bools'       => ['is_base_unit', 'is_purchase_unit', 'is_sale_unit', 'is_active'],
    'order_by'    => 'product_id, factor',
]);
