<?php
/**
 * admin/platform-settings.php — Platform Owner: Subscription bank-account settings
 *
 * Lets the Platform Owner view/edit the bank account tenants use to pay subscription fees.
 * Values are stored in the master `platform_settings` table (key/value pairs).
 *
 * Auth: requires $_SESSION['platform_user_id'] (super admin).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>403</title>'
       . '<body style="font-family:sans-serif;padding:40px">'
       . '<h1>403 — Platform Owner only</h1>'
       . '<p><a href="/admin/platform-login.php">Sign in</a></p></body></html>';
    exit;
}

$db = Database::platform()->getConnection();

$h = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// Keys managed on this page
$KEYS = [
    'subscription_bank_name'     => 'ธนาคาร',
    'subscription_bank_acct_name' => 'ชื่อบัญชี',
    'subscription_bank_acct_no'  => 'เลขที่บัญชี',
    'subscription_promptpay_id'  => 'PromptPay (เบอร์/เลขประจำตัว)',
];

// ---------------------------------------------------------------------------
// POST — UPSERT each key
// ---------------------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $upsert = $db->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach (array_keys($KEYS) as $key) {
            $val = trim((string)($_POST[$key] ?? ''));
            $upsert->execute([$key, $val !== '' ? $val : null]);
        }
        $flash = ['type' => 'ok', 'msg' => 'บันทึกการตั้งค่าเรียบร้อยแล้ว'];
    } catch (\Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()];
        error_log('[platform-settings] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// GET — load current values
// ---------------------------------------------------------------------------
$tableError = false;
$values     = [];
try {
    $inList = implode(',', array_fill(0, count($KEYS), '?'));
    $stmt   = $db->prepare(
        "SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ({$inList})"
    );
    $stmt->execute(array_keys($KEYS));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $values[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Throwable $e) {
    $tableError = true;
    error_log('[platform-settings] load failed: ' . $e->getMessage());
}
?>
<?php
require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('settings', 'ตั้งค่าระบบ', 'บัญชีรับชำระค่าบริการ (Subscription)');
?>
    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
            <?= $h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($tableError): ?>
        <div class="pf-card pf-card-pad text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl">
            <i class="fas fa-triangle-exclamation mr-2"></i>
            ยังไม่ได้ติดตั้งตาราง <code>platform_settings</code> — รัน migration ก่อน
        </div>
    <?php else: ?>
        <div class="pf-card pf-card-pad max-w-xl">
            <p class="text-sm text-slate-500 mb-5">
                ข้อมูลบัญชีด้านล่างจะแสดงบนหน้า Billing ของ tenant ทุกราย เพื่อให้ลูกค้าโอนชำระค่าบริการรายเดือน
            </p>
            <form method="POST" class="space-y-4">
                <?php foreach ($KEYS as $key => $label): ?>
                    <div>
                        <label for="<?= $h($key) ?>" class="block text-sm font-medium text-slate-700 mb-1">
                            <?= $h($label) ?>
                        </label>
                        <input
                            type="text"
                            id="<?= $h($key) ?>"
                            name="<?= $h($key) ?>"
                            value="<?= $h($values[$key] ?? '') ?>"
                            class="pf-input w-full"
                        >
                    </div>
                <?php endforeach; ?>

                <div class="pt-2">
                    <button type="submit" class="pf-btn pf-btn-primary">
                        <i class="fas fa-save mr-1"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php platform_shell_bottom(); ?>
