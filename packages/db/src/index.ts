export * from './masterPool';
export * from './tenantPoolRegistry';
export * from './migrateAll';
export * from './codegen';

// kysely-codegen names the generated interface `DB` in BOTH output files
// (no --interface-name flag exists to rename it at generation time) —
// re-export each under a distinct alias so downstream packages (e.g.
// @reya/auth) can import the concrete type without reaching into
// './generated/*' directly. Named re-export, not `export *`: both generated
// files also independently declare `Decimal`/`Generated`/etc. utility types
// that would collide if star-exported together.
export type { DB as MasterDB } from './generated/master-db';
export type { DB as TenantDB } from './generated/tenant-db';
