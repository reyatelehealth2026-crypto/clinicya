<?php
/**
 * CNY Odoo ERP API Endpoints - Standalone Version
 * ไม่ต้องพึ่ง config อื่นๆ
 */

// CRITICAL: Error handling must be FIRST - before any includes
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Set headers before any potential output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Register shutdown function to clean output buffer on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error: ' . $error['message']
        ]);
    }
});

require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

/**
 * Inline CnyOdooAPI for standalone operation
 */
class CnyOdooAPIStandalone
{
    private $baseUrl;
    private $apiUser;
    private $userToken;
    private $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : '', '/');
        $this->apiUser = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : '';
        $this->userToken = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '';
        $this->timeout = defined('ODOO_API_TIMEOUT') ? (int) ODOO_API_TIMEOUT : 30;
    }

    private function request($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;

        if ($this->baseUrl === '' || $this->apiUser === '' || $this->userToken === '') {
            return [
                'success' => false,
                'error' => 'Odoo credentials are not configured. Please set CNY_ODOO_API_USER and CNY_ODOO_USER_TOKEN in environment/config.'
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Api-User: ' . $this->apiUser,
                'User-Token: ' . $this->userToken
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response',
                'raw' => substr($response, 0, 200)
            ];
        }

        // Handle Odoo JSON-RPC format
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
                'error' => $decoded['error']['message'] ?? 'Unknown error',
                'data' => $decoded['error']
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded
        ];
    }

    public function testConnection()
    {
        $result = $this->getProduct('0001');
        return [
            'success' => $result['success'] ?? false,
            'message' => ($result['success'] ?? false) ? 'เชื่อมต่อสำเร็จ' : ($result['error'] ?? 'ไม่สามารถเชื่อมต่อได้'),
            'base_url' => $this->baseUrl,
            'api_user' => $this->apiUser
        ];
    }

    public function getApiInfo()
    {
        return [
            'name' => 'CNY Odoo ERP API',
            'version' => '1.0',
            'base_url' => $this->baseUrl,
            'api_user' => $this->apiUser,
            'endpoints' => [
                ['name' => 'Get Product', 'path' => '/ineco_gc/get_product', 'method' => 'POST'],
                ['name' => 'Get SKU', 'path' => '/ineco_gc/get_sku', 'method' => 'POST'],
                ['name' => 'Get Partner', 'path' => '/ineco_gc/get_partner', 'method' => 'POST'],
                ['name' => 'Get Partner Details', 'path' => '/ineco_gc/get_partner_details', 'method' => 'POST'],
                ['name' => 'Create Sale Order', 'path' => '/ineco_gc/create_sale_order', 'method' => 'POST'],
                ['name' => 'Get Sale Order', 'path' => '/ineco_gc/get_sale_order', 'method' => 'POST'],
                ['name' => 'Get Sale Invoice', 'path' => '/ineco_gc/get_sale_invoice', 'method' => 'POST'],
                ['name' => 'Update Delivery Fee', 'path' => '/ineco_gc/update_delivery_fee', 'method' => 'POST'],
                ['name' => 'Calculate Delivery Fee', 'path' => '/ineco_gc/calculate_delivery_fee', 'method' => 'POST']
            ]
        ];
    }

    public function getProduct($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_product', ['PRODUCT_CODE' => $productCode]);
    }

    // Get multiple products with pagination
    public function getProductList($offset = 0, $limit = 10)
    {
        // Since the API doesn't have a list endpoint, we'll search products by code range
        $products = [];
        $startCode = str_pad($offset, 4, '0', STR_PAD_LEFT);
        $endCode = str_pad($offset + $limit, 4, '0', STR_PAD_LEFT);

        // Fetch products in range
        for ($i = $offset; $i < $offset + $limit; $i++) {
            $code = str_pad($i, 4, '0', STR_PAD_LEFT);
            $result = $this->getProduct($code);
            if ($result['success'] && isset($result['data']['products']) && !empty($result['data']['products'])) {
                foreach ($result['data']['products'] as $product) {
                    $products[] = $product;
                }
            }
        }

        return [
            'success' => true,
            'data' => [
                'products' => $products,
                'offset' => $offset,
                'limit' => $limit,
                'count' => count($products)
            ]
        ];
    }

    public function getSku($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_sku', ['PRODUCT_CODE' => $productCode]);
    }

    public function getPartner($partnerCode)
    {
        return $this->request('POST', '/ineco_gc/get_partner', ['PARTNER_CODE' => $partnerCode]);
    }

    public function getPartnerDetails($partnerId)
    {
        return $this->request('POST', '/ineco_gc/get_partner_details', ['PARTNER_ID' => intval($partnerId)]);
    }

    public function createSaleOrder($orderData)
    {
        return $this->request('POST', '/ineco_gc/create_sale_order', $orderData);
    }

    public function createSimpleSaleOrder($orderRef, $partnerId, $partnerCode, $items, $options = [])
    {
        $sumSubtotal = 0;
        $orderLines = [];

        foreach ($items as $item) {
            $subtotal = ($item['qty'] ?? 1) * ($item['price_unit'] ?? 0);
            $discount = $item['discount'] ?? 0;
            $subtotalAfterDiscount = $subtotal * (1 - $discount / 100);

            $orderLines[] = [
                'product_id' => $item['product_id'],
                'qty' => $item['qty'] ?? 1,
                'price_unit' => $item['price_unit'] ?? 0,
                'discount' => $discount,
                'price_subtotal' => $subtotalAfterDiscount
            ];

            $sumSubtotal += $subtotalAfterDiscount;
        }

        $discountAmount = $options['discount_amount'] ?? 0;
        $amountAfterDiscount = $sumSubtotal - $discountAmount;
        $amountUntax = round($amountAfterDiscount / 1.07, 2);
        $taxed = round($amountAfterDiscount - $amountUntax, 2);

        $orderData = [
            'order_ref' => $orderRef,
            'marketplace' => $options['marketplace'] ?? 'LINE',
            'marketplace_shop_name' => $options['marketplace_shop_name'] ?? 'LINE OA',
            'payment_data' => $options['payment_data'] ?? 'COD',
            'customer_order' => [
                'partner_id' => intval($partnerId),
                'partner_code' => $partnerCode
            ],
            'customer_delivery_address' => [
                'partner_shipping_address_id' => $options['shipping_address_id'] ?? $partnerId,
                'partner_shipping_address_code' => $options['shipping_address_code'] ?? $partnerCode . '-01'
            ],
            'order_line' => $orderLines,
            'order_bottom_amount' => [
                [
                    'sum_price_subtotal' => $sumSubtotal,
                    'discount_amount' => $discountAmount,
                    'amount_after_discount' => $amountAfterDiscount,
                    'amount_untax' => $amountUntax,
                    'taxed' => $taxed,
                    'total_amount' => $amountAfterDiscount
                ]
            ]
        ];

        return $this->createSaleOrder($orderData);
    }

    public function getSaleOrder($orderRef)
    {
        return $this->request('POST', '/ineco_gc/get_sale_order', ['ORDER_REF' => $orderRef]);
    }

    public function getSaleInvoice($invoiceNumber)
    {
        return $this->request('POST', '/ineco_gc/get_sale_invoice', ['INVOICE_NUMBER' => $invoiceNumber]);
    }

    public function updateDeliveryFee($orderId, $deliveryFee)
    {
        return $this->request('POST', '/ineco_gc/update_delivery_fee', [
            'order_id' => intval($orderId),
            'delivery_fee' => floatval($deliveryFee)
        ]);
    }

    public function calculateDeliveryFee($province, $weight)
    {
        return $this->request('POST', '/ineco_gc/calculate_delivery_fee', [
            'province' => $province,
            'weight' => floatval($weight)
        ]);
    }
}

// Initialize API
$odooApi = new CnyOdooAPIStandalone();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($_POST, $input);

try {
    switch ($action) {
        case 'test':
            $result = $odooApi->testConnection();
            break;

        case 'info':
            $result = ['success' => true, 'data' => $odooApi->getApiInfo()];
            break;

        case 'get_product':
            $productCode = $data['product_code'] ?? $data['PRODUCT_CODE'] ?? '';
            if (empty($productCode))
                throw new Exception('product_code is required');
            $result = $odooApi->getProduct($productCode);
            break;

        case 'search_products':
            $offset = intval($data['offset'] ?? 1);
            $limit = min(intval($data['limit'] ?? 10), 20); // Max 20 items
            $result = $odooApi->getProductList($offset, $limit);
            break;

        case 'get_sku':
            $productCode = $data['product_code'] ?? $data['PRODUCT_CODE'] ?? '';
            if (empty($productCode))
                throw new Exception('product_code is required');
            $result = $odooApi->getSku($productCode);
            break;

        case 'get_partner':
            $partnerCode = $data['partner_code'] ?? $data['PARTNER_CODE'] ?? '';
            if (empty($partnerCode))
                throw new Exception('partner_code is required');
            $result = $odooApi->getPartner($partnerCode);
            break;

        case 'get_partner_details':
            $partnerId = $data['partner_id'] ?? $data['PARTNER_ID'] ?? '';
            if (empty($partnerId))
                throw new Exception('partner_id is required');
            $result = $odooApi->getPartnerDetails($partnerId);
            break;

        case 'create_sale_order':
            $result = $odooApi->createSaleOrder($data);
            break;

        case 'create_simple_order':
            $orderRef = $data['order_ref'] ?? '';
            $partnerId = $data['partner_id'] ?? '';
            $partnerCode = $data['partner_code'] ?? '';
            $items = $data['items'] ?? [];
            if (empty($orderRef) || empty($partnerId) || empty($items)) {
                throw new Exception('order_ref, partner_id, and items are required');
            }
            $result = $odooApi->createSimpleSaleOrder($orderRef, $partnerId, $partnerCode, $items, $data);
            break;

        case 'get_sale_order':
            $orderRef = $data['order_ref'] ?? $data['ORDER_REF'] ?? '';
            if (empty($orderRef))
                throw new Exception('order_ref is required');
            $result = $odooApi->getSaleOrder($orderRef);
            break;

        case 'get_sale_invoice':
            $invoiceNumber = $data['invoice_number'] ?? $data['INVOICE_NUMBER'] ?? '';
            if (empty($invoiceNumber))
                throw new Exception('invoice_number is required');
            $result = $odooApi->getSaleInvoice($invoiceNumber);
            break;

        case 'update_delivery_fee':
            $orderId = $data['order_id'] ?? '';
            $deliveryFee = $data['delivery_fee'] ?? '';
            if (empty($orderId) || $deliveryFee === '')
                throw new Exception('order_id and delivery_fee are required');
            $result = $odooApi->updateDeliveryFee($orderId, $deliveryFee);
            break;

        case 'calculate_delivery_fee':
            $province = $data['province'] ?? '';
            $weight = $data['weight'] ?? '';
            if (empty($province) || $weight === '')
                throw new Exception('province and weight are required');
            $result = $odooApi->calculateDeliveryFee($province, $weight);
            break;

        default:
            $result = [
                'success' => false,
                'error' => 'Invalid action',
                'available_actions' => [
                    'test',
                    'info',
                    'get_product',
                    'search_products',
                    'get_sku',
                    'get_partner',
                    'get_partner_details',
                    'create_sale_order',
                    'create_simple_order',
                    'get_sale_order',
                    'get_sale_invoice',
                    'update_delivery_fee',
                    'calculate_delivery_fee'
                ]
            ];
    }

    // Clear any accidental output before sending JSON
    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    ob_end_flush();

} catch (Exception $e) {
    // Clear any accidental output before sending JSON error
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
}
