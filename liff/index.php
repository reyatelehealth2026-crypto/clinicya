<?php
/**
 * Unified LIFF Application - Single Entry Point
 * LIFF Telepharmacy Redesign - SPA Architecture
 * 
 * Requirements: 1.1, 1.2, 1.3
 * - Display Skeleton_Loading within 100ms
 * - Authenticate LINE_User and retrieve profile automatically
 * - Client-side routing without full page reloads
 */

// Debug mode - show errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

// Get parameters
$page = $_GET['page'] ?? 'home';
$lineAccountId = $_GET['account'] ?? null;
$liffIdParam = $_GET['liff_id'] ?? null;

// Get LIFF ID and shop settings
$liffId = '';
$shopName = 'ร้านค้า';
$shopLogo = '';
$companyName = 'MedCare';

// Build LIFF ID to Account mapping for JavaScript
$liffToAccountMap = [];
try {
    $stmtAll = $db->query("SELECT id, liff_id FROM line_accounts WHERE liff_id IS NOT NULL AND liff_id != ''");
    while ($row = $stmtAll->fetch(PDO::FETCH_ASSOC)) {
        if ($row['liff_id']) {
            $liffToAccountMap[$row['liff_id']] = (int)$row['id'];
        }
    }
} catch (Exception $e) {}

try {
    // Priority: 1) liff_id param, 2) account param, 3) default
    if ($liffIdParam) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE liff_id = ? LIMIT 1");
        $stmt->execute([$liffIdParam]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($lineAccountId) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            $stmt = $db->prepare("SELECT * FROM line_accounts ORDER BY id LIMIT 1");
            $stmt->execute();
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    if ($account) {
        $lineAccountId = $account['id'];
        $liffId = $account['liff_id'] ?? '';
        $shopName = $account['name'];
        $companyName = $shopName;
        
        try {
            $stmt2 = $db->prepare("SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1");
            $stmt2->execute([$lineAccountId]);
            $shop = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($shop) {
                if ($shop['shop_name']) {
                    $shopName = $shop['shop_name'];
                    $companyName = $shopName;
                }
                if ($shop['shop_logo']) {
                    $shopLogo = $shop['shop_logo'];
                }
            }
        } catch (Exception $e2) {}
    } else {
        $lineAccountId = 1;
    }
} catch (Exception $e) {
    error_log("liff/index.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

$baseUrl = rtrim(BASE_URL, '/');

// Page configuration
$pages = [
    'home' => ['title' => 'หน้าหลัก', 'icon' => 'fa-home'],
    'shop' => ['title' => 'ร้านค้า', 'icon' => 'fa-store'],
    'cart' => ['title' => 'ตะกร้า', 'icon' => 'fa-shopping-cart'],
    'checkout' => ['title' => 'ชำระเงิน', 'icon' => 'fa-credit-card'],
    'orders' => ['title' => 'ออเดอร์ของฉัน', 'icon' => 'fa-box'],
    'member' => ['title' => 'บัตรสมาชิก', 'icon' => 'fa-id-card'],
    'points' => ['title' => 'ประวัติแต้ม', 'icon' => 'fa-coins'],
    'redeem' => ['title' => 'แลกแต้ม', 'icon' => 'fa-gift'],
    'appointments' => ['title' => 'นัดหมาย', 'icon' => 'fa-calendar-check'],
    'video-call' => ['title' => 'ปรึกษาเภสัชกร', 'icon' => 'fa-video'],
    'profile' => ['title' => 'โปรไฟล์', 'icon' => 'fa-user'],
    'wishlist' => ['title' => 'รายการโปรด', 'icon' => 'fa-heart'],
    'coupons' => ['title' => 'คูปอง', 'icon' => 'fa-ticket'],
    'health-profile' => ['title' => 'ข้อมูลสุขภาพ', 'icon' => 'fa-heartbeat'],
    'notifications' => ['title' => 'การแจ้งเตือน', 'icon' => 'fa-bell'],
];

$currentPage = $pages[$page] ?? $pages['home'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#11B0A6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($currentPage['title']) ?> - <?= htmlspecialchars($shopName) ?></title>
    
    <!-- LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- App Styles -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/liff/assets/css/liff-app.css?v=20241228f">
</head>
<body>
    <!-- Loading Overlay - Shows immediately (Requirement 1.1) -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <p class="loading-text">กำลังโหลด...</p>
    </div>

    <!-- App Shell -->
    <div id="app" class="app-shell hidden">
        <!-- Header (dynamic per page) -->
        <header id="app-header" class="app-header"></header>
        
        <!-- Main Content Area -->
        <main id="app-content" class="app-content">
            <!-- Page content will be rendered here by router -->
        </main>
        
        <!-- Bottom Navigation -->
        <nav id="bottom-nav" class="bottom-nav">
            <a href="#/" class="nav-item" data-page="home">
                <i class="fas fa-home"></i>
                <span>หน้าหลัก</span>
            </a>
            <a href="#/shop" class="nav-item" data-page="shop">
                <i class="fas fa-store"></i>
                <span>ร้านค้า</span>
            </a>
            <a href="#/cart" class="nav-item" data-page="cart">
                <i class="fas fa-shopping-cart"></i>
                <span>ตะกร้า</span>
                <span id="cart-badge" class="nav-badge hidden">0</span>
            </a>
            <a href="#/orders" class="nav-item" data-page="orders">
                <i class="fas fa-box"></i>
                <span>ออเดอร์</span>
            </a>
            <a href="#/profile" class="nav-item" data-page="profile">
                <i class="fas fa-user"></i>
                <span>โปรไฟล์</span>
            </a>
        </nav>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Modal Container -->
    <div id="modal-container" class="modal-container hidden"></div>

    <!-- App Configuration -->
    <script>
        // Debug helper - show messages on screen for mobile debugging
        window.debugLog = function(msg, type) {
            type = type || 'info';
            var colors = { info: '#2196F3', error: '#f44336', warn: '#ff9800', success: '#4CAF50' };
            var debugDiv = document.getElementById('debug-log');
            if (!debugDiv) {
                debugDiv = document.createElement('div');
                debugDiv.id = 'debug-log';
                debugDiv.style.cssText = 'position:fixed;bottom:60px;left:0;right:0;max-height:200px;overflow-y:auto;background:rgba(0,0,0,0.9);color:white;padding:5px;z-index:99999;font-size:10px;font-family:monospace;';
                document.body.appendChild(debugDiv);
            }
            var line = document.createElement('div');
            line.style.cssText = 'padding:2px 5px;border-left:3px solid ' + (colors[type] || colors.info) + ';margin:2px 0;';
            line.textContent = new Date().toLocaleTimeString() + ' [' + type.toUpperCase() + '] ' + msg;
            debugDiv.appendChild(line);
            debugDiv.scrollTop = debugDiv.scrollHeight;
        };
        
        // Global error handler to catch JS errors
        window.onerror = function(msg, url, line, col, error) {
            var errorDiv = document.createElement('div');
            errorDiv.style.cssText = 'position:fixed;top:0;left:0;right:0;background:red;color:white;padding:10px;z-index:99999;font-size:12px;';
            errorDiv.innerHTML = 'JS Error: ' + msg + '<br>File: ' + url + '<br>Line: ' + line;
            document.body.appendChild(errorDiv);
            window.debugLog('ERROR: ' + msg + ' at ' + url + ':' + line, 'error');
            console.error('Global error:', msg, url, line, col, error);
            return false;
        };
        
        // Catch unhandled promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            window.debugLog('Promise Error: ' + (event.reason?.message || event.reason), 'error');
        });
        
        window.APP_CONFIG = {
            BASE_URL: '<?= $baseUrl ?>',
            LIFF_ID: '<?= $liffId ?>',
            ACCOUNT_ID: <?= (int)$lineAccountId ?>,
            INITIAL_PAGE: '<?= $page ?>',
            SHOP_NAME: '<?= addslashes($shopName) ?>',
            SHOP_LOGO: '<?= addslashes($shopLogo) ?>',
            COMPANY_NAME: '<?= addslashes($companyName) ?>',
            LIFF_TO_ACCOUNT: <?= json_encode($liffToAccountMap) ?>
        };
        console.log('APP_CONFIG loaded:', window.APP_CONFIG);
    </script>

    <!-- App Scripts -->
    <?php $v = '20241228ad'; // Cache bust version ?>
    <script src="<?= $baseUrl ?>/liff/assets/js/store.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/router.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/skeleton.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/lazy-image.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/drug-interaction.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/prescription-handler.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/permission-checker.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/video-call.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/liff-message-bridge.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/bottom-nav.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/page-transition.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/ai-chat.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/components/health-profile.js?v=<?= $v ?>"></script>
    <script src="<?= $baseUrl ?>/liff/assets/js/liff-app.js?v=<?= $v ?>"></script>
</body>
</html>
