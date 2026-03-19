<?php
/**
 * Odoo Slip Upload API
 * 
 * Handles payment slip uploads from LINE users.
 * Downloads image from LINE, converts to Base64, uploads to Odoo,
 * and sends confirmation message back to user.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

// Debugging 500 errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit;
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/BdoSlipContract.php';
// OdooAPIClient not needed — saving locally first

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);

    // Required parameters
    $lineUserId = $input['line_user_id'] ?? null;
    $messageId = $input['message_id'] ?? null;
    $imageBase64 = $input['image_base64'] ?? null; // Support direct upload
    $imageUrl = $input['image_url'] ?? null; // Support URL download (from Next.js admin)
    $lineAccountId = $input['line_account_id'] ?? null;

    // Optional parameters
    $bdoId = $input['bdo_id'] ?? null;
    $invoiceId = $input['invoice_id'] ?? null;
    $orderId = $input['order_id'] ?? null;
    $amount = $input['amount'] ?? null;
    $transferDate = $input['transfer_date'] ?? null;
    $skipLineNotify = $input['skip_line_notify'] ?? false; // Skip LINE confirmation (admin flow)

    // Validate required parameters
    if (!$lineUserId) {
        throw new Exception('Missing line_user_id');
    }

    if (!$messageId && !$imageBase64 && !$imageUrl) {
        throw new Exception('Missing message_id, image_base64, or image_url');
    }

    // Get LINE account info
    if (!$lineAccountId) {
        // Find LINE account for this user
        $stmt = $db->prepare("
            SELECT line_account_id 
            FROM users 
            WHERE line_user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('User not found');
        }

        $lineAccountId = $user['line_account_id'];
    }

    // Get LINE access token (needed only if message_id is used for download)
    $lineAPI = null;
    if ($messageId && !$imageBase64) {
        $stmt = $db->prepare("
            SELECT channel_access_token 
            FROM line_accounts 
            WHERE id = ?
        ");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception('LINE account not found');
        }

        // Initialize LINE API
        $lineAPI = new LineAPI($account['channel_access_token']);
    }

    // ========================================================================
    // 13.2.2 Download image from URL / LINE Content API / Use Base64
    // ========================================================================
    $imageData = null;
    $imageMimeType = 'image/jpeg';

    if ($imageUrl) {
        // Download from URL (admin flow — image already saved on server)
        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $imageMimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
        curl_close($ch);

        if (!$imageData || $httpCode !== 200 || strlen($imageData) < 100) {
            // Fallback: if URL is expired/broken, try downloading directly from LINE using message_id
            if ($messageId) {
                if (!$lineAPI) {
                    $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
                    $stmt->execute([$lineAccountId]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$account) {
                        throw new Exception('LINE account not found for fallback download');
                    }
                    $lineAPI = new LineAPI($account['channel_access_token']);
                }

                $imageData = $lineAPI->getMessageContent($messageId);
                if (!$imageData || strlen($imageData) < 100) {
                    throw new Exception('Failed to download image from URL and LINE fallback');
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $imageMimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
            } else {
                throw new Exception('Failed to download image from URL: ' . $imageUrl);
            }
        }
    } elseif ($imageBase64) {
        // Use provided Base64
        $imageData = base64_decode($imageBase64);
        if (!$imageData || strlen($imageData) < 100) {
            throw new Exception('Invalid base64 image data');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $imageMimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
    } else {
        // Download from LINE
        $imageData = $lineAPI->getMessageContent($messageId);

        if (!$imageData || strlen($imageData) < 100) {
            throw new Exception('Failed to download image from LINE');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $imageMimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
    }

    // ========================================================================
    // Save slip image locally to uploads/slips/
    // ========================================================================
    $ext = 'jpg';
    if ($imageMimeType === 'image/png') $ext = 'png';
    elseif ($imageMimeType === 'application/pdf') $ext = 'pdf';
    $filename = 'slip_' . $lineUserId . '_' . time() . '.' . $ext;

    $slipsDir = __DIR__ . '/../uploads/slips';
    if (!is_dir($slipsDir)) {
        mkdir($slipsDir, 0755, true);
    }
    $savePath = $slipsDir . '/' . $filename;
    $bytesWritten = file_put_contents($savePath, $imageData);
    if ($bytesWritten === false) {
        throw new Exception('Failed to save slip image to disk');
    }

    $relativeImagePath = 'uploads/slips/' . $filename;

    // ========================================================================
    // Auto-lookup odoo_partner_id from line_user_id → odoo_line_users
    // ========================================================================
    $odooPartnerId = null;
    try {
        $partnerStmt = $db->prepare("
            SELECT odoo_partner_id FROM odoo_line_users 
            WHERE line_user_id = ? LIMIT 1
        ");
        $partnerStmt->execute([$lineUserId]);
        $partnerRow = $partnerStmt->fetch(PDO::FETCH_ASSOC);
        if ($partnerRow) {
            $odooPartnerId = $partnerRow['odoo_partner_id'];
        }
    } catch (Exception $e) {
        // odoo_line_users may not exist yet — skip silently
        error_log('[odoo-slip-upload] partner lookup failed: ' . $e->getMessage());
    }

    // ========================================================================
    // Save record to odoo_slip_uploads table (local — not sent to Odoo yet)
    // ========================================================================
    $status = BdoSlipContract::SLIP_STATUS_NEW;
    $uploadedBy = $input['uploaded_by'] ?? null;
    $inputMessageId = $input['message_id_ref'] ?? null; // message table ID

    // SlipMate verification data (optional — sent when admin verified slip before saving)
    $slipVerified = isset($input['slip_verified']) ? ($input['slip_verified'] ? 1 : 0) : null;
    $slipVerifyRef = $input['slip_verify_ref'] ?? null;
    $slipVerifyAmount = $input['slip_verify_amount'] ?? null;
    $slipVerifyData = isset($input['slip_verify_data']) ? json_encode($input['slip_verify_data'], JSON_UNESCAPED_UNICODE) : null;
    $slipVerifiedAt = $slipVerified !== null ? date('Y-m-d H:i:s') : null;

    // Check if slip verification columns exist (backward compatibility)
    $hasVerificationColumns = false;
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM odoo_slip_uploads LIKE 'slip_verified'");
        $hasVerificationColumns = $colCheck->rowCount() > 0;
    } catch (Exception $e) {
        error_log('[odoo-slip-upload] Column check failed: ' . $e->getMessage());
    }

    if ($hasVerificationColumns) {
        // New schema with verification columns
        $stmt = $db->prepare("
            INSERT INTO odoo_slip_uploads 
            (line_account_id, line_user_id, odoo_partner_id, bdo_id, invoice_id, order_id, 
             amount, transfer_date, image_path, image_url, uploaded_by, message_id,
             slip_verified, slip_verify_ref, slip_verify_amount, slip_verify_data, slip_verified_at,
             status, match_reason, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW())
        ");
        $stmt->execute([
            $lineAccountId,
            $lineUserId,
            $odooPartnerId,
            $bdoId ?? null,
            $invoiceId,
            $orderId ?? null,
            $amount,
            $transferDate,
            $relativeImagePath,
            $imageUrl,
            $uploadedBy,
            $inputMessageId,
            $slipVerified,
            $slipVerifyRef,
            $slipVerifyAmount !== null ? (float) $slipVerifyAmount : null,
            $slipVerifyData,
            $slipVerifiedAt,
            $status,
        ]);
    } else {
        // Old schema without verification columns (fallback)
        $stmt = $db->prepare("
            INSERT INTO odoo_slip_uploads 
            (line_account_id, line_user_id, odoo_partner_id, bdo_id, invoice_id, order_id, 
             amount, transfer_date, image_path, image_url, uploaded_by, message_id,
             status, match_reason, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW())
        ");
        $stmt->execute([
            $lineAccountId,
            $lineUserId,
            $odooPartnerId,
            $bdoId ?? null,
            $invoiceId,
            $orderId ?? null,
            $amount,
            $transferDate,
            $relativeImagePath,
            $imageUrl,
            $uploadedBy,
            $inputMessageId,
            $status,
        ]);
    }
    $slipDbId = $db->lastInsertId();

    // ========================================================================
    // Update odoo_bdo_orders.payment_status when slip is linked to a BDO
    // ========================================================================
    if ($bdoId) {
        try {
            $tblCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
            if ($tblCheck->rowCount() > 0) {
                $updBdo = $db->prepare("
                    UPDATE odoo_bdo_orders 
                    SET payment_status = 'slip_uploaded', slip_upload_id = ?, updated_at = NOW()
                    WHERE bdo_id = ? AND payment_status = 'pending'
                ");
                $updBdo->execute([(int) $slipDbId, (int) $bdoId]);
            }
        } catch (Exception $e) {
            error_log('[odoo-slip-upload] bdo_orders status update failed: ' . $e->getMessage());
        }
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกสลิปเรียบร้อยแล้ว',
        'data' => [
            'id' => (int) $slipDbId,
            'image_path' => $relativeImagePath,
            'status' => $status,
            'line_user_id' => $lineUserId,
            'amount' => $amount,
            'transfer_date' => $transferDate
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
