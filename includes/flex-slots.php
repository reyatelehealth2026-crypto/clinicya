<?php
/**
 * Flex Studio slot catalog / แคตตาล็อก slot ของ Flex Studio
 *
 * Single source of truth for the ~25 Flex "slots" the platform can send.
 * Shared by flex-studio.php (gallery) and api/flex-preview.php (sample builder).
 *
 * Each slot: key, label (Thai), group, icon (emoji), producer (where it ships from).
 * `odoo` => true slots are only shown when $isOdooMode is on.
 */

if (!function_exists('flex_studio_slots')) {
    /**
     * @return array<int,array{key:string,label:string,group:string,icon:string,producer:string,odoo?:bool}>
     */
    function flex_studio_slots(): array
    {
        return [
            // Bot / Menu
            ['key' => 'welcome',        'label' => 'ข้อความต้อนรับ',       'group' => 'Bot / เมนู',    'icon' => '👋', 'producer' => 'BusinessBot / webhook'],
            ['key' => 'main_menu',      'label' => 'เมนูหลัก',            'group' => 'Bot / เมนู',    'icon' => '📋', 'producer' => 'BusinessBot'],
            ['key' => 'quick_menu',     'label' => 'เมนูด่วน',            'group' => 'Bot / เมนู',    'icon' => '⚡', 'producer' => 'BusinessBot'],
            ['key' => 'liff_menu',      'label' => 'เมนู LIFF',           'group' => 'Bot / เมนู',    'icon' => '📱', 'producer' => 'BusinessBot'],

            // Order / Commerce
            ['key' => 'order_receipt',  'label' => 'ใบเสร็จออเดอร์',       'group' => 'ออเดอร์',       'icon' => '🧾', 'producer' => 'api/checkout.php'],
            ['key' => 'order_status',   'label' => 'สถานะออเดอร์',        'group' => 'ออเดอร์',       'icon' => '🚚', 'producer' => 'FlexTemplates::orderStatus'],
            ['key' => 'slip_received',  'label' => 'รับสลิปแล้ว',          'group' => 'ออเดอร์',       'icon' => '✅', 'producer' => 'FlexTemplates::slipReceived'],
            ['key' => 'cart_summary',   'label' => 'สรุปตะกร้า',          'group' => 'ออเดอร์',       'icon' => '🛒', 'producer' => 'BusinessBot / cart'],

            // Product
            ['key' => 'product_card',     'label' => 'การ์ดสินค้า',        'group' => 'สินค้า',        'icon' => '🏷️', 'producer' => 'FlexTemplates::productCard'],
            ['key' => 'product_carousel', 'label' => 'สินค้าหลายชิ้น',     'group' => 'สินค้า',        'icon' => '🎠', 'producer' => 'FlexTemplates::productCarousel'],
            ['key' => 'promo_card',       'label' => 'โปรโมชั่น',          'group' => 'สินค้า',        'icon' => '🎁', 'producer' => 'FlexTemplates::promoCard'],

            // Pharmacy
            ['key' => 'medicine_label',          'label' => 'ฉลากยา (ซองยา)',   'group' => 'เภสัชกรรม',    'icon' => '💊', 'producer' => 'inbox-v2 / dispense'],
            ['key' => 'medicine_label_carousel', 'label' => 'ฉลากยาหลายรายการ',  'group' => 'เภสัชกรรม',    'icon' => '💊', 'producer' => 'inbox-v2 / dispense'],

            // Loyalty
            ['key' => 'points_receipt', 'label' => 'ใบรับแต้ม',           'group' => 'สะสมแต้ม',      'icon' => '⭐', 'producer' => 'FlexTemplates::pointsReceipt'],
            ['key' => 'reward_card',    'label' => 'ของรางวัล',           'group' => 'สะสมแต้ม',      'icon' => '🏆', 'producer' => 'BusinessBot / rewards'],
            ['key' => 'member_card',    'label' => 'บัตรสมาชิก',          'group' => 'สะสมแต้ม',      'icon' => '🪪', 'producer' => 'BusinessBot / member'],
            ['key' => 'referral_card',  'label' => 'ชวนเพื่อน',           'group' => 'สะสมแต้ม',      'icon' => '🤝', 'producer' => 'FlexTemplates::referralCard'],

            // Reminders (cron)
            ['key' => 'rmd_medication',    'label' => 'เตือนกินยา',        'group' => 'แจ้งเตือน',     'icon' => '⏰', 'producer' => 'cron/medication_reminder'],
            ['key' => 'rmd_refill',        'label' => 'เตือนเติมยา',       'group' => 'แจ้งเตือน',     'icon' => '🔁', 'producer' => 'cron/medication_refill_reminder'],
            ['key' => 'rmd_appointment',   'label' => 'เตือนนัดหมาย',      'group' => 'แจ้งเตือน',     'icon' => '📅', 'producer' => 'cron/appointment_reminder'],
            ['key' => 'rmd_reward_expiry', 'label' => 'เตือนแต้มหมดอายุ',   'group' => 'แจ้งเตือน',     'icon' => '⌛', 'producer' => 'cron/reward_expiry_reminder'],
            ['key' => 'rmd_restock',       'label' => 'เตือนของเข้าใหม่',   'group' => 'แจ้งเตือน',     'icon' => '📦', 'producer' => 'cron/restock_notification'],
            ['key' => 'rmd_wishlist',      'label' => 'เตือนสินค้าที่อยากได้', 'group' => 'แจ้งเตือน',  'icon' => '❤️', 'producer' => 'cron/wishlist_notification'],

            // Other
            ['key' => 'notification', 'label' => 'แจ้งเตือนทั่วไป', 'group' => 'อื่นๆ', 'icon' => '🔔', 'producer' => 'FlexTemplates::notification'],
            ['key' => 'odoo_order',   'label' => 'ออเดอร์ Odoo',    'group' => 'อื่นๆ', 'icon' => '🧩', 'producer' => 'OdooFlexTemplates', 'odoo' => true],
            ['key' => 'pos_receipt',  'label' => 'ใบเสร็จ POS',     'group' => 'อื่นๆ', 'icon' => '🖨️', 'producer' => 'POSReceiptService'],
        ];
    }

    /** Valid slot keys, honouring the Odoo kill-switch. */
    function flex_studio_slot_keys(bool $isOdooMode = false): array
    {
        $keys = [];
        foreach (flex_studio_slots() as $s) {
            if (!empty($s['odoo']) && !$isOdooMode) {
                continue;
            }
            $keys[] = $s['key'];
        }
        return $keys;
    }
}
