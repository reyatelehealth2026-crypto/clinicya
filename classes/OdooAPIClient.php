<?php
/**
 * Odoo API Client
 * 
 * Handles all API communication with Odoo ERP using JSON-RPC 2.0 format.
 * Includes rate limiting, error handling, retry logic, and comprehensive logging.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

require_once __DIR__ . '/SecurityHelper.php';

class OdooAPIClient
{
    private $db;
    /** @var SecurityHelper */
    private $securityHelper;
    private $lineAccountId;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $connectTimeout;
    private $rateLimit;
    private static $tableExistsCache = [];
    private static $rateLimitWindow = ['minute' => null, 'count' => null];

    /**
     * Error code to Thai message mapping
     */
    private const ERROR_MESSAGES = [
        'MISSING_API_KEY' => 'ไม่พบ API Key กรุณาติดต่อผู้ดูแลระบบ',
        'INVALID_API_KEY' => 'API Key ไม่ถูกต้อง',
        'MISSING_PARAMETER' => 'ข้อมูลไม่ครบถ้วน',
        'LINE_USER_NOT_LINKED' => 'กรุณาเชื่อมต่อบัญชี Odoo ก่อนใช้งาน',
        'PARTNER_NOT_FOUND' => 'ไม่พบข้อมูลลูกค้า กรุณาตรวจสอบข้อมูลอีกครั้ง',
        'ORDER_NOT_FOUND' => 'ไม่พบออเดอร์',
        'INVOICE_NOT_FOUND' => 'ไม่พบใบแจ้งหนี้',
        'BDO_NOT_FOUND' => 'ไม่พบข้อมูล BDO',
        'SLIP_NOT_FOUND' => 'ไม่พบสลิป',
        'CUSTOMER_MISMATCH' => 'ออเดอร์นี้ไม่ใช่ของคุณ',
        'ALREADY_LINKED' => 'บัญชี LINE นี้เชื่อมต่อกับบัญชีอื่นแล้ว',
        'NOT_LINKED' => 'กรุณาเชื่อมต่อบัญชี Odoo ก่อน',
        'INVALID_IMAGE' => 'รูปภาพไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง',
        'RATE_LIMIT_EXCEEDED' => 'มีการเรียกใช้งานมากเกินไป กรุณารอสักครู่',
        'NETWORK_ERROR' => 'เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง',
        'TIMEOUT_ERROR' => 'การเชื่อมต่อหมดเวลา กรุณาลองใหม่อีกครั้ง',
        'UNKNOWN_ERROR' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ กรุณาติดต่อผู้ดูแลระบบ',
        // v11.0.1.2.0 - Security Enhancement: customer_code requires phone
        'PHONE_REQUIRED' => 'เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน',
        'PHONE_MISMATCH' => 'เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า กรุณาตรวจสอบรหัสลูกค้าและเบอร์โทรศัพท์',
        'CUSTOMER_CODE_NOT_FOUND' => 'ไม่พบรหัสลูกค้า กรุณาตรวจสอบรหัสอีกครั้ง'
    ];

    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID (nullable for shared mode)
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 3;
        $this->apiKey = ODOO_API_KEY;
        $this->baseUrl = rtrim(ODOO_API_BASE_URL, '/');
        $this->timeout = ODOO_API_TIMEOUT;
        $this->connectTimeout = defined('ODOO_API_CONNECT_TIMEOUT') ? (int) ODOO_API_CONNECT_TIMEOUT : 3;
        $this->rateLimit = ODOO_API_RATE_LIMIT;

        if (empty($this->apiKey)) {
            throw new Exception('MISSING_API_KEY');
        }
    }

    /**
     * Make API call to Odoo using JSON-RPC 2.0 format
     * 
     * @param string $endpoint API endpoint (e.g., '/reya/orders')
     * @param array $params Request parameters
     * @param int $retryCount Number of retries (default: 3)
     * @return array API response data
     * @throws Exception on error
     */
    public function call($endpoint, $params = [], $retryCount = null)
    {
        $maxRetries = $retryCount === null
            ? (defined('ODOO_API_RETRY_LIMIT') ? max(0, (int) ODOO_API_RETRY_LIMIT) : 1)
            : max(0, (int) $retryCount);
        $timeout = $this->resolveTimeoutForEndpoint($endpoint);
        $connectTimeout = min(max(1, $this->connectTimeout), max(1, $timeout - 1));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $startTime = microtime(true);
            $logged = false;

            try {
                if (!$this->checkRateLimit()) {
                    throw new Exception('RATE_LIMIT_EXCEEDED');
                }

                $requestBody = [
                    'jsonrpc' => '2.0',
                    'params' => $params
                ];

                $ch = curl_init($this->baseUrl . $endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($requestBody),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-Api-Key: ' . $this->apiKey
                    ],
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                $duration = round((microtime(true) - $startTime) * 1000);

                if ($response === false) {
                    if ($attempt < $maxRetries && $this->shouldRetryCurlError($curlErrno, $curlError)) {
                        $this->sleepBeforeRetry($attempt);
                        continue;
                    }

                    throw new Exception('NETWORK_ERROR: ' . $curlError);
                }

                $data = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("OdooAPI Invalid JSON from $endpoint: " . mb_substr($response, 0, 500));
                    throw new Exception('Invalid JSON response: ' . json_last_error_msg());
                }

                if ($httpCode >= 400 && $attempt < $maxRetries && $this->shouldRetryHttpStatus($httpCode)) {
                    $this->sleepBeforeRetry($attempt);
                    continue;
                }

                $this->logApiCall($endpoint, $params, $data, $httpCode, $duration);
                $logged = true;

                if ($httpCode >= 400) {
                    $this->handleError($data, $httpCode);
                }

                if ($httpCode === 200 && isset($data['error'])) {
                    if ($this->shouldDebugLog()) {
                        error_log("OdooAPI 200 with error field from $endpoint: " . json_encode($data['error']));
                    }
                    return [];
                }

                if ($httpCode === 200 && empty($data)) {
                    if ($this->shouldDebugLog()) {
                        error_log("OdooAPI empty response from $endpoint, returning []");
                    }
                    return [];
                }

                if ($httpCode === 200 && !isset($data['jsonrpc']) && !isset($data['result'])) {
                    if ($this->shouldDebugLog()) {
                        error_log("OdooAPI non-JSON-RPC response from $endpoint, returning as-is");
                    }
                    return $data;
                }

                return $data['result'] ?? $data;
            } catch (Exception $e) {
                if (!$logged) {
                    $this->logApiCall(
                        $endpoint,
                        $params,
                        ['error' => $e->getMessage()],
                        0,
                        round((microtime(true) - $startTime) * 1000),
                        $e->getMessage()
                    );
                }
                throw $e;
            }
        }

        throw new Exception('NETWORK_ERROR: retry limit exhausted');
    }

    /**
     * Health check - test connection to Odoo
     * 
     * @return array Health status
     */
    public function health()
    {
        try {
            $result = $this->call('/reya/health', []);
            return [
                'success' => true,
                'status' => $result['status'] ?? 'ok',
                'message' => 'Connected to Odoo successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // ========================================================================
    // User Linking Methods
    // ========================================================================

    /**
     * Link LINE user to Odoo partner account
     * 
     * @param string $lineUserId LINE user ID
     * @param string|null $phone Phone number
     * @param string|null $customerCode Customer code
     * @param string|null $email Email address
     * @return array Partner information
     */
    public function linkUser($lineUserId, $phone = null, $customerCode = null, $email = null)
    {
        $params = ['line_user_id' => $lineUserId];

        if ($phone)
            $params['phone'] = $phone;
        if ($customerCode)
            $params['customer_code'] = $customerCode;
        if ($email)
            $params['email'] = $email;

        return $this->call('/reya/user/link', $params);
    }

    /**
     * Unlink LINE user from Odoo partner
     * 
     * @param string $lineUserId LINE user ID
     * @return array Success status
     */
    public function unlinkUser($lineUserId)
    {
        return $this->call('/reya/user/unlink', ['line_user_id' => $lineUserId]);
    }

    /**
     * Get user profile from Odoo
     * 
     * @param string $lineUserId LINE user ID
     * @return array User profile
     */
    public function getUserProfile($lineUserId)
    {
        return $this->call('/reya/user/profile', ['line_user_id' => $lineUserId]);
    }

    /**
     * Update notification settings
     * 
     * @param string $lineUserId LINE user ID
     * @param bool $enabled Enable/disable notifications
     * @return array Success status
     */
    public function updateNotification($lineUserId, $enabled)
    {
        return $this->call('/reya/user/notification', [
            'line_user_id' => $lineUserId,
            'enabled' => $enabled
        ]);
    }

    // ========================================================================
    // Order Methods
    // ========================================================================

    /**
     * Get orders list
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, date_from, date_to, limit, offset)
     * @return array Orders list
     */
    public function getOrders($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/orders', $params);
    }

    /**
     * Get order detail
     * 
     * @param int $orderId Order ID
     * @param string $lineUserId LINE user ID
     * @return array Order details
     */
    public function getOrderDetail($orderId, $lineUserId)
    {
        return $this->call('/reya/order/detail', [
            'order_id' => $orderId,
            'line_user_id' => $lineUserId
        ]);
    }

    /**
     * Get order tracking timeline
     * 
     * @param int $orderId Order ID
     * @param string $lineUserId LINE user ID
     * @return array Tracking timeline
     */
    public function getOrderTracking($orderId, $lineUserId)
    {
        return $this->call('/reya/order/tracking', [
            'order_id' => $orderId,
            'line_user_id' => $lineUserId
        ]);
    }

    // ========================================================================
    // Invoice Methods
    // ========================================================================

    /**
     * Get invoices list
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, limit, offset)
     * @return array Invoices list
     */
    public function getInvoices($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/invoices', $params);
    }

    /**
     * Get credit status
     * 
     * @param string $lineUserId LINE user ID
     * @return array Credit status
     */
    public function getCreditStatus($lineUserId)
    {
        return $this->call('/reya/credit-status', ['line_user_id' => $lineUserId]);
    }

    // ========================================================================
    // Payment Methods
    // ========================================================================

    /**
     * Upload payment slip
     * 
     * @param string $lineUserId LINE user ID
     * @param string $slipImageBase64 Base64 encoded slip image
     * @param array $options Additional options (bdo_id, invoice_id, amount, transfer_date)
     * @return array Upload result with auto-match status
     */
    public function uploadSlip($lineUserId, $slipImageBase64, $options = [])
    {
        $params = array_merge([
            'line_user_id' => $lineUserId,
            'slip_image' => $slipImageBase64
        ], $options);

        return $this->call('/reya/slip/upload', $params);
    }

    /**
     * Upload payment slip via multipart/form-data (matches Odoo spec: POST /reya/slip/upload)
     *
     * @param string $lineUserId  LINE User ID ของลูกค้า
     * @param string $imageData   Binary image data (jpg/png/pdf)
     * @param string $filename    ชื่อไฟล์ เช่น slip.jpg
     * @param string $mimeType    MIME type เช่น image/jpeg
     * @param array  $options     Optional: amount, transfer_date, invoice_id, order_id
     * @return array Upload result from Odoo
     */
    public function uploadSlipMultipart($lineUserId, $imageData, $filename = 'slip.jpg', $mimeType = 'image/jpeg', $options = [])
    {
        $startTime = microtime(true);

        // Write image data to a temp file so CURLFile can reference it
        $tmpFile = tempnam(sys_get_temp_dir(), 'slip_');
        file_put_contents($tmpFile, $imageData);

        try {
            $postFields = [
                'line_user_id' => $lineUserId,
                'file'         => new CURLFile($tmpFile, $mimeType, $filename),
            ];

            if (!empty($options['amount']))
                $postFields['amount'] = (float) $options['amount'];
            if (!empty($options['transfer_date']))
                $postFields['transfer_date'] = $options['transfer_date'];
            if (!empty($options['invoice_id']))
                $postFields['invoice_id'] = (int) $options['invoice_id'];
            if (!empty($options['order_id']))
                $postFields['order_id'] = (int) $options['order_id'];

            $uploadUrl = $this->baseUrl . '/reya/slip/upload';
            $timeout = $this->resolveTimeoutForEndpoint('/reya/slip/upload');
            $connectTimeout = min(max(1, $this->connectTimeout), max(1, $timeout - 1));
            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_HTTPHEADER     => [
                    'X-Api-Key: ' . $this->apiKey,
                    'X-Requested-With: XMLHttpRequest',
                    'X-CSRF-Token: 1',   // Odoo Werkzeug: non-empty value bypasses CSRF check
                ],
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $response     = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            $contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000);

            if ($response === false) {
                throw new Exception('NETWORK_ERROR: ' . $curlError);
            }

            if ($httpCode >= 400 || $this->shouldDebugLog()) {
                error_log("[OdooAPIClient::uploadSlipMultipart] URL=$uploadUrl HTTP=$httpCode CT=$contentType RAW=" . mb_substr($response, 0, 500));
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("HTTP $httpCode (Content-Type: $contentType): Odoo returned non-JSON — " . mb_substr($response, 0, 500));
            }

            $this->logApiCall('/reya/slip/upload', ['line_user_id' => $lineUserId], $data, $httpCode, $duration);

            if ($httpCode >= 400) {
                $odooMsg = $data['message'] ?? ($data['error'] ?? null);
                $detail  = $odooMsg ? "$odooMsg" : mb_substr($response, 0, 300);
                throw new Exception("HTTP $httpCode: $detail");
            }

            return $data;

        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Get payment status
     * 
     * @param string $lineUserId LINE user ID
     * @param int|null $orderId Order ID
     * @param int|null $bdoId BDO ID
     * @param int|null $invoiceId Invoice ID
     * @return array Payment status
     */
    public function getPaymentStatus($lineUserId, $orderId = null, $bdoId = null, $invoiceId = null)
    {
        $params = ['line_user_id' => $lineUserId];

        if ($orderId)
            $params['order_id'] = $orderId;
        if ($bdoId)
            $params['bdo_id'] = $bdoId;
        if ($invoiceId)
            $params['invoice_id'] = $invoiceId;

        return $this->call('/reya/payment/status', $params);
    }

    // ========================================================================
    // BDO Methods (Staging-ready endpoints)
    // ========================================================================

    /**
     * Get BDO list for a customer
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, limit, offset)
     * @return array BDO list
     */
    public function getBdoList($lineUserId = null, $options = [])
    {
        $params = $options;
        if ($lineUserId !== null && $lineUserId !== '') {
            $params['line_user_id'] = $lineUserId;
        }
        return $this->call('/reya/bdo/list', $params);
    }

    /**
     * Get BDO detail (SO lines, invoices, credit notes, deposits, summary)
     * 
     * @param string $lineUserId LINE user ID
     * @param int $bdoId BDO ID
     * @return array BDO detail
     */
    public function getBdoDetail($lineUserId, $bdoId)
    {
        return $this->call('/reya/bdo/detail', [
            'line_user_id' => $lineUserId,
            'bdo_id' => (int) $bdoId
        ]);
    }

    /**
     * Download BDO Statement PDF from Odoo
     * 
     * Uses GET with api_key query param (binary response).
     * Returns raw PDF binary or throws on error.
     * 
     * @param int $bdoId BDO ID
     * @return string Raw PDF binary data
     * @throws Exception on error
     */
    public function getStatementPdf($bdoId)
    {
        $url = $this->baseUrl . '/reya/bdo/statement-pdf/' . (int) $bdoId . '?api_key=' . urlencode($this->apiKey);
        $timeout = $this->resolveTimeoutForEndpoint('/reya/bdo/statement-pdf');
        $connectTimeout = min(max(1, $this->connectTimeout), max(1, $timeout - 1));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('NETWORK_ERROR: ' . $curlError);
        }

        if ($httpCode >= 400) {
            throw new Exception("Statement PDF download failed: HTTP $httpCode");
        }

        if (strpos($contentType, 'application/pdf') === false && strpos($contentType, 'application/octet-stream') === false) {
            throw new Exception("Unexpected content type: $contentType");
        }

        return $response;
    }

    // ========================================================================
    // Slip ↔ BDO Matching Methods (Staging-ready endpoints)
    // ========================================================================

    /**
     * Match a slip to one or more BDOs
     * 
     * Sales กดจับคู่ใน Re-Ya Dashboard → ส่งผลไป Odoo
     * 
     * @param string $lineUserId LINE user ID
     * @param int $slipInboxId Slip Inbox record ID from Odoo
     * @param array $matches Array of [{bdo_id: int, amount: float}, ...]
     * @param string $note Optional note
     * @return array Match result from Odoo
     */
    public function matchSlipBdo($lineUserId, $slipInboxId, array $matches, $note = '')
    {
        $params = [
            'line_user_id' => $lineUserId,
            'slip_inbox_id' => (int) $slipInboxId,
            'matches' => $matches
        ];

        if ($note !== '') {
            $params['note'] = $note;
        }

        return $this->call('/reya/slip/match-bdo', $params);
    }

    /**
     * Unmatch a slip (ยกเลิกการจับคู่)
     * 
     * @param string $lineUserId LINE user ID
     * @param int $slipInboxId Slip Inbox record ID from Odoo
     * @param string $reason Reason for unmatch
     * @return array Unmatch result from Odoo
     */
    public function unmatchSlip($lineUserId, $slipInboxId, $reason = '')
    {
        $params = [
            'line_user_id' => $lineUserId,
            'slip_inbox_id' => (int) $slipInboxId
        ];

        if ($reason !== '') {
            $params['reason'] = $reason;
        }

        return $this->call('/reya/slip/unmatch', $params);
    }

    // ========================================================================
    // Salesperson Methods
    // ========================================================================

    /**
     * Get salesperson dashboard
     * 
     * @param string $lineUserId LINE user ID
     * @param string $period Period (today, week, month)
     * @return array Dashboard data
     */
    public function getSalespersonDashboard($lineUserId, $period = 'today')
    {
        return $this->call('/reya/salesperson/dashboard', [
            'line_user_id' => $lineUserId,
            'period' => $period
        ]);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Handle API error response
     * 
     * @param array $response Error response
     * @param int $httpCode HTTP status code
     * @throws Exception
     */
    private function handleError($response, $httpCode = 0)
    {
        $errorCode = $response['error']['code'] ?? 'UNKNOWN_ERROR';
        $errorMessage = $response['error']['message'] ?? 'Unknown error';

        // Get Thai error message
        $thaiMessage = self::ERROR_MESSAGES[$errorCode] ?? self::ERROR_MESSAGES['UNKNOWN_ERROR'];

        throw new Exception($thaiMessage . ' (' . $errorCode . ')', $httpCode);
    }

    /**
     * Check rate limit
     * 
     * @return bool True if within rate limit
     */
    private function checkRateLimit()
    {
        try {
            if (!$this->tableExists('odoo_api_logs')) {
                return true;
            }

            $currentMinute = date('Y-m-d H:i');
            if (self::$rateLimitWindow['minute'] === $currentMinute && self::$rateLimitWindow['count'] !== null) {
                return self::$rateLimitWindow['count'] < $this->rateLimit;
            }

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM odoo_api_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'] ?? 0;
            self::$rateLimitWindow = ['minute' => $currentMinute, 'count' => (int) $count];

            return $count < $this->rateLimit;

        } catch (Exception $e) {
            // If rate limit check fails, allow the request
            return true;
        }
    }

    /**
     * Log API call to database (optional)
     * 
     * @param string $endpoint Endpoint called
     * @param array $params Request parameters
     * @param array $response Response data
     * @param int $statusCode HTTP status code
     * @param int $duration Duration in milliseconds
     * @param string|null $error Error message if failed
     */
    private function logApiCall($endpoint, $params, $response, $statusCode, $duration, $error = null)
    {
        try {
            if (!$this->tableExists('odoo_api_logs')) {
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO odoo_api_logs 
                (line_account_id, endpoint, method, request_params, response_data, 
                 status_code, error_message, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $this->lineAccountId,
                $endpoint,
                'POST',
                json_encode(class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($params) : $params),
                json_encode(class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($response) : $response),
                $statusCode,
                $error,
                $duration
            ]);

            $currentMinute = date('Y-m-d H:i');
            if (self::$rateLimitWindow['minute'] === $currentMinute && self::$rateLimitWindow['count'] !== null) {
                self::$rateLimitWindow['count']++;
            }
        } catch (Exception $e) {
            // Logging failed, but don't throw error
            error_log('Failed to log Odoo API call: ' . $e->getMessage());
        }
    }

    private function tableExists($table)
    {
        if (array_key_exists($table, self::$tableExistsCache)) {
            return self::$tableExistsCache[$table];
        }

        try {
            $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($table));
            self::$tableExistsCache[$table] = $stmt && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            self::$tableExistsCache[$table] = false;
        }

        return self::$tableExistsCache[$table];
    }

    private function shouldDebugLog()
    {
        return defined('ODOO_API_DEBUG_LOG') && ODOO_API_DEBUG_LOG;
    }

    private function shouldRetryCurlError($curlErrno, $curlError)
    {
        if (in_array((int) $curlErrno, [6, 7, 18, 28, 52, 56], true)) {
            return true;
        }

        $message = strtolower((string) $curlError);
        foreach (['timeout', 'timed out', 'connection reset', 'temporarily unavailable', 'empty reply'] as $needle) {
            if ($message !== '' && strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function shouldRetryHttpStatus($httpCode)
    {
        return in_array((int) $httpCode, [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function sleepBeforeRetry($attempt)
    {
        $baseDelayMs = 250 * (2 ** max(0, (int) $attempt));
        $delayMs = min(2000, $baseDelayMs + random_int(0, 150));
        usleep($delayMs * 1000);
    }

    private function resolveTimeoutForEndpoint($endpoint)
    {
        $endpoint = (string) $endpoint;

        if (strpos($endpoint, '/reya/health') === 0) {
            return min($this->timeout, 5);
        }

        if (strpos($endpoint, '/reya/slip/upload') === 0 || strpos($endpoint, '/reya/bdo/statement-pdf') === 0) {
            return max($this->timeout, 25);
        }

        $dashboardReadEndpoints = [
            '/reya/orders',
            '/reya/invoices',
            '/reya/payment/status',
            '/reya/credit-status',
            '/reya/bdo/list',
            '/reya/bdo/detail',
            '/reya/user/profile',
        ];

        if (in_array($endpoint, $dashboardReadEndpoints, true)) {
            return min($this->timeout, 12);
        }

        return $this->timeout;
    }
}
