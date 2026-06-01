<?php
/**
 * Documents API — Thai accounting document suite.
 * API จัดการเอกสารบัญชี (ใบเสนอราคา / ใบกำกับภาษี ฯลฯ)
 *
 * Multi-action endpoint:
 *   GET  ?action=list&doc_type=&status=&q=&page=&per_page=
 *   GET  ?action=get&id=N
 *   POST ?action=create
 *   POST ?action=update            (only when status=pending_approval)
 *   POST ?action=approve
 *   POST ?action=cancel            (requires cancel_reason)
 *   POST ?action=convert           (QT->INV->TAX etc; copies line items)
 *   GET  ?action=pdf&id=N          (printable HTML; PDF stub unless TCPDF present)
 *   GET  ?action=export_csv&doc_type=&from=&to=
 *
 * Every query is multi-tenant scoped via line_account_id.
 * Doc numbers are atomic — see genDocNumber() inside an explicit transaction.
 *
 * @package Documents
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/document-helpers.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// PDF/CSV actions stream their own headers; JSON for the rest.
$nonJsonActions = ['pdf', 'export_csv'];
if (!in_array($action, $nonJsonActions, true)) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'database_unavailable']);
    exit;
}

$logger = ActivityLogger::getInstance($db);

// Resolve current tenant. Mirror header.php precedence.
// SECURITY: the tenant is taken ONLY from the authenticated session (and trusted
// server-side fallbacks). We deliberately do NOT accept ?line_account_id from the
// request — that was an IDOR letting any admin read/write another tenant's VAT
// documents. Super-admins select a tenant via admin/switch-tenant.php (session).
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
// Fallback: derive from admin user's primary tenant (admin_users.line_account_id)
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $lineAccountId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) { /* ignore */ }
}
// Final fallback: first active line_account (single-tenant deployments)
if ($lineAccountId <= 0) {
    try {
        $row = $db->query('SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $lineAccountId = (int)($row['id'] ?? 0);
    } catch (\Throwable $e) { /* ignore */ }
}
if ($lineAccountId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'no_line_account', 'message' => 'ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน หรือสร้างบัญชี LINE Account อย่างน้อย 1 รายการ']);
    exit;
}

// Admin id for audit columns.
$adminId = (int)($_SESSION['admin_id'] ?? 0) ?: null;

// ============================================================================
// Helper: full doc fetch
// ============================================================================
function documents_fetch(PDO $db, int $lineAccountId, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM business_documents WHERE id = ? AND line_account_id = ?');
    $stmt->execute([$id, $lineAccountId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT * FROM business_document_items WHERE document_id = ? ORDER BY line_no ASC, id ASC'
    );
    $stmt->execute([$id]);
    $doc['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $doc;
}

function documents_resolve_input(): array
{
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== ''
        && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function documents_norm_items(array $rawItems, float $vatRate, bool $vatInclusive): array
{
    $items = [];
    $lineNo = 1;
    $subtotal = 0.0;
    $totalDiscount = 0.0;
    foreach ($rawItems as $it) {
        $name = trim((string)($it['product_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = (float)($it['quantity'] ?? 1);
        if ($qty <= 0) {
            continue;
        }
        $unitPrice = (float)($it['unit_price'] ?? 0);
        $discPct   = (float)($it['discount_percent'] ?? 0);
        $discAmt   = (float)($it['discount_amount'] ?? 0);
        $lineTotal = computeLineTotal($qty, $unitPrice, $discPct, $discAmt);

        $items[] = [
            'line_no'          => $lineNo++,
            'product_id'       => isset($it['product_id']) && (int)$it['product_id'] > 0 ? (int)$it['product_id'] : null,
            'product_sku'      => isset($it['product_sku']) ? substr((string)$it['product_sku'], 0, 100) : null,
            'product_name'     => substr($name, 0, 255),
            'description'      => isset($it['description']) ? (string)$it['description'] : null,
            'quantity'         => round($qty, 2),
            'unit'             => isset($it['unit']) ? substr((string)$it['unit'], 0, 50) : null,
            'unit_price'       => round($unitPrice, 2),
            'discount_percent' => round($discPct, 2),
            'discount_amount'  => round($discAmt, 2),
            'line_total'       => $lineTotal,
        ];
        $subtotal += $qty * $unitPrice;
        $totalDiscount += ($qty * $unitPrice - $lineTotal);
    }
    $totals = calcVAT($subtotal - $totalDiscount, $vatRate, $vatInclusive);
    return [
        'items'           => $items,
        'subtotal'        => round($subtotal, 2),
        'discount_amount' => round($totalDiscount, 2),
        'vat_amount'      => $totals['vat'],
        'total_amount'    => $totals['total'],
    ];
}

function documents_insert(PDO $db, array $doc, array $items): int
{
    $cols = [
        'line_account_id','doc_type','doc_number','ref_transaction_id','ref_doc_id',
        'customer_user_id','customer_name','customer_tax_id','customer_branch_code',
        'customer_address','customer_phone','customer_email',
        'issue_date','due_date','valid_until',
        'subtotal','discount_amount','vat_rate','vat_amount','total_amount',
        'payment_method','payment_ref',
        'status','note','internal_note','created_by'
    ];
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO business_documents (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
    $stmt = $db->prepare($sql);
    $vals = [];
    foreach ($cols as $c) {
        $vals[] = $doc[$c] ?? null;
    }
    $stmt->execute($vals);
    $docId = (int)$db->lastInsertId();

    if (!empty($items)) {
        $sql = 'INSERT INTO business_document_items
            (document_id, line_no, product_id, product_sku, product_name, description,
             quantity, unit, unit_price, discount_percent, discount_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $db->prepare($sql);
        foreach ($items as $it) {
            $stmt->execute([
                $docId,
                $it['line_no'],
                $it['product_id'],
                $it['product_sku'],
                $it['product_name'],
                $it['description'],
                $it['quantity'],
                $it['unit'],
                $it['unit_price'],
                $it['discount_percent'],
                $it['discount_amount'],
                $it['line_total'],
            ]);
        }
    }
    return $docId;
}

// ============================================================================
// Actions
// ============================================================================
switch ($action) {

    // ------------------------------------------------------------------- list
    case 'list':
    {
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $docType = strtoupper(trim((string)($_GET['doc_type'] ?? '')));
        $status  = trim((string)($_GET['status'] ?? ''));
        $q       = trim((string)($_GET['q'] ?? ''));
        $from    = trim((string)($_GET['from'] ?? ''));
        $to      = trim((string)($_GET['to'] ?? ''));
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $where = ['line_account_id = ?'];
        $params = [$lineAccountId];

        if ($docType !== '' && isset(REYA_DOCUMENT_TYPES[$docType])) {
            $where[] = 'doc_type = ?';
            $params[] = $docType;
        }
        if (in_array($status, ['pending_approval','approved','cancelled'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(doc_number LIKE ? OR customer_name LIKE ? OR customer_tax_id LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($from !== '') { $where[] = 'issue_date >= ?'; $params[] = $from; }
        if ($to   !== '') { $where[] = 'issue_date <= ?'; $params[] = $to; }
        $whereSql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT COUNT(*) FROM business_documents WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT id, doc_type, doc_number, issue_date, due_date, valid_until,
                       customer_user_id, customer_name, customer_tax_id,
                       subtotal, discount_amount, vat_amount, total_amount,
                       status, created_at, approved_at, cancelled_at
                  FROM business_documents
                 WHERE {$whereSql}
                 ORDER BY issue_date DESC, id DESC
                 LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Decorate with Thai labels.
        foreach ($rows as &$r) {
            $r['doc_type_label']    = docTypeLabel($r['doc_type']);
            $r['status_label']      = docStatusLabel($r['status']);
            $r['issue_date_thai']   = formatThaiDate((string)$r['issue_date']);
        }
        unset($r);

        echo json_encode([
            'success'  => true,
            'data'     => $rows,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int)ceil($total / $perPage),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------------------------------------------------- get
    case 'get':
    {
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'bad_id']); exit; }
        $doc = documents_fetch($db, $lineAccountId, $id);
        if (!$doc) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'not_found']); exit; }
        $doc['doc_type_label']  = docTypeLabel($doc['doc_type']);
        $doc['status_label']    = docStatusLabel($doc['status']);
        $doc['issue_date_thai'] = formatThaiDate((string)$doc['issue_date']);
        echo json_encode(['success' => true, 'data' => $doc], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------- create
    case 'create':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $input = documents_resolve_input();

        $docType = strtoupper(trim((string)($input['doc_type'] ?? '')));
        if (!isset(REYA_DOCUMENT_TYPES[$docType])) {
            echo json_encode(['success'=>false,'error'=>'bad_doc_type']); exit;
        }

        $vatRate = isset($input['vat_rate']) ? (float)$input['vat_rate'] : 7.00;
        $vatInclusive = !empty($input['vat_inclusive']);

        $rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
        if (empty($rawItems)) {
            echo json_encode(['success'=>false,'error'=>'items_required']); exit;
        }
        $norm = documents_norm_items($rawItems, $vatRate, $vatInclusive);
        if (empty($norm['items'])) {
            echo json_encode(['success'=>false,'error'=>'no_valid_items']); exit;
        }

        $issueDate = trim((string)($input['issue_date'] ?? ''));
        if ($issueDate === '') {
            $issueDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        }

        // Atomic: number generation + insert in one transaction.
        $db->beginTransaction();
        try {
            $docNumber = genDocNumber($db, $lineAccountId, $docType);

            $doc = [
                'line_account_id'      => $lineAccountId,
                'doc_type'             => $docType,
                'doc_number'           => $docNumber,
                'ref_transaction_id'   => isset($input['ref_transaction_id']) && (int)$input['ref_transaction_id'] > 0 ? (int)$input['ref_transaction_id'] : null,
                'ref_doc_id'           => isset($input['ref_doc_id']) && (int)$input['ref_doc_id'] > 0 ? (int)$input['ref_doc_id'] : null,
                'customer_user_id'     => isset($input['customer_user_id']) && (int)$input['customer_user_id'] > 0 ? (int)$input['customer_user_id'] : null,
                'customer_name'        => isset($input['customer_name']) ? substr((string)$input['customer_name'], 0, 255) : null,
                'customer_tax_id'      => isset($input['customer_tax_id']) ? substr((string)$input['customer_tax_id'], 0, 20) : null,
                'customer_branch_code' => isset($input['customer_branch_code']) ? substr((string)$input['customer_branch_code'], 0, 20) : null,
                'customer_address'     => $input['customer_address'] ?? null,
                'customer_phone'       => isset($input['customer_phone']) ? substr((string)$input['customer_phone'], 0, 50) : null,
                'customer_email'       => isset($input['customer_email']) ? substr((string)$input['customer_email'], 0, 100) : null,
                'issue_date'           => $issueDate,
                'due_date'             => !empty($input['due_date'])    ? (string)$input['due_date']    : null,
                'valid_until'          => !empty($input['valid_until']) ? (string)$input['valid_until'] : null,
                'subtotal'             => $norm['subtotal'],
                'discount_amount'      => $norm['discount_amount'],
                'vat_rate'             => $vatRate,
                'vat_amount'           => $norm['vat_amount'],
                'total_amount'         => $norm['total_amount'],
                'payment_method'       => isset($input['payment_method']) ? substr((string)$input['payment_method'], 0, 50) : null,
                'payment_ref'          => isset($input['payment_ref'])    ? substr((string)$input['payment_ref'], 0, 100)   : null,
                'status'               => 'pending_approval',
                'note'                 => $input['note'] ?? null,
                'internal_note'        => $input['internal_note'] ?? null,
                'created_by'           => $adminId,
            ];

            $docId = documents_insert($db, $doc, $norm['items']);
            $db->commit();

            $logger->logData('create', "สร้างเอกสาร {$docType} {$docNumber}", [
                'entity_type'    => 'business_document',
                'entity_id'      => $docId,
                'line_account_id'=> $lineAccountId,
                'new_value'      => ['doc_number' => $docNumber, 'total' => $norm['total_amount']],
            ]);

            $full = documents_fetch($db, $lineAccountId, $docId);
            echo json_encode(['success' => true, 'data' => $full], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[documents.create] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false,'error'=>'create_failed','message'=>'สร้างเอกสารไม่สำเร็จ']);
        }
        exit;
    }

    // ---------------------------------------------------------------- update
    case 'update':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $input = documents_resolve_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'bad_id']); exit; }

        $existing = documents_fetch($db, $lineAccountId, $id);
        if (!$existing) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'not_found']); exit; }
        if ($existing['status'] !== 'pending_approval') {
            http_response_code(409);
            echo json_encode(['success'=>false,'error'=>'locked','message'=>'เอกสารถูกอนุมัติหรือยกเลิกแล้ว ไม่สามารถแก้ไขได้']);
            exit;
        }

        $vatRate = isset($input['vat_rate']) ? (float)$input['vat_rate'] : (float)$existing['vat_rate'];
        $vatInclusive = !empty($input['vat_inclusive']);
        $rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
        if (empty($rawItems)) { echo json_encode(['success'=>false,'error'=>'items_required']); exit; }
        $norm = documents_norm_items($rawItems, $vatRate, $vatInclusive);
        if (empty($norm['items'])) { echo json_encode(['success'=>false,'error'=>'no_valid_items']); exit; }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE business_documents SET
                customer_user_id = ?, customer_name = ?, customer_tax_id = ?, customer_branch_code = ?,
                customer_address = ?, customer_phone = ?, customer_email = ?,
                issue_date = ?, due_date = ?, valid_until = ?,
                subtotal = ?, discount_amount = ?, vat_rate = ?, vat_amount = ?, total_amount = ?,
                payment_method = ?, payment_ref = ?, note = ?, internal_note = ?
                WHERE id = ? AND line_account_id = ? AND status = ?');
            $stmt->execute([
                isset($input['customer_user_id']) && (int)$input['customer_user_id'] > 0 ? (int)$input['customer_user_id'] : null,
                isset($input['customer_name']) ? substr((string)$input['customer_name'], 0, 255) : null,
                isset($input['customer_tax_id']) ? substr((string)$input['customer_tax_id'], 0, 20) : null,
                isset($input['customer_branch_code']) ? substr((string)$input['customer_branch_code'], 0, 20) : null,
                $input['customer_address'] ?? null,
                isset($input['customer_phone']) ? substr((string)$input['customer_phone'], 0, 50) : null,
                isset($input['customer_email']) ? substr((string)$input['customer_email'], 0, 100) : null,
                !empty($input['issue_date']) ? (string)$input['issue_date'] : $existing['issue_date'],
                !empty($input['due_date']) ? (string)$input['due_date'] : null,
                !empty($input['valid_until']) ? (string)$input['valid_until'] : null,
                $norm['subtotal'], $norm['discount_amount'], $vatRate, $norm['vat_amount'], $norm['total_amount'],
                isset($input['payment_method']) ? substr((string)$input['payment_method'], 0, 50) : null,
                isset($input['payment_ref'])    ? substr((string)$input['payment_ref'], 0, 100)   : null,
                $input['note'] ?? null,
                $input['internal_note'] ?? null,
                $id, $lineAccountId, 'pending_approval',
            ]);

            // Replace items wholesale (simpler + safer for draft docs).
            $del = $db->prepare('DELETE FROM business_document_items WHERE document_id = ?');
            $del->execute([$id]);
            $ins = $db->prepare('INSERT INTO business_document_items
                (document_id, line_no, product_id, product_sku, product_name, description,
                 quantity, unit, unit_price, discount_percent, discount_amount, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($norm['items'] as $it) {
                $ins->execute([
                    $id, $it['line_no'], $it['product_id'], $it['product_sku'], $it['product_name'],
                    $it['description'], $it['quantity'], $it['unit'], $it['unit_price'],
                    $it['discount_percent'], $it['discount_amount'], $it['line_total'],
                ]);
            }
            $db->commit();

            $logger->logData('update', "แก้ไขเอกสาร {$existing['doc_number']}", [
                'entity_type'    => 'business_document',
                'entity_id'      => $id,
                'line_account_id'=> $lineAccountId,
            ]);

            echo json_encode(['success' => true, 'data' => documents_fetch($db, $lineAccountId, $id)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[documents.update] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false,'error'=>'update_failed','message'=>'แก้ไขเอกสารไม่สำเร็จ']);
        }
        exit;
    }

    // --------------------------------------------------------------- approve
    case 'approve':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $input = documents_resolve_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'bad_id']); exit; }

        $stmt = $db->prepare(
            "UPDATE business_documents SET status='approved', approved_by=?, approved_at=NOW()
             WHERE id = ? AND line_account_id = ? AND status='pending_approval'"
        );
        $stmt->execute([$adminId, $id, $lineAccountId]);
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success'=>false,'error'=>'not_found_or_locked']); exit;
        }
        $logger->logData('approve', "อนุมัติเอกสาร id={$id}", [
            'entity_type'    => 'business_document',
            'entity_id'      => $id,
            'line_account_id'=> $lineAccountId,
        ]);
        echo json_encode(['success' => true, 'data' => documents_fetch($db, $lineAccountId, $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------- cancel
    case 'cancel':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $input = documents_resolve_input();
        $id = (int)($input['id'] ?? 0);
        $reason = trim((string)($input['cancel_reason'] ?? ''));
        if ($id <= 0)            { echo json_encode(['success'=>false,'error'=>'bad_id']); exit; }
        if ($reason === '')      { echo json_encode(['success'=>false,'error'=>'reason_required','message'=>'กรุณาระบุเหตุผลการยกเลิก']); exit; }

        $stmt = $db->prepare(
            "UPDATE business_documents
                SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?
              WHERE id = ? AND line_account_id = ? AND status <> 'cancelled'"
        );
        $stmt->execute([$adminId, $reason, $id, $lineAccountId]);
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success'=>false,'error'=>'not_found_or_already_cancelled']); exit;
        }
        $logger->logData('cancel', "ยกเลิกเอกสาร id={$id}: {$reason}", [
            'entity_type'    => 'business_document',
            'entity_id'      => $id,
            'line_account_id'=> $lineAccountId,
            'extra_data'     => ['cancel_reason' => $reason],
        ]);
        echo json_encode(['success' => true, 'data' => documents_fetch($db, $lineAccountId, $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --------------------------------------------------------------- convert
    case 'convert':
    {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method']); exit; }
        $input = documents_resolve_input();
        $sourceId   = (int)($input['source_id'] ?? 0);
        $targetType = strtoupper(trim((string)($input['target_doc_type'] ?? '')));
        if ($sourceId <= 0 || !isset(REYA_DOCUMENT_TYPES[$targetType])) {
            echo json_encode(['success'=>false,'error'=>'bad_input']); exit;
        }
        $src = documents_fetch($db, $lineAccountId, $sourceId);
        if (!$src) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'source_not_found']); exit; }

        // Build new doc carrying customer + items from source.
        $items = [];
        $lineNo = 1;
        foreach ($src['items'] as $it) {
            $items[] = [
                'line_no'          => $lineNo++,
                'product_id'       => $it['product_id'] !== null ? (int)$it['product_id'] : null,
                'product_sku'      => $it['product_sku'],
                'product_name'     => $it['product_name'],
                'description'      => $it['description'],
                'quantity'         => (float)$it['quantity'],
                'unit'             => $it['unit'],
                'unit_price'       => (float)$it['unit_price'],
                'discount_percent' => (float)$it['discount_percent'],
                'discount_amount'  => (float)$it['discount_amount'],
                'line_total'       => (float)$it['line_total'],
            ];
        }
        $vatRate = (float)$src['vat_rate'];

        $db->beginTransaction();
        try {
            $newNumber = genDocNumber($db, $lineAccountId, $targetType);
            $issueDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');

            $doc = [
                'line_account_id'      => $lineAccountId,
                'doc_type'             => $targetType,
                'doc_number'           => $newNumber,
                'ref_transaction_id'   => $src['ref_transaction_id'] !== null ? (int)$src['ref_transaction_id'] : null,
                'ref_doc_id'           => $sourceId,
                'customer_user_id'     => $src['customer_user_id'] !== null ? (int)$src['customer_user_id'] : null,
                'customer_name'        => $src['customer_name'],
                'customer_tax_id'      => $src['customer_tax_id'],
                'customer_branch_code' => $src['customer_branch_code'],
                'customer_address'     => $src['customer_address'],
                'customer_phone'       => $src['customer_phone'],
                'customer_email'       => $src['customer_email'],
                'issue_date'           => $issueDate,
                'due_date'             => null,
                'valid_until'          => null,
                'subtotal'             => (float)$src['subtotal'],
                'discount_amount'      => (float)$src['discount_amount'],
                'vat_rate'             => $vatRate,
                'vat_amount'           => (float)$src['vat_amount'],
                'total_amount'         => (float)$src['total_amount'],
                'payment_method'       => null,
                'payment_ref'          => null,
                'status'               => 'pending_approval',
                'note'                 => $src['note'],
                'internal_note'        => 'แปลงจาก ' . docTypeLabel($src['doc_type']) . ' ' . $src['doc_number'],
                'created_by'           => $adminId,
            ];
            $newId = documents_insert($db, $doc, $items);
            $db->commit();

            $logger->logData('convert', "แปลง {$src['doc_number']} → {$newNumber}", [
                'entity_type'    => 'business_document',
                'entity_id'      => $newId,
                'line_account_id'=> $lineAccountId,
                'extra_data'     => ['source_id' => $sourceId, 'target_type' => $targetType],
            ]);

            echo json_encode([
                'success' => true,
                'data' => documents_fetch($db, $lineAccountId, $newId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[documents.convert] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false,'error'=>'convert_failed']);
        }
        exit;
    }

    // ------------------------------------------------------------------- pdf
    case 'pdf':
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo 'bad id'; exit; }
        $doc = documents_fetch($db, $lineAccountId, $id);
        if (!$doc) { http_response_code(404); echo 'not found'; exit; }

        // Load shop tax info for header.
        $stmt = $db->prepare('SELECT * FROM shop_tax_info WHERE line_account_id = ?');
        $stmt->execute([$lineAccountId]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        require_once __DIR__ . '/../includes/documents/pdf-renderer.php';

        $logger->logData('export', "พิมพ์เอกสาร {$doc['doc_number']}", [
            'entity_type'    => 'business_document',
            'entity_id'      => $id,
            'line_account_id'=> $lineAccountId,
        ]);

        // Stream HTML printable view. (Real PDF needs TCPDF/DOMPDF — not installed.)
        header('Content-Type: text/html; charset=utf-8');
        echo renderDocumentPrintable($doc, $shop);
        exit;
    }

    // ------------------------------------------------------------ export_csv
    case 'export_csv':
    {
        $docType = strtoupper(trim((string)($_GET['doc_type'] ?? '')));
        $from    = trim((string)($_GET['from'] ?? ''));
        $to      = trim((string)($_GET['to'] ?? ''));

        $where = ['line_account_id = ?'];
        $params = [$lineAccountId];
        if ($docType !== '' && isset(REYA_DOCUMENT_TYPES[$docType])) {
            $where[] = 'doc_type = ?'; $params[] = $docType;
        }
        if ($from !== '') { $where[] = 'issue_date >= ?'; $params[] = $from; }
        if ($to   !== '') { $where[] = 'issue_date <= ?'; $params[] = $to; }
        $whereSql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT doc_type, doc_number, issue_date, customer_name, customer_tax_id,
                                     subtotal, discount_amount, vat_amount, total_amount, status
                                FROM business_documents WHERE {$whereSql}
                                ORDER BY issue_date ASC, id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $filename = 'documents_' . ($docType ?: 'ALL') . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads Thai correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ประเภท', 'เลขที่เอกสาร', 'วันที่', 'ชื่อลูกค้า', 'เลขผู้เสียภาษี',
            'ยอดก่อนภาษี', 'ส่วนลด', 'VAT', 'ยอดรวม', 'สถานะ',
        ]);
        foreach ($rows as $r) {
            fputcsv($out, [
                docTypeLabel($r['doc_type']),
                $r['doc_number'],
                $r['issue_date'],
                $r['customer_name'],
                $r['customer_tax_id'],
                $r['subtotal'],
                $r['discount_amount'],
                $r['vat_amount'],
                $r['total_amount'],
                docStatusLabel($r['status']),
            ]);
        }
        fclose($out);
        exit;
    }

    // -------------------------------------------------------------- default
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        exit;
}
