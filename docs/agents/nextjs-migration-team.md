# ทีม Agent สำหรับแผนมิเกรท PHP → Next.js

ทีมนี้ประจำแผน `docs/plans/2026-07-12-nextjs-full-migration-plan.md` — ใช้ hand off งานรายเฟสให้ agent ที่เหมาะสมโดยตรง ไฟล์นิยาม agent อยู่ที่ `.claude/agents/mig-*.md`

## Mapping เฟส → Agent

| Agent | เฟสที่รับผิดชอบ | ขอบเขต |
|---|---|---|
| `mig-orchestrator` | ทุกเฟส (คุมลำดับ) | brief งาน, คุม route flips/canary ramp, co-sign เฉพาะเฟสเสี่ยงสูง |
| `mig-infra` | 0, 13 + edge/CI-CD | PHP Dockerfile, MariaDB import, DNS/TLS cutover, nginx routes.json, blue-green |
| `mig-kernel` | 1 + schema/storage workstreams | packages/db (pool registry, migrate-all, drift audit), tenant, auth + session bridge, admin shell/nav |
| `mig-ui` | 2, 9, 11 (UI), 12 (public) | port หน้า admin/public เป็น Server Components + Server Actions |
| `mig-api` | 3, 4, 5 | mini-app APIs (checkout ท้ายสุด), inbox-v2 + cursor contract, dispense + documents/VAT |
| `mig-line` | 6, 12 (webhooks) | webhook fast-ACK + BullMQ pipeline, packages/line, Flex golden fixtures, FB/TikTok/Telegram adapters |
| `mig-ai` | 7 | SSE pipeline + safety gates (fail-open) + event contract |
| `mig-worker` | 8, 10, 11 (jobs) | Odoo stack, cron → BullMQ (single-ownership manifest), provisioning/billing jobs, websocket รวมเหลือ 1 |
| `mig-verify` | ทุกเฟส (gate เดียว) | รัน acceptance criteria + parity + rollback drill แล้ว sign-off |

## Review flow แบบลดขั้นตอน (แทน 3 lane เดิมของทีม miniapp)

**เดิม:** R1 (`miniapp-review` code review) → R2 (orchestrator sign-off) → R3 (`miniapp-qa` UAT) = 3 ขั้นทุกงาน

**ใหม่: 1 ขั้นเป็นหลัก**

1. Specialist ส่งงานพร้อม **หลักฐานในตัว** (golden fixtures, parity report, property tests, screenshot diff) — เขียนเทสต์เองเป็นส่วนหนึ่งของ deliverable
2. `mig-verify` รัน **gate เดียว** ตาม acceptance criteria ของเฟสในแผน (§3) + verification harness (§7):
   - **PASS → merge + flip canary ได้เลย** สำหรับเฟสเสี่ยงต่ำ: 1, 2, 4, 8, 9, 10, 11, 12
   - **FAIL → ตีกลับครั้งเดียวพร้อม diagnosis** (จำแนก: contract drift / parity miss / หลักฐานขาด / rollback ไม่ได้ซ้อม); FAIL ซ้ำ batch เดิม escalate ไป orchestrator — ไม่มี review ping-pong
3. **เฉพาะเฟสเสี่ยงสูง** (0 VPS cutover, 3 checkout endpoint, 5 dispense/เลขเอกสาร, 6 LINE webhook, 7 AI SSE): PASS ของ mig-verify ต้องมี `mig-orchestrator` **co-sign ก่อน flip traffic production** — co-sign คือตรวจหลักฐาน parity + rollback drill ไม่ใช่ review โค้ดซ้ำ

หลักการ: ให้ automated gates (byte-diff fixtures, shadow parity ≥99.5–99.9%, property tests, canary ramp ต่อ tenant) ทำหน้าที่แทน manual review หลายชั้น — คนตัดสินเฉพาะจุดที่เครื่องตัดสินไม่ได้ (scope, ความพร้อม rollback, ผลกระทบผู้ใช้จริง)

## วิธี hand off

Hand off เฟสหนึ่งให้ระบุ: (1) เฟส/batch ที่ทำ (2) acceptance criteria ยกมาจากแผน (3) ไฟล์ PHP ที่จะ retire (4) กลไก cutover/rollback ตัวอย่าง:

```
ใช้ mig-kernel: ทำ Phase 1 batch แรก — packages/db pool registry + migrate-all runner
ตามแผน docs/plans/2026-07-12-nextjs-full-migration-plan.md §1.2, §4.1
Acceptance: codegen 280 ตารางผ่าน, pool LRU evict ทำงาน, migrate-all dry-run ครบทุก tenant
```
