<?php
/**
 * signup.php — public landing for self-serve shop signup.
 *
 * Single CTA: "Sign up / sign in with Google" → auth/google-start.php.
 * No DB needed; this is a static marketing/entry page.
 */
declare(strict_types=1);
$base = 're-ya.com';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เปิดร้านยาออนไลน์กับ REYA — สมัครฟรี</title>
    <meta name="description" content="เปิดร้านยา/คลินิกออนไลน์บน LINE กับ REYA — สมัครด้วย Google ใช้งานได้ทันที">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-50 via-white to-slate-100 flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-emerald-600 text-white text-3xl font-bold shadow-lg mb-4">R</div>
            <h1 class="text-3xl font-extrabold text-slate-900 leading-tight">เปิดร้านยาออนไลน์<br>ในไม่กี่นาที</h1>
            <p class="text-slate-500 mt-3">จัดการ LINE OA, ขายของ, สะสมแต้ม, จ่ายยา — ครบในที่เดียว</p>
        </div>

        <div class="bg-white rounded-3xl border border-slate-200 shadow-xl p-8">
            <ul class="space-y-2.5 text-sm text-slate-600 mb-6">
                <li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> เว็บร้านของคุณเอง <span class="font-mono text-xs bg-slate-100 px-1.5 py-0.5 rounded">ชื่อร้าน.<?= htmlspecialchars($base) ?></span></li>
                <li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> ระบบสมาชิก + สะสมแต้ม</li>
                <li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> ร้านค้าออนไลน์ + ตะกร้า + ชำระเงิน</li>
                <li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> สมัครฟรี เริ่มใช้ได้ทันที</li>
            </ul>

            <a href="/auth/google-start.php"
               class="flex items-center justify-center gap-3 w-full bg-white border-2 border-slate-200 hover:border-emerald-400 hover:shadow-md transition rounded-xl py-3.5 font-semibold text-slate-700">
                <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5h-1.9V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.5-5.2l-6.2-5.3C29.2 35 26.7 36 24 36c-5.3 0-9.7-3.1-11.3-7.6l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H24v8h11.3c-.8 2.2-2.2 4.1-4 5.5l6.2 5.3C41.2 36.4 44 30.8 44 24c0-1.3-.1-2.3-.4-3.5z"/></svg>
                สมัคร / เข้าสู่ระบบด้วย Google
            </a>
            <p class="text-xs text-slate-400 text-center mt-4">เมื่อสมัคร ถือว่ายอมรับเงื่อนไขการใช้งานของ REYA</p>
        </div>
    </div>
</body>
</html>
