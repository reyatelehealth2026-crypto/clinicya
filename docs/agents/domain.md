# Domain docs — where knowledge lives, and which file wins

อ้างถึงจาก [`CLAUDE.md`](../../CLAUDE.md) §"Domain docs"

## Single-context rule

รีโปนี้มีเอกสารเยอะมาก (269 ไฟล์ `.md`) กติกาคือ **มีไฟล์บริบทหลักหนึ่งไฟล์ + ทะเบียนการตัดสินใจหนึ่งชุด** ที่เหลือคือเอกสารเฉพาะกิจ

| ชั้น | ไฟล์ | ตอบคำถามอะไร | ใครอ่าน |
|---|---|---|---|
| **บริบทหลัก** | [`CLAUDE.md`](../../CLAUDE.md) | ระบบนี้คืออะไร entry point อยู่ไหน รันคำสั่งอะไร | โหลดอัตโนมัติทุก session |
| **การตัดสินใจ** | [`docs/adr/`](../adr/README.md) | **ทำไม**ถึงเป็นแบบนี้ และเคยปฏิเสธทางเลือกไหนไป | ตามลิงก์จากโค้ด / CLAUDE.md |
| **ความรู้เชิงลึก** | [`docs/ai/`](../ai/README.md) | architecture, api-contracts, database-schema, known-risks | ตามลิงก์เมื่อต้องการ |
| **แผนงาน** | [`docs/plans/`](../plans/) | จะทำอะไรต่อ เรียงลำดับยังไง | ตอนวางแผน/รีวิวเฟส |
| **ขั้นตอนปฏิบัติ** | [`docs/runbooks/`](../runbooks/) | ทำ cutover/parity check ยังไงทีละขั้น | ตอนลงมือทำจริง |

### ลำดับความน่าเชื่อถือเมื่อขัดแย้งกัน

```
โค้ดจริง  >  docs/adr/  >  CLAUDE.md  >  docs/ai/  >  docs/plans/  >  เอกสารที่ root
```

เหตุผล: `docs/ai/` เป็นเอกสารที่ AI สำรวจโค้ดแล้วอนุมาน (สถานะ `Inferred, needs confirmation.` ลงวันที่ 2026-07-03)
ส่วน `docs/plans/` บันทึก *เจตนา* ซึ่งอาจยังไม่ได้ลงมือทำ — go-live audit ยืนยันว่าโค้ด Next.js ที่พอร์ตแล้ว
"ยังถูกปิดสวิตช์ทั้งหมด" ดังนั้นอย่าอ่านแผนแล้วสรุปว่าเกิดขึ้นแล้ว

## `CONTEXT.md` — ยังไม่มี

`CLAUDE.md` §"Domain docs" เขียนว่า *"one `CONTEXT.md` + `docs/adr/` at the repo root"* แต่ **`CONTEXT.md` ไม่มีอยู่จริง**

สาเหตุเดียวกับที่ `docs/adr/` หายไปนาน: `.gitignore` มีกฎ blanket `*.md` และ negation `!CONTEXT.md` ถูกวางไว้
**เหนือ** กฎนั้น ซึ่งใน gitignore กฎหลังสุดชนะ — negation จึงตายสนิท ไฟล์ถูกเขียนแล้วแต่ `git add` เงียบ ๆ ไม่เข้ารีโป
(แก้ลำดับแล้วเมื่อ 2026-09-02 ทั้ง `!docs/adr/*.md` และ `!CONTEXT.md`)

**ปัจจุบัน `CLAUDE.md` ทำหน้าที่บริบทหลักแทน** หากจะสร้าง `CONTEXT.md` จริงต้องตัดสินใจก่อนว่ามันต่างจาก `CLAUDE.md`
อย่างไร ไม่งั้นจะกลายเป็นไฟล์ที่สองที่พูดเรื่องเดียวกันแล้วเริ่ม drift

## ADR — กติกาโดยย่อ

รายละเอียดเต็มที่ [`docs/adr/README.md`](../adr/README.md) สรุปที่ต้องรู้:

- อ้างในโค้ดด้วยรูปแบบ `ADR-NNN` หรือ `ADR-NNN §"ชื่อหัวข้อ"`
- **ห้ามเปลี่ยนชื่อหัวข้อที่โค้ดอ้างถึง** — `Connection routing`, `Hosting constraint`, `Provisioning Pipeline`,
  `Session model` ถูกล็อกไว้แล้ว
- ADR ใหม่เริ่มที่ **0007** (0003–0005 สงวนไว้ ไม่มีโค้ดใดอ้างถึง จึงไม่แต่งเนื้อหาให้)
- `python3 scripts/verify-adrs.py` ตรวจว่า reference ทั้งหมดยัง resolve ได้ รันอัตโนมัติใน CI
  ([`.github/workflows/adr-check.yml`](../../.github/workflows/adr-check.yml))

## เอกสารที่ root — เป็นหนี้ ไม่ใช่โครงสร้าง

root มีไฟล์ `.md` **38 ไฟล์** ส่วนใหญ่เป็น `TASK_*_SUMMARY.md` และคู่มือ deploy ที่ทับซ้อนกัน 6 ฉบับ
(`DEPLOYMENT_GUIDE.md`, `DEPLOY_INSTRUCTIONS.md`, `DEPLOY_SUMMARY.md`, `DEPLOY_TO_GITHUB.md`,
`QUICK_DEPLOY_GUIDE.md`, `GITHUB_PUSH_GUIDE.md`) ทั้งหมดเป็นบันทึกงานที่เสร็จแล้ว ไม่ใช่เอกสารอ้างอิงที่มีชีวิต

**อย่าเพิ่มไฟล์ใหม่ที่ root** — เอกสารใหม่ควรลงในโฟลเดอร์ตามตารางข้างบน

## สำหรับ AI agent

- [`.claude/agents/*.md`](../../.claude/agents/) — 20 subagent definitions แต่ละตัวมีหัวข้อ `**Mandatory reads**`
  ตัวที่ทำงานแตะ tenant routing / provisioning / session ถูกชี้ไป ADR ที่เกี่ยวข้องแล้ว
- [`.github/copilot-instructions.md`](../../.github/copilot-instructions.md) — สำหรับ GitHub Copilot
- เมื่อพอร์ตโค้ดที่มี comment อ้าง ADR ให้อ่าน ADR นั้นก่อนเปลี่ยนพฤติกรรม และถ้าจำเป็นต้องขัดกับ ADR
  ให้เขียน ADR ใหม่ที่ supersede ฉบับเดิม อย่าเปลี่ยนเงียบ ๆ
