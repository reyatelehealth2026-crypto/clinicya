<?php
/**
 * Internal helper for the small lookup-table APIs used by /products.php
 * (drug-groups, generic-names, product-units, storage-locations,
 *  drug-label-templates, admin-drug-interactions).
 *
 * Each API includes this file and calls reya_lookup_crud() with its table
 * config. We keep this in /api/ so the wrappers are tiny.
 *
 * NOT intended for external/public use.
 */

function reya_lookup_crud(PDO $db, int $lineAccountId, array $cfg): void
{
    header('Content-Type: application/json; charset=utf-8');
    $table        = $cfg['table'];
    $columns      = $cfg['columns'];        // whitelist of writable columns
    $requiredCols = $cfg['required'] ?? []; // columns that must be non-empty
    $orderBy      = $cfg['order_by'] ?? 'id';
    $entityType   = $cfg['entity_type'] ?? $table;
    $nullable     = array_fill_keys($cfg['nullable'] ?? [], true);
    $integers     = array_fill_keys($cfg['integers'] ?? [], true);
    $bools        = array_fill_keys($cfg['bools'] ?? [], true);
    $selectExtra  = $cfg['select_extra'] ?? '';
    $tenantCol    = $cfg['tenant_col'] ?? 'line_account_id';
    $tenantNullable = !empty($cfg['tenant_nullable']); // for drug_interactions: includes NULL rows in list

    require_once __DIR__ . '/../classes/ActivityLogger.php';
    require_once __DIR__ . '/../includes/inventory/_lookup_helpers.php'; // reya_csrf_check()
    $log = ActivityLogger::getInstance($db);

    $action = $_REQUEST['action'] ?? 'list';
    try {
        switch ($action) {
            case 'list': {
                $tenantClause = $tenantNullable
                    ? "({$tenantCol} = ? OR {$tenantCol} IS NULL)"
                    : "{$tenantCol} = ?";
                $sql = "SELECT *{$selectExtra} FROM `{$table}` WHERE {$tenantClause} ORDER BY {$orderBy}";
                $stmt = $db->prepare($sql);
                $stmt->execute([$lineAccountId]);
                echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
                return;
            }
            case 'get': {
                $id = (int)($_GET['id'] ?? 0);
                $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ? AND {$tenantCol} " . ($tenantNullable ? '<=> ' : '= ') . '?');
                // Use a simpler scoped get
                $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ? AND ({$tenantCol} = ?" . ($tenantNullable ? " OR {$tenantCol} IS NULL" : '') . ')');
                $stmt->execute([$id, $lineAccountId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($row ? ['success' => true, 'item' => $row] : ['success' => false, 'error' => 'not found']);
                return;
            }
            case 'save': {
                if (!reya_csrf_check()) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                    return;
                }
                $id   = (int)($_POST['id'] ?? 0);
                $data = [];
                foreach ($columns as $c) {
                    if (!array_key_exists($c, $_POST)) continue;
                    $v = $_POST[$c];
                    if (isset($bools[$c]))    $v = (int)!!$v;
                    elseif (isset($integers[$c])) $v = ($v === '' ? null : (int)$v);
                    if ($v === '' && isset($nullable[$c])) $v = null;
                    $data[$c] = $v;
                }
                foreach ($requiredCols as $r) {
                    if (!isset($data[$r]) || $data[$r] === '' || $data[$r] === null) {
                        throw new Exception("ฟิลด์ {$r} จำเป็น");
                    }
                }
                if ($id > 0) {
                    // Guard against silent no-op: the row must exist AND belong to
                    // this tenant. Global rows (tenant_col IS NULL, e.g. shared
                    // drug_interactions) are read-only here — editing them would
                    // mutate data shared across all tenants.
                    $own = $db->prepare("SELECT `{$tenantCol}` FROM `{$table}` WHERE id = ?");
                    $own->execute([$id]);
                    $owner = $own->fetchColumn();
                    if ($owner === false)      throw new Exception('ไม่พบรายการที่ต้องการแก้ไข');
                    if ($owner === null)       throw new Exception('รายการนี้เป็นข้อมูลกลาง (ใช้ร่วมกันทุกร้าน) — แก้ไขไม่ได้');
                    if ((int)$owner !== $lineAccountId) throw new Exception('ไม่พบรายการในร้านนี้');

                    $sets = []; $params = [];
                    foreach ($data as $k => $v) { $sets[] = "`{$k}` = ?"; $params[] = $v; }
                    $params[] = $id; $params[] = $lineAccountId;
                    $stmt = $db->prepare("UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE id = ? AND {$tenantCol} = ?");
                    $stmt->execute($params);
                } else {
                    $data[$tenantCol] = $lineAccountId;
                    $cols = array_keys($data);
                    $place = implode(',', array_fill(0, count($cols), '?'));
                    $stmt = $db->prepare("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$place})");
                    $stmt->execute(array_values($data));
                    $id = (int)$db->lastInsertId();
                }
                $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved {$entityType} #{$id}", ['entity_type' => $entityType, 'entity_id' => $id]);
                echo json_encode(['success' => true, 'id' => $id]);
                return;
            }
            case 'delete': {
                if (!reya_csrf_check()) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                    return;
                }
                $id = (int)($_POST['id'] ?? 0);
                // Same ownership guard as save — never report success on a global
                // or non-existent row (the old silent no-op).
                $own = $db->prepare("SELECT `{$tenantCol}` FROM `{$table}` WHERE id = ?");
                $own->execute([$id]);
                $owner = $own->fetchColumn();
                if ($owner === false)      throw new Exception('ไม่พบรายการที่ต้องการลบ');
                if ($owner === null)       throw new Exception('รายการนี้เป็นข้อมูลกลาง (ใช้ร่วมกันทุกร้าน) — ลบไม่ได้');
                if ((int)$owner !== $lineAccountId) throw new Exception('ไม่พบรายการในร้านนี้');

                $stmt = $db->prepare("DELETE FROM `{$table}` WHERE id = ? AND {$tenantCol} = ?");
                $stmt->execute([$id, $lineAccountId]);
                $log->logAdmin(ActivityLogger::ACTION_DELETE, "Deleted {$entityType} #{$id}", ['entity_type' => $entityType, 'entity_id' => $id]);
                echo json_encode(['success' => true]);
                return;
            }
            default:
                if (isset($cfg['custom_handler']) && is_callable($cfg['custom_handler'])) {
                    if (call_user_func($cfg['custom_handler'], $db, $lineAccountId, $action, $log)) return;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'unknown action: ' . $action]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
