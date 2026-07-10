<?php
/**
 * Flex Studio — real-data preview API
 * คืน Flex contents (bubble/carousel) ของแต่ละ slot สำหรับ live preview
 *
 * GET api/flex-preview.php?slot=order_receipt
 *   → { success, slot, altText, contents }
 *
 * Uses the shop's REAL branding (shop_settings + flex_brand_settings) with
 * representative sample content, routed through FlexTemplates::render() so the
 * active theme and any saved per-slot override are applied — the preview is the
 * same render path as a real send. Read-only; tenant-scoped by current_bot_id.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/FlexTemplates.php';
require_once __DIR__ . '/../includes/flex-slots.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? ($_SESSION['line_account_id'] ?? null);

if (!$lineAccountId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no tenant / ไม่พบร้าน']);
    exit;
}
$lineAccountId = (int) $lineAccountId;

$slot = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['slot'] ?? ''));
if ($slot === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing slot']);
    exit;
}

// Odoo kill-switch: block odoo_* slots for non-Odoo tenants.
$orderDataSource = function_exists('getShopOrderDataSource') ? getShopOrderDataSource($db, $lineAccountId) : 'internal';
$isOdooMode = ($orderDataSource === 'odoo') && defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true;
if (!in_array($slot, flex_studio_slot_keys($isOdooMode), true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'unknown slot']);
    exit;
}

/** Normalise: some template methods return a full flex message envelope. */
function fp_contents($x)
{
    if (is_array($x) && ($x['type'] ?? null) === 'flex' && isset($x['contents'])) {
        return $x['contents'];
    }
    return $x;
}

// ── shop branding (real) ────────────────────────────────────────────────
$shopInfo = ['name' => 'ร้านยา', 'address' => '', 'phone' => '', 'logo' => '', 'open_hours' => '08:00-20:00 น.', 'pharmacist' => ''];
try {
    $stmt = $db->prepare("SELECT shop_name, shop_logo, contact_phone, shop_address, pharmacist_name FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    if ($ss = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $shopInfo['name'] = $ss['shop_name'] ?: $shopInfo['name'];
        $shopInfo['logo'] = $ss['shop_logo'] ?: '';
        $shopInfo['phone'] = $ss['contact_phone'] ?: '';
        $shopInfo['address'] = $ss['shop_address'] ?: '';
        $shopInfo['pharmacist'] = $ss['pharmacist_name'] ?: '';
    }
} catch (Exception $e) {
    // defaults stand
}
$shopName = $shopInfo['name'];

/** Fetch one real product for product slots; fall back to a sample. */
function fp_sample_product(PDO $db, int $lineAccountId): array
{
    try {
        $stmt = $db->prepare("SELECT name, price, image_url FROM business_items WHERE line_account_id = ? AND (is_active = 1 OR is_active IS NULL) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$lineAccountId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'id' => 0,
                'name' => $row['name'] ?? 'สินค้าตัวอย่าง',
                'price' => (float) ($row['price'] ?? 120),
                'image' => $row['image_url'] ?: 'https://via.placeholder.com/300x200?text=Product',
                'image_url' => $row['image_url'] ?: 'https://via.placeholder.com/300x200?text=Product',
                'description' => 'สินค้าตัวอย่างจากร้านของคุณ',
            ];
        }
    } catch (Exception $e) {
        // fall through
    }
    return [
        'id' => 0, 'name' => 'พาราเซตามอล 500mg', 'price' => 35.0,
        'image' => 'https://via.placeholder.com/300x200?text=Product',
        'image_url' => 'https://via.placeholder.com/300x200?text=Product',
        'description' => 'ตัวอย่างสินค้า',
    ];
}

// ── sample builders per slot (fallback for render) ──────────────────────
$sampleOrder = [
    'order_number' => 'ORD-2026-0001',
    'created_at'   => date('Y-m-d H:i:s'),
    'shipping_fee' => 40,
    'grand_total'  => 320,
];
$sampleItems = [
    ['product_name' => 'พาราเซตามอล 500mg', 'quantity' => 2, 'subtotal' => 70],
    ['product_name' => 'วิตามินซี 1000mg',  'quantity' => 1, 'subtotal' => 210],
];
$sampleMed = [
    'name' => 'Amoxicillin 500mg', 'productName' => 'Amoxicillin 500mg',
    'image' => $shopInfo['logo'] ?: 'https://via.placeholder.com/100x100?text=Rx',
    'isMedicine' => true, 'usageType' => 'internal',
    'dosage' => 'ครั้งละ 1 แคปซูล', 'quantity' => '20 แคปซูล',
    'timeOfDay' => ['morning', 'evening'], 'mealTiming' => 'after',
    'instructions' => 'รับประทานให้ครบตามที่แพทย์สั่ง',
    'warning' => 'หากมีผื่นคันหยุดใช้ทันที',
];

$altText = 'ตัวอย่าง Flex';

$builders = [
    'welcome' => function () use ($shopName) {
        return fp_contents(FlexTemplates::welcome('คุณลูกค้า', null, $shopName, []));
    },
    'main_menu' => function () use ($shopName) {
        return fp_contents(FlexTemplates::mainMenu($shopName));
    },
    'quick_menu' => function () use ($shopName) {
        return fp_contents(FlexTemplates::quickMenu($shopName));
    },
    'liff_menu' => function () use ($shopName) {
        return fp_contents(FlexTemplates::liffMenu($shopName, '', '', 'คุณลูกค้า'));
    },
    'order_receipt' => function () use ($sampleOrder, $sampleItems, $shopName) {
        return fp_contents(FlexTemplates::receipt($sampleOrder, $sampleItems, $shopName));
    },
    'order_status' => function () {
        return fp_contents(FlexTemplates::orderStatus('ORD-2026-0001', 'shipped', 'TH1234567890', 'พัสดุกำลังจัดส่ง'));
    },
    'slip_received' => function () {
        return fp_contents(FlexTemplates::slipReceived('ORD-2026-0001', 320));
    },
    'cart_summary' => function () use ($sampleItems) {
        $items = array_map(fn($i) => ['name' => $i['product_name'], 'quantity' => $i['quantity'], 'price' => $i['subtotal']], $sampleItems);
        return fp_contents(FlexTemplates::cartSummary($items, 280, 3));
    },
    'product_card' => function () use ($db, $lineAccountId) {
        return fp_contents(FlexTemplates::productCard(fp_sample_product($db, $lineAccountId)));
    },
    'product_carousel' => function () use ($db, $lineAccountId) {
        $p = fp_sample_product($db, $lineAccountId);
        return fp_contents(FlexTemplates::productCarousel([$p, $p, $p]));
    },
    'promo_card' => function () {
        return fp_contents(FlexTemplates::promoCard('โปรลดพิเศษ', 'ลด 20% ทุกชิ้นวันนี้เท่านั้น', 'https://via.placeholder.com/600x300?text=Promo'));
    },
    'medicine_label' => function () use ($sampleMed, $shopInfo) {
        return fp_contents(FlexTemplates::medicineLabel($sampleMed, $shopInfo, 'คุณลูกค้า'));
    },
    'medicine_label_carousel' => function () use ($sampleMed, $shopInfo) {
        return fp_contents(FlexTemplates::medicineLabelsCarousel([$sampleMed, $sampleMed], $shopInfo, 'คุณลูกค้า'));
    },
    'points_receipt' => function () use ($shopInfo) {
        $claim = ['points' => 50, 'total_points' => 320, 'amount' => 500, 'order_number' => 'ORD-2026-0001', 'created_at' => date('Y-m-d H:i:s')];
        return fp_contents(FlexTemplates::pointsReceipt($claim, $shopInfo, 'คุณลูกค้า'));
    },
    'reward_card' => function () use ($shopName) {
        return fp_contents(FlexTemplates::notification('🏆 ของรางวัล', "แลก 200 แต้ม รับส่วนลด 50 บาท\nที่ $shopName", '🎁', FlexTemplates::BRAND_PRIMARY, [['label' => 'แลกเลย', 'text' => 'redeem']]));
    },
    'member_card' => function () use ($shopName) {
        return fp_contents(FlexTemplates::notification('🪪 บัตรสมาชิก', "คุณลูกค้า\nสมาชิก $shopName\nแต้มสะสม 320", '⭐', FlexTemplates::BRAND_PRIMARY));
    },
    'referral_card' => function () use ($shopName) {
        return fp_contents(FlexTemplates::referralCard('คุณลูกค้า', 'REF12345', 'รับ 50 แต้มเมื่อเพื่อนสมัคร', ''));
    },
    'rmd_medication' => function () {
        return fp_contents(FlexTemplates::notification('⏰ ถึงเวลาทานยา', "Amoxicillin 500mg\nครั้งละ 1 แคปซูล หลังอาหารเช้า", '💊', FlexTemplates::BRAND_PRIMARY, [['label' => 'ทานแล้ว', 'text' => 'taken']]));
    },
    'rmd_refill' => function () use ($shopName) {
        return fp_contents(FlexTemplates::notification('🔁 ยาใกล้หมดแล้ว', "ยาของคุณเหลืออีก 3 วัน\nสั่งเติมยาที่ $shopName ได้เลย", '💊', FlexTemplates::BRAND_PRIMARY, [['label' => 'สั่งเติมยา', 'text' => 'refill']]));
    },
    'rmd_appointment' => function () {
        return fp_contents(FlexTemplates::notification('📅 เตือนนัดหมาย', "พรุ่งนี้ 10:00 น.\nนัดปรึกษาเภสัชกร", '📅', FlexTemplates::BRAND_PRIMARY));
    },
    'rmd_reward_expiry' => function () {
        return fp_contents(FlexTemplates::notification('⌛ แต้มใกล้หมดอายุ', "แต้ม 120 คะแนนจะหมดอายุใน 7 วัน\nรีบใช้ก่อนหมดเวลา", '⭐', FlexTemplates::BRAND_PRIMARY, [['label' => 'ใช้แต้ม', 'text' => 'rewards']]));
    },
    'rmd_restock' => function () {
        return fp_contents(FlexTemplates::notification('📦 สินค้าเข้าใหม่', "สินค้าที่คุณรอ กลับมาแล้ว!\nรีบสั่งก่อนหมด", '📦', FlexTemplates::BRAND_PRIMARY, [['label' => 'สั่งซื้อ', 'text' => 'shop']]));
    },
    'rmd_wishlist' => function () {
        return fp_contents(FlexTemplates::notification('❤️ สินค้าที่อยากได้ลดราคา', "สินค้าในรายการโปรดของคุณ ลดราคาแล้ว", '❤️', FlexTemplates::BRAND_PRIMARY, [['label' => 'ดูสินค้า', 'text' => 'shop']]));
    },
    'notification' => function () {
        return fp_contents(FlexTemplates::notification('🔔 แจ้งเตือน', 'ข้อความแจ้งเตือนตัวอย่างจากร้านของคุณ', '🔔', FlexTemplates::BRAND_PRIMARY));
    },
    'odoo_order' => function () {
        return fp_contents(FlexTemplates::notification('🧩 ออเดอร์ Odoo', "SO-0001\nยอดรวม ฿320\nสถานะ: ยืนยันแล้ว", '📦', FlexTemplates::BRAND_PRIMARY));
    },
    'pos_receipt' => function () use ($sampleOrder, $sampleItems, $shopName) {
        return fp_contents(FlexTemplates::receipt($sampleOrder, $sampleItems, $shopName));
    },
];

if (!isset($builders[$slot])) {
    // Valid slot but no dedicated sample builder → generic notification card.
    $builders[$slot] = function () use ($shopName) {
        return fp_contents(FlexTemplates::notification('ตัวอย่าง Flex', "ตัวอย่างข้อความจาก $shopName", '🔔', FlexTemplates::BRAND_PRIMARY));
    };
}

try {
    // render() applies the shop's active override (if any) + brand theme.
    $vars = []; // sample builders are self-contained; overrides render standalone
    $contents = FlexTemplates::render($slot, $vars, $lineAccountId, function () use ($builders, $slot) {
        return $builders[$slot]();
    });

    echo json_encode([
        'success'  => true,
        'slot'     => $slot,
        'altText'  => $altText,
        'contents' => $contents,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'render failed', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
