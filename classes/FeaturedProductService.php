<?php
/**
 * FeaturedProductService - จัดการสินค้าแนะนำสำหรับ Landing Page
 */

class FeaturedProductService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get featured products for landing page
     */
    public function getFeaturedProducts(int $limit = 8): array {
        try {
            // First try to get manually selected products
            $sql = "SELECT p.id, p.name, p.price, p.sale_price, p.image_url, p.sku,
                           lf.sort_order
                    FROM landing_featured_products lf
                    INNER JOIN products p ON lf.product_id = p.id
                    WHERE lf.is_active = 1 AND p.is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (lf.line_account_id = ? OR lf.line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY lf.sort_order ASC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no manual selection, fallback to featured/bestseller products
            if (empty($products)) {
                return $this->getAutoFeaturedProducts($limit);
            }
            
            return $products;
        } catch (PDOException $e) {
            return $this->getAutoFeaturedProducts($limit);
        }
    }
    
    /**
     * Auto-select featured products based on flags
     */
    private function getAutoFeaturedProducts(int $limit = 8): array {
        try {
            $sql = "SELECT id, name, price, sale_price, image_url, sku
                    FROM products 
                    WHERE is_active = 1 
                    AND (is_featured = 1 OR is_bestseller = 1 OR is_new = 1)";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY is_featured DESC, is_bestseller DESC, is_new DESC, id DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get all featured product selections for admin
     */
    public function getAllForAdmin(): array {
        try {
            $sql = "SELECT lf.*, p.name as product_name, p.image_url as product_image, p.price, p.is_active as product_active
                    FROM landing_featured_products lf
                    LEFT JOIN products p ON lf.product_id = p.id
                    WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (lf.line_account_id = ? OR lf.line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY lf.sort_order ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Search products for selection
     */
    public function searchProducts(string $query, int $limit = 20): array {
        $sql = "SELECT id, name, sku, price, image_url 
                FROM products 
                WHERE is_active = 1 
                AND (name LIKE ? OR sku LIKE ?)";
        $params = ["%{$query}%", "%{$query}%"];
        
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY name ASC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Add product to featured list
     */
    public function addProduct(int $productId): int {
        // Check if already exists
        $stmt = $this->db->prepare("SELECT id FROM landing_featured_products WHERE product_id = ? AND (line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL))");
        $stmt->execute([$productId, $this->lineAccountId, $this->lineAccountId]);
        if ($stmt->fetch()) {
            throw new Exception('สินค้านี้ถูกเลือกไว้แล้ว');
        }
        
        $sortOrder = $this->getNextSortOrder();
        $stmt = $this->db->prepare("
            INSERT INTO landing_featured_products (line_account_id, product_id, sort_order, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$this->lineAccountId, $productId, $sortOrder]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Remove product from featured list
     */
    public function removeProduct(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM landing_featured_products WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Reorder featured products
     */
    public function reorder(array $ids): bool {
        $stmt = $this->db->prepare("UPDATE landing_featured_products SET sort_order = ? WHERE id = ?");
        foreach ($ids as $order => $id) {
            $stmt->execute([$order + 1, (int)$id]);
        }
        return true;
    }
    
    /**
     * Toggle product active status
     */
    public function toggleActive(int $id): bool {
        $stmt = $this->db->prepare("UPDATE landing_featured_products SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    private function getNextSortOrder(): int {
        $stmt = $this->db->query("SELECT MAX(sort_order) FROM landing_featured_products");
        return ((int)$stmt->fetchColumn()) + 1;
    }
    
    public function getCount(): int {
        try {
            $sql = "SELECT COUNT(*) FROM landing_featured_products WHERE is_active = 1";
            $params = [];
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}
