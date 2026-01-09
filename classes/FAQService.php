<?php
/**
 * FAQService - จัดการคำถามที่พบบ่อยสำหรับ Landing Page
 * 
 * Requirements: 4.1, 4.3, 4.5, 10.3
 */

class FAQService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get active FAQs with limit
     * Requirements: 4.1, 4.5 - Display FAQ section with min 3, max 10 items
     * 
     * @param int $limit Maximum number of FAQs to return (default 10)
     * @return array
     */
    public function getActiveFAQs(int $limit = 10): array {
        // Enforce max limit of 10 per Requirements 4.5
        $limit = min($limit, 10);
        
        $sql = "SELECT id, question, answer, sort_order 
                FROM landing_faqs 
                WHERE is_active = 1";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        } else {
            $sql .= " AND line_account_id IS NULL";
        }
        
        $sql .= " ORDER BY sort_order ASC, id ASC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get FAQ structured data for JSON-LD (FAQPage schema)
     * Requirements: 4.3 - Include FAQPage Structured_Data for SEO
     * 
     * @return array JSON-LD structured data
     */
    public function getFAQStructuredData(): array {
        $faqs = $this->getActiveFAQs();
        
        if (empty($faqs)) {
            return [];
        }
        
        $mainEntity = [];
        foreach ($faqs as $faq) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer']
                ]
            ];
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity
        ];
    }
    
    /**
     * Get single FAQ by ID
     * 
     * @param int $id FAQ ID
     * @return array|null
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM landing_faqs WHERE id = ?";
        $params = [$id];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Create new FAQ
     * Requirements: 10.3 - Allow adding FAQ entries
     * 
     * @param array $data FAQ data (question, answer, sort_order, is_active)
     * @return int New FAQ ID
     */
    public function create(array $data): int {
        $question = trim($data['question'] ?? '');
        $answer = trim($data['answer'] ?? '');
        
        if (empty($question) || empty($answer)) {
            throw new InvalidArgumentException('Question and answer are required');
        }
        
        $sortOrder = (int)($data['sort_order'] ?? $this->getNextSortOrder());
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        
        $stmt = $this->db->prepare("
            INSERT INTO landing_faqs (line_account_id, question, answer, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $question,
            $answer,
            $sortOrder,
            $isActive
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update existing FAQ
     * Requirements: 10.3 - Allow editing FAQ entries
     * 
     * @param int $id FAQ ID
     * @param array $data Updated data
     * @return bool
     */
    public function update(int $id, array $data): bool {
        $faq = $this->getById($id);
        if (!$faq) {
            return false;
        }
        
        $fields = [];
        $params = [];
        
        if (isset($data['question'])) {
            $question = trim($data['question']);
            if (empty($question)) {
                throw new InvalidArgumentException('Question cannot be empty');
            }
            $fields[] = 'question = ?';
            $params[] = $question;
        }
        
        if (isset($data['answer'])) {
            $answer = trim($data['answer']);
            if (empty($answer)) {
                throw new InvalidArgumentException('Answer cannot be empty');
            }
            $fields[] = 'answer = ?';
            $params[] = $answer;
        }
        
        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }
        
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        
        if (empty($fields)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        $sql = "UPDATE landing_faqs SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete FAQ
     * Requirements: 10.3 - Allow deleting FAQ entries
     * 
     * @param int $id FAQ ID
     * @return bool
     */
    public function delete(int $id): bool {
        $faq = $this->getById($id);
        if (!$faq) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM landing_faqs WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Reorder FAQs
     * 
     * @param array $ids Array of FAQ IDs in desired order
     * @return bool
     */
    public function reorder(array $ids): bool {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("UPDATE landing_faqs SET sort_order = ? WHERE id = ?");
            
            foreach ($ids as $order => $id) {
                $stmt->execute([$order + 1, (int)$id]);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get all FAQs (including inactive) for admin
     * 
     * @return array
     */
    public function getAllForAdmin(): array {
        $sql = "SELECT * FROM landing_faqs WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY sort_order ASC, id ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get next sort order value
     * 
     * @return int
     */
    private function getNextSortOrder(): int {
        $sql = "SELECT MAX(sort_order) FROM landing_faqs WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $max = $stmt->fetchColumn();
        
        return ($max ?? 0) + 1;
    }
    
    /**
     * Get FAQ count
     * 
     * @param bool $activeOnly Count only active FAQs
     * @return int
     */
    public function getCount(bool $activeOnly = true): int {
        $sql = "SELECT COUNT(*) FROM landing_faqs WHERE 1=1";
        $params = [];
        
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
