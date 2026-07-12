import { getMasterDb } from '@reya/db';
import { sql } from 'kysely';
import type { LineAccountRouteRepository } from './routeByLineAccount';

/**
 * Default LineAccountRouteRepository backed by master.tenant_line_account_routes
 * via @reya/db's shared master Kysely instance. Mirrors
 * TenantContext::resolveTenantByLineAccount() / ::routeByLineAccount() exactly.
 */
export function createMasterLineAccountRouteRepository(): LineAccountRouteRepository {
  return {
    async findTenantIdByLineAccountId(lineAccountId: number): Promise<number | null> {
      const db = getMasterDb();
      const result = await sql<{ tenant_id: number }>`
        SELECT tenant_id FROM tenant_line_account_routes
         WHERE line_account_id = ${lineAccountId} AND is_active = 1
         ORDER BY id ASC LIMIT 1
      `.execute(db);
      const row = result.rows[0];
      return row ? row.tenant_id : null;
    },

    async touchLastSeen(lineAccountId: number, tenantId: number): Promise<void> {
      const db = getMasterDb();
      await sql`
        UPDATE tenant_line_account_routes SET last_seen_at = NOW()
         WHERE line_account_id = ${lineAccountId} AND tenant_id = ${tenantId}
      `.execute(db);
    },
  };
}
