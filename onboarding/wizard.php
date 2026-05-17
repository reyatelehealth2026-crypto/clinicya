<?php
/**
 * Setup Wizard — 7-step first-time admin onboarding
 *
 * Routing: /onboarding/wizard.php?step=1..7
 *   ?step missing  → resume from admin_users.onboarding_step + 1
 *   ?step>7         → clamp to 7
 *
 * Saves POST to /onboarding/api.php (action=save-step | skip-all).
 * Step bodies live in /onboarding/steps/0X-*.php (included below).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php'; // populates $currentUser

$db = Database::getInstance()->getConnection();

// ---------------------------------------------------------------------------
// Resolve current onboarding state for this admin
// ---------------------------------------------------------------------------
$adminId = (int)($currentUser['id'] ?? 0);
if ($adminId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT onboarding_completed, onboarding_step, onboarding_skipped
       FROM admin_users WHERE id = :id LIMIT 1'
);
$stmt->execute([':id' => $adminId]);
$state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'onboarding_completed' => 0,
    'onboarding_step'      => 0,
    'onboarding_skipped'   => 0,
];

$forceRetake = isset($_GET['retake']);
if (!$forceRetake && ((int)$state['onboarding_completed'] === 1 || (int)$state['onboarding_skipped'] === 1)) {
    header('Location: /index.php');
    exit;
}

$savedStep = (int)$state['onboarding_step'];
$requestedStep = isset($_GET['step']) ? (int)$_GET['step'] : ($savedStep + 1);
if ($requestedStep < 1) { $requestedStep = 1; }
if ($requestedStep > 7) { $requestedStep = 7; }
$step = $requestedStep;

$lineAccountId = (int)($currentUser['line_account_id'] ?? 0);

// ---------------------------------------------------------------------------
// Prefill: pull existing rows so user can resume / edit
// ---------------------------------------------------------------------------
$prefill = [
    'shop' => [], 'line' => [], 'liff' => [],
    'payment' => [], 'pharmacist' => [], 'ai' => [],
];
try {
    if ($lineAccountId > 0) {
        $s = $db->prepare('SELECT * FROM shop_settings WHERE line_account_id = :id LIMIT 1');
        $s->execute([':id' => $lineAccountId]);
        $prefill['shop'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $prefill['payment'] = $prefill['shop'];

        $s = $db->prepare('SELECT * FROM line_accounts WHERE id = :id LIMIT 1');
        $s->execute([':id' => $lineAccountId]);
        $prefill['line'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        $s = $db->prepare('SELECT * FROM liff_apps WHERE line_account_id = :id ORDER BY id ASC LIMIT 1');
        $s->execute([':id' => $lineAccountId]);
        $prefill['liff'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        $s = $db->prepare('SELECT * FROM pharmacists WHERE line_account_id = :id ORDER BY id ASC LIMIT 1');
        $s->execute([':id' => $lineAccountId]);
        $prefill['pharmacist'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        $s = $db->prepare('SELECT * FROM ai_settings WHERE line_account_id = :id LIMIT 1');
        $s->execute([':id' => $lineAccountId]);
        $prefill['ai'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    // tables may not exist yet — wizard will create rows on save
}

// Build LIFF & webhook URLs to show user
$baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : (
    (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
);
$webhookUrl   = $baseUrl . '/webhook.php?account=' . ($lineAccountId ?: '{ACCOUNT_ID}');
$liffEndpoint = $baseUrl . '/line-mini-app/';

if (empty($_SESSION['onboarding_csrf'])) {
    $_SESSION['onboarding_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['onboarding_csrf'];

$stepTitles = [
    1 => ['ข้อมูลร้าน', 'Shop Profile'],
    2 => ['เชื่อม LINE OA', 'Connect LINE OA'],
    3 => ['ตั้งค่า LIFF', 'LIFF App'],
    4 => ['ระบบชำระเงิน', 'Payment'],
    5 => ['เภสัชกรคนแรก', 'First Pharmacist'],
    6 => ['ตั้งค่า AI', 'AI Settings'],
    7 => ['เสร็จสมบูรณ์', 'All Done'],
];
$currentTitle = $stepTitles[$step] ?? ['', ''];
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Setup Wizard — Clinicya</title>
<link rel="stylesheet" href="/onboarding/wizard.css">
</head>
<body class="wz-body">
<div class="wz-shell">
  <header class="wz-header">
    <div class="wz-brand">
      <img src="/assets/images/logo.png" alt="Clinicya" onerror="this.style.display='none'">
      <span>Clinicya Setup</span>
    </div>
    <div class="wz-header-actions">
      <span class="wz-step-pill">ขั้น <?= $step ?> / 7</span>
      <button type="button" class="wz-skip-all" data-action="skip-all"
              title="ข้ามทั้งหมดและไปหน้าหลัก">ข้ามทั้งหมด</button>
    </div>
  </header>

  <div class="wz-progress" aria-label="progress">
    <?php for ($i = 1; $i <= 7; $i++): ?>
      <div class="wz-dot <?= $i < $step ? 'done' : ($i === $step ? 'active' : '') ?>"
           title="<?= htmlspecialchars($stepTitles[$i][0]) ?>"><?= $i ?></div>
    <?php endfor; ?>
    <div class="wz-progress-bar"><span style="width: <?= ($step / 7) * 100 ?>%"></span></div>
  </div>

  <main class="wz-card">
    <h1 class="wz-title">
      <?= htmlspecialchars($currentTitle[0]) ?>
      <small><?= htmlspecialchars($currentTitle[1]) ?></small>
    </h1>

    <form id="wz-form" method="post" action="/onboarding/api.php"
          enctype="multipart/form-data" data-step="<?= $step ?>"
          autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="step" value="<?= $step ?>">
      <input type="hidden" name="action" value="save-step">

      <?php
      $candidates = glob(__DIR__ . sprintf('/steps/%02d-*.php', $step)) ?: [];
      if (!empty($candidates) && file_exists($candidates[0])) {
          include $candidates[0];
      } else {
          echo '<p class="wz-error">ไม่พบไฟล์ขั้นที่ ' . (int)$step . '</p>';
      }
      ?>

      <div id="wz-msg" class="wz-msg" role="status" aria-live="polite"></div>

      <div class="wz-nav">
        <?php if ($step > 1): ?>
          <a class="wz-btn wz-btn-ghost" href="?step=<?= $step - 1 ?>">← ย้อนกลับ</a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <button type="submit" class="wz-btn wz-btn-primary">
          <?php if ($step < 7): ?>บันทึก &amp; ถัดไป →<?php else: ?>เริ่มใช้งาน Clinicya<?php endif; ?>
        </button>
      </div>
    </form>
  </main>

  <footer class="wz-footer">© Clinicya — ตั้งค่าครั้งแรกเพื่อใช้งานระบบ</footer>
</div>

<script>
(function () {
  const form = document.getElementById('wz-form');
  const msg  = document.getElementById('wz-msg');
  const step = parseInt(form.dataset.step, 10);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    msg.textContent = ''; msg.className = 'wz-msg';

    let firstInvalid = null;
    form.querySelectorAll('[required]').forEach((el) => {
      const v = (el.value || '').trim();
      const wrap = el.closest('.wz-field') || el.parentElement;
      if (!v) { wrap?.classList.add('wz-invalid'); if (!firstInvalid) firstInvalid = el; }
      else    { wrap?.classList.remove('wz-invalid'); }
    });
    if (firstInvalid) {
      msg.textContent = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ';
      msg.classList.add('err'); firstInvalid.focus(); return;
    }

    const fd = new FormData(form);
    msg.textContent = 'กำลังบันทึก…';
    try {
      const res = await fetch(form.action, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'บันทึกไม่สำเร็จ');
      window.location.href = (step >= 7)
        ? (data.redirect || '/index.php')
        : ('/onboarding/wizard.php?step=' + (step + 1));
    } catch (err) {
      msg.textContent = err.message; msg.classList.add('err');
    }
  });

  document.querySelector('[data-action="skip-all"]').addEventListener('click', async () => {
    if (!confirm('ยืนยันข้ามการตั้งค่าทั้งหมด? สามารถกลับมาทำได้ภายหลัง')) return;
    const fd = new FormData();
    fd.append('action', 'skip-all');
    fd.append('csrf', <?= json_encode($csrf) ?>);
    const res = await fetch('/onboarding/api.php', {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    const data = await res.json();
    window.location.href = data.redirect || '/index.php';
  });

  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = document.querySelector(btn.dataset.copy);
      if (!target) return;
      const text = target.value || target.textContent || '';
      navigator.clipboard.writeText(text.trim()).then(() => {
        const orig = btn.textContent;
        btn.textContent = 'คัดลอกแล้ว ✓';
        setTimeout(() => { btn.textContent = orig; }, 1500);
      });
    });
  });
})();
</script>
</body>
</html>
