<?php
/**
 * Help Center - คู่มือการใช้งานระบบ
 * Updated: 2026
 */
require_once 'config/config.php';
require_once 'config/database.php';

$pageTitle = 'คู่มือการใช้งาน';
require_once 'includes/header.php';
?>

<style>
.help-card { transition: all 0.3s ease; }
.help-card:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
.accordion-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
.accordion-content.open { max-height: 2000px; }
.step-number { width: 28px; height: 28px; background: linear-gradient(135deg, #06C755 0%, #00a040 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; flex-shrink: 0; }
</style>

<!-- Header -->
<div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-2xl p-8 mb-8 text-white">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold mb-2">📚 คู่มือการใช้งาน</h1>
            <p class="text-green-100">ระบบ LINE CRM + ร้านยา + Telecare</p>
        </div>
        <div class="text-6xl opacity-20"><i class="fas fa-book-open"></i></div>
    </div>
</div>

<!-- Quick Links -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    <a href="#getting-started" class="help-card bg-white rounded-xl p-4 text-center hover:shadow-lg">
        <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-rocket text-blue-500 text-xl"></i>
        </div>
        <div class="font-semibold text-gray-800 text-sm">เริ่มต้นใช้งาน</div>
    </a>
    <a href="#liff-app" class="help-card bg-white rounded-xl p-4 text-center hover:shadow-lg">
        <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mx-auto mb-3">
            <i class="fab fa-line text-green-500 text-xl"></i>
        </div>
        <div class="font-semibold text-gray-800 text-sm">LIFF App</div>
    </a>
    <a href="#pharmacy" class="help-card bg-white rounded-xl p-4 text-center hover:shadow-lg">
        <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-pills text-teal-500 text-xl"></i>
        </div>
        <div class="font-semibold text-gray-800 text-sm">ร้านยา</div>
    </a>
    <a href="#admin-guide" class="help-card bg-white rounded-xl p-4 text-center hover:shadow-lg">
        <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-user-shield text-purple-500 text-xl"></i>
        </div>
        <div class="font-semibold text-gray-800 text-sm">สำหรับแอดมิน</div>
    </a>
    <a href="#faq" class="help-card bg-white rounded-xl p-4 text-center hover:shadow-lg">
        <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-question-circle text-orange-500 text-xl"></i>
        </div>
        <div class="font-semibold text-gray-800 text-sm">FAQ</div>
    </a>
</div>

<!-- Getting Started -->
<div id="getting-started" class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fas fa-rocket text-blue-500 mr-3"></i>เริ่มต้นใช้งาน
        </h2>
    </div>
    <div class="p-6">
        <div class="space-y-6">
            <div class="flex gap-4">
                <div class="step-number">1</div>
                <div class="flex-1">
                    <h4 class="font-semibold text-gray-800 mb-2">ตั้งค่า LINE Official Account</h4>
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
                        <ol class="space-y-2">
                            <li>1. ไปที่ <a href="https://developers.line.biz" target="_blank" class="text-green-600 hover:underline">LINE Developers Console</a></li>
                            <li>2. สร้าง Provider และ Channel (Messaging API)</li>
                            <li>3. คัดลอก Channel Access Token และ Channel Secret</li>
                            <li>4. ไปที่เมนู <a href="settings.php?tab=line" class="text-green-600 hover:underline">ตั้งค่าระบบ > LINE Accounts</a></li>
                            <li>5. ตั้งค่า Webhook URL: <code class="bg-gray-200 px-2 py-1 rounded"><?= BASE_URL ?>/webhook.php</code></li>
                        </ol>
                    </div>
                </div>
            </div>
            
            <div class="flex gap-4">
                <div class="step-number">2</div>
                <div class="flex-1">
                    <h4 class="font-semibold text-gray-800 mb-2">ตั้งค่า LIFF App</h4>
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
                        <ol class="space-y-2">
                            <li>1. ไปที่ <a href="settings.php?tab=liff" class="text-green-600 hover:underline">ตั้งค่าระบบ > LIFF Settings</a></li>
                            <li>2. สร้าง LIFF App ใน LINE Developers Console</li>
                            <li>3. ใช้ Endpoint URL: <code class="bg-gray-200 px-2 py-1 rounded"><?= BASE_URL ?>/liff/</code></li>
                            <li>4. คัดลอก LIFF ID มาใส่ในระบบ</li>
                        </ol>
                    </div>
                </div>
            </div>
            
            <div class="flex gap-4">
                <div class="step-number">3</div>
                <div class="flex-1">
                    <h4 class="font-semibold text-gray-800 mb-2">ตั้งค่าร้านค้า</h4>
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
                        <ol class="space-y-2">
                            <li>1. ไปที่เมนู <a href="shop/settings.php" class="text-green-600 hover:underline">ข้อมูลร้าน</a></li>
                            <li>2. กรอกชื่อร้าน, ที่อยู่, เบอร์โทร</li>
                            <li>3. ตั้งค่าค่าจัดส่งและเงื่อนไขส่งฟรี</li>
                            <li>4. เพิ่มบัญชีธนาคาร/พร้อมเพย์</li>
                        </ol>
                    </div>
                </div>
            </div>
            
            <div class="flex gap-4">
                <div class="step-number">4</div>
                <div class="flex-1">
                    <h4 class="font-semibold text-gray-800 mb-2">ตั้งค่า Rich Menu</h4>
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
                        <ol class="space-y-2">
                            <li>1. ไปที่เมนู <a href="rich-menu.php" class="text-green-600 hover:underline">Rich Menu</a></li>
                            <li>2. สร้าง Rich Menu ใหม่ หรือ Sync จาก LINE</li>
                            <li>3. อัพโหลดรูปภาพ (2500x1686 หรือ 2500x843 px)</li>
                            <li>4. กำหนด Action สำหรับแต่ละปุ่ม</li>
                            <li>5. ตั้งเป็น Default เพื่อแสดงให้ผู้ใช้ทุกคน</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LIFF App -->
<div id="liff-app" class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fab fa-line text-green-500 mr-3"></i>LIFF App (Unified)
        </h2>
    </div>
    <div class="p-6">
        <p class="text-gray-600 mb-4">LIFF App ใหม่รวมทุกฟังก์ชันในที่เดียว ลูกค้าสามารถเข้าถึงได้จาก Rich Menu หรือลิงก์</p>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-green-50 rounded-lg p-4 text-center">
                <div class="text-2xl mb-2">🏠</div>
                <div class="font-semibold text-sm">หน้าหลัก</div>
                <div class="text-xs text-gray-500">Dashboard + Member Card</div>
            </div>
            <div class="bg-purple-50 rounded-lg p-4 text-center">
                <div class="text-2xl mb-2">🛒</div>
                <div class="font-semibold text-sm">ร้านค้า</div>
                <div class="text-xs text-gray-500">สินค้า + ตะกร้า + Checkout</div>
            </div>
            <div class="bg-blue-50 rounded-lg p-4 text-center">
                <div class="text-2xl mb-2">📅</div>
                <div class="font-semibold text-sm">นัดหมาย</div>
                <div class="text-xs text-gray-500">จองคิว + Video Call</div>
            </div>
            <div class="bg-teal-50 rounded-lg p-4 text-center">
                <div class="text-2xl mb-2">💊</div>
                <div class="font-semibold text-sm">ปรึกษาเภสัชกร</div>
                <div class="text-xs text-gray-500">AI Chat + Telecare</div>
            </div>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-yellow-700 text-sm"><i class="fas fa-lightbulb mr-2"></i><strong>Tip:</strong> ใช้ LIFF ID เดียวสำหรับทุกฟังก์ชัน ระบบจะ route ไปหน้าที่ถูกต้องอัตโนมัติ</p>
        </div>
    </div>
</div>

<!-- Pharmacy -->
<div id="pharmacy" class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fas fa-pills text-teal-500 mr-3"></i>ระบบร้านยา (Pharmacy)
        </h2>
    </div>
    <div class="p-6 space-y-4">
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-prescription-bottle-alt text-blue-500 mr-2"></i>จ่ายยา (Dispense)</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-2">
                        <li>ไปที่ <a href="pharmacy.php?tab=dispense" class="text-green-600 hover:underline">ร้านยา > จ่ายยา</a></li>
                        <li>เลือกลูกค้าจากรายการ หรือสร้าง Session ใหม่จากหน้า Inbox</li>
                        <li>ค้นหาและเพิ่มยาที่ต้องการจ่าย</li>
                        <li>ระบบจะตรวจสอบ Drug Interaction อัตโนมัติ</li>
                        <li>กรอกข้อบ่งใช้และคำแนะนำ</li>
                        <li>ยืนยันการจ่ายยา</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-exclamation-triangle text-orange-500 mr-2"></i>Drug Interactions</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <p class="mb-2">ระบบตรวจสอบปฏิกิริยาระหว่างยาอัตโนมัติ:</p>
                    <ul class="list-disc list-inside space-y-1 ml-4">
                        <li><span class="text-red-500 font-semibold">Severe</span> - อันตราย ห้ามใช้ร่วมกัน</li>
                        <li><span class="text-orange-500 font-semibold">Moderate</span> - ควรระวัง ปรึกษาแพทย์</li>
                        <li><span class="text-yellow-500 font-semibold">Mild</span> - มีผลเล็กน้อย</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-user-md text-purple-500 mr-2"></i>เภสัชกร</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-2">
                        <li>ไปที่ <a href="pharmacy.php?tab=pharmacists" class="text-green-600 hover:underline">ร้านยา > เภสัชกร</a></li>
                        <li>เพิ่มข้อมูลเภสัชกร (ชื่อ, เลขใบอนุญาต)</li>
                        <li>กำหนดตารางเวลาทำงาน</li>
                        <li>เภสัชกรจะแสดงใน LIFF สำหรับนัดหมาย Video Call</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Admin Guide -->
<div id="admin-guide" class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fas fa-user-shield text-purple-500 mr-3"></i>คู่มือสำหรับแอดมิน
        </h2>
    </div>
    <div class="p-6 space-y-4">
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-inbox text-blue-500 mr-2"></i>กล่องข้อความ (Inbox)</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <p class="mb-2"><strong>หน้า Inbox แบบ Full Screen:</strong></p>
                    <ul class="list-disc list-inside space-y-1 ml-4">
                        <li>ดูและตอบข้อความแบบ Real-time</li>
                        <li>แสดง Flex Message Preview</li>
                        <li>ปุ่มจ่ายยาสำหรับสร้าง Dispense Session</li>
                        <li>Block/Mute ผู้ใช้ได้</li>
                        <li>ดูประวัติการสั่งซื้อของลูกค้า</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-receipt text-green-500 mr-2"></i>จัดการคำสั่งซื้อ</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <p class="mb-2"><strong>สถานะคำสั่งซื้อ:</strong></p>
                    <ul class="space-y-2 ml-4">
                        <li><span class="px-2 py-1 bg-yellow-100 text-yellow-600 rounded text-xs">pending</span> - รอดำเนินการ</li>
                        <li><span class="px-2 py-1 bg-blue-100 text-blue-600 rounded text-xs">confirmed</span> - ยืนยันแล้ว</li>
                        <li><span class="px-2 py-1 bg-green-100 text-green-600 rounded text-xs">paid</span> - ชำระแล้ว</li>
                        <li><span class="px-2 py-1 bg-purple-100 text-purple-600 rounded text-xs">processing</span> - กำลังจัดเตรียม</li>
                        <li><span class="px-2 py-1 bg-indigo-100 text-indigo-600 rounded text-xs">shipped</span> - จัดส่งแล้ว</li>
                        <li><span class="px-2 py-1 bg-emerald-100 text-emerald-600 rounded text-xs">delivered</span> - ส่งถึงแล้ว</li>
                        <li><span class="px-2 py-1 bg-red-100 text-red-600 rounded text-xs">cancelled</span> - ยกเลิก</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-hand-sparkles text-pink-500 mr-2"></i>ข้อความต้อนรับ</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-2">
                        <li>ไปที่ <a href="settings.php?tab=welcome" class="text-green-600 hover:underline">ตั้งค่าระบบ > ข้อความต้อนรับ</a></li>
                        <li>เลือกประเภท: ข้อความธรรมดา หรือ Flex Message</li>
                        <li>ใส่ข้อความหรือ JSON</li>
                        <li>ใช้ {name} แทนชื่อผู้ใช้</li>
                        <li>บันทึกและทดสอบโดยเพิ่มเพื่อนใหม่</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-th-large text-cyan-500 mr-2"></i>Rich Menu</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-2">
                        <li>ไปที่ <a href="rich-menu.php" class="text-green-600 hover:underline">Rich Menu</a></li>
                        <li>สร้างใหม่ หรือกด "Sync จาก LINE" เพื่อดึงที่มีอยู่</li>
                        <li>อัพโหลดรูปภาพ (จะ resize อัตโนมัติ)</li>
                        <li>ใช้ Template 2x3 หรือ 1x3</li>
                        <li>กำหนด Action: ส่งข้อความ, เปิดลิงก์, หรือสลับ Rich Menu</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold"><i class="fas fa-brain text-indigo-500 mr-2"></i>AI Tools</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ul class="list-disc list-inside space-y-2 ml-4">
                        <li><strong>AI Chat:</strong> คุยกับ AI ทั่วไป</li>
                        <li><strong>Setup Assistant:</strong> ผู้ช่วยตั้งค่าระบบ</li>
                        <li><strong>AI Pharmacy:</strong> ช่วยตอบคำถามเกี่ยวกับยา</li>
                    </ul>
                    <p class="mt-2">ตั้งค่า API Key ที่ <a href="ai-settings.php" class="text-green-600 hover:underline">AI Settings</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FAQ -->
<div id="faq" class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fas fa-question-circle text-orange-500 mr-3"></i>คำถามที่พบบ่อย (FAQ)
        </h2>
    </div>
    <div class="p-6 space-y-4">
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold">บอทไม่ตอบข้อความ?</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-1 ml-4">
                        <li>ตรวจสอบ Webhook URL ใน LINE Developers Console</li>
                        <li>เปิดใช้งาน "Use webhook" แล้วหรือยัง</li>
                        <li>Channel Access Token ยังไม่หมดอายุ</li>
                        <li>ตรวจสอบ error_log บน server</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold">LIFF เปิดไม่ได้?</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-1 ml-4">
                        <li>ตรวจสอบ LIFF ID ถูกต้อง</li>
                        <li>Endpoint URL ต้องเป็น HTTPS</li>
                        <li>ตั้งค่า Linked OA ใน LIFF settings</li>
                        <li>ลองเปิดใน LINE App (ไม่ใช่ browser)</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold">Rich Menu รูปไม่ขึ้น?</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-1 ml-4">
                        <li>กด "Sync จาก LINE" เพื่อดึงข้อมูลใหม่</li>
                        <li>ลบ Rich Menu เก่าที่ไม่มีอยู่แล้ว</li>
                        <li>สร้าง Rich Menu ใหม่พร้อมอัพโหลดรูป</li>
                        <li>รูปต้องเป็น PNG/JPEG ขนาดไม่เกิน 1MB</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold">ข้อความต้อนรับไม่ส่ง?</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <ol class="list-decimal list-inside space-y-1 ml-4">
                        <li>ตรวจสอบว่าเปิดใช้งานแล้ว (toggle เปิด)</li>
                        <li>ถ้าใช้ Flex ตรวจสอบ JSON ถูกต้อง</li>
                        <li>ทดสอบโดยลบเพื่อนแล้วเพิ่มใหม่</li>
                        <li>ตรวจสอบ error_log</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="border rounded-lg">
            <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50">
                <span class="font-semibold">รองรับ LINE OA กี่บัญชี?</span>
                <i class="fas fa-chevron-down text-gray-400 accordion-icon"></i>
            </button>
            <div class="accordion-content">
                <div class="p-4 pt-0 text-sm text-gray-600">
                    <p>ระบบรองรับหลาย LINE OA (Multi-bot):</p>
                    <ul class="list-disc list-inside space-y-1 mt-2 ml-4">
                        <li>แต่ละ LINE OA มีข้อมูลแยกกัน</li>
                        <li>สลับ LINE OA ได้จาก dropdown ด้านบน</li>
                        <li>Admin จัดการได้ทุก LINE OA</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Menu Reference -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-6 border-b">
        <h2 class="text-xl font-bold flex items-center">
            <i class="fas fa-sitemap text-gray-500 mr-3"></i>เมนูหลักในระบบ
        </h2>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">📊 Dashboard</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="dashboard.php" class="hover:text-green-600">• หน้าหลัก</a></li>
                    <li><a href="analytics.php" class="hover:text-green-600">• Analytics</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">💬 LINE CRM</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="inbox.php" class="hover:text-green-600">• กล่องข้อความ</a></li>
                    <li><a href="members.php" class="hover:text-green-600">• สมาชิก</a></li>
                    <li><a href="broadcast.php" class="hover:text-green-600">• Broadcast</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">🛒 ร้านค้า</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="shop/products.php" class="hover:text-green-600">• สินค้า</a></li>
                    <li><a href="shop/orders.php" class="hover:text-green-600">• ออเดอร์</a></li>
                    <li><a href="shop/promotions.php" class="hover:text-green-600">• โปรโมชั่น</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">💊 ร้านยา</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="pharmacy.php" class="hover:text-green-600">• Dashboard</a></li>
                    <li><a href="pharmacy.php?tab=dispense" class="hover:text-green-600">• จ่ายยา</a></li>
                    <li><a href="pharmacy.php?tab=interactions" class="hover:text-green-600">• Drug Interactions</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">📦 คลังสินค้า</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="inventory/" class="hover:text-green-600">• สต็อก</a></li>
                    <li><a href="procurement.php" class="hover:text-green-600">• จัดซื้อ</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">⚙️ ตั้งค่า</h4>
                <ul class="text-gray-600 space-y-1">
                    <li><a href="settings.php" class="hover:text-green-600">• ตั้งค่าระบบ</a></li>
                    <li><a href="rich-menu.php" class="hover:text-green-600">• Rich Menu</a></li>
                    <li><a href="shop/settings.php" class="hover:text-green-600">• ข้อมูลร้าน</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Contact Support -->
<div class="bg-gradient-to-r from-gray-700 to-gray-800 rounded-xl p-8 text-white text-center">
    <h3 class="text-xl font-bold mb-2">ยังมีคำถาม?</h3>
    <p class="text-gray-300 mb-4">ติดต่อทีมซัพพอร์ต</p>
    <div class="flex justify-center gap-4">
        <a href="https://line.me/ti/p/~@reya" target="_blank" class="px-6 py-2 bg-green-500 rounded-lg hover:bg-green-600 transition">
            <i class="fab fa-line mr-2"></i>LINE Support
        </a>
    </div>
</div>

<script>
function toggleAccordion(btn) {
    const content = btn.nextElementSibling;
    const icon = btn.querySelector('.accordion-icon');
    content.classList.toggle('open');
    icon.style.transform = content.classList.contains('open') ? 'rotate(180deg)' : '';
}
</script>

<?php require_once 'includes/footer.php'; ?>
