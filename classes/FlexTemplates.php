<?php
/**
 * Flex Message Templates - ข้อความสวยๆ แพรวพราว
 * รองรับ: Sender, Quick Reply, Alt Text, Emoji และอื่นๆ
 */

class FlexTemplates
{
    // Default sender profiles
    private static $senders = [
        'default' => ['name' => 'Shop Bot', 'iconUrl' => 'https://i.imgur.com/BOBkgJA.png'],
        'shop' => ['name' => 'Shop', 'iconUrl' => 'https://i.imgur.com/BOBkgJA.png'],
        'support' => ['name' => 'Support', 'iconUrl' => 'https://i.imgur.com/YkPqZKx.png'],
        'notify' => ['name' => 'Notify', 'iconUrl' => 'https://i.imgur.com/8LQHV0Z.png'],
        'order' => ['name' => 'Order', 'iconUrl' => 'https://i.imgur.com/wPVlSoK.png'],
        'payment' => ['name' => 'Payment', 'iconUrl' => 'https://i.imgur.com/3P1Z3hB.png'],
    ];

    // Default quick reply sets
    private static $quickReplySets = [
        'main' => [
            ['label' => 'ดูสินค้า', 'text' => 'shop'],
            ['label' => 'เมนู', 'text' => 'menu'],
            ['label' => 'ตะกร้า', 'text' => 'cart'],
            ['label' => 'ออเดอร์', 'text' => 'orders'],
        ],
        'shop' => [
            ['label' => 'ดูสินค้า', 'text' => 'shop'],
            ['label' => 'ตะกร้า', 'text' => 'cart'],
            ['label' => 'ชำระเงิน', 'text' => 'checkout'],
        ],
        'order' => [
            ['label' => 'เช็คสถานะ', 'text' => 'orders'],
            ['label' => 'ส่งสลิป', 'text' => 'สลิป'],
            ['label' => 'ช้อปต่อ', 'text' => 'shop'],
        ],
        'support' => [
            ['label' => 'เมนู', 'text' => 'menu'],
            ['label' => 'FAQ', 'text' => 'faq'],
            ['label' => 'โทรหาเรา', 'text' => 'contact'],
        ],
    ];

    /**
     * Set custom sender
     */
    public static function setSender($key, $name, $iconUrl)
    {
        self::$senders[$key] = ['name' => $name, 'iconUrl' => $iconUrl];
    }

    /**
     * Get sender profile
     */
    public static function getSender($key = 'default')
    {
        return self::$senders[$key] ?? self::$senders['default'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Flex Studio: per-shop brand tokens + slot overrides
    // ธีมต่อร้าน + เทมเพลต override ต่อ slot  (see docs/plans/2026-07-10-flex-studio-*)
    // ─────────────────────────────────────────────────────────────────────

    /** Canonical brand colors used across templates; remapped to shop tokens. */
    const BRAND_PRIMARY = '#06C755';       // LINE green  → primary_color
    const BRAND_PRIMARY_DARK = '#006400';  // medicine-label dark green → primary_dark

    /** Per-request token cache keyed by line_account_id ('_none' when unset). */
    private static $tokenCache = [];
    /** Current line account for auto-theming inside toMessage(). */
    private static $currentAccountId = null;

    /**
     * Set the active shop context so toMessage() auto-themes to that shop's brand.
     * Producers call this once before building Flex. Null disables theming.
     */
    public static function useAccount($lineAccountId)
    {
        self::$currentAccountId = $lineAccountId ? (int) $lineAccountId : null;
    }

    /**
     * Clear cached tokens. Long-running / multi-tenant loop processes
     * (cron, workers) should call this per tenant iteration so a rebrand or a
     * tenant switch is picked up instead of serving a stale cached theme.
     */
    public static function resetTokenCache()
    {
        self::$tokenCache = [];
    }

    /**
     * Merge brand tokens for a shop: hardcoded defaults ← shop_settings ← flex_brand_settings.
     * Always tenant-scoped by line_account_id. Never throws; missing tables fall back to defaults.
     */
    public static function getTokens($lineAccountId = null)
    {
        $id = $lineAccountId !== null ? (int) $lineAccountId : self::$currentAccountId;
        // Key by tenant too: line_account_id values collide across per-tenant DBs
        // (ADR-001), so an id-only key would leak one tenant's brand into another.
        $tenantPart = '';
        if (class_exists('TenantContext')) {
            try {
                $tid = TenantContext::getCurrentTenantId();
                if ($tid) {
                    $tenantPart = 't' . $tid . ':';
                }
            } catch (Exception $e) {
                // no tenant context → global key
            }
        }
        $cacheKey = $tenantPart . ($id === null ? '_none' : (string) $id);
        if (isset(self::$tokenCache[$cacheKey])) {
            return self::$tokenCache[$cacheKey];
        }

        $tokens = [
            'primary_color'     => self::BRAND_PRIMARY,
            'primary_dark'      => self::BRAND_PRIMARY_DARK,
            'accent_color'      => null,
            'logo_url'          => null,
            'sender_name'       => null,
            'sender_icon_url'   => null,
            'shop_display_name' => null,
            'footer_text'       => null,
            'corner_style'      => null,
        ];

        if ($id !== null && class_exists('Database')) {
            try {
                $db = Database::getInstance()->getConnection();
                try {
                    $stmt = $db->prepare("SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    if ($ss = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($ss['shop_name'])) {
                            $tokens['shop_display_name'] = $ss['shop_name'];
                        }
                        if (!empty($ss['shop_logo'])) {
                            $tokens['logo_url'] = $ss['shop_logo'];
                            $tokens['sender_icon_url'] = $ss['shop_logo'];
                        }
                    }
                } catch (Exception $e) {
                    // shop_settings missing column/table → ignore
                }
                try {
                    $stmt = $db->prepare("SELECT * FROM flex_brand_settings WHERE line_account_id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    if ($bs = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        foreach (['primary_color', 'primary_dark', 'accent_color', 'logo_url', 'sender_name', 'sender_icon_url', 'shop_display_name', 'footer_text', 'corner_style'] as $k) {
                            if (isset($bs[$k]) && $bs[$k] !== '' && $bs[$k] !== null) {
                                $tokens[$k] = $bs[$k];
                            }
                        }
                    }
                } catch (Exception $e) {
                    // flex_brand_settings not migrated yet → defaults stand
                }
            } catch (Exception $e) {
                // no DB → defaults
            }
        }

        self::$tokenCache[$cacheKey] = $tokens;
        return $tokens;
    }

    /**
     * Recolor a Flex bubble/carousel from the canonical brand colors to a shop's
     * tokens. Idempotent and non-destructive: only swaps exact brand-color hex on
     * color/backgroundColor/borderColor keys. Returns input unchanged when the shop
     * uses default colors.
     */
    public static function applyTheme($flex, $lineAccountId = null)
    {
        $tokens = self::getTokens($lineAccountId);
        $primary = $tokens['primary_color'];
        $primaryDark = $tokens['primary_dark'];
        if ($primary === self::BRAND_PRIMARY && $primaryDark === self::BRAND_PRIMARY_DARK) {
            return $flex; // nothing to remap
        }
        return self::walkTheme($flex, $primary, $primaryDark);
    }

    private static function walkTheme($node, $primary, $primaryDark)
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = self::walkTheme($v, $primary, $primaryDark);
            } elseif (is_string($v) && in_array($k, ['color', 'backgroundColor', 'borderColor', 'startColor', 'endColor'], true)) {
                $up = strtoupper($v);
                // Match the brand color exactly OR with an 8-digit alpha suffix
                // (templates build tints like '#0B5F5020'); preserve the suffix.
                // โทนคลินิก (CLINIC_MAIN/CLINIC_DEEP) นับเป็นสีแบรนด์ด้วย ไม่งั้น
                // การ์ดที่ย้ายมาใช้โทนนี้จะหลุดจากธีมของร้านไปเงียบ ๆ
                foreach ([
                    self::BRAND_PRIMARY => $primary,
                    self::CLINIC_MAIN => $primary,
                    self::BRAND_PRIMARY_DARK => $primaryDark,
                    self::CLINIC_DEEP => $primaryDark,
                ] as $brandHex => $shopHex) {
                    if (strpos($up, $brandHex) === 0) {
                        $node[$k] = $shopHex . substr($v, strlen($brandHex));
                        break;
                    }
                }
            }
        }
        return $node;
    }

    /**
     * Look up an active per-shop override for a Flex slot. Returns decoded Flex
     * contents (bubble/carousel) or null. Never throws.
     */
    public static function getActiveOverride($slotKey, $lineAccountId)
    {
        if (!$lineAccountId || !class_exists('Database')) {
            return null;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT flex_json FROM flex_templates WHERE line_account_id = ? AND slot_key = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int) $lineAccountId, $slotKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['flex_json'])) {
                $decoded = json_decode($row['flex_json'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            // slot columns not migrated / bad JSON → no override
        }
        return null;
    }

    /**
     * Replace {{var}} placeholders in every string leaf of a Flex payload.
     * Unknown placeholders are left intact.
     */
    public static function substituteVars($flex, array $vars)
    {
        if (is_array($flex)) {
            foreach ($flex as $k => $v) {
                $flex[$k] = self::substituteVars($v, $vars);
            }
            return $flex;
        }
        if (is_string($flex) && strpos($flex, '{{') !== false) {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($vars) {
                return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
            }, $flex);
        }
        return $flex;
    }

    /**
     * Central render gateway. Producers call this instead of a template method
     * directly so a shop can override a slot without code changes.
     *
     *   $contents = FlexTemplates::render('order_receipt', $vars, $lineAccountId,
     *       fn($v) => FlexTemplates::receipt($v['order'], $v['items'], $v['shop_name']));
     *
     * 1. active DB override for (line_account_id, slot_key) → its JSON + {{var}} subst
     * 2. otherwise → $fallback($vars) hardcoded builder
     * Both paths are themed to the shop's brand. Returns Flex contents (bubble/carousel);
     * wrap with toMessage() to send.
     */
    public static function render($slotKey, array $vars, $lineAccountId, callable $fallback, $allowOverride = true)
    {
        self::useAccount($lineAccountId);
        // Dynamic slots (per-event clinical/order data) must NOT be replaced by a
        // static override — pass $allowOverride=false there so the real builder wins.
        $override = $allowOverride ? self::getActiveOverride($slotKey, $lineAccountId) : null;
        $flex = $override !== null ? self::substituteVars($override, $vars) : $fallback($vars);
        return self::applyTheme($flex, $lineAccountId);
    }

    /**
     * Build Quick Reply object
     */
    public static function buildQuickReply($items = [], $preset = null)
    {
        if ($preset && isset(self::$quickReplySets[$preset])) {
            $items = self::$quickReplySets[$preset];
        }
        
        if (empty($items)) return null;

        // ผลลัพธ์ที่ build แล้วถูกส่งกลับเข้ามาอีกรอบ — เช่นผู้เรียกที่ build เอง
        // แล้วส่งต่อให้ textMessage() ซึ่งเรียกเมธอดนี้ซ้ำ ถ้าไม่กันไว้จะวนบน
        // ['items' => ...] ที่ไม่มีคีย์ label แล้ว throw หลังงานจริงสำเร็จไปแล้ว
        if (isset($items['items']) && is_array($items['items'])) {
            return $items;
        }

        $quickReplyItems = [];
        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'camera') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'camera', 'label' => $item['label'] ?? 'ถ่ายรูป']
                ];
            } elseif (isset($item['type']) && $item['type'] === 'cameraRoll') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'cameraRoll', 'label' => $item['label'] ?? 'เลือกรูป']
                ];
            } elseif (isset($item['type']) && $item['type'] === 'location') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'location', 'label' => $item['label'] ?? 'ส่งตำแหน่ง']
                ];
            } elseif (isset($item['uri'])) {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'uri', 'label' => $item['label'], 'uri' => $item['uri']]
                ];
            } elseif (isset($item['data'])) {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'postback', 'label' => $item['label'], 'data' => $item['data'], 'displayText' => $item['displayText'] ?? $item['label']]
                ];
            } else {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'message', 'label' => $item['label'], 'text' => $item['text'] ?? $item['label']]
                ];
            }
        }

        return ['items' => $quickReplyItems];
    }

    /**
     * Convert bubble/carousel to LINE message format with all options
     * @param array $contents - Flex bubble or carousel
     * @param string $altText - Alt text for notification
     * @param string|array $sender - Sender key or custom sender array
     * @param array|string $quickReply - Quick reply items or preset name
     * @return array - LINE message object
     */
    public static function toMessage($contents, $altText = 'ข้อความ', $sender = null, $quickReply = null)
    {
        // Auto-theme to the active shop's brand (no-op when unset / default colors)
        $contents = self::applyTheme($contents);

        $message = [
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $contents
        ];

        // Add sender: explicit arg → shop brand token → none
        if ($sender) {
            if (is_string($sender)) {
                $message['sender'] = self::getSender($sender);
            } elseif (is_array($sender)) {
                $message['sender'] = $sender;
            }
        } else {
            $tokens = self::getTokens();
            $tokenSender = [];
            // LINE caps sender.name at 20 chars and requires an https iconUrl;
            // enforce here so an over-long name or relative/http logo can never
            // make LINE reject the whole message.
            $nm = trim((string) ($tokens['sender_name'] ?? ''));
            if ($nm !== '') {
                $tokenSender['name'] = mb_substr($nm, 0, 20);
            }
            $icon = (string) ($tokens['sender_icon_url'] ?? '');
            if ($icon !== '' && preg_match('#^https://#i', $icon)) {
                $tokenSender['iconUrl'] = $icon;
            }
            if ($tokenSender) {
                $message['sender'] = $tokenSender;
            }
        }

        // Add quick reply
        if ($quickReply) {
            if (is_string($quickReply)) {
                $message['quickReply'] = self::buildQuickReply([], $quickReply);
            } elseif (is_array($quickReply)) {
                $message['quickReply'] = self::buildQuickReply($quickReply);
            }
        }

        return $message;
    }

    /**
     * Create text message with sender and quick reply
     */
    public static function textMessage($text, $sender = null, $quickReply = null, $emojis = null)
    {
        $message = ['type' => 'text', 'text' => $text];

        if ($sender) {
            $message['sender'] = is_string($sender) ? self::getSender($sender) : $sender;
        }

        if ($quickReply) {
            $message['quickReply'] = is_string($quickReply) ? self::buildQuickReply([], $quickReply) : self::buildQuickReply($quickReply);
        }

        if ($emojis) {
            $message['emojis'] = $emojis;
        }

        return $message;
    }

    /**
     * Welcome Message - ข้อความต้อนรับสุดปัง
     */
    public static function welcome($displayName, $pictureUrl = null, $shopName = 'LINE Shop', $features = [])
    {
        $defaultFeatures = [
            ['text' => 'สั่งซื้อสินค้าจากร้านได้ในแชท'],
            ['text' => 'ปรึกษาเภสัชกรและประเมินอาการเบื้องต้น'],
            ['text' => 'สะสมแต้มและรับสิทธิ์สมาชิก'],
            ['text' => 'ติดตามสถานะคำสั่งซื้อและการจัดส่ง'],
        ];
        $features = $features ?: $defaultFeatures;

        $rows = [['type' => 'text', 'text' => "สวัสดีคุณ {$displayName}", 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_INK, 'wrap' => true]];
        foreach ($features as $f) {
            $rows[] = ['type' => 'separator', 'color' => self::CLINIC_HAIRLINE, 'margin' => 'md'];
            $rows[] = ['type' => 'text', 'text' => self::stripEmoji($f['text'] ?? ''), 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'margin' => 'md', 'wrap' => true];
        }

        return self::clinicCard(
            $shopName,
            'ยินดีต้อนรับ ขอบคุณที่เพิ่มเพื่อน',
            $rows,
            [
                ['label' => 'ดูสินค้า', 'text' => 'shop', 'style' => 'primary'],
                ['label' => 'ดูเมนูทั้งหมด', 'text' => 'menu', 'style' => 'secondary'],
            ]
        );
    }

    /**
     * Main Menu - เมนูหลักสวยๆ (อัพเกรด V2)
     */
    public static function mainMenu($shopName = 'LINE Shop', $menuItems = null)
    {
        $defaultItems = [
            ['label' => 'ดูสินค้า', 'desc' => 'เลือกซื้อสินค้าจากร้าน', 'text' => 'shop'],
            ['label' => 'ตะกร้า', 'desc' => 'ดูรายการที่เลือกไว้', 'text' => 'cart'],
            ['label' => 'สถานะคำสั่งซื้อ', 'desc' => 'ติดตามรายการที่สั่งไว้', 'text' => 'orders'],
            ['label' => 'แจ้งชำระเงิน', 'desc' => 'ส่งสลิปโอนเงินให้เจ้าหน้าที่ตรวจสอบ', 'text' => 'สลิป'],
            ['label' => 'แต้มสะสมและสิทธิ์สมาชิก', 'desc' => 'ยอดแต้มคงเหลือและระดับสมาชิก', 'text' => 'points'],
            ['label' => 'ติดต่อร้าน', 'desc' => 'สอบถามข้อมูลเพิ่มเติม', 'text' => 'contact'],
        ];
        $menuItems = $menuItems ?: $defaultItems;

        // เมนูร้านค้าใช้โครงเดียวกับเมนูผู้ป่วย — แถวรายการ ไม่มี emoji ไม่มีสีแยกต่อปุ่ม
        $rows = [];
        foreach ($menuItems as $item) {
            $rows[] = self::clinicMenuRow(
                self::stripEmoji($item['label'] ?? ''),
                self::stripEmoji($item['desc'] ?? ''),
                ['type' => 'message', 'label' => self::stripEmoji($item['label'] ?? ''), 'text' => $item['text'] ?? $item['label'] ?? '']
            );
        }

        return self::clinicCard($shopName, 'เลือกรายการที่ต้องการ', self::clinicRows($rows));
    }

    
    /**
     * Quick Menu - เมนูด่วนแบบ Carousel
     */
    public static function quickMenu($shopName = 'LINE Shop')
    {
        $groups = [
            ['ช้อปปิ้ง', 'เลือกซื้อสินค้าจากร้าน', [
                ['ดูสินค้าทั้งหมด', 'รายการสินค้าที่เปิดขายอยู่', 'shop'],
                ['สินค้าขายดี', 'รายการที่ลูกค้าสั่งบ่อย', 'bestseller'],
                ['สินค้าใหม่', 'รายการที่เพิ่งเข้าร้าน', 'new'],
                ['โปรโมชัน', 'ส่วนลดและสิทธิพิเศษช่วงนี้', 'promotion'],
            ]],
            ['คำสั่งซื้อ', 'ตะกร้าและการชำระเงิน', [
                ['ตะกร้าสินค้า', 'ดูรายการที่เลือกไว้', 'cart'],
                ['สถานะคำสั่งซื้อ', 'ติดตามรายการที่สั่งไว้', 'orders'],
                ['แจ้งชำระเงิน', 'ส่งสลิปโอนเงินให้ตรวจสอบ', 'สลิป'],
                ['ติดตามพัสดุ', 'เลขพัสดุและสถานะจัดส่ง', 'tracking'],
            ]],
            ['สมาชิก', 'แต้มสะสมและสิทธิ์', [
                ['แต้มสะสม', 'ยอดแต้มคงเหลือ', 'points'],
                ['ระดับสมาชิก', 'สิทธิ์ตามระดับที่ได้รับ', 'membership'],
                ['ของรางวัล', 'รายการที่แลกได้ด้วยแต้ม', 'ของรางวัล'],
                ['ติดต่อร้าน', 'สอบถามข้อมูลเพิ่มเติม', 'contact'],
            ]],
        ];

        $bubbles = [];
        foreach ($groups as [$title, $subtitle, $items]) {
            $rows = [];
            foreach ($items as [$label, $hint, $text]) {
                $rows[] = self::clinicMenuRow($label, $hint, ['type' => 'message', 'label' => $label, 'text' => $text]);
            }
            $bubbles[] = self::clinicCard($title, $subtitle, self::clinicRows($rows), [], 'kilo');
        }

        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * Order Status Update - อัพเดทสถานะออเดอร์
     *
     * โทนคลินิกชุดเดียวกับเมนูผู้ป่วย ใช้สีแดงเฉพาะสถานะที่ต้องตรวจสอบ
     */
    public static function orderStatus($orderNumber, $status, $trackingNumber = null, $message = '')
    {
        $statusConfig = [
            'pending'   => ['text' => 'รอดำเนินการ', 'msg' => 'ออเดอร์ของคุณอยู่ระหว่างรอดำเนินการ'],
            'confirmed' => ['text' => 'ยืนยันแล้ว', 'msg' => 'ออเดอร์ของคุณได้รับการยืนยันแล้ว'],
            'paid'      => ['text' => 'ชำระเงินแล้ว', 'msg' => 'ได้รับการชำระเงินเรียบร้อย'],
            'shipping'  => ['text' => 'กำลังจัดส่ง', 'msg' => 'สินค้ากำลังจัดส่งถึงคุณ'],
            'delivered' => ['text' => 'จัดส่งแล้ว', 'msg' => 'สินค้าถึงมือคุณแล้ว'],
            'cancelled' => ['text' => 'ยกเลิก', 'msg' => 'ออเดอร์ถูกยกเลิก', 'alert' => true],
        ];

        $config = $statusConfig[$status] ?? ['text' => (string) $status, 'msg' => ''];
        $tone = !empty($config['alert']) ? self::CLINIC_ALERT : self::CLINIC_MAIN;

        $rows = [
            self::memberRow('สถานะ', $config['text'], $tone),
            self::memberRow('เลขที่ออเดอร์', '#' . $orderNumber),
        ];
        if ($trackingNumber) {
            $rows[] = self::memberRow('เลขพัสดุ', $trackingNumber, self::CLINIC_INK);
        }

        $body = self::clinicRows($rows);
        $statusMessage = $message ?: $config['msg'];
        if ($statusMessage !== '') {
            $body[] = ['type' => 'separator', 'color' => self::CLINIC_HAIRLINE, 'margin' => 'md'];
            $body[] = ['type' => 'text', 'text' => self::stripEmoji($statusMessage), 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'wrap' => true, 'margin' => 'md'];
        }

        return self::clinicCard(
            'สถานะคำสั่งซื้อ',
            $config['text'],
            $body,
            [['label' => 'ดูคำสั่งซื้อทั้งหมด', 'text' => 'orders', 'style' => 'secondary']]
        );
    }

    /**
     * Slip Received - ได้รับสลิปแล้ว
     */
    public static function slipReceived($orderNumber, $amount)
    {
        return self::clinicCard(
            'ได้รับสลิปแล้ว',
            'อยู่ระหว่างตรวจสอบการชำระเงิน',
            array_merge(
                self::clinicRows([
                    self::memberRow('เลขที่ออเดอร์', '#' . $orderNumber),
                    self::memberRow('ยอดชำระ', '฿' . number_format($amount, 2), self::CLINIC_MAIN),
                ]),
                [
                    ['type' => 'separator', 'color' => self::CLINIC_HAIRLINE, 'margin' => 'md'],
                    ['type' => 'text', 'text' => 'เจ้าหน้าที่จะตรวจสอบและแจ้งผลให้ทราบ', 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'wrap' => true, 'margin' => 'md'],
                ]
            )
        );
    }

    /**
     * Notification Card - การแจ้งเตือนทั่วไป
     */
    public static function notification($title, $message, $icon = '', $color = '#0B5F50', $buttons = [])
    {
        // $icon/$color ยังรับไว้เพื่อความเข้ากันได้กับผู้เรียกเดิม แต่การ์ดคลินิก
        // ไม่วาด emoji และใช้โทนเขียวเข้มชุดเดียวทั้งระบบ
        return self::clinicCard(
            $title,
            'แจ้งเตือน',
            [['type' => 'text', 'text' => self::stripEmoji($message), 'size' => 'sm', 'color' => self::CLINIC_INK, 'wrap' => true]],
            $buttons,
            'kilo'
        );
    }

    /**
     * Product Card - การ์ดสินค้า
     */
    public static function productCard($product, $showAddToCart = true)
    {
        $price = $product['sale_price'] ?? $product['price'];
        $originalPrice = ($product['sale_price'] ?? null) ? $product['price'] : null;
        
        $priceContents = [
            ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xl', 'weight' => 'bold', 'color' => '#0B5F50']
        ];
        
        if ($originalPrice) {
            $priceContents[] = ['type' => 'text', 'text' => '฿' . number_format($originalPrice), 'size' => 'sm', 'color' => '#AAAAAA', 'decoration' => 'line-through', 'margin' => 'sm'];
        }

        // Stock line is only shown when stock is actually known. Sources like
        // ProductRecommender don't always carry a stock column — a missing key
        // must NOT render as "❌ สินค้าหมด" (that wrongly blocks conversion).
        $hasStock = array_key_exists('stock', $product) && $product['stock'] !== null;
        $inStock = !$hasStock || (int) $product['stock'] > 0;

        $buttons = [];
        if ($showAddToCart && $inStock) {
            $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'เพิ่มลงตะกร้า', 'text' => "add {$product['id']}"], 'style' => 'primary', 'color' => '#0B5F50'];
        }
        $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'รายละเอียด', 'text' => "product {$product['id']}"], 'style' => 'secondary', 'margin' => 'sm'];

        $bodyContents = [
            ['type' => 'text', 'text' => $product['name'], 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
            ['type' => 'box', 'layout' => 'horizontal', 'contents' => $priceContents, 'margin' => 'md'],
        ];
        // Only render a stock line when stock is actually known.
        if ($hasStock) {
            $bodyContents[] = $inStock
                ? ['type' => 'text', 'text' => "เหลือ {$product['stock']} ชิ้น", 'size' => 'xs', 'color' => '#888888', 'margin' => 'md']
                : ['type' => 'text', 'text' => 'สินค้าหมด', 'size' => 'xs', 'color' => '#EF4444', 'margin' => 'md'];
        }

        return [
            'type' => 'bubble',
            'hero' => $product['image_url'] ? [
                'type' => 'image', 'url' => $product['image_url'], 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover',
                'action' => ['type' => 'message', 'text' => "product {$product['id']}"]
            ] : null,
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => $bodyContents,
                'paddingAll' => 'lg'
            ],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $buttons, 'paddingAll' => 'lg']
        ];
    }

    /**
     * Product Carousel - แสดงสินค้าหลายชิ้น
     */
    public static function productCarousel($products)
    {
        $bubbles = [];
        foreach (array_slice($products, 0, 10) as $product) {
            $bubble = self::productCard($product);
            if ($bubble) $bubbles[] = $bubble;
        }
        
        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * Cart Summary - สรุปตะกร้า
     */
    public static function cartSummary($items, $total, $itemCount)
    {
        $itemContents = [];
        foreach (array_slice($items, 0, 5) as $item) {
            $itemContents[] = [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $item['name'], 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => "x{$item['quantity']}", 'size' => 'sm', 'flex' => 1, 'align' => 'center', 'color' => '#888888'],
                    ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'flex' => 1, 'align' => 'end']
                ],
                'margin' => 'sm'
            ];
        }
        
        if (count($items) > 5) {
            $itemContents[] = ['type' => 'text', 'text' => '... และอีก ' . (count($items) - 5) . ' รายการ', 'size' => 'xs', 'color' => '#888888', 'margin' => 'md'];
        }

        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'ตะกร้าสินค้า', 'weight' => 'bold', 'size' => 'xl', 'color' => '#0B5F50'],
                    ['type' => 'text', 'text' => "{$itemCount} รายการ", 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $itemContents, 'margin' => 'lg'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => '฿' . number_format($total, 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#0B5F50', 'align' => 'end']
                    ], 'margin' => 'lg']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ชำระเงิน', 'text' => 'checkout'], 'style' => 'primary', 'color' => '#0B5F50'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ช้อปต่อ', 'text' => 'shop'], 'style' => 'secondary', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ]
        ];
    }

    /**
     * Confirm Dialog - ยืนยันการทำรายการ
     */
    public static function confirmDialog($title, $message, $confirmText = 'ยืนยัน', $confirmAction = 'confirm', $cancelText = 'ยกเลิก', $cancelAction = 'cancel')
    {
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => $message, 'size' => 'sm', 'align' => 'center', 'color' => '#666666', 'wrap' => true, 'margin' => 'md']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $cancelText, 'text' => $cancelAction], 'style' => 'secondary', 'flex' => 1],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $confirmText, 'text' => $confirmAction], 'style' => 'primary', 'color' => '#0B5F50', 'flex' => 1]
                ],
                'paddingAll' => 'lg'
            ]
        ];
    }

    /**
     * Success/Error Messages
     */
    public static function success($title, $message, $buttons = [])
    {
        return self::statusMessage('', $title, $message, '#0B5F50', $buttons);
    }

    public static function error($title, $message, $suggestion = '', $buttons = [])
    {
        $msg = $suggestion ? "{$message}\n\n{$suggestion}" : $message;
        return self::statusMessage('', $title, $msg, '#EF4444', $buttons);
    }

    public static function warning($title, $message, $buttons = [])
    {
        return self::statusMessage('', $title, $message, '#F59E0B', $buttons);
    }

    public static function info($title, $message, $buttons = [])
    {
        return self::statusMessage('', $title, $message, '#3B82F6', $buttons);
    }

    /**
     * การ์ดสถานะ (สำเร็จ/เตือน/ผิดพลาด/ข้อมูล) — โทนคลินิกชุดเดียวกับเมนูผู้ป่วย
     *
     * $icon กับ $color ยังรับไว้เพื่อไม่ให้ผู้เรียกเดิมพัง แต่ไม่ได้วาด emoji แล้ว
     * ใช้แค่แยกว่าเป็นเรื่องเตือน (แดง) หรือเรื่องปกติ (เขียวเข้ม)
     */
    private static function statusMessage($icon, $title, $message, $color, $buttons = [])
    {
        $isAlert = in_array(strtoupper((string) $color), ['#EF4444', '#F59E0B', '#DC2626'], true);
        $tone = $isAlert ? self::CLINIC_ALERT : self::CLINIC_MAIN;

        return self::clinicCard(
            $title,
            $isAlert ? 'ต้องตรวจสอบ' : 'แจ้งข้อมูล',
            [['type' => 'text', 'text' => self::stripEmoji($message), 'size' => 'sm', 'color' => self::CLINIC_INK, 'wrap' => true]],
            array_map(static function ($btn) use ($tone) {
                return $btn + ['style' => 'primary', 'color' => $tone];
            }, $buttons),
            'kilo'
        );
    }

    /**
     * Image Carousel - แสดงรูปภาพหลายรูป
     */
    public static function imageCarousel($images, $aspectRatio = '1:1')
    {
        $columns = [];
        foreach (array_slice($images, 0, 10) as $img) {
            $columns[] = [
                'imageUrl' => $img['url'],
                'action' => isset($img['action']) ? $img['action'] : ['type' => 'message', 'text' => $img['text'] ?? 'ดูรูป']
            ];
        }

        return [
            'type' => 'template',
            'altText' => 'รูปภาพ',
            'template' => [
                'type' => 'image_carousel',
                'columns' => $columns
            ]
        ];
    }

    /**
     * Receipt - ใบเสร็จ
     */
    public static function receipt($order, $items, $shopName = 'LINE Shop')
    {
        $itemContents = [];
        foreach ($items as $item) {
            $itemContents[] = [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => "x{$item['quantity']}", 'size' => 'sm', 'flex' => 1, 'align' => 'center'],
                    ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'flex' => 1, 'align' => 'end']
                ],
                'margin' => 'sm'
            ];
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'ใบเสร็จ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'xl'],
                    ['type' => 'text', 'text' => $shopName, 'color' => '#FFFFFF', 'size' => 'sm', 'margin' => 'sm']
                ],
                'backgroundColor' => '#0B5F50', 'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'เลขที่:', 'size' => 'sm', 'color' => '#888888'],
                        ['type' => 'text', 'text' => $order['order_number'], 'size' => 'sm', 'align' => 'end', 'weight' => 'bold']
                    ]],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'วันที่:', 'size' => 'sm', 'color' => '#888888'],
                        ['type' => 'text', 'text' => date('d/m/Y H:i', strtotime($order['created_at'])), 'size' => 'sm', 'align' => 'end']
                    ], 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $itemContents, 'margin' => 'lg'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm'],
                        ['type' => 'text', 'text' => '฿' . number_format($order['shipping_fee']), 'size' => 'sm', 'align' => 'end']
                    ], 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'lg'],
                        ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#0B5F50', 'align' => 'end']
                    ], 'margin' => 'lg']
                ],
                'paddingAll' => 'xl'
            ]
        ];
    }

    /**
     * Group Welcome - ต้อนรับเข้ากลุ่ม
     */
    public static function groupWelcome($groupName, $botName = 'Bot')
    {
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'สวัสดีครับ!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'lg', 'color' => '#0B5F50'],
                    ['type' => 'text', 'text' => "ขอบคุณที่เชิญ {$botName} เข้ากลุ่ม", 'size' => 'sm', 'align' => 'center', 'color' => '#666666', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => 'คำสั่งที่ใช้ได้', 'weight' => 'bold', 'size' => 'md', 'margin' => 'xl'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '• พิมพ์ "shop" - ดูสินค้า', 'size' => 'sm', 'color' => '#555555'],
                        ['type' => 'text', 'text' => '• พิมพ์ "menu" - ดูเมนู', 'size' => 'sm', 'color' => '#555555', 'margin' => 'sm'],
                        ['type' => 'text', 'text' => '• พิมพ์ "help" - ขอความช่วยเหลือ', 'size' => 'sm', 'color' => '#555555', 'margin' => 'sm']
                    ], 'margin' => 'lg', 'paddingAll' => 'md', 'backgroundColor' => '#F8F8F8', 'cornerRadius' => 'lg']
                ],
                'paddingAll' => 'xl'
            ]
        ];
    }

    /**
     * Loading/Processing - กำลังดำเนินการ
     */
    public static function loading($message = 'กำลังดำเนินการ...')
    {
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $message, 'size' => 'md', 'align' => 'center', 'color' => '#666666', 'margin' => 'lg']
                ],
                'paddingAll' => 'xl'
            ]
        ];
    }

    /**
     * Empty State - ไม่มีข้อมูล
     */
    public static function emptyState($title, $message, $actionLabel = null, $actionText = null)
    {
        $contents = [
            ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'margin' => 'lg', 'color' => '#888888'],
            ['type' => 'text', 'text' => $message, 'size' => 'sm', 'align' => 'center', 'color' => '#AAAAAA', 'wrap' => true, 'margin' => 'md']
        ];

        $bubble = [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $contents, 'paddingAll' => 'xl']
        ];

        if ($actionLabel && $actionText) {
            $bubble['footer'] = [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $actionLabel, 'text' => $actionText], 'style' => 'primary', 'color' => '#0B5F50']
                ],
                'paddingAll' => 'lg'
            ];
        }

        return $bubble;
    }

    /**
     * Create Share Button - ปุ่มแชร์ให้เพื่อน
     * ใช้ LINE Share Target Picker (LIFF) หรือ URI Scheme
     * @param string $label - ข้อความบนปุ่ม
     * @param string $shareText - ข้อความที่จะแชร์
     * @param string $style - primary, secondary, link
     * @param string $color - สีปุ่ม
     * @return array - Button component
     */
    public static function shareButton($label = 'แชร์ให้เพื่อน', $shareText = '', $style = 'secondary', $color = '#3B82F6')
    {
        // ใช้ LINE URI Scheme สำหรับแชร์ข้อความ
        // line://msg/text/{message} - แชร์ข้อความ
        // line://share - เปิด share picker
        $encodedText = urlencode($shareText);
        
        return [
            'type' => 'button',
            'action' => [
                'type' => 'uri',
                'label' => $label,
                'uri' => "https://line.me/R/share?text=" . $encodedText
            ],
            'style' => $style,
            'color' => $color,
            'height' => 'sm'
        ];
    }

    /**
     * Create Share Flex Button - ปุ่มแชร์ Flex Message
     * ใช้ LIFF Share Target Picker
     * @param string $liffId - LIFF ID สำหรับ share
     * @param string $label - ข้อความบนปุ่ม
     * @param array $params - parameters ที่จะส่งไป LIFF
     * @return array - Button component
     */
    public static function shareFlexButton($liffId, $label = 'แชร์ให้เพื่อน', $params = [])
    {
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        
        return [
            'type' => 'button',
            'action' => [
                'type' => 'uri',
                'label' => $label,
                'uri' => "https://liff.line.me/{$liffId}{$queryString}"
            ],
            'style' => 'secondary',
            'color' => '#3B82F6',
            'height' => 'sm'
        ];
    }

    /**
     * Add Share Button to existing bubble
     * @param array $bubble - Flex bubble
     * @param string $shareText - ข้อความที่จะแชร์
     * @param string $label - ข้อความบนปุ่ม
     * @return array - Modified bubble with share button
     */
    public static function withShareButton($bubble, $shareText, $label = 'แชร์ให้เพื่อน')
    {
        if (!isset($bubble['footer'])) {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [],
                'paddingAll' => 'lg'
            ];
        }
        
        // Add share button to footer
        $bubble['footer']['contents'][] = self::shareButton($label, $shareText, 'secondary', '#3B82F6');
        
        return $bubble;
    }

    /**
     * Add Quick Reply with Share option
     * @param array $message - LINE message object
     * @param array $items - Quick reply items
     * @param string $shareText - ข้อความที่จะแชร์ (optional)
     * @return array - Message with quick reply
     */
    public static function withQuickReply($message, $items = [], $shareText = null)
    {
        $quickReplyItems = [];
        
        foreach ($items as $item) {
            $quickReplyItems[] = [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => self::stripEmoji($item['label']),
                    'text' => $item['text'] ?? $item['label']
                ]
            ];
        }
        
        // Add share button if shareText provided
        if ($shareText) {
            $encodedText = urlencode($shareText);
            $quickReplyItems[] = [
                'type' => 'action',
                'action' => [
                    'type' => 'uri',
                    'label' => 'แชร์',
                    'uri' => "https://line.me/R/share?text=" . $encodedText
                ]
            ];
        }
        
        if (!empty($quickReplyItems)) {
            $message['quickReply'] = ['items' => $quickReplyItems];
        }
        
        return $message;
    }

    /**
     * Shareable Product Card - การ์ดสินค้าพร้อมปุ่มแชร์
     */
    public static function shareableProductCard($product, $shopUrl = '')
    {
        $price = $product['sale_price'] ?? $product['price'];
        $shareText = "{$product['name']}\nราคา ฿" . number_format($price);
        if ($shopUrl) {
            $shareText .= "\n{$shopUrl}";
        }
        
        $bubble = self::productCard($product);
        
        // Add share button to footer
        if (isset($bubble['footer']['contents'])) {
            $bubble['footer']['contents'][] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => 'แชร์ให้เพื่อน',
                    'uri' => "https://line.me/R/share?text=" . urlencode($shareText)
                ],
                'style' => 'secondary',
                'color' => '#3B82F6',
                'height' => 'sm',
                'margin' => 'sm'
            ];
        }
        
        return $bubble;
    }

    /**
     * Promo Card with Share - โปรโมชั่นพร้อมแชร์
     */
    public static function promoCard($title, $description, $imageUrl = null, $actionText = 'shop', $shareText = '')
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'xl', 'color' => self::CLINIC_MAIN, 'wrap' => true],
                    ['type' => 'text', 'text' => $description, 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'md']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูเลย!', 'text' => $actionText], 'style' => 'primary', 'color' => '#FF6B6B'],
                    self::shareButton('บอกเพื่อน', $shareText ?: "{$title}\n{$description}", 'secondary', '#3B82F6')
                ],
                'paddingAll' => 'lg',
                'spacing' => 'sm'
            ]
        ];
        
        if ($imageUrl) {
            $bubble['hero'] = [
                'type' => 'image',
                'url' => $imageUrl,
                'size' => 'full',
                'aspectRatio' => '20:13',
                'aspectMode' => 'cover'
            ];
        }
        
        return $bubble;
    }

    /**
     * Referral Card - บัตรแนะนำเพื่อน
     */
    public static function referralCard($userName, $referralCode, $reward = '', $shopUrl = '')
    {
        $shareText = "{$userName} ชวนคุณมาช้อป!\n";
        $shareText .= "ใช้โค้ด: {$referralCode}";
        if ($reward) {
            $shareText .= "\nรับ {$reward}";
        }
        if ($shopUrl) {
            $shareText .= "\n{$shopUrl}";
        }
        
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'ชวนเพื่อนมาช้อป!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'lg', 'color' => '#FF6B6B'],
                    ['type' => 'text', 'text' => 'แชร์โค้ดนี้ให้เพื่อน', 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'md'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => 'โค้ดของคุณ', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                        ['type' => 'text', 'text' => $referralCode, 'size' => 'xxl', 'weight' => 'bold', 'color' => '#FF6B6B', 'align' => 'center']
                    ], 'margin' => 'xl', 'paddingAll' => 'lg', 'backgroundColor' => '#FFF5F5', 'cornerRadius' => 'lg'],
                    $reward ? ['type' => 'text', 'text' => "เพื่อนได้รับ {$reward}", 'size' => 'sm', 'align' => 'center', 'color' => '#0B5F50', 'margin' => 'lg'] : ['type' => 'filler']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    self::shareButton('แชร์ให้เพื่อนเลย!', $shareText, 'primary', '#FF6B6B')
                ],
                'paddingAll' => 'lg'
            ]
        ];
    }
    
    /**
     * LIFF Menu - เมนู LIFF สำหรับลูกค้าใหม่
     * เปิด LIFF เพื่อ get profile อัตโนมัติ
     * @param string $shopName ชื่อร้าน
     * @param string $liffShopUrl URL ของ LIFF Shop
     * @param string $liffVideoCallUrl URL ของ LIFF Video Call (optional)
     * @param string $displayName ชื่อลูกค้า (optional)
     */
    public static function liffMenu($shopName = 'LINE Shop', $liffShopUrl = '', $liffVideoCallUrl = '', $displayName = 'คุณลูกค้า')
    {
        $buttons = [];
        if ($liffShopUrl) {
            $buttons[] = ['label' => 'เปิดร้านค้า', 'uri' => $liffShopUrl, 'style' => 'primary'];
        }
        if ($liffVideoCallUrl) {
            $buttons[] = ['label' => 'วิดีโอคอลกับเภสัชกร', 'uri' => $liffVideoCallUrl, 'style' => 'secondary'];
        }
        $buttons[] = ['label' => 'ดูเมนูทั้งหมด', 'text' => 'menu', 'style' => 'secondary'];

        return self::clinicCard(
            $shopName,
            'เลือกบริการที่ต้องการ',
            [
                ['type' => 'text', 'text' => "สวัสดีคุณ {$displayName}", 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_INK, 'wrap' => true],
                ['type' => 'text', 'text' => 'กดปุ่มด้านล่างเพื่อเริ่มใช้งาน', 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'margin' => 'sm', 'wrap' => true],
            ],
            $buttons
        );
    }
    
    /**
     * First Message Menu - เมนูสำหรับข้อความแรก
     * ส่งเมื่อลูกค้าทักมาครั้งแรก
     */
    public static function firstMessageMenu($shopName = 'LINE Shop', $liffShopUrl = '', $displayName = 'คุณลูกค้า')
    {
        $buttons = [];
        if ($liffShopUrl) {
            $buttons[] = ['label' => 'เปิดร้านค้า', 'uri' => $liffShopUrl, 'style' => 'primary'];
        }
        $buttons[] = ['label' => 'ดูเมนูทั้งหมด', 'text' => 'menu', 'style' => 'secondary'];

        return self::clinicCard(
            $shopName,
            'เริ่มใช้งานร้านยา',
            [
                ['type' => 'text', 'text' => "สวัสดีคุณ {$displayName}", 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_INK, 'wrap' => true],
                ['type' => 'text', 'text' => 'พิมพ์ข้อความสอบถามได้ตลอด หรือเลือกจากปุ่มด้านล่าง', 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'margin' => 'sm', 'wrap' => true],
            ],
            $buttons
        );
    }

    /**
     * Points Receipt - ใบเสร็จรับแต้มสะสม (loyalty point claim confirmation)
     *
     * Pushed to the customer's LINE after they scan the give-points QR and claim.
     * Matches the medicine-label colour scheme (darkGreen #006400 header,
     * lightGreen #E8F5E9 footer) so the brand feel is consistent.
     *
     * @param array  $claim {
     *     @type string  voucher_no      e.g. "WI20260602-001"
     *     @type int     points          points credited this transaction
     *     @type float   amount          sale amount in THB (0 if points entered directly)
     *     @type string  payment_method  cash|transfer|card|qr (informational)
     *     @type int     total_points    customer's running balance after credit
     *     @type string  claimed_at      Y-m-d H:i:s (defaults to now)
     * }
     * @param array  $shopInfo  { name, logo, phone }
     * @param string $customerName
     * @return array Flex bubble
     */
    public static function pointsReceipt($claim, $shopInfo = [], $customerName = '')
    {
        $darkGreen = '#006400';
        $lightGreen = '#E8F5E9';
        $white = '#FFFFFF';
        $black = '#000000';
        $gray = '#666666';

        $shopName = !empty($shopInfo['name']) ? (string) $shopInfo['name'] : 'ร้านยา';
        $shopPhone = !empty($shopInfo['phone']) ? (string) $shopInfo['phone'] : '';
        $shopLogo = !empty($shopInfo['logo']) ? (string) $shopInfo['logo'] : '';

        $voucherNo = (string) ($claim['voucher_no'] ?? '');
        $points = (int) ($claim['points'] ?? 0);
        $amount = (float) ($claim['amount'] ?? 0);
        $totalPoints = (int) ($claim['total_points'] ?? 0);
        $paymentMethod = (string) ($claim['payment_method'] ?? '');

        // Thai Buddhist-year date + time (Asia/Bangkok handled by app config).
        $ts = !empty($claim['claimed_at']) ? strtotime((string) $claim['claimed_at']) : time();
        if ($ts === false) {
            $ts = time();
        }
        $thaiDate = date('d/m/', $ts) . ((int) date('Y', $ts) + 543) . ' ' . date('H:i', $ts) . ' น.';

        $paymentLabels = [
            'cash' => 'เงินสด',
            'transfer' => 'โอนเงิน',
            'card' => 'บัตร',
            'qr' => 'QR',
        ];
        $paymentText = $paymentLabels[$paymentMethod] ?? ($paymentMethod !== '' ? $paymentMethod : '-');

        // --- Header: optional logo + shop name, centered (darkGreen) ---
        $headerContents = [];
        if ($shopLogo !== '') {
            $headerContents[] = [
                'type' => 'image',
                'url' => $shopLogo,
                'size' => 'xs',
                'aspectMode' => 'cover',
                'aspectRatio' => '1:1',
                'align' => 'center'
            ];
        }
        $headerContents[] = ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'lg', 'color' => $white, 'align' => 'center', 'wrap' => true, 'margin' => $shopLogo !== '' ? 'sm' : 'none'];
        $headerContents[] = ['type' => 'text', 'text' => 'ใบรับแต้มสะสม', 'size' => 'xs', 'color' => $white, 'align' => 'center', 'margin' => 'xs'];

        // --- Detail rows helper ---
        $detailRow = static function (string $label, string $value, string $valueColor = '#000000') use ($gray): array {
            return [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => $gray, 'flex' => 0],
                    ['type' => 'text', 'text' => $value, 'size' => 'sm', 'color' => $valueColor, 'align' => 'end', 'flex' => 1, 'wrap' => true]
                ],
                'margin' => 'md'
            ];
        };

        $bodyContents = [];

        // Success banner
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'บันทึกการซื้อสำเร็จ', 'size' => 'md', 'weight' => 'bold', 'color' => $darkGreen, 'align' => 'center']
            ],
            'paddingAll' => 'sm'
        ];

        // Voucher + date
        if ($voucherNo !== '') {
            $bodyContents[] = $detailRow('เลขที่', $voucherNo, $black);
        }
        $bodyContents[] = $detailRow('วันที่', $thaiDate, $black);
        $bodyContents[] = ['type' => 'separator', 'color' => '#E5E7EB', 'margin' => 'md'];

        // Amount + payment (amount only when a sale value was recorded)
        if ($amount > 0) {
            $bodyContents[] = $detailRow('ยอดที่ชำระ', '฿' . number_format($amount, 2), $black);
        }
        $bodyContents[] = $detailRow('วิธีชำระ', $paymentText, $black);

        // Points earned — highlighted box
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => 'แต้มที่ได้รับ', 'size' => 'sm', 'color' => $darkGreen, 'weight' => 'bold', 'gravity' => 'center', 'flex' => 1],
                ['type' => 'text', 'text' => '+' . number_format($points) . ' แต้ม', 'size' => 'xl', 'color' => $darkGreen, 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
            ],
            'margin' => 'md',
            'paddingAll' => 'md',
            'backgroundColor' => $lightGreen,
            'cornerRadius' => 'md',
            'borderWidth' => '1px',
            'borderColor' => $darkGreen
        ];

        // Running balance
        $bodyContents[] = $detailRow('แต้มสะสมรวม', number_format($totalPoints) . ' แต้ม', $darkGreen);

        // Thank-you + keep-as-proof
        $bodyContents[] = ['type' => 'separator', 'color' => '#E5E7EB', 'margin' => 'md'];
        $thankName = $customerName !== '' ? $customerName : 'ลูกค้า';
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'ขอบคุณคุณ ' . $thankName . '', 'size' => 'sm', 'color' => $black, 'align' => 'center', 'wrap' => true, 'weight' => 'bold'],
                ['type' => 'text', 'text' => 'เก็บข้อความนี้ไว้เป็นหลักฐาน', 'size' => 'xxs', 'color' => $gray, 'align' => 'center', 'margin' => 'sm']
            ],
            'margin' => 'md'
        ];

        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $headerContents,
                'backgroundColor' => $darkGreen,
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'paddingAll' => 'lg',
                'backgroundColor' => $white
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $shopPhone !== '' ? ('โทร ' . $shopPhone) : 'สะสมแต้มแลกของรางวัลได้เลย', 'size' => 'xs', 'color' => $darkGreen, 'align' => 'center', 'weight' => 'bold']
                ],
                'paddingAll' => 'md',
                'backgroundColor' => $lightGreen
            ]
        ];

        return $bubble;
    }

    /**
     * Medicine Label - ซองยา/ฉลากยา (Redesigned)
     * - สีเขียวเข้ม ไม่ไล่สี
     * - เวลาทานยาเป็นช่องสี่เหลี่ยมพร้อม ✓
     * - แสดงคำแนะนำทั้งหมด ติ๊กเฉพาะที่เลือก
     * - มีรูปสินค้าข้างชื่อยา
     */
    public static function medicineLabel($item, $shopInfo = [], $patientName = '', $checkoutUrl = null)
    {
        // Dark green color scheme
        $darkGreen = '#006400';
        $lightGreen = '#E8F5E9';
        $white = '#FFFFFF';
        $black = '#000000';
        $gray = '#666666';
        
        $shopName = !empty($shopInfo['name']) ? $shopInfo['name'] : 'ร้านยา';
        $shopAddress = !empty($shopInfo['address']) ? $shopInfo['address'] : '';
        $shopPhone = !empty($shopInfo['phone']) ? $shopInfo['phone'] : '';
        $shopLogo = !empty($shopInfo['logo']) ? $shopInfo['logo'] : '';
        $openHours = !empty($shopInfo['open_hours']) ? $shopInfo['open_hours'] : '08:00-24:00 น.';
        $pharmacistName = !empty($shopInfo['pharmacist']) ? $shopInfo['pharmacist'] : '';
        
        $isMedicine = !empty($item['isMedicine']) && $item['isMedicine'] !== false;
        $isExternal = ($item['usageType'] ?? 'internal') === 'external';
        
        // Product image URL
        $productImage = !empty($item['image']) ? $item['image'] : 'https://via.placeholder.com/100x100?text=No+Image';
        
        // Time of day - ช่องสี่เหลี่ยมพร้อม ✓
        $timeOfDay = $item['timeOfDay'] ?? [];
        $timeMap = [
            'morning' => ['label' => 'เช้า', 'checked' => in_array('morning', $timeOfDay)],
            'noon' => ['label' => 'กลางวัน', 'checked' => in_array('noon', $timeOfDay)],
            'evening' => ['label' => 'เย็น', 'checked' => in_array('evening', $timeOfDay)],
            'bedtime' => ['label' => 'ก่อนนอน', 'checked' => in_array('bedtime', $timeOfDay)]
        ];
        
        // Meal timing
        $mealTiming = $item['mealTiming'] ?? 'after';
        $beforeMeal = $mealTiming === 'before';
        $afterMeal = $mealTiming === 'after';
        
        // Build time checkboxes row - ช่องสี่เหลี่ยมพร้อม ✓ (ขนาดเท่ากันทุกช่อง)
        $timeIconsRow = [];
        foreach ($timeMap as $key => $time) {
            $checkMark = $time['checked'] ? '✓' : '-';
            $bgColor = $time['checked'] ? $darkGreen : '#F3F4F6';
            $textColor = $time['checked'] ? $white : '#D1D5DB';
            $labelColor = $time['checked'] ? $darkGreen : '#9CA3AF';
            $borderColor = $time['checked'] ? $darkGreen : '#E5E7EB';
            
            $timeIconsRow[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => $checkMark, 'size' => 'lg', 'align' => 'center', 'color' => $textColor, 'weight' => 'bold']
                        ],
                        'width' => '36px',
                        'height' => '36px',
                        'backgroundColor' => $bgColor,
                        'cornerRadius' => 'md',
                        'justifyContent' => 'center',
                        'alignItems' => 'center',
                        'borderWidth' => '2px',
                        'borderColor' => $borderColor
                    ],
                    ['type' => 'text', 'text' => $time['label'], 'size' => 'xs', 'align' => 'center', 'color' => $labelColor, 'margin' => 'sm', 'weight' => $time['checked'] ? 'bold' : 'regular']
                ],
                'flex' => 1,
                'alignItems' => 'center',
                'spacing' => 'xs'
            ];
        }
        
        // 2026-05-27 — Warnings-only block (timing/water guidance moved to "วิธีใช้").
        // Show only items the pharmacist actually ticked, in a clean ⚠️ section.
        $specialInst = $item['specialInstructions'] ?? [];
        $warningMap = [
            'drowsiness'  => 'ยานี้อาจทำให้ง่วงซึม',
            'no_alcohol'  => 'ห้ามดื่มแอลกอฮอล์',
        ];
        $specialContents = [];
        foreach ($warningMap as $key => $label) {
            if (!in_array($key, $specialInst, true)) continue;
            $specialContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => '#B91C1C', 'weight' => 'bold', 'margin' => 'sm', 'wrap' => true, 'flex' => 1]
                ],
                'margin' => 'sm'
            ];
        }
        
        // Add custom notes
        if (!empty($item['notes'])) {
            $specialContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $item['notes'], 'size' => 'xs', 'color' => $gray, 'margin' => 'sm', 'wrap' => true, 'flex' => 1]
                ],
                'margin' => 'sm'
            ];
        }
        
        // Build body contents
        $bodyContents = [];

        // (1) Pregnancy / allergy warning bar — small white text on red bg, at the TOP of body.
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'ตั้งครรภ์ แพ้ยา มีโรคประจำตัว กรุณาแจ้งเภสัชกร', 'size' => 'xxs', 'color' => $white, 'wrap' => true, 'align' => 'center', 'weight' => 'bold']
            ],
            'backgroundColor' => '#B91C1C',
            'paddingAll' => 'sm',
            'cornerRadius' => 'md'
        ];

        // (2) Rx badge row — ฉลากยา / MEDICINE LABEL
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => '℞ ฉลากยา', 'size' => 'md', 'weight' => 'bold', 'color' => $darkGreen, 'flex' => 1],
                ['type' => 'text', 'text' => 'MEDICINE LABEL', 'size' => 'xs', 'color' => $gray, 'align' => 'end', 'gravity' => 'center', 'flex' => 1]
            ],
            'margin' => 'md'
        ];
        $bodyContents[] = ['type' => 'separator', 'color' => '#E5E7EB', 'margin' => 'sm'];

        // (3) Framed two-row patient + date block (AD year).
        $adDate = date('d/m/Y');
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ผู้ป่วย', 'size' => 'xxs', 'color' => $gray],
                        ['type' => 'text', 'text' => $patientName ?: 'ลูกค้าทั่วไป', 'size' => 'md', 'weight' => 'bold', 'color' => $black, 'wrap' => true, 'margin' => 'xs']
                    ],
                    'flex' => 2
                ],
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => 'วันที่จ่ายยา', 'size' => 'xxs', 'color' => $gray, 'align' => 'end'],
                        ['type' => 'text', 'text' => $adDate, 'size' => 'sm', 'color' => $black, 'align' => 'end', 'margin' => 'xs']
                    ],
                    'flex' => 1
                ]
            ],
            'margin' => 'md',
            'paddingAll' => 'md',
            'backgroundColor' => '#F9FAFB',
            'cornerRadius' => 'md',
            'borderWidth' => '1px',
            'borderColor' => '#E5E7EB'
        ];
        
        // === Medicine card — image left, name + brand right (restored from clean-rows revert) ===
        $__brandLine = [];
        if (!empty($item['generic_name'])) $__brandLine[] = $item['generic_name'];
        if (!empty($item['strength']))     $__brandLine[] = $item['strength'];
        $__brandText = implode(' · ', $__brandLine);

        $__medContents = [
            ['type' => 'text', 'text' => 'ชื่อสินค้า', 'size' => 'xxs', 'color' => $gray],
            ['type' => 'text', 'text' => $item['name'] ?? '-', 'size' => 'md', 'weight' => 'bold', 'wrap' => true, 'color' => $black]
        ];
        if (!empty($__brandText)) {
            $__medContents[] = ['type' => 'text', 'text' => $__brandText, 'size' => 'xxs', 'color' => $gray, 'wrap' => true, 'margin' => 'xs'];
        }
        if (!empty($item['manufacturer'])) {
            $__medContents[] = ['type' => 'text', 'text' => 'ผลิตโดย ' . $item['manufacturer'], 'size' => 'xxs', 'color' => $gray, 'wrap' => true];
        }

        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'image', 'url' => $productImage, 'size' => 'full', 'aspectMode' => 'cover', 'aspectRatio' => '1:1']
                    ],
                    'width' => '64px',
                    'height' => '64px',
                    'cornerRadius' => 'md',
                    'flex' => 0
                ],
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $__medContents,
                    'flex' => 1,
                    'margin' => 'md'
                ]
            ],
            'margin' => 'md',
            'paddingAll' => 'md',
            'backgroundColor' => $lightGreen,
            'cornerRadius' => 'md',
            'borderWidth' => '1px',
            'borderColor' => $darkGreen
        ];

        // ข้อบ่งใช้ — colored green box (restored)
        if (!empty($item['indication'])) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'สรรพคุณ / ข้อบ่งใช้', 'size' => 'xs', 'weight' => 'bold', 'color' => $darkGreen],
                    ['type' => 'text', 'text' => (string) $item['indication'], 'size' => 'sm', 'wrap' => true, 'color' => $black, 'margin' => 'sm']
                ],
                'margin' => 'md',
                'paddingAll' => 'md',
                'backgroundColor' => '#F0F9F4',
                'cornerRadius' => 'md'
            ];
        }

        // วิธีใช้ — colored yellow box, defaults from DB usage_text
        $usageDisplay = '';
        if (!empty($item['usage_text'])) {
            $usageDisplay = (string) $item['usage_text'];
        } elseif ($isMedicine) {
            $usageDisplay = 'รับประทานครั้งละ ' . ($item['dosage'] ?? 1) . ' ' . ($item['dosageUnit'] ?? 'เม็ด');
        }
        if ($usageDisplay !== '') {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'วิธีใช้', 'size' => 'xs', 'weight' => 'bold', 'color' => '#B45309'],
                    ['type' => 'text', 'text' => $usageDisplay, 'size' => 'sm', 'wrap' => true, 'color' => $black, 'margin' => 'sm']
                ],
                'margin' => 'md',
                'paddingAll' => 'md',
                'backgroundColor' => '#FFFBEB',
                'cornerRadius' => 'md',
                'borderWidth' => '1px',
                'borderColor' => '#FCD34D'
            ];
        }

        // Quantity row + price (simple horizontal rows, no clean-row helper)
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => 'จำนวน:', 'size' => 'sm', 'color' => $gray],
                ['type' => 'text', 'text' => ($item['qty'] ?? 1) . ' ' . ($item['unit'] ?? 'ชิ้น'), 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'color' => $black]
            ],
            'margin' => 'lg'
        ];

        $price = ($item['price'] ?? 0) * ($item['qty'] ?? 1);
        if ($price > 0) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => 'ราคา:', 'size' => 'sm', 'color' => $gray],
                    ['type' => 'text', 'text' => '฿' . number_format($price, 2), 'size' => 'lg', 'weight' => 'bold', 'color' => $darkGreen, 'align' => 'end']
                ],
                'margin' => 'sm'
            ];
        }

        // หมายเหตุ — only when present
        if (!empty($item['notes'])) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => 'หมายเหตุ:', 'size' => 'sm', 'color' => $gray, 'flex' => 0],
                    ['type' => 'text', 'text' => (string) $item['notes'], 'size' => 'sm', 'color' => $black, 'flex' => 1, 'margin' => 'sm', 'wrap' => true]
                ],
                'margin' => 'md'
            ];
        }

        // Warnings (red box) — only if pharmacist ticked something
        if ($isMedicine && !empty($specialContents)) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => array_merge(
                    [['type' => 'text', 'text' => 'คำเตือน', 'size' => 'xs', 'weight' => 'bold', 'color' => '#B91C1C']],
                    $specialContents
                ),
                'margin' => 'md',
                'paddingAll' => 'md',
                'backgroundColor' => '#FEF2F2',
                'cornerRadius' => 'md',
                'borderWidth' => '1px',
                'borderColor' => '#FCA5A5'
            ];
        }

        // Build header — vertical centered block: shop name, Pharmacist (English label), phone.
        $headerContents = [
            ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'xl', 'color' => $white, 'align' => 'center', 'wrap' => true]
        ];
        if (!empty($pharmacistName)) {
            $headerContents[] = ['type' => 'text', 'text' => 'Pharmacist: ' . $pharmacistName, 'size' => 'xs', 'color' => $white, 'align' => 'center', 'margin' => 'xs'];
        }
        if (!empty($shopPhone)) {
            $headerContents[] = ['type' => 'text', 'text' => 'โทร ' . $shopPhone, 'size' => 'sm', 'color' => $white, 'align' => 'center', 'margin' => 'sm'];
        }

        $headerBox = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => $headerContents,
            'backgroundColor' => $darkGreen,
            'paddingAll' => 'lg'
        ];

        // Build bubble — dark-green header (with optional inline logo), then body
        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => $headerBox,
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'paddingAll' => 'lg',
                'backgroundColor' => $white
            ]
        ];
        
        // Footer — opening hours (restored), with optional checkout button
        if ($checkoutUrl) {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'uri', 'label' => 'ชำระเงิน', 'uri' => $checkoutUrl], 'style' => 'primary', 'color' => $darkGreen],
                    ['type' => 'text', 'text' => 'เปิดทำการทุกวัน เวลา ' . $openHours, 'size' => 'xxs', 'color' => $gray, 'align' => 'center', 'margin' => 'md']
                ],
                'paddingAll' => 'lg',
                'backgroundColor' => $lightGreen
            ];
        } else {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'เปิดทำการทุกวัน เวลา ' . $openHours, 'size' => 'xs', 'color' => $darkGreen, 'align' => 'center', 'weight' => 'bold']
                ],
                'paddingAll' => 'md',
                'backgroundColor' => $lightGreen
            ];
        }
        
        return $bubble;
    }

    /**
     * Medicine Labels Carousel - หลายซองยา
     */
    public static function medicineLabelsCarousel($items, $shopInfo = [], $patientName = '', $checkoutUrl = null)
    {
        $bubbles = [];
        foreach ($items as $item) {
            $bubbles[] = self::medicineLabel($item, $shopInfo, $patientName, null);
        }
        
        // Add summary bubble with checkout
        if ($checkoutUrl && count($items) > 0) {
            $total = array_reduce($items, fn($sum, $item) => $sum + (($item['price'] ?? 0) * ($item['qty'] ?? 1)), 0);
            
            $itemsList = [];
            foreach ($items as $item) {
                $itemsList[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => $item['name'], 'size' => 'xs', 'flex' => 3, 'wrap' => true],
                        ['type' => 'text', 'text' => 'x' . ($item['qty'] ?? 1), 'size' => 'xs', 'flex' => 1, 'align' => 'center'],
                        ['type' => 'text', 'text' => '฿' . number_format(($item['price'] ?? 0) * ($item['qty'] ?? 1)), 'size' => 'xs', 'flex' => 1, 'align' => 'end']
                    ],
                    'margin' => 'sm'
                ];
            }
            
            $summaryBubble = [
                'type' => 'bubble',
                'size' => 'mega',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => 'สรุปรายการ', 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF', 'align' => 'center']
                    ],
                    'backgroundColor' => '#8B5CF6',
                    'paddingAll' => 'lg'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => array_merge(
                        $itemsList,
                        [
                            ['type' => 'separator', 'margin' => 'lg'],
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'md'],
                                ['type' => 'text', 'text' => '฿' . number_format($total, 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#0B5F50', 'align' => 'end']
                            ], 'margin' => 'lg']
                        ]
                    ),
                    'paddingAll' => 'lg'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => 'ชำระเงินทั้งหมด', 'uri' => $checkoutUrl], 'style' => 'primary', 'color' => '#0B5F50']
                    ],
                    'paddingAll' => 'lg'
                ]
            ];
            
            $bubbles[] = $summaryBubble;
        }
        
        return [
            'type' => 'carousel',
            'contents' => $bubbles
        ];
    }

    // ==================================================================
    // หน้าจอฝั่งสมาชิก — Flex คือ surface หลัก ลูกค้าต้องจบงานได้ในแชท
    //
    // ทุกปุ่มยิง postback ที่ MemberPostbackRouter รับ ใช้รูปแบบ query-string
    // เดียวกับปุ่ม medication_taken / medication_snooze ที่ cron ส่งอยู่แล้ว
    // LINE จำกัด postback.data 300 ตัวอักษร จึงส่งได้แค่ id ห้ามยัด payload
    // ==================================================================

    /** สีประจำโซนสมาชิก — ใช้ร่วมกันทุกใบเพื่อให้รู้ทันทีว่าอยู่เรื่องไหน */
    const MEMBER_COLOR_MED = '#11B0A6';
    const MEMBER_COLOR_POINTS = '#F59E0B';
    const MEMBER_COLOR_APPT = '#6366F1';
    const MEMBER_COLOR_PREFS = '#64748B';

    /** carousel ของ LINE รับได้ 12 ใบ กันไว้ 1 ใบให้การ์ด "ดูทั้งหมด" */
    const MEMBER_CAROUSEL_LIMIT = 10;

    /**
     * หัวการ์ดสมาชิก — แถบสีเดียวกันทั้งโซน
     */
    private static function memberHeader($icon, $title, $subtitle, $color)
    {
        return [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => $color,
            'paddingAll' => '15px',
            'contents' => [[
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => array_values(array_filter([
                    // ไอคอนว่าง = ไม่วาดช่องไอคอนเลย (LINE ไม่รับ text ว่าง)
                    self::stripEmoji($icon) === '' ? null : ['type' => 'text', 'text' => self::stripEmoji($icon), 'size' => 'xxl', 'flex' => 0],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'flex' => 1,
                        'contents' => [
                            // shrink-to-fit ย่อขนาดอักษรให้พอดีบรรทัดเดียว แทนที่จะตัดท้าย
                            // ด้วย "..." อย่างที่ wrap ทำไม่ได้ในหัวการ์ดความสูงคงที่
                            ['type' => 'text', 'text' => $title, 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'adjustMode' => 'shrink-to-fit'],
                            ['type' => 'text', 'text' => $subtitle, 'color' => '#FFFFFF', 'size' => 'xs', 'margin' => 'xs', 'adjustMode' => 'shrink-to-fit'],
                        ],
                    ],
                ])),
            ]],
        ];
    }

    /** แถวข้อมูล label ซ้าย / value ขวา */
    public static function memberRow($label, $value, $valueColor = '#111111')
    {
        return [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                ['type' => 'text', 'text' => ($value === '' || $value === null) ? '-' : $value, 'size' => 'sm', 'weight' => 'bold', 'color' => $valueColor, 'align' => 'end', 'flex' => 3, 'wrap' => true],
            ],
        ];
    }

    /** ปุ่ม postback มาตรฐานของหน้าจอสมาชิก */
    private static function memberButton($label, $data, $style = 'secondary', $color = null)
    {
        $btn = [
            'type' => 'button',
            'height' => 'sm',
            'style' => $style,
            'action' => ['type' => 'postback', 'label' => $label, 'data' => $data, 'displayText' => $label],
        ];
        if ($color !== null) {
            $btn['color'] = $color;
        }
        return $btn;
    }

    /** ใบปิดท้าย carousel เมื่อรายการยาวเกินขีดจำกัดของ LINE */
    private static function memberMoreBubble($message, $uri = null)
    {
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'justifyContent' => 'center',
                'paddingAll' => '20px',
                'contents' => [
                    ['type' => 'text', 'text' => '···', 'size' => 'xxl', 'align' => 'center', 'color' => '#CBD5E1'],
                    ['type' => 'text', 'text' => $message, 'size' => 'sm', 'color' => '#64748B', 'align' => 'center', 'wrap' => true, 'margin' => 'md'],
                ],
            ],
        ];
        if ($uri) {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [[
                    'type' => 'button',
                    'height' => 'sm',
                    'style' => 'primary',
                    'color' => '#111111',
                    'action' => ['type' => 'uri', 'label' => 'เปิดดูทั้งหมด', 'uri' => $uri],
                ]],
            ];
        }
        return $bubble;
    }

    /** แปลงรหัสความถี่เป็นภาษาคน — ชุดเดียวกับ cron/medication_reminder.php */
    public static function frequencyLabel($frequency)
    {
        $map = [
            'once' => 'ครั้งเดียว',
            'daily' => 'ทุกวัน',
            'twice_daily' => 'วันละ 2 ครั้ง',
            'three_times_daily' => 'วันละ 3 ครั้ง',
            'weekly' => 'สัปดาห์ละครั้ง',
            'as_needed' => 'เมื่อจำเป็น',
        ];
        $frequency = (string) $frequency;
        if (isset($map[$frequency])) {
            return $map[$frequency];
        }
        return $frequency === '' ? '-' : $frequency;
    }

    /** วันที่แบบสั้นภาษาไทย เช่น 3 ก.ย. 14:20 */
    public static function thaiShortDate($datetime)
    {
        if (!$datetime) {
            return '';
        }
        $ts = strtotime((string) $datetime);
        if (!$ts) {
            return (string) $datetime;
        }
        $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('H:i', $ts);
    }

    /** วันที่เต็มภาษาไทย พ.ศ. เช่น 3 กันยายน 2569 */
    public static function thaiFullDate($date)
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime((string) $date);
        if (!$ts) {
            return (string) $date;
        }
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
            'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
    }

    /**
     * เมนูรวมฝั่งสมาชิก — ประตูเดียวที่เห็นทั้งระบบได้ในแชท ไม่ต้องเข้า mini app
     *
     * ตัวเลขทุกช่องมาจากฐานจริง ลูกค้าจึงเห็นว่าระบบรู้จักเขาอยู่ก่อนจะกดเข้าไป
     * หน้าไหน ปุ่มวางเป็นแนวตั้งเพื่อให้แต่ละปุ่มได้ความกว้างเต็มใบ ตัวอักษร
     * จึงไม่ถูกตัดท้ายเหมือนตอนวางคู่กันแนวนอน
     *
     * @param array $stats ['meds' => int, 'doses_today' => int,
     *                      'appointments' => int, 'points' => int]
     */
    public static function memberMenu(array $stats = [])
    {
        $meds = (int) ($stats['meds'] ?? 0);
        $doses = (int) ($stats['doses_today'] ?? 0);
        $appts = (int) ($stats['appointments'] ?? 0);
        $points = (int) ($stats['points'] ?? 0);

        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::memberHeader(
                '🩺',
                'ศูนย์สมาชิก',
                'ดูยา นัดหมาย และแต้มได้ในแชทนี้',
                self::MEMBER_COLOR_MED
            ),
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    self::memberRow('ยาที่กำลังทาน', $meds ? $meds . ' รายการ' : 'ยังไม่มี'),
                    self::memberRow(
                        'ทานแล้ววันนี้',
                        $doses ? $doses . ' ครั้ง' : 'ยังไม่ได้บันทึก',
                        $doses ? '#10B981' : '#111111'
                    ),
                    self::memberRow('นัดหมายที่จะถึง', $appts ? $appts . ' นัด' : 'ไม่มีนัด'),
                    self::memberRow(
                        'แต้มสะสม',
                        number_format($points) . ' แต้ม',
                        self::MEMBER_COLOR_POINTS
                    ),
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'paddingAll' => '15px',
                'contents' => [
                    self::memberButton('ยาของฉัน', 'action=member_medications', 'primary', self::MEMBER_COLOR_MED),
                    self::memberButton('นัดหมายของฉัน', 'action=member_appointments', 'primary', self::MEMBER_COLOR_APPT),
                    self::memberButton('ประวัติแต้ม', 'action=member_points_history', 'primary', self::MEMBER_COLOR_POINTS),
                    self::memberButton('ตั้งค่าแจ้งเตือน', 'action=member_notif_prefs'),
                ],
            ],
        ];

        return self::toMessage($bubble, 'ศูนย์สมาชิก');
    }

    /**
     * ยาของฉัน — carousel ยาที่กำลังทาน พร้อมปุ่มสั่งซ้ำ / หยุดเตือน
     *
     * @param array $meds แถวจาก medication_reminders
     *                    (id, medication_name, dosage, frequency, reminder_times)
     */
    public static function medicationList($meds, $moreUri = null)
    {
        if (empty($meds)) {
            return self::emptyState(
                'ยังไม่มียาที่ตั้งเตือน',
                'เมื่อรับยาจากร้าน ระบบจะตั้งเตือนให้อัตโนมัติ'
            );
        }

        $bubbles = [];
        foreach (array_slice($meds, 0, self::MEMBER_CAROUSEL_LIMIT) as $med) {
            $times = json_decode((string) ($med['reminder_times'] ?? '[]'), true);
            $timeText = (is_array($times) && $times) ? implode(' · ', $times) . ' น.' : 'ยังไม่ตั้งเวลา';

            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => self::memberHeader('💊', mb_substr((string) $med['medication_name'], 0, 40), $timeText, self::MEMBER_COLOR_MED),
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '15px',
                    'contents' => [
                        self::memberRow('ขนาดยา', (string) ($med['dosage'] ?? '')),
                        self::memberRow('ความถี่', self::frequencyLabel($med['frequency'] ?? '')),
                        self::memberRow('เวลาเตือน', $timeText),
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '15px',
                    'contents' => [
                        self::memberButton('สั่งซ้ำ', 'action=member_med_refill&id=' . (int) $med['id'], 'primary', self::MEMBER_COLOR_MED),
                        self::memberButton('หยุดเตือน', 'action=member_med_stop&id=' . (int) $med['id']),
                    ],
                ],
            ];
        }

        if (count($meds) > self::MEMBER_CAROUSEL_LIMIT) {
            $bubbles[] = self::memberMoreBubble(
                'ยังมียาอีก ' . (count($meds) - self::MEMBER_CAROUSEL_LIMIT) . ' รายการ',
                $moreUri
            );
        }

        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * ประวัติแต้ม — 10 รายการล่าสุด บวกเขียว ลบแดง
     *
     * @param array $rows แถวจาก points_transactions (created_at, description, points)
     */
    public static function pointsHistory($rows, $balance = 0, $moreUri = null)
    {
        if (empty($rows)) {
            return self::emptyState('ยังไม่มีประวัติแต้ม', 'เมื่อซื้อสินค้าหรือส่งสลิป แต้มจะขึ้นที่นี่');
        }

        $items = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $pts = (int) ($row['points'] ?? 0);
            $items[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'margin' => 'md',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'flex' => 3,
                        'contents' => [
                            ['type' => 'text', 'text' => mb_substr((string) ($row['description'] ?? 'รายการแต้ม'), 0, 40), 'size' => 'sm', 'wrap' => true],
                            ['type' => 'text', 'text' => self::thaiShortDate($row['created_at'] ?? ''), 'size' => 'xxs', 'color' => '#9CA3AF', 'margin' => 'xs'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => ($pts >= 0 ? '+' : '') . number_format($pts),
                        'size' => 'sm',
                        'weight' => 'bold',
                        'align' => 'end',
                        'flex' => 1,
                        'color' => $pts >= 0 ? '#10B981' : '#EF4444',
                    ],
                ],
            ];
        }

        $footer = [self::memberButton('กลับไปบัตรสมาชิก', 'action=member_card')];
        if ($moreUri) {
            $footer[] = [
                'type' => 'button',
                'height' => 'sm',
                'style' => 'link',
                'action' => ['type' => 'uri', 'label' => 'ดูย้อนหลังทั้งหมด', 'uri' => $moreUri],
            ];
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::memberHeader('📋', 'ประวัติแต้ม', 'คงเหลือ ' . number_format((int) $balance) . ' แต้ม', self::MEMBER_COLOR_POINTS),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $items],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '15px', 'contents' => $footer],
        ];
    }

    /**
     * นัดหมายของฉัน — carousel นัดที่ยังไม่ผ่าน พร้อมปุ่มยืนยัน / ขอยกเลิก
     *
     * @param array $appts แถวจาก appointments
     */
    public static function appointmentList($appts)
    {
        if (empty($appts)) {
            return self::emptyState('ยังไม่มีนัดหมาย', 'พิมพ์ "จองนัด" เพื่อนัดปรึกษาเภสัชกรได้เลยค่ะ');
        }

        $typeLabels = [
            'consultation' => 'ปรึกษาเภสัชกร',
            'video_call' => 'วิดีโอคอล',
            'pickup' => 'รับยาที่ร้าน',
            'delivery' => 'จัดส่งถึงบ้าน',
        ];
        $statusLabels = [
            'pending' => ['รอยืนยัน', '#F59E0B'],
            'confirmed' => ['ยืนยันแล้ว', '#10B981'],
            'in_progress' => ['กำลังให้บริการ', '#3B82F6'],
            'completed' => ['เสร็จสิ้น', '#6B7280'],
            'cancelled' => ['ยกเลิกแล้ว', '#EF4444'],
            'no_show' => ['ไม่มาตามนัด', '#EF4444'],
        ];

        $bubbles = [];
        foreach (array_slice($appts, 0, self::MEMBER_CAROUSEL_LIMIT) as $appt) {
            $status = (string) ($appt['status'] ?? 'pending');
            $label = isset($statusLabels[$status]) ? $statusLabels[$status] : ['-', '#6B7280'];

            $footer = [];
            if ($status === 'pending') {
                $footer[] = self::memberButton('ยืนยันนัด', 'action=member_appt_confirm&id=' . (int) $appt['id'], 'primary', '#10B981');
            }
            if ($status === 'pending' || $status === 'confirmed') {
                $footer[] = self::memberButton('ขอยกเลิก', 'action=member_appt_cancel&id=' . (int) $appt['id']);
            }
            if (!$footer) {
                $footer[] = self::memberButton('กลับไปบัตรสมาชิก', 'action=member_card');
            }

            $typeKey = (string) ($appt['appointment_type'] ?? '');
            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => self::memberHeader(
                    '📅',
                    isset($typeLabels[$typeKey]) ? $typeLabels[$typeKey] : 'นัดหมาย',
                    self::thaiFullDate($appt['appointment_date'] ?? ''),
                    self::MEMBER_COLOR_APPT
                ),
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '15px',
                    'contents' => [
                        self::memberRow('เวลา', substr((string) ($appt['appointment_time'] ?? ''), 0, 5) . ' น.'),
                        self::memberRow('สถานะ', $label[0], $label[1]),
                        self::memberRow('หมายเหตุ', mb_substr((string) ($appt['notes'] ?? ''), 0, 60)),
                    ],
                ],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '15px', 'contents' => $footer],
            ];
        }

        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * ตั้งค่าแจ้งเตือน — เปิดปิดรายประเภทจากในแชท ไม่ต้องเข้า mini App
     *
     * @param array $prefs แถวจาก user_notification_preferences
     */
    public static function notificationPrefs($prefs)
    {
        $types = [
            'drug_reminders' => ['💊', 'เตือนทานยา / เติมยา'],
            'appointment_reminders' => ['📅', 'เตือนนัดหมาย'],
            'order_updates' => ['📦', 'สถานะคำสั่งซื้อ'],
            'promotions' => ['🎁', 'โปรโมชันและของรางวัล'],
            'restock_alerts' => ['🔔', 'สินค้าเข้าสต็อก'],
        ];

        $rows = [];
        foreach ($types as $key => $meta) {
            $on = !isset($prefs[$key]) || (int) $prefs[$key] === 1;
            $rows[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'margin' => 'md',
                'alignItems' => 'center',
                'contents' => [
                    ['type' => 'text', 'text' => $meta[1], 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    [
                        'type' => 'button',
                        'flex' => 2,
                        'height' => 'sm',
                        'style' => $on ? 'primary' : 'secondary',
                        'color' => $on ? '#10B981' : '#E2E8F0',
                        'action' => [
                            'type' => 'postback',
                            'label' => $on ? 'เปิดอยู่' : 'ปิดอยู่',
                            'data' => 'action=member_notif_toggle&key=' . $key,
                            'displayText' => ($on ? 'ปิด' : 'เปิด') . 'การแจ้งเตือน: ' . $meta[1],
                        ],
                    ],
                ],
            ];
        }

        $rows[] = ['type' => 'separator', 'margin' => 'lg'];
        $rows[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'paddingAll' => 'md',
            'backgroundColor' => '#FFF7ED',
            'cornerRadius' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => 'ช่วงห้ามรบกวน 21:00–08:00 น.', 'size' => 'xs', 'color' => '#92400E', 'weight' => 'bold'],
                ['type' => 'text', 'text' => 'ข้อความที่ร้านเป็นคนเริ่มจะถูกเลื่อนไปเช้า ยกเว้นเตือนทานยาและนัดหมายที่คุณตั้งเวลาไว้เอง', 'size' => 'xxs', 'color' => '#92400E', 'wrap' => true, 'margin' => 'sm'],
            ],
        ];

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::memberHeader('⚙️', 'ตั้งค่าแจ้งเตือน', 'กดปุ่มเพื่อเปิดหรือปิดได้ทันที', self::MEMBER_COLOR_PREFS),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $rows],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [self::memberButton('กลับไปบัตรสมาชิก', 'action=member_card')],
            ],
        ];
    }

    // ==================================================================
    // Patient self-service — โทนคลินิก ไม่มีอีโมจิตกแต่ง
    //
    // ใช้พาเลตต์ dark-teal ชุดเดียวกับบัตรสมาชิก (BusinessBot::BRAND_*)
    // เจตนา: ไม่สร้างภาษาภาพชุดที่สองในบอทตัวเดียว
    // ==================================================================

    // public เพราะ BusinessBot วาดการ์ด (ออเดอร์/ของรางวัล/ร้านค้า) ด้วยโทนเดียวกัน
    public const CLINIC_MAIN = '#0B5F50';
    public const CLINIC_DEEP = '#082D28';
    public const CLINIC_ON_DARK = '#C7E4DC';
    public const CLINIC_INK = '#0F172A';
    public const CLINIC_MUTED = '#64748B';
    public const CLINIC_HAIRLINE = '#E2E8F0';
    public const CLINIC_ALERT = '#B91C1C';

    /** หัวการ์ดคลินิก — ไล่เฉดเดียวกับบัตรสมาชิก ไม่มีไอคอน */
    private static function clinicHeader($title, $subtitle)
    {
        return [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'backgroundColor' => self::CLINIC_MAIN,
            'background' => [
                'type' => 'linearGradient',
                'angle' => '160deg',
                'startColor' => self::CLINIC_MAIN,
                'endColor' => self::CLINIC_DEEP,
            ],
            'contents' => [
                ['type' => 'text', 'text' => $title, 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                ['type' => 'text', 'text' => $subtitle, 'color' => self::CLINIC_ON_DARK, 'size' => 'xs', 'margin' => 'sm', 'wrap' => true],
            ],
        ];
    }

    /** แถวเมนูที่กดได้ทั้งแถว — หัวข้อหนา + คำอธิบายหนึ่งบรรทัด */
    public static function clinicMenuRow($label, $hint, array $action)
    {
        return [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingTop' => 'md',
            'paddingBottom' => 'md',
            'action' => $action,
            'contents' => [
                ['type' => 'text', 'text' => $label, 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_INK],
                ['type' => 'text', 'text' => $hint, 'size' => 'xxs', 'color' => self::CLINIC_MUTED, 'margin' => 'xs', 'wrap' => true],
            ],
        ];
    }

    /** ปุ่มเปิด URI มาตรฐานของการ์ดคลินิก */
    private static function clinicUriButton($label, $uri, $style = 'secondary', $color = null)
    {
        $btn = [
            'type' => 'button',
            'height' => 'sm',
            'style' => $style,
            'action' => ['type' => 'uri', 'label' => self::stripEmoji($label), 'uri' => $uri],
        ];
        if ($color !== null) {
            $btn['color'] = $color;
        }
        return $btn;
    }

    /**
     * ตัด emoji และสัญลักษณ์ตกแต่งออก — ดีไซน์คลินิกไม่ใช้ emoji
     *
     * เรียกจากตัวสร้างการ์ดกลาง แทนที่จะไล่แก้ป้ายปุ่มทีละจุดใน 30 ไฟล์ที่เรียก
     * ช่วงที่ตัดครอบเฉพาะบล็อกสัญลักษณ์/emoji ไม่แตะอักษรไทย (U+0E00–U+0E7F)
     */
    public static function stripEmoji($text)
    {
        $clean = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2460}-\x{24FF}\x{25A0}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]/u',
            '',
            (string) $text
        );
        return trim(preg_replace('/\s{2,}/u', ' ', $clean));
    }

    /** ปุ่มการ์ดคลินิก รับได้ทั้ง uri และ message ป้ายถูกตัด emoji ให้เสมอ */
    public static function clinicButton(array $btn, $defaultColor = self::CLINIC_MAIN)
    {
        $label = self::stripEmoji($btn['label'] ?? '');
        $action = isset($btn['uri'])
            ? ['type' => 'uri', 'label' => $label, 'uri' => $btn['uri']]
            : ['type' => 'message', 'label' => $label, 'text' => $btn['text'] ?? $btn['label'] ?? $label];

        $style = $btn['style'] ?? 'primary';
        $out = ['type' => 'button', 'height' => 'sm', 'margin' => 'sm', 'style' => $style, 'action' => $action];
        if ($style === 'primary') {
            $out['color'] = $btn['color'] ?? $defaultColor;
        }
        return $out;
    }

    /**
     * การ์ดคลินิกทั่วไป — header เขียวเข้ม + เนื้อหา + ปุ่ม
     * ตัวสร้างกลางที่เมนู/สถานะ/ออเดอร์/ของรางวัล ใช้ร่วมกัน เพื่อไม่ให้ดีไซน์แตกอีก
     */
    public static function clinicCard($title, $subtitle, array $bodyContents, array $buttons = [], $size = 'mega')
    {
        $bubble = [
            'type' => 'bubble',
            'size' => $size,
            'header' => self::clinicHeader(self::stripEmoji($title), self::stripEmoji($subtitle)),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $bodyContents],
        ];

        if (!empty($buttons)) {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => array_map([self::class, 'clinicButton'], $buttons),
            ];
        }

        return $bubble;
    }

    /** แถวเนื้อหาคั่นด้วยเส้นบาง — ใส่ separator ให้อัตโนมัติระหว่างแถว */
    public static function clinicRows(array $rows)
    {
        $out = [];
        foreach ($rows as $i => $row) {
            if ($i > 0) {
                $out[] = ['type' => 'separator', 'color' => self::CLINIC_HAIRLINE];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * เมนูหลักผู้ป่วย — ทางเข้าเดียวของ self-service ทั้งหมด
     *
     * @param array $shopInfo ['shop_name', 'is_open']
     * @param array $liffUrls ['home', 'health'] — deep link เข้า Mini App (ไม่มีก็ซ่อนปุ่ม)
     */
    public static function patientMainMenu(array $shopInfo = [], array $liffUrls = [])
    {
        $shopName = trim((string) ($shopInfo['shop_name'] ?? '')) ?: 'ร้านยา';
        $isOpen = !empty($shopInfo['is_open']);

        $entries = [
            ['เวลาทำการและเภสัชกร', 'สถานะร้านและผู้มีหน้าที่ปฏิบัติการ', ['type' => 'message', 'label' => 'เวลาทำการ', 'text' => 'เวลาทำการ']],
            ['ปรึกษาเภสัชกร', 'ประเมินอาการเบื้องต้นก่อนส่งต่อเภสัชกร', ['type' => 'message', 'label' => 'ปรึกษาเภสัช', 'text' => 'ปรึกษาเภสัช']],
            ['ขอรับยาเดิม', 'สั่งซ้ำจากรายการยาที่เคยได้รับ', ['type' => 'message', 'label' => 'ขอรับยาเดิม', 'text' => 'ขอรับยาเดิม']],
            ['แต้มสะสมและสิทธิ์สมาชิก', 'ยอดแต้มคงเหลือและระดับสมาชิก', ['type' => 'message', 'label' => 'แต้ม', 'text' => 'แต้ม']],
            ['สถานะคำสั่งซื้อ', 'ติดตามรายการที่สั่งไว้', ['type' => 'message', 'label' => 'สถานะออเดอร์', 'text' => 'สถานะออเดอร์']],
            ['แจ้งชำระเงิน', 'ส่งสลิปโอนเงินให้เจ้าหน้าที่ตรวจสอบ', ['type' => 'message', 'label' => 'ส่งสลิป', 'text' => 'ส่งสลิป']],
        ];

        if (!empty($liffUrls['health'])) {
            $entries[] = ['ประวัติยาและสุขภาพ', 'เปิดดูรายละเอียดในแอปผู้ป่วย', ['type' => 'uri', 'label' => 'ประวัติยา', 'uri' => $liffUrls['health']]];
        }

        $rows = [];
        foreach ($entries as $i => $entry) {
            if ($i > 0) {
                $rows[] = ['type' => 'separator', 'color' => self::CLINIC_HAIRLINE];
            }
            $rows[] = self::clinicMenuRow($entry[0], $entry[1], $entry[2]);
        }

        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::clinicHeader($shopName, $isOpen ? 'เปิดให้บริการ' : 'ปิดให้บริการชั่วคราว'),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $rows],
        ];

        if (!empty($liffUrls['home'])) {
            $bubble['footer'] = [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [self::clinicUriButton('เปิดแอปผู้ป่วย', $liffUrls['home'], 'primary', self::CLINIC_MAIN)],
            ];
        }

        return $bubble;
    }

    /**
     * เวลาทำการและเภสัชกรผู้ปฏิบัติการ
     *
     * shop_settings เก็บสถานะเป็น is_open (เปิด/ปิด) ไม่ได้เก็บช่วงเวลา
     * การ์ดจึงรายงานสถานะขณะนี้ ไม่ใช่ตารางเวลา
     *
     * @param bool   $isOpen
     * @param string $pharmacistName
     * @param array  $contact ['phone', 'address', 'pharmacist_license', 'pharmacy_license']
     */
    public static function pharmacyHoursCard($isOpen, $pharmacistName = '', array $contact = [])
    {
        $isOpen = (bool) $isOpen;

        $rows = [
            self::memberRow('สถานะขณะนี้', $isOpen ? 'เปิดให้บริการ' : 'ปิดให้บริการชั่วคราว', $isOpen ? self::CLINIC_MAIN : self::CLINIC_ALERT),
            self::memberRow('เภสัชกรผู้ปฏิบัติการ', trim((string) $pharmacistName)),
        ];

        $optional = [
            'pharmacist_license' => 'เลขที่ใบประกอบวิชาชีพ',
            'pharmacy_license' => 'เลขที่ใบอนุญาตร้านยา',
            'phone' => 'โทรศัพท์',
            'address' => 'ที่อยู่',
        ];
        foreach ($optional as $key => $label) {
            $value = trim((string) ($contact[$key] ?? ''));
            if ($value !== '') {
                $rows[] = self::memberRow($label, $value);
            }
        }

        $footer = [];
        $phone = preg_replace('/[^0-9+]/', '', (string) ($contact['phone'] ?? ''));
        if ($phone !== '') {
            $footer[] = self::clinicUriButton('โทรหาร้าน', 'tel:' . $phone, 'primary', self::CLINIC_MAIN);
        }
        $footer[] = [
            'type' => 'button',
            'height' => 'sm',
            'style' => 'secondary',
            'action' => ['type' => 'message', 'label' => 'ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัช'],
        ];

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::clinicHeader(
                'เวลาทำการเภสัชกร',
                $isOpen ? 'พร้อมให้คำปรึกษาขณะนี้' : 'นอกเวลาให้บริการ ข้อความจะถูกบันทึกไว้'
            ),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $rows],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '15px', 'contents' => $footer],
        ];
    }

    /**
     * ขอรับยาเดิม — การ์ดนำเข้าสู่รายการยาที่มีปุ่มสั่งซ้ำอยู่แล้ว
     *
     * ปุ่มหลักยิง postback action=member_medications ซึ่ง MemberPostbackRouter
     * ตอบด้วย medicationList() (มีปุ่ม member_med_refill รายตัวอยู่แล้ว)
     * จึงไม่สร้างเส้นทางสั่งซ้ำเส้นที่สอง
     *
     * @param array       $recentMedications แถวจาก medication_reminders
     * @param string|null $liffUrl           deep link ไปหน้า /health ใน Mini App
     */
    public static function refillRequestCard($recentMedications = [], $liffUrl = null)
    {
        $recentMedications = is_array($recentMedications) ? $recentMedications : [];
        $body = [];

        if (empty($recentMedications)) {
            $body[] = ['type' => 'text', 'text' => 'ยังไม่มีรายการยาที่บันทึกไว้', 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_INK];
            $body[] = ['type' => 'text', 'text' => 'เมื่อรับยาจากร้าน ระบบจะบันทึกให้อัตโนมัติ แล้วสั่งซ้ำได้จากที่นี่', 'size' => 'xs', 'color' => self::CLINIC_MUTED, 'margin' => 'sm', 'wrap' => true];
        } else {
            $body[] = ['type' => 'text', 'text' => 'ยาที่ได้รับล่าสุด', 'size' => 'xs', 'color' => self::CLINIC_MUTED];
            foreach (array_slice($recentMedications, 0, 3) as $med) {
                $name = trim((string) ($med['medication_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $body[] = self::memberRow(mb_substr($name, 0, 40), trim((string) ($med['dosage'] ?? '')));
            }
            $remaining = count($recentMedications) - 3;
            if ($remaining > 0) {
                $body[] = ['type' => 'text', 'text' => 'และอีก ' . $remaining . ' รายการ', 'size' => 'xxs', 'color' => self::CLINIC_MUTED, 'margin' => 'md'];
            }
        }

        $footer = [self::memberButton('ดูรายการยาและสั่งซ้ำ', 'action=member_medications', 'primary', self::CLINIC_MAIN)];
        if (!empty($liffUrl)) {
            $footer[] = self::clinicUriButton('เปิดประวัติยาในแอป', $liffUrl);
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::clinicHeader('ขอรับยาเดิม', 'สั่งซ้ำจากรายการยาที่เคยได้รับ'),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $body],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '15px', 'contents' => $footer],
        ];
    }

    /**
     * เริ่มประเมินอาการ — อธิบายขั้นตอนและขอบเขตก่อนเข้าห้องปรึกษา
     *
     * @param string|null $liffUrl deep link ไปหน้า /ai-chat ใน Mini App
     */
    public static function clinicalTriageCard($liffUrl = null)
    {
        $steps = [
            ['1', 'ระบุอาการและระยะเวลาที่เป็น'],
            ['2', 'ระบบคัดกรองสัญญาณอันตรายเบื้องต้น'],
            ['3', 'เภสัชกรรับช่วงต่อเมื่อจำเป็น'],
        ];

        $body = [
            ['type' => 'text', 'text' => 'การประเมินนี้ไม่ใช่การวินิจฉัยทางการแพทย์ หากมีอาการรุนแรงหรือฉุกเฉิน โทร 1669 ทันที', 'size' => 'xs', 'color' => self::CLINIC_ALERT, 'wrap' => true],
            ['type' => 'separator', 'margin' => 'lg', 'color' => self::CLINIC_HAIRLINE],
        ];
        foreach ($steps as $step) {
            $body[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'margin' => 'md',
                'spacing' => 'md',
                'contents' => [
                    ['type' => 'text', 'text' => $step[0], 'size' => 'sm', 'weight' => 'bold', 'color' => self::CLINIC_MAIN, 'flex' => 0],
                    ['type' => 'text', 'text' => $step[1], 'size' => 'sm', 'color' => self::CLINIC_INK, 'wrap' => true, 'flex' => 1],
                ],
            ];
        }

        $footer = [];
        if (!empty($liffUrl)) {
            $footer[] = self::clinicUriButton('เริ่มประเมินอาการ', $liffUrl, 'primary', self::CLINIC_MAIN);
        }
        // 'ปรึกษาเภสัชกร' เป็นคำสั่งหยุดบอทที่มีอยู่เดิม — ปุ่มนี้คือทางออกสู่คนจริง
        $footer[] = [
            'type' => 'button',
            'height' => 'sm',
            'style' => 'secondary',
            'action' => ['type' => 'message', 'label' => 'ขอคุยกับเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
        ];

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => self::clinicHeader('ปรึกษาเภสัชกร', 'ประเมินอาการเบื้องต้นก่อนส่งต่อ'),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '15px', 'contents' => $body],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '15px', 'contents' => $footer],
        ];
    }
}
