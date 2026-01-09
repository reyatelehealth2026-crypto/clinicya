<?php
/**
 * FeaturedProductService - จัดการสินค้าแนะนำสำหรับ Landing Page
 * รองรับหลายตาราง: business_items, cny_products, products
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
            // Get manually selected products
            $sql = "SELECT lf.id, lf.product_id, lf.product_source, lf.sort_order
                    FROM landing_featured_products lf
                    WHERE lf.is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (lf.line_account_id = ? OR lf.line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY lf.sort_order ASC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $selections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($selections)) {
                return $this->getAutoFeaturedProducts($limit);
            }
            
            // Fetch actual product data from respective tables
            $products = [];
            foreach ($selections as $sel) {
                $product = $this->getProductFromSource($sel['product_id'], $sel['product_source']);
                if ($product) {
                    $product['sort_order'] = $sel['sort_order'];
                    $products[] = $product;
                }
            }
            
            return $products;
        } catch (PDOException $e) {
            return $this->getAutoFeaturedProducts($limit);
        }
    }
    
    /**
     * Get product from specific source table
     */
    private function getProductFromSource(int $productId, string $source): ?array {
        try {
            switch ($source) {
                case 'business_items':
                    $sql = "SELECT id, name, price, image_url, sku
                            FROM business_items WHERE id = ?";
                    break;
                case 'cny_products':
                    $sql = "SELECT id, name, price, image_url, sku
                            FROM cny_products WHERE id = ?";
                    break;
                default:
                    $sql = "SELECT id, name, price, sale_price, image_url, sku
                            FROM products WHERE id = ?";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Auto-select featured products from available tables
     */
    private function getAutoFeaturedProducts(int $limit = 8): array {
        $products = [];
        
        // Try business_items first
        try {
            $sql = "SELECT id, name, price, image_url, sku
                    FROM business_items 
                    WHERE is_active = 1
                    ORDER BY id DESC LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$limit]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
        
        // If not enough, try cny_products
        if (count($products) < $limit) {
            try {
                $remaining = $limit - count($products);
                $sql = "SELECT id, name, price, image_url, sku
                        FROM cny_products 
                        WHERE is_active = 1
                        ORDER BY id DESC LIMIT ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$remaining]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $products = array_merge($products, $items);
            } catch (PDOException $e) {}
        }
        
        return array_slice($products, 0, $limit);
    }
    
    /**
     * Get all featured product selections for admin
     */
    public function getAllForAdmin(): array {
        try {
            $sql = "SELECT lf.* FROM landing_featured_products lf WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (lf.line_account_id = ? OR lf.line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY lf.sort_order ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $selections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Enrich with product data
            foreach ($selections as &$sel) {
                $product = $this->getProductFromSource($sel['product_id'], $sel['product_source'] ?? 'products');
                $sel['product_name'] = $product['name'] ?? 'สินค้าถูกลบ';
                $sel['product_image'] = $product['image_url'] ?? null;
                $sel['price'] = $product['price'] ?? 0;
                $sel['product_active'] = $product ? true : false;
            }
            
            return $selections;
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Add product to featured list
     */
    public function addProduct(int $productId, string $source = 'products'): int {
        // Check if already exists
        $stmt = $this->db->prepare("SELECT id FROM landing_featured_products WHERE product_id = ? AND product_source = ? AND (line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL))");
        $stmt->execute([$productId, $source, $this->lineAccountId, $this->lineAccountId]);
        if ($stmt->fetch()) {
            throw new Exception('สินค้านี้ถูกเลือกไว้แล้ว');
        }
        
        $sortOrder = $this->getNextSortOrder();
        $stmt = $this->db->prepare("
            INSERT INTO landing_featured_products (line_account_id, product_id, product_source, sort_order, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$this->lineAccountId, $productId, $source, $sortOrder]);
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
