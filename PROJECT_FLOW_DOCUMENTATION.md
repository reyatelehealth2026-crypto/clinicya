# 📋 LINE Telepharmacy Platform - Project Flow Documentation

## 🏗️ สถาปัตยกรรมระบบ (System Architecture)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           LINE Telepharmacy Platform                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                   │
│  │   LINE App   │    │  Web Browser │    │  Admin Panel │                   │
│  │   (LIFF)     │    │  (Landing)   │    │  (Backend)   │                   │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘                   │
│         │                   │                   │                            │
│         ▼                   ▼                   ▼                            │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                        Entry Points                                  │    │
│  │  /liff/index.php (SPA)  │  /index.php (Landing)  │  /admin/ (Admin) │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                          API Layer (/api/)                           │    │
│  │  checkout.php │ member.php │ orders.php │ ai-chat.php │ etc.        │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                      Service Classes (/classes/)                     │    │
│  │  LineAPI │ LoyaltyPoints │ GeminiAI │ NotificationService │ etc.    │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                         Database (MySQL)                             │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🚀 Entry Points (จุดเริ่มต้นของระบบ)

### 1. `/liff/index.php` - LIFF SPA Application (หลัก)

**Entry Point หลักสำหรับผู้ใช้ LINE** - Single Page Application (SPA)

```
URL: https://liff.line.me/{LIFF_ID}
     หรือ /liff/?account={id}&page={page}
```

**Flow การทำงาน:**
```
1. User เปิด LIFF URL ใน LINE App
   │
   ▼
2. /liff/index.php โหลด
   ├── ดึง LINE Account จาก DB
   ├── ดึง Shop Settings
   ├── สร้าง APP_CONFIG (JavaScript)
   │
   ▼
3. โหลด JavaScript Files:
   ├── store.js (State Management)
   ├── router.js (Client-side Routing)
   ├── liff-app.js (Main Controller)
   └── components/*.js (UI Components)
   │
   ▼
4. LiffApp.init() ทำงาน:
   ├── liff.init() - Initialize LIFF SDK
   ├── liff.getProfile() - ดึงข้อมูลผู้ใช้
   ├── loadMemberData() - ดึงข้อมูลสมาชิก
   └── router.init() - เริ่ม Client-side Routing
   │
   ▼
5. Router นำทางไปหน้าที่ต้องการ:
   ├── #/ หรือ #/home → Home Page
   ├── #/shop → Shop Page
   ├── #/cart → Cart Page
   ├── #/checkout → Checkout Page
   ├── #/orders → Orders Page
   ├── #/profile → Profile Page
   └── etc.
```

### 2. `/index.php` - Public Landing Page

**หน้า Landing สำหรับผู้เยี่ยมชมทั่วไป**

```
URL: https://yourdomain.com/
```

**Flow การทำงาน:**
```
1. User เข้าเว็บไซต์
   │
   ▼
2. /index.php โหลด
   ├── ตรวจสอบ installed.lock
   ├── ดึง LINE Account (default)
   ├── ดึง Shop Settings
   ├── ดึง Theme Colors
   └── ดึง Featured Products
   │
   ▼
3. แสดงหน้า Landing:
   ├── Hero Section + CTA
   ├── Services Section
   ├── Products Section
   └── Contact Section
   │
   ▼
4. User กดปุ่ม "เปิดแอป"
   │
   ▼
5. Redirect ไป LIFF URL
```

### 3. `/webhook.php` - LINE Webhook Handler

**รับ Events จาก LINE Platform**

```
URL: https://yourdomain.com/webhook.php?account={id}
```

**Flow การทำงาน:**
```
1. LINE Platform ส่ง Event
   │
   ▼
2. /webhook.php รับ Request
   ├── Validate Signature
   ├── ระบุ LINE Account
   │
   ▼
3. Process Events:
   │
   ├── follow → handleFollow()
   │   ├── บันทึก User ใน DB
   │   ├── ส่ง Welcome Message
   │   ├── CRM Auto-tag
   │   └── Assign Rich Menu
   │
   ├── unfollow → handleUnfollow()
   │   └── Mark user as blocked
   │
   ├── message → handleMessage()
   │   ├── Text Message
   │   │   ├── @ai → AI Chat
   │   │   ├── shop → Shop Menu
   │   │   └── etc.
   │   ├── Image Message
   │   └── Sticker Message
   │
   └── postback → handlePostback()
       └── Process button clicks
```

### 4. `/admin/` - Admin Panel

**หน้า Admin สำหรับจัดการระบบ**

```
URL: https://yourdomain.com/admin/
```

---

## 📱 LIFF Application Flow (รายละเอียด)

### Home Page Flow (`#/home`)

```
┌─────────────────────────────────────────────────────────────────┐
│                         Home Page                                │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  Header: Shop Logo + Notifications + AI Chat Button     │    │
│  └─────────────────────────────────────────────────────────┘    │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  Member Card: Points, Tier, QR Code                     │    │
│  │  (ถ้าไม่ได้ Login → แสดงปุ่ม Login/Register)            │    │
│  └─────────────────────────────────────────────────────────┘    │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  Service Grid (3x2):                                    │    │
│  │  [ร้านค้า] [ตะกร้า] [ออเดอร์]                           │    │
│  │  [AI ผู้ช่วย] [นัดหมาย] [แลกแต้ม]                        │    │
│  └─────────────────────────────────────────────────────────┘    │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  AI Assistant Quick Actions                             │    │
│  └─────────────────────────────────────────────────────────┘    │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  Available Pharmacists                                  │    │
│  └─────────────────────────────────────────────────────────┘    │
├─────────────────────────────────────────────────────────────────┤
│  Bottom Navigation: [หน้าหลัก] [ร้านค้า] [ตะกร้า] [ออเดอร์] [โปรไฟล์] │
└─────────────────────────────────────────────────────────────────┘
```

### Shop Flow (`#/shop` → `#/cart` → `#/checkout`)

```
┌──────────────────────────────────────────────────────────────────────────┐
│                              SHOP FLOW                                    │
└──────────────────────────────────────────────────────────────────────────┘

1. SHOP PAGE (#/shop)
   │
   ├── API: GET /api/shop-products.php?action=list
   │   └── Response: { products: [...], categories: [...] }
   │
   ├── แสดงสินค้า (Grid/List)
   │   ├── Filter by Category
   │   ├── Search
   │   └── Sort (ราคา, ยอดนิยม, ใหม่)
   │
   └── User กด "เพิ่มลงตะกร้า"
       │
       ├── store.addToCart(product, qty)
       │   ├── Update Local State
       │   └── Sync to Server (POST /api/checkout.php?action=add_to_cart)
       │
       └── แสดง Cart Summary Bar
           │
           ▼
2. CART PAGE (#/cart)
   │
   ├── แสดงรายการในตะกร้า
   │   ├── แก้ไขจำนวน
   │   ├── ลบสินค้า
   │   └── Drug Interaction Check (ถ้ามียา)
   │
   ├── ใส่ Coupon Code
   │   └── API: POST /api/checkout.php?action=apply_coupon
   │
   └── กด "ดำเนินการสั่งซื้อ"
       │
       ▼
3. CHECKOUT PAGE (#/checkout)
   │
   ├── กรอกข้อมูลจัดส่ง
   │   ├── ชื่อ-นามสกุล
   │   ├── เบอร์โทร
   │   └── ที่อยู่
   │
   ├── เลือกวิธีชำระเงิน
   │   ├── โอนเงิน
   │   └── เก็บเงินปลายทาง
   │
   ├── ถ้ามียาที่ต้องใช้ใบสั่งแพทย์
   │   └── Prescription Handler → รอ Pharmacist Approve
   │
   └── กด "ยืนยันคำสั่งซื้อ"
       │
       ├── API: POST /api/checkout.php?action=create_order
       │   └── Response: { success: true, order_id: "ORD..." }
       │
       ├── Clear Cart
       │
       ├── Send LINE Message (Order Confirmation)
       │   └── LiffMessageBridge.sendOrderConfirmation()
       │
       └── Redirect → #/order/{order_id}
```

### Order Management Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           ORDER FLOW                                      │
└──────────────────────────────────────────────────────────────────────────┘

1. ORDERS LIST (#/orders)
   │
   ├── API: GET /api/orders.php?action=list&line_user_id={id}
   │
   └── แสดงรายการ Orders
       ├── Filter by Status
       └── กดดูรายละเอียด
           │
           ▼
2. ORDER DETAIL (#/order/{id})
   │
   ├── API: GET /api/orders.php?action=detail&order_id={id}
   │
   ├── แสดงรายละเอียด
   │   ├── สถานะ Order
   │   ├── รายการสินค้า
   │   ├── ข้อมูลจัดส่ง
   │   └── ข้อมูลการชำระเงิน
   │
   └── Actions:
       ├── อัพโหลดสลิป (ถ้ายังไม่ชำระ)
       ├── ติดตามพัสดุ
       └── ติดต่อร้าน
```

### Points & Rewards Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                        POINTS & REWARDS FLOW                              │
└──────────────────────────────────────────────────────────────────────────┘

1. POINTS DASHBOARD (#/points)
   │
   ├── API: GET /api/points.php?action=dashboard
   │
   └── แสดง:
       ├── คะแนนปัจจุบัน
       ├── Tier Progress
       ├── Points History
       └── กฎการได้รับคะแนน
           │
           ▼
2. REDEEM PAGE (#/redeem)
   │
   ├── API: GET /api/points.php?action=rewards
   │
   ├── แสดงรางวัลที่แลกได้
   │
   └── กด "แลกรางวัล"
       │
       ├── API: POST /api/points.php?action=redeem
       │
       └── หักคะแนน + สร้าง Redemption Record
```

### AI Assistant Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         AI ASSISTANT FLOW                                 │
└──────────────────────────────────────────────────────────────────────────┘

1. AI ASSISTANT PAGE (#/ai-assistant)
   │
   ├── Quick Actions:
   │   ├── ปรึกษาอาการ
   │   ├── ถามเรื่องยา
   │   └── แนะนำสินค้า
   │
   └── Chat Interface
       │
       ▼
2. USER ส่งข้อความ
   │
   ├── API: POST /api/ai-chat.php
   │   ├── Input: { message, session_id, context }
   │   │
   │   ├── Process:
   │   │   ├── Load Health Profile (ถ้ามี)
   │   │   ├── Check Drug Allergies
   │   │   ├── Detect Red Flags
   │   │   └── Generate AI Response (Gemini/OpenAI)
   │   │
   │   └── Output: { response, suggestions, warnings }
   │
   └── แสดงคำตอบ + Suggestions
       │
       ├── ถ้ามี Red Flag → แจ้งเตือน Pharmacist
       │
       └── ถ้าแนะนำสินค้า → แสดงปุ่ม "เพิ่มลงตะกร้า"
```

### Video Call / Pharmacist Consultation Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                        VIDEO CALL FLOW                                    │
└──────────────────────────────────────────────────────────────────────────┘

1. APPOINTMENTS PAGE (#/appointments)
   │
   ├── API: GET /api/appointments.php?action=list
   │
   ├── แสดงนัดหมายที่มี
   │
   └── กด "นัดหมายใหม่"
       │
       ├── เลือกเภสัชกร
       ├── เลือกวัน/เวลา
       │
       └── API: POST /api/appointments.php?action=create
           │
           ▼
2. VIDEO CALL PAGE (#/video-call/{appointment_id})
   │
   ├── API: GET /api/video-call.php?action=join
   │
   ├── Permission Check:
   │   ├── Camera
   │   └── Microphone
   │
   ├── Initialize WebRTC / Video SDK
   │
   └── Start Video Call
       │
       ├── Chat Panel (ส่งข้อความระหว่างโทร)
       │
       └── End Call
           │
           └── API: POST /api/video-call.php?action=end
```

### Health Profile Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       HEALTH PROFILE FLOW                                 │
└──────────────────────────────────────────────────────────────────────────┘

1. HEALTH PROFILE PAGE (#/health-profile)
   │
   ├── API: GET /api/health-profile.php?action=get
   │
   └── แสดง/แก้ไขข้อมูล:
       ├── ข้อมูลพื้นฐาน (น้ำหนัก, ส่วนสูง, เพศ, อายุ)
       ├── โรคประจำตัว
       ├── ยาที่แพ้
       ├── ยาที่ใช้ประจำ
       └── ประวัติการรักษา
           │
           └── API: POST /api/health-profile.php?action=update
```

---

## 🔌 API Endpoints (รายละเอียด)

### Core APIs

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/member.php` | GET/POST | จัดการข้อมูลสมาชิก |
| `/api/checkout.php` | POST | จัดการตะกร้า/สั่งซื้อ |
| `/api/orders.php` | GET/POST | จัดการ Orders |
| `/api/shop-products.php` | GET | ดึงข้อมูลสินค้า |
| `/api/points.php` | GET/POST | จัดการคะแนนสะสม |
| `/api/points-rules.php` | GET | ดึงกฎการได้รับคะแนน |

### AI & Health APIs

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/ai-chat.php` | POST | AI Chat (Gemini/OpenAI) |
| `/api/health-profile.php` | GET/POST | ข้อมูลสุขภาพ |
| `/api/drug-interactions.php` | POST | ตรวจสอบปฏิกิริยายา |
| `/api/symptom-assessment.php` | POST | ประเมินอาการ |

### Communication APIs

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/appointments.php` | GET/POST | จัดการนัดหมาย |
| `/api/video-call.php` | GET/POST | Video Call |
| `/api/messages.php` | GET/POST | ข้อความ |
| `/api/user-notifications.php` | GET/POST | การแจ้งเตือน |

### Utility APIs

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/wishlist.php` | GET/POST | รายการโปรด |
| `/api/medication-reminders.php` | GET/POST | เตือนทานยา |
| `/api/liff-bridge.php` | POST | ส่งข้อความผ่าน LIFF |

---

## ⚙️ Service Classes (รายละเอียด)

### Core Services

```php
// /classes/LineAPI.php
class LineAPI {
    // ส่งข้อความ, ดึง Profile, จัดการ Rich Menu
    pushMessage($userId, $messages)
    replyMessage($replyToken, $messages)
    getProfile($userId)
    linkRichMenu($userId, $richMenuId)
}

// /classes/Database.php
class Database {
    // Singleton Database Connection
    getInstance()
    getConnection()
}

// /classes/LoyaltyPoints.php
class LoyaltyPoints {
    // จัดการคะแนนสะสม
    addPoints($userId, $points, $reason)
    deductPoints($userId, $points, $reason)
    getBalance($userId)
    getTier($userId)
}
```

### AI Services

```php
// /classes/GeminiAI.php
class GeminiAI {
    // Google Gemini AI Integration
    chat($message, $context)
    analyzeSymptoms($symptoms)
}

// /classes/OpenAI.php
class OpenAI {
    // OpenAI Integration (Fallback)
    chat($message, $context)
}
```

### Notification Services

```php
// /classes/NotificationService.php
class NotificationService {
    // ส่งการแจ้งเตือน
    sendOrderNotification($orderId)
    sendAppointmentReminder($appointmentId)
    sendMedicationReminder($reminderId)
}

// /classes/TelegramAPI.php
class TelegramAPI {
    // แจ้งเตือน Admin ผ่าน Telegram
    sendMessage($chatId, $message)
}
```

### Business Logic Services

```php
// /classes/CRMManager.php
class CRMManager {
    // Customer Relationship Management
    onUserFollow($userId)
    assignTags($userId, $tags)
    triggerDripCampaign($userId, $campaignId)
}

// /classes/AutoTagManager.php
class AutoTagManager {
    // Auto-tagging based on behavior
    onFollow($userId)
    onPurchase($userId, $orderId)
    onBroadcastClick($userId, $campaignId)
}

// /classes/DynamicRichMenu.php
class DynamicRichMenu {
    // Dynamic Rich Menu based on user segment
    assignRichMenuByRules($userId, $lineUserId)
}
```

---

## ⏰ Cron Jobs (Scheduled Tasks)

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           CRON JOBS                                       │
└──────────────────────────────────────────────────────────────────────────┘

/cron/medication_reminder.php
├── ทุก 15 นาที
└── ส่งเตือนทานยาตามเวลาที่ตั้งไว้

/cron/medication_refill_reminder.php
├── ทุกวัน 09:00
└── เตือนเติมยาเมื่อใกล้หมด

/cron/appointment_reminder.php
├── ทุก 30 นาที
└── เตือนนัดหมายล่วงหน้า 24 ชม. และ 1 ชม.

/cron/reward_expiry_reminder.php
├── ทุกวัน 10:00
└── เตือนคะแนนใกล้หมดอายุ

/cron/restock_notification.php
├── ทุกวัน 08:00
└── แจ้งเตือน Admin เมื่อสินค้าใกล้หมด

/cron/wishlist_notification.php
├── ทุกวัน 11:00
└── แจ้งเตือนเมื่อสินค้าในรายการโปรดลดราคา

/cron/process_broadcast_queue.php
├── ทุก 5 นาที
└── ส่ง Broadcast Messages ที่อยู่ใน Queue

/cron/process_drip_campaigns.php
├── ทุก 10 นาที
└── ส่งข้อความ Drip Campaign ตามกำหนด

/cron/check_inactive_users.php
├── ทุกวัน 14:00
└── ตรวจสอบและ Tag ผู้ใช้ที่ไม่ Active

/cron/scheduled_reports.php
├── ทุกวัน 07:00
└── สร้างและส่ง Reports อัตโนมัติ

/cron/sync_worker.php
├── ทุก 1 นาที
└── Sync ข้อมูลกับระบบภายนอก (CNY Pharmacy)
```

**Crontab Configuration:**
```bash
# Medication Reminders
*/15 * * * * php /path/to/cron/medication_reminder.php
0 9 * * * php /path/to/cron/medication_refill_reminder.php

# Appointments
*/30 * * * * php /path/to/cron/appointment_reminder.php

# Rewards
0 10 * * * php /path/to/cron/reward_expiry_reminder.php

# Inventory
0 8 * * * php /path/to/cron/restock_notification.php

# Wishlist
0 11 * * * php /path/to/cron/wishlist_notification.php

# Broadcast & Campaigns
*/5 * * * * php /path/to/cron/process_broadcast_queue.php
*/10 * * * * php /path/to/cron/process_drip_campaigns.php

# User Management
0 14 * * * php /path/to/cron/check_inactive_users.php

# Reports
0 7 * * * php /path/to/cron/scheduled_reports.php

# Sync
* * * * * php /path/to/cron/sync_worker.php
```

---

## 🔄 Data Flow Diagrams

### User Registration Flow

```
┌─────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────┐
│  LINE   │────▶│  webhook.php │────▶│  Database   │────▶│  CRM     │
│  Follow │     │  (follow)    │     │  (users)    │     │  Auto-tag│
└─────────┘     └─────────────┘     └─────────────┘     └──────────┘
                      │                                       │
                      ▼                                       ▼
               ┌─────────────┐                         ┌──────────┐
               │  Welcome    │                         │  Rich    │
               │  Message    │                         │  Menu    │
               └─────────────┘                         └──────────┘
```

### Purchase Flow

```
┌─────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────┐
│  LIFF   │────▶│  checkout   │────▶│  Database   │────▶│  Points  │
│  Cart   │     │  API        │     │  (orders)   │     │  Award   │
└─────────┘     └─────────────┘     └─────────────┘     └──────────┘
                      │                                       │
                      ▼                                       ▼
               ┌─────────────┐                         ┌──────────┐
               │  LINE       │                         │  Telegram│
               │  Notify     │                         │  Admin   │
               └─────────────┘                         └──────────┘
```

### AI Chat Flow

```
┌─────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────┐
│  User   │────▶│  ai-chat    │────▶│  Health     │────▶│  Gemini  │
│  Message│     │  API        │     │  Profile    │     │  AI      │
└─────────┘     └─────────────┘     └─────────────┘     └──────────┘
                      │                                       │
                      ▼                                       ▼
               ┌─────────────┐                         ┌──────────┐
               │  Drug       │                         │  Response│
               │  Interaction│                         │  + Suggest│
               └─────────────┘                         └──────────┘
                      │
                      ▼ (if Red Flag)
               ┌─────────────┐
               │  Pharmacist │
               │  Alert      │
               └─────────────┘
```

---

## 📁 Directory Structure

```
/
├── liff/                      # LIFF SPA Application (Main Entry)
│   ├── index.php              # SPA Entry Point
│   └── assets/
│       ├── css/
│       │   └── liff-app.css   # Main Styles
│       └── js/
│           ├── store.js       # State Management
│           ├── router.js      # Client-side Router
│           ├── liff-app.js    # Main Controller
│           └── components/    # UI Components
│               ├── ai-chat.js
│               ├── health-profile.js
│               ├── points-dashboard.js
│               ├── video-call.js
│               └── ...
│
├── api/                       # Backend APIs
│   ├── checkout.php
│   ├── member.php
│   ├── orders.php
│   ├── ai-chat.php
│   ├── health-profile.php
│   └── ...
│
├── classes/                   # Service Classes
│   ├── LineAPI.php
│   ├── LoyaltyPoints.php
│   ├── GeminiAI.php
│   ├── NotificationService.php
│   └── ...
│
├── cron/                      # Scheduled Tasks
│   ├── medication_reminder.php
│   ├── appointment_reminder.php
│   └── ...
│
├── admin/                     # Admin Panel
│   └── index.php
│
├── config/                    # Configuration
│   ├── config.php
│   └── database.php
│
├── index.php                  # Public Landing Page
├── webhook.php                # LINE Webhook Handler
└── ...
```

---

## 🔐 Authentication Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                      AUTHENTICATION FLOW                                  │
└──────────────────────────────────────────────────────────────────────────┘

1. LIFF AUTHENTICATION (User)
   │
   ├── User เปิด LIFF URL ใน LINE App
   │   │
   │   ▼
   ├── liff.init({ liffId })
   │   │
   │   ▼
   ├── liff.isLoggedIn() ?
   │   │
   │   ├── YES → liff.getProfile()
   │   │         └── ได้ userId, displayName, pictureUrl
   │   │
   │   └── NO → liff.login()
   │             └── Redirect to LINE Login
   │
   └── ใช้ userId ในการเรียก API

2. ADMIN AUTHENTICATION
   │
   ├── /admin/ → /auth/login.php
   │   │
   │   ▼
   ├── กรอก Username/Password
   │   │
   │   ▼
   ├── AdminAuth::login($username, $password)
   │   │
   │   ▼
   └── Session-based Authentication
       └── $_SESSION['admin_id'], $_SESSION['admin_role']
```

---

## 🔔 Notification System

```
┌──────────────────────────────────────────────────────────────────────────┐
│                      NOTIFICATION CHANNELS                                │
└──────────────────────────────────────────────────────────────────────────┘

1. LINE PUSH MESSAGE
   │
   ├── Order Confirmation
   ├── Order Status Update
   ├── Appointment Reminder
   ├── Medication Reminder
   ├── Points Earned/Redeemed
   └── Promotional Messages

2. TELEGRAM (Admin)
   │
   ├── New Order Alert
   ├── New User Follow
   ├── Low Stock Alert
   ├── Red Flag Alert (AI Chat)
   └── System Errors

3. EMAIL (Optional)
   │
   ├── Order Confirmation
   ├── Password Reset
   └── Reports
```

---

## 📊 Database Schema (Key Tables)

```sql
-- Users & Members
users (id, line_user_id, display_name, picture_url, phone, ...)
members (id, user_id, member_id, tier, points, ...)
health_profiles (id, user_id, allergies, conditions, medications, ...)

-- Products & Orders
products (id, name, price, sale_price, is_prescription, ...)
orders (id, user_id, order_number, status, total, ...)
order_items (id, order_id, product_id, quantity, price, ...)
cart_items (id, user_id, product_id, quantity, ...)

-- Points & Rewards
points_transactions (id, user_id, points, type, reason, ...)
rewards (id, name, points_required, ...)
redemptions (id, user_id, reward_id, points_used, ...)

-- Appointments & Video Calls
appointments (id, user_id, pharmacist_id, datetime, status, ...)
video_calls (id, appointment_id, room_id, started_at, ended_at, ...)

-- AI & Health
ai_chat_sessions (id, user_id, session_id, ...)
ai_chat_messages (id, session_id, role, content, ...)

-- Notifications
medication_reminders (id, user_id, medication_name, times, ...)
user_notifications (id, user_id, type, enabled, ...)

-- LINE Integration
line_accounts (id, name, channel_access_token, channel_secret, liff_id, ...)
shop_settings (id, line_account_id, shop_name, shop_logo, ...)
```

---

## 🚀 Quick Start Guide

### 1. Installation

```bash
# 1. Clone/Upload files to server
# 2. Access /install/ to run installation wizard
# 3. Configure database credentials
# 4. Set up LINE Channel (Messaging API)
# 5. Configure LIFF App in LINE Developers Console
```

### 2. LINE Configuration

```
LINE Developers Console:
├── Create Messaging API Channel
│   ├── Get Channel Access Token
│   ├── Get Channel Secret
│   └── Set Webhook URL: https://yourdomain.com/webhook.php
│
└── Create LIFF App
    ├── Endpoint URL: https://yourdomain.com/liff/
    ├── Scope: profile, openid
    └── Get LIFF ID
```

### 3. Admin Setup

```
1. Login to /admin/
2. Go to LINE Accounts → Add Account
3. Enter Channel Access Token, Channel Secret, LIFF ID
4. Go to Shop Settings → Configure shop info
5. Go to Products → Add products
```

### 4. Test Flow

```
1. Add LINE Official Account as friend
2. Receive Welcome Message
3. Open LIFF App from Rich Menu
4. Browse products, add to cart
5. Complete checkout
6. Check order in "ออเดอร์ของฉัน"
```

---

## 📝 Notes

- **LIFF Entry Point**: ใช้ `/liff/index.php` เป็น entry point หลัก (ไม่ใช่ `liff-app.php`)
- **SPA Architecture**: ใช้ Client-side routing ผ่าน hash (#/page)
- **State Management**: ใช้ `store.js` สำหรับจัดการ state ทั้งหมด
- **API Pattern**: ทุก API ใช้ `action` parameter เพื่อระบุ operation
- **Multi-Account**: รองรับหลาย LINE Account ในระบบเดียว

---

*Document generated: December 2024*
*Version: 2.5 - LINE Telepharmacy Platform*
