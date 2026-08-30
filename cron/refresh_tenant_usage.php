<?php
/**
 * Refresh Tenant Usage Snapshots
 * รีเฟรช cache ตัวเลขการใช้งานของทุก tenant ลง tenant_usage_snapshots
 * เพื่อให้หน้าเจ้าของระบบ admin/customers.php แสดงตัวเลขได้เร็ว (ไม่ต้อง query สดทุกครั้ง)
 *
 * Run:      php cron/refresh_tenant_usage.php
 * Schedule: ทุกชั่วโมง (0 * * * *) — ปรับได้ตามจำนวน tenant
 *
 * หมายเหตุ: เป็น platform-level cron ที่วน tenant — ต้อง skip subdomain resolution
 * และเชื่อม master DB เอง (ตามกฎ CLI/cron ใน CLAUDE.md).
 */
declare(strict_types=1);

// CLI/cron: ห้ามให้ resolve_subdomain.php รัน (ไม่มี HTTP_HOST)
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php'; // โหลด global \Database (tenant-aware)
require_once __DIR__ . '/../classes/TenantContext.php';
require_once __DIR__ . '/../includes/platform-billing-helpers.php';

echo "=== Refresh Tenant Usage Snapshots ===\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n";

try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot reach master DB (zrismpsz_reya_platform): ' . $e->getMessage() . "\n");
    exit(1);
}

$start     = microtime(true);
$refreshed = refreshAllTenantUsage($platformDb);
$elapsed   = round((microtime(true) - $start) * 1000);

echo "Refreshed usage snapshots for {$refreshed} tenant(s) in {$elapsed} ms\n";
echo "Done.\n";
