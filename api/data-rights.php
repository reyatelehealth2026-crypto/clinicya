<?php
/**
 * Data Rights API — สิทธิของเจ้าของข้อมูลตาม PDPA (self-service)
 *
 * Customer-facing endpoint (เหมือน api/consent.php): CORS + JSON, ไม่มี admin
 * session, ไม่ใช้ template หน้า admin. รับ POST JSON:
 *   { line_user_id, line_account_id, action, ... }
 *
 * Actions:
 *   - withdraw_consent — ถอนความยินยอม health_data (mirror api/consent.php)
 *   - request_deletion — ขอลบข้อมูล (SOFT flag เท่านั้น) + คืน confirmation code
 *   - export_data      — ส่งออกข้อมูล "ของเจ้าของเท่านั้น" เป็น JSON
 *
 * ความปลอดภัย (สำคัญ): ทุก action resolve users.id ฝั่ง server จาก
 * (line_user_id, line_account_id) — ไม่รับ/ไม่เชื่อ user_id จาก client เลย
 * (กัน IDOR / ข้าม tenant). ทุกคำขอบันทึก audit row ลง consultation_audit
 * (append-only, hash-chained) ด้วย actor_type='customer'.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/AIChat/Autoloader.php'; // Modules\ PSR-4 autoloader (covers PDPA too)

use Modules\PDPA\Services\DataRightsService;

$db = Database::getInstance()->getConnection();

/**
 * Response helper — เหมือน api/consent.php.
 *
 * @param array<string,mixed> $data
 */
function drJsonResponse(bool $success, string $message = '', array $data = []): void
{
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ── Parse input (JSON body first, then form/query fallback) ──────────
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action      = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? null;
$lineUserId  = $input['line_user_id'] ?? $_POST['line_user_id'] ?? $_GET['line_user_id'] ?? null;
$lineUserId  = is_string($lineUserId) ? trim($lineUserId) : null;

$rawAccount  = $input['line_account_id'] ?? $_POST['line_account_id'] ?? $_GET['line_account_id'] ?? null;
$lineAccountId = ($rawAccount !== null && $rawAccount !== '' && (int) $rawAccount > 0)
    ? (int) $rawAccount
    : null;

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

// ── Validate identity BEFORE doing anything ─────────────────────────
if ($lineUserId === null || $lineUserId === '') {
    drJsonResponse(false, 'LINE User ID required');
}

$service = new DataRightsService($db, $lineAccountId);
$user = $service->resolveUser($lineUserId);
if ($user === null) {
    drJsonResponse(false, 'User not found');
}
$userId = (int) $user['id'];

/**
 * Append an audit row (append-only, hash-chained). No-op if the audit class
 * is unavailable — must never break the customer flow.
 *
 * @param array<string,mixed> $payload
 */
function drAudit($db, ?int $lineAccountId, string $eventType, int $userId, array $payload): void
{
    try {
        if (class_exists(\Modules\AIChat\Services\ConsultationAudit::class)) {
            (new \Modules\AIChat\Services\ConsultationAudit($db, $lineAccountId))->log(
                $eventType,
                'customer',
                null,
                $userId,
                $payload
            );
        }
    } catch (\Throwable $e) {
        error_log('[data-rights] audit failed: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
        // ── 1) Withdraw consent ─────────────────────────────────────
        case 'withdraw_consent':
            $consentType = $input['consent_type'] ?? 'health_data';
            if (!is_string($consentType) || $consentType === '') {
                $consentType = 'health_data';
            }
            $service->withdrawConsent($userId, $consentType, $ip, $ua);
            drAudit($db, $lineAccountId, 'consent_withdraw', $userId, [
                'consent_type' => $consentType,
                'source'       => 'data-rights-api',
            ]);
            drJsonResponse(true, 'ถอนความยินยอมเรียบร้อยแล้ว', ['consent_type' => $consentType]);
            break;

        // ── 2) Request deletion (SOFT flag only) ────────────────────
        case 'request_deletion':
            $reason = $input['reason'] ?? null;
            $reason = is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 2000) : null;

            $code = $service->markForDeletion($userId, $lineUserId, $reason, $ip, $ua);

            drAudit($db, $lineAccountId, 'data_deletion_request', $userId, [
                'confirmation_code' => $code,
                'source'            => 'data-rights-api',
            ]);

            // Best-effort admin notification (Telegram if configured; else log).
            try {
                $name = (string) ($user['display_name'] ?? $user['real_name'] ?? ('user#' . $userId));
                if (class_exists(\SiteNotifier::class) && method_exists(\SiteNotifier::class, 'sendTelegram')) {
                    \SiteNotifier::sendTelegram(
                        "🗑️ คำขอลบข้อมูล (PDPA)\nลูกค้า: {$name}\nรหัสยืนยัน: {$code}\nline_account_id: " . ($lineAccountId ?? 'n/a')
                    );
                }
            } catch (\Throwable $e) {
                // fall through to dev_logs below
            }
            try {
                $stmt = $db->prepare(
                    "INSERT INTO dev_logs (log_type, source, message, data, created_at)
                     VALUES ('info', 'data-rights', ?, ?, NOW())"
                );
                $stmt->execute([
                    'PDPA deletion request',
                    json_encode(
                        ['user_id' => $userId, 'confirmation_code' => $code, 'line_account_id' => $lineAccountId],
                        JSON_UNESCAPED_UNICODE
                    ),
                ]);
            } catch (\Throwable $e) {
                // dev_logs is best-effort only.
            }

            drJsonResponse(true, 'รับคำขอลบข้อมูลแล้ว เราจะดำเนินการภายใน 30 วัน', [
                'confirmation_code' => $code,
                'status'            => 'requested',
            ]);
            break;

        // ── 3) Export own data ──────────────────────────────────────
        case 'export_data':
            $export = $service->buildExportForUser($user);
            drAudit($db, $lineAccountId, 'data_export', $userId, ['source' => 'data-rights-api']);
            drJsonResponse(true, 'ส่งออกข้อมูลเรียบร้อยแล้ว', ['data' => $export]);
            break;

        default:
            drJsonResponse(false, 'Invalid action');
    }
} catch (\Throwable $e) {
    error_log('[data-rights] ' . $e->getMessage());
    drJsonResponse(false, 'เกิดข้อผิดพลาดในการดำเนินการ');
}
