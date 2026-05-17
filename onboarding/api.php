<?php
/**
 * POST handler for the Setup Wizard.
 *
 * Actions:
 *   action=save-step  step=1..7  + step-specific fields
 *   action=skip-all                  → onboarding_skipped=1
 *
 * Always returns JSON: { success: bool, error?: string, redirect?: string }
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

function wz_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function wz_ok(array $extra = []): void {
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wz_fail('Method not allowed', 405);

$csrfPosted  = $_POST['csrf'] ?? '';
$csrfSession = $_SESSION['onboarding_csrf'] ?? '';
if (!$csrfPosted || !hash_equals($csrfSession, $csrfPosted)) wz_fail('Invalid CSRF', 419);

$db = Database::getInstance()->getConnection();
$adminId = (int)($currentUser['id'] ?? 0);
if ($adminId <= 0) wz_fail('Not authenticated', 401);

$action = $_POST['action'] ?? '';

function wz_required(array $fields, array $src): void {
    foreach ($fields as $f) {
        if (!isset($src[$f]) || trim((string)$src[$f]) === '') {
            wz_fail("กรุณากรอก '{$f}' ให้ครบ");
        }
    }
}
function wz_upload(string $field, string $subdir, string $prefix): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $info = @getimagesize($_FILES[$field]['tmp_name']);
    if (!$info) wz_fail('ไฟล์ต้องเป็นรูปภาพ');
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']] ?? null;
    if (!$ext) wz_fail('รองรับเฉพาะ JPG / PNG / WebP');
    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        wz_fail('อัปโหลดล้มเหลว');
    }
    return '/uploads/' . $subdir . '/' . $name;
}
function wz_line_account_id(PDO $db, int $adminId): int {
    $s = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = :id');
    $s->execute([':id' => $adminId]);
    return (int)($s->fetchColumn() ?: 0);
}
function wz_set_step(PDO $db, int $adminId, int $step): void {
    $u = $db->prepare(
        'UPDATE admin_users SET onboarding_step = GREATEST(onboarding_step, :s) WHERE id = :id'
    );
    $u->execute([':s' => $step, ':id' => $adminId]);
}

// ---- skip-all -------------------------------------------------------------
if ($action === 'skip-all') {
    $u = $db->prepare('UPDATE admin_users SET onboarding_skipped = 1 WHERE id = :id');
    $u->execute([':id' => $adminId]);
    wz_ok(['redirect' => '/index.php']);
}

if ($action !== 'save-step') wz_fail('Unknown action');

$step = (int)($_POST['step'] ?? 0);
if ($step < 1 || $step > 7) wz_fail('Invalid step');

$lineAccountId = wz_line_account_id($db, $adminId);

try {
    $db->beginTransaction();

    switch ($step) {
        case 1: // Shop profile
            wz_required(['shop_name', 'contact_phone', 'address'], $_POST);
            $logo = wz_upload('shop_logo', 'shop', 'logo');

            // Ensure this admin has a line_account row (placeholder if none)
            if ($lineAccountId <= 0) {
                $i = $db->prepare(
                    "INSERT INTO line_accounts (name, channel_secret, channel_access_token, is_active)
                     VALUES (:n, :cs, :ct, 0)"
                );
                $i->execute([
                    ':n'  => $_POST['shop_name'],
                    ':cs' => 'pending_' . bin2hex(random_bytes(6)),
                    ':ct' => '',
                ]);
                $lineAccountId = (int)$db->lastInsertId();
                $u = $db->prepare('UPDATE admin_users SET line_account_id = :la WHERE id = :id');
                $u->execute([':la' => $lineAccountId, ':id' => $adminId]);
            }

            $u = $db->prepare(
                "INSERT INTO shop_settings
                    (line_account_id, shop_name, address, contact_phone, welcome_message, shop_logo)
                 VALUES (:la, :n, :a, :p, :w, :lo)
                 ON DUPLICATE KEY UPDATE
                    shop_name = VALUES(shop_name),
                    address = VALUES(address),
                    contact_phone = VALUES(contact_phone),
                    welcome_message = VALUES(welcome_message),
                    shop_logo = COALESCE(VALUES(shop_logo), shop_logo)"
            );
            $u->execute([
                ':la' => $lineAccountId,
                ':n'  => trim($_POST['shop_name']),
                ':a'  => trim($_POST['address']),
                ':p'  => trim($_POST['contact_phone']),
                ':w'  => trim($_POST['open_hours'] ?? ''),
                ':lo' => $logo,
            ]);
            break;

        case 2: // LINE OA
            wz_required(['channel_id', 'channel_secret', 'channel_access_token', 'display_name'], $_POST);
            $u = $db->prepare(
                "UPDATE line_accounts
                    SET name = :n, channel_id = :ci, channel_secret = :cs,
                        channel_access_token = :ct, is_active = 1
                  WHERE id = :id"
            );
            $u->execute([
                ':n'  => trim($_POST['display_name']),
                ':ci' => trim($_POST['channel_id']),
                ':cs' => trim($_POST['channel_secret']),
                ':ct' => trim($_POST['channel_access_token']),
                ':id' => $lineAccountId,
            ]);
            break;

        case 3: // LIFF
            wz_required(['liff_id', 'endpoint_url', 'liff_name'], $_POST);
            $exists = $db->prepare('SELECT id FROM liff_apps WHERE line_account_id = :la LIMIT 1');
            $exists->execute([':la' => $lineAccountId]);
            $liffRowId = (int)($exists->fetchColumn() ?: 0);
            if ($liffRowId > 0) {
                $u = $db->prepare(
                    "UPDATE liff_apps SET liff_id = :li, endpoint_url = :ep, name = :n WHERE id = :id"
                );
                $u->execute([
                    ':li' => trim($_POST['liff_id']),
                    ':ep' => trim($_POST['endpoint_url']),
                    ':n'  => trim($_POST['liff_name']),
                    ':id' => $liffRowId,
                ]);
            } else {
                $u = $db->prepare(
                    "INSERT INTO liff_apps (line_account_id, liff_id, name, endpoint_url, is_active)
                     VALUES (:la, :li, :n, :ep, 1)"
                );
                $u->execute([
                    ':la' => $lineAccountId,
                    ':li' => trim($_POST['liff_id']),
                    ':n'  => trim($_POST['liff_name']),
                    ':ep' => trim($_POST['endpoint_url']),
                ]);
            }
            $u = $db->prepare('UPDATE line_accounts SET liff_id = :li WHERE id = :id');
            $u->execute([':li' => trim($_POST['liff_id']), ':id' => $lineAccountId]);
            break;

        case 4: // Payment
            wz_required(['promptpay_number'], $_POST);
            $bankJson = json_encode([
                'bank_name'    => trim($_POST['bank_name'] ?? ''),
                'account_no'   => trim($_POST['bank_account'] ?? ''),
                'account_name' => trim($_POST['bank_account_name'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
            $u = $db->prepare(
                "INSERT INTO shop_settings (line_account_id, promptpay_number, promptpay_name, bank_accounts)
                 VALUES (:la, :pp, :pn, :bk)
                 ON DUPLICATE KEY UPDATE
                    promptpay_number = VALUES(promptpay_number),
                    promptpay_name   = VALUES(promptpay_name),
                    bank_accounts    = VALUES(bank_accounts)"
            );
            $u->execute([
                ':la' => $lineAccountId,
                ':pp' => trim($_POST['promptpay_number']),
                ':pn' => trim($_POST['promptpay_name'] ?? ''),
                ':bk' => $bankJson,
            ]);
            break;

        case 5: // Pharmacist
            wz_required(['name', 'license_no'], $_POST);
            $img = wz_upload('image', 'pharmacists', 'ph');
            $u = $db->prepare(
                "INSERT INTO pharmacists
                    (line_account_id, name, license_no, image_url, is_active, is_available)
                 VALUES (:la, :n, :l, :i, 1, 1)"
            );
            $u->execute([
                ':la' => $lineAccountId,
                ':n'  => trim($_POST['name']),
                ':l'  => trim($_POST['license_no']),
                ':i'  => $img,
            ]);
            break;

        case 6: // AI (optional)
            if (empty($_POST['skip_ai'])) {
                wz_required(['provider', 'model', 'api_key'], $_POST);
                $provider = in_array($_POST['provider'], ['gemini','openai','claude'], true)
                    ? $_POST['provider'] : 'gemini';
                $col = $provider === 'openai' ? 'openai_api_key' : 'gemini_api_key';
                $sql = "INSERT INTO ai_settings
                            (line_account_id, ai_provider, model, {$col}, is_enabled)
                        VALUES (:la, :p, :m, :k, 1)
                        ON DUPLICATE KEY UPDATE
                            ai_provider = VALUES(ai_provider),
                            model       = VALUES(model),
                            {$col}      = VALUES({$col}),
                            is_enabled  = 1";
                $u = $db->prepare($sql);
                $u->execute([
                    ':la' => $lineAccountId,
                    ':p'  => $provider,
                    ':m'  => trim($_POST['model']),
                    ':k'  => trim($_POST['api_key']),
                ]);
            }
            break;

        case 7: // Complete
            $u = $db->prepare(
                "UPDATE admin_users
                    SET onboarding_completed = 1,
                        onboarding_step      = 7,
                        onboarding_completed_at = NOW()
                  WHERE id = :id"
            );
            $u->execute([':id' => $adminId]);
            $db->commit();
            wz_ok(['redirect' => '/index.php?welcome=1']);
    }

    wz_set_step($db, $adminId, $step);
    $db->commit();
    wz_ok();

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    wz_fail('Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    wz_fail($e->getMessage(), 500);
}
