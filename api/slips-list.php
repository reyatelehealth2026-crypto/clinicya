<?php
/**
 * Slips List API
 * 
 * Returns paginated list of all slip uploads, auto-joined with users table
 * to show customer name. Used by odoo-dashboard.php and Slip Center.
 * 
 * GET params:
 *   limit        - Max records (default 30, max 200)
 *   offset       - Pagination offset (default 0)
 *   search       - Search by customer display_name or line_user_id (LIKE)
 *   status       - Filter by status (pending|matched|failed)
 *   date         - Filter by upload date (YYYY-MM-DD)
 *   line_user_id - Exact match on line_user_id (for customer-scoped queries)
 *   customer_ref - Exact match on customer_ref (joins odoo_line_users to resolve)
 *   partner_id   - Filter by resolved partner_id (via odoo_line_users)
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../api/odoo-dashboard-functions.php';

// Redis + APCu + file — แชร์ระหว่างผู้ใช้/ worker (dashboard matching โหลดสลิป pending บ่อย)
$slipsCacheTtl = 30;
$slipsCacheInput = $_GET;
if (is_array($slipsCacheInput)) {
    unset($slipsCacheInput['_t']);
    dashboardApiNormalizeCacheInput($slipsCacheInput);
}
$slipsCacheKey = 'slips_list_' . sha1(json_encode($slipsCacheInput, JSON_UNESCAPED_UNICODE));
$cachedPayload = dashboardApiCacheGet($slipsCacheKey, $slipsCacheTtl);
if ($cachedPayload !== null && is_array($cachedPayload) && !empty($cachedPayload['success'])) {
    header('X-Dashboard-Cache: HIT');
    header('X-Cache-Layer: redis');
    echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    $limit       = min((int) ($_GET['limit']  ?? 30), 2000);
    $offset      = max((int) ($_GET['offset'] ?? 0),  0);
    $search      = trim($_GET['search']      ?? '');
    $status      = trim($_GET['status']      ?? '');
    $date        = trim($_GET['date']        ?? '');
    $lineUserId  = trim($_GET['line_user_id'] ?? '');
    $customerRef = trim($_GET['customer_ref'] ?? '');
    $partnerId   = (int) ($_GET['partner_id'] ?? 0);

    // Resolve line_user_id from customer_ref or partner_id if not directly provided
    if ($lineUserId === '' && $customerRef !== '') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_customer_code = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([$customerRef]);
            $row = $stmt->fetchColumn();
            if ($row) $lineUserId = $row;
        } catch (Exception $e) { /* ignore */ }

        // Also try odoo_bdos as fallback for customer_ref→line_user_id
        if ($lineUserId === '') {
            try {
                $stmt = $db->prepare("SELECT line_user_id FROM odoo_bdos WHERE customer_ref = ? AND line_user_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([$customerRef]);
                $row = $stmt->fetchColumn();
                if ($row) $lineUserId = $row;
            } catch (Exception $e) { /* ignore */ }
        }
    }

    if ($lineUserId === '' && $partnerId > 0) {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([$partnerId]);
            $row = $stmt->fetchColumn();
            if ($row) $lineUserId = $row;
        } catch (Exception $e) { /* ignore */ }
    }

    $where  = [];
    $params = [];

    // Exact customer scope takes priority over fuzzy search
    if ($lineUserId !== '') {
        $where[]  = 's.line_user_id = ?';
        $params[] = $lineUserId;
    } elseif ($search !== '') {
        $where[]  = '(u.display_name LIKE ? OR s.line_user_id LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if ($status !== '') {
        $where[]  = 's.status = ?';
        $params[] = $status;
    }

    if ($date !== '') {
        $where[]  = 'DATE(s.uploaded_at) = ?';
        $params[] = $date;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count
    $countSql = "
        SELECT COUNT(*) 
        FROM odoo_slip_uploads s
        LEFT JOIN users u ON u.line_user_id = s.line_user_id
        $whereClause
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Data
    $dataSql = "
        SELECT 
            s.id,
            s.line_user_id,
            s.line_account_id,
            s.odoo_slip_id,
            s.slip_inbox_id,
            s.slip_inbox_name,
            s.bdo_id,
            s.bdo_name,
            s.invoice_id,
            s.order_id,
            s.amount,
            s.transfer_date,
            s.image_path,
            s.image_url,
            s.uploaded_by,
            s.message_id,
            s.status,
            s.match_confidence,
            s.delivery_type,
            s.bdo_amount,
            s.match_reason,
            s.uploaded_at,
            s.matched_at,
            u.display_name AS customer_name,
            u.picture_url  AS customer_avatar,
            COALESCE(olu.odoo_customer_code, ob.customer_ref) AS customer_ref,
            COALESCE(olu.odoo_partner_id, ob.partner_id) AS partner_id
        FROM odoo_slip_uploads s
        LEFT JOIN users u ON u.line_user_id = s.line_user_id
        LEFT JOIN odoo_line_users olu ON olu.line_user_id = s.line_user_id
        LEFT JOIN odoo_bdos ob ON ob.line_user_id = s.line_user_id AND ob.customer_ref IS NOT NULL
        $whereClause
        ORDER BY s.uploaded_at DESC
        LIMIT ? OFFSET ?
    ";
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataStmt = $db->prepare($dataSql);
    $dataStmt->execute($dataParams);
    $slips = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build full image URLs
    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://cny.re-ya.com', '/');
    foreach ($slips as &$slip) {
        $slip['id']     = (int) $slip['id'];
        $slip['amount'] = $slip['amount'] !== null ? (float) $slip['amount'] : null;
        $slip['slip_inbox_id'] = $slip['slip_inbox_id'] !== null ? (int) $slip['slip_inbox_id'] : null;
        $slip['bdo_id'] = $slip['bdo_id'] !== null ? (int) $slip['bdo_id'] : null;
        $slip['bdo_amount'] = $slip['bdo_amount'] !== null ? (float) $slip['bdo_amount'] : null;
        $slip['partner_id'] = $slip['partner_id'] !== null ? (int) $slip['partner_id'] : null;

        if ($slip['image_path']) {
            $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
        } else {
            $slip['image_full_url'] = $slip['image_url'] ?: null;
        }
    }
    unset($slip);

    $payload = [
        'success' => true,
        'data'    => [
            'slips'  => $slips,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ],
    ];
    dashboardApiCacheSet($slipsCacheKey, $payload, $slipsCacheTtl);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
