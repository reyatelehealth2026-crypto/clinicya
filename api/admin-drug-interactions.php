<?php
/**
 * Admin Drug Interactions API — CRUD for drug_interactions per tenant.
 *
 * NOTE: The existing /api/drug-interactions.php is the LIFF consumer endpoint
 * (read-only check for cart/medication interactions). To avoid breaking that
 * consumer surface, the admin CRUD for /products.php Tab 8 lives at this
 * separate URL. This is a documented deviation from the spec (which named
 * /api/drug-interactions.php) — see status report.
 *
 * The drug_interactions table uses the legacy column names drug1_name /
 * drug2_name. The admin tab UI uses the same names; the spec's drug_a/drug_b
 * are semantic aliases.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/_products_lookup_crud.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
if (!$lineAccountId) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

// Custom handler for "duplicate_global" (copy a NULL-tenant row to current tenant)
$customHandler = function (PDO $db, int $lineAccountId, string $action, ActivityLogger $log): bool {
    if ($action !== 'duplicate_global') return false;
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM drug_interactions WHERE id=? AND line_account_id IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Global row not found');
        $ins = $db->prepare(
            'INSERT INTO drug_interactions
                (line_account_id, drug1_name, drug1_generic, drug2_name, drug2_generic,
                 severity, description, mechanism, recommendation)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $lineAccountId, $row['drug1_name'], $row['drug1_generic'],
            $row['drug2_name'], $row['drug2_generic'], $row['severity'],
            $row['description'], $row['mechanism'] ?? null, $row['recommendation']
        ]);
        $newId = (int)$db->lastInsertId();
        $log->logPharmacy(ActivityLogger::ACTION_CREATE, "Duplicated global interaction #{$id} → #{$newId}", ['entity_type' => 'drug_interaction', 'entity_id' => $newId]);
        echo json_encode(['success' => true, 'id' => $newId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    return true;
};

reya_lookup_crud($db, (int)$lineAccountId, [
    'table'           => 'drug_interactions',
    'entity_type'     => 'drug_interaction',
    'columns'         => ['drug1_name', 'drug1_generic', 'drug2_name', 'drug2_generic',
                          'severity', 'description', 'mechanism', 'recommendation'],
    'required'        => ['drug1_name', 'drug2_name', 'severity'],
    'nullable'        => ['drug1_generic', 'drug2_generic', 'description', 'mechanism', 'recommendation'],
    'order_by'        => 'FIELD(severity, "contraindicated","severe","moderate","mild"), drug1_name ASC',
    'tenant_nullable' => true,   // include global (NULL) rows in list
    'custom_handler'  => $customHandler,
]);
