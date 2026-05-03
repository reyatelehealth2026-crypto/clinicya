<?php
/**
 * Odoo Integration Guard
 *
 * Shared kill-switch for Odoo HTTP entrypoints. Include at the very top of any
 * api/odoo-*.php (or other Odoo-touching public endpoint) and call
 * requireOdooIntegrationEnabled() before any handler logic. When the master
 * flag ODOO_INTEGRATION_ENABLED is false, the function emits HTTP 410 + JSON
 * and terminates the request.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/config.php';
 *   require_once __DIR__ . '/../includes/odoo-guard.php';
 *   requireOdooIntegrationEnabled();
 */

if (!function_exists('requireOdooIntegrationEnabled')) {
    function requireOdooIntegrationEnabled()
    {
        if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'success'    => false,
            'error'      => 'Odoo integration is not enabled for this tenant',
            'error_code' => 'ODOO_INTEGRATION_DISABLED'
        ]);
        exit;
    }
}
