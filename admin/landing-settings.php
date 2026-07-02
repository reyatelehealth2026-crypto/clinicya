<?php
/**
 * Landing Page Settings - Admin Panel
 * จัดการตั้งค่า Landing Page: Banners, Featured Products, SEO, FAQ, Testimonials, Trust Badges
 * 
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5
 */

// Set the base path for includes
define('ADMIN_BASE_PATH', dirname(__DIR__) . '/');

require_once ADMIN_BASE_PATH . 'config/config.php';
require_once ADMIN_BASE_PATH . 'config/database.php';
require_once ADMIN_BASE_PATH . 'includes/auth_check.php';
require_once ADMIN_BASE_PATH . 'includes/components/tabs.php';
require_once ADMIN_BASE_PATH . 'includes/components/form-section.php';
require_once ADMIN_BASE_PATH . 'includes/components/field.php';
require_once ADMIN_BASE_PATH . 'includes/components/toggle.php';
require_once ADMIN_BASE_PATH . 'includes/components/sticky-save-bar.php';
require_once ADMIN_BASE_PATH . 'classes/FAQService.php';
require_once ADMIN_BASE_PATH . 'classes/TestimonialService.php';
require_once ADMIN_BASE_PATH . 'classes/TrustBadgeService.php';
require_once ADMIN_BASE_PATH . 'classes/LandingSEOService.php';
require_once ADMIN_BASE_PATH . 'classes/LandingBannerService.php';
require_once ADMIN_BASE_PATH . 'classes/FeaturedProductService.php';
require_once ADMIN_BASE_PATH . 'classes/HealthArticleService.php';
require_once ADMIN_BASE_PATH . 'classes/LandingV2Config.php';
require_once ADMIN_BASE_PATH . 'classes/TenantFileStorage.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;
$lineAccountId = $currentBotId; // Alias for includes

// Initialize services
$faqService = new FAQService($db, $currentBotId);
$testimonialService = new TestimonialService($db, $currentBotId);
$trustBadgeService = new TrustBadgeService($db, $currentBotId);
$seoService = new LandingSEOService($db, $currentBotId);
$bannerService = new LandingBannerService($db, $currentBotId);
$featuredProductService = new FeaturedProductService($db, $currentBotId);
$articleService = new HealthArticleService($db, $currentBotId);
$landingV2 = new LandingV2Config($db, $currentBotId);

// Tab configuration
$tabs = [
    'website_v2' => ['label' => 'เว็บโฉมใหม่', 'icon' => 'fas fa-wand-magic-sparkles'],
    'banners' => ['label' => 'แบนเนอร์', 'icon' => 'fas fa-images', 'badge' => $bannerService->getCount()],
    'featured' => ['label' => 'สินค้าแนะนำ', 'icon' => 'fas fa-star', 'badge' => $featuredProductService->getCount()],
    'articles' => ['label' => 'บทความ', 'icon' => 'fas fa-newspaper', 'badge' => $articleService->getCount()],
    'seo' => ['label' => 'SEO', 'icon' => 'fas fa-search'],
    'faq' => ['label' => 'FAQ', 'icon' => 'fas fa-question-circle'],
    'testimonials' => ['label' => 'รีวิว', 'icon' => 'fas fa-comments', 'badge' => $testimonialService->getPendingCount()],
    'trust' => ['label' => 'Trust Badges', 'icon' => 'fas fa-shield-alt'],
    'custom_html' => ['label' => 'Custom HTML', 'icon' => 'fas fa-code'],
];

$activeTab = getActiveTab($tabs, 'banners');
$pageTitle = 'ตั้งค่า Landing Page';

$success = null;
$error = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // Banner actions
        if ($action === 'create_banner') {
            $bannerService->create([
                'title' => $_POST['title'] ?? '',
                'image_url' => $_POST['image_url'] ?? '',
                'link_url' => $_POST['link_url'] ?? '',
                'link_type' => $_POST['link_type'] ?? 'none',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);
            $success = 'เพิ่มแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        }
        elseif ($action === 'update_banner') {
            $bannerService->update((int)$_POST['id'], [
                'title' => $_POST['title'] ?? '',
                'image_url' => $_POST['image_url'] ?? '',
                'link_url' => $_POST['link_url'] ?? '',
                'link_type' => $_POST['link_type'] ?? 'none',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);
            $success = 'อัปเดตแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        }
        elseif ($action === 'delete_banner') {
            $bannerService->delete((int)$_POST['id']);
            $success = 'ลบแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        }
        
        // Featured Products actions
        elseif ($action === 'add_featured') {
            $productSource = $_POST['product_source'] ?? 'products';
            $featuredProductService->addProduct((int)$_POST['product_id'], $productSource);
            $success = 'เพิ่มสินค้าแนะนำสำเร็จ!';
            $activeTab = 'featured';
        }
        elseif ($action === 'remove_featured') {
            $featuredProductService->removeProduct((int)$_POST['id']);
            $success = 'ลบสินค้าออกจากรายการแนะนำสำเร็จ!';
            $activeTab = 'featured';
        }
        elseif ($action === 'toggle_featured') {
            $featuredProductService->toggleActive((int)$_POST['id']);
            $success = 'อัปเดตสถานะสำเร็จ!';
            $activeTab = 'featured';
        }
        
        // SEO Settings actions (Requirements: 10.1, 10.2)
        if ($action === 'save_seo') {
            $settings = [
                'page_title' => trim($_POST['page_title'] ?? ''),
                'app_name' => trim($_POST['app_name'] ?? ''),
                'favicon_url' => trim($_POST['favicon_url'] ?? ''),
                'meta_keywords' => trim($_POST['meta_keywords'] ?? ''),
                'meta_description' => trim($_POST['meta_description'] ?? ''),
                'latitude' => trim($_POST['latitude'] ?? ''),
                'longitude' => trim($_POST['longitude'] ?? ''),
                'google_map_embed' => trim($_POST['google_map_embed'] ?? ''),
                'operating_hours' => $_POST['operating_hours'] ?? ''
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$currentBotId, $key, $value]);
            }
            
            $success = 'บันทึกการตั้งค่า SEO สำเร็จ!';
            $activeTab = 'seo';
        }
        
        // FAQ actions (Requirements: 10.3)
        elseif ($action === 'create_faq') {
            $faqService->create([
                'question' => $_POST['question'] ?? '',
                'answer' => $_POST['answer'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);
            $success = 'เพิ่มคำถามที่พบบ่อยสำเร็จ!';
            $activeTab = 'faq';
        }
        elseif ($action === 'update_faq') {
            $faqService->update((int)$_POST['id'], [
                'question' => $_POST['question'] ?? '',
                'answer' => $_POST['answer'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);
            $success = 'อัปเดตคำถามที่พบบ่อยสำเร็จ!';
            $activeTab = 'faq';
        }
        elseif ($action === 'delete_faq') {
            $faqService->delete((int)$_POST['id']);
            $success = 'ลบคำถามที่พบบ่อยสำเร็จ!';
            $activeTab = 'faq';
        }
        elseif ($action === 'reorder_faq') {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!empty($ids)) {
                $faqService->reorder($ids);
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        // Testimonial actions (Requirements: 10.4)
        elseif ($action === 'create_testimonial') {
            $testimonialService->create([
                'customer_name' => $_POST['customer_name'] ?? '',
                'rating' => (int)($_POST['rating'] ?? 5),
                'review_text' => $_POST['review_text'] ?? '',
                'source' => 'manual',
                'status' => 'approved'
            ]);
            $success = 'เพิ่มรีวิวสำเร็จ!';
            $activeTab = 'testimonials';
        }
        elseif ($action === 'update_testimonial') {
            $testimonialService->update((int)$_POST['id'], [
                'customer_name' => $_POST['customer_name'] ?? '',
                'rating' => (int)($_POST['rating'] ?? 5),
                'review_text' => $_POST['review_text'] ?? ''
            ]);
            $success = 'อัปเดตรีวิวสำเร็จ!';
            $activeTab = 'testimonials';
        }
        elseif ($action === 'approve_testimonial') {
            $testimonialService->approve((int)$_POST['id']);
            $success = 'อนุมัติรีวิวสำเร็จ!';
            $activeTab = 'testimonials';
        }
        elseif ($action === 'reject_testimonial') {
            $testimonialService->reject((int)$_POST['id']);
            $success = 'ปฏิเสธรีวิวสำเร็จ!';
            $activeTab = 'testimonials';
        }
        elseif ($action === 'delete_testimonial') {
            $testimonialService->delete((int)$_POST['id']);
            $success = 'ลบรีวิวสำเร็จ!';
            $activeTab = 'testimonials';
        }
        
        // Trust Badge actions (Requirements: 10.5)
        elseif ($action === 'save_trust') {
            $settings = [
                'license_number' => trim($_POST['license_number'] ?? ''),
                'establishment_year' => trim($_POST['establishment_year'] ?? '')
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$currentBotId, $key, $value]);
            }
            
            $success = 'บันทึกการตั้งค่า Trust Badges สำเร็จ!';
            $activeTab = 'trust';
        }
        
        // Custom HTML action (2026-05-27)
        elseif ($action === 'save_custom_html') {
            // section = '' → append-at-end block (custom_html); else override a
            // hardcoded section (custom_html_{section}).
            $section = preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['section'] ?? '')));
            $allowedSections = ['hero', 'about', 'features', 'services', 'cta'];
            $key = in_array($section, $allowedSections, true) ? 'custom_html_' . $section : 'custom_html';
            $html = (string) ($_POST['custom_html'] ?? '');
            $stmt = $db->prepare(
                "INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([$currentBotId, $key, $html]);
            $success = 'บันทึก Custom HTML สำเร็จ!';
            $activeTab = 'custom_html';
            $_GET['section'] = $section; // keep the editor on the same section after save
        }

        // Custom Badges action (Requirements: 10.5)
        elseif ($action === 'save_custom_badges') {
            $customBadgesJson = $_POST['custom_badges_json'] ?? '[]';
            $customBadges = json_decode($customBadgesJson, true);
            
            if (is_array($customBadges)) {
                $trustBadgeService->saveCustomBadges($customBadges);
                $success = 'บันทึก Custom Badges สำเร็จ!';
            } else {
                $error = 'ข้อมูล Custom Badges ไม่ถูกต้อง';
            }
            $activeTab = 'trust';
        }

        // Landing V2 actions (2026-07-03) — เว็บร้านโฉมใหม่
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
            $activeTab = 'website_v2';
        }
        elseif ($action === 'upload_v2_photo') {
            $activeTab = 'website_v2';
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
            // (กันไฟล์ polyglot เช่นรูป JPEG ที่ตั้งชื่อ .phar/.php7)
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
            // ห้ามลบไฟล์ที่หน้า published ยังอ้างถึงอยู่ ไม่งั้นหน้าจริงรูปแตกจนกว่าจะ republish
            $publishedPhotos = array_values(($landingV2->getPublished()['photos'] ?? []));
            if ($oldFilename !== '' && $oldFilename !== $newFilename && !in_array($oldFilename, $publishedPhotos, true)) {
                TenantFileStorage::delete((int) $v2TenantId, 'shop_photos', $oldFilename);
            }
            $success = 'อัปโหลดรูป "' . LandingV2Config::PHOTO_SLOTS[$slot] . '" แล้ว';
        }
        elseif ($action === 'remove_v2_photo') {
            $activeTab = 'website_v2';
            $slot = $_POST['slot'] ?? '';
            if (!isset(LandingV2Config::PHOTO_SLOTS[$slot])) {
                throw new Exception('ตำแหน่งรูปไม่ถูกต้อง');
            }
            $draft = $landingV2->getDraft();
            $oldFilename = $draft['photos'][$slot] ?? '';
            $draft['photos'][$slot] = '';
            $landingV2->saveDraft($draft);
            $v2TenantId = class_exists('TenantContext') ? TenantContext::getCurrentTenantId() : null;
            // ห้ามลบไฟล์ที่หน้า published ยังอ้างถึงอยู่ (เหมือน upload_v2_photo)
            $publishedPhotos = array_values(($landingV2->getPublished()['photos'] ?? []));
            if ($v2TenantId && $oldFilename !== '' && !in_array($oldFilename, $publishedPhotos, true)) {
                TenantFileStorage::delete((int) $v2TenantId, 'shop_photos', $oldFilename);
            }
            $success = 'ลบรูปแล้ว';
        }
        elseif ($action === 'publish_v2') {
            $landingV2->publish();
            $success = 'เผยแพร่เว็บโฉมใหม่แล้ว ลูกค้าเห็นหน้าใหม่ทันที';
            $activeTab = 'website_v2';
        }
        elseif ($action === 'unpublish_v2') {
            $landingV2->unpublish();
            $success = 'ปิดใช้เว็บโฉมใหม่แล้ว ลูกค้ากลับไปเห็นหน้าเดิม';
            $activeTab = 'website_v2';
        }

    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Load current settings
$landingSettings = [];
try {
    $sql = "SELECT setting_key, setting_value FROM landing_settings WHERE line_account_id " . 
           ($currentBotId ? "= ?" : "IS NULL");
    $stmt = $db->prepare($sql);
    $stmt->execute($currentBotId ? [$currentBotId] : []);
    $landingSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    // Table might not exist
}

require_once ADMIN_BASE_PATH . 'includes/header.php';
echo getTabsStyles();
echo getFormSectionStyles();
echo getFieldStyles();
echo getToggleStyles();
echo getStickySaveBarStyles();
?>

<style>
.faq-item { transition: all 0.2s; cursor: grab; }
.faq-item:active { cursor: grabbing; }
.faq-item.dragging { opacity: 0.5; background: #f0f9ff; }
.testimonial-card { transition: all 0.2s; }
.testimonial-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.rating-stars { color: #fbbf24; }
.badge-status { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
.badge-pending { background: #fef3c7; color: #d97706; }
.badge-approved { background: #dcfce7; color: #16a34a; }
.badge-rejected { background: #fee2e2; color: #dc2626; }
.trust-badge-preview { padding: 16px; background: #f8fafc; border-radius: 12px; text-align: center; }
.trust-badge-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 20px; }
</style>

<?php if ($success): ?>
<div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-check-circle text-xl"></i>
    <span><?= htmlspecialchars($success) ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-xl"></i>
    <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab) ?>

<style>
/* 2026-05-27: 2-col layout for landing-settings — form left, Live Preview right.
   Stacks on tablets/mobile (< 1280px). */
.landing-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
    margin-top: 16px;
}
@media (min-width: 1280px) {
    .landing-layout { grid-template-columns: minmax(0, 1fr) 420px; }
}
.landing-preview-pane {
    position: sticky;
    top: 80px;
    align-self: start;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.1);
}
.landing-preview-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;
    font-size: 13px; font-weight: 600; color: #1e293b;
}
.landing-preview-head .actions { display: flex; gap: 6px; }
.landing-preview-head button, .landing-preview-head a {
    width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
    background: white; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer;
    color: #64748b; text-decoration: none; font-size: 12px;
}
.landing-preview-head button:hover, .landing-preview-head a:hover { background: #f1f5f9; color: #0f172a; }
.landing-preview-frame {
    width: 100%; aspect-ratio: 9 / 16; max-height: 80vh;
    border: 0; display: block; background: #f1f5f9;
}
</style>

<div class="landing-layout">
    <!-- Tab Content (left) -->
    <div class="tab-content">
        <div class="tab-panel">
            <?php
            switch ($activeTab) {
                case 'website_v2':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-website-v2.php';
                    break;
                case 'banners':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-banners.php';
                    break;
                case 'featured':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-featured.php';
                    break;
                case 'articles':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-articles.php';
                    break;
                case 'faq':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-faq.php';
                    break;
                case 'testimonials':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-testimonials.php';
                    break;
                case 'trust':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-trust.php';
                    break;
                case 'custom_html':
                    include ADMIN_BASE_PATH . 'includes/landing/admin-custom-html.php';
                    break;
                default:
                    include ADMIN_BASE_PATH . 'includes/landing/admin-seo.php';
            }
            ?>
        </div>
    </div>

    <!-- Live Preview pane (sticky right side on desktop) -->
    <aside class="landing-preview-pane">
        <div class="landing-preview-head">
            <span>
                <i class="fas fa-eye text-emerald-500 mr-1"></i>
                Live Preview · Landing
            </span>
            <div class="actions">
                <button type="button" onclick="reyaReloadLandingPreview()" title="รีโหลด preview">
                    <i class="fas fa-rotate"></i>
                </button>
                <a href="/" target="_blank" title="เปิดในแท็บใหม่">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            </div>
        </div>
        <iframe id="reyaLandingPreview"
                src="/?preview=1&_=<?= time() ?>"
                class="landing-preview-frame"
                loading="lazy"
                title="REYA Landing Page Live Preview"></iframe>
    </aside>
</div>

<script>
function reyaReloadLandingPreview() {
    const fr = document.getElementById('reyaLandingPreview');
    if (!fr) return;
    fr.src = '/?preview=1&_=' + Date.now();
}

// 2026-05-27: listen for "click section in preview → switch tab" messages from iframe
window.addEventListener('message', function (ev) {
    const msg = ev && ev.data;
    if (!msg || msg.type !== 'reya-edit-tab' || !msg.tab) return;
    // Switch tab by reloading with ?tab= (and ?section= for Custom HTML overrides)
    const url = new URL(window.location.href);
    url.searchParams.set('tab', msg.tab);
    if (msg.section) { url.searchParams.set('section', msg.section); }
    else { url.searchParams.delete('section'); }
    window.location.href = url.toString();
});
</script>

<?php require_once ADMIN_BASE_PATH . 'includes/footer.php'; ?>
