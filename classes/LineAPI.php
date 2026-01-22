<?php
/**
 * LINE Messaging API Class
 */

class LineAPI
{
    private $channelAccessToken;
    private $channelSecret;
    private $apiEndpoint = 'https://api.line.me/v2/bot';

    public function __construct($accessToken = null, $secret = null)
    {
        $this->channelAccessToken = $accessToken ?? (defined('LINE_CHANNEL_ACCESS_TOKEN') ? LINE_CHANNEL_ACCESS_TOKEN : '');
        $this->channelSecret = $secret ?? (defined('LINE_CHANNEL_SECRET') ? LINE_CHANNEL_SECRET : '');
    }

    /**
     * Get Access Token
     */
    public function getAccessToken(): string
    {
        return $this->channelAccessToken;
    }

    /**
     * Get Bot Info
     */
    public function getBotInfo()
    {
        $url = $this->apiEndpoint . '/info';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Send request to LINE API
     */
    private function sendRequest($endpoint, $data = null, $method = 'POST')
    {
        $url = $this->apiEndpoint . $endpoint;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Reply to message
     */
    public function replyMessage($replyToken, $messages)
    {
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        }
        return $this->sendRequest('/message/reply', [
            'replyToken' => $replyToken,
            'messages' => $messages
        ]);
    }

    /**
     * Push message to user
     */
    public function pushMessage($userId, $messages)
    {
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        }
        return $this->sendRequest('/message/push', [
            'to' => $userId,
            'messages' => $messages
        ]);
    }

    /**
     * Smart send message - ใช้ replyToken ก่อนถ้ามี (ฟรี!) ถ้าไม่มีค่อย fallback ไป pushMessage
     * 
     * @param string $userId LINE User ID
     * @param mixed $messages ข้อความ (string หรือ array)
     * @param string|null $replyToken Reply token จาก webhook (optional)
     * @param string|null $tokenExpires เวลาหมดอายุของ token (optional)
     * @param PDO|null $db Database connection สำหรับ clear token หลังใช้ (optional)
     * @param int|null $internalUserId Internal user ID สำหรับ clear token (optional)
     * @return array ['code' => int, 'body' => array, 'method' => 'reply'|'push']
     */
    public function sendMessage($userId, $messages, $replyToken = null, $tokenExpires = null, $db = null, $internalUserId = null)
    {
        // Normalize messages
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        } elseif (isset($messages['type'])) {
            // Single message object, wrap in array
            $messages = [$messages];
        }

        // Try reply token first (FREE!)
        if ($replyToken && !empty($replyToken)) {
            // Check if token is still valid (LINE reply tokens expire in ~30 seconds)
            $isValid = true;
            if ($tokenExpires) {
                $isValid = strtotime($tokenExpires) > time();
            }

            if ($isValid) {
                $result = $this->replyMessage($replyToken, $messages);

                // Clear token after use (success or fail - token is single-use)
                $this->clearReplyToken($db, $internalUserId, $userId);

                if ($result['code'] === 200) {
                    $result['method'] = 'reply';
                    return $result;
                }
                // Reply failed, fallback to push
                error_log("LineAPI: replyMessage failed (code: {$result['code']}), falling back to pushMessage");
            } else {
                // Token expired, clear it
                $this->clearReplyToken($db, $internalUserId, $userId);
            }
        }

        // Fallback to push message
        $result = $this->pushMessage($userId, $messages);
        $result['method'] = 'push';
        return $result;
    }

    /**
     * Clear reply token from database (token is single-use)
     */
    private function clearReplyToken($db, $internalUserId = null, $lineUserId = null)
    {
        if (!$db)
            return;

        try {
            if ($internalUserId) {
                $stmt = $db->prepare("UPDATE users SET reply_token = NULL, reply_token_expires = NULL WHERE id = ?");
                $stmt->execute([$internalUserId]);
            } elseif ($lineUserId) {
                $stmt = $db->prepare("UPDATE users SET reply_token = NULL, reply_token_expires = NULL WHERE line_user_id = ?");
                $stmt->execute([$lineUserId]);
            }
        } catch (\Exception $e) {
            error_log("LineAPI: Failed to clear reply token: " . $e->getMessage());
        }
    }

    /**
     * Send message with auto-fetch reply token from database
     * 
     * @param PDO $db Database connection
     * @param int $userId Internal user ID (not LINE user ID)
     * @param mixed $messages ข้อความ
     * @return array
     */
    public function sendMessageToUser($db, $userId, $messages)
    {
        // Get user info with reply token
        $stmt = $db->prepare("SELECT line_user_id, reply_token, reply_token_expires FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !$user['line_user_id']) {
            return ['code' => 400, 'body' => ['message' => 'User not found'], 'method' => 'none'];
        }

        // Pass $db and $userId to clear token after use
        return $this->sendMessage(
            $user['line_user_id'],
            $messages,
            $user['reply_token'] ?? null,
            $user['reply_token_expires'] ?? null,
            $db,
            $userId
        );
    }


    /**
     * Broadcast message to all followers
     */
    public function broadcastMessage($messages)
    {
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        }
        return $this->sendRequest('/message/broadcast', ['messages' => $messages]);
    }

    /**
     * Multicast message to multiple users
     */
    public function multicastMessage($userIds, $messages)
    {
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        }
        return $this->sendRequest('/message/multicast', [
            'to' => $userIds,
            'messages' => $messages
        ]);
    }

    /**
     * Narrowcast message - ส่งข้อความแบบจำกัดจำนวนถึงเพื่อนทั้งหมดใน LINE OA
     * 
     * @param array $messages ข้อความที่จะส่ง
     * @param int|null $maxLimit จำนวนสูงสุดที่ต้องการส่ง (null = ไม่จำกัด)
     * @param array|null $recipient ตัวกรองผู้รับ (audience, demographic filter)
     * @param array|null $filter ตัวกรองเพิ่มเติม (demographic)
     * @return array ['code' => int, 'body' => array, 'requestId' => string]
     */
    public function narrowcastMessage($messages, $maxLimit = null, $recipient = null, $filter = null)
    {
        if (!is_array($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        }

        $data = ['messages' => $messages];

        // เพิ่ม recipient filter (audience หรือ redelivery)
        if ($recipient) {
            $data['recipient'] = $recipient;
        }

        // เพิ่ม demographic filter
        if ($filter) {
            $data['filter'] = $filter;
        }

        // เพิ่ม limit (จำกัดจำนวนผู้รับ)
        if ($maxLimit && $maxLimit > 0) {
            $data['limit'] = [
                'max' => (int) $maxLimit,
                'upToRemainingQuota' => true // ส่งเท่าที่ quota เหลือถ้าไม่พอ
            ];
        }

        // Narrowcast ใช้ endpoint ต่างจาก broadcast
        $url = $this->apiEndpoint . '/message/narrowcast';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken,
            'X-Line-Retry-Key' => $this->generateRetryKey() // ป้องกันส่งซ้ำ
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // ต้องการ header เพื่อดึง X-Line-Request-Id

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        // แยก header และ body
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // ดึง X-Line-Request-Id จาก header
        $requestId = null;
        if (preg_match('/X-Line-Request-Id:\s*(.+)/i', $headerStr, $matches)) {
            $requestId = trim($matches[1]);
        }

        return [
            'code' => $httpCode,
            'body' => json_decode($body, true),
            'requestId' => $requestId
        ];
    }

    /**
     * Get Narrowcast progress - ตรวจสอบสถานะการส่ง Narrowcast
     * 
     * @param string $requestId Request ID จาก narrowcastMessage
     * @return array ['phase' => string, 'successCount' => int, 'failureCount' => int, ...]
     */
    public function getNarrowcastProgress($requestId)
    {
        $url = $this->apiEndpoint . '/message/progress/narrowcast?requestId=' . urlencode($requestId);
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Generate unique retry key for narrowcast
     */
    private function generateRetryKey()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Get user profile
     */
    public function getProfile($userId)
    {
        $url = $this->apiEndpoint . '/profile/' . $userId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Get followers count
     */
    public function getFollowersCount()
    {
        $url = 'https://api.line.me/v2/bot/insight/followers';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Create Rich Menu
     */
    public function createRichMenu($data)
    {
        return $this->sendRequest('/richmenu', $data);
    }

    /**
     * Upload Rich Menu Image
     * ส่งตรงไป LINE API โดยไม่ผ่าน proxy
     */
    public function uploadRichMenuImage($richMenuId, $imagePath)
    {
        $url = 'https://api-data.line.me/v2/bot/richmenu/' . $richMenuId . '/content';

        // อ่านไฟล์และบีบอัดถ้าจำเป็น
        $imageData = file_get_contents($imagePath);
        $fileSize = strlen($imageData);

        // ถ้าไฟล์ใหญ่เกิน 500KB ให้บีบอัดใหม่
        if ($fileSize > 500000) {
            $imageInfo = getimagesize($imagePath);
            if ($imageInfo) {
                $img = null;
                switch ($imageInfo['mime']) {
                    case 'image/jpeg':
                    case 'image/jpg':
                        $img = imagecreatefromjpeg($imagePath);
                        break;
                    case 'image/png':
                        $img = imagecreatefrompng($imagePath);
                        break;
                }

                if ($img) {
                    // บีบอัดเป็น JPEG quality 70
                    ob_start();
                    imagejpeg($img, null, 70);
                    $imageData = ob_get_clean();
                    imagedestroy($img);
                    $contentType = 'image/jpeg';
                    error_log("Rich Menu image compressed: " . round(strlen($imageData) / 1024) . "KB");
                } else {
                    $contentType = 'image/jpeg';
                }
            } else {
                $contentType = 'image/jpeg';
            }
        } else {
            // ตรวจสอบ content type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $imagePath);
                finfo_close($finfo);
                $contentType = ($mimeType === 'image/png') ? 'image/png' : 'image/jpeg';
            } else {
                $contentType = 'image/jpeg';
            }
        }

        $headers = [
            'Authorization: Bearer ' . $this->channelAccessToken,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($imageData)
        ];

        // ใช้ CURL ส่งตรงไป LINE API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Rich Menu image upload failed: HTTP {$httpCode}, error: {$error}, response: {$response}");
        } else {
            error_log("Rich Menu image uploaded successfully: " . round(strlen($imageData) / 1024) . "KB");
        }

        return [
            'code' => $httpCode,
            'body' => json_decode($response, true),
            'error' => $error
        ];
    }

    /**
     * Set default Rich Menu
     */
    public function setDefaultRichMenu($richMenuId)
    {
        $url = $this->apiEndpoint . '/user/all/richmenu/' . $richMenuId;
        $headers = [
            'Authorization: Bearer ' . $this->channelAccessToken,
            'Content-Length: 0'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Delete Rich Menu
     */
    public function deleteRichMenu($richMenuId)
    {
        $url = $this->apiEndpoint . '/richmenu/' . $richMenuId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Link Rich Menu to User (Dynamic Rich Menu)
     */
    public function linkRichMenuToUser($userId, $richMenuId)
    {
        $url = $this->apiEndpoint . '/user/' . $userId . '/richmenu/' . $richMenuId;
        $headers = [
            'Authorization: Bearer ' . $this->channelAccessToken,
            'Content-Length: 0'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Unlink Rich Menu from User
     */
    public function unlinkRichMenuFromUser($userId)
    {
        $url = $this->apiEndpoint . '/user/' . $userId . '/richmenu';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Get Rich Menu linked to User
     */
    public function getRichMenuIdOfUser($userId)
    {
        $url = $this->apiEndpoint . '/user/' . $userId . '/richmenu';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Link Rich Menu to Multiple Users (Bulk)
     */
    public function linkRichMenuToMultipleUsers($richMenuId, $userIds)
    {
        $url = $this->apiEndpoint . '/richmenu/' . $richMenuId . '/users';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['userIds' => $userIds]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Unlink Rich Menu from Multiple Users (Bulk)
     */
    public function unlinkRichMenuFromMultipleUsers($userIds)
    {
        $url = $this->apiEndpoint . '/richmenu/bulk/unlink';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['userIds' => $userIds]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    // ==================== RICH MENU ALIAS (for switching) ====================

    /**
     * Create Rich Menu Alias
     */
    public function createRichMenuAlias($richMenuId, $aliasId)
    {
        $url = $this->apiEndpoint . '/richmenu/alias';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'richMenuAliasId' => $aliasId,
            'richMenuId' => $richMenuId
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Update Rich Menu Alias
     */
    public function updateRichMenuAlias($aliasId, $richMenuId)
    {
        $url = $this->apiEndpoint . '/richmenu/alias/' . $aliasId;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['richMenuId' => $richMenuId]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Delete Rich Menu Alias
     */
    public function deleteRichMenuAlias($aliasId)
    {
        $url = $this->apiEndpoint . '/richmenu/alias/' . $aliasId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Get Rich Menu Alias
     */
    public function getRichMenuAlias($aliasId)
    {
        $url = $this->apiEndpoint . '/richmenu/alias/' . $aliasId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Get All Rich Menu Aliases
     */
    public function getRichMenuAliasList()
    {
        $url = $this->apiEndpoint . '/richmenu/alias/list';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Get Rich Menu Image URL (download and return base64)
     */
    public function getRichMenuImage($richMenuId)
    {
        if (empty($richMenuId))
            return null;

        $url = 'https://api-data.line.me/v2/bot/richmenu/' . $richMenuId . '/content';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response && strlen($response) > 100) {
            // Detect content type if not provided
            if (empty($contentType) || strpos($contentType, 'image') === false) {
                $contentType = 'image/png';
            }
            return 'data:' . $contentType . ';base64,' . base64_encode($response);
        }

        // Log error for debugging
        if ($httpCode !== 200) {
            error_log("getRichMenuImage failed: HTTP {$httpCode}, error: {$error}, richMenuId: {$richMenuId}");
        }
        return null;
    }

    /**
     * Download Rich Menu Image (return raw binary data)
     */
    public function downloadRichMenuImage($richMenuId)
    {
        if (empty($richMenuId))
            return null;

        $url = 'https://api-data.line.me/v2/bot/richmenu/' . $richMenuId . '/content';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response && strlen($response) > 100) {
            return $response;
        }

        return null;
    }

    /**
     * Get all Rich Menus from LINE
     */
    public function getRichMenuList()
    {
        $url = $this->apiEndpoint . '/richmenu/list';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Get default Rich Menu ID
     */
    public function getDefaultRichMenu()
    {
        $url = $this->apiEndpoint . '/user/all/richmenu';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Cancel default Rich Menu (remove default for all users)
     */
    public function cancelDefaultRichMenu()
    {
        $url = $this->apiEndpoint . '/user/all/richmenu';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }

    /**
     * Validate signature
     */
    public function validateSignature($body, $signature)
    {
        $hash = base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
        return hash_equals($hash, $signature);
    }

    /**
     * Get message content (image, video, audio, file)
     */
    public function getMessageContent($messageId)
    {
        $url = 'https://api-data.line.me/v2/bot/message/' . $messageId . '/content';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log for debugging
        if ($httpCode !== 200) {
            error_log("LINE getMessageContent failed: HTTP {$httpCode}, messageId: {$messageId}, error: {$curlError}");
        }

        if ($httpCode === 200 && $response && strlen($response) > 100) {
            return $response; // Binary data
        }
        return null;
    }

    /**
     * Get group summary
     */
    public function getGroupSummary($groupId)
    {
        $url = $this->apiEndpoint . '/group/' . $groupId . '/summary';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }

    /**
     * Get group member profile
     */
    public function getGroupMemberProfile($groupId, $userId)
    {
        $url = $this->apiEndpoint . '/group/' . $groupId . '/member/' . $userId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }

    /**
     * Get group members count
     */
    public function getGroupMembersCount($groupId)
    {
        $url = $this->apiEndpoint . '/group/' . $groupId . '/members/count';
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }

    /**
     * Leave group
     */
    public function leaveGroup($groupId)
    {
        $url = $this->apiEndpoint . '/group/' . $groupId . '/leave';
        $headers = [
            'Authorization: Bearer ' . $this->channelAccessToken,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Leave room
     */
    public function leaveRoom($roomId)
    {
        $url = $this->apiEndpoint . '/room/' . $roomId . '/leave';
        $headers = [
            'Authorization: Bearer ' . $this->channelAccessToken,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Get room member profile
     */
    public function getRoomMemberProfile($roomId, $userId)
    {
        $url = $this->apiEndpoint . '/room/' . $roomId . '/member/' . $userId;
        $headers = ['Authorization: Bearer ' . $this->channelAccessToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }

    /**
     * Mark messages as read on LINE
     * Uses the markAsReadToken from webhook message event
     * This will show "Read" status to the user in LINE chat
     * 
     * @param string $markAsReadToken The token from webhook message event
     * @return array Response with success status
     */
    public function markAsRead($markAsReadToken)
    {
        if (empty($markAsReadToken)) {
            error_log("[LineAPI::markAsRead] Error: markAsReadToken is empty or null");
            return ['success' => false, 'error' => 'markAsReadToken is required'];
        }

        error_log("[LineAPI::markAsRead] Calling LINE API with token: " . substr($markAsReadToken, 0, 20) . "...");

        $url = 'https://api.line.me/v2/bot/chat/markAsRead';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];

        $data = ['markAsReadToken' => $markAsReadToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log the response for debugging
        error_log("[LineAPI::markAsRead] HTTP Code: {$httpCode}, Response: " . substr($response, 0, 200));

        if ($httpCode === 200) {
            error_log("[LineAPI::markAsRead] Success - Message marked as read on LINE");
            return ['success' => true];
        }

        error_log("[LineAPI::markAsRead] Failed - HTTP {$httpCode}, Error: {$error}, Response: {$response}");

        return [
            'success' => false,
            'error' => $error ?: 'HTTP ' . $httpCode,
            'response' => json_decode($response, true)
        ];
    }

    /**
     * Mark multiple messages as read
     * 
     * @param array $tokens Array of markAsReadTokens
     * @return array Results for each token
     */
    public function markMultipleAsRead(array $tokens)
    {
        $results = [];
        foreach ($tokens as $token) {
            $results[] = $this->markAsRead($token);
        }
        return $results;
    }
}