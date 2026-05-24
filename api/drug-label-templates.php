<?php
/**
 * Drug Label Templates API (admin) — CRUD for drug_label_templates per tenant.
 * Powers /products.php Tab 7 and the template dropdown in product add/edit.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'       => 'drug_label_templates',
    'entity_type' => 'drug_label_template',
    'columns'     => ['name', 'template_text', 'language',
                      'applies_to_generic_id', 'applies_to_usage_pattern',
                      'default_for_drug_group_id', 'is_active'],
    'required'    => ['name', 'template_text'],
    'nullable'    => ['applies_to_generic_id', 'applies_to_usage_pattern', 'default_for_drug_group_id'],
    'integers'    => ['applies_to_generic_id', 'default_for_drug_group_id'],
    'bools'       => ['is_active'],
    'order_by'    => 'name ASC',
]);
