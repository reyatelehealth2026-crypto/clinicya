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
        'shop' => ['name' => '🛒 Shop', 'iconUrl' => 'https://i.imgur.com/BOBkgJA.png'],
        'support' => ['name' => '💬 Support', 'iconUrl' => 'https://i.imgur.com/YkPqZKx.png'],
        'notify' => ['name' => '🔔 Notify', 'iconUrl' => 'https://i.imgur.com/8LQHV0Z.png'],
        'order' => ['name' => '📦 Order', 'iconUrl' => 'https://i.imgur.com/wPVlSoK.png'],
        'payment' => ['name' => '💳 Payment', 'iconUrl' => 'https://i.imgur.com/3P1Z3hB.png'],
    ];

    // Default quick reply sets
    private static $quickReplySets = [
        'main' => [
            ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
            ['label' => '📋 เมนู', 'text' => 'menu'],
            ['label' => '🛍️ ตะกร้า', 'text' => 'cart'],
            ['label' => '📦 ออเดอร์', 'text' => 'orders'],
        ],
        'shop' => [
            ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
            ['label' => '🛍️ ตะกร้า', 'text' => 'cart'],
            ['label' => '💳 ชำระเงิน', 'text' => 'checkout'],
        ],
        'order' => [
            ['label' => '📦 เช็คสถานะ', 'text' => 'orders'],
            ['label' => '💳 ส่งสลิป', 'text' => 'สลิป'],
            ['label' => '🛒 ช้อปต่อ', 'text' => 'shop'],
        ],
        'support' => [
            ['label' => '📋 เมนู', 'text' => 'menu'],
            ['label' => '❓ FAQ', 'text' => 'faq'],
            ['label' => '📞 โทรหาเรา', 'text' => 'contact'],
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

    /**
     * Build Quick Reply object
     */
    public static function buildQuickReply($items = [], $preset = null)
    {
        if ($preset && isset(self::$quickReplySets[$preset])) {
            $items = self::$quickReplySets[$preset];
        }
        
        if (empty($items)) return null;

        $quickReplyItems = [];
        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'camera') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'camera', 'label' => $item['label'] ?? '📷 ถ่ายรูป']
                ];
            } elseif (isset($item['type']) && $item['type'] === 'cameraRoll') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'cameraRoll', 'label' => $item['label'] ?? '🖼️ เลือกรูป']
                ];
            } elseif (isset($item['type']) && $item['type'] === 'location') {
                $quickReplyItems[] = [
                    'type' => 'action',
                    'action' => ['type' => 'location', 'label' => $item['label'] ?? '📍 ส่งตำแหน่ง']
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
        $message = [
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $contents
        ];

        // Add sender
        if ($sender) {
            if (is_string($sender)) {
                $message['sender'] = self::getSender($sender);
            } elseif (is_array($sender)) {
                $message['sender'] = $sender;
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
            ['icon' => '🛒', 'text' => 'สั่งซื้อสินค้าง่ายๆ'],
            ['icon' => '💬', 'text' => 'แชทกับเราได้ตลอด 24 ชม.'],
            ['icon' => '🎁', 'text' => 'โปรโมชั่นพิเศษสำหรับสมาชิก'],
            ['icon' => '🚚', 'text' => 'จัดส่งรวดเร็วทันใจ']
        ];
        $features = $features ?: $defaultFeatures;

        $featureContents = [];
        foreach ($features as $f) {
            $featureContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $f['icon'], 'size' => 'lg', 'flex' => 0],
                    ['type' => 'text', 'text' => $f['text'], 'size' => 'sm', 'color' => '#555555', 'margin' => 'md', 'flex' => 1, 'wrap' => true]
                ],
                'margin' => 'md'
            ];
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '🎉', 'size' => '3xl', 'align' => 'center']
                    ], 'paddingTop' => 'lg']
                ],
                'backgroundColor' => '#06C755',
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'ยินดีต้อนรับ!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "สวัสดีคุณ {$displayName}", 'size' => 'lg', 'align' => 'center', 'margin' => 'md', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => "ขอบคุณที่เพิ่มเพื่อน {$shopName}", 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => '✨ สิ่งที่คุณจะได้รับ', 'weight' => 'bold', 'size' => 'md', 'margin' => 'xl', 'color' => '#333333'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $featureContents, 'margin' => 'lg', 'paddingAll' => 'md', 'backgroundColor' => '#F8F8F8', 'cornerRadius' => 'lg']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เริ่มช้อปเลย!', 'text' => 'shop'], 'style' => 'primary', 'color' => '#06C755', 'height' => 'md'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูเมนูทั้งหมด', 'text' => 'menu'], 'style' => 'secondary', 'height' => 'sm', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ],
            'styles' => ['footer' => ['separator' => true]]
        ];
    }

    /**
     * Main Menu - เมนูหลักสวยๆ (อัพเกรด V2)
     */
    public static function mainMenu($shopName = 'LINE Shop', $menuItems = null)
    {
        $defaultItems = [
            ['icon' => '🛒', 'label' => 'ดูสินค้า', 'desc' => 'เลือกซื้อสินค้าคุณภาพ', 'text' => 'shop', 'color' => '#06C755'],
            ['icon' => '🛍️', 'label' => 'ตะกร้า', 'desc' => 'ดูสินค้าในตะกร้า', 'text' => 'cart', 'color' => '#3B82F6'],
            ['icon' => '📦', 'label' => 'ออเดอร์', 'desc' => 'เช็คสถานะคำสั่งซื้อ', 'text' => 'orders', 'color' => '#8B5CF6'],
            ['icon' => '💳', 'label' => 'ส่งสลิป', 'desc' => 'แจ้งชำระเงิน', 'text' => 'สลิป', 'color' => '#F59E0B'],
            ['icon' => '⭐', 'label' => 'แต้มสะสม', 'desc' => 'ดูแต้มและสิทธิพิเศษ', 'text' => 'points', 'color' => '#EC4899'],
            ['icon' => '📞', 'label' => 'ติดต่อเรา', 'desc' => 'สอบถามข้อมูลเพิ่มเติม', 'text' => 'contact', 'color' => '#EF4444'],
        ];
        $menuItems = $menuItems ?: $defaultItems;

        // สร้างเมนูแบบ Grid 2 คอลัมน์
        $rows = [];
        $chunks = array_chunk($menuItems, 2);
        
        foreach ($chunks as $pair) {
            $rowContents = [];
            foreach ($pair as $item) {
                $rowContents[] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                            ['type' => 'text', 'text' => $item['icon'], 'size' => 'xxl', 'align' => 'center']
                        ], 'paddingAll' => 'lg', 'backgroundColor' => ($item['color'] ?? '#06C755') . '15', 'cornerRadius' => 'xl'],
                        ['type' => 'text', 'text' => $item['label'], 'size' => 'md', 'weight' => 'bold', 'align' => 'center', 'margin' => 'md'],
                        ['type' => 'text', 'text' => $item['desc'] ?? '', 'size' => 'xxs', 'color' => '#888888', 'align' => 'center', 'wrap' => true]
                    ],
                    'flex' => 1,
                    'paddingAll' => 'md',
                    'backgroundColor' => '#FFFFFF',
                    'cornerRadius' => 'xl',
                    'borderWidth' => '1px',
                    'borderColor' => '#E5E5E5',
                    'action' => ['type' => 'message', 'text' => $item['text']]
                ];
            }
            // ถ้ามีแค่ 1 item ในแถว ให้เพิ่ม filler
            if (count($rowContents) === 1) {
                $rowContents[] = ['type' => 'filler'];
            }
            $rows[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => $rowContents,
                'spacing' => 'md'
            ];
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => '📋', 'size' => 'xl'],
                        ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'lg', 'margin' => 'sm', 'color' => '#FFFFFF']
                    ]],
                    ['type' => 'text', 'text' => 'เลือกเมนูที่ต้องการได้เลยค่ะ', 'size' => 'sm', 'color' => '#FFFFFF', 'margin' => 'sm', 'style' => 'italic']
                ],
                'backgroundColor' => '#06C755',
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $rows,
                'spacing' => 'md',
                'paddingAll' => 'lg',
                'backgroundColor' => '#F5F5F5'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => '💬 พิมพ์ข้อความเพื่อสอบถามได้เลย', 'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'flex' => 1]
                    ]]
                ],
                'paddingAll' => 'md',
                'backgroundColor' => '#FAFAFA'
            ]
        ];
    }
    
    /**
     * Quick Menu - เมนูด่วนแบบ Carousel
     */
    public static function quickMenu($shopName = 'LINE Shop')
    {
        $menus = [
            [
                'title' => '🛒 ช้อปปิ้ง',
                'color' => '#06C755',
                'items' => [
                    ['icon' => '🏪', 'label' => 'ดูสินค้าทั้งหมด', 'text' => 'shop'],
                    ['icon' => '🔥', 'label' => 'สินค้าขายดี', 'text' => 'bestseller'],
                    ['icon' => '🆕', 'label' => 'สินค้าใหม่', 'text' => 'new'],
                    ['icon' => '💰', 'label' => 'โปรโมชั่น', 'text' => 'promotion'],
                ]
            ],
            [
                'title' => '📦 คำสั่งซื้อ',
                'color' => '#3B82F6',
                'items' => [
                    ['icon' => '🛍️', 'label' => 'ตะกร้าสินค้า', 'text' => 'cart'],
                    ['icon' => '📋', 'label' => 'ออเดอร์ของฉัน', 'text' => 'orders'],
                    ['icon' => '💳', 'label' => 'ส่งสลิปชำระเงิน', 'text' => 'สลิป'],
                    ['icon' => '🚚', 'label' => 'ติดตามพัสดุ', 'text' => 'tracking'],
                ]
            ],
            [
                'title' => '⭐ สมาชิก',
                'color' => '#EC4899',
                'items' => [
                    ['icon' => '🎁', 'label' => 'แต้มสะสม', 'text' => 'points'],
                    ['icon' => '🏆', 'label' => 'ระดับสมาชิก', 'text' => 'membership'],
                    ['icon' => '🎫', 'label' => 'คูปองของฉัน', 'text' => 'coupons'],
                    ['icon' => '📞', 'label' => 'ติดต่อเรา', 'text' => 'contact'],
                ]
            ]
        ];
        
        $bubbles = [];
        foreach ($menus as $menu) {
            $itemContents = [];
            foreach ($menu['items'] as $item) {
                $itemContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => $item['icon'], 'size' => 'lg', 'flex' => 0],
                        ['type' => 'text', 'text' => $item['label'], 'size' => 'sm', 'margin' => 'md', 'flex' => 1, 'gravity' => 'center']
                    ],
                    'paddingAll' => 'md',
                    'backgroundColor' => '#F8F8F8',
                    'cornerRadius' => 'lg',
                    'action' => ['type' => 'message', 'text' => $item['text']]
                ];
            }
            
            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $menu['title'], 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF']
                    ],
                    'backgroundColor' => $menu['color'],
                    'paddingAll' => 'lg'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $itemContents,
                    'spacing' => 'sm',
                    'paddingAll' => 'md'
                ]
            ];
        }
        
        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * Order Status Update - อัพเดทสถานะออเดอร์
     */
    public static function orderStatus($orderNumber, $status, $trackingNumber = null, $message = '')
    {
        $statusConfig = [
            'pending' => ['icon' => '⏳', 'text' => 'รอดำเนินการ', 'color' => '#F59E0B', 'msg' => 'ออเดอร์ของคุณอยู่ระหว่างรอดำเนินการ'],
            'confirmed' => ['icon' => '✅', 'text' => 'ยืนยันแล้ว', 'color' => '#06C755', 'msg' => 'ออเดอร์ของคุณได้รับการยืนยันแล้ว'],
            'paid' => ['icon' => '💰', 'text' => 'ชำระเงินแล้ว', 'color' => '#06C755', 'msg' => 'ได้รับการชำระเงินเรียบร้อย'],
            'shipping' => ['icon' => '🚚', 'text' => 'กำลังจัดส่ง', 'color' => '#3B82F6', 'msg' => 'สินค้ากำลังจัดส่งถึงคุณ'],
            'delivered' => ['icon' => '📦', 'text' => 'จัดส่งแล้ว', 'color' => '#10B981', 'msg' => 'สินค้าถึงมือคุณแล้ว'],
            'cancelled' => ['icon' => '❌', 'text' => 'ยกเลิก', 'color' => '#EF4444', 'msg' => 'ออเดอร์ถูกยกเลิก']
        ];

        $config = $statusConfig[$status] ?? ['icon' => '📋', 'text' => $status, 'color' => '#888888', 'msg' => ''];
        $statusMessage = $message ?: $config['msg'];

        $bodyContents = [
            ['type' => 'text', 'text' => $config['icon'], 'size' => '4xl', 'align' => 'center'],
            ['type' => 'text', 'text' => $config['text'], 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'lg', 'color' => $config['color']],
            ['type' => 'text', 'text' => "ออเดอร์ #{$orderNumber}", 'size' => 'md', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm'],
            ['type' => 'separator', 'margin' => 'xl'],
            ['type' => 'text', 'text' => $statusMessage, 'size' => 'sm', 'align' => 'center', 'wrap' => true, 'margin' => 'xl', 'color' => '#555555']
        ];

        if ($trackingNumber) {
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '📮 เลขพัสดุ', 'size' => 'xs', 'color' => '#888888'],
                    ['type' => 'text', 'text' => $trackingNumber, 'size' => 'lg', 'weight' => 'bold', 'color' => '#3B82F6']
                ],
                'margin' => 'xl', 'paddingAll' => 'lg', 'backgroundColor' => '#EFF6FF', 'cornerRadius' => 'lg'
            ];
        }

        return [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $bodyContents, 'paddingAll' => 'xl'],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูออเดอร์ทั้งหมด', 'text' => 'orders'], 'style' => 'secondary', 'height' => 'sm']
                ],
                'paddingAll' => 'lg'
            ]
        ];
    }

    /**
     * Slip Received - ได้รับสลิปแล้ว
     */
    public static function slipReceived($orderNumber, $amount)
    {
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '✅', 'size' => '4xl', 'align' => 'center']
                    ], 'paddingAll' => 'lg'],
                    ['type' => 'text', 'text' => 'ได้รับสลิปแล้ว!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderNumber}", 'size' => 'md', 'align' => 'center', 'color' => '#888888', 'margin' => 'md'],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '💰 ยอดชำระ', 'size' => 'sm', 'color' => '#888888', 'align' => 'center'],
                        ['type' => 'text', 'text' => '฿' . number_format($amount, 2), 'size' => 'xxl', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'center']
                    ], 'margin' => 'xl', 'paddingAll' => 'lg', 'backgroundColor' => '#F0FDF4', 'cornerRadius' => 'lg'],
                    ['type' => 'text', 'text' => '⏳ กรุณารอตรวจสอบ', 'size' => 'sm', 'align' => 'center', 'color' => '#F59E0B', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => 'จะแจ้งผลให้ทราบเร็วๆ นี้', 'size' => 'xs', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm']
                ],
                'paddingAll' => 'xl'
            ]
        ];
    }

    /**
     * Notification Card - การแจ้งเตือนทั่วไป
     */
    public static function notification($title, $message, $icon = '🔔', $color = '#06C755', $buttons = [])
    {
        $footerContents = [];
        foreach ($buttons as $btn) {
            $action = isset($btn['uri']) 
                ? ['type' => 'uri', 'label' => $btn['label'], 'uri' => $btn['uri']]
                : ['type' => 'message', 'label' => $btn['label'], 'text' => $btn['text'] ?? $btn['label']];
            
            $footerContents[] = [
                'type' => 'button', 'action' => $action,
                'style' => $btn['style'] ?? 'primary',
                'color' => $btn['color'] ?? $color,
                'height' => 'sm', 'margin' => 'sm'
            ];
        }

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                            ['type' => 'text', 'text' => $icon, 'size' => 'xxl', 'align' => 'center']
                        ], 'width' => '60px', 'height' => '60px', 'backgroundColor' => $color . '20', 'cornerRadius' => 'xxl', 'justifyContent' => 'center'],
                        ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                            ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                            ['type' => 'text', 'text' => $message, 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm']
                        ], 'margin' => 'lg', 'flex' => 1]
                    ]]
                ],
                'paddingAll' => 'xl'
            ]
        ];

        if (!empty($footerContents)) {
            $bubble['footer'] = ['type' => 'box', 'layout' => 'vertical', 'contents' => $footerContents, 'paddingAll' => 'lg'];
        }

        return $bubble;
    }

    /**
     * Product Card - การ์ดสินค้า
     */
    public static function productCard($product, $showAddToCart = true)
    {
        $price = $product['sale_price'] ?? $product['price'];
        $originalPrice = $product['sale_price'] ? $product['price'] : null;
        
        $priceContents = [
            ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xl', 'weight' => 'bold', 'color' => '#06C755']
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
            $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "add {$product['id']}"], 'style' => 'primary', 'color' => '#06C755'];
        }
        $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 รายละเอียด', 'text' => "product {$product['id']}"], 'style' => 'secondary', 'margin' => 'sm'];

        $bodyContents = [
            ['type' => 'text', 'text' => $product['name'], 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
            ['type' => 'box', 'layout' => 'horizontal', 'contents' => $priceContents, 'margin' => 'md'],
        ];
        // Only render a stock line when stock is actually known.
        if ($hasStock) {
            $bodyContents[] = $inStock
                ? ['type' => 'text', 'text' => "📦 เหลือ {$product['stock']} ชิ้น", 'size' => 'xs', 'color' => '#888888', 'margin' => 'md']
                : ['type' => 'text', 'text' => '❌ สินค้าหมด', 'size' => 'xs', 'color' => '#EF4444', 'margin' => 'md'];
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
                    ['type' => 'text', 'text' => '🛍️ ตะกร้าสินค้า', 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "{$itemCount} รายการ", 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $itemContents, 'margin' => 'lg'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => '฿' . number_format($total, 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end']
                    ], 'margin' => 'lg']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '💳 ชำระเงิน', 'text' => 'checkout'], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ช้อปต่อ', 'text' => 'shop'], 'style' => 'secondary', 'margin' => 'sm']
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
                    ['type' => 'text', 'text' => '❓', 'size' => '3xl', 'align' => 'center'],
                    ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => $message, 'size' => 'sm', 'align' => 'center', 'color' => '#666666', 'wrap' => true, 'margin' => 'md']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $cancelText, 'text' => $cancelAction], 'style' => 'secondary', 'flex' => 1],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $confirmText, 'text' => $confirmAction], 'style' => 'primary', 'color' => '#06C755', 'flex' => 1]
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
        return self::statusMessage('✅', $title, $message, '#06C755', $buttons);
    }

    public static function error($title, $message, $suggestion = '', $buttons = [])
    {
        $msg = $suggestion ? "{$message}\n\n💡 {$suggestion}" : $message;
        return self::statusMessage('❌', $title, $msg, '#EF4444', $buttons);
    }

    public static function warning($title, $message, $buttons = [])
    {
        return self::statusMessage('⚠️', $title, $message, '#F59E0B', $buttons);
    }

    public static function info($title, $message, $buttons = [])
    {
        return self::statusMessage('ℹ️', $title, $message, '#3B82F6', $buttons);
    }

    private static function statusMessage($icon, $title, $message, $color, $buttons = [])
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $icon, 'size' => '3xl', 'align' => 'center'],
                    ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'margin' => 'lg', 'color' => $color],
                    ['type' => 'text', 'text' => $message, 'size' => 'sm', 'align' => 'center', 'color' => '#666666', 'wrap' => true, 'margin' => 'md']
                ],
                'paddingAll' => 'xl'
            ]
        ];

        if (!empty($buttons)) {
            $footerContents = [];
            foreach ($buttons as $btn) {
                $footerContents[] = [
                    'type' => 'button',
                    'action' => ['type' => 'message', 'label' => $btn['label'], 'text' => $btn['text'] ?? $btn['label']],
                    'style' => $btn['style'] ?? 'secondary',
                    'height' => 'sm', 'margin' => 'sm'
                ];
            }
            $bubble['footer'] = ['type' => 'box', 'layout' => 'vertical', 'contents' => $footerContents, 'paddingAll' => 'lg'];
        }

        return $bubble;
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
                    ['type' => 'text', 'text' => '🧾 ใบเสร็จ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'xl'],
                    ['type' => 'text', 'text' => $shopName, 'color' => '#FFFFFF', 'size' => 'sm', 'margin' => 'sm']
                ],
                'backgroundColor' => '#06C755', 'paddingAll' => 'lg'
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
                        ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end']
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
                    ['type' => 'text', 'text' => '🎉', 'size' => '4xl', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'สวัสดีครับ!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'lg', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "ขอบคุณที่เชิญ {$botName} เข้ากลุ่ม", 'size' => 'sm', 'align' => 'center', 'color' => '#666666', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => '💡 คำสั่งที่ใช้ได้', 'weight' => 'bold', 'size' => 'md', 'margin' => 'xl'],
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
                    ['type' => 'text', 'text' => '⏳', 'size' => '3xl', 'align' => 'center'],
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
            ['type' => 'text', 'text' => '📭', 'size' => '4xl', 'align' => 'center'],
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
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => $actionLabel, 'text' => $actionText], 'style' => 'primary', 'color' => '#06C755']
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
    public static function shareButton($label = '📤 แชร์ให้เพื่อน', $shareText = '', $style = 'secondary', $color = '#3B82F6')
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
    public static function shareFlexButton($liffId, $label = '📤 แชร์ให้เพื่อน', $params = [])
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
    public static function withShareButton($bubble, $shareText, $label = '📤 แชร์ให้เพื่อน')
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
                    'label' => $item['label'],
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
                    'label' => '📤 แชร์',
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
        $shareText = "🛒 {$product['name']}\n💰 ราคา ฿" . number_format($price);
        if ($shopUrl) {
            $shareText .= "\n🔗 {$shopUrl}";
        }
        
        $bubble = self::productCard($product);
        
        // Add share button to footer
        if (isset($bubble['footer']['contents'])) {
            $bubble['footer']['contents'][] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => '📤 แชร์ให้เพื่อน',
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
                    ['type' => 'text', 'text' => '🎉 ' . $title, 'weight' => 'bold', 'size' => 'xl', 'color' => '#FF6B6B', 'wrap' => true],
                    ['type' => 'text', 'text' => $description, 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'md']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ดูเลย!', 'text' => $actionText], 'style' => 'primary', 'color' => '#FF6B6B'],
                    self::shareButton('📤 บอกเพื่อน', $shareText ?: "🎉 {$title}\n{$description}", 'secondary', '#3B82F6')
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
        $shareText = "🎁 {$userName} ชวนคุณมาช้อป!\n";
        $shareText .= "ใช้โค้ด: {$referralCode}";
        if ($reward) {
            $shareText .= "\n🎉 รับ {$reward}";
        }
        if ($shopUrl) {
            $shareText .= "\n🔗 {$shopUrl}";
        }
        
        return [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '🎁', 'size' => '4xl', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'ชวนเพื่อนมาช้อป!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'lg', 'color' => '#FF6B6B'],
                    ['type' => 'text', 'text' => 'แชร์โค้ดนี้ให้เพื่อน', 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'md'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => 'โค้ดของคุณ', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                        ['type' => 'text', 'text' => $referralCode, 'size' => 'xxl', 'weight' => 'bold', 'color' => '#FF6B6B', 'align' => 'center']
                    ], 'margin' => 'xl', 'paddingAll' => 'lg', 'backgroundColor' => '#FFF5F5', 'cornerRadius' => 'lg'],
                    $reward ? ['type' => 'text', 'text' => "🎉 เพื่อนได้รับ {$reward}", 'size' => 'sm', 'align' => 'center', 'color' => '#06C755', 'margin' => 'lg'] : ['type' => 'filler']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    self::shareButton('📤 แชร์ให้เพื่อนเลย!', $shareText, 'primary', '#FF6B6B')
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
        $menuItems = [];
        
        // Shop button - always show if LIFF URL available
        if ($liffShopUrl) {
            $menuItems[] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => '🛒 เปิดร้านค้า',
                    'uri' => $liffShopUrl
                ],
                'style' => 'primary',
                'color' => '#06C755',
                'height' => 'md'
            ];
        }
        
        // Video Call button - optional
        if ($liffVideoCallUrl) {
            $menuItems[] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => '📹 วิดีโอคอล',
                    'uri' => $liffVideoCallUrl
                ],
                'style' => 'secondary',
                'height' => 'sm',
                'margin' => 'sm'
            ];
        }
        
        // Menu button - always show
        $menuItems[] = [
            'type' => 'button',
            'action' => [
                'type' => 'message',
                'label' => '📋 ดูเมนูทั้งหมด',
                'text' => 'menu'
            ],
            'style' => 'secondary',
            'height' => 'sm',
            'margin' => 'sm'
        ];
        
        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '🎉', 'size' => '3xl', 'align' => 'center']
                ],
                'backgroundColor' => '#06C755',
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'ยินดีต้อนรับ!', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "สวัสดีคุณ {$displayName}", 'size' => 'lg', 'align' => 'center', 'margin' => 'md', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => "ขอบคุณที่ติดต่อ {$shopName}", 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '✨ เลือกบริการที่ต้องการ', 'weight' => 'bold', 'size' => 'md', 'align' => 'center', 'color' => '#333333'],
                        ['type' => 'text', 'text' => 'กดปุ่มเริ่มต้นใช้งาน', 'size' => 'xs', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm']
                    ], 'margin' => 'xl', 'paddingAll' => 'md', 'backgroundColor' => '#F8F8F8', 'cornerRadius' => 'lg']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $menuItems,
                'paddingAll' => 'lg'
            ],
            'styles' => ['footer' => ['separator' => true]]
        ];
    }
    
    /**
     * First Message Menu - เมนูสำหรับข้อความแรก
     * ส่งเมื่อลูกค้าทักมาครั้งแรก
     */
    public static function firstMessageMenu($shopName = 'LINE Shop', $liffShopUrl = '', $displayName = 'คุณลูกค้า')
    {
        $buttons = [];
        
        // LIFF Shop button
        if ($liffShopUrl) {
            $buttons[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '🛒', 'size' => 'xxl', 'align' => 'center']
                    ], 'width' => '50px', 'height' => '50px', 'backgroundColor' => '#06C75520', 'cornerRadius' => 'xl', 'justifyContent' => 'center'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => 'เปิดร้านค้า', 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => 'ดูสินค้าและสั่งซื้อ', 'size' => 'xs', 'color' => '#888888']
                    ], 'margin' => 'lg', 'justifyContent' => 'center']
                ],
                'paddingAll' => 'md',
                'backgroundColor' => '#FFFFFF',
                'cornerRadius' => 'xl',
                'borderWidth' => '1px',
                'borderColor' => '#E5E5E5',
                'action' => ['type' => 'uri', 'uri' => $liffShopUrl]
            ];
        }
        
        // Menu button
        $buttons[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'text', 'text' => '📋', 'size' => 'xxl', 'align' => 'center']
                ], 'width' => '50px', 'height' => '50px', 'backgroundColor' => '#3B82F620', 'cornerRadius' => 'xl', 'justifyContent' => 'center'],
                ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'text', 'text' => 'ดูเมนู', 'weight' => 'bold', 'size' => 'md'],
                    ['type' => 'text', 'text' => 'บริการทั้งหมด', 'size' => 'xs', 'color' => '#888888']
                ], 'margin' => 'lg', 'justifyContent' => 'center']
            ],
            'paddingAll' => 'md',
            'backgroundColor' => '#FFFFFF',
            'cornerRadius' => 'xl',
            'borderWidth' => '1px',
            'borderColor' => '#E5E5E5',
            'margin' => 'md',
            'action' => ['type' => 'message', 'text' => 'menu']
        ];
        
        // Contact button
        $buttons[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'text', 'text' => '💬', 'size' => 'xxl', 'align' => 'center']
                ], 'width' => '50px', 'height' => '50px', 'backgroundColor' => '#EC489920', 'cornerRadius' => 'xl', 'justifyContent' => 'center'],
                ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'text', 'text' => 'ติดต่อเรา', 'weight' => 'bold', 'size' => 'md'],
                    ['type' => 'text', 'text' => 'สอบถามข้อมูล', 'size' => 'xs', 'color' => '#888888']
                ], 'margin' => 'lg', 'justifyContent' => 'center']
            ],
            'paddingAll' => 'md',
            'backgroundColor' => '#FFFFFF',
            'cornerRadius' => 'xl',
            'borderWidth' => '1px',
            'borderColor' => '#E5E5E5',
            'margin' => 'md',
            'action' => ['type' => 'message', 'text' => 'contact']
        ];
        
        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => '👋', 'size' => '3xl', 'align' => 'center']
                    ], 'flex' => 0],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF'],
                        ['type' => 'text', 'text' => 'ยินดีให้บริการค่ะ', 'size' => 'sm', 'color' => '#FFFFFF', 'style' => 'italic']
                    ], 'margin' => 'lg']
                ],
                'backgroundColor' => '#06C755',
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => "สวัสดีคุณ {$displayName} 🙏", 'weight' => 'bold', 'size' => 'lg', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'เลือกบริการที่ต้องการได้เลยค่ะ', 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'md'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $buttons, 'margin' => 'lg']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '💡 พิมพ์ข้อความเพื่อสอบถามได้เลย', 'size' => 'xs', 'color' => '#888888', 'align' => 'center']
                ],
                'paddingAll' => 'md',
                'backgroundColor' => '#FAFAFA'
            ]
        ];
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
            'cash' => '💵 เงินสด',
            'transfer' => '📱 โอนเงิน',
            'card' => '💳 บัตร',
            'qr' => '📲 QR',
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
        $headerContents[] = ['type' => 'text', 'text' => '⭐ ใบรับแต้มสะสม', 'size' => 'xs', 'color' => $white, 'align' => 'center', 'margin' => 'xs'];

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
                ['type' => 'text', 'text' => '✅ บันทึกการซื้อสำเร็จ', 'size' => 'md', 'weight' => 'bold', 'color' => $darkGreen, 'align' => 'center']
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
                ['type' => 'text', 'text' => '+' . number_format($points) . ' ⭐', 'size' => 'xl', 'color' => $darkGreen, 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
            ],
            'margin' => 'md',
            'paddingAll' => 'md',
            'backgroundColor' => $lightGreen,
            'cornerRadius' => 'md',
            'borderWidth' => '1px',
            'borderColor' => $darkGreen
        ];

        // Running balance
        $bodyContents[] = $detailRow('แต้มสะสมรวม', number_format($totalPoints) . ' ⭐', $darkGreen);

        // Thank-you + keep-as-proof
        $bodyContents[] = ['type' => 'separator', 'color' => '#E5E7EB', 'margin' => 'md'];
        $thankName = $customerName !== '' ? $customerName : 'ลูกค้า';
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => 'ขอบคุณคุณ ' . $thankName . ' 🙏', 'size' => 'sm', 'color' => $black, 'align' => 'center', 'wrap' => true, 'weight' => 'bold'],
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
                    ['type' => 'text', 'text' => $shopPhone !== '' ? ('☎ ' . $shopPhone) : 'สะสมแต้มแลกของรางวัลได้เลย', 'size' => 'xs', 'color' => $darkGreen, 'align' => 'center', 'weight' => 'bold']
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
                    ['type' => 'text', 'text' => '⚠️', 'size' => 'sm', 'flex' => 0],
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
                    ['type' => 'text', 'text' => '📝', 'size' => 'sm', 'flex' => 0],
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
                ['type' => 'text', 'text' => '⚠️ ตั้งครรภ์ แพ้ยา มีโรคประจำตัว กรุณาแจ้งเภสัชกร', 'size' => 'xxs', 'color' => $white, 'wrap' => true, 'align' => 'center', 'weight' => 'bold']
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
                    ['type' => 'text', 'text' => '💊 สรรพคุณ / ข้อบ่งใช้', 'size' => 'xs', 'weight' => 'bold', 'color' => $darkGreen],
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
                    ['type' => 'text', 'text' => '📖 วิธีใช้', 'size' => 'xs', 'weight' => 'bold', 'color' => '#B45309'],
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
                    [['type' => 'text', 'text' => '⚠️ คำเตือน', 'size' => 'xs', 'weight' => 'bold', 'color' => '#B91C1C']],
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
            $headerContents[] = ['type' => 'text', 'text' => '☎ ' . $shopPhone, 'size' => 'sm', 'color' => $white, 'align' => 'center', 'margin' => 'sm'];
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
                    ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '💳 ชำระเงิน', 'uri' => $checkoutUrl], 'style' => 'primary', 'color' => $darkGreen],
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
                        ['type' => 'text', 'text' => '🧾 สรุปรายการ', 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF', 'align' => 'center']
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
                                ['type' => 'text', 'text' => '฿' . number_format($total, 2), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end']
                            ], 'margin' => 'lg']
                        ]
                    ),
                    'paddingAll' => 'lg'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '💳 ชำระเงินทั้งหมด', 'uri' => $checkoutUrl], 'style' => 'primary', 'color' => '#06C755']
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
}
