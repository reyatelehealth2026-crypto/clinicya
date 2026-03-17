<?php
/**
 * BDO Inbox API — Normalized facade for inboxreya BDO Confirm feature.
 *
 * This endpoint is the SINGLE data source for the new inboxreya BDO Confirm UI.
 * It replaces the scattered actions in odoo-webhooks-dashboard.php and
 * odoo-dashboard-api.php with a clean, consistent contract.
 *
 * All read actions serve cached local data (fast).
 * All write actions (match/unmatch) proxy to Odoo first — local DB is only
 * updated AFTER Odoo confirms success. There are NO local-only fallbacks for
 * state-changing operations.
 *
 * Actions (POST JSON body: { "action": "...", ...params }):
 *
 *  READ (fast, from local cache):
 *   - bdo_list          : List BDOs for a customer (by line_user_id or partner_id)
 *   - bdo_detail        : BDO detail from Odoo (live) with local fallback for read-only fields
 *   - slip_list         : Unmatched/pending slips for a customer
 *   - bdo_context       : Open BDO contexts for a customer (for slip-upload auto-attach)
 *   - matching_workspace: Combined BDO + slip data for the matching UI
 *
 *  WRITE (Odoo-first, no local-only fallback):
 *   - slip_match_bdo    : Match slip(s) to BDO(s) via POST /reya/slip/match-bdo
 *   - slip_unmatch      : Unmatch via POST /reya/slip/unmatch
 *   - slip_upload       : Upload slip via POST /reya/slip/upload
 *
 *  UTILITY:
 *   - statement_pdf_url : Return URL to download statement PDF
 *   - health            : Health check
 *
 * Auth: Internal only — called from inboxreya via INTERNAL_API_SECRET header.
 *
 * @version 1.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Internal-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BdoSlipContract.php';
require_once __DIR__ . '/../classes/BdoContextManager.php';

// ── Performance: track start time for latency logging ────────────────────────
$_apiStartTime = microtime(true);

// ── Auth check ───────────────────────────────────────────────────────────────
$internalSecret = defined('INTERNAL_API_SECRET') ? INTERNAL_API_SECRET : '';
$requestSecret  = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? ($_GET['secret'] ?? '');

if ($internalSecret && !hash_equals($internalSecret, $requestSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Fast health check (no DB) ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] === 'GET') && (($_GET['action'] ?? '') === 'health' || ($_GET['action'] ?? '') === '')) {
    header('Cache-Control: no-cache');
    echo json_encode([
        'success' => true,
        'data'    => ['status' => 'ok', 'service' => 'bdo-inbox-api', 'version' => '1.0.0', 'timestamp' => date('c')],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    } else {
        $input = $_GET;
    }

    $action = trim((string) ($input['action'] ?? ''));

    if ($action === '' || $action === 'health') {
        echo json_encode(['success' => true, 'data' => ['status' => 'ok', 'service' => 'bdo-inbox-api', 'timestamp' => date('c')]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Read-only actions: add cache headers ──────────────────────────────────
    $readActions = ['bdo_list', 'slip_list', 'bdo_context', 'matching_workspace', 'statement_pdf_url'];
    if (in_array($action, $readActions, true)) {
        header('Cache-Control: private, max-age=10'); // 10s client-side cache for read actions
    }

    $result = match ($action) {
        'bdo_list'           => actionBdoList($db, $input),
        'bdo_detail'         => actionBdoDetail($db, $input),
        'slip_list'          => actionSlipList($db, $input),
        'bdo_context'        => actionBdoContext($db, $input),
        'matching_workspace' => actionMatchingWorkspace($db, $input),
        'slip_match_bdo'     => actionSlipMatchBdo($db, $input),
        'slip_unmatch'       => actionSlipUnmatch($db, $input),
        'slip_upload'        => actionSlipUpload($db, $input),
        'statement_pdf_url'  => actionStatementPdfUrl($db, $input),
        default              => throw new InvalidArgumentException("Unknown action: {$action}"),
    };

    // ── Structured latency logging for write actions ──────────────────────────
    $writeActions = ['slip_match_bdo', 'slip_unmatch', 'slip_upload'];
    if (in_array($action, $writeActions, true)) {
        $latencyMs = (int) round((microtime(true) - $_apiStartTime) * 1000);
        $lineUserId = $input['line_user_id'] ?? '';
        $success = ($result['success'] ?? true) !== false;
        error_log(sprintf(
            '[bdo-inbox-api] action=%s line_user_id=%s success=%s latency_ms=%d',
            $action, $lineUserId, $success ? 'true' : 'false', $latencyMs
        ));
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $latencyMs = (int) round((microtime(true) - $_apiStartTime) * 1000);
    error_log('[bdo-inbox-api] ERROR latency_ms=' . $latencyMs . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error'], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════════════════
// READ ACTIONS
// ════════════════════════════════════════════════════════════════════════════

/**
 * List BDOs for a customer from local cache (odoo_bdos table).
 * Returns only 'waiting' BDOs by default (configurable via state param).
 */
function actionBdoList(PDO $db, array $input): array
{
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $state       = trim((string) ($input['state']        ?? 'waiting'));
    $limit       = min((int) ($input['limit']  ?? 50), 200);
    $offset      = max((int) ($input['offset'] ?? 0), 0);

    if ($lineUserId === '' && $partnerId === '') {
        throw new InvalidArgumentException('line_user_id หรือ partner_id จำเป็นต้องระบุ');
    }

    // Resolve partner_id from line_user_id if needed
    if ($partnerId === '' && $lineUserId !== '') {
        $partnerId = resolvePartnerIdFromLineUser($db, $lineUserId) ?? '';
    }

    $where  = [];
    $params = [];

    if ($partnerId !== '') {
        $where[]  = 'b.partner_id = ?';
        $params[] = (int) $partnerId;
    } elseif ($lineUserId !== '') {
        $where[]  = 'b.line_user_id = ?';
        $params[] = $lineUserId;
    }

    if ($state !== 'all') {
        $where[]  = 'b.state = ?';
        $params[] = $state;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_bdos b {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare("
            SELECT
                b.bdo_id, b.bdo_name, b.order_id, b.order_name,
                b.partner_id, b.customer_ref, b.line_user_id,
                b.salesperson_name, b.state, b.amount_total, b.currency,
                b.bdo_date, b.expected_delivery, b.updated_at,
                ctx.delivery_type, ctx.amount AS ctx_amount,
                ctx.statement_pdf_path, ctx.qr_payload IS NOT NULL AS has_qr,
                ctx.selected_invoices_json, ctx.selected_credit_notes_json,
                ctx.financial_summary_json
            FROM odoo_bdos b
            LEFT JOIN odoo_bdo_context ctx
                ON ctx.bdo_id = b.bdo_id AND ctx.line_user_id = b.line_user_id
            {$whereClause}
            ORDER BY b.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bdos = array_map('normalizeBdoRow', $rows);

        return ['bdos' => $bdos, 'total' => $total, 'limit' => $limit, 'offset' => $offset];

    } catch (Exception $e) {
        error_log('[bdo-inbox-api:bdo_list] ' . $e->getMessage());
        return ['bdos' => [], 'total' => 0, 'error' => 'Database error'];
    }
}

/**
 * BDO detail — fetches from Odoo live (authoritative financial data).
 * Falls back to local cache for read-only display if Odoo is unreachable.
 */
function actionBdoDetail(PDO $db, array $input): array
{
    $bdoId      = (int) ($input['bdo_id'] ?? 0);
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));

    if ($bdoId <= 0) {
        throw new InvalidArgumentException('bdo_id จำเป็นต้องระบุ');
    }

    $odooResult = callOdooApi(BdoSlipContract::ENDPOINT_BDO_DETAIL, [
        'line_user_id' => $lineUserId,
        'bdo_id'       => $bdoId,
    ]);

    $odooError = BdoSlipContract::extractOdooError($odooResult);

    if ($odooError === null) {
        $data = BdoSlipContract::extractOdooData($odooResult);
        return [
            'bdo'    => BdoSlipContract::normalizeBdo($data['bdo'] ?? $data ?? []),
            'source' => 'odoo',
        ];
    }

    // Fallback: local cache (read-only, may be stale)
    try {
        $stmt = $db->prepare("
            SELECT b.*, ctx.delivery_type, ctx.statement_pdf_path,
                   ctx.financial_summary_json, ctx.selected_invoices_json,
                   ctx.selected_credit_notes_json, ctx.qr_payload
            FROM odoo_bdos b
            LEFT JOIN odoo_bdo_context ctx
                ON ctx.bdo_id = b.bdo_id AND ctx.line_user_id = b.line_user_id
            WHERE b.bdo_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bdoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'bdo'         => normalizeBdoRow($row),
                'source'      => 'local_cache',
                'odoo_error'  => $odooError,
                'stale_warning' => 'ข้อมูลอาจไม่ใช่ล่าสุด เนื่องจากไม่สามารถเชื่อมต่อ Odoo ได้',
            ];
        }
    } catch (Exception $e) {
        error_log('[bdo-inbox-api:bdo_detail] local fallback error: ' . $e->getMessage());
    }

    throw new RuntimeException("ไม่พบ BDO #{$bdoId} (Odoo: {$odooError})");
}

/**
 * List slips for a customer — returns all fields needed by matching UI.
 * Includes slip_inbox_id, match_confidence, delivery_type, bdo_amount.
 */
function actionSlipList(PDO $db, array $input): array
{
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId  = trim((string) ($input['partner_id']  ?? ''));
    $statusFilter = trim((string) ($input['status'] ?? ''));
    $limit      = min((int) ($input['limit']  ?? 50), 200);
    $offset     = max((int) ($input['offset'] ?? 0), 0);

    if ($lineUserId === '' && $partnerId === '') {
        throw new InvalidArgumentException('line_user_id หรือ partner_id จำเป็นต้องระบุ');
    }

    if ($lineUserId === '' && $partnerId !== '') {
        $lineUserId = resolveLineUserIdFromPartner($db, $partnerId) ?? '';
    }

    if ($lineUserId === '') {
        return ['slips' => [], 'total' => 0];
    }

    $where  = ['s.line_user_id = ?'];
    $params = [$lineUserId];

    if ($statusFilter !== '') {
        $normalizedStatusFilter = BdoSlipContract::normalizeSlipStatus($statusFilter);
        if ($normalizedStatusFilter === BdoSlipContract::SLIP_STATUS_NEW) {
            $openStatuses = BdoSlipContract::getOpenSlipStatuses();
            $placeholders = implode(', ', array_fill(0, count($openStatuses), '?'));
            $where[] = "s.status IN ({$placeholders})";
            array_push($params, ...$openStatuses);
        } else {
            $where[]  = 's.status = ?';
            $params[] = $normalizedStatusFilter;
        }
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_slip_uploads s {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare("
            SELECT
                s.id,
                s.slip_inbox_id,
                s.slip_inbox_name,
                s.odoo_slip_id,
                s.odoo_partner_id AS partner_id,
                s.bdo_id,
                s.bdo_name,
                s.invoice_id,
                s.order_id,
                s.amount,
                s.transfer_date,
                s.status,
                s.match_confidence,
                s.match_reason,
                s.delivery_type,
                s.bdo_amount,
                s.image_path,
                s.image_url,
                s.uploaded_at,
                s.matched_at,
                s.line_user_id
            FROM odoo_slip_uploads s
            {$whereClause}
            ORDER BY s.uploaded_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : (defined('BASE_URL') ? BASE_URL : 'https://cny.re-ya.com'), '/');

        $slips = array_map(function ($row) use ($baseUrl) {
            $row['id']             = (int) $row['id'];
            $row['slip_inbox_id']  = $row['slip_inbox_id'] !== null ? (int) $row['slip_inbox_id'] : null;
            $row['odoo_slip_id']   = $row['odoo_slip_id']  !== null ? (int) $row['odoo_slip_id']  : null;
            $row['partner_id']     = $row['partner_id']    !== null ? (int) $row['partner_id']    : null;
            $row['bdo_id']         = $row['bdo_id']        !== null ? (int) $row['bdo_id']        : null;
            $row['amount']         = $row['amount']        !== null ? (float) $row['amount']      : null;
            $row['bdo_amount']     = $row['bdo_amount']    !== null ? (float) $row['bdo_amount']  : null;
            // Canonical slip ID for Odoo operations (prefer slip_inbox_id)
            $row['canonical_slip_id'] = $row['slip_inbox_id'] ?? $row['odoo_slip_id'] ?? $row['id'];
            // Full image URL
            $row['image_full_url'] = $row['image_path']
                ? $baseUrl . '/' . ltrim($row['image_path'], '/')
                : ($row['image_url'] ?: null);
            $row['status'] = BdoSlipContract::normalizeSlipStatus($row['status'] ?? null);
            return $row;
        }, $rows);

        return ['slips' => $slips, 'total' => $total, 'limit' => $limit, 'offset' => $offset];

    } catch (Exception $e) {
        error_log('[bdo-inbox-api:slip_list] ' . $e->getMessage());
        return ['slips' => [], 'total' => 0, 'error' => 'Database error'];
    }
}

/**
 * Return open BDO contexts for a customer.
 * Used by slip-upload flow to know which BDO(s) to attach.
 */
function actionBdoContext(PDO $db, array $input): array
{
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));

    if ($lineUserId === '') {
        throw new InvalidArgumentException('line_user_id จำเป็นต้องระบุ');
    }

    $ctxMgr  = new BdoContextManager($db);
    $contexts = $ctxMgr->getOpenContexts($lineUserId);

    return [
        'contexts'    => $contexts,
        'count'       => count($contexts),
        'ambiguous'   => count($contexts) > 1,
        'single_bdo_id' => count($contexts) === 1 ? (int) $contexts[0]['bdo_id'] : null,
    ];
}

/**
 * Combined workspace for the matching UI.
 * Returns pending slips + open BDOs + smart match suggestions in one call.
 */
function actionMatchingWorkspace(PDO $db, array $input): array
{
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId  = trim((string) ($input['partner_id']  ?? ''));

    if ($lineUserId === '' && $partnerId === '') {
        throw new InvalidArgumentException('line_user_id หรือ partner_id จำเป็นต้องระบุ');
    }

    // Resolve IDs
    if ($lineUserId === '' && $partnerId !== '') {
        $lineUserId = resolveLineUserIdFromPartner($db, $partnerId) ?? '';
    }
    if ($partnerId === '' && $lineUserId !== '') {
        $partnerId = resolvePartnerIdFromLineUser($db, $lineUserId) ?? '';
    }

    // Fetch pending slips (new + unmatched)
    $slipResult = actionSlipList($db, array_merge($input, [
        'line_user_id' => $lineUserId,
        'status'       => '',
        'limit'        => 100,
        'offset'       => 0,
    ]));

    $pendingSlips = array_filter($slipResult['slips'] ?? [], function ($s) {
        return BdoSlipContract::isOpenSlipStatus($s['status'] ?? null)
            || in_array($s['status'] ?? BdoSlipContract::SLIP_STATUS_NEW, ['unmatched', 'manual'], true);
    });

    // Fetch open BDOs
    $bdoResult = actionBdoList($db, array_merge($input, [
        'line_user_id' => $lineUserId,
        'partner_id'   => $partnerId,
        'state'        => 'waiting',
        'limit'        => 50,
        'offset'       => 0,
    ]));

    $openBdos = $bdoResult['bdos'] ?? [];

    // Build smart match suggestions
    $suggestions = buildMatchSuggestions(array_values($pendingSlips), $openBdos);

    return [
        'pending_slips'  => array_values($pendingSlips),
        'open_bdos'      => $openBdos,
        'suggestions'    => $suggestions,
        'slip_total'     => count($pendingSlips),
        'bdo_total'      => count($openBdos),
    ];
}

/**
 * Build smart match suggestions based on bdo_id context and amount matching.
 * Priority: 1) bdo_id direct match, 2) exact amount ±1 THB, 3) fuzzy ±5%
 */
function buildMatchSuggestions(array $slips, array $bdos): array
{
    $suggestions = [];

    foreach ($slips as $slip) {
        $slipAmt   = (float) ($slip['amount'] ?? 0);
        $slipBdoId = (int) ($slip['bdo_id'] ?? 0);

        foreach ($bdos as $bdo) {
            $bdoId  = (int) ($bdo['bdo_id'] ?? 0);
            $bdoAmt = (float) ($bdo['amount_total'] ?? $bdo['ctx_amount'] ?? 0);

            // Direct bdo_id match (highest confidence)
            if ($slipBdoId > 0 && $slipBdoId === $bdoId) {
                $diff = abs($slipAmt - $bdoAmt);
                $suggestions[] = [
                    'slip_id'    => $slip['canonical_slip_id'] ?? $slip['id'],
                    'bdo_id'     => $bdoId,
                    'confidence' => $diff <= 1 ? 'exact_bdo_id' : 'bdo_id_amount_mismatch',
                    'amount_diff' => $diff,
                    'slip'       => $slip,
                    'bdo'        => $bdo,
                ];
                continue 2;
            }

            // Exact amount match (±1 THB)
            if ($slipAmt > 0 && $bdoAmt > 0 && abs($slipAmt - $bdoAmt) <= 1) {
                $suggestions[] = [
                    'slip_id'    => $slip['canonical_slip_id'] ?? $slip['id'],
                    'bdo_id'     => $bdoId,
                    'confidence' => 'exact_amount',
                    'amount_diff' => abs($slipAmt - $bdoAmt),
                    'slip'       => $slip,
                    'bdo'        => $bdo,
                ];
            }
        }
    }

    // Sort: exact_bdo_id first, then exact_amount
    usort($suggestions, function ($a, $b) {
        $order = ['exact_bdo_id' => 0, 'exact_amount' => 1, 'bdo_id_amount_mismatch' => 2];
        return ($order[$a['confidence']] ?? 9) <=> ($order[$b['confidence']] ?? 9);
    });

    return $suggestions;
}

// ════════════════════════════════════════════════════════════════════════════
// WRITE ACTIONS (Odoo-first, no local-only fallback for state changes)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Match slip(s) to BDO(s) — proxies to Odoo /reya/slip/match-bdo.
 * Local DB is updated ONLY after Odoo confirms success.
 */
function actionSlipMatchBdo(PDO $db, array $input): array
{
    $slipInboxId = (int) ($input['slip_inbox_id'] ?? 0);
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $matches     = $input['matches'] ?? [];
    $note        = trim((string) ($input['note'] ?? ''));

    // Validate
    $slipAmount = (float) ($input['slip_amount'] ?? 0);
    $validation = BdoSlipContract::validateMatchRequest($slipInboxId, $matches, $slipAmount);
    if (!$validation['valid']) {
        return ['success' => false, 'error' => $validation['error']];
    }

    // Call Odoo — this is the authoritative action
    $odooResult = callOdooApi(BdoSlipContract::ENDPOINT_SLIP_MATCH, [
        'line_user_id'  => $lineUserId,
        'slip_inbox_id' => $slipInboxId,
        'matches'       => $matches,
        'note'          => $note,
    ]);

    $odooError = BdoSlipContract::extractOdooError($odooResult);

    if ($odooError !== null) {
        // Do NOT update local DB — Odoo rejected the match
        error_log("[bdo-inbox-api:slip_match_bdo] Odoo rejected: slip_inbox_id={$slipInboxId}, error={$odooError}");
        return [
            'success'    => false,
            'error'      => $odooError,
            'error_type' => 'odoo_rejected',
        ];
    }

    // Odoo confirmed — update local cache
    updateLocalSlipAfterMatch($db, $slipInboxId, $matches, $note);

    $data = BdoSlipContract::extractOdooData($odooResult);
    return array_merge(['success' => true, 'source' => 'odoo'], $data ?? []);
}

/**
 * Unmatch slip — proxies to Odoo /reya/slip/unmatch.
 * Blocked if slip is already posted/done.
 */
function actionSlipUnmatch(PDO $db, array $input): array
{
    $slipInboxId = (int) ($input['slip_inbox_id'] ?? 0);
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $reason      = trim((string) ($input['reason'] ?? ''));

    // Check local status before calling Odoo (fast guard)
    $localStatus = getLocalSlipStatus($db, $slipInboxId);
    $validation  = BdoSlipContract::validateUnmatchRequest($slipInboxId, $localStatus ?? '');
    if (!$validation['valid']) {
        return ['success' => false, 'error' => $validation['error']];
    }

    // Call Odoo
    $odooResult = callOdooApi(BdoSlipContract::ENDPOINT_SLIP_UNMATCH, [
        'line_user_id'  => $lineUserId,
        'slip_inbox_id' => $slipInboxId,
        'reason'        => $reason,
    ]);

    $odooError = BdoSlipContract::extractOdooError($odooResult);

    if ($odooError !== null) {
        error_log("[bdo-inbox-api:slip_unmatch] Odoo rejected: slip_inbox_id={$slipInboxId}, error={$odooError}");
        return [
            'success'    => false,
            'error'      => $odooError,
            'error_type' => 'odoo_rejected',
        ];
    }

    // Odoo confirmed — reset local cache
    updateLocalSlipAfterUnmatch($db, $slipInboxId);

    $data = BdoSlipContract::extractOdooData($odooResult);
    return array_merge(['success' => true, 'source' => 'odoo'], $data ?? []);
}

/**
 * Upload slip — proxies to Odoo /reya/slip/upload.
 * Resolves bdo_id from context if not explicitly provided.
 */
function actionSlipUpload(PDO $db, array $input): array
{
    $lineUserId    = trim((string) ($input['line_user_id'] ?? ''));
    $slipImageB64  = trim((string) ($input['slip_image']   ?? ''));
    $amount        = isset($input['amount']) ? (float) $input['amount'] : null;
    $transferDate  = trim((string) ($input['transfer_date'] ?? ''));
    $bdoId         = isset($input['bdo_id']) ? (int) $input['bdo_id'] : null;

    if ($lineUserId === '' || $slipImageB64 === '') {
        return ['success' => false, 'error' => 'line_user_id และ slip_image จำเป็นต้องระบุ'];
    }

    // Auto-resolve bdo_id from context if not provided
    if ($bdoId === null) {
        $ctxMgr   = new BdoContextManager($db);
        $ambiguous = [];
        $resolved  = $ctxMgr->resolveSlipBdoId($lineUserId, $ambiguous);

        if ($resolved !== null) {
            $bdoId = $resolved;
        } elseif (!empty($ambiguous)) {
            // Multiple open BDOs — caller must specify
            return [
                'success'        => false,
                'error'          => 'ลูกค้ามีหลาย BDO ที่รอชำระ กรุณาระบุ BDO ที่ต้องการแนบสลิป',
                'ambiguous_bdos' => array_map(function ($ctx) {
                    return [
                        'bdo_id'       => (int) $ctx['bdo_id'],
                        'bdo_name'     => $ctx['bdo_name'],
                        'amount'       => $ctx['amount'] !== null ? (float) $ctx['amount'] : null,
                        'delivery_type' => $ctx['delivery_type'],
                    ];
                }, $ambiguous),
            ];
        }
    }

    $params = BdoSlipContract::buildUploadParams(
        $lineUserId,
        $slipImageB64,
        $amount,
        $transferDate !== '' ? $transferDate : null,
        $bdoId
    );

    $odooResult = callOdooApi(BdoSlipContract::ENDPOINT_SLIP_UPLOAD, $params);
    $odooError  = BdoSlipContract::extractOdooError($odooResult);

    if ($odooError !== null) {
        return ['success' => false, 'error' => $odooError];
    }

    $data = BdoSlipContract::extractOdooData($odooResult);
    $slip = $data['slip'] ?? [];

    // Update local cache with Odoo-returned slip_inbox_id and match result
    if (!empty($slip['slip_inbox_id'])) {
        updateLocalSlipFromOdooResponse($db, $lineUserId, $slip);
    }

    return [
        'success' => true,
        'slip'    => BdoSlipContract::normalizeSlip($slip),
        'match_result' => $data['match_result'] ?? null,
    ];
}

/**
 * Return the URL to download a statement PDF.
 * Prefers locally cached file, falls back to Odoo direct URL.
 */
function actionStatementPdfUrl(PDO $db, array $input): array
{
    $bdoId      = (int) ($input['bdo_id'] ?? 0);
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));

    if ($bdoId <= 0) {
        throw new InvalidArgumentException('bdo_id จำเป็นต้องระบุ');
    }

    $baseUrl = rtrim(defined('BASE_URL') ? BASE_URL : 'https://cny.re-ya.com', '/');

    // Check local cache first
    try {
        $stmt = $db->prepare("
            SELECT statement_pdf_path FROM odoo_bdo_context
            WHERE bdo_id = ? AND line_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bdoId, $lineUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['statement_pdf_path'])) {
            $localPath = __DIR__ . '/../' . $row['statement_pdf_path'];
            if (file_exists($localPath)) {
                return [
                    'url'    => $baseUrl . '/' . ltrim($row['statement_pdf_path'], '/'),
                    'source' => 'local_cache',
                ];
            }
        }
    } catch (Exception $e) {
        error_log('[bdo-inbox-api:statement_pdf_url] ' . $e->getMessage());
    }

    // Fallback: Odoo direct URL
    $odooBase = defined('ODOO_API_BASE_URL') ? rtrim(ODOO_API_BASE_URL, '/') : 'https://erp.cnyrxapp.com';
    $apiKey   = defined('ODOO_API_KEY') ? ODOO_API_KEY : '';

    return [
        'url'    => $odooBase . BdoSlipContract::ENDPOINT_BDO_PDF . $bdoId . '?api_key=' . urlencode($apiKey),
        'source' => 'odoo_direct',
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════

/**
 * Call Odoo JSON-RPC API. Returns full decoded response or null on network error.
 */
function callOdooApi(string $endpoint, array $params): ?array
{
    $baseUrl = defined('ODOO_API_BASE_URL') ? rtrim(ODOO_API_BASE_URL, '/') : 'https://erp.cnyrxapp.com';
    $apiKey  = defined('ODOO_API_KEY') ? ODOO_API_KEY : '';

    if (!$apiKey) {
        error_log('[bdo-inbox-api] ODOO_API_KEY not configured');
        return null;
    }

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'call',
        'params'  => $params,
    ]);

    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Api-Key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $response === false) {
        error_log("[bdo-inbox-api] cURL error for {$endpoint}: {$curlErr}");
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Normalize a BDO row from local DB into a consistent shape.
 */
function normalizeBdoRow(array $row): array
{
    $fin = [];
    if (!empty($row['financial_summary_json'])) {
        $fin = json_decode($row['financial_summary_json'], true) ?? [];
    }

    return [
        'bdo_id'                     => $row['bdo_id'] !== null ? (int) $row['bdo_id'] : null,
        'bdo_name'                   => $row['bdo_name'] ?? null,
        'order_id'                   => $row['order_id'] !== null ? (int) $row['order_id'] : null,
        'order_name'                 => $row['order_name'] ?? null,
        'partner_id'                 => $row['partner_id'] !== null ? (int) $row['partner_id'] : null,
        'customer_ref'               => $row['customer_ref'] ?? null,
        'line_user_id'               => $row['line_user_id'] ?? null,
        'salesperson_name'           => $row['salesperson_name'] ?? null,
        'state'                      => $row['state'] ?? 'waiting',
        'amount_total'               => $row['amount_total'] !== null ? (float) $row['amount_total'] : null,
        'currency'                   => $row['currency'] ?? 'THB',
        'delivery_type'              => $row['delivery_type'] ?? null,
        'bdo_date'                   => $row['bdo_date'] ?? null,
        'expected_delivery'          => $row['expected_delivery'] ?? null,
        'updated_at'                 => $row['updated_at'] ?? null,
        'has_qr'                     => !empty($row['has_qr']),
        'statement_pdf_path'         => $row['statement_pdf_path'] ?? null,
        'selected_invoices'          => !empty($row['selected_invoices_json'])
            ? (json_decode($row['selected_invoices_json'], true) ?? []) : [],
        'selected_credit_notes'      => !empty($row['selected_credit_notes_json'])
            ? (json_decode($row['selected_credit_notes_json'], true) ?? []) : [],
        'financial_summary'          => $fin,
        'amount_net_to_pay'          => $fin['amount_net_to_pay'] ?? $row['amount_total'] ?? null,
        'amount_outstanding_invoice' => (float) ($fin['amount_outstanding_invoice'] ?? 0),
        'amount_credit_note'         => (float) ($fin['amount_credit_note'] ?? 0),
        'amount_deposit'             => (float) ($fin['amount_deposit'] ?? 0),
        'amount_so_this_round'       => (float) ($fin['amount_so_this_round'] ?? 0),
    ];
}

/**
 * Update local slip cache after a successful Odoo match.
 */
function updateLocalSlipAfterMatch(PDO $db, int $slipInboxId, array $matches, string $note): void
{
    try {
        $bdoId = (int) ($matches[0]['bdo_id'] ?? 0);
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'matched',
                bdo_id = ?,
                match_reason = ?,
                matched_at = NOW()
            WHERE slip_inbox_id = ? OR odoo_slip_id = ? OR id = ?
        ")->execute([$bdoId ?: null, $note ?: 'Matched via BDO Inbox', $slipInboxId, $slipInboxId, $slipInboxId]);
    } catch (Exception $e) {
        error_log('[bdo-inbox-api] updateLocalSlipAfterMatch: ' . $e->getMessage());
    }
}

/**
 * Reset local slip cache after a successful Odoo unmatch.
 */
function updateLocalSlipAfterUnmatch(PDO $db, int $slipInboxId): void
{
    try {
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'new',
                bdo_id = NULL,
                match_reason = NULL,
                match_confidence = NULL,
                matched_at = NULL
            WHERE slip_inbox_id = ? OR odoo_slip_id = ? OR id = ?
        ")->execute([$slipInboxId, $slipInboxId, $slipInboxId]);
    } catch (Exception $e) {
        error_log('[bdo-inbox-api] updateLocalSlipAfterUnmatch: ' . $e->getMessage());
    }
}

/**
 * Update local slip record from Odoo upload response.
 */
function updateLocalSlipFromOdooResponse(PDO $db, string $lineUserId, array $slip): void
{
    try {
        $slipInboxId  = (int) ($slip['slip_inbox_id'] ?? 0);
        $confidence   = $slip['match_confidence'] ?? null;
        $status       = BdoSlipContract::normalizeSlipStatus($slip['status'] ?? null);
        $bdoId        = isset($slip['bdo_id']) ? (int) $slip['bdo_id'] : null;
        $bdoName      = $slip['bdo_name'] ?? null;
        $deliveryType = $slip['delivery_type'] ?? null;
        $bdoAmount    = isset($slip['bdo_amount']) ? (float) $slip['bdo_amount'] : null;
        $inboxName    = $slip['slip_inbox_name'] ?? null;

        if ($slipInboxId > 0) {
            $db->prepare("
                UPDATE odoo_slip_uploads
                SET slip_inbox_id    = ?,
                    slip_inbox_name  = COALESCE(?, slip_inbox_name),
                    match_confidence = COALESCE(?, match_confidence),
                    status           = COALESCE(?, status),
                    bdo_id           = COALESCE(?, bdo_id),
                    bdo_name         = COALESCE(?, bdo_name),
                    delivery_type    = COALESCE(?, delivery_type),
                    bdo_amount       = COALESCE(?, bdo_amount)
                WHERE line_user_id = ?
                  AND (slip_inbox_id IS NULL OR slip_inbox_id = ?)
                ORDER BY uploaded_at DESC
                LIMIT 1
            ")->execute([
                $slipInboxId, $inboxName, $confidence, $status,
                $bdoId, $bdoName, $deliveryType, $bdoAmount,
                $lineUserId, $slipInboxId,
            ]);
        }
    } catch (Exception $e) {
        error_log('[bdo-inbox-api] updateLocalSlipFromOdooResponse: ' . $e->getMessage());
    }
}

/**
 * Get local slip status by slip_inbox_id (for pre-unmatch validation).
 */
function getLocalSlipStatus(PDO $db, int $slipInboxId): ?string
{
    try {
        $stmt = $db->prepare("
            SELECT status FROM odoo_slip_uploads
            WHERE slip_inbox_id = ? OR odoo_slip_id = ?
            LIMIT 1
        ");
        $stmt->execute([$slipInboxId, $slipInboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['status'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Resolve Odoo partner_id from line_user_id.
 */
function resolvePartnerIdFromLineUser(PDO $db, string $lineUserId): ?string
{
    try {
        $stmt = $db->prepare("SELECT odoo_partner_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetchColumn();
        return $row ? (string) $row : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Resolve line_user_id from Odoo partner_id.
 */
function resolveLineUserIdFromPartner(PDO $db, string $partnerId): ?string
{
    try {
        $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
        $stmt->execute([(int) $partnerId]);
        $row = $stmt->fetchColumn();
        return $row ? (string) $row : null;
    } catch (Exception $e) {
        return null;
    }
}
