<?php
/**
 * Odoo Sync Service
 * 
 * Syncs Odoo webhook data to dedicated tables (odoo_orders, odoo_invoices, odoo_bdos)
 * for fast querying and real-time updates.
 * 
 * @version 1.0.0
 * @created 2026-03-02
 */

class OdooSyncService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Process webhook and sync to appropriate table based on event type
     * 
     * @param array $payload Webhook payload
     * @param string $eventType Event type (e.g. order.confirmed, invoice.posted, bdo.confirmed)
     * @param int $webhookId Webhook log ID for tracking
     * @return bool Success status
     */
    public function syncWebhook($payload, $eventType, $webhookId = null)
    {
        if (!is_array($payload) || empty($eventType)) {
            error_log("[OdooSyncService] Invalid input - payload is not array or eventType is empty");
            return false;
        }

        try {
            // Route to appropriate sync method based on event type
            if (str_starts_with($eventType, 'order.') || str_starts_with($eventType, 'sale.') || 
                str_starts_with($eventType, 'delivery.') || str_starts_with($eventType, 'payment.')) {
                return $this->syncOrder($payload, $eventType, $webhookId);
            } elseif (str_starts_with($eventType, 'invoice.')) {
                return $this->syncInvoice($payload, $eventType, $webhookId);
            } elseif (str_starts_with($eventType, 'bdo.')) {
                return $this->syncBdo($payload, $eventType, $webhookId);
            }

            error_log("[OdooSyncService] Unhandled event type: {$eventType}");
            return false;
        } catch (Exception $e) {
            error_log("[OdooSyncService] Sync failed for event {$eventType}: " . $e->getMessage());
            throw $e; // Re-throw so caller can display the real error
        }
    }

    /**
     * Sync order data to odoo_orders table
     */
    private function syncOrder($payload, $eventType, $webhookId)
    {
        // Try multiple field names for order_id
        $orderId = (int) ($payload['order_id'] ?? $payload['id'] ?? 0);
        
        // If still no order_id, try extracting from order object
        if (!$orderId && isset($payload['order']['id'])) {
            $orderId = (int) $payload['order']['id'];
        }
        
        if (!$orderId) {
            error_log("[OdooSyncService] syncOrder: No order_id found in payload for event {$eventType}");
            return false;
        }

        $customer = $payload['customer'] ?? [];
        $salesperson = $payload['salesperson'] ?? [];

        // Extract partner_id - customer.id IS the partner_id in Odoo webhooks
        $partnerId = null;
        if (isset($customer['id']) && $customer['id']) {
            $partnerId = (int) $customer['id'];
        } elseif (isset($customer['partner_id']) && $customer['partner_id']) {
            $partnerId = (int) $customer['partner_id'];
        }

        // Determine payment and delivery status from event type
        $isPaid = in_array($eventType, ['order.paid', 'invoice.paid', 'payment.confirmed', 'payment.received']) ? 1 : 0;
        $isDelivered = in_array($eventType, ['delivery.done', 'delivery.completed', 'order.delivered']) ? 1 : 0;
        
        $paymentStatus = $this->extractPaymentStatus($eventType, $payload);
        $deliveryStatus = $this->extractDeliveryStatus($eventType, $payload);

        $orderName = $payload['order_name'] ?? $payload['name'] ?? $payload['order_ref'] ?? ('SO-' . $orderId);

        // Sanitize: line_user_id may be boolean false from Odoo webhook - convert to null
        $lineUserId = $customer['line_user_id'] ?? null;
        if ($lineUserId === false || $lineUserId === 'false' || $lineUserId === '') {
            $lineUserId = null;
        }

        $data = [
            'order_id' => $orderId,
            'order_name' => $orderName,
            'partner_id' => $partnerId,
            'customer_ref' => $customer['ref'] ?? null,
            'line_user_id' => $lineUserId,
            'salesperson_id' => (int) ($salesperson['id'] ?? 0) ?: null,
            'salesperson_name' => $salesperson['name'] ?? null,
            'state' => $payload['state'] ?? $payload['new_state'] ?? null,
            'state_display' => $payload['state_display'] ?? $payload['new_state_display'] ?? null,
            'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
            'amount_tax' => isset($payload['amount_tax']) ? (float) $payload['amount_tax'] : null,
            'amount_untaxed' => isset($payload['amount_untaxed']) ? (float) $payload['amount_untaxed'] : null,
            'currency' => $payload['currency'] ?? 'THB',
            'date_order' => $payload['date_order'] ?? $payload['order_date'] ?? null,
            'expected_delivery' => $payload['expected_delivery'] ?? null,
            'payment_date' => $payload['payment_date'] ?? $payload['date_payment'] ?? null,
            'payment_status' => $paymentStatus,
            'delivery_status' => $deliveryStatus,
            'is_paid' => $isPaid,
            'is_delivered' => $isDelivered,
            'items_count' => (int) ($payload['items_count'] ?? 0),
            'latest_event' => $eventType,
            'webhook_id' => $webhookId,
            'synced_at' => date('Y-m-d H:i:s'),
        ];

        return $this->upsertOrder($data);
    }

    /**
     * Sync invoice data to odoo_invoices table
     */
    private function syncInvoice($payload, $eventType, $webhookId)
    {
        // Try multiple field names for invoice_id
        $invoiceId = (int) ($payload['invoice_id'] ?? $payload['id'] ?? 0);
        
        // If still no invoice_id, try extracting from invoice object
        if (!$invoiceId && isset($payload['invoice']['id'])) {
            $invoiceId = (int) $payload['invoice']['id'];
        }
        
        if (!$invoiceId) {
            error_log("[OdooSyncService] syncInvoice: No invoice_id found in payload for event {$eventType}");
            return false;
        }

        $customer = $payload['customer'] ?? [];
        $salesperson = $payload['salesperson'] ?? [];

        // Extract partner_id - customer.id IS the partner_id in Odoo webhooks
        $partnerId = null;
        if (isset($customer['id']) && $customer['id']) {
            $partnerId = (int) $customer['id'];
        } elseif (isset($customer['partner_id']) && $customer['partner_id']) {
            $partnerId = (int) $customer['partner_id'];
        }

        $isPaid = in_array($eventType, ['invoice.paid', 'payment.confirmed', 'payment.received']) ? 1 : 0;
        $state = $payload['state'] ?? $payload['invoice_state'] ?? null;
        
        // If invoice is paid, force state to 'paid' and residual to 0
        if ($isPaid) {
            $state = $state ?: 'paid';
            if (!isset($payload['amount_residual'])) {
                $payload['amount_residual'] = 0;
            }
        }
        
        // Check if overdue
        $dueDate = $payload['due_date'] ?? $payload['invoice_date_due'] ?? null;
        $isOverdue = 0;
        if ($dueDate && !$isPaid) {
            $isOverdue = (strtotime($dueDate) < time()) ? 1 : 0;
        }

        $invoiceNumber = $payload['invoice_number'] ?? $payload['number'] ?? $payload['name'] ?? ('INV-' . $invoiceId);

        // Sanitize: line_user_id may be boolean false from Odoo webhook
        $lineUserId = $customer['line_user_id'] ?? null;
        if ($lineUserId === false || $lineUserId === 'false' || $lineUserId === '') {
            $lineUserId = null;
        }

        $data = [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'order_id' => (int) ($payload['order_id'] ?? 0) ?: null,
            'order_name' => $payload['order_name'] ?? $payload['order_ref'] ?? null,
            'partner_id' => $partnerId,
            'customer_ref' => $customer['ref'] ?? null,
            'line_user_id' => $lineUserId,
            'salesperson_id' => (int) ($salesperson['id'] ?? 0) ?: null,
            'salesperson_name' => $salesperson['name'] ?? null,
            'state' => $state,
            'invoice_state' => $state,
            'payment_state' => $payload['payment_state'] ?? ($isPaid ? 'paid' : 'not_paid'),
            'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
            'amount_tax' => isset($payload['amount_tax']) ? (float) $payload['amount_tax'] : null,
            'amount_untaxed' => isset($payload['amount_untaxed']) ? (float) $payload['amount_untaxed'] : null,
            'amount_residual' => isset($payload['amount_residual']) ? (float) $payload['amount_residual'] : null,
            'currency' => $payload['currency'] ?? 'THB',
            'invoice_date' => $payload['invoice_date'] ?? null,
            'due_date' => $dueDate,
            'payment_date' => $payload['payment_date'] ?? null,
            'payment_term' => $payload['payment_term'] ?? null,
            'payment_method' => $payload['payment_method'] ?? null,
            'is_paid' => $isPaid,
            'is_overdue' => $isOverdue,
            'pdf_url' => $payload['pdf_url'] ?? null,
            'latest_event' => $eventType,
            'webhook_id' => $webhookId,
            'synced_at' => date('Y-m-d H:i:s'),
        ];

        $result = $this->upsertInvoice($data);

        // When invoice is paid, also update the corresponding order's is_paid flag
        if ($isPaid && !empty($data['order_id'])) {
            try {
                $stmt = $this->db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
                $stmt->execute([$data['order_id']]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $upd = $this->db->prepare("UPDATE odoo_orders SET is_paid = 1, payment_status = 'paid', latest_event = CASE WHEN latest_event NOT LIKE 'delivery.%' AND latest_event NOT LIKE 'order.delivered%' THEN ? ELSE latest_event END, updated_at = NOW() WHERE order_id = ?");
                    $upd->execute([$eventType, $data['order_id']]);
                }
            } catch (Exception $e) {
                error_log('[OdooSyncService] Failed to update order is_paid for order_id ' . $data['order_id'] . ': ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Sync BDO data to odoo_bdos table
     */
    private function syncBdo($payload, $eventType, $webhookId)
    {
        $bdoId = (int) ($payload['bdo_id'] ?? 0);
        if (!$bdoId) return false;

        $customer = $payload['customer'] ?? [];
        $salesperson = $payload['salesperson'] ?? [];

        // Extract partner_id - customer.id IS the partner_id in Odoo webhooks
        $partnerId = null;
        if (isset($customer['id']) && $customer['id']) {
            $partnerId = (int) $customer['id'];
        } elseif (isset($customer['partner_id']) && $customer['partner_id']) {
            $partnerId = (int) $customer['partner_id'];
        }

        // Extract order_id from sale_orders[] array or sale_order object
        $orderId = null;
        $orderName = null;
        $saleOrders = $payload['sale_orders'] ?? [];
        if (!empty($saleOrders) && is_array($saleOrders)) {
            $orderId = (int) ($saleOrders[0]['id'] ?? 0) ?: null;
            $orderName = $saleOrders[0]['name'] ?? null;
        }
        if (!$orderId && isset($payload['sale_order']['id'])) {
            $orderId = (int) $payload['sale_order']['id'];
            $orderName = $payload['sale_order']['name'] ?? $orderName;
        }
        if (!$orderId) {
            $orderId = (int) ($payload['order_id'] ?? 0) ?: null;
        }
        if (!$orderName) {
            $orderName = $payload['order_name'] ?? $payload['order_ref'] ?? null;
        }

        // Extract payment info
        $payment = $payload['payment'] ?? [];
        $paymentMethod = $payment['method'] ?? null;
        $paymentReference = $payment['reference'] ?? $payload['bdo_name'] ?? null;
        $qrData = null;
        if (isset($payment['promptpay']['qr_data']['raw_payload'])) {
            $qrData = $payment['promptpay']['qr_data']['raw_payload'];
        }

        // Sanitize: line_user_id may be boolean false from Odoo webhook
        $lineUserId = $customer['line_user_id'] ?? null;
        if ($lineUserId === false || $lineUserId === 'false' || $lineUserId === '') {
            $lineUserId = null;
        }

        $data = [
            'bdo_id' => $bdoId,
            'bdo_name' => $payload['bdo_name'] ?? null,
            'order_id' => $orderId,
            'order_name' => $orderName,
            'partner_id' => $partnerId,
            'customer_ref' => $customer['ref'] ?? null,
            'line_user_id' => $lineUserId,
            'salesperson_id' => (int) ($salesperson['id'] ?? 0) ?: null,
            'salesperson_name' => $salesperson['name'] ?? null,
            'state' => $payload['new_state'] ?? $payload['state'] ?? 'confirmed',
            'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
            'currency' => $payload['currency'] ?? 'THB',
            'bdo_date' => $payload['bdo_date'] ?? null,
            'expected_delivery' => $payload['expected_delivery'] ?? null,
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'qr_data' => $qrData,
            'latest_event' => $eventType,
            'webhook_id' => $webhookId,
            'synced_at' => date('Y-m-d H:i:s'),
        ];

        $result = $this->upsertBdo($data);

        // Sync BDO ↔ SO linkage to odoo_bdo_orders table
        $this->syncBdoOrders($payload, $bdoId, $partnerId, $lineUserId, $webhookId);

        return $result;
    }

    /**
     * Sync BDO ↔ Sale Order linkage to odoo_bdo_orders table.
     * Extracts sale_orders[] from payload and inserts each link.
     */
    private function syncBdoOrders($payload, $bdoId, $partnerId, $lineUserId, $webhookId)
    {
        try {
            // Check if odoo_bdo_orders table exists
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
            if ($tableCheck->rowCount() === 0) {
                return; // Table not created yet
            }

            $bdoName = $payload['bdo_name'] ?? null;
            $amountTotal = isset($payload['amount_total']) ? (float) $payload['amount_total'] : null;
            $payment = $payload['payment'] ?? [];
            $paymentMethod = $payment['method'] ?? null;
            $paymentReference = $payment['reference'] ?? $bdoName;
            $customer = $payload['customer'] ?? [];
            $customerName = $customer['name'] ?? null;
            $deliveryId = null;

            // Collect all sale orders from payload
            $orders = [];
            $saleOrders = $payload['sale_orders'] ?? [];
            if (!empty($saleOrders) && is_array($saleOrders)) {
                foreach ($saleOrders as $so) {
                    $soId = (int) ($so['id'] ?? 0);
                    if ($soId > 0) {
                        $orders[] = ['id' => $soId, 'name' => $so['name'] ?? null];
                    }
                }
            }
            // Fallback: single sale_order object
            if (empty($orders) && isset($payload['sale_order']['id'])) {
                $soId = (int) $payload['sale_order']['id'];
                if ($soId > 0) {
                    $orders[] = ['id' => $soId, 'name' => $payload['sale_order']['name'] ?? null];
                }
            }
            // Fallback: order_id at root
            if (empty($orders)) {
                $soId = (int) ($payload['order_id'] ?? 0);
                if ($soId > 0) {
                    $orders[] = ['id' => $soId, 'name' => $payload['order_name'] ?? $payload['order_ref'] ?? null];
                }
            }

            if (empty($orders)) {
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO odoo_bdo_orders 
                    (bdo_id, bdo_name, order_id, order_name, amount_total, payment_reference, 
                     partner_id, customer_name, line_user_id, payment_method, webhook_delivery_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    bdo_name = VALUES(bdo_name),
                    order_name = VALUES(order_name),
                    amount_total = VALUES(amount_total),
                    payment_reference = VALUES(payment_reference),
                    partner_id = VALUES(partner_id),
                    customer_name = VALUES(customer_name),
                    line_user_id = COALESCE(VALUES(line_user_id), line_user_id),
                    payment_method = COALESCE(VALUES(payment_method), payment_method),
                    webhook_delivery_id = VALUES(webhook_delivery_id),
                    updated_at = NOW()
            ");

            foreach ($orders as $so) {
                $stmt->execute([
                    $bdoId,
                    $bdoName,
                    $so['id'],
                    $so['name'],
                    $amountTotal,
                    $paymentReference,
                    $partnerId,
                    $customerName,
                    $lineUserId,
                    $paymentMethod,
                    $webhookId,
                ]);
            }
        } catch (Exception $e) {
            error_log("[OdooSyncService] syncBdoOrders failed for bdo_id {$bdoId}: " . $e->getMessage());
        }
    }

    /**
     * Find BDO by payment reference (bdo_name) for slip matching
     * 
     * @param string $ref Payment reference (typically BDO name)
     * @param int|null $partnerId Optional partner_id to narrow search
     * @return array|null BDO order record or null
     */
    public function findBdoByPaymentRef($ref, $partnerId = null)
    {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
            if ($tableCheck->rowCount() === 0) {
                return null;
            }

            $sql = "SELECT * FROM odoo_bdo_orders WHERE payment_reference = ?";
            $params = [$ref];
            if ($partnerId) {
                $sql .= " AND partner_id = ?";
                $params[] = (int) $partnerId;
            }
            $sql .= " ORDER BY created_at DESC LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("[OdooSyncService] findBdoByPaymentRef failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find pending BDO orders for a partner (for slip attachment UI)
     * 
     * @param int $partnerId Odoo partner ID
     * @param int $limit Max results
     * @return array List of pending BDO order records
     */
    public function getPendingBdoOrders($partnerId, $limit = 50)
    {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
            if ($tableCheck->rowCount() === 0) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT bo.*, b.state as bdo_state, b.bdo_date
                FROM odoo_bdo_orders bo
                LEFT JOIN odoo_bdos b ON bo.bdo_id = b.bdo_id
                WHERE bo.partner_id = ?
                  AND bo.payment_status = 'pending'
                ORDER BY bo.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([(int) $partnerId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[OdooSyncService] getPendingBdoOrders failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Insert or update order record
     */
    private function upsertOrder($data)
    {
        $orderId = $data['order_id'];
        
        // Filter out NULL values (keep 0, false, '' — they are valid)
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        
        // Ensure order_id is always present
        $data['order_id'] = $orderId;
        
        try {
            // Check if exists
            $stmt = $this->db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
            $stmt->execute([$orderId]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $updateFields = [];
                $params = [];
                foreach ($data as $key => $value) {
                    if ($key === 'order_id') continue;
                    $updateFields[] = "`{$key}` = ?";
                    $params[] = $value;
                }
                if (empty($updateFields)) return true;
                $params[] = $orderId;
                $sql = "UPDATE odoo_orders SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE order_id = ?";
                $stmt = $this->db->prepare($sql);
                $r = $stmt->execute($params);
                if (!$r) {
                    $err = implode(' | ', $stmt->errorInfo());
                    fwrite(STDERR, "[upsertOrder] UPDATE failed order_id={$orderId}: {$err}\n");
                    throw new Exception("UPDATE odoo_orders failed for order_id {$orderId}: {$err}");
                }
                return $r;
            } else {
                $fields = array_keys($data);
                $placeholders = array_fill(0, count($fields), '?');
                $sql = "INSERT INTO odoo_orders (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $this->db->prepare($sql);
                $r = $stmt->execute(array_values($data));
                if (!$r) {
                    $err = implode(' | ', $stmt->errorInfo());
                    fwrite(STDERR, "[upsertOrder] INSERT failed order_id={$orderId}: {$err}\n");
                    throw new Exception("INSERT odoo_orders failed for order_id {$orderId}: {$err}");
                }
                return $r;
            }
        } catch (Exception $e) {
            fwrite(STDERR, "[upsertOrder] EXCEPTION order_id={$orderId}: " . $e->getMessage() . "\n");
            throw $e;
        }
    }

    /**
     * Insert or update invoice record
     */
    private function upsertInvoice($data)
    {
        $invoiceId = $data['invoice_id'];
        
        // Filter out NULL values (keep 0, false, '' — they are valid)
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        $data['invoice_id'] = $invoiceId;
        
        try {
            $stmt = $this->db->prepare("SELECT id FROM odoo_invoices WHERE invoice_id = ? LIMIT 1");
            $stmt->execute([$invoiceId]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $updateFields = [];
                $params = [];
                foreach ($data as $key => $value) {
                    if ($key === 'invoice_id') continue;
                    $updateFields[] = "`{$key}` = ?";
                    $params[] = $value;
                }
                
                if (empty($updateFields)) {
                    return true;
                }
                
                $params[] = $invoiceId;
                
                $sql = "UPDATE odoo_invoices SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE invoice_id = ?";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute($params);
            } else {
                $fields = array_keys($data);
                $placeholders = array_fill(0, count($fields), '?');
                
                $sql = "INSERT INTO odoo_invoices (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute(array_values($data));
            }
        } catch (Exception $e) {
            error_log("[OdooSyncService] upsertInvoice failed for invoice_id {$invoiceId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert or update BDO record
     */
    private function upsertBdo($data)
    {
        $bdoId = $data['bdo_id'];
        
        // Filter out NULL values
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        $data['bdo_id'] = $bdoId;
        
        try {
            $stmt = $this->db->prepare("SELECT id FROM odoo_bdos WHERE bdo_id = ? LIMIT 1");
            $stmt->execute([$bdoId]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $updateFields = [];
                $params = [];
                foreach ($data as $key => $value) {
                    if ($key === 'bdo_id') continue;
                    $updateFields[] = "`{$key}` = ?";
                    $params[] = $value;
                }
                
                if (empty($updateFields)) {
                    return true;
                }
                
                $params[] = $bdoId;
                
                $sql = "UPDATE odoo_bdos SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE bdo_id = ?";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute($params);
            } else {
                $fields = array_keys($data);
                $placeholders = array_fill(0, count($fields), '?');
                
                $sql = "INSERT INTO odoo_bdos (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute(array_values($data));
            }
        } catch (Exception $e) {
            error_log("[OdooSyncService] upsertBdo failed for bdo_id {$bdoId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract payment status from event type
     */
    private function extractPaymentStatus($eventType, $payload)
    {
        if (str_contains($eventType, 'paid') || str_contains($eventType, 'payment.confirmed') || str_contains($eventType, 'payment.received')) {
            return 'paid';
        }
        if (str_contains($eventType, 'awaiting_payment')) {
            return 'awaiting_payment';
        }
        return $payload['payment_status'] ?? null;
    }

    /**
     * Extract delivery status from event type
     */
    private function extractDeliveryStatus($eventType, $payload)
    {
        if (str_contains($eventType, 'delivery.done') || str_contains($eventType, 'delivery.completed') || str_contains($eventType, 'delivered')) {
            return 'delivered';
        }
        if (str_contains($eventType, 'delivery') || str_contains($eventType, 'to_delivery')) {
            return 'in_delivery';
        }
        if (str_contains($eventType, 'packed')) {
            return 'packed';
        }
        return $payload['delivery_status'] ?? null;
    }

    /**
     * Backfill existing webhook log data to new tables
     * Run this once after migration to populate tables with historical data
     * 
     * @param int $limit Number of records to process per batch
     * @param int $offset Starting offset
     * @return array Stats about processed records
     */
    public function backfillFromWebhookLog($limit = 1000, $offset = 0)
    {
        $stats = ['orders' => 0, 'invoices' => 0, 'bdos' => 0, 'errors' => 0];

        try {
            $stmt = $this->db->prepare("
                SELECT id, event_type, payload, processed_at
                FROM odoo_webhooks_log
                WHERE synced_to_tables = FALSE
                  AND (
                    event_type LIKE 'order.%' OR
                    event_type LIKE 'sale.%' OR
                    event_type LIKE 'invoice.%' OR
                    event_type LIKE 'bdo.%' OR
                    event_type LIKE 'delivery.%' OR
                    event_type LIKE 'payment.%'
                  )
                ORDER BY processed_at ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($webhooks as $wh) {
                $payload = json_decode($wh['payload'], true);
                if (!$payload) {
                    $stats['errors']++;
                    $stats['last_error'] = "Webhook #{$wh['id']}: Invalid JSON payload";
                    continue;
                }

                try {
                    $success = $this->syncWebhook($payload, $wh['event_type'], $wh['id']);
                } catch (Exception $e) {
                    $success = false;
                    $stats['last_error'] = "Webhook #{$wh['id']} ({$wh['event_type']}): " . $e->getMessage();
                }
                
                if ($success) {
                    // Mark as synced
                    $updateStmt = $this->db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?");
                    $updateStmt->execute([$wh['id']]);

                    // Count by type
                    if (str_starts_with($wh['event_type'], 'order.') || str_starts_with($wh['event_type'], 'sale.') ||
                        str_starts_with($wh['event_type'], 'delivery.') || str_starts_with($wh['event_type'], 'payment.')) {
                        $stats['orders']++;
                    } elseif (str_starts_with($wh['event_type'], 'invoice.')) {
                        $stats['invoices']++;
                    } elseif (str_starts_with($wh['event_type'], 'bdo.')) {
                        $stats['bdos']++;
                    }
                } else {
                    $stats['errors']++;
                    if (empty($stats['last_error'])) {
                        $stats['last_error'] = "Webhook #{$wh['id']} ({$wh['event_type']}): syncWebhook returned false";
                    }
                }
            }

            $stats['processed'] = count($webhooks);
            return $stats;

        } catch (Exception $e) {
            error_log('[OdooSyncService] Backfill failed: ' . $e->getMessage());
            $stats['error_message'] = $e->getMessage();
            return $stats;
        }
    }
}
