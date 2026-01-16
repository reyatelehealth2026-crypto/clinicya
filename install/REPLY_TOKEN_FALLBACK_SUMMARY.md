# Reply Token Fallback - Implementation Summary

## ปัญหาที่พบ
Reply Token ของ LINE มีข้อจำกัด:
- **อายุ 30 วินาที** - หมดอายุหลังจากได้รับ webhook event
- **ใช้ได้ครั้งเดียว** - ถ้าเรียก `replyMessage()` ซ้ำจะ error
- **ไม่มี fallback** - ถ้า reply ล้มเหลว ข้อความจะไม่ถูกส่ง

## สาเหตุที่ Token หมดอายุ
1. ระบบประมวลผลช้า (AI, database query)
2. มีการเรียก API อื่นก่อน (getProfile, getMessageContent)
3. Network latency
4. Token ถูกใช้ไปแล้วโดยโค้ดส่วนอื่น

## วิธีแก้ไข

### ✅ สิ่งที่ทำแล้ว

#### 1. สร้าง Wrapper Function (Line 145-180)
```php
function sendMessageWithFallback($line, $replyToken, $userId, $messages, $db = null)
```
- ลอง `replyMessage()` ก่อน (ฟรี! ไม่นับ quota)
- ถ้าล้มเหลว fallback ไปใช้ `pushMessage()` อัตโนมัติ
- Log ทุกครั้งที่ fallback เกิดขึ้น

#### 2. แก้จุดสำคัญแล้ว (3 จุด)

**A. Payment Slip Upload (6 จุด) - Lines 3542-3606**
- ✅ ไม่พบออเดอร์
- ✅ ไม่สามารถรับรูปภาพได้
- ✅ ระบบมีปัญหา (mkdir failed)
- ✅ ระบบมีปัญหา (permission)
- ✅ ไม่สามารถบันทึกรูปได้
- ✅ ยืนยันรับสลิป (Flex Message)

**B. Broadcast Click (Line 565)**
- ✅ ตอบกลับเมื่อลูกค้ากดสินค้าจาก broadcast

**C. General Auto-Reply (Line 1027-1041)**
- ✅ มี fallback อยู่แล้วจากการแก้ไขก่อนหน้า

### 🔴 จุดสำคัญที่ต้องแก้ต่อ (7 จุด)

#### Order Management
1. **Line ~811** - ยกเลิกออเดอร์
2. **Line ~1326** - ยืนยันออเดอร์ (Flex Message)
3. **Line ~1329** - ไม่พบออเดอร์
4. **Line ~3326** - ยืนยันออเดอร์จาก LIFF
5. **Line ~3345** - Error สร้างออเดอร์
6. **Line ~3388** - ไม่มีออเดอร์รอชำระ
7. **Line ~3508** - แสดงออเดอร์รอชำระ

### 🟡 จุดปานกลางที่ควรแก้ (6 จุด)

#### LIFF & Consent
8. **Line ~923** - ขอ consent
9. **Line ~956** - LIFF Menu
10. **Line ~1083** - LIFF Reply
11. **Line ~1393** - Shop Flex Message

#### User Actions
12. **Line ~1124** - หยุด AI (ขอคุยกับเภสัชกร)
13. **Line ~1356** - AI ไม่ได้เปิดใช้งาน

### 🟢 จุดที่แก้ทีหลังได้ (8 จุด)

#### Commands & Auto-Reply
14. **Line ~1411** - Auto-reply ตาม bot mode
15. **Line ~1443** - Contact command
16. **Line ~1490** - Menu command
17. **Line ~1513** - Quick menu command
18. **Line ~1530** - Auto-reply fallback
19. **Line ~1576** - checkAutoReply
20. **Line ~1600** - Generic error message

## วิธีใช้งาน Wrapper Function

### ก่อน (เดิม)
```php
$line->replyMessage($replyToken, [$message]);
```

### หลัง (ใหม่)
```php
sendMessageWithFallback($line, $replyToken, $userId, [$message], $db);
```

### ตัวอย่างการแก้ไข

**Order Cancellation (Line ~811)**
```php
// เดิม
$line->replyMessage($replyToken, [$cancelMessage]);

// ใหม่
sendMessageWithFallback($line, $replyToken, $userId, [$cancelMessage], $db);
```

**Order Confirmation (Line ~1326)**
```php
// เดิม
$line->replyMessage($replyToken, [$message]);

// ใหม่
sendMessageWithFallback($line, $replyToken, $userId, [$message], $db);
```

## การตรวจสอบ Fallback

### Query เพื่อดูสถิติ Fallback
```sql
-- จำนวน fallback แต่ละวัน
SELECT 
    DATE(created_at) as date,
    COUNT(*) as fallback_count,
    COUNT(DISTINCT user_id) as affected_users
FROM dev_logs 
WHERE message = 'Reply failed, used push fallback'
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- ดูรายละเอียด fallback
SELECT 
    created_at,
    user_id,
    JSON_EXTRACT(data, '$.reply_code') as reply_code,
    JSON_EXTRACT(data, '$.push_code') as push_code,
    JSON_EXTRACT(data, '$.reason') as reason
FROM dev_logs 
WHERE message = 'Reply failed, used push fallback'
ORDER BY created_at DESC
LIMIT 50;
```

### ตรวจสอบ Reply Token ที่ล้มเหลว
```sql
-- Error codes จาก LINE API
SELECT 
    JSON_EXTRACT(data, '$.reply_code') as error_code,
    COUNT(*) as count
FROM dev_logs 
WHERE message = 'Reply failed, used push fallback'
GROUP BY error_code;
```

## ข้อดีของ Fallback

### ประหยัด Quota
- Reply Message = **ฟรี** (ไม่นับ quota)
- Push Message = **นับ quota** (500 ฟรี/เดือน)
- ระบบจะลอง reply ก่อนเสมอ → ประหยัด quota สูงสุด

### ความน่าเชื่อถือ
- ข้อความไม่หาย แม้ token หมดอายุ
- ลูกค้าได้รับข้อความเสมอ
- ลดการ complain จากลูกค้า

### Monitoring
- Log ทุกครั้งที่ fallback เกิดขึ้น
- ติดตามปัญหาได้ง่าย
- วิเคราะห์ pattern ของ token expiration

## ขั้นตอนต่อไป

### Phase 1: Critical (ทำทันที) 🔴
1. แก้ Order Management (7 จุด)
2. Test payment flow ทั้งหมด
3. Test order creation & cancellation
4. Monitor logs เป็นเวลา 1-2 วัน

### Phase 2: Medium (ทำภายใน 1 สัปดาห์) 🟡
1. แก้ LIFF & Consent (4 จุด)
2. แก้ User Actions (2 จุด)
3. Test LIFF flow
4. Monitor logs

### Phase 3: Low Priority (ทำเมื่อมีเวลา) 🟢
1. แก้ Commands & Auto-Reply (8 จุด)
2. Refactor ให้โค้ดสะอาดขึ้น
3. พิจารณาแก้ใน LineAPI class (วิธีที่ 3)

## เอกสารที่เกี่ยวข้อง

- `install/REPLY_TOKEN_ANALYSIS.md` - วิเคราะห์ละเอียดทุกจุด
- `install/apply_reply_fallback.php` - รายการจุดที่ต้องแก้
- `webhook.php` (Line 145-180) - Wrapper function

## ผลกระทบ

### ก่อนแก้
- ข้อความหายเมื่อ token หมดอายุ
- ลูกค้าไม่ได้รับการตอบกลับ
- ไม่มี log เพื่อ debug

### หลังแก้
- ✅ ข้อความส่งได้เสมอ (reply หรือ push)
- ✅ ประหยัด quota (ลอง reply ก่อน)
- ✅ มี log ครบถ้วน
- ✅ ติดตามปัญหาได้

## สรุป

เราได้สร้าง `sendMessageWithFallback()` function ที่จัดการ reply token expiration อัตโนมัติ และแก้ไขจุดสำคัญ 3 จุดแล้ว (Payment Slip Upload, Broadcast Click, General Auto-Reply)

ยังเหลืออีก 21 จุดที่ต้องแก้ โดยแบ่งเป็น:
- 🔴 Critical: 7 จุด (Order Management)
- 🟡 Medium: 6 จุด (LIFF & User Actions)
- 🟢 Low: 8 จุด (Commands & Auto-Reply)

แนะนำให้แก้ตามลำดับความสำคัญ และ monitor logs หลังแก้แต่ละ phase
