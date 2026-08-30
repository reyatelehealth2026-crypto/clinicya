<?php
/**
 * platform-audit.php — Shared audit-write helper for Platform Owner pages.
 *
 * แยกออกมาจาก closure `$writeAudit` ใน admin/switch-tenant.php เพื่อให้
 * admin/customers.php (และหน้า platform อื่นๆ) reuse ได้ — เขียน 1 แถวลง
 * super_admin_audit ต่อ 1 action ที่ข้าม/แตะ tenant boundary.
 *
 * เขียน audit ห้าม fatal กับ flow หลัก — error ถูก log แล้วกลืน.
 */
declare(strict_types=1);

if (!function_exists('platformAudit')) {
    /**
     * @param \PDO        $db             master DB (reya_platform) connection
     * @param int         $platformUserId platform_users.id ของคนที่ทำ action
     * @param int|null    $tenantId       tenants.id (null = platform-wide)
     * @param string      $action         verb-noun เช่น record_payment, suspend_tenant
     * @param array<mixed> $metadata      บริบทเพิ่มเติม (เก็บเป็น JSON)
     */
    function platformAudit(
        \PDO $db,
        int $platformUserId,
        ?int $tenantId,
        string $action,
        array $metadata = []
    ): void {
        try {
            $stmt = $db->prepare(
                'INSERT INTO super_admin_audit
                    (platform_user_id, tenant_id, action, ip_address, user_agent,
                     request_method, request_uri, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $platformUserId,
                $tenantId,
                $action,
                $_SERVER['REMOTE_ADDR']    ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                $_SERVER['REQUEST_METHOD'] ?? null,
                substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[platform-audit] audit write failed: ' . $e->getMessage());
        }
    }
}
