<?php
/**
 * Admin Authentication & Authorization
 * จัดการการเข้าสู่ระบบและสิทธิ์ของผู้ดูแล
 */

require_once __DIR__ . '/ActivityLogger.php';

class AdminAuth
{
    private $db;
    private $sessionKey = 'admin_user';
    
    // Roles
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ADMIN = 'admin';
    const ROLE_STAFF = 'staff';
    
    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureTables();
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Ensure admin tables exist
     */
    private function ensureTables()
    {
        // Check admin_users table
        try {
            $result = $this->db->query("SELECT 1 FROM admin_users LIMIT 1");
            
            // Table exists - check for missing columns
            $this->ensureColumn('admin_users', 'login_count', 'INT DEFAULT 0 AFTER last_login');
            $this->ensureColumn('admin_users', 'last_login', 'TIMESTAMP NULL AFTER is_active');
            $this->ensureColumn('admin_users', 'phone', 'VARCHAR(20) NULL AFTER email');
            $this->ensureColumn('admin_users', 'line_user_id', 'VARCHAR(50) NULL AFTER phone');
        } catch (Exception $e) {
            // Create admin_users table
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS admin_users (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(50) NOT NULL UNIQUE,
                        password VARCHAR(255) NOT NULL,
                        email VARCHAR(100),
                        display_name VARCHAR(100),
                        role VARCHAR(20) DEFAULT 'admin',
                        is_active TINYINT(1) DEFAULT 1,
                        last_login TIMESTAMP NULL,
                        login_count INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                // Create default super admin
                $defaultPassword = password_hash('password', PASSWORD_DEFAULT);
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO admin_users (username, password, display_name, role, is_active) 
                    VALUES (?, ?, 'Super Admin', 'super_admin', 1)
                ");
                $stmt->execute(['admin', $defaultPassword]);
            } catch (Exception $createError) {
                error_log("AdminAuth: Failed to create admin_users table: " . $createError->getMessage());
            }
        }
        
        // Check admin_bot_access table
        try {
            $this->db->query("SELECT 1 FROM admin_bot_access LIMIT 1");
        } catch (Exception $e) {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS admin_bot_access (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    line_account_id INT NOT NULL,
                    can_view TINYINT(1) DEFAULT 1,
                    can_edit TINYINT(1) DEFAULT 1,
                    can_broadcast TINYINT(1) DEFAULT 1,
                    can_manage_users TINYINT(1) DEFAULT 1,
                    can_manage_shop TINYINT(1) DEFAULT 1,
                    can_view_analytics TINYINT(1) DEFAULT 1,
                    granted_by INT,
                    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_admin_bot (admin_id, line_account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        // Check admin_activity_log table
        try {
            $this->db->query("SELECT 1 FROM admin_activity_log LIMIT 1");
        } catch (Exception $e) {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS admin_activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    line_account_id INT NULL,
                    action VARCHAR(50) NOT NULL,
                    details TEXT,
                    ip_address VARCHAR(45),
                    user_agent VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin_id (admin_id),
                    INDEX idx_action (action),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Login
     */
    public function login($username, $password)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Table might not exist - try to create it
            $this->ensureTables();
            $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }
        
        // Update login info (handle missing columns gracefully)
        try {
            // Check if login_count column exists
            $hasLoginCount = false;
            try {
                $this->db->query("SELECT login_count FROM admin_users LIMIT 1");
                $hasLoginCount = true;
            } catch (Exception $e) {
                // Column doesn't exist
            }
            
            if ($hasLoginCount) {
                $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW(), login_count = COALESCE(login_count, 0) + 1 WHERE id = ?");
            } else {
                $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            }
            $stmt->execute([$user['id']]);
        } catch (Exception $e) {
            // Ignore update errors - login should still work
            error_log("AdminAuth login update error: " . $e->getMessage());
        }
        
        // Set session
        unset($user['password']);
        $_SESSION[$this->sessionKey] = $user;

        // Platform-owner activity feed + Telegram (best-effort).
        if (@is_file(__DIR__ . '/TenantActivity.php')) {
            require_once __DIR__ . '/TenantActivity.php';
            TenantActivity::log(
                TenantActivity::currentTenantId(), 'login',
                (string) ($user['display_name'] ?? $user['username'] ?? ''), 'เข้าสู่ระบบ'
            );
        }

        // Log activity (both old and new logger)
        $this->logActivity($user['id'], null, 'login', 'User logged in');
        
        // Log to ActivityLogger
        try {
            $activityLogger = ActivityLogger::getInstance($this->db);
            $activityLogger->logAuth(ActivityLogger::ACTION_LOGIN, 'เข้าสู่ระบบ', [
                'admin_id' => $user['id'],
                'admin_name' => $user['display_name'] ?? $user['username'],
                'extra_data' => ['role' => $user['role']]
            ]);
        } catch (Exception $e) {
            // Ignore logger errors
        }
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Logout
     */
    public function logout()
    {
        if ($this->isLoggedIn()) {
            $user = $this->getCurrentUser();
            $this->logActivity($user['id'], null, 'logout', 'User logged out');
            
            // Log to ActivityLogger
            try {
                $activityLogger = ActivityLogger::getInstance($this->db);
                $activityLogger->logAuth(ActivityLogger::ACTION_LOGOUT, 'ออกจากระบบ', [
                    'admin_id' => $user['id'],
                    'admin_name' => $user['display_name'] ?? $user['username']
                ]);
            } catch (Exception $e) {
                // Ignore logger errors
            }
        }
        unset($_SESSION[$this->sessionKey]);
        unset($_SESSION['current_bot_id']);
    }
    
    /**
     * Check if logged in
     */
    public function isLoggedIn()
    {
        return isset($_SESSION[$this->sessionKey]) && !empty($_SESSION[$this->sessionKey]['id']);
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser()
    {
        return $_SESSION[$this->sessionKey] ?? null;
    }
    
    /**
     * Check if super admin
     */
    public function isSuperAdmin()
    {
        $user = $this->getCurrentUser();
        return $user && $user['role'] === self::ROLE_SUPER_ADMIN;
    }
    
    /**
     * Get accessible LINE accounts for current user
     */
    public function getAccessibleBots()
    {
        $user = $this->getCurrentUser();
        if (!$user) return [];
        
        // Super admin can access all
        if ($user['role'] === self::ROLE_SUPER_ADMIN) {
            $stmt = $this->db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Others - only assigned bots
        $stmt = $this->db->prepare("
            SELECT la.*, aba.can_view, aba.can_edit, aba.can_broadcast, 
                   aba.can_manage_users, aba.can_manage_shop, aba.can_view_analytics
            FROM line_accounts la
            JOIN admin_bot_access aba ON la.id = aba.line_account_id
            WHERE aba.admin_id = ? AND la.is_active = 1 AND aba.can_view = 1
            ORDER BY la.name
        ");
        $stmt->execute([$user['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if user can access specific bot
     */
    public function canAccessBot($lineAccountId, $permission = 'can_view')
    {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        // Super admin can access all
        if ($user['role'] === self::ROLE_SUPER_ADMIN) return true;
        
        $stmt = $this->db->prepare("SELECT * FROM admin_bot_access WHERE admin_id = ? AND line_account_id = ?");
        $stmt->execute([$user['id'], $lineAccountId]);
        $access = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$access) return false;
        
        return $access[$permission] ?? false;
    }
    
    /**
     * Get current bot ID (with access check)
     */
    public function getCurrentBotId()
    {
        $botId = $_SESSION['current_bot_id'] ?? null;
        
        if ($botId && $this->canAccessBot($botId)) {
            return $botId;
        }
        
        // Get first accessible bot
        $bots = $this->getAccessibleBots();
        if (!empty($bots)) {
            $_SESSION['current_bot_id'] = $bots[0]['id'];
            return $bots[0]['id'];
        }
        
        return null;
    }
    
    /**
     * Set current bot (with access check)
     */
    public function setCurrentBot($botId)
    {
        if ($this->canAccessBot($botId)) {
            $_SESSION['current_bot_id'] = $botId;
            return true;
        }
        return false;
    }
    
    /**
     * Require login - redirect if not logged in
     */
    public function requireLogin($redirectTo = 'auth/login.php')
    {
        if (!$this->isLoggedIn()) {
            header("Location: {$redirectTo}");
            exit;
        }
    }
    
    /**
     * Require super admin
     */
    public function requireSuperAdmin($redirectTo = 'index.php')
    {
        $this->requireLogin();
        if (!$this->isSuperAdmin()) {
            header("Location: {$redirectTo}?error=no_permission");
            exit;
        }
    }
    
    /**
     * Require bot access
     */
    public function requireBotAccess($botId, $permission = 'can_view', $redirectTo = 'index.php')
    {
        $this->requireLogin();
        if (!$this->canAccessBot($botId, $permission)) {
            header("Location: {$redirectTo}?error=no_bot_access");
            exit;
        }
    }
    
    // ==================== Admin Management ====================
    
    /**
     * Create admin user
     */
    public function createAdmin($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO admin_users (username, password, email, phone, line_user_id, display_name, role, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['username'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['line_user_id'] ?? null,
            $data['display_name'] ?? $data['username'],
            $data['role'] ?? self::ROLE_ADMIN,
            $data['is_active'] ?? 1
        ]);
        return $this->db->lastInsertId();
    }
    
    /**
     * Update admin user
     */
    public function updateAdmin($id, $data)
    {
        $fields = [];
        $values = [];
        
        foreach (['email', 'phone', 'line_user_id', 'display_name', 'role', 'is_active'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }
        
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password = ?";
            $values[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) return false;
        
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE admin_users SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }
    
    /**
     * Delete admin user
     */
    public function deleteAdmin($id)
    {
        // Cannot delete super admin
        $stmt = $this->db->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if ($user && $user['role'] === self::ROLE_SUPER_ADMIN) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM admin_users WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Get all admins
     */
    public function getAllAdmins()
    {
        try {
            $stmt = $this->db->query("SELECT id, username, email, phone, line_user_id, display_name, role, is_active, last_login, login_count, created_at FROM admin_users ORDER BY role, username");
        } catch (Exception $e) {
            // Fallback without new columns
            try {
                $stmt = $this->db->query("SELECT id, username, email, display_name, role, is_active, last_login, login_count, created_at FROM admin_users ORDER BY role, username");
            } catch (Exception $e2) {
                $stmt = $this->db->query("SELECT id, username, email, display_name, role, is_active, created_at FROM admin_users ORDER BY role, username");
            }
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get admin by ID
     */
    public function getAdminById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email, phone, line_user_id, display_name, role, is_active, last_login, login_count, created_at FROM admin_users WHERE id = ?");
        } catch (Exception $e) {
            $stmt = $this->db->prepare("SELECT id, username, email, display_name, role, is_active, created_at FROM admin_users WHERE id = ?");
        }
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // ==================== Bot Access Management ====================
    
    /**
     * Grant bot access to admin
     */
    public function grantBotAccess($adminId, $lineAccountId, $permissions = [])
    {
        $currentUser = $this->getCurrentUser();
        
        $stmt = $this->db->prepare("
            INSERT INTO admin_bot_access (admin_id, line_account_id, can_view, can_edit, can_broadcast, can_manage_users, can_manage_shop, can_view_analytics, granted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                can_view = VALUES(can_view),
                can_edit = VALUES(can_edit),
                can_broadcast = VALUES(can_broadcast),
                can_manage_users = VALUES(can_manage_users),
                can_manage_shop = VALUES(can_manage_shop),
                can_view_analytics = VALUES(can_view_analytics)
        ");
        
        return $stmt->execute([
            $adminId,
            $lineAccountId,
            $permissions['can_view'] ?? 1,
            $permissions['can_edit'] ?? 1,
            $permissions['can_broadcast'] ?? 1,
            $permissions['can_manage_users'] ?? 1,
            $permissions['can_manage_shop'] ?? 1,
            $permissions['can_view_analytics'] ?? 1,
            $currentUser['id'] ?? null
        ]);
    }
    
    /**
     * Revoke bot access from admin
     */
    public function revokeBotAccess($adminId, $lineAccountId)
    {
        $stmt = $this->db->prepare("DELETE FROM admin_bot_access WHERE admin_id = ? AND line_account_id = ?");
        return $stmt->execute([$adminId, $lineAccountId]);
    }
    
    /**
     * Get admin's bot access
     */
    public function getAdminBotAccess($adminId)
    {
        $stmt = $this->db->prepare("
            SELECT aba.*, la.name as bot_name, la.basic_id
            FROM admin_bot_access aba
            JOIN line_accounts la ON aba.line_account_id = la.id
            WHERE aba.admin_id = ?
        ");
        $stmt->execute([$adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ==================== Activity Log ====================
    
    /**
     * Log activity
     */
    public function logActivity($adminId, $lineAccountId, $action, $details = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_activity_log (admin_id, line_account_id, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $adminId,
                $lineAccountId,
                $action,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Ignore log errors
        }
    }
    
    /**
     * Get activity log
     */
    public function getActivityLog($limit = 100, $adminId = null, $lineAccountId = null)
    {
        $sql = "SELECT al.*, au.username, au.display_name, la.name as bot_name
                FROM admin_activity_log al
                LEFT JOIN admin_users au ON al.admin_id = au.id
                LEFT JOIN line_accounts la ON al.line_account_id = la.id
                WHERE 1=1";
        $params = [];
        
        if ($adminId) {
            $sql .= " AND al.admin_id = ?";
            $params[] = $adminId;
        }
        if ($lineAccountId) {
            $sql .= " AND al.line_account_id = ?";
            $params[] = $lineAccountId;
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ensure a column exists in a table
     */
    private function ensureColumn($table, $column, $definition)
    {
        try {
            $this->db->query("SELECT {$column} FROM {$table} LIMIT 1");
        } catch (Exception $e) {
            try {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            } catch (Exception $e2) {
                // Ignore if column already exists or other error
            }
        }
    }
}
