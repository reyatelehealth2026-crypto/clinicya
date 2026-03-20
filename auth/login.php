<?php
/**
 * Admin Login Page - Glassmorphism Design
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
    <link rel="stylesheet" href="../assets/css/design-tokens.css">
    <link rel="stylesheet" href="../assets/css/glassmorphism.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <style>
        * { font-family: 'Inter', 'Sarabun', sans-serif; }
        
        body {
            background: 
                radial-gradient(at 40% 20%, rgba(99, 102, 241, 0.2) 0px, transparent 50%),
                radial-gradient(at 80% 0%, rgba(139, 92, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 0% 50%, rgba(236, 72, 153, 0.1) 0px, transparent 50%),
                linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            min-height: 100vh;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(40px) saturate(180%);
            -webkit-backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 32px;
            box-shadow: 
                0 40px 80px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        
        .login-bg {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.3) 0%, rgba(139, 92, 246, 0.3) 50%, rgba(236, 72, 153, 0.2) 100%);
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
                radial-gradient(circle at 30% 30%, rgba(99, 102, 241, 0.4) 0%, transparent 40%),
                radial-gradient(circle at 70% 70%, rgba(236, 72, 153, 0.3) 0%, transparent 40%);
            pointer-events: none;
        }
        
        .logo-box {
            width: 88px;
            height: 88px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 10;
        }
        
        .logo-box i {
            font-size: 40px;
            background: linear-gradient(135deg, #a5b4fc, #c7d2fe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .input-field {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 14px;
            padding: 16px 18px;
            padding-left: 48px;
            width: 100%;
            font-size: 15px;
            transition: all 0.3s ease;
            color: white;
        }
        
        .input-field::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        
        .input-field:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(99, 102, 241, 0.6);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }
        
        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.4);
            transition: color 0.3s ease;
            font-size: 16px;
        }

        .input-field:focus + .input-icon {
            color: #a5b4fc;
        }
        
        .login-btn {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            font-weight: 600;
            padding: 16px 32px;
            border-radius: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
            border: none;
            font-size: 16px;
            cursor: pointer;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(99, 102, 241, 0.4);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .icon-container {
            width: 48px;
            height: 48px;
            background: rgba(99, 102, 241, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a5b4fc;
            margin-bottom: 20px;
        }
        
        .error-box {
            background: rgba(244, 63, 94, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(244, 63, 94, 0.2);
            color: #fda4af;
            border-radius: 14px;
        }
        
        .checkbox-custom {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .checkbox-custom:checked {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-color: transparent;
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
        
        .text-gradient {
            background: linear-gradient(135deg, #a5b4fc 0%, #c7d2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-5xl">
        <!-- Main Card -->
        <div class="login-container">
            <div class="split-card min-h-[560px]">
                
                <!-- Left Side - Branding -->
                <div class="login-bg hidden md:flex flex-1 flex-col items-center justify-center p-12 text-center">
                    <!-- Logo Box -->
                    <div class="logo-box mb-8">
                        <i class="fas fa-clinic-medical"></i>
                    </div>
                    
                    <h2 class="text-white text-2xl font-bold tracking-wide mb-3 relative z-10">LINE Telepharmacy</h2>
                    <h3 class="text-indigo-200 text-lg font-medium mb-6 relative z-10">Unified Management System</h3>
                    
                    <p class="text-white/70 text-sm max-w-sm leading-relaxed relative z-10">
                        ระบบจัดการร้านขายยาและคลินิกออนไลน์ครบวงจร เชื่อมต่อข้อมูลลูกค้า แชท และคลังสินค้าไว้ในที่เดียว
                    </p>

                    <div class="mt-12 flex gap-4 text-white/50 relative z-10">
                        <i class="fas fa-shield-alt text-xl" title="Secure"></i>
                        <i class="fas fa-sync text-xl" title="Real-time"></i>
                        <i class="fas fa-mobile-alt text-xl" title="Mobile Ready"></i>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="flex-1 flex flex-col justify-center p-8 md:p-14 relative">
                    
                    <div class="max-w-sm w-full mx-auto">
                        <!-- Mobile Header (Hidden on Desktop) -->
                        <div class="md:hidden flex flex-col items-center text-center mb-8">
                            <div class="logo-box mb-4">
                                <i class="fas fa-clinic-medical"></i>
                            </div>
                            <h2 class="text-xl font-bold text-white">Telepharmacy System</h2>
                            <p class="text-sm text-white/60 mt-1">Please sign in to continue</p>
                        </div>

                        <!-- Desktop Header -->
                        <div class="hidden md:block mb-8">
                            <div class="icon-container">
                                <i class="fas fa-sign-in-alt text-xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-white tracking-tight">Welcome Back</h3>
                            <p class="text-white/60 mt-2 text-sm">Sign in to access your dashboard</p>
                        </div>
                        
                        <!-- Error Message -->
                        <?php if ($error): ?>
                        <div class="error-box mb-6 p-4 text-sm flex items-start gap-3">
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Login Form -->
                        <form method="POST" class="space-y-5">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-white/70 ml-1">Username / Email</label>
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
                                    <label class="text-sm font-medium text-white/70">Password</label>
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
                                       class="checkbox-custom">
                                <label for="remember" class="ml-2 text-sm text-white/60 select-none cursor-pointer">Keep me signed in</label>
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
        <p class="text-center text-white/40 text-sm mt-8">
            &copy; <?= date('Y') ?> LINE Telepharmacy Platform. All rights reserved.
        </p>
    </div>
</body>
</html>
