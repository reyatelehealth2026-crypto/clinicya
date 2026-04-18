<?php
/**
 * Storefront Bulk Operations API
 * --------------------------------------------------
 * Bulk management สำหรับ tab=storefront ใน /inventory/
 * Ref: docs/ODOO_PRODUCT_SYNC_PHP.md §14
 *
 * Actions (POST):
 *   - bulk_toggle                    { ids[], enabled, dry_run? }
 *   - bulk_disable_zero_price        { dry_run? }
 *   - bulk_disable_by_category       { category, dry_run? }
 *   - bulk_disable_by_drug_type      { drug_type, dry_run? }
 *   - bulk_disable_by_odoo_inactive  { dry_run? }
 *   - bulk_enable_by_ids             { ids[], dry_run? }    // guard: reject ถ้ามี sell_price = 0
 *   - set_featured_order             { id, order }
 *
 * Scope: ทุก action scope ด้วย session current_bot_id (line_account_id)
 * Auth:  admin / super_admin เท่านั้น
 * Audit: เขียนทุก op ลง activity_logs ผ่าน ActivityLogger
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Auth guard (ไม่ใช้ auth_check.php เพราะมัน redirect) ──────────────────────
if (empty($_SESSION['admin_user']) || empty($_SESSION['admin_user']['role'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'unauthenticated',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = $_SESSION['admin_user']['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'forbidden: admin only',
        'role'    => $role,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Setup ─────────────────────────────────────────────────────────────────────
$db            = Database::getInstance()->getConnection();
$logger        = ActivityLogger::getInstance($db);
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 1);
$adminId       = (int) ($_SESSION['admin_user']['id'] ?? 0);
$adminName     = (string) ($_SESSION['admin_user']['username'] ?? $_SESSION['admin_user']['name'] ?? '');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$dryRun = !empty($_POST['dry_run'] ?? $_GET['dry_run'] ?? false);

// ─── Helpers ───────────────────────────────────────────────────────────────────
function jsonResponse(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    $data['timestamp'] = $data['timestamp'] ?? date('c');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function parseIds($raw): array
{
    if (is_array($raw)) {
        return array_values(array_unique(array_map('intval', $raw)));
    }
    if (is_string($raw) && $raw !== '') {
        $parts = explode(',', $raw);
        return array_values(array_unique(array_map('intval', $parts)));
    }
    return [];
}

function logBulkOp(
    ActivityLogger $logger,
    string $action,
    int $affected,
    array $meta,
    int $adminId,
    int $lineAccountId
): void {
    $logger->logAdmin(
        'bulk_' . $action,
        'Storefront bulk: ' . $action . ' (affected=' . $affected . ')',
        [
            'admin_id'        => $adminId,
            'entity_type'     => 'odoo_products_cache',
            'new_value'       => array_merge(['affected' => $affected], $meta),
            'line_account_id' => $lineAccountId,
            'extra_data'      => $meta,
        ]
    );
}

// ─── Action dispatcher ─────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_toggle': {
            $ids     = parseIds($_POST['ids'] ?? []);
            $enabled = (int) ($_POST['enabled'] ?? 0);
            $enabled = $enabled === 1 ? 1 : 0;

            if (empty($ids)) {
                jsonResponse(['success' => false, 'error' => 'ids required'], 400);
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Guard: เปิด storefront ต้องไม่มีของราคา 0
            if ($enabled === 1) {
                $checkStmt = $db->prepare(
                    "SELECT COUNT(*) FROM odoo_products_cache
                     WHERE line_account_id = ?
                       AND id IN ($placeholders)
                       AND (online_price IS NULL OR online_price = 0)
                       AND (list_price   IS NULL OR list_price   = 0)"
                );
                $checkStmt->execute(array_merge([$lineAccountId], $ids));
                $zeroCount = (int) $checkStmt->fetchColumn();
                if ($zeroCount > 0) {
                    jsonResponse([
                        'success' => false,
                        'error'   => "มี {$zeroCount} รายการที่ราคา 0 — กรุณาตั้งราคาก่อนเปิดขาย",
                        'zero_count' => $zeroCount,
                    ], 422);
                }
            }

            if ($dryRun) {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM odoo_products_cache
                     WHERE line_account_id = ? AND id IN ($placeholders)"
                );
                $cntStmt->execute(array_merge([$lineAccountId], $ids));
                jsonResponse([
                    'success'  => true,
                    'dry_run'  => true,
                    'affected' => (int) $cntStmt->fetchColumn(),
                    'enabled'  => $enabled,
                ]);
            }

            $stmt = $db->prepare(
                "UPDATE odoo_products_cache
                 SET storefront_enabled = ?, updated_at = NOW()
                 WHERE line_account_id = ? AND id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$enabled, $lineAccountId], $ids));
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected,
                ['ids' => $ids, 'enabled' => $enabled],
                $adminId, $lineAccountId
            );
            jsonResponse([
                'success'  => true,
                'affected' => $affected,
                'enabled'  => $enabled,
            ]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_disable_zero_price': {
            $whereSql = "line_account_id = ?
                         AND storefront_enabled = 1
                         AND (
                             (online_price IS NULL OR online_price = 0)
                             AND (list_price IS NULL OR list_price = 0)
                         )";

            if ($dryRun) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereSql}");
                $cntStmt->execute([$lineAccountId]);
                jsonResponse([
                    'success'  => true,
                    'dry_run'  => true,
                    'affected' => (int) $cntStmt->fetchColumn(),
                ]);
            }

            $stmt = $db->prepare("UPDATE odoo_products_cache
                SET storefront_enabled = 0, updated_at = NOW()
                WHERE {$whereSql}");
            $stmt->execute([$lineAccountId]);
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected, [], $adminId, $lineAccountId);
            jsonResponse(['success' => true, 'affected' => $affected]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_disable_by_category': {
            $cat = trim((string) ($_POST['category'] ?? ''));
            if ($cat === '') {
                jsonResponse(['success' => false, 'error' => 'category required'], 400);
            }

            $whereSql = "line_account_id = ? AND storefront_enabled = 1 AND category = ?";

            if ($dryRun) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereSql}");
                $cntStmt->execute([$lineAccountId, $cat]);
                jsonResponse([
                    'success'  => true,
                    'dry_run'  => true,
                    'affected' => (int) $cntStmt->fetchColumn(),
                    'category' => $cat,
                ]);
            }

            $stmt = $db->prepare("UPDATE odoo_products_cache
                SET storefront_enabled = 0, updated_at = NOW()
                WHERE {$whereSql}");
            $stmt->execute([$lineAccountId, $cat]);
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected, ['category' => $cat], $adminId, $lineAccountId);
            jsonResponse([
                'success'  => true,
                'affected' => $affected,
                'category' => $cat,
            ]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_disable_by_drug_type': {
            $type = trim((string) ($_POST['drug_type'] ?? ''));
            if ($type === '') {
                jsonResponse(['success' => false, 'error' => 'drug_type required'], 400);
            }

            $whereSql = "line_account_id = ? AND storefront_enabled = 1 AND drug_type = ?";

            if ($dryRun) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereSql}");
                $cntStmt->execute([$lineAccountId, $type]);
                jsonResponse([
                    'success'   => true,
                    'dry_run'   => true,
                    'affected'  => (int) $cntStmt->fetchColumn(),
                    'drug_type' => $type,
                ]);
            }

            $stmt = $db->prepare("UPDATE odoo_products_cache
                SET storefront_enabled = 0, updated_at = NOW()
                WHERE {$whereSql}");
            $stmt->execute([$lineAccountId, $type]);
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected, ['drug_type' => $type], $adminId, $lineAccountId);
            jsonResponse([
                'success'   => true,
                'affected'  => $affected,
                'drug_type' => $type,
            ]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_disable_by_odoo_inactive': {
            $whereSql = "line_account_id = ? AND storefront_enabled = 1 AND is_active = 0";

            if ($dryRun) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereSql}");
                $cntStmt->execute([$lineAccountId]);
                jsonResponse([
                    'success'  => true,
                    'dry_run'  => true,
                    'affected' => (int) $cntStmt->fetchColumn(),
                ]);
            }

            $stmt = $db->prepare("UPDATE odoo_products_cache
                SET storefront_enabled = 0, updated_at = NOW()
                WHERE {$whereSql}");
            $stmt->execute([$lineAccountId]);
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected, [], $adminId, $lineAccountId);
            jsonResponse(['success' => true, 'affected' => $affected]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'bulk_enable_by_ids': {
            $ids = parseIds($_POST['ids'] ?? []);
            if (empty($ids)) {
                jsonResponse(['success' => false, 'error' => 'ids required'], 400);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Guard: ห้ามเปิดถ้ามีของราคา 0
            $checkStmt = $db->prepare(
                "SELECT COUNT(*) FROM odoo_products_cache
                 WHERE line_account_id = ?
                   AND id IN ($placeholders)
                   AND (online_price IS NULL OR online_price = 0)
                   AND (list_price   IS NULL OR list_price   = 0)"
            );
            $checkStmt->execute(array_merge([$lineAccountId], $ids));
            $zeroCount = (int) $checkStmt->fetchColumn();
            if ($zeroCount > 0) {
                jsonResponse([
                    'success'    => false,
                    'error'      => "มี {$zeroCount} รายการที่ราคา 0 — กรุณาตั้งราคาก่อนเปิดขาย",
                    'zero_count' => $zeroCount,
                ], 422);
            }

            if ($dryRun) {
                $cntStmt = $db->prepare(
                    "SELECT COUNT(*) FROM odoo_products_cache
                     WHERE line_account_id = ? AND id IN ($placeholders) AND storefront_enabled = 0"
                );
                $cntStmt->execute(array_merge([$lineAccountId], $ids));
                jsonResponse([
                    'success'  => true,
                    'dry_run'  => true,
                    'affected' => (int) $cntStmt->fetchColumn(),
                ]);
            }

            $stmt = $db->prepare(
                "UPDATE odoo_products_cache
                 SET storefront_enabled = 1, updated_at = NOW()
                 WHERE line_account_id = ? AND id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$lineAccountId], $ids));
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected, ['ids' => $ids], $adminId, $lineAccountId);
            jsonResponse(['success' => true, 'affected' => $affected]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        case 'set_featured_order': {
            $id    = (int) ($_POST['id'] ?? 0);
            $order = isset($_POST['order']) && $_POST['order'] !== '' ? (int) $_POST['order'] : null;
            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'id required'], 400);
            }

            $stmt = $db->prepare(
                "UPDATE odoo_products_cache
                 SET featured_order = ?, updated_at = NOW()
                 WHERE line_account_id = ? AND id = ?"
            );
            $stmt->execute([$order, $lineAccountId, $id]);
            $affected = $stmt->rowCount();
            logBulkOp($logger, $action, $affected,
                ['id' => $id, 'featured_order' => $order],
                $adminId, $lineAccountId
            );
            jsonResponse([
                'success'        => true,
                'affected'       => $affected,
                'id'             => $id,
                'featured_order' => $order,
            ]);
            break;
        }

        // ─────────────────────────────────────────────────────────────────────
        default:
            jsonResponse([
                'success' => false,
                'error'   => 'unknown action: ' . $action,
                'valid_actions' => [
                    'bulk_toggle',
                    'bulk_disable_zero_price',
                    'bulk_disable_by_category',
                    'bulk_disable_by_drug_type',
                    'bulk_disable_by_odoo_inactive',
                    'bulk_enable_by_ids',
                    'set_featured_order',
                ],
            ], 400);
    }
} catch (\Throwable $e) {
    error_log('[storefront-bulk] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonResponse([
        'success' => false,
        'error'   => 'server error: ' . $e->getMessage(),
    ], 500);
}
