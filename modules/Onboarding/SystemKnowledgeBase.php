<?php
/**
 * SystemKnowledgeBase - ฐานความรู้เกี่ยวกับระบบ
 */

namespace Modules\Onboarding;

class SystemKnowledgeBase {
    
    // Knowledge Topics
    const KNOWLEDGE = [
        'line_connection' => [
            'title' => 'การเชื่อมต่อ LINE Official Account',
            'content' => '
## วิธีเชื่อมต่อ LINE OA

1. ไปที่ LINE Developers Console (https://developers.line.biz)
2. เลือก Provider และ Channel ที่ต้องการ
3. ไปที่ Messaging API settings
4. คัดลอก Channel Access Token และ Channel Secret
5. นำมาใส่ในหน้า LINE Accounts ของระบบ

### สิ่งที่ต้องมี
- Channel Access Token (Long-lived)
- Channel Secret

### หมายเหตุ
- ต้องเปิดใช้งาน Messaging API ก่อน
- Channel Access Token สามารถ Issue ใหม่ได้ถ้าหาย
            '
        ],
        'webhook' => [
            'title' => 'การตั้งค่า Webhook',
            'content' => '
## วิธีตั้งค่า Webhook

1. ไปที่ LINE Developers Console
2. เลือก Channel > Messaging API
3. ในส่วน Webhook settings:
   - เปิด Use webhook
   - ใส่ Webhook URL: {WEBHOOK_URL}
   - กด Verify เพื่อทดสอบ

### การแก้ปัญหา Webhook
- ตรวจสอบว่า URL ถูกต้อง
- ตรวจสอบว่า SSL Certificate ถูกต้อง
- ตรวจสอบว่า Server ตอบกลับ 200 OK
            '
        ],
        'liff' => [
            'title' => 'LIFF (LINE Front-end Framework)',
            'content' => '
## LIFF คืออะไร?

LIFF เป็นเฟรมเวิร์คที่ช่วยให้สร้างหน้าเว็บที่เปิดภายใน LINE App ได้ ทำให้ลูกค้าใช้งานได้สะดวกโดยไม่ต้องออกจาก LINE

### LIFF Apps ในระบบ
- **LIFF Shop**: ร้านค้าออนไลน์
- **LIFF Member**: บัตรสมาชิกดิจิทัล
- **LIFF Checkout**: หน้าชำระเงิน
- **LIFF Register**: หน้าลงทะเบียน

### วิธีสร้าง LIFF App
1. ไปที่ LINE Developers Console
2. เลือก Channel > LIFF
3. กด Add เพื่อสร้าง LIFF App ใหม่
4. ตั้งค่า Endpoint URL และ Scope
5. คัดลอก LIFF ID มาใส่ในระบบ
            '
        ],
        'shop' => [
            'title' => 'การตั้งค่าร้านค้า',
            'content' => '
## การตั้งค่าร้านค้า

### ข้อมูลพื้นฐาน
- ชื่อร้าน
- โลโก้ร้าน
- ข้อมูลติดต่อ
- เวลาทำการ

### การชำระเงิน
- บัญชีธนาคาร
- PromptPay
- บัตรเครดิต (ถ้ามี)

### การจัดส่ง
- โซนจัดส่ง
- ค่าจัดส่ง
- ระยะเวลาจัดส่ง
            '
        ],
        'products' => [
            'title' => 'การจัดการสินค้า',
            'content' => '
## การจัดการสินค้า

### วิธีเพิ่มสินค้า
1. ไปที่ Shop > Products
2. กด "เพิ่มสินค้า"
3. กรอกข้อมูล: ชื่อ, ราคา, รูปภาพ, รายละเอียด
4. เลือกหมวดหมู่
5. กดบันทึก

### การนำเข้าสินค้า
- นำเข้าจาก CSV
- Sync จาก CNY API (สำหรับร้านยา)

### Tips
- ใส่รูปภาพคุณภาพดี
- เขียนรายละเอียดให้ครบถ้วน
- ตั้งราคาให้ถูกต้อง
            '
        ],
        'rich_menu' => [
            'title' => 'Rich Menu',
            'content' => '
## Rich Menu คืออะไร?

Rich Menu เป็นเมนูลัดที่แสดงด้านล่างห้องแชท ช่วยให้ลูกค้าเข้าถึงฟีเจอร์ต่างๆ ได้ง่าย

### วิธีสร้าง Rich Menu
1. ไปที่ Rich Menu
2. กด "สร้าง Rich Menu"
3. อัพโหลดรูปภาพ (2500x1686 หรือ 2500x843)
4. กำหนด Action สำหรับแต่ละปุ่ม
5. กดบันทึกและเปิดใช้งาน

### ประเภท Action
- เปิด URL
- ส่งข้อความ
- เปิด LIFF
- Postback
            '
        ],
        'auto_reply' => [
            'title' => 'ข้อความตอบกลับอัตโนมัติ',
            'content' => '
## Auto Reply

ระบบตอบกลับอัตโนมัติเมื่อลูกค้าส่งข้อความที่ตรงกับ keyword ที่กำหนด

### วิธีตั้งค่า
1. ไปที่ Auto Reply
2. กด "เพิ่มข้อความตอบกลับ"
3. กำหนด Keyword (คำที่ต้องการให้ตอบ)
4. กำหนดข้อความตอบกลับ
5. เปิดใช้งาน

### Tips
- ใช้ keyword ที่ลูกค้ามักถาม
- ตอบให้กระชับและเป็นประโยชน์
- ใส่ลิงก์ไปยังข้อมูลเพิ่มเติม
            '
        ],
        'ai_chat' => [
            'title' => 'AI Chat',
            'content' => '
## AI Chat

ใช้ AI (Gemini) ตอบคำถามลูกค้าอัตโนมัติ

### วิธีเปิดใช้งาน
1. ไปที่ AI Settings
2. ใส่ Gemini API Key
3. เปิดใช้งาน AI Chat
4. ตั้งค่า Prompt และ Context

### การขอ API Key
1. ไปที่ Google AI Studio
2. สร้าง API Key
3. คัดลอกมาใส่ในระบบ

### Tips
- ตั้ง System Prompt ให้เหมาะกับธุรกิจ
- ใส่ข้อมูลสินค้าและบริการ
- ทดสอบก่อนเปิดใช้งานจริง
            '
        ],
        'broadcast' => [
            'title' => 'Broadcast',
            'content' => '
## Broadcast

ส่งข้อความถึงลูกค้าหลายคนพร้อมกัน

### วิธีส่ง Broadcast
1. ไปที่ Broadcast
2. กด "สร้าง Broadcast"
3. เลือกกลุ่มเป้าหมาย
4. สร้างข้อความ (Text, Image, Flex)
5. ตั้งเวลาส่งหรือส่งทันที

### Tips
- อย่าส่งบ่อยเกินไป
- ส่งเนื้อหาที่มีประโยชน์
- ใช้ Segment เพื่อส่งตรงกลุ่ม
            '
        ],
        'loyalty' => [
            'title' => 'ระบบแต้มสะสม',
            'content' => '
## Loyalty Points

ระบบแต้มสะสมช่วยสร้างความภักดีของลูกค้า

### วิธีตั้งค่า
1. ไปที่ Loyalty Points
2. เปิดใช้งานระบบแต้ม
3. ตั้งค่าอัตราการได้รับแต้ม
4. สร้างรางวัลแลกแต้ม

### ประโยชน์
- ลูกค้ากลับมาซื้อซ้ำ
- สร้างความผูกพัน
- เพิ่มยอดขาย
            '
        ]
    ];
    
    // Feature Information
    const FEATURES = [
        'inbox' => [
            'name' => 'Inbox',
            'description' => 'จัดการข้อความจากลูกค้าทั้งหมดในที่เดียว',
            'url' => '/inbox.php',
            'icon' => 'fas fa-inbox'
        ],
        'users' => [
            'name' => 'Users',
            'description' => 'จัดการข้อมูลลูกค้าและประวัติการสั่งซื้อ',
            'url' => '/users.php',
            'icon' => 'fas fa-users'
        ],
        'shop' => [
            'name' => 'Shop',
            'description' => 'จัดการร้านค้า สินค้า และคำสั่งซื้อ',
            'url' => '/shop/index.php',
            'icon' => 'fas fa-store'
        ],
        'broadcast' => [
            'name' => 'Broadcast',
            'description' => 'ส่งข้อความถึงลูกค้าหลายคนพร้อมกัน',
            'url' => '/broadcast.php',
            'icon' => 'fas fa-bullhorn'
        ],
        'analytics' => [
            'name' => 'Analytics',
            'description' => 'ดูสถิติและรายงานการใช้งาน',
            'url' => '/analytics.php',
            'icon' => 'fas fa-chart-line'
        ],
        'rich_menu' => [
            'name' => 'Rich Menu',
            'description' => 'สร้างเมนูลัดสำหรับลูกค้า',
            'url' => '/rich-menu.php',
            'icon' => 'fas fa-th-large'
        ],
        'auto_reply' => [
            'name' => 'Auto Reply',
            'description' => 'ตั้งค่าข้อความตอบกลับอัตโนมัติ',
            'url' => '/auto-reply.php',
            'icon' => 'fas fa-robot'
        ],
        'ai_settings' => [
            'name' => 'AI Settings',
            'description' => 'ตั้งค่า AI สำหรับตอบแชทอัตโนมัติ',
            'url' => '/ai-settings.php',
            'icon' => 'fas fa-brain'
        ],
        'loyalty' => [
            'name' => 'Loyalty Points',
            'description' => 'จัดการระบบแต้มสะสม',
            'url' => '/loyalty-points.php',
            'icon' => 'fas fa-coins'
        ],
        'members' => [
            'name' => 'Members',
            'description' => 'จัดการสมาชิกและบัตรสมาชิก',
            'url' => '/members.php',
            'icon' => 'fas fa-id-card'
        ]
    ];
    
    // Business Type Tips
    const BUSINESS_TIPS = [
        'pharmacy' => [
            'name' => 'ร้านยา',
            'recommended_features' => ['ai_chat', 'products', 'loyalty', 'broadcast'],
            'tips' => [
                'ใช้ AI Pharmacy Mode สำหรับตอบคำถามเกี่ยวกับยา',
                'Sync สินค้าจาก CNY API เพื่อความสะดวก',
                'ใช้ระบบแต้มสะสมเพื่อให้ลูกค้ากลับมาซื้อซ้ำ',
                'ส่ง Broadcast แจ้งโปรโมชั่นยาและวิตามิน'
            ]
        ],
        'retail' => [
            'name' => 'ร้านค้าปลีก',
            'recommended_features' => ['shop', 'broadcast', 'loyalty', 'rich_menu'],
            'tips' => [
                'ตั้งค่าร้านค้าออนไลน์ให้ครบถ้วน',
                'ใช้ Rich Menu เพื่อให้ลูกค้าเข้าถึงร้านค้าง่าย',
                'ส่ง Broadcast แจ้งสินค้าใหม่และโปรโมชั่น',
                'ใช้ระบบแต้มสะสมเพื่อสร้างความภักดี'
            ]
        ],
        'service' => [
            'name' => 'ธุรกิจบริการ',
            'recommended_features' => ['auto_reply', 'broadcast', 'members', 'ai_chat'],
            'tips' => [
                'ใช้ Auto Reply ตอบคำถามที่พบบ่อย',
                'ใช้ระบบนัดหมายสำหรับจองบริการ',
                'ส่ง Broadcast แจ้งโปรโมชั่นและข่าวสาร',
                'ใช้บัตรสมาชิกสำหรับลูกค้าประจำ'
            ]
        ],
        'restaurant' => [
            'name' => 'ร้านอาหาร',
            'recommended_features' => ['shop', 'rich_menu', 'broadcast', 'loyalty'],
            'tips' => [
                'ใส่เมนูอาหารพร้อมรูปภาพสวยๆ',
                'ใช้ Rich Menu แสดงเมนูและโปรโมชั่น',
                'ส่ง Broadcast แจ้งเมนูใหม่และโปรโมชั่น',
                'ใช้ระบบแต้มสะสมสำหรับลูกค้าประจำ'
            ]
        ]
    ];
    
    /**
     * Get knowledge about a topic
     */
    public function getKnowledge(string $topic): ?array {
        return self::KNOWLEDGE[$topic] ?? null;
    }
    
    /**
     * Get all knowledge topics
     */
    public function getAllTopics(): array {
        return array_keys(self::KNOWLEDGE);
    }
    
    /**
     * Get feature information
     */
    public function getFeatureInfo(string $feature): ?array {
        return self::FEATURES[$feature] ?? null;
    }
    
    /**
     * Get all features
     */
    public function getAllFeatures(): array {
        return self::FEATURES;
    }
    
    /**
     * Get navigation path
     */
    public function getNavigationPath(string $destination): ?string {
        $feature = self::FEATURES[$destination] ?? null;
        return $feature ? $feature['url'] : null;
    }
    
    /**
     * Get tips for business type
     */
    public function getTipsForBusinessType(string $businessType): ?array {
        return self::BUSINESS_TIPS[$businessType] ?? null;
    }
    
    /**
     * Get all business types
     */
    public function getAllBusinessTypes(): array {
        return self::BUSINESS_TIPS;
    }
    
    /**
     * Search knowledge by keyword
     */
    public function searchKnowledge(string $keyword): array {
        $results = [];
        $keyword = mb_strtolower($keyword);
        
        foreach (self::KNOWLEDGE as $key => $item) {
            if (mb_strpos(mb_strtolower($item['title']), $keyword) !== false ||
                mb_strpos(mb_strtolower($item['content']), $keyword) !== false) {
                $results[$key] = $item;
            }
        }
        
        return $results;
    }
    
    /**
     * Get troubleshooting guide
     */
    public function getTroubleshootingGuide(string $issue): ?array {
        $guides = [
            'webhook_failed' => [
                'title' => 'Webhook ไม่ทำงาน',
                'steps' => [
                    'ตรวจสอบว่า Webhook URL ถูกต้อง',
                    'ตรวจสอบว่า SSL Certificate ถูกต้อง',
                    'ตรวจสอบว่า Server ตอบกลับ 200 OK',
                    'ลอง Verify Webhook ใน LINE Console อีกครั้ง'
                ]
            ],
            'message_not_received' => [
                'title' => 'ไม่ได้รับข้อความจากลูกค้า',
                'steps' => [
                    'ตรวจสอบว่า Webhook ทำงานปกติ',
                    'ตรวจสอบว่า Channel Access Token ถูกต้อง',
                    'ตรวจสอบ Error Log ของระบบ',
                    'ลองส่งข้อความทดสอบ'
                ]
            ],
            'liff_not_working' => [
                'title' => 'LIFF ไม่ทำงาน',
                'steps' => [
                    'ตรวจสอบว่า LIFF ID ถูกต้อง',
                    'ตรวจสอบว่า Endpoint URL ถูกต้อง',
                    'ตรวจสอบว่า LIFF App เปิดใช้งานอยู่',
                    'ลองเปิด LIFF ใน LINE App'
                ]
            ]
        ];
        
        return $guides[$issue] ?? null;
    }
}
