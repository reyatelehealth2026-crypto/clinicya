<?php
/**
 * Odoo Product Service
 * Read-only service for fetching products from Odoo API.
 */
class OdooProductService
{
    private $db;
    private $lineAccountId;
    private $baseUrl;
    private $apiUser;
    private $userToken;
    private $timeout;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->baseUrl = rtrim(defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : '', '/');
        $this->apiUser = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : '';
        $this->userToken = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '';
        $this->timeout = defined('ODOO_API_TIMEOUT') ? (int) ODOO_API_TIMEOUT : 30;
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->apiUser !== '' && $this->userToken !== '';
    }

    public function getProductsByRange($offset = 1, $limit = 20)
    {
        if (!$this->isConfigured()) {
            throw new Exception('Odoo product API is not configured');
        }

        $offset = max(1, (int) $offset);
        $limit = max(1, min(50, (int) $limit));

        $products = [];
        for ($i = $offset; $i < $offset + $limit; $i++) {
            $code = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $response = $this->request('/ineco_gc/get_product', ['PRODUCT_CODE' => $code]);

            if (($response['success'] ?? false) && !empty($response['data']['products']) && is_array($response['data']['products'])) {
                foreach ($response['data']['products'] as $product) {
                    $products[] = $this->normalizeProduct($product);
                }
            }
        }

        return [
            'products' => $products,
            'offset' => $offset,
            'limit' => $limit,
            'count' => count($products)
        ];
    }

    private function normalizeProduct(array $product)
    {
        $prices = $product['product_price_ids'] ?? [];
        $onlinePrice = null;        // tier '005' (legacy — คง fallback ไว้เพื่อ backward compat)
        $sellPrice = null;          // tier '03' / '003' (primary — ดู ODOO_PRODUCT_SYNC_PHP.md §4)

        if (is_array($prices)) {
            foreach ($prices as $row) {
                $code = (string) ($row['price_code'] ?? '');
                // normalize: '03' === '003' === '3'
                $normalizedCode = ltrim($code, '0');
                if ($normalizedCode === '') {
                    $normalizedCode = '0';
                }

                if ($code === '005' && $onlinePrice === null) {
                    $onlinePrice = (float) ($row['price'] ?? 0);
                }
                if ($normalizedCode === '3' && $sellPrice === null) {
                    $sellPrice = (float) ($row['price'] ?? 0);
                }
            }
        }

        // fallback: ถ้าไม่มี tier 03 → ใช้ list_price; ถ้าไม่มี tier 005 → ใช้ tier 03
        $listPrice = (float) ($product['list_price'] ?? 0);
        if ($sellPrice === null) {
            $sellPrice = $listPrice;
        }
        if ($onlinePrice === null) {
            $onlinePrice = $sellPrice;
        }

        return [
            'product_id' => $product['product_id'] ?? null,
            'product_code' => $product['product_code'] ?? '',
            'sku' => $product['sku'] ?? '',
            'name' => $product['name'] ?? '',
            'generic_name' => $product['generic_name'] ?? '',
            'barcode' => $product['barcode'] ?? '',
            'category' => $product['category'] ?? '',
            'list_price' => $listPrice,
            'online_price' => $onlinePrice,
            'sell_price' => $sellPrice,             // NEW: price tier 03 (primary)
            'saleable_qty' => (float) ($product['saleable_qty'] ?? 0),
            'active' => !empty($product['active'])
        ];
    }

    /**
     * Derive drug_type จาก drug_type_rules table
     * Priority: category (priority ASC) → name_contains → sku_prefix
     * กฎที่ line_account_id = NULL ใช้กับทุกบัญชี; ถ้ามี rule เฉพาะบัญชีจะ override
     *
     * @param array $product normalized product จาก normalizeProduct()
     * @return string|null drug_type หรือ null ถ้าไม่ match กฎใดเลย
     */
    public function deriveDrugType(array $product): ?string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT drug_type FROM drug_type_rules
                WHERE is_active = 1
                  AND (line_account_id IS NULL OR line_account_id = :lid)
                  AND (
                        (match_type = 'category'      AND match_value = :cat)
                     OR (match_type = 'name_contains' AND :name LIKE CONCAT('%', match_value, '%'))
                     OR (match_type = 'sku_prefix'    AND :sku LIKE CONCAT(match_value, '%'))
                  )
                ORDER BY
                  CASE WHEN line_account_id IS NULL THEN 1 ELSE 0 END,
                  priority ASC
                LIMIT 1
            ");
            $stmt->execute([
                ':lid'  => $this->lineAccountId ?? 0,
                ':cat'  => (string) ($product['category']     ?? ''),
                ':name' => (string) ($product['name']         ?? ''),
                ':sku'  => (string) ($product['sku']          ?? ''),
            ]);
            $result = $stmt->fetchColumn();
            return $result !== false && $result !== null ? (string) $result : null;
        } catch (Exception $e) {
            // ถ้าตาราง drug_type_rules ยังไม่มี (migration ยังไม่รัน) return null ไม่ throw
            error_log('deriveDrugType: ' . $e->getMessage());
            return null;
        }
    }

    private function request($endpoint, array $payload)
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Api-User: ' . $this->apiUser,
                'User-Token: ' . $this->userToken,
                'Connection: keep-alive',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5, // Reduced from 10s
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '', // Accept gzip/deflate
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Network error: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception('Invalid JSON response from Odoo');
        }

        if (isset($decoded['jsonrpc']) && isset($decoded['result'])) {
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $decoded['result']
            ];
        }

        if (isset($decoded['jsonrpc']) && isset($decoded['error'])) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'error' => $decoded['error']['message'] ?? 'Unknown Odoo error',
                'data' => $decoded['error']
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded,
        ];
    }
}
