<?php
/**
 * Drug Groups API (admin) — CRUD for drug_groups per tenant.
 * Powers /products.php Tab 3 and any drug-group dropdown.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'drug_groups',
    'entity_type' => 'drug_group',
    'columns'     => ['code', 'name_th', 'name_en', 'description', 'is_active'],
    'required'    => ['name_th'],
    'bools'       => ['is_active'],
    'nullable'    => ['code', 'name_en', 'description'],
    'order_by'    => 'name_th ASC',
]);
