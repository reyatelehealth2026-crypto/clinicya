<?php
/**
 * beta.php — REYA Beta Signup public landing page + form
 *
 * URL: https://re-ya.com/beta  (Apache .htaccess strips .php)
 *
 * Single file = GET (render form) + POST (validate + insert into master DB)
 *
 * @package Marketing
 * @version 1.0.0
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token
if (empty($_SESSION['beta_csrf'])) {
    $_SESSION['beta_csrf'] = bin2hex(random_bytes(16));
}

// Tell config that we want platform DB context (no tenant routing for this page)
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$h = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// Marketing analytics — public client-side IDs (safe to expose). Configure via
// env or config constant; leave blank to disable the tag entirely.
$metaPixelId = (string)(getenv('META_PIXEL_ID') ?: (defined('META_PIXEL_ID') ? META_PIXEL_ID : ''));
$ga4Id       = (string)(getenv('GA4_MEASUREMENT_ID') ?: (defined('GA4_MEASUREMENT_ID') ? GA4_MEASUREMENT_ID : ''));

$success    = false;
$errors     = [];
$old        = []; // sticky form values on error

// ---------------------------------------------------------------------------
// POST — validate + insert
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals($_SESSION['beta_csrf'] ?? '', $token)) {
        $errors[] = 'Session หมดอายุ กรุณา reload หน้านี้แล้วลองอีกครั้ง';
    }

    $old = $_POST;

    // --- Required text fields ---
    $required = [
        'full_name'         => 'ชื่อ-นามสกุล',
        'phone'             => 'เบอร์โทรศัพท์',
        'line_id'           => 'LINE ID',
        'business_name'     => 'ชื่อร้าน/คลินิก',
        'province'          => 'จังหวัด',
    ];
    foreach ($required as $field => $label) {
        if (trim((string)($_POST[$field] ?? '')) === '') {
            $errors[] = "กรุณากรอก {$label}";
        }
    }

    // --- Validate ENUMs ---
    $enums = [
        'business_type'     => ['single_pharmacy','multi_pharmacy','clinic','medical_clinic','beauty_clinic','pharmacy_clinic','other'],
        'branch_count'      => ['1','2_3','4_5','5_plus'],
        'current_system'    => ['line_oa_only','spreadsheet','pos','crm','none','other'],
        'trial_window'      => ['immediate','7_days','15_days','30_days','need_more_info'],
        'has_line_oa'       => ['yes','no','barely_used','unsure'],
        'decision_maker'    => ['owner','pharmacist','manager','marketing','it','exec_approval'],
        'contact_time'      => ['morning','afternoon','late_afternoon','evening','line_first'],
        'preferred_package' => ['beta_trial','single_pharm','multi_pharm','clinic','need_advice'],
        'knows_beta_perk'   => ['yes_want','no_want','need_info'],
        'demo_format'       => ['video_call','clip','phone','line','unsure'],
    ];
    foreach ($enums as $field => $allowed) {
        $v = (string)($_POST[$field] ?? '');
        if (!in_array($v, $allowed, true)) {
            $errors[] = "กรุณาเลือก {$field}";
        }
    }

    // --- Checkbox arrays ---
    $painPoints = is_array($_POST['pain_points'] ?? null) ? $_POST['pain_points'] : [];
    $goals      = is_array($_POST['goals'] ?? null) ? $_POST['goals'] : [];
    if (empty($painPoints)) $errors[] = 'กรุณาเลือกอย่างน้อย 1 ปัญหา';
    if (empty($goals))      $errors[] = 'กรุณาเลือกอย่างน้อย 1 วัตถุประสงค์';

    // --- Consent (required true) ---
    if (empty($_POST['consent_contact'])) {
        $errors[] = 'กรุณายอมรับเงื่อนไขการติดต่อกลับ';
    }

    // --- Email if provided ---
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    // --- Preferred subdomain (optional, but if provided must be a valid slug) ---
    $preferredSub = strtolower(trim((string)($_POST['preferred_subdomain'] ?? '')));
    if ($preferredSub !== '') {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $preferredSub)) {
            $errors[] = 'Subdomain ต้องเป็นตัวอักษร a-z, 0-9, ขีดกลาง (2-32 ตัว)';
        }
        // Reserved subdomains — ทีมจะเสนอชื่ออื่นถ้าชน
        $reservedSub = ['www','api','admin','platform','app','shop','odoo','stg','dev',
                        'mail','cdn','assets','blog','support','help','docs','beta'];
        if (in_array($preferredSub, $reservedSub, true)) {
            $errors[] = "Subdomain '{$preferredSub}' สงวนไว้สำหรับระบบ — กรุณาเลือกชื่ออื่น";
        }
    }

    // --- Phone basic check ---
    $phone = preg_replace('/[\s\-()]/', '', (string)($_POST['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^[0-9+]{8,20}$/', $phone)) {
        $errors[] = 'รูปแบบเบอร์โทรไม่ถูกต้อง';
    }

    // --- Compute simple lead score (0-100) ---
    $score = 0;
    $score += in_array($_POST['trial_window'] ?? '', ['immediate','7_days'], true) ? 30 : 0;
    $score += ($_POST['decision_maker'] ?? '') === 'owner' ? 25 : (($_POST['decision_maker'] ?? '') === 'manager' ? 15 : 5);
    $score += ($_POST['has_line_oa'] ?? '') === 'yes' ? 15 : 5;
    $score += ($_POST['preferred_package'] ?? '') === 'beta_trial' ? 15 : 5;
    $score += ($_POST['knows_beta_perk'] ?? '') === 'yes_want' ? 15 : 5;
    $score = min(100, $score);

    if (empty($errors)) {
        try {
            $db = Database::platform()->getConnection();
            $stmt = $db->prepare(
                'INSERT INTO beta_signups
                    (full_name, phone, line_id, email,
                     business_name, business_type, business_type_other, preferred_subdomain, branch_count, province,
                     pain_points, current_system, goals,
                     trial_window, has_line_oa, decision_maker, contact_time,
                     preferred_package, knows_beta_perk,
                     additional_message, demo_format,
                     consent_contact, lead_score,
                     ip_address, user_agent, referrer,
                     utm_source, utm_medium, utm_campaign,
                     created_at)
                 VALUES (?, ?, ?, ?,
                         ?, ?, ?, ?, ?, ?,
                         ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?,
                         ?, ?,
                         1, ?,
                         ?, ?, ?,
                         ?, ?, ?,
                         NOW())'
            );
            $stmt->execute([
                trim((string)$_POST['full_name']),
                $phone,
                trim((string)$_POST['line_id']),
                $email !== '' ? $email : null,

                trim((string)$_POST['business_name']),
                (string)$_POST['business_type'],
                ($_POST['business_type'] === 'other') ? trim((string)($_POST['business_type_other'] ?? '')) : null,
                $preferredSub !== '' ? $preferredSub : null,
                (string)$_POST['branch_count'],
                trim((string)$_POST['province']),

                json_encode(array_values(array_filter($painPoints, 'is_string')), JSON_UNESCAPED_UNICODE),
                (string)$_POST['current_system'],
                json_encode(array_values(array_filter($goals, 'is_string')), JSON_UNESCAPED_UNICODE),

                (string)$_POST['trial_window'],
                (string)$_POST['has_line_oa'],
                (string)$_POST['decision_maker'],
                (string)$_POST['contact_time'],

                (string)$_POST['preferred_package'],
                (string)$_POST['knows_beta_perk'],

                trim((string)($_POST['additional_message'] ?? '')) ?: null,
                (string)$_POST['demo_format'],
                $score,

                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500) ?: null,

                substr((string)($_GET['utm_source']   ?? ''), 0, 80) ?: null,
                substr((string)($_GET['utm_medium']   ?? ''), 0, 80) ?: null,
                substr((string)($_GET['utm_campaign'] ?? ''), 0, 80) ?: null,
            ]);

            $success = true;
            // Rotate CSRF after successful submission
            $_SESSION['beta_csrf'] = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            error_log('[beta-signup] ' . $e->getMessage());
            $errors[] = 'ขออภัย ระบบบันทึกข้อมูลไม่สำเร็จ กรุณาลองอีกครั้ง หรือทัก LINE @reya';
        }
    }
}

// Sticky values
$v = function (string $field, $default = '') use ($old) {
    return isset($old[$field]) ? $old[$field] : $default;
};
$checked = function (string $field, $option) use ($old): string {
    if (!isset($old[$field])) return '';
    $val    = $old[$field];
    $option = (string)$option;
    if (is_array($val)) {
        foreach ($val as $v) {
            if ((string)$v === $option) return 'checked';
        }
        return '';
    }
    return (string)$val === $option ? 'checked' : '';
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนทดลองใช้ REYA Beta — ฟรีค่าตั้งระบบ 2,000 บาท</title>
    <meta name="description" content="REYA — ระบบช่วยร้านขายยาและคลินิกจัดการลูกค้า ปรึกษาออนไลน์ นัดหมาย วิดีโอคอล ติดตามการใช้ยา ผ่าน LINE OA. สมัคร Beta รับสิทธิ์ฟรีค่าตั้งระบบ 2,000 บาท">
    <meta property="og:title" content="REYA Beta — ฟรีค่าตั้งระบบ 2,000 บาท">
    <meta property="og:description" content="ระบบจัดการร้านขายยา/คลินิก ผ่าน LINE OA แบบมืออาชีพ">
    <meta property="og:type" content="website">

    <?php /* --- Marketing analytics (render only when configured) --- */ ?>
    <?php if ($ga4Id !== ''): ?>
    <!-- Google Analytics 4 -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $h($ga4Id) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= $h($ga4Id) ?>');
    </script>
    <?php endif; ?>
    <?php if ($metaPixelId !== ''): ?>
    <!-- Meta Pixel -->
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window,document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?= $h($metaPixelId) ?>');
        fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?= $h($metaPixelId) ?>&ev=PageView&noscript=1" alt=""/></noscript>
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Sarabun', 'Inter', sans-serif; }
        body { font-family: 'Sarabun', 'Inter', sans-serif; }
        .hero-gradient {
            background:
                radial-gradient(at 30% 20%, rgba(16, 185, 129, 0.18) 0px, transparent 50%),
                radial-gradient(at 80% 70%, rgba(5, 150, 105, 0.18) 0px, transparent 50%),
                linear-gradient(135deg, #064e3b 0%, #047857 50%, #059669 100%);
        }
        .glass {
            backdrop-filter: blur(12px);
            background-color: rgba(255, 255, 255, 0.95);
        }
        .form-card {
            box-shadow: 0 30px 60px -20px rgba(6, 78, 59, 0.15);
        }
        input[type="radio"]:checked + label,
        input[type="checkbox"]:checked + label {
            border-color: #059669;
            background-color: #ecfdf5;
            color: #065f46;
            font-weight: 600;
        }
        .check-card {
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .check-card:hover {
            border-color: #10b981;
            background-color: #f0fdf4;
        }
        /* Inner indicator (square for checkbox, dot for radio).
           Tailwind's peer-checked only targets direct siblings, so we use plain
           CSS to drill from the (sibling) input into label's descendant. */
        input[type="checkbox"]:checked + label .reya-check-box {
            background-color: #10b981;
            border-color: #10b981;
        }
        input[type="checkbox"]:checked + label .reya-check-box::after {
            content: '✓';
            color: white;
            font-size: 14px;
            font-weight: 800;
            line-height: 1;
        }
        input[type="radio"]:checked + label .reya-radio-dot {
            border-color: #10b981;
        }
        input[type="radio"]:checked + label .reya-radio-dot::after {
            content: '';
            width: 10px;
            height: 10px;
            border-radius: 9999px;
            background-color: #10b981;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .float-icon { animation: float 4s ease-in-out infinite; }
    </style>
</head>
<body class="bg-slate-50">

<?php if ($success): ?>
    <!-- =============== Thank you page =============== -->
    <div class="min-h-screen flex items-center justify-center px-4 hero-gradient">
        <div class="max-w-xl w-full glass rounded-3xl p-10 text-center form-card">
            <div class="w-20 h-20 mx-auto rounded-full bg-emerald-100 flex items-center justify-center mb-6">
                <i class="fas fa-check text-4xl text-emerald-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 mb-3">ขอบคุณที่ลงทะเบียน 🎉</h1>
            <p class="text-slate-600 mb-6 leading-relaxed">
                ทีมงาน REYA ได้รับข้อมูลของท่านเรียบร้อยแล้ว<br>
                เราจะติดต่อกลับภายใน <strong class="text-emerald-700">24 ชั่วโมง</strong>
                ทางโทรศัพท์หรือ LINE ที่ท่านให้ไว้
            </p>
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5 mb-6 text-left">
                <p class="text-sm font-semibold text-emerald-800 mb-2">
                    <i class="fas fa-gift mr-2"></i>สิทธิ์ที่ท่านได้รับ
                </p>
                <ul class="text-sm text-emerald-700 space-y-1.5 ml-1">
                    <li><i class="fas fa-check-circle text-emerald-600 mr-2"></i>ฟรีค่าตั้งระบบ มูลค่า 2,000 บาท</li>
                    <li><i class="fas fa-check-circle text-emerald-600 mr-2"></i>นัดหมาย Demo ระบบกับทีมงาน</li>
                    <li><i class="fas fa-check-circle text-emerald-600 mr-2"></i>ทดลองใช้ครบทุก feature ของ REYA</li>
                </ul>
            </div>
            <div class="flex gap-3 justify-center">
                <a href="https://line.me/R/ti/p/@reya" target="_blank"
                   class="inline-flex items-center gap-2 bg-[#06C755] hover:bg-[#05a847] text-white font-semibold px-6 py-3 rounded-xl transition">
                    <i class="fab fa-line text-xl"></i> ทัก LINE @reya
                </a>
                <a href="/" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-6 py-3 rounded-xl transition">
                    กลับหน้าหลัก
                </a>
            </div>
        </div>
    </div>

    <?php /* --- Conversion events (fire once on successful submit) --- */ ?>
    <?php if ($metaPixelId !== ''): ?>
    <script>fbq('track', 'Lead', {value: 2000, currency: 'THB', lead_score: <?= (int)($score ?? 0) ?>});</script>
    <?php endif; ?>
    <?php if ($ga4Id !== ''): ?>
    <script>gtag('event', 'sign_up', {method: 'beta_form', value: 2000, currency: 'THB', lead_score: <?= (int)($score ?? 0) ?>});</script>
    <?php endif; ?>

<?php else: ?>
    <!-- =============== Hero =============== -->
    <section class="hero-gradient text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <i class="fas fa-prescription-bottle-alt text-9xl absolute top-12 right-8 float-icon"></i>
            <i class="fas fa-comments text-7xl absolute bottom-20 left-12 float-icon" style="animation-delay:1s"></i>
            <i class="fas fa-video text-6xl absolute top-1/2 right-1/3 float-icon" style="animation-delay:2s"></i>
        </div>
        <div class="max-w-5xl mx-auto px-6 pt-14 pb-20 relative">
            <div class="inline-flex items-center gap-2 bg-white/10 border border-white/20 rounded-full px-4 py-1.5 mb-6 text-sm">
                <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse"></span>
                เปิดรับสมัครรอบ Beta — จำนวนจำกัด
            </div>
            <h1 class="text-4xl md:text-6xl font-extrabold mb-6 leading-tight">
                ลงทะเบียนทดลองใช้<br>
                <span class="bg-gradient-to-r from-emerald-200 to-white bg-clip-text text-transparent">REYA รุ่น Beta</span>
            </h1>
            <p class="text-xl md:text-2xl text-emerald-50/90 mb-3 max-w-2xl">
                ระบบช่วยร้านขายยาและคลินิกบริหารลูกค้า ปรึกษา นัดหมาย ติดตามการใช้ยา ผ่าน LINE OA
            </p>
            <div class="flex flex-wrap gap-3 mt-8">
                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-2.5 text-sm">
                    <i class="fas fa-gift text-amber-300 mr-2"></i>
                    <span class="font-semibold">ฟรีค่าตั้งระบบ</span> มูลค่า <strong>2,000 บาท</strong>
                </div>
                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-2.5 text-sm">
                    <i class="fas fa-bolt text-yellow-300 mr-2"></i> ทีมงานติดต่อกลับใน 24 ชม.
                </div>
                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-2.5 text-sm">
                    <i class="fas fa-shield-alt text-emerald-300 mr-2"></i> ปลอดภัย รักษาข้อมูลตาม PDPA
                </div>
            </div>
        </div>
    </section>

    <!-- =============== Value props =============== -->
    <section class="max-w-5xl mx-auto px-6 -mt-10 relative z-10">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ([
                ['fas fa-users',          'จัดการลูกค้า', 'บริหารฐานลูกค้าเป็นระบบ'],
                ['fas fa-comments',       'ปรึกษาเภสัช',   'รับคำปรึกษา 1:1 ผ่าน LINE'],
                ['fas fa-calendar-check', 'นัดหมาย',        'จองคิวหมอ/เภสัช online'],
                ['fas fa-pills',          'ติดตามยา',      'แจ้งเตือนการใช้ยาแม่นยำ'],
            ] as $item): ?>
                <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
                        <i class="<?= $h($item[0]) ?>"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900 text-sm mb-1"><?= $h($item[1]) ?></h3>
                    <p class="text-xs text-slate-500"><?= $h($item[2]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- =============== Description =============== -->
    <section class="max-w-3xl mx-auto px-6 pt-16">
        <div class="bg-emerald-50/60 border border-emerald-100 rounded-2xl p-6">
            <h2 class="font-bold text-emerald-900 mb-2 text-lg">เกี่ยวกับ REYA</h2>
            <p class="text-slate-700 leading-relaxed text-sm">
                REYA คือระบบช่วยร้านขายยา/คลินิก จัดการลูกค้า การให้คำปรึกษา นัดหมาย วิดีโอคอล
                ติดตามการใช้ยา และสร้างระบบบริการผ่าน LINE OA ให้ดูเป็นมืออาชีพมากขึ้น
                <br><br>
                <strong class="text-emerald-700">สำหรับลูกค้าที่ลงทะเบียนในช่วง Beta</strong> รับสิทธิ์พิเศษ
                <strong>ฟรีค่าตั้งระบบ มูลค่า 2,000 บาท</strong>
                ทีมงานจะติดต่อกลับเพื่อแนะนำระบบ ประเมินความเหมาะสม และนัดหมายทดลองใช้งาน
            </p>
        </div>
    </section>

    <!-- =============== Form =============== -->
    <main class="max-w-3xl mx-auto px-6 py-12">
        <?php if ($errors): ?>
            <div class="bg-red-50 border border-red-200 rounded-2xl p-5 mb-6">
                <p class="font-semibold text-red-800 mb-2">
                    <i class="fas fa-exclamation-circle mr-2"></i>กรุณาตรวจสอบข้อมูล:
                </p>
                <ul class="text-sm text-red-700 ml-5 list-disc space-y-1">
                    <?php foreach ($errors as $err): ?>
                        <li><?= $h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate class="space-y-8">
            <input type="hidden" name="_csrf" value="<?= $h($_SESSION['beta_csrf']) ?>">

            <!-- Section 1 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">1</span>
                    <h2 class="text-xl font-bold text-slate-900">ข้อมูลผู้ลงทะเบียน</h2>
                </div>
                <div class="grid md:grid-cols-2 gap-5">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">ชื่อ-นามสกุล <span class="text-red-500">*</span></span>
                        <input type="text" name="full_name" required maxlength="120" value="<?= $h($v('full_name')) ?>"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">เบอร์โทรศัพท์ <span class="text-red-500">*</span></span>
                        <input type="tel" name="phone" required maxlength="20" value="<?= $h($v('phone')) ?>"
                               placeholder="0812345678"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">LINE ID <span class="text-red-500">*</span></span>
                        <input type="text" name="line_id" required maxlength="80" value="<?= $h($v('line_id')) ?>"
                               placeholder="@yourshop หรือ line ID"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">อีเมล <span class="text-slate-400 text-xs">(ไม่บังคับ)</span></span>
                        <input type="email" name="email" maxlength="120" value="<?= $h($v('email')) ?>"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>
                </div>
            </section>

            <!-- Section 2 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">2</span>
                    <h2 class="text-xl font-bold text-slate-900">ข้อมูลร้าน / องค์กร</h2>
                </div>
                <div class="space-y-5">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">ชื่อร้านขายยา / คลินิก / องค์กร <span class="text-red-500">*</span></span>
                        <input type="text" name="business_name" required maxlength="200" value="<?= $h($v('business_name')) ?>"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ประเภทธุรกิจของท่าน <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <?php foreach ([
                                'single_pharmacy'  => 'ร้านขายยาเดี่ยว',
                                'multi_pharmacy'   => 'ร้านขายยาหลายสาขา',
                                'clinic'           => 'คลินิก',
                                'medical_clinic'   => 'คลินิกเวชกรรม',
                                'beauty_clinic'    => 'คลินิกความงาม',
                                'pharmacy_clinic'  => 'ร้านยา + คลินิก',
                                'other'            => 'อื่น ๆ',
                            ] as $key => $label): ?>
                                <input type="radio" id="bt_<?= $key ?>" name="business_type" value="<?= $key ?>"
                                       <?= $checked('business_type', $key) ?> required class="hidden peer">
                                <label for="bt_<?= $key ?>" class="check-card flex items-center justify-center text-sm
                                       border-2 border-slate-200 rounded-xl px-3 py-2.5 text-slate-700 text-center">
                                    <?= $h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" name="business_type_other" maxlength="120" value="<?= $h($v('business_type_other')) ?>"
                               placeholder="ถ้าเลือก อื่น ๆ ให้ระบุ"
                               class="mt-2 w-full border border-slate-200 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">จำนวนสาขา <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-4 gap-2">
                            <?php foreach ([
                                '1'      => '1 สาขา',
                                '2_3'    => '2–3 สาขา',
                                '4_5'    => '4–5 สาขา',
                                '5_plus' => 'มากกว่า 5',
                            ] as $key => $label): ?>
                                <input type="radio" id="br_<?= $key ?>" name="branch_count" value="<?= $key ?>"
                                       <?= $checked('branch_count', $key) ?> required class="hidden peer">
                                <label for="br_<?= $key ?>" class="check-card flex items-center justify-center text-sm
                                       border-2 border-slate-200 rounded-xl px-3 py-2.5 text-slate-700 text-center">
                                    <?= $h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">จังหวัดที่ตั้งร้าน/คลินิก <span class="text-red-500">*</span></span>
                        <input type="text" name="province" required maxlength="80" value="<?= $h($v('province')) ?>"
                               placeholder="กรุงเทพมหานคร / นนทบุรี / ฯลฯ"
                               class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>

                    <!-- Preferred subdomain (optional) -->
                    <div class="bg-emerald-50/60 border-2 border-emerald-100 rounded-2xl p-4">
                        <label class="block">
                            <span class="text-sm font-semibold text-emerald-900 flex items-center gap-1.5">
                                <i class="fas fa-link text-emerald-600"></i>
                                ชื่อ URL ของร้านที่ต้องการ <span class="text-slate-400 text-xs font-normal">(ไม่บังคับ — ถ้าไม่ระบุทีมงานจะแนะนำให้)</span>
                            </span>
                            <div class="mt-2 flex items-stretch rounded-xl overflow-hidden border-2 border-emerald-200 bg-white focus-within:border-emerald-500 transition">
                                <span class="px-3 flex items-center bg-slate-50 text-slate-400 text-sm border-r border-emerald-200">https://</span>
                                <input type="text" name="preferred_subdomain" id="prefSub"
                                       maxlength="32" pattern="[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?"
                                       value="<?= $h($v('preferred_subdomain')) ?>"
                                       placeholder="smilepharm"
                                       oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, ''); document.getElementById('subPreview').textContent = this.value || 'your-shop';"
                                       class="flex-1 px-3 py-2.5 text-sm focus:outline-none font-mono">
                                <span class="px-3 flex items-center bg-slate-50 text-slate-700 text-sm border-l border-emerald-200 font-medium">.re-ya.com</span>
                            </div>
                            <p class="text-xs text-emerald-800 mt-2">
                                <i class="fas fa-eye mr-1"></i>
                                ตัวอย่าง URL: <code class="bg-white border border-emerald-200 px-2 py-0.5 rounded text-emerald-700">https://<span id="subPreview"><?= $h($v('preferred_subdomain', 'your-shop')) ?></span>.re-ya.com/auth/login.php</code>
                            </p>
                            <p class="text-xs text-slate-500 mt-1.5">
                                ใช้ตัวอักษร a-z, ตัวเลข 0-9 และขีดกลาง — เช่น <code>smilepharm</code>, <code>khaopharm-bkk</code>.
                                ทีมงานจะตรวจให้ก่อนสร้างให้ ถ้าชื่อนี้มีคนใช้แล้วจะแจ้งกลับ
                            </p>
                        </label>
                    </div>
                </div>
            </section>

            <!-- Section 3 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">3</span>
                    <h2 class="text-xl font-bold text-slate-900">ปัญหาที่ต้องการให้ REYA ช่วยแก้</h2>
                </div>
                <div class="space-y-5">
                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">
                            ตอนนี้ร้านของท่านเจอปัญหาอะไรบ้าง <span class="text-red-500">*</span>
                            <span class="text-xs text-slate-400">(เลือกได้มากกว่า 1 ข้อ)</span>
                        </span>
                        <div class="grid md:grid-cols-2 gap-2">
                            <?php foreach ([
                                'response_slow'   => 'ลูกค้าทัก LINE แล้วตอบไม่ทัน',
                                'data_scattered'  => 'ข้อมูลลูกค้ากระจัดกระจาย',
                                'no_followup'     => 'ไม่มีระบบติดตามลูกค้าหลังขาย',
                                'no_appointment'  => 'นัดหมายปรึกษาเภสัช/แพทย์ไม่เป็นระบบ',
                                'pro_line_oa'     => 'ต้องการให้บริการผ่าน LINE OA แบบมืออาชีพ',
                                'video_call'      => 'ต้องการระบบวิดีโอคอลปรึกษา',
                                'consultation_log'=> 'ต้องการเก็บประวัติการให้คำปรึกษา',
                                'repeat_sales'    => 'ต้องการเพิ่มยอดขายจากลูกค้าเก่า',
                                'customer_groups' => 'ต้องการแยกลูกค้าตามกลุ่ม (โรคประจำตัว/ยา/บริการ)',
                                'pain_other'      => 'อื่น ๆ',
                            ] as $key => $label): ?>
                                <input type="checkbox" id="pp_<?= $key ?>" name="pain_points[]" value="<?= $key ?>"
                                       <?= $checked('pain_points', $key) ?> class="hidden peer">
                                <label for="pp_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 flex items-center gap-2">
                                    <span class="reya-check-box w-5 h-5 rounded border-2 border-slate-300 flex-shrink-0 flex items-center justify-center"></span>
                                    <span><?= $h($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ปัจจุบันใช้ระบบอะไรอยู่ <span class="text-red-500">*</span></span>
                        <div class="grid md:grid-cols-3 gap-2">
                            <?php foreach ([
                                'line_oa_only' => 'ใช้ LINE OA อย่างเดียว',
                                'spreadsheet'  => 'Excel / Google Sheet',
                                'pos'          => 'โปรแกรมขายหน้าร้าน',
                                'crm'          => 'CRM อยู่แล้ว',
                                'none'         => 'ยังไม่มีระบบ',
                                'other'        => 'อื่น ๆ',
                            ] as $key => $label): ?>
                                <input type="radio" id="cs_<?= $key ?>" name="current_system" value="<?= $key ?>"
                                       <?= $checked('current_system', $key) ?> required class="hidden peer">
                                <label for="cs_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 text-center">
                                    <?= $h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">
                            ต้องการใช้ REYA เพื่อวัตถุประสงค์หลักอะไร <span class="text-red-500">*</span>
                            <span class="text-xs text-slate-400">(เลือกได้มากกว่า 1)</span>
                        </span>
                        <div class="grid md:grid-cols-2 gap-2">
                            <?php foreach ([
                                'manage_line_oa'      => 'จัดการลูกค้าใน LINE OA',
                                'online_consult'      => 'ให้คำปรึกษาเภสัชกรรมออนไลน์',
                                'appointment'         => 'นัดหมายลูกค้า',
                                'video_call'          => 'วิดีโอคอลกับลูกค้า',
                                'med_followup'        => 'ติดตามการใช้ยา/บริการหลังขาย',
                                'customer_history'    => 'เก็บประวัติลูกค้า',
                                'sell_online_shop'    => '🛒 ขายสินค้าออนไลน์ผ่านหน้าร้าน LINE Mini App',
                                'order_payment_mgmt'  => '💳 จัดการคำสั่งซื้อ + รับชำระเงิน (โอน/COD/QR)',
                                'inventory_stock'     => '📦 จัดการสต็อกสินค้า + ขายหลายหน่วย',
                                'loyalty_program'     => '⭐ ระบบสมาชิก + แต้มสะสม',
                                'product_broadcast'   => '📣 บรอดแคสต์โปรโมชั่นสินค้า',
                                'repeat_campaign'     => 'ทำแคมเปญกลับมาซื้อซ้ำ',
                                'credibility'         => 'เพิ่มความน่าเชื่อถือให้ร้าน/คลินิก',
                                'goals_other'         => 'อื่น ๆ',
                            ] as $key => $label): ?>
                                <input type="checkbox" id="g_<?= $key ?>" name="goals[]" value="<?= $key ?>"
                                       <?= $checked('goals', $key) ?> class="hidden peer">
                                <label for="g_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 flex items-center gap-2">
                                    <span class="reya-check-box w-5 h-5 rounded border-2 border-slate-300 flex-shrink-0 flex items-center justify-center"></span>
                                    <span><?= $h($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 4 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">4</span>
                    <h2 class="text-xl font-bold text-slate-900">ความพร้อมในการทดลองใช้ Beta</h2>
                </div>
                <div class="space-y-5">
                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ท่านพร้อมทดลองใช้ระบบภายในกี่วัน <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                            <?php foreach ([
                                'immediate'      => 'พร้อมทันที',
                                '7_days'         => 'ภายใน 7 วัน',
                                '15_days'        => 'ภายใน 15 วัน',
                                '30_days'        => 'ภายใน 30 วัน',
                                'need_more_info' => 'ขอศึกษาก่อน',
                            ] as $key => $label): ?>
                                <input type="radio" id="tw_<?= $key ?>" name="trial_window" value="<?= $key ?>"
                                       <?= $checked('trial_window', $key) ?> required class="hidden peer">
                                <label for="tw_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-2 py-2.5 text-slate-700 text-center"><?= $h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ร้าน/องค์กรของท่านมี LINE OA แล้วหรือยัง <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <?php foreach ([
                                'yes'         => 'มีแล้ว',
                                'no'          => 'ยังไม่มี',
                                'barely_used' => 'มีแต่ไม่ค่อยใช้',
                                'unsure'      => 'ไม่แน่ใจ',
                            ] as $key => $label): ?>
                                <input type="radio" id="lo_<?= $key ?>" name="has_line_oa" value="<?= $key ?>"
                                       <?= $checked('has_line_oa', $key) ?> required class="hidden peer">
                                <label for="lo_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 text-center"><?= $h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ใครเป็นผู้ตัดสินใจใช้ระบบ <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <?php foreach ([
                                'owner'          => 'เจ้าของร้าน/กิจการ',
                                'pharmacist'     => 'เภสัชกรประจำร้าน',
                                'manager'        => 'ผู้จัดการร้าน',
                                'marketing'      => 'ฝ่ายการตลาด',
                                'it'             => 'ฝ่าย IT',
                                'exec_approval'  => 'ต้องเสนอผู้บริหาร',
                            ] as $key => $label): ?>
                                <input type="radio" id="dm_<?= $key ?>" name="decision_maker" value="<?= $key ?>"
                                       <?= $checked('decision_maker', $key) ?> required class="hidden peer">
                                <label for="dm_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 text-center"><?= $h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ช่วงเวลาที่สะดวกให้ทีมงานติดต่อกลับ <span class="text-red-500">*</span></span>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                            <?php foreach ([
                                'morning'         => '09–12 น.',
                                'afternoon'       => '13–15 น.',
                                'late_afternoon'  => '15–18 น.',
                                'evening'         => 'หลัง 18 น.',
                                'line_first'      => 'ทาง LINE ก่อน',
                            ] as $key => $label): ?>
                                <input type="radio" id="ct_<?= $key ?>" name="contact_time" value="<?= $key ?>"
                                       <?= $checked('contact_time', $key) ?> required class="hidden peer">
                                <label for="ct_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-2 py-2.5 text-slate-700 text-center"><?= $h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 5 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">5</span>
                    <h2 class="text-xl font-bold text-slate-900">แพ็กเกจและสิทธิ์ Beta</h2>
                </div>
                <div class="space-y-5">
                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ท่านสนใจแพ็กเกจแบบใด <span class="text-red-500">*</span></span>
                        <div class="space-y-2">
                            <?php foreach ([
                                'beta_trial'   => 'ทดลองใช้ Beta ก่อน',
                                'single_pharm' => 'แพ็กเกจร้านยาเดี่ยว',
                                'multi_pharm'  => 'แพ็กเกจร้านยาหลายสาขา',
                                'clinic'       => 'แพ็กเกจคลินิก',
                                'need_advice'  => 'ยังไม่แน่ใจ ขอให้ทีมงานแนะนำ',
                            ] as $key => $label): ?>
                                <input type="radio" id="pkg_<?= $key ?>" name="preferred_package" value="<?= $key ?>"
                                       <?= $checked('preferred_package', $key) ?> required class="hidden peer">
                                <label for="pkg_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-4 py-3 text-slate-700 flex items-center gap-3 block">
                                    <span class="reya-radio-dot w-5 h-5 rounded-full border-2 border-slate-300 flex-shrink-0 flex items-center justify-center"></span>
                                    <span><?= $h($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-amber-50 border-2 border-amber-200 rounded-2xl p-5">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-gift text-amber-500 text-2xl mt-1"></i>
                            <div class="flex-1">
                                <p class="font-semibold text-amber-900 text-sm mb-2">
                                    ท่านทราบหรือไม่ว่า Beta นี้ได้รับสิทธิ์ฟรีค่าตั้งระบบ มูลค่า 2,000 บาท <span class="text-red-500">*</span>
                                </p>
                                <div class="space-y-1.5">
                                    <?php foreach ([
                                        'yes_want'  => 'ทราบ และต้องการรับสิทธิ์',
                                        'no_want'   => 'ยังไม่ทราบ แต่สนใจรับสิทธิ์',
                                        'need_info' => 'ขอรายละเอียดเพิ่มเติมก่อน',
                                    ] as $key => $label): ?>
                                        <label class="flex items-center gap-2 cursor-pointer text-sm text-amber-900">
                                            <input type="radio" name="knows_beta_perk" value="<?= $key ?>"
                                                   <?= $checked('knows_beta_perk', $key) ?> required
                                                   class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                                            <?= $h($label) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 6 -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">6</span>
                    <h2 class="text-xl font-bold text-slate-900">ข้อความเพิ่มเติม</h2>
                </div>
                <div class="space-y-5">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            อยากให้ REYA ช่วยแก้ปัญหาอะไรให้ร้าน/คลินิกของท่านมากที่สุด
                            <span class="text-slate-400 text-xs">(ไม่บังคับ)</span>
                        </span>
                        <textarea name="additional_message" rows="4" maxlength="1000"
                                  placeholder="เล่ารายละเอียดปัญหาที่ท่านอยากแก้ — ยิ่งละเอียด เราจะยิ่งช่วยแก้ได้ตรงจุด"
                                  class="mt-1 w-full border border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= $h($v('additional_message')) ?></textarea>
                    </label>

                    <div>
                        <span class="text-sm font-medium text-slate-700 block mb-2">ต้องการให้ทีมงานสาธิตระบบในรูปแบบใด <span class="text-red-500">*</span></span>
                        <div class="grid md:grid-cols-2 gap-2">
                            <?php foreach ([
                                'video_call' => 'นัด Demo ผ่านวิดีโอคอล',
                                'clip'       => 'ส่งคลิปแนะนำระบบให้ดูก่อน',
                                'phone'      => 'ให้ทีมงานโทรอธิบาย',
                                'line'       => 'ทัก LINE เพื่อสอบถามก่อน',
                                'unsure'     => 'ยังไม่แน่ใจ',
                            ] as $key => $label): ?>
                                <input type="radio" id="df_<?= $key ?>" name="demo_format" value="<?= $key ?>"
                                       <?= $checked('demo_format', $key) ?> required class="hidden peer">
                                <label for="df_<?= $key ?>" class="check-card text-sm border-2 border-slate-200
                                       rounded-xl px-3 py-2.5 text-slate-700 text-center"><?= $h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 7 — Consent -->
            <section class="bg-white rounded-3xl border border-slate-200 p-8 form-card">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-600 text-white font-bold">7</span>
                    <h2 class="text-xl font-bold text-slate-900">การยินยอมให้ติดต่อกลับ</h2>
                </div>
                <label class="flex items-start gap-3 cursor-pointer p-4 border-2 border-slate-200 rounded-2xl hover:bg-slate-50">
                    <input type="checkbox" name="consent_contact" value="1" required
                           <?= $v('consent_contact') === '1' ? 'checked' : '' ?>
                           class="w-5 h-5 mt-0.5 text-emerald-600 rounded focus:ring-emerald-500">
                    <span class="text-sm text-slate-700 leading-relaxed">
                        ข้าพเจ้ายินยอมให้ทีมงาน REYA ติดต่อกลับผ่านโทรศัพท์ LINE
                        หรือช่องทางที่ให้ไว้ เพื่อแนะนำระบบและแจ้งสิทธิ์ทดลองใช้ Beta
                        ตามนโยบายความเป็นส่วนตัวของบริษัท
                    </span>
                </label>
            </section>

            <!-- Submit -->
            <div class="text-center pt-4">
                <button type="submit"
                        class="bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800
                               text-white font-bold px-12 py-4 rounded-2xl text-lg shadow-xl hover:shadow-2xl
                               transition-all transform hover:-translate-y-0.5 inline-flex items-center gap-3">
                    <i class="fas fa-paper-plane"></i>
                    ลงทะเบียนรับสิทธิ์ Beta
                </button>
                <p class="mt-4 text-xs text-slate-500">
                    <i class="fas fa-lock text-slate-400 mr-1"></i>
                    ข้อมูลของท่านจะถูกใช้เพื่อติดต่อกลับเท่านั้น — ไม่เปิดเผยให้บุคคลที่สาม
                </p>
            </div>
        </form>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-12">
        <div class="max-w-5xl mx-auto px-6 text-center">
            <div class="text-2xl font-bold text-white mb-2">REYA</div>
            <p class="text-sm mb-4">ระบบจัดการร้านขายยา/คลินิก ผ่าน LINE OA</p>
            <div class="flex justify-center gap-4 text-xs">
                <a href="https://line.me/R/ti/p/@reya" class="hover:text-emerald-400">LINE @reya</a>
                <span>·</span>
                <a href="mailto:hello@re-ya.com" class="hover:text-emerald-400">hello@re-ya.com</a>
                <span>·</span>
                <a href="https://re-ya.com/" class="hover:text-emerald-400">re-ya.com</a>
            </div>
            <p class="text-xs mt-6 opacity-60">© <?= date('Y') ?> REYA Platform. All rights reserved.</p>
        </div>
    </footer>
<?php endif; ?>

</body>
</html>
