<?php
/**
 * Admin Login Page - Modern Split Screen Design
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AdminAuth.php';

$db = Database::getInstance()->getConnection();
$auth = new AdminAuth($db);

// Already logged in - redirect to dashboard
if (isset($_SESSION['admin_user']) && !empty($_SESSION['admin_user']['id'])) {
    header('Location: ../dashboard');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            header('Location: ../dashboard');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - LINE Telepharmacy Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', 'Sarabun', sans-serif; }
        
        .login-bg {
            background: linear-gradient(135deg, #00B900 0%, #047857 100%);
            position: relative;
            overflow: hidden;
        }
        
        .login-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                linear-gradient(135deg, transparent 40%, rgba(255, 255, 255, 0.05) 40%, rgba(255, 255, 255, 0.05) 60%, transparent 60%),
                linear-gradient(225deg, transparent 40%, rgba(255, 255, 255, 0.05) 40%, rgba(255, 255, 255, 0.05) 60%, transparent 60%);
            background-size: 100px 100px;
            pointer-events: none;
            opacity: 0.5;
        }
        
        .divider-line {
            width: 1px;
            height: 70%;
            background: rgba(226, 232, 240, 0.8);
            margin: 0 20px;
        }
        
        .input-field {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            padding-left: 44px;
            width: 100%;
            font-size: 15px;
            transition: all 0.2s;
            color: #1e293b;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #00B900;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 185, 0, 0.1);
        }
        
        .input-field::placeholder {
            color: #94a3b8;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            transition: color 0.2s;
            font-size: 16px;
        }

        .input-field:focus + .input-icon {
            color: #00B900;
        }
        
        .login-btn {
            background: #00B900;
            color: white;
            font-weight: 600;
            padding: 14px 32px;
            border-radius: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 185, 0, 0.2);
            font-size: 16px;
        }
        
        .login-btn:hover {
            background: #00A000;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 185, 0, 0.3);
        }
        
        .logo-box {
            width: 88px;
            height: 88px;
            background: white;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            position: relative;
            z-index: 10;
        }
        
        .logo-box i {
            font-size: 40px;
            background: linear-gradient(135deg, #00B900, #047857);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .icon-container {
            width: 48px;
            height: 48px;
            background: rgba(0, 185, 0, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #00B900;
            margin-bottom: 20px;
        }

        .split-card {
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .split-card {
                flex-direction: row;
            }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-5xl">
        <!-- Main Card -->
        <div class="bg-white rounded-3xl shadow-[0_20px_50px_rgba(0,0,0,0.05)] overflow-hidden border border-slate-100">
            <div class="split-card min-h-[560px]">
                
                <!-- Left Side - Branding -->
                <div class="login-bg hidden md:flex flex-1 flex-col items-center justify-center p-12 text-center">
                    <!-- Logo Box -->
                    <div class="logo-box mb-8">
                        <i class="fas fa-clinic-medical"></i>
                    </div>
                    
                    <h2 class="text-white text-2xl font-bold tracking-wide mb-3">LINE Telepharmacy</h2>
                    <h3 class="text-green-100 text-lg font-medium mb-6">Unified Management System</h3>
                    
                    <p class="text-green-50/80 text-sm max-w-sm leading-relaxed">
                        ระบบจัดการร้านขายยาและคลินิกออนไลน์ครบวงจร เชื่อมต่อข้อมูลลูกค้า แชท และคลังสินค้าไว้ในที่เดียว
                    </p>

                    <div class="mt-12 flex gap-4 text-green-100/60">
                        <i class="fas fa-shield-alt text-xl" title="Secure"></i>
                        <i class="fas fa-sync text-xl" title="Real-time"></i>
                        <i class="fas fa-mobile-alt text-xl" title="Mobile Ready"></i>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="flex-1 flex flex-col justify-center p-8 md:p-14 bg-white relative">
                    
                    <div class="max-w-sm w-full mx-auto">
                        <!-- Mobile Header (Hidden on Desktop) -->
                        <div class="md:hidden flex flex-col items-center text-center mb-8">
                            <div class="w-16 h-16 bg-green-50 border border-green-100 rounded-2xl flex items-center justify-center mb-4 text-green-600 text-2xl shadow-sm">
                                <i class="fas fa-clinic-medical"></i>
                            </div>
                            <h2 class="text-xl font-bold text-slate-800">Telepharmacy System</h2>
                            <p class="text-sm text-slate-500 mt-1">Please sign in to continue</p>
                        </div>

                        <!-- Desktop Header -->
                        <div class="hidden md:block mb-8">
                            <div class="icon-container">
                                <i class="fas fa-sign-in-alt text-xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-slate-800 tracking-tight">Welcome Back</h3>
                            <p class="text-slate-500 mt-2 text-sm">Sign in to access your dashboard</p>
                        </div>
                        
                        <!-- Error Message -->
                        <?php if ($error): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-600 rounded-xl text-sm flex items-start gap-3 shadow-sm">
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Login Form -->
                        <form method="POST" class="space-y-5">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-slate-700 ml-1">Username / Email</label>
                                <div class="relative">
                                    <input type="text" name="username" required autofocus
                                           class="input-field"
                                           placeholder="Enter your username"
                                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                    <i class="fas fa-user input-icon"></i>
                                </div>
                            </div>
                            
                            <div class="space-y-1">
                                <div class="flex items-center justify-between ml-1">
                                    <label class="text-sm font-medium text-slate-700">Password</label>
                                </div>
                                <div class="relative">
                                    <input type="password" name="password" required
                                           class="input-field"
                                           placeholder="Enter your password">
                                    <i class="fas fa-lock input-icon"></i>
                                </div>
                            </div>
                            
                            <!-- Remember Me -->
                            <div class="flex items-center pt-2">
                                <input type="checkbox" id="remember" name="remember" 
                                       class="w-4 h-4 rounded border-slate-300 text-green-600 focus:ring-green-500">
                                <label for="remember" class="ml-2 text-sm text-slate-500 select-none cursor-pointer">Keep me signed in</label>
                            </div>
                            
                            <!-- Login Button -->
                            <div class="pt-4">
                                <button type="submit" class="login-btn w-full flex items-center justify-center gap-2">
                                    <span>Sign In</span>
                                    <i class="fas fa-arrow-right text-sm"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-slate-400 text-sm mt-8">
            &copy; <?= date('Y') ?> LINE Telepharmacy Platform. All rights reserved.
        </p>
    </div>
</body>
</html>
