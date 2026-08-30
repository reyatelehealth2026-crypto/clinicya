<?php
/**
 * Mini-app content reorder endpoint.
 *
 * Re-numbers display_order of banners / sections / products based on a posted
 * array of ids (in desired order). Called by SortableJS drag-end on the
 * redesigned admin/miniapp-settings.php page.
 *
 * POST application/x-www-form-urlencoded:
 *   type  = banner | section | product
 *   ids[] = id1, id2, ...   (in desired order)
 *
 * Response: { success: bool, type, updated: int, error? }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
$userRole      = (string) ($_SESSION['admin_user']['role'] ?? '');
$unrestricted  = in_array($userRole, ['super_admin', 'admin'], true);

function ro_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ro_fail('POST only', 405);
    }

    $type = (string) ($_POST['type'] ?? '');
    $tableMap = [
        'banner'  => 'miniapp_banners',
        'section' => 'miniapp_home_sections',
        'product' => 'miniapp_home_products',
    ];
    if (!isset($tableMap[$type])) {
        ro_fail('invalid type — must be banner|section|product');
    }
    $table = $tableMap[$type];

    // Accept ids as form array (ids[]=1&ids[]=2) or JSON-encoded string
    $rawIds = $_POST['ids'] ?? null;
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $rawIds)));
    } elseif (is_array($rawIds)) {
        $ids = $rawIds;
    } else {
        $ids = [];
    }
    if (empty($ids)) {
        ro_fail('ids required');
    }

    $db->beginTransaction();
    $sql = "UPDATE `$table` SET display_order = :pos WHERE id = :id"
        . ($unrestricted ? '' : ' AND line_account_id = :la');
    $stmt = $db->prepare($sql);

    $updated = 0;
    $pos     = 0;
    foreach ($ids as $rawId) {
        $iid = (int) $rawId;
        if ($iid <= 0) continue;
        $pos++;
        $params = [':pos' => $pos, ':id' => $iid];
        if (!$unrestricted) $params[':la'] = $lineAccountId;
        $stmt->execute($params);
        $updated += $stmt->rowCount();
    }
    $db->commit();

    echo json_encode([
        'success' => true,
        'type'    => $type,
        'updated' => $updated,
        'total'   => $pos,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[miniapp-reorder] ' . $e->getMessage());
    ro_fail('Server error: ' . $e->getMessage(), 500);
}
