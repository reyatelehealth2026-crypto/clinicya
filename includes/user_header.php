<?php
/**
 * User Header - Header สำหรับผู้ใช้ทั่วไป (ไม่ใช่ Admin)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_check.php';

// User ต้องตั้งค่า LINE Account แล้ว
requireUserWithAccount();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$db = Database::getInstance()->getConnection();

// Get user's LINE account
$lineAccount = null;
if ($currentUser['line_account_id']) {
    $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ?");
    $stmt->execute([$currentUser['line_account_id']]);
    $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Set current bot id for queries
$currentBotId = $currentUser['line_account_id'];
$_SESSION['current_bot_id'] = $currentBotId;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?? APP_NAME ?></title>
    
    <!-- PWA Support -->
    <?php include __DIR__ . '/pwa_head.php'; ?>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { 
            font-family: 'Noto Sans Thai', sans-serif; 
            background: #f8fafc;
        }
        
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border-right: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .menu-section {
            padding: 16px 12px 8px;
        }
        
        .menu-section-title {
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 12px;
            margin-bottom: 8px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            margin: 2px 0;
            border-radius: 8px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .menu-item:hover {
            background: #f1f5f9;
            color: #334155;
        }
        
        .menu-item.active {
            background: linear-gradient(135deg, #06C755 0%, #00a040 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(6, 199, 85, 0.3);
        }
        
        .menu-item.active .menu-icon {
            color: white;
        }
        
        .menu-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 14px;
            color: #94a3b8;
        }
        
        .menu-item:hover .menu-icon {
            color: #06C755;
        }
        
        .menu-item.active:hover .menu-icon {
            color: white;
        }
        
        .bot-card {
            display: flex;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 10px;
            margin: 12px;
        }
        
        .bot-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #06C755 0%, #00a040 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            overflow: hidden;
        }
        
        .bot-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        
        .top-header {
            background: white;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -260px;
                top: 0;
                height: 100%;
                z-index: 50;
            }
            .sidebar.open {
                left: 0;
            }
            .mobile-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.5);
                z-index: 40;
            }
            .mobile-overlay.open {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div id="mobileOverlay" class="mobile-overlay" onclick="toggleMobileSidebar()"></div>
    
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar flex flex-col h-screen">
            <!-- Brand -->
            <div class="sidebar-brand flex items-center">
                <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center">
                    <i class="fab fa-line text-white text-xl"></i>
                </div>
                <div class="ml-3">
                    <div class="font-bold text-gray-800"><?= APP_NAME ?></div>
                    <div class="text-xs text-gray-400">User Panel</div>
                </div>
                <button onclick="toggleMobileSidebar()" class="ml-auto md:hidden text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Current LINE Account -->
            <?php if ($lineAccount): ?>
            <div class="bot-card">
                <div class="bot-avatar">
                    <?php if (!empty($lineAccount['picture_url'])): ?>
                    <img src="<?= htmlspecialchars($lineAccount['picture_url']) ?>">
                    <?php else: ?>
                    <i class="fab fa-line"></i>
                    <?php endif; ?>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <div class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($lineAccount['name']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($lineAccount['basic_id'] ?? '') ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto py-4">
                <div class="px-3">
                    <a href="dashboard.php" class="menu-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                        <span class="menu-icon"><i class="fas fa-th-large"></i></span>
                        Dashboard
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">จัดการ</div>
                    <div class="px-3">
                        <a href="../messages.php" class="menu-item <?= $currentPage === 'messages' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-inbox"></i></span>
                            ข้อความ
                        </a>
                        
                        <a href="users.php" class="menu-item <?= $currentPage === 'users' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-users"></i></span>
                            ลูกค้า
                        </a>
                        
                        <a href="../broadcast.php" class="menu-item <?= $currentPage === 'broadcast' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-bullhorn"></i></span>
                            Broadcast
                        </a>
                        
                        <a href="auto-reply.php" class="menu-item <?= $currentPage === 'auto-reply' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-robot"></i></span>
                            ตอบกลับอัตโนมัติ
                        </a>
                    </div>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">ร้านค้า</div>
                    <div class="px-3">
                        <a href="orders.php" class="menu-item <?= in_array($currentPage, ['orders', 'order-detail']) ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-receipt"></i></span>
                            คำสั่งซื้อ
                        </a>
                        
                        <a href="products.php" class="menu-item <?= $currentPage === 'products' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-box"></i></span>
                            สินค้า
                        </a>
                        
                        <a href="categories.php" class="menu-item <?= $currentPage === 'categories' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-folder"></i></span>
                            หมวดหมู่
                        </a>
                    </div>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">ตั้งค่า</div>
                    <div class="px-3">
                        <a href="settings-overview.php" class="menu-item <?= $currentPage === 'settings-overview' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-sliders-h"></i></span>
                            ภาพรวมการตั้งค่า
                        </a>
                        
                        <a href="welcome-settings.php" class="menu-item <?= $currentPage === 'welcome-settings' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-hand-wave"></i></span>
                            ข้อความต้อนรับ
                        </a>
                        
                        <a href="shop-settings.php" class="menu-item <?= $currentPage === 'shop-settings' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-store"></i></span>
                            ตั้งค่าร้านค้า
                        </a>
                        
                        <a href="rich-menu.php" class="menu-item <?= $currentPage === 'rich-menu' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-th-large"></i></span>
                            Rich Menu
                        </a>
                        
                        <a href="../analytics.php" class="menu-item <?= $currentPage === 'analytics' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-chart-bar"></i></span>
                            สถิติ
                        </a>
                        
                        <a href="help.php" class="menu-item <?= $currentPage === 'help' ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas fa-question-circle"></i></span>
                            คู่มือการใช้งาน
                        </a>
                    </div>
                </div>
            </nav>
            
            <!-- Footer -->
            <div class="p-4 border-t border-gray-100">
                <div class="text-xs text-gray-400 text-center">Version 2.5</div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <div class="flex items-center">
                    <button onclick="toggleMobileSidebar()" class="md:hidden mr-4 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-lg font-semibold text-gray-800"><?= $pageTitle ?? 'Dashboard' ?></h1>
                </div>
                
                <div class="flex items-center gap-3">
                    <div class="text-right mr-2 hidden sm:block">
                        <div class="text-sm font-medium text-gray-700"><?= htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']) ?></div>
                        <div class="text-xs text-gray-400">User</div>
                    </div>
                    <div class="relative" x-data="{ open: false }">
                        <button onclick="toggleUserMenu()" class="w-10 h-10 rounded-xl bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white font-semibold">
                            <?= strtoupper(substr($currentUser['display_name'] ?: $currentUser['username'], 0, 1)) ?>
                        </button>
                        <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-user mr-2 text-gray-400"></i>โปรไฟล์
                            </a>
                            <hr class="my-2">
                            <a href="../auth/logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <i class="fas fa-sign-out-alt mr-2"></i>ออกจากระบบ
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Content Area -->
            <div class="content-area">

<script>
function toggleMobileSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('mobileOverlay').classList.toggle('open');
}

function toggleUserMenu() {
    document.getElementById('userMenu').classList.toggle('hidden');
}

document.addEventListener('click', function(e) {
    const userMenu = document.getElementById('userMenu');
    if (userMenu && !e.target.closest('.relative')) {
        userMenu.classList.add('hidden');
    }
});
</script>
