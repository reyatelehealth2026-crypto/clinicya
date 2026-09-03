# REYA — Domain Context

> สัญญาด้านภาษาธุรกิจสำหรับระบบ REYA Pharmacy SaaS Platform.
> เอกสารฉบับนี้เป็น single source of truth สำหรับคำศัพท์โดเมน — ทุก commit ที่เปลี่ยน
> ความหมายเชิงธุรกิจของ entity ใด ๆ ต้องอัปเดตที่นี่ก่อน.
>
> ADR เก็บไว้ที่ `docs/adr/` สำหรับการตัดสินใจที่ย้อนกลับยาก.

---

## Glossary (ศัพท์โดเมน)

### Layer 0 — Platform

| Term (EN) | คำไทย | คำจำกัดความ |
|-----------|-------|-------------|
| **Platform** | แพลตฟอร์ม / ระบบ | REYA — codebase + infrastructure ที่ host SaaS นี้. มี single deployment, single database cluster (รายละเอียด isolation ดู ADR-001). |
| **Platform Owner** | เจ้าของระบบ / ทีม dev | กลุ่มคนที่พัฒนาและดูแล Platform. มี `role = super_admin`. **เห็นข้ามทุก Tenant ได้** แต่ทุกการเข้าถึงข้าม Tenant ต้องถูกบันทึก audit log. |

### Layer 1 — Tenant (ลูกค้าของ Platform)

| Term (EN) | คำไทย | คำจำกัดความ |
|-----------|-------|-------------|
| **Tenant** | ร้าน (ในระบบ) | **หน่วย isolation หลัก.** ลูกค้า 1 ราย = 1 Tenant. ข้อมูลของ Tenant A ห้ามถูก Tenant B เห็นเด็ดขาด (ยกเว้น Platform Owner). 1 Tenant = นิติบุคคล/บุคคลธรรมดา 1 ราย ที่ซื้อ subscription. |
| **Tenant Owner** | เจ้าของร้าน | ผู้สมัครใช้ระบบ — มี `role = admin`. มีสิทธิ์เต็มภายใน Tenant ตัวเอง แต่ไม่เห็นข้าม. |
| **Tenant Staff** | พนักงานในร้าน | ผู้ใช้ที่ Tenant Owner เพิ่มเข้ามา — มี `role ∈ {pharmacist, marketing, tech, staff}`. สิทธิ์จำกัดตาม role + permission. |
| **Tenant Plan** | แพ็กเกจของร้าน | Subscription tier ของ Tenant (Starter / Pro / Enterprise / Custom). คุม **Entitlement** ของ Tenant. |
| **Entitlement** | สิทธิ์การใช้งาน | Feature flag ต่อ Tenant ที่ **Platform Owner เป็นคนอนุมัติ/เปลี่ยน** — ไม่ใช่ self-serve. ตัวอย่าง: `max_branches`, `max_channels`, `allow_documents`, `allow_ai_chat`. |

### Layer 2 — Branch (สาขาจริงของ Tenant)

| Term (EN) | คำไทย | คำจำกัดความ |
|-----------|-------|-------------|
| **Branch** | สาขา (สถานที่จริง) | สถานที่ตั้งของ Tenant. Default ทุก Tenant เริ่มต้นที่ **1 Branch**. การเปิด Branch เพิ่มต้อง entitlement (Platform Owner อนุมัติ). 1 Branch = 1 ใบอนุญาตเภสัช + 1 รหัสสาขา ก.พ. 30 (`00000` = สำนักงานใหญ่). |
| **Default Branch** | สาขาเริ่มต้น | Branch แรกของ Tenant — สร้างอัตโนมัติตอน Tenant signup. ลบไม่ได้. |
| **Branch Manager** | หัวหน้าสาขา | Tenant Staff ที่มีสิทธิ์เฉพาะ Branch ที่ระบุ (เห็นข้าม Branch อื่นภายใน Tenant เดียวกันไม่ได้). |

### Layer 3 — Channel (ช่องทางที่ลูกค้าคุย)

| Term (EN) | คำไทย | คำจำกัดความ |
|-----------|-------|-------------|
| **Channel** | ช่องทาง | Integration ที่ Tenant ใช้คุยกับลูกค้า. ปัจจุบันมีแค่ **LINE OA** (เก็บใน `line_accounts`). อนาคต = Web Chat / TikTok DM / Facebook. **Channel เก่าใน DB ใช้คอลัมน์ `line_account_id` เป็น tenant scope — ตามแผน V2 จะ rename เป็น `tenant_id` (ดู ADR-001).** |
| **Default Channel** | ช่องทางหลัก | Channel ที่ Tenant set เป็นค่าเริ่มต้น. ใช้ส่ง broadcast / notification. |

### บทบาทผู้ใช้ (User Roles)

ลำดับชั้น (สูง → ต่ำ):

```
Platform Owner (super_admin)        ← ทีม dev / เห็นข้าม Tenant
        ↓
Tenant Owner (admin)                ← เจ้าของร้าน / เห็นเฉพาะ Tenant ตัวเอง
        ↓
Tenant Staff (pharmacist / marketing / tech / staff)  ← พนักงาน / scope ตาม role
        ↓
End User (user)                     ← ลูกค้าของร้าน (มา consume ผ่าน LINE Mini App)
```

### Domain-specific terms (ที่เคยใช้แล้ว — อย่าทับซ้อน)

| Term | คำจำกัดความ |
|------|-------------|
| **Dispense** (จ่ายยา) | กระบวนการที่เภสัชกรอนุมัติยาให้ลูกค้า — สร้าง `dispensing_records` + เพิ่ม cart/transaction. |
| **Triage** (คัดกรอง) | AI flow ที่สอบถามอาการลูกค้าก่อนแนะนำยา — จบใน `triage_sessions`. |
| **Chief Complaint** (อาการสำคัญ) | สรุปอาการของลูกค้าใน 1 ประโยค — ใช้ตอนเภสัชกรเห็นใน dispense. |

---

## Decisions Log

| Date | Decision | ADR |
|------|----------|-----|
| 2026-05-25 | Tenant model = 3-tier (Tenant → Branch → Channel) with Branch optional gated by Entitlement | ADR-003 (จะเขียน) |
| 2026-05-25 | Multi-branch & multi-channel = Platform Owner-approved (no self-serve provisioning) | ADR-002 (จะเขียน) |
| 2026-05-25 | Isolation enforcement = **Database-per-Tenant** on shared MariaDB instance | ADR-001 ✅ |
| 2026-05-25 | File storage = per-tenant directory (`/var/reya/storage/tenant_NNNN/`) | ADR-005 (จะเขียน) |
| 2026-05-25 | Super admin cross-tenant access = audit-logged in `platform.super_admin_audit` | ADR-006 (จะเขียน) |

---

## Open Questions

ดูใน chat session ปัจจุบัน — กำลัง interview design decisions ทีละข้อ.
