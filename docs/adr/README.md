# Architecture Decision Records

ทะเบียนการตัดสินใจเชิงสถาปัตยกรรมของ Clinicya / REYA — บันทึกว่า **ทำไม** ระบบถึงเป็นแบบนี้ ไม่ใช่ว่ามันทำอะไร

## ทำไมโฟลเดอร์นี้ถึงเพิ่งมี

ADR-001, ADR-002 และ ADR-006 ถูกอ้างอิงในโค้ด **มากกว่า 30 จุด** ตั้งแต่พฤษภาคม 2026 — รวมถึง 3 migration ที่ระบุชื่อไฟล์ตรง ๆ ว่า `docs/adr/0001-database-per-tenant-isolation.md` และ `CLAUDE.md:224` ที่ระบุว่า *"one `CONTEXT.md` + `docs/adr/` at the repo root"*

แต่ **ไฟล์เหล่านั้นไม่เคยถูก commit เข้ารีโป** — ตรวจ `git log --all --diff-filter=D -- 'docs/adr/*'` แล้วไม่พบแม้แต่ประวัติการลบ นักพัฒนาและ AI agent ที่อ่านโค้ดเจอ `See ADR-006 §"Session model"` จึงไม่มีทางหาเอกสารนั้นได้

โฟลเดอร์นี้ปิดช่องว่างดังกล่าว โดย **สร้าง ADR ขึ้นใหม่จากโค้ดที่อ้างถึงมัน** ทุกไฟล์จึงมีสถานะ `Reconstructed from code, needs confirmation` และมีหัวข้อ `Known Gaps` ระบุสิ่งที่กู้คืนไม่ได้

## ทะเบียน ADR

| # | หัวข้อ | สถานะ | ถูกอ้างในโค้ด |
|---|---|---|---|
| [0001](0001-database-per-tenant-isolation.md) | Database-per-Tenant Isolation | Reconstructed, needs confirmation | ~25 จุด |
| [0002](0002-tenant-provisioning-and-entitlement.md) | Tenant Provisioning Flow + Entitlement Gating | **Accepted (2026-05-25)** | 1 จุด |
| [0003](0003-branch-model.md) | Branch Model (Multi-Branch Within Tenant) | **Accepted (2026-05-25)** | ไม่มี |
| [0004](0004-cron-execution-model.md) | Cron Job Execution Model (Per-Tenant Loop) | **Accepted (2026-05-25)** | ไม่มี |
| [0005](0005-file-storage-layout.md) | File Storage Layout + Signed URL Strategy | **Accepted (2026-05-25)** | ไม่มี |
| [0006](0006-super-admin-audit.md) | Super Admin Cross-Tenant Access + Audit | **Accepted (2026-05-25)** | 8 จุด |
| [0007](0007-two-realm-session-implementation.md) | Two-Realm Session Model — implementation drift จาก ADR-006 | Documents current code, needs confirmation | — |

### 0003–0005 ถูกพบแล้ว — ต้นฉบับอยู่บนเครื่องนักพัฒนา

ตอนกู้เอกสารรอบแรกเราสรุปว่า "ไม่มีโค้ดใดอ้าง ADR-003/004/005 จึงสงวนเลขไว้ ไม่แต่งเนื้อหา"

ภายหลังพบว่า **ต้นฉบับ 0002–0006 มีอยู่จริง** เป็นไฟล์ untracked บนเครื่องนักพัฒนา ลงวันที่ 2026-05-25 ทุกฉบับมี `Status: Accepted`, `Deciders: Platform Owner + Engineering` และหัวข้อ `Alternatives Considered` ครบ — ซึ่งคือข้อมูลที่ฉบับกู้คืนบอกว่า "กู้จากโค้ดไม่ได้"

สาเหตุที่ไม่เคยเข้ารีโปคือ bug ลำดับกฎใน `.gitignore` ตัวเดียวกับที่อธิบายไว้ข้างบน (`CONTEXT.md` ก็หายด้วยเหตุผลเดียวกัน)

**ผลที่ตามมา:**

- ADR-002 ถึง 006 ในโฟลเดอร์นี้เป็น **ต้นฉบับ** แล้ว ไม่ใช่ฉบับกู้คืน
- ฉบับกู้คืนของ 0002 ถูกลบ เพราะต้นฉบับครอบคลุมเรื่องเดียวกันและมีหัวข้อที่โค้ดอ้าง (`§"Provisioning Pipeline"`) อยู่ครบ
- ฉบับกู้คืนของ 0006 **ไม่ถูกลบ** แต่ย้ายไปเป็น [ADR-007](0007-two-realm-session-implementation.md) เพราะเทียบแล้วพบว่า **โค้ดจริงเดินห่างจาก ADR-006** — ต้นฉบับพูดถึง `$_SESSION['acting_tenant_id']` ส่วนโค้ดปัจจุบันใช้ two-realm cookie ซึ่งต้นฉบับไม่เอ่ยถึงเลย
- **ADR-001 ยังไม่พบต้นฉบับ** จึงยังเป็นฉบับกู้คืนจากโค้ดอยู่
- ADR ใหม่ยังคงเริ่มที่ **0008** เป็นต้นไป

## Convention

ยึดตามที่โค้ดอ้างอิงอยู่แล้ว — **ห้ามเปลี่ยน** เพราะจะทำให้ reference ในโค้ดเสียหาย

| หัวข้อ | กติกา |
|---|---|
| ตำแหน่ง | `docs/adr/` ที่ root ของรีโป |
| ชื่อไฟล์ | `NNNN-kebab-case-title.md` — เลข 4 หลักเติมศูนย์หน้า |
| การอ้างในโค้ด | `ADR-NNN` (3 หลัก มีขีด) เช่น `ADR-001`, `ADR-006` |
| การอ้างหัวข้อย่อย | `ADR-NNN §"ชื่อหัวข้อ"` เช่น `ADR-006 §"Session model"` |
| หัวเรื่องไฟล์ | `# ADR-NNN: Title In English` |
| ภาษา | **หัวข้อภาษาอังกฤษ เนื้อหาภาษาไทย** ตาม convention bilingual ของโปรเจกต์ (`CLAUDE.md:7`) |

### ⚠️ ข้อสำคัญ: หัวข้อที่ถูกอ้างต้องมีอยู่จริงในไฟล์

โค้ดอ้างหัวข้อย่อยด้วยชื่อ ถ้าเปลี่ยนชื่อหัวข้อ reference จะเสีย หัวข้อที่ **ถูกล็อกไว้แล้ว** โดยโค้ด:

| หัวข้อ | ADR | ผู้อ้าง |
|---|---|---|
| `Connection routing` | 0001 | `database/migration_2026-05-25_platform_master.sql:84` |
| `Hosting constraint` | 0001 | `classes/TenantContext.php:36` |
| `Provisioning Pipeline` | 0002 | `classes/TenantProvisioning.php:406` |
| `Session model` | 0006 | `admin/switch-tenant.php:5`, `includes/auth_check.php:60` |

### โครงสร้างไฟล์มาตรฐาน

```markdown
# ADR-NNN: Title

## Status          — Proposed | Accepted | Reconstructed, needs confirmation
                     | Superseded by ADR-XXX | Deprecated
## Date
## Context         — สถานการณ์และข้อจำกัดที่บีบให้ต้องตัดสินใจ
## Decision        — ตัดสินใจอะไร (หัวข้อย่อยที่โค้ดอ้างอยู่ตรงนี้)
## Alternatives Considered  — ทางเลือกและเหตุผลที่ปฏิเสธ
## Consequences    — ผลที่ตามมา ทั้งดีและเสีย รวมหนี้ที่ยังค้าง
## Evidence        — file path → symbol/บรรทัด ของทุกข้ออ้าง
## Known Gaps      — สิ่งที่ยังยืนยันไม่ได้ (ถ้ามี)
## Last Verified From Code
## Related
```

หัวข้อ `Evidence` และ `Last Verified From Code` เป็น convention เฉพาะของโปรเจกต์นี้ (สืบทอดจาก `docs/ai/adrs/`) — ทุกข้ออ้างต้องตามรอยกลับไปยังไฟล์และบรรทัดได้ เพื่อให้ผู้อ่านคนถัดไปตรวจสอบได้ว่าเอกสารยังตรงกับโค้ดอยู่หรือไม่

## Lifecycle

```
PROPOSED → ACCEPTED → (SUPERSEDED by ADR-XXX | DEPRECATED)
```

- **ห้ามลบ ADR เก่า** — มันคือบันทึกประวัติศาสตร์ว่าทำไมเคยตัดสินใจแบบนั้น
- เมื่อการตัดสินใจเปลี่ยน ให้เขียน ADR ใหม่ที่อ้างถึงและ supersede ฉบับเดิม
- ADR ที่มีสถานะ `Reconstructed` ควรถูกเปลี่ยนเป็น `Accepted` เมื่อผู้ตัดสินใจเดิมยืนยันเนื้อหาแล้ว

## ความสัมพันธ์กับ `docs/ai/adrs/`

มีสองโฟลเดอร์ที่หน้าตาคล้ายกัน **คนละวัตถุประสงค์** อย่าสับสน:

| | `docs/adr/` (โฟลเดอร์นี้) | `docs/ai/adrs/` |
|---|---|---|
| สถานะ | **Canonical** — คือชุดที่โค้ดอ้างถึง | ส่วนหนึ่งของ AI knowledge base |
| ที่มา | สร้างใหม่จากโค้ดที่อ้าง ADR-001/002/006 | AI สำรวจโค้ดแล้วอนุมานเอง (2026-07-03) |
| โค้ดอ้างถึงไหม | **ใช่** 30+ จุด | **ไม่** ไม่มีที่ใดอ้างเลย |
| รูปแบบเลข | `ADR-001` (3 หลัก มีขีด) | `ADR 0001` (4 หลัก เว้นวรรค) |
| สถานะเนื้อหา | Reconstructed, needs confirmation | `Inferred, needs confirmation.` |

**เลขชนกันโดยบังเอิญ ไม่ใช่เรื่องเดียวกัน:**

| เลข | `docs/adr/` | `docs/ai/adrs/` | ชนกันไหม |
|---|---|---|---|
| 0001 | Database-per-Tenant Isolation | Tenant-Aware Database Routing | หัวข้อทับซ้อน — ฉบับ canonical ครอบคลุมกว้างกว่าและมี §"Hosting constraint" / §"Connection routing" ที่โค้ดอ้าง |
| 0002 | Tenant Provisioning Pipeline | Shared LINE Mini App Runtime Tenant Resolution | **คนละเรื่องสิ้นเชิง** |
| 0003 | Branch Model | Prefer LINE Reply Then Push Fallback | คนละเรื่อง |

`docs/ai/adrs/0002` และ `0003` บันทึกการตัดสินใจที่ **ยังไม่มี ADR canonical ครอบคลุม** และมีคุณค่าในตัวเอง หากต้องการยกระดับเป็น canonical ให้ยืนยันเนื้อหากับผู้ตัดสินใจก่อน แล้วเขียนใหม่ที่เลข **0008 เป็นต้นไป**

## การเพิ่ม ADR ใหม่

1. ใช้เลขถัดไปที่ว่าง — **เริ่มที่ 0008**
2. ตั้งชื่อไฟล์ `NNNN-kebab-case-title.md`
3. ใช้โครงสร้างมาตรฐานข้างต้น หัวข้ออังกฤษ เนื้อหาไทย
4. ทุกข้ออ้างต้องมี `Evidence` ชี้ไฟล์และบรรทัด
5. เพิ่มแถวในทะเบียนด้านบน
6. เมื่ออ้างจากโค้ด ใช้รูปแบบ `ADR-NNN` หรือ `ADR-NNN §"Section"` และตรวจว่าหัวข้อนั้นมีอยู่จริงในไฟล์

## เอกสารที่เกี่ยวข้อง

- [`CLAUDE.md`](../../CLAUDE.md) — คู่มือสถาปัตยกรรมสำหรับ agent สรุป ADR-001 ไว้ที่ §Multi-Tenant SaaS
  - ⚠️ `CLAUDE.md:224` อ้างถึง `CONTEXT.md` ที่ root ด้วย แต่ **ไฟล์นั้นยังไม่มีอยู่จริง** — เป็น dangling reference อีกจุดที่ยังไม่ได้แก้ในรอบนี้ (นอกขอบเขต ADR)
- [`docs/ai/`](../ai/README.md) — AI knowledge base (architecture, api-contracts, database-schema, known-risks ฯลฯ)
- [`docs/plans/2026-07-12-nextjs-full-migration-plan.md`](../plans/2026-07-12-nextjs-full-migration-plan.md) — แผน migration ไป Next.js **บรรจุการตัดสินใจสำคัญที่ยังไม่ถูกยกระดับเป็น ADR** (Kysely แทน Prisma, stateful session แทน JWT, Strangler pattern, Docker/VPS, monorepo)
- [`docs/plans/2026-08-14-golive-readiness-audit.md`](../plans/2026-08-14-golive-readiness-audit.md) — สถานะความพร้อม go-live
