<?php
/**
 * LIFF Register - หน้าสมัครสมาชิก + Consent รวมในหน้าเดียว
 * UI/UX Premium Design - รวม consent ไว้ในขั้นตอนสมัคร
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Get company info
$companyName = 'MedCare';
$companyLogo = '';
$lineAccountId = $_GET['account'] ?? 1;
$isEditMode = isset($_GET['edit']);

try {
    $stmt = $db->prepare("SELECT name FROM line_accounts WHERE id = ? OR is_default = 1 ORDER BY is_default DESC LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lineAccount) {
        $companyName = $lineAccount['name'];
    }
    
    $stmt = $db->prepare("SELECT shop_name, logo_url FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($shop) {
        $companyName = $shop['shop_name'] ?: $companyName;
        $companyLogo = $shop['logo_url'] ?? '';
    }
} catch (Exception $e) {}

// Get LIFF ID - Use Unified LIFF ID from liff-app
require_once 'includes/liff-helper.php';
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
$lineAccountId = $liffData['line_account_id'];

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $isEditMode ? 'แก้ไขข้อมูล' : 'สมัครสมาชิก' ?> - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }
        
        :root {
            --primary: #11B0A6;
            --primary-dark: #0D9488;
            --secondary: #667EEA;
            --accent: #FF6B6B;
        }
        
        body { 
            background: linear-gradient(180deg, #11B0A6 0%, #0D9488 50%, #F0FDFA 50%, #F8FAFC 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.2);
        }
        
        .input-modern {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #E5E7EB;
            border-radius: 14px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #F9FAFB;
        }
        
        .input-modern:focus {
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(17, 176, 166, 0.1);
            outline: none;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 16px;
            transition: color 0.3s;
        }
        
        .input-group:focus-within .input-icon {
            color: var(--primary);
        }
        
        .input-label {
            position: absolute;
            left: 48px;
            top: -8px;
            background: white;
            padding: 0 6px;
            font-size: 11px;
            color: #6B7280;
            font-weight: 500;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 16px 32px;
            border-radius: 16px;
            font-weight: 600;
            font-size: 17px;
            width: 100%;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px -10px rgba(17, 176, 166, 0.5);
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px -10px rgba(17, 176, 166, 0.6);
        }
        
        .btn-primary:disabled {
            background: #D1D5DB;
            box-shadow: none;
            transform: none;
            cursor: not-allowed;
        }
        
        .consent-card {
            background: #F8FAFC;
            border: 2px solid #E5E7EB;
            border-radius: 16px;
            padding: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .consent-card:hover {
            border-color: var(--primary);
            background: #F0FDFA;
        }
        
        .consent-card.checked {
            border-color: var(--primary);
            background: linear-gradient(135deg, #F0FDFA 0%, #CCFBF1 100%);
        }
        
        .consent-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid #D1D5DB;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .consent-card.checked .consent-checkbox {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .step-progress {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transition: all 0.3s;
        }
        
        .step-dot.active {
            width: 28px;
            border-radius: 5px;
            background: white;
        }
        
        .step-dot.completed {
            background: #34D399;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #374151;
            margin: 20px 0 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #F3F4F6;
        }
        
        .section-header i {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 10px;
            font-size: 14px;
        }
        
        .avatar-container {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            overflow: hidden;
            margin: 0 auto 12px;
        }
        
        .avatar-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        
        .input-unit {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 13px;
        }
        
        .error-shake { animation: shake 0.4s ease-in-out; }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }
        
        .fade-up {
            animation: fadeUp 0.5s ease-out;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .input-error {
            border-color: #EF4444 !important;
            background: #FEF2F2 !important;
        }
    </style>
</head>
<body class="min-h-screen pb-8">

    <!-- Header -->
    <div class="p-4 pt-6">
        <div class="flex items-center justify-between text-white">
            <button onclick="goBack()" class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center backdrop-blur-sm">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="step-progress">
                <div class="step-dot" id="step1"></div>
                <div class="step-dot active" id="step2"></div>
                <div class="step-dot" id="step3"></div>
            </div>
            <button onclick="closeLiff()" class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center backdrop-blur-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="px-4 fade-up">
        <div class="glass-card p-5 max-w-lg mx-auto">
            <!-- Avatar & Welcome -->
            <div class="text-center mb-5">
                <div class="avatar-container" id="avatarContainer">
                    <img id="userAvatar" src="https://via.placeholder.com/90/11B0A6/ffffff?text=?" alt="Avatar">
                </div>
                <h1 class="text-xl font-bold text-gray-800 mb-1" id="welcomeTitle">
                    <?= $isEditMode ? 'แก้ไขข้อมูลสมาชิก' : 'สมัครสมาชิก' ?>
                </h1>
                <p class="text-gray-500 text-sm" id="welcomeSubtitle">กรอกข้อมูลเพื่อรับสิทธิพิเศษมากมาย</p>
            </div>

            <!-- Registration Form -->
            <form id="registerForm">
                
                <?php if (!$isEditMode): ?>
                <!-- Consent Section - แสดงเฉพาะตอนสมัครใหม่ -->
                <div class="section-header">
                    <i class="fas fa-shield-alt"></i>
                    <span>ยอมรับข้อตกลง</span>
                    <span class="text-xs text-red-500 ml-auto">* จำเป็น</span>
                </div>
                
                <div class="space-y-3 mb-4">
                    <!-- Terms of Service -->
                    <div class="consent-card" onclick="toggleConsent('terms')">
                        <div class="flex items-start gap-3">
                            <div class="consent-checkbox" id="checkbox_terms">
                                <i class="fas fa-check text-white text-xs hidden"></i>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800 text-sm">📋 ข้อตกลงการใช้งาน</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    ฉันยอมรับ <a href="terms-of-service.php" target="_blank" class="text-teal-600 underline" onclick="event.stopPropagation()">ข้อตกลงการใช้งาน</a>
                                </p>
                            </div>
                        </div>
                        <input type="checkbox" id="consent_terms" class="hidden">
                    </div>

                    <!-- Privacy Policy -->
                    <div class="consent-card" onclick="toggleConsent('privacy')">
                        <div class="flex items-start gap-3">
                            <div class="consent-checkbox" id="checkbox_privacy">
                                <i class="fas fa-check text-white text-xs hidden"></i>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800 text-sm">🔒 นโยบายความเป็นส่วนตัว (PDPA)</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    ฉันยอมรับ <a href="privacy-policy.php" target="_blank" class="text-teal-600 underline" onclick="event.stopPropagation()">นโยบายคุ้มครองข้อมูลส่วนบุคคล</a>
                                </p>
                            </div>
                        </div>
                        <input type="checkbox" id="consent_privacy" class="hidden">
                    </div>

                    <!-- Health Data Consent -->
                    <div class="consent-card" onclick="toggleConsent('health')">
                        <div class="flex items-start gap-3">
                            <div class="consent-checkbox" id="checkbox_health">
                                <i class="fas fa-check text-white text-xs hidden"></i>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800 text-sm">💊 ข้อมูลสุขภาพ</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    ยินยอมให้เก็บข้อมูลสุขภาพเพื่อความปลอดภัยในการใช้ยา
                                </p>
                            </div>
                        </div>
                        <input type="checkbox" id="consent_health" class="hidden">
                    </div>
                    
                    <!-- Accept All -->
                    <div class="flex items-center justify-center gap-2 py-2">
                        <button type="button" onclick="acceptAllConsents()" class="text-sm text-teal-600 font-medium hover:text-teal-700">
                            <i class="fas fa-check-double mr-1"></i>ยอมรับทั้งหมด
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Personal Info Section -->
                <div class="section-header">
                    <i class="fas fa-user"></i>
                    <span>ข้อมูลส่วนตัว</span>
                </div>

                <div class="grid-2 mb-3">
                    <div class="input-group relative">
                        <input type="text" id="first_name" class="input-modern" placeholder="ชื่อ" required>
                        <i class="fas fa-user input-icon"></i>
                        <span class="input-label">ชื่อ *</span>
                    </div>
                    <div class="input-group relative">
                        <input type="text" id="last_name" class="input-modern" placeholder="นามสกุล">
                        <i class="fas fa-user input-icon"></i>
                        <span class="input-label">นามสกุล</span>
                    </div>
                </div>

                <div class="input-group relative mb-3">
                    <input type="tel" id="phone" class="input-modern" placeholder="0812345678" pattern="[0-9]{9,10}" inputmode="numeric">
                    <i class="fas fa-phone input-icon"></i>
                    <span class="input-label">เบอร์โทรศัพท์</span>
                </div>

                <div class="grid-2 mb-3">
                    <div class="input-group relative">
                        <input type="date" id="birthday" class="input-modern" required max="<?= date('Y-m-d') ?>">
                        <i class="fas fa-birthday-cake input-icon"></i>
                        <span class="input-label">วันเกิด *</span>
                    </div>
                    <div class="input-group relative">
                        <select id="gender" class="input-modern" required>
                            <option value="">เลือกเพศ</option>
                            <option value="male">ชาย</option>
                            <option value="female">หญิง</option>
                            <option value="other">ไม่ระบุ</option>
                        </select>
                        <i class="fas fa-venus-mars input-icon"></i>
                        <span class="input-label">เพศ *</span>
                    </div>
                </div>

                <!-- Health Info Section -->
                <div class="section-header">
                    <i class="fas fa-heartbeat"></i>
                    <span>ข้อมูลสุขภาพ</span>
                    <span class="text-xs text-gray-400 ml-auto">(ไม่บังคับ)</span>
                </div>

                <div class="grid-2 mb-3">
                    <div class="input-group relative">
                        <input type="number" id="weight" class="input-modern pr-12" placeholder="0" step="0.1" min="0" max="300">
                        <i class="fas fa-weight input-icon"></i>
                        <span class="input-label">น้ำหนัก</span>
                        <span class="input-unit">กก.</span>
                    </div>
                    <div class="input-group relative">
                        <input type="number" id="height" class="input-modern pr-12" placeholder="0" step="0.1" min="0" max="300">
                        <i class="fas fa-ruler-vertical input-icon"></i>
                        <span class="input-label">ส่วนสูง</span>
                        <span class="input-unit">ซม.</span>
                    </div>
                </div>

                <div class="input-group relative mb-3">
                    <input type="text" id="medical_conditions" class="input-modern" placeholder="เช่น เบาหวาน, ความดันสูง">
                    <i class="fas fa-notes-medical input-icon"></i>
                    <span class="input-label">โรคประจำตัว</span>
                </div>

                <div class="input-group relative mb-3">
                    <input type="text" id="drug_allergies" class="input-modern" placeholder="เช่น Penicillin, Aspirin">
                    <i class="fas fa-allergies input-icon"></i>
                    <span class="input-label">ยาที่แพ้</span>
                </div>

                <!-- Address Section -->
                <div class="section-header">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>ที่อยู่จัดส่ง</span>
                    <span class="text-xs text-gray-400 ml-auto">(ไม่บังคับ)</span>
                </div>

                <div class="input-group relative mb-3">
                    <input type="text" id="address" class="input-modern" placeholder="บ้านเลขที่ ซอย ถนน">
                    <i class="fas fa-home input-icon"></i>
                    <span class="input-label">ที่อยู่</span>
                </div>

                <div class="grid-2 mb-3">
                    <div class="input-group relative">
                        <input type="text" id="district" class="input-modern" placeholder="เขต/อำเภอ">
                        <i class="fas fa-map input-icon"></i>
                        <span class="input-label">เขต/อำเภอ</span>
                    </div>
                    <div class="input-group relative">
                        <input type="text" id="province" class="input-modern" placeholder="จังหวัด">
                        <i class="fas fa-city input-icon"></i>
                        <span class="input-label">จังหวัด</span>
                    </div>
                </div>

                <div class="input-group relative mb-5">
                    <input type="text" id="postal_code" class="input-modern" placeholder="10xxx" pattern="[0-9]{5}" inputmode="numeric" maxlength="5">
                    <i class="fas fa-mail-bulk input-icon"></i>
                    <span class="input-label">รหัสไปรษณีย์</span>
                </div>

                <!-- Benefits Preview -->
                <div class="bg-gradient-to-r from-teal-50 to-cyan-50 rounded-2xl p-4 mb-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-teal-500 to-cyan-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-gift text-white"></i>
                        </div>
                        <div>
                            <p class="font-bold text-gray-800">สิทธิพิเศษที่คุณจะได้รับ</p>
                            <p class="text-xs text-gray-500">เมื่อสมัครสมาชิกสำเร็จ</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-check-circle text-teal-500"></i>
                            <span>แต้มต้อนรับ 100 แต้ม</span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-check-circle text-teal-500"></i>
                            <span>ส่วนลดพิเศษ</span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-check-circle text-teal-500"></i>
                            <span>สะสมแต้มทุกการซื้อ</span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-check-circle text-teal-500"></i>
                            <span>ปรึกษาเภสัชกร</span>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="btnSubmit" class="btn-primary" <?= $isEditMode ? '' : 'disabled' ?>>
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= $isEditMode ? 'บันทึกการแก้ไข' : 'ยืนยันสมัครสมาชิก' ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center">
        <div class="bg-white rounded-3xl p-8 text-center shadow-2xl">
            <div class="w-16 h-16 border-4 border-teal-200 border-t-teal-600 rounded-full animate-spin mx-auto mb-4"></div>
            <p class="text-gray-600 font-medium">กำลังบันทึกข้อมูล...</p>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    const IS_EDIT_MODE = <?= $isEditMode ? 'true' : 'false' ?>;
    
    let userId = null;
    let profile = null;
    let consents = { terms: false, privacy: false, health: false };

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        if (LIFF_ID) {
            try {
                await liff.init({ liffId: LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    profile = await liff.getProfile();
                    userId = profile.userId;
                    
                    // Update avatar
                    if (profile.pictureUrl) {
                        document.getElementById('userAvatar').src = profile.pictureUrl;
                    }
                    
                    // Update welcome message
                    if (profile.displayName) {
                        document.getElementById('welcomeSubtitle').textContent = 
                            IS_EDIT_MODE ? `แก้ไขข้อมูลของ ${profile.displayName}` : `สวัสดี ${profile.displayName}! 👋`;
                    }
                    
                    if (IS_EDIT_MODE) {
                        await loadExistingData();
                    } else {
                        await checkRegistration();
                    }
                } else {
                    liff.login();
                }
            } catch (e) {
                console.error('LIFF init error:', e);
            }
        }
        
        // Enable button for edit mode
        if (IS_EDIT_MODE) {
            document.getElementById('btnSubmit').disabled = false;
        }
    }

    async function checkRegistration() {
        if (!userId) return;
        
        try {
            const response = await fetch(`${BASE_URL}/api/member.php?action=check&line_user_id=${userId}&line_account_id=${ACCOUNT_ID}`);
            const data = await response.json();
            
            if (data.success && data.is_registered) {
                // Already registered, redirect to edit mode
                window.location.href = `${BASE_URL}/liff-register.php?account=${ACCOUNT_ID}&edit=1`;
            }
        } catch (e) {
            console.error('Check registration error:', e);
        }
    }

    async function loadExistingData() {
        if (!userId) return;
        
        try {
            const response = await fetch(`${BASE_URL}/api/member.php?action=get_card&line_user_id=${userId}&line_account_id=${ACCOUNT_ID}`);
            const data = await response.json();
            
            if (data.success && data.member) {
                const m = data.member;
                document.getElementById('first_name').value = m.first_name || '';
                document.getElementById('last_name').value = m.last_name || '';
                document.getElementById('phone').value = m.phone || '';
                document.getElementById('birthday').value = m.birthday || '';
                document.getElementById('gender').value = m.gender || '';
                document.getElementById('weight').value = m.weight || '';
                document.getElementById('height').value = m.height || '';
                document.getElementById('medical_conditions').value = m.medical_conditions || '';
                document.getElementById('drug_allergies').value = m.drug_allergies || '';
                document.getElementById('address').value = m.address || '';
                document.getElementById('district').value = m.district || '';
                document.getElementById('province').value = m.province || '';
                document.getElementById('postal_code').value = m.postal_code || '';
            }
        } catch (e) {
            console.error('Load data error:', e);
        }
    }

    // Consent functions
    function toggleConsent(type) {
        consents[type] = !consents[type];
        const card = event.currentTarget;
        const checkbox = document.getElementById(`consent_${type}`);
        const checkboxUI = document.getElementById(`checkbox_${type}`);
        const checkIcon = checkboxUI.querySelector('i');
        
        checkbox.checked = consents[type];
        
        if (consents[type]) {
            card.classList.add('checked');
            checkIcon.classList.remove('hidden');
        } else {
            card.classList.remove('checked');
            checkIcon.classList.add('hidden');
        }
        
        updateSubmitButton();
    }
    
    function acceptAllConsents() {
        ['terms', 'privacy', 'health'].forEach(type => {
            consents[type] = true;
            const card = document.querySelector(`[onclick="toggleConsent('${type}')"]`);
            const checkbox = document.getElementById(`consent_${type}`);
            const checkboxUI = document.getElementById(`checkbox_${type}`);
            const checkIcon = checkboxUI.querySelector('i');
            
            checkbox.checked = true;
            card.classList.add('checked');
            checkIcon.classList.remove('hidden');
        });
        
        updateSubmitButton();
    }
    
    function updateSubmitButton() {
        const btn = document.getElementById('btnSubmit');
        // Terms and Privacy are required
        if (consents.terms && consents.privacy) {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }

    // Form validation
    function validateField(fieldId) {
        const el = document.getElementById(fieldId);
        if (!el) return true;
        
        const value = el.value.trim();
        const isRequired = el.hasAttribute('required');
        
        if (isRequired && !value) {
            el.classList.add('input-error', 'error-shake');
            setTimeout(() => el.classList.remove('error-shake'), 400);
            return false;
        }
        
        el.classList.remove('input-error');
        return true;
    }

    // Form submission
    document.getElementById('registerForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!userId) {
            Swal.fire({
                icon: 'warning',
                title: 'กรุณาเปิดผ่าน LINE',
                text: 'ต้องเปิดผ่านแอป LINE เพื่อสมัครสมาชิก',
                confirmButtonColor: '#11B0A6'
            });
            return;
        }
        
        // Validate required fields
        let isValid = true;
        isValid = validateField('first_name') && isValid;
        isValid = validateField('birthday') && isValid;
        isValid = validateField('gender') && isValid;
        
        // Validate consents for new registration
        if (!IS_EDIT_MODE && (!consents.terms || !consents.privacy)) {
            Swal.fire({
                icon: 'warning',
                title: 'กรุณายอมรับข้อตกลง',
                text: 'คุณต้องยอมรับข้อตกลงการใช้งานและนโยบายความเป็นส่วนตัว',
                confirmButtonColor: '#11B0A6'
            });
            return;
        }
        
        if (!isValid) {
            Swal.fire({
                icon: 'error',
                title: 'กรุณากรอกข้อมูลให้ครบ',
                text: 'โปรดตรวจสอบข้อมูลที่จำเป็น',
                confirmButtonColor: '#11B0A6'
            });
            return;
        }
        
        // Show loading
        document.getElementById('loadingOverlay').classList.remove('hidden');
        document.getElementById('loadingOverlay').classList.add('flex');
        document.getElementById('btnSubmit').disabled = true;
        
        try {
            // Save consent first (for new registration)
            if (!IS_EDIT_MODE) {
                await fetch(`${BASE_URL}/api/consent.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save',
                        line_user_id: userId,
                        line_account_id: ACCOUNT_ID,
                        consents: {
                            terms_of_service: consents.terms,
                            privacy_policy: consents.privacy,
                            health_data: consents.health
                        }
                    })
                });
            }
            
            // Collect form data
            const formData = {
                action: IS_EDIT_MODE ? 'update_profile' : 'register',
                line_user_id: userId,
                line_account_id: ACCOUNT_ID,
                first_name: document.getElementById('first_name').value.trim(),
                last_name: document.getElementById('last_name').value.trim(),
                birthday: document.getElementById('birthday').value,
                gender: document.getElementById('gender').value,
                weight: document.getElementById('weight').value || null,
                height: document.getElementById('height').value || null,
                medical_conditions: document.getElementById('medical_conditions').value.trim(),
                drug_allergies: document.getElementById('drug_allergies').value.trim(),
                phone: document.getElementById('phone').value.trim(),
                address: document.getElementById('address').value.trim(),
                district: document.getElementById('district').value.trim(),
                province: document.getElementById('province').value.trim(),
                postal_code: document.getElementById('postal_code').value.trim()
            };
            
            const response = await fetch(`${BASE_URL}/api/member.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            
            const data = await response.json();
            
            // Hide loading
            document.getElementById('loadingOverlay').classList.add('hidden');
            document.getElementById('loadingOverlay').classList.remove('flex');
            
            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: IS_EDIT_MODE ? 'บันทึกสำเร็จ! ✨' : 'สมัครสมาชิกสำเร็จ! 🎉',
                    html: IS_EDIT_MODE ? 
                        '<p class="text-gray-600">ข้อมูลของคุณถูกอัพเดทแล้ว</p>' :
                        `<div class="text-center">
                            <p class="text-gray-600 mb-2">รหัสสมาชิก: <strong class="text-teal-600">${data.member_id || 'NEW'}</strong></p>
                            <div class="bg-gradient-to-r from-teal-100 to-cyan-100 rounded-xl p-3 mt-3">
                                <p class="text-teal-700 font-medium">🎁 คุณได้รับ ${data.welcome_bonus || 100} แต้มต้อนรับ!</p>
                            </div>
                        </div>`,
                    confirmButtonColor: '#11B0A6',
                    confirmButtonText: IS_EDIT_MODE ? 'กลับหน้าหลัก' : 'ดูบัตรสมาชิก'
                });
                
                window.location.href = `${BASE_URL}/liff-app.php?account=${ACCOUNT_ID}`;
            } else {
                throw new Error(data.message || 'เกิดข้อผิดพลาด');
            }
        } catch (e) {
            console.error('Register error:', e);
            document.getElementById('loadingOverlay').classList.add('hidden');
            document.getElementById('loadingOverlay').classList.remove('flex');
            
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: e.message,
                confirmButtonColor: '#11B0A6'
            });
            document.getElementById('btnSubmit').disabled = !IS_EDIT_MODE && (!consents.terms || !consents.privacy);
        }
    });

    // Clear error on input
    document.querySelectorAll('.input-modern').forEach(el => {
        el.addEventListener('input', () => el.classList.remove('input-error'));
    });

    function goBack() {
        if (liff.isInClient()) {
            liff.closeWindow();
        } else {
            window.history.back();
        }
    }

    function closeLiff() {
        if (liff.isInClient()) {
            liff.closeWindow();
        } else {
            window.close();
        }
    }
    </script>
</body>
</html>
