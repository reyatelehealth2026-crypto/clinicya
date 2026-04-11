<?php
/**
 * Mini App Home Content Settings - Admin Panel
 * จัดการเนื้อหาหน้า Home: แบนเนอร์สไลด์, Sections, สินค้า/Flash Sale
 */

define('ADMIN_BASE_PATH', dirname(__DIR__) . '/');

require_once ADMIN_BASE_PATH . 'config/config.php';
require_once ADMIN_BASE_PATH . 'config/database.php';
require_once ADMIN_BASE_PATH . 'includes/auth_check.php';
require_once ADMIN_BASE_PATH . 'includes/components/tabs.php';
require_once ADMIN_BASE_PATH . 'classes/MiniAppContentService.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

$service = new MiniAppContentService($db, $currentBotId);

// Tab configuration
$tabs = [
    'banners'  => ['label' => 'แบนเนอร์', 'icon' => 'fas fa-images', 'badge' => $service->getBannerCount()],
    'sections' => ['label' => 'Sections', 'icon' => 'fas fa-layer-group', 'badge' => $service->getSectionCount()],
    'products' => ['label' => 'สินค้า', 'icon' => 'fas fa-box-open', 'badge' => $service->getProductCount()],
];

$activeTab = getActiveTab($tabs, 'banners');
$pageTitle = 'ตั้งค่า Mini App Home';

$success = null;
$error = null;

// Link type options (shared across tabs)
$linkTypes = [
    'none'      => 'ไม่มีลิ้งค์',
    'url'       => 'URL ภายนอก',
    'miniapp'   => 'หน้าใน Mini App',
    'liff'      => 'LIFF URL',
    'line_chat' => 'เปิดแชท LINE',
    'deep_link' => 'Deep Link',
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // === BANNER ACTIONS ===
        if ($action === 'create_banner') {
            $service->createBanner($_POST);
            $success = 'เพิ่มแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        } elseif ($action === 'update_banner') {
            $service->updateBanner((int)$_POST['id'], $_POST);
            $success = 'อัปเดตแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        } elseif ($action === 'delete_banner') {
            $service->deleteBanner((int)$_POST['id']);
            $success = 'ลบแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        } elseif ($action === 'toggle_banner') {
            $service->toggleBanner((int)$_POST['id']);
            $success = 'เปลี่ยนสถานะแบนเนอร์สำเร็จ!';
            $activeTab = 'banners';
        }

        // === SECTION ACTIONS ===
        elseif ($action === 'create_section') {
            $service->createSection($_POST);
            $success = 'เพิ่ม Section สำเร็จ!';
            $activeTab = 'sections';
        } elseif ($action === 'update_section') {
            $service->updateSection((int)$_POST['id'], $_POST);
            $success = 'อัปเดต Section สำเร็จ!';
            $activeTab = 'sections';
        } elseif ($action === 'delete_section') {
            $service->deleteSection((int)$_POST['id']);
            $success = 'ลบ Section สำเร็จ!';
            $activeTab = 'sections';
        } elseif ($action === 'toggle_section') {
            $service->toggleSection((int)$_POST['id']);
            $success = 'เปลี่ยนสถานะ Section สำเร็จ!';
            $activeTab = 'sections';
        }

        // === PRODUCT ACTIONS ===
        elseif ($action === 'create_product') {
            // Handle JSON fields from textarea
            $_POST['promotion_tags'] = json_decode($_POST['promotion_tags'] ?? '[]', true) ?: [];
            $_POST['badges'] = json_decode($_POST['badges'] ?? '[]', true) ?: [];
            $_POST['delivery_options'] = json_decode($_POST['delivery_options'] ?? '[]', true) ?: [];
            $_POST['image_gallery'] = json_decode($_POST['image_gallery'] ?? '[]', true) ?: [];
            $service->createProduct($_POST);
            $success = 'เพิ่มสินค้าสำเร็จ!';
            $activeTab = 'products';
        } elseif ($action === 'update_product') {
            $_POST['promotion_tags'] = json_decode($_POST['promotion_tags'] ?? '[]', true) ?: [];
            $_POST['badges'] = json_decode($_POST['badges'] ?? '[]', true) ?: [];
            $_POST['delivery_options'] = json_decode($_POST['delivery_options'] ?? '[]', true) ?: [];
            $_POST['image_gallery'] = json_decode($_POST['image_gallery'] ?? '[]', true) ?: [];
            $service->updateProduct((int)$_POST['id'], $_POST);
            $success = 'อัปเดตสินค้าสำเร็จ!';
            $activeTab = 'products';
        } elseif ($action === 'delete_product') {
            $service->deleteProduct((int)$_POST['id']);
            $success = 'ลบสินค้าสำเร็จ!';
            $activeTab = 'products';
        } elseif ($action === 'toggle_product') {
            $service->toggleProduct((int)$_POST['id']);
            $success = 'เปลี่ยนสถานะสินค้าสำเร็จ!';
            $activeTab = 'products';
        }

    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

require_once ADMIN_BASE_PATH . 'includes/header.php';
echo getTabsStyles();
?>

<style>
.miniapp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: all 0.2s; }
.miniapp-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.miniapp-card.inactive { opacity: 0.5; }
.miniapp-form-group { margin-bottom: 16px; }
.miniapp-form-group label { display: block; font-weight: 600; font-size: 13px; color: #475569; margin-bottom: 6px; }
.miniapp-form-group input, .miniapp-form-group select, .miniapp-form-group textarea {
    width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: border-color 0.2s;
}
.miniapp-form-group input:focus, .miniapp-form-group select:focus, .miniapp-form-group textarea:focus {
    outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.1);
}
.miniapp-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.miniapp-form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.miniapp-hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
.miniapp-preview-img { max-height: 80px; border-radius: 8px; border: 1px solid #e2e8f0; }
.miniapp-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
.miniapp-badge-active { background: #dcfce7; color: #16a34a; }
.miniapp-badge-inactive { background: #fee2e2; color: #dc2626; }
.miniapp-badge-style { background: #ede9fe; color: #7c3aed; }
.miniapp-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.miniapp-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.miniapp-btn-primary { background: #7c3aed; color: white; }
.miniapp-btn-primary:hover { background: #6d28d9; }
.miniapp-btn-danger { background: #ef4444; color: white; }
.miniapp-btn-danger:hover { background: #dc2626; }
.miniapp-btn-sm { padding: 6px 12px; font-size: 12px; }
.miniapp-btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #475569; }
.miniapp-btn-outline:hover { background: #f8fafc; }
.miniapp-modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
.miniapp-modal.active { display: flex; }
.miniapp-modal-content { background: white; border-radius: 16px; padding: 24px; max-width: 700px; width: 95%; max-height: 90vh; overflow-y: auto; }
.miniapp-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.miniapp-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8; padding: 4px; }
@media (max-width: 640px) {
    .miniapp-form-row, .miniapp-form-row-3 { grid-template-columns: 1fr; }
}
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

<?= renderTabs($tabs, $activeTab) ?>

<div class="tab-content">
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
            case 'banners':
                include ADMIN_BASE_PATH . 'includes/miniapp/admin-miniapp-banners.php';
                break;
            case 'sections':
                include ADMIN_BASE_PATH . 'includes/miniapp/admin-miniapp-sections.php';
                break;
            case 'products':
                include ADMIN_BASE_PATH . 'includes/miniapp/admin-miniapp-products.php';
                break;
        }
        ?>
    </div>
</div>

<?php require_once ADMIN_BASE_PATH . 'includes/footer.php'; ?>
