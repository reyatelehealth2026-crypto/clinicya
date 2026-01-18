# 📊 LINE Telepharmacy CRM - Workflow & System Architecture

> ฉบับสมบูรณ์ | เวอร์ชัน 2.5 | มกราคม 2026

---

## 📑 สารบัญ

1. [ภาพรวมระบบ CRM](#1-ภาพรวมระบบ-crm)
2. [Customer Journey Workflows](#2-customer-journey-workflows)
3. [Admin Operation Workflows](#3-admin-operation-workflows)
4. [Automation & Background Jobs](#4-automation--background-jobs)
5. [Data Flow Architecture](#5-data-flow-architecture)
6. [Integration Points](#6-integration-points)
7. [CRM Features Deep Dive](#7-crm-features-deep-dive)

---

## 1. ภาพรวมระบบ CRM

### 1.1 System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                    LINE Telepharmacy CRM Platform                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐          │
│  │  LINE App    │    │ LIFF WebApp  │    │ Admin Panel  │          │
│  │  (Messaging) │    │  (E-comm)    │    │  (Backend)   │          │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘          │
│         │                   │                   │                   │
│         └───────────────────┼───────────────────┘                   │
│                             ▼                                        │
│         ┌───────────────────────────────────────────┐               │
│         │        Application Layer (PHP)            │               │
│         │  webhook.php  │  API  │  Admin Pages     │               │
│         └───────────────────────────────────────────┘               │
│                             ▼                                        │
│         ┌───────────────────────────────────────────┐               │
│         │         Service Classes Layer             │               │
│         │  CRM │ AI │ Loyalty │ Notifications      │               │
│         └───────────────────────────────────────────┘               │
│                             ▼                                        │
│         ┌───────────────────────────────────────────┐               │
│         │          Data Layer (MySQL)               │               │
│         │  Users │ Orders │ Messages │ Analytics   │               │
│         └───────────────────────────────────────────┘               │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘

External Integrations:
├── LINE Platform (Messaging API, LIFF)
├── Google Gemini AI / OpenAI
├── Payment Gateways (PromptPay)
├── Telegram Bot (Admin Alerts)
└── External ERP/API (CNY Pharmacy)
```

### 1.2 Core CRM Components

| Component | Purpose | Key Features |
|-----------|---------|--------------|
| **Customer Database** | จัดเก็บข้อมูลลูกค้า | Profile, Segmentation, Tags, Health Data |
| **Message Center** | จัดการการสื่อสาร | Inbox, Broadcast, Auto-reply, Chat History |
| **Lead Management** | จัดการ Leads | Lead Tracking, Scoring, Conversion Funnel |
| **Loyalty System** | ระบบสะสมแต้ม | Points, Tiers, Rewards, Redemption |
| **Campaign Manager** | การตลาดอัตโนมัติ | Broadcast, Drip Campaigns, Segmentation |
| **Analytics & Reports** | วิเคราะห์ข้อมูล | Dashboard, Sales Reports, User Behavior |
| **AI Assistant** | ปัญญาประดิษฐ์ | Drug Consultation, Product Recommendation |
| **Appointment System** | จัดการนัดหมาย | Booking, Reminders, Video Calls |

---

## 2. Customer Journey Workflows

### 2.1 User Acquisition Flow (การได้ลูกค้าใหม่)

```
┌──────────────────────────────────────────────────────────────────┐
│                   USER ACQUISITION WORKFLOW                       │
└──────────────────────────────────────────────────────────────────┘

1. DISCOVERY PHASE
   │
   ├── User เจอ LINE OA จาก:
   │   ├── QR Code (ที่ร้าน, โฆษณา)
   │   ├── LINE Add Friend Ads
   │   ├── LINE Official Account Search
   │   └── Friend Referral / Share
   │
   ▼
2. ADD FRIEND
   │
   ├── User กด "Add Friend"
   │   │
   │   ▼
   ├── LINE Platform ส่ง "follow" event
   │   │
   │   ▼
   ├── webhook.php รับ event
   │   │
   │   ▼
   ├── handleFollow() function
   │   ├── ดึง LINE Profile (displayName, pictureUrl)
   │   ├── บันทึกลง users table
   │   ├── สร้าง member_id (เฉพาะสมาชิก)
   │   ├── Initialize points = 0
   │   └── บันทึก source tracking (ถ้ามี)
   │
   ▼
3. AUTO-TAG & SEGMENTATION
   │
   ├── CRMManager::onUserFollow()
   │   ├── Tag: "new_follower" (Auto)
   │   ├── Tag: source tag (ถ้ามี utm_source)
   │   ├── Tag: "needs_onboarding"
   │   └── Add to "New Users" segment
   │
   ▼
4. WELCOME MESSAGE
   │
   ├── ส่ง Welcome Flex Message
   │   ├── ข้อความต้อนรับ
   │   ├── แนะนำบริการ
   │   ├── Quick Actions Buttons:
   │   │   ├── [เปิดแอป] → LIFF URL
   │   │   ├── [ร้านค้า] → LIFF Shop
   │   │   ├── [สมัครสมาชิก] → LIFF Register
   │   │   └── [โปรโมชั่น] → LIFF Promotions
   │   └── ส่งผ่าน LINE Messaging API
   │
   ▼
5. RICH MENU ASSIGNMENT
   │
   ├── DynamicRichMenu::assignRichMenuByRules()
   │   ├── เช็คเงื่อนไข (new user, member, VIP)
   │   ├── เลือก Rich Menu ที่เหมาะสม
   │   └── Assign ผ่าน LINE API
   │
   ▼
6. ONBOARDING TRIGGER (Optional)
   │
   ├── Start Drip Campaign: "New User Onboarding"
   │   ├── Day 1: Welcome + Product Intro
   │   ├── Day 3: First Purchase Incentive
   │   ├── Day 7: Loyalty Program Intro
   │   └── Day 14: Video Call Service Intro
   │
   ▼
7. ANALYTICS & TRACKING
   │
   └── บันทึก event:
       ├── analytics_events: "user_follow"
       ├── Source: utm tracking
       └── Timestamp: created_at

╔════════════════════════════════════════════════════════════════╗
║  💡 CRM INSIGHT: การได้ลูกค้าใหม่                             ║
╠════════════════════════════════════════════════════════════════╣
║  • Conversion Rate: % ที่ Add Friend → Active User            ║
║  • Onboarding Rate: % ที่ทำ First Action ภายใน 24 ชม.       ║
║  • Source Attribution: ช่องทางที่มี Quality Users สูงสุด     ║
╚════════════════════════════════════════════════════════════════╝
```

### 2.2 Member Registration Flow (การสมัครสมาชิก)

```
┌──────────────────────────────────────────────────────────────────┐
│                  MEMBER REGISTRATION WORKFLOW                     │
└──────────────────────────────────────────────────────────────────┘

1. ENTRY POINT
   │
   ├── User กดปุ่ม "สมัครสมาชิก" จาก:
   │   ├── Welcome Message
   │   ├── Rich Menu
   │   └── LIFF Home Page
   │
   ▼
2. LIFF REGISTRATION PAGE (#/register)
   │
   ├── liff.init()
   ├── liff.getProfile() → ดึง LINE Profile อัตโนมัติ
   │   ├── line_user_id
   │   ├── displayName
   │   └── pictureUrl
   │
   ▼
3. FORM DISPLAY
   │
   ├── แสดงฟอร์มสมัครสมาชิก:
   │   ├── ชื่อ-นามสกุล (Pre-fill จาก LINE)
   │   ├── วันเกิด (DatePicker)
   │   ├── เพศ (Radio)
   │   ├── เบอร์โทรศัพท์ (Required)
   │   ├── Email (Optional)
   │   ├── ที่อยู่ (Textarea)
   │   │
   │   └── ข้อมูลสุขภาพ (Optional):
   │       ├── โรคประจำตัว (Checkboxes)
   │       ├── ยาที่แพ้ (Tags Input)
   │       ├── ยาที่ใช้ประจำ (Text)
   │       └── ประวัติการรักษา (Textarea)
   │
   ▼
4. DATA VALIDATION (Client-side)
   │
   ├── ตรวจสอบ Required Fields
   ├── Validate Phone Number Format
   ├── Validate Email Format (ถ้ามี)
   └── แสดง Error Messages
   │
   ▼
5. SUBMIT TO API
   │
   ├── POST /api/member.php?action=register
   │   ├── Headers: Content-Type: application/json
   │   └── Body: { line_user_id, personal_data, health_data }
   │
   ▼
6. SERVER-SIDE PROCESSING
   │
   ├── api/member.php
   │   │
   │   ├── Validate Input
   │   ├── Sanitize Data
   │   ├── Check Duplicate (phone/email)
   │   │
   │   ▼
   │   ├── Database Transaction START
   │   │   │
   │   │   ├── UPDATE users SET
   │   │   │   ├── phone = ?
   │   │   │   ├── email = ?
   │   │   │   ├── is_member = 1
   │   │   │   └── member_since = NOW()
   │   │   │
   │   │   ├── INSERT INTO members
   │   │   │   ├── user_id
   │   │   │   ├── member_id (Generate: M + timestamp)
   │   │   │   ├── full_name
   │   │   │   ├── birthdate
   │   │   │   ├── gender
   │   │   │   ├── address
   │   │   │   ├── tier = 'bronze' (default)
   │   │   │   └── created_at
   │   │   │
   │   │   └── INSERT INTO health_profiles
   │   │       ├── user_id
   │   │       ├── allergies (JSON)
   │   │       ├── chronic_diseases (JSON)
   │   │       ├── current_medications (JSON)
   │   │       └── medical_history (TEXT)
   │   │
   │   └── Database Transaction COMMIT
   │
   ▼
7. WELCOME BONUS
   │
   ├── LoyaltyPoints::addPoints()
   │   ├── user_id
   │   ├── points = 100 (Welcome Bonus)
   │   ├── reason = 'member_registration'
   │   ├── expiry_date = +1 year
   │   └── notification = true
   │
   ▼
8. AUTO-TAG UPDATE
   │
   ├── Remove Tag: "needs_onboarding"
   ├── Add Tag: "member"
   ├── Add Tag: "bronze_tier"
   └── Update Segment: "Active Members"
   │
   ▼
9. NOTIFICATION
   │
   ├── ส่ง LINE Message
   │   ├── ยินดีต้อนรับสู่ระบบสมาชิก
   │   ├── แสดง Virtual Member Card
   │   ├── แจ้งแต้มต้อนรับ 100 คะแนน
   │   └── แนะนำสิทธิพิเศษ
   │
   └── LiffMessageBridge::sendRegistrationSuccess()
   │
   ▼
10. ANALYTICS
    │
    └── บันทึก event:
        ├── analytics_events: "member_registration"
        ├── user_id
        ├── registration_source (LIFF)
        └── timestamp

╔════════════════════════════════════════════════════════════════╗
║  💡 CRM INSIGHT: Member Conversion                            ║
╠════════════════════════════════════════════════════════════════╣
║  • Follower → Member Conversion Rate                          ║
║  • Average Time to Register (from Add Friend)                 ║
║  • Health Data Completion Rate                                ║
║  • First Purchase after Registration (Days)                   ║
╚════════════════════════════════════════════════════════════════╝
```

### 2.3 Shopping & Purchase Flow (E-commerce)

```
┌──────────────────────────────────────────────────────────────────┐
│                   E-COMMERCE PURCHASE WORKFLOW                    │
└──────────────────────────────────────────────────────────────────┘

1. BROWSE PRODUCTS (#/shop)
   │
   ├── GET /api/shop-products.php?action=list
   │   ├── Response: { products: [], categories: [] }
   │   └── Display: Grid/List View
   │
   ├── User Actions:
   │   ├── Filter by Category
   │   ├── Search by Keyword
   │   ├── Sort (price, popular, new)
   │   └── View Product Details
   │
   ▼
2. VIEW PRODUCT DETAIL (#/product/:id)
   │
   ├── GET /api/shop-products.php?action=detail&id=X
   │
   ├── Display:
   │   ├── Product Images (Gallery)
   │   ├── Name, Description
   │   ├── Price, Sale Price
   │   ├── Stock Status
   │   ├── Prescription Required? (Badge)
   │   ├── Product Properties (Form, Dosage)
   │   └── Related Products
   │
   ├── AI-powered Features:
   │   ├── "ถามเภสัชกร AI" button
   │   └── Smart Recommendations
   │
   ▼
3. ADD TO CART
   │
   ├── User กด "Add to Cart" + เลือกจำนวน
   │   │
   │   ▼
   ├── Client-side:
   │   ├── store.addToCart(product, quantity)
   │   ├── Update Badge Count
   │   └── Show Toast Notification
   │   │
   │   ▼
   ├── Server-side:
   │   ├── POST /api/checkout.php?action=add_to_cart
   │   │   ├── Body: { line_user_id, product_id, quantity }
   │   │   └── INSERT INTO cart_items
   │   │
   │   └── Response: { success: true, cart_count: 3 }
   │
   ▼
4. CART PAGE (#/cart)
   │
   ├── GET /api/checkout.php?action=get_cart
   │
   ├── Display Cart Items:
   │   ├── Product Thumbnail
   │   ├── Name, Price
   │   ├── Quantity Selector (+ / -)
   │   ├── Remove Button
   │   └── Subtotal
   │
   ├── Cart Summary:
   │   ├── Items Subtotal: 450 บาท
   │   ├── Shipping: 50 บาท (ฟรีเมื่อซื้อครบ 500)
   │   ├── Discount: -50 บาท (ถ้ามี coupon)
   │   └── Total: 450 บาท
   │
   ├── Optional Features:
   │   ├── Apply Coupon Code
   │   ├── Use Points (แลกส่วนลด)
   │   └── Drug Interaction Check (ถ้ามียา)
   │       │
   │       └── POST /api/drug-interactions.php
   │           ├── Check ยาที่ซื้อ vs ยาที่กิน vs ยาที่แพ้
   │           └── แสดง Warning (ถ้ามี)
   │
   ▼
5. PROCEED TO CHECKOUT
   │
   ├── User กด "Checkout"
   │   │
   │   ├── IF not Member:
   │   │   └── Prompt: "สมัครสมาชิกเพื่อรับส่วนลด"
   │   │
   │   └── IF has Prescription Drug:
   │       └── Alert: "สินค้าต้องใช้ใบสั่งแพทย์"
   │
   ▼
6. CHECKOUT PAGE (#/checkout)
   │
   ├── Section 1: Shipping Information
   │   ├── ชื่อผู้รับ (Pre-fill from member)
   │   ├── เบอร์โทร
   │   ├── ที่อยู่จัดส่ง
   │   ├── จังหวัด, อำเภอ (Dropdown)
   │   └── รหัสไปรษณีย์
   │
   ├── Section 2: Delivery Method
   │   ├── [•] จัดส่งถึงบ้าน (50 บาท)
   │   └── [ ] รับที่ร้าน (ฟรี)
   │
   ├── Section 3: Payment Method
   │   ├── [ ] โอนเงิน (PromptPay/Bank Transfer)
   │   ├── [ ] บัตรเครดิต (ถ้าเปิดใช้)
   │   └── [•] เก็บเงินปลายทาง (COD)
   │
   ├── Section 4: Prescription Upload (ถ้ามียา Rx)
   │   ├── Upload ใบสั่งแพทย์
   │   └── รอเภสัชกรตรวจสอบ
   │
   ├── Section 5: Order Summary
   │   ├── รายการสินค้า
   │   ├── ยอดรวม
   │   └── Points ที่จะได้รับ
   │
   └── [Confirm Order] Button
   │
   ▼
7. ORDER CREATION
   │
   ├── POST /api/checkout.php?action=create_order
   │   │
   │   ├── Server Validation:
   │   │   ├── ตรวจสอบ Stock
   │   │   ├── ตรวจสอบ Prescription (ถ้าจำเป็น)
   │   │   ├── คำนวณราคาใหม่
   │   │   └── Check Fraud (ถ้ามีระบบ)
   │   │
   │   ├── Database Transaction START
   │   │   │
   │   │   ├── INSERT INTO orders
   │   │   │   ├── order_number (Generate: ORD + timestamp)
   │   │   │   ├── user_id
   │   │   │   ├── status = 'pending'
   │   │   │   ├── subtotal, shipping, discount, total
   │   │   │   ├── payment_method
   │   │   │   ├── shipping_address (JSON)
   │   │   │   └── created_at
   │   │   │
   │   │   ├── INSERT INTO order_items (foreach cart item)
   │   │   │   ├── order_id
   │   │   │   ├── product_id
   │   │   │   ├── quantity
   │   │   │   ├── price_at_purchase
   │   │   │   └── subtotal
   │   │   │
   │   │   ├── UPDATE products SET stock = stock - quantity
   │   │   │
   │   │   └── DELETE FROM cart_items WHERE user_id = ?
   │   │
   │   └── Database Transaction COMMIT
   │
   ▼
8. POST-ORDER PROCESSING
   │
   ├── Parallel Tasks:
   │   │
   │   ├── Task 1: LINE Notification
   │   │   ├── ส่ง Order Confirmation Message
   │   │   ├── รายละเอียดออเดอร์
   │   │   ├── ขั้นตอนการชำระเงิน (ถ้าโอน)
   │   │   └── ปุ่ม "ดูรายละเอียด" → LIFF Order Detail
   │   │
   │   ├── Task 2: Admin Notification
   │   │   ├── Telegram Alert
   │   │   │   ├── "New Order: ORD20260119001"
   │   │   │   ├── ลูกค้า: John Doe
   │   │   │   ├── ยอดเงิน: 450 บาท
   │   │   │   └── Link to Admin Panel
   │   │   │
   │   │   └── Email (Optional)
   │   │
   │   ├── Task 3: Inventory Update
   │   │   └── Trigger stock level check
   │   │       └── IF stock < min_stock:
   │   │           └── Alert Admin (restock needed)
   │   │
   │   └── Task 4: Analytics
   │       └── บันทึก events:
   │           ├── "order_created"
   │           ├── order_value
   │           ├── products_purchased
   │           └── payment_method
   │
   ▼
9. PAYMENT PROCESSING
   │
   ├── IF Payment Method = "โอนเงิน":
   │   │
   │   ├── User Upload Payment Slip
   │   │   ├── POST /api/orders.php?action=upload_slip
   │   │   ├── Upload Image
   │   │   └── UPDATE orders SET payment_slip_url = ?, status = 'pending_verification'
   │   │
   │   └── Admin Verification:
   │       ├── Admin ตรวจสอบสลิป
   │       ├── Approve/Reject
   │       └── UPDATE orders SET status = 'paid' / 'payment_failed'
   │
   └── IF Payment Method = "COD":
       └── status = 'pending' (รอจัดส่ง)
   │
   ▼
10. ORDER FULFILLMENT
    │
    ├── Admin กดปุ่ม "Prepare Order"
    │   └── UPDATE orders SET status = 'preparing'
    │
    ├── Admin กดปุ่ม "Ship Order"
    │   ├── UPDATE orders SET status = 'shipped', shipped_at = NOW()
    │   ├── ใส่ Tracking Number (Optional)
    │   └── ส่ง LINE Notification: "สินค้าจัดส่งแล้ว"
    │
    ├── Customer รับสินค้า
    │   └── (Manual/Auto) UPDATE orders SET status = 'delivered', delivered_at = NOW()
    │
    ▼
11. POST-PURCHASE
    │
    ├── Award Points:
    │   ├── LoyaltyPoints::addPoints()
    │   │   ├── points = order_total * 0.01 (1%)
    │   │   ├── reason = 'purchase'
    │   │   └── reference = order_id
    │   │
    │   └── ส่ง LINE: "คุณได้รับ 4 คะแนน"
    │
    ├── Request Review (7 days later):
    │   └── Drip Campaign: "Review Request"
    │
    └── Repurchase Campaign (30 days later):
        └── Broadcast: "ยาหมดแล้วหรือยัง?"

╔════════════════════════════════════════════════════════════════╗
║  💡 CRM METRICS: E-commerce Performance                       ║
╠════════════════════════════════════════════════════════════════╣
║  • Conversion Rate: Visit → Add to Cart → Purchase           ║
║  • Average Order Value (AOV)                                  ║
║  • Cart Abandonment Rate                                      ║
║  • Repeat Purchase Rate                                       ║
║  • Time to Purchase (from First Visit)                        ║
╚════════════════════════════════════════════════════════════════╝
```

### 2.4 AI Chat & Consultation Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                   AI CHAT & CONSULTATION WORKFLOW                 │
└──────────────────────────────────────────────────────────────────┘

1. AI CHAT INITIATION
   │
   ├── Entry Points:
   │   ├── ส่งข้อความ "@ai คำถาม"
   │   ├── กดปุ่ม "AI ผู้ช่วย" ใน LIFF
   │   └── กดปุ่ม "ถามเภสัชกร" ในหน้าสินค้า
   │
   ▼
2. MESSAGE ROUTING (webhook.php)
   │
   ├── ตรวจจับ AI Trigger:
   │   ├── ขึ้นต้นด้วย "@ai"
   │   ├── User อยู่ใน "ai_chat_mode"
   │   └── Keyword Match: "ถาม", "ปรึกษา"
   │
   ▼
3. SESSION MANAGEMENT
   │
   ├── ตรวจสอบ Active Session:
   │   ├── SELECT * FROM ai_chat_sessions
   │   │   WHERE user_id = ? AND is_active = 1
   │   │   AND created_at > (NOW() - INTERVAL 1 HOUR)
   │   │
   │   ├── IF found: ใช้ session_id เดิม
   │   └── IF not: สร้าง session ใหม่
   │       └── INSERT INTO ai_chat_sessions
   │           ├── session_id (UUID)
   │           ├── user_id
   │           ├── model_used ('gemini-2.0-flash')
   │           └── created_at
   │
   ▼
4. CONTEXT GATHERING
   │
   ├── ดึงข้อมูลประกอบการตัดสินใจ:
   │   │
   │   ├── A. User Profile
   │   │   └── SELECT * FROM members WHERE user_id = ?
   │   │
   │   ├── B. Health Profile
   │   │   └── SELECT * FROM health_profiles WHERE user_id = ?
   │   │       ├── allergies (ยาที่แพ้)
   │   │       ├── chronic_diseases (โรคประจำตัว)
   │   │       └── current_medications (ยาที่ใช้)
   │   │
   │   ├── C. Chat History (Last 10 messages)
   │   │   └── SELECT * FROM ai_chat_messages
   │   │       WHERE session_id = ? ORDER BY created_at DESC LIMIT 10
   │   │
   │   ├── D. Purchase History (Optional)
   │   │   └── Recent products bought
   │   │
   │   └── E. Current Cart (Optional)
   │       └── Products in cart (for recommendations)
   │
   ▼
5. SAFETY CHECK (Pre-processing)
   │
   ├── RedFlagDetector::check($message, $health_profile)
   │   │
   │   ├── Critical Symptoms Detection:
   │   │   ├── Regex Patterns:
   │   │   │   ├── "เจ็บหน้าอก" + "แรง|รุนแรง"
   │   │   │   ├── "หายใจไม่ออก|หายใจลำบาก"
   │   │   │   ├── "เลือดออก" + "มาก|ไหล|ไม่หยุด"
   │   │   │   ├── "ชัก|เป็นลม"
   │   │   │   └── "ปวดท้อง" + "รุนแรง"
   │   │   │
   │   │   └── IF detected:
   │   │       ├── flag = 'EMERGENCY'
   │   │       └── GOTO Emergency Protocol (ข้อ 6)
   │   │
   │   └── Drug Allergy Check:
   │       ├── Extract drug names from message
   │       └── IF drug IN user.allergies:
   │           ├── flag = 'ALLERGY_WARNING'
   │           └── Include in AI prompt
   │
   ▼
6. EMERGENCY PROTOCOL (ถ้า Red Flag)
   │
   ├── AI Response:
   │   ├── "อาการของคุณจำเป็นต้องพบแพทย์โดยด่วน"
   │   ├── "กรุณาไปโรงพยาบาลหรือโทร 1669"
   │   └── "ไม่แนะนำให้ซื้อยารับประทานเอง"
   │
   ├── Admin Alert:
   │   ├── Telegram Notification:
   │   │   ├── "🚨 RED FLAG DETECTED"
   │   │   ├── User: @displayName
   │   │   ├── Symptom: "เจ็บหน้าอกรุนแรง"
   │   │   └── Requires: Immediate Follow-up
   │   │
   │   └── Dashboard Alert (Real-time via WebSocket)
   │
   ├── Log Event:
   │   └── INSERT INTO red_flag_alerts
   │
   └── STOP NORMAL FLOW (ไม่ส่ง AI generate)
   │
   ▼
7. AI PROCESSING (Normal Flow)
   │
   ├── Prepare AI Prompt:
   │   │
   │   ├── System Prompt:
   │   │   """
   │   │   คุณคือเภสัชกร AI ผู้เชี่ยวชาญ
   │   │   - ให้คำแนะนำที่ปลอดภัย ไม่แทนที่แพทย์
   │   │   - ตรวจสอบ drug allergies เสมอ
   │   │   - แนะนำสินค้าที่เหมาะสม
   │   │   - ถ้าไม่แน่ใจ แนะนำให้ปรึกษาเภสัชกรจริง
   │   │   """
   │   │
   │   ├── User Context:
   │   │   """
   │   │   User Profile:
   │   │   - อายุ: 35 ปี, เพศ: หญิง
   │   │   - แพ้ยา: Penicillin
   │   │   - โรคประจำตัว: ความดันโลหิตสูง
   │   │   - ยาที่กิน: Amlodipine 5mg
   │   │   """
   │   │
   │   ├── Chat History (last 3 turns)
   │   │
   │   └── Current Message:
   │       "ปวดหัวมากค่ะ มียาอะไรแนะนำไหม"
   │
   ├── Product Search (RAG - Retrieval Augmented Generation):
   │   │
   │   ├── Extract Keywords: "ปวดหัว"
   │   │
   │   ├── Vector Search (Optional) หรือ SQL Search:
   │   │   └── SELECT * FROM products
   │   │       WHERE (name LIKE '%paracetamol%'
   │   │          OR name LIKE '%พาราเซตามอล%'
   │   │          OR category = 'pain_relief')
   │   │         AND is_active = 1
   │   │         AND stock > 0
   │   │       LIMIT 5
   │   │
   │   └── Include in AI Prompt:
   │       """
   │       Available Products:
   │       1. Paracetamol 500mg - 50 บาท
   │       2. Ibuprofen 400mg - 80 บาท
   │       (แต่ user แพ้ aspirin ให้ระวัง NSAID)
   │       """
   │
   ├── Call AI API:
   │   │
   │   ├── POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent
   │   │   ├── Headers: { "x-goog-api-key": "..." }
   │   │   └── Body: { contents: [{ parts: [{ text: full_prompt }] }] }
   │   │
   │   └── Response:
   │       """
   │       จากอาการปวดหัว แนะนำให้รับประทาน Paracetamol 500mg
   │       ครั้งละ 1-2 เม็ด ทุก 4-6 ชั่วโมง
   │
   │       ⚠️ ควรดื่มน้ำมากๆ และพักผ่อน
   │       ถ้าปวดหัวรุนแรงหรือไม่ดีขึ้นภายใน 2 วัน
   │       กรุณาพบแพทย์
   │       """
   │
   ▼
8. RESPONSE FORMATTING
   │
   ├── Parse AI Response
   │
   ├── Generate Flex Message (Optional):
   │   ├── AI Answer (Text)
   │   ├── Product Recommendations (Cards)
   │   └── Quick Actions:
   │       ├── [ดูสินค้า]
   │       ├── [เพิ่มลงตะกร้า]
   │       └── [ปรึกษาเภสัชกรจริง]
   │
   └── Store in Database:
       └── INSERT INTO ai_chat_messages
           ├── (role='user', content='ปวดหัว...')
           └── (role='assistant', content='AI response...')
   │
   ▼
9. SEND RESPONSE
   │
   ├── LINE Messaging API
   │   ├── replyMessage (ถ้ามี replyToken)
   │   └── pushMessage (ถ้า timeout)
   │
   └── Analytics:
       └── บันทึก event:
           ├── "ai_chat_message"
           ├── intent (detected)
           ├── response_time (ms)
           └── products_recommended
   │
   ▼
10. POST-CHAT ACTIONS
    │
    ├── IF user กด "เพิ่มลงตะกร้า":
    │   └── GOTO Shopping Flow (ข้อ 2.3)
    │
    ├── IF user กด "ปรึกษาเภสัชกร":
    │   └── GOTO Appointment Flow (ข้อ 2.5)
    │
    └── Session Timeout:
        └── UPDATE ai_chat_sessions SET is_active = 0
            WHERE session_id = ? AND last_message_at < (NOW() - INTERVAL 1 HOUR)

╔════════════════════════════════════════════════════════════════╗
║  💡 AI PERFORMANCE METRICS                                    ║
╠════════════════════════════════════════════════════════════════╣
║  • Response Time (avg < 3s)                                   ║
║  • Accuracy Rate (user satisfaction)                          ║
║  • Product Recommendation CTR                                 ║
║  • Conversion Rate (AI Chat → Purchase)                       ║
║  • Red Flag Detection Rate                                    ║
║  • Escalation to Human Pharmacist Rate                        ║
╚════════════════════════════════════════════════════════════════╝
```

### 2.5 Appointment & Video Call Flow

```
┌──────────────────────────────────────────────────────────────────┐
│              APPOINTMENT & VIDEO CALL WORKFLOW                    │
└──────────────────────────────────────────────────────────────────┘

1. APPOINTMENT BOOKING
   │
   ├── User เข้า LIFF #/appointments หรือ กดปุ่ม "นัดหมาย"
   │
   ▼
2. SELECT SERVICE TYPE
   │
   ├── เลือกประเภทบริการ:
   │   ├── [ ] ปรึกษาเภสัชกร (ทั่วไป)
   │   ├── [ ] ปรึกษายา / โรคเฉพาะทาง
   │   ├── [ ] ประเมินอาการ / Symptom Assessment
   │   └── [ ] Follow-up นัดเก่า
   │
   ▼
3. SELECT PHARMACIST
   │
   ├── GET /api/pharmacists.php?action=available
   │
   ├── แสดงรายชื่อเภสัชกร:
   │   ├── รูปโปรไฟล์
   │   ├── ชื่อ-นามสกุล
   │   ├── ความเชี่ยวชาญ
   │   ├── Rating ⭐⭐⭐⭐⭐
   │   └── Status (Online/Offline/Busy)
   │
   └── User เลือกเภสัชกร
   │
   ▼
4. SELECT DATE & TIME
   │
   ├── GET /api/appointments.php?action=available_slots
   │   └── Response: { dates: [], time_slots: [] }
   │
   ├── Calendar View:
   │   ├── แสดงวันที่ว่าง (highlighted)
   │   └── Disable วันที่เต็ม
   │
   ├── Time Slots:
   │   ├── 09:00-09:30 [จองได้]
   │   ├── 09:30-10:00 [เต็ม]
   │   ├── 10:00-10:30 [จองได้]
   │   └── ...
   │
   └── User เลือกวันและเวลา
   │
   ▼
5. FILL REASON & NOTES
   │
   ├── Form:
   │   ├── เหตุผลการนัดหมาย (Textarea)
   │   ├── อาการ / คำถาม (Optional)
   │   └── ไฟล์แนบ (รูปถ่าย, เอกสาร)
   │
   ▼
6. CONFIRM BOOKING
   │
   ├── แสดง Summary:
   │   ├── บริการ: ปรึกษาเภสัชกร
   │   ├── เภสัชกร: ภญ. สมหญิง ใจดี
   │   ├── วันที่: 20 ม.ค. 2026
   │   ├── เวลา: 10:00-10:30
   │   └── ค่าบริการ: ฟรี (หรือ 200 บาท)
   │
   ├── User กด "ยืนยันการนัดหมาย"
   │   │
   │   ▼
   ├── POST /api/appointments.php?action=create
   │   │
   │   ├── INSERT INTO appointments
   │   │   ├── appointment_id (Generate)
   │   │   ├── user_id
   │   │   ├── pharmacist_id
   │   │   ├── service_type
   │   │   ├── appointment_date
   │   │   ├── appointment_time
   │   │   ├── duration (30 mins)
   │   │   ├── reason, notes
   │   │   ├── status = 'confirmed'
   │   │   └── created_at
   │   │
   │   └── Response: { success: true, appointment_id: "APT..." }
   │
   ▼
7. NOTIFICATIONS
   │
   ├── Send to User:
   │   ├── LINE Message:
   │   │   ├── "✅ นัดหมายสำเร็จ"
   │   │   ├── รายละเอียด
   │   │   ├── วิธีเข้า Video Call
   │   │   └── ปุ่ม "Add to Calendar"
   │   │
   │   └── Calendar Event (ถ้า integrate)
   │
   └── Send to Pharmacist:
       ├── LINE Message / Telegram
       └── Dashboard Notification
   │
   ▼
8. REMINDER SYSTEM (Cron Job)
   │
   ├── cron/appointment_reminder.php (runs every 30 mins)
   │   │
   │   ├── Query Upcoming Appointments:
   │   │   └── SELECT * FROM appointments
   │   │       WHERE status = 'confirmed'
   │   │         AND appointment_datetime BETWEEN
   │   │             NOW() AND NOW() + INTERVAL 24 HOUR
   │   │         AND reminder_24h_sent = 0
   │   │
   │   ├── Send Reminder:
   │   │   ├── 24 ชม. ก่อน: "เตือนนัดหมายพรุ่งนี้"
   │   │   ├── 1 ชม. ก่อน: "เตือนนัดหมายอีก 1 ชม."
   │   │   └── 10 นาที ก่อน: "เตรียมพร้อม + ลิงก์ Video Call"
   │   │
   │   └── UPDATE appointments SET reminder_XX_sent = 1
   │
   ▼
9. VIDEO CALL SESSION (เมื่อถึงเวลา)
   │
   ├── User กดลิงก์จาก LINE → เปิด LIFF #/video-call/:id
   │
   ├── GET /api/video-call.php?action=join&appointment_id=X
   │   │
   │   ├── Validate:
   │   │   ├── Appointment exists
   │   │   ├── User authorized
   │   │   ├── Time slot valid (±10 mins)
   │   │   └── Pharmacist ready
   │   │
   │   └── Response:
   │       ├── room_id (Generate or use WebRTC room)
   │       ├── access_token (for video SDK)
   │       └── pharmacist_info
   │
   ├── Initialize Video Call:
   │   ├── Request Permissions (Camera, Mic)
   │   ├── Initialize WebRTC / Video SDK (Agora, Twilio, etc.)
   │   ├── Connect to Room
   │   └── Display Video Streams (User + Pharmacist)
   │
   ├── Call Features:
   │   ├── Video On/Off Toggle
   │   ├── Mute/Unmute
   │   ├── Chat Panel (Text)
   │   ├── Screen Share (Optional)
   │   └── End Call Button
   │
   ▼
10. DURING CALL - PHARMACIST ACTIONS
    │
    ├── Pharmacist Interface (Admin Panel or LIFF):
    │   ├── View User Health Profile
    │   ├── View Purchase History
    │   ├── Take Notes (บันทึกการปรึกษา)
    │   ├── Prescribe Medication (ถ้ามีระบบ)
    │   └── Recommend Products
    │
    ▼
11. END CALL
    │
    ├── User หรือ Pharmacist กด "End Call"
    │   │
    │   ├── POST /api/video-call.php?action=end
    │   │   ├── call_id
    │   │   ├── duration (actual)
    │   │   └── end_reason
    │   │
    │   └── UPDATE appointments SET
    │       ├── status = 'completed'
    │       ├── actual_start_time
    │       ├── actual_end_time
    │       └── duration
    │
    ├── Save Call Record:
    │   └── INSERT INTO video_call_records
    │       ├── appointment_id
    │       ├── room_id
    │       ├── duration
    │       └── recording_url (ถ้าบันทึก - ต้องขออนุญาต)
    │
    └── Save Consultation Notes:
        └── INSERT INTO consultation_notes
            ├── appointment_id
            ├── pharmacist_notes
            ├── prescriptions (JSON)
            └── follow_up_required
    │
    ▼
12. POST-CALL ACTIONS
    │
    ├── Send to User:
    │   ├── LINE Message:
    │   │   ├── "ขอบคุณที่ใช้บริการ"
    │   │   ├── สรุปการปรึกษา (ถ้ามี)
    │   │   ├── ยาที่แนะนำ (Carousel)
    │   │   ├── ปุ่ม "ซื้อยา" → LIFF Cart
    │   │   └── ปุ่ม "ให้คะแนน" → LIFF Review
    │   │
    │   └── Award Points:
    │       └── LoyaltyPoints::addPoints(10, 'video_consultation')
    │
    ├── Request Rating (ต่อมา):
    │   └── POST /api/appointments.php?action=rate
    │       ├── rating (1-5 stars)
    │       ├── review (text)
    │       └── UPDATE pharmacists SET avg_rating
    │
    └── Follow-up:
        └── IF follow_up_required:
            └── Drip Campaign: "Follow-up Reminder" (7 days)

╔════════════════════════════════════════════════════════════════╗
║  💡 TELEMEDICINE METRICS                                      ║
╠════════════════════════════════════════════════════════════════╣
║  • Booking Conversion Rate                                    ║
║  • Show-up Rate (% ที่มาตามนัด)                              ║
║  • Average Consultation Duration                              ║
║  • Patient Satisfaction Score                                 ║
║  • Prescription Fill Rate (% ที่ซื้อยาหลังปรึกษา)            ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 3. Admin Operation Workflows

### 3.1 Message Management (Inbox)

```
┌──────────────────────────────────────────────────────────────────┐
│                      ADMIN INBOX WORKFLOW                         │
└──────────────────────────────────────────────────────────────────┘

1. INBOX VIEW (inbox-v2.php)
   │
   ├── Page Load:
   │   │
   │   ├── GET /api/inbox.php?action=get_conversations
   │   │   ├── Params: line_account_id, filter, search, page
   │   │   └── Response: { conversations: [], unread_count: 5 }
   │   │
   │   └── Display:
   │       ├── Left Panel: Conversation List
   │       │   ├── User Avatar + Name
   │       │   ├── Last Message Preview
   │       │   ├── Timestamp
   │       │   ├── Unread Badge (🔴 2)
   │       │   └── Tags (VIP, Member)
   │       │
   │       └── Right Panel: Chat Window (empty until select)
   │
   ├── Filters:
   │   ├── [All] [Unread] [Assigned to Me] [Archived]
   │   └── Search: ค้นหาจากชื่อหรือข้อความ
   │
   ▼
2. SELECT CONVERSATION
   │
   ├── Admin กดเลือกลูกค้า
   │   │
   │   ├── WebSocket: join room
   │   │   └── ws.send({ action: 'join', user_id: 'xxx' })
   │   │
   │   ├── GET /api/inbox.php?action=get_messages
   │   │   ├── Params: line_user_id, limit=50
   │   │   └── Response: { messages: [], user_info: {} }
   │   │
   │   └── Display:
   │       ├── User Info Header:
   │       │   ├── Name, Avatar
   │       │   ├── Tags (แก้ไขได้)
   │       │   ├── Member Status
   │       │   └── Actions: [View Profile] [Assign] [Block]
   │       │
   │       └── Chat History (Scrollable):
   │           ├── Message Bubbles (User = left, Admin = right)
   │           ├── Timestamps
   │           ├── Read Status (✓✓)
   │           └── Load More (ถ้ามีเก่ากว่า)
   │
   ▼
3. MARK AS READ
   │
   ├── POST /api/inbox.php?action=mark_read
   │   ├── Body: { line_user_id: 'xxx' }
   │   └── UPDATE messages SET is_read = 1
   │       WHERE line_user_id = ? AND is_from_user = 1
   │
   └── Update UI: เอา Unread Badge ออก
   │
   ▼
4. SEND REPLY
   │
   ├── Admin พิมพ์ข้อความใน Input Box
   │
   ├── Support:
   │   ├── Text Message
   │   ├── Image Upload
   │   ├── File Upload
   │   ├── Emoji Picker
   │   ├── Quick Reply Templates
   │   └── Product Picker (แนบสินค้า)
   │
   ├── กด Send
   │   │
   │   ▼
   ├── POST /api/inbox.php?action=send_message
   │   │
   │   ├── Validate:
   │   │   ├── ข้อความไม่ว่าง
   │   │   ├── ไฟล์ size < 10MB
   │   │   └── Admin authorized
   │   │
   │   ├── Database Transaction:
   │   │   └── INSERT INTO messages
   │   │       ├── line_user_id
   │   │       ├── line_account_id
   │   │       ├── message_text / image_url
   │   │       ├── is_from_user = 0 (from admin)
   │   │       ├── sent_by_admin_id
   │   │       └── created_at
   │   │
   │   ├── LINE API Call:
   │   │   └── POST https://api.line.me/v2/bot/message/push
   │   │       ├── Headers: { "Authorization": "Bearer TOKEN" }
   │   │       └── Body: {
   │   │           "to": "U1234567...",
   │   │           "messages": [{
   │   │               "type": "text",
   │   │               "text": "ข้อความจากแอดมิน"
   │   │           }]
   │   │       }
   │   │
   │   └── Response: { success: true, message_id: 'xxx' }
   │
   ├── WebSocket Broadcast:
   │   └── ws.broadcast({
   │       action: 'new_message',
   │       room: 'user_xxx',
   │       message: { ... }
   │   })
   │
   └── UI Update:
       ├── แสดงข้อความใน Chat Window (Optimistic UI)
       ├── Disable Send button ชั่วคราว
       └── แสดง ✓ เมื่อส่งสำเร็จ
   │
   ▼
5. ADVANCED ACTIONS
   │
   ├── A. Assign Conversation
   │   ├── กด "Assign" button
   │   ├── เลือก Admin / Pharmacist
   │   └── POST /api/inbox.php?action=assign
   │       ├── INSERT INTO conversation_assignments
   │       └── Notify assignee
   │
   ├── B. Add/Remove Tags
   │   ├── กด Tag icon
   │   ├── เลือก Tags (VIP, Member, Lead, etc.)
   │   └── POST /api/user-tags.php?action=update
   │
   ├── C. Add Notes
   │   ├── กด "Add Note" button
   │   ├── พิมพ์บันทึกภายใน (ลูกค้าไม่เห็น)
   │   └── POST /api/inbox.php?action=add_note
   │       └── INSERT INTO user_notes
   │
   ├── D. View User Profile
   │   ├── กด "View Profile"
   │   └── Modal:
   │       ├── ข้อมูลส่วนตัว
   │       ├── Purchase History
   │       ├── Loyalty Points
   │       ├── Appointments
   │       └── Activity Log
   │
   └── E. Block User
       ├── กด "Block" button
       └── UPDATE users SET is_blocked = 1

╔════════════════════════════════════════════════════════════════╗
║  💡 INBOX PERFORMANCE METRICS                                 ║
╠════════════════════════════════════════════════════════════════╣
║  • Average Response Time (ควรต่ำกว่า 5 นาที)                 ║
║  • Messages per Conversation                                  ║
║  • Resolution Rate (% ที่ปิด conversation)                   ║
║  • Admin Workload Distribution                                ║
╚════════════════════════════════════════════════════════════════╝
```

### 3.2 Broadcast Campaign Workflow

```
┌──────────────────────────────────────────────────────────────────┐
│                      BROADCAST CAMPAIGN WORKFLOW                  │
└──────────────────────────────────────────────────────────────────┘

1. CREATE CAMPAIGN
   │
   ├── Admin > Broadcast > "Create New Campaign"
   │
   ▼
2. STEP 1: Campaign Settings
   │
   ├── Form:
   │   ├── Campaign Name: "Flash Sale - Jan 2026"
   │   ├── Campaign Type:
   │   │   ├── [ ] One-time Broadcast
   │   │   ├── [•] Scheduled Broadcast
   │   │   └── [ ] Recurring Broadcast
   │   │
   │   ├── Schedule (ถ้าเลือก Scheduled):
   │   │   ├── Date: 2026-01-25
   │   │   └── Time: 10:00 AM
   │   │
   │   └── Internal Notes: (optional)
   │
   ▼
3. STEP 2: Target Audience (Segmentation)
   │
   ├── Targeting Options:
   │   │
   │   ├── A. All Users
   │   │   └── [ ] ส่งให้ทุกคน (ไม่แนะนำ)
   │   │
   │   ├── B. By Tags
   │   │   ├── [✓] VIP
   │   │   ├── [✓] Member
   │   │   └── [ ] Lead
   │   │
   │   ├── C. By Segment
   │   │   ├── [ ] Active Users (7 วันล่าสุด)
   │   │   ├── [•] Purchased Before (30 วันล่าสุด)
   │   │   └── [ ] Never Purchased
   │   │
   │   ├── D. By Behavior
   │   │   ├── [ ] Abandoned Cart (มีสินค้าในตะกร้า)
   │   │   ├── [ ] Birthday This Month
   │   │   └── [ ] Points Expiring Soon
   │   │
   │   └── E. Custom Query (Advanced)
   │       └── SQL Query Builder
   │
   ├── Preview Audience:
   │   ├── Estimated Recipients: 1,247 users
   │   ├── FREE Limit: 500/month
   │   └── Cost: ฟรี (ใน limit) หรือ 0.15 บาท/ข้อความ
   │
   └── กด "Next"
   │
   ▼
4. STEP 3: Message Content
   │
   ├── Message Type Selection:
   │   │
   │   ├── A. Text Message
   │   │   └── Textarea + Emoji Picker
   │   │
   │   ├── B. Image Message
   │   │   ├── Upload Image (max 10MB)
   │   │   └── Alt Text
   │   │
   │   ├── C. Flex Message (แนะนำ)
   │   │   ├── Template Library:
   │   │   │   ├── Product Catalog
   │   │   │   ├── Promotion Card
   │   │   │   ├── Coupon
   │   │   │   └── Event Invitation
   │   │   │
   │   │   └── Flex Message Simulator (LINE Flex Simulator)
   │   │
   │   └── D. Video / Audio (ถ้ารองรับ)
   │
   ├── Personalization:
   │   ├── Variable Tags:
   │   │   ├── {{name}} → ชื่อลูกค้า
   │   │   ├── {{points}} → คะแนนปัจจุบัน
   │   │   └── {{tier}} → ระดับสมาชิก
   │   │
   │   └── Preview with Sample Data
   │
   ├── Call-to-Action:
   │   ├── [แก้ไข] Add Buttons:
   │   │   ├── "ดูสินค้า" → LIFF URL
   │   │   ├── "ใช้คูปอง" → LIFF Coupon
   │   │   └── "แชร์เพื่อน" → Share Target Picker
   │   │
   │   └── Link Tracking:
   │       └── [✓] Enable UTM tracking
   │
   ▼
5. STEP 4: Review & Test
   │
   ├── Campaign Summary:
   │   ├── Target: 1,247 users
   │   ├── Schedule: 25 Jan 2026, 10:00 AM
   │   ├── Message Type: Flex Message
   │   └── Estimated Cost: ฟรี (ใน limit)
   │
   ├── Test Send:
   │   ├── ใส่ LINE User ID ของตัวเอง
   │   ├── กด "Send Test"
   │   └── ตรวจสอบข้อความใน LINE
   │
   └── Final Check:
       ├── [✓] Message preview correct
       ├── [✓] Target audience correct
       ├── [✓] Schedule correct
       └── กด "Launch Campaign"
   │
   ▼
6. CAMPAIGN EXECUTION
   │
   ├── IF One-time / Now:
   │   │
   │   ├── POST /api/broadcast.php?action=send_now
   │   │   │
   │   │   ├── Get Target Users:
   │   │   │   └── SELECT user_id, line_user_id FROM users
   │   │   │       WHERE (tags, segments, behavior conditions)
   │   │   │         AND is_blocked = 0
   │   │   │
   │   │   ├── Batch Processing (chunks of 100):
   │   │   │   │
   │   │   │   ├── FOREACH batch:
   │   │   │   │   │
   │   │   │   │   ├── Personalize Message (replace {{vars}})
   │   │   │   │   │
   │   │   │   │   ├── LINE Multicast API:
   │   │   │   │   │   └── POST /v2/bot/message/multicast
   │   │   │   │   │       ├── to: [user_ids...] (max 500)
   │   │   │   │   │       └── messages: [...]
   │   │   │   │   │
   │   │   │   │   ├── Log Results:
   │   │   │   │   │   └── INSERT INTO broadcast_logs
   │   │   │   │   │       ├── campaign_id, user_id
   │   │   │   │   │       ├── status ('sent', 'failed')
   │   │   │   │   │       └── sent_at
   │   │   │   │   │
   │   │   │   │   └── Rate Limiting (ป้องกัน spam)
   │   │   │   │       └── sleep(0.5) between batches
   │   │   │   │
   │   │   │   └── Update Campaign Status:
   │   │   │       └── UPDATE broadcasts SET
   │   │   │           status = 'completed',
   │   │   │           total_sent = 1200,
   │   │   │           total_failed = 47
   │   │   │
   │   │   └── Send Admin Notification:
   │   │       └── Telegram: "Campaign completed: 1,200 sent"
   │   │
   │   └── ELSE IF Scheduled:
   │       │
   │       └── INSERT INTO broadcast_queue
   │           ├── campaign_id
   │           ├── scheduled_at
   │           ├── status = 'pending'
   │           └── Cron Job จะ process ทีหลัง
   │
   ▼
7. CRON JOB (Scheduled Broadcasts)
   │
   ├── cron/process_broadcast_queue.php (ทุก 5 นาที)
   │   │
   │   ├── SELECT * FROM broadcast_queue
   │   │   WHERE status = 'pending'
   │   │     AND scheduled_at <= NOW()
   │   │   LIMIT 10
   │   │
   │   └── FOREACH campaign:
   │       ├── Execute broadcast (same as Step 6)
   │       └── UPDATE broadcast_queue SET status = 'completed'
   │
   ▼
8. ANALYTICS & TRACKING
   │
   ├── Track Events:
   │   ├── broadcast_sent
   │   ├── broadcast_delivered (webhook)
   │   ├── broadcast_clicked (UTM tracking)
   │   └── broadcast_converted (purchase)
   │
   ├── Campaign Report:
   │   ├── Sent: 1,200
   │   ├── Delivered: 1,150 (95.8%)
   │   ├── Clicked: 345 (30%)
   │   ├── Purchased: 87 (25.2% of clicks)
   │   └── Revenue: 43,500 บาท
   │
   └── A/B Testing (Optional):
       ├── แยก audience เป็น 2 กลุ่ม
       ├── ส่งข้อความต่างกัน (A vs B)
       └── เปรียบเทียบ CTR, Conversion

╔════════════════════════════════════════════════════════════════╗
║  💡 BROADCAST BEST PRACTICES                                  ║
╠════════════════════════════════════════════════════════════════╣
║  • Segment อย่างชัดเจน (ไม่ส่งแบบ mass)                      ║
║  • Personalize ข้อความ (ใช้ {{name}})                        ║
║  • Test ก่อนส่งเสมอ                                           ║
║  • Monitor delivery rate และ block rate                      ║
║  • ไม่ส่งบ่อยเกินไป (max 2-3 ครั้ง/สัปดาห์)                  ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 4. Automation & Background Jobs

### 4.1 Cron Jobs Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                       AUTOMATION WORKFLOWS                        │
└──────────────────────────────────────────────────────────────────┘

1. MEDICATION REMINDERS (Every 15 mins)
   cron/medication_reminder.php
   │
   ├── Query reminders due now:
   │   └── SELECT * FROM medication_reminders
   │       WHERE next_reminder <= NOW()
   │         AND is_active = 1
   │
   ├── FOREACH reminder:
   │   ├── Send LINE Push Message:
   │   │   └── "⏰ ถึงเวลาทานยา: [ชื่อยา] [ขนาด]"
   │   │
   │   ├── Calculate next reminder time
   │   └── UPDATE medication_reminders SET
   │       next_reminder = CALCULATE_NEXT(),
   │       last_sent = NOW()
   │
   └── Log: sent X reminders

2. APPOINTMENT REMINDERS (Every 30 mins)
   cron/appointment_reminder.php
   │
   ├── Query upcoming appointments:
   │   │
   │   ├── 24h before:
   │   │   └── WHERE appointment_datetime BETWEEN
   │   │       NOW() + INTERVAL 23 HOUR
   │   │       AND NOW() + INTERVAL 25 HOUR
   │   │
   │   ├── 1h before:
   │   │   └── WHERE appointment_datetime BETWEEN
   │   │       NOW() + INTERVAL 50 MINUTE
   │   │       AND NOW() + INTERVAL 70 MINUTE
   │   │
   │   └── 10 mins before:
   │       └── WHERE appointment_datetime BETWEEN
   │           NOW() + INTERVAL 5 MINUTE
   │           AND NOW() + INTERVAL 15 MINUTE
   │
   ├── Send appropriate reminder
   └── Mark as sent

3. POINTS EXPIRY REMINDER (Daily 10:00)
   cron/reward_expiry_reminder.php
   │
   ├── Query points expiring soon:
   │   └── SELECT * FROM points_transactions
   │       WHERE expiry_date BETWEEN
   │         NOW() AND NOW() + INTERVAL 30 DAY
   │       GROUP BY user_id
   │
   ├── FOREACH user:
   │   └── Send: "แต้มของคุณจะหมดอายุใน 30 วัน"
   │
   └── Log

4. DRIP CAMPAIGNS (Every 10 mins)
   cron/process_drip_campaigns.php
   │
   ├── Query active campaigns:
   │   └── SELECT * FROM drip_campaigns WHERE is_active = 1
   │
   ├── FOREACH campaign:
   │   ├── Get subscribers:
   │   │   └── JOIN drip_subscribers WHERE next_send <= NOW()
   │   │
   │   ├── Get next message in sequence
   │   │
   │   ├── Send message
   │   │
   │   └── UPDATE next_send time or mark completed
   │
   └── Log

5. SYNC WORKER (Every 1 min)
   cron/sync_worker.php
   │
   ├── Sync Products from External API (CNY):
   │   ├── GET https://external-api.com/products
   │   ├── Compare with local DB
   │   ├── UPDATE/INSERT products
   │   └── Log sync results
   │
   ├── Process background queues:
   │   ├── Email queue
   │   ├── Export jobs
   │   └── Report generation
   │
   └── Health check

6. DATABASE MAINTENANCE (Monthly)
   database/maintenance.sql
   │
   ├── Clean old data:
   │   ├── DELETE old messages (> 6 months)
   │   ├── DELETE old sessions (> 3 months)
   │   └── DELETE old analytics (> 1 year)
   │
   ├── OPTIMIZE TABLES
   └── UPDATE statistics

╔════════════════════════════════════════════════════════════════╗
║  💡 AUTOMATION MONITORING                                     ║
╠════════════════════════════════════════════════════════════════╣
║  • ตรวจสอบ cron logs ทุกวัน                                   ║
║  • Alert ถ้า job ไม่ทำงาน > 2 รอบ                            ║
║  • Monitor queue backlog                                      ║
║  • Track error rate (< 1%)                                    ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 5. Data Flow Architecture

### 5.1 System Integration Diagram

```
┌────────────────────────────────────────────────────────────────┐
│                     DATA FLOW ARCHITECTURE                      │
└────────────────────────────────────────────────────────────────┘

[LINE Platform]
     │
     │ Webhook Events (POST)
     ▼
┌─────────────────┐
│  webhook.php    │ ◄─── Signature Validation
└────────┬────────┘
         │
         ├─ follow event ──────────► handleFollow()
         ├─ unfollow event ────────► handleUnfollow()
         ├─ message event ─────────► handleMessage()
         ├─ postback event ────────► handlePostback()
         └─ beacon event ──────────► handleBeacon()
                │
                ▼
         ┌──────────────┐
         │ Auto Reply   │
         │ Priority     │
         └──────┬───────┘
                │
       ┌────────┴────────┐
       │                 │
       ▼                 ▼
  [AI Mode]        [Keyword Match]
       │                 │
       └────────┬────────┘
                ▼
         ┌──────────────┐
         │  Database    │
         │  Save        │
         └──────────────┘
                │
                ▼
         ┌──────────────┐
         │  WebSocket   │ ──► Real-time to Admin
         │  Broadcast   │
         └──────────────┘

[LIFF Application]
     │
     │ API Calls
     ▼
┌─────────────────┐
│   /api/*.php    │
└────────┬────────┘
         │
         ├─ checkout.php ──────► Orders
         ├─ member.php ────────► Members
         ├─ points.php ────────► Loyalty
         ├─ ai-chat.php ───────► AI Service
         └─ appointments.php ──► Bookings
                │
                ▼
         ┌──────────────┐
         │  Services    │
         │  Classes     │
         └──────┬───────┘
                │
       ┌────────┴────────────┐
       │                     │
       ▼                     ▼
 [LineAPI.php]        [GeminiAI.php]
       │                     │
       │                     ▼
       │              [Google AI API]
       │
       ▼
 [LINE Messaging API]

[Admin Panel]
     │
     │ Admin Actions
     ▼
┌─────────────────┐
│  Admin Pages    │
└────────┬────────┘
         │
         ├─ messages.php ──────► Inbox
         ├─ broadcast.php ─────► Campaigns
         ├─ admin-users.php ───► Users Mgmt
         └─ shop/*.php ────────► Products
                │
                ▼
         ┌──────────────┐
         │  Database    │ ◄─── CRUD Operations
         └──────┬───────┘
                │
                ▼
         ┌──────────────┐
         │  WebSocket   │ ──► Sync with LIFF
         │  Updates     │
         └──────────────┘

[Background Jobs]
     │
     │ Scheduled (Cron)
     ▼
┌─────────────────┐
│  cron/*.php     │
└────────┬────────┘
         │
         ├─ Reminders
         ├─ Sync Jobs
         ├─ Campaigns
         └─ Maintenance
                │
                ▼
         ┌──────────────┐
         │  LINE Push   │
         │  Messages    │
         └──────────────┘
```

### 5.2 Real-time Communication (WebSocket)

```
┌────────────────────────────────────────────────────────────────┐
│                    WEBSOCKET ARCHITECTURE                       │
└────────────────────────────────────────────────────────────────┘

Client (Admin Inbox)            WebSocket Server              Database
       │                               │                          │
       │ 1. Connect                    │                          │
       ├──────────────────────────────>│                          │
       │                               │                          │
       │ 2. Authenticate               │                          │
       │    {type:'auth',token:'...'}  │                          │
       ├──────────────────────────────>│                          │
       │                               │ 3. Validate token        │
       │                               ├─────────────────────────>│
       │                               │<─────────────────────────┤
       │                               │  User validated          │
       │                               │                          │
       │ 4. Join room                  │                          │
       │    {type:'join',user_id}      │                          │
       ├──────────────────────────────>│                          │
       │                               │ Subscribe to user_xxx    │
       │                               │                          │
       │<──────────────────────────────┤                          │
       │    {type:'joined',room}       │                          │
       │                               │                          │
       │                               │                          │
  [New Message arrives via webhook.php]                          │
       │                               │<──  5. webhook inserts   │
       │                               │     new message          │
       │                               │                          │
       │                               │ 6. Emit to room          │
       │<──────────────────────────────┤    subscribers           │
       │    {type:'message',data}      │                          │
       │                               │                          │
       │ 7. Display in UI              │                          │
       │    (Real-time update)         │                          │
       │                               │                          │
       │ 8. Mark as read               │                          │
       ├──────────────────────────────>│                          │
       │                               ├─────────────────────────>│
       │                               │  UPDATE messages         │
       │                               │                          │
       │                               │ 9. Broadcast read status │
       │<──────────────────────────────┤                          │
       │    {type:'read',message_ids}  │                          │
       │                               │                          │
```

---

## 6. Integration Points

### 6.1 External Integrations

| Service | Purpose | Integration Method | Endpoint |
|---------|---------|-------------------|----------|
| **LINE Messaging API** | ส่ง/รับข้อความ | REST API | `api.line.me` |
| **LINE LIFF** | Web App in LINE | LIFF SDK | `liff.line.me` |
| **Google Gemini AI** | AI Chat, Analysis | REST API | `generativelanguage.googleapis.com` |
| **OpenAI** | Alternative AI | REST API | `api.openai.com` |
| **Telegram Bot** | Admin Alerts | REST API | `api.telegram.org` |
| **PromptPay** | Payment Gateway | QR Code | - |
| **CNY Pharmacy API** | Product Sync | REST API | Custom |
| **Google Analytics** | Web Analytics | gtag.js | - |

### 6.2 Webhook Events Handling

```php
// webhook.php - Event Router
$event = $input['events'][0];

switch ($event['type']) {
    case 'follow':
        handleFollow($event);
        break;

    case 'unfollow':
        handleUnfollow($event);
        break;

    case 'message':
        handleMessage($event);
        break;

    case 'postback':
        handlePostback($event);
        break;

    case 'beacon':
        handleBeacon($event);
        break;

    case 'accountLink':
        handleAccountLink($event);
        break;

    case 'memberJoined':
        handleMemberJoined($event);
        break;

    case 'memberLeft':
        handleMemberLeft($event);
        break;
}
```

---

## 7. CRM Features Deep Dive

### 7.1 Customer Segmentation Matrix

```
┌────────────────────────────────────────────────────────────────┐
│                    SEGMENTATION STRATEGY                        │
└────────────────────────────────────────────────────────────────┘

Behavioral Segments:
├── New Users (0-7 days since follow)
│   └── Goal: Convert to Member
├── Active Users (purchased in last 30 days)
│   └── Goal: Increase frequency
├── At-Risk (no activity 60-90 days)
│   └── Goal: Re-engage with offer
└── Churned (no activity > 90 days)
    └── Goal: Win-back campaign

Value-based Segments:
├── VIP (top 10% by revenue)
│   └── Action: Exclusive offers, priority support
├── High-Value (top 30%)
│   └── Action: Loyalty rewards, upsell
├── Medium-Value (30-70%)
│   └── Action: Increase purchase frequency
└── Low-Value (bottom 30%)
    └── Action: First purchase incentive

Lifecycle Segments:
├── Leads (followers, not members)
├── New Members (0-30 days)
├── Growing (31-90 days, increasing value)
├── Loyal (90+ days, consistent purchases)
└── Champions (advocates, high LTV)

Health-based Segments:
├── Chronic Disease Patients
│   └── Medication adherence programs
├── Allergy-prone
│   └── Safety alerts, product filters
└── Elderly Care
    └── Reminders, easy ordering
```

### 7.2 Lead Scoring System

```
┌────────────────────────────────────────────────────────────────┐
│                      LEAD SCORING MODEL                         │
└────────────────────────────────────────────────────────────────┘

Engagement Score (0-100):

Profile Completeness:
├── Has phone number: +10
├── Has email: +5
├── Has birthday: +5
├── Health profile filled: +15
└── Address saved: +5

Activity Score:
├── Opened LIFF: +5 (first time +10)
├── Viewed products: +3 per session
├── Added to cart: +10
├── Completed purchase: +20
├── Used AI chat: +7
└── Booked appointment: +15

Interaction Score:
├── Replied to broadcast: +8
├── Clicked link: +5
├── Shared with friend: +12
├── Left review: +10
└── Referred friend: +15

Recency:
├── Active today: +10
├── Active this week: +5
├── Active this month: +2
└── No activity 30+ days: -5

Lead Temperature:
├── 🔥 Hot (80-100): Ready to convert
├── 🌡️ Warm (50-79): Nurture with content
├── ❄️ Cold (0-49): Re-engagement needed
```

### 7.3 Customer Lifetime Value (CLV)

```sql
-- Calculate CLV
SELECT
    u.user_id,
    u.display_name,
    COUNT(DISTINCT o.order_id) as total_orders,
    SUM(o.total) as total_revenue,
    AVG(o.total) as avg_order_value,
    DATEDIFF(NOW(), u.created_at) as days_as_customer,
    SUM(o.total) / COUNT(DISTINCT o.order_id) as clv_per_order,
    (SUM(o.total) / COUNT(DISTINCT o.order_id)) *
        (365 / (DATEDIFF(NOW(), u.created_at) / COUNT(DISTINCT o.order_id)))
        as predicted_annual_value
FROM users u
LEFT JOIN orders o ON u.user_id = o.user_id
WHERE o.status = 'completed'
GROUP BY u.user_id
ORDER BY predicted_annual_value DESC;
```

---

## 📊 CRM Success Metrics Dashboard

### Key Performance Indicators (KPIs)

**Acquisition Metrics:**
- Follower Growth Rate
- Cost Per Acquisition (CPA)
- Source Attribution Mix

**Engagement Metrics:**
- Daily Active Users (DAU)
- Messages per User
- LIFF Session Duration
- AI Chat Usage Rate

**Conversion Metrics:**
- Follower → Member Rate
- Member → First Purchase Rate
- Repeat Purchase Rate
- Average Order Value (AOV)

**Retention Metrics:**
- Churn Rate
- Customer Lifetime Value (CLV)
- Loyalty Program Participation
- NPS (Net Promoter Score)

**Revenue Metrics:**
- Monthly Recurring Revenue (MRR)
- Revenue per User
- Gross Margin
- Customer Acquisition Cost (CAC) vs LTV

---

**🎯 สรุป:** ระบบ CRM ของ LINE Telepharmacy ครอบคลุมทุก Customer Touchpoint ตั้งแต่การได้ลูกค้าใหม่ การดูแลรักษาความสัมพันธ์ ไปจนถึงการเพิ่มมูลค่าลูกค้าตลอดชีวิต โดยใช้ Automation, AI และ Data Analytics เป็นเครื่องมือขับเคลื่อน

---

*Document Version: 2.5*
*Last Updated: มกราคม 2026*
*For: LINE Telepharmacy CRM Platform*
