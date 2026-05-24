<?php
/**
 * Generic Names API (admin) — CRUD for generic_names per tenant.
 * Powers /products.php Tab 4 and any generic-name typeahead.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'generic_names',
    'entity_type' => 'generic_name',
    'columns'     => ['generic_name', 'atc_code', 'default_dosage_form', 'default_unit', 'default_warnings', 'description'],
    'required'    => ['generic_name'],
    'nullable'    => ['atc_code', 'default_dosage_form', 'default_unit', 'default_warnings', 'description'],
    'order_by'    => 'generic_name ASC',
]);
