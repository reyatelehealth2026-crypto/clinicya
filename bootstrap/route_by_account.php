<?php
/**
 * bootstrap/route_by_account.php — Tenant routing for root-domain entry points.
 *
 * Inclusion contract:
 *   require_once 'config/config.php';
 *   require_once 'config/database.php';
 *   require_once 'bootstrap/route_by_account.php';   // ← before any Database::getInstance() call
 *
 * Behaviour:
 *   - If TenantContext is already pinned (subdomain hit, or earlier setCurrentTenantId
 *     call) → do nothing.
 *   - Else look for a `line_account_id`, short Mini-App alias `la`, or
 *     webhook-style `account` in: $_GET, $_POST, JSON body of POST request.
 *     First non-empty wins.
 *   - If found and the platform routing table has a mapping → pin TenantContext
 *     so subsequent Database::getInstance() resolves to the tenant DB.
 *   - On failure or no signal → silently fall through (legacy DB still works).
 *
 * Why we need this:
 *   The LINE webhook URL and the LIFF Mini App both load from re-ya.com (root
 *   domain), so resolve_subdomain.php cannot find a tenant. Without this
 *   bootstrap, requests fall back to the legacy zrismpsz_demo DB while admin
 *   pages running on tenant subdomains write to zrismpsz_reya_t_NNNN — causing
 *   split-brain data (admin can't see customer orders, customer can't see
 *   admin-issued labels, etc).
 *
 * Files that must include this:
 *   - webhook.php
 *   - api/checkout.php
 *   - api/member.php
 *   - api/orders.php
 *   - any other Mini App / LIFF API endpoint that receives line_account_id
 *
 * 2026-05-27
 */
declare(strict_types=1);

require_once __DIR__ . '/../classes/TenantContext.php';

(static function () {
    // Subdomain or explicit override already pinned the tenant → respect it.
    if (\TenantContext::getCurrentTenantId() !== null) {
        return;
    }

    // 1. Query string (?line_account_id=N, ?la=N — Mini App, or ?account=N — webhook style)
    $candidate = $_GET['line_account_id'] ?? $_GET['la'] ?? $_GET['account'] ?? null;

    // 2. POST form field
    if ($candidate === null || $candidate === '') {
        $candidate = $_POST['line_account_id'] ?? $_POST['la'] ?? $_POST['account'] ?? null;
    }

    // 3. JSON body for POSTs (line-mini-app uses fetch with JSON content-type)
    if (($candidate === null || $candidate === '') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = @file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $candidate = $json['line_account_id'] ?? $json['la'] ?? $json['account'] ?? null;
            }
        }
    }

    if ($candidate === null || $candidate === '' || !is_numeric($candidate)) {
        return;
    }
    $lineAccountId = (int) $candidate;
    if ($lineAccountId <= 0) {
        return;
    }

    try {
        \TenantContext::routeByLineAccount($lineAccountId);
    } catch (\Throwable $e) {
        // Never let routing break a request — log + continue (legacy fallback).
        error_log('[route_by_account] failed for line_account_id=' . $lineAccountId . ': ' . $e->getMessage());
    }
})();
