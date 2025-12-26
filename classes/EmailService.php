<?php
/**
 * EmailService - ส่ง Email ผ่าน SMTP หรือ PHP mail()
 * รองรับทั้ง SMTP และ PHP mail() function
 */

class EmailService
{
    private $db;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpSecure; // 'tls' or 'ssl'
    private $fromEmail;
    private $fromName;
    private $useSmtp = false;
    
    public function __construct($db = null)
    {
        $this->db = $db;
        $this->loadSettings();
    }
    
    /**
     * โหลดการตั้งค่า SMTP จาก database หรือ config
     */
    private function loadSettings()
    {
        // ลองโหลดจาก database ก่อน
        if ($this->db) {
            try {
                $stmt = $this->db->query("SELECT * FROM email_settings WHERE id = 1");
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($settings && !empty($settings['smtp_host'])) {
                    $this->smtpHost = $settings['smtp_host'];
                    $this->smtpPort = $settings['smtp_port'] ?? 587;
                    $this->smtpUser = $settings['smtp_user'] ?? '';
                    $this->smtpPass = $settings['smtp_pass'] ?? '';
                    $this->smtpSecure = $settings['smtp_secure'] ?? 'tls';
                    $this->fromEmail = $settings['from_email'] ?? '';
                    $this->fromName = $settings['from_name'] ?? 'Notification';
                    $this->useSmtp = true;
                    return;
                }
            } catch (Exception $e) {
                // Table doesn't exist, use defaults
            }
        }
        
        // ใช้ค่า default
        $this->fromEmail = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $this->fromName = 'Notification System';
        $this->useSmtp = false;
    }
    
    /**
     * ส่ง Email
     */
    public function send($to, $subject, $body, $isHtml = true)
    {
        // ถ้ามี SMTP settings ให้ใช้ SMTP
        if ($this->useSmtp) {
            return $this->sendSmtp($to, $subject, $body, $isHtml);
        }
        
        // Fallback ไปใช้ PHP mail()
        return $this->sendMail($to, $subject, $body, $isHtml);
    }
    
    /**
     * ส่งผ่าน PHP mail()
     */
    private function sendMail($to, $subject, $body, $isHtml = true)
    {
        $headers = [
            'MIME-Version: 1.0',
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3'
        ];
        
        if ($isHtml) {
            $headers[] = 'Content-type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-type: text/plain; charset=UTF-8';
        }
        
        $headers[] = 'From: ' . $this->fromName . ' <' . $this->fromEmail . '>';
        
        $result = @mail($to, $subject, $body, implode("\r\n", $headers));
        
        if (!$result) {
            error_log("EmailService mail() failed to: {$to}");
        } else {
            error_log("EmailService mail() sent to: {$to}");
        }
        
        return $result;
    }
    
    /**
     * ส่งผ่าน SMTP (ใช้ socket โดยตรง)
     */
    private function sendSmtp($to, $subject, $body, $isHtml = true)
    {
        try {
            $secure = $this->smtpSecure === 'ssl' ? 'ssl://' : '';
            $socket = @fsockopen($secure . $this->smtpHost, $this->smtpPort, $errno, $errstr, 30);
            
            if (!$socket) {
                error_log("SMTP connection failed: {$errstr} ({$errno})");
                return $this->sendMail($to, $subject, $body, $isHtml); // Fallback
            }
            
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '220') {
                error_log("SMTP greeting failed: {$response}");
                fclose($socket);
                return $this->sendMail($to, $subject, $body, $isHtml);
            }
            
            // EHLO
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            $this->getSmtpResponse($socket);
            
            // STARTTLS if needed
            if ($this->smtpSecure === 'tls') {
                fputs($socket, "STARTTLS\r\n");
                $response = $this->getSmtpResponse($socket);
                if (substr($response, 0, 3) == '220') {
                    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    fputs($socket, "EHLO " . gethostname() . "\r\n");
                    $this->getSmtpResponse($socket);
                }
            }
            
            // AUTH LOGIN
            if (!empty($this->smtpUser)) {
                fputs($socket, "AUTH LOGIN\r\n");
                $this->getSmtpResponse($socket);
                fputs($socket, base64_encode($this->smtpUser) . "\r\n");
                $this->getSmtpResponse($socket);
                fputs($socket, base64_encode($this->smtpPass) . "\r\n");
                $response = $this->getSmtpResponse($socket);
                if (substr($response, 0, 3) != '235') {
                    error_log("SMTP auth failed: {$response}");
                    fclose($socket);
                    return $this->sendMail($to, $subject, $body, $isHtml);
                }
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM:<{$this->fromEmail}>\r\n");
            $this->getSmtpResponse($socket);
            
            // RCPT TO
            fputs($socket, "RCPT TO:<{$to}>\r\n");
            $this->getSmtpResponse($socket);
            
            // DATA
            fputs($socket, "DATA\r\n");
            $this->getSmtpResponse($socket);
            
            // Headers
            $contentType = $isHtml ? 'text/html' : 'text/plain';
            $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
            $headers .= "To: {$to}\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: {$contentType}; charset=UTF-8\r\n";
            $headers .= "\r\n";
            
            fputs($socket, $headers . $body . "\r\n.\r\n");
            $response = $this->getSmtpResponse($socket);
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            $success = substr($response, 0, 3) == '250';
            if ($success) {
                error_log("EmailService SMTP sent to: {$to}");
            } else {
                error_log("EmailService SMTP failed: {$response}");
            }
            
            return $success;
        } catch (Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return $this->sendMail($to, $subject, $body, $isHtml);
        }
    }
    
    private function getSmtpResponse($socket)
    {
        $response = '';
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $response;
    }
    
    /**
     * ส่ง Email ทดสอบ
     */
    public function sendTest($to)
    {
        $subject = "🔔 ทดสอบการแจ้งเตือน Email";
        $body = $this->buildTestEmailBody();
        return $this->send($to, $subject, $body, true);
    }
    
    private function buildTestEmailBody()
    {
        return "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;'>
    <div style='max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <div style='width: 60px; height: 60px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;'>
                <span style='font-size: 30px;'>✅</span>
            </div>
        </div>
        <h2 style='color: #059669; margin-bottom: 20px; text-align: center;'>ทดสอบการแจ้งเตือน</h2>
        <p style='text-align: center; color: #374151;'>ระบบแจ้งเตือน Email ทำงานปกติ</p>
        <p style='text-align: center; color: #6b7280; font-size: 14px;'>📅 " . date('Y-m-d H:i:s') . "</p>
        <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
        <p style='text-align: center; color: #9ca3af; font-size: 12px;'>ข้อความนี้ส่งจากระบบอัตโนมัติ</p>
    </div>
</body>
</html>";
    }
}
