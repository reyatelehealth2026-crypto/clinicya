<?php
/**
 * CNY Pharmacy API Client
 * สำหรับเชื่อมต่อกับ CNY Pharmacy Manager API
 * Sync สินค้ายาเข้าระบบ LINECRM
 */

class CnyPharmacyAPI
{
    private $baseUrl = 'https://manager.cnypharmacy.com/api';
    private $token = '90xcKekelCqCAjmgkpI1saJF6N55eiNexcI4hdcYM2M';
    private $db;
    private $lineAccountId;
    private $timeout = 120;
    private $pushStateTable = 'cny_product_push_state';
    private $pushLogTable = 'cny_product_push_log';
    
    public function __construct($db = null, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    /**
     * Determine if payload indicates more pages
     */
    private function payloadHasMore($payload, $currentPage, $limit, $count)
    {
        $containers = [];
        if (is_array($payload)) {
            $containers[] = $payload;
            if (isset($payload['data']) && is_array($payload['data'])) {
                $containers[] = $payload['data'];
            }
        }

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }

            if (array_key_exists('has_more', $container)) {
                return (bool)$container['has_more'];
            }

            foreach (['last_page', 'total_page', 'total_pages', 'totalPage', 'totalPages', 'max_page', 'maxPage'] as $key) {
                if (isset($container[$key]) && is_numeric($container[$key])) {
                    return $currentPage < (int)$container[$key];
                }
            }
        }

        if ($count < $limit) {
            return false;
        }

        return true;
    }

    /**
     * Extract flat product rows from nested payloads
     */
    private function extractProductsFromPayload($payload)
    {
        if (!is_array($payload)) {
            return [];
        }

        $products = [];
        $queue = [$payload];

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (!is_array($current)) {
                continue;
            }

            if ($this->looksLikeProductRow($current)) {
                $products[] = $this->mapGroupedProductRow($current);
                continue;
            }

            if ($this->isAssocArray($current)) {
                foreach ($current as $value) {
                    if (is_array($value)) {
                        $queue[] = $value;
                    }
                }
            } else {
                foreach ($current as $value) {
                    if (is_array($value)) {
                        if ($this->looksLikeProductRow($value)) {
                            $products[] = $this->mapGroupedProductRow($value);
                        } else {
                            $queue[] = $value;
                        }
                    }
                }
            }
        }

        return $products;
    }

    private function looksLikeProductRow(array $row)
    {
        $keys = ['sku', 'SKU', 'product_code', 'productCode', 'product_id', 'productId', 'name', 'Name', 'product_name', 'productName'];
        foreach ($keys as $key) {
            if (!empty($row[$key])) {
                return true;
            }
        }
        return false;
    }

    private function mapGroupedProductRow(array $row)
    {
        $sku = $row['sku'] ?? $row['SKU'] ?? $row['product_code'] ?? $row['productCode'] ?? $row['code'] ?? '';
        $qty = $row['qty'] ?? $row['quantity'] ?? $row['stock'] ?? 0;
        $qtyIncoming = $row['qty_incoming'] ?? $row['incoming_qty'] ?? 0;
        $enable = $row['enable'] ?? ($row['active'] ?? 1);
        $productPrice = $row['product_price'] ?? $row['productPrice'] ?? $row['prices'] ?? $row['price_list'] ?? null;

        return [
            'id' => $row['id'] ?? $row['product_id'] ?? $row['productId'] ?? null,
            'sku' => (string)$sku,
            'barcode' => $row['barcode'] ?? $row['Barcode'] ?? $row['bar_code'] ?? null,
            'name' => $row['name'] ?? $row['Name'] ?? $row['product_name'] ?? $row['productName'] ?? '',
            'name_en' => $row['name_en'] ?? $row['nameEn'] ?? $row['english_name'] ?? null,
            'spec_name' => $row['spec_name'] ?? $row['specName'] ?? $row['spec'] ?? null,
            'description' => $row['description'] ?? $row['detail'] ?? $row['Description'] ?? null,
            'properties_other' => $row['properties_other'] ?? $row['propertiesOther'] ?? $row['properties'] ?? null,
            'how_to_use' => $row['how_to_use'] ?? $row['howToUse'] ?? $row['usage'] ?? null,
            'photo_path' => $row['photo_path'] ?? $row['photoPath'] ?? $row['image'] ?? $row['image_url'] ?? null,
            'qty' => is_numeric($qty) ? $qty : 0,
            'qty_incoming' => is_numeric($qtyIncoming) ? $qtyIncoming : 0,
            'enable' => (string)($enable ? '1' : '0'),
            'product_price' => $productPrice,
            'properties_raw' => $row
        ];
    }

    private function isAssocArray(array $array)
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
    
    /**
     * Set custom API credentials
     */
    public function setCredentials($baseUrl, $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        return $this;
    }

    /**
     * Build absolute URL allowing external endpoints
     */
    private function buildUrl($endpoint)
    {
        if (!$endpoint) {
            return $this->baseUrl;
        }

        if (stripos($endpoint, 'http://') === 0 || stripos($endpoint, 'https://') === 0) {
            return $endpoint;
        }

        if ($endpoint[0] !== '/') {
            $endpoint = '/' . $endpoint;
        }

        return $this->baseUrl . $endpoint;
    }
    
    /**
     * Make API request
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = $this->buildUrl($endpoint);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        // Try to decode JSON first - if it works, it's valid JSON even if description contains HTML
        $decoded = json_decode($response, true);
        
        // Check JSON decode error
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // Only check for HTML if JSON decode failed
            if (strpos($response, '<!doctype html') === 0 || strpos($response, '<html') === 0) {
                return ['success' => false, 'error' => 'API returned HTML instead of JSON', 'is_html' => true];
            }
            return ['success' => false, 'error' => 'Invalid JSON response: ' . json_last_error_msg()];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded
        ];
    }
    
    /**
     * Stream API request - save to temp file to avoid memory issues
     */
    private function requestToFile($endpoint, $timeout = 300)
    {
        $url = $this->buildUrl($endpoint);
        $tempFile = sys_get_temp_dir() . '/cny_products_' . md5($url) . '.json';
        
        $fp = fopen($tempFile, 'w');
        if (!$fp) {
            return ['success' => false, 'error' => 'Cannot create temp file'];
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json'
            ]
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        
        if ($error) {
            @unlink($tempFile);
            return ['success' => false, 'error' => $error];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'file' => $tempFile
        ];
    }
    
    /**
     * Get SKU list only - using regex streaming to avoid memory issues
     */
    public function getSkuList()
    {
        $cacheFile = sys_get_temp_dir() . '/cny_sku_list.json';
        
        // Check cache (valid for 1 hour)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $skus = json_decode(file_get_contents($cacheFile), true);
            if (is_array($skus)) {
                return ['success' => true, 'data' => $skus, 'cached' => true];
            }
        }
        
        // Download to temp file
        $result = $this->requestToFile('/get_product_all', 600);
        if (!$result['success']) {
            return $result;
        }
        
        $tempFile = $result['file'];
        
        // Stream parse - read file in chunks and extract SKUs with regex
        $skus = [];
        $handle = fopen($tempFile, 'r');
        if (!$handle) {
            @unlink($tempFile);
            return ['success' => false, 'error' => 'Cannot read temp file'];
        }
        
        $buffer = '';
        while (!feof($handle)) {
            $buffer .= fread($handle, 8192); // Read 8KB at a time
            
            // Extract SKUs using regex
            if (preg_match_all('/"sku"\s*:\s*"([^"]+)"/', $buffer, $matches)) {
                foreach ($matches[1] as $sku) {
                    if (!in_array($sku, $skus)) {
                        $skus[] = $sku;
                    }
                }
            }
            
            // Keep only last 1KB to handle SKUs split across chunks
            if (strlen($buffer) > 10000) {
                $buffer = substr($buffer, -1000);
            }
        }
        fclose($handle);
        @unlink($tempFile);
        
        // Cache the SKU list
        file_put_contents($cacheFile, json_encode($skus));
        
        return ['success' => true, 'data' => $skus, 'cached' => false, 'count' => count($skus)];
    }
    
    /**
     * Get all products data cached - ดึงข้อมูลสินค้าทั้งหมดและ cache ไว้
     */
    public function getAllProductsCached()
    {
        $cacheFile = sys_get_temp_dir() . '/cny_products_all.json';
        
        // Check cache (valid for 1 hour)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $products = json_decode(file_get_contents($cacheFile), true);
            if (is_array($products)) {
                return ['success' => true, 'data' => $products, 'cached' => true];
            }
        }
        
        // Download to temp file
        $result = $this->requestToFile('/get_product_all', 600);
        if (!$result['success']) {
            return $result;
        }
        
        $tempFile = $result['file'];
        $content = file_get_contents($tempFile);
        @unlink($tempFile);
        
        $products = json_decode($content, true);
        if (!is_array($products)) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        // Cache the products
        file_put_contents($cacheFile, $content);
        
        return ['success' => true, 'data' => $products, 'cached' => false, 'count' => count($products)];
    }
    
    /**
     * Get product from cached data by SKU
     */
    public function getProductFromCache($sku)
    {
        $result = $this->getAllProductsCached();
        if (!$result['success']) {
            return $result;
        }
        
        foreach ($result['data'] as $product) {
            if (($product['sku'] ?? '') === $sku) {
                return ['success' => true, 'data' => $product, 'from_cache' => true];
            }
        }
        
        return ['success' => false, 'error' => 'Product not found in cache'];
    }
    
    /**
     * Sync by fetching individual products by SKU
     */
    public function syncBySku($skuList, $options = [])
    {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database connection required'];
        }
        
        $stats = ['total' => count($skuList), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        
        foreach ($skuList as $sku) {
            try {
                $result = $this->getProductBySku($sku);
                if (!$result['success'] || empty($result['data'])) {
                    $stats['skipped']++;
                    continue;
                }
                
                $syncResult = $this->syncProduct($result['data'], $options);
                if ($syncResult['action'] === 'created') $stats['created']++;
                elseif ($syncResult['action'] === 'updated') $stats['updated']++;
                else $stats['skipped']++;
                
            } catch (Exception $e) {
                $stats['errors'][] = ['sku' => $sku, 'error' => $e->getMessage()];
            }
        }
        
        return ['success' => true, 'stats' => $stats];
    }
    
    // ==================== API METHODS ====================
    
    /**
     * ดึงข้อมูลสินค้าทั้งหมด
     */
    public function getAllProducts()
    {
        return $this->request('GET', '/get_product_all');
    }

    /**
     * Fetch paginated grouped products from public API (page 1..N)
     */
    public function fetchGroupedProductsPage($page = 1, $limit = 50)
    {
        $page = max(1, (int)$page);
        $limit = max(10, min(200, (int)$limit));
        $endpoint = sprintf('https://www.cnypharmacy.com/api/getDataProductIsGroup?page=%d&limit=%d', $page, $limit);

        $response = $this->request('GET', $endpoint);
        if (!$response['success']) {
            return $response;
        }

        $products = $this->extractProductsFromPayload($response['data']);

        return [
            'success' => true,
            'products' => $products,
            'page' => $page,
            'limit' => $limit,
            'count' => count($products),
            'has_more' => $this->payloadHasMore($response['data'], $page, $limit, count($products)),
            'raw' => $response['data']
        ];
    }

    /**
     * Fetch all grouped products across pages (up to max_pages)
     */
    public function fetchAllGroupedProducts($options = [])
    {
        $startPage = max(1, (int)($options['start_page'] ?? 1));
        $limit = (int)($options['limit'] ?? 50);
        $maxPages = (int)($options['max_pages'] ?? 200);

        $allProducts = [];
        $page = $startPage;
        $pagesProcessed = 0;

        while ($page <= $maxPages) {
            $result = $this->fetchGroupedProductsPage($page, $limit);
            if (!$result['success']) {
                $result['products'] = $allProducts;
                return $result;
            }

            $pagesProcessed++;

            if ($result['count'] === 0) {
                break;
            }

            $allProducts = array_merge($allProducts, $result['products']);

            if (!$result['has_more']) {
                break;
            }

            $page++;
        }

        return [
            'success' => true,
            'products' => $allProducts,
            'count' => count($allProducts),
            'pages_processed' => $pagesProcessed,
            'limit' => $limit
        ];
    }
    
    /**
     * ดึงข้อมูลสินค้าด้วย SKU
     */
    public function getProductBySku($sku)
    {
        return $this->request('GET', '/get_product_sku/' . urlencode($sku));
    }
    
    /**
     * อัพเดทข้อมูลสินค้า
     */
    public function updateProduct($productData)
    {
        return $this->request('POST', '/update_product', $productData);
    }
    
    /**
     * อัพเดท description สินค้า
     */
    public function updateProductDescription($sku, $description)
    {
        return $this->request('POST', '/update_product_description', [
            'sku' => $sku,
            'description' => $description
        ]);
    }

    // ==================== OUTBOUND PRODUCT PUSH ====================

    /**
     * Push single product by ID to CNY API
     */
    public function pushProductUpdateById($productId)
    {
        if (!$this->db) {
            throw new Exception('Database connection required');
        }

        $product = $this->fetchLocalProductById($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }

        return $this->pushProductUpdateFromRow($product);
    }

    /**
     * Push single product by SKU to CNY API
     */
    public function pushProductUpdateBySku($sku)
    {
        if (!$this->db) {
            throw new Exception('Database connection required');
        }

        $product = $this->fetchLocalProductBySku($sku);
        if (!$product) {
            throw new Exception('Product not found');
        }

        return $this->pushProductUpdateFromRow($product);
    }

    /**
     * Push already-loaded local product row to CNY API
     */
    public function pushProductUpdateFromRow(array $localProduct)
    {
        if (!$this->db) {
            throw new Exception('Database connection required');
        }

        $this->ensurePushTables();

        $payload = $this->normalizePushPayload($localProduct);
        $apiResponse = $this->updateProduct($payload);
        $success = $apiResponse['success'] ?? false;
        $message = $success ? 'Push completed' : ($apiResponse['error'] ?? 'Unknown error');

        $this->logProductPush(
            $localProduct['id'] ?? null,
            $payload['sku'],
            $payload,
            $apiResponse
        );

        if (!$success) {
            throw new Exception($message);
        }

        return [
            'success' => true,
            'payload' => $payload,
            'response' => $apiResponse['data'] ?? null,
            'http_code' => $apiResponse['http_code'] ?? null
        ];
    }

    /**
     * Get aggregated stats for outbound push jobs
     */
    public function getPushStats()
    {
        if (!$this->db) {
            return null;
        }

        $this->ensurePushTables();
        $table = $this->pushStateTable;

        $stats = [
            'tracked' => 0,
            'success' => 0,
            'failed' => 0,
            'pending' => 0,
            'needs_push' => 0,
            'last_success' => null,
            'last_error' => null
        ];

        try {
            $stats['tracked'] = (int)$this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $stats['success'] = (int)$this->db->query("SELECT COUNT(*) FROM {$table} WHERE last_status = 'success'")->fetchColumn();
            $stats['failed'] = (int)$this->db->query("SELECT COUNT(*) FROM {$table} WHERE last_status = 'failed'")->fetchColumn();
            $stats['pending'] = (int)$this->db->query("SELECT COUNT(*) FROM {$table} WHERE last_status = 'pending'")->fetchColumn();

            $stmt = $this->db->query("SELECT MAX(last_pushed_at) FROM {$table} WHERE last_status = 'success'");
            $stats['last_success'] = $stmt->fetchColumn() ?: null;

            $stmt = $this->db->query("SELECT last_error FROM {$table} WHERE last_status = 'failed' ORDER BY updated_at DESC LIMIT 1");
            $stats['last_error'] = $stmt->fetchColumn() ?: null;

            // Count products needing push (never pushed or updated after last push)
            $itemsTable = $this->getItemsTable();
            if ($itemsTable) {
                $needsSql = "SELECT COUNT(*)
                              FROM {$itemsTable} bi
                              LEFT JOIN {$table} ps ON ps.product_id = bi.id
                             WHERE ps.product_id IS NULL
                                OR ps.last_pushed_at IS NULL
                                OR bi.updated_at > ps.last_pushed_at";
                $stats['needs_push'] = (int)$this->db->query($needsSql)->fetchColumn();
            }

        } catch (Exception $e) {
            // Ignore stats errors
        }

        return $stats;
    }

    /**
     * Get recent push logs for dashboard display
     */
    public function getRecentPushLogs($limit = 10)
    {
        if (!$this->db) {
            return [];
        }

        $this->ensurePushTables();
        $limit = max(1, min(100, (int)$limit));

        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->pushLogTable} ORDER BY id DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Public helper for ensuring push tables exist
     */
    public function ensurePushTablesExist()
    {
        $this->ensurePushTables();
    }

    /**
     * Expose detected items table name
     */
    public function getItemsTableName()
    {
        return $this->getItemsTable();
    }
    
    // ==================== SYNC METHODS ====================
    
    /**
     * Sync สินค้าทั้งหมดเข้า LINECRM (memory efficient)
     */
    public function syncAllProducts($options = [])
    {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database connection required'];
        }
        
        $limit = $options['limit'] ?? 100;
        $offset = $options['offset'] ?? 0;
        
        // Download to temp file to avoid memory issues
        $result = $this->requestToFile('/get_product_all');
        if (!$result['success']) {
            return $result;
        }
        
        $tempFile = $result['file'];
        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        
        // Read and parse JSON in chunks
        $content = file_get_contents($tempFile);
        @unlink($tempFile); // Clean up
        
        $products = json_decode($content, true);
        unset($content); // Free memory
        
        if (!is_array($products)) {
            return ['success' => false, 'error' => 'Invalid response format'];
        }
        
        $totalProducts = count($products);
        
        // Apply offset and limit
        if ($offset > 0 || $limit > 0) {
            $products = array_slice($products, $offset, $limit > 0 ? $limit : null);
        }
        
        $stats['total'] = count($products);
        $stats['total_available'] = $totalProducts;
        
        foreach ($products as $product) {
            try {
                $syncResult = $this->syncProduct($product, $options);
                if ($syncResult['action'] === 'created') $stats['created']++;
                elseif ($syncResult['action'] === 'updated') $stats['updated']++;
                else $stats['skipped']++;
            } catch (Exception $e) {
                $stats['errors'][] = ['sku' => $product['sku'] ?? 'unknown', 'error' => $e->getMessage()];
            }
            
            // Free memory periodically
            if ($stats['total'] > 0 && ($stats['created'] + $stats['updated'] + $stats['skipped']) % 50 === 0) {
                gc_collect_cycles();
            }
        }
        
        return ['success' => true, 'stats' => $stats];
    }

    
    /**
     * Sync สินค้าเดี่ยวเข้า LINECRM
     */
    public function syncProduct($cnyProduct, $options = [])
    {
        $table = $this->getItemsTable();
        if (!$table) {
            throw new Exception('Items table not found');
        }
        
        $sku = $cnyProduct['sku'] ?? null;
        $cnyId = $cnyProduct['id'] ?? null;
        
        if (!$sku && !$cnyId) {
            return ['action' => 'skipped', 'reason' => 'No SKU or ID'];
        }
        
        // Map CNY product to LINECRM format
        $mapped = $this->mapProduct($cnyProduct, $options);
        
        // Use CNY ID as product ID option
        $useCnyId = $options['use_cny_id'] ?? true;
        
        // Check if exists by CNY ID first, then by SKU
        $existing = null;
        if ($cnyId && $useCnyId) {
            $existing = $this->findById($cnyId);
        }
        if (!$existing && $sku) {
            $existing = $this->findBySku($sku);
        }
        
        if ($existing) {
            // Update
            if ($options['update_existing'] ?? true) {
                $this->updateLocalProduct($existing['id'], $mapped, $table);
                return ['action' => 'updated', 'id' => $existing['id']];
            }
            return ['action' => 'skipped', 'reason' => 'Already exists'];
        }
        
        // Create new with CNY ID
        if ($cnyId && $useCnyId) {
            $id = $this->createLocalProductWithId($cnyId, $mapped, $table);
        } else {
            $id = $this->createLocalProduct($mapped, $table);
        }
        return ['action' => 'created', 'id' => $id];
    }
    
    /**
     * Map CNY product to LINECRM format
     */
    private function mapProduct($cnyProduct, $options = [])
    {
        // Get price (use first price or GEN price)
        $price = 0;
        $prices = $cnyProduct['product_price'] ?? [];
        if (!empty($prices)) {
            // Try to find GEN price first
            foreach ($prices as $p) {
                if (strpos($p['customer_group'] ?? '', 'GEN') !== false) {
                    $price = floatval($p['price']);
                    break;
                }
            }
            // Fallback to first price
            if ($price == 0 && isset($prices[0]['price'])) {
                $price = floatval($prices[0]['price']);
            }
        }
        
        // Get unit from first price (หน่วยจำนวน เช่น ขวด[ 60ML ])
        $unit = null;
        $baseUnit = null;
        if (!empty($prices[0]['unit'])) {
            $unit = $prices[0]['unit'];
            // Extract base unit from unit string (e.g., "ขวด[ 60ML ]" -> "ขวด")
            if (preg_match('/^([^\[\s]+)/', $unit, $matches)) {
                $baseUnit = trim($matches[1]);
            }
        }
        
        // Auto-create category from CNY category field
        $categoryId = $options['default_category_id'] ?? null;
        $autoCategory = $options['auto_category'] ?? true;
        
        if ($autoCategory && !empty($cnyProduct['category'])) {
            $categoryId = $this->getOrCreateCategory($cnyProduct['category']);
        }
        
        return [
            'sku' => $cnyProduct['sku'] ?? null,
            'barcode' => $cnyProduct['barcode'] ?? null,
            'name' => $cnyProduct['name'] ?? $cnyProduct['name_en'] ?? 'Unknown',
            'name_en' => $cnyProduct['name_en'] ?? null,
            'description' => $this->buildDescription($cnyProduct),
            'manufacturer' => $this->extractManufacturer($cnyProduct['name_en'] ?? ''),
            'generic_name' => $cnyProduct['spec_name'] ?? null,
            'usage_instructions' => $cnyProduct['how_to_use'] ?? null,
            'price' => $price,
            'unit' => $unit,
            'base_unit' => $baseUnit,
            'stock' => intval($cnyProduct['qty'] ?? 0),
            'image_url' => $cnyProduct['photo_path'] ?? null,
            'is_active' => ($cnyProduct['enable'] ?? '1') == '1' ? 1 : 0,
            'category_id' => $categoryId,
            'line_account_id' => $this->lineAccountId,
            'item_type' => 'physical',
            'delivery_method' => 'shipping',
            // New CNY-specific fields for full compatibility
            'product_price' => json_encode($prices, JSON_UNESCAPED_UNICODE),
            'properties_other' => $cnyProduct['properties_other'] ?? null,
            'photo_path' => $cnyProduct['photo_path'] ?? null,
            'cny_id' => $cnyProduct['id'] ?? null,
            'cny_category' => $cnyProduct['category'] ?? null,
            'hashtag' => $cnyProduct['hashtag'] ?? null,
            'qty_incoming' => intval($cnyProduct['qty_incoming'] ?? 0),
            'enable' => ($cnyProduct['enable'] ?? '1') == '1' ? 1 : 0,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'extra_data' => json_encode([
                'cny_id' => $cnyProduct['id'] ?? null,
                'cny_category' => $cnyProduct['category'] ?? null,
                'name_en' => $cnyProduct['name_en'] ?? null,
                'hashtag' => $cnyProduct['hashtag'] ?? null,
                'properties' => $cnyProduct['properties_other'] ?? null,
                'all_prices' => $prices,
                'qty_incoming' => $cnyProduct['qty_incoming'] ?? 0
            ], JSON_UNESCAPED_UNICODE)
        ];
    }
    
    /**
     * Parse category name from CNY format
     * Example: "GIS-04-แก้ท้องเสีย-ท้องผูก" -> "แก้ท้องเสีย-ท้องผูก"
     */
    private function parseCategoryName($cnyCategoryCode)
    {
        if (empty($cnyCategoryCode)) {
            return null;
        }
        
        // Pattern: CODE-NUMBER-ชื่อหมวดหมู่ภาษาไทย
        // Example: GIS-04-แก้ท้องเสีย-ท้องผูก
        // Example: VIT-01-วิตามิน
        // Example: MED-02-ยาแก้ปวด
        
        // Split by dash and find Thai text
        $parts = explode('-', $cnyCategoryCode);
        
        // Find first part that contains Thai characters
        $thaiParts = [];
        $foundThai = false;
        
        foreach ($parts as $part) {
            // Check if part contains Thai characters
            if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $part)) {
                $foundThai = true;
            }
            
            if ($foundThai) {
                $thaiParts[] = $part;
            }
        }
        
        if (!empty($thaiParts)) {
            return implode('-', $thaiParts);
        }
        
        // Fallback: return last part if no Thai found
        if (count($parts) > 2) {
            return implode('-', array_slice($parts, 2));
        }
        
        // Last resort: return original
        return $cnyCategoryCode;
    }
    
    /**
     * Get or create category by CNY category code
     * Auto-creates category if not exists
     */
    public function getOrCreateCategory($cnyCategoryCode)
    {
        if (empty($cnyCategoryCode) || !$this->db) {
            return null;
        }
        
        // Parse category name from code
        $categoryName = $this->parseCategoryName($cnyCategoryCode);
        if (empty($categoryName)) {
            return null;
        }
        
        // Determine categories table
        $categoriesTable = 'product_categories';
        if (!$this->tableExists($categoriesTable)) {
            // Try to create table
            try {
                $this->db->exec("CREATE TABLE IF NOT EXISTS product_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    cny_code VARCHAR(100) NULL,
                    description TEXT,
                    image_url VARCHAR(500),
                    sort_order INT DEFAULT 0,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_name (name),
                    INDEX idx_cny_code (cny_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Exception $e) {
                return null;
            }
        }
        
        // Check if cny_code column exists, add if not
        if (!$this->hasColumn($categoriesTable, 'cny_code')) {
            try {
                $this->db->exec("ALTER TABLE {$categoriesTable} ADD COLUMN cny_code VARCHAR(100) NULL");
                $this->db->exec("ALTER TABLE {$categoriesTable} ADD INDEX idx_cny_code (cny_code)");
            } catch (Exception $e) {
                // Ignore if already exists
            }
        }
        
        // First, try to find by cny_code (exact match)
        try {
            $stmt = $this->db->prepare("SELECT id FROM {$categoriesTable} WHERE cny_code = ? LIMIT 1");
            $stmt->execute([$cnyCategoryCode]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                return $existing['id'];
            }
        } catch (Exception $e) {
            // Continue to name search
        }
        
        // Try to find by name
        try {
            $stmt = $this->db->prepare("SELECT id FROM {$categoriesTable} WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1");
            $stmt->execute([$categoryName, $this->lineAccountId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update cny_code if not set
                try {
                    $this->db->prepare("UPDATE {$categoriesTable} SET cny_code = ? WHERE id = ? AND (cny_code IS NULL OR cny_code = '')")
                        ->execute([$cnyCategoryCode, $existing['id']]);
                } catch (Exception $e) {}
                
                return $existing['id'];
            }
        } catch (Exception $e) {
            // Continue to create
        }
        
        // Create new category
        try {
            // Get max sort_order
            $stmt = $this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM {$categoriesTable}");
            $nextOrder = $stmt->fetchColumn();
            
            $stmt = $this->db->prepare("INSERT INTO {$categoriesTable} (line_account_id, name, cny_code, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$this->lineAccountId, $categoryName, $cnyCategoryCode, $nextOrder]);
            
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Get all categories created from CNY sync
     */
    public function getCnyCategories()
    {
        $categoriesTable = 'product_categories';
        if (!$this->tableExists($categoriesTable)) {
            return [];
        }
        
        try {
            $stmt = $this->db->query("SELECT * FROM {$categoriesTable} WHERE cny_code IS NOT NULL AND cny_code != '' ORDER BY sort_order");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Build description from CNY product
     */
    private function buildDescription($product)
    {
        $parts = [];
        
        if (!empty($product['properties_other'])) {
            $text = $this->cleanHtml($product['properties_other']);
            if ($text) $parts[] = "สรรพคุณ: " . $text;
        }
        
        if (!empty($product['spec_name'])) {
            $text = $this->cleanHtml($product['spec_name']);
            if ($text) $parts[] = "ส่วนประกอบ: " . $text;
        }
        
        if (!empty($product['description']) && $product['description'] !== 'Product detail') {
            $text = $this->cleanHtml($product['description']);
            // Skip if it's HTML page content
            if ($text && strlen($text) < 2000 && strpos($text, 'CNY Pharmacy') === false) {
                $parts[] = $text;
            }
        }
        
        return implode("\n\n", $parts) ?: null;
    }
    
    /**
     * Clean HTML from text
     */
    private function cleanHtml($text)
    {
        if (empty($text)) return null;
        
        // Check if it's HTML
        if (strpos($text, '<html') !== false || strpos($text, '<!doctype') !== false) {
            return null; // Skip full HTML pages
        }
        
        // Strip HTML tags
        $text = strip_tags($text);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text ?: null;
    }
    
    /**
     * Extract manufacturer from name_en (usually in brackets)
     */
    private function extractManufacturer($nameEn)
    {
        if (preg_match('/\[([^\]]+)\]/', $nameEn, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    // ==================== DATABASE HELPERS ====================
    
    /**
     * Get items table name
     */
    private function getItemsTable()
    {
        // Priority: business_items (main table) > products (legacy)
        if ($this->tableExists('business_items')) return 'business_items';
        if ($this->tableExists('products')) return 'products';
        return null;
    }
    
    /**
     * Check if table exists
     */
    private function tableExists($table)
    {
        try {
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if column exists — results cached per-request to avoid N×M SHOW COLUMNS queries
     */
    private static $columnCache = [];
    private function hasColumn($table, $column)
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columnCache)) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                self::$columnCache[$key] = $stmt->rowCount() > 0;
            } catch (Exception $e) {
                self::$columnCache[$key] = false;
            }
        }
        return self::$columnCache[$key];
    }
    
    /**
     * Find product by SKU
     */
    public function findBySku($sku)
    {
        $table = $this->getItemsTable();
        if (!$table || !$this->hasColumn($table, 'sku')) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE sku = ? LIMIT 1");
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find product by ID
     */
    public function findById($id)
    {
        $table = $this->getItemsTable();
        if (!$table) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find product by barcode
     */
    public function findByBarcode($barcode)
    {
        $table = $this->getItemsTable();
        if (!$table || !$this->hasColumn($table, 'barcode')) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE barcode = ? LIMIT 1");
        $stmt->execute([$barcode]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    
    /**
     * Create new product in local database
     */
    private function createLocalProduct($data, $table)
    {
        $columns = [];
        $placeholders = [];
        $values = [];
        
        // Define allowed columns per table - including new CNY fields
        $allowedColumns = [
            'sku', 'barcode', 'name', 'name_en', 'description', 'manufacturer', 'generic_name',
            'usage_instructions', 'price', 'unit', 'base_unit', 'stock', 'image_url', 'is_active',
            'category_id', 'line_account_id', 'item_type', 'delivery_method',
            // New CNY-specific fields
            'product_price', 'properties_other', 'photo_path', 'cny_id', 'cny_category',
            'hashtag', 'qty_incoming', 'enable', 'last_synced_at'
        ];
        
        foreach ($allowedColumns as $col) {
            if (isset($data[$col]) && $this->hasColumn($table, $col)) {
                $columns[] = $col;
                $placeholders[] = '?';
                $values[] = $data[$col];
            }
        }
        
        // Handle extra_data if column exists
        if (isset($data['extra_data']) && $this->hasColumn($table, 'extra_data')) {
            $columns[] = 'extra_data';
            $placeholders[] = '?';
            $values[] = $data['extra_data'];
        }
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Create new product with specific ID (from CNY API)
     * ใช้ ID จาก CNY API แทนการ auto increment
     */
    private function createLocalProductWithId($id, $data, $table)
    {
        // Check if ID already exists
        $checkStmt = $this->db->prepare("SELECT id FROM {$table} WHERE id = ?");
        $checkStmt->execute([$id]);
        if ($checkStmt->fetch()) {
            // ID exists, update instead
            $this->updateLocalProduct($id, $data, $table);
            return $id;
        }
        
        $columns = ['id'];
        $placeholders = ['?'];
        $values = [$id];
        
        // Define allowed columns per table - including new CNY fields
        $allowedColumns = [
            'sku', 'barcode', 'name', 'name_en', 'description', 'manufacturer', 'generic_name',
            'usage_instructions', 'price', 'unit', 'base_unit', 'stock', 'image_url', 'is_active',
            'category_id', 'line_account_id', 'item_type', 'delivery_method',
            // New CNY-specific fields
            'product_price', 'properties_other', 'photo_path', 'cny_id', 'cny_category',
            'hashtag', 'qty_incoming', 'enable', 'last_synced_at'
        ];
        
        foreach ($allowedColumns as $col) {
            if (isset($data[$col]) && $this->hasColumn($table, $col)) {
                $columns[] = $col;
                $placeholders[] = '?';
                $values[] = $data[$col];
            }
        }
        
        // Handle extra_data if column exists
        if (isset($data['extra_data']) && $this->hasColumn($table, 'extra_data')) {
            $columns[] = 'extra_data';
            $placeholders[] = '?';
            $values[] = $data['extra_data'];
        }
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        return $id;
    }
    
    /**
     * Update existing product in local database
     */
    private function updateLocalProduct($id, $data, $table)
    {
        $sets = [];
        $values = [];
        
        // Columns to update - including new CNY fields
        $updateColumns = [
            'barcode', 'name', 'name_en', 'description', 'manufacturer', 'generic_name',
            'usage_instructions', 'price', 'unit', 'base_unit', 'stock', 'image_url', 'is_active',
            'category_id',
            // New CNY-specific fields
            'product_price', 'properties_other', 'photo_path', 'cny_id', 'cny_category',
            'hashtag', 'qty_incoming', 'enable', 'last_synced_at'
        ];
        
        foreach ($updateColumns as $col) {
            if (isset($data[$col]) && $this->hasColumn($table, $col)) {
                $sets[] = "{$col} = ?";
                $values[] = $data[$col];
            }
        }
        
        // Handle extra_data
        if (isset($data['extra_data']) && $this->hasColumn($table, 'extra_data')) {
            $sets[] = "extra_data = ?";
            $values[] = $data['extra_data'];
        }
        
        if (empty($sets)) return false;
        
        $values[] = $id;
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Ensure outbound push tables exist
     */
    private function ensurePushTables()
    {
        if (!$this->db) {
            return;
        }

        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS {$this->pushStateTable} (
                product_id INT PRIMARY KEY,
                sku VARCHAR(100) NULL,
                last_status ENUM('pending','success','failed') DEFAULT 'pending',
                last_error TEXT NULL,
                last_http_code INT NULL,
                last_payload JSON NULL,
                last_response JSON NULL,
                last_pushed_at DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_last_status (last_status),
                INDEX idx_last_pushed (last_pushed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->db->exec("CREATE TABLE IF NOT EXISTS {$this->pushLogTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NULL,
                sku VARCHAR(100) NULL,
                success TINYINT(1) DEFAULT 0,
                http_code INT NULL,
                message VARCHAR(255) NULL,
                payload JSON NULL,
                response JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product (product_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            // Table creation issues should not block runtime
        }
    }

    private function fetchLocalProductById($productId)
    {
        $table = $this->getItemsTable();
        if (!$table) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchLocalProductBySku($sku)
    {
        $table = $this->getItemsTable();
        if (!$table) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE sku = ? LIMIT 1");
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function normalizePushPayload(array $product)
    {
        $sku = trim($product['sku'] ?? '');
        if ($sku === '') {
            throw new Exception('SKU is required for push');
        }

        $productId = $product['cny_id'] ?? $product['id'] ?? null;
        if (!$productId) {
            throw new Exception('Product ID is required for push');
        }

        $productCode = $product['sku_id'] ?? $this->extractProductCode($sku);
        $uomName = $product['unit'] ?? ($product['base_unit'] ?? 'ชิ้น');
        $uomFactor = isset($product['uom_factor']) ? (float)$product['uom_factor'] : 1.0;

        $payload = [
            'sku' => $sku,
            'product_id' => (int)$productId,
            'product_code' => $productCode,
            'uom_factor' => $uomFactor > 0 ? $uomFactor : 1.0,
            'uom_name' => $uomName,
            'barcode' => $product['barcode'] ?? null,
            'name' => $product['name'] ?? '',
            'list_price' => isset($product['price']) ? (float)$product['price'] : 0.0,
            'active' => (bool)($product['is_active'] ?? 1),
            'benefit' => !empty($product['properties_other']),
            'how_to_use' => !empty($product['usage_instructions']),
            'caution' => !empty($product['caution'] ?? $product['contraindications'] ?? null)
        ];

        // Remove nulls but keep boolean false values
        foreach ($payload as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function extractProductCode($sku)
    {
        if (strpos($sku, '-') !== false) {
            return trim(strtok($sku, '-'));
        }
        return $sku;
    }

    private function logProductPush($productId, $sku, array $payload, array $apiResponse)
    {
        if (!$this->db) {
            return;
        }

        $success = $apiResponse['success'] ?? false;
        $httpCode = $apiResponse['http_code'] ?? null;
        $message = $success ? 'OK' : ($apiResponse['error'] ?? 'Unknown error');
        $now = date('Y-m-d H:i:s');

        try {
            $logStmt = $this->db->prepare("INSERT INTO {$this->pushLogTable} (product_id, sku, success, http_code, message, payload, response) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $logStmt->execute([
                $productId,
                $sku,
                $success ? 1 : 0,
                $httpCode,
                mb_substr($message, 0, 250),
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                isset($apiResponse['data']) ? json_encode($apiResponse['data'], JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (Exception $e) {
            // Ignore logging failure
        }

        try {
            $stateStmt = $this->db->prepare("INSERT INTO {$this->pushStateTable}
                (product_id, sku, last_status, last_error, last_http_code, last_payload, last_response, last_pushed_at)
                VALUES (:product_id, :sku, :status, :error, :http_code, :payload, :response, :pushed_at)
                ON DUPLICATE KEY UPDATE
                    sku = VALUES(sku),
                    last_status = VALUES(last_status),
                    last_error = VALUES(last_error),
                    last_http_code = VALUES(last_http_code),
                    last_payload = VALUES(last_payload),
                    last_response = VALUES(last_response),
                    last_pushed_at = VALUES(last_pushed_at),
                    updated_at = CURRENT_TIMESTAMP");

            $stateStmt->execute([
                ':product_id' => $productId,
                ':sku' => $sku,
                ':status' => $success ? 'success' : 'failed',
                ':error' => $success ? null : $message,
                ':http_code' => $httpCode,
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':response' => isset($apiResponse['data']) ? json_encode($apiResponse['data'], JSON_UNESCAPED_UNICODE) : null,
                ':pushed_at' => $now
            ]);
        } catch (Exception $e) {
            // Ignore state failure
        }
    }
    
    /**
     * Update categories for all existing products from CNY data
     * Use this to retroactively assign categories to products that were synced before auto-category feature
     */
    public function updateAllProductCategories()
    {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database connection required'];
        }
        
        $table = $this->getItemsTable();
        if (!$table) {
            return ['success' => false, 'error' => 'Products table not found'];
        }
        
        // Check if extra_data column exists
        if (!$this->hasColumn($table, 'extra_data')) {
            return ['success' => false, 'error' => 'extra_data column not found'];
        }
        
        $stats = ['total' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        
        // Get all products with extra_data
        $stmt = $this->db->query("SELECT id, extra_data, category_id FROM {$table} WHERE extra_data IS NOT NULL AND extra_data != ''");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats['total'] = count($products);
        
        foreach ($products as $product) {
            try {
                $extraData = json_decode($product['extra_data'], true);
                
                // Check if cny_category exists in extra_data
                $cnyCategory = $extraData['cny_category'] ?? null;
                
                if (empty($cnyCategory)) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Get or create category
                $categoryId = $this->getOrCreateCategory($cnyCategory);
                
                if ($categoryId && $categoryId != $product['category_id']) {
                    // Update product category
                    $updateStmt = $this->db->prepare("UPDATE {$table} SET category_id = ? WHERE id = ?");
                    $updateStmt->execute([$categoryId, $product['id']]);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
                
            } catch (Exception $e) {
                $stats['errors'][] = ['id' => $product['id'], 'error' => $e->getMessage()];
            }
        }
        
        return ['success' => true, 'stats' => $stats];
    }
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * Get sync status/stats
     */
    public function getSyncStats()
    {
        $table = $this->getItemsTable();
        if (!$table) return null;
        
        $stats = [];
        
        // Total products
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$table}");
        $stats['total'] = $stmt->fetchColumn();
        
        // Products with SKU (synced from CNY)
        if ($this->hasColumn($table, 'sku')) {
            $stmt = $this->db->query("SELECT COUNT(*) FROM {$table} WHERE sku IS NOT NULL AND sku != ''");
            $stats['with_sku'] = $stmt->fetchColumn();
        }
        
        // Active products
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
        $stats['active'] = $stmt->fetchColumn();
        
        // Out of stock
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$table} WHERE stock <= 0");
        $stats['out_of_stock'] = $stmt->fetchColumn();
        
        return $stats;
    }
    
    /**
     * Test API connection
     */
    public function testConnection()
    {
        $result = $this->request('GET', '/get_product_sku/0001');
        return [
            'success' => $result['success'],
            'http_code' => $result['http_code'] ?? null,
            'message' => $result['success'] ? 'Connection successful' : ($result['error'] ?? 'Connection failed')
        ];
    }
    
    /**
     * Search products from CNY API
     */
    public function searchProducts($keyword)
    {
        $result = $this->getAllProducts();
        if (!$result['success']) return $result;
        
        $products = $result['data'];
        $filtered = array_filter($products, function($p) use ($keyword) {
            $searchIn = strtolower(
                ($p['name'] ?? '') . ' ' . 
                ($p['name_en'] ?? '') . ' ' . 
                ($p['sku'] ?? '') . ' ' . 
                ($p['barcode'] ?? '') . ' ' .
                ($p['spec_name'] ?? '')
            );
            return strpos($searchIn, strtolower($keyword)) !== false;
        });
        
        return ['success' => true, 'data' => array_values($filtered)];
    }
    
    /**
     * Get product with all price tiers
     */
    public function getProductWithPrices($sku)
    {
        $result = $this->getProductBySku($sku);
        if (!$result['success']) return $result;
        
        $product = $result['data'];
        
        // Format prices
        $prices = [];
        foreach ($product['product_price'] ?? [] as $p) {
            $prices[] = [
                'group' => $p['customer_group'] ?? 'Unknown',
                'price' => floatval($p['price']),
                'unit' => $p['unit'] ?? '',
                'enabled' => ($p['enable'] ?? '1') == '1'
            ];
        }
        
        return [
            'success' => true,
            'product' => $product,
            'prices' => $prices
        ];
    }
}
