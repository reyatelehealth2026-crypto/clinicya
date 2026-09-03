# ADR-003: Branch Model (Multi-Branch Within Tenant)

**Status:** Accepted (2026-05-25)
**Deciders:** Platform Owner + Engineering
**Supersedes:** Implicit "1 Tenant = 1 physical location" assumption

---

## Context

CONTEXT.md introduces the **Branch** layer:

> **Branch** = สถานที่ตั้งของ Tenant. Default ทุก Tenant เริ่มต้นที่ 1 Branch.
> การเปิด Branch เพิ่มต้อง entitlement (Platform Owner อนุมัติ).
> 1 Branch = 1 ใบอนุญาตเภสัช + 1 รหัสสาขา ก.พ. 30 (`00000` = สำนักงานใหญ่).

The codebase today has **no concept of Branch** at all. Every transactional
row (a sale, a dispense, a stock movement, a tax document) implicitly
belongs to "the one location of this Tenant." This works today because:

- All current Tenants happen to have exactly one physical location
- All pharmacist licenses are tied to one address
- Thai e-tax documents (ใบกำกับภาษี) carry a 5-digit branch suffix on the
  Tax ID, but everyone has been using `00000` (สำนักงานใหญ่)

The constraint changes the moment a real customer says "I have a main
store in Bangkok and a second store in Chiang Mai":

- Each Branch has its **own pharmacist license** (เลขที่ใบอนุญาต)
- Each Branch has its **own 5-digit branch code** for tax documents
- **Stock is per-Branch** (can't dispense from Bangkok if customer is at
  Chiang Mai counter)
- **Reports are per-Branch AND consolidated** (owner wants both views)
- **Staff are per-Branch** (a pharmacist may work at one branch only)

We need to introduce Branch as a first-class concept inside the tenant DB,
not in the master DB — Branch is a Tenant-internal hierarchy, not a
Platform-level concept.

## Decision

Add a `branches` table to the tenant template and a nullable `branch_id`
foreign key on every transactional / inventory / document table. Default
Branch (id=1, code='00000') is auto-created at Tenant provisioning. Multi-
branch is **entitlement-gated** via `max_branches` (ADR-002).

### Schema — `reya_tenant_NNNN.branches`

```sql
CREATE TABLE branches (
  id                    INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id             INT UNSIGNED NOT NULL,
  -- ^ Redundant copy of platform.tenants.id; lets us spot data
  --   in the wrong DB during ops/debug. Validated at write time.
  code                  CHAR(5) NOT NULL,
  -- ^ Thai tax branch code. '00000'=สำนักงานใหญ่, '00001','00002'...
  name_th               VARCHAR(255) NOT NULL,
  name_en               VARCHAR(255) NULL,
  address_th            TEXT NULL,
  address_en            TEXT NULL,
  tax_id_branch_suffix  CHAR(5) NOT NULL,
  -- ^ Same as `code` 99% of the time, separated in case of edge tax setup.
  license_number        VARCHAR(64) NULL,
  -- ^ เลขที่ใบอนุญาตเภสัช (ภญ./ภก.) — printed on rx labels & tax docs.
  license_holder_name   VARCHAR(255) NULL,
  -- ^ ชื่อเภสัชกรผู้มีหน้าที่ปฏิบัติการ (printed on labels)
  phone                 VARCHAR(32) NULL,
  email                 VARCHAR(255) NULL,
  timezone              VARCHAR(64) NOT NULL DEFAULT 'Asia/Bangkok',
  is_default            TINYINT(1) NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  opened_at             DATE NULL,
  closed_at             DATE NULL,
  notes                 TEXT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant_code (tenant_id, code),
  KEY idx_active (tenant_id, is_active)
) ENGINE=InnoDB;
```

**Invariants enforced by app code (not DB constraints, since cross-DB):**

- Exactly one row per Tenant has `is_default=1`
- The default Branch cannot be deactivated or deleted
- `code='00000'` reserved for the default Branch (สำนักงานใหญ่)
- Sibling Branches use `00001`, `00002`, ... (monotonic, gaps allowed)

### Provisioning seed (ADR-002 step 6)

```sql
INSERT INTO branches
  (tenant_id, code, name_th, tax_id_branch_suffix, is_default, is_active, opened_at)
VALUES
  ($tenantId, '00000', 'สำนักงานใหญ่', '00000', 1, 1, CURDATE());
```

The provisioning script then captures the inserted `id` (always 1 on a
fresh DB) and uses it as the default for follow-up seeds (admin user's
branch list, etc.).

### Tables that grow `branch_id`

Every table that records *something that physically happened at a place*
gets a nullable `branch_id INT UNSIGNED NULL` column with an index. Initial
backfill: assign all existing rows to the default Branch (id=1).

| Table | Why | Backfill strategy |
|-------|-----|-------------------|
| `transactions`             | A sale happens at a Branch | UPDATE SET branch_id=1 |
| `dispensing_records`       | Pharmacist is licensed per Branch | UPDATE SET branch_id=1 |
| `cart`                     | Cart belongs to a Branch's stock | UPDATE SET branch_id=1 |
| `business_items` (stock)   | Stock is physical, per-Branch | **See note below** |
| `stock_movements`          | Movement is between/within Branches | UPDATE SET branch_id=1 |
| `purchase_orders` (PO)     | PO ships to a specific Branch | UPDATE SET branch_id=1 |
| `goods_receives` (GR)      | GR happens at a Branch's loading dock | UPDATE SET branch_id=1 |
| `business_documents`       | Tax docs carry Branch code | UPDATE SET branch_id=1 |
| `document_lines`           | Inherits from header | (via JOIN) |
| `dispensing_records_items` | Inherits | (via JOIN) |
| `payment_slips`            | Payment attributed to Branch | UPDATE SET branch_id=1 |
| `consultation_sessions`    | Telepharmacy session = which Branch's pharmacist | UPDATE SET branch_id=1 |
| `triage_sessions`          | Same as above | UPDATE SET branch_id=1 |
| `appointments`             | Appointment at a Branch | UPDATE SET branch_id=1 |

**Tables that DO NOT get `branch_id` (intentional):**

- `customers` / `users` — a customer belongs to the Tenant, can visit any
  Branch. Customer's *preferred* Branch is a separate concept (future).
- `business_messages` / `messages` — conversation is Tenant-scoped, the
  individual dispense within the conversation carries Branch.
- `ai_settings`, `notification_settings`, `shop_tax` — Tenant-wide config.
- `line_accounts` — Channel ≠ Branch. A LINE OA serves all Branches.

**Stock note (`business_items`):** Stock is special. Either:

- **(a)** Add `branch_id` to `business_items` → each Branch has its own SKU
  rows (recommended; simpler; matches how POS systems work)
- **(b)** Add a separate `business_items_stock_per_branch` table → catalogue
  is shared, on-hand qty is split

We choose **(a)** for v1: each Branch maintains its own item rows. Catalogue
duplication is cheap at <10 Branches per Tenant; cross-Branch reports JOIN
by `(name, sku)`. Reconsider when a Tenant hits 5+ Branches.

### Cross-Branch Operations

#### Stock transfer (โอนสินค้าระหว่างสาขา)

New table:

```sql
CREATE TABLE stock_transfers (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NOT NULL,
  from_branch_id INT UNSIGNED NOT NULL,
  to_branch_id   INT UNSIGNED NOT NULL,
  status        ENUM('draft','in_transit','received','cancelled') NOT NULL,
  shipped_at    TIMESTAMP NULL,
  received_at   TIMESTAMP NULL,
  notes         TEXT NULL,
  created_by    INT UNSIGNED NOT NULL,
  received_by   INT UNSIGNED NULL,
  KEY idx_branches (from_branch_id, to_branch_id, status)
);

CREATE TABLE stock_transfer_lines (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  transfer_id   INT UNSIGNED NOT NULL,
  item_id_from  INT UNSIGNED NOT NULL,  -- business_items.id at source
  item_id_to    INT UNSIGNED NULL,      -- linked at receive time
  qty           DECIMAL(12,3) NOT NULL,
  KEY (transfer_id)
);
```

State machine: `draft → in_transit → received` (or `cancelled` from draft).
On `received`, atomically: decrement source stock, increment destination
stock, write two `stock_movements` rows.

#### Multi-branch consolidated reports

Reports take an optional `branch_ids[]` filter. Default = all Branches the
current user has access to. Implementation:

```php
$branchFilter = empty($branchIds)
    ? "1=1"
    : "branch_id IN (" . implode(',', array_map('intval', $branchIds)) . ")";
$sql = "SELECT ... FROM transactions WHERE $branchFilter AND created_at BETWEEN ...";
```

UI exposes a Branch picker at the top of every report page; remembered in
session per (user, report).

### Branch-scoped permissions

Add to existing tenant `platform_users` table:

```sql
ALTER TABLE platform_users
  ADD COLUMN branch_access JSON NULL
  COMMENT 'NULL = all branches; ["1","3"] = restricted to Branch ids 1 and 3';
```

Resolution rule:

- `role='admin'` (Tenant Owner) — `branch_access` is forced to NULL = all
- `role IN ('pharmacist','marketing','tech','staff')` — `branch_access`
  defaults to `[1]` (default Branch) and is editable by Tenant Owner
- API/page entry checks: `Branch::userCanAccess($userId, $branchId)` →
  bool; called at the top of any controller that loads Branch-scoped data
- Branch picker UI shows only Branches the user can access

### Entitlement gate (from ADR-002)

When Tenant Owner clicks "เพิ่มสาขา" in `/branches`:

```php
$tenantId = TenantContext::currentTenantId();
$currentCount = Branch::countActive($tenantId);
$max = Entitlement::getInt($tenantId, 'max_branches');   // ADR-002

if ($currentCount >= $max) {
    // UI hides the button outright; this branch is a defense-in-depth.
    throw new EntitlementDeniedException(
        "แพ็กเกจของท่านอนุญาตสูงสุด {$max} สาขา " .
        "กรุณาติดต่อทีมงาน REYA เพื่ออัปเกรด"
    );
}
```

If a Tenant later downgrades (Pro → Starter) and exceeds `max_branches`,
ADR-002 open question #2 applies — not decided here.

### Migration plan (existing 2 Tenants)

1. Add `branches` table to template SQL
2. Write `database/migration_2026-05-26_add_branches.sql`:
   ```sql
   CREATE TABLE branches (...);
   INSERT INTO branches (id, tenant_id, code, name_th, tax_id_branch_suffix,
                         is_default, is_active)
     VALUES (1, @tenant_id, '00000', 'สำนักงานใหญ่', '00000', 1, 1);
   -- @tenant_id substituted by migration runner per Tenant DB
   ```
3. Write `database/migration_2026-05-26_add_branch_id_columns.sql`:
   - `ALTER TABLE transactions ADD COLUMN branch_id INT UNSIGNED NULL,
      ADD INDEX idx_branch (branch_id);`
   - (Repeat for every table in the list above)
   - `UPDATE <table> SET branch_id = 1 WHERE branch_id IS NULL;`
4. Run via the per-Tenant migration runner from ADR-001
5. Application updates ship in subsequent PRs, gated by feature flag
   `BRANCH_UI_ENABLED` until reports + transfer flow are ready
6. Once stable: `ALTER TABLE <table> MODIFY branch_id INT UNSIGNED NOT NULL`
   to lock in the invariant

### UI sketch

`/branches` — list view (Tenant Owner only):

```
┌─ สาขาทั้งหมด (2/3) ────────────────────── [+ เพิ่มสาขา] ─┐
│  รหัส   ชื่อ                ที่อยู่             สถานะ      │
│  00000  สำนักงานใหญ่         กรุงเทพ           ●  เปิด    │
│  00001  สาขาเชียงใหม่        เชียงใหม่         ●  เปิด    │
└──────────────────────────────────────────────────────┘
```

`/branches/{id}` — detail / edit (license, address, hours)
`/branches/{id}/staff` — assign users
`/branches/transfers` — stock transfer flow

## Consequences

### Positive

- **Real multi-location pharmacies become addressable customers** — opens
  the upmarket segment previously blocked.
- **Tax document compliance** improves — branch suffix on Tax ID can now
  reflect reality (`0105556123456-00001` for a real second Branch).
- **Stock theft / shrinkage audit** possible per Branch — manager sees
  which location is leaking inventory.
- **Branch-scoped staff** prevents accidental cross-Branch dispense.
- **Default Branch keeps existing single-location Tenants unchanged** —
  no UI surfaced if `max_branches=1`.

### Negative

- **Every report query gains a `branch_id` filter** — N+1 risk if joined
  carelessly. Need composite index `(tenant_table_pk, branch_id)` patterns.
- **Backfill is irreversible** — once we set `branch_id=1` on a million
  rows, splitting them out post-hoc is painful.
- **Stock model duplication (option a)** — same SKU appears N times for N
  Branches; product master maintenance is N× work. Mitigation: a
  "publish to all Branches" bulk action in Products UI.
- **Branch picker fatigue** — UI is more complex even for single-Branch
  Tenants. Mitigation: hide entire picker when `count(branches)==1`.
- **Cross-Branch reports need UNION-by-app** for the report-builder when a
  Tenant operates in multiple Branches with item-id divergence; complicates
  ad-hoc SQL.

### Neutral / Tradeoffs accepted

- We chose stock model (a) over (b). Revisit if any Tenant hits 5+ Branches
  AND complains about catalogue maintenance.
- `branches.tenant_id` is redundant inside a tenant DB but is a cheap
  defense against ops mistakes (e.g. importing a backup into the wrong DB
  would show up immediately).
- Branch deletion is not supported in v1 — only deactivation. Real
  pharmacy closures keep history forever.

## Alternatives Considered

### Branch as separate Tenant
Rejected: each Branch as its own Tenant DB would multiply isolation cost
(50 Tenants × 3 Branches = 150 DBs) and break consolidated reporting at the
Tenant Owner level. Branches share customers and conversations; they are
not isolation units.

### Branch as just a tag on `transactions` (no first-class table)
Rejected: pharmacist license, address, tax suffix need somewhere to live;
JSON in a settings blob would invite drift.

### Stock model (b) — separate `stock_per_branch` join table
Considered, deferred. Better for >5 Branches but more code right now.

### Auto-create N Branches on Tenant signup based on a sales-call form
Rejected: violates ADR-002 "provisioning is deterministic" — better to
provision 1 then add Branches as a follow-up explicit action.

## Open questions

> Surfaced for the next grilling round.

1. **Branch code uniqueness scope.** `(tenant_id, code)` unique today. But
   Thai tax law allows the SAME branch code at different Tax IDs (which
   are separate legal entities). If one human owns two Tenants both with
   `00000`, our system handles it (different DBs). Confirm this is the
   intent.
2. **Default Branch rename.** Can the Tenant Owner change "สำนักงานใหญ่"
   to "สาขากรุงเทพ" if they move the head office? Today: yes; but the code
   stays `00000`. OK?
3. **License expiry / renewal tracking.** Pharmacist licenses expire.
   Should we have an `expires_at` on `branches.license_number` and warn
   30 days before? Or out of scope for v1?
4. **Stock model decision threshold.** When does a Tenant trigger
   migration from model (a) to (b)? Right now no rule; should we make it
   automatic at 5+ Branches?
5. **Cross-Branch customer view.** A customer dispensed at Branch 00000
   then visits Branch 00001 — does the pharmacist at 00001 see the 00000
   dispense history? Privacy / KYC says yes (it's the same Tenant). UI
   says yes. Branch-scoped staff permission says... only if they have
   access to both Branches? Decide.
6. **Receipt printer per Branch.** Physical hardware varies per location;
   does the dispense flow need a Branch-aware printer queue? Or is that
   purely client-side (browser picks printer)?
7. **Migrating `branch_id` from NULL to NOT NULL.** When? After how many
   weeks of UI being live without complaint? Lock-in is one-way.

## Related decisions

- **ADR-001:** Database-per-Tenant (Branch lives inside Tenant DB)
- **ADR-002:** Provisioning (seeds default Branch); `max_branches` entitlement
- **ADR-004:** Cron jobs may need per-Branch fan-out (e.g. low-stock alerts)
- **ADR-006:** Super admin audit captures cross-Branch operations
