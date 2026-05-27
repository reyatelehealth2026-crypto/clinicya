<?php
/**
 * Products API — admin CRUD + bulk actions for /products.php Tab 1.
 *
 * Multi-tenant: every query is scoped by line_account_id (current session bot).
 * Auth: requires an admin session (via includes/auth_check.php).
 *
 * Actions:
 *   GET  ?action=list                       paginated product list with filters
 *   GET  ?action=get&id=N                   full product record
 *   POST ?action=save                       insert/update (validates SKU)
 *   POST ?action=delete                     soft delete (is_active=0)
 *   GET  ?action=duplicate_check[&sku=...]  find duplicate SKUs (one-or-all)
 *   POST ?action=stock_adjust               lot/expiry adjustment + log
 *   GET  ?action=stock_movements            list latest 100 movements
 *   GET  ?action=stock_count_init           snapshot of current stock for count
 *   POST ?action=stock_count_submit         apply counted deltas
 *   POST ?action=bulk_label_by_generic      assign label to products by generic
 *   POST ?action=bulk_label_by_usage        assign label to products by usage_method
 *   POST ?action=bulk_set_active            bulk show/hide
 *   POST ?action=bulk_assign_label          bulk assign label
 *   POST ?action=set_default_dispensing_fee fill dispensing_fee where = 0
 *   POST ?action=print_tags                 returns a URL to a printable page
 *   GET  ?action=stock_summary              KPIs for the summary modal
 *
 * @package Products
 * @version 1.0.0
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

$db             = Database::getInstance()->getConnection();
$lineAccountId  = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
$adminId        = $_SESSION['admin_user']['id'] ?? ($_SESSION['user_id'] ?? null);
$activityLogger = ActivityLogger::getInstance($db);

// Fallback 1: derive from admin user's primary tenant
if ($lineAccountId <= 0 && !empty($adminId)) {
    try {
        $stmt = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$adminId]);
        $lineAccountId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) { /* ignore */ }
}
// Fallback 2: first active line_account (single-tenant deployment)
if ($lineAccountId <= 0) {
    try {
        $row = $db->query('SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $lineAccountId = (int)($row['id'] ?? 0);
    } catch (\Throwable $e) { /* ignore */ }
}

if ($lineAccountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_line_account', 'message' => 'ยังไม่ได้เลือก LINE account — กรุณาสร้างบัญชี LINE Account ก่อน']);
    exit;
}

/**
 * Minimal CSRF check for state-changing requests. Matches token from
 * includes/products/_lookup_helpers.php (per-session).
 */
function products_csrf_ok(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['_csrf'] ?? '';
    return is_string($token) && hash_equals($_SESSION['reya_products_csrf'] ?? '', $token);
}

$action = $_REQUEST['action'] ?? '';

// The print view returns HTML, not JSON — handle BEFORE the JSON switch so
// it can write its own Content-Type and exit cleanly.
if ($action === 'print_view') {
    handlePrintView($db, $lineAccountId);
    exit;
}

try {
    switch ($action) {
        case 'list':                          handleList($db, $lineAccountId); break;
        case 'get':                           handleGet($db, $lineAccountId); break;
        case 'save':                          ensureCsrf(); handleSave($db, $lineAccountId, $activityLogger); break;
        case 'delete':                        ensureCsrf(); handleDelete($db, $lineAccountId, $activityLogger); break;
        case 'duplicate_check':               handleDuplicateCheck($db, $lineAccountId); break;
        case 'stock_adjust':                  ensureCsrf(); handleStockAdjust($db, $lineAccountId, $adminId, $activityLogger); break;
        case 'stock_movements':               handleStockMovements($db, $lineAccountId); break;
        case 'stock_count_init':              handleStockCountInit($db, $lineAccountId); break;
        case 'stock_count_submit':            ensureCsrf(); handleStockCountSubmit($db, $lineAccountId, $adminId, $activityLogger); break;
        case 'bulk_label_by_generic':         ensureCsrf(); handleBulkLabelByGeneric($db, $lineAccountId, $activityLogger); break;
        case 'bulk_label_by_usage':           ensureCsrf(); handleBulkLabelByUsage($db, $lineAccountId, $activityLogger); break;
        case 'bulk_set_active':               ensureCsrf(); handleBulkSetActive($db, $lineAccountId, $activityLogger); break;
        case 'bulk_assign_label':             ensureCsrf(); handleBulkAssignLabel($db, $lineAccountId, $activityLogger); break;
        case 'set_default_dispensing_fee':    ensureCsrf(); handleSetDefaultDispensingFee($db, $lineAccountId, $activityLogger); break;
        case 'print_tags':                    handlePrintTags($db, $lineAccountId); break;
        case 'stock_summary':                 handleStockSummary($db, $lineAccountId); break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    error_log('[api/products] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function ensureCsrf(): void
{
    if (!products_csrf_ok()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function handleList(PDO $db, int $lineAccountId): void
{
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
    $search   = trim((string)($_GET['search'] ?? ''));
    $catId    = (int)($_GET['category_id'] ?? 0);
    $dgId     = (int)($_GET['drug_group_id'] ?? 0);
    $status   = (string)($_GET['status'] ?? '');
    $rx       = (string)($_GET['rx'] ?? '');

    $where  = ['bi.line_account_id = ?'];
    $params = [$lineAccountId];

    if ($search !== '') {
        $where[] = '(bi.name LIKE ? OR bi.name_en LIKE ? OR bi.sku LIKE ? OR gn.generic_name LIKE ?)';
        $like    = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($catId > 0) { $where[] = 'bi.category_id = ?';     $params[] = $catId; }
    if ($dgId  > 0) { $where[] = 'bi.drug_group_id = ?';   $params[] = $dgId; }
    if ($status === 'active')        { $where[] = 'bi.is_active = 1 AND bi.stock > 0'; }
    elseif ($status === 'hidden')    { $where[] = 'bi.is_active = 0'; }
    elseif ($status === 'out_of_stock') { $where[] = 'bi.stock <= 0'; }
    if ($rx === '1') { $where[] = 'bi.requires_prescription = 1'; }
    elseif ($rx === '0') { $where[] = '(bi.requires_prescription = 0 OR bi.requires_prescription IS NULL)'; }

    $sql = 'FROM business_items bi
              LEFT JOIN generic_names gn ON gn.id = bi.generic_name_id
              LEFT JOIN drug_groups   dg ON dg.id = bi.drug_group_id
              LEFT JOIN product_units pu ON pu.id = bi.unit_id
            WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare('SELECT COUNT(*) ' . $sql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset    = ($page - 1) * $perPage;
    $listSql   = 'SELECT bi.id, bi.sku, bi.name, bi.name_en, bi.image_url, bi.stock, bi.min_stock,
                         bi.price, bi.sale_price, bi.cost_price, bi.dispensing_fee,
                         bi.is_active, bi.is_featured, bi.requires_prescription,
                         bi.unit, bi.unit_id, bi.category_id, bi.drug_group_id, bi.generic_name_id,
                         bi.storage_location_id, bi.label_template_id, bi.usage_method,
                         gn.generic_name, dg.name_th AS drug_group_name, pu.unit_name AS unit_name
                  ' . $sql . '
                  ORDER BY bi.name ASC
                  LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare($listSql);
    foreach ($params as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success'  => true,
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
}

function handleGet(PDO $db, int $lineAccountId): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'invalid id']); return; }
    $stmt = $db->prepare('SELECT * FROM business_items WHERE id = ? AND line_account_id = ?');
    $stmt->execute([$id, $lineAccountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'error' => 'not found']); return; }
    echo json_encode(['success' => true, 'item' => $row]);
}

function handleSave(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $id = (int)($_POST['id'] ?? 0);

    // Whitelist columns
    $cols = [
        'sku', 'name', 'name_en', 'category_id', 'unit', 'unit_id',
        'price', 'sale_price', 'cost_price', 'dispensing_fee',
        'stock', 'min_stock', 'reorder_point', 'storage_location_id',
        'generic_name_id', 'drug_group_id', 'dosage_form', 'active_ingredient',
        'drug_category', 'requires_prescription', 'prescription_warning',
        'storage_condition', 'usage_method',
        'label_template_id', 'label_language', 'default_usage_text', 'default_warning_text',
        'image_url', 'is_active', 'is_featured',
    ];
    $data = [];
    foreach ($cols as $c) {
        if (array_key_exists($c, $_POST)) {
            $v = $_POST[$c];
            // Empty string → NULL for nullable id-style columns
            if ($v === '' && in_array($c, ['category_id','unit_id','storage_location_id','generic_name_id','drug_group_id','label_template_id','sale_price','cost_price','dispensing_fee','reorder_point','min_stock'], true)) {
                $v = null;
            }
            $data[$c] = $v;
        }
    }

    $name = trim((string)($data['name'] ?? ''));
    $sku  = trim((string)($data['sku']  ?? ''));
    if ($name === '') throw new Exception('กรุณาระบุชื่อสินค้า');
    if ($sku  === '') throw new Exception('กรุณาระบุ SKU');

    // Coerce known integer/boolean fields
    foreach (['requires_prescription','is_active','is_featured'] as $k) {
        if (array_key_exists($k, $data)) $data[$k] = (int)!!$data[$k];
    }

    // SKU duplicate guard
    if ($id > 0) {
        $stmt = $db->prepare('SELECT id FROM business_items WHERE line_account_id = ? AND sku = ? AND id <> ?');
        $stmt->execute([$lineAccountId, $sku, $id]);
    } else {
        $stmt = $db->prepare('SELECT id FROM business_items WHERE line_account_id = ? AND sku = ?');
        $stmt->execute([$lineAccountId, $sku]);
    }
    if ($stmt->fetchColumn()) {
        throw new Exception('SKU "' . $sku . '" ถูกใช้แล้วในร้านนี้');
    }

    if ($id > 0) {
        $sets = []; $params = [];
        foreach ($data as $k => $v) { $sets[] = "`{$k}` = ?"; $params[] = $v; }
        $params[] = $id; $params[] = $lineAccountId;
        $stmt = $db->prepare('UPDATE business_items SET ' . implode(',', $sets) . ' WHERE id = ? AND line_account_id = ?');
        $stmt->execute($params);
        $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Updated product #{$id}", ['entity_type' => 'business_item', 'entity_id' => $id, 'new_value' => $data]);
    } else {
        $data['line_account_id'] = $lineAccountId;
        $cols2 = array_keys($data);
        $place = implode(',', array_fill(0, count($cols2), '?'));
        $stmt = $db->prepare('INSERT INTO business_items (`' . implode('`,`', $cols2) . '`) VALUES (' . $place . ')');
        $stmt->execute(array_values($data));
        $id = (int)$db->lastInsertId();
        $log->logAdmin(ActivityLogger::ACTION_CREATE, "Created product {$name}", ['entity_type' => 'business_item', 'entity_id' => $id, 'new_value' => $data]);
    }

    echo json_encode(['success' => true, 'id' => $id]);
}

function handleDelete(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception('invalid id');
    $stmt = $db->prepare('UPDATE business_items SET is_active = 0 WHERE id = ? AND line_account_id = ?');
    $stmt->execute([$id, $lineAccountId]);
    $log->logAdmin(ActivityLogger::ACTION_DELETE, "Soft-deleted product #{$id}", ['entity_type' => 'business_item', 'entity_id' => $id]);
    echo json_encode(['success' => true]);
}

function handleDuplicateCheck(PDO $db, int $lineAccountId): void
{
    $sku       = trim((string)($_GET['sku'] ?? ''));
    $excludeId = (int)($_GET['exclude_id'] ?? 0);

    if ($sku !== '') {
        // Single-SKU check (used by Add/Edit modal)
        $stmt = $db->prepare('SELECT id, name, sku FROM business_items WHERE line_account_id = ? AND sku = ? AND id <> ? LIMIT 1');
        $stmt->execute([$lineAccountId, $sku, $excludeId]);
        $hit = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'duplicate' => $hit ?: null]);
        return;
    }

    // Whole-shop scan: group by SKU having count > 1
    $stmt = $db->prepare(
        'SELECT sku FROM business_items
          WHERE line_account_id = ? AND sku IS NOT NULL AND sku <> ""
          GROUP BY sku HAVING COUNT(*) > 1
          LIMIT 500'
    );
    $stmt->execute([$lineAccountId]);
    $skus = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $out = [];
    if ($skus) {
        $place = implode(',', array_fill(0, count($skus), '?'));
        $params = array_merge([$lineAccountId], $skus);
        $stmt = $db->prepare("SELECT id, sku, name FROM business_items WHERE line_account_id = ? AND sku IN ({$place}) ORDER BY sku, id");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $bySku = [];
        foreach ($rows as $r) { $bySku[$r['sku']][] = $r; }
        foreach ($bySku as $s => $items) {
            $out[] = ['sku' => $s, 'items' => $items];
        }
    }
    echo json_encode(['success' => true, 'duplicates' => $out]);
}

function handleStockAdjust(PDO $db, int $lineAccountId, ?int $adminId, ActivityLogger $log): void
{
    $sku   = trim((string)($_POST['sku'] ?? ''));
    $delta = (int)($_POST['quantity_delta'] ?? 0);
    $lot   = trim((string)($_POST['lot_no'] ?? ''));
    $exp   = trim((string)($_POST['expiry_date'] ?? ''));
    $type  = trim((string)($_POST['movement_type'] ?? 'adjustment'));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($sku === '' || $delta === 0) throw new Exception('กรุณาระบุ SKU และจำนวน Δ');

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, stock FROM business_items WHERE line_account_id = ? AND sku = ? LIMIT 1');
        $stmt->execute([$lineAccountId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('ไม่พบสินค้ารหัส ' . $sku);
        $before = (int)$row['stock'];
        $after  = $before + $delta;

        $upd = $db->prepare('UPDATE business_items SET stock = ? WHERE id = ? AND line_account_id = ?');
        $upd->execute([$after, $row['id'], $lineAccountId]);

        $ins = $db->prepare(
            'INSERT INTO stock_movements
                (line_account_id, product_id, movement_type, quantity, stock_before, stock_after,
                 reference_type, notes, created_by, lot_no, expiry_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$lineAccountId, $row['id'], $type, $delta, $before, $after, 'adjustment', $note, $adminId, $lot ?: null, $exp ?: null]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $log->logPharmacy(ActivityLogger::ACTION_UPDATE,
        "Stock adjust SKU={$sku} Δ={$delta} lot={$lot}",
        ['entity_type' => 'business_item', 'entity_id' => (int)$row['id']]);
    echo json_encode(['success' => true, 'stock_after' => $after]);
}

function handleStockMovements(PDO $db, int $lineAccountId): void
{
    $stmt = $db->prepare(
        'SELECT sm.id, sm.product_id, bi.name AS product_name, sm.movement_type,
                sm.quantity, sm.stock_before, sm.stock_after, sm.lot_no, sm.expiry_date,
                sm.reference_type, sm.reference_id, sm.notes, sm.created_at
           FROM stock_movements sm
           LEFT JOIN business_items bi ON bi.id = sm.product_id
          WHERE sm.line_account_id = ?
          ORDER BY sm.created_at DESC
          LIMIT 100'
    );
    $stmt->execute([$lineAccountId]);
    echo json_encode(['success' => true, 'movements' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function handleStockCountInit(PDO $db, int $lineAccountId): void
{
    $stmt = $db->prepare(
        'SELECT id, sku, name, stock FROM business_items
          WHERE line_account_id = ?
          ORDER BY name ASC
          LIMIT 5000'
    );
    $stmt->execute([$lineAccountId]);
    echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function handleStockCountSubmit(PDO $db, int $lineAccountId, ?int $adminId, ActivityLogger $log): void
{
    $sessionName = trim((string)($_POST['session_name'] ?? 'count ' . date('Y-m-d H:i')));
    $itemsJson   = (string)($_POST['items'] ?? '[]');
    $items       = json_decode($itemsJson, true);
    if (!is_array($items) || !$items) throw new Exception('ไม่มีรายการที่จะบันทึก');

    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO stock_count_sessions (line_account_id, name, status, started_by, submitted_by, submitted_at)
             VALUES (?, ?, "submitted", ?, ?, NOW())'
        );
        $ins->execute([$lineAccountId, $sessionName, $adminId, $adminId]);
        $sessionId = (int)$db->lastInsertId();

        $itemIns = $db->prepare(
            'INSERT INTO stock_count_items (session_id, product_id, expected_qty, counted_qty, delta, counted_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stockUpd = $db->prepare('UPDATE business_items SET stock = ? WHERE id = ? AND line_account_id = ?');
        $movIns   = $db->prepare(
            'INSERT INTO stock_movements
                (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_by)
             VALUES (?, ?, "count_correction", ?, ?, ?, "stock_count", ?, ?, ?)'
        );
        $getStock = $db->prepare('SELECT stock FROM business_items WHERE id = ? AND line_account_id = ?');

        $adjusted = 0;
        foreach ($items as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $cnt = (int)($row['counted_qty'] ?? 0);
            if ($pid <= 0) continue;

            $getStock->execute([$pid, $lineAccountId]);
            $stockBefore = (int)$getStock->fetchColumn();
            $delta = $cnt - $stockBefore;

            $itemIns->execute([$sessionId, $pid, $stockBefore, $cnt, $delta]);
            if ($delta !== 0) {
                $stockUpd->execute([$cnt, $pid, $lineAccountId]);
                $movIns->execute([$lineAccountId, $pid, $delta, $stockBefore, $cnt, $sessionId, 'stock count: ' . $sessionName, $adminId]);
                $adjusted++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $log->logPharmacy(ActivityLogger::ACTION_UPDATE, "Stock count session #{$sessionId} adjusted {$adjusted} products", ['entity_type' => 'stock_count_session', 'entity_id' => $sessionId]);
    echo json_encode(['success' => true, 'session_id' => $sessionId, 'adjusted' => $adjusted, 'total' => count($items)]);
}

function handleBulkLabelByGeneric(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $genericId = (int)($_POST['generic_id'] ?? 0);
    $tplId     = (int)($_POST['template_id'] ?? 0);
    if (!$genericId || !$tplId) throw new Exception('กรุณาเลือก Generic และเทมเพลต');

    $stmt = $db->prepare('UPDATE business_items SET label_template_id = ? WHERE line_account_id = ? AND generic_name_id = ?');
    $stmt->execute([$tplId, $lineAccountId, $genericId]);
    $affected = $stmt->rowCount();
    $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Bulk label by generic #{$genericId} → tpl #{$tplId} ({$affected})");
    echo json_encode(['success' => true, 'affected' => $affected]);
}

function handleBulkLabelByUsage(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $usage = trim((string)($_POST['usage_method'] ?? ''));
    $tplId = (int)($_POST['template_id'] ?? 0);
    if ($usage === '' || !$tplId) throw new Exception('กรุณาระบุ usage_method และเทมเพลต');

    $stmt = $db->prepare('UPDATE business_items SET label_template_id = ? WHERE line_account_id = ? AND usage_method = ?');
    $stmt->execute([$tplId, $lineAccountId, $usage]);
    $affected = $stmt->rowCount();
    $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Bulk label by usage '{$usage}' → tpl #{$tplId} ({$affected})");
    echo json_encode(['success' => true, 'affected' => $affected]);
}

function handleBulkSetActive(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $ids    = json_decode((string)($_POST['ids'] ?? '[]'), true) ?: [];
    $active = (int)!!($_POST['is_active'] ?? 0);
    $ids    = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) throw new Exception('ไม่มีสินค้าที่เลือก');

    $place  = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$active, $lineAccountId], $ids);
    $stmt   = $db->prepare("UPDATE business_items SET is_active = ? WHERE line_account_id = ? AND id IN ({$place})");
    $stmt->execute($params);
    $affected = $stmt->rowCount();
    $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Bulk set is_active={$active} on {$affected} products");
    echo json_encode(['success' => true, 'affected' => $affected]);
}

function handleBulkAssignLabel(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $ids   = json_decode((string)($_POST['ids'] ?? '[]'), true) ?: [];
    $tplId = (int)($_POST['label_template_id'] ?? 0);
    $ids   = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids || !$tplId) throw new Exception('ไม่มีสินค้า/เทมเพลตที่เลือก');

    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$tplId, $lineAccountId], $ids);
    $stmt = $db->prepare("UPDATE business_items SET label_template_id = ? WHERE line_account_id = ? AND id IN ({$place})");
    $stmt->execute($params);
    $affected = $stmt->rowCount();
    $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Bulk assign label tpl #{$tplId} to {$affected} products");
    echo json_encode(['success' => true, 'affected' => $affected]);
}

function handleSetDefaultDispensingFee(PDO $db, int $lineAccountId, ActivityLogger $log): void
{
    $fee = (float)($_POST['dispensing_fee'] ?? 0);
    if ($fee < 0) throw new Exception('ค่าหยิบยาต้องไม่ติดลบ');
    $stmt = $db->prepare('UPDATE business_items SET dispensing_fee = ? WHERE line_account_id = ? AND (dispensing_fee = 0 OR dispensing_fee IS NULL)');
    $stmt->execute([$fee, $lineAccountId]);
    $affected = $stmt->rowCount();
    $log->logAdmin(ActivityLogger::ACTION_UPDATE, "Set default dispensing_fee={$fee} on {$affected} products");
    echo json_encode(['success' => true, 'affected' => $affected]);
}

function handlePrintTags(PDO $db, int $lineAccountId): void
{
    // We don't generate PDF here (TCPDF/DOMPDF is out of scope); instead return a URL
    // to a printable HTML page that the browser can Save-As-PDF.
    $ids  = json_decode((string)($_POST['ids'] ?? '[]'), true) ?: [];
    $type = $_POST['type'] ?? 'price';
    $ids  = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) throw new Exception('ไม่มีสินค้าที่เลือก');

    // Store the selection in session so the printable page can read it without
    // bloating the URL.
    $_SESSION['reya_print_tags'] = ['ids' => $ids, 'type' => $type, 'at' => time()];
    echo json_encode(['success' => true, 'url' => '/api/products.php?action=print_view&type=' . urlencode($type)]);
}

function handlePrintView(PDO $db, int $lineAccountId): void
{
    header('Content-Type: text/html; charset=utf-8');
    $sel = $_SESSION['reya_print_tags'] ?? null;
    if (!$sel || !is_array($sel['ids'] ?? null)) { echo 'No items selected'; return; }
    $type = $sel['type'] ?? 'price';
    $place = implode(',', array_fill(0, count($sel['ids']), '?'));
    $params = array_merge([$lineAccountId], $sel['ids']);
    $stmt = $db->prepare("SELECT sku, name, price, sale_price FROM business_items WHERE line_account_id = ? AND id IN ({$place})");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $title = $type === 'sticker' ? 'สติ๊กเกอร์สินค้า' : 'ป้ายราคา';
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<style>body{font-family:Sarabun,Tahoma,sans-serif;margin:0;padding:8mm;}.tags{display:grid;grid-template-columns:repeat(3,1fr);gap:6mm;}.tag{border:1px dashed #999;padding:6mm;text-align:center;}.sku{font-family:monospace;font-size:10pt;color:#666}.name{font-size:12pt;margin:4px 0;}.price{font-size:20pt;font-weight:bold;color:#0f766e;}@media print{.no-print{display:none}}</style>';
    echo '</head><body><div class="no-print" style="margin-bottom:6mm"><button onclick="window.print()">พิมพ์</button></div><div class="tags">';
    foreach ($items as $it) {
        echo '<div class="tag"><div class="sku">' . htmlspecialchars($it['sku'] ?? '') . '</div>';
        echo '<div class="name">' . htmlspecialchars($it['name'] ?? '') . '</div>';
        $price = $it['sale_price'] !== null && $it['sale_price'] !== '' && (float)$it['sale_price'] > 0 ? $it['sale_price'] : $it['price'];
        echo '<div class="price">' . number_format((float)$price, 2) . ' ฿</div></div>';
    }
    echo '</div></body></html>';
}

function handleStockSummary(PDO $db, int $lineAccountId): void
{
    $stmt = $db->prepare(
        'SELECT
            COUNT(*)                                                  AS total_products,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END)            AS active,
            SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END)               AS out_of_stock,
            SUM(CASE WHEN min_stock > 0 AND stock > 0 AND stock <= min_stock THEN 1 ELSE 0 END) AS low_stock,
            SUM(stock * COALESCE(cost_price, 0))                      AS stock_value_cost,
            SUM(stock * COALESCE(price, 0))                           AS stock_value_price
           FROM business_items
          WHERE line_account_id = ?'
    );
    $stmt->execute([$lineAccountId]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'summary' => $summary]);
}
