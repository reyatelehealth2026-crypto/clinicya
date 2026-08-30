<?php
/**
 * Catalog of LINE Flex samples the bot can send.
 *
 * This page-facing catalog keeps the samples deterministic and avoids calling
 * live bot handlers that may mutate cart/order/loyalty state.
 */

require_once __DIR__ . '/FlexTemplates.php';

class FlexSampleCatalog
{
    public static function all(array $context = []): array
    {
        $shopName = (string) ($context['shop_name'] ?? 'REYA Pharmacy');
        $customerName = (string) ($context['customer_name'] ?? 'คุณลูกค้า');
        $shopUrl = (string) ($context['shop_url'] ?? 'https://re-ya.com/');
        $liffShopUrl = (string) ($context['liff_shop_url'] ?? $shopUrl);
        $liffVideoUrl = (string) ($context['liff_video_url'] ?? '');
        $logoUrl = (string) ($context['logo_url'] ?? '');
        $phone = (string) ($context['phone'] ?? '02-000-0000');

        $shopInfo = [
            'name' => $shopName,
            'phone' => $phone,
            'logo' => $logoUrl,
            'address' => 'ตัวอย่างที่อยู่ร้านยา',
            'open_hours' => '08:00-22:00 น.',
            'pharmacist' => 'ภก. ตัวอย่าง',
        ];

        $products = self::sampleProducts();
        $cartItems = [
            ['name' => 'ชุดตรวจ ATK', 'quantity' => 2, 'subtotal' => 180],
            ['name' => 'วิตามินซี 1000 mg', 'quantity' => 1, 'subtotal' => 220],
            ['name' => 'หน้ากากอนามัย', 'quantity' => 1, 'subtotal' => 120],
        ];
        $medicineItems = self::sampleMedicineItems();

        $entries = [
            'welcome' => self::entry('ต้อนรับลูกค้าใหม่', 'First touch', 'follow / start', 'FlexTemplates::welcome', FlexTemplates::toMessage(
                FlexTemplates::welcome($customerName, null, $shopName),
                'ยินดีต้อนรับ'
            )),
            'main_menu' => self::entry('เมนูหลัก', 'Bot menu', 'menu', 'FlexTemplates::mainMenu', FlexTemplates::toMessage(
                FlexTemplates::mainMenu($shopName),
                'เมนูหลัก'
            )),
            'quick_menu' => self::entry('เมนูลัด', 'Bot menu', 'quick menu', 'FlexTemplates::quickMenu', FlexTemplates::toMessage(
                FlexTemplates::quickMenu($shopName),
                'เมนูลัด'
            )),
            'liff_menu' => self::entry('เมนูเปิด LIFF', 'LIFF', 'เปิดร้าน / shop', 'FlexTemplates::liffMenu', FlexTemplates::toMessage(
                FlexTemplates::liffMenu($shopName, $liffShopUrl, $liffVideoUrl, $customerName),
                'เมนูร้านยา'
            )),
            'first_message_menu' => self::entry('เมนูลูกค้าใหม่', 'LIFF', 'first message', 'FlexTemplates::firstMessageMenu', FlexTemplates::toMessage(
                FlexTemplates::firstMessageMenu($shopName, $liffShopUrl, $customerName),
                'เริ่มใช้งานร้านยา'
            )),
            'product_card' => self::entry('การ์ดสินค้าเดี่ยว', 'Shop', 'product 101', 'FlexTemplates::productCard', FlexTemplates::toMessage(
                FlexTemplates::productCard($products[0]),
                'สินค้าแนะนำ'
            )),
            'product_carousel' => self::entry('สินค้าแบบ Carousel', 'Shop', 'shop', 'FlexTemplates::productCarousel', FlexTemplates::toMessage(
                FlexTemplates::productCarousel($products),
                'รายการสินค้า'
            )),
            'shareable_product' => self::entry('สินค้าแบบแชร์ได้', 'Shop', 'share product', 'FlexTemplates::shareableProductCard', FlexTemplates::toMessage(
                FlexTemplates::shareableProductCard($products[1], 'สินค้าแนะนำจากร้าน ' . $shopName),
                'สินค้าแชร์ได้'
            )),
            'cart_summary' => self::entry('สรุปตะกร้า', 'Order', 'cart', 'FlexTemplates::cartSummary', FlexTemplates::toMessage(
                FlexTemplates::cartSummary($cartItems, 520, 4),
                'สรุปตะกร้า'
            )),
            'order_status' => self::entry('สถานะออเดอร์', 'Order', 'orders', 'FlexTemplates::orderStatus', FlexTemplates::toMessage(
                FlexTemplates::orderStatus('RYA-1001', 'shipping', 'TH123456789TH', 'กำลังจัดส่งสินค้าให้คุณ'),
                'สถานะออเดอร์'
            )),
            'slip_received' => self::entry('รับสลิปโอนเงิน', 'Payment', 'ส่งสลิป', 'FlexTemplates::slipReceived', FlexTemplates::toMessage(
                FlexTemplates::slipReceived('RYA-1001', 520),
                'ได้รับสลิปแล้ว'
            )),
            'receipt' => self::entry('ใบเสร็จคำสั่งซื้อ', 'Payment', 'receipt', 'FlexTemplates::receipt', FlexTemplates::toMessage(
                FlexTemplates::receipt([
                    'order_number' => 'RYA-1001',
                    'created_at' => date('Y-m-d H:i:s'),
                    'shipping_fee' => 40,
                    'grand_total' => 560,
                ], [
                    ['product_name' => 'ชุดตรวจ ATK', 'quantity' => 2, 'subtotal' => 180],
                    ['product_name' => 'วิตามินซี 1000 mg', 'quantity' => 1, 'subtotal' => 220],
                ], $shopName),
                'ใบเสร็จ'
            )),
            'points_receipt' => self::entry('ใบรับแต้มสะสม', 'Loyalty', 'สะสมแต้ม', 'FlexTemplates::pointsReceipt', FlexTemplates::toMessage(
                FlexTemplates::pointsReceipt([
                    'voucher_no' => 'PTS-1001',
                    'points' => 56,
                    'amount' => 560,
                    'payment_method' => 'transfer',
                    'total_points' => 1280,
                    'claimed_at' => date('Y-m-d H:i:s'),
                ], $shopInfo, $customerName),
                'ใบรับแต้ม'
            )),
            'medicine_label' => self::entry('ฉลากยาเดี่ยว', 'Dispense', 'dispense', 'FlexTemplates::medicineLabel', FlexTemplates::toMessage(
                FlexTemplates::medicineLabel($medicineItems[0], $shopInfo, $customerName, $shopUrl . 'checkout'),
                'ฉลากยา'
            )),
            'medicine_carousel' => self::entry('ฉลากยาหลายรายการ', 'Dispense', 'dispense multiple', 'FlexTemplates::medicineLabelsCarousel', FlexTemplates::toMessage(
                FlexTemplates::medicineLabelsCarousel($medicineItems, $shopInfo, $customerName, $shopUrl . 'checkout'),
                'ฉลากยาหลายรายการ'
            )),
            'promo_card' => self::entry('โปรโมชันพร้อมแชร์', 'Marketing', 'promo', 'FlexTemplates::promoCard', FlexTemplates::toMessage(
                FlexTemplates::promoCard('โปรสมาชิกวันนี้', 'ซื้อครบ 500 บาท รับแต้มพิเศษ 2 เท่า', self::sampleImage('promo'), 'shop', 'โปรสมาชิกจากร้าน ' . $shopName),
                'โปรโมชัน'
            )),
            'referral_card' => self::entry('แนะนำเพื่อน', 'Marketing', 'referral', 'FlexTemplates::referralCard', FlexTemplates::toMessage(
                FlexTemplates::referralCard($customerName, 'REYA2026', 'คูปองส่วนลด 50 บาท', $shopUrl),
                'แนะนำเพื่อน'
            )),
            'image_carousel' => self::entry('รูปภาพ Carousel', 'Marketing', 'ดูรูป', 'FlexTemplates::imageCarousel', FlexTemplates::imageCarousel([
                ['url' => self::sampleImage('pharmacy-1'), 'text' => 'ดูโปรโมชัน'],
                ['url' => self::sampleImage('pharmacy-2'), 'text' => 'ดูสินค้า'],
            ])),
            'success' => self::entry('แจ้งสำเร็จ', 'System', 'success', 'FlexTemplates::success', FlexTemplates::toMessage(
                FlexTemplates::success('ทำรายการสำเร็จ', 'ระบบบันทึกข้อมูลเรียบร้อยแล้ว'),
                'สำเร็จ'
            )),
            'warning' => self::entry('แจ้งเตือน', 'System', 'warning', 'FlexTemplates::warning', FlexTemplates::toMessage(
                FlexTemplates::warning('กรุณาตรวจสอบข้อมูล', 'ข้อมูลบางส่วนยังไม่ครบถ้วน'),
                'แจ้งเตือน'
            )),
            'error' => self::entry('แจ้งข้อผิดพลาด', 'System', 'error', 'FlexTemplates::error', FlexTemplates::toMessage(
                FlexTemplates::error('ส่งไม่สำเร็จ', 'ระบบไม่พบข้อมูลลูกค้าที่เลือก', 'ลองเลือกผู้รับใหม่อีกครั้ง'),
                'เกิดข้อผิดพลาด'
            )),
            'info' => self::entry('แจ้งข้อมูลทั่วไป', 'System', 'info', 'FlexTemplates::info', FlexTemplates::toMessage(
                FlexTemplates::info('ข้อมูลร้านยา', 'เปิดให้บริการทุกวัน 08:00-22:00 น.'),
                'ข้อมูลร้านยา'
            )),
            'confirm_dialog' => self::entry('ยืนยันรายการ', 'System', 'confirm', 'FlexTemplates::confirmDialog', FlexTemplates::toMessage(
                FlexTemplates::confirmDialog('ยืนยันการสั่งซื้อ', 'ต้องการยืนยันคำสั่งซื้อนี้หรือไม่?', 'ยืนยัน', 'confirm order', 'ยกเลิก', 'cancel order'),
                'ยืนยันรายการ'
            )),
            'notification' => self::entry('ข้อความแจ้งเตือน', 'System', 'notify', 'FlexTemplates::notification', FlexTemplates::toMessage(
                FlexTemplates::notification('แจ้งเตือนจากร้านยา', 'เภสัชกรรับเรื่องแล้ว กำลังตรวจสอบข้อมูลให้ค่ะ', '🔔', '#06C755'),
                'แจ้งเตือน'
            )),
            'empty_state' => self::entry('ไม่มีข้อมูล', 'System', 'empty', 'FlexTemplates::emptyState', FlexTemplates::toMessage(
                FlexTemplates::emptyState('ยังไม่มีสินค้า', 'ตอนนี้ยังไม่มีสินค้าในหมวดนี้', 'ดูหมวดอื่น', 'shop'),
                'ไม่มีข้อมูล'
            )),
            'loading' => self::entry('กำลังดำเนินการ', 'System', 'loading', 'FlexTemplates::loading', FlexTemplates::toMessage(
                FlexTemplates::loading('กำลังตรวจสอบข้อมูลให้สักครู่...'),
                'กำลังดำเนินการ'
            )),
            'group_welcome' => self::entry('ต้อนรับเข้ากลุ่ม', 'Group', 'join group', 'FlexTemplates::groupWelcome', FlexTemplates::toMessage(
                FlexTemplates::groupWelcome('กลุ่มลูกค้าร้านยา', $shopName),
                'ยินดีต้อนรับเข้ากลุ่ม'
            )),
            'loyalty_points' => self::entry('สถานะแต้มสมาชิก', 'Loyalty', 'แต้ม / points', 'BusinessBot::showPoints', self::loyaltyPointsMessage($shopName, $customerName)),
            'member_card' => self::entry('บัตรสมาชิก', 'Loyalty', 'สมาชิก / member', 'BusinessBot::showMemberCard', self::memberCardMessage($shopName, $customerName)),
            'rewards_carousel' => self::entry('ของรางวัลสมาชิก', 'Loyalty', 'ของรางวัล / rewards', 'BusinessBot::showRewards', self::rewardsMessage()),
            'booking_card' => self::entry('นัดหมายเภสัชกร', 'Booking', 'booking', 'BusinessBot::startBooking', self::simpleActionMessage(
                'นัดหมายเภสัชกร',
                'เลือกเวลาที่สะดวกเพื่อให้เภสัชกรติดต่อกลับ',
                [['label' => 'เลือกเวลานัด', 'text' => 'booking']]
            )),
            'contact_card' => self::entry('ติดต่อร้านยา', 'Support', 'contact', 'BusinessBot::showContact', self::simpleActionMessage(
                'ติดต่อร้านยา',
                'โทร ' . $phone . ' หรือพิมพ์ข้อความหาเภสัชกรได้ทันที',
                [['label' => 'คุยกับเภสัชกร', 'text' => 'contact pharmacist']]
            )),
            'consent_card' => self::entry('ขอความยินยอม', 'Onboarding', 'consent', 'webhook consent flow', self::simpleActionMessage(
                'ยินยอมรับบริการ',
                'กรุณาอ่านเงื่อนไขและยืนยันก่อนเริ่มใช้งานบริการร้านยา',
                [['label' => 'ยอมรับและเริ่มใช้', 'text' => 'ยอมรับ']]
            )),
        ];

        return $entries;
    }

    public static function get(string $key, array $context = []): ?array
    {
        $entries = self::all($context);
        return $entries[$key] ?? null;
    }

    private static function entry(string $title, string $category, string $command, string $source, array $message): array
    {
        return [
            'title' => $title,
            'category' => $category,
            'command' => $command,
            'source' => $source,
            'message' => $message,
            'altText' => (string) ($message['altText'] ?? $title),
            'type' => (string) ($message['type'] ?? 'flex'),
        ];
    }

    private static function sampleProducts(): array
    {
        return [
            [
                'id' => 101,
                'name' => 'วิตามินซี 1000 mg',
                'price' => 290,
                'sale_price' => 220,
                'stock' => 24,
                'image_url' => self::sampleImage('vitamin'),
            ],
            [
                'id' => 102,
                'name' => 'ชุดตรวจ ATK',
                'price' => 120,
                'sale_price' => 90,
                'stock' => 40,
                'image_url' => self::sampleImage('atk'),
            ],
            [
                'id' => 103,
                'name' => 'เจลแอลกอฮอล์ 500 ml',
                'price' => 149,
                'sale_price' => null,
                'stock' => 18,
                'image_url' => self::sampleImage('gel'),
            ],
        ];
    }

    private static function sampleMedicineItems(): array
    {
        return [
            [
                'name' => 'Paracetamol 500 mg',
                'qty' => 10,
                'price' => 35,
                'image' => self::sampleImage('medicine'),
                'isMedicine' => true,
                'usageType' => 'internal',
                'dosage' => 'ครั้งละ 1 เม็ด',
                'frequency' => 'วันละ 3 ครั้ง',
                'timeOfDay' => ['morning', 'noon', 'evening'],
                'mealTiming' => 'after',
                'instructions' => 'ใช้เมื่อมีไข้หรือปวดศีรษะ',
                'warnings' => 'ห้ามรับประทานเกินวันละ 8 เม็ด',
            ],
            [
                'name' => 'เกลือแร่ ORS',
                'qty' => 3,
                'price' => 45,
                'image' => self::sampleImage('ors'),
                'isMedicine' => true,
                'usageType' => 'internal',
                'dosage' => 'ละลายน้ำ 1 แก้ว',
                'frequency' => 'จิบตามอาการ',
                'timeOfDay' => ['morning', 'evening'],
                'mealTiming' => 'after',
                'instructions' => 'ดื่มช้าๆ หลังถ่ายเหลว',
                'warnings' => 'หากอาการไม่ดีขึ้นควรพบแพทย์',
            ],
        ];
    }

    private static function loyaltyPointsMessage(string $shopName, string $customerName): array
    {
        return FlexTemplates::toMessage([
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $shopName, 'size' => 'sm', 'color' => '#06C755', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => 'แต้มสะสมของ ' . $customerName, 'size' => 'lg', 'weight' => 'bold', 'wrap' => true, 'margin' => 'md'],
                    ['type' => 'text', 'text' => '1,280 แต้ม', 'size' => '3xl', 'weight' => 'bold', 'color' => '#0F766E', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => 'ใช้แลกของรางวัลหรือส่วนลดได้ทันที', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'md'],
                ],
                'paddingAll' => 'xl',
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#06C755', 'action' => ['type' => 'message', 'label' => 'ดูของรางวัล', 'text' => 'ของรางวัล']],
                ],
            ],
        ], 'แต้มสะสม');
    }

    private static function memberCardMessage(string $shopName, string $customerName): array
    {
        return FlexTemplates::toMessage([
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'MEMBER CARD', 'size' => 'xs', 'color' => '#D1FAE5', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => $shopName, 'size' => 'lg', 'color' => '#FFFFFF', 'weight' => 'bold', 'wrap' => true, 'margin' => 'md'],
                    ['type' => 'text', 'text' => $customerName, 'size' => 'xxl', 'color' => '#FFFFFF', 'weight' => 'bold', 'wrap' => true, 'margin' => 'xl'],
                    ['type' => 'text', 'text' => 'REYA-0001280', 'size' => 'sm', 'color' => '#CCFBF1', 'margin' => 'md'],
                    ['type' => 'separator', 'color' => '#99F6E4', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => 'ระดับ Gold · 1,280 แต้ม', 'size' => 'md', 'color' => '#FFFFFF', 'weight' => 'bold', 'margin' => 'xl'],
                ],
                'backgroundColor' => '#0F766E',
                'paddingAll' => 'xl',
            ],
        ], 'บัตรสมาชิก');
    }

    private static function rewardsMessage(): array
    {
        $rewards = [
            ['title' => 'ส่วนลด 50 บาท', 'points' => '500 แต้ม', 'text' => 'redeem 1'],
            ['title' => 'จัดส่งฟรี', 'points' => '300 แต้ม', 'text' => 'redeem 2'],
            ['title' => 'วิตามินทดลอง', 'points' => '800 แต้ม', 'text' => 'redeem 3'],
        ];

        $contents = [];
        foreach ($rewards as $reward) {
            $contents[] = [
                'type' => 'bubble',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ของรางวัล', 'size' => 'xs', 'color' => '#06C755', 'weight' => 'bold'],
                        ['type' => 'text', 'text' => $reward['title'], 'size' => 'lg', 'weight' => 'bold', 'wrap' => true, 'margin' => 'md'],
                        ['type' => 'text', 'text' => $reward['points'], 'size' => 'xl', 'color' => '#0F766E', 'weight' => 'bold', 'margin' => 'xl'],
                    ],
                    'paddingAll' => 'xl',
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'style' => 'primary', 'color' => '#06C755', 'action' => ['type' => 'message', 'label' => 'แลกเลย', 'text' => $reward['text']]],
                    ],
                ],
            ];
        }

        return FlexTemplates::toMessage(['type' => 'carousel', 'contents' => $contents], 'ของรางวัลสมาชิก');
    }

    private static function simpleActionMessage(string $title, string $message, array $actions): array
    {
        $buttons = [];
        foreach ($actions as $action) {
            $buttons[] = [
                'type' => 'button',
                'style' => count($buttons) === 0 ? 'primary' : 'secondary',
                'color' => '#06C755',
                'action' => [
                    'type' => 'message',
                    'label' => $action['label'],
                    'text' => $action['text'],
                ],
            ];
        }

        return FlexTemplates::toMessage([
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $title, 'size' => 'xl', 'weight' => 'bold', 'wrap' => true],
                    ['type' => 'text', 'text' => $message, 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'md'],
                ],
                'paddingAll' => 'xl',
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $buttons,
                'spacing' => 'sm',
            ],
        ], $title);
    }

    private static function sampleImage(string $seed): string
    {
        return 'https://placehold.co/1024x768/0f766e/ffffff.png?text=' . rawurlencode($seed);
    }
}
