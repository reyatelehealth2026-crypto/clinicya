<?php
/**
 * Points Claim API — "ให้แต้มผ่าน QR" (give loyalty points via one-time QR)
 *
 * Offline counter-sale loyalty flow. NO customer lookup by the pharmacist:
 *   - create : pharmacist generates a single-use token + QR encoding a LIFF
 *              claim URL. Requires the tenant to have a Mini App LIFF id.
 *   - claim  : customer scans QR → Mini App LIFF → this endpoint resolves the
 *              LINE user, credits points, marks the token used, pushes a Flex
 *              receipt. Single-use + expiry enforced by a guarded UPDATE.
 *   - status : read-only check of a token's state.
 *
 * Tenant isolation: every query is scoped by line_account_id. The customer-side
 * actions hit the root domain (LIFF loads from re-ya.com/miniapp), so we route
 * to the correct tenant DB via bootstrap/route_by_account.php (same pattern as
 * api/checkout.php and api/member.php).
 *
 * 2026-06-02
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Route root-domain Mini App calls (claim/status) to the correct tenant DB.
require_once __DIR__ . '/../bootstrap/route_by_account.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

// Resolve action + JSON body once (claim/status come as JSON POST from the app).
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
            if ($action === '' && !empty($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
    // Merge form POST as a fallback (inbox same-page fetch uses FormData).
    $input = array_merge($_POST, $input);
}

ensurePointsClaimsTable($db);

try {
    switch ($action) {
        case 'create':
            handleCreateClaim($db, $input);
            break;
        case 'give_direct':
            handleGiveDirect($db, $input);
            break;
        case 'claim':
            handleClaim($db, $input);
            break;
        case 'status':
            handleStatus($db);
            break;
        default:
            pcJson(false, 'Invalid action');
    }
} catch (Throwable $e) {
    error_log('[points-claim] ' . $e->getMessage());
    pcJson(false, 'เกิดข้อผิดพลาด / Unexpected error');
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * @param array<string,mixed> $extra
 */
function pcJson(bool $success, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Defensive self-create so a fresh tenant (or a deploy where the migration
 * hasn't run yet) still works. Mirrors database/migration_2026-06-02_points_claims.sql.
 */
function ensurePointsClaimsTable(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `points_claims` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `line_account_id` INT NOT NULL,
                `token` VARCHAR(64) NOT NULL,
                `voucher_no` VARCHAR(30) NOT NULL,
                `points` INT NOT NULL DEFAULT 0,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `payment_method` VARCHAR(20) DEFAULT NULL,
                `status` ENUM('pending','claimed','expired','cancelled') NOT NULL DEFAULT 'pending',
                `claimed_by_user_id` INT NULL,
                `claimed_line_user_id` VARCHAR(64) NULL,
                `points_transaction_id` INT NULL,
                `claimed_at` TIMESTAMP NULL DEFAULT NULL,
                `expires_at` TIMESTAMP NOT NULL,
                `created_by` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_token` (`token`),
                KEY `idx_account_status` (`line_account_id`, `status`),
                KEY `idx_expires` (`expires_at`),
                KEY `idx_claimed_user` (`claimed_by_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[points-claim] ensure table: ' . $e->getMessage());
    }
}

/**
 * Resolve the tenant's Mini App LIFF id (for the claim deep-link / QR).
 * Returns '' when no active LIFF is configured — the caller must then refuse
 * to mint a broken QR.
 */
function pcResolveLiffId(PDO $db, int $lineAccountId): string
{
    // 1) liff_apps (if a tenant manages multiple LIFF apps explicitly)
    try {
        $stmt = $db->prepare(
            "SELECT liff_id FROM liff_apps
             WHERE line_account_id = ? AND is_active = 1
               AND (name IN ('miniapp','claim','order','checkout') OR endpoint_url LIKE '%/miniapp%')
             ORDER BY FIELD(name,'claim','miniapp','order','checkout') LIMIT 1"
        );
        $stmt->execute([$lineAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && pcIsRealLiff($row['liff_id'] ?? '')) {
            return (string) $row['liff_id'];
        }
    } catch (Throwable $e) {
        error_log('[points-claim] liff_apps lookup: ' . $e->getMessage());
    }

    // 2) Canonical source for provisioned/migrated tenants: line_accounts.liff_id
    try {
        $stmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $liff = (string) ($stmt->fetchColumn() ?: '');
        if (pcIsRealLiff($liff)) {
            return $liff;
        }
    } catch (Throwable $e) {
        error_log('[points-claim] line_accounts liff lookup: ' . $e->getMessage());
    }

    return '';
}

/** A LIFF id counts as "connected" only when present and not a PENDING placeholder. */
function pcIsRealLiff($liffId): bool
{
    $id = trim((string) $liffId);
    return $id !== '' && stripos($id, 'PENDING') !== 0;
}

/** Build the absolute base URL for this request (https on prod). */
function pcBaseUrl(): string
{
    if (defined('BASE_URL') && BASE_URL) {
        return rtrim((string) BASE_URL, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 're-ya.com';

    return $scheme . '://' . $host;
}

/** A QR image URL for arbitrary text, via the same service api/checkout.php uses. */
function pcQrImageUrl(string $data, int $size = 320): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&data=' . urlencode($data);
}

/** Voucher number: WI + yyyymmdd + '-' + zero-padded daily sequence. */
function pcGenerateVoucherNo(PDO $db, int $lineAccountId): string
{
    $datePart = date('Ymd');
    $seq = 1;
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM points_claims
             WHERE line_account_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$lineAccountId]);
        $seq = ((int) $stmt->fetchColumn()) + 1;
    } catch (Throwable $e) {
        // fall back to seq=1 — UNIQUE is on token, not voucher_no, so a dup is harmless
    }

    return 'WI' . $datePart . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

/**
 * Shop display info for the Flex receipt, scoped to the tenant.
 * @return array{name:string,phone:string,logo:string}
 */
function pcShopInfo(PDO $db, int $lineAccountId): array
{
    $info = ['name' => 'ร้านยา', 'phone' => '', 'logo' => ''];
    try {
        $stmt = $db->prepare(
            "SELECT shop_name, contact_phone, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1"
        );
        $stmt->execute([$lineAccountId]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            if (!empty($s['shop_name'])) {
                $info['name'] = (string) $s['shop_name'];
            }
            $info['phone'] = trim((string) ($s['contact_phone'] ?? ''));
            $rawLogo = trim((string) ($s['shop_logo'] ?? ''));
            if ($rawLogo !== '') {
                $info['logo'] = preg_match('#^https?://#i', $rawLogo)
                    ? $rawLogo
                    : pcBaseUrl() . '/' . ltrim($rawLogo, '/');
            }
        }
    } catch (Throwable $e) {
        error_log('[points-claim] shop info: ' . $e->getMessage());
    }
    if ($info['name'] === 'ร้านยา') {
        try {
            $stmt = $db->prepare("SELECT name FROM line_accounts WHERE id = ?");
            $stmt->execute([$lineAccountId]);
            $la = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($la && !empty($la['name'])) {
                $info['name'] = (string) $la['name'];
            }
        } catch (Throwable $e) {
            // keep default
        }
    }

    return $info;
}

// =============================================================================
// Actions
// =============================================================================

/**
 * create — pharmacist generates a one-time claim token + QR.
 * Requires an authenticated admin session (the inbox runs on the tenant
 * subdomain). NO customer is referenced here.
 *
 * @param array<string,mixed> $data
 */
function handleCreateClaim(PDO $db, array $data): void
{
    // Auth: only a logged-in pharmacist/admin may mint claim tokens.
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }

    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    if ($lineAccountId <= 0) {
        pcJson(false, 'Missing line_account_id');
    }

    $amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : 0.0;
    $pointsInput = isset($data['points']) && $data['points'] !== '' ? (int) $data['points'] : 0;
    $paymentMethod = pcNormalizePayment((string) ($data['payment_method'] ?? ''));

    if ($amount < 0 || $pointsInput < 0) {
        pcJson(false, 'ค่าต้องไม่ติดลบ / Values must be positive');
    }

    // Points: explicit points win; otherwise derive from amount via the
    // tenant's existing loyalty rate (points_settings.points_per_baht).
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
    if ($pointsInput > 0) {
        $points = $pointsInput;
    } elseif ($amount > 0) {
        $points = $loyalty->calculatePoints($amount);
    } else {
        pcJson(false, 'กรุณากรอกยอดเงินหรือแต้ม / Enter an amount or points');
    }

    if ($points <= 0) {
        pcJson(false, 'แต้มที่จะให้ต้องมากกว่า 0 / Points to give must be greater than 0');
    }

    // Must have a Mini App LIFF — otherwise the QR would open nothing.
    $liffId = pcResolveLiffId($db, $lineAccountId);
    if ($liffId === '') {
        pcJson(false, 'ยังไม่ได้เชื่อมต่อ LIFF — กรุณาเชื่อมต่อ Mini App ก่อนสร้าง QR / Connect the LINE Mini App (LIFF) first', [
            'liff_missing' => true,
        ]);
    }

    // One-time token (URL-safe). UNIQUE on token guards collisions.
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $voucherNo = pcGenerateVoucherNo($db, $lineAccountId);
    $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60); // 30 minutes
    $createdBy = (int) ($_SESSION['admin_user']['id'] ?? 0) ?: null;

    $stmt = $db->prepare(
        "INSERT INTO points_claims
            (line_account_id, token, voucher_no, points, amount, payment_method, status, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
    );
    $stmt->execute([$lineAccountId, $token, $voucherNo, $points, $amount, $paymentMethod, $expiresAt, $createdBy]);

    // Claim URL → LIFF deep-link that opens the Mini App /claim route.
    $claimUrl = 'https://liff.line.me/' . $liffId . '/claim?token=' . rawurlencode($token);

    // Shop info for the dedicated scan/print window.
    $shop = pcShopInfo($db, $lineAccountId);

    pcJson(true, 'สร้าง QR สำเร็จ', [
        'token' => $token,
        'voucher_no' => $voucherNo,
        'points' => $points,
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'claim_url' => $claimUrl,
        'qr_image_url' => pcQrImageUrl($claimUrl),
        'expires_at' => $expiresAt,
        'expires_in_seconds' => 30 * 60,
        'shop_name' => $shop['name'],
        'shop_phone' => $shop['phone'],
    ]);
}

/**
 * give_direct — pharmacist credits points straight to a known customer
 * (the one whose chat is open in the inbox). NO QR / no scan needed.
 *
 * The credit goes through the SAME loyalty ledger as everything else
 * (LoyaltyPoints::addPoints → INSERT points_transactions), so it shows up in
 * the customer's points history exactly like an online order. A 'claimed'
 * points_claims row is also written for audit / voucher numbering.
 *
 * Admin session required. Strictly tenant-scoped: the customer must belong to
 * this OA (line_account_id) or the request is rejected.
 *
 * @param array<string,mixed> $data
 */
function handleGiveDirect(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }

    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    $userId = (int) ($data['user_id'] ?? 0);
    if ($lineAccountId <= 0) {
        pcJson(false, 'Missing line_account_id');
    }
    if ($userId <= 0) {
        pcJson(false, 'กรุณาเลือกลูกค้า / Select a customer');
    }

    // Isolation: the customer must belong to THIS tenant's LINE OA.
    $stmt = $db->prepare("SELECT id, line_user_id, display_name FROM users WHERE id = ? AND line_account_id = ? LIMIT 1");
    $stmt->execute([$userId, $lineAccountId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        pcJson(false, 'ไม่พบลูกค้าในร้านนี้ / Customer not found in this shop');
    }

    $amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : 0.0;
    $pointsInput = isset($data['points']) && $data['points'] !== '' ? (int) $data['points'] : 0;
    $paymentMethod = pcNormalizePayment((string) ($data['payment_method'] ?? ''));

    if ($amount < 0 || $pointsInput < 0) {
        pcJson(false, 'ค่าต้องไม่ติดลบ / Values must be positive');
    }

    $loyalty = new LoyaltyPoints($db, $lineAccountId);
    if ($pointsInput > 0) {
        $points = $pointsInput;
    } elseif ($amount > 0) {
        $points = $loyalty->calculatePoints($amount);
    } else {
        pcJson(false, 'กรุณากรอกยอดเงินหรือแต้ม / Enter an amount or points');
    }
    if ($points <= 0) {
        pcJson(false, 'แต้มที่จะให้ต้องมากกว่า 0 / Points to give must be greater than 0');
    }

    $voucherNo = pcGenerateVoucherNo($db, $lineAccountId);
    $createdBy = (int) ($_SESSION['admin_user']['id'] ?? 0) ?: null;
    // token column is NOT NULL + UNIQUE; mint a value even though there's no QR.
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

    $db->beginTransaction();
    try {
        // Audit row — already 'claimed' (direct credit, nothing to scan).
        $ins = $db->prepare(
            "INSERT INTO points_claims
                (line_account_id, token, voucher_no, points, amount, payment_method, status,
                 claimed_by_user_id, claimed_line_user_id, claimed_at, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'claimed', ?, ?, NOW(), NOW(), ?)"
        );
        $ins->execute([
            $lineAccountId, $token, $voucherNo, $points, $amount, $paymentMethod,
            $userId, (string) ($user['line_user_id'] ?? ''), $createdBy,
        ]);
        $claimId = (int) $db->lastInsertId();

        // Credit the loyalty ledger → points_transactions → customer history.
        $credited = $loyalty->addPoints(
            $userId,
            $points,
            'claim',
            $claimId,
            'รับแต้มจากการซื้อหน้าร้าน #' . $voucherNo
        );
        if (!$credited) {
            $db->rollBack();
            pcJson(false, 'ไม่สามารถเพิ่มแต้มได้ / Failed to credit points');
        }

        // Link the ledger row back onto the claim (best-effort).
        try {
            $pt = $db->prepare(
                "SELECT id FROM points_transactions
                 WHERE user_id = ? AND reference_type = 'claim' AND reference_id = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $pt->execute([$userId, $claimId]);
            $ptId = $pt->fetchColumn();
            if ($ptId) {
                $db->prepare("UPDATE points_claims SET points_transaction_id = ? WHERE id = ?")
                   ->execute([(int) $ptId, $claimId]);
            }
        } catch (Throwable $e) {
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[points-claim] give_direct tx: ' . $e->getMessage());
        pcJson(false, 'เกิดข้อผิดพลาด / Could not credit points');
    }

    // Running balance after credit.
    $balance = $points;
    try {
        $bal = $loyalty->getUserPoints($userId);
        $balance = (int) ($bal['available_points'] ?? $points);
    } catch (Throwable $e) {
    }

    // Push the Flex receipt to the customer (best-effort — never fail the credit).
    $shop = pcShopInfo($db, $lineAccountId);
    $lineUserId = (string) ($user['line_user_id'] ?? '');
    if ($lineUserId !== '') {
        pcPushReceipt($db, $lineAccountId, $lineUserId, [
            'voucher_no' => $voucherNo,
            'points' => $points,
            'amount' => $amount,
            'payment_method' => (string) $paymentMethod,
            'total_points' => $balance,
            'claimed_at' => date('Y-m-d H:i:s'),
        ], $shop, (string) ($user['display_name'] ?? ''), $userId);
    }

    pcJson(true, 'ให้แต้มสำเร็จ +' . number_format($points) . ' แต้ม', [
        'state' => 'claimed',
        'voucher_no' => $voucherNo,
        'points' => $points,
        'total_points' => $balance,
        'user_id' => $userId,
        'shop_name' => $shop['name'],
    ]);
}

/**
 * claim — customer scans QR in the Mini App and claims the points.
 * Single-use + expiry enforced via a guarded UPDATE inside a transaction.
 *
 * @param array<string,mixed> $data
 */
function handleClaim(PDO $db, array $data): void
{
    $token = trim((string) ($data['token'] ?? ''));
    $lineUserId = trim((string) ($data['line_user_id'] ?? ''));
    $displayName = trim((string) ($data['display_name'] ?? ''));
    $pictureUrl = trim((string) ($data['picture_url'] ?? ''));
    $reqLineAccountId = isset($data['line_account_id']) ? (int) $data['line_account_id'] : 0;

    if ($token === '') {
        pcJson(false, 'ไม่พบรหัสรับแต้ม / Missing token');
    }
    if ($lineUserId === '') {
        pcJson(false, 'กรุณาเข้าสู่ระบบผ่าน LINE / LINE login required');
    }

    // Load the claim (token is UNIQUE → at most one row, already in this tenant DB).
    $stmt = $db->prepare("SELECT * FROM points_claims WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        pcJson(false, 'รหัสรับแต้มไม่ถูกต้อง / Invalid claim code', ['state' => 'invalid']);
    }

    $lineAccountId = (int) $claim['line_account_id'];

    // Defence-in-depth: if the app sent a line_account_id, it must match the token's.
    if ($reqLineAccountId > 0 && $reqLineAccountId !== $lineAccountId) {
        pcJson(false, 'รหัสรับแต้มไม่ถูกต้อง / Invalid claim code', ['state' => 'invalid']);
    }

    // Already-claimed → idempotent friendly response (no double credit).
    if ($claim['status'] === 'claimed') {
        pcJson(false, 'รหัสนี้ถูกใช้ไปแล้ว / This code was already claimed', ['state' => 'claimed']);
    }
    if ($claim['status'] === 'cancelled') {
        pcJson(false, 'รหัสนี้ถูกยกเลิก / This code was cancelled', ['state' => 'cancelled']);
    }
    if (strtotime((string) $claim['expires_at']) < time()) {
        // best-effort mark expired (not required for correctness)
        try {
            $u = $db->prepare("UPDATE points_claims SET status = 'expired' WHERE id = ? AND status = 'pending'");
            $u->execute([(int) $claim['id']]);
        } catch (Throwable $e) {
        }
        pcJson(false, 'รหัสหมดอายุแล้ว / This code has expired', ['state' => 'expired']);
    }

    $points = (int) $claim['points'];

    $db->beginTransaction();
    try {
        // ── Single-use lock: only one request can flip pending → claimed. ──
        // The WHERE status='pending' AND expires_at>NOW() makes this atomic
        // even under concurrent scans; rowCount()===0 means someone won the race.
        $lock = $db->prepare(
            "UPDATE points_claims
             SET status = 'claimed', claimed_at = NOW()
             WHERE id = ? AND status = 'pending' AND expires_at > NOW()"
        );
        $lock->execute([(int) $claim['id']]);
        if ($lock->rowCount() === 0) {
            $db->rollBack();
            pcJson(false, 'รหัสนี้ถูกใช้ไปแล้ว / This code was already claimed', ['state' => 'claimed']);
        }

        // ── Resolve or create the customer (scoped to this tenant's LINE OA). ──
        $userId = pcResolveOrCreateUser($db, $lineAccountId, $lineUserId, $displayName, $pictureUrl);

        // ── Credit points using the existing loyalty ledger. ──
        $loyalty = new LoyaltyPoints($db, $lineAccountId);
        $credited = $loyalty->addPoints(
            $userId,
            $points,
            'claim',
            (int) $claim['id'],
            'รับแต้มจากการซื้อหน้าร้าน #' . $claim['voucher_no']
        );
        if (!$credited) {
            $db->rollBack();
            pcJson(false, 'ไม่สามารถเพิ่มแต้มได้ / Failed to credit points');
        }

        // Capture the credit ledger row id (best-effort) for traceability.
        $pointsTxnId = null;
        try {
            $pt = $db->prepare(
                "SELECT id FROM points_transactions
                 WHERE user_id = ? AND reference_type = 'claim' AND reference_id = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $pt->execute([$userId, (int) $claim['id']]);
            $pointsTxnId = $pt->fetchColumn() ?: null;
        } catch (Throwable $e) {
        }

        // Backfill claimer details on the claim row.
        $upd = $db->prepare(
            "UPDATE points_claims
             SET claimed_by_user_id = ?, claimed_line_user_id = ?, points_transaction_id = ?
             WHERE id = ?"
        );
        $upd->execute([$userId, $lineUserId, $pointsTxnId !== null ? (int) $pointsTxnId : null, (int) $claim['id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[points-claim] claim tx: ' . $e->getMessage());
        pcJson(false, 'เกิดข้อผิดพลาด / Could not complete claim');
    }

    // Running balance after credit.
    $balance = $points;
    try {
        $loyalty = new LoyaltyPoints($db, $lineAccountId);
        $bal = $loyalty->getUserPoints($userId);
        $balance = (int) ($bal['available_points'] ?? $points);
    } catch (Throwable $e) {
    }

    // Push the Flex receipt to the customer (best-effort — never fail the claim).
    $shop = pcShopInfo($db, $lineAccountId);
    pcPushReceipt($db, $lineAccountId, $lineUserId, [
        'voucher_no' => (string) $claim['voucher_no'],
        'points' => $points,
        'amount' => (float) $claim['amount'],
        'payment_method' => (string) ($claim['payment_method'] ?? ''),
        'total_points' => $balance,
        'claimed_at' => date('Y-m-d H:i:s'),
    ], $shop, $displayName, $userId);

    pcJson(true, 'รับแต้มสำเร็จ', [
        'state' => 'claimed',
        'voucher_no' => (string) $claim['voucher_no'],
        'points' => $points,
        'total_points' => $balance,
        'shop_name' => $shop['name'],
    ]);
}

/**
 * status — read-only token state (used by the claim page before/after claiming).
 */
function handleStatus(PDO $db): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        pcJson(false, 'Missing token');
    }

    $stmt = $db->prepare("SELECT status, points, amount, voucher_no, expires_at FROM points_claims WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        pcJson(false, 'Invalid token', ['state' => 'invalid']);
    }

    $state = (string) $row['status'];
    if ($state === 'pending' && strtotime((string) $row['expires_at']) < time()) {
        $state = 'expired';
    }

    pcJson(true, '', [
        'state' => $state,
        'points' => (int) $row['points'],
        'amount' => (float) $row['amount'],
        'voucher_no' => (string) $row['voucher_no'],
        'expires_at' => (string) $row['expires_at'],
    ]);
}

// =============================================================================
// Internal
// =============================================================================

function pcNormalizePayment(string $raw): ?string
{
    $s = strtolower(trim($raw));
    $allowed = ['cash', 'transfer', 'card', 'qr'];

    return in_array($s, $allowed, true) ? $s : null;
}

/**
 * Find the customer by (line_account_id, line_user_id); create a minimal row
 * if they're not a member yet. Strictly tenant-scoped.
 */
function pcResolveOrCreateUser(PDO $db, int $lineAccountId, string $lineUserId, string $displayName, string $pictureUrl): int
{
    $stmt = $db->prepare(
        "SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1"
    );
    $stmt->execute([$lineUserId, $lineAccountId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        // Opportunistically fill a missing display name / picture from LIFF.
        if ($displayName !== '' || $pictureUrl !== '') {
            try {
                $db->prepare(
                    "UPDATE users
                     SET display_name = CASE WHEN (display_name IS NULL OR display_name = '' OR display_name = 'LIFF User')
                                             THEN ? ELSE display_name END,
                         picture_url = CASE WHEN (picture_url IS NULL OR picture_url = '') THEN ? ELSE picture_url END
                     WHERE id = ?"
                )->execute([$displayName, $pictureUrl, (int) $id]);
            } catch (Throwable $e) {
                // non-fatal
            }
        }

        return (int) $id;
    }

    $name = $displayName !== '' ? $displayName : 'LINE User';
    try {
        $ins = $db->prepare(
            "INSERT INTO users (line_account_id, line_user_id, display_name, picture_url) VALUES (?, ?, ?, ?)"
        );
        $ins->execute([$lineAccountId, $lineUserId, $name, $pictureUrl !== '' ? $pictureUrl : null]);
    } catch (Throwable $e) {
        // Oldest schema fallback (no picture_url column).
        $ins = $db->prepare(
            "INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (?, ?, ?)"
        );
        $ins->execute([$lineAccountId, $lineUserId, $name]);
    }

    return (int) $db->lastInsertId();
}

/**
 * Push the points-receipt Flex to the customer. Best-effort; logs and swallows.
 */
function pcPushReceipt(PDO $db, int $lineAccountId, string $lineUserId, array $claim, array $shop, string $customerName, int $userId): void
{
    try {
        require_once __DIR__ . '/../classes/FlexTemplates.php';
        require_once __DIR__ . '/../classes/LineAccountManager.php';

        $manager = new LineAccountManager($db);
        $line = $manager->getLineAPI($lineAccountId);

        $bubble = FlexTemplates::pointsReceipt($claim, $shop, $customerName);
        $message = FlexTemplates::toMessage($bubble, '⭐ รับแต้มสำเร็จ +' . number_format((int) $claim['points']) . ' แต้ม');

        if (method_exists($line, 'pushMessage')) {
            $line->pushMessage($lineUserId, [$message]);
        }

        // Persist in chat history so it shows in the inbox conversation.
        try {
            $hasSentBy = false;
            $col = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
            $hasSentBy = $col && $col->rowCount() > 0;
            $content = json_encode($message, JSON_UNESCAPED_UNICODE);
            if ($hasSentBy) {
                $db->prepare(
                    "INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read)
                     VALUES (?, ?, 'outgoing', 'flex', ?, 'system:points', NOW(), 1)"
                )->execute([$lineAccountId, $userId, $content]);
            } else {
                $db->prepare(
                    "INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read)
                     VALUES (?, ?, 'outgoing', 'flex', ?, NOW(), 1)"
                )->execute([$lineAccountId, $userId, $content]);
            }
        } catch (Throwable $e) {
            error_log('[points-claim] persist message: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('[points-claim] push receipt: ' . $e->getMessage());
    }
}
