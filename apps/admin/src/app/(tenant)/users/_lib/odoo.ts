/**
 * isOdooIntegrationEnabled — Node-side mirror of config/config.php's
 * ODOO_INTEGRATION_ENABLED master kill-switch:
 *
 *   $odooEnabledEnv = getenv('ODOO_INTEGRATION_ENABLED');
 *   define('ODOO_INTEGRATION_ENABLED', $odooEnabledEnv !== false
 *       ? in_array(strtolower((string) $odooEnabledEnv), ['1','true','yes','on'], true)
 *       : false);
 *
 * A single GLOBAL env var, not per-tenant (contrast with dashboard.php's
 * `$isOdooMode`, which additionally requires
 * shop_settings.order_data_source==='odoo' — that's a different, wider gate
 * out of scope for users.php's Odoo tab / user-detail.php's Odoo ERP card,
 * which both use ONLY this flag: `defined('ODOO_INTEGRATION_ENABLED') &&
 * ODOO_INTEGRATION_ENABLED === true`).
 *
 * Deliberately reads `process.env` directly rather than going through
 * @reya/config's loadEnv()/envSchema — ODOO_INTEGRATION_ENABLED is not yet
 * part of that shared zod schema, and packages/config is outside this
 * batch's allowed paths (mig-ui owns apps/admin/src/app/(tenant)/{users,
 * user-detail}/** and src/components/** only for this batch). Flagged as a
 * follow-up: promote this into packages/config/src/env.ts once a batch that
 * owns that package picks it up.
 */
export function isOdooIntegrationEnabled(env: NodeJS.ProcessEnv = process.env): boolean {
  const raw = env.ODOO_INTEGRATION_ENABLED;
  if (raw === undefined) {
    return false;
  }
  return ['1', 'true', 'yes', 'on'].includes(raw.toLowerCase());
}
