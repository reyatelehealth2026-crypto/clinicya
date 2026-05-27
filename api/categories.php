<?php
/**
 * Categories API (admin) — CRUD for item_categories per tenant.
 *
 * Tenant scoped via $_SESSION['current_bot_id']. Lightweight wrapper used by
 * /products.php Tab 2 and by other admin pages that need a category dropdown.
 *
 * Actions:
 *   GET  ?action=list
 *   GET  ?action=get&id=N
 *   POST ?action=save
 *   POST ?action=delete
 *   POST ?action=reorder    (json body: order=[id,id,...])
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$log           = ActivityLogger::getInstance($db);

if (!$lineAccountId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'no tenant']); exit; }

$action = $_REQUEST['action'] ?? 'list';
try {
    switch ($action) {
        case 'list': {
            $stmt = $db->prepare(
                'SELECT c.id, c.name, c.cny_code, c.display_order, c.is_active,
                        COUNT(bi.id) AS product_count
                   FROM item_categories c
                   LEFT JOIN business_items bi
                     ON bi.category_id = c.id AND bi.line_account_id = c.line_account_id
                  WHERE c.line_account_id = ?
                  GROUP BY c.id
                  ORDER BY c.display_order ASC, c.name ASC'
            );
            $stmt->execute([$lineAccountId]);
            echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
            break;
        }
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM item_categories WHERE id = ? AND line_account_id = ?');
            $stmt->execute([$id, $lineAccountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ? ['success' => true, 'item' => $row] : ['success' => false, 'error' => 'not found']);
            break;
        }
        case 'save': {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $code  = trim((string)($_POST['cny_code'] ?? ''));
            $order = (int)($_POST['display_order'] ?? 0);
            $act   = (int)!!($_POST['is_active'] ?? 1);
            if ($name === '') throw new Exception('กรุณาระบุชื่อหมวด');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE item_categories SET name=?, cny_code=?, display_order=?, is_active=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$name, $code, $order, $act, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO item_categories (line_account_id, name, cny_code, display_order, is_active) VALUES (?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $name, $code, $order, $act]);
                $id = (int)$db->lastInsertId();
            }
            $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved category #{$id}", ['entity_type' => 'item_category', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        }
        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM item_categories WHERE id=? AND line_account_id=?');
            $stmt->execute([$id, $lineAccountId]);
            $log->logAdmin(ActivityLogger::ACTION_DELETE, "Deleted category #{$id}", ['entity_type' => 'item_category', 'entity_id' => $id]);
            echo json_encode(['success' => true]);
            break;
        }
        case 'reorder': {
            $order = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
            $stmt = $db->prepare('UPDATE item_categories SET display_order = ? WHERE id = ? AND line_account_id = ?');
            foreach ($order as $i => $catId) {
                $stmt->execute([(int)$i, (int)$catId, $lineAccountId]);
            }
            echo json_encode(['success' => true]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
