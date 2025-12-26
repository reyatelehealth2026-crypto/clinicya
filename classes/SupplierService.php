<?php
/**
 * SupplierService - จัดการข้อมูล Supplier
 */

class SupplierService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Create new supplier
     */
    public function create(array $data): int {
        // Generate code if not provided
        if (empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO suppliers 
            (line_account_id, code, name, contact_person, phone, email, address, tax_id, payment_terms, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $data['code'],
            $data['name'],
            $data['contact_person'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['tax_id'] ?? null,
            $data['payment_terms'] ?? 30
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update supplier
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'contact_person', 'phone', 'email', 'address', 'tax_id', 'payment_terms'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE suppliers SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }
    
    /**
     * Deactivate supplier
     */
    public function deactivate(int $id): bool {
        $stmt = $this->db->prepare("UPDATE suppliers SET is_active = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Activate supplier
     */
    public function activate(int $id): bool {
        $stmt = $this->db->prepare("UPDATE suppliers SET is_active = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Get all suppliers
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT * FROM suppliers WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR code LIKE ? OR contact_person LIKE ?)";
            $search = "%{$filters['search']}%";
            $params = array_merge($params, [$search, $search, $search]);
        }
        
        $sql .= " ORDER BY name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get supplier by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Generate supplier code
     */
    private function generateCode(): string {
        $stmt = $this->db->query("SELECT MAX(id) FROM suppliers");
        $maxId = (int)$stmt->fetchColumn();
        return sprintf("SUP-%04d", $maxId + 1);
    }
    
    /**
     * Update total purchase amount
     */
    public function updateTotalPurchase(int $supplierId, float $amount): bool {
        $stmt = $this->db->prepare("UPDATE suppliers SET total_purchase_amount = total_purchase_amount + ? WHERE id = ?");
        return $stmt->execute([$amount, $supplierId]);
    }
}
