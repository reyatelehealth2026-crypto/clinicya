<?php
/**
 * Odoo Webhook Handler
 * รับ webhook events จาก Odoo ERP และบันทึกลง Database
 * 
 * Endpoint: POST /api/odoo-webhook.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Odoo-Signature');

require_once __DIR__ . '/../config/database.php';

/**
 * Webhook Handler Class
 */
class OdooWebhookHandler {
    private $db;
    private $webhookSecret;
    
    // Order states mapping
    private $orderStates = [
        'draft' => ['display' => 'แบบร่าง', 'type' => 'draft'],
        'sent' => ['display' => 'ส่งใบเสนอราคา', 'type' => 'draft'],
        'validated' => ['display' => 'ตรวจสอบแล้ว', 'type' => 'progress'],
        'picker_assign' => ['display' => 'เตรียมจัดสินค้า', 'type' => 'progress'],
        'picking' => ['display' => 'กำลังจัดสินค้า', 'type' => 'progress'],
        'picked' => ['display' => 'จัดเสร็จแล้ว', 'type' => 'progress'],
        'packing' => ['display' => 'กำลังแพ็ค', 'type' => 'progress'],
        'packed' => ['display' => 'แพ็คเสร็จ', 'type' => 'progress'],
        'reserved' => ['display' => 'จองสินค้าแล้ว', 'type' => 'progress'],
        'awaiting_payment' => ['display' => 'รอชำระเงิน', 'type' => 'progress'],
        'paid' => ['display' => 'ชำระแล้ว', 'type' => 'progress'],
        'to_delivery' => ['display' => 'เตรียมส่ง', 'type' => 'progress'],
        'in_delivery' => ['display' => 'กำลังจัดส่ง', 'type' => 'progress'],
        'delivered' => ['display' => 'จัดส่งแล้ว', 'type' => 'done'],
        'cancel' => ['display' => 'ยกเลิก', 'type' => 'cancel'],
        'cancelled' => ['display' => 'ยกเลิก', 'type' => 'cancel']
    ];
    
    public function __construct($db) {
        $this->db = $db;
        $this->webhookSecret = $_ENV['ODOO_WEBHOOK_SECRET'] ?? 'your-secret-key';
    }
    
    /**
     * Verify webhook signature
     */
    public function verifySignature($payload, $signature, $timestamp) {
        // Check timestamp (prevent replay attacks - 5 min window)
        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            return false;
        }
        
        // Verify HMAC
        $expectedSig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        return hash_equals($expectedSig, str_replace('sha256=', '', $signature));
    }
    
    /**
     * Process webhook
     */
    public function process($eventType, $data) {
        // Log the webhook first
        $logId = $this->logWebhook($eventType, $data);
        
        try {
            switch ($eventType) {
                // Order Events
                case 'order.validated':
                case 'order.picker_assigned':
                case 'order.picking':
                case 'order.picked':
                case 'order.packing':
                case 'order.packed':
                case 'order.reserved':
                case 'order.awaiting_payment':
                case 'order.paid':
                case 'order.to_delivery':
                case 'order.in_delivery':
                case 'order.delivered':
                    $this->handleOrderEvent($eventType, $data);
                    break;
                    
                // BDO Events
                case 'bdo.confirmed':
                case 'bdo.done':
                case 'bdo.cancelled':
                    $this->handleBdoEvent($eventType, $data);
                    break;
                    
                // Delivery Events
                case 'delivery.departed':
                case 'delivery.completed':
                    $this->handleDeliveryEvent($eventType, $data);
                    break;
                    
                // Invoice Events
                case 'invoice.created':
                case 'invoice.paid':
                case 'invoice.overdue':
                    $this->handleInvoiceEvent($eventType, $data);
                    break;
                    
                // Payment Events
                case 'payment.confirmed':
                    $this->handlePaymentEvent($eventType, $data);
                    break;
                    
                default:
                    throw new Exception("Unknown event type: {$eventType}");
            }
            
            // Mark as processed
            $this->markProcessed($logId);
            
            return [
                'success' => true,
                'message' => "Event {$eventType} processed successfully",
                'log_id' => $logId
            ];
            
        } catch (Exception $e) {
            $this->markError($logId, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Log webhook
     */
    private function logWebhook($eventType, $data) {
        $stmt = $this->db->prepare("INSERT INTO odoo_webhook_logs 
            (event_type, event_id, odoo_order_id, odoo_invoice_id, odoo_payment_id, odoo_bdo_id, 
             line_user_id, partner_id, payload) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $eventId = $data['event_id'] ?? null;
        $orderId = $data['data']['order_id'] ?? $data['data']['sale_order']['id'] ?? null;
        $invoiceId = $data['data']['invoice']['invoice_id'] ?? $data['data']['invoice_id'] ?? null;
        $paymentId = $data['data']['payment_id'] ?? null;
        $bdoId = $data['data']['bdo_id'] ?? null;
        $lineUserId = $data['data']['customer']['line_user_id'] ?? null;
        $partnerId = $data['data']['customer']['id'] ?? null;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([$eventType, $eventId, $orderId, $invoiceId, $paymentId, $bdoId, 
                        $lineUserId, $partnerId, $payload]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Mark webhook as processed
     */
    private function markProcessed($logId) {
        $stmt = $this->db->prepare("UPDATE odoo_webhook_logs 
            SET processed = TRUE, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$logId]);
    }
    
    /**
     * Mark webhook with error
     */
    private function markError($logId, $errorMessage) {
        $stmt = $this->db->prepare("UPDATE odoo_webhook_logs 
            SET error_message = ? WHERE id = ?");
        $stmt->execute([$errorMessage, $logId]);
    }
    
    /**
     * Handle Order events
     */
    private function handleOrderEvent($eventType, $data) {
        $orderData = $data['data'] ?? $data;
        
        $orderId = $orderData['order_id'];
        $orderName = $orderData['order_name'];
        $newState = $orderData['new_state'] ?? $this->getStateFromEvent($eventType);
        $oldState = $orderData['old_state'] ?? null;
        
        // Upsert order
        $this->upsertOrder($orderData);
        
        // Add state history
        $this->addOrderStateHistory($orderId, $newState, $orderData);
        
        // Update LINE notification if needed
        $this->notifyLineUser($orderData);
    }
    
    /**
     * Handle BDO events
     */
    private function handleBdoEvent($eventType, $data) {
        $bdoData = $data['data'] ?? $data;
        
        // Create or update invoice if BDO confirmed
        if ($eventType === 'bdo.confirmed' && isset($bdoData['invoice'])) {
            $this->upsertInvoice($bdoData['invoice'], $bdoData);
        }
        
        // Update order state
        if (isset($bdoData['sale_order']['id'])) {
            $this->updateOrderState($bdoData['sale_order']['id'], 'awaiting_payment', $bdoData);
        }
    }
    
    /**
     * Handle Delivery events
     */
    private function handleDeliveryEvent($eventType, $data) {
        $deliveryData = $data['data'] ?? $data;
        
        foreach ($deliveryData['orders'] ?? [] as $order) {
            $state = ($eventType === 'delivery.departed') ? 'in_delivery' : 'delivered';
            $this->updateOrderState($order['order_id'], $state, $deliveryData);
        }
    }
    
    /**
     * Handle Invoice events
     */
    private function handleInvoiceEvent($eventType, $data) {
        $invoiceData = $data['data'] ?? $data;

        if ($eventType === 'invoice.paid') {
            $this->updateInvoiceState($invoiceData['invoice_id'], 'paid', $invoiceData);
            // If this invoice belongs to a BDO, recalculate BDO financial state
            $this->syncBdoStateFromInvoicePaid($invoiceData);
            // Award loyalty points to customer
            $this->awardInvoicePoints($invoiceData);
        } elseif ($eventType === 'invoice.created') {
            $this->upsertInvoice($invoiceData, null);
        }
    }

    /**
     * Award loyalty points when invoice.paid fires
     * อัตรา: 1,000 ฿ = 1 point (ปัดลง)
     * Deduplication: เช็ค points_transactions (reference_type='invoice', reference_id=invoice_id)
     */
    private function awardInvoicePoints($invoiceData) {
        $invoiceId     = (int) ($invoiceData['invoice_id'] ?? 0);
        $invoiceNumber = $invoiceData['invoice_number'] ?? "INV-{$invoiceId}";
        $orderName     = $invoiceData['order_name'] ?? '';
        $amountTotal   = (float) ($invoiceData['amount_total'] ?? 0);
        $lineUserId    = $invoiceData['customer']['line_user_id'] ?? null;
        $pdfPath       = $invoiceData['pdf_url'] ?? null;

        if (!$invoiceId || !$lineUserId || $lineUserId === 'false' || $lineUserId === false) {
            return;
        }

        // ── Deduplicate ─────────────────────────────────────────────────────
        $checkStmt = $this->db->prepare(
            "SELECT id FROM points_transactions WHERE reference_type = 'invoice' AND reference_id = ? LIMIT 1"
        );
        $checkStmt->execute([$invoiceId]);
        if ($checkStmt->fetch()) {
            error_log("[awardInvoicePoints] Already processed invoice {$invoiceId}, skipping");
            return;
        }

        // ── คำนวณแต้ม ────────────────────────────────────────────────────────
        $points = (int) floor($amountTotal / 1000);
        if ($points <= 0) {
            error_log("[awardInvoicePoints] Amount {$amountTotal} too low for points (invoice {$invoiceId})");
            return;
        }

        // ── หา LineUser ──────────────────────────────────────────────────────
        $userStmt = $this->db->prepare(
            "SELECT id, points, line_account_id, display_name, picture_url
             FROM users WHERE line_user_id = ? LIMIT 1"
        );
        $userStmt->execute([$lineUserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            error_log("[awardInvoicePoints] LineUser not found for line_user_id={$lineUserId}");
            return;
        }

        $userId        = (int) $user['id'];
        $lineAccountId = (int) ($user['line_account_id'] ?? 3);
        $newPoints     = (int) $user['points'] + $points;
        $displayName   = $user['display_name'] ?? 'ลูกค้า';
        $pictureUrl    = $user['picture_url'] ?? '';

        // ── อัพเดทแต้ม ───────────────────────────────────────────────────────
        $this->db->prepare(
            "UPDATE users SET
                points          = points + ?,
                available_points = available_points + ?,
                total_points    = total_points + ?,
                total_spent     = total_spent + ?,
                order_count     = order_count + 1
             WHERE id = ?"
        )->execute([$points, $points, $points, $amountTotal, $userId]);

        // ── บันทึก points_transactions ────────────────────────────────────────
        $desc = "ได้รับแต้มจากออเดอ " . ($orderName ?: $invoiceNumber)
              . " (" . number_format($amountTotal, 0, '.', ',') . " ฿ → {$points} point)";
        $this->db->prepare(
            "INSERT INTO points_transactions
                (user_id, points, type, balance_after, reference_type, reference_id, description, line_account_id, created_at)
             VALUES (?, ?, 'earn', ?, 'invoice', ?, ?, ?, NOW())"
        )->execute([$userId, $points, $newPoints, $invoiceId, $desc, $lineAccountId]);

        // ── System message ────────────────────────────────────────────────────
        $msgContent = "🧾 ชำระเงินใบแจ้งหนี้ {$invoiceNumber} เรียบร้อย\n"
                    . "🛒 ยอดซื้อ " . number_format($amountTotal, 0, '.', ',') . " ฿\n"
                    . "🎁 ได้รับ {$points} point\n"
                    . "แต้มคงเหลือ: " . number_format($newPoints, 0, '.', ',') . " แต้ม";
        $this->db->prepare(
            "INSERT INTO messages
                (user_id, line_account_id, direction, message_type, content, sent_by, is_read, created_at, updated_at)
             VALUES (?, ?, 'outgoing', 'text', ?, 'system_invoice_paid', 1, NOW(), NOW())"
        )->execute([$userId, $lineAccountId, $msgContent]);

        // ── สร้าง Flex Message ────────────────────────────────────────────────
        $odooBase = defined('ODOO_PRODUCTION_API_BASE_URL') ? ODOO_PRODUCTION_API_BASE_URL : 'https://erp.cnyrxapp.com';
        $pdfUrl   = $pdfPath ? rtrim($odooBase, '/') . $pdfPath : null;

        $defaultAvatar = 'https://profile.line-scdn.net/0hLhff-3aQE0dbHwxJqYdsEGdaHSosMRUPI3EPJ39PTCBze1BDZC1Zc3ofGiUiLlcUZHgIJS0cTSV-';

        $footerButtons = [
            [
                'type'   => 'button',
                'action' => [
                    'type'  => 'uri',
                    'label' => 'เข้าสู่เมนูแลกพอยท์',
                    'uri'   => 'https://liff.line.me/2008876929-yRgjH7fX',
                ],
                'style'  => 'primary',
                'color'  => '#0C665D',
                'height' => 'sm',
            ],
        ];
        if ($pdfUrl) {
            $footerButtons[] = [
                'type'   => 'button',
                'action' => [
                    'type'  => 'uri',
                    'label' => 'ดูใบแจ้งหนี้ PDF',
                    'uri'   => $pdfUrl,
                ],
                'style'  => 'secondary',
                'height' => 'sm',
                'margin' => 'sm',
            ];
        }

        $invoiceInfoContents = [
            [
                'type'     => 'box',
                'layout'   => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => 'ใบแจ้งหนี้', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                    ['type' => 'text', 'text' => $invoiceNumber, 'size' => 'sm', 'color' => '#1A1A1A', 'weight' => 'bold', 'align' => 'end'],
                ],
            ],
        ];
        if ($orderName) {
            $invoiceInfoContents[] = [
                'type'     => 'box',
                'layout'   => 'horizontal',
                'margin'   => 'xs',
                'contents' => [
                    ['type' => 'text', 'text' => 'ออเดอร์', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                    ['type' => 'text', 'text' => $orderName, 'size' => 'sm', 'color' => '#1A1A1A', 'align' => 'end'],
                ],
            ];
        }
        $invoiceInfoContents[] = [
            'type'     => 'box',
            'layout'   => 'horizontal',
            'margin'   => 'xs',
            'contents' => [
                ['type' => 'text', 'text' => 'ยอดชำระ', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                ['type' => 'text', 'text' => number_format($amountTotal, 2, '.', ',') . ' ฿', 'size' => 'sm', 'color' => '#1A1A1A', 'weight' => 'bold', 'align' => 'end'],
            ],
        ];

        $flexMessage = [
            'type'     => 'flex',
            'altText'  => "🧾 ได้รับแต้มจากออเดอ " . ($orderName ?: $invoiceNumber) . " — +{$points} point!",
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type'       => 'box',
                    'layout'     => 'vertical',
                    'paddingAll' => '20px',
                    'contents'   => [
                        // ── Header ─────────────────────────────────────────
                        [
                            'type'            => 'box',
                            'layout'          => 'horizontal',
                            'paddingAll'      => '14px',
                            'backgroundColor' => '#FFFFFF',
                            'cornerRadius'    => '12px',
                            'borderWidth'     => '2px',
                            'borderColor'     => '#0C665D',
                            'contents'        => [
                                [
                                    'type'         => 'box',
                                    'layout'       => 'vertical',
                                    'width'        => '56px',
                                    'height'       => '56px',
                                    'cornerRadius' => '28px',
                                    'contents'     => [[
                                        'type'        => 'image',
                                        'url'         => $pictureUrl ?: $defaultAvatar,
                                        'aspectMode'  => 'cover',
                                        'aspectRatio' => '1:1',
                                        'size'        => 'full',
                                    ]],
                                ],
                                [
                                    'type'            => 'box',
                                    'layout'          => 'vertical',
                                    'margin'          => 'md',
                                    'justifyContent'  => 'center',
                                    'contents'        => [
                                        ['type' => 'text', 'text' => $displayName, 'weight' => 'bold', 'size' => 'md', 'color' => '#1A1A1A', 'wrap' => true],
                                        ['type' => 'text', 'text' => 'ได้รับแต้มสะสม', 'size' => 'xs', 'color' => '#0C665D', 'margin' => 'xs'],
                                    ],
                                ],
                            ],
                        ],
                        // ── Invoice info ────────────────────────────────────
                        [
                            'type'            => 'box',
                            'layout'          => 'vertical',
                            'margin'          => 'xl',
                            'paddingAll'      => '12px',
                            'backgroundColor' => '#F5F5F5',
                            'cornerRadius'    => '10px',
                            'contents'        => $invoiceInfoContents,
                        ],
                        // ── แต้มที่ได้รับ ────────────────────────────────────
                        [
                            'type'            => 'box',
                            'layout'          => 'vertical',
                            'margin'          => 'lg',
                            'paddingAll'      => '20px',
                            'backgroundColor' => '#F0F9F8',
                            'cornerRadius'    => '16px',
                            'contents'        => [
                                ['type' => 'text', 'text' => 'ได้รับแต้มจากออเดอ', 'size' => 'sm', 'color' => '#666666', 'align' => 'center'],
                                ['type' => 'text', 'text' => "+{$points}", 'size' => '4xl', 'weight' => 'bold', 'color' => '#0C665D', 'align' => 'center'],
                                ['type' => 'text', 'text' => 'point', 'size' => 'md', 'color' => '#0C665D', 'weight' => 'bold', 'align' => 'center', 'margin' => 'xs'],
                            ],
                        ],
                        // ── อัตราแลก ─────────────────────────────────────────
                        ['type' => 'text', 'text' => '(อัตรา 1,000 ฿ = 1 point)', 'size' => 'xs', 'color' => '#999999', 'align' => 'center', 'margin' => 'sm'],
                        // ── แต้มคงเหลือ ───────────────────────────────────────
                        [
                            'type'            => 'box',
                            'layout'          => 'horizontal',
                            'margin'          => 'xl',
                            'paddingAll'      => '14px',
                            'backgroundColor' => '#FFFFFF',
                            'cornerRadius'    => '10px',
                            'borderWidth'     => '1px',
                            'borderColor'     => '#E5E5E5',
                            'contents'        => [
                                ['type' => 'text', 'text' => 'แต้มคงเหลือ', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                                ['type' => 'text', 'text' => number_format($newPoints, 0, '.', ',') . ' แต้ม', 'size' => 'md', 'color' => '#0C665D', 'weight' => 'bold', 'align' => 'end'],
                            ],
                        ],
                        // ── Thank you ─────────────────────────────────────────
                        ['type' => 'text', 'text' => "ได้รับชำระเงินใบแจ้งหนี้ {$invoiceNumber} เรียบร้อยแล้ว ขอบคุณที่ใช้บริการ", 'size' => 'xs', 'color' => '#999999', 'margin' => 'xl', 'wrap' => true, 'align' => 'center'],
                    ],
                ],
                'footer' => [
                    'type'       => 'box',
                    'layout'     => 'vertical',
                    'paddingAll' => '16px',
                    'contents'   => $footerButtons,
                ],
            ],
        ];

        // ── ส่งผ่าน liff-bridge ───────────────────────────────────────────────
        $this->sendFlexViaBridge($lineUserId, $lineAccountId, $flexMessage);

        error_log("[awardInvoicePoints] ✅ invoice={$invoiceNumber} user={$lineUserId} +{$points}pt → {$newPoints}pt");
    }

    /**
     * ส่ง Flex Message ผ่าน liff-bridge.php via HTTP
     */
    private function sendFlexViaBridge($lineUserId, $lineAccountId, $flexMessage) {
        $bridgeUrl = defined('APP_URL') ? APP_URL . '/api/liff-bridge.php' : 'https://cny.re-ya.com/api/liff-bridge.php';
        $secret    = $_ENV['INTERNAL_API_SECRET'] ?? '';

        $payload = json_encode([
            'action'         => 'send_flex_message',
            'line_user_id'   => $lineUserId,
            'lineUserId'     => $lineUserId,
            'line_account_id' => $lineAccountId,
            'lineAccountId'  => $lineAccountId,
            'data'           => ['flexMessage' => $flexMessage],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[sendFlexViaBridge] HTTP {$httpCode}: {$response}");
        }
    }

    /**
     * When invoice.paid fires, find any BDO that contains this invoice
     * and update its amount_net_to_pay / payment_state accordingly.
     */
    private function syncBdoStateFromInvoicePaid($invoiceData) {
        $invoiceNumber = $invoiceData['invoice_number'] ?? $invoiceData['name'] ?? null;
        $invoiceId     = $invoiceData['invoice_id'] ?? null;
        $paidAmount    = (float) ($invoiceData['amount_total'] ?? $invoiceData['amount_residual'] ?? 0);

        if (!$invoiceNumber && !$invoiceId) return;

        try {
            // Find BDOs that reference this invoice in selected_invoices_json
            $whereClauses = [];
            $params = [];
            if ($invoiceNumber) {
                $whereClauses[] = 'selected_invoices_json LIKE ?';
                $params[] = '%' . $invoiceNumber . '%';
            }
            if ($invoiceId) {
                $whereClauses[] = 'selected_invoices_json LIKE ?';
                $params[] = '%"invoice_id":' . (int)$invoiceId . '%';
            }

            if (empty($whereClauses)) return;

            $sql = 'SELECT DISTINCT bdo_id FROM odoo_bdo_context WHERE (' . implode(' OR ', $whereClauses) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $bdoIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($bdoIds as $bdoId) {
                $bdoId = (int) $bdoId;

                // Subtract paid invoice amount from stored net_to_pay
                $bdoStmt = $this->db->prepare('
                    SELECT amount_net_to_pay, financial_summary_json
                    FROM odoo_bdos WHERE bdo_id = ? LIMIT 1
                ');
                $bdoStmt->execute([$bdoId]);
                $bdoRow = $bdoStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$bdoRow) continue;

                $currentNet = (float) ($bdoRow['amount_net_to_pay'] ?? 0);
                $newNet = max(0, $currentNet - $paidAmount);

                // Update amount_net_to_pay; if fully paid set payment_state = in_payment
                $newPaymentState = ($newNet <= 0) ? 'in_payment' : null;

                if ($newPaymentState) {
                    $this->db->prepare('
                        UPDATE odoo_bdos
                        SET amount_net_to_pay = ?, payment_state = ?, updated_at = NOW()
                        WHERE bdo_id = ?
                    ')->execute([$newNet, $newPaymentState, $bdoId]);
                } else {
                    $this->db->prepare('
                        UPDATE odoo_bdos
                        SET amount_net_to_pay = ?, updated_at = NOW()
                        WHERE bdo_id = ?
                    ')->execute([$newNet, $bdoId]);
                }
            }
        } catch (\Exception $e) {
            error_log('[syncBdoStateFromInvoicePaid] ' . $e->getMessage());
        }
    }
    
    /**
     * Handle Payment events
     */
    private function handlePaymentEvent($eventType, $data) {
        $paymentData = $data['data'] ?? $data;
        
        $this->upsertPayment($paymentData);
        
        // Update invoice state + propagate to linked BDO
        if (isset($paymentData['invoice_id'])) {
            $this->updateInvoiceState($paymentData['invoice_id'], 'paid', $paymentData);
            $this->syncBdoStateFromInvoicePaid($paymentData);
        }
        
        // Update order state
        if (!empty($paymentData['related_orders'])) {
            foreach ($paymentData['related_orders'] as $orderName) {
                $orderId = $this->getOrderIdByName($orderName);
                if ($orderId) {
                    $this->updateOrderState($orderId, 'paid', $paymentData);
                }
            }
        }
    }
    
    /**
     * Upsert Order
     */
    private function upsertOrder($orderData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_orders 
            (odoo_order_id, order_name, order_ref, partner_id, partner_code, partner_name, line_user_id,
             state, state_display, amount_total, amount_untaxed, amount_tax,
             payment_method, payment_state, marketplace, marketplace_shop_name,
             order_date, confirmed_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            amount_total = VALUES(amount_total),
            payment_state = VALUES(payment_state),
            confirmed_at = COALESCE(confirmed_at, VALUES(confirmed_at)),
            updated_at = NOW()");
        
        $state = $orderData['new_state'] ?? $orderData['state'] ?? 'draft';
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'draft'];
        
        $confirmedAt = null;
        if ($state === 'validated' || in_array($state, ['picker_assign', 'picking', 'picked', 'packing', 'packed'])) {
            $confirmedAt = date('Y-m-d H:i:s');
        }
        
        $stmt->execute([
            $orderData['order_id'],
            $orderData['order_name'],
            $orderData['order_ref'] ?? null,
            $orderData['customer']['id'] ?? null,
            $orderData['customer']['partner_code'] ?? null,
            $orderData['customer']['name'] ?? null,
            $orderData['customer']['line_user_id'] ?? null,
            $state,
            $stateInfo['display'],
            $orderData['amount_total'] ?? 0,
            $orderData['amount_untaxed'] ?? 0,
            $orderData['amount_tax'] ?? 0,
            $orderData['payment_method'] ?? null,
            $orderData['payment_state'] ?? 'not_paid',
            $orderData['marketplace'] ?? null,
            $orderData['marketplace_shop_name'] ?? null,
            $orderData['order_date'] ?? date('Y-m-d'),
            $confirmedAt
        ]);
    }
    
    /**
     * Upsert Invoice
     */
    private function upsertInvoice($invoiceData, $parentData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_invoices 
            (odoo_invoice_id, invoice_number, odoo_order_id, order_name,
             partner_id, partner_name, line_user_id,
             state, state_display, amount_total, amount_untaxed, amount_tax, amount_residual,
             invoice_date, due_date, pdf_url,
             payment_method, promptpay_qr_data, bank_account, bank_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            amount_residual = VALUES(amount_residual),
            pdf_url = VALUES(pdf_url),
            updated_at = NOW()");
        
        $payment = $parentData['payment'] ?? [];
        $promptpay = $payment['promptpay'] ?? [];
        $bankTransfer = $payment['bank_transfer'] ?? [];
        
        $stmt->execute([
            $invoiceData['invoice_id'],
            $invoiceData['invoice_number'],
            $parentData['sale_order']['id'] ?? null,
            $parentData['sale_order']['name'] ?? null,
            $parentData['customer']['id'] ?? null,
            $parentData['customer']['name'] ?? null,
            $parentData['customer']['line_user_id'] ?? null,
            $invoiceData['state'] ?? 'open',
            $invoiceData['state'] === 'open' ? 'ค้างชำระ' : ($invoiceData['state'] === 'paid' ? 'ชำระแล้ว' : $invoiceData['state']),
            $invoiceData['amount_total'] ?? 0,
            $invoiceData['amount_untaxed'] ?? 0,
            $invoiceData['amount_tax'] ?? 0,
            $invoiceData['amount_residual'] ?? 0,
            $invoiceData['invoice_date'] ?? date('Y-m-d'),
            $invoiceData['due_date'] ?? null,
            $invoiceData['pdf_url'] ?? null,
            $payment['method'] ?? null,
            $promptpay['qr_data'] ?? null,
            $bankTransfer['account_number'] ?? null,
            $bankTransfer['bank_name'] ?? null
        ]);
    }
    
    /**
     * Upsert Payment
     */
    private function upsertPayment($paymentData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_payments 
            (odoo_payment_id, payment_name, odoo_invoice_id, invoice_number,
             odoo_order_id, order_name, partner_id, partner_name, line_user_id,
             state, state_display, amount, currency, method, method_display,
             reference, slip_image_url, bank_name, bank_account, payment_date, posted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            posted_at = COALESCE(posted_at, VALUES(posted_at)),
            updated_at = NOW()");
        
        $payment = $paymentData['payment'] ?? $paymentData;
        $methodDisplay = [
            'cash' => 'เงินสด',
            'bank_transfer' => 'โอนเงิน',
            'promptpay' => 'พร้อมเพย์',
            'cheque' => 'เช็ค',
            'credit_card' => 'บัตรเครดิต'
        ][$payment['method'] ?? 'cash'] ?? $payment['method'];
        
        $postedAt = ($paymentData['new_state'] ?? '') === 'post' ? date('Y-m-d H:i:s') : null;
        
        $stmt->execute([
            $paymentData['payment_id'],
            $paymentData['payment_name'],
            $paymentData['invoice_id'] ?? null,
            null, // invoice_number - need to lookup
            null, // order_id
            null, // order_name
            $paymentData['customer']['id'] ?? null,
            $paymentData['customer']['name'] ?? null,
            $paymentData['customer']['line_user_id'] ?? null,
            $paymentData['new_state'] ?? 'draft',
            $paymentData['new_state'] === 'post' ? 'ผ่านรายการ' : 'แบบร่าง',
            $payment['amount'] ?? 0,
            $payment['currency'] ?? 'THB',
            $payment['method'] ?? 'cash',
            $methodDisplay,
            $payment['reference'] ?? null,
            $payment['slip_image_url'] ?? null,
            $payment['bank_name'] ?? null,
            $payment['bank_account'] ?? null,
            $payment['date'] ?? date('Y-m-d'),
            $postedAt
        ]);
    }
    
    /**
     * Update Order State
     */
    private function updateOrderState($orderId, $state, $data) {
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'progress'];
        
        $stmt = $this->db->prepare("UPDATE odoo_orders 
            SET state = ?, state_display = ?, updated_at = NOW() 
            WHERE odoo_order_id = ?");
        $stmt->execute([$state, $stateInfo['display'], $orderId]);
        
        // Add state history
        $this->addOrderStateHistory($orderId, $state, $data);
    }
    
    /**
     * Update Invoice State
     */
    private function updateInvoiceState($invoiceId, $state, $data) {
        $paidAt = ($state === 'paid') ? date('Y-m-d H:i:s') : null;
        $amountResidual = ($state === 'paid') ? 0 : ($data['amount_residual'] ?? null);
        
        $stmt = $this->db->prepare("UPDATE odoo_invoices 
            SET state = ?, state_display = ?, amount_residual = ?, paid_at = COALESCE(paid_at, ?), updated_at = NOW() 
            WHERE odoo_invoice_id = ?");
        $stmt->execute([
            $state, 
            $state === 'paid' ? 'ชำระแล้ว' : ($state === 'open' ? 'ค้างชำระ' : $state),
            $amountResidual,
            $paidAt,
            $invoiceId
        ]);
    }
    
    /**
     * Add Order State History
     */
    private function addOrderStateHistory($orderId, $state, $data) {
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'progress'];
        
        $stmt = $this->db->prepare("INSERT INTO odoo_order_states 
            (odoo_order_id, state, state_display, state_type, assignee_id, assignee_name, assignee_type, notes, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $assignee = $data['picker'] ?? $data['driver'] ?? null;
        
        $stmt->execute([
            $orderId,
            $state,
            $stateInfo['display'],
            $stateInfo['type'],
            $assignee['id'] ?? null,
            $assignee['name'] ?? null,
            isset($data['picker']) ? 'picker' : (isset($data['driver']) ? 'driver' : null),
            $data['note'] ?? null
        ]);
    }
    
    /**
     * Get Order ID by Name
     */
    private function getOrderIdByName($orderName) {
        $stmt = $this->db->prepare("SELECT odoo_order_id FROM odoo_orders WHERE order_name = ? LIMIT 1");
        $stmt->execute([$orderName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['odoo_order_id'] : null;
    }
    
    /**
     * Get state from event type
     */
    private function getStateFromEvent($eventType) {
        $mapping = [
            'order.validated' => 'validated',
            'order.picker_assigned' => 'picker_assign',
            'order.picking' => 'picking',
            'order.picked' => 'picked',
            'order.packing' => 'packing',
            'order.packed' => 'packed',
            'order.reserved' => 'reserved',
            'order.awaiting_payment' => 'awaiting_payment',
            'order.paid' => 'paid',
            'order.to_delivery' => 'to_delivery',
            'order.in_delivery' => 'in_delivery',
            'order.delivered' => 'delivered'
        ];
        return $mapping[$eventType] ?? 'draft';
    }
    
    /**
     * Notify LINE User
     */
    private function notifyLineUser($orderData) {
        // This will be implemented to send notification via LINE API
        // For now, just log that notification should be sent
        $lineUserId = $orderData['customer']['line_user_id'] ?? null;
        if ($lineUserId) {
            // TODO: Send LINE message
            error_log("Should notify LINE user: {$lineUserId} about order {$orderData['order_name']}");
        }
    }
}

// =====================================================
// Main Handler
// =====================================================

try {
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
    // Get headers
    $signature = $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_ODOO_TIMESTAMP'] ?? '';
    $eventType = $_SERVER['HTTP_X_ODOO_EVENT'] ?? '';
    
    // Get payload
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }
    
    // If no event type in header, try to get from body
    if (empty($eventType) && isset($data['event'])) {
        $eventType = $data['event'];
    }
    
    if (empty($eventType)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing event type']);
        exit;
    }
    
    // Initialize database
    $db = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", 
                  $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Process webhook
    $handler = new OdooWebhookHandler($db);
    
    // Optional: Verify signature (uncomment when ready)
    // if (!$handler->verifySignature($payload, $signature, $timestamp)) {
    //     http_response_code(401);
    //     echo json_encode(['success' => false, 'error' => 'Invalid signature']);
    //     exit;
    // }
    
    $result = $handler->process($eventType, $data);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
