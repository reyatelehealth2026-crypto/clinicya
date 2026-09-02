# ADR 0001: Tenant-Aware Database Routing

## Status

Inferred, needs confirmation.

> **หมายเหตุ (2026-09-02):** ADR ที่โค้ดอ้างถึงจริงในชื่อ `ADR-001` คือ [`docs/adr/0001-database-per-tenant-isolation.md`](../../adr/0001-database-per-tenant-isolation.md) ซึ่งครอบคลุมหัวข้อเดียวกันแต่กว้างกว่า และมีหัวข้อย่อย §"Hosting constraint" / §"Connection routing" ที่โค้ดอ้างถึงโดยตรง ไฟล์นี้เป็นเอกสาร AI-inferred ที่ไม่มีโค้ดใดอ้างถึง — ดู [`docs/ai/adrs/README.md`](README.md)

## Context

`modules/Core/Database.php` implements `getInstance()`, `forTenant()`, and `platform()`; it resolves tenant DB names from `tenants.db_name` and supports platform context. `classes/Database.php` proxies legacy global `Database` calls to the tenant-aware factory but warns about class collision with `config/database.php` and load-order-dependent behavior.

## Decision

The system is moving from a legacy shared database singleton toward explicit tenant/platform routing through `TenantContext` and `Modules\Core\Database`.

## Consequences

- New code can call `Database::forTenant($tenantId)` or `Database::platform()`.
- Legacy code may still fall back to shared DB when no tenant context is set.
- Consolidating database bootstrap is a high-priority risk reduction.

## Evidence

- `modules/Core/Database.php`: `Database::getInstance`, `Database::forTenant`, `Database::platform`, `resolveTenantDbName`.
- `classes/Database.php`: warning about class collision with `config/database.php`.
- `includes/auth_check.php`: tenant session resolution and platform override rules.
- `database/migration_2026-05-25_platform_master.sql`: `tenants.db_name`, `platform_users`, tenant migration tables.

## Last Verified From Code

Verified on 2026-07-03 from `modules/Core/Database.php`, `classes/Database.php`, `includes/auth_check.php`, `database/migration_2026-05-25_platform_master.sql`.
