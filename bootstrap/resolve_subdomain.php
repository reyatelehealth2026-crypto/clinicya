<?php
/**
 * resolve_subdomain.php — Tenant resolution from HTTP Host subdomain.
 *
 * Wave 3 / SaaS subdomain routing (ADR-001 + Option A subdomain decision).
 *
 * Flow:
 *   1. Parse HTTP_HOST
 *   2. Extract subdomain part (e.g. "tenant-0001.re-ya.com" → "tenant-0001")
 *   3. Skip reserved subdomains (www, api, admin, ...)
 *   4. Look up master.tenants WHERE slug = ?
 *   5. If found + active → set TenantContext + $_SESSION['active_tenant_id']
 *   6. If found + suspended/terminated → return 503 maintenance page + exit
 *   7. If not found OR root domain → return null (caller decides — usually public landing)
 *
 * This runs on EVERY request that loads config/database.php (most of the app).
 * Fail-safe: any error → log + fall through (route to legacy default behaviour).
 *
 * To skip resolution (CLI scripts, cron):
 *   define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
 *   before require_once 'config/database.php'
 *
 * @package Platform
 * @version 1.0.0
 */

declare(strict_types=1);

if (!function_exists('reya_resolve_tenant_from_host')) {

    /**
     * The base domain for tenant subdomains.
     * Adjustable via env REYA_BASE_DOMAIN. Default: re-ya.com.
     */
    function reya_base_domain(): string
    {
        $env = getenv('REYA_BASE_DOMAIN');
        return $env !== false && $env !== '' ? $env : 're-ya.com';
    }

    /**
     * Reserved subdomain whitelist — these are NEVER tenant slugs.
     */
    function reya_reserved_subdomains(): array
    {
        return [
            // Infra
            'www', 'api', 'admin', 'platform', 'cdn', 'static', 'assets',
            'mail', 'webmail', 'smtp', 'imap', 'pop', 'webhook', 'webhooks',
            'cpanel', 'whm', 'ftp', 'sftp', 'ns1', 'ns2', 'autodiscover',
            'autoconfig', 'mta-sts', '_dmarc', '_dkim', 'wpad',
            // App-internal subdomains
            'app', 'dashboard', 'pharmacy', 'inventory', 'inbox',
            'liff', 'miniapp', 'docs', 'help', 'support', 'status',
            // Existing re-ya.com DNS records (do NOT treat as tenant slugs)
            'shop',     // public storefront (separate service @ 100.24.30.221)
            'odoo',     // Odoo ERP (separate service @ 100.24.30.221)
            'stg',      // staging environment (same origin, separate code branch)
            'dev',      // dev environment reserved
            // Reserved for future internal use
            'auth', 'login', 'signup', 'register', 'billing', 'pay',
            'blog', 'news', 'about', 'contact', 'legal', 'terms', 'privacy',
        ];
    }

    /**
     * Parse the subdomain from HTTP_HOST. Returns:
     *   - subdomain string (e.g. "tenant-0001") if Host = "{slug}.{base_domain}"
     *   - null for root domain, reserved subdomains, or invalid hosts
     */
    function reya_extract_subdomain(): ?string
    {
        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }
        // Strip port
        $host = preg_replace('/:\d+$/', '', $host);

        $baseDomain = strtolower(reya_base_domain());

        // Match exactly one subdomain segment in front of base domain.
        // Allow slug chars: lowercase letters, digits, hyphen (no leading/trailing hyphen).
        $pattern = '/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.' . preg_quote($baseDomain, '/') . '$/i';
        if (!preg_match($pattern, $host, $m)) {
            return null;
        }

        $subdomain = strtolower($m[1]);

        if (in_array($subdomain, reya_reserved_subdomains(), true)) {
            return null;
        }
        return $subdomain;
    }

    /**
     * True only when the request is for the BARE root domain (re-ya.com) or its
     * www alias — i.e. NOT a tenant subdomain and NOT another reserved subdomain
     * (api/admin/shop/…). Used to map the master/HQ tenant onto the root domain.
     */
    function reya_is_root_host(): bool
    {
        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/:\d+$/', '', $host);
        $base = strtolower(reya_base_domain());
        return $host === $base || $host === 'www.' . $base;
    }

    /**
     * The tenant slug that the root domain (re-ya.com) should serve. The master
     * branch lives here. Adjustable via env REYA_ROOT_TENANT_SLUG. Default:
     * tenant-0001 (master.tenants id 1 → zrismpsz_reya_t_0001).
     */
    /**
     * True when the request carries an explicit line-account routing signal
     * (?account=N from the LINE webhook, ?la / ?line_account_id from Mini-App
     * deep links). On the root domain such requests must be routed by
     * route_by_account.php instead of being pinned to the root-default tenant,
     * else every tenant's webhook would resolve to tenant-0001.
     */
    function reya_has_explicit_account_signal(): bool
    {
        foreach (['account', 'la', 'line_account_id'] as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                return true;
            }
            if (isset($_POST[$k]) && $_POST[$k] !== '') {
                return true;
            }
        }
        return false;
    }

    function reya_root_tenant_slug(): ?string
    {
        $env = getenv('REYA_ROOT_TENANT_SLUG');
        $slug = ($env !== false && $env !== '') ? $env : 'tenant-0001';
        $slug = strtolower(trim($slug));
        return $slug !== '' ? $slug : null;
    }

    /**
     * Resolve subdomain → tenant_id via master DB.
     * Returns tenant_id when matched + active. null otherwise.
     * Side effect: emits 503 + exit when tenant is suspended/terminated.
     */
    function reya_resolve_tenant_from_host(): ?int
    {
        $sub = reya_extract_subdomain();
        $isRoot = false;
        if ($sub === null) {
            // Root domain (re-ya.com / www.re-ya.com) is the master/HQ tenant's
            // home — map it to the configured root tenant (default tenant-0001).
            // Any OTHER null (a reserved subdomain like api/admin/shop) stays null
            // so it keeps falling through to the legacy default connection.
            if (reya_is_root_host() && !reya_has_explicit_account_signal()) {
                $sub = reya_root_tenant_slug();
                $isRoot = true;
            }
            if ($sub === null) {
                return null;
            }
        }

        // Need classes/Database.php loaded by now (caller in config/database.php
        // should already have required it via the proxy shim).
        if (!class_exists('Database', false)) {
            // Caller didn't load Database yet — try to require it once.
            $shim = __DIR__ . '/../classes/Database.php';
            if (is_file($shim)) {
                require_once $shim;
            } else {
                return null;
            }
        }

        try {
            $db   = \Database::platform()->getConnection();
            $stmt = $db->prepare('SELECT id, status, display_name FROM tenants WHERE slug = ? LIMIT 1');
            $stmt->execute([$sub]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                // Root domain must NEVER 404 — fall through to the legacy landing
                // (config'd root tenant missing → treat as unconfigured, not broken).
                if ($isRoot) {
                    return null;
                }
                // Subdomain looks like a tenant slug but no tenant exists — return 404
                // to prevent users from probing tenant existence + avoid serving the
                // root-domain content under a stranger's URL.
                http_response_code(404);
                header('Content-Type: text/html; charset=utf-8');
                $base = htmlspecialchars(reya_base_domain(), ENT_QUOTES, 'UTF-8');
                $sub  = htmlspecialchars($sub, ENT_QUOTES, 'UTF-8');
                echo '<!doctype html><meta charset="utf-8"><title>404 — ไม่พบร้าน</title>'
                   . '<style>body{font-family:sans-serif;max-width:560px;margin:80px auto;text-align:center;color:#475569}'
                   . '.h{color:#dc2626;font-size:64px;margin:0}.s{color:#0f172a;font-size:24px;margin:8px 0 24px}</style>'
                   . '<p class="h">404</p>'
                   . '<p class="s">ไม่พบร้าน <code>' . $sub . '</code></p>'
                   . '<p>ตรวจสอบ URL อีกครั้ง หรือสมัครใช้ระบบที่ '
                   . '<a href="https://' . $base . '/">' . $base . '</a></p>';
                exit;
            }

            $status = (string)($row['status'] ?? '');
            if ($status === 'suspended' || $status === 'terminated' || $status === 'pending_setup') {
                // Never take the master/root domain offline on a status glitch —
                // fall through to legacy so re-ya.com always serves something.
                if ($isRoot) {
                    return null;
                }
                http_response_code(503);
                header('Content-Type: text/html; charset=utf-8');
                $name = htmlspecialchars((string)$row['display_name'], ENT_QUOTES, 'UTF-8');
                $msg  = $status === 'suspended'
                    ? 'บัญชีของร้านนี้ถูกระงับชั่วคราว — กรุณาติดต่อทีมงาน REYA'
                    : ($status === 'terminated'
                        ? 'บัญชีของร้านนี้ถูกปิดแล้ว'
                        : 'ร้านยังอยู่ระหว่างการตั้งค่า กรุณารอสักครู่');
                echo '<!doctype html><meta charset="utf-8"><title>ระงับ — ' . $name . '</title>'
                   . '<style>body{font-family:sans-serif;max-width:560px;margin:80px auto;text-align:center;color:#475569}</style>'
                   . '<h1 style="color:#dc2626">' . $name . '</h1>'
                   . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
                   . '<p><a href="https://' . htmlspecialchars(reya_base_domain(), ENT_QUOTES, 'UTF-8') . '">ไปยังหน้าหลัก</a></p>';
                exit;
            }
            return (int)$row['id'];
        } catch (\Throwable $e) {
            error_log('[resolve_subdomain] lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

// -----------------------------------------------------------------------------
// Boot — run resolution if not explicitly skipped.
// -----------------------------------------------------------------------------
if (!defined('REYA_SKIP_SUBDOMAIN_RESOLUTION')) {
    $tenantId = reya_resolve_tenant_from_host();
    if ($tenantId !== null) {
        if (!class_exists('TenantContext', false)) {
            $tcShim = __DIR__ . '/../classes/TenantContext.php';
            if (is_file($tcShim)) {
                require_once $tcShim;
            }
        }
        if (class_exists('TenantContext', false)) {
            \TenantContext::setCurrentTenantId($tenantId);
            if (session_status() === PHP_SESSION_ACTIVE) {
                // Pin to session so subsequent requests on this subdomain stay scoped
                // even if Host header is missing (e.g. internal redirects).
                if (!isset($_SESSION['active_tenant_id']) || (int)$_SESSION['active_tenant_id'] !== $tenantId) {
                    $_SESSION['active_tenant_id'] = $tenantId;
                    $_SESSION['active_tenant_source'] = 'subdomain';
                }
            }
        }
    }
}
