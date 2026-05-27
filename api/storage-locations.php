<?php
/**
 * Storage Locations API (admin) — CRUD for storage_locations per tenant.
 * Powers /products.php Tab 6 and the location dropdown in product add/edit.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'storage_locations',
    'entity_type' => 'storage_location',
    'columns'     => ['code', 'name', 'temperature_range', 'humidity_range', 'notes', 'is_active'],
    'required'    => ['name'],
    'nullable'    => ['code', 'temperature_range', 'humidity_range', 'notes'],
    'bools'       => ['is_active'],
    'order_by'    => 'code ASC, name ASC',
]);
