# LINE Mini App — แผนครบ 100% + แจกจ่าย Subagent + วงรีวิว–UAT

**วัตถุประสงค์:** รวบรวมงานที่เหลือให้ครบทุกส่วนตามนิยาม “100%” สำหรับ journey **แอดไลน์ → ติดต่อ/ช้อป → ชำระเงิน → หลังการขาย** แยกเป็น workstream มอบหมาย **subagent ผู้เชี่ยวชาญ** พร้อม **ซับฝ่ายรีวิว**, **ทดสอบจริง**, **บันทึกผล–ติ–ปรับ** จนกว่าจะผ่านเกณฑ์ปิดงาน

**อ้างอิง:** [`2026-04-12-line-mini-app-implementation-checklist.md`](./2026-04-12-line-mini-app-implementation-checklist.md), Next mini app (`line-mini-app/`), [`webhook.php`](../../webhook.php), [`api/checkout.php`](../../api/checkout.php)

---

## 1. นิยาม “100%” (Definition of Done)

| ชั้น | เกณฑ์ |
|------|--------|
| **Technical** | Build ผ่าน, type/lint ผ่าน, ไม่มี regression ชัดเจน, env/staging/prod แยกชัด |
| **Product** | Journey หลักทำได้ครบ: แอดไลน์ได้ประสบการณ์ที่ตั้งใจ → เข้ามินิแอป → ค้นหา/เลือกสินค้า → ตะกร้า → ชำระ (โอน+COD) → ออเดอร์+สลิป → ได้ข้อมูล/ลิงก์กลับในแชทเมื่อธุรกิจกำหนด |
| **Ops** | Rich menu, welcome, LIFF endpoint, `line_accounts` / `shop_settings` สอดคล้องกับ deploy จริง |
| **Compliance / risk** | ไม่รั่วข้อมูล, โปรโม/ยอดสั่งซื้อสอดคล้อง backend, ข้อความทางการแพทย์/AI มี disclaimer ตามนโยบายร้าน |

ถ้า scope บางข้อ “ไม่ทำในเฟสนี้” ต้อง **บันทึกเป็น Out of scope พร้อมเหตุผล** — ไม่นับเป็น 100% แต่นับเป็น “100% ของ scope ที่ล็อกแล้ว”

---

## 2. แผนที่ช่องว่าง (Gap → งาน)

รวมจาก checklist + การไล่ flow ใน repo:

| ID | ช่องว่าง | งานที่ต้องปิด |
|----|----------|----------------|
| G1 | **สมัคร/โปรไฟล์เต็ม** (เทียบ `liff-app` Register) | ออกแบบ + `/register` หรือ wizard ในมินิแอป + `member.php` |
| G2 | **ค้นหา/กรองสินค้าใน UI** | `ShopClient` + `fetchProducts` ส่ง `search` / `category_id`; UX ชัด |
| G3 | **ข้อมูลโอนนอก QR** | แสดงเลขบัญชี/ชื่อบัญชีจาก `shop_settings` บนหน้า checkout/order (copy-friendly) |
| G4 | **Deep link / Flex / ข้อความ OA** | แก้ URL ให้ตรง Next (`/shop`, `/order/[id]`) — อัปเดต [`LiffMessageHandler`](../../classes/LiffMessageHandler.php) และเทมเพลต Flex ในระบบ |
| G5 | **แจ้งเตือนลูกค้าใน LINE หลังออเดอร์** | ตรวจว่ามี LINE push หรือไม่; ถ้าไม่มี → ออกแบบส่งหลัง `create_order` / เปลี่ยนสถานะ (คนละเรื่องจาก Telegram แจ้งร้าน) |
| G6 | **เส้นทาง “ติดต่อคน” vs AI** | นิยาม: keyword → คิวเภสัช / แอดมิน; เอกสาร + webhook หรือระบบคิวที่มีอยู่ |
| G7 | **Phase D — route เสริม** | notifications, wishlist, นัด, วิดีโอ, health profile — เลือก priority หรือ “not in scope” เป็นรายการ |
| G8 | **ทดสอบบนอุปกรณ์จริง + LIFF** | UAT script, บันทึกผล, regression |

---

## 3. Subagent roster (ผู้เชี่ยวชาญที่แนะนำ)

| รหัส | โฟกัส | เหมาะกับ |
|------|--------|-----------|
| **SA-FE** | Next.js / LIFF / UX มินิแอป | G1, G2, G3, G7 (ส่วน UI) |
| **SA-BE** | PHP API, `checkout.php`, `member.php`, transaction | G1, G3 (ฟิลด์/API), G5 |
| **SA-LINE** | Webhook, Flex, Rich Menu, Messaging API, deep link | G4, G5, G6 (ข้อความ) |
| **SA-OPS** | env, LINE Developers, DB `shop_settings` / `line_accounts`, staging | ทุกงานที่ต้อง deploy |
| **SA-QA** | UAT, อุปกรณ์จริง, สคริปต์ทดสอบ, regression | G8 |
| **SA-SEC** | CORS, token, การหลุดข้อมูล, rate limit AI | ทุกเฟสก่อน production |
| **SA-REVIEW** | Code review + spec compliance (ไม่แทนที่ QA) | หลังแต่ละ PR |

**Orchestrator (เอเจนหลัก):** คิวงาน, รับรายงานจาก subagent, ตัดสินใจ scope, สั่งรอบถัดไป

### Claude Code agents (ไฟล์พร้อม invoke)

โฟลเดอร์: [`.claude/agents/`](../../.claude/agents/) — รูปแบบตาม **agent-development** (YAML frontmatter + system prompt + ตัวอย่าง trigger)

| รหัสแผน | ไฟล์ agent |
|---------|------------|
| Orchestrator | `miniapp-orchestrator.md` |
| SA-FE | `miniapp-fe.md` |
| SA-BE | `miniapp-be.md` |
| SA-LINE | `miniapp-line.md` |
| SA-OPS | `miniapp-ops.md` |
| SA-QA | `miniapp-qa.md` |
| SA-SEC | `miniapp-sec.md` |
| SA-REVIEW | `miniapp-review.md` |

รีวิว lane: **R1** → `miniapp-review` · **R2** → `miniapp-orchestrator` · **R3** → `miniapp-qa`

---

## 4. แจกจ่ายงานเป็น Workstream (พร้อม brief ให้ subagent)

### WS-A — Access & Ops baseline (ซ้ำเติมจาก Phase A)

- **Owner:** SA-OPS + SA-LINE  
- **Input:** deploy URL จริง, LIFF ID, OA  
- **Tasks:** ยืนยัน endpoint เดียวกับ DB; rich menu / QR เปิดมินิแอปถูกต้อง; ทดสอบ `NEXT_PUBLIC_*` ทุก env  
- **Done when:** ตาราง env ครบ + screenshot หรือลิงก์ทดสอบ  

### WS-B1 — ร้านค้า: ค้นหา + หมวด (G2)

- **Owner:** SA-FE (+ SA-BE ถ้าต้องขยาย API)  
- **Brief:** เพิ่มช่องค้นหา + filter ตาม `category_id`; ใช้พารามิเตอร์ที่ [`checkout.php`](../../api/checkout.php) `handleGetProducts` รองรับแล้ว  
- **Done when:** ค้นหาได้บนมือถือ; ไม่กระทบ performance โหลดรายการ  

### WS-B2 — ชำระเงิน: ข้อมูลโอนเต็ม (G3)

- **Owner:** SA-FE + SA-BE  
- **Brief:** อ่านฟิลด์จาก `shop_settings` (ชื่อบัญชี, เลขบัญชี, ธนาคาร — ตามที่ schema มี); แสดง checkout + order detail สำหรับ transfer  
- **Done when:** ลูกค้า copy เลขได้; ไม่โชว์ข้อมูลที่ไม่ควรใน COD  

### WS-C — สมัครสมาชิก / โปรไฟล์ (G1)

- **Owner:** SA-FE + SA-BE  
- **Brief:** เปรียบเทียบ [`liff-app` RegisterPage](../../liff-app/src/pages/RegisterPage.tsx); ฟิลด์ขั้นต่ำ + validation; เรียก `member.php` `register` / `update_profile`  
- **Done when:** user ใหม่สมัครจบในมินิแอปได้ หรือมี “ข้ามไปช้อปก่อน” ชัดเจน  

### WS-D — Deep link & OA content (G4)

- **Owner:** SA-LINE + SA-BE  
- **Brief:** แทนที่ `?page=order-detail&order_id=` ด้วย path ที่ Next ใช้ (หรือ redirect layer); อัปเดต Flex / ข้อความที่ส่งจากระบบ  
- **Done when:** กดจากข้อความแล้วเปิดหน้าถูกใน LIFF จริง  

### WS-E — แจ้งเตือนลูกค้า LINE (G5)

- **Owner:** SA-BE + SA-LINE  
- **Brief:** สำรวจว่ามี LINE push หลังสั่งซื้อหรือไม่; ถ้าไม่ — ออกแบบ message + จุดเรียก (หลัง insert transaction / เมื่อสถานะเปลี่ยน)  
- **Done when:** ลูกค้าได้รับข้อความตามสถานะที่กำหนด หรือเอกสาร “ใช้เฉพาะ Telegram” พร้อม sign-off  

### WS-F — Human contact & AI (G6)

- **Owner:** SA-LINE + product owner  
- **Brief:** flow คำสั่ง/keyword, คิว, เวลาทำการ; ไม่บังคับรวมในมินิแอปถ้าแก้ในแชทได้  
- **Done when:** เอกสาร one-pager + พฤติกรรมใน webhook สอดคล้อง  

### WS-G — Phase D routes (G7)

- **Owner:** SA-FE + SA-BE  
- **Brief:** backlog เป็นรายการ; เลือก 1–2 route ต่อสปรินต์  
- **Done when:** แต่ละ route มี “done” หรือ “not in scope”  

### WS-H — QA / UAT / Security (G8 + SA-SEC)

- **Owner:** SA-QA + SA-SEC  
- **Brief:** รันสคริปต์ B4 จาก checklist + เคสแอดไลน์ใหม่ + deep link; ตรวจ CORS/HTTPS/ข้อมูลอ่อนไหว  
- **Done when:** รายงาน UAT ผ่าน + ไม่มี blocker sec  

---

## 5. ซับฝ่ายรีวิว (Review lane)

| ลำดับ | ใคร | ทำอะไร |
|--------|-----|--------|
| R1 | **SA-REVIEW** | ทบทวน PR ตาม acceptance ของ workstream |
| R2 | **Orchestrator** | เทียบกับนิยาม 100% และ checklist |
| R3 | **SA-QA** | ทดสอบบนเครื่องจริงหลัง merge ไป staging |

**กฎ:** ไม่ merge ไป production โดยไม่มี R2 sign-off อย่างน้อยหนึ่งรอบสำหรับ release ใหญ่

---

## 6. ลองใช้จริง → ติผล → วนปรับ (Feedback loop)

1. **ทดสอบจริง:** ตาม B4 + เคส G2–G6 ที่เกี่ยวข้อง  
2. **บันทึก:** ตาราง Issue | ขั้นตอน | อุปกรณ์ | คาดหวัง | เกิดจริง | รูป/วิดีโอ  
3. **ติและจัดลำดับ:** P0/P1/P2  
4. **มอบหมายซ้ำ:** subagent เดิมหรือเฉพาะทาง  
5. **Exit “OK ที่สุด” เมื่อ:** ไม่มี P0/P1 ที่เกี่ยวกับ journey หลัก; P2 รับทราบหรือตั้งเป็น backlog  

---

## 7. Prompt สั้นๆ สำหรับ subagent (คัดลอกใช้ได้)

```
You are SA-[FE|BE|LINE|OPS|QA|SEC|REVIEW] working on workstream WS-[A–H] in repo odoo.
Read: docs/plans/2026-04-13-line-mini-app-100pct-subagent-orchestration.md and the linked checklist.
Scope: [paste G# items only].
Acceptance: [paste from section 4 for that WS].
Deliver: PR + short report: what changed, how to test, risks, screenshots if UI.
Do not expand scope beyond this workstream without orchestrator approval.
```

---

## 8. Checklist สำหรับ Orchestrator ก่อนปิดโปรเจกต์ “100%”

- [ ] G1–G8 แต่ละข้อ: Done / Deferred (พร้อมเหตุผล) / N/A  
- [ ] Staging = production config pattern (ยกเว้น key ลับ)  
- [ ] UAT รายงานฉบับล่าสุดแนบใน `docs/` หรือเครื่องมือทีม  
- [ ] เอกสาร OA (rich menu, welcome) อัปเดตให้ตรง URL จริง  

---

*เอกสารนี้เป็นแผน orchestration — ไม่แทนการตัดสินใจ product เรื่อง scope; ปรับลำดับ workstream ตามทรัพยากรและความเสี่ยงของร้านได้*
