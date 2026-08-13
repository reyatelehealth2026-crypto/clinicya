import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getGeneralSettings } from '../_lib/general-queries';
import { isOdooIntegrationEnabled } from '../../users/_lib/odoo';
import { GeneralSettingsForm } from './GeneralSettingsForm';

/**
 * GeneralTab — Server Component port of includes/shop/general.php
 * (297 LOC): the shop-identity/shipping/COD/social/payment-channel form
 * backed by `shop_settings`. `await`-invoked from page.tsx like
 * ./ConsentTab.tsx/./WelcomeTab.tsx. Real PHP has no page-title `<h2>` of
 * its own for this tab (confirmed by reading the full source — the tab nav
 * pill itself is the only "ข้อมูลร้าน" label a user sees), so this wrapper
 * renders nothing but the form.
 *
 * Odoo gate: general.php's `defined('ODOO_INTEGRATION_ENABLED') &&
 * ODOO_INTEGRATION_ENABLED === true` (lines 116-138) is computed here via
 * (tenant)/users/_lib/odoo.ts's `isOdooIntegrationEnabled()` (read-only
 * import, not modified) and passed down as a plain boolean — the
 * process.env check can't run inside ./GeneralSettingsForm.tsx (a 'use
 * client' component).
 */
export interface GeneralTabProps {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
}

export async function GeneralTab({ db, currentBotId }: GeneralTabProps) {
  const settings = await getGeneralSettings(db, currentBotId);
  const showOdooOrderSource = isOdooIntegrationEnabled();

  return <GeneralSettingsForm settings={settings} showOdooOrderSource={showOdooOrderSource} />;
}
