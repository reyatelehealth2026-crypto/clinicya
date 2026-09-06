# ADR-008: แหล่งเก็บแต้มเดียว — `points_transactions` เป็น ledger, `users.available_points` เป็น cache

**Status:** Proposed (2026-09-07)
**Deciders:** Engineering (รอ Platform Owner ยืนยัน)
**Feature area:** Loyalty — ที่เก็บยอดแต้ม, ประวัติรายการ, และการคำนวณ tier

---

## Context

ระบบแต้มถูกเขียนซ้ำหลายรอบโดยไม่ได้ถอดของเดิมออก ผลคือวันนี้ยอดแต้มของ
ลูกค้าคนเดียวกระจายอยู่ในหลายที่ และแต่ละหน้าจอเห็นไม่เหมือนกัน

### ตาราง `users` มีคอลัมน์แต้ม 5 คอลัมน์

`database/migration_2026-05-25_tenant_template.sql:313` เป็นต้นไป:

| คอลัมน์ | บรรทัด | ใครเขียน |
|---|---|---|
| `total_points` | 344 | `LoyaltyPoints::addPoints()` |
| `available_points` | 345 | `LoyaltyPoints::addPoints/deductPoints()` |
| `used_points` | 346 | `LoyaltyPoints::deductPoints()` |
| `loyalty_points` | 356 | ไม่มีใครเขียน (`install/install_all.php:166` สร้างไว้เฉย ๆ) |
| `points` | 376 | `api/member.php:263`, `api/points.php:281`, `shop/order-detail.php:407` |

### ยอดจริงมีสองเส้น ไม่เคยบวกกัน

มีสอง code path ที่ทั้งคู่ยัง live อยู่ และไม่รู้จักกัน:

**เส้น A — `LoyaltyPoints`** เขียน `users.available_points` + log ลง
`points_transactions`
ใช้โดย: receipt points (`webhook.php:5660`), POS (`classes/POSService.php:191`),
รีวิวใบเสร็จ (`classes/ReceiptPointsAdmin.php:29`), ปรับมือโดยแอดมิน
(`user-detail.php:59`), คืนแต้ม (`membership.php:186`), แลกของรางวัลจาก LINE
(`classes/LoyaltyPoints.php:387` ← `webhook.php:1489`, `api/rewards.php:117`)

**เส้น B — SQL ตรง** เขียน `users.points` + log ลง `points_history`
ใช้โดย: โบนัสต้อนรับสมาชิกใหม่ (`api/member.php:263` และ `:400`, `:454`),
แลกของรางวัล (`api/points.php:281`), แต้มจากออเดอร์ (`shop/order-detail.php:407`)

`shop/order-detail.php` เขียน **ทั้งสองตาราง** ในไฟล์เดียว

### อาการที่ลูกค้าเจอจริง

1. **โบนัสต้อนรับ 50 แต้มมองไม่เห็น** — `api/member.php:263` เขียนลง
   `users.points` แต่ `api/member.php:523` อ่าน
   `available_points ?? total_points` ไฟล์เดียวกัน เขียนคอลัมน์หนึ่ง อ่านอีก
   คอลัมน์หนึ่ง สมาชิกใหม่ทุกคนจึงได้แต้มที่ใช้ไม่ได้
2. **ประวัติแต้มขาดครึ่ง** — `LoyaltyPoints::getPointsHistory()` อ่าน
   `points_transactions` ส่วน `api/points.php:93` และ `api/ai-admin.php:721`
   อ่าน `points_history` ไม่มีหน้าไหนเห็นครบ
3. **แลกของรางวัลอาจถูกปฏิเสธทั้งที่มีแต้ม** — `api/points.php:268` เช็ค
   `$user['points']` ลูกค้าที่สะสมจากใบเสร็จ (เข้า `available_points`)
   จะถูกตอบว่า *"แต้มไม่เพียงพอ"*

### ตารางที่ตายแล้วแต่ยังอยู่ในสคีมา

ตรวจด้วยการนับผู้เขียน/ผู้อ่านในโค้ดโปรดักชัน (ไม่นับ `install/`, `archive/`,
`vendor/`):

| ตาราง | writer | reader | สถานะ |
|---|---|---|---|
| `points_transactions` | 4 | 8 | **ledger ตัวจริง** |
| `points_history` | 4 | 2 | ledger คู่ขนาน |
| `loyalty_points` | 0 | 0 | ตายแล้ว (แก้ผู้อ่านค้างไปใน `8927519a`) |
| `loyalty_points_history` | 0 | 0 | ตายสนิท |
| `points_rules` | 0 | 0 | ตายสนิท (มีแต่ migration script) |
| `points_tiers` | 0 | 1 | เกือบตาย |
| `member_tiers` | 0 | 3 | fallback ของ `TierService` ไม่มีใครเขียน |
| `tier_settings` | 2 | 5 | ที่เก็บ tier ตัวจริง |

### ledger ที่มีอยู่โกหกได้ภายใต้ concurrency

`points_transactions` มีคอลัมน์ครบพอเป็น ledger จริง (`type` enum
earn/redeem/expire/adjust/refund, `balance_after`, `expires_at`,
`reference_type`, `reference_id`) แต่ `classes/LoyaltyPoints.php` ไม่มี
`beginTransaction` แม้แต่ครั้งเดียว — `addPoints()` อ่านยอดเดิม แล้วค่อย
`UPDATE ... SET total_points = total_points + ?` แล้วค่อย INSERT

ตัว UPDATE เป็น atomic increment จึงไม่หายยอด แต่ `balance_after` ที่บันทึกลง
ledger คำนวณจากค่าที่อ่านมาก่อนหน้า สองรายการที่เข้ามาพร้อมกันจะบันทึก
`balance_after` เท่ากัน — ledger จึงกระทบยอดไม่ตรงตั้งแต่ตอนนี้

ไม่มี idempotency key ที่ path แต้มเลย มีเฉพาะฝั่ง Odoo webhook
(`classes/OdooWebhookHandler.php`)

---

## Decision

### 1. แหล่งความจริงเดียว

- **`points_transactions` คือ ledger** — ทุกการเปลี่ยนแปลงแต้มต้องมีแถวที่นี่
- **`users.available_points` คือ cache ของยอดคงเหลือ** — อ่านได้เร็ว แต่
  ต้องสร้างใหม่จาก ledger ได้เสมอ
- **`users.total_points` / `used_points`** คงไว้เป็น cache เช่นกัน
- **tier ไม่เก็บ** — คำนวณจากยอดผ่าน `TierService` ทุกครั้ง
  (`users.member_tier` ไม่มีในสคีมาเลย และ `api/member.php:516` ระบุไว้แล้วว่า
  ไม่เชื่อค่าที่เก็บ)

### 2. `classes/LoyaltyPoints.php` เป็นทางเข้าเดียว

โค้ดใหม่ห้าม `UPDATE users SET points`/`available_points` ตรง ๆ ต้องผ่าน
`addPoints()` / `deductPoints()` เท่านั้น เส้น B ทั้งหมดย้ายมาเรียกสองเมธอดนี้

### 3. `addPoints`/`deductPoints` ต้องเป็น transaction และ idempotent

- ครอบด้วย `beginTransaction` + `SELECT ... FOR UPDATE` เพื่อให้
  `balance_after` ตรงกับความจริง
- เพิ่ม `points_transactions.idempotency_key` + `UNIQUE(source, idempotency_key)`
  ให้ event เดิมส่งซ้ำได้แต้มครั้งเดียว

### 4. คอลัมน์และตารางที่เลิกใช้

เลิกใช้ (ยังไม่ DROP): `users.points`, `users.loyalty_points`,
`points_history`, `loyalty_points`, `loyalty_points_history`, `points_rules`,
`points_tiers`

`member_tiers` คงไว้เป็น fallback ของ `TierService` ตามเดิม

---

## Migration plan

ทำตามลำดับ ห้ามข้าม — แต่ละเฟสปล่อยแยก deploy ได้

| เฟส | งาน | เช็คว่าสำเร็จยังไง |
|---|---|---|
| 0 | **สำรวจความเสียหายก่อน** — นับผู้ใช้ที่ `points > 0` แต่ `available_points = 0` และกลับกัน ต่อ tenant | ได้ตัวเลขจริง ตัดสินใจเฟส 2 ได้ |
| 1 | `LoyaltyPoints` ครอบ transaction + `FOR UPDATE`; เพิ่ม `idempotency_key` | property test: 50 การให้แต้มพร้อมกัน แล้ว `balance_after` ของแถวสุดท้าย = `available_points` |
| 2 | **backfill** — ย้ายยอดจาก `users.points` เข้า `available_points` และ import `points_history` เข้า `points_transactions` เป็น `type='migration'` | ยอดรวมก่อน/หลังเท่ากันทุก user |
| 3 | เปลี่ยนเส้น B ทั้งหมดมาเรียก `LoyaltyPoints` (`api/member.php` × 3, `api/points.php`, `shop/order-detail.php`) | grep แล้วไม่เหลือ `UPDATE users SET points` |
| 4 | ผู้อ่าน `points_history` (`api/points.php`, `api/ai-admin.php`) ย้ายมาอ่าน `points_transactions` | ประวัติในทุกหน้าจอตรงกัน |
| 5 | DROP คอลัมน์/ตารางที่เลิกใช้ ผ่าน `database/migration_*.sql` + บรรทัด `!` ใน `.gitignore` | สคีมาเหลือที่เก็บแต้มชุดเดียว |

**เฟส 2 คือจุดที่ย้อนยาก** — ต้อง snapshot ตาราง `users` ต่อ tenant ก่อนรัน และ
เก็บ mapping ไว้ให้ย้อนได้

---

## Consequences

### ได้

- ลูกค้าเห็นแต้มก้อนเดียว ทุกหน้าจอตรงกัน
- โบนัสต้อนรับ 50 แต้มใช้ได้จริง
- ประวัติแต้มครบในที่เดียว ตรวจสอบย้อนหลังได้
- มีฐานให้ต่อ external event API ในอนาคต — ledger + idempotency คือสิ่งที่
  ต้องมีก่อนเปิดให้ระบบภายนอกส่ง event เข้ามา ไม่ใช่หลังจากนั้น

### เสีย / เสี่ยง

- เฟส 2 แตะยอดแต้มจริงของลูกค้า ถ้าพลาดคือลูกค้าเสียแต้ม ต้อง snapshot ก่อน
- ระหว่างเฟส 3 ยังไม่จบ จะมีช่วงที่โค้ดสองแบบอยู่ด้วยกัน ต้องปล่อยเรียงเฟส
  ห้ามปล่อยพร้อมกัน
- ยอดที่ลูกค้าเห็นอาจ **เพิ่มขึ้น** หลัง backfill (เพราะเดิมมองไม่เห็นแต้มใน
  `users.points`) ต้องเตรียมคำอธิบายให้ร้าน ไม่ใช่ปล่อยให้ตกใจ

---

## Known Gaps

- **ยังไม่ได้ query โปรดักชัน** — ทั้งหมดข้างบนสรุปจากโค้ดและสคีมาในรีโป
  ยังไม่รู้ว่าจริง ๆ มีลูกค้ากี่คนที่แต้มค้างใน `users.points` (เฟส 0 ตอบข้อนี้)
- **`api/points.php` อาจตายแล้ว** — หาผู้เรียกในรีโปไม่เจอเลย (มีแต่
  `install/test_rewards_api.php`) ถ้ายืนยันว่าไม่มี client ภายนอกเรียก ก็ลบทิ้ง
  ได้แทนที่จะพอร์ต ซึ่งจะทำให้เฟส 3 เล็กลง
- **นโยบายวันหมดอายุแต้ม** — `points_settings.points_expiry_days` มีอยู่ และ
  `points_transactions.expires_at` ถูกเขียน แต่ยังไม่ได้ตรวจว่ามี job ไหน
  บังคับใช้จริง ยกไปเป็น ADR แยก
- **`points_claims` vs `receipt_point_claims`** — เป็นคนละระบบ (QR/เบอร์โทร
  หน้าร้าน vs รูปใบเสร็จ) ADR นี้ไม่แตะ ทั้งคู่ปลายทางลง `LoyaltyPoints` เหมือนกัน

---

## เกี่ยวข้องกับ ADR อื่น

- **ADR-007** (`0007-receipt-points-review.md`) — receipt points เป็นผู้ใช้
  รายใหญ่ของเส้น A ADR นี้ไม่เปลี่ยนพฤติกรรมการตรวจใบเสร็จ เปลี่ยนแค่ปลายทาง
  ที่แต้มไปลง
- **ADR-001** — ทุกตารางในเอกสารนี้อยู่ใน tenant DB การ migrate ต้องวนทุก
  tenant ตามรูปแบบใน `cron/`
