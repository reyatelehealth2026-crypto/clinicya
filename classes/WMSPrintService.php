<?php
/**
 * WMSPrintService - Print Service for WMS
 * Generates packing slips and shipping labels
 * 
 * Requirements: 3.4, 4.1, 4.2, 4.5, 8.2, 8.3
 */

class WMSPrintService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Set line account ID
     */
    public function setLineAccountId(int $lineAccountId): void {
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get order details for printing
     * 
     * @param int $orderId Order ID
     * @return array Order data with items and shop settings
     * @throws Exception if order not found
     */
    private function getOrderForPrint(int $orderId): array {
        // Get order
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   u.phone as customer_phone,
                   u.email as customer_email
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Get order items
        $stmt = $this->db->prepare("
            SELECT ti.*, 
                   bi.storage_condition as storage_location
            FROM transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.transaction_id = ?
            ORDER BY ti.product_name ASC
        ");
        $stmt->execute([$orderId]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get shop settings for sender info
        $lineAccountId = $order['line_account_id'] ?? $this->lineAccountId;
        if ($lineAccountId) {
            $stmt = $this->db->prepare("
                SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1
            ");
            $stmt->execute([$lineAccountId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM shop_settings WHERE id = 1 LIMIT 1");
        }
        $order['shop'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        return $order;
    }

    
    /**
     * Generate packing slip HTML for an order
     * Requirements: 3.4
     * 
     * @param int $orderId Order ID
     * @return string HTML content for packing slip
     */
    public function generatePackingSlip(int $orderId): string {
        $order = $this->getOrderForPrint($orderId);
        
        // Record print timestamp
        $this->recordLabelPrint($orderId, 'packing_slip');
        
        $shopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
        $shopPhone = $order['shop']['contact_phone'] ?? '';
        $shopAddress = $order['shop']['address'] ?? '';
        
        $html = $this->getPackingSlipHeader($shopName);
        $html .= $this->getPackingSlipContent($order, $shopName, $shopPhone, $shopAddress);
        $html .= $this->getPackingSlipFooter();
        
        return $html;
    }
    
    /**
     * Generate packing slip header HTML
     */
    private function getPackingSlipHeader(string $shopName): string {
        return '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packing Slip - ' . htmlspecialchars($shopName) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Sarabun", "Helvetica Neue", Arial, sans-serif; font-size: 12px; line-height: 1.4; }
        .packing-slip { width: 210mm; min-height: 148mm; padding: 10mm; margin: 0 auto; background: white; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
        .shop-info h1 { font-size: 18px; margin-bottom: 5px; }
        .shop-info p { font-size: 11px; color: #666; }
        .order-info { text-align: right; }
        .order-info .order-number { font-size: 16px; font-weight: bold; }
        .order-info .order-date { font-size: 11px; color: #666; }
        .section { margin-bottom: 15px; }
        .section-title { font-size: 13px; font-weight: bold; background: #f5f5f5; padding: 5px 10px; margin-bottom: 10px; }
        .customer-info { display: flex; gap: 20px; }
        .customer-info .col { flex: 1; }
        .customer-info label { font-weight: bold; display: block; margin-bottom: 3px; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .items-table th { background: #f5f5f5; font-weight: bold; }
        .items-table .qty { text-align: center; width: 60px; }
        .items-table .check { text-align: center; width: 50px; }
        .checkbox { width: 16px; height: 16px; border: 2px solid #333; display: inline-block; }
        .totals { margin-top: 15px; text-align: right; }
        .totals table { margin-left: auto; }
        .totals td { padding: 3px 10px; }
        .totals .total-row { font-weight: bold; font-size: 14px; }
        .footer { margin-top: 20px; padding-top: 10px; border-top: 1px dashed #ccc; font-size: 10px; color: #666; text-align: center; }
        .notes { margin-top: 15px; padding: 10px; background: #fffbeb; border: 1px solid #fcd34d; }
        .notes-title { font-weight: bold; margin-bottom: 5px; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .packing-slip { page-break-after: always; }
            .packing-slip:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>';
    }

    
    /**
     * Generate packing slip content HTML
     */
    private function getPackingSlipContent(array $order, string $shopName, string $shopPhone, string $shopAddress): string {
        $orderNumber = htmlspecialchars($order['order_number'] ?? '');
        $orderDate = isset($order['created_at']) ? date('d/m/Y H:i', strtotime($order['created_at'])) : '';
        
        // Customer info
        $customerName = htmlspecialchars($order['shipping_name'] ?? $order['customer_name'] ?? '');
        $customerPhone = htmlspecialchars($order['shipping_phone'] ?? $order['customer_phone'] ?? '');
        $customerAddress = htmlspecialchars($order['shipping_address'] ?? '');
        
        $html = '<div class="packing-slip">
    <div class="header">
        <div class="shop-info">
            <h1>' . htmlspecialchars($shopName) . '</h1>
            <p>' . htmlspecialchars($shopAddress) . '</p>
            <p>โทร: ' . htmlspecialchars($shopPhone) . '</p>
        </div>
        <div class="order-info">
            <div class="order-number">ใบจัดสินค้า</div>
            <div class="order-number">#' . $orderNumber . '</div>
            <div class="order-date">' . $orderDate . '</div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">ข้อมูลลูกค้า</div>
        <div class="customer-info">
            <div class="col">
                <label>ชื่อผู้รับ:</label>
                <p>' . $customerName . '</p>
            </div>
            <div class="col">
                <label>เบอร์โทร:</label>
                <p>' . $customerPhone . '</p>
            </div>
        </div>
        <div style="margin-top: 10px;">
            <label style="font-weight: bold;">ที่อยู่จัดส่ง:</label>
            <p>' . nl2br($customerAddress) . '</p>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">รายการสินค้า</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th class="check">✓</th>
                    <th>รหัสสินค้า</th>
                    <th>ชื่อสินค้า</th>
                    <th class="qty">จำนวน</th>
                    <th>ตำแหน่ง</th>
                </tr>
            </thead>
            <tbody>';
        
        $totalItems = 0;
        foreach ($order['items'] as $item) {
            $totalItems += (int)$item['quantity'];
            $html .= '
                <tr>
                    <td class="check"><span class="checkbox"></span></td>
                    <td>' . htmlspecialchars($item['product_sku'] ?? '-') . '</td>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td class="qty">' . (int)$item['quantity'] . '</td>
                    <td>' . htmlspecialchars($item['storage_location'] ?? '-') . '</td>
                </tr>';
        }
        
        $html .= '
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: bold;">รวมทั้งหมด:</td>
                    <td class="qty" style="font-weight: bold;">' . $totalItems . ' ชิ้น</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>';
        
        // Add notes if present
        $notes = $order['note'] ?? $order['notes'] ?? '';
        if (!empty($notes)) {
            $html .= '
    <div class="notes">
        <div class="notes-title">📝 หมายเหตุจากลูกค้า:</div>
        <p>' . nl2br(htmlspecialchars($notes)) . '</p>
    </div>';
        }
        
        $html .= '
    <div class="footer">
        <p>พิมพ์เมื่อ: ' . date('d/m/Y H:i:s') . ' | ผู้จัดสินค้า: _________________ | ผู้ตรวจสอบ: _________________</p>
    </div>
</div>';
        
        return $html;
    }
    
    /**
     * Generate packing slip footer HTML
     */
    private function getPackingSlipFooter(): string {
        return '</body></html>';
    }

    
    /**
     * Generate shipping label HTML for an order
     * Requirements: 4.1, 4.2, 4.5
     * 
     * Label contains:
     * - Recipient name, address, order number (4.1)
     * - Sender information from shop settings (4.2)
     * - Barcode/QR code for tracking if available (4.3)
     * - Standard label size A6/10x15cm (4.4)
     * - Records print timestamp for audit (4.5)
     * 
     * @param int $orderId Order ID
     * @return string HTML content for shipping label
     */
    public function generateShippingLabel(int $orderId): string {
        $order = $this->getOrderForPrint($orderId);
        
        // Record print timestamp (Requirements 4.5)
        $this->recordLabelPrint($orderId, 'shipping_label');
        
        // Shop/Sender info (Requirements 4.2)
        $shopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
        $shopPhone = $order['shop']['contact_phone'] ?? '';
        $shopAddress = $order['shop']['address'] ?? '';
        
        $html = $this->getShippingLabelHeader($shopName);
        $html .= $this->getShippingLabelContent($order, $shopName, $shopPhone, $shopAddress);
        $html .= $this->getShippingLabelFooter();
        
        return $html;
    }
    
    /**
     * Generate shipping label header HTML
     * Standard A6 size (105mm x 148mm) or 10x15cm
     */
    private function getShippingLabelHeader(string $shopName): string {
        return '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipping Label - ' . htmlspecialchars($shopName) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Sarabun", "Helvetica Neue", Arial, sans-serif; font-size: 11px; line-height: 1.3; }
        .shipping-label { width: 100mm; height: 150mm; padding: 5mm; margin: 0 auto; background: white; border: 1px solid #000; position: relative; }
        .label-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 8px; }
        .order-number { font-size: 14px; font-weight: bold; }
        .carrier-info { font-size: 12px; font-weight: bold; text-align: right; }
        .section { margin-bottom: 8px; padding: 5px; }
        .section-title { font-size: 10px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 3px; }
        .recipient { background: #f5f5f5; border: 1px solid #ddd; padding: 8px; }
        .recipient-name { font-size: 16px; font-weight: bold; margin-bottom: 5px; }
        .recipient-phone { font-size: 14px; font-weight: bold; margin-bottom: 5px; }
        .recipient-address { font-size: 12px; line-height: 1.4; }
        .sender { font-size: 10px; border-top: 1px dashed #ccc; padding-top: 5px; margin-top: 5px; }
        .sender-title { font-weight: bold; }
        .tracking-section { text-align: center; padding: 8px; border: 2px solid #000; margin-top: 8px; }
        .tracking-number { font-size: 14px; font-weight: bold; font-family: monospace; letter-spacing: 1px; }
        .barcode { margin: 5px 0; font-family: "Libre Barcode 39", monospace; font-size: 40px; }
        .qr-placeholder { width: 60px; height: 60px; border: 1px solid #ccc; margin: 5px auto; display: flex; align-items: center; justify-content: center; font-size: 8px; color: #999; }
        .items-summary { font-size: 10px; border-top: 1px solid #ddd; padding-top: 5px; margin-top: 5px; }
        .print-date { position: absolute; bottom: 3mm; right: 5mm; font-size: 8px; color: #999; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .shipping-label { page-break-after: always; border: none; }
            .shipping-label:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>';
    }

    
    /**
     * Generate shipping label content HTML
     * Requirements: 4.1 (recipient name, address, order number), 4.2 (sender info)
     */
    private function getShippingLabelContent(array $order, string $shopName, string $shopPhone, string $shopAddress): string {
        $orderNumber = htmlspecialchars($order['order_number'] ?? '');
        
        // Recipient info (Requirements 4.1)
        $recipientName = htmlspecialchars($order['shipping_name'] ?? $order['customer_name'] ?? '');
        $recipientPhone = htmlspecialchars($order['shipping_phone'] ?? $order['customer_phone'] ?? '');
        $recipientAddress = htmlspecialchars($order['shipping_address'] ?? '');
        
        // Carrier and tracking
        $carrier = htmlspecialchars($order['carrier'] ?? $order['shipping_provider'] ?? '');
        $trackingNumber = htmlspecialchars($order['tracking_number'] ?? $order['shipping_tracking'] ?? '');
        
        // Calculate total items
        $totalItems = 0;
        foreach ($order['items'] as $item) {
            $totalItems += (int)$item['quantity'];
        }
        
        $html = '<div class="shipping-label">
    <div class="label-header">
        <div class="order-number">#' . $orderNumber . '</div>
        <div class="carrier-info">' . ($carrier ?: 'ขนส่ง') . '</div>
    </div>
    
    <div class="section recipient">
        <div class="section-title">ผู้รับ / Recipient</div>
        <div class="recipient-name">' . $recipientName . '</div>
        <div class="recipient-phone">📞 ' . $recipientPhone . '</div>
        <div class="recipient-address">' . nl2br($recipientAddress) . '</div>
    </div>
    
    <div class="section sender">
        <div class="sender-title">ผู้ส่ง / Sender:</div>
        <div>' . htmlspecialchars($shopName) . '</div>
        <div>' . htmlspecialchars($shopPhone) . '</div>
        <div>' . htmlspecialchars($shopAddress) . '</div>
    </div>';
        
        // Tracking section (Requirements 4.3)
        if (!empty($trackingNumber)) {
            $html .= '
    <div class="tracking-section">
        <div class="section-title">เลขพัสดุ / Tracking</div>
        <div class="tracking-number">' . $trackingNumber . '</div>
        <div class="barcode">*' . $trackingNumber . '*</div>
    </div>';
        } else {
            $html .= '
    <div class="tracking-section">
        <div class="section-title">เลขพัสดุ / Tracking</div>
        <div style="color: #999; font-style: italic;">รอกรอกเลขพัสดุ</div>
    </div>';
        }
        
        $html .= '
    <div class="items-summary">
        📦 จำนวนสินค้า: ' . $totalItems . ' ชิ้น | ' . count($order['items']) . ' รายการ
    </div>
    
    <div class="print-date">พิมพ์: ' . date('d/m/Y H:i') . '</div>
</div>';
        
        return $html;
    }
    
    /**
     * Generate shipping label footer HTML
     */
    private function getShippingLabelFooter(): string {
        return '</body></html>';
    }

    
    /**
     * Record label print timestamp for audit
     * Requirements: 4.5
     * 
     * @param int $orderId Order ID
     * @param string $type Print type (packing_slip, shipping_label)
     */
    private function recordLabelPrint(int $orderId, string $type): void {
        try {
            // Update label_printed_at timestamp
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET label_printed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            
            // Log activity
            $lineAccountId = $this->lineAccountId ?? 0;
            $stmt = $this->db->prepare("
                INSERT INTO wms_activity_logs 
                (line_account_id, order_id, action, notes, metadata, created_at)
                VALUES (?, ?, 'label_printed', ?, ?, NOW())
            ");
            $stmt->execute([
                $lineAccountId,
                $orderId,
                "Printed {$type}",
                json_encode(['type' => $type, 'printed_at' => date('Y-m-d H:i:s')])
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the print operation
            error_log("Failed to record label print for order {$orderId}: " . $e->getMessage());
        }
    }

    
    // =============================================
    // BATCH PRINTING METHODS (Requirements 8.2, 8.3)
    // =============================================
    
    /**
     * Generate batch packing slips for multiple orders
     * Requirements: 8.2 - Generate multi-page PDF with one slip per page
     * 
     * @param array $orderIds Array of order IDs
     * @return string HTML content for all packing slips
     */
    public function generateBatchPackingSlips(array $orderIds): string {
        if (empty($orderIds)) {
            throw new Exception("No orders provided for batch printing");
        }
        
        $shopName = 'ร้านค้า';
        
        // Get first order's shop name for header
        try {
            $order = $this->getOrderForPrint($orderIds[0]);
            $shopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
        } catch (Exception $e) {
            // Use default shop name
        }
        
        $html = $this->getPackingSlipHeader($shopName);
        
        foreach ($orderIds as $orderId) {
            try {
                $order = $this->getOrderForPrint($orderId);
                $orderShopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
                $orderShopPhone = $order['shop']['contact_phone'] ?? '';
                $orderShopAddress = $order['shop']['address'] ?? '';
                
                $html .= $this->getPackingSlipContent($order, $orderShopName, $orderShopPhone, $orderShopAddress);
                
                // Record print for each order
                $this->recordLabelPrint($orderId, 'packing_slip_batch');
            } catch (Exception $e) {
                // Skip orders that can't be printed, log error
                error_log("Failed to generate packing slip for order {$orderId}: " . $e->getMessage());
                continue;
            }
        }
        
        $html .= $this->getPackingSlipFooter();
        
        return $html;
    }
    
    /**
     * Generate batch shipping labels for multiple orders
     * Requirements: 8.3 - Generate labels in sequence
     * 
     * @param array $orderIds Array of order IDs
     * @return string HTML content for all shipping labels
     */
    public function generateBatchLabels(array $orderIds): string {
        if (empty($orderIds)) {
            throw new Exception("No orders provided for batch printing");
        }
        
        $shopName = 'ร้านค้า';
        
        // Get first order's shop name for header
        try {
            $order = $this->getOrderForPrint($orderIds[0]);
            $shopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
        } catch (Exception $e) {
            // Use default shop name
        }
        
        $html = $this->getShippingLabelHeader($shopName);
        
        foreach ($orderIds as $orderId) {
            try {
                $order = $this->getOrderForPrint($orderId);
                $orderShopName = $order['shop']['shop_name'] ?? 'ร้านค้า';
                $orderShopPhone = $order['shop']['contact_phone'] ?? '';
                $orderShopAddress = $order['shop']['address'] ?? '';
                
                $html .= $this->getShippingLabelContent($order, $orderShopName, $orderShopPhone, $orderShopAddress);
                
                // Record print for each order
                $this->recordLabelPrint($orderId, 'shipping_label_batch');
            } catch (Exception $e) {
                // Skip orders that can't be printed, log error
                error_log("Failed to generate shipping label for order {$orderId}: " . $e->getMessage());
                continue;
            }
        }
        
        $html .= $this->getShippingLabelFooter();
        
        return $html;
    }
    
    /**
     * Get orders ready for label printing
     * Returns orders that are packed but haven't had labels printed
     * 
     * @param array $filters Optional filters
     * @return array List of orders
     */
    public function getOrdersForPrinting(array $filters = []): array {
        $sql = "SELECT t.id, t.order_number, t.shipping_name, t.wms_status, 
                       t.label_printed_at, t.created_at,
                       (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count
                FROM transactions t
                WHERE t.wms_status IN ('packed', 'ready_to_ship')";
        
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        // Filter for unprinted labels only
        if (!empty($filters['unprinted_only'])) {
            $sql .= " AND t.label_printed_at IS NULL";
        }
        
        $sql .= " ORDER BY t.pack_completed_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Mark orders as label printed
     * Requirements: 8.4
     * 
     * @param array $orderIds Array of order IDs
     * @return bool Success
     */
    public function markLabelsPrinted(array $orderIds): bool {
        if (empty($orderIds)) {
            return true;
        }
        
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $stmt = $this->db->prepare("
            UPDATE transactions 
            SET label_printed_at = NOW()
            WHERE id IN ({$placeholders})
        ");
        
        return $stmt->execute($orderIds);
    }
    
    /**
     * Check if shipping label contains all required fields
     * Used for validation/testing
     * 
     * @param int $orderId Order ID
     * @return array Validation result with missing fields
     */
    public function validateShippingLabelFields(int $orderId): array {
        $order = $this->getOrderForPrint($orderId);
        
        $requiredFields = [
            'recipient_name' => !empty($order['shipping_name'] ?? $order['customer_name']),
            'recipient_address' => !empty($order['shipping_address']),
            'order_number' => !empty($order['order_number']),
            'sender_name' => !empty($order['shop']['shop_name']),
            'sender_address' => !empty($order['shop']['address']),
        ];
        
        $missingFields = [];
        foreach ($requiredFields as $field => $present) {
            if (!$present) {
                $missingFields[] = $field;
            }
        }
        
        return [
            'valid' => empty($missingFields),
            'missing_fields' => $missingFields,
            'fields_checked' => array_keys($requiredFields)
        ];
    }
    
    // =============================================
    // LOCATION LABEL METHODS (Requirements 6.1, 6.2)
    // =============================================
    
    /**
     * Format location code to human-readable format
     * Requirements: 6.2 - Human-readable format display
     * 
     * @param string $locationCode Location code (e.g., A1-03-02)
     * @return array Human-readable components
     */
    public function formatLocationForDisplay(string $locationCode): array {
        $parts = explode('-', strtoupper($locationCode));
        
        if (count($parts) !== 3) {
            return [
                'zone' => $locationCode,
                'shelf' => '',
                'bin' => '',
                'display' => $locationCode,
                'display_th' => $locationCode
            ];
        }
        
        $zone = $parts[0];
        $shelf = (int)$parts[1];
        $bin = (int)$parts[2];
        
        // Map zone codes to readable names
        $zoneNames = [
            'A' => 'A (General)',
            'A1' => 'A1 (General)',
            'B' => 'B (General)',
            'B1' => 'B1 (General)',
            'C' => 'C (Slow Moving)',
            'RX' => 'RX (Controlled)',
            'COLD' => 'Cold Storage',
            'HAZ' => 'Hazardous'
        ];
        
        $zoneNamesTh = [
            'A' => 'โซน A (ทั่วไป)',
            'A1' => 'โซน A1 (ทั่วไป)',
            'B' => 'โซน B (ทั่วไป)',
            'B1' => 'โซน B1 (ทั่วไป)',
            'C' => 'โซน C (สินค้าหมุนช้า)',
            'RX' => 'โซน RX (ยาควบคุม)',
            'COLD' => 'ห้องเย็น',
            'HAZ' => 'วัตถุอันตราย'
        ];
        
        $zoneName = $zoneNames[$zone] ?? "Zone {$zone}";
        $zoneNameTh = $zoneNamesTh[$zone] ?? "โซน {$zone}";
        
        return [
            'zone' => $zone,
            'shelf' => $shelf,
            'bin' => $bin,
            'zone_name' => $zoneName,
            'zone_name_th' => $zoneNameTh,
            'display' => "{$zoneName}, Shelf {$shelf}, Bin {$bin}",
            'display_th' => "{$zoneNameTh}, ชั้น {$shelf}, ช่อง {$bin}"
        ];
    }
    
    /**
     * Generate location label HTML for a single location
     * Requirements: 6.1 - Generate barcode/QR with location code
     * Requirements: 6.2 - Human-readable format display
     * 
     * @param array $location Location data from LocationService
     * @return string HTML content for location label
     */
    public function generateLocationLabel(array $location): string {
        $locationCode = $location['location_code'] ?? '';
        $formatted = $this->formatLocationForDisplay($locationCode);
        
        $html = $this->getLocationLabelHeader();
        $html .= $this->getLocationLabelContent($location, $formatted);
        $html .= $this->getLocationLabelFooter();
        
        return $html;
    }
    
    /**
     * Generate batch location labels for multiple locations
     * 
     * @param array $locations Array of location data
     * @return string HTML content for all location labels
     */
    public function generateBatchLocationLabels(array $locations): string {
        if (empty($locations)) {
            throw new Exception("No locations provided for label printing");
        }
        
        $html = $this->getLocationLabelHeader();
        
        foreach ($locations as $location) {
            $locationCode = $location['location_code'] ?? '';
            $formatted = $this->formatLocationForDisplay($locationCode);
            $html .= $this->getLocationLabelContent($location, $formatted);
        }
        
        $html .= $this->getLocationLabelFooter();
        
        return $html;
    }
    
    /**
     * Generate location label header HTML
     * Label size: 50mm x 30mm (standard shelf label)
     */
    private function getLocationLabelHeader(): string {
        return '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Labels</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Sarabun", "Helvetica Neue", Arial, sans-serif; font-size: 10px; line-height: 1.2; }
        .location-label { 
            width: 50mm; 
            height: 30mm; 
            padding: 2mm; 
            margin: 2mm; 
            background: white; 
            border: 1px solid #000; 
            display: inline-block;
            vertical-align: top;
            position: relative;
            page-break-inside: avoid;
        }
        .label-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid #333; 
            padding-bottom: 1mm; 
            margin-bottom: 1mm; 
        }
        .location-code { 
            font-size: 14px; 
            font-weight: bold; 
            font-family: monospace;
            letter-spacing: 1px;
        }
        .zone-badge {
            font-size: 8px;
            padding: 1px 3px;
            border-radius: 2px;
            font-weight: bold;
        }
        .zone-general { background: #e3f2fd; color: #1565c0; }
        .zone-cold { background: #e0f7fa; color: #00838f; }
        .zone-controlled { background: #fce4ec; color: #c62828; }
        .zone-hazardous { background: #fff3e0; color: #e65100; }
        .barcode-section { 
            text-align: center; 
            margin: 1mm 0;
        }
        .barcode { 
            font-family: "Libre Barcode 39", "Free 3 of 9", monospace; 
            font-size: 28px; 
            line-height: 1;
        }
        .qr-placeholder {
            width: 15mm;
            height: 15mm;
            border: 1px solid #ccc;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6px;
            color: #999;
            background: #f9f9f9;
        }
        .human-readable {
            font-size: 9px;
            text-align: center;
            margin-top: 1mm;
            color: #333;
        }
        .human-readable-th {
            font-size: 8px;
            text-align: center;
            color: #666;
        }
        .capacity-info {
            position: absolute;
            bottom: 1mm;
            right: 2mm;
            font-size: 7px;
            color: #999;
        }
        .ergonomic-indicator {
            position: absolute;
            bottom: 1mm;
            left: 2mm;
            font-size: 7px;
        }
        .ergonomic-golden { color: #f9a825; }
        .ergonomic-upper { color: #7b1fa2; }
        .ergonomic-lower { color: #1976d2; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .location-label { 
                page-break-inside: avoid;
                border: 1px solid #000;
                margin: 1mm;
            }
        }
        @page {
            size: A4;
            margin: 5mm;
        }
    </style>
</head>
<body>';
    }
    
    /**
     * Generate location label content HTML
     * Requirements: 6.1 - Barcode/QR with location code
     * Requirements: 6.2 - Human-readable format
     */
    private function getLocationLabelContent(array $location, array $formatted): string {
        $locationCode = htmlspecialchars($location['location_code'] ?? '');
        $zoneType = $location['zone_type'] ?? 'general';
        $ergonomicLevel = $location['ergonomic_level'] ?? 'golden';
        $capacity = (int)($location['capacity'] ?? 100);
        
        // Zone badge class
        $zoneBadgeClass = 'zone-general';
        $zoneBadgeText = 'GEN';
        switch ($zoneType) {
            case 'cold_storage':
                $zoneBadgeClass = 'zone-cold';
                $zoneBadgeText = 'COLD';
                break;
            case 'controlled':
                $zoneBadgeClass = 'zone-controlled';
                $zoneBadgeText = 'RX';
                break;
            case 'hazardous':
                $zoneBadgeClass = 'zone-hazardous';
                $zoneBadgeText = 'HAZ';
                break;
        }
        
        // Ergonomic level indicator
        $ergonomicClass = 'ergonomic-golden';
        $ergonomicIcon = '★';
        $ergonomicText = 'Golden';
        switch ($ergonomicLevel) {
            case 'upper':
                $ergonomicClass = 'ergonomic-upper';
                $ergonomicIcon = '↑';
                $ergonomicText = 'Upper';
                break;
            case 'lower':
                $ergonomicClass = 'ergonomic-lower';
                $ergonomicIcon = '↓';
                $ergonomicText = 'Lower';
                break;
        }
        
        $html = '<div class="location-label">
    <div class="label-header">
        <span class="location-code">' . $locationCode . '</span>
        <span class="zone-badge ' . $zoneBadgeClass . '">' . $zoneBadgeText . '</span>
    </div>
    
    <div class="barcode-section">
        <div class="barcode">*' . $locationCode . '*</div>
    </div>
    
    <div class="human-readable">' . htmlspecialchars($formatted['display']) . '</div>
    <div class="human-readable-th">' . htmlspecialchars($formatted['display_th']) . '</div>
    
    <div class="ergonomic-indicator ' . $ergonomicClass . '">' . $ergonomicIcon . ' ' . $ergonomicText . '</div>
    <div class="capacity-info">Cap: ' . $capacity . '</div>
</div>';
        
        return $html;
    }
    
    /**
     * Generate location label footer HTML
     */
    private function getLocationLabelFooter(): string {
        return '</body></html>';
    }
    
    /**
     * Generate location label with QR code (SVG-based)
     * Requirements: 6.1 - Generate QR with location code
     * 
     * @param array $location Location data
     * @return string HTML content with QR code
     */
    public function generateLocationLabelWithQR(array $location): string {
        $locationCode = $location['location_code'] ?? '';
        $formatted = $this->formatLocationForDisplay($locationCode);
        
        $html = $this->getLocationLabelWithQRHeader();
        $html .= $this->getLocationLabelWithQRContent($location, $formatted);
        $html .= $this->getLocationLabelFooter();
        
        return $html;
    }
    
    /**
     * Generate batch location labels with QR codes
     * 
     * @param array $locations Array of location data
     * @return string HTML content for all location labels with QR
     */
    public function generateBatchLocationLabelsWithQR(array $locations): string {
        if (empty($locations)) {
            throw new Exception("No locations provided for label printing");
        }
        
        $html = $this->getLocationLabelWithQRHeader();
        
        foreach ($locations as $location) {
            $locationCode = $location['location_code'] ?? '';
            $formatted = $this->formatLocationForDisplay($locationCode);
            $html .= $this->getLocationLabelWithQRContent($location, $formatted);
        }
        
        $html .= $this->getLocationLabelFooter();
        
        return $html;
    }
    
    /**
     * Generate location label with QR header HTML
     * Larger label size: 60mm x 40mm for QR code
     */
    private function getLocationLabelWithQRHeader(): string {
        return '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Labels with QR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Sarabun", "Helvetica Neue", Arial, sans-serif; font-size: 10px; line-height: 1.2; }
        .location-label-qr { 
            width: 60mm; 
            height: 40mm; 
            padding: 2mm; 
            margin: 2mm; 
            background: white; 
            border: 1px solid #000; 
            display: inline-block;
            vertical-align: top;
            position: relative;
            page-break-inside: avoid;
        }
        .label-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid #333; 
            padding-bottom: 1mm; 
            margin-bottom: 2mm; 
        }
        .location-code { 
            font-size: 16px; 
            font-weight: bold; 
            font-family: monospace;
            letter-spacing: 1px;
        }
        .zone-badge {
            font-size: 9px;
            padding: 2px 4px;
            border-radius: 2px;
            font-weight: bold;
        }
        .zone-general { background: #e3f2fd; color: #1565c0; }
        .zone-cold { background: #e0f7fa; color: #00838f; }
        .zone-controlled { background: #fce4ec; color: #c62828; }
        .zone-hazardous { background: #fff3e0; color: #e65100; }
        .label-body {
            display: flex;
            gap: 3mm;
        }
        .qr-section {
            flex: 0 0 20mm;
        }
        .qr-code {
            width: 20mm;
            height: 20mm;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }
        .qr-code svg {
            width: 100%;
            height: 100%;
        }
        .info-section {
            flex: 1;
        }
        .human-readable {
            font-size: 10px;
            margin-bottom: 1mm;
            color: #333;
        }
        .human-readable-th {
            font-size: 9px;
            color: #666;
            margin-bottom: 2mm;
        }
        .details {
            font-size: 8px;
            color: #666;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1px;
        }
        .capacity-bar {
            height: 3mm;
            background: #e0e0e0;
            border-radius: 1mm;
            margin-top: 2mm;
            overflow: hidden;
        }
        .capacity-fill {
            height: 100%;
            background: #4caf50;
            border-radius: 1mm;
        }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .location-label-qr { 
                page-break-inside: avoid;
                border: 1px solid #000;
                margin: 1mm;
            }
        }
        @page {
            size: A4;
            margin: 5mm;
        }
    </style>
</head>
<body>';
    }
    
    /**
     * Generate location label with QR content HTML
     */
    private function getLocationLabelWithQRContent(array $location, array $formatted): string {
        $locationCode = htmlspecialchars($location['location_code'] ?? '');
        $zoneType = $location['zone_type'] ?? 'general';
        $ergonomicLevel = $location['ergonomic_level'] ?? 'golden';
        $capacity = (int)($location['capacity'] ?? 100);
        $currentQty = (int)($location['current_qty'] ?? 0);
        $utilization = $capacity > 0 ? round(($currentQty / $capacity) * 100) : 0;
        
        // Zone badge class
        $zoneBadgeClass = 'zone-general';
        $zoneBadgeText = 'General';
        switch ($zoneType) {
            case 'cold_storage':
                $zoneBadgeClass = 'zone-cold';
                $zoneBadgeText = 'Cold Storage';
                break;
            case 'controlled':
                $zoneBadgeClass = 'zone-controlled';
                $zoneBadgeText = 'Controlled';
                break;
            case 'hazardous':
                $zoneBadgeClass = 'zone-hazardous';
                $zoneBadgeText = 'Hazardous';
                break;
        }
        
        // Ergonomic level text
        $ergonomicText = 'Golden Zone';
        switch ($ergonomicLevel) {
            case 'upper':
                $ergonomicText = 'Upper Level';
                break;
            case 'lower':
                $ergonomicText = 'Lower Level';
                break;
        }
        
        // Generate simple QR code placeholder (in production, use a QR library)
        $qrSvg = $this->generateSimpleQRPlaceholder($locationCode);
        
        $html = '<div class="location-label-qr">
    <div class="label-header">
        <span class="location-code">' . $locationCode . '</span>
        <span class="zone-badge ' . $zoneBadgeClass . '">' . $zoneBadgeText . '</span>
    </div>
    
    <div class="label-body">
        <div class="qr-section">
            <div class="qr-code">' . $qrSvg . '</div>
        </div>
        <div class="info-section">
            <div class="human-readable">' . htmlspecialchars($formatted['display']) . '</div>
            <div class="human-readable-th">' . htmlspecialchars($formatted['display_th']) . '</div>
            <div class="details">
                <div class="details-row">
                    <span>Level:</span>
                    <span>' . $ergonomicText . '</span>
                </div>
                <div class="details-row">
                    <span>Capacity:</span>
                    <span>' . $currentQty . ' / ' . $capacity . '</span>
                </div>
            </div>
            <div class="capacity-bar">
                <div class="capacity-fill" style="width: ' . min(100, $utilization) . '%;"></div>
            </div>
        </div>
    </div>
</div>';
        
        return $html;
    }
    
    /**
     * Generate a simple QR code placeholder SVG
     * In production, replace with actual QR code generation library
     * 
     * @param string $data Data to encode
     * @return string SVG markup
     */
    private function generateSimpleQRPlaceholder(string $data): string {
        // This generates a simple visual placeholder
        // For production, integrate with a QR library like phpqrcode or chillerlan/php-qrcode
        $hash = md5($data);
        $size = 21; // QR code modules
        $moduleSize = 100 / $size;
        
        $svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="100" height="100" fill="white"/>';
        
        // Generate pseudo-random pattern based on hash
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $charIndex = ($y * $size + $x) % 32;
                $char = $hash[$charIndex];
                $value = hexdec($char);
                
                // Position patterns (corners)
                $isPositionPattern = 
                    ($x < 7 && $y < 7) || 
                    ($x >= $size - 7 && $y < 7) || 
                    ($x < 7 && $y >= $size - 7);
                
                if ($isPositionPattern) {
                    // Draw position pattern
                    $inOuter = ($x < 7 && $y < 7) || ($x >= $size - 7 && $y < 7) || ($x < 7 && $y >= $size - 7);
                    $localX = $x % 7;
                    $localY = $y % 7;
                    if ($x >= $size - 7) $localX = $x - ($size - 7);
                    if ($y >= $size - 7) $localY = $y - ($size - 7);
                    
                    $isBlack = ($localX == 0 || $localX == 6 || $localY == 0 || $localY == 6) ||
                               ($localX >= 2 && $localX <= 4 && $localY >= 2 && $localY <= 4);
                    
                    if ($isBlack) {
                        $svg .= '<rect x="' . ($x * $moduleSize) . '" y="' . ($y * $moduleSize) . '" ';
                        $svg .= 'width="' . $moduleSize . '" height="' . $moduleSize . '" fill="black"/>';
                    }
                } else if ($value > 7) {
                    // Data modules
                    $svg .= '<rect x="' . ($x * $moduleSize) . '" y="' . ($y * $moduleSize) . '" ';
                    $svg .= 'width="' . $moduleSize . '" height="' . $moduleSize . '" fill="black"/>';
                }
            }
        }
        
        $svg .= '</svg>';
        
        return $svg;
    }
    
    /**
     * Get locations for label printing
     * 
     * @param array $filters Optional filters (zone, zone_type, etc.)
     * @return array List of locations
     */
    public function getLocationsForPrinting(array $filters = []): array {
        $sql = "SELECT * FROM warehouse_locations WHERE is_active = 1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['zone'])) {
            $sql .= " AND zone = ?";
            $params[] = strtoupper($filters['zone']);
        }
        
        if (!empty($filters['zone_type'])) {
            $sql .= " AND zone_type = ?";
            $params[] = $filters['zone_type'];
        }
        
        if (!empty($filters['shelf'])) {
            $sql .= " AND shelf = ?";
            $params[] = (int)$filters['shelf'];
        }
        
        if (!empty($filters['location_ids']) && is_array($filters['location_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['location_ids']), '?'));
            $sql .= " AND id IN ({$placeholders})";
            $params = array_merge($params, $filters['location_ids']);
        }
        
        $sql .= " ORDER BY zone, shelf, bin";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
