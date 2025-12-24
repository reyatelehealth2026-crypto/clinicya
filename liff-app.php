<?php
/**
 * Unified LIFF App - ระบบ LIFF รวมศูนย์ (Telecare Style)
 * ใช้ LIFF ID เดียว แต่ route ไปหน้าต่างๆ ตาม parameter
 * 
 * Usage: https://liff.line.me/{LIFF_ID}?page=shop
 * Pages: home, shop, checkout, orders, points, redeem, appointments, profile
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Get parameters
$page = $_GET['page'] ?? 'home';
$lineAccountId = $_GET['account'] ?? 1;

// Include LIFF helper
if (file_exists('includes/liff-helper.php')) {
    require_once 'includes/liff-helper.php';
}

// Get LIFF ID and shop settings
$liffId = '';
$shopName = 'ร้านค้า';
$shopLogo = '';
$companyName = 'MedCare';

try {
    $stmt = $db->prepare("SELECT la.*, ss.shop_name, ss.logo_url 
        FROM line_accounts la 
        LEFT JOIN shop_settings ss ON la.id = ss.line_account_id
        WHERE la.id = ? OR la.is_default = 1 
        ORDER BY (la.id = ?) DESC, la.is_default DESC LIMIT 1");
    $stmt->execute([$lineAccountId, $lineAccountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        $lineAccountId = $account['id'];
        $liffId = $account['liff_id'] ?? '';
        $shopName = $account['shop_name'] ?: $account['name'];
        $companyName = $shopName;
        $shopLogo = $account['logo_url'] ?? '';
    }
} catch (Exception $e) {}

$baseUrl = rtrim(BASE_URL, '/');

// Page titles and icons
$pages = [
    'home' => ['title' => 'หน้าหลัก', 'icon' => 'fa-home'],
    'shop' => ['title' => 'ร้านค้า', 'icon' => 'fa-store'],
    'checkout' => ['title' => 'ตะกร้า', 'icon' => 'fa-shopping-cart'],
    'orders' => ['title' => 'ออเดอร์ของฉัน', 'icon' => 'fa-box'],
    'points' => ['title' => 'ประวัติแต้ม', 'icon' => 'fa-coins'],
    'redeem' => ['title' => 'แลกแต้ม', 'icon' => 'fa-gift'],
    'appointments' => ['title' => 'นัดหมาย', 'icon' => 'fa-calendar-check'],
    'my-appointments' => ['title' => 'นัดหมายของฉัน', 'icon' => 'fa-calendar'],
    'profile' => ['title' => 'โปรไฟล์', 'icon' => 'fa-user'],
    'register' => ['title' => 'สมัครสมาชิก', 'icon' => 'fa-user-plus'],
];

$currentPage = $pages[$page] ?? $pages['home'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($currentPage['title']) ?> - <?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #11B0A6;
            --secondary-color: #FF5A5F;
            --bg-light: #F8FAFC;
        }
        body { 
            font-family: 'Sarabun', -apple-system, sans-serif; 
            background-color: var(--bg-light);
            color: #334155;
            -webkit-tap-highlight-color: transparent;
            min-height: 100vh;
        }
        
        .loading-overlay {
            position: fixed; inset: 0; background: white; z-index: 9999;
            display: flex; align-items: center; justify-content: center; flex-direction: column;
        }
        .spinner { width: 40px; height: 40px; border: 3px solid #e5e7eb; border-top-color: #11B0A6; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Skeleton Loading */
        .skeleton { 
            animation: pulse 2s infinite; 
            background: linear-gradient(90deg, rgba(255,255,255,0.1) 25%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0.1) 75%); 
            background-size: 200% 100%; 
        }
        @keyframes pulse { 
            0% { background-position: 200% 0; } 
            100% { background-position: -200% 0; } 
        }
        
        /* Service Grid Styles */
        .service-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 0;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .service-btn:active { transform: scale(0.92); }
        .icon-box {
            width: 62px;
            height: 62px;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        
        /* Doctor Card */
        .doctor-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border: 1px solid #F1F5F9;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .doctor-card:active { transform: scale(0.98); }
        .rating-badge {
            position: absolute;
            bottom: -4px;
            right: -4px;
            background: var(--secondary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: bold;
        }
        
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 12px 0 max(12px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
            z-index: 50;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #94A3B8;
            font-size: 10px;
            text-decoration: none;
            cursor: pointer;
        }
        .nav-item.active { color: var(--primary-color); }
        .nav-item i { font-size: 20px; margin-bottom: 2px; }
        
        .content-area { padding-bottom: 80px; }
        
        /* Auto-resize text */
        .auto-text-size {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            max-width: 100%;
        }
        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body class="pb-24">

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="spinner mb-4"></div>
    <p class="text-gray-500">กำลังโหลด...</p>
</div>

<!-- Main Content -->
<div id="mainContent" class="content-area hidden">

<!-- Header & Member Card Section -->
<div class="bg-white px-4 pt-6 pb-6 rounded-b-[32px] shadow-sm mb-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <?php if ($shopLogo): ?>
            <img src="<?= htmlspecialchars($shopLogo) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-100">
            <?php else: ?>
            <div class="w-10 h-10 rounded-full bg-teal-500 flex items-center justify-center text-white">
                <i class="fas fa-plus-circle"></i>
            </div>
            <?php endif; ?>
            <div>
                <h1 class="font-bold text-gray-800 text-lg leading-none mb-1"><?= htmlspecialchars($companyName) ?></h1>
                <p class="text-[11px] text-gray-400 font-medium uppercase tracking-wider">Health & Wellness</p>
            </div>
        </div>
        <div class="flex gap-4 text-gray-400">
            <i class="far fa-bell text-xl cursor-pointer hover:text-gray-600" onclick="sendMessage('แจ้งเตือน')"></i>
            <i class="far fa-comment-dots text-xl cursor-pointer hover:text-gray-600" onclick="openChat()"></i>
        </div>
    </div>

    <!-- Member Card - Telecare Style -->
    <div id="memberCard" class="relative overflow-hidden" style="border-radius: 20px; box-shadow: 0 8px 30px rgba(0,0,0,0.12);">
        <!-- Loading Skeleton -->
        <div id="cardSkeleton" class="p-5" style="background: linear-gradient(135deg, #87CEEB 0%, #5BB5E0 50%, #E8F4F8 50%, #FFFFFF 100%);">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-20 h-20 skeleton rounded-full bg-white/30"></div>
                <div class="flex-1">
                    <div class="h-6 w-40 skeleton rounded mb-2 bg-white/30"></div>
                    <div class="h-4 w-32 skeleton rounded bg-white/30"></div>
                </div>
            </div>
        </div>
        
        <!-- Card Content (Registered Member) -->
        <div id="cardContent" class="hidden">
            <div class="flex" style="min-height: 180px;">
                <!-- Left side - Blue gradient with mascot -->
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <div class="absolute bottom-0 left-2 right-0">
                        <img id="mascotImage" src="<?= $baseUrl ?>/assets/images/telecare-mascot.png" 
                             onerror="this.src='https://cdn-icons-png.flaticon.com/512/4712/4712109.png'" 
                             class="w-28 h-auto object-contain" style="filter: drop-shadow(2px 4px 6px rgba(0,0,0,0.2));">
                    </div>
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight" id="companyTitle"><?= htmlspecialchars($companyName) ?></h2>
                        <p class="text-xs opacity-90" id="companySubtitle">Health Care</p>
                    </div>
                </div>
                
                <!-- Right side - White/Light blue with card info -->
                <div class="w-3/5 p-4" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <div class="flex items-center gap-2 mb-4">
                        <h3 class="font-bold text-gray-700 text-lg">MEMBER CARD</h3>
                        <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                <path d="M7 10h10M12 7v6" stroke="white" stroke-width="1.5" fill="none"/>
                            </svg>
                        </div>
                    </div>
                    
                    <div class="space-y-2">
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">Name</span>
                            <span class="text-gray-800 font-semibold flex-1 border-b border-gray-300 pb-1 ml-2 truncate auto-text-size" id="memberName" style="font-size: 14px;">-</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">ID</span>
                            <span class="text-gray-800 font-mono text-sm flex-1 border-b border-gray-300 pb-1 ml-2" id="memberId">-</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">Expiry</span>
                            <span class="text-gray-800 text-sm flex-1 border-b border-gray-300 pb-1 ml-2" id="memberExpiry">-</span>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
                                <i class="fas fa-coins text-amber-500 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-400 leading-none">Points</p>
                                <p class="font-bold text-amber-600" id="memberPoints">0</p>
                            </div>
                        </div>
                        <span class="text-[10px] px-2 py-1 rounded-full font-bold" id="tierBadge" style="background: linear-gradient(135deg, #FFD700, #FFA500); color: white;">GOLD</span>
                    </div>
                </div>
            </div>
            
            <div class="px-4 pb-3 pt-2" style="background: #FFFFFF;">
                <div class="bg-gray-100 rounded-full h-1.5 overflow-hidden">
                    <div id="progressBar" class="h-full rounded-full transition-all duration-1000" style="width: 0%; background: linear-gradient(90deg, #5BB5E0, #11B0A6);"></div>
                </div>
                <p class="text-[9px] text-gray-400 text-right mt-1" id="nextTierLabel">Loading...</p>
            </div>
        </div>
        
        <!-- Not Registered Card -->
        <div id="notRegisteredCard" class="hidden">
            <div class="flex" style="min-height: 180px;">
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <div class="absolute bottom-0 left-2 right-0">
                        <img src="https://cdn-icons-png.flaticon.com/512/4712/4712109.png" class="w-28 h-auto object-contain opacity-50">
                    </div>
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight"><?= htmlspecialchars($companyName) ?></h2>
                        <p class="text-xs opacity-90">Health Care</p>
                    </div>
                </div>
                <div class="w-3/5 p-4 flex flex-col justify-center" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <div class="text-center">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                            <i class="fas fa-user-plus text-xl text-gray-400"></i>
                        </div>
                        <p class="text-gray-500 text-sm mb-3">ลงทะเบียนเพื่อรับสิทธิพิเศษ</p>
                        <button onclick="openRegister()" class="px-4 py-2 bg-gradient-to-r from-teal-500 to-cyan-500 text-white rounded-xl font-bold text-sm shadow-md">
                            ลงทะเบียนเลย
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Guest Card (Not logged in) -->
        <div id="guestCard" class="hidden">
            <div class="flex" style="min-height: 180px;">
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <div class="absolute bottom-0 left-2 right-0">
                        <img src="https://cdn-icons-png.flaticon.com/512/4712/4712109.png" class="w-28 h-auto object-contain opacity-50">
                    </div>
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight"><?= htmlspecialchars($companyName) ?></h2>
                        <p class="text-xs opacity-90">Health Care</p>
                    </div>
                </div>
                <div class="w-3/5 p-4 flex flex-col justify-center" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <div class="text-center">
                        <p class="text-gray-500 text-sm mb-3">เข้าสู่ระบบเพื่อดูบัตรสมาชิก</p>
                        <button onclick="liffLogin()" class="px-4 py-2 bg-green-500 text-white rounded-xl font-bold text-sm shadow-md flex items-center justify-center gap-2 mx-auto">
                            <i class="fab fa-line"></i> เข้าสู่ระบบ LINE
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Service Grid (6 เมนู) -->
<div class="px-4 mb-6">
    <div class="grid grid-cols-3 gap-y-6 gap-x-3">
        <div onclick="goToPage('shop')" class="service-btn">
            <div class="icon-box bg-emerald-50 text-emerald-500">
                <i class="fas fa-store text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ร้านค้า</span>
        </div>
        
        <div onclick="goToPage('checkout')" class="service-btn">
            <div class="icon-box bg-orange-50 text-orange-500 relative">
                <i class="fas fa-shopping-cart text-2xl"></i>
                <span id="cartBadge" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full items-center justify-center font-bold hidden">0</span>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ตะกร้า</span>
        </div>
        
        <div onclick="goToPage('orders')" class="service-btn">
            <div class="icon-box bg-blue-50 text-blue-500">
                <i class="fas fa-box-open text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ออเดอร์</span>
        </div>
        
        <div onclick="openAIAssistant()" class="service-btn">
            <div class="icon-box bg-gradient-to-br from-violet-500 to-purple-600 text-white relative">
                <i class="fas fa-robot text-2xl"></i>
                <span class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></span>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ผู้ช่วย AI</span>
        </div>
        
        <div onclick="goToPage('symptom')" class="service-btn">
            <div class="icon-box bg-rose-50 text-rose-500">
                <i class="fas fa-stethoscope text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ประเมินอาการ</span>
        </div>
        
        <div onclick="goToPage('redeem')" class="service-btn">
            <div class="icon-box bg-purple-50 text-purple-500">
                <i class="fas fa-gift text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">แลกแต้ม</span>
        </div>
    </div>
</div>

<!-- AI Assistant Quick Actions -->
<div class="px-4 mb-6">
    <div class="bg-gradient-to-r from-violet-500 to-purple-600 rounded-2xl p-4 text-white">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                <i class="fas fa-robot text-xl"></i>
            </div>
            <div>
                <h3 class="font-bold">ผู้ช่วย AI ร้านยา</h3>
                <p class="text-xs text-white/80">ถามเรื่องยาและสุขภาพได้เลย</p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <button onclick="askAI('ปวดหัว')" class="bg-white/20 hover:bg-white/30 rounded-xl py-2 px-3 text-sm text-left transition">
                <i class="fas fa-head-side-virus mr-2"></i>ปวดหัว
            </button>
            <button onclick="askAI('ไข้หวัด')" class="bg-white/20 hover:bg-white/30 rounded-xl py-2 px-3 text-sm text-left transition">
                <i class="fas fa-thermometer-half mr-2"></i>ไข้หวัด
            </button>
            <button onclick="askAI('ปวดท้อง')" class="bg-white/20 hover:bg-white/30 rounded-xl py-2 px-3 text-sm text-left transition">
                <i class="fas fa-stomach mr-2"></i>ปวดท้อง
            </button>
            <button onclick="askAI('แพ้อากาศ')" class="bg-white/20 hover:bg-white/30 rounded-xl py-2 px-3 text-sm text-left transition">
                <i class="fas fa-allergies mr-2"></i>แพ้อากาศ
            </button>
        </div>
        <button onclick="openAIAssistant()" class="w-full mt-3 bg-white text-purple-600 font-bold py-2.5 rounded-xl hover:bg-gray-100 transition">
            <i class="fas fa-comment-medical mr-2"></i>พิมพ์ถามอาการอื่นๆ
        </button>
    </div>
</div>

<!-- Available Pharmacists Today -->
<div class="px-4 py-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-gray-800 text-lg flex items-center">
            <i class="fas fa-user-md text-teal-500 mr-2"></i>เภสัชกรว่างวันนี้
        </h2>
        <a onclick="goToPage('appointments')" class="text-sm text-teal-600 font-medium cursor-pointer">ดูทั้งหมด</a>
    </div>
    <div id="pharmacistsList" class="space-y-3">
        <div class="text-center py-8 text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p>กำลังโหลด...</p>
        </div>
    </div>
</div>

</div>

<!-- Bottom Navigation -->
<nav class="bottom-nav">
    <a class="nav-item active">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
    </a>
    <a class="nav-item" onclick="goToPage('my-appointments')">
        <i class="far fa-calendar-alt"></i>
        <span>นัดหมาย</span>
    </a>
    <a class="nav-item" onclick="goToPage('shop')">
        <i class="fas fa-shopping-basket"></i>
        <span>ร้านยา</span>
    </a>
    <a class="nav-item" onclick="goToPage('profile')">
        <i class="far fa-user"></i>
        <span>โปรไฟล์</span>
    </a>
</nav>

<script>
const CONFIG = {
    BASE_URL: '<?= $baseUrl ?>',
    LIFF_ID: '<?= $liffId ?>',
    ACCOUNT_ID: <?= (int)$lineAccountId ?>,
    CURRENT_PAGE: '<?= $page ?>',
    SHOP_NAME: '<?= addslashes($shopName) ?>',
    SHOP_LOGO: '<?= addslashes($shopLogo) ?>'
};

// Debug: แสดง config
console.log('🔧 CONFIG:', CONFIG);
console.log('🔧 LIFF_ID:', CONFIG.LIFF_ID || 'NOT SET');

let liffProfile = null;
let memberData = null;
let userId = null;

// Initialize LIFF
document.addEventListener('DOMContentLoaded', initLiff);

async function initLiff() {
    if (!CONFIG.LIFF_ID) {
        console.log('No LIFF ID configured');
        showGuestCard();
        hideLoading();
        loadAvailablePharmacists();
        return;
    }
    
    try {
        await liff.init({ liffId: CONFIG.LIFF_ID });
        console.log('LIFF initialized, isLoggedIn:', liff.isLoggedIn(), 'isInClient:', liff.isInClient());
        
        if (!liff.isLoggedIn()) {
            // ถ้าอยู่ใน LINE app ให้ login อัตโนมัติ
            if (liff.isInClient()) {
                liff.login();
                return;
            }
            // ถ้าอยู่ใน browser ปกติ ให้แสดง Guest Card แทน (ไม่ auto login)
            console.log('Not logged in, showing guest card');
            showGuestCard();
            hideLoading();
            loadAvailablePharmacists();
            return;
        }
        
        // Get profile
        liffProfile = await liff.getProfile();
        userId = liffProfile.userId;
        console.log('Got profile:', liffProfile.displayName);
        
        // Load member data
        await loadMemberData();
        
        hideLoading();
        
        // Handle page routing
        if (CONFIG.CURRENT_PAGE !== 'home') {
            routeToPage(CONFIG.CURRENT_PAGE);
        }
        
    } catch (e) {
        console.error('LIFF init error:', e);
        showGuestCard();
        hideLoading();
        loadAvailablePharmacists();
    }
}

async function loadMemberData() {
    try {
        console.log('Loading member data for userId:', userId, 'accountId:', CONFIG.ACCOUNT_ID);
        const response = await fetch(`${CONFIG.BASE_URL}/api/member.php?action=get_card&line_user_id=${userId}&line_account_id=${CONFIG.ACCOUNT_ID}`);
        const data = await response.json();
        console.log('Member API response:', data);
        
        if (data.success && data.member && data.member.is_registered && data.member.first_name) {
            // สมัครแล้วและมีข้อมูลครบ → แสดงบัตรสมาชิก
            memberData = data;
            renderCard(data);
        } else {
            // ยังไม่สมัคร หรือยังไม่ได้กรอกข้อมูล → ไปหน้าสมัครสมาชิก
            console.log('Not registered, redirecting to register page...');
            window.location.href = `${CONFIG.BASE_URL}/liff-register.php?account=${CONFIG.ACCOUNT_ID}`;
            return;
        }
    } catch (e) {
        console.error('Load member error:', e);
        // Error → ไปหน้าสมัครสมาชิก
        window.location.href = `${CONFIG.BASE_URL}/liff-register.php?account=${CONFIG.ACCOUNT_ID}`;
        return;
    }
    
    // Load pharmacists after member data
    loadAvailablePharmacists();
}

function renderCard(data) {
    const { member, tier, next_tier } = data;
    
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.remove('hidden');
    document.getElementById('notRegisteredCard').classList.add('hidden');
    document.getElementById('guestCard').classList.add('hidden');
    
    // Update member info
    const fullName = (member.first_name || liffProfile?.displayName || '-') + (member.last_name ? ' ' + member.last_name : '');
    const nameEl = document.getElementById('memberName');
    nameEl.textContent = fullName;
    
    // Auto-resize name text to fit
    autoResizeText(nameEl, 14, 10);
    
    document.getElementById('memberId').textContent = member.member_id || '000000';
    document.getElementById('memberPoints').textContent = numberFormat(member.points || 0);
    
    // Expiry date
    if (member.expiry_date) {
        const expiry = new Date(member.expiry_date);
        document.getElementById('memberExpiry').textContent = expiry.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } else if (member.created_at) {
        const created = new Date(member.created_at);
        created.setFullYear(created.getFullYear() + 1);
        document.getElementById('memberExpiry').textContent = created.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } else {
        document.getElementById('memberExpiry').textContent = '-';
    }
    
    // Tier badge
    const tierBadge = document.getElementById('tierBadge');
    const tierName = tier?.tier_name || 'MEMBER';
    tierBadge.textContent = tierName.toUpperCase();
    
    const tierColors = {
        'platinum': 'linear-gradient(135deg, #334155, #0F172A)',
        'gold': 'linear-gradient(135deg, #FFD700, #FFA500)',
        'silver': 'linear-gradient(135deg, #C0C0C0, #A0A0A0)',
        'bronze': 'linear-gradient(135deg, #CD7F32, #8B4513)',
        'vip': 'linear-gradient(135deg, #9333EA, #6B21A8)'
    };
    const tierCode = tier?.tier_code || 'gold';
    tierBadge.style.background = tierColors[tierCode] || tierColors['gold'];
    
    // Progress
    if (next_tier && tier) {
        const progress = ((member.points - tier.min_points) / (next_tier.min_points - tier.min_points)) * 100;
        document.getElementById('progressBar').style.width = Math.min(100, progress) + '%';
        document.getElementById('nextTierLabel').textContent = `อีก ${numberFormat(next_tier.min_points - member.points)} แต้มถึง ${next_tier.tier_name}`;
    } else {
        document.getElementById('progressBar').style.width = '100%';
        document.getElementById('nextTierLabel').textContent = 'ระดับสูงสุดแล้ว 🎉';
    }
}

function showNotRegisteredCard() {
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.add('hidden');
    document.getElementById('guestCard').classList.add('hidden');
    document.getElementById('notRegisteredCard').classList.remove('hidden');
}

function showGuestCard() {
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.add('hidden');
    document.getElementById('notRegisteredCard').classList.add('hidden');
    document.getElementById('guestCard').classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('hidden');
    document.getElementById('mainContent').classList.remove('hidden');
}

function showError(msg) {
    document.getElementById('loadingOverlay').innerHTML = `
        <div class="text-center p-8">
            <i class="fas fa-exclamation-circle text-5xl text-red-400 mb-4"></i>
            <p class="text-gray-600">${msg}</p>
            <button onclick="location.reload()" class="mt-4 px-6 py-2 bg-teal-500 text-white rounded-lg">ลองใหม่</button>
        </div>
    `;
}

// Page routing
function goToPage(page) {
    routeToPage(page);
}

function routeToPage(page) {
    const routes = {
        'shop': `${CONFIG.BASE_URL}/liff-shop.php?account=${CONFIG.ACCOUNT_ID}`,
        'checkout': `${CONFIG.BASE_URL}/liff-checkout.php?account=${CONFIG.ACCOUNT_ID}`,
        'orders': `${CONFIG.BASE_URL}/liff-my-orders.php?account=${CONFIG.ACCOUNT_ID}`,
        'points': `${CONFIG.BASE_URL}/liff-points-history.php?account=${CONFIG.ACCOUNT_ID}`,
        'redeem': `${CONFIG.BASE_URL}/liff-redeem-points.php?account=${CONFIG.ACCOUNT_ID}`,
        'appointments': `${CONFIG.BASE_URL}/liff-appointment.php?account=${CONFIG.ACCOUNT_ID}`,
        'my-appointments': `${CONFIG.BASE_URL}/liff-my-appointments.php?account=${CONFIG.ACCOUNT_ID}`,
        'profile': `${CONFIG.BASE_URL}/liff-member-card.php?account=${CONFIG.ACCOUNT_ID}`,
        'register': `${CONFIG.BASE_URL}/liff-register.php?account=${CONFIG.ACCOUNT_ID}`,
        'symptom': `${CONFIG.BASE_URL}/liff-symptom-assessment.php?account=${CONFIG.ACCOUNT_ID}`
    };
    
    if (routes[page]) {
        window.location.replace(routes[page]);
    }
}

// AI Assistant Functions
function openAIAssistant() {
    Swal.fire({
        title: '<i class="fas fa-robot text-purple-500"></i> ผู้ช่วย AI ร้านยา',
        html: `
            <p class="text-gray-500 text-sm mb-4">พิมพ์อาการหรือคำถามเกี่ยวกับยา</p>
            <input type="text" id="aiQuestion" class="swal2-input" placeholder="เช่น ปวดหัว มีไข้ ไอ...">
            <div class="mt-3 flex flex-wrap gap-2 justify-center">
                <button onclick="setAIQuestion('ปวดหัวไมเกรน')" class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm">ปวดหัวไมเกรน</button>
                <button onclick="setAIQuestion('ท้องเสีย')" class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm">ท้องเสีย</button>
                <button onclick="setAIQuestion('นอนไม่หลับ')" class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm">นอนไม่หลับ</button>
                <button onclick="setAIQuestion('ปวดกล้ามเนื้อ')" class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm">ปวดกล้ามเนื้อ</button>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane mr-1"></i> ถาม AI',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#8B5CF6',
        preConfirm: () => {
            const question = document.getElementById('aiQuestion').value.trim();
            if (!question) {
                Swal.showValidationMessage('กรุณาพิมพ์อาการหรือคำถาม');
                return false;
            }
            return question;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            askAI(result.value);
        }
    });
}

function setAIQuestion(text) {
    document.getElementById('aiQuestion').value = text;
}

async function askAI(symptom) {
    Swal.fire({
        title: 'กำลังค้นหา...',
        html: '<i class="fas fa-spinner fa-spin text-3xl text-purple-500"></i><p class="mt-2">AI กำลังวิเคราะห์อาการ</p>',
        showConfirmButton: false,
        allowOutsideClick: false
    });
    
    try {
        // ส่งข้อความไปยัง LINE Chat (ผ่าน webhook จะตอบกลับด้วย AI)
        if (typeof liff !== 'undefined' && liff.isInClient()) {
            await liff.sendMessages([{
                type: 'text',
                text: `ช่วยหายาสำหรับอาการ: ${symptom}`
            }]);
            
            Swal.fire({
                icon: 'success',
                title: 'ส่งคำถามแล้ว!',
                html: `<p class="text-gray-600">AI จะตอบกลับในแชท LINE</p>
                       <p class="text-sm text-gray-400 mt-2">อาการ: ${symptom}</p>`,
                confirmButtonText: 'ดูในแชท',
                confirmButtonColor: '#06C755'
            }).then(() => {
                liff.closeWindow();
            });
        } else {
            // ถ้าไม่ได้อยู่ใน LINE ให้ไปหน้า symptom assessment
            Swal.fire({
                icon: 'info',
                title: 'ประเมินอาการ',
                html: `<p class="text-gray-600">ไปหน้าประเมินอาการเพื่อรับคำแนะนำ</p>`,
                confirmButtonText: 'ไปประเมินอาการ',
                confirmButtonColor: '#8B5CF6'
            }).then(() => {
                window.location.href = `${CONFIG.BASE_URL}/liff-symptom-assessment.php?account=${CONFIG.ACCOUNT_ID}&symptom=${encodeURIComponent(symptom)}`;
            });
        }
    } catch (e) {
        console.error('AI error:', e);
        Swal.fire({
            icon: 'error',
            title: 'เกิดข้อผิดพลาด',
            text: 'ไม่สามารถส่งคำถามได้ กรุณาลองใหม่',
            confirmButtonColor: '#EF4444'
        });
    }
}

async function loadAvailablePharmacists() {
    const listContainer = document.getElementById('pharmacistsList');
    if (!listContainer) return;
    
    try {
        const response = await fetch(`${CONFIG.BASE_URL}/api/appointments.php?action=pharmacists&line_account_id=${CONFIG.ACCOUNT_ID}`);
        const data = await response.json();
        console.log('Pharmacists API response:', data);
        
        if (data.success && data.pharmacists && data.pharmacists.length > 0) {
            const available = data.pharmacists.filter(p => p.is_available == 1 || p.is_available === true || p.is_available === '1');
            const pharmacists = available.length > 0 ? available : data.pharmacists;
            
            let html = '';
            pharmacists.slice(0, 5).forEach(p => {
                const isAvailable = p.is_available == 1 || p.is_available === true || p.is_available === '1';
                html += `
                    <div class="doctor-card ${!isAvailable ? 'opacity-60' : ''}" onclick="${isAvailable ? `goToPage('appointments')` : ''}">
                        <div class="flex gap-3">
                            <div class="relative">
                                <img src="${p.image_url || 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + p.id}" 
                                    class="w-14 h-14 rounded-full object-cover bg-gray-100">
                                <span class="rating-badge">${p.rating || '5.0'}</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-bold text-gray-800">${p.title || ''}${p.name || ''}</h3>
                                <p class="text-xs text-gray-500">${p.specialty || 'เภสัชกรทั่วไป'}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs text-gray-400"><i class="fas fa-clock mr-1"></i>${p.consultation_duration || 15} นาที</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-teal-600">${p.consultation_fee > 0 ? '฿' + numberFormat(p.consultation_fee) : 'ฟรี'}</p>
                                ${!isAvailable ? '<span class="text-xs text-red-500">ไม่ว่าง</span>' : '<span class="text-xs text-green-500">ว่าง</span>'}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            listContainer.innerHTML = html;
        } else {
            listContainer.innerHTML = `
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-user-md text-3xl mb-2"></i>
                    <p>ยังไม่มีเภสัชกรในระบบ</p>
                </div>
            `;
        }
    } catch (e) {
        console.error('Load pharmacists error:', e);
        listContainer.innerHTML = `
            <div class="text-center py-8 text-gray-400">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>ไม่สามารถโหลดข้อมูลได้</p>
            </div>
        `;
    }
}

function openRegister() {
    window.location.href = CONFIG.BASE_URL + '/liff-register.php?account=' + CONFIG.ACCOUNT_ID;
}

function liffLogin() {
    console.log('liffLogin called, LIFF_ID:', CONFIG.LIFF_ID);
    
    if (!CONFIG.LIFF_ID) {
        Swal.fire({
            icon: 'warning',
            title: 'ไม่พบ LIFF ID',
            text: 'กรุณาติดต่อผู้ดูแลระบบ',
            confirmButtonColor: '#11B0A6'
        });
        return;
    }
    
    // วิธีที่ดีที่สุดคือเปิด LIFF URL โดยตรง - จะ auto login
    const liffUrl = `https://liff.line.me/${CONFIG.LIFF_ID}`;
    console.log('Opening LIFF URL:', liffUrl);
    window.location.href = liffUrl;
}

function openChat() {
    if (liff.isInClient()) {
        liff.closeWindow();
    } else {
        Swal.fire({ 
            icon: 'info', 
            title: 'กรุณาเปิดผ่าน LINE App', 
            text: 'เพื่อแชทกับร้านค้า',
            confirmButtonColor: '#11B0A6' 
        });
    }
}

async function sendMessage(text) {
    if (liff.isInClient()) {
        try {
            await liff.sendMessages([{ type: 'text', text: text }]);
            liff.closeWindow();
        } catch (e) { 
            console.error(e); 
        }
    } else {
        Swal.fire({ 
            icon: 'info', 
            title: 'เปิดใน LINE', 
            text: `คุณกดเมนู: ${text}`, 
            confirmButtonColor: '#11B0A6' 
        });
    }
}

function numberFormat(num) {
    return new Intl.NumberFormat('th-TH').format(num);
}

// Auto-resize text to fit container without wrapping
function autoResizeText(element, maxSize = 14, minSize = 9) {
    if (!element) return;
    
    const parent = element.parentElement;
    if (!parent) return;
    
    element.style.fontSize = maxSize + 'px';
    
    const labelWidth = 56 + 8;
    const availableWidth = parent.offsetWidth - labelWidth - 4;
    
    let currentSize = maxSize;
    while (element.scrollWidth > availableWidth && currentSize > minSize) {
        currentSize -= 0.5;
        element.style.fontSize = currentSize + 'px';
    }
    
    if (element.scrollWidth > availableWidth) {
        element.style.textOverflow = 'ellipsis';
        element.style.overflow = 'hidden';
        element.style.whiteSpace = 'nowrap';
    }
}
</script>
</body>
</html>
