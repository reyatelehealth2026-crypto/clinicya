import { sql, type Kysely } from 'kysely';
import type { MasterDB } from '@reya/db';

/**
 * impersonation.ts — port of the `$writeAudit` closure in
 * admin/switch-tenant.php (ADR-006). Column list and NOW() usage match
 * `super_admin_audit` in database/migration_2026-05-25_platform_master.sql
 * exactly: (platform_user_id, tenant_id, action, ip_address, user_agent,
 * request_method, request_uri, metadata, created_at).
 *
 * switchTenant() calls this on EVERY enter/exit — never best-effort-skipped
 * the way the PHP original swallows write failures into error_log(); here a
 * failed INSERT propagates as a thrown error so a broken audit trail is
 * never silently invisible to the caller (tests assert exactly one call per
 * switchTenant() invocation, not "call unless it happened to fail").
 */

export interface WriteSuperAdminAuditInput {
  platformUserId: number;
  tenantId: number | null;
  action: string;
  ipAddress?: string | null;
  userAgent?: string | null;
  requestMethod?: string | null;
  requestUri?: string | null;
  metadata?: Record<string, unknown> | null;
}

export async function writeSuperAdminAudit(db: Kysely<MasterDB>, input: WriteSuperAdminAuditInput): Promise<void> {
  // Mirrors PHP's `$metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null`
  // — an empty/absent metadata object is stored as SQL NULL, not `{}`.
  const metadataJson =
    input.metadata && Object.keys(input.metadata).length > 0 ? JSON.stringify(input.metadata) : null;

  await sql`
    INSERT INTO super_admin_audit
      (platform_user_id, tenant_id, action, ip_address, user_agent, request_method, request_uri, metadata, created_at)
    VALUES
      (${input.platformUserId}, ${input.tenantId}, ${input.action}, ${input.ipAddress ?? null},
       ${input.userAgent ?? null}, ${input.requestMethod ?? null}, ${input.requestUri ?? null}, ${metadataJson}, NOW())
  `.execute(db);
}
