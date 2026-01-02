<?php
/**
 * AI Studio - Gemini & Imagen
 * ครบวงจร: สร้างรูป, ออกแบบ Flex, แชทบอท, แคปชั่น
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';

$pageTitle = 'AI Studio';

// Get Gemini API Key from multiple sources
$geminiApiKey = '';
$currentBotId = $_SESSION['current_bot_id'] ?? null;
try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Try ai_settings table first (actual column structure)
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
    
    // 3. Try settings table
    if (empty($geminiApiKey)) {
        try {
            $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'gemini_api_key' LIMIT 1");
            $result = $stmt->fetch();
            if ($result && !empty($result['value'])) {
                $geminiApiKey = $result['value'];
            }
        } catch (Exception $e) {}
    }
    
    // 4. Try system_settings table
    if (empty($geminiApiKey)) {
        try {
            $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                $geminiApiKey = $result['setting_value'];
            }
        } catch (Exception $e) {}
    }
    
    // 5. Fallback to config constant
    if (empty($geminiApiKey) && defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
        $geminiApiKey = GEMINI_API_KEY;
    }
} catch (Exception $e) {}

require_once 'includes/header.php';
?>

<style>
.ai-card { transition: all 0.3s; }
.ai-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
.chat-bubble { max-width: 85%; }
.chat-bubble.user { background: linear-gradient(135deg, #06C755, #00B900); }
.chat-bubble.ai { background: #f3f4f6; }
.typing-indicator span { animation: typing 1.4s infinite; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-4px); } }
.tab-btn.active { background: linear-gradient(135deg, #8B5CF6, #6366F1); color: white; }
.flex-preview { font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
</style>

<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">
            <i class="fas fa-robot text-purple-500"></i> AI Studio
        </h1>
        <p class="text-gray-600">สร้างสรรค์ด้วย Gemini 2.0 & Imagen 4</p>
    </div>

    <!-- API Key Status -->
    <div class="mb-6 flex justify-center">
        <button onclick="openApiModal()" id="apiStatusBadge" class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all hover:scale-105 <?= $geminiApiKey ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-yellow-100 text-yellow-700 border border-yellow-200' ?>">
            <i class="fas fa-key"></i>
            <span><?= $geminiApiKey ? 'API Key พร้อมใช้งาน' : 'ยังไม่ได้ตั้งค่า API Key' ?></span>
        </button>
    </div>

    <!-- Tabs -->
    <div class="flex flex-wrap justify-center gap-2 mb-8">
        <button onclick="switchTab('chat')" class="tab-btn active px-6 py-3 rounded-xl font-medium transition-all" data-tab="chat">
            <i class="fas fa-comments mr-2"></i>แชทบอท AI
        </button>
        <button onclick="switchTab('image')" class="tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="image">
            <i class="fas fa-image mr-2"></i>สร้างรูปภาพ
        </button>
        <button onclick="switchTab('flex')" class="tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="flex">
            <i class="fas fa-layer-group mr-2"></i>ออกแบบ Flex
        </button>
        <button onclick="switchTab('caption')" class="tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="caption">
            <i class="fas fa-pen-fancy mr-2"></i>สร้างแคปชั่น
        </button>
        <button onclick="switchTab('translate')" class="tab-btn px-6 py-3 rounded-xl font-medium bg-gray-100 hover:bg-gray-200 transition-all" data-tab="translate">
            <i class="fas fa-language mr-2"></i>แปลภาษา
        </button>
    </div>

    <!-- Tab Contents -->
    <div class="max-w-5xl mx-auto">
        
        <!-- Chat Tab -->
        <div id="tab-chat" class="tab-content">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-4 text-white">
                    <h3 class="font-bold text-lg"><i class="fas fa-robot mr-2"></i>AI Assistant</h3>
                    <p class="text-sm text-purple-200">ถามอะไรก็ได้ - Gemini 2.0 Flash</p>
                </div>
                
                <div id="chatMessages" class="h-96 overflow-y-auto p-4 space-y-4 bg-gray-50">
                    <div class="chat-bubble ai rounded-2xl p-4 text-gray-700">
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
                    <form onsubmit="sendChat(event)" class="flex gap-2">
                        <input type="text" id="chatInput" class="flex-1 px-4 py-3 border rounded-xl focus:ring-2 focus:ring-purple-500" placeholder="พิมพ์ข้อความ...">
                        <button type="submit" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Image Tab -->
        <div id="tab-image" class="tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <!-- Input -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-500 to-rose-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-magic mr-2"></i>สร้างรูปภาพ</h3>
                        <p class="text-sm text-pink-200">Imagen 4.0 - สร้างรูปจริงจาก AI</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียดรูปภาพ (Prompt)</label>
                            <textarea id="imagePrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-pink-500" placeholder="เช่น: กาแฟลาเต้ร้อน ในถ้วยเซรามิกสีขาว วางบนโต๊ะไม้..."></textarea>
                            <p class="text-xs text-gray-500 mt-2"><i class="fas fa-language text-blue-500"></i> รองรับภาษาไทย (แปลอัตโนมัติ)</p>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">สไตล์รูปภาพ</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="setImageStyle('realistic')" class="style-btn active px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📷 สมจริง</button>
                                <button type="button" onclick="setImageStyle('artistic')" class="style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🎨 ศิลปะ</button>
                                <button type="button" onclick="setImageStyle('cartoon')" class="style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🖼️ การ์ตูน</button>
                                <button type="button" onclick="setImageStyle('minimal')" class="style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">⬜ มินิมอล</button>
                                <button type="button" onclick="setImageStyle('product')" class="style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📦 สินค้า</button>
                                <button type="button" onclick="setImageStyle('food')" class="style-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🍔 อาหาร</button>
                            </div>
                        </div>
                        
                        <button onclick="generateImage()" id="btnGenImage" class="w-full py-3 bg-gradient-to-r from-pink-500 to-rose-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-paint-brush mr-2"></i>สร้างรูปภาพ
                        </button>
                        
                        <button onclick="getImageIdeas()" class="w-full mt-2 py-2 border border-pink-300 text-pink-600 rounded-xl text-sm hover:bg-pink-50">
                            <i class="fas fa-lightbulb mr-1"></i> ขอไอเดีย
                        </button>
                    </div>
                </div>
                
                <!-- Result -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-image mr-2"></i>ผลลัพธ์</h3>
                    </div>
                    <div id="imageResult" class="p-6 min-h-[400px] flex items-center justify-center">
                        <div class="text-center text-gray-400">
                            <i class="fas fa-image text-6xl mb-4"></i>
                            <p>รูปภาพจะแสดงที่นี่</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Flex Tab -->
        <div id="tab-flex" class="tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <!-- Input -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-500 to-cyan-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-layer-group mr-2"></i>ออกแบบ Flex Message</h3>
                        <p class="text-sm text-blue-200">สร้าง LINE Flex Message ด้วย AI</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">ประเภท Flex</label>
                            <select id="flexType" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500">
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
                            <textarea id="flexPrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500" placeholder="เช่น: โปรโมทกาแฟลาเต้ ราคา 65 บาท ลด 20% เหลือ 52 บาท มีรูปกาแฟสวยๆ"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">สี Theme</label>
                            <div class="flex gap-2">
                                <button type="button" onclick="setFlexColor('#06C755')" class="w-8 h-8 rounded-full bg-green-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setFlexColor('#3B82F6')" class="w-8 h-8 rounded-full bg-blue-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setFlexColor('#EF4444')" class="w-8 h-8 rounded-full bg-red-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setFlexColor('#F59E0B')" class="w-8 h-8 rounded-full bg-yellow-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setFlexColor('#8B5CF6')" class="w-8 h-8 rounded-full bg-purple-500 border-2 border-white shadow"></button>
                                <button type="button" onclick="setFlexColor('#EC4899')" class="w-8 h-8 rounded-full bg-pink-500 border-2 border-white shadow"></button>
                            </div>
                        </div>
                        
                        <button onclick="generateFlex()" id="btnGenFlex" class="w-full py-3 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-magic mr-2"></i>สร้าง Flex Message
                        </button>
                    </div>
                </div>
                
                <!-- Preview -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-eye mr-2"></i>Preview</h3>
                        <button onclick="copyFlexJson()" class="text-sm text-blue-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy JSON</button>
                    </div>
                    <div id="flexPreview" class="p-6 min-h-[400px] bg-gray-800 flex items-center justify-center">
                        <div class="text-center text-gray-500">
                            <i class="fas fa-layer-group text-6xl mb-4"></i>
                            <p>Flex Message จะแสดงที่นี่</p>
                        </div>
                    </div>
                    <div class="p-4 border-t bg-gray-50">
                        <button onclick="sendFlexToLine()" class="w-full py-2 bg-green-500 text-white rounded-xl hover:bg-green-600">
                            <i class="fab fa-line mr-2"></i>ส่งไปยัง LINE
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- JSON Output -->
            <div class="mt-6 bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="p-4 bg-gray-800 text-white flex justify-between items-center">
                    <h3 class="font-bold"><i class="fas fa-code mr-2"></i>JSON Code</h3>
                    <button onclick="copyFlexJson()" class="text-sm bg-gray-700 px-3 py-1 rounded hover:bg-gray-600"><i class="far fa-copy mr-1"></i>Copy</button>
                </div>
                <pre id="flexJson" class="p-4 bg-gray-900 text-green-400 text-sm overflow-x-auto max-h-64">// Flex JSON จะแสดงที่นี่</pre>
            </div>
        </div>

        <!-- Caption Tab -->
        <div id="tab-caption" class="tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <!-- Input -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-orange-500 to-amber-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-pen-fancy mr-2"></i>สร้างแคปชั่น</h3>
                        <p class="text-sm text-orange-200">แคปชั่นโซเชียลมีเดียสุดปัง</p>
                    </div>
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">ประเภทแคปชั่น</label>
                            <select id="captionType" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-orange-500">
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
                            <textarea id="captionPrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-orange-500" placeholder="เช่น: กาแฟลาเต้ รสชาติเข้มข้น หอมกรุ่น ราคา 65 บาท"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">โทนเสียง</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="setCaptionTone('friendly')" class="tone-btn active px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">😊 เป็นกันเอง</button>
                                <button type="button" onclick="setCaptionTone('professional')" class="tone-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">👔 มืออาชีพ</button>
                                <button type="button" onclick="setCaptionTone('fun')" class="tone-btn px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">🎉 สนุกสนาน</button>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" id="includeHashtags" checked class="rounded text-orange-500">
                                <span class="text-sm text-gray-700">ใส่ Hashtags</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input type="checkbox" id="includeEmoji" checked class="rounded text-orange-500">
                                <span class="text-sm text-gray-700">ใส่ Emoji</span>
                            </label>
                        </div>
                        
                        <button onclick="generateCaption()" id="btnGenCaption" class="w-full py-3 bg-gradient-to-r from-orange-500 to-amber-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-magic mr-2"></i>สร้างแคปชั่น
                        </button>
                    </div>
                </div>
                
                <!-- Result -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-file-alt mr-2"></i>แคปชั่นที่สร้าง</h3>
                        <button onclick="copyCaptionResult()" class="text-sm text-orange-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy</button>
                    </div>
                    <div id="captionResult" class="p-6 min-h-[400px]">
                        <div class="text-center text-gray-400 py-12">
                            <i class="fas fa-pen-fancy text-6xl mb-4"></i>
                            <p>แคปชั่นจะแสดงที่นี่</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Translate Tab -->
        <div id="tab-translate" class="tab-content hidden">
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
                                <select id="sourceLang" class="text-sm border rounded px-2 py-1">
                                    <option value="auto">🔍 ตรวจจับอัตโนมัติ</option>
                                    <option value="th">🇹🇭 ไทย</option>
                                    <option value="en">🇺🇸 อังกฤษ</option>
                                    <option value="zh">🇨🇳 จีน</option>
                                    <option value="ja">🇯🇵 ญี่ปุ่น</option>
                                    <option value="ko">🇰🇷 เกาหลี</option>
                                </select>
                            </div>
                            <textarea id="translateInput" rows="6" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-teal-500" placeholder="พิมพ์ข้อความที่ต้องการแปล..."></textarea>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="text-sm font-medium text-gray-700">ผลลัพธ์</label>
                                <select id="targetLang" class="text-sm border rounded px-2 py-1">
                                    <option value="en">🇺🇸 อังกฤษ</option>
                                    <option value="th">🇹🇭 ไทย</option>
                                    <option value="zh">🇨🇳 จีน</option>
                                    <option value="ja">🇯🇵 ญี่ปุ่น</option>
                                    <option value="ko">🇰🇷 เกาหลี</option>
                                </select>
                            </div>
                            <div id="translateResult" class="w-full h-40 px-4 py-3 border rounded-xl bg-gray-50 overflow-y-auto">
                                <span class="text-gray-400">ผลลัพธ์จะแสดงที่นี่...</span>
                            </div>
                            <button onclick="copyTranslateResult()" class="mt-2 text-sm text-teal-600 hover:underline"><i class="far fa-copy mr-1"></i>Copy</button>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-center">
                        <button onclick="translateText()" id="btnTranslate" class="px-8 py-3 bg-gradient-to-r from-teal-500 to-emerald-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-exchange-alt mr-2"></i>แปลภาษา
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- API Key Modal -->
<div id="apiModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="closeApiModal()"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md">
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-4 rounded-t-2xl">
                <div class="flex justify-between items-center text-white">
                    <h3 class="font-bold text-lg"><i class="fas fa-key mr-2"></i>ตั้งค่า API Key</h3>
                    <button onclick="closeApiModal()" class="hover:opacity-80"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">กรอก Gemini API Key จาก Google AI Studio</p>
                <div class="relative mb-4">
                    <input type="password" id="apiKeyInput" class="w-full px-4 py-3 pr-12 border rounded-xl focus:ring-2 focus:ring-purple-500" placeholder="AIzaSy..." value="<?= htmlspecialchars($geminiApiKey) ?>">
                    <button onclick="toggleApiKeyVisibility()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
                <div id="apiError" class="hidden p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 mb-4"></div>
                <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-sm text-blue-500 hover:underline block mb-4">
                    <i class="fas fa-external-link-alt mr-1"></i>รับ API Key ฟรี
                </a>
                <button onclick="saveApiKey()" id="btnSaveApi" class="w-full py-3 bg-purple-600 text-white rounded-xl font-bold hover:bg-purple-700">
                    <i class="fas fa-check-circle mr-2"></i>บันทึก API Key
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-5 right-5 bg-gray-900 text-white px-6 py-3 rounded-lg shadow-2xl transform translate-y-20 opacity-0 transition-all duration-300 flex items-center gap-3 z-50">
    <i class="fas fa-check-circle text-green-400"></i>
    <span id="toast-message">สำเร็จ</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
// State
let currentApiKey = '<?= addslashes($geminiApiKey) ?>';
let currentImageStyle = 'realistic';
let currentFlexColor = '#06C755';
let currentCaptionTone = 'friendly';
let currentFlexJson = null;
let chatHistory = [];

// Tab Switching
function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('active');
        el.classList.add('bg-gray-100');
    });
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    document.querySelector(`[data-tab="${tab}"]`).classList.remove('bg-gray-100');
}

// API Modal
function openApiModal() { document.getElementById('apiModal').classList.remove('hidden'); }
function closeApiModal() { document.getElementById('apiModal').classList.add('hidden'); }
function toggleApiKeyVisibility() {
    const input = document.getElementById('apiKeyInput');
    const icon = document.getElementById('toggleIcon');
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

async function saveApiKey() {
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    const btn = document.getElementById('btnSaveApi');
    
    if (!apiKey.startsWith('AIza')) {
        document.getElementById('apiError').textContent = 'API Key ไม่ถูกต้อง';
        document.getElementById('apiError').classList.remove('hidden');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ตรวจสอบ...';
    
    try {
        // Test API
        await callGemini("Test", "Reply OK");
        
        // Save to server via FormData (ajax_handler uses POST)
        const formData = new FormData();
        formData.append('action', 'save_gemini_key');
        formData.append('api_key', apiKey);
        await fetch('api/ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        currentApiKey = apiKey;
        document.getElementById('apiStatusBadge').innerHTML = '<i class="fas fa-key"></i><span>API Key พร้อมใช้งาน</span>';
        document.getElementById('apiStatusBadge').className = 'flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium bg-green-100 text-green-700 border border-green-200';
        showToast('บันทึก API Key สำเร็จ!');
        closeApiModal();
    } catch (e) {
        document.getElementById('apiError').textContent = 'API Key ผิดพลาด: ' + e.message;
        document.getElementById('apiError').classList.remove('hidden');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>บันทึก API Key';
    }
}

// Gemini API Call
async function callGemini(text, systemInstruction = '') {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${currentApiKey}`;
    const body = {
        contents: [{ parts: [{ text }] }]
    };
    if (systemInstruction) {
        body.systemInstruction = { parts: [{ text: systemInstruction }] };
    }
    
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    
    if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error?.message || res.status);
    }
    
    const data = await res.json();
    return data.candidates[0].content.parts[0].text;
}

// Imagen API Call
async function callImagen(prompt) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key=${currentApiKey}`;
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            instances: [{ prompt }],
            parameters: { sampleCount: 1 }
        })
    });
    
    if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error?.message || res.status);
    }
    
    const data = await res.json();
    return data.predictions[0].bytesBase64Encoded;
}

// Chat Functions
async function sendChat(e) {
    e.preventDefault();
    if (!currentApiKey) { openApiModal(); return; }
    
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;
    
    // Add user message
    addChatMessage(message, 'user');
    input.value = '';
    
    // Add typing indicator
    const typingId = addTypingIndicator();
    
    try {
        // Build context
        let context = chatHistory.slice(-10).map(m => `${m.role}: ${m.content}`).join('\n');
        const prompt = context ? `${context}\nUser: ${message}` : message;
        
        const response = await callGemini(prompt, 'คุณคือ AI Assistant ที่ช่วยเหลือเรื่องธุรกิจ การตลาด และการขายออนไลน์ ตอบเป็นภาษาไทย กระชับ เป็นมิตร');
        
        // Remove typing indicator
        document.getElementById(typingId)?.remove();
        
        // Add AI response
        addChatMessage(response, 'ai');
        
        // Save to history
        chatHistory.push({ role: 'User', content: message });
        chatHistory.push({ role: 'AI', content: response });
        
    } catch (err) {
        document.getElementById(typingId)?.remove();
        addChatMessage('เกิดข้อผิดพลาด: ' + err.message, 'ai');
    }
}

function addChatMessage(text, type) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = `chat-bubble ${type} rounded-2xl p-4 ${type === 'user' ? 'ml-auto text-white' : 'mr-auto text-gray-700'}`;
    div.innerHTML = marked.parse(text);
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function addTypingIndicator() {
    const container = document.getElementById('chatMessages');
    const id = 'typing-' + Date.now();
    const div = document.createElement('div');
    div.id = id;
    div.className = 'chat-bubble ai rounded-2xl p-4 mr-auto';
    div.innerHTML = '<div class="typing-indicator flex gap-1"><span class="w-2 h-2 bg-gray-400 rounded-full"></span><span class="w-2 h-2 bg-gray-400 rounded-full"></span><span class="w-2 h-2 bg-gray-400 rounded-full"></span></div>';
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return id;
}

// Image Functions
function setImageStyle(style) {
    currentImageStyle = style;
    document.querySelectorAll('.style-btn').forEach(btn => btn.classList.remove('active', 'bg-pink-100', 'border-pink-500'));
    event.target.classList.add('active', 'bg-pink-100', 'border-pink-500');
}

async function generateImage() {
    if (!currentApiKey) { openApiModal(); return; }
    
    const prompt = document.getElementById('imagePrompt').value.trim();
    if (!prompt) { showToast('กรุณากรอก Prompt', true); return; }
    
    const btn = document.getElementById('btnGenImage');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    const resultDiv = document.getElementById('imageResult');
    resultDiv.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin text-4xl text-pink-500 mb-4"></i><p class="text-gray-600">กำลังสร้างรูปภาพ...</p></div>';
    
    try {
        // Enhance prompt with style
        const stylePrompts = {
            realistic: 'photorealistic, high quality, detailed',
            artistic: 'artistic, creative, beautiful art style',
            cartoon: 'cartoon style, colorful, fun',
            minimal: 'minimalist, clean, simple design',
            product: 'product photography, studio lighting, white background',
            food: 'food photography, appetizing, professional'
        };
        
        const enhancedPrompt = await callGemini(
            `Translate to English and enhance for image generation: "${prompt}". Style: ${stylePrompts[currentImageStyle]}`,
            'Output only the enhanced English prompt, nothing else.'
        );
        
        // Generate image
        const imageBase64 = await callImagen(enhancedPrompt);
        const imageUrl = `data:image/png;base64,${imageBase64}`;
        
        resultDiv.innerHTML = `
            <div class="space-y-4">
                <img src="${imageUrl}" class="max-w-full rounded-xl shadow-lg mx-auto">
                <div class="flex gap-2 justify-center">
                    <a href="${imageUrl}" download="ai-image.png" class="px-4 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800">
                        <i class="fas fa-download mr-2"></i>ดาวน์โหลด
                    </a>
                    <button onclick="generateImage()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                        <i class="fas fa-redo mr-2"></i>สร้างใหม่
                    </button>
                </div>
                <div class="text-xs text-gray-500 bg-gray-50 p-3 rounded-lg">
                    <strong>Enhanced Prompt:</strong> ${enhancedPrompt}
                </div>
            </div>
        `;
        
        showToast('สร้างรูปภาพสำเร็จ!');
    } catch (err) {
        resultDiv.innerHTML = `<div class="text-center text-red-500"><i class="fas fa-exclamation-circle text-4xl mb-4"></i><p>${err.message}</p></div>`;
        showToast('เกิดข้อผิดพลาด', true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paint-brush mr-2"></i>สร้างรูปภาพ';
    }
}

async function getImageIdeas() {
    if (!currentApiKey) { openApiModal(); return; }
    
    try {
        const ideas = await callGemini(
            'ขอ 5 ไอเดียสำหรับสร้างรูปภาพสินค้า/อาหาร/ธุรกิจ ที่น่าสนใจ (JSON array of strings)',
            'Output JSON array only, no markdown'
        );
        
        const parsed = JSON.parse(ideas.replace(/```json|```/g, '').trim());
        const ideaHtml = parsed.map(idea => 
            `<button onclick="document.getElementById('imagePrompt').value='${idea.replace(/'/g, "\\'")}';" class="block w-full text-left p-2 bg-pink-50 hover:bg-pink-100 rounded-lg text-sm mb-2">${idea}</button>`
        ).join('');
        
        document.getElementById('imagePrompt').insertAdjacentHTML('afterend', `<div class="mt-2 p-3 bg-gray-50 rounded-lg">${ideaHtml}</div>`);
    } catch (err) {
        showToast('ไม่สามารถโหลดไอเดียได้', true);
    }
}

// Flex Functions
function setFlexColor(color) {
    currentFlexColor = color;
}

async function generateFlex() {
    if (!currentApiKey) { openApiModal(); return; }
    
    const type = document.getElementById('flexType').value;
    const prompt = document.getElementById('flexPrompt').value.trim();
    if (!prompt) { showToast('กรุณากรอกรายละเอียด', true); return; }
    
    const btn = document.getElementById('btnGenFlex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const typePrompts = {
            product: 'สร้าง LINE Flex Message สำหรับโปรโมทสินค้า',
            promo: 'สร้าง LINE Flex Message สำหรับโปรโมชั่น/ส่วนลด',
            menu: 'สร้าง LINE Flex Message สำหรับเมนูร้านอาหาร',
            receipt: 'สร้าง LINE Flex Message สำหรับใบเสร็จ/ออเดอร์',
            welcome: 'สร้าง LINE Flex Message สำหรับข้อความต้อนรับ',
            announce: 'สร้าง LINE Flex Message สำหรับประกาศ/ข่าวสาร',
            booking: 'สร้าง LINE Flex Message สำหรับจองคิว/นัดหมาย',
            custom: 'สร้าง LINE Flex Message ตามที่ระบุ'
        };
        
        const systemPrompt = `คุณคือผู้เชี่ยวชาญ LINE Flex Message
${typePrompts[type]}
ใช้สี theme: ${currentFlexColor}
Output เป็น JSON Flex Message format เท่านั้น (bubble type)
ต้องมี hero, body, footer ครบ
ใช้ action type uri หรือ message ตามความเหมาะสม
ห้ามใส่ markdown หรือ code block`;
        
        const flexJson = await callGemini(prompt, systemPrompt);
        
        // Parse and validate
        let parsed;
        try {
            const cleaned = flexJson.replace(/```json|```/g, '').trim();
            parsed = JSON.parse(cleaned);
        } catch (e) {
            throw new Error('ไม่สามารถ parse JSON ได้');
        }
        
        currentFlexJson = parsed;
        
        // Show preview
        document.getElementById('flexPreview').innerHTML = `
            <div class="flex-preview bg-white rounded-xl shadow-lg max-w-sm mx-auto overflow-hidden">
                <div class="p-4 text-center text-gray-500">
                    <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                    <p>Flex Message สร้างสำเร็จ!</p>
                    <p class="text-xs mt-2">ดู JSON ด้านล่าง</p>
                </div>
            </div>
        `;
        
        // Show JSON
        document.getElementById('flexJson').textContent = JSON.stringify(parsed, null, 2);
        
        showToast('สร้าง Flex Message สำเร็จ!');
    } catch (err) {
        showToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้าง Flex Message';
    }
}

function copyFlexJson() {
    if (!currentFlexJson) { showToast('ยังไม่มี Flex JSON', true); return; }
    navigator.clipboard.writeText(JSON.stringify(currentFlexJson, null, 2));
    showToast('คัดลอก JSON แล้ว!');
}

async function sendFlexToLine() {
    if (!currentFlexJson) { showToast('ยังไม่มี Flex Message', true); return; }
    // TODO: Implement send to LINE
    showToast('ฟีเจอร์นี้กำลังพัฒนา');
}

// Caption Functions
function setCaptionTone(tone) {
    currentCaptionTone = tone;
    document.querySelectorAll('.tone-btn').forEach(btn => btn.classList.remove('active', 'bg-orange-100', 'border-orange-500'));
    event.target.classList.add('active', 'bg-orange-100', 'border-orange-500');
}

async function generateCaption() {
    if (!currentApiKey) { openApiModal(); return; }
    
    const type = document.getElementById('captionType').value;
    const prompt = document.getElementById('captionPrompt').value.trim();
    if (!prompt) { showToast('กรุณากรอกรายละเอียด', true); return; }
    
    const includeHashtags = document.getElementById('includeHashtags').checked;
    const includeEmoji = document.getElementById('includeEmoji').checked;
    
    const btn = document.getElementById('btnGenCaption');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const toneMap = {
            friendly: 'เป็นกันเอง อบอุ่น',
            professional: 'มืออาชีพ น่าเชื่อถือ',
            fun: 'สนุกสนาน ตลก'
        };
        
        const typeMap = {
            product: 'ขายสินค้า',
            food: 'อาหาร/เครื่องดื่ม',
            promo: 'โปรโมชั่น',
            lifestyle: 'ไลฟ์สไตล์',
            motivate: 'สร้างแรงบันดาลใจ',
            funny: 'ตลก/ขำขัน',
            review: 'รีวิว'
        };
        
        const systemPrompt = `สร้างแคปชั่นโซเชียลมีเดียภาษาไทย
ประเภท: ${typeMap[type]}
โทนเสียง: ${toneMap[currentCaptionTone]}
${includeHashtags ? 'ใส่ Hashtags ที่เกี่ยวข้อง 5-10 อัน' : 'ไม่ต้องใส่ Hashtags'}
${includeEmoji ? 'ใส่ Emoji ให้เหมาะสม' : 'ไม่ต้องใส่ Emoji'}
สร้าง 3 เวอร์ชัน: สั้น, กลาง, ยาว`;
        
        const caption = await callGemini(prompt, systemPrompt);
        
        document.getElementById('captionResult').innerHTML = `
            <div class="prose prose-sm max-w-none">
                ${marked.parse(caption)}
            </div>
        `;
        
        showToast('สร้างแคปชั่นสำเร็จ!');
    } catch (err) {
        showToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้างแคปชั่น';
    }
}

function copyCaptionResult() {
    const text = document.getElementById('captionResult').innerText;
    navigator.clipboard.writeText(text);
    showToast('คัดลอกแคปชั่นแล้ว!');
}

// Translate Functions
async function translateText() {
    if (!currentApiKey) { openApiModal(); return; }
    
    const text = document.getElementById('translateInput').value.trim();
    if (!text) { showToast('กรุณากรอกข้อความ', true); return; }
    
    const sourceLang = document.getElementById('sourceLang').value;
    const targetLang = document.getElementById('targetLang').value;
    
    const btn = document.getElementById('btnTranslate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังแปล...';
    
    const langNames = {
        auto: 'ตรวจจับอัตโนมัติ',
        th: 'ไทย',
        en: 'อังกฤษ',
        zh: 'จีน',
        ja: 'ญี่ปุ่น',
        ko: 'เกาหลี'
    };
    
    try {
        const systemPrompt = `แปลข้อความต่อไปนี้${sourceLang !== 'auto' ? `จากภาษา${langNames[sourceLang]}` : ''}เป็นภาษา${langNames[targetLang]}
แปลให้เป็นธรรมชาติ ไม่ใช่แปลตรงตัว
Output เฉพาะคำแปลเท่านั้น ไม่ต้องอธิบาย`;
        
        const result = await callGemini(text, systemPrompt);
        
        document.getElementById('translateResult').innerHTML = `<div class="text-gray-700">${result}</div>`;
        
        showToast('แปลภาษาสำเร็จ!');
    } catch (err) {
        document.getElementById('translateResult').innerHTML = `<div class="text-red-500">${err.message}</div>`;
        showToast('เกิดข้อผิดพลาด', true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-exchange-alt mr-2"></i>แปลภาษา';
    }
}

function copyTranslateResult() {
    const text = document.getElementById('translateResult').innerText;
    navigator.clipboard.writeText(text);
    showToast('คัดลอกแล้ว!');
}

// Utility Functions
function showToast(msg, isError = false) {
    const toast = document.getElementById('toast');
    document.getElementById('toast-message').textContent = msg;
    toast.querySelector('i').className = isError ? 'fas fa-exclamation-circle text-red-400' : 'fas fa-check-circle text-green-400';
    toast.classList.remove('translate-y-20', 'opacity-0');
    setTimeout(() => toast.classList.add('translate-y-20', 'opacity-0'), 3000);
}

// Init
document.addEventListener('DOMContentLoaded', function() {
    if (!currentApiKey) {
        openApiModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
