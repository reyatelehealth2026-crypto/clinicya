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
require_once ADMIN_BASE_PATH . 'classes/FAQService.php';
require_once ADMIN_BASE_PATH . 'classes/TestimonialService.php';
require_once ADMIN_BASE_PATH . 'classes/TrustBadgeService.php';
require_once ADMIN_BASE_PATH . 'classes/LandingSEOService.php';
require_once ADMIN_BASE_PATH . 'classes/LandingBannerService.php';
require_once ADMIN_BASE_PATH . 'classes/FeaturedProductService.php';
require_once ADMIN_BASE_PATH . 'classes/HealthArticleService.php';

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

// Tab configuration
$tabs = [
    'banners' => ['label' => 'แบนเนอร์', 'icon' => 'fas fa-images', 'badge' => $bannerService->getCount()],
    'featured' => ['label' => 'สินค้าแนะนำ', 'icon' => 'fas fa-star', 'badge' => $featuredProductService->getCount()],
    'articles' => ['label' => 'บทความ', 'icon' => 'fas fa-newspaper', 'badge' => $articleService->getCount()],
    'seo' => ['label' => 'SEO', 'icon' => 'fas fa-search'],
    'faq' => ['label' => 'FAQ', 'icon' => 'fas fa-question-circle'],
    'testimonials' => ['label' => 'รีวิว', 'icon' => 'fas fa-comments', 'badge' => $testimonialService->getPendingCount()],
    'trust' => ['label' => 'Trust Badges', 'icon' => 'fas fa-shield-alt'],
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

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
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
            default:
                include ADMIN_BASE_PATH . 'includes/landing/admin-seo.php';
        }
        ?>
    </div>
</div>

<?php require_once ADMIN_BASE_PATH . 'includes/footer.php'; ?>
