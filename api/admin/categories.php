<?php
/**
 * Categories admin API — create + update product category.
 *
 * Table: business_categories(id, line_account_id, name, description, image_url, sort_order, is_active, created_at)
 *
 * Actions:
 *   POST action=create               name, description?, image_url?, sort_order?
 *   POST action=set_product_category product_id, category_id (use 0/empty to clear)
 *   GET  action=list                 → list categories for current tenant
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? $currentUser['line_account_id'] ?? 0);
$userRole      = (string) ($_SESSION['admin_user']['role'] ?? '');
$unrestricted  = in_array($userRole, ['super_admin', 'admin'], true);
$action        = $_POST['action'] ?? $_GET['action'] ?? 'list';

function cat_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function cat_ok(array $extra = []): void {
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {

        case 'list': {
            $sql = 'SELECT id, name, description, image_url, sort_order, is_active
                      FROM business_categories
                     WHERE is_active = 1';
            $params = [];
            if (!$unrestricted) {
                $sql .= ' AND (line_account_id = ? OR line_account_id IS NULL)';
                $params[] = $lineAccountId;
            }
            $sql .= ' ORDER BY sort_order ASC, name ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            cat_ok(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'create': {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                cat_fail('กรุณากรอกชื่อหมวดหมู่');
            }
            if (mb_strlen($name) > 100) {
                cat_fail('ชื่อยาวเกินไป (สูงสุด 100 ตัวอักษร)');
            }

            // Prevent duplicates within same tenant (case-sensitive match)
            $dup = $db->prepare(
                'SELECT id FROM business_categories
                  WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL)
                  LIMIT 1'
            );
            $dup->execute([$name, $lineAccountId]);
            if ($existingId = $dup->fetchColumn()) {
                cat_ok(['id' => (int) $existingId, 'name' => $name, 'duplicate' => true]);
            }

            $stmt = $db->prepare(
                'INSERT INTO business_categories
                    (line_account_id, name, description, image_url, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $lineAccountId,
                $name,
                ($_POST['description'] ?? '') !== '' ? (string) $_POST['description'] : null,
                ($_POST['image_url'] ?? '')   !== '' ? (string) $_POST['image_url']   : null,
                isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
            ]);
            cat_ok(['id' => (int) $db->lastInsertId(), 'name' => $name]);
        }

        case 'set_product_category': {
            $productId  = (int) ($_POST['product_id'] ?? 0);
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== ''
                ? (int) $_POST['category_id'] : null;
            if ($productId <= 0) {
                cat_fail('product_id required');
            }

            // Ownership check
            $own = $db->prepare('SELECT line_account_id FROM business_items WHERE id = ?');
            $own->execute([$productId]);
            $rowLa = $own->fetchColumn();
            if ($rowLa === false) {
                cat_fail('not found', 404);
            }
            if (!$unrestricted && (int) $rowLa !== $lineAccountId) {
                cat_fail('forbidden', 403);
            }

            // Validate category exists (if not clearing)
            if ($categoryId !== null && $categoryId > 0) {
                $check = $db->prepare(
                    'SELECT id FROM business_categories
                      WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)'
                );
                $check->execute([$categoryId, $lineAccountId]);
                if (!$check->fetchColumn()) {
                    cat_fail('หมวดหมู่ไม่พบ', 404);
                }
            } else {
                $categoryId = null;
            }

            $u = $db->prepare('UPDATE business_items SET category_id = ?, updated_at = NOW() WHERE id = ?');
            $u->execute([$categoryId, $productId]);
            cat_ok(['product_id' => $productId, 'category_id' => $categoryId]);
        }

        default:
            cat_fail('unknown action: ' . $action);
    }
} catch (Throwable $e) {
    error_log('[categories] ' . $e->getMessage());
    cat_fail('Server error: ' . $e->getMessage(), 500);
}
