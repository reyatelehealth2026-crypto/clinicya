<?php
/**
 * AI Studio Tab - Gemini & Imagen
 * ครบวงจร: สร้างรูป, ออกแบบ Flex, แชทบอท, แคปชั่น
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Get Gemini API Key from multiple sources
$geminiApiKey = '';
$currentBotId = $_SESSION['current_bot_id'] ?? null;
try {
    // 1. Try ai_settings table first
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$currentBotId]);
    } else {
        $stmt = $db->query("SELECT gemini_api_key FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
    }
    $result = $stmt->fetch();
    if ($result && !empty($result['gemini_api_key'])) {
        $geminiApiKey = $result['gemini_api_key'];
    }
    
    // 2. Try line_accounts table
    if (empty($geminiApiKey) && $currentBotId) {
        try {
            $stmt = $db->prepare("SELECT gemini_api_key FROM line_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$currentBotId]);
            $result = $stmt->fetch();
            if ($result && !empty($result['gemini_api_key'])) {
                $geminiApiKey = $result['gemini_api_key'];
            }
        } catch (Exception $e) {}
    }
    
    // 3. Fallback to config constant
    if (empty($geminiApiKey) && defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
        $geminiApiKey = GEMINI_API_KEY;
    }
} catch (Exception $e) {}
?>

<style>
.studio-card { transition: all 0.3s; }
.studio-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
.studio-chat-bubble { max-width: 85%; }
.studio-chat-bubble.user { background: linear-gradient(135deg, #06C755, #00B900); }
.studio-chat-bubble.ai { background: #f3f4f6; }
.studio-typing-indicator span { animation: studioTyping 1.4s infinite; }
.studio-typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.studio-typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
@keyframes studioTyping { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-4px); } }
.studio-tab-btn.active { background: linear-gradient(135deg, #8B5CF6, #6366F1); color: white; }
</style>

<div class="container mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">
            <i class="fas fa-robot text-purple-500"></i> AI Studio
        </h1>
        <p class="text-gray-600">สร้างสรรค์ด้วย Gemini 2.0 & Imagen 4</p>
    </div>

    <!-- API Key Status -->
    <div class="mb-6 flex justify-center">
        <button onclick="openStudioApiModal()" id="studioApiStatusBadge" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all hover:scale-105 <?= $geminiApiKey ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-yellow-100 text-yellow-700 border border-yellow-200' ?>">
            <i class="fas fa-key"></i>
            <span><?= $geminiApiKey ? 'API Key พร้อมใช้งาน' : 'ยังไม่ได้ตั้งค่า API Key' ?></span>
        </button>
    </div>

    <!-- Studio Tabs -->
    <div class="flex flex-wrap justify-center gap-2 mb-8">
        <button onclick="switchStudioTab('chat')" class="studio-tab-btn active px-6 py-3 rounded-xl font-medium transition-all" data-tab="chat">
            <i class="fas fa-comments mr-2"></i>แชทบอท AI
        </button>
        <button onclick="switchStudioTab('image')" class="studio-tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="image">
            <i class="fas fa-image mr-2"></i>สร้างรูปภาพ
        </button>
        <button onclick="switchStudioTab('flex')" class="studio-tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="flex">
            <i class="fas fa-layer-group mr-2"></i>ออกแบบ Flex
        </button>
        <button onclick="switchStudioTab('caption')" class="studio-tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="caption">
            <i class="fas fa-pen-fancy mr-2"></i>สร้างแคปชั่น
        </button>
        <button onclick="switchStudioTab('translate')" class="studio-tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="translate">
            <i class="fas fa-language mr-2"></i>แปลภาษา
        </button>
    </div>

    <!-- Tab Contents -->
    <div class="max-w-5xl mx-auto">
        
        <!-- Chat Tab -->
        <div id="studio-tab-chat" class="studio-tab-content">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-4 text-white">
                    <h3 class="font-bold text-lg"><i class="fas fa-robot mr-2"></i>AI Assistant</h3>
                    <p class="text-sm text-purple-200">ถามอะไรก็ได้ - Gemini 2.0 Flash</p>
                </div>
                
                <div id="studioChatMessages" class="h-96 overflow-y-auto p-4 space-y-4 bg-gray-50">
                    <div class="studio-chat-bubble ai rounded-2xl p-4 text-gray-700">
                        <p>สวัสดีครับ! 👋 ผมคือ AI Assistant พร้อมช่วยเหลือคุณ</p>
                        <p class="text-sm mt-2">ลองถามอะไรก็ได้ เช่น:</p>
                        <ul class="text-sm mt-1 space-y-1 text-gray-600">
                            <li>• เขียนแคปชั่นขายสินค้า</li>
                            <li>• ช่วยคิดไอเดียโปรโมชั่น</li>
                            <li>• แปลภาษา</li>
                            <li>• ตอบคำถามทั่วไป</li>
                        </ul>
                    </div>
                </div>
                
                <div class="p-4 border-t bg-white">
                    <form onsubmit="sendStudioChat(event)" class="flex gap-2">
                        <input type="text" id="studioChatInput" class="flex-1 px-4 py-3 border rounded-xl focus:ring-2 focus:ring-purple-500" placeholder="พิมพ์ข้อความ...">
                        <button type="submit" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Image Tab -->
        <div id="studio-tab-image" class="studio-tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-500 to-rose-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-magic mr-2"></i>สร้างรูปภาพ</h3>
                        <p class="text-sm text-pink-200">Imagen 4.0 - สร้างรูปจริงจาก AI</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียดรูปภาพ (Prompt)</label>
                            <textarea id="studioImagePrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-pink-500" placeholder="เช่น: กาแฟลาเต้ร้อน ในถ้วยเซรามิกสีขาว วางบนโต๊ะไม้..."></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">สไตล์รูปภาพ</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="setStudioImageStyle('realistic')" class="studio-style-btn active px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📷 สมจริง</button>
                                <button type="button" onclick="setStudioImageStyle('artistic')" class="studio-style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🎨 ศิลปะ</button>
                                <button type="button" onclick="setStudioImageStyle('cartoon')" class="studio-style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🖼️ การ์ตูน</button>
                                <button type="button" onclick="setStudioImageStyle('minimal')" class="studio-style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">⬜ มินิมอล</button>
                                <button type="button" onclick="setStudioImageStyle('product')" class="studio-style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📦 สินค้า</button>
                                <button type="button" onclick="setStudioImageStyle('food')" class="studio-style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🍔 อาหาร</button>
                            </div>
                        </div>
                        
                        <button onclick="generateStudioImage()" id="btnStudioGenImage" class="w-full py-3 bg-gradient-to-r from-pink-500 to-rose-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-paint-brush mr-2"></i>สร้างรูปภาพ
                        </button>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-image mr-2"></i>ผลลัพธ์</h3>
                    </div>
                    <div id="studioImageResult" class="p-6 min-h-[400px] flex items-center justify-center">
                        <div class="text-center text-gray-400">
                            <i class="fas fa-image text-6xl mb-4"></i>
                            <p>รูปภาพจะแสดงที่นี่</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Flex Tab -->
        <div id="studio-tab-flex" class="studio-tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-cyan-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-layer-group mr-2"></i>ออกแบบ Flex Message</h3>
                        <p class="text-sm text-blue-200">สร้าง LINE Flex Message ด้วย AI</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">ประเภท Flex</label>
                            <select id="studioFlexType" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500">
                                <option value="product">🛍️ โปรโมทสินค้า</option>
                                <option value="promo">🎉 โปรโมชั่น/ส่วนลด</option>
                                <option value="menu">📋 เมนูร้านอาหาร</option>
                                <option value="receipt">🧾 ใบเสร็จ/ออเดอร์</option>
                                <option value="welcome">👋 ข้อความต้อนรับ</option>
                                <option value="announce">📢 ประกาศ/ข่าวสาร</option>
                                <option value="booking">📅 จองคิว/นัดหมาย</option>
                                <option value="custom">✨ กำหนดเอง</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียด</label>
                            <textarea id="studioFlexPrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500" placeholder="เช่น: โปรโมทกาแฟลาเต้ ราคา 65 บาท ลด 20% เหลือ 52 บาท"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">สี Theme</label>
                            <div class="flex gap-2">
                                <button type="button" onclick="setStudioFlexColor('#06C755')" class="w-8 h-8 rounded-full bg-green-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#3B82F6')" class="w-8 h-8 rounded-full bg-blue-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#EF4444')" class="w-8 h-8 rounded-full bg-red-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#F59E0B')" class="w-8 h-8 rounded-full bg-yellow-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#8B5CF6')" class="w-8 h-8 rounded-full bg-purple-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#EC4899')" class="w-8 h-8 rounded-full bg-pink-500 border-2 border-white shadow"></button>
                            </div>
                        </div>
                        
                        <button onclick="generateStudioFlex()" id="btnStudioGenFlex" class="w-full py-3 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-magic mr-2"></i>สร้าง Flex Message
                        </button>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-eye mr-2"></i>Preview</h3>
                        <button onclick="copyStudioFlexJson()" class="text-sm text-blue-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy JSON</button>
                    </div>
                    <div id="studioFlexPreview" class="p-6 min-h-[400px] bg-gray-800 flex items-center justify-center">
                        <div class="text-center text-gray-500">
                            <i class="fas fa-layer-group text-6xl mb-4"></i>
                            <p>Flex Message จะแสดงที่นี่</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-6 bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="p-4 bg-gray-800 text-white flex justify-between items-center">
                    <h3 class="font-bold"><i class="fas fa-code mr-2"></i>JSON Code</h3>
                    <button onclick="copyStudioFlexJson()" class="text-sm bg-gray-700 px-3 py-1 rounded hover:bg-gray-600"><i class="far fa-copy mr-1"></i>Copy</button>
                </div>
                <pre id="studioFlexJson" class="p-4 bg-gray-900 text-green-400 text-sm overflow-x-auto max-h-64">// Flex JSON จะแสดงที่นี่</pre>
            </div>
        </div>

        <!-- Caption Tab -->
        <div id="studio-tab-caption" class="studio-tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-orange-500 to-amber-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-pen-fancy mr-2"></i>สร้างแคปชั่น</h3>
                        <p class="text-sm text-orange-200">แคปชั่นโซเชียลมีเดียสุดปัง</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">ประเภทแคปชั่น</label>
                            <select id="studioCaptionType" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-orange-500">
                                <option value="product">🛍️ ขายสินค้า</option>
                                <option value="food">🍔 อาหาร/เครื่องดื่ม</option>
                                <option value="promo">🎉 โปรโมชั่น</option>
                                <option value="lifestyle">✨ ไลฟ์สไตล์</option>
                                <option value="motivate">💪 สร้างแรงบันดาลใจ</option>
                                <option value="funny">😂 ตลก/ขำขัน</option>
                                <option value="review">⭐ รีวิว</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียดสินค้า/เนื้อหา</label>
                            <textarea id="studioCaptionPrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-orange-500" placeholder="เช่น: กาแฟลาเต้ รสชาติเข้มข้น หอมกรุ่น ราคา 65 บาท"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" id="studioIncludeHashtags" checked class="rounded text-orange-500">
                                <span class="text-sm text-gray-700">ใส่ Hashtags</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input type="checkbox" id="studioIncludeEmoji" checked class="rounded text-orange-500">
                                <span class="text-sm text-gray-700">ใส่ Emoji</span>
                            </label>
                        </div>
                        
                        <button onclick="generateStudioCaption()" id="btnStudioGenCaption" class="w-full py-3 bg-gradient-to-r from-orange-500 to-amber-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-magic mr-2"></i>สร้างแคปชั่น
                        </button>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-file-alt mr-2"></i>แคปชั่นที่สร้าง</h3>
                        <button onclick="copyStudioCaptionResult()" class="text-sm text-orange-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy</button>
                    </div>
                    <div id="studioCaptionResult" class="p-6 min-h-[400px]">
                        <div class="text-center text-gray-400 py-12">
                            <i class="fas fa-pen-fancy text-6xl mb-4"></i>
                            <p>แคปชั่นจะแสดงที่นี่</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Translate Tab -->
        <div id="studio-tab-translate" class="studio-tab-content hidden">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-gradient-to-r from-teal-500 to-emerald-500 p-4 text-white">
                    <h3 class="font-bold text-lg"><i class="fas fa-language mr-2"></i>แปลภาษา</h3>
                    <p class="text-sm text-teal-200">แปลภาษาด้วย AI อัจฉริยะ</p>
                </div>
                <div class="p-6">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-sm font-medium text-gray-700">ข้อความต้นฉบับ</label>
                                <select id="studioSourceLang" class="text-sm border rounded px-2 py-1">
                                    <option value="auto">🔍 ตรวจจับอัตโนมัติ</option>
                                    <option value="th">🇹🇭 ไทย</option>
                                    <option value="en">🇺🇸 อังกฤษ</option>
                                    <option value="zh">🇨🇳 จีน</option>
                                    <option value="ja">🇯🇵 ญี่ปุ่น</option>
                                    <option value="ko">🇰🇷 เกาหลี</option>
                                </select>
                            </div>
                            <textarea id="studioTranslateInput" rows="6" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-teal-500" placeholder="พิมพ์ข้อความที่ต้องการแปล..."></textarea>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-sm font-medium text-gray-700">ผลลัพธ์</label>
                                <select id="studioTargetLang" class="text-sm border rounded px-2 py-1">
                                    <option value="en">🇺🇸 อังกฤษ</option>
                                    <option value="th">🇹🇭 ไทย</option>
                                    <option value="zh">🇨🇳 จีน</option>
                                    <option value="ja">🇯🇵 ญี่ปุ่น</option>
                                    <option value="ko">🇰🇷 เกาหลี</option>
                                </select>
                            </div>
                            <div id="studioTranslateResult" class="w-full h-40 px-4 py-3 border rounded-xl bg-gray-50 overflow-y-auto">
                                <span class="text-gray-400">ผลลัพธ์จะแสดงที่นี่...</span>
                            </div>
                            <button onclick="copyStudioTranslateResult()" class="mt-2 text-sm text-teal-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy</button>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-center">
                        <button onclick="translateStudioText()" id="btnStudioTranslate" class="px-8 py-3 bg-gradient-to-r from-teal-500 to-emerald-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-exchange-alt mr-2"></i>แปลภาษา
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- API Key Modal -->
<div id="studioApiModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="closeStudioApiModal()"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md">
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-4 rounded-t-2xl">
                <div class="flex justify-between items-center text-white">
                    <h3 class="font-bold text-lg"><i class="fas fa-key mr-2"></i>ตั้งค่า API Key</h3>
                    <button onclick="closeStudioApiModal()" class="hover:opacity-80"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">กรอก Gemini API Key จาก Google AI Studio</p>
                <div class="relative mb-4">
                    <input type="password" id="studioApiKeyInput" class="w-full px-4 py-3 pr-12 border rounded-xl focus:ring-2 focus:ring-purple-500" placeholder="AIzaSy..." value="<?= htmlspecialchars($geminiApiKey) ?>">
                    <button onclick="toggleStudioApiKeyVisibility()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <i class="fas fa-eye" id="studioToggleIcon"></i>
                    </button>
                </div>
                <div id="studioApiError" class="hidden p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 mb-4"></div>
                <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-sm text-blue-500 hover:underline block mb-4">
                    <i class="fas fa-external-link-alt mr-1"></i>รับ API Key ฟรี
                </a>
                <button onclick="saveStudioApiKey()" id="btnStudioSaveApi" class="w-full py-3 bg-purple-600 text-white rounded-xl font-bold hover:bg-purple-700">
                    <i class="fas fa-check-circle mr-2"></i>บันทึก API Key
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="studioToast" class="fixed bottom-5 right-5 bg-gray-900 text-white px-6 py-3 rounded-lg shadow-2xl transform translate-y-20 opacity-0 transition-all duration-300 flex items-center gap-3 z-50">
    <i class="fas fa-check-circle text-green-400"></i>
    <span id="studioToastMessage">สำเร็จ</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
// State
let studioCurrentApiKey = '<?= addslashes($geminiApiKey) ?>';
let studioCurrentImageStyle = 'realistic';
let studioCurrentFlexColor = '#06C755';
let studioCurrentFlexJson = null;
let studioChatHistory = [];

// Tab Switching
function switchStudioTab(tab) {
    document.querySelectorAll('.studio-tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.studio-tab-btn').forEach(el => {
        el.classList.remove('active');
        el.classList.add('bg-gray-100');
    });
    document.getElementById('studio-tab-' + tab).classList.remove('hidden');
    document.querySelector(`.studio-tab-btn[data-tab="${tab}"]`).classList.add('active');
    document.querySelector(`.studio-tab-btn[data-tab="${tab}"]`).classList.remove('bg-gray-100');
}

// API Modal
function openStudioApiModal() { document.getElementById('studioApiModal').classList.remove('hidden'); }
function closeStudioApiModal() { document.getElementById('studioApiModal').classList.add('hidden'); }
function toggleStudioApiKeyVisibility() {
    const input = document.getElementById('studioApiKeyInput');
    const icon = document.getElementById('studioToggleIcon');
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

function showStudioToast(message, isError = false) {
    const toast = document.getElementById('studioToast');
    const msgEl = document.getElementById('studioToastMessage');
    msgEl.textContent = message;
    toast.querySelector('i').className = isError ? 'fas fa-exclamation-circle text-red-400' : 'fas fa-check-circle text-green-400';
    toast.classList.remove('translate-y-20', 'opacity-0');
    setTimeout(() => toast.classList.add('translate-y-20', 'opacity-0'), 3000);
}

async function saveStudioApiKey() {
    const apiKey = document.getElementById('studioApiKeyInput').value.trim();
    const btn = document.getElementById('btnStudioSaveApi');
    
    if (!apiKey.startsWith('AIza')) {
        document.getElementById('studioApiError').textContent = 'API Key ไม่ถูกต้อง';
        document.getElementById('studioApiError').classList.remove('hidden');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ตรวจสอบ...';
    
    try {
        await callStudioGemini("Test", "Reply OK");
        
        const formData = new FormData();
        formData.append('action', 'save_gemini_key');
        formData.append('api_key', apiKey);
        await fetch('api/ajax_handler.php', { method: 'POST', body: formData });
        
        studioCurrentApiKey = apiKey;
        document.getElementById('studioApiStatusBadge').innerHTML = '<i class="fas fa-key"></i><span>API Key พร้อมใช้งาน</span>';
        document.getElementById('studioApiStatusBadge').className = 'flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium bg-green-100 text-green-700 border border-green-200';
        showStudioToast('บันทึก API Key สำเร็จ!');
        closeStudioApiModal();
    } catch (e) {
        document.getElementById('studioApiError').textContent = 'API Key ผิดพลาด: ' + e.message;
        document.getElementById('studioApiError').classList.remove('hidden');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>บันทึก API Key';
    }
}

// Gemini API Call
async function callStudioGemini(text, systemInstruction = '') {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${studioCurrentApiKey}`;
    const body = { contents: [{ parts: [{ text }] }] };
    if (systemInstruction) body.systemInstruction = { parts: [{ text: systemInstruction }] };
    
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    if (!res.ok) { const err = await res.json(); throw new Error(err.error?.message || res.status); }
    const data = await res.json();
    return data.candidates[0].content.parts[0].text;
}

// Chat Functions
async function sendStudioChat(e) {
    e.preventDefault();
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    
    const input = document.getElementById('studioChatInput');
    const message = input.value.trim();
    if (!message) return;
    
    addStudioChatMessage(message, 'user');
    input.value = '';
    const typingId = addStudioTypingIndicator();
    
    try {
        let context = studioChatHistory.slice(-10).map(m => `${m.role}: ${m.content}`).join('\n');
        const prompt = context ? `${context}\nUser: ${message}` : message;
        const response = await callStudioGemini(prompt, 'คุณคือ AI Assistant ที่ช่วยเหลือเรื่องธุรกิจ การตลาด และการขายออนไลน์ ตอบเป็นภาษาไทย กระชับ เป็นมิตร');
        
        document.getElementById(typingId)?.remove();
        addStudioChatMessage(response, 'ai');
        studioChatHistory.push({ role: 'User', content: message });
        studioChatHistory.push({ role: 'AI', content: response });
    } catch (err) {
        document.getElementById(typingId)?.remove();
        addStudioChatMessage('เกิดข้อผิดพลาด: ' + err.message, 'ai');
    }
}

function addStudioChatMessage(text, type) {
    const container = document.getElementById('studioChatMessages');
    const div = document.createElement('div');
    div.className = `studio-chat-bubble ${type} rounded-2xl p-4 ${type === 'user' ? 'ml-auto text-white' : 'mr-auto text-gray-700'}`;
    div.innerHTML = typeof marked !== 'undefined' ? marked.parse(text) : text;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function addStudioTypingIndicator() {
    const container = document.getElementById('studioChatMessages');
    const id = 'studio-typing-' + Date.now();
    const div = document.createElement('div');
    div.id = id;
    div.className = 'studio-chat-bubble ai rounded-2xl p-4 mr-auto';
    div.innerHTML = '<div class="studio-typing-indicator flex gap-1"><span class="w-2 h-2 bg-gray-400 rounded-full"></span><span class="w-2 h-2 bg-gray-400 rounded-full"></span><span class="w-2 h-2 bg-gray-400 rounded-full"></span></div>';
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return id;
}

// Image Functions
function setStudioImageStyle(style) { studioCurrentImageStyle = style; }

async function generateStudioImage() {
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    const prompt = document.getElementById('studioImagePrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอก Prompt', true); return; }
    
    const btn = document.getElementById('btnStudioGenImage');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    document.getElementById('studioImageResult').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin text-4xl text-pink-500 mb-4"></i><p class="text-gray-600">กำลังสร้างรูปภาพ...</p></div>';
    
    try {
        const stylePrompts = { realistic: 'photorealistic, high quality', artistic: 'artistic, creative', cartoon: 'cartoon style', minimal: 'minimalist, clean', product: 'product photography', food: 'food photography' };
        const enhancedPrompt = await callStudioGemini(`Translate to English and enhance: "${prompt}". Style: ${stylePrompts[studioCurrentImageStyle]}`, 'Output only the enhanced English prompt');
        
        const url = `https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key=${studioCurrentApiKey}`;
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ instances: [{ prompt: enhancedPrompt }], parameters: { sampleCount: 1 } }) });
        if (!res.ok) throw new Error('ไม่สามารถสร้างรูปภาพได้');
        const data = await res.json();
        const imageUrl = `data:image/png;base64,${data.predictions[0].bytesBase64Encoded}`;
        
        document.getElementById('studioImageResult').innerHTML = `<div class="space-y-4"><img src="${imageUrl}" class="max-w-full rounded-xl shadow-lg mx-auto"><div class="flex gap-2 justify-center"><a href="${imageUrl}" download="ai-image.png" class="px-4 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800"><i class="fas fa-download mr-2"></i>ดาวน์โหลด</a></div></div>`;
        showStudioToast('สร้างรูปภาพสำเร็จ!');
    } catch (err) {
        document.getElementById('studioImageResult').innerHTML = `<div class="text-center text-red-500"><i class="fas fa-exclamation-circle text-4xl mb-4"></i><p>${err.message}</p></div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paint-brush mr-2"></i>สร้างรูปภาพ';
    }
}

// Flex Functions
function setStudioFlexColor(color) { studioCurrentFlexColor = color; }

async function generateStudioFlex() {
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    const prompt = document.getElementById('studioFlexPrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอกรายละเอียด', true); return; }
    
    const btn = document.getElementById('btnStudioGenFlex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const type = document.getElementById('studioFlexType').value;
        const systemPrompt = `สร้าง LINE Flex Message JSON สำหรับ ${type} ใช้สี: ${studioCurrentFlexColor} Output JSON เท่านั้น ห้ามใส่ markdown`;
        const flexJson = await callStudioGemini(prompt, systemPrompt);
        
        const cleaned = flexJson.replace(/```json|```/g, '').trim();
        studioCurrentFlexJson = JSON.parse(cleaned);
        
        document.getElementById('studioFlexPreview').innerHTML = '<div class="text-center text-white"><i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i><p>Flex Message สร้างสำเร็จ!</p></div>';
        document.getElementById('studioFlexJson').textContent = JSON.stringify(studioCurrentFlexJson, null, 2);
        showStudioToast('สร้าง Flex Message สำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้าง Flex Message';
    }
}

function copyStudioFlexJson() {
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex JSON', true); return; }
    navigator.clipboard.writeText(JSON.stringify(studioCurrentFlexJson, null, 2));
    showStudioToast('คัดลอก JSON แล้ว!');
}

// Caption Functions
async function generateStudioCaption() {
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    const prompt = document.getElementById('studioCaptionPrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอกรายละเอียด', true); return; }
    
    const btn = document.getElementById('btnStudioGenCaption');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const type = document.getElementById('studioCaptionType').value;
        const includeHashtags = document.getElementById('studioIncludeHashtags').checked;
        const includeEmoji = document.getElementById('studioIncludeEmoji').checked;
        
        const systemPrompt = `สร้างแคปชั่นโซเชียลมีเดียภาษาไทย ประเภท: ${type} ${includeHashtags ? 'ใส่ Hashtags 5-10 อัน' : ''} ${includeEmoji ? 'ใส่ Emoji' : ''} สร้าง 3 เวอร์ชัน: สั้น, กลาง, ยาว`;
        const caption = await callStudioGemini(prompt, systemPrompt);
        
        document.getElementById('studioCaptionResult').innerHTML = `<div class="prose prose-sm max-w-none">${typeof marked !== 'undefined' ? marked.parse(caption) : caption}</div>`;
        showStudioToast('สร้างแคปชั่นสำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้างแคปชั่น';
    }
}

function copyStudioCaptionResult() {
    navigator.clipboard.writeText(document.getElementById('studioCaptionResult').innerText);
    showStudioToast('คัดลอกแคปชั่นแล้ว!');
}

// Translate Functions
async function translateStudioText() {
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    const text = document.getElementById('studioTranslateInput').value.trim();
    if (!text) { showStudioToast('กรุณากรอกข้อความ', true); return; }
    
    const btn = document.getElementById('btnStudioTranslate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังแปล...';
    
    const langNames = { auto: 'ตรวจจับอัตโนมัติ', th: 'ไทย', en: 'อังกฤษ', zh: 'จีน', ja: 'ญี่ปุ่น', ko: 'เกาหลี' };
    const sourceLang = document.getElementById('studioSourceLang').value;
    const targetLang = document.getElementById('studioTargetLang').value;
    
    try {
        const systemPrompt = `แปลข้อความ${sourceLang !== 'auto' ? `จากภาษา${langNames[sourceLang]}` : ''}เป็นภาษา${langNames[targetLang]} แปลให้เป็นธรรมชาติ Output เฉพาะคำแปล`;
        const result = await callStudioGemini(text, systemPrompt);
        document.getElementById('studioTranslateResult').innerHTML = result;
        showStudioToast('แปลภาษาสำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-exchange-alt mr-2"></i>แปลภาษา';
    }
}

function copyStudioTranslateResult() {
    navigator.clipboard.writeText(document.getElementById('studioTranslateResult').innerText);
    showStudioToast('คัดลอกผลลัพธ์แล้ว!');
}
</script>
