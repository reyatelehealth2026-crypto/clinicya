<?php
/**
 * auth/google-onboard.php — collect shop name + subdomain for a new Google
 * account, then provision a LOCKED (pending_setup) tenant.
 *
 * Requires $_SESSION['google_pending'] (set by auth/google-callback.php).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sso_config.php';
require_once __DIR__ . '/../classes/SelfServeProvisioning.php';
require_once __DIR__ . '/../classes/TenantSso.php';

$g = $_SESSION['google_pending'] ?? null;
// 15-minute freshness window on the pending Google identity.
if (!is_array($g) || empty($g['sub']) || (time() - (int)($g['ts'] ?? 0)) > 900) {
    header('Location: /signup.php', true, 302);
    exit;
}

$base = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';
$h    = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$error = null;
$shopName = '';
$slug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shopName = trim((string)($_POST['shop_name'] ?? ''));
    $slug     = strtolower(trim((string)($_POST['subdomain'] ?? '')));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    try {
        $res = SelfServeProvisioning::provision([
            'google_id' => $g['sub'],
            'email'     => $g['email'],
            'name'      => $g['name'],
            'shop_name' => $shopName,
            'subdomain' => $slug,
            'phone'     => $phone,
        ]);
        // Done — log the new owner straight into their dashboard (demo mode)
        // via an SSO handoff to the subdomain.
        unset($_SESSION['google_pending']);

        // Best-effort alert to the platform owner (email + Telegram). Never blocks signup.
        try {
            require_once __DIR__ . '/../classes/SiteNotifier.php';
            SiteNotifier::notifySignup([
                'shop_name' => $shopName,
                'subdomain' => $res['subdomain'],
                'tenant_id' => $res['tenant_id'],
                'email'     => $g['email'],
                'name'      => $g['name'],
                'phone'     => $phone,
            ]);
        } catch (\Throwable $eNotify) {
            error_log('[google-onboard] signup notify: ' . $eNotify->getMessage());
        }

        $ssoToken = TenantSso::sign($g['email'], (int)$res['tenant_id'], (string)$res['subdomain']);
        header('Location: https://' . $res['subdomain'] . '.' . $base . '/auth/sso-consume.php?token=' . urlencode($ssoToken), true, 302);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าร้านของคุณ — REYA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-6">
    <div class="bg-white rounded-3xl border border-slate-200 shadow-xl w-full max-w-md p-8">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-600 text-white mb-3">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1.6-5h14.8L21 9M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9M4 9h16M9 20v-6h6v6"/></svg>
            </div>
            <h1 class="text-xl font-bold text-slate-900">ตั้งค่าร้านของคุณ</h1>
            <p class="text-sm text-slate-500 mt-1">เข้าสู่ระบบเป็น <strong><?= $h($g['email']) ?></strong></p>
        </div>

        <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-xl text-sm bg-red-50 border border-red-200 text-red-700"><?= $h($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4" id="onboardForm">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">ชื่อร้าน</label>
                <input type="text" name="shop_name" value="<?= $h($shopName) ?>" required maxlength="150"
                       placeholder="เช่น ร้านยาเภสัชกรสมชาย"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">ชื่อเว็บ (subdomain)</label>
                <div class="flex items-center border border-slate-300 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-emerald-500">
                    <input type="text" name="subdomain" value="<?= $h($slug) ?>" required
                           pattern="[a-z0-9][a-z0-9-]{1,28}[a-z0-9]" minlength="3" maxlength="30"
                           placeholder="myshop"
                           class="flex-1 px-4 py-2.5 text-sm focus:outline-none lowercase">
                    <span class="px-3 py-2.5 bg-slate-50 text-slate-500 text-sm border-l border-slate-200">.<?= $h($base) ?></span>
                </div>
                <p class="text-xs text-slate-400 mt-1">a-z, 0-9, ขีดกลาง (3–30 ตัว)</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">เบอร์โทร <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                <input type="tel" name="phone" value="<?= $h($_POST['phone'] ?? '') ?>" maxlength="40"
                       placeholder="08x-xxx-xxxx"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>
            <button type="submit" id="submitBtn" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 rounded-xl transition">
                สร้างร้าน
            </button>
            <p class="text-xs text-slate-400 text-center">ร้านจะถูกสร้างทันที และรอทีมงานอนุมัติก่อนเปิดใช้งาน</p>
        </form>
    </div>

    <!-- Provisioning progress overlay — shown while the shop is being created -->
    <div id="provisioning" class="prov-overlay" role="status" aria-live="polite" aria-hidden="true">
        <div class="prov-card">
            <div class="prov-mark">R</div>
            <h2>กำลังสร้างร้านของคุณ</h2>
            <p class="prov-status" id="provStatus">กำลังเริ่มต้น</p>
            <div class="prov-track"><div class="prov-fill" id="provFill"></div></div>
            <p class="prov-note">โปรดอย่าปิดหรือรีเฟรชหน้านี้ ระบบกำลังเตรียมร้านให้คุณ</p>
        </div>
    </div>
    <style>
        .prov-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:24px;
            background:rgba(248,250,252,.94);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
        .prov-overlay.show{display:flex}
        .prov-card{width:min(92vw,400px);background:#fff;border:1px solid #e2e8f0;border-radius:24px;
            box-shadow:0 30px 70px -30px rgba(15,23,42,.3);padding:38px 32px;text-align:center}
        .prov-mark{width:54px;height:54px;border-radius:16px;background:#059669;color:#fff;font-weight:800;font-size:22px;
            display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
        .prov-card h2{font-size:18px;font-weight:700;color:#0f172a;margin-bottom:8px}
        .prov-status{font-size:14px;color:#059669;font-weight:600;min-height:21px;transition:opacity .25s}
        .prov-track{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:18px 0 14px}
        .prov-fill{height:100%;width:6%;border-radius:999px;background:linear-gradient(90deg,#10b981,#059669);
            transition:width .6s cubic-bezier(.16,1,.3,1)}
        .prov-note{font-size:12px;color:#94a3b8;line-height:1.6}
    </style>
    <script>
    (function () {
        var form = document.getElementById('onboardForm');
        if (!form) return;
        var steps = [
            'กำลังตรวจสอบชื่อเว็บ',
            'กำลังสร้างฐานข้อมูลร้าน',
            'กำลังติดตั้งระบบร้านค้า',
            'กำลังเตรียมข้อมูลตัวอย่าง',
            'เกือบเสร็จแล้ว กำลังพาเข้าสู่ร้าน'
        ];
        form.addEventListener('submit', function () {
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
            var ov = document.getElementById('provisioning');
            var fill = document.getElementById('provFill');
            var status = document.getElementById('provStatus');
            var btn = document.getElementById('submitBtn');
            if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
            ov.classList.add('show');
            ov.setAttribute('aria-hidden', 'false');
            var i = 0, pct = 8;
            status.textContent = steps[0];
            fill.style.width = pct + '%';
            var iv = setInterval(function () {
                i = Math.min(i + 1, steps.length - 1);
                pct = Math.min(pct + (pct < 70 ? 16 : 7), 93);
                status.textContent = steps[i];
                fill.style.width = pct + '%';
                if (pct >= 93) clearInterval(iv);
            }, 900);
        });
    })();
    </script>
</body>
</html>
