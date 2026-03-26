<?php
/**
 * BDO Feature Flags — Staged rollout configuration.
 *
 * Controls which parts of the new BDO Confirm flow are active.
 * All flags default to ENABLED for new deployments.
 * Set to false to fall back to legacy behavior during rollout.
 *
 * Override via environment variables (recommended for production):
 *   BDO_FLAG_NEW_CONTEXT_MANAGER=0  → disable new BdoContextManager
 *   BDO_FLAG_STRICT_MATCH=0         → allow local-only match fallback (legacy)
 *   etc.
 *
 * @version 1.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

return [
    /**
     * Use BdoCodntextManager (multi-BDO-safe) instead of legacy saveBdoContext().
     * When true: openContext() / closeContext() are called on webhook events.
     * When false: legacy saveBdoContext() is used (single-BDO-per-customer).
     */
    'new_context_manager' => (bool) (getenv('BDO_FLAG_NEW_CONTEXT_MANAGER') !== false
        ? getenv('BDO_FLAG_NEW_CONTEXT_MANAGER')
        : true),

    /**
     * Strict match mode: match/unmatch mutations MUST succeed at Odoo.
     * When true: no local-only fallback for state-changing operations.
     * When false: legacy behavior (local DB updated even if Odoo fails).
     */
    'strict_match' => (bool) (getenv('BDO_FLAG_STRICT_MATCH') !== false
        ? getenv('BDO_FLAG_STRICT_MATCH')
        : true),

    /**
     * Use new bdo-inbox-api.php as the primary data source for inboxreya.
     * When true: inboxreya calls /api/bdo-inbox-api.php.
     * When false: inboxreya uses legacy /api/odoo-webhooks-dashboard.php actions.
     */
    'new_inbox_api' => (bool) (getenv('BDO_FLAG_NEW_INBOX_API') !== false
        ? getenv('BDO_FLAG_NEW_INBOX_API')
        : true),

    /**
     * Store selected_invoices and selected_credit_notes from bdo.confirmed webhook.
     * When true: financial_summary_json, selected_invoices_json are persisted.
     * When false: only basic context (bdo_id, amount, delivery_type) is stored.
     */
    'store_financial_detail' => (bool) (getenv('BDO_FLAG_STORE_FINANCIAL_DETAIL') !== false
        ? getenv('BDO_FLAG_STORE_FINANCIAL_DETAIL')
        : true),

    /**
     * Block unmatch when slip status is posted or done.
     * When true: unmatch is rejected with error if status is posted/done.
     * When false: unmatch is always allowed (legacy behavior).
     */
    'block_unmatch_posted' => (bool) (getenv('BDO_FLAG_BLOCK_UNMATCH_POSTED') !== false
        ? getenv('BDO_FLAG_BLOCK_UNMATCH_POSTED')
        : true),

    /**
     * Require bdo_id resolution before slip upload when multiple BDOs are open.
     * When true: returns ambiguous_bdos error if customer has >1 open BDO.
     * When false: silently picks the latest BDO (legacy behavior).
     */
    'require_bdo_id_resolution' => (bool) (getenv('BDO_FLAG_REQUIRE_BDO_ID_RESOLUTION') !== false
        ? getenv('BDO_FLAG_REQUIRE_BDO_ID_RESOLUTION')
        : true),
];
