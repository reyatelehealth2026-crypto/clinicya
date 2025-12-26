<?php
/**
 * NotificationService - ระบบแจ้งเตือนรวมศูนย์
 * ส่งแจ้งเตือนผ่าน LINE, Email, Telegram
 */

class NotificationService
{
    private $db;
    private $lineAccountId;
    private $settings = [];
    private $adminRecipients = [];
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }
    
    /**
     * โหลดการตั้งค่าจาก database
     */
    private function loadSettings()
    {
        try {
            $accountId = (int)($this->lineAccountId ?: 0);
            $stmt = $this->db->prepare("SELECT * FROM notification_settings WHERE line_account_id = ?");
            $stmt->execute([$accountId]);
            $this->settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
            // โหลดรายชื่อผู้รับแจ้งเตือน
            $adminIds = array_filter(explode(',', $this->settings['notify_admin_users'] ?? ''));
            if (!empty($adminIds)) {
                $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
                $stmt = $this->db->prepare("SELECT id, username, email, line_user_id, role FROM admin_users WHERE id IN ({$placeholders}) AND is_active = 1");
                $stmt->execute($adminIds);
                $this->adminRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("NotificationService loadSettings error: " . $e->getMessage());
        }
    }
    
    /**
     * ส่งแจ้งเตือนออเดอร์ใหม่
     */
    public function notifyNewOrder($order)
    {
        if (!($this->settings['line_notify_enabled'] ?? 0) || !($this->settings['line_notify_new_order'] ?? 0)) {
            return false;
        }
        
        $message = $this->buildOrderMessage($order, 'new');
        return $this->sendToAdmins($message, 'LINE');
    }
    
    /**
     * ส่งแจ้งเตือนการชำระเงิน
     */
    public function notifyPayment($order, $slipUrl = null)
    {
        if (!($this->settings['line_notify_enabled'] ?? 0) || !($this->settings['line_notify_payment'] ?? 0)) {
            return false;
        }
        
        $message = $this->buildPaymentMessage($order, $slipUrl);
        return $this->sendToAdmins($message, 'LINE');
    }
    
    /**
     * ส่งแจ้งเตือนเคสฉุกเฉิน (Red Flag)
     */
    public function notifyUrgent($data)
    {
        $results = [];
        
        // LINE
        if (($this->settings['line_notify_enabled'] ?? 0) && ($this->settings['line_notify_urgent'] ?? 0)) {
            $message = $this->buildUrgentMessage($data);
            $results['line'] = $this->sendToAdmins($message, 'LINE');
        }
        
        // Email
        if (($this->settings['email_enabled'] ?? 0) && ($this->settings['email_notify_urgent'] ?? 0)) {
            $results['email'] = $this->sendUrgentEmail($data);
        }
        
        // Telegram
        if ($this->settings['telegram_enabled'] ?? 0) {
            $results['telegram'] = $this->sendTelegram($this->buildUrgentMessage($data, 'text'));
        }
        
        return $results;
    }
    
    /**
     * ส่งแจ้งเตือนนัดหมายใหม่
     */
    public function notifyNewAppointment($appointment)
    {
        if (!($this->settings['line_notify_enabled'] ?? 0) || !($this->settings['line_notify_appointment'] ?? 0)) {
            return false;
        }
        
        $message = $this->buildAppointmentMessage($appointment);
        return $this->sendToAdmins($message, 'LINE');
    }
    
    /**
     * ส่งแจ้งเตือนสินค้าใกล้หมด
     */
    public function notifyLowStock($products)
    {
        $results = [];
        
        // LINE
        if (($this->settings['line_notify_enabled'] ?? 0) && ($this->settings['line_notify_low_stock'] ?? 0)) {
            $message = $this->buildLowStockMessage($products);
            $results['line'] = $this->sendToAdmins($message, 'LINE');
        }
        
        // Email
        if (($this->settings['email_enabled'] ?? 0) && ($this->settings['email_notify_low_stock'] ?? 0)) {
            $results['email'] = $this->sendLowStockEmail($products);
        }
        
        return $results;
    }
    
    /**
     * ส่งข้อความไปยัง admin ทุกคนที่ตั้งค่าไว้
     */
    private function sendToAdmins($message, $channel = 'LINE')
    {
        if (empty($this->adminRecipients)) {
            error_log("NotificationService: No admin recipients configured");
            return false;
        }
        
        $token = $this->getChannelAccessToken();
        if (!$token) {
            error_log("NotificationService: No channel access token");
            return false;
        }
        
        $success = 0;
        foreach ($this->adminRecipients as $admin) {
            if (empty($admin['line_user_id'])) continue;
            
            $result = $this->sendLinePush($admin['line_user_id'], $message, $token);
            if ($result) $success++;
        }
        
        return $success > 0;
    }
    
    /**
     * ส่ง LINE Push Message
     */
    private function sendLinePush($lineUserId, $message, $token)
    {
        try {
            $messages = is_array($message) && isset($message['type']) ? [$message] : $message;
            if (!is_array($messages)) {
                $messages = [['type' => 'text', 'text' => $message]];
            }
            
            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'to' => $lineUserId,
                    'messages' => $messages
                ])
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("LINE Push failed ({$httpCode}): {$response}");
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("sendLinePush error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ส่ง Telegram
     */
    private function sendTelegram($message)
    {
        try {
            $stmt = $this->db->query("SELECT * FROM telegram_settings WHERE id = 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings || empty($settings['bot_token']) || empty($settings['chat_id'])) {
                return false;
            }
            
            $url = "https://api.telegram.org/bot{$settings['bot_token']}/sendMessage";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => [
                    'chat_id' => $settings['chat_id'],
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        } catch (Exception $e) {
            error_log("sendTelegram error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ส่ง Email ฉุกเฉิน
     */
    private function sendUrgentEmail($data)
    {
        $emails = array_filter(explode("\n", $this->settings['email_addresses'] ?? ''));
        if (empty($emails)) return false;
        
        $subject = "🚨 [ด่วน] แจ้งเตือนฉุกเฉิน - " . ($data['user_name'] ?? 'ลูกค้า');
        $body = $this->buildUrgentEmailBody($data);
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Notification <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
            'X-Priority: 1'
        ];
        
        $success = 0;
        foreach ($emails as $email) {
            $email = trim($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (mail($email, $subject, $body, implode("\r\n", $headers))) {
                    $success++;
                }
            }
        }
        
        return $success > 0;
    }
    
    /**
     * ส่ง Email สินค้าใกล้หมด
     */
    private function sendLowStockEmail($products)
    {
        $emails = array_filter(explode("\n", $this->settings['email_addresses'] ?? ''));
        if (empty($emails)) return false;
        
        $subject = "📦 แจ้งเตือนสินค้าใกล้หมด - " . count($products) . " รายการ";
        $body = $this->buildLowStockEmailBody($products);
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Notification <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>'
        ];
        
        $success = 0;
        foreach ($emails as $email) {
            $email = trim($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (mail($email, $subject, $body, implode("\r\n", $headers))) {
                    $success++;
                }
            }
        }
        
        return $success > 0;
    }
    
    // ==================== Message Builders ====================
    
    private function buildOrderMessage($order, $type = 'new')
    {
        $orderNum = $order['order_number'] ?? $order['id'] ?? '-';
        $total = number_format($order['total_amount'] ?? 0, 2);
        $customer = $order['customer_name'] ?? $order['display_name'] ?? 'ลูกค้า';
        
        return [
            'type' => 'flex',
            'altText' => "🛒 ออเดอร์ใหม่ #{$orderNum}",
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => '🛒 ออเดอร์ใหม่!', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg']
                    ],
                    'backgroundColor' => '#06C755',
                    'paddingAll' => '15px'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => "#{$orderNum}", 'weight' => 'bold', 'size' => 'xl'],
                        ['type' => 'text', 'text' => "👤 {$customer}", 'size' => 'sm', 'color' => '#666666', 'margin' => 'md'],
                        ['type' => 'text', 'text' => "💰 ฿{$total}", 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755', 'margin' => 'md'],
                        ['type' => 'text', 'text' => '📅 ' . date('d/m/Y H:i'), 'size' => 'xs', 'color' => '#999999', 'margin' => 'md']
                    ],
                    'paddingAll' => '15px'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => ['type' => 'uri', 'label' => '📋 ดูรายละเอียด', 'uri' => $this->getBaseUrl() . '/shop/orders'],
                            'style' => 'primary',
                            'color' => '#06C755'
                        ]
                    ],
                    'paddingAll' => '10px'
                ]
            ]
        ];
    }
    
    private function buildPaymentMessage($order, $slipUrl = null)
    {
        $orderNum = $order['order_number'] ?? $order['id'] ?? '-';
        $total = number_format($order['total_amount'] ?? 0, 2);
        
        $text = "💳 แจ้งชำระเงิน!\n\n";
        $text .= "📦 ออเดอร์: #{$orderNum}\n";
        $text .= "💰 ยอด: ฿{$total}\n";
        $text .= "📅 " . date('d/m/Y H:i');
        
        return ['type' => 'text', 'text' => $text];
    }
    
    private function buildUrgentMessage($data, $format = 'flex')
    {
        $userName = $data['user_name'] ?? 'ลูกค้า';
        $symptoms = implode(', ', $data['symptoms'] ?? ['ไม่ระบุ']);
        $severity = $data['severity'] ?? '-';
        $redFlags = $data['red_flags'] ?? [];
        
        if ($format === 'text') {
            $text = "🚨 แจ้งเตือนฉุกเฉิน!\n\n";
            $text .= "👤 {$userName}\n";
            $text .= "🩺 อาการ: {$symptoms}\n";
            $text .= "📊 ความรุนแรง: {$severity}/10\n";
            if (!empty($redFlags)) {
                $text .= "\n⚠️ Red Flags:\n";
                foreach ($redFlags as $flag) {
                    $text .= "- " . (is_array($flag) ? ($flag['message'] ?? '') : $flag) . "\n";
                }
            }
            return $text;
        }
        
        return [
            'type' => 'flex',
            'altText' => '🚨 แจ้งเตือนฉุกเฉิน!',
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => '🚨 แจ้งเตือนฉุกเฉิน!', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg']
                    ],
                    'backgroundColor' => '#DC2626',
                    'paddingAll' => '15px'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $userName, 'weight' => 'bold', 'size' => 'xl'],
                        ['type' => 'text', 'text' => "🩺 {$symptoms}", 'size' => 'sm', 'wrap' => true, 'margin' => 'md'],
                        ['type' => 'text', 'text' => "📊 ความรุนแรง: {$severity}/10", 'size' => 'sm', 'margin' => 'sm']
                    ],
                    'paddingAll' => '15px'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => ['type' => 'uri', 'label' => '📋 ดูรายละเอียด', 'uri' => $this->getBaseUrl() . '/pharmacist-dashboard'],
                            'style' => 'primary',
                            'color' => '#DC2626'
                        ]
                    ],
                    'paddingAll' => '10px'
                ]
            ]
        ];
    }
    
    private function buildAppointmentMessage($appointment)
    {
        $customer = $appointment['customer_name'] ?? $appointment['display_name'] ?? 'ลูกค้า';
        $date = $appointment['appointment_date'] ?? date('Y-m-d');
        $time = $appointment['appointment_time'] ?? '-';
        $service = $appointment['service_name'] ?? 'ปรึกษาเภสัชกร';
        
        $text = "📅 นัดหมายใหม่!\n\n";
        $text .= "👤 {$customer}\n";
        $text .= "📋 {$service}\n";
        $text .= "🗓️ {$date} เวลา {$time}";
        
        return ['type' => 'text', 'text' => $text];
    }
    
    private function buildLowStockMessage($products)
    {
        $count = count($products);
        $text = "📦 สินค้าใกล้หมด ({$count} รายการ)\n\n";
        
        $i = 0;
        foreach ($products as $product) {
            if ($i >= 5) {
                $text .= "... และอีก " . ($count - 5) . " รายการ";
                break;
            }
            $name = $product['name'] ?? $product['product_name'] ?? '-';
            $stock = $product['stock'] ?? $product['quantity'] ?? 0;
            $text .= "• {$name}: {$stock} ชิ้น\n";
            $i++;
        }
        
        return ['type' => 'text', 'text' => $text];
    }
    
    private function buildUrgentEmailBody($data)
    {
        $userName = htmlspecialchars($data['user_name'] ?? 'ลูกค้า');
        $symptoms = htmlspecialchars(implode(', ', $data['symptoms'] ?? ['ไม่ระบุ']));
        $severity = htmlspecialchars($data['severity'] ?? '-');
        
        return "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='font-family: sans-serif; padding: 20px;'>
    <div style='max-width: 500px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <div style='background: #DC2626; color: white; padding: 20px; text-align: center;'>
            <h1 style='margin: 0;'>🚨 แจ้งเตือนฉุกเฉิน</h1>
        </div>
        <div style='padding: 20px;'>
            <p><strong>👤 ชื่อ:</strong> {$userName}</p>
            <p><strong>🩺 อาการ:</strong> {$symptoms}</p>
            <p><strong>📊 ความรุนแรง:</strong> {$severity}/10</p>
            <p style='text-align: center; margin-top: 20px;'>
                <a href='{$this->getBaseUrl()}/pharmacist-dashboard' style='display: inline-block; background: #DC2626; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;'>ดูรายละเอียด</a>
            </p>
        </div>
    </div>
</body></html>";
    }
    
    private function buildLowStockEmailBody($products)
    {
        $rows = '';
        foreach ($products as $product) {
            $name = htmlspecialchars($product['name'] ?? $product['product_name'] ?? '-');
            $stock = (int)($product['stock'] ?? $product['quantity'] ?? 0);
            $rows .= "<tr><td style='padding: 8px; border-bottom: 1px solid #eee;'>{$name}</td><td style='padding: 8px; border-bottom: 1px solid #eee; text-align: right;'>{$stock}</td></tr>";
        }
        
        return "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='font-family: sans-serif; padding: 20px;'>
    <div style='max-width: 500px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <div style='background: #F59E0B; color: white; padding: 20px; text-align: center;'>
            <h1 style='margin: 0;'>📦 สินค้าใกล้หมด</h1>
        </div>
        <div style='padding: 20px;'>
            <table style='width: 100%;'>
                <tr><th style='text-align: left; padding: 8px;'>สินค้า</th><th style='text-align: right; padding: 8px;'>คงเหลือ</th></tr>
                {$rows}
            </table>
            <p style='text-align: center; margin-top: 20px;'>
                <a href='{$this->getBaseUrl()}/inventory/low-stock' style='display: inline-block; background: #F59E0B; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;'>ดูรายละเอียด</a>
            </p>
        </div>
    </div>
</body></html>";
    }
    
    private function getChannelAccessToken()
    {
        try {
            if ($this->lineAccountId) {
                $stmt = $this->db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
                $stmt->execute([$this->lineAccountId]);
            } else {
                $stmt = $this->db->query("SELECT channel_access_token FROM line_accounts WHERE is_active = 1 LIMIT 1");
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['channel_access_token'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        return $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
