import { getMasterDb } from '@reya/db';
import { sql } from 'kysely';
import type { TenantRepository, TenantRow, TenantStatus } from './resolveTenant';

interface TenantsTableRow {
  id: number;
  status: TenantStatus;
  display_name: string;
}

/**
 * Default TenantRepository backed by master.tenants via @reya/db's shared
 * master Kysely instance. Mirrors the query in resolve_subdomain.php exactly:
 *   SELECT id, status, display_name FROM tenants WHERE slug = ? LIMIT 1
 */
export function createMasterTenantRepository(): TenantRepository {
  return {
    async findBySlug(slug: string): Promise<TenantRow | null> {
      const db = getMasterDb();
      const result = await sql<TenantsTableRow>`
        SELECT id, status, display_name FROM tenants WHERE slug = ${slug} LIMIT 1
      `.execute(db);
      const row = result.rows[0];
      if (!row) {
        return null;
      }
      return { id: row.id, status: row.status, displayName: row.display_name };
    },
  };
}
