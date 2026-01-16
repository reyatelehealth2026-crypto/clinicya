# Reply Token Usage Analysis

## สรุปปัญหา
Reply Token มีอายุเพียง **30 วินาที** และใช้ได้เพียง **1 ครั้ง** เท่านั้น หากใช้ reply token หลังจากหมดอายุหรือถูกใช้ไปแล้ว จะทำให้ `replyMessage()` ล้มเหลว และข้อความไม่ถูกส่งไปหาผู้ใช้

## สถานะปัจจุบัน

### ✅ มี Fallback แล้ว (3 จุด)
1. **Line 439** - `sendWelcomeMessage()` - มี fallback logic ที่สมบูรณ์
2. **Line 1027-1041** - General Mode Auto-Reply - มี fallback แล้ว (เพิ่งแก้ไข)
3. **Line 1139-1165** - AI Chatbot Mode - มี fallback แล้ว

### ❌ ยังไม่มี Fallback (25+ จุด)

#### กลุ่ม 1: Broadcast & Product Click
- **Line 565** - `handleBroadcastClick()` - ตอบกลับเมื่อลูกค้ากดสินค้าจาก broadcast

#### กลุ่ม 2: Order Management
- **Line 811** - ยกเลิกออเดอร์
- **Line 1326** - ยืนยันออเดอร์ (Flex Message)
- **Line 1329** - ไม่พบออเดอร์
- **Line 3326** - ยืนยันออเดอร์จาก LIFF
- **Line 3345** - Error สร้างออเดอร์
- **Line 3388** - ไม่มีออเดอร์รอชำระ
- **Line 3508** - แสดงออเดอร์รอชำระ
- **Line 3542** - ไม่พบออเดอร์

#### กลุ่ม 3: Payment Slip Upload
- **Line 3549** - ไม่สามารถรับรูปภาพได้
- **Line 3557** - ระบบมีปัญหา (mkdir failed)
- **Line 3564** - ระบบมีปัญหา (permission)
- **Line 3573** - ไม่สามารถบันทึกรูปได้
- **Line 3606** - ยืนยันรับสลิป (Flex Message)
- **Line 3678** - ยืนยันรับหลักฐานการชำระเงิน

#### กลุ่ม 4: Consent & LIFF
- **Line 923** - ขอ consent
- **Line 956** - LIFF Menu
- **Line 1083** - LIFF Reply
- **Line 1393** - Shop Flex Message

#### กลุ่ม 5: User Commands
- **Line 1124** - หยุด AI (ขอคุยกับเภสัชกร)
- **Line 1356** - AI ไม่ได้เปิดใช้งาน
- **Line 1411** - Auto-reply ตาม bot mode
- **Line 1443** - Contact command
- **Line 1490** - Menu command
- **Line 1513** - Quick menu command
- **Line 1530** - Auto-reply (fallback)
- **Line 1576** - Auto-reply (checkAutoReply)

#### กลุ่ม 6: Error Handling
- **Line 1600** - Error message (generic)

## แนวทางแก้ไข

### วิธีที่ 1: เพิ่ม Fallback ทีละจุด (ปัจจุบัน)
```php
$replyResult = $line->replyMessage($replyToken, [$message]);
$replyCode = $replyResult['code'] ?? 0;

if ($replyCode !== 200) {
    // Fallback to push
    $line->pushMessage($userId, [$message]);
    devLog($db, 'info', 'webhook', 'Reply failed, used push fallback', [
        'reply_code' => $replyCode,
        'user_id' => $userId
    ], $userId);
}
```

**ข้อดี:**
- ควบคุมได้ละเอียด
- เห็นชัดว่าจุดไหนมี fallback

**ข้อเสีย:**
- ต้องแก้ 25+ จุด
- โค้ดซ้ำซ้อน
- ง่ายต่อการลืมเพิ่ม fallback ในจุดใหม่

### วิธีที่ 2: สร้าง Wrapper Function (แนะนำ) ⭐
```php
/**
 * ส่งข้อความด้วย Reply Token พร้อม Auto-Fallback
 * ถ้า reply ล้มเหลว จะ fallback ไปใช้ push อัตโนมัติ
 */
function sendMessageWithFallback($line, $replyToken, $userId, $messages, $db = null) {
    // ลอง reply ก่อน (ฟรี!)
    $replyResult = $line->replyMessage($replyToken, $messages);
    $replyCode = $replyResult['code'] ?? 0;
    
    // ถ้า reply สำเร็จ - เสร็จสิ้น
    if ($replyCode === 200) {
        return ['method' => 'reply', 'success' => true, 'code' => $replyCode];
    }
    
    // ถ้า reply ล้มเหลว - fallback ไปใช้ push
    $pushResult = $line->pushMessage($userId, $messages);
    $pushCode = $pushResult['code'] ?? 0;
    
    // Log fallback
    if ($db) {
        devLog($db, 'info', 'webhook', 'Reply failed, used push fallback', [
            'reply_code' => $replyCode,
            'push_code' => $pushCode,
            'user_id' => $userId
        ], $userId);
    }
    
    return [
        'method' => 'push',
        'success' => $pushCode === 200,
        'reply_code' => $replyCode,
        'push_code' => $pushCode
    ];
}
```

**การใช้งาน:**
```php
// แทนที่
$line->replyMessage($replyToken, [$message]);

// ด้วย
sendMessageWithFallback($line, $replyToken, $userId, [$message], $db);
```

**ข้อดี:**
- แก้ที่เดียว ใช้ได้ทุกที่
- โค้ดสะอาด ไม่ซ้ำซ้อน
- Auto-fallback ทุกครั้ง
- Log ได้ครบถ้วน

**ข้อเสีย:**
- ต้องแก้ทุกจุดที่เรียก `replyMessage()`

### วิธีที่ 3: แก้ใน LineAPI Class (ดีที่สุด) 🏆
แก้ใน `classes/LineAPI.php` ให้ `replyMessage()` มี auto-fallback ในตัว

```php
public function replyMessage($replyToken, $messages, $userId = null, $autoFallback = true) {
    $result = $this->sendRequest('reply', [
        'replyToken' => $replyToken,
        'messages' => $messages
    ]);
    
    // ถ้า auto-fallback เปิดอยู่ และ reply ล้มเหลว และมี userId
    if ($autoFallback && $userId && ($result['code'] ?? 0) !== 200) {
        // Fallback to push
        return $this->pushMessage($userId, $messages);
    }
    
    return $result;
}
```

**ข้อดี:**
- แก้ที่เดียว ใช้ได้ทุกที่อัตโนมัติ
- ไม่ต้องแก้โค้ดเดิมเลย (ถ้าส่ง userId ไปด้วย)
- Backward compatible

**ข้อเสีย:**
- ต้องส่ง `$userId` ไปด้วยทุกครั้ง

## คำแนะนำ

**ระยะสั้น (ด่วน):**
- ใช้วิธีที่ 2 สร้าง wrapper function `sendMessageWithFallback()`
- แก้จุดสำคัญก่อน: Order, Payment, Broadcast

**ระยะยาว (แนะนำ):**
- ใช้วิธีที่ 3 แก้ใน LineAPI class
- Refactor ทั้งหมดให้ใช้ method ใหม่

## Priority List (แก้ตามลำดับความสำคัญ)

### 🔴 สำคัญมาก (ต้องแก้ก่อน)
1. Payment slip upload (lines 3549, 3557, 3564, 3573, 3606, 3678)
2. Order confirmation (lines 1326, 3326)
3. Order errors (lines 1329, 3345, 3388, 3542, 3508)

### 🟡 สำคัญปานกลาง
4. Broadcast click (line 565)
5. Order cancellation (line 811)
6. LIFF messages (lines 923, 956, 1083, 1393)

### 🟢 สำคัญน้อย (แก้ทีหลังได้)
7. User commands (lines 1124, 1443, 1490, 1513)
8. Auto-reply fallback (lines 1411, 1530, 1576)
9. Error messages (lines 1356, 1600)

## สถิติ Reply Token ที่ล้มเหลว

ตรวจสอบได้จาก:
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) as failed_replies
FROM dev_logs 
WHERE source = 'webhook' 
    AND message LIKE '%Reply failed%'
GROUP BY DATE(created_at)
ORDER BY date DESC;
```
