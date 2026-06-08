<?php
/**
 * api/broadcast-quota.php — เช็คโควต้าข้อความ (broadcast) ของ LINE Messaging API ต่อบัญชี.
 *
 * LINE มีโควต้าข้อความต่อเดือน (push/broadcast/multicast/narrowcast นับรวม). หน้านี้ดึง
 * โควต้า + ยอดที่ใช้ไปแล้ว มาคำนวณยอดคงเหลือ เพื่อกันยิง broadcast เกินโควต้า.
 *
 * GET ?account_id=N  → เฉพาะบัญชีนั้น
 * GET (ไม่ใส่)        → ทุกบัญชี
 *
 * Response:
 *   { ok: true, month: "2026-06",
 *     accounts: [{ id, name, quota_type, limit, used, remaining, error }] }
 *   - quota_type "limited" = มีเพดาน (ดู limit/remaining) · "none" = ไม่จำกัด (remaining=null)
 *
 * @package Broadcast
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

try {
    $db      = Database::getInstance()->getConnection();
    $manager = new LineAccountManager($db);

    $accountIdParam = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;

    // Pick accounts: one (?account_id=N) or all in this tenant.
    $accounts = $manager->getAllAccounts();
    if ($accountIdParam > 0) {
        $accounts = array_values(array_filter(
            $accounts,
            static fn ($a) => (int) ($a['id'] ?? 0) === $accountIdParam
        ));
    }

    $results = [];
    foreach ($accounts as $acc) {
        $accId = (int) ($acc['id'] ?? 0);
        $row = [
            'id'         => $accId,
            'name'       => $acc['name'] ?? ('Account #' . $accId),
            'quota_type' => null,
            'limit'      => null,
            'used'       => null,
            'remaining'  => null,
            'error'      => null,
        ];

        try {
            $line = $manager->getLineAPI($accId);
            if (!$line) {
                $row['error'] = 'no_line_api';
                $results[] = $row;
                continue;
            }

            $quota = $line->getQuota();
            if (($quota['code'] ?? 0) !== 200) {
                $row['error'] = 'quota_http_' . ($quota['code'] ?? 0);
                $results[] = $row;
                continue;
            }

            $cons  = $line->getQuotaConsumption();
            $qType = $quota['body']['type'] ?? 'none';
            $limit = isset($quota['body']['value']) ? (int) $quota['body']['value'] : null;
            $used  = isset($cons['body']['totalUsage']) ? (int) $cons['body']['totalUsage'] : null;

            $row['quota_type'] = $qType;                       // "limited" | "none"
            $row['limit']      = $qType === 'limited' ? $limit : null;
            $row['used']       = $used;
            $row['remaining']  = ($qType === 'limited' && $limit !== null && $used !== null)
                ? max(0, $limit - $used)
                : null;                                        // null = ไม่จำกัด / unknown
        } catch (\Throwable $e) {
            $row['error'] = $e->getMessage();
        }

        $results[] = $row;
    }

    echo json_encode([
        'ok'       => true,
        'month'    => date('Y-m'),
        'accounts' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
