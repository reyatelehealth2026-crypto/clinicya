# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

This is a **single-context** repo: one `CONTEXT.md` + `docs/adr/` at the root.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root (REYA pharmacy SaaS domain glossary, bilingual Thai/English).
- **`docs/adr/`** — read ADRs that touch the area you're about to work in (e.g. `0001-database-per-tenant-isolation.md`, `0002-tenant-provisioning-and-entitlement.md`, `0003-branch-model.md`, `0004-cron-execution-model.md`, `0005-file-storage-layout.md`, `0006-super-admin-audit.md`).

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The producer skill (`/grill-with-docs`) creates them lazily when terms or decisions actually get resolved.

## File structure

```
/
├── CONTEXT.md
├── docs/adr/
│   ├── 0001-database-per-tenant-isolation.md
│   └── 0002-tenant-provisioning-and-entitlement.md
└── ...
```

If this ever becomes a multi-context layout, add a `CONTEXT-MAP.md` at the root pointing at one `CONTEXT.md` per context, and check `src/<context>/docs/adr/` for context-scoped decisions.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal — either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/grill-with-docs`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0001 (database-per-tenant isolation) — but worth reopening because…_
