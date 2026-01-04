<?php
/**
 * Admin page to manually sync CNY products
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in (not necessarily admin for initial setup)
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$isLoggedIn = isset($_SESSION['user_id']);

if (!$isLoggedIn) {
    // Redirect to login
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$output = '';
$running = false;

if (isset($_POST['sync'])) {
    $running = true;
    set_time_limit(300); // 5 minutes
    
    ob_start();
    include __DIR__ . '/../cron/sync_cny_products.php';
    $output = ob_get_clean();
}
?>

<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">
            <i class="fas fa-sync-alt text-blue-500 mr-2"></i>
            ซิงค์ข้อมูลสินค้า CNY Pharmacy
        </h1>
        
        <?php if (!$isAdmin): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
            <p class="text-yellow-700">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                คุณไม่มีสิทธิ์ Admin แต่สามารถดูหน้านี้ได้เพื่อการติดตั้งครั้งแรก
            </p>
        </div>
        <?php endif; ?>
        
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <p class="text-blue-700">
                <i class="fas fa-info-circle mr-2"></i>
                คลิกปุ่มด้านล่างเพื่อดึงข้อมูลสินค้าทั้งหมดจาก CNY Pharmacy API และบันทึกลงฐานข้อมูล
            </p>
            <p class="text-blue-600 text-sm mt-2">
                กระบวนการนี้อาจใช้เวลา 1-2 นาที
            </p>
        </div>

        <?php if ($output): ?>
        <div class="bg-gray-900 text-green-400 p-4 rounded-lg mb-6 font-mono text-sm overflow-auto max-h-96">
            <?= nl2br(htmlspecialchars($output)) ?>
        </div>
        <?php endif; ?>

        <form method="POST" onsubmit="document.getElementById('syncBtn').disabled=true; document.getElementById('syncBtn').innerHTML='<i class=\'fas fa-spinner fa-spin mr-2\'></i>กำลังซิงค์...';">
            <button type="submit" name="sync" id="syncBtn" 
                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                    <?= $running ? 'disabled' : '' ?>>
                <i class="fas fa-sync-alt mr-2"></i>
                เริ่มซิงค์ข้อมูล
            </button>
            <a href="../shop/products-cny.php" class="ml-3 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 inline-block">
                <i class="fas fa-arrow-left mr-2"></i>
                กลับไปหน้าสินค้า
            </a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
