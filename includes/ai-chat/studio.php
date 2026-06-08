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
                    <p class="text-sm text-purple-200">ถามอะไรก็ได้ — Gemini ล่าสุด (ใช้ key ร้านอัตโนมัติ)</p>
                </div>
                
                <div id="studioChatMessages" class="h-96 overflow-y-auto p-4 space-y-4 bg-gray-50">
                    <div class="studio-chat-bubble ai rounded-2xl p-4 text-gray-700">
                        <p>สวัสดีครับ! 👋 ผมคือ AI Assistant พร้อมช่วยเหลือคุณ</p>
                        <p class="text-sm mt-2">ลองถามอะไรก็ได้ เช่น:</p>
                        <ul class="text-sm mt-1 space-y-1 text-gray-600">
                            <li>• เขียนแคปชั่นขายสินค้า</li>
                            <li>• ช่วยคิดไอเดียโปรโมชั่น</li>
                            <li>• แปลภาษา</li>
                            <li>• วิเคราะห์รูปภาพ 📸</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="px-4 py-2 bg-gray-100 border-t">
                    <div class="flex gap-2 overflow-x-auto pb-2">
                        <button onclick="askStudioQuick('เขียนข้อความต้อนรับลูกค้าใหม่')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            👋 ต้อนรับลูกค้า
                        </button>
                        <button onclick="askStudioQuick('คิดโปรโมชั่นสำหรับร้านขายยา')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            🎁 คิดโปรโมชั่น
                        </button>
                        <button onclick="askStudioQuick('ช่วยตอบคำถามลูกค้าเรื่องการจัดส่ง')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            📦 ตอบเรื่องจัดส่ง
                        </button>
                        <button onclick="askStudioQuick('เขียนโพสต์ขายวิตามิน/อาหารเสริม ให้น่าสนใจ พร้อมจุดเด่นและ call-to-action')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            📝 โพสต์ขายสินค้า
                        </button>
                        <button onclick="askStudioQuick('ช่วยตอบรีวิว/คอมเมนต์ลูกค้าอย่างสุภาพและมืออาชีพ')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            💬 ตอบรีวิวลูกค้า
                        </button>
                        <button onclick="askStudioQuick('สรุปสรรพคุณและวิธีใช้ของสินค้านี้แบบเข้าใจง่ายสำหรับลูกค้า')" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border">
                            📋 สรุปสรรพคุณ
                        </button>
                        <button onclick="clearStudioChat()" class="px-3 py-1.5 text-xs bg-white rounded-lg hover:bg-gray-50 whitespace-nowrap border text-red-600">
                            🗑️ ล้างแชท
                        </button>
                    </div>
                </div>
                
                <div class="p-4 border-t bg-white">
                    <form onsubmit="sendStudioChat(event)" class="space-y-2">
                        <!-- Image Preview -->
                        <div id="studioChatImagePreview" class="hidden">
                            <div class="relative inline-block">
                                <img id="studioChatImagePreviewImg" class="max-h-32 rounded-lg border">
                                <button type="button" onclick="clearStudioChatImage()" class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full hover:bg-red-600">
                                    <i class="fas fa-times text-xs"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <input type="file" id="studioChatImageInput" accept="image/*" class="hidden" onchange="handleStudioChatImage(event)">
                            <button type="button" onclick="document.getElementById('studioChatImageInput').click()" class="px-4 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors" title="แนบรูปภาพ">
                                <i class="fas fa-image"></i>
                            </button>
                            <input type="text" id="studioChatInput" class="flex-1 px-4 py-3 border rounded-xl focus:ring-2 focus:ring-purple-500" placeholder="พิมพ์ข้อความหรือแนบรูป...">
                            <button type="submit" id="studioChatSendBtn" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Image Tab -->
        <div id="studio-tab-image" class="studio-tab-content hidden">
            <div class="grid lg:grid-cols-2 gap-6">
                <!-- Controls -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-500 to-rose-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-wand-magic-sparkles mr-2"></i>สร้าง &amp; แก้รูปด้วย AI</h3>
                        <p class="text-sm text-pink-100">Nano Banana — สร้างใหม่ / วางสินค้าในฉาก / แก้รูป / โปสเตอร์</p>
                    </div>
                    <div class="p-6 space-y-4">
                        <!-- Mode -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">โหมด</label>
                            <div class="grid grid-cols-2 gap-2" id="studioImgModes">
                                <button type="button" data-mode="generate" onclick="setStudioImgMode('generate')" class="studio-mode-btn px-3 py-2 border rounded-lg text-sm text-left">🎨 สร้างใหม่</button>
                                <button type="button" data-mode="product_scene" onclick="setStudioImgMode('product_scene')" class="studio-mode-btn px-3 py-2 border rounded-lg text-sm text-left">📦 วางสินค้าในฉาก</button>
                                <button type="button" data-mode="edit" onclick="setStudioImgMode('edit')" class="studio-mode-btn px-3 py-2 border rounded-lg text-sm text-left">✏️ แก้รูปเดิม</button>
                                <button type="button" data-mode="poster" onclick="setStudioImgMode('poster')" class="studio-mode-btn px-3 py-2 border rounded-lg text-sm text-left">🖼️ โปสเตอร์/แบนเนอร์</button>
                            </div>
                        </div>

                        <!-- Reference images -->
                        <div id="studioRefZoneWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รูปอ้างอิง (reference) <span id="studioRefHint" class="text-xs font-normal text-gray-400"></span></label>
                            <div id="studioRefDrop" onclick="document.getElementById('studioRefInput').click()" class="border-2 border-dashed border-pink-200 rounded-xl text-center py-5 px-3 cursor-pointer hover:bg-pink-50 transition-colors">
                                <i class="fas fa-images text-2xl text-pink-300 mb-1"></i>
                                <p class="text-sm text-gray-500">ลาก-วาง วาง (paste) หรือคลิกเพื่อแนบรูป</p>
                                <p class="text-xs text-gray-400">สินค้า / โลโก้ / รูปที่จะแก้ — สูงสุด 6 รูป</p>
                                <input type="file" id="studioRefInput" accept="image/*" multiple class="hidden" onchange="handleStudioRefFiles(event)">
                            </div>
                            <div id="studioRefThumbs" class="flex flex-wrap gap-2 mt-2"></div>
                        </div>

                        <!-- Prompt -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="block text-sm font-medium text-gray-700">คำสั่ง (Prompt)</label>
                                <button type="button" onclick="enhanceStudioPrompt()" id="btnEnhancePrompt" class="text-xs text-purple-600 hover:underline"><i class="fas fa-wand-magic-sparkles mr-1"></i>ช่วยเขียน prompt</button>
                            </div>
                            <textarea id="studioImagePrompt" rows="3" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-pink-500" placeholder="เช่น: วางขวดวิตามินบนโต๊ะไม้ พื้นหลังเบลอ แสงธรรมชาติ สไตล์มินิมอล..."></textarea>
                            <div id="studioImgTemplates" class="flex flex-wrap gap-1 mt-2"></div>
                        </div>

                        <!-- Settings -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">โมเดล</label>
                                <select id="studioImgModel" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-pink-500">
                                    <option value="gemini-3.1-flash-image">Nano Banana 2 (เร็ว+ดี)</option>
                                    <option value="gemini-3-pro-image">Nano Banana Pro (สวยสุด+ข้อความ)</option>
                                    <option value="gemini-2.5-flash-image">Nano Banana (ประหยัด)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">สัดส่วน</label>
                                <select id="studioImgAspect" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-pink-500">
                                    <option value="1:1">1:1 จัตุรัส</option>
                                    <option value="4:5">4:5 แนวตั้ง (โพสต์)</option>
                                    <option value="3:4">3:4 แนวตั้ง</option>
                                    <option value="9:16">9:16 สตอรี่</option>
                                    <option value="16:9">16:9 แนวนอน</option>
                                    <option value="3:2">3:2 แนวนอน</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">ขนาด</label>
                                <select id="studioImgSize" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-pink-500">
                                    <option value="1K">1K (เร็ว)</option>
                                    <option value="2K" selected>2K (แนะนำ)</option>
                                    <option value="4K">4K (คมชัด)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">จำนวนรูป</label>
                                <select id="studioImgVariations" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-pink-500">
                                    <option value="1">1 รูป</option>
                                    <option value="2">2 รูป</option>
                                    <option value="3">3 รูป</option>
                                    <option value="4">4 รูป</option>
                                </select>
                            </div>
                        </div>

                        <button onclick="generateStudioImage()" id="btnStudioGenImage" class="w-full py-3 bg-gradient-to-r from-pink-500 to-rose-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-paint-brush mr-2"></i>สร้างรูปภาพ
                        </button>
                        <p class="text-xs text-gray-400 text-center">ใช้ Google API key ของร้าน (ตั้งใน ตั้งค่า AI) อัตโนมัติ — ถ้าไม่มีจะให้กรอกเอง</p>
                    </div>
                </div>

                <!-- Results -->
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-700"><i class="fas fa-image mr-2"></i>ผลลัพธ์</h3>
                        <span id="studioImgCount" class="text-xs text-gray-400"></span>
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

                        <!-- Reference images (become the Flex hero) -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รูปสินค้า (reference) <span class="text-xs font-normal text-gray-400">— จะใช้เป็นภาพ hero ของ bubble (ไม่บังคับ)</span></label>
                            <div id="studioFlexRefDrop" onclick="document.getElementById('studioFlexRefInput').click()" class="border-2 border-dashed border-blue-200 rounded-xl text-center py-5 px-3 cursor-pointer hover:bg-blue-50 transition-colors">
                                <i class="fas fa-images text-2xl text-blue-300 mb-1"></i>
                                <p class="text-sm text-gray-500">ลาก-วาง วาง (paste) หรือคลิกเพื่อแนบรูป</p>
                                <p class="text-xs text-gray-400">แต่ละรูปจะกลายเป็น hero ของแต่ละ bubble ตามลำดับ — สูงสุด 10 รูป</p>
                                <input type="file" id="studioFlexRefInput" accept="image/*" multiple class="hidden" onchange="handleStudioFlexRefFiles(event)">
                            </div>
                            <div id="studioFlexRefThumbs" class="flex flex-wrap gap-2 mt-2"></div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">จำนวน bubble</label>
                            <select id="studioFlexBubbleCount" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500">
                                <option value="1">1 bubble (เดี่ยว)</option>
                                <option value="2">2 bubbles (carousel)</option>
                                <option value="3">3 bubbles</option>
                                <option value="4">4 bubbles</option>
                                <option value="5">5 bubbles</option>
                                <option value="6">6 bubbles</option>
                                <option value="8">8 bubbles</option>
                                <option value="10">10 bubbles</option>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">เลือกหลาย bubble เพื่อสร้าง carousel เลื่อนได้ใน LINE</p>
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
                        <button onclick="saveStudioFlexTemplate()" id="btnStudioSaveFlex" class="w-full mt-2 py-3 bg-white border border-blue-500 text-blue-600 rounded-xl font-bold hover:bg-blue-50 transition-all disabled:opacity-40" disabled>
                            <i class="fas fa-save mr-2"></i>บันทึกเทมเพลท
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
                        <!-- Upload Image for Caption -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">📸 อัปโหลดรูปเพื่อสร้างแคปชั่น (ไม่บังคับ)</label>
                            <div class="border-2 border-dashed border-gray-300 rounded-xl p-4 text-center hover:border-orange-400 transition-colors cursor-pointer" onclick="document.getElementById('studioCaptionImageInput').click()">
                                <input type="file" id="studioCaptionImageInput" accept="image/*" class="hidden" onchange="handleStudioCaptionImage(event)">
                                <div id="studioCaptionImagePreview" class="hidden">
                                    <img id="studioCaptionImagePreviewImg" class="max-h-40 mx-auto rounded-lg mb-2">
                                    <button type="button" onclick="event.stopPropagation(); clearStudioCaptionImage()" class="text-sm text-red-600 hover:underline">
                                        <i class="fas fa-times mr-1"></i>ลบรูป
                                    </button>
                                </div>
                                <div id="studioCaptionImagePlaceholder">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">คลิกเพื่ออัปโหลดรูป</p>
                                    <p class="text-xs text-gray-400">AI จะวิเคราะห์รูปและสร้างแคปชั่นให้อัตโนมัติ</p>
                                </div>
                            </div>
                        </div>
                        
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
let studioChatImageData = null;
let studioCaptionImageData = null;

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

// Gemini API Call (with Vision support)
async function callStudioGemini(text, systemInstruction = '', imageData = null, wantJson = false) {
    // Route through the server endpoint so the shop's stored Google key is used
    // automatically (no key paste needed). Falls back to a user-entered key.
    const payload = { prompt: text, system: systemInstruction, api_key: studioCurrentApiKey || '' };
    if (wantJson) payload.json = true;
    if (imageData && imageData.data) {
        payload.image = 'data:' + (imageData.mimeType || 'image/png') + ';base64,' + imageData.data;
    }
    const res = await fetch('api/ai-studio-text.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401) { throw new Error('กรุณาเข้าสู่ระบบใหม่'); }
    if (res.status === 422) { openStudioApiModal(); throw new Error(data.error || 'ต้องตั้งค่า API key'); }
    if (!data || !data.success) { throw new Error((data && data.error) || 'AI ไม่ตอบ'); }
    return data.text;
}

// Chat Functions
async function sendStudioChat(e) {
    e.preventDefault();
    // API key resolved server-side from the shop's ai_settings (client key optional)
    
    const input = document.getElementById('studioChatInput');
    const message = input.value.trim();
    if (!message && !studioChatImageData) return;
    
    const displayMessage = message || '📸 [รูปภาพ]';
    addStudioChatMessage(displayMessage, 'user', studioChatImageData?.preview);
    input.value = '';
    const typingId = addStudioTypingIndicator();
    
    const sendBtn = document.getElementById('studioChatSendBtn');
    sendBtn.disabled = true;
    
    try {
        let context = studioChatHistory.slice(-10).map(m => `${m.role}: ${m.content}`).join('\n');
        const prompt = context ? `${context}\nUser: ${message}` : message;
        const systemPrompt = studioChatImageData 
            ? 'คุณคือ AI Assistant ที่ช่วยวิเคราะห์รูปภาพและตอบคำถาม ตอบเป็นภาษาไทย กระชับ เป็นมิตร อธิบายรูปภาพอย่างละเอียด'
            : 'คุณคือ AI Assistant ที่ช่วยเหลือเรื่องธุรกิจ การตลาด และการขายออนไลน์ ตอบเป็นภาษาไทย กระชับ เป็นมิตร';
        
        const response = await callStudioGemini(prompt, systemPrompt, studioChatImageData);
        
        document.getElementById(typingId)?.remove();
        addStudioChatMessage(response, 'ai');
        studioChatHistory.push({ role: 'User', content: message });
        studioChatHistory.push({ role: 'AI', content: response });
        
        clearStudioChatImage();
    } catch (err) {
        document.getElementById(typingId)?.remove();
        addStudioChatMessage('เกิดข้อผิดพลาด: ' + err.message, 'ai');
    } finally {
        sendBtn.disabled = false;
    }
}

function handleStudioChatImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(event) {
        const base64 = event.target.result.split(',')[1];
        studioChatImageData = {
            mimeType: file.type,
            data: base64,
            preview: event.target.result
        };
        
        document.getElementById('studioChatImagePreviewImg').src = event.target.result;
        document.getElementById('studioChatImagePreview').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}

function clearStudioChatImage() {
    studioChatImageData = null;
    document.getElementById('studioChatImagePreview').classList.add('hidden');
    document.getElementById('studioChatImageInput').value = '';
}

function askStudioQuick(question) {
    document.getElementById('studioChatInput').value = question;
    document.getElementById('studioChatInput').focus();
}

function clearStudioChat() {
    if (!confirm('ต้องการล้างประวัติการสนทนาหรือไม่?')) return;
    studioChatHistory = [];
    const container = document.getElementById('studioChatMessages');
    container.innerHTML = `
        <div class="studio-chat-bubble ai rounded-2xl p-4 text-gray-700">
            <p>สวัสดีครับ! 👋 ผมคือ AI Assistant พร้อมช่วยเหลือคุณ</p>
            <p class="text-sm mt-2">ลองถามอะไรก็ได้ เช่น:</p>
            <ul class="text-sm mt-1 space-y-1 text-gray-600">
                <li>• เขียนแคปชั่นขายสินค้า</li>
                <li>• ช่วยคิดไอเดียโปรโมชั่น</li>
                <li>• แปลภาษา</li>
                <li>• วิเคราะห์รูปภาพ 📸</li>
            </ul>
        </div>
    `;
    showStudioToast('ล้างแชทแล้ว');
}

function addStudioChatMessage(text, type, imagePreview = null) {
    const container = document.getElementById('studioChatMessages');
    const div = document.createElement('div');
    div.className = `studio-chat-bubble ${type} rounded-2xl p-4 ${type === 'user' ? 'ml-auto text-white' : 'mr-auto text-gray-700'}`;
    
    let content = '';
    if (imagePreview) {
        content += `<img src="${imagePreview}" class="max-w-full max-h-48 rounded-lg mb-2">`;
    }
    content += typeof marked !== 'undefined' ? marked.parse(text) : text;
    
    div.innerHTML = content;
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
function setStudioImageStyle(style) { 
    studioCurrentImageStyle = style;
    document.querySelectorAll('.studio-style-btn').forEach(btn => btn.classList.remove('active', 'ring-2', 'ring-pink-500'));
    event.target.classList.add('active', 'ring-2', 'ring-pink-500');
}

function useImageTemplate(type) {
    const templates = {
        product: 'สินค้าวางบนพื้นหลังสีขาว แสงสว่างนุ่มนวล มุมมองสวยงาม สไตล์โปรดักส์ช็อต คุณภาพสูง',
        food: 'อาหารจานสวยงาม แสงธรรมชาติ มุมมองจากด้านบน สไตล์ฟู้ดโฟโตกราฟี น่าทาน สดใหม่',
        promo: 'แบนเนอร์โปรโมชั่น สีสันสดใส ข้อความชัดเจน ดีไซน์ทันสมัย ดึงดูดสายตา',
        social: 'โพสต์โซเชียลมีเดีย สไตล์มินิมอล สีพาสเทล ดูสะอาดตา เหมาะกับ Instagram'
    };
    document.getElementById('studioImagePrompt').value = templates[type] || '';
    showStudioToast('ใช้เทมเพลต: ' + type);
}

// ===== AI Studio image generation (Nano Banana) =====
let studioRefImages = [];      // array of data URLs (reference images)
let studioImgMode = 'generate';

const STUDIO_IMG_MODE_CFG = {
    generate:      { needRef:false, refHint:'(ไม่บังคับ)',          templates:['โลโก้ร้านยาโมเดิร์น มินิมอล','พื้นหลังโปรโมชั่นสีพาสเทล','ไอคอนสุขภาพ/ยา น่ารัก'] },
    product_scene: { needRef:true,  refHint:'(แนบรูปสินค้า)',        templates:['วางบนโต๊ะไม้ พื้นหลังเบลอ แสงธรรมชาติ','พื้นขาวสะอาดสไตล์สตูดิโอ','ฉากธรรมชาติเขียวสดชื่น','โต๊ะหินอ่อนหรู'] },
    edit:          { needRef:true,  refHint:'(แนบรูปที่จะแก้)',      templates:['ลบพื้นหลังให้โปร่ง','เปลี่ยนพื้นหลังเป็นสีขาว','เพิ่มความสว่างและคมชัด','เติมข้อความโปรลงบนรูป'] },
    poster:        { needRef:false, refHint:'(แนบโลโก้/สินค้า ถ้ามี)', templates:['โปสเตอร์โปรโมชั่นลด 20% สดใส','แบนเนอร์เปิดร้านยาใหม่','โปสเตอร์แนะนำวิตามิน พร้อมราคา'] },
};

function setStudioImgMode(mode) {
    studioImgMode = mode;
    document.querySelectorAll('.studio-mode-btn').forEach(b => {
        const on = b.dataset.mode === mode;
        ['ring-2','ring-pink-500','bg-pink-50','border-pink-300'].forEach(c => b.classList.toggle(c, on));
    });
    const cfg = STUDIO_IMG_MODE_CFG[mode] || STUDIO_IMG_MODE_CFG.generate;
    const hint = document.getElementById('studioRefHint'); if (hint) hint.textContent = cfg.refHint;
    const tpl = document.getElementById('studioImgTemplates');
    if (tpl) tpl.innerHTML = (cfg.templates||[]).map(t =>
        `<button type="button" onclick="applyStudioTemplate(this)" class="px-2 py-1 text-xs bg-gray-100 hover:bg-pink-100 rounded-full border border-gray-200">${t}</button>`).join('');
}
function applyStudioTemplate(btn) {
    const ta = document.getElementById('studioImagePrompt');
    ta.value = ta.value.trim() ? (ta.value.trim() + ', ' + btn.textContent) : btn.textContent;
}

function renderStudioRefThumbs() {
    const box = document.getElementById('studioRefThumbs');
    if (!box) return;
    box.innerHTML = studioRefImages.map((src,i) =>
        `<div class="relative w-16 h-16"><img src="${src}" class="w-16 h-16 object-cover rounded-lg border"><button type="button" onclick="removeStudioRef(${i})" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full text-xs leading-5 text-center">&times;</button></div>`).join('');
}
function addStudioRefDataUrl(dataUrl) {
    if (studioRefImages.length >= 6) { showStudioToast('แนบได้สูงสุด 6 รูป', true); return; }
    studioRefImages.push(dataUrl); renderStudioRefThumbs();
}
function handleStudioRefFiles(e) {
    [...e.target.files].forEach(f => { if (!f.type.startsWith('image/')) return; const r=new FileReader(); r.onload=ev=>addStudioRefDataUrl(ev.target.result); r.readAsDataURL(f); });
    e.target.value = '';
}
function removeStudioRef(i) { studioRefImages.splice(i,1); renderStudioRefThumbs(); }

// paste image into the studio image tab
document.addEventListener('paste', function(e) {
    const imgTab = document.getElementById('studio-tab-image');
    if (!imgTab || imgTab.classList.contains('hidden')) return;
    for (const item of (e.clipboardData?.items || [])) {
        if (item.type.startsWith('image/')) { const f=item.getAsFile(); const r=new FileReader(); r.onload=ev=>addStudioRefDataUrl(ev.target.result); r.readAsDataURL(f); }
    }
});
// drag-drop onto the reference zone
document.addEventListener('DOMContentLoaded', function() {
    const dz = document.getElementById('studioRefDrop');
    if (dz) {
        ['dragover','dragenter'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('bg-pink-50'); }));
        ['dragleave'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('bg-pink-50'); }));
        dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('bg-pink-50'); [...(e.dataTransfer?.files||[])].forEach(f => { if (f.type.startsWith('image/')) { const r=new FileReader(); r.onload=ev=>addStudioRefDataUrl(ev.target.result); r.readAsDataURL(f); } }); });
    }
    try { setStudioImgMode('generate'); } catch (e) {}
});

async function enhanceStudioPrompt() {
    const ta = document.getElementById('studioImagePrompt'); const cur = ta.value.trim();
    if (!cur) { showStudioToast('พิมพ์ไอเดียคร่าวๆ ก่อน', true); return; }
    // API key resolved server-side from the shop's ai_settings (client key optional)
    const btn = document.getElementById('btnEnhancePrompt'); const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const out = await callStudioGemini(`ขยายและแปลงไอเดียนี้เป็น prompt ภาษาอังกฤษสำหรับสร้างรูป (โหมด: ${studioImgMode}) ใส่รายละเอียดแสง องค์ประกอบภาพ และสไตล์ให้ออกมาสวยระดับโฆษณา: "${cur}"`, 'Output only the final English image prompt, no explanation or quotes');
        if (out) ta.value = out.trim();
    } catch (err) { showStudioToast('ช่วยเขียน prompt ไม่สำเร็จ', true); }
    finally { btn.disabled = false; btn.innerHTML = old; }
}

async function generateStudioImage() {
    const prompt = document.getElementById('studioImagePrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอก Prompt', true); return; }
    const cfg = STUDIO_IMG_MODE_CFG[studioImgMode] || {};
    if (cfg.needRef && studioRefImages.length === 0) { showStudioToast('โหมดนี้ต้องแนบรูปอ้างอิงอย่างน้อย 1 รูป', true); return; }

    const btn = document.getElementById('btnStudioGenImage'); const oldBtn = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    document.getElementById('studioImageResult').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin text-4xl text-pink-500 mb-4"></i><p class="text-gray-600">กำลังสร้างรูปภาพ... (อาจใช้เวลา 10–40 วินาที)</p></div>';
    try {
        const res = await fetch('api/ai-studio-image.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: document.getElementById('studioImgModel').value,
                prompt, mode: studioImgMode,
                aspect_ratio: document.getElementById('studioImgAspect').value,
                image_size: document.getElementById('studioImgSize').value,
                variations: parseInt(document.getElementById('studioImgVariations').value || '1', 10),
                reference_images: studioRefImages,
                api_key: studioCurrentApiKey || ''
            })
        });
        const data = await res.json().catch(() => ({}));
        if (res.status === 401) { showStudioToast('กรุณาเข้าสู่ระบบใหม่', true); throw new Error('กรุณาเข้าสู่ระบบใหม่'); }
        if (res.status === 422) { openStudioApiModal(); throw new Error(data.error || 'ต้องตั้งค่า API key ก่อน'); }
        if (!data || !data.success) { throw new Error((data && data.error) || 'สร้างรูปไม่สำเร็จ'); }
        renderStudioGallery(data.images || []);
        showStudioToast('สร้างรูปสำเร็จ!');
    } catch (err) {
        document.getElementById('studioImageResult').innerHTML = `<div class="text-center text-red-500 px-4"><i class="fas fa-exclamation-circle text-4xl mb-3"></i><p>${err.message}</p></div>`;
    } finally {
        btn.disabled = false; btn.innerHTML = oldBtn;
    }
}

function renderStudioGallery(images) {
    const wrap = document.getElementById('studioImageResult');
    const cnt = document.getElementById('studioImgCount'); if (cnt) cnt.textContent = images.length + ' รูป';
    wrap.classList.remove('items-center','justify-center');
    if (!images.length) { wrap.innerHTML = '<p class="text-gray-400">ไม่ได้รูป</p>'; return; }
    wrap.innerHTML = '<div class="grid grid-cols-1 gap-4 w-full">' + images.map((im,i) => {
        const url = im.data_url || im.url;
        return `<div class="border rounded-xl overflow-hidden bg-gray-50">
            <img src="${url}" class="w-full object-contain max-h-[420px] bg-white">
            <div class="flex flex-wrap gap-2 p-2">
                <a href="${url}" download="ai-studio-${Date.now()}-${i}.png" class="px-3 py-1.5 text-xs bg-gray-900 text-white rounded-lg hover:bg-gray-800"><i class="fas fa-download mr-1"></i>ดาวน์โหลด</a>
                <button type="button" onclick="useStudioImageAsRef('${url}')" class="px-3 py-1.5 text-xs bg-pink-100 text-pink-700 rounded-lg hover:bg-pink-200"><i class="fas fa-recycle mr-1"></i>ใช้เป็น reference ต่อ</button>
            </div></div>`;
    }).join('') + '</div>';
}
function useStudioImageAsRef(dataUrl) { addStudioRefDataUrl(dataUrl); setStudioImgMode('edit'); showStudioToast('เพิ่มเป็น reference แล้ว — ปรับ prompt แล้วสร้างต่อได้'); }

// Flex Functions
function setStudioFlexColor(color) { studioCurrentFlexColor = color; }

// ----- Flex reference images (hero) -----
let studioFlexRefImages = [];   // array of data URLs

function renderStudioFlexRefThumbs() {
    const box = document.getElementById('studioFlexRefThumbs');
    if (!box) return;
    box.innerHTML = studioFlexRefImages.map((src, i) =>
        `<div class="relative w-16 h-16"><img src="${src}" class="w-16 h-16 object-cover rounded-lg border"><button type="button" onclick="removeStudioFlexRef(${i})" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full text-xs leading-5 text-center">&times;</button></div>`).join('');
}
function addStudioFlexRefDataUrl(dataUrl) {
    if (studioFlexRefImages.length >= 10) { showStudioToast('แนบได้สูงสุด 10 รูป', true); return; }
    studioFlexRefImages.push(dataUrl); renderStudioFlexRefThumbs();
}
function handleStudioFlexRefFiles(e) {
    [...e.target.files].forEach(f => { if (!f.type.startsWith('image/')) return; const r = new FileReader(); r.onload = ev => addStudioFlexRefDataUrl(ev.target.result); r.readAsDataURL(f); });
    e.target.value = '';
}
function removeStudioFlexRef(i) { studioFlexRefImages.splice(i, 1); renderStudioFlexRefThumbs(); }

// paste + drag-drop onto the Flex reference zone
document.addEventListener('paste', function(e) {
    const flexTab = document.getElementById('studio-tab-flex');
    if (!flexTab || flexTab.classList.contains('hidden')) return;
    for (const item of (e.clipboardData?.items || [])) {
        if (item.type.startsWith('image/')) { const f = item.getAsFile(); const r = new FileReader(); r.onload = ev => addStudioFlexRefDataUrl(ev.target.result); r.readAsDataURL(f); }
    }
});
document.addEventListener('DOMContentLoaded', function() {
    const dz = document.getElementById('studioFlexRefDrop');
    if (dz) {
        ['dragover','dragenter'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('bg-blue-50'); }));
        ['dragleave'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('bg-blue-50'); }));
        dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('bg-blue-50'); [...(e.dataTransfer?.files || [])].forEach(f => { if (f.type.startsWith('image/')) { const r = new FileReader(); r.onload = ev => addStudioFlexRefDataUrl(ev.target.result); r.readAsDataURL(f); } }); });
    }
});

async function generateStudioFlex() {
    const prompt = document.getElementById('studioFlexPrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอกรายละเอียด', true); return; }

    const btn = document.getElementById('btnStudioGenFlex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    document.getElementById('studioFlexPreview').innerHTML = '<div class="text-center text-blue-300"><i class="fas fa-spinner fa-spin text-4xl mb-3"></i><p>กำลังสร้าง Flex... (10–30 วินาที)</p></div>';

    try {
        const res = await fetch('api/ai-studio-flex.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                prompt,
                type: document.getElementById('studioFlexType').value,
                color: studioCurrentFlexColor,
                bubble_count: parseInt(document.getElementById('studioFlexBubbleCount').value || '1', 10),
                reference_images: studioFlexRefImages,
                api_key: studioCurrentApiKey || ''
            })
        });
        const data = await res.json().catch(() => ({}));
        if (res.status === 401) { showStudioToast('กรุณาเข้าสู่ระบบใหม่', true); throw new Error('กรุณาเข้าสู่ระบบใหม่'); }
        if (res.status === 422) { openStudioApiModal(); throw new Error(data.error || 'ต้องตั้งค่า API key ก่อน'); }
        if (!data || !data.success) { throw new Error((data && data.error) || 'สร้าง Flex ไม่สำเร็จ'); }

        studioCurrentFlexJson = data.flex;
        renderStudioFlexPreview(studioCurrentFlexJson);
        document.getElementById('studioFlexJson').textContent = JSON.stringify(studioCurrentFlexJson, null, 2);
        document.getElementById('btnStudioSaveFlex').disabled = false;
        showStudioToast('สร้าง Flex Message สำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
        document.getElementById('studioFlexPreview').innerHTML = `<div class="text-center text-red-400 px-4"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>${err.message}</p></div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้าง Flex Message';
    }
}

// ----- Minimal LINE Flex → HTML renderer (covers common components) -----
function flexEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function renderFlexComp(c){
    if(!c||typeof c!=='object') return '';
    const sizeMap={xxs:'10px',xs:'11px',sm:'13px',md:'15px',lg:'17px',xl:'20px',xxl:'24px','3xl':'28px','4xl':'34px','5xl':'40px'};
    switch(c.type){
        case 'box': {
            const dir = c.layout==='horizontal'||c.layout==='baseline' ? 'row':'column';
            const style = `display:flex;flex-direction:${dir};gap:${c.spacing?'6px':'2px'};` + (c.backgroundColor?`background:${flexEsc(c.backgroundColor)};`:'') + (c.paddingAll?`padding:10px;`:'') + (dir==='row'?'align-items:center;':'');
            return `<div style="${style}">${(c.contents||[]).map(renderFlexComp).join('')}</div>`;
        }
        case 'text': {
            const fs = sizeMap[c.size]||'15px';
            const w = c.weight==='bold'?'700':'400';
            const col = c.color||'#111';
            const al = c.align||'start';
            return `<div style="font-size:${fs};font-weight:${w};color:${flexEsc(col)};text-align:${al==='start'?'left':al==='end'?'right':'center'};white-space:pre-wrap;${c.wrap?'':'overflow:hidden;text-overflow:ellipsis;'}">${flexEsc(c.text)}</div>`;
        }
        case 'image':
            return `<img src="${flexEsc(c.url)}" style="width:100%;border-radius:6px;object-fit:cover;${c.aspectMode==='cover'?'':''}">`;
        case 'button': {
            const a=c.action||{}; const bg=c.style==='primary'?(c.color||studioCurrentFlexColor):'#fff';
            const fg=c.style==='primary'?'#fff':(c.color||studioCurrentFlexColor);
            const bd=c.style==='primary'?'none':`1px solid ${fg}`;
            return `<div style="background:${flexEsc(bg)};color:${flexEsc(fg)};border:${bd};border-radius:8px;padding:8px;text-align:center;font-weight:600;font-size:14px;margin-top:4px;">${flexEsc(a.label||'ปุ่ม')}</div>`;
        }
        case 'separator':
            return `<div style="height:1px;background:#eee;margin:6px 0;"></div>`;
        case 'spacer': case 'filler':
            return `<div style="flex:1"></div>`;
        case 'icon':
            return `<img src="${flexEsc(c.url)}" style="width:16px;height:16px;vertical-align:middle;">`;
        default:
            return c.contents ? (c.contents||[]).map(renderFlexComp).join('') : '';
    }
}
function renderBubbleHtml(b){
    let inner='';
    if(b.hero) inner += `<div>${renderFlexComp(b.hero)}</div>`;
    if(b.body) inner += `<div style="padding:14px;">${renderFlexComp(b.body)}</div>`;
    if(b.footer) inner += `<div style="padding:10px 14px 14px;">${renderFlexComp(b.footer)}</div>`;
    return `<div style="width:280px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.25);font-family:'Sukhumvit Set','Prompt',sans-serif;">${inner}</div>`;
}
function renderStudioFlexPreview(json){
    const wrap=document.getElementById('studioFlexPreview');
    try{
        let html='';
        if(json && json.type==='carousel' && Array.isArray(json.contents)){
            html=`<div style="display:flex;gap:12px;overflow-x:auto;padding:4px;">${json.contents.map(renderBubbleHtml).join('')}</div>`;
        } else {
            html=renderBubbleHtml(json);
        }
        wrap.innerHTML=`<div class="w-full flex justify-center">${html}</div>`;
    }catch(e){
        wrap.innerHTML=`<div class="text-center text-yellow-300 px-4"><i class="fas fa-check-circle text-2xl mb-2"></i><p>สร้าง JSON สำเร็จ แต่ preview เรนเดอร์ไม่ได้ — ดู JSON ด้านล่าง</p></div>`;
    }
}

function copyStudioFlexJson() {
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex JSON', true); return; }
    navigator.clipboard.writeText(JSON.stringify(studioCurrentFlexJson, null, 2));
    showStudioToast('คัดลอก JSON แล้ว!');
}

async function saveStudioFlexTemplate() {
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex ให้บันทึก', true); return; }
    const name = prompt('ตั้งชื่อเทมเพลท Flex:', 'AI Studio Flex ' + new Date().toLocaleDateString('th-TH'));
    if (!name) return;

    const btn = document.getElementById('btnStudioSaveFlex');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    try {
        const fd = new FormData();
        fd.append('action', 'save_template');
        fd.append('name', name);
        fd.append('category', 'AI Studio');
        fd.append('flex_json', JSON.stringify(studioCurrentFlexJson));
        const res = await fetch('flex-builder.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!data || !data.success) { throw new Error((data && data.error) || 'บันทึกไม่สำเร็จ'); }
        showStudioToast('บันทึกเทมเพลทแล้ว!');
    } catch (err) {
        showStudioToast('บันทึกไม่สำเร็จ: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = old;
    }
}

// Caption Functions
function handleStudioCaptionImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(event) {
        const base64 = event.target.result.split(',')[1];
        studioCaptionImageData = {
            mimeType: file.type,
            data: base64
        };
        
        document.getElementById('studioCaptionImagePreviewImg').src = event.target.result;
        document.getElementById('studioCaptionImagePreview').classList.remove('hidden');
        document.getElementById('studioCaptionImagePlaceholder').classList.add('hidden');
    };
    reader.readAsDataURL(file);
}

function clearStudioCaptionImage() {
    studioCaptionImageData = null;
    document.getElementById('studioCaptionImagePreview').classList.add('hidden');
    document.getElementById('studioCaptionImagePlaceholder').classList.remove('hidden');
    document.getElementById('studioCaptionImageInput').value = '';
}

async function generateStudioCaption() {
    // API key resolved server-side from the shop's ai_settings (client key optional)
    const prompt = document.getElementById('studioCaptionPrompt').value.trim();
    if (!prompt && !studioCaptionImageData) { showStudioToast('กรุณากรอกรายละเอียดหรืออัปโหลดรูป', true); return; }
    
    const btn = document.getElementById('btnStudioGenCaption');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    document.getElementById('studioCaptionResult').innerHTML = '<div class="text-center text-orange-400 py-10"><i class="fas fa-spinner fa-spin text-3xl mb-3"></i><p>กำลังสร้างแคปชั่น 3 แบบ...</p></div>';
    try {
        const type = document.getElementById('studioCaptionType').value;
        const includeHashtags = document.getElementById('studioIncludeHashtags').checked;
        const includeEmoji = document.getElementById('studioIncludeEmoji').checked;

        const systemPrompt = `คุณคือนักการตลาดร้านยา/สุขภาพ เขียนแคปชั่นโซเชียลภาษาไทย ประเภท: ${type}. `
            + (includeHashtags ? 'ใส่ hashtag 5-8 อันต่อแคปชั่น. ' : 'ไม่ต้องใส่ hashtag. ')
            + (includeEmoji ? 'ใส่อีโมจิพอเหมาะ. ' : 'ไม่ใส่อีโมจิ. ')
            + 'สร้าง 3 แบบที่ต่างกัน (เช่น สั้นกระชับ / เล่าเรื่อง / กระตุ้นให้ซื้อ). ตอบเป็น JSON array เท่านั้น: [{"label":"ชื่อสไตล์","text":"แคปชั่น"}]';
        let finalPrompt = prompt || 'วิเคราะห์รูปที่แนบมาแล้วเขียนแคปชั่นที่น่าสนใจ';
        if (studioCaptionImageData) finalPrompt += '\n(วิเคราะห์รูปที่แนบมาประกอบด้วย)';

        const out = await callStudioGemini(finalPrompt, systemPrompt, studioCaptionImageData, true);
        let options = null;
        try { options = JSON.parse(out.replace(/```json|```/g, '').trim()); } catch (e) {}
        if (Array.isArray(options) && options.length) renderStudioCaptions(options);
        else document.getElementById('studioCaptionResult').innerHTML = `<div class="prose prose-sm max-w-none whitespace-pre-wrap">${flexEsc(out)}</div>`;
        showStudioToast('สร้างแคปชั่นสำเร็จ!');
        if (studioCaptionImageData) clearStudioCaptionImage();
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
        document.getElementById('studioCaptionResult').innerHTML = `<div class="text-center text-red-500 py-10"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>${err.message}</p></div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้างแคปชั่น';
    }
}

let studioCaptionOptions = [];
function renderStudioCaptions(options) {
    studioCaptionOptions = options;
    document.getElementById('studioCaptionResult').innerHTML = '<div class="space-y-3">' + options.map((o,i) =>
        `<div class="border rounded-xl p-3 bg-orange-50/40">
            <div class="flex justify-between items-center mb-1">
                <span class="text-xs font-semibold text-orange-600">${flexEsc(o.label || ('แบบ ' + (i+1)))}</span>
                <button onclick="copyStudioCaption(${i})" class="text-xs text-orange-600 hover:underline"><i class="far fa-copy mr-1"></i>คัดลอก</button>
            </div>
            <div class="text-sm text-gray-800 whitespace-pre-wrap">${flexEsc(o.text || '')}</div>
        </div>`).join('') + '</div>';
}
function copyStudioCaption(i) { const o = studioCaptionOptions[i]; if (!o) return; navigator.clipboard.writeText(o.text || ''); showStudioToast('คัดลอกแคปชั่นแล้ว!'); }
function copyStudioCaptionResult() {
    navigator.clipboard.writeText(document.getElementById('studioCaptionResult').innerText);
    showStudioToast('คัดลอกแล้ว!');
}

// Translate Functions
async function translateStudioText() {
    // API key resolved server-side from the shop's ai_settings (client key optional)
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
