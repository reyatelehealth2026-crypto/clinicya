<?php
/**
 * TemplateService - จัดการ Quick Reply Templates สำหรับ Inbox Chat
 * 
 * Requirements: 2.1, 2.3, 2.4, 2.5
 */

class TemplateService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get all quick reply templates
     * Requirements: 2.1 - Display searchable list of quick reply templates
     * 
     * @param string $search Optional search query
     * @return array Templates with usage stats
     */
    public function getTemplates(string $search = ''): array {
        $sql = "SELECT id, line_account_id, name, content, category, 
                       usage_count, last_used_at, created_by, created_at, updated_at
                FROM quick_reply_templates 
                WHERE line_account_id = ?";
        $params = [$this->lineAccountId];
        
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR content LIKE ? OR category LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY usage_count DESC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get single template by ID
     * 
     * @param int $id Template ID
     * @return array|null
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM quick_reply_templates WHERE id = ? AND line_account_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Create new template
     * Requirements: 2.4 - Save template for future use
     * 
     * @param string $name Template name
     * @param string $content Template content with placeholders
     * @param string $category Optional category
     * @param int|null $createdBy Admin user ID who created
     * @return int Template ID
     */
    public function createTemplate(string $name, string $content, string $category = '', ?int $createdBy = null, ?string $quickReply = null): int {
        $name = trim($name);
        $content = trim($content);
        $category = trim($category);
        
        if (empty($name)) {
            throw new InvalidArgumentException('Template name is required');
        }
        
        if (empty($content)) {
            throw new InvalidArgumentException('Template content is required');
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO quick_reply_templates 
            (line_account_id, name, content, category, created_by, quick_reply)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $name,
            $content,
            $category,
            $createdBy,
            $quickReply
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    
    /**
     * Update existing template
     * Requirements: 2.4 - Manage templates
     * 
     * @param int $id Template ID
     * @param array $data Updated data (name, content, category)
     * @return bool
     */
    public function updateTemplate(int $id, array $data): bool {
        $template = $this->getById($id);
        if (!$template) {
            return false;
        }
        
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (empty($name)) {
                throw new InvalidArgumentException('Template name cannot be empty');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        
        if (isset($data['content'])) {
            $content = trim($data['content']);
            if (empty($content)) {
                throw new InvalidArgumentException('Template content cannot be empty');
            }
            $fields[] = 'content = ?';
            $params[] = $content;
        }
        
        if (isset($data['category'])) {
            $fields[] = 'category = ?';
            $params[] = trim($data['category']);
        }
        
        if (empty($fields)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        $params[] = $this->lineAccountId;
        
        $sql = "UPDATE quick_reply_templates SET " . implode(', ', $fields) . 
               " WHERE id = ? AND line_account_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete template
     * Requirements: 2.4 - Manage templates
     * 
     * @param int $id Template ID
     * @return bool
     */
    public function deleteTemplate(int $id): bool {
        $template = $this->getById($id);
        if (!$template) {
            return false;
        }
        
        $stmt = $this->db->prepare(
            "DELETE FROM quick_reply_templates WHERE id = ? AND line_account_id = ?"
        );
        return $stmt->execute([$id, $this->lineAccountId]);
    }
    
    /**
     * Fill placeholders in template with customer data
     * Requirements: 2.3 - Auto-fill placeholders with customer data
     * 
     * Supported placeholders: {name}, {phone}, {email}, {order_id}
     * 
     * @param string $template Template content
     * @param array $customerData Customer data ['name', 'phone', 'email', 'order_id']
     * @return string Filled template
     */
    public function fillPlaceholders(string $template, array $customerData): string {
        $placeholders = [
            '{name}' => $customerData['name'] ?? '',
            '{phone}' => $customerData['phone'] ?? '',
            '{email}' => $customerData['email'] ?? '',
            '{order_id}' => $customerData['order_id'] ?? ''
        ];
        
        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $template
        );
    }
    
    /**
     * Record template usage
     * Requirements: 2.5 - Show usage count and last used date
     * 
     * @param int $templateId Template ID
     * @return void
     */
    public function recordUsage(int $templateId): void {
        $stmt = $this->db->prepare("
            UPDATE quick_reply_templates 
            SET usage_count = usage_count + 1, 
                last_used_at = NOW()
            WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$templateId, $this->lineAccountId]);
    }
    
    /**
     * Get templates by category
     * 
     * @param string $category Category name
     * @return array
     */
    public function getByCategory(string $category): array {
        $sql = "SELECT * FROM quick_reply_templates 
                WHERE line_account_id = ? AND category = ?
                ORDER BY usage_count DESC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all categories
     * 
     * @return array
     */
    public function getCategories(): array {
        $sql = "SELECT DISTINCT category FROM quick_reply_templates 
                WHERE line_account_id = ? AND category != ''
                ORDER BY category ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get most used templates
     * 
     * @param int $limit Number of templates to return
     * @return array
     */
    public function getMostUsed(int $limit = 5): array {
        $sql = "SELECT * FROM quick_reply_templates 
                WHERE line_account_id = ? AND usage_count > 0
                ORDER BY usage_count DESC
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get template count
     * 
     * @return int
     */
    public function getCount(): int {
        $sql = "SELECT COUNT(*) FROM quick_reply_templates WHERE line_account_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        return (int)$stmt->fetchColumn();
    }
}
