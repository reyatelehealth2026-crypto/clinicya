<?php
/**
 * LINE Account Manager - จัดการหลายบัญชี LINE OA
 */

require_once __DIR__ . '/LineAPI.php';

class LineAccountManager
{
    private $db;
    private static $instance = null;
    private $accounts = [];

    public function __construct($db = null)
    {
        if ($db) {
            $this->db = $db;
        } else {
            $this->db = Database::getInstance()->getConnection();
        }
        $this->loadAccounts();
    }

    public static function getInstance($db = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Load all active accounts
     */
    private function loadAccounts()
    {
        try {
            $stmt = $this->db->query("SELECT * FROM line_accounts WHERE is_active = 1");
            $this->accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->accounts = [];
        }
    }

    /**
     * Get account by channel secret (for webhook validation)
     */
    public function getAccountBySecret($channelSecret)
    {
        foreach ($this->accounts as $account) {
            if ($account['channel_secret'] === $channelSecret) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Get account by ID
     */
    public function getAccountById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM line_accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get default account
     */
    public function getDefaultAccount()
    {
        foreach ($this->accounts as $account) {
            if ($account['is_default']) {
                return $account;
            }
        }
        // Return first account if no default
        return $this->accounts[0] ?? null;
    }

    /**
     * Get all accounts
     */
    public function getAllAccounts()
    {
        $stmt = $this->db->query("SELECT * FROM line_accounts ORDER BY is_default DESC, name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Validate webhook signature and get account
     */
    public function validateAndGetAccount($body, $signature)
    {
        foreach ($this->accounts as $account) {
            $hash = base64_encode(hash_hmac('sha256', $body, $account['channel_secret'], true));
            if (hash_equals($hash, $signature)) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Create new account
     */
    public function createAccount($data)
    {
        // ตรวจสอบว่ามี columns หรือไม่
        $hasBotMode = $this->columnExists('line_accounts', 'bot_mode');
        $hasLiffId = $this->columnExists('line_accounts', 'liff_id');
        
        // Build dynamic insert
        $columns = ['name', 'channel_id', 'channel_secret', 'channel_access_token', 'basic_id', 'is_default'];
        $values = [
            $data['name'],
            $data['channel_id'] ?? null,
            $data['channel_secret'],
            $data['channel_access_token'],
            $data['basic_id'] ?? null,
            $data['is_default'] ?? 0
        ];
        
        if ($hasBotMode) {
            $columns[] = 'bot_mode';
            $values[] = $data['bot_mode'] ?? 'shop';
        }
        
        if ($hasLiffId) {
            $columns[] = 'liff_id';
            $values[] = $data['liff_id'] ?? null;
        }
        
        // เพิ่ม LIFF IDs แยกแต่ละหน้า
        $liffFields = ['liff_main_id', 'liff_consent_id', 'liff_register_id', 'liff_member_card_id', 'liff_shop_id', 'liff_checkout_id'];
        foreach ($liffFields as $liffField) {
            if ($this->columnExists('line_accounts', $liffField)) {
                $columns[] = $liffField;
                $values[] = $data[$liffField] ?? null;
            }
        }
        
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnStr = implode(', ', $columns);
        
        $stmt = $this->db->prepare("INSERT INTO line_accounts ({$columnStr}) VALUES ({$placeholders})");
        $stmt->execute($values);

        $accountId = $this->db->lastInsertId();

        // If this is default, unset other defaults
        if (!empty($data['is_default'])) {
            $this->setDefault($accountId);
        }

        // Generate webhook URL
        $webhookUrl = BASE_URL . '/webhook.php?account=' . $accountId;
        $stmt = $this->db->prepare("UPDATE line_accounts SET webhook_url = ? WHERE id = ?");
        $stmt->execute([$webhookUrl, $accountId]);

        $this->loadAccounts();
        return $accountId;
    }

    /**
     * Update account
     */
    public function updateAccount($id, $data)
    {
        // Debug log
        error_log("LineAccountManager::updateAccount - ID: $id, Data: " . json_encode($data));
        
        $fields = [];
        $values = [];
        
        // รายการ fields ที่อัพเดทได้
        $allowedFields = ['name', 'channel_id', 'channel_secret', 'channel_access_token', 'basic_id', 'is_active'];
        
        // เพิ่ม bot_mode ถ้ามี column
        $hasBotMode = $this->columnExists('line_accounts', 'bot_mode');
        error_log("LineAccountManager::updateAccount - hasBotMode: " . ($hasBotMode ? 'YES' : 'NO'));
        
        if ($hasBotMode) {
            $allowedFields[] = 'bot_mode';
        }
        
        // เพิ่ม liff_id ถ้ามี column
        $hasLiffId = $this->columnExists('line_accounts', 'liff_id');
        if ($hasLiffId) {
            $allowedFields[] = 'liff_id';
        }
        
        // เพิ่ม LIFF IDs แยกแต่ละหน้า
        $liffFields = ['liff_main_id', 'liff_consent_id', 'liff_register_id', 'liff_member_card_id', 'liff_shop_id', 'liff_checkout_id'];
        foreach ($liffFields as $liffField) {
            if ($this->columnExists('line_accounts', $liffField)) {
                $allowedFields[] = $liffField;
            }
        }

        foreach ($allowedFields as $field) {
            // ใช้ array_key_exists แทน isset เพราะ isset(null) = false
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
                error_log("LineAccountManager::updateAccount - Field: $field = " . ($data[$field] ?? 'NULL'));
            }
        }

        if (empty($fields)) {
            error_log("LineAccountManager::updateAccount - No fields to update!");
            return false;
        }

        $values[] = $id;
        $sql = "UPDATE line_accounts SET " . implode(', ', $fields) . " WHERE id = ?";
        error_log("LineAccountManager::updateAccount - SQL: $sql");
        error_log("LineAccountManager::updateAccount - Values: " . json_encode($values));
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($values);
        $rowCount = $stmt->rowCount();
        
        error_log("LineAccountManager::updateAccount - Result: " . ($result ? 'SUCCESS' : 'FAILED') . ", Rows: $rowCount");

        if (!empty($data['is_default'])) {
            $this->setDefault($id);
        }

        $this->loadAccounts();
        return true;
    }
    
    /**
     * Check if column exists in table
     */
    private function columnExists($table, $column)
    {
        try {
            // ใช้ INFORMATION_SCHEMA แทน SHOW COLUMNS เพราะทำงานได้ดีกว่ากับ prepared statement
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result && $result['cnt'] > 0);
        } catch (Exception $e) {
            error_log("columnExists error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete account
     */
    public function deleteAccount($id)
    {
        $stmt = $this->db->prepare("DELETE FROM line_accounts WHERE id = ? AND is_default = 0");
        $stmt->execute([$id]);
        $this->loadAccounts();
        return $stmt->rowCount() > 0;
    }

    /**
     * Set default account
     */
    public function setDefault($id)
    {
        $this->db->exec("UPDATE line_accounts SET is_default = 0");
        $stmt = $this->db->prepare("UPDATE line_accounts SET is_default = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $this->loadAccounts();
    }

    /**
     * Get LineAPI instance for account
     */
    public function getLineAPI($accountId = null)
    {
        if ($accountId) {
            $account = $this->getAccountById($accountId);
        } else {
            $account = $this->getDefaultAccount();
        }

        if (!$account) {
            // Fallback to config
            return new LineAPI();
        }

        return new LineAPI($account['channel_access_token'], $account['channel_secret']);
    }

    /**
     * Test account connection
     */
    public function testConnection($accountId)
    {
        $account = $this->getAccountById($accountId);
        if (!$account) return ['success' => false, 'message' => 'Account not found'];

        $line = new LineAPI($account['channel_access_token'], $account['channel_secret']);
        $result = $line->getBotInfo();

        if (isset($result['userId'])) {
            // Update picture URL
            $stmt = $this->db->prepare("UPDATE line_accounts SET picture_url = ? WHERE id = ?");
            $stmt->execute([$result['pictureUrl'] ?? null, $accountId]);

            return ['success' => true, 'data' => $result];
        }

        return ['success' => false, 'message' => $result['message'] ?? 'Connection failed'];
    }
}
