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

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'product_units',
    'entity_type' => 'product_unit',
    'columns'     => ['code', 'name', 'name_en', 'sub_unit_id', 'conversion_ratio', 'is_base_unit', 'is_active'],
    'required'    => ['name'],
    'nullable'    => ['code', 'name_en', 'sub_unit_id'],
    'integers'    => ['sub_unit_id'],
    'bools'       => ['is_base_unit', 'is_active'],
    'order_by'    => 'is_base_unit DESC, name ASC',
]);
