<?php

// Master kill-switch — retail-api shares the same ODOO_INTEGRATION_ENABLED env flag
// as the main monolith. Tenants that do not use Odoo leave it unset/false.
$retailOdooEnabledRaw = getenv('ODOO_INTEGRATION_ENABLED');
$retailOdooEnabled = $retailOdooEnabledRaw !== false
    && in_array(strtolower((string) $retailOdooEnabledRaw), ['1', 'true', 'yes', 'on'], true);

switch ($requestMethod) {
    case 'POST':
        if ($action === 'odoo-status') {
            if (!$retailOdooEnabled) {
                sendError('Odoo integration is not enabled for this tenant', 410);
                break;
            }
            require_once __DIR__ . '/../webhook/odoo-status.php';
        } else {
            sendError('Action not found', 404);
        }
        break;

    default:
        sendError('Method not allowed', 405);
}
