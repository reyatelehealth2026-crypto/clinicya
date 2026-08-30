<?php
/**
 * Manage product_units (multi-unit support per business_items row).
 *
 * Schema (pre-existing): id, line_account_id, product_id (FK business_items.id),
 *   unit_name, unit_code, factor (base units per 1 of this unit),
 *   cost_price, sale_price, barcode, is_base_unit, is_purchase_unit, is_sale_unit, is_active.
 *
 * Actions:
 *   GET  ?action=list&item_id=X
 *   POST action=create  item_id, unit_name, factor, sale_price?, cost_price?, barcode?
 *   POST action=update  id, [fields...]
 *   POST action=delete  id
 *   POST action=set_base id  → set as base + rescale factors + recalc business_items.stock
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $currentUser['line_account_id'] ?? 0);
$userRole = (string)($_SESSION['admin_user']['role'] ?? '');
$unrestricted = in_array($userRole, ['super_admin', 'admin'], true);
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

function pu_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function pu_ok(array $extra = []): void {
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function pu_owns(PDO $db, int $itemId, int $lineAccountId, bool $unrestricted): bool {
    if ($unrestricted) return true;
    $s = $db->prepare('SELECT line_account_id FROM business_items WHERE id = ?');
    $s->execute([$itemId]);
    $row = $s->fetchColumn();
    if ($row === false) return false;
    return ((int)$row) === $lineAccountId || $row === null;
}

try {
    switch ($action) {

        case 'list': {
            $itemId = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
            if ($itemId <= 0) pu_fail('item_id required');
            if (!pu_owns($db, $itemId, $lineAccountId, $unrestricted)) pu_fail('forbidden', 403);
            $s = $db->prepare(
                'SELECT id, product_id AS item_id, unit_name, unit_code, factor AS unit_size,
                        cost_price, sale_price, barcode, is_base_unit, is_active
                   FROM product_units
                  WHERE product_id = ?
                  ORDER BY is_base_unit DESC, factor ASC'
            );
            $s->execute([$itemId]);
            pu_ok(['units' => $s->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'create': {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!pu_owns($db, $itemId, $lineAccountId, $unrestricted)) pu_fail('forbidden', 403);
            $name = trim((string)($_POST['unit_name'] ?? ''));
            $size = (float)($_POST['unit_size'] ?? $_POST['factor'] ?? 0);
            if ($name === '' || $size <= 0) pu_fail('unit_name + unit_size (>0) required');
            // Look up the item's tenant for the row insert
            $la = (int)$db->query('SELECT line_account_id FROM business_items WHERE id = ' . $itemId)->fetchColumn();
            $s = $db->prepare(
                'INSERT INTO product_units
                    (product_id, line_account_id, unit_name, unit_code, factor,
                     cost_price, sale_price, barcode, is_base_unit, is_purchase_unit, is_sale_unit, is_active)
                 VALUES (?,?,?,?,?,?,?,?,0,1,1,1)'
            );
            $s->execute([
                $itemId, $la, $name,
                trim((string)($_POST['unit_code'] ?? '')) ?: null,
                $size,
                ($_POST['cost_price'] ?? '') !== '' ? (float)$_POST['cost_price'] : null,
                ($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null,
                trim((string)($_POST['barcode'] ?? '')) ?: null,
            ]);
            pu_ok(['id' => (int)$db->lastInsertId()]);
        }

        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            $s = $db->prepare('SELECT product_id, line_account_id, is_base_unit FROM product_units WHERE id = ?');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) pu_fail('not found', 404);
            if (!$unrestricted && (int)$row['line_account_id'] !== $lineAccountId) pu_fail('forbidden', 403);

            // map UI field names to DB columns
            $map = [
                'unit_name'  => 'unit_name',
                'unit_code'  => 'unit_code',
                'unit_size'  => 'factor',
                'factor'     => 'factor',
                'cost_price' => 'cost_price',
                'sale_price' => 'sale_price',
                'barcode'    => 'barcode',
                'is_active'  => 'is_active',
            ];
            $set = []; $args = [];
            foreach ($map as $in => $col) {
                if (!array_key_exists($in, $_POST)) continue;
                $v = $_POST[$in];
                if (in_array($col, ['cost_price', 'sale_price'], true)) {
                    $v = ($v === '' || $v === null) ? null : (float)$v;
                } elseif ($col === 'factor') {
                    $v = (float)$v;
                    if ($v <= 0) pu_fail('factor must be > 0');
                } elseif (in_array($col, ['is_active'], true)) {
                    $v = (int)$v;
                } else {
                    $v = trim((string)$v);
                    if ($v === '' && in_array($col, ['unit_code','barcode'], true)) $v = null;
                }
                $set[] = "`$col` = ?"; $args[] = $v;
            }
            if (!$set) pu_fail('no fields to update');
            $args[] = $id;
            $u = $db->prepare("UPDATE product_units SET " . implode(',', $set) . " WHERE id = ?");
            $u->execute($args);
            pu_ok();
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $s = $db->prepare('SELECT line_account_id, is_base_unit FROM product_units WHERE id = ?');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) pu_fail('not found', 404);
            if (!$unrestricted && (int)$row['line_account_id'] !== $lineAccountId) pu_fail('forbidden', 403);
            if ((int)$row['is_base_unit'] === 1) pu_fail('ห้ามลบหน่วยฐาน — เปลี่ยน base unit ก่อน');
            $d = $db->prepare('DELETE FROM product_units WHERE id = ?');
            $d->execute([$id]);
            pu_ok();
        }

        // Set as base: rescale all factors so this becomes 1.0, and rescale business_items.stock.
        case 'set_base': {
            $id = (int)($_POST['id'] ?? 0);
            $s = $db->prepare('SELECT product_id, line_account_id, factor FROM product_units WHERE id = ?');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) pu_fail('not found', 404);
            if (!$unrestricted && (int)$row['line_account_id'] !== $lineAccountId) pu_fail('forbidden', 403);
            $itemId = (int)$row['product_id'];
            $newSize = (float)$row['factor'];
            if ($newSize <= 0) pu_fail('invalid factor');

            $db->beginTransaction();
            $factor = 1.0 / $newSize;
            $u1 = $db->prepare('UPDATE product_units SET factor = factor * ?, is_base_unit = 0 WHERE product_id = ?');
            $u1->execute([$factor, $itemId]);
            $u2 = $db->prepare('UPDATE product_units SET is_base_unit = 1, factor = 1.0 WHERE id = ?');
            $u2->execute([$id]);
            $u3 = $db->prepare('UPDATE business_items SET stock = stock / ? WHERE id = ?');
            $u3->execute([$newSize, $itemId]);
            $db->commit();
            pu_ok(['rescaled_factor' => $factor, 'new_base_unit_id' => $id]);
        }

        default:
            pu_fail('unknown action: ' . $action);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[product-units] ' . $e->getMessage());
    pu_fail('Server error: ' . $e->getMessage(), 500);
}
