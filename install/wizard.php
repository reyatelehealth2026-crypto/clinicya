<?php
/**
 * LINE Telepharmacy CRM - Installation Wizard
 * Version: 3.2
 * 
 * Modern 7-step installation wizard
 */

// Check if already installed
$lockFile = __DIR__ . '/../config/installed.lock';
$isInstalled = file_exists($lockFile);
$forceReinstall = isset($_GET['force']) || isset($_GET['reinstall']);

// If installed and not forcing, show already installed page
if ($isInstalled && !$forceReinstall) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    $installDate = $lockData['installed_at'] ?? 'Unknown';
    $version = $lockData['version'] ?? 'Unknown';
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(600);

// Get current step from session or default to 1
$currentStep = $_SESSION['install_step'] ?? 1;

// Auto-detect app URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = dirname(dirname($_SERVER['REQUEST_URI']));
$autoUrl = rtrim($protocol . '://' . $host . $path, '/');
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LINE Telepharmacy CRM - Installation Wizard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #06D6A0;
            --primary-dark: #05B384;
            --secondary: #118AB2;
            --accent: #EF476F;
            --bg-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f857a6 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --text-dark: #1a1a2e;
            --text-muted: #6c757d;
            --border-radius: 16px;
            --shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Prompt', sans-serif;
            background: var(--bg-gradient);
            min-height: 100vh;
            padding: 20px;
            color: var(--text-dark);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header */
        .wizard-header {
            text-align: center;
            padding: 30px 0;
            color: white;
        }

        .wizard-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .wizard-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .version-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-top: 10px;
        }

        /* Progress Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
            margin-bottom: 30px;
            position: relative;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 60px;
            right: 60px;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            z-index: 0;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }

        .step-item.active .step-number {
            background: white;
            color: var(--secondary);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            transform: scale(1.1);
        }

        .step-item.completed .step-number {
            background: var(--primary);
            color: white;
        }

        .step-label {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
            text-align: center;
        }

        .step-item.active .step-label {
            color: white;
            font-weight: 500;
        }

        /* Card */
        .wizard-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: fadeInUp 0.5s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 25px 30px;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .card-body {
            padding: 30px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .form-group label .required {
            color: var(--accent);
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(6, 214, 160, 0.15);
        }

        .form-control.error {
            border-color: var(--accent);
        }

        .form-hint {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(6, 214, 160, 0.3);
        }

        .btn-secondary {
            background: #e0e0e0;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
        }

        /* Requirements List */
        .requirements-list {
            list-style: none;
        }

        .requirements-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .requirements-list li:last-child {
            border-bottom: none;
        }

        .req-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .req-icon.pass {
            background: #d1fae5;
            color: #059669;
        }

        .req-icon.fail {
            background: #fee2e2;
            color: #dc2626;
        }

        .req-name {
            flex: 1;
            font-weight: 500;
        }

        .req-status {
            font-size: 0.9rem;
        }

        .req-status.pass {
            color: #059669;
        }

        .req-status.fail {
            color: #dc2626;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Progress Bar */
        .install-progress {
            margin: 20px 0;
        }

        .progress-bar {
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .progress-text {
            text-align: center;
            margin-top: 10px;
            font-weight: 500;
            color: var(--text-muted);
        }

        /* Installation Log */
        .install-log {
            background: #1a1a2e;
            color: #00ff88;
            padding: 20px;
            border-radius: 10px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 0.85rem;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
        }

        .install-log .log-entry {
            padding: 4px 0;
        }

        .install-log .log-success {
            color: #22c55e;
        }

        .install-log .log-error {
            color: #ef4444;
        }

        .install-log .log-warning {
            color: #f59e0b;
        }

        /* Completion */
        .completion-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: pulse 2s infinite;
        }

        .completion-icon i {
            font-size: 3rem;
            color: white;
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(6, 214, 160, 0.4);
            }

            50% {
                box-shadow: 0 0 0 20px rgba(6, 214, 160, 0);
            }
        }

        .next-steps {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .next-steps h4 {
            margin-bottom: 15px;
            color: var(--secondary);
        }

        .next-steps ol {
            padding-left: 20px;
        }

        .next-steps li {
            padding: 8px 0;
            line-height: 1.6;
        }

        /* Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Section Headers */
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header i {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }

        .section-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Hide step content */
        .step-content {
            display: none;
        }

        .step-content.active {
            display: block;
        }

        /* Footer */
        .wizard-footer {
            text-align: center;
            padding: 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        .wizard-footer a {
            color: white;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="wizard-header">
            <h1>🏥 LINE Telepharmacy CRM</h1>
            <p>Installation Wizard</p>
            <div class="version-badge">Version 3.2</div>
        </div>

        <?php if ($isInstalled && !$forceReinstall): ?>
            <!-- Already Installed Page -->
            <div class="wizard-card">
                <div class="card-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <h2><i class="fas fa-exclamation-triangle"></i> ระบบติดตั้งแล้ว</h2>
                    <p>LINE Telepharmacy CRM ได้ถูกติดตั้งบนเซิร์ฟเวอร์นี้แล้ว</p>
                </div>
                <div class="card-body" style="text-align: center; padding: 40px;">
                    <div class="completion-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-check"></i>
                    </div>

                    <h2 style="margin-bottom: 10px;">ติดตั้งแล้ว!</h2>
                    <p style="color: var(--text-muted); margin-bottom: 10px;">
                        Version: <strong><?= htmlspecialchars($version) ?></strong>
                    </p>
                    <p style="color: var(--text-muted); margin-bottom: 30px;">
                        ติดตั้งเมื่อ: <?= htmlspecialchars($installDate) ?>
                    </p>

                    <div class="alert alert-warning" style="text-align: left;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>ต้องการติดตั้งใหม่?</strong><br>
                            การติดตั้งซ้ำจะเขียนทับการตั้งค่าเดิม แต่จะไม่ลบข้อมูลในฐานข้อมูล
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                        <a href="../" class="btn btn-primary">
                            <i class="fas fa-home"></i> ไปหน้า Dashboard
                        </a>
                        <a href="?reinstall=1" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> ติดตั้งใหม่
                        </a>
                    </div>

                    <div class="next-steps" style="margin-top: 30px; text-align: left;">
                        <h4><i class="fas fa-wrench"></i> หากต้องการตั้งค่าเพิ่มเติม:</h4>
                        <ul style="list-style: disc; padding-left: 20px; line-height: 1.8;">
                            <li><a href="../line-accounts.php">จัดการบัญชี LINE OA</a></li>
                            <li><a href="../ai-settings.php">ตั้งค่า AI</a></li>
                            <li><a href="../settings.php">ตั้งค่าระบบทั่วไป</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <!-- Progress Steps -->
            <div class="progress-steps">
                <div class="step-item" data-step="1">
                    <div class="step-number">1</div>
                    <div class="step-label">ยินดีต้อนรับ</div>
                </div>
                <div class="step-item" data-step="2">
                    <div class="step-number">2</div>
                    <div class="step-label">ตรวจสอบ</div>
                </div>
                <div class="step-item" data-step="3">
                    <div class="step-number">3</div>
                    <div class="step-label">ฐานข้อมูล</div>
                </div>
                <div class="step-item" data-step="4">
                    <div class="step-number">4</div>
                    <div class="step-label">ตั้งค่าแอป</div>
                </div>
                <div class="step-item" data-step="5">
                    <div class="step-number">5</div>
                    <div class="step-label">LINE API</div>
                </div>
                <div class="step-item" data-step="6">
                    <div class="step-number">6</div>
                    <div class="step-label">ผู้ดูแล</div>
                </div>
                <div class="step-item" data-step="7">
                    <div class="step-number">7</div>
                    <div class="step-label">ติดตั้ง</div>
                </div>
            </div>

            <!-- Wizard Card -->
            <div class="wizard-card">
                <!-- Step 1: Welcome -->
                <div class="step-content active" data-step="1">
                    <div class="card-header">
                        <h2><i class="fas fa-star"></i> ยินดีต้อนรับ</h2>
                        <p>ขอบคุณที่เลือกใช้ LINE Telepharmacy CRM Platform</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>ก่อนเริ่มต้น</strong><br>
                                กรุณาเตรียมข้อมูลต่อไปนี้ให้พร้อม:
                            </div>
                        </div>

                        <ul class="requirements-list">
                            <li>
                                <div class="req-icon pass"><i class="fas fa-database"></i></div>
                                <span class="req-name">ข้อมูลฐานข้อมูล MySQL (Host, Username, Password)</span>
                            </li>
                            <li>
                                <div class="req-icon pass"><i class="fab fa-line"></i></div>
                                <span class="req-name">LINE Channel Secret และ Access Token</span>
                            </li>
                            <li>
                                <div class="req-icon pass"><i class="fas fa-link"></i></div>
                                <span class="req-name">LIFF ID (สร้างได้ที่ LINE Developers Console)</span>
                            </li>
                            <li>
                                <div class="req-icon pass"><i class="fas fa-user-shield"></i></div>
                                <span class="req-name">ข้อมูลบัญชีผู้ดูแลระบบ</span>
                            </li>
                        </ul>

                        <div class="section-header" style="margin-top: 30px;">
                            <i class="fas fa-rocket"></i>
                            <h3>ฟีเจอร์หลัก</h3>
                        </div>

                        <div class="form-row">
                            <div>
                                <p><i class="fas fa-check text-success"></i> ระบบ CRM จัดการลูกค้า</p>
                                <p><i class="fas fa-check text-success"></i> ร้านค้าออนไลน์ E-commerce</p>
                                <p><i class="fas fa-check text-success"></i> AI ผู้ช่วยเภสัชกร</p>
                                <p><i class="fas fa-check text-success"></i> ระบบแต้มสะสม Loyalty</p>
                            </div>
                            <div>
                                <p><i class="fas fa-check text-success"></i> นัดหมาย Video Call</p>
                                <p><i class="fas fa-check text-success"></i> Broadcast & Auto-Reply</p>
                                <p><i class="fas fa-check text-success"></i> ระบบคลังสินค้า WMS</p>
                                <p><i class="fas fa-check text-success"></i> รายงานและ Analytics</p>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div></div>
                        <button class="btn btn-primary" onclick="nextStep()">
                            เริ่มต้นติดตั้ง <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Requirements -->
                <div class="step-content" data-step="2">
                    <div class="card-header">
                        <h2><i class="fas fa-clipboard-check"></i> ตรวจสอบระบบ</h2>
                        <p>ตรวจสอบความพร้อมของเซิร์ฟเวอร์</p>
                    </div>
                    <div class="card-body">
                        <div id="requirementsLoading" class="text-center" style="padding: 40px;">
                            <div class="spinner"
                                style="margin: 0 auto 15px; border-color: var(--primary); border-top-color: transparent;">
                            </div>
                            <p>กำลังตรวจสอบระบบ...</p>
                        </div>
                        <div id="requirementsResult" style="display: none;">
                            <ul class="requirements-list" id="requirementsList"></ul>
                            <div id="requirementsAlert" class="alert" style="display: none; margin-top: 20px;"></div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="prevStep()">
                            <i class="fas fa-arrow-left"></i> ย้อนกลับ
                        </button>
                        <button class="btn btn-primary" onclick="nextStep()" id="btnStep2Next" disabled>
                            ถัดไป <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 3: Database -->
                <div class="step-content" data-step="3">
                    <div class="card-header">
                        <h2><i class="fas fa-database"></i> ตั้งค่าฐานข้อมูล</h2>
                        <p>กรอกข้อมูลสำหรับเชื่อมต่อ MySQL Database</p>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Database Host <span class="required">*</span></label>
                                <input type="text" class="form-control" id="dbHost" value="localhost" required>
                                <div class="form-hint">ปกติคือ localhost หรือ 127.0.0.1</div>
                            </div>
                            <div class="form-group">
                                <label>Database Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="dbName" placeholder="telepharmacy" required>
                                <div class="form-hint">ระบบจะสร้างให้อัตโนมัติถ้ายังไม่มี</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Username <span class="required">*</span></label>
                                <input type="text" class="form-control" id="dbUser" required>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" class="form-control" id="dbPass">
                            </div>
                        </div>

                        <div id="dbTestResult" style="display: none;"></div>

                        <button type="button" class="btn btn-secondary" onclick="testDatabase()" id="btnTestDb">
                            <i class="fas fa-plug"></i> ทดสอบการเชื่อมต่อ
                        </button>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="prevStep()">
                            <i class="fas fa-arrow-left"></i> ย้อนกลับ
                        </button>
                        <button class="btn btn-primary" onclick="nextStep()" id="btnStep3Next" disabled>
                            ถัดไป <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 4: App Settings -->
                <div class="step-content" data-step="4">
                    <div class="card-header">
                        <h2><i class="fas fa-cog"></i> ตั้งค่าแอปพลิเคชัน</h2>
                        <p>กำหนดค่าพื้นฐานของระบบ</p>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>ชื่อแอปพลิเคชัน <span class="required">*</span></label>
                                <input type="text" class="form-control" id="appName" value="LINE Telepharmacy" required>
                            </div>
                            <div class="form-group">
                                <label>URL ของระบบ <span class="required">*</span></label>
                                <input type="url" class="form-control" id="appUrl" value="<?= htmlspecialchars($autoUrl) ?>"
                                    required>
                                <div class="form-hint">URL หลักของระบบ (ไม่ต้องมี / ท้าย)</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Timezone</label>
                                <select class="form-control" id="appTimezone">
                                    <option value="Asia/Bangkok" selected>Asia/Bangkok (GMT+7)</option>
                                    <option value="Asia/Singapore">Asia/Singapore (GMT+8)</option>
                                    <option value="UTC">UTC (GMT+0)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Debug Mode</label>
                                <select class="form-control" id="appDebug">
                                    <option value="0" selected>ปิด (Production)</option>
                                    <option value="1">เปิด (Development)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="prevStep()">
                            <i class="fas fa-arrow-left"></i> ย้อนกลับ
                        </button>
                        <button class="btn btn-primary" onclick="nextStep()">
                            ถัดไป <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 5: LINE API -->
                <div class="step-content" data-step="5">
                    <div class="card-header">
                        <h2><i class="fab fa-line"></i> ตั้งค่า LINE API</h2>
                        <p>เชื่อมต่อกับ LINE Messaging API</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>วิธีรับค่าเหล่านี้:</strong><br>
                                ไปที่ <a href="https://developers.line.biz/console/" target="_blank">LINE Developers
                                    Console</a> > เลือก Channel > Messaging API
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Channel Secret <span class="required">*</span></label>
                            <input type="text" class="form-control" id="lineChannelSecret"
                                placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                            <div class="form-hint">พบได้ที่ Basic settings > Channel secret</div>
                        </div>

                        <div class="form-group">
                            <label>Channel Access Token <span class="required">*</span></label>
                            <textarea class="form-control" id="lineAccessToken" rows="3"
                                placeholder="xxxxxxxxxxxxxxxxxxxx..."></textarea>
                            <div class="form-hint">พบได้ที่ Messaging API > Channel access token (ใช้ long-lived)</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>LIFF ID (Main)</label>
                                <input type="text" class="form-control" id="liffId" placeholder="1234567890-xxxxxxxx">
                                <div class="form-hint">สร้างได้ที่ LINE Developers > LIFF</div>
                            </div>
                            <div class="form-group">
                                <label>Channel ID</label>
                                <input type="text" class="form-control" id="lineChannelId" placeholder="1234567890">
                            </div>
                        </div>

                        <div class="alert alert-warning">
                            <i class="fas fa-lightbulb"></i>
                            <div>
                                <strong>Tip:</strong> สามารถข้ามขั้นตอนนี้และตั้งค่าภายหลังได้ที่หน้า LINE Accounts
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="prevStep()">
                            <i class="fas fa-arrow-left"></i> ย้อนกลับ
                        </button>
                        <button class="btn btn-primary" onclick="nextStep()">
                            ถัดไป <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 6: Admin Account -->
                <div class="step-content" data-step="6">
                    <div class="card-header">
                        <h2><i class="fas fa-user-shield"></i> สร้างบัญชีผู้ดูแล</h2>
                        <p>สร้างบัญชี Super Admin สำหรับจัดการระบบ</p>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Username <span class="required">*</span></label>
                                <input type="text" class="form-control" id="adminUsername" value="admin" required>
                            </div>
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" class="form-control" id="adminEmail" placeholder="admin@example.com"
                                    required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Password <span class="required">*</span></label>
                                <input type="password" class="form-control" id="adminPassword" minlength="6" required>
                                <div class="form-hint">อย่างน้อย 6 ตัวอักษร</div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Password <span class="required">*</span></label>
                                <input type="password" class="form-control" id="adminPasswordConfirm" minlength="6"
                                    required>
                            </div>
                        </div>

                        <div id="adminValidation" style="display: none;"></div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="prevStep()">
                            <i class="fas fa-arrow-left"></i> ย้อนกลับ
                        </button>
                        <button class="btn btn-primary" onclick="validateAndInstall()">
                            <i class="fas fa-download"></i> เริ่มติดตั้ง
                        </button>
                    </div>
                </div>

                <!-- Step 7: Installation -->
                <div class="step-content" data-step="7">
                    <div class="card-header">
                        <h2><i class="fas fa-cogs"></i> กำลังติดตั้ง</h2>
                        <p>กรุณารอสักครู่ ระบบกำลังติดตั้ง...</p>
                    </div>
                    <div class="card-body">
                        <div id="installProgress">
                            <div class="install-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
                                </div>
                                <div class="progress-text" id="progressText">กำลังเตรียมการติดตั้ง...</div>
                            </div>

                            <div class="install-log" id="installLog"></div>
                        </div>

                        <div id="installComplete" style="display: none; text-align: center; padding: 30px 0;">
                            <div class="completion-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <h2 style="margin-bottom: 10px;">ติดตั้งสำเร็จ! 🎉</h2>
                            <p style="color: var(--text-muted); margin-bottom: 30px;">ระบบพร้อมใช้งานแล้ว</p>

                            <div class="next-steps">
                                <h4><i class="fas fa-list-check"></i> ขั้นตอนถัดไป:</h4>
                                <ol>
                                    <li><strong>ลบโฟลเดอร์ install/</strong> เพื่อความปลอดภัย</li>
                                    <li>ไปที่ <a href="../line-accounts.php">LINE Accounts</a> เพื่อตั้งค่า LINE OA</li>
                                    <li>ตั้งค่า Webhook URL: <code id="webhookUrl"></code></li>
                                    <li>สร้าง LIFF Apps ใน LINE Developers Console</li>
                                    <li>ตั้งค่า AI ที่หน้า AI Settings (ถ้าต้องการใช้)</li>
                                </ol>
                            </div>

                            <div style="margin-top: 30px;">
                                <a href="../" class="btn btn-primary">
                                    <i class="fas fa-home"></i> ไปหน้า Dashboard
                                </a>
                                <a href="../line-accounts.php" class="btn btn-secondary">
                                    <i class="fab fa-line"></i> ตั้งค่า LINE Account
                                </a>
                            </div>
                        </div>

                        <div id="installError" style="display: none;"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="wizard-footer">
            <p>LINE Telepharmacy CRM Platform v3.2 &copy;
                <?= date('Y') ?>
            </p>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 7;
        let dbTested = false;
        let installationData = {};

        // Initialize
        document.addEventListener('DOMContentLoaded', function () {
            updateStepIndicators();
        });

        // Step Navigation
        function nextStep() {
            if (currentStep < totalSteps) {
                // Validate current step before proceeding
                if (!validateStep(currentStep)) {
                    return;
                }

                currentStep++;
                showStep(currentStep);

                // Trigger step-specific actions
                if (currentStep === 2) {
                    checkRequirements();
                }
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }

        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.step-content').forEach(el => {
                el.classList.remove('active');
            });

            // Show current step
            document.querySelector(`.step-content[data-step="${step}"]`).classList.add('active');

            // Update indicators
            updateStepIndicators();
        }

        function updateStepIndicators() {
            document.querySelectorAll('.step-item').forEach(el => {
                const step = parseInt(el.dataset.step);
                el.classList.remove('active', 'completed');

                if (step === currentStep) {
                    el.classList.add('active');
                } else if (step < currentStep) {
                    el.classList.add('completed');
                }
            });
        }

        function validateStep(step) {
            // Add validation logic for each step
            if (step === 3) {
                if (!dbTested) {
                    alert('กรุณาทดสอบการเชื่อมต่อฐานข้อมูลก่อน');
                    return false;
                }
            }

            if (step === 4) {
                const appName = document.getElementById('appName').value.trim();
                const appUrl = document.getElementById('appUrl').value.trim();
                if (!appName || !appUrl) {
                    alert('กรุณากรอกข้อมูลให้ครบถ้วน');
                    return false;
                }
            }

            return true;
        }

        // Check Requirements
        async function checkRequirements() {
            document.getElementById('requirementsLoading').style.display = 'block';
            document.getElementById('requirementsResult').style.display = 'none';

            try {
                const response = await fetch('wizard-api.php?action=check_requirements');
                const data = await response.json();

                document.getElementById('requirementsLoading').style.display = 'none';
                document.getElementById('requirementsResult').style.display = 'block';

                const list = document.getElementById('requirementsList');
                list.innerHTML = '';

                let allPassed = true;

                data.requirements.forEach(req => {
                    const li = document.createElement('li');
                    const passed = req.passed;
                    if (!passed) allPassed = false;

                    li.innerHTML = `
                        <div class="req-icon ${passed ? 'pass' : 'fail'}">
                            <i class="fas ${passed ? 'fa-check' : 'fa-times'}"></i>
                        </div>
                        <span class="req-name">${req.name}</span>
                        <span class="req-status ${passed ? 'pass' : 'fail'}">${passed ? 'ผ่าน' : 'ไม่ผ่าน'}</span>
                    `;
                    list.appendChild(li);
                });

                const alert = document.getElementById('requirementsAlert');
                alert.style.display = 'flex';

                if (allPassed) {
                    alert.className = 'alert alert-success';
                    alert.innerHTML = '<i class="fas fa-check-circle"></i><div>ระบบพร้อมสำหรับการติดตั้ง!</div>';
                    document.getElementById('btnStep2Next').disabled = false;
                } else {
                    alert.className = 'alert alert-error';
                    alert.innerHTML = '<i class="fas fa-exclamation-circle"></i><div>กรุณาแก้ไขปัญหาข้างต้นก่อนดำเนินการต่อ</div>';
                    document.getElementById('btnStep2Next').disabled = true;
                }

            } catch (error) {
                console.error('Error checking requirements:', error);
                document.getElementById('requirementsLoading').style.display = 'none';
                document.getElementById('requirementsResult').style.display = 'block';
                document.getElementById('requirementsAlert').style.display = 'flex';
                document.getElementById('requirementsAlert').className = 'alert alert-error';
                document.getElementById('requirementsAlert').innerHTML = '<i class="fas fa-exclamation-circle"></i><div>เกิดข้อผิดพลาดในการตรวจสอบ</div>';
            }
        }

        // Test Database
        async function testDatabase() {
            const btn = document.getElementById('btnTestDb');
            const result = document.getElementById('dbTestResult');

            const data = {
                host: document.getElementById('dbHost').value,
                name: document.getElementById('dbName').value,
                user: document.getElementById('dbUser').value,
                pass: document.getElementById('dbPass').value
            };

            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div> กำลังทดสอบ...';
            result.style.display = 'none';

            try {
                const response = await fetch('wizard-api.php?action=test_database', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const res = await response.json();

                result.style.display = 'block';

                if (res.success) {
                    result.innerHTML = `<div class="alert alert-success" style="margin-top: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <div><strong>เชื่อมต่อสำเร็จ!</strong><br>${res.message}</div>
                    </div>`;
                    dbTested = true;
                    document.getElementById('btnStep3Next').disabled = false;

                    // Save to installation data
                    installationData.database = data;
                } else {
                    result.innerHTML = `<div class="alert alert-error" style="margin-top: 20px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><strong>ไม่สามารถเชื่อมต่อได้</strong><br>${res.message}</div>
                    </div>`;
                    dbTested = false;
                    document.getElementById('btnStep3Next').disabled = true;
                }

            } catch (error) {
                result.style.display = 'block';
                result.innerHTML = `<div class="alert alert-error" style="margin-top: 20px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><strong>เกิดข้อผิดพลาด</strong><br>${error.message}</div>
                </div>`;
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plug"></i> ทดสอบการเชื่อมต่อ';
        }

        // Validate and Start Installation
        function validateAndInstall() {
            const password = document.getElementById('adminPassword').value;
            const confirm = document.getElementById('adminPasswordConfirm').value;
            const email = document.getElementById('adminEmail').value;
            const username = document.getElementById('adminUsername').value;

            const validation = document.getElementById('adminValidation');

            if (!username || !email || !password) {
                validation.style.display = 'block';
                validation.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div>กรุณากรอกข้อมูลให้ครบถ้วน</div></div>';
                return;
            }

            if (password.length < 6) {
                validation.style.display = 'block';
                validation.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div>รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร</div></div>';
                return;
            }

            if (password !== confirm) {
                validation.style.display = 'block';
                validation.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div>รหัสผ่านไม่ตรงกัน</div></div>';
                return;
            }

            validation.style.display = 'none';

            // Collect all data
            installationData.app = {
                name: document.getElementById('appName').value,
                url: document.getElementById('appUrl').value.replace(/\/$/, ''),
                timezone: document.getElementById('appTimezone').value,
                debug: document.getElementById('appDebug').value
            };

            installationData.line = {
                channelId: document.getElementById('lineChannelId').value,
                channelSecret: document.getElementById('lineChannelSecret').value,
                accessToken: document.getElementById('lineAccessToken').value,
                liffId: document.getElementById('liffId').value
            };

            installationData.admin = {
                username: username,
                email: email,
                password: password
            };

            // Go to step 7 and start installation
            currentStep = 7;
            showStep(7);
            startInstallation();
        }

        // Start Installation Process
        async function startInstallation() {
            const log = document.getElementById('installLog');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');

            function addLog(message, type = 'info') {
                const entry = document.createElement('div');
                entry.className = `log-entry log-${type}`;
                entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
                log.appendChild(entry);
                log.scrollTop = log.scrollHeight;
            }

            function updateProgress(percent, text) {
                progressFill.style.width = percent + '%';
                progressText.textContent = text;
            }

            try {
                addLog('เริ่มต้นการติดตั้ง...', 'info');
                updateProgress(10, 'กำลังเตรียมฐานข้อมูล...');

                const response = await fetch('wizard-api.php?action=install', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(installationData)
                });

                const data = await response.json();

                if (data.success) {
                    // Simulate step-by-step progress
                    const steps = data.logs || [];
                    for (let i = 0; i < steps.length; i++) {
                        await new Promise(r => setTimeout(r, 300));
                        addLog(steps[i], steps[i].includes('Error') ? 'error' : 'success');
                        updateProgress(20 + (i / steps.length * 70), steps[i]);
                    }

                    updateProgress(100, 'ติดตั้งเสร็จสมบูรณ์!');
                    addLog('ติดตั้งเสร็จสมบูรณ์!', 'success');

                    await new Promise(r => setTimeout(r, 1000));

                    // Show completion
                    document.getElementById('installProgress').style.display = 'none';
                    document.getElementById('installComplete').style.display = 'block';
                    document.getElementById('webhookUrl').textContent = installationData.app.url + '/webhook.php';

                } else {
                    addLog('Error: ' + data.message, 'error');
                    document.getElementById('installError').style.display = 'block';
                    document.getElementById('installError').innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div><strong>เกิดข้อผิดพลาด</strong><br>${data.message}</div>
                        </div>
                        <button class="btn btn-secondary" onclick="prevStep(); currentStep = 3; showStep(3);">
                            <i class="fas fa-arrow-left"></i> กลับไปแก้ไข
                        </button>
                    `;
                }

            } catch (error) {
                addLog('Error: ' + error.message, 'error');
                document.getElementById('installError').style.display = 'block';
                document.getElementById('installError').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><strong>เกิดข้อผิดพลาด</strong><br>${error.message}</div>
                    </div>
                `;
            }
        }
    </script>
</body>

</html>