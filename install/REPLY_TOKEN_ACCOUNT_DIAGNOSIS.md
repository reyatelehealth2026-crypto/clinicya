# Reply Token Missing for Specific LINE Account - Diagnosis Guide

## ปัญหา
ข้อความจาก LINE Account ID 4 ได้รับ reply token แต่ Account ID 3 ไม่มี reply token

## สาเหตุที่เป็นไปได้

### 1. Webhook URL Configuration ผิดพลาด
LINE Account ID 3 อาจไม่มี `?account=3` parameter ใน Webhook URL

**ตรวจสอบ:**
```sql
SELECT id, account_name, webhook_url 
FROM line_accounts 
WHERE id = 3;
```

**Webhook URL ที่ถูกต้อง:**
```
https://yourdomain.com/webhook.php?account=3
```

### 2. LINE API ไม่ส่ง replyToken มา
บาง event types ไม่มี replyToken:
- `unfollow` - ไม่มี replyToken
- `leave` - ไม่มี replyToken  
- `beacon` - ไม่มี replyToken
- `memberLeft` - ไม่มี replyToken

**Event types ที่มี replyToken:**
- `message` - มี replyToken ✅
- `follow` - มี replyToken ✅
- `join` - มี replyToken ✅
- `postback` - มี replyToken ✅

### 3. Webhook Signature Validation ล้มเหลว
ถ้า signature ไม่ถูกต้อง webhook.php จะไม่ประมวลผล event

**ตรวจสอบ:**
```sql
SELECT id, account_name, 
       CASE WHEN channel_secret IS NOT NULL THEN 'Yes' ELSE 'No' END as has_secret
FROM line_accounts 
WHERE id = 3;
```

## วิธีแก้ไข

### ขั้นตอนที่ 1: ตรวจสอบสถิติ Reply Token

รันไฟล์ debug:
```bash
php install/check_reply_token_by_account.php
```

ดูผลลัพธ์:
- ถ้า Account 3 มี "Without Token" สูง = มีปัญหา
- ถ้า Account 4 มี "With Token" สูง = ปกติ

### ขั้นตอนที่ 2: ตรวจสอบ Webhook URL

1. เข้า LINE Developers Console
2. เลือก Account ID 3
3. ไปที่ Messaging API > Webhook settings
4. ตรวจสอบ Webhook URL

**ต้องเป็น:**
```
https://yourdomain.com/webhook.php?account=3
```

**ไม่ใช่:**
```
https://yourdomain.com/webhook.php  ❌ (ไม่มี ?account=3)
```

### ขั้นตอนที่ 3: Debug Webhook Real-time

1. ใช้ไฟล์ `install/debug_webhook_reply_token.php` เป็น webhook ชั่วคราว:
```
https://yourdomain.com/install/debug_webhook_reply_token.php?account=3
```

2. ส่งข้อความทดสอบไปที่ Account 3

3. ดู log file:
```bash
cat webhook_debug.log
```

4. ตรวจสอบว่ามี `"has_reply_token": "YES"` หรือไม่

### ขั้นตอนที่ 4: ตรวจสอบ Database

```sql
-- ดูข้อความล่าสุดจาก Account 3
SELECT 
    m.id,
    m.line_account_id,
    u.display_name,
    m.reply_token IS NOT NULL as has_token,
    m.content,
    m.created_at
FROM messages m
LEFT JOIN users u ON m.user_id = u.id
WHERE m.line_account_id = 3
AND m.direction = 'incoming'
ORDER BY m.created_at DESC
LIMIT 10;
```

### ขั้นตอนที่ 5: แก้ไข Webhook URL

ถ้าพบว่า Webhook URL ไม่มี `?account=3`:

1. อัพเดท database:
```sql
UPDATE line_accounts 
SET webhook_url = 'https://yourdomain.com/webhook.php?account=3'
WHERE id = 3;
```

2. อัพเดทใน LINE Developers Console:
   - Messaging API > Webhook settings
   - Webhook URL: `https://yourdomain.com/webhook.php?account=3`
   - กด "Update"
   - กด "Verify" เพื่อทดสอบ

3. ทดสอบส่งข้อความใหม่

## การทดสอบ

### Test 1: ส่งข้อความไปที่ Account 3
```
ผู้ใช้: สวัสดีครับ
```

ตรวจสอบ:
```sql
SELECT reply_token FROM messages 
WHERE line_account_id = 3 
ORDER BY created_at DESC LIMIT 1;
```

ต้องได้ reply_token ที่ไม่ใช่ NULL

### Test 2: ตรวจสอบ dev_logs
```sql
SELECT * FROM dev_logs 
WHERE source = 'webhook' 
AND data LIKE '%account_id":3%'
ORDER BY created_at DESC 
LIMIT 10;
```

ดูว่ามี error อะไรเกิดขึ้นหรือไม่

## โค้ดที่เกี่ยวข้อง

### webhook.php (บรรทัด 207)
```php
$replyToken = $event['replyToken'] ?? null;
```

Reply token ถูกดึงจาก LINE API โดยตรง ถ้า LINE ไม่ส่งมา จะเป็น `null`

### webhook.php (บรรทัด 767-776)
```php
// บันทึกข้อความพร้อม reply_token
$stmt = $db->prepare("
    INSERT INTO messages 
    (line_account_id, user_id, direction, message_type, content, reply_token, is_read) 
    VALUES (?, ?, 'incoming', ?, ?, ?, 0)
");
$stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken]);
```

### webhook.php (บรรทัด 819-826)
```php
// บันทึก reply_token ใน users table
if ($replyToken) {
    $expires = date('Y-m-d H:i:s', time() + 50);
    $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
    $stmt->execute([$replyToken, $expires, $user['id']]);
}
```

## สรุป

ปัญหาส่วนใหญ่เกิดจาก:
1. **Webhook URL ไม่มี `?account=3` parameter** (90% ของกรณี)
2. Event type ที่ไม่มี replyToken (10% ของกรณี)

แก้ไขโดย:
1. เพิ่ม `?account=3` ใน Webhook URL ทั้งใน database และ LINE Console
2. ทดสอบส่งข้อความใหม่
3. ตรวจสอบด้วย `install/check_reply_token_by_account.php`

## ไฟล์ที่เกี่ยวข้อง
- `webhook.php` - Main webhook handler
- `install/check_reply_token_by_account.php` - ตรวจสอบสถิติ
- `install/debug_webhook_reply_token.php` - Debug real-time
- `webhook_debug.log` - Log file สำหรับ debug
