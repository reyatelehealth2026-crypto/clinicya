<?php
/**
 * Send Pending Slips to Odoo API
 *
 * Reads all pending slips from odoo_slip_uploads, uploads each one
 * to Odoo via multipart/form-data (POST /reya/slip/upload), and
 * updates the status to matched/failed.
 *
 * POST body (JSON):
 *   ids?      – array of specific slip IDs to send (optional; sends all pending if omitted)
 *   dry_run?  – bool, if true just returns what would be sent without sending
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooAPIClient.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids    = $input['ids']    ?? null;   // array|null
    $dryRun = !empty($input['dry_run']);

    // ------------------------------------------------------------------ //
    // 1. Fetch pending slips (optionally filtered by IDs)
    // ------------------------------------------------------------------ //
    $allowRetry = !empty($input['retry']); // if true, also retry failed slips
    $statusFilter = $allowRetry ? "s.status IN ('new','pending','failed')" : "s.status IN ('new','pending')";
    $where  = "$statusFilter AND s.image_path IS NOT NULL";
    $params = [];

    if (!empty($ids) && is_array($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where       .= " AND s.id IN ($placeholders)";
        $params       = array_map('intval', $ids);
    }

    $stmt = $db->prepare("
        SELECT
            s.id,
            s.line_user_id,
            s.line_account_id,
            s.image_path,
            s.amount,
            s.transfer_date,
            s.invoice_id,
            s.order_id,
            s.bdo_id
        FROM odoo_slip_uploads s
        WHERE $where
        ORDER BY s.uploaded_at ASC
        LIMIT 100
    ");
    $stmt->execute($params);
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($slips)) {
        echo json_encode([
            'success' => true,
            'message' => 'ไม่มีสลิปที่รอส่ง',
            'data'    => ['sent' => 0, 'failed' => 0, 'results' => []],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($dryRun) {
        echo json_encode([
            'success' => true,
            'message' => 'dry_run: พบ ' . count($slips) . ' สลิปที่จะส่ง',
            'data'    => ['slips' => $slips],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------------------------------------------------ //
    // 2. Group slips by line_account_id so we reuse OdooAPIClient per account
    // ------------------------------------------------------------------ //
    $byAccount = [];
    foreach ($slips as $slip) {
        $byAccount[$slip['line_account_id']][] = $slip;
    }

    $sent    = 0;
    $failed  = 0;
    $results = [];

    foreach ($byAccount as $accountId => $accountSlips) {
        // Initialise Odoo client for this LINE account
        try {
            $odoo = new OdooAPIClient($db, $accountId);
        } catch (Exception $e) {
            foreach ($accountSlips as $slip) {
                $failed++;
                $results[] = ['id' => $slip['id'], 'success' => false, 'error' => 'OdooAPIClient init: ' . $e->getMessage()];
                _markSlip($db, $slip['id'], 'failed', 'OdooAPIClient init failed: ' . $e->getMessage());
            }
            continue;
        }

        foreach ($accountSlips as $slip) {
            $slipId = (int) $slip['id'];
            try {
                // Read image file from disk
                $fullPath = __DIR__ . '/../' . ltrim($slip['image_path'], '/');
                if (!file_exists($fullPath)) {
                    throw new Exception('Image file not found: ' . $slip['image_path']);
                }
                $imageData = file_get_contents($fullPath);
                if (!$imageData || strlen($imageData) < 100) {
                    throw new Exception('Image file is empty or too small');
                }

                $base64 = base64_encode($imageData);

                $options = [];
                if ($slip['amount']        !== null) $options['amount']        = (float) $slip['amount'];
                if ($slip['transfer_date'] !== null) $options['transfer_date'] = $slip['transfer_date'];
                if ($slip['invoice_id']    !== null) $options['invoice_id']    = (int) $slip['invoice_id'];
                if ($slip['order_id']      !== null) $options['order_id']      = (int) $slip['order_id'];

                // Auto-populate bdo_id from odoo_bdo_context if not already set
                $bdoId = $slip['bdo_id'] !== null ? (int) $slip['bdo_id'] : null;
                if ($bdoId === null || $bdoId <= 0) {
                    try {
                        $ctxStmt = $db->prepare("
                            SELECT bdo_id FROM odoo_bdo_context
                            WHERE line_user_id = ? AND state = 'waiting'
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $ctxStmt->execute([$slip['line_user_id']]);
                        $ctxBdo = $ctxStmt->fetchColumn();
                        if ($ctxBdo) {
                            $bdoId = (int) $ctxBdo;
                        }
                    } catch (Exception $e) {
                        // Non-critical: continue without bdo_id
                        error_log('[send-slips-to-odoo] bdo_context lookup failed: ' . $e->getMessage());
                    }
                }
                if ($bdoId !== null && $bdoId > 0) {
                    $options['bdo_id'] = $bdoId;
                }

                // Use JSON-RPC uploadSlip (base64) — avoids CSRF rejection from multipart POST
                $odooResult = $odoo->uploadSlip($slip['line_user_id'], $base64, $options);

                // Extract fields from Odoo response (supports nested data.slip structure)
                $slipData = $odooResult['data']['slip'] ?? $odooResult['slip'] ?? $odooResult;
                $matchData = $odooResult['data']['match_result'] ?? $odooResult['match_result'] ?? [];

                $odooSlipId    = $slipData['id'] ?? $odooResult['id'] ?? $odooResult['slip_id'] ?? null;
                $odooOrderId   = $slipData['order_id'] ?? $odooResult['order_id'] ?? null;
                $odooInvoiceId = $slipData['invoice_id'] ?? $odooResult['invoice_id'] ?? null;

                // New fields from BDO integration
                $matchConfidence = $slipData['match_confidence'] ?? $matchData['confidence'] ?? null;
                $bdoName         = $slipData['bdo_name'] ?? null;
                $deliveryType    = $slipData['delivery_type'] ?? null;
                $bdoAmount       = $slipData['bdo_amount'] ?? null;
                $slipInboxId     = $slipData['slip_inbox_id'] ?? null;
                $slipInboxName   = $slipData['slip_inbox_name'] ?? null;

                // Mark as matched with extended fields
                _markSlip($db, $slipId, 'matched', 'Sent to Odoo: ' . json_encode($odooResult), $odooSlipId, $odooOrderId, $odooInvoiceId);
                _updateSlipExtendedFields($db, $slipId, $matchConfidence, $bdoName, $deliveryType, $bdoAmount, $slipInboxId, $slipInboxName);

                // Update odoo_bdo_orders.payment_status to 'matched' if bdo_id was used
                if ($bdoId !== null && $bdoId > 0) {
                    try {
                        $tblChk = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
                        if ($tblChk->rowCount() > 0) {
                            $db->prepare("UPDATE odoo_bdo_orders SET payment_status = 'matched', updated_at = NOW() WHERE bdo_id = ? AND payment_status IN ('pending','slip_uploaded')")->execute([(int)$bdoId]);
                        }
                    } catch (Exception $e2) {
                        error_log('[send-slips-to-odoo] bdo_orders matched update failed: ' . $e2->getMessage());
                    }
                }

                $sent++;
                $results[] = [
                    'id'      => $slipId,
                    'success' => true,
                    'odoo'    => $odooResult,
                    'odoo_slip_id'    => $odooSlipId,
                    'match_confidence' => $matchConfidence,
                ];

            } catch (Exception $e) {
                $failed++;
                $errDetail = $e->getMessage();
                $results[] = ['id' => $slipId, 'success' => false, 'error' => $errDetail];
                _markSlip($db, $slipId, 'failed', $errDetail);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "ส่งสำเร็จ $sent / " . count($slips) . " รายการ" . ($failed > 0 ? " (ล้มเหลว $failed)" : ''),
        'data'    => [
            'sent'    => $sent,
            'failed'  => $failed,
            'results' => $results,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

function _markSlip(PDO $db, int $id, string $status, string $reason, ?int $odooSlipId = null, $odooOrderId = null, $odooInvoiceId = null): void
{
    $db->prepare("
        UPDATE odoo_slip_uploads
        SET status = ?, match_reason = ?, matched_at = NOW(),
            odoo_slip_id  = COALESCE(?, odoo_slip_id),
            order_id      = COALESCE(?, order_id),
            invoice_id    = COALESCE(?, invoice_id)
        WHERE id = ?
    ")->execute([$status, mb_substr($reason, 0, 500), $odooSlipId, $odooOrderId ? (int)$odooOrderId : null, $odooInvoiceId ? (int)$odooInvoiceId : null, $id]);
}

function _updateSlipExtendedFields(PDO $db, int $id, ?string $matchConfidence, ?string $bdoName, ?string $deliveryType, $bdoAmount, $slipInboxId, ?string $slipInboxName): void
{
    try {
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET match_confidence = COALESCE(?, match_confidence),
                bdo_name         = COALESCE(?, bdo_name),
                delivery_type    = COALESCE(?, delivery_type),
                bdo_amount       = COALESCE(?, bdo_amount),
                slip_inbox_id    = COALESCE(?, slip_inbox_id),
                slip_inbox_name  = COALESCE(?, slip_inbox_name)
            WHERE id = ?
        ")->execute([
            $matchConfidence,
            $bdoName,
            $deliveryType,
            $bdoAmount !== null ? (float)$bdoAmount : null,
            $slipInboxId !== null ? (int)$slipInboxId : null,
            $slipInboxName,
            $id
        ]);
    } catch (Exception $e) {
        error_log('[send-slips-to-odoo] Extended fields update failed for slip ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Wraps uploadSlipMultipart and captures raw HTTP details on failure
 * so the caller can show a meaningful error message.
 */
function _uploadSlipWithDetail(
    OdooAPIClient $odoo,
    string $lineUserId,
    string $imageData,
    string $filename,
    string $mimeType,
    array  $options
): array {
    try {
        return $odoo->uploadSlipMultipart($lineUserId, $imageData, $filename, $mimeType, $options);
    } catch (Exception $e) {
        // Re-throw with original message — OdooAPIClient already captures HTTP code + body
        throw new Exception($e->getMessage());
    }
}
