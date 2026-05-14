<?php
/**
 * Inbox Product Intelligence API
 * 
 * GET ?action=overview             - Dashboard: trending products + categories (uses cache)
 * GET ?action=stock_check          - Check stock from Odoo API (slow, for detail views)
 * GET ?action=product_detail       - Single product detail from Odoo
 * GET ?action=low_stock_alert      - Products with low stock + high mentions
 * GET ?action=trending_products    - Raw trending list
 * 
 * Cached endpoints (APCu 15 min): overview, low_stock_alert, trending_products
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

function jsonResp($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

require_once __DIR__ . '/../classes/CnyOdooAPI.php';

$odooApi    = new CnyOdooAPI();
$action     = $_GET['action'] ?? 'overview';
$days       = max(1, min(30, intval($_GET['days'] ?? 7)));
$productCode = $_GET['product_code'] ?? '';
$lowStockThreshold = intval($_GET['low_stock_threshold'] ?? 20);
$line_account_id = intval($_GET['line_account_id'] ?? 3);

// ═══════════════════════════════════════════════════════════════
// DATA LAYER
// ═══════════════════════════════════════════════════════════════

function getDb(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('mysql:host=localhost;dbname=cny_re_ya_com;charset=utf8mb4', 'cny_re_ya_com', 'cny_re_ya_com', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $db;
}

function getTodayMessages(PDO $db, int $days = 1): array {
    $stmt = $db->prepare("
        SELECT m.id, m.user_id, m.content, m.created_at, u.display_name
        FROM messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.direction = 'incoming'
          AND m.message_type = 'text'
          AND m.content IS NOT NULL
          AND LENGTH(m.content) >= 3
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND m.content NOT IN ('contact', 'menu', 'shop', 'liff_menu', 'ยกเลิก', 'สมัครสมาชิก', 'ขั้นตอน')
          AND m.content NOT LIKE '%ลูกค้าทั่วไป%'
          AND m.content NOT LIKE '%ยอดชำระ%'
          AND m.content NOT LIKE '%ชำระเงินให้ทางร้าน%'
          AND m.content NOT LIKE '%@cnypharmacy%'
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * ค้นหาชื่อสินค้าจากข้อความ โดย match กับ cny_products + shop_products
 */
function findMentionedProducts(PDO $db, array $messages): array {
    if (empty($messages)) return [];

    $prodStmt = $db->query("
        SELECT
            c.sku AS product_code,
            c.sku,
            c.name,
            c.qty AS saleable_qty,
            c.qty_incoming,
            COALESCE(o.generic_name, '') AS generic_name,
            COALESCE(o.category, '') AS category,
            COALESCE(o.list_price, 0) AS list_price,
            COALESCE(o.online_price, 0) AS online_price,
            c.enable
        FROM cny_products c
        LEFT JOIN shop_products o ON o.product_code = c.sku
        WHERE c.enable = '1' OR c.enable = 1
        ORDER BY c.qty ASC
    ");
    $allProducts = $prodStmt->fetchAll();

    // Pre-build search index: word => [product_codes]
    $nameIndex = [];
    $codeIndex = [];
    foreach ($allProducts as $prod) {
        $code = $prod['product_code'];
        $name = mb_strtolower($prod['name'] ?? '');
        $generic = mb_strtolower($prod['generic_name'] ?? '');

        if (strlen($code) >= 3) $codeIndex[$code] = $code;

        $words = preg_split('/[\s\[\]\(\)#\/,.\-]+/u', $name);
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4) $nameIndex[$w][] = $code;
        }
        if ($generic) {
            $gwords = preg_split('/[\s\[\]\(\)\/,.\-]+/u', $generic);
            foreach ($gwords as $w) {
                if (mb_strlen($w) >= 5) $nameIndex[$w][] = $code;
            }
        }
    }

    $prodMap = [];
    foreach ($allProducts as $p) {
        $prodMap[$p['product_code']] = $p;
    }

    $mentioned = [];
    foreach ($messages as $msg) {
        $content = mb_strtolower(trim($msg['content']));
        if (mb_strlen($content) < 3) continue;

        $matchedCodes = [];

        // Check code index
        foreach ($codeIndex as $code => $idx) {
            if (stripos($content, $code) !== false) {
                $matchedCodes[$code] = 'code';
            }
        }

        // Check name/generic word index
        foreach ($nameIndex as $word => $codes) {
            if (stripos($content, $word) !== false) {
                foreach ($codes as $c) {
                    if (!isset($matchedCodes[$c])) $matchedCodes[$c] = 'name';
                }
            }
        }

        foreach ($matchedCodes as $code => $matchType) {
            if (!isset($mentioned[$code])) {
                $p = $prodMap[$code];
                $mentioned[$code] = [
                    'product_code' => $code,
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'generic_name' => $p['generic_name'],
                    'category' => $p['category'],
                    'list_price' => (float)$p['list_price'],
                    'online_price' => (float)$p['online_price'],
                    'cache_qty' => (float)$p['saleable_qty'],
                    'qty_incoming' => (float)($p['qty_incoming'] ?? 0),
                    'match_type' => $matchType,
                    'mention_count' => 0,
                    'mentioners' => [],
                ];
            }
            $mentioned[$code]['mention_count']++;
            $name = $msg['display_name'];
            if ($name && !in_array($name, $mentioned[$code]['mentioners'])) {
                $mentioned[$code]['mentioners'][] = $name;
            }
        }
    }

    usort($mentioned, fn($a, $b) => $b['mention_count'] - $a['mention_count']);
    return array_values($mentioned);
}

function checkStockFromOdoo($odooApi, PDO $db, array $productCodes, int $cacheHours = 6): array {
    $results = [];
    $errors = 0;

    $freshCodes = [];
    $staleCodes = [];
    $now = time();
    $cacheExpiry = $now - ($cacheHours * 3600);

    foreach ($productCodes as $code) {
        $stmt = $db->prepare("SELECT product_code, last_synced_at FROM shop_products WHERE product_code = ? AND line_account_id = 3 AND last_synced_at >= FROM_UNIXTIME(?)");
        $stmt->execute([$code, $cacheExpiry]);
        $cached = $stmt->fetch();
        if ($cached) {
            // Already fresh in cache, get full data
            $full = $db->prepare("SELECT * FROM shop_products WHERE product_code = ? AND line_account_id = 3");
            $full->execute([$code]);
            $row = $full->fetch();
            if ($row) {
                $results[$code] = [
                    'product_code' => $code,
                    'sku' => $row['sku'] ?? '',
                    'name' => $row['name'] ?? '',
                    'generic_name' => $row['generic_name'] ?? '',
                    'category' => $row['category'] ?? '',
                    'saleable_qty' => (float)($row['saleable_qty'] ?? 0),
                    'list_price' => (float)($row['list_price'] ?? 0),
                    'active' => (bool)($row['is_active'] ?? 1),
                ];
                continue;
            }
        }
        $freshCodes[] = $code;
    }

    foreach ($freshCodes as $code) {
        try {
            $resp = $odooApi->getProduct($code);
            if (is_array($resp) && isset($resp['success']) && $resp['success']) {
                $data = $resp['data'] ?? [];
                $products = $data['products'] ?? [];
                if (!empty($products)) {
                    $p = $products[0];
                    $results[$code] = [
                        'product_code' => $code,
                        'sku' => $p['sku'] ?? '',
                        'name' => $p['name'] ?? '',
                        'generic_name' => $p['generic_name'] ?? '',
                        'category' => $p['category'] ?? '',
                        'saleable_qty' => (float)($p['saleable_qty'] ?? 0),
                        'list_price' => (float)($p['list_price'] ?? 0),
                        'active' => (bool)($p['active'] ?? true),
                    ];

                    $stmtUp = $db->prepare("
                        INSERT INTO shop_products (line_account_id, product_id, product_code, sku, name, generic_name, barcode, category, list_price, online_price, saleable_qty, is_active, last_synced_at)
                        VALUES (3, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            product_id=VALUES(product_id), sku=VALUES(sku), name=VALUES(name),
                            generic_name=VALUES(generic_name), barcode=VALUES(barcode),
                            category=VALUES(category), list_price=VALUES(list_price),
                            online_price=VALUES(online_price), saleable_qty=VALUES(saleable_qty),
                            is_active=VALUES(is_active), last_synced_at=NOW()
                    ");
                    $stmtUp->execute([$p['id']??null, $code, $p['sku']??null, $p['name']??'', $p['generic_name']??'', $p['barcode']??null, $p['category']??'', (float)($p['list_price']??0), (float)($p['online_price']??0), (float)($p['saleable_qty']??0), (int)($p['active']??1)]);
                } else { $errors++; }
            } else { $errors++; }
        } catch (Exception $e) { $errors++; }
    }

    return ['results' => $results, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════
// ROUTER with APCu cache for heavy endpoints
// ═══════════════════════════════════════════════════════════════

try {
    $db = getDb();



    switch ($action) {

        case 'product_detail':
            if (empty($productCode)) jsonResp(['error' => 'product_code is required'], 400);
            $stock = checkStockFromOdoo($odooApi, $db, [$productCode]);
            jsonResp($stock);

        case 'trending_products':
            $msgs = getTodayMessages($db, $days);
            $trending = findMentionedProducts($db, $msgs);
            $result = [
                'days' => $days,
                'total_messages' => count($msgs),
                'products_mentioned' => count($trending),
                'data' => $trending,
            ];

            jsonResp($result);

        case 'low_stock_alert':
            $msgs = getTodayMessages($db, $days);
            $trending = findMentionedProducts($db, $msgs);
            $alerts = [];
            foreach ($trending as $t) {
                $qty = $t['cache_qty'];
                if ($qty !== null && $qty <= $lowStockThreshold) {
                    $alerts[] = [
                        'product_code' => $t['product_code'],
                        'name' => $t['name'],
                        'generic_name' => $t['generic_name'],
                        'category' => $t['category'],
                        'live_qty' => $qty,
                        'threshold' => $lowStockThreshold,
                        'mention_count' => $t['mention_count'],
                        'mentioners' => $t['mentioners'],
                        'active' => true,
                    ];
                }
            }
            usort($alerts, fn($a, $b) => $a['live_qty'] - $b['live_qty']);
            $result = [
                'days' => $days,
                'low_stock_threshold' => $lowStockThreshold,
                'total_alerts' => count($alerts),
                'data' => $alerts,
            ];

            jsonResp($result);

        case 'overview':
        default:
            $msgs = getTodayMessages($db, $days);
            $trending = findMentionedProducts($db, $msgs);

            // Use cache data only (fast, no Odoo API)
            $merged = [];
            foreach ($trending as $t) {
                $merged[] = array_merge($t, [
                    'live_qty' => $t['cache_qty'],
                    'active' => true,
                ]);
            }

            $lowStock = array_filter($merged, fn($p) =>
                $p['live_qty'] !== null && $p['live_qty'] <= $lowStockThreshold
            );
            usort($lowStock, fn($a, $b) => $a['live_qty'] - $b['live_qty']);

            $categories = [];
            foreach ($merged as $p) {
                $cat = $p['category'] ?? 'อื่นๆ';
                if (!isset($categories[$cat])) {
                    $categories[$cat] = ['category' => $cat, 'count' => 0, 'mentions' => 0];
                }
                $categories[$cat]['count']++;
                $categories[$cat]['mentions'] += $p['mention_count'];
            }
            usort($categories, fn($a, $b) => $b['mentions'] - $a['mentions']);

            $result = [
                'days' => $days,
                'total_messages' => count($msgs),
                'products_mentioned' => count($trending),
                'products' => $merged,
                'low_stock_alerts' => array_values($lowStock),
                'categories' => array_values($categories),
            ];

            jsonResp($result);
    }

} catch (Exception $e) {
    jsonResp(['error' => $e->getMessage()], 500);
}
