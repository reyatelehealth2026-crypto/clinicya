# สรุปการปรับปรุงระบบแลกแต้ม (Rewards System)

## วันที่: 2026-01-19

## ปัญหาที่พบ

1. **API Endpoints ไม่สอดคล้องกัน**
   - `debug-rewards.html` ใช้ `/api/debug-rewards.php`
   - `rewards-catalog.js` (LIFF frontend) ใช้ `/api/points-history.php`
   - ควรใช้ `/api/rewards.php` ที่มี logic ถูกต้อง

2. **การตรวจสอบ is_active ไม่ถูกต้อง**
   - ใช้ `!$reward['is_active']` ซึ่งไม่รองรับค่า string '0' หรือ '1'
   - ต้องตรวจสอบทั้ง string และ boolean

3. **การจัดการ stock ไม่สอดคล้อง**
   - ไม่มีการ normalize ค่า stock (NULL ควรเป็น -1 แทน unlimited)
   - การตรวจสอบ stock ไม่ครอบคลุมทุกกรณี

4. **ข้อความ error เป็นภาษาอังกฤษ**
   - ควรเป็นภาษาไทยให้สอดคล้องกับ UI

## การแก้ไข

### 1. ปรับ rewards-catalog.js ให้ใช้ API ที่ถูกต้อง

#### ไฟล์: `liff/assets/js/components/rewards-catalog.js`

**เปลี่ยนจาก:**
- ใช้ `/api/points-history.php?action=rewards`

**เปลี่ยนเป็น:**
- ใช้ `/api/rewards.php?action=list` สำหรับโหลดรายการรางวัล
- ใช้ `/api/rewards.php?action=my_redemptions` สำหรับโหลดประวัติการแลก
- ใช้ `/api/rewards.php?action=redeem` สำหรับแลกรางวัล

**ฟังก์ชันใหม่ที่เพิ่มเข้าไป:**

```javascript
async loadUserRedemptions(lineUserId) {
    // โหลดประวัติการแลกรางวัลแยกต่างหาก
}

async loadUserPoints(lineUserId) {
    // โหลดคะแนนผู้ใช้จาก points-history API
}
```

**ปรับปรุง confirmRedemption():**
- ส่ง `line_account_id` เพิ่มเติม
- อัปเดตแต้มจาก response (`new_balance`)
- รองรับ error message ทั้งจาก `data.message` และ `data.error`

### 2. แก้ไข LoyaltyPoints.php ให้ตรวจสอบ status อย่างถูกต้อง

#### ไฟล์: `classes/LoyaltyPoints.php`

**ฟังก์ชัน redeemReward():**

**ก่อนแก้ไข:**
```php
if (!$reward || !$reward['is_active']) return ['success' => false, 'message' => 'Reward not found'];
if ($reward['stock'] == 0) return ['success' => false, 'message' => 'Out of stock'];
```

**หลังแก้ไข:**
```php
if (!$reward) return ['success' => false, 'message' => 'ไม่พบรางวัล'];

// ตรวจสอบ is_active รองรับทั้ง string และ boolean
if (isset($reward['is_active']) && ($reward['is_active'] === 0 || $reward['is_active'] === '0' || $reward['is_active'] === false)) {
    return ['success' => false, 'message' => 'รางวัลนี้ไม่พร้อมให้บริการ'];
}

// ตรวจสอบ stock อย่างครอบคลุม (-1 = unlimited)
if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] !== -1 && $reward['stock'] <= 0) {
    return ['success' => false, 'message' => 'รางวัลหมดแล้ว'];
}
```

**การหักแต้ม:**
```php
// เปลี่ยนข้อความเป็นภาษาไทย
if (!$this->deductPoints($userId, $reward['points_required'], 'reward', $rewardId, "แลกรางวัล: {$reward['name']}")) {
    return ['success' => false, 'message' => 'ไม่สามารถหักแต้มได้'];
}
```

**การอัปเดต stock:**
```php
// ปรับให้ชัดเจนว่า -1 คือ unlimited
if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] > 0 && $reward['stock'] !== -1) {
    $stmt = $this->db->prepare("UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0");
    $stmt->execute([$rewardId]);
}
```

### 3. ปรับ getRewards() ให้ normalize stock

**ฟังก์ชัน getRewards():**

เพิ่มการ normalize ค่า stock หลังจาก fetch จากฐานข้อมูล:

```php
// Normalize stock field for consistency
foreach ($rewards as &$reward) {
    // Convert stock to integer, handle NULL as -1 (unlimited)
    if (!isset($reward['stock']) || $reward['stock'] === null) {
        $reward['stock'] = -1;
    } else {
        $reward['stock'] = (int)$reward['stock'];
    }
}
```

### 4. ปรับ deserializeRedemptions() ให้รองรับหลายรูปแบบ

**ไฟล์: `liff/assets/js/components/rewards-catalog.js`**

เพิ่มการรองรับชื่อ field ที่หลากหลาย:

```javascript
deserializeRedemptions(redemptions) {
    return redemptions.map(r => ({
        id: parseInt(r.id),
        reward_id: parseInt(r.reward_id),
        reward_name: r.reward_name || r.name || '',
        image_url: r.image_url || r.reward_image || r.image || null,
        points_used: parseInt(r.points_used) || parseInt(r.points) || 0,
        redemption_code: r.redemption_code || r.code || '',
        // ... ส่วนอื่นๆ
    }));
}
```

## ผลลัพธ์ที่คาดหวัง

1. ✅ ระบบแลกแต้มใช้ API endpoints ที่ถูกต้องและสอดคล้องกัน
2. ✅ การตรวจสอบ is_active รองรับทั้งค่า string และ boolean
3. ✅ การจัดการ stock ถูกต้อง (NULL = -1 = unlimited, 0 = หมด, >0 = มีสต๊อก)
4. ✅ ข้อความ error เป็นภาษาไทยทั้งหมด
5. ✅ Frontend และ Backend ใช้ logic เดียวกัน

## การทดสอบ

### ขั้นตอนการทดสอบ:

1. **ทดสอบโหลดรางวัล**
   - เปิด LIFF หน้าแลกแต้ม
   - ตรวจสอบว่ารายการรางวัลโหลดได้ถูกต้อง
   - ตรวจสอบว่า stock แสดงผลถูกต้อง (unlimited, เหลือ X ชิ้น, หมดแล้ว)

2. **ทดสอบแลกรางวัล**
   - กรณีแต้มพอ → ควรแลกได้สำเร็จและได้รหัสรับรางวัล
   - กรณีแต้มไม่พอ → ควรแสดง error "แต้มไม่เพียงพอ"
   - กรณีสินค้าหมด → ควรแสดง error "รางวัลหมดแล้ว"
   - กรณีรางวัลไม่ active → ควรแสดง error "รางวัลนี้ไม่พร้อมให้บริการ"

3. **ทดสอบอัปเดต stock**
   - แลกรางวัลที่มีจำนวนจำกัด
   - ตรวจสอบว่า stock ลดลง 1
   - รีโหลดหน้าแล้วตรวจสอบว่า stock ถูกต้อง

4. **ทดสอบประวัติการแลก**
   - เปิดแท็บ "รางวัลของฉัน"
   - ตรวจสอบว่าแสดงประวัติการแลกถูกต้อง
   - ตรวจสอบว่ารหัสรับรางวัลแสดงผล

## ไฟล์ที่แก้ไข

1. `liff/assets/js/components/rewards-catalog.js`
   - loadRewardsData()
   - loadUserRedemptions() (ใหม่)
   - loadUserPoints() (ใหม่)
   - confirmRedemption()
   - deserializeRedemptions()

2. `classes/LoyaltyPoints.php`
   - redeemReward()
   - getRewards()

## หมายเหตุ

- API `/api/debug-rewards.php` ยังคงใช้งานได้ตามเดิมสำหรับการ debug
- การเปลี่ยนแปลงนี้ไม่กระทบกับส่วนอื่นของระบบ
- ต้องทดสอบการแลกรางวัลในทุกกรณีเพื่อให้แน่ใจว่าทำงานถูกต้อง

---

**สร้างโดย:** Claude Code Assistant
**วันที่:** 2026-01-19
**เวอร์ชัน:** 1.0
