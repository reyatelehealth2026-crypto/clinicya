<?php
/**
 * Admin Interactive Tour launcher.
 * Included near the end of includes/footer.php (before </body>).
 *
 * Emits:
 *   - <link> to tour.css
 *   - <script> with window.__ADMIN_TOUR_CONFIG (8 tour stops, Thai copy)
 *   - <script src="...tour.js">
 *
 * Trigger sources:
 *   - URL params ?after=wizard or ?tour=1
 *   - Any element with [data-admin-tour-launch] (see user dropdown in header.php)
 */

if (!isset($baseUrl)) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
}

$adminTourSteps = [
    [
        'selector'  => '.sidebar',
        'fallbacks' => ['#sidebar', 'aside.sidebar'],
        'title'     => 'เมนูหลักของระบบ',
        'content'   => 'เมนูทั้งหมดถูกจัดกลุ่มตาม workflow — ดูภาพรวมได้จากแถบนี้',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="inbox-v2"]',
        'fallbacks' => ['a[href$="inbox-v2.php"]'],
        'title'     => 'Inbox — กล่องข้อความรวม',
        'content'   => 'ตอบแชทลูกค้า + จ่ายยา + แท็ก ทั้งหมดในจุดเดียว',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="pharmacy"]',
        'fallbacks' => ['a[href$="pharmacy.php"]', 'a[href*="/pharmacy"]'],
        'title'     => 'ระบบจ่ายยา',
        'content'   => 'มาตรฐานการจ่ายยา + ฉลากยา + ส่งผ่าน LINE อัตโนมัติ',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="dispense-tracking"]',
        'fallbacks' => ['a[href$="dispense-tracking.php"]'],
        'title'     => 'ติดตามการรับยาซ้ำ (Refill)',
        'content'   => 'ติดตามยาคงเหลือลูกค้า — เตือนล่วงหน้า 3 วันก่อนหมด',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="shop/orders"]',
        'fallbacks' => ['a[href$="shop/orders.php"]', 'a[href*="orders.php"]'],
        'title'     => 'ออเดอร์ลูกค้า',
        'content'   => 'จัดการออเดอร์ + ยืนยันสลิป + เชื่อมต่อ WMS',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="inventory"]',
        'fallbacks' => ['a[href$="inventory.php"]', 'a[href*="/inventory"]'],
        'title'     => 'คลังสินค้า',
        'content'   => 'ดูสต๊อก + ปรับสต๊อก + แจ้งเตือนสินค้าใกล้หมดอายุ',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="broadcast"]',
        'fallbacks' => ['a[href$="broadcast.php"]'],
        'title'     => 'Broadcast LINE',
        'content'   => 'ส่งโปรโมชั่นถึงลูกค้าผ่าน LINE — กรองตามแท็กได้',
        'position'  => 'right',
    ],
    [
        'selector'  => 'a[href*="settings"]',
        'fallbacks' => ['a[href$="settings.php"]'],
        'title'     => 'ตั้งค่า & ความช่วยเหลือ',
        'content'   => 'ตั้งค่าระบบทั้งหมด — เปิดทัวร์นี้ใหม่ได้ตลอดที่หน้า /help',
        'position'  => 'right',
    ],
];

$adminTourConfig = [
    'version'    => 'v1',
    'autoLaunch' => false, // auto only via ?after=wizard or ?tour=1
    'steps'      => $adminTourSteps,
];
?>
<link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>includes/onboarding/tour.css">
<script>
    window.__ADMIN_TOUR_CONFIG = <?= json_encode($adminTourConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars($baseUrl) ?>includes/onboarding/tour.js" defer></script>
