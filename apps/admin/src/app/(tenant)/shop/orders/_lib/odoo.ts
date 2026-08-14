/**
 * isOdooIntegrationEnabled — Node-side mirror of config/config.php's
 * ODOO_INTEGRATION_ENABLED master kill-switch:
 *
 *   $odooEnabledEnv = getenv('ODOO_INTEGRATION_ENABLED');
 *   define('ODOO_INTEGRATION_ENABLED', $odooEnabledEnv !== false
 *       ? in_array(strtolower((string) $odooEnabledEnv), ['1','true','yes','on'], true)
 *       : false);
 *
 * shop/orders.php's own gate (lines 23-25) is:
 *   $isOdooMode = ($orderDataSource === 'odoo')
 *       && defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true;
 * i.e. the GLOBAL env kill-switch below, ANDed with the PER-TENANT
 * `shop_settings.order_data_source` flag (see queries.ts's
 * getShopOrderDataSource()) — both must be true. This file only ports the
 * global half; the per-tenant half lives in queries.ts.
 *
 * STANDALONE, non-hoisted copy of users/_lib/odoo.ts (same rationale, same
 * upstream PHP constant) — this batch's brief deliberately keeps
 * shop/orders/** fully disjoint from users/**'s parallel work in the same
 * worktree/monorepo batch, so this tiny helper is duplicated rather than
 * imported.
 *
 * Deliberately reads `process.env` directly rather than going through
 * @reya/config's loadEnv()/envSchema — ODOO_INTEGRATION_ENABLED is not yet
 * part of that shared zod schema, and packages/config is outside this
 * batch's allowed paths. Flagged as a follow-up: promote this into
 * packages/config/src/env.ts once a batch that owns that package picks it
 * up (same flag users/_lib/odoo.ts already raises).
 */
export function isOdooIntegrationEnabled(env: NodeJS.ProcessEnv = process.env): boolean {
  const raw = env.ODOO_INTEGRATION_ENABLED;
  if (raw === undefined) {
    return false;
  }
  return ['1', 'true', 'yes', 'on'].includes(raw.toLowerCase());
}
