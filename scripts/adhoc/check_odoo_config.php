<?php
/**
 * Odoo Config Diagnostic
 * เปิด URL นี้ดูว่า OdooProductService ทำไม isConfigured() = false
 *
 *   https://clinicya.re-ya.com/install/check_odoo_config.php
 *
 * แสดง:
 *   - ODOO_ENVIRONMENT (production/staging)
 *   - ODOO_API_BASE_URL (ต้องไม่ว่าง)
 *   - CNY_ODOO_API_USER (ต้องไม่ว่าง)
 *   - CNY_ODOO_USER_TOKEN (ต้องไม่ว่าง แสดงเฉพาะ length + prefix/suffix)
 *   - env vars ที่ใช้ override
 *   - ทดสอบ getProductsByRange(1, 1) ถ้า config ครบ
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Admin guard
if (empty($_SESSION['admin_user'])) {
    header('Location: /auth/login.php');
    exit;
}

$role = $_SESSION['admin_user']['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'], true)) {
    http_response_code(403);
    die('Admin only');
}

/**
 * mask string เพื่อ log โดยไม่เปิด secret
 */
function mask(string $s, int $prefix = 4, int $suffix = 4): string
{
    $len = strlen($s);
    if ($len === 0) {
        return '(ว่าง)';
    }
    if ($len <= $prefix + $suffix) {
        return str_repeat('*', $len);
    }
    return substr($s, 0, $prefix) . str_repeat('*', max(3, $len - $prefix - $suffix)) . substr($s, -$suffix) . " (len={$len})";
}

function statusBadge(bool $ok): string
{
    return $ok
        ? '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#10b981;color:#fff;font-size:12px;">✓ OK</span>'
        : '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#ef4444;color:#fff;font-size:12px;">✗ EMPTY</span>';
}

$env              = defined('ODOO_ENVIRONMENT') ? ODOO_ENVIRONMENT : '(not defined)';
$baseUrl          = defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : '';
$apiUser          = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : '';
$userToken        = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '';

$envVars = [
    'CNY_ODOO_API_USER'        => getenv('CNY_ODOO_API_USER'),
    'CNY_ODOO_USER_TOKEN'      => getenv('CNY_ODOO_USER_TOKEN'),
    'ODOO_PRODUCTION_API_USER' => getenv('ODOO_PRODUCTION_API_USER'),
    'ODOO_PRODUCTION_USER_TOKEN' => getenv('ODOO_PRODUCTION_USER_TOKEN'),
];

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Odoo Config Diagnostic</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 980px; margin: 32px auto; padding: 0 16px; background: #f7f7f7; color: #333; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        h2 { font-size: 16px; margin-top: 24px; color: #555; }
        .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin: 12px 0; }
        .ok { background: #e6f7ee; border-color: #a0d9b4; }
        .err { background: #fde7e7; border-color: #f3a7a7; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #fafafa; font-weight: 600; color: #666; }
        code { font-family: ui-monospace, monospace; background: #f3f4f6; padding: 2px 6px; border-radius: 3px; }
        .btn { display: inline-block; padding: 10px 18px; background: #2563eb; color: #fff; border-radius: 6px; text-decoration: none; margin: 8px 4px 0 0; }
        .btn.secondary { background: #6b7280; }
        .btn.green { background: #10b981; }
        pre { background: #1f2937; color: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>

<h1>🔍 Odoo Product API — Diagnostic</h1>
<p>ตรวจสอบว่า <code>OdooProductService::isConfigured()</code> ทำไม return false</p>

<?php
$baseUrlOk   = $baseUrl !== '';
$apiUserOk   = $apiUser !== '';
$userTokenOk = $userToken !== '';
$isConfigured = $baseUrlOk && $apiUserOk && $userTokenOk;
?>

<div class="box <?= $isConfigured ? 'ok' : 'err' ?>">
    <h2 style="margin-top:0;">
        สรุป: <?= $isConfigured
            ? '<span style="color:#0a6;">✅ พร้อมใช้งาน</span>'
            : '<span style="color:#c00;">❌ ยังไม่พร้อม (ขาดค่า config)</span>' ?>
    </h2>

    <table>
        <tr>
            <th>ตัวแปร</th>
            <th>ค่าที่ได้</th>
            <th>สถานะ</th>
        </tr>
        <tr>
            <td><code>ODOO_ENVIRONMENT</code></td>
            <td><code><?= htmlspecialchars((string) $env) ?></code></td>
            <td><?= $env === 'production'
                ? '<span style="color:#0a6;">production</span>'
                : '<span style="color:#c80;">⚠ ไม่ใช่ production — credentials อาจว่าง</span>' ?></td>
        </tr>
        <tr>
            <td><code>ODOO_API_BASE_URL</code></td>
            <td><code><?= htmlspecialchars($baseUrl ?: '(ว่าง)') ?></code></td>
            <td><?= statusBadge($baseUrlOk) ?></td>
        </tr>
        <tr>
            <td><code>CNY_ODOO_API_USER</code></td>
            <td><code><?= htmlspecialchars(mask($apiUser, 5, 5)) ?></code></td>
            <td><?= statusBadge($apiUserOk) ?></td>
        </tr>
        <tr>
            <td><code>CNY_ODOO_USER_TOKEN</code></td>
            <td><code><?= htmlspecialchars(mask($userToken, 6, 4)) ?></code></td>
            <td><?= statusBadge($userTokenOk) ?></td>
        </tr>
    </table>
</div>

<h2>📡 Environment Variables (getenv) — ใช้ override ค่า default</h2>
<div class="box">
    <p style="color:#666;font-size:13px;">
        ถ้าค่าเหล่านี้ถูกตั้งเป็น <b>string ว่าง</b> (แต่ไม่ได้ลบออก) จะทำให้ <code>getenv() ?: default</code>
        return <b>ค่าว่าง</b> แทน default → ทำให้ config ผิดพลาด
    </p>
    <table>
        <tr>
            <th>ENV</th>
            <th>ค่าปัจจุบัน</th>
            <th>สถานะ</th>
        </tr>
        <?php foreach ($envVars as $name => $val): ?>
            <tr>
                <td><code><?= htmlspecialchars($name) ?></code></td>
                <td>
                    <?php if ($val === false): ?>
                        <span style="color:#999;">(not set — ใช้ default)</span>
                    <?php elseif ($val === ''): ?>
                        <span style="color:#c00;">⚠ (ว่าง — override default เป็น '')</span>
                    <?php else: ?>
                        <code><?= htmlspecialchars(mask((string) $val, 5, 4)) ?></code>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($val === false): ?>
                        <span style="color:#666;">OK</span>
                    <?php elseif ($val === ''): ?>
                        <span style="color:#c00;"><b>ลบ env var นี้ออก</b></span>
                    <?php else: ?>
                        <span style="color:#0a6;">set</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if (!$isConfigured): ?>
<h2>🛠 วิธีแก้</h2>
<div class="box err">
    <p><b>กรณีที่พบบ่อย:</b></p>
    <ol>
        <li>
            <b><code>ODOO_ENVIRONMENT</code> ไม่ใช่ <code>production</code></b> —
            config/config.php บรรทัด 51 ต้องเป็น <code>define('ODOO_ENVIRONMENT', 'production');</code>
        </li>
        <li>
            <b>Server มี env var <code>CNY_ODOO_USER_TOKEN=""</code> (string ว่าง)</b> —
            ถ้าเปิดเจอ ⚠ ด้านบน ให้แก้ไข/ลบ env var นั้นออก (เช่น ลบใน <code>.user.ini</code>, <code>.htaccess</code>, หรือ cPanel env)
        </li>
        <li>
            <b>Server มี config.php version เก่า</b> —
            บรรทัด 140-146 อาจไม่มี → ต้อง deploy config.php ใหม่ไปด้วย
            (ตรวจ <code>grep "CNY_ODOO_USER_TOKEN" config/config.php</code> ที่ server)
        </li>
        <li>
            <b>Server <code>clinicya.re-ya.com</code> ใช้ config.php แยก</b> จาก <code>cny.re-ya.com</code> —
            ถ้าใช่ ให้ deploy/sync config.php ไปด้วย
        </li>
    </ol>
    <p><b>วิธีแก้เร่งด่วน</b> (ทดสอบ): สามารถ hardcode token ใน <code>config/config.php</code> บรรทัด 146 แทนที่ <code>$defaultCnyOdooUserToken</code></p>
</div>
<?php endif; ?>

<?php
// ─── ทดสอบ API call ถ้า config ครบ ─────────────────────────────────────────────
if ($isConfigured && !empty($_GET['test'])):
?>
<h2>🧪 ผลทดสอบเรียก API</h2>
<div class="box">
    <?php
    try {
        require_once __DIR__ . '/../classes/OdooProductService.php';
        $svc = new OdooProductService(Database::getInstance()->getConnection(), 1);
        $startAt = microtime(true);
        $result = $svc->getProductsByRange(1, 1);
        $tookMs = (int) ((microtime(true) - $startAt) * 1000);
        ?>
        <p><span style="color:#0a6;font-weight:600;">✓ API ตอบสำเร็จ</span> ในเวลา <?= $tookMs ?>ms</p>
        <p>ได้สินค้า <?= count($result['products']) ?> รายการจากช่วง offset=<?= $result['offset'] ?> limit=<?= $result['limit'] ?></p>
        <pre><?= htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    <?php } catch (\Throwable $e) { ?>
        <p><span style="color:#c00;font-weight:600;">✗ ERROR:</span> <?= htmlspecialchars($e->getMessage()) ?></p>
        <pre><?= htmlspecialchars($e->getTraceAsString()) ?></pre>
    <?php } ?>
</div>
<?php endif; ?>

<div style="margin-top:24px;">
    <?php if ($isConfigured): ?>
        <a href="?test=1" class="btn green">🧪 ทดสอบเรียก API (getProductsByRange 1,1)</a>
    <?php endif; ?>
    <a href="/inventory/?tab=catalog-sync" class="btn secondary">← กลับ catalog-sync</a>
</div>

</body>
</html>
