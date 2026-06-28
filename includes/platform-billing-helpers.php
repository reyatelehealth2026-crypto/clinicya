<?php
/**
 * platform-billing-helpers.php — Pure helpers for the Platform Owner billing page.
 *
 * ใช้โดย admin/customers.php และ cron/refresh_tenant_usage.php
 *
 * - addBillingCycle()  : เลื่อนวันครบกำหนดไปตามรอบบิล (clamp สิ้นเดือนให้ถูกต้อง)
 * - paymentState()     : คำนวณสถานะการจ่ายจาก next_due_date เทียบวันนี้ (ไม่เก็บใน DB)
 * - countTenantUsage() : นับ usage จาก tenant DB (guard ด้วย SHOW TABLES LIKE)
 *
 * addBillingCycle / paymentState เป็น pure functions — มี unit test ใน
 * tests/Platform/BillingHelpersTest.php
 */
declare(strict_types=1);

if (!function_exists('addBillingCycle')) {
    /**
     * บวกรอบบิลให้กับวันที่ (YYYY-MM-DD) แล้วคืนวันใหม่ (YYYY-MM-DD).
     *
     * Clamp วันสิ้นเดือนให้ถูก: 2026-01-31 + monthly => 2026-02-28
     * (ไม่ใช่ PHP "+1 month" ปกติที่จะ overflow ไป 2026-03-03)
     *
     * @param string $date  วันเริ่ม/วันครบกำหนดเดิม รูปแบบ YYYY-MM-DD
     * @param string $cycle 'monthly' | 'quarterly' | 'yearly'
     * @return string วันครบกำหนดรอบถัดไป รูปแบบ YYYY-MM-DD
     * @throws \InvalidArgumentException ถ้า date/cycle ไม่ถูกต้อง
     */
    function addBillingCycle(string $date, string $cycle): string
    {
        $months = match ($cycle) {
            'monthly'   => 1,
            'quarterly' => 3,
            'yearly'    => 12,
            default     => throw new \InvalidArgumentException("Unknown billing cycle: {$cycle}"),
        };

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException("Invalid date: {$date}");
        }

        $day          = (int) $d->format('j');
        $firstOfMonth = $d->modify('first day of this month')->modify("+{$months} month");
        $daysInTarget = (int) $firstOfMonth->format('t');
        $targetDay    = min($day, $daysInTarget);

        return $firstOfMonth
            ->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $targetDay)
            ->format('Y-m-d');
    }
}

if (!function_exists('paymentState')) {
    /**
     * คำนวณสถานะการจ่ายจากวันครบกำหนด (next_due_date) เทียบกับวันนี้.
     *
     *   today <= nextDue                      => 'paid'    (ปกติ / จ่ายแล้ว, เขียว)
     *   nextDue < today <= nextDue + grace    => 'due'     (ใกล้/ค้างจ่าย, เหลือง)
     *   today > nextDue + grace               => 'overdue' (เกินกำหนด, แดง)
     *   nextDue ว่าง/ไม่ถูกต้อง               => 'unknown'
     *
     * @param string|null $nextDue   YYYY-MM-DD หรือ null
     * @param string      $today     YYYY-MM-DD (ฉีดเข้ามาเพื่อให้ test ได้)
     * @param int         $graceDays จำนวนวันผ่อนผันหลังครบกำหนด (default 7)
     */
    function paymentState(?string $nextDue, string $today, int $graceDays = 7): string
    {
        if ($nextDue === null || $nextDue === '') {
            return 'unknown';
        }

        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $nextDue);
        $now = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($due === false || $now === false) {
            return 'unknown';
        }

        if ($now <= $due) {
            return 'paid';
        }

        $graceEnd = $due->modify('+' . max(0, $graceDays) . ' day');
        if ($now <= $graceEnd) {
            return 'due';
        }

        return 'overdue';
    }
}

if (!function_exists('countTenantUsage')) {
    /**
     * นับ usage จาก tenant DB — guard ทุกตารางด้วย SHOW TABLES LIKE
     * จึงไม่ fatal ถ้าตารางไม่มี (คืน null ในคีย์นั้น).
     *
     * @return array<string,int|string|null>
     */
    function countTenantUsage(\PDO $pdo): array
    {
        $hasTable = static function (string $table) use ($pdo): bool {
            try {
                $chk = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?'
                );
                $chk->execute([$table]);
                return (int) $chk->fetchColumn() > 0;
            } catch (\Throwable $e) {
                return false;
            }
        };

        $countRows = static function (string $table) use ($pdo, $hasTable): ?int {
            if (!$hasTable($table)) {
                return null;
            }
            try {
                return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $latestDate = static function (string $table, string $column = 'created_at') use ($pdo, $hasTable): ?string {
            if (!$hasTable($table)) {
                return null;
            }
            try {
                $value = $pdo->query("SELECT MAX(`{$column}`) FROM `{$table}`")->fetchColumn();
                return $value !== false && $value !== null && $value !== '' ? (string) $value : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $orderTable = $hasTable('transactions') ? 'transactions' : ($hasTable('orders') ? 'orders' : null);
        $productTable = $hasTable('business_items') ? 'business_items' : ($hasTable('shop_products') ? 'shop_products' : null);

        return [
            'num_users'         => $countRows('users'),
            'num_admin_users'   => $countRows('admin_users'),
            'num_line_accounts' => $countRows('line_accounts'),
            'num_orders'        => $orderTable ? $countRows($orderTable) : null,
            'num_products'      => $productTable ? $countRows($productTable) : null,
            'num_messages'      => $countRows('messages'),
            'last_user_at'      => $latestDate('users'),
            'last_order_at'     => $orderTable ? $latestDate($orderTable) : null,
            'last_message_at'   => $latestDate('messages'),
        ];
    }
}

if (!function_exists('platformColumnExists')) {
    function platformColumnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('refreshAllTenantUsage')) {
    /**
     * รีเฟรช usage snapshot ของ tenant (ยกเว้น terminated) ลง tenant_usage_snapshots
     * โดย connect เข้า DB ของแต่ละร้านแล้วนับสด. ใช้โดย admin/customers.php (ปุ่มรีเฟรช)
     * และ cron/refresh_tenant_usage.php.
     *
     * ต้องโหลด global \Database (config/database.php) ไว้แล้วก่อนเรียก.
     *
     * @param \PDO     $platformDb    master DB connection
     * @param int|null $onlyTenantId  รีเฟรชเฉพาะ tenant นี้ (null = ทุกร้าน)
     * @return int จำนวนร้านที่อัปเดตสำเร็จ
     */
    function refreshAllTenantUsage(\PDO $platformDb, ?int $onlyTenantId = null): int
    {
        $sql    = "SELECT id FROM tenants WHERE status <> 'terminated'";
        $params = [];
        if ($onlyTenantId !== null && $onlyTenantId > 0) {
            $sql     .= ' AND id = ?';
            $params[] = $onlyTenantId;
        }
        $stmt = $platformDb->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $detailedUsageColumns = platformColumnExists($platformDb, 'tenant_usage_snapshots', 'num_admin_users');
        $upsert = $platformDb->prepare($detailedUsageColumns
            ? 'INSERT INTO tenant_usage_snapshots
                (tenant_id, num_users, num_admin_users, num_line_accounts, num_orders, num_products,
                 num_messages, last_user_at, last_order_at, last_message_at, computed_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE
                num_users         = VALUES(num_users),
                num_admin_users   = VALUES(num_admin_users),
                num_line_accounts = VALUES(num_line_accounts),
                num_orders        = VALUES(num_orders),
                num_products      = VALUES(num_products),
                num_messages      = VALUES(num_messages),
                last_user_at      = VALUES(last_user_at),
                last_order_at     = VALUES(last_order_at),
                last_message_at   = VALUES(last_message_at),
                computed_at       = VALUES(computed_at)'
            : 'INSERT INTO tenant_usage_snapshots
                (tenant_id, num_users, num_orders, num_messages, computed_at)
               VALUES (?, ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE
                num_users    = VALUES(num_users),
                num_orders   = VALUES(num_orders),
                num_messages = VALUES(num_messages),
                computed_at  = VALUES(computed_at)'
        );

        // NOTE: Database::forTenant() pools each tenant connection for the request.
        // At beta scale (handful of tenants) that's fine. If tenant count grows to
        // hundreds, call \Modules\Core\Database::resetAll() periodically to release
        // per-tenant PDO handles between iterations.
        $ok = 0;
        foreach ($ids as $tid) {
            $tid = (int) $tid;
            try {
                $tenantPdo = \Database::forTenant($tid)->getConnection();
                $counts    = countTenantUsage($tenantPdo);
                if ($detailedUsageColumns) {
                    $upsert->execute([
                        $tid,
                        $counts['num_users'],
                        $counts['num_admin_users'],
                        $counts['num_line_accounts'],
                        $counts['num_orders'],
                        $counts['num_products'],
                        $counts['num_messages'],
                        $counts['last_user_at'],
                        $counts['last_order_at'],
                        $counts['last_message_at'],
                    ]);
                } else {
                    $upsert->execute([$tid, $counts['num_users'], $counts['num_orders'], $counts['num_messages']]);
                }
                $ok++;
            } catch (\Throwable $e) {
                error_log('[refreshAllTenantUsage] tenant ' . $tid . ': ' . $e->getMessage());
            }
        }

        return $ok;
    }
}
