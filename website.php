<?php
/**
 * เว็บไซต์ร้าน (Website Hub) — ศูนย์รวมการจัดการหน้าเว็บสาธารณะของร้านในหน้าเดียว
 *
 * - Checklist ความครบของข้อมูล (โลโก้ ติดต่อ เวลาทำการ รูปร้าน สินค้า LINE เผยแพร่)
 * - ข้อมูลร้าน (ชื่อ/โลโก้/คำอธิบาย/ที่อยู่/โทร) — เขียนลง shop_settings ตัวเดียวกับ settings.php
 * - เวลาทำการ — เขียนลง landing_settings.operating_hours ตัวเดียวกับแท็บ SEO เดิม
 * - ธีม/hero/รูปหน้าร้าน/เผยแพร่ ของเว็บโฉมใหม่ (ย้ายมาจากแท็บชั่วคราวใน landing-settings)
 * - "ตั้งค่าแบบมีไกด์" (website-wizard.js) — พาไล่ทีละขั้นบนฟอร์มเดิมของหน้านี้ ไม่มี handler แยก
 *
 * เนื้อหาเชิงลึก (แบนเนอร์ สินค้าแนะนำ FAQ บทความ) ยังแก้ที่ /admin/landing-settings ตามเดิม
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';
require_once 'classes/LandingV2Config.php';
require_once 'classes/TenantFileStorage.php';
require_once 'classes/FeaturedProductService.php';
require_once 'includes/liff-helper.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

$landingV2 = new LandingV2Config($db, $currentBotId);
$featuredService = new FeaturedProductService($db, $currentBotId);

$success = null;
$error = null;

/** upsert ค่าใน landing_settings ที่ scope ของ OA ปัจจุบัน (รองรับกรณียังไม่มี OA = NULL) */
function websiteHubPutLandingSetting(PDO $db, ?int $botId, string $key, string $value): void
{
    if ($botId !== null) {
        $stmt = $db->prepare(
            "INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$botId, $key, $value]);
        return;
    }
    // NULL scope: unique key ของ MySQL ไม่กันแถวซ้ำ ต้อง select-then-update
    $sel = $db->prepare(
        "SELECT id FROM landing_settings WHERE setting_key = ? AND line_account_id IS NULL ORDER BY id DESC LIMIT 1"
    );
    $sel->execute([$key]);
    $existingId = $sel->fetchColumn();
    if ($existingId !== false) {
        $db->prepare("UPDATE landing_settings SET setting_value = ? WHERE id = ?")
           ->execute([$value, (int) $existingId]);
        return;
    }
    $db->prepare("INSERT INTO landing_settings (line_account_id, setting_key, setting_value) VALUES (NULL, ?, ?)")
       ->execute([$key, $value]);
}

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_shop_info') {
            // ตารางบางร้านถูกสร้างจาก fallback แคบใน settings.php (ไม่มี shop_address ฯลฯ)
            // จนกว่าเจ้าของร้านจะเข้าหน้าตั้งค่าเดิมสักครั้ง — เติมคอลัมน์ที่ขาดก่อนบันทึก
            // กันบันทึกพังกลางทาง (โลโก้อัปโหลดไปแล้วแต่ค่าอื่นเขียนไม่ได้)
            $hubColumnsToAdd = [
                'shop_address'  => "TEXT DEFAULT NULL",
                'contact_phone' => "VARCHAR(20) DEFAULT NULL",
                'shop_logo'     => "VARCHAR(500) DEFAULT NULL",
            ];
            foreach ($hubColumnsToAdd as $col => $type) {
                try {
                    $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE '$col'");
                    if ($stmt->rowCount() === 0) {
                        $db->exec("ALTER TABLE shop_settings ADD COLUMN $col $type");
                    }
                } catch (Exception $e) {
                    // อ่าน/แก้ schema ไม่ได้ — ปล่อยให้ UPDATE ด้านล่างล้มแล้วรายงาน error ปกติ
                }
            }

            // อัปเดตเฉพาะฟิลด์ฝั่งเว็บไซต์ — ไม่แตะ shipping/bank/COD ของ settings.php
            $updateFields = [
                'shop_name'       => trim($_POST['shop_name'] ?? ''),
                'welcome_message' => trim($_POST['welcome_message'] ?? ''),
                'shop_address'    => trim($_POST['shop_address'] ?? ''),
                'contact_phone'   => trim($_POST['contact_phone'] ?? ''),
            ];
            if ($updateFields['shop_name'] === '') {
                throw new Exception('กรุณาระบุชื่อร้าน');
            }

            // โลโก้ (ถ้าอัปโหลดมา) — นามสกุลจากชนิดรูปที่ตรวจแล้วจริง เหมือน shop_photos
            if (!empty($_FILES['logo_file']['tmp_name']) && ($_FILES['logo_file']['error'] ?? 1) === UPLOAD_ERR_OK) {
                if (($_FILES['logo_file']['size'] ?? 0) > 2 * 1024 * 1024) {
                    throw new Exception('ไฟล์โลโก้ใหญ่เกิน 2MB');
                }
                $imgInfo = @getimagesize($_FILES['logo_file']['tmp_name']);
                if ($imgInfo === false || !in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    throw new Exception('โลโก้รองรับเฉพาะ JPG, PNG, WEBP');
                }
                $hubTenantId = class_exists('TenantContext') ? TenantContext::getCurrentTenantId() : null;
                if (!$hubTenantId) {
                    throw new Exception('ไม่พบ tenant ปัจจุบัน (super admin ต้องเลือกร้านก่อน)');
                }
                $logoUpload = $_FILES['logo_file'];
                $logoUpload['name'] = 'logo' . image_type_to_extension($imgInfo[2]);
                $logoFilename = TenantFileStorage::saveUpload((int) $hubTenantId, 'logos', $logoUpload);
                $updateFields['shop_logo'] = TenantFileStorage::url((int) $hubTenantId, 'logos', $logoFilename);
            }

            // update-or-insert ตาม line_account_id (semantics เดียวกับ settings.php)
            if ($currentBotId) {
                $sel = $db->prepare("SELECT id FROM shop_settings WHERE line_account_id = ? LIMIT 1");
                $sel->execute([$currentBotId]);
            } else {
                $sel = $db->query("SELECT id FROM shop_settings ORDER BY id ASC LIMIT 1");
            }
            $existingId = $sel->fetchColumn();

            if ($existingId) {
                $setClauses = [];
                $values = [];
                foreach ($updateFields as $field => $value) {
                    $setClauses[] = "$field = ?";
                    $values[] = $value;
                }
                $values[] = (int) $existingId;
                $db->prepare("UPDATE shop_settings SET " . implode(', ', $setClauses) . " WHERE id = ?")
                   ->execute($values);
            } else {
                $updateFields['line_account_id'] = $currentBotId;
                $fields = array_keys($updateFields);
                $placeholders = array_fill(0, count($fields), '?');
                $db->prepare(
                    "INSERT INTO shop_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")"
                )->execute(array_values($updateFields));
            }
            $success = 'บันทึกข้อมูลร้านแล้ว';
        }

        elseif ($action === 'save_hours') {
            $dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            $hours = [];
            foreach ($dayKeys as $day) {
                if (!empty($_POST['hours_closed'][$day])) {
                    $hours[$day] = 'closed';
                    continue;
                }
                $open  = trim($_POST['hours_open'][$day] ?? '');
                $close = trim($_POST['hours_close'][$day] ?? '');
                $timePattern = '/\A([01]?\d|2[0-3]):[0-5]\d\z/';
                if (preg_match($timePattern, $open) && preg_match($timePattern, $close)) {
                    $hours[$day] = $open . '-' . $close;
                }
                // ไม่กรอก = ไม่บันทึกวันนั้น (หน้าเว็บจะไม่แสดงแถววันนั้น)
            }
            websiteHubPutLandingSetting($db, $currentBotId, 'operating_hours', json_encode($hours, JSON_UNESCAPED_UNICODE));
            $success = 'บันทึกเวลาทำการแล้ว';
        }

        // ── เว็บโฉมใหม่ (ย้ายมาจาก landing-settings แท็บชั่วคราว) ──────
        elseif ($action === 'save_v2_draft') {
            $draft = $landingV2->getDraft();
            $draft['theme']    = $_POST['v2_theme'] ?? $draft['theme'];
            $draft['hero']     = $_POST['v2_hero'] ?? $draft['hero'];
            $draft['headline'] = $_POST['v2_headline'] ?? '';
            $draft['tagline']  = $_POST['v2_tagline'] ?? '';
            foreach (array_keys(LandingV2Config::defaults()['show']) as $sec) {
                $draft['show'][$sec] = isset($_POST['v2_show'][$sec]);
            }
            $landingV2->saveDraft($draft);
            $success = 'บันทึกร่างแล้ว กด "ดูตัวอย่างร่าง" เพื่อเช็คก่อนเผยแพร่';
        }
        elseif ($action === 'upload_v2_photo') {
            $slot = $_POST['slot'] ?? '';
            if (!isset(LandingV2Config::PHOTO_SLOTS[$slot])) {
                throw new Exception('ตำแหน่งรูปไม่ถูกต้อง');
            }
            if (empty($_FILES['photo']['tmp_name']) || ($_FILES['photo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new Exception('กรุณาเลือกไฟล์รูปก่อนอัปโหลด');
            }
            if (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new Exception('ไฟล์ใหญ่เกิน 5MB');
            }
            $imgInfo = @getimagesize($_FILES['photo']['tmp_name']);
            if ($imgInfo === false || !in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                throw new Exception('รองรับเฉพาะไฟล์รูป JPG, PNG, WEBP');
            }
            // ตั้งนามสกุลไฟล์จากชนิดรูปที่ตรวจแล้วจริง ไม่เชื่อชื่อไฟล์จากผู้ใช้
            $v2Upload = $_FILES['photo'];
            $v2Upload['name'] = 'photo' . image_type_to_extension($imgInfo[2]);
            $v2TenantId = class_exists('TenantContext') ? TenantContext::getCurrentTenantId() : null;
            if (!$v2TenantId) {
                throw new Exception('ไม่พบ tenant ปัจจุบัน (super admin ต้องเลือกร้านก่อน)');
            }
            $newFilename = TenantFileStorage::saveUpload((int) $v2TenantId, 'shop_photos', $v2Upload);
            $draft = $landingV2->getDraft();
            $oldFilename = $draft['photos'][$slot] ?? '';
            $draft['photos'][$slot] = $newFilename;
            $landingV2->saveDraft($draft);
            // ห้ามลบไฟล์ที่หน้า published ยังอ้างถึงอยู่
            $publishedPhotos = array_values(($landingV2->getPublished()['photos'] ?? []));
            if ($oldFilename !== '' && $oldFilename !== $newFilename && !in_array($oldFilename, $publishedPhotos, true)) {
                TenantFileStorage::delete((int) $v2TenantId, 'shop_photos', $oldFilename);
            }
            $success = 'อัปโหลดรูป "' . LandingV2Config::PHOTO_SLOTS[$slot] . '" แล้ว';
        }
        elseif ($action === 'remove_v2_photo') {
            $slot = $_POST['slot'] ?? '';
            if (!isset(LandingV2Config::PHOTO_SLOTS[$slot])) {
                throw new Exception('ตำแหน่งรูปไม่ถูกต้อง');
            }
            $draft = $landingV2->getDraft();
            $oldFilename = $draft['photos'][$slot] ?? '';
            $draft['photos'][$slot] = '';
            $landingV2->saveDraft($draft);
            $v2TenantId = class_exists('TenantContext') ? TenantContext::getCurrentTenantId() : null;
            $publishedPhotos = array_values(($landingV2->getPublished()['photos'] ?? []));
            if ($v2TenantId && $oldFilename !== '' && !in_array($oldFilename, $publishedPhotos, true)) {
                TenantFileStorage::delete((int) $v2TenantId, 'shop_photos', $oldFilename);
            }
            $success = 'ลบรูปแล้ว';
        }
        elseif ($action === 'publish_v2') {
            $landingV2->publish();
            $success = 'เผยแพร่เว็บโฉมใหม่แล้ว ลูกค้าเห็นหน้าใหม่ทันที';
        }
        elseif ($action === 'unpublish_v2') {
            $landingV2->unpublish();
            $success = 'ปิดใช้เว็บโฉมใหม่แล้ว ลูกค้ากลับไปเห็นหน้าเดิม';
        }
    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// ── โหลดข้อมูลสำหรับแสดงผล + checklist ───────────────────────────────
$shopSettings = [];
try {
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$currentBotId]);
    } else {
        $stmt = $db->query("SELECT * FROM shop_settings ORDER BY id ASC LIMIT 1");
    }
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $shopSettings = [];
}

$hubLandingSettings = [];
try {
    $stmt = $db->prepare(
        "SELECT setting_key, setting_value FROM landing_settings
         WHERE line_account_id = ? OR line_account_id IS NULL
         ORDER BY line_account_id IS NULL DESC"
    );
    $stmt->execute([$currentBotId]);
    $hubLandingSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $hubLandingSettings = [];
}

$hubHours = [];
if (!empty($hubLandingSettings['operating_hours'])) {
    $decoded = json_decode($hubLandingSettings['operating_hours'], true);
    if (is_array($decoded)) { $hubHours = $decoded; }
}

$hubLineAccount = null;
try {
    $stmt = $db->query("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
    $hubLineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hubLineAccount) {
        $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1");
        $hubLineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$v2DraftForChecklist = $landingV2->getDraft();
$hasAnyPhoto = false;
foreach ($v2DraftForChecklist['photos'] as $p) {
    if ($p !== '') { $hasAnyPhoto = true; break; }
}
$featuredCount = 0;
try { $featuredCount = $featuredService->getCount(); } catch (Exception $e) {}
$hasLine = !empty($hubLineAccount['basic_id'])
    || (!empty($hubLineAccount['liff_id']) && reya_is_real_liff_id($hubLineAccount['liff_id']));

$checklist = [
    [
        'label' => 'ตั้งชื่อร้านและโลโก้',
        'done'  => !empty($shopSettings['shop_name']) && !empty($shopSettings['shop_logo']),
        'hint'  => 'โลโก้แสดงบนหัวเว็บและผลค้นหา',
        'link'  => '#shop-info',
    ],
    [
        'label' => 'ใส่ที่อยู่และเบอร์โทร',
        'done'  => !empty($shopSettings['shop_address']) && !empty($shopSettings['contact_phone']),
        'hint'  => 'ลูกค้าโทรหาและเดินทางมาร้านได้',
        'link'  => '#shop-info',
    ],
    [
        'label' => 'ตั้งเวลาทำการ',
        'done'  => !empty($hubHours),
        'hint'  => 'หน้าเว็บจะโชว์ป้าย "เปิดอยู่ตอนนี้" อัตโนมัติ',
        'link'  => '#hours',
    ],
    [
        'label' => 'อัปโหลดรูปหน้าร้านจริง',
        'done'  => $hasAnyPhoto,
        'hint'  => 'รูปจริงช่วยให้ลูกค้าเชื่อใจว่าไม่ใช่เพจปลอม',
        'link'  => '#v2-settings',
    ],
    [
        'label' => 'เลือกสินค้าแนะนำ',
        'done'  => $featuredCount > 0,
        'hint'  => 'ยังไม่เลือก = หน้าเว็บซ่อน section สินค้า',
        'link'  => '/admin/landing-settings?tab=featured',
    ],
    [
        'label' => 'เชื่อมบัญชี LINE OA',
        'done'  => $hasLine,
        'hint'  => 'ปุ่ม "เพิ่มเพื่อน LINE" ต้องใช้บัญชีนี้',
        'link'  => '/settings?tab=line',
    ],
    [
        'label' => 'เผยแพร่เว็บโฉมใหม่',
        'done'  => $landingV2->isPublished(),
        'hint'  => 'กดเผยแพร่ในกล่อง "เว็บร้านโฉมใหม่" ด้านล่าง',
        'link'  => '#v2-settings',
    ],
];
$checklistDone = count(array_filter($checklist, fn ($c) => $c['done']));
$checklistTotal = count($checklist);

$hubDayNames = [
    'mon' => 'จันทร์', 'tue' => 'อังคาร', 'wed' => 'พุธ', 'thu' => 'พฤหัสบดี',
    'fri' => 'ศุกร์', 'sat' => 'เสาร์', 'sun' => 'อาทิตย์',
];

$pageTitle = 'เว็บไซต์ร้าน';
$wizardCssVersion = @filemtime(__DIR__ . '/assets/css/website-wizard.css') ?: 1;
$extraStyles = '<link rel="stylesheet" href="assets/css/website-wizard.css?v=' . $wizardCssVersion . '">';
require_once 'includes/header.php';
?>

<style>
/* badge สถานะ (ชุดเดียวกับ landing-settings เพราะ admin-website-v2.php ใช้ร่วม) */
.badge-status { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
.badge-pending { background: #fef3c7; color: #d97706; }
.badge-approved { background: #dcfce7; color: #16a34a; }
</style>

<div class="max-w-5xl mx-auto space-y-6">

    <?php if ($success): ?>
    <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl flex items-center gap-3">
        <i class="fas fa-check-circle text-xl"></i>
        <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3">
        <i class="fas fa-exclamation-circle text-xl"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- หัวหน้า: ชื่อ + ลิงก์ดูหน้าเว็บ -->
    <div class="bg-white rounded-xl shadow-sm p-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold flex items-center gap-2">
                <i class="fas fa-globe text-emerald-600"></i>
                เว็บไซต์ร้าน
            </h2>
            <p class="text-sm text-gray-500 mt-1">จัดการหน้าเว็บสาธารณะของร้านครบในหน้าเดียว</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="WebsiteWizard.start()"
                    class="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium text-sm">
                <i class="fas fa-wand-magic-sparkles mr-1"></i> ตั้งค่าแบบมีไกด์
            </button>
            <a href="/" target="_blank"
               class="px-4 py-2 rounded-lg bg-gray-800 text-white hover:bg-gray-700 font-medium text-sm">
                <i class="fas fa-arrow-up-right-from-square mr-1"></i> ดูหน้าเว็บจริง
            </a>
        </div>
    </div>

    <!-- Checklist ความครบ -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <span class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-list-check text-emerald-600"></i>
                </span>
                ความพร้อมของหน้าเว็บ
            </h3>
            <span class="text-sm font-bold <?= $checklistDone === $checklistTotal ? 'text-emerald-600' : 'text-amber-600' ?>">
                <?= $checklistDone ?>/<?= $checklistTotal ?> ข้อ
            </span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2 mb-5">
            <div class="bg-emerald-500 h-2 rounded-full" style="width: <?= (int) round($checklistDone / $checklistTotal * 100) ?>%"></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <?php foreach ($checklist as $item): ?>
            <a href="<?= htmlspecialchars($item['link']) ?>"
               class="flex items-start gap-3 p-3 rounded-lg border <?= $item['done'] ? 'border-emerald-100 bg-emerald-50/50' : 'border-gray-200 hover:border-emerald-300' ?>">
                <?php if ($item['done']): ?>
                <span class="w-5 h-5 mt-0.5 flex-none rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]"><i class="fas fa-check"></i></span>
                <?php else: ?>
                <span class="w-5 h-5 mt-0.5 flex-none rounded-full border-2 border-gray-300"></span>
                <?php endif; ?>
                <span>
                    <span class="block text-sm font-medium <?= $item['done'] ? 'text-emerald-800' : 'text-gray-800' ?>"><?= htmlspecialchars($item['label']) ?></span>
                    <span class="block text-xs text-gray-400"><?= htmlspecialchars($item['hint']) ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ข้อมูลร้าน -->
    <form method="POST" enctype="multipart/form-data" id="shop-info" class="bg-white rounded-xl shadow-sm p-6">
        <input type="hidden" name="action" value="save_shop_info">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
            <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-store text-blue-600"></i>
            </span>
            ข้อมูลร้าน
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อร้าน *</label>
                <input type="text" name="shop_name" required maxlength="255"
                       value="<?= htmlspecialchars($shopSettings['shop_name'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทรร้าน</label>
                <input type="text" name="contact_phone" maxlength="20"
                       value="<?= htmlspecialchars($shopSettings['contact_phone'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">คำอธิบายร้านสั้นๆ</label>
                <input type="text" name="welcome_message" maxlength="500"
                       value="<?= htmlspecialchars($shopSettings['welcome_message'] ?? '') ?>"
                       placeholder="เช่น ร้านยาใกล้บ้าน มีเภสัชกรพร้อมให้คำปรึกษา"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                <p class="text-xs text-gray-400 mt-1">ใช้เป็นคำโปรยบนหน้าเว็บเมื่อไม่ได้ตั้งคำโปรยเฉพาะ</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">ที่อยู่ร้าน</label>
                <textarea name="shop_address" rows="2" maxlength="500"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"><?= htmlspecialchars($shopSettings['shop_address'] ?? '') ?></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">โลโก้ร้าน (JPG/PNG/WEBP ไม่เกิน 2MB)</label>
                <div class="flex items-center gap-4">
                    <?php if (!empty($shopSettings['shop_logo'])): ?>
                    <img src="<?= htmlspecialchars($shopSettings['shop_logo']) ?>" alt="โลโก้ปัจจุบัน"
                         class="w-14 h-14 rounded-xl object-cover border border-gray-200">
                    <?php endif; ?>
                    <input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp"
                           class="block text-sm text-gray-500 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-sm file:font-medium hover:file:bg-emerald-100">
                </div>
            </div>
        </div>
        <div class="flex justify-end mt-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">
                <i class="fas fa-floppy-disk mr-1"></i> บันทึกข้อมูลร้าน
            </button>
        </div>
    </form>

    <!-- เวลาทำการ -->
    <form method="POST" id="hours" class="bg-white rounded-xl shadow-sm p-6">
        <input type="hidden" name="action" value="save_hours">
        <h3 class="text-lg font-bold mb-1 flex items-center gap-2">
            <span class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-amber-600"></i>
            </span>
            เวลาทำการ
        </h3>
        <p class="text-sm text-gray-500 mb-4">หน้าเว็บใช้คำนวณป้าย "เปิดอยู่ตอนนี้" อัตโนมัติ วันที่ไม่กรอกจะไม่แสดง</p>
        <div class="space-y-2">
            <?php foreach ($hubDayNames as $dayKey => $dayName):
                $range = $hubHours[$dayKey] ?? '';
                $isClosed = ($range === 'closed');
                $open = ''; $close = '';
                if (!$isClosed && preg_match('/\A(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})\z/', (string) $range, $m)) {
                    $open = $m[1]; $close = $m[2];
                }
            ?>
            <div class="flex flex-wrap items-center gap-3 p-2 rounded-lg hover:bg-gray-50">
                <span class="w-20 text-sm font-medium"><?= htmlspecialchars($dayName) ?></span>
                <input type="time" name="hours_open[<?= $dayKey ?>]" value="<?= htmlspecialchars($open) ?>"
                       class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hours-time" data-day="<?= $dayKey ?>">
                <span class="text-gray-400 text-sm">ถึง</span>
                <input type="time" name="hours_close[<?= $dayKey ?>]" value="<?= htmlspecialchars($close) ?>"
                       class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hours-time" data-day="<?= $dayKey ?>">
                <label class="flex items-center gap-2 text-sm text-gray-600 ml-2 cursor-pointer">
                    <input type="checkbox" name="hours_closed[<?= $dayKey ?>]" value="1" <?= $isClosed ? 'checked' : '' ?>
                           class="w-4 h-4 text-red-500 rounded focus:ring-red-400 hours-closed" data-day="<?= $dayKey ?>">
                    ปิดทำการ
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-end mt-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">
                <i class="fas fa-floppy-disk mr-1"></i> บันทึกเวลาทำการ
            </button>
        </div>
    </form>

    <!-- เว็บโฉมใหม่: ธีม / hero / รูปหน้าร้าน / เผยแพร่ -->
    <div id="v2-settings">
        <?php include 'includes/landing/admin-website-v2.php'; ?>
    </div>

    <!-- ทางลัดเนื้อหาเชิงลึก -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
            <span class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-pen-to-square text-purple-600"></i>
            </span>
            เนื้อหาบนหน้าเว็บ
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <a href="/admin/landing-settings?tab=featured" class="p-4 border border-gray-200 rounded-xl hover:border-emerald-400 text-center">
                <i class="fas fa-star text-amber-500 text-xl mb-2"></i>
                <span class="block text-sm font-medium">สินค้าแนะนำ</span>
                <span class="block text-xs text-gray-400"><?= (int) $featuredCount ?> รายการ</span>
            </a>
            <a href="/admin/landing-settings?tab=faq" class="p-4 border border-gray-200 rounded-xl hover:border-emerald-400 text-center">
                <i class="fas fa-question-circle text-sky-500 text-xl mb-2"></i>
                <span class="block text-sm font-medium">คำถามที่พบบ่อย</span>
            </a>
            <a href="/admin/landing-settings?tab=articles" class="p-4 border border-gray-200 rounded-xl hover:border-emerald-400 text-center">
                <i class="fas fa-newspaper text-teal-500 text-xl mb-2"></i>
                <span class="block text-sm font-medium">บทความสุขภาพ</span>
            </a>
            <a href="/admin/landing-settings?tab=seo" class="p-4 border border-gray-200 rounded-xl hover:border-emerald-400 text-center">
                <i class="fas fa-map-location-dot text-rose-500 text-xl mb-2"></i>
                <span class="block text-sm font-medium">แผนที่ & SEO</span>
            </a>
        </div>
    </div>

</div>

<script>
// ติ๊ก "ปิดทำการ" แล้ว disable ช่องเวลาให้เห็นชัด
document.querySelectorAll('.hours-closed').forEach(function (cb) {
    function sync() {
        document.querySelectorAll('.hours-time[data-day="' + cb.dataset.day + '"]').forEach(function (inp) {
            inp.disabled = cb.checked;
            inp.classList.toggle('opacity-40', cb.checked);
        });
    }
    cb.addEventListener('change', sync);
    sync();
});
</script>

<?php $wizardJsVersion = @filemtime(__DIR__ . '/assets/js/website-wizard.js') ?: 1; ?>
<script src="assets/js/website-wizard.js?v=<?= $wizardJsVersion ?>"></script>

<?php require_once 'includes/footer.php'; ?>
