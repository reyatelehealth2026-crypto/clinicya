<?php
/**
 * ExpenseCategoryService - จัดการหมวดหมู่ค่าใช้จ่าย
 * 
 * Requirements: 3.3, 3.4
 */

class ExpenseCategoryService {
    private $db;
    private $lineAccountId;
    
    /**
     * Default expense categories
     */
    private static $defaultCategories = [
        ['name' => 'ค่าสาธารณูปโภค', 'name_en' => 'Utilities', 'expense_type' => 'operating'],
        ['name' => 'ค่าเช่า', 'name_en' => 'Rent', 'expense_type' => 'operating'],
        ['name' => 'เงินเดือน', 'name_en' => 'Salary', 'expense_type' => 'operating'],
        ['name' => 'ค่าอินเทอร์เน็ต', 'name_en' => 'Internet', 'expense_type' => 'operating'],
        ['name' => 'ค่าโทรศัพท์', 'name_en' => 'Telephone', 'expense_type' => 'operating'],
        ['name' => 'ค่าขนส่ง', 'name_en' => 'Transportation', 'expense_type' => 'operating'],
        ['name' => 'ค่าซ่อมบำรุง', 'name_en' => 'Maintenance', 'expense_type' => 'operating'],
        ['name' => 'ค่าใช้จ่ายสำนักงาน', 'name_en' => 'Office Supplies', 'expense_type' => 'administrative'],
        ['name' => 'ค่าธรรมเนียมธนาคาร', 'name_en' => 'Bank Fees', 'expense_type' => 'financial'],
        ['name' => 'อื่นๆ', 'name_en' => 'Miscellaneous', 'expense_type' => 'other'],
    ];
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Create new expense category
     * 
     * @param array $data Category data (name, name_en, description, expense_type)
     * @return int Created category ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['name'])) {
            throw new Exception('Category name is required');
        }
        
        // Validate expense_type if provided
        $validTypes = ['operating', 'administrative', 'financial', 'other'];
        $expenseType = $data['expense_type'] ?? 'operating';
        if (!in_array($expenseType, $validTypes)) {
            throw new Exception('Invalid expense type');
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories 
            (line_account_id, name, name_en, description, expense_type, is_default, is_active)
            VALUES (?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $data['name'],
            $data['name_en'] ?? null,
            $data['description'] ?? null,
            $expenseType
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update expense category
     * 
     * @param int $id Category ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'name_en', 'description', 'expense_type', 'is_active'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        // Validate expense_type if being updated
        if (isset($data['expense_type'])) {
            $validTypes = ['operating', 'administrative', 'financial', 'other'];
            if (!in_array($data['expense_type'], $validTypes)) {
                throw new Exception('Invalid expense type');
            }
        }
        
        $values[] = $id;
        $sql = "UPDATE expense_categories SET " . implode(', ', $fields) . " WHERE id = ?";
        
        // Add line_account_id check if set
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $values[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Delete expense category (soft delete by setting is_active = 0)
     * 
     * @param int $id Category ID
     * @return bool Success status
     * @throws Exception If category has expenses
     */
    public function delete(int $id): bool {
        // Check if category has expenses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ?");
        $stmt->execute([$id]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('Cannot delete category with existing expenses');
        }
        
        return $this->update($id, ['is_active' => 0]);
    }
    
    /**
     * Get all expense categories
     * 
     * @param array $filters Optional filters (is_active, expense_type, search)
     * @return array List of categories
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT * FROM expense_categories WHERE 1=1";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by active status (default to active only)
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = (int)$filters['is_active'];
        } else {
            $sql .= " AND is_active = 1";
        }
        
        // Filter by expense type
        if (!empty($filters['expense_type'])) {
            $sql .= " AND expense_type = ?";
            $params[] = $filters['expense_type'];
        }
        
        // Search by name
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR name_en LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY is_default DESC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get category by ID
     * 
     * @param int $id Category ID
     * @return array|null Category data or null if not found
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM expense_categories WHERE id = ?";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Initialize default expense categories
     * Creates default categories if they don't exist
     * 
     * @return int Number of categories created
     */
    public function initializeDefaults(): int {
        $created = 0;
        
        foreach (self::$defaultCategories as $category) {
            // Check if category already exists
            $stmt = $this->db->prepare("
                SELECT id FROM expense_categories 
                WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL)
            ");
            $stmt->execute([$category['name'], $this->lineAccountId]);
            
            if (!$stmt->fetch()) {
                // Create the category
                $stmt = $this->db->prepare("
                    INSERT INTO expense_categories 
                    (line_account_id, name, name_en, expense_type, is_default, is_active)
                    VALUES (?, ?, ?, ?, 1, 1)
                ");
                $stmt->execute([
                    $this->lineAccountId,
                    $category['name'],
                    $category['name_en'],
                    $category['expense_type']
                ]);
                $created++;
            }
        }
        
        return $created;
    }
    
    /**
     * Get default categories list
     * 
     * @return array List of default category definitions
     */
    public static function getDefaultCategories(): array {
        return self::$defaultCategories;
    }
    
    /**
     * Check if category exists
     * 
     * @param int $id Category ID
     * @return bool True if exists and active
     */
    public function exists(int $id): bool {
        $category = $this->getById($id);
        return $category !== null && $category['is_active'] == 1;
    }
    
    /**
     * Get categories grouped by expense type
     * 
     * @return array Categories grouped by type
     */
    public function getGroupedByType(): array {
        $categories = $this->getAll();
        $grouped = [
            'operating' => [],
            'administrative' => [],
            'financial' => [],
            'other' => []
        ];
        
        foreach ($categories as $category) {
            $type = $category['expense_type'] ?? 'other';
            $grouped[$type][] = $category;
        }
        
        return $grouped;
    }
}
