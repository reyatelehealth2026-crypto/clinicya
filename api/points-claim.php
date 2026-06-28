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
        case 'lookup_phone':
            handleLookupPhone($db, $input);
            break;
        case 'member_detail':
            handleMemberDetail($db, $input);
            break;
        case 'give_by_phone':
            handleGiveByPhone($db, $input);
            break;
        case 'list_merge_candidates':
            handleListMergeCandidates($db, $input);
            break;
        case 'confirm_merge':
            handleConfirmMerge($db, $input);
            break;
        case 'dismiss_merge':
            handleDismissMerge($db, $input);
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

// =============================================================================
// Phone-keyed counter loyalty (external POS, LINE optional) — 2026-06-20
// =============================================================================

/**
 * Normalize a Thai phone number to digits ('+66 81-234-5678' -> '0812345678').
 */
function pcNormalizePhone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 11 && strpos($digits, '66') === 0) {
        $digits = '0' . substr($digits, 2); // +66xxxxxxxxx -> 0xxxxxxxxx
    }
    return $digits;
}

/** Best display name for a users row (real name first, then parts, then LINE name). */
function pcCustomerName(array $u): string
{
    $real = trim((string) ($u['real_name'] ?? ''));
    if ($real !== '') {
        return $real;
    }
    $parts = trim(trim((string) ($u['first_name'] ?? '')) . ' ' . trim((string) ($u['last_name'] ?? '')));
    if ($parts !== '') {
        return $parts;
    }
    $dn = trim((string) ($u['display_name'] ?? ''));
    return $dn !== '' ? $dn : 'ลูกค้า';
}

/** True when this users row is a real LINE follower (not a phone-only ghost). */
function pcIsLineUser(array $u): bool
{
    return strpos((string) ($u['line_user_id'] ?? ''), 'offline:') !== 0;
}

/**
 * Defensive self-create for the merge-flag table (mirrors
 * database/migration_2026-06-20_points_phone_members.sql).
 */
function ensurePointsMergeTable(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `points_merge_candidates` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `line_account_id` INT NOT NULL,
                `phone` VARCHAR(20) NOT NULL,
                `offline_user_id` INT NOT NULL,
                `line_user_id` INT NOT NULL,
                `offline_points` INT NOT NULL DEFAULT 0,
                `status` ENUM('pending','merged','dismissed') NOT NULL DEFAULT 'pending',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `resolved_at` TIMESTAMP NULL DEFAULT NULL,
                `resolved_by` INT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_pair` (`line_account_id`, `offline_user_id`, `line_user_id`),
                KEY `idx_account_status` (`line_account_id`, `status`),
                KEY `idx_phone` (`line_account_id`, `phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[points-claim] ensure merge table: ' . $e->getMessage());
    }
}

/**
 * Shared credit core for counter sales: audit row in points_claims (already
 * 'claimed') + LoyaltyPoints::addPoints ledger entry. Mirrors handleGiveDirect.
 *
 * @param array<string,mixed> $user users row (must include id, line_user_id)
 * @return array{ok:bool, claim_id?:int, voucher_no?:string, balance?:int}
 */
function pcCreditCounterSale(
    PDO $db,
    LoyaltyPoints $loyalty,
    int $lineAccountId,
    array $user,
    float $amount,
    int $points,
    ?string $paymentMethod,
    ?int $createdBy
): array {
    $userId = (int) $user['id'];
    $voucherNo = pcGenerateVoucherNo($db, $lineAccountId);
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); // token col is NOT NULL+UNIQUE

    $db->beginTransaction();
    try {
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

        $credited = $loyalty->addPoints($userId, $points, 'claim', $claimId, 'รับแต้มจากการซื้อหน้าร้าน #' . $voucherNo);
        if (!$credited) {
            $db->rollBack();
            return ['ok' => false];
        }

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
        error_log('[points-claim] counter credit tx: ' . $e->getMessage());
        return ['ok' => false];
    }

    $balance = $points;
    try {
        $bal = $loyalty->getUserPoints($userId);
        $balance = (int) ($bal['available_points'] ?? $points);
    } catch (Throwable $e) {
    }

    return ['ok' => true, 'claim_id' => $claimId, 'voucher_no' => $voucherNo, 'balance' => $balance];
}

/**
 * lookup_phone — fast counter lookup. Returns every customer sharing the phone
 * in this tenant (LINE-linked first), each with name + current balance. Doubles
 * as the "check points" screen. Admin session required.
 *
 * @param array<string,mixed> $data
 */
function handleLookupPhone(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    if ($lineAccountId <= 0) {
        pcJson(false, 'Missing line_account_id');
    }
    $phone = pcNormalizePhone((string) ($data['phone'] ?? ''));
    if (strlen($phone) < 8) {
        pcJson(false, 'เบอร์ไม่ถูกต้อง / Invalid phone');
    }

    $stmt = $db->prepare(
        "SELECT id, line_user_id, display_name, real_name, first_name, last_name, picture_url, available_points
         FROM users
         WHERE line_account_id = ? AND phone = ?
         ORDER BY (line_user_id LIKE 'offline:%') ASC, available_points DESC, id ASC"
    );
    $stmt->execute([$lineAccountId, $phone]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        pcJson(true, '', ['found' => false, 'phone' => $phone]);
    }

    $customers = array_map(static function (array $r): array {
        $hasLine = pcIsLineUser($r);
        return [
            'user_id' => (int) $r['id'],
            'name' => pcCustomerName($r),
            'points' => (int) $r['available_points'],
            'has_line' => $hasLine,
            'picture_url' => $hasLine ? (string) ($r['picture_url'] ?? '') : '',
        ];
    }, $rows);

    pcJson(true, '', ['found' => true, 'phone' => $phone, 'customers' => $customers]);
}

/**
 * member_detail — a customer's points summary + recent ledger entries. Used by
 * the phone-members admin page (loyalty-members.php). Admin session required.
 *
 * @param array<string,mixed> $data
 */
function handleMemberDetail(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    $userId = (int) ($data['user_id'] ?? 0);
    if ($lineAccountId <= 0 || $userId <= 0) {
        pcJson(false, 'ข้อมูลไม่ครบ / Missing parameters');
    }

    $us = $db->prepare(
        "SELECT id, line_user_id, display_name, real_name, first_name, last_name, phone,
                available_points, total_points, used_points, created_at
         FROM users WHERE id = ? AND line_account_id = ? LIMIT 1"
    );
    $us->execute([$userId, $lineAccountId]);
    $u = $us->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        pcJson(false, 'ไม่พบลูกค้า / Customer not found');
    }

    $tx = [];
    try {
        $ts = $db->prepare(
            "SELECT type, points, balance_after, description, created_at
             FROM points_transactions
             WHERE user_id = ? AND line_account_id = ?
             ORDER BY id DESC LIMIT 50"
        );
        $ts->execute([$userId, $lineAccountId]);
        $tx = $ts->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    pcJson(true, '', [
        'customer' => [
            'user_id' => (int) $u['id'],
            'name' => pcCustomerName($u),
            'phone' => (string) ($u['phone'] ?? ''),
            'has_line' => pcIsLineUser($u),
            'available_points' => (int) $u['available_points'],
            'total_points' => (int) $u['total_points'],
            'used_points' => (int) $u['used_points'],
            'created_at' => (string) ($u['created_at'] ?? ''),
        ],
        'transactions' => array_map(static function (array $t): array {
            return [
                'type' => (string) $t['type'],
                'points' => (int) $t['points'],
                'balance_after' => (int) $t['balance_after'],
                'description' => (string) ($t['description'] ?? ''),
                'created_at' => (string) ($t['created_at'] ?? ''),
            ];
        }, $tx),
    ]);
}

/**
 * give_by_phone — credit points to a customer identified by phone. Finds an
 * existing customer (LINE-linked preferred) or auto-creates a phone-only ghost
 * (line_user_id = 'offline:<phone>'). When a sale is credited to a LINE user
 * while a separate ghost with the same phone still holds points, a merge
 * candidate is flagged for later pharmacist confirmation (never auto-merged).
 * Admin session required.
 *
 * @param array<string,mixed> $data
 */
function handleGiveByPhone(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    if ($lineAccountId <= 0) {
        pcJson(false, 'Missing line_account_id');
    }

    $phone = pcNormalizePhone((string) ($data['phone'] ?? ''));
    if (strlen($phone) < 8) {
        pcJson(false, 'กรุณากรอกเบอร์ให้ถูกต้อง / Enter a valid phone');
    }
    $name = trim((string) ($data['name'] ?? ''));
    $explicitUserId = (int) ($data['user_id'] ?? 0); // pharmacist picked a specific match

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

    $cols = 'id, line_user_id, display_name, real_name, first_name, last_name, available_points';
    $stmt = $db->prepare(
        "SELECT $cols FROM users
         WHERE line_account_id = ? AND phone = ?
         ORDER BY (line_user_id LIKE 'offline:%') ASC, available_points DESC, id ASC"
    );
    $stmt->execute([$lineAccountId, $phone]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $target = null;
    if ($explicitUserId > 0) {
        foreach ($matches as $m) {
            if ((int) $m['id'] === $explicitUserId) {
                $target = $m;
                break;
            }
        }
        if (!$target) {
            $vs = $db->prepare("SELECT $cols FROM users WHERE id = ? AND line_account_id = ? LIMIT 1");
            $vs->execute([$explicitUserId, $lineAccountId]);
            $target = $vs->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (!$target && $matches) {
        $target = $matches[0]; // ordering puts LINE-linked first
    }

    $isNew = false;
    if (!$target) {
        $syntheticLineId = 'offline:' . $phone;
        $displayName = $name !== '' ? $name : ('ลูกค้า ' . substr($phone, -4));
        try {
            $ins = $db->prepare(
                "INSERT INTO users (line_account_id, line_user_id, display_name, real_name, phone, is_registered, source, registered_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, 'counter', NOW(), NOW())"
            );
            $ins->execute([$lineAccountId, $syntheticLineId, $displayName, ($name !== '' ? $name : null), $phone]);
            $newId = (int) $db->lastInsertId();
            $rs = $db->prepare("SELECT $cols FROM users WHERE id = ? LIMIT 1");
            $rs->execute([$newId]);
            $target = $rs->fetch(PDO::FETCH_ASSOC) ?: null;
            $isNew = true;
        } catch (Throwable $e) {
            // unique_line_user race — fetch the row that won
            $rs = $db->prepare("SELECT $cols FROM users WHERE line_account_id = ? AND line_user_id = ? LIMIT 1");
            $rs->execute([$lineAccountId, $syntheticLineId]);
            $target = $rs->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$target) {
            pcJson(false, 'ไม่สามารถสร้างลูกค้า / Could not create customer');
        }
    } elseif ($name !== '' && !pcIsLineUser($target) && trim((string) ($target['real_name'] ?? '')) === '') {
        // backfill a name onto an unnamed ghost
        try {
            $db->prepare("UPDATE users SET real_name = ?, display_name = ? WHERE id = ?")
               ->execute([$name, $name, (int) $target['id']]);
            $target['real_name'] = $name;
            $target['display_name'] = $name;
        } catch (Throwable $e) {
        }
    }

    $createdBy = (int) ($_SESSION['admin_user']['id'] ?? 0) ?: null;
    $res = pcCreditCounterSale($db, $loyalty, $lineAccountId, $target, $amount, $points, $paymentMethod, $createdBy);
    if (empty($res['ok'])) {
        pcJson(false, 'ไม่สามารถให้แต้มได้ / Could not credit points');
    }

    $targetIsLine = pcIsLineUser($target);

    if ($targetIsLine) {
        $shop = pcShopInfo($db, $lineAccountId);
        pcPushReceipt($db, $lineAccountId, (string) $target['line_user_id'], [
            'voucher_no' => $res['voucher_no'],
            'points' => $points,
            'amount' => $amount,
            'payment_method' => (string) $paymentMethod,
            'total_points' => $res['balance'],
            'claimed_at' => date('Y-m-d H:i:s'),
        ], $shop, pcCustomerName($target), (int) $target['id']);
    }

    $mergeFlag = $targetIsLine
        ? pcFlagMergeForPhone($db, $lineAccountId, $phone, (int) $target['id'])
        : null;

    pcJson(true, 'ให้แต้มสำเร็จ +' . number_format($points) . ' แต้ม', [
        'state' => 'claimed',
        'voucher_no' => $res['voucher_no'],
        'points' => $points,
        'total_points' => $res['balance'],
        'user_id' => (int) $target['id'],
        'customer_name' => pcCustomerName($target),
        'has_line' => $targetIsLine,
        'is_new' => $isNew,
        'merge_flag' => $mergeFlag,
    ]);
}

/**
 * Flag (don't perform) a merge when a LINE user shares a phone with a points-
 * holding offline ghost. Idempotent via the uniq_pair key.
 *
 * @return array{offline_user_id:int, offline_points:int}|null
 */
function pcFlagMergeForPhone(PDO $db, int $lineAccountId, string $phone, int $lineUserId): ?array
{
    try {
        ensurePointsMergeTable($db);
        $st = $db->prepare(
            "SELECT id, available_points FROM users
             WHERE line_account_id = ? AND phone = ? AND line_user_id LIKE 'offline:%' AND available_points > 0
             ORDER BY available_points DESC LIMIT 1"
        );
        $st->execute([$lineAccountId, $phone]);
        $ghost = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ghost) {
            return null;
        }
        $offlineId = (int) $ghost['id'];
        if ($offlineId === $lineUserId) {
            return null;
        }
        $pts = (int) $ghost['available_points'];

        $db->prepare(
            "INSERT INTO points_merge_candidates
                (line_account_id, phone, offline_user_id, line_user_id, offline_points, status)
             VALUES (?, ?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE offline_points = VALUES(offline_points),
                status = IF(status = 'merged', 'merged', 'pending'), resolved_at = NULL, resolved_by = NULL"
        )->execute([$lineAccountId, $phone, $offlineId, $lineUserId, $pts]);

        return ['offline_user_id' => $offlineId, 'offline_points' => $pts];
    } catch (Throwable $e) {
        error_log('[points-claim] flag merge: ' . $e->getMessage());
        return null;
    }
}

/**
 * list_merge_candidates — pending phone->LINE merges for the quiet-time review
 * surface. Admin session required.
 *
 * @param array<string,mixed> $data
 */
function handleListMergeCandidates(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    if ($lineAccountId <= 0) {
        pcJson(false, 'Missing line_account_id');
    }
    ensurePointsMergeTable($db);

    $stmt = $db->prepare(
        "SELECT mc.id, mc.phone, mc.offline_points,
                go.real_name AS ghost_name, go.display_name AS ghost_display,
                lu.real_name AS line_real, lu.display_name AS line_display, lu.available_points AS line_points
         FROM points_merge_candidates mc
         JOIN users go ON go.id = mc.offline_user_id
         JOIN users lu ON lu.id = mc.line_user_id
         WHERE mc.line_account_id = ? AND mc.status = 'pending'
         ORDER BY mc.created_at DESC LIMIT 100"
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'phone' => (string) $r['phone'],
            'offline_points' => (int) $r['offline_points'],
            'ghost_name' => pcCustomerName(['real_name' => $r['ghost_name'], 'display_name' => $r['ghost_display']]),
            'line_name' => pcCustomerName(['real_name' => $r['line_real'], 'display_name' => $r['line_display']]),
            'line_points' => (int) $r['line_points'],
        ];
    }, $rows);

    pcJson(true, '', ['count' => count($items), 'candidates' => $items]);
}

/**
 * confirm_merge — move the ghost's points into the LINE user, then mark merged.
 * Admin session required.
 *
 * @param array<string,mixed> $data
 */
function handleConfirmMerge(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    $candidateId = (int) ($data['candidate_id'] ?? 0);
    if ($lineAccountId <= 0 || $candidateId <= 0) {
        pcJson(false, 'ข้อมูลไม่ครบ / Missing parameters');
    }
    ensurePointsMergeTable($db);

    $cs = $db->prepare(
        "SELECT * FROM points_merge_candidates WHERE id = ? AND line_account_id = ? AND status = 'pending' LIMIT 1"
    );
    $cs->execute([$candidateId, $lineAccountId]);
    $cand = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$cand) {
        pcJson(false, 'ไม่พบรายการรอยืนยัน / Candidate not found or already resolved');
    }

    $offlineId = (int) $cand['offline_user_id'];
    $lineUserId = (int) $cand['line_user_id'];
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
    $resolvedBy = (int) ($_SESSION['admin_user']['id'] ?? 0) ?: null;

    // Re-read the ghost's CURRENT balance (it may have changed since flagging).
    $gs = $db->prepare("SELECT available_points FROM users WHERE id = ? AND line_account_id = ? LIMIT 1");
    $gs->execute([$offlineId, $lineAccountId]);
    $move = (int) ($gs->fetchColumn() ?: 0);

    try {
        if ($move > 0) {
            $loyalty->deductPoints($offlineId, $move, 'merge', $candidateId, 'โอนแต้มไปยังบัญชี LINE (รวมร่าง)');
            $loyalty->addPoints($lineUserId, $move, 'merge', $candidateId, 'รับแต้มจากบัญชีเบอร์เดิม (รวมร่าง)');
        }
        $db->prepare(
            "UPDATE points_merge_candidates
             SET status = 'merged', offline_points = ?, resolved_at = NOW(), resolved_by = ?
             WHERE id = ?"
        )->execute([$move, $resolvedBy, $candidateId]);
    } catch (Throwable $e) {
        error_log('[points-claim] confirm merge: ' . $e->getMessage());
        pcJson(false, 'ไม่สามารถรวมแต้มได้ / Merge failed');
    }

    $balance = $move;
    try {
        $bal = $loyalty->getUserPoints($lineUserId);
        $balance = (int) ($bal['available_points'] ?? $move);
    } catch (Throwable $e) {
    }

    pcJson(true, 'รวมแต้มสำเร็จ +' . number_format($move) . ' แต้ม', [
        'merged_points' => $move,
        'line_balance' => $balance,
    ]);
}

/**
 * dismiss_merge — pharmacist decides NOT to merge (e.g. mistyped phone).
 *
 * @param array<string,mixed> $data
 */
function handleDismissMerge(PDO $db, array $data): void
{
    if (empty($_SESSION['admin_user'])) {
        http_response_code(401);
        pcJson(false, 'กรุณาเข้าสู่ระบบ / Unauthorized');
    }
    $lineAccountId = (int) ($data['line_account_id'] ?? \TenantContext::getCurrentTenantId() ?? 0);
    $candidateId = (int) ($data['candidate_id'] ?? 0);
    if ($lineAccountId <= 0 || $candidateId <= 0) {
        pcJson(false, 'ข้อมูลไม่ครบ / Missing parameters');
    }
    ensurePointsMergeTable($db);
    $resolvedBy = (int) ($_SESSION['admin_user']['id'] ?? 0) ?: null;
    $db->prepare(
        "UPDATE points_merge_candidates
         SET status = 'dismissed', resolved_at = NOW(), resolved_by = ?
         WHERE id = ? AND line_account_id = ? AND status = 'pending'"
    )->execute([$resolvedBy, $candidateId, $lineAccountId]);
    pcJson(true, 'ยกเลิกการรวมแล้ว / Dismissed');
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
