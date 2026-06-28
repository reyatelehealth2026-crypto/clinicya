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
                <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-500 to-rose-500 p-4 text-white">
                        <h3 class="font-bold text-lg"><i class="fas fa-magic mr-2"></i>สร้างรูปภาพ</h3>
                        <p class="text-sm text-pink-200">Imagen 4.0 - สร้างรูปจริงจาก AI</p>
                    </div>
                    <div class="p-6">
                        <!-- Template Quick Select -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">เทมเพลตสำเร็จรูป</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" onclick="useImageTemplate('product')" class="px-3 py-2 text-sm bg-gradient-to-r from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 rounded-lg border border-blue-200 text-left">
                                    <div class="font-medium">📦 รูปสินค้า</div>
                                    <div class="text-xs text-gray-600">ถ่ายสินค้าสวยงาม</div>
                                </button>
                                <button type="button" onclick="useImageTemplate('food')" class="px-3 py-2 text-sm bg-gradient-to-r from-orange-50 to-orange-100 hover:from-orange-100 hover:to-orange-200 rounded-lg border border-orange-200 text-left">
                                    <div class="font-medium">🍔 อาหาร</div>
                                    <div class="text-xs text-gray-600">ถ่ายอาหารน่าทาน</div>
                                </button>
                                <button type="button" onclick="useImageTemplate('promo')" class="px-3 py-2 text-sm bg-gradient-to-r from-pink-50 to-pink-100 hover:from-pink-100 hover:to-pink-200 rounded-lg border border-pink-200 text-left">
                                    <div class="font-medium">🎉 โปรโมชั่น</div>
                                    <div class="text-xs text-gray-600">แบนเนอร์โปรโมท</div>
                                </button>
                                <button type="button" onclick="useImageTemplate('social')" class="px-3 py-2 text-sm bg-gradient-to-r from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 rounded-lg border border-purple-200 text-left">
                                    <div class="font-medium">📱 โซเชียล</div>
                                    <div class="text-xs text-gray-600">โพสต์โซเชียล</div>
                                </button>
                            </div>
                        </div>
                        
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
                        <p class="text-sm text-blue-200">สร้าง LINE Flex Message ด้วย AI ให้เข้ากับการตลาดของร้านยา</p>
                    </div>
                    <div class="p-6">
                        <!-- Marketing Presets -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">🎯 แนวคิดการตลาด (Marketing Presets)</label>
                            <select id="studioFlexMarketingPreset" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500" onchange="applyMarketingPreset()">
                                <option value="">-- เลือกกลยุทธ์การตลาดเพื่อเริ่มร่าง Prompt --</option>
                                <option value="pain_outcome">🎯 Pain → Outcome (เปลี่ยนปัญหาเป็นผลลัพธ์ของสินค้า/บริการ)</option>
                                <option value="symptom_care">🤒 Symptom-Based Broadcast (ดูแลคนไข้และแนะนำยาตามอาการ)</option>
                                <option value="refill_reminder">🎁 Medication Refill Loop (แจ้งเตือนรับยาต่อเนื่อง/รักษาโรคเรื้อรัง)</option>
                                <option value="loyalty_points">🏆 Loyalty Points Loop (กระตุ้นกลับมาใช้แต้มสะสมแลกสิทธิพิเศษ)</option>
                                <option value="special_discount">🎉 Special Promotion (โปรโมชั่นพิเศษ/ซื้อคู่ประหยัดกว่า)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ประเภท Flex</label>
                                <select id="studioFlexType" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500">
                                    <option value="product">🛍️ โปรโมทสินค้า</option>
                                    <option value="promo">🎉 โปรโมชั่น/ส่วนลด</option>
                                    <option value="menu">📋 เมนูร้านอาหาร</option>
                                    <option value="receipt">🧾 ใบเสร็จ/ออเดอร์</option>
                                    <option value="welcome">👋 ข้อความต้อนรับ</option>
                                    <option value="announce">📢 ประกาศ/ข่าวสาร</option>
                                    <option value="booking">📅 จองคิว/นัดหมาย</option>
                                    <option value="custom" selected>✨ กำหนดเอง</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">จำนวนการ์ด (สไลด์)</label>
                                <input type="number" id="studioFlexBubbleCount" min="1" max="10" value="1" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <!-- Real Product Picker (Hybrid builder) -->
                        <div class="mb-4 p-4 bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-xl">
                            <div class="flex items-center justify-between gap-2">
                                <label class="block text-sm font-bold text-emerald-800"><i class="fas fa-pills mr-1"></i>เลือกสินค้าจริง <span class="font-normal text-emerald-600 text-xs">(ราคา/รูป/รหัส จากระบบ 100%)</span></label>
                                <button type="button" onclick="openProductPicker()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg font-medium shadow-sm transition-all whitespace-nowrap"><i class="fas fa-plus mr-1"></i>เลือกสินค้า</button>
                            </div>
                            <div id="studioPickedChips" class="flex flex-wrap gap-2 mt-2"></div>
                            <div id="studioPickedEmpty" class="text-xs text-emerald-700/70 mt-1">ยังไม่ได้เลือกสินค้า — เลือกแล้วระบบจะสร้าง Flex จากข้อมูลจริง (ถ้าไม่เลือก จะใช้ AI ล้วนตามรายละเอียดด้านล่าง)</div>
                            <div id="studioProductOptions" class="hidden mt-3 flex flex-wrap items-center gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium text-gray-600">เลย์เอาต์:</span>
                                    <select id="studioFlexLayout" onchange="if(studioPickedProducts.length) buildFlexFromProducts()" class="px-3 py-1.5 border rounded-lg text-sm">
                                        <option value="2up">2 สินค้า/การ์ด</option>
                                        <option value="1up">1 สินค้า/การ์ด</option>
                                    </select>
                                </div>
                                <button type="button" onclick="generateFlexCopy()" id="btnStudioGenCopy" class="px-3 py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 text-sm rounded-lg font-medium transition-all"><i class="fas fa-wand-magic-sparkles mr-1"></i>ให้ AI เขียนคำโปรย</button>
                                <span id="studioCopyStatus" class="text-xs text-gray-500"></span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รายละเอียดข้อความ/รายละเอียดการตลาด</label>
                            <textarea id="studioFlexPrompt" rows="4" class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-blue-500" placeholder="กรอกข้อมูลที่ต้องการ เช่น: แนะนำอาหารเสริมวิตามินซี หรือเลือกจากแนวคิดการตลาดด้านบน"></textarea>
                        </div>

                        <!-- Image Upload for Flex -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">📸 รูปภาพสินค้าอ้างอิง (สูงสุด 10 รูป จะใช้เป็นภาพ Hero ของการ์ดแต่ละใบ)</label>
                            <div class="border-2 border-dashed border-gray-300 rounded-xl p-4 text-center hover:border-blue-400 transition-colors cursor-pointer" onclick="document.getElementById('studioFlexImageInput').click()">
                                <input type="file" id="studioFlexImageInput" accept="image/*" multiple class="hidden" onchange="handleStudioFlexImages(event)">
                                <div id="studioFlexImagePlaceholder">
                                    <i class="fas fa-images text-gray-400 text-3xl mb-2"></i>
                                    <p class="text-sm text-gray-500">คลิกเพื่ออัปโหลดรูปภาพสินค้า (เลือกได้หลายรูป)</p>
                                </div>
                                <div id="studioFlexImagePreviewContainer" class="flex flex-wrap gap-2 justify-center mt-2 hidden"></div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">สี Theme</label>
                            <div id="studioFlexColorRow" class="flex flex-wrap gap-2 items-center">
                                <!-- เขียว -->
                                <button type="button" onclick="setStudioFlexColor('#06C755')" data-color="#06C755" class="studio-color-btn w-8 h-8 rounded-full bg-[#06C755] border-2 border-white shadow ring-4 ring-offset-2 ring-blue-400" title="LINE Green"></button>
                                <button type="button" onclick="setStudioFlexColor('#15803D')" data-color="#15803D" class="studio-color-btn w-8 h-8 rounded-full bg-[#15803D] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#059669')" data-color="#059669" class="studio-color-btn w-8 h-8 rounded-full bg-[#059669] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#10B981')" data-color="#10B981" class="studio-color-btn w-8 h-8 rounded-full bg-[#10B981] border-2 border-white shadow"></button>
                                <!-- ฟ้า/เทอร์ควอยซ์ -->
                                <button type="button" onclick="setStudioFlexColor('#3B82F6')" data-color="#3B82F6" class="studio-color-btn w-8 h-8 rounded-full bg-[#3B82F6] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#2563EB')" data-color="#2563EB" class="studio-color-btn w-8 h-8 rounded-full bg-[#2563EB] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#0EA5E9')" data-color="#0EA5E9" class="studio-color-btn w-8 h-8 rounded-full bg-[#0EA5E9] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#14B8A6')" data-color="#14B8A6" class="studio-color-btn w-8 h-8 rounded-full bg-[#14B8A6] border-2 border-white shadow"></button>
                                <!-- แดง/ชมพู -->
                                <button type="button" onclick="setStudioFlexColor('#EF4444')" data-color="#EF4444" class="studio-color-btn w-8 h-8 rounded-full bg-[#EF4444] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#E53E3E')" data-color="#E53E3E" class="studio-color-btn w-8 h-8 rounded-full bg-[#E53E3E] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#EC4899')" data-color="#EC4899" class="studio-color-btn w-8 h-8 rounded-full bg-[#EC4899] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#F43F5E')" data-color="#F43F5E" class="studio-color-btn w-8 h-8 rounded-full bg-[#F43F5E] border-2 border-white shadow"></button>
                                <!-- ส้ม/เหลือง -->
                                <button type="button" onclick="setStudioFlexColor('#F59E0B')" data-color="#F59E0B" class="studio-color-btn w-8 h-8 rounded-full bg-[#F59E0B] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#F97316')" data-color="#F97316" class="studio-color-btn w-8 h-8 rounded-full bg-[#F97316] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#EAB308')" data-color="#EAB308" class="studio-color-btn w-8 h-8 rounded-full bg-[#EAB308] border-2 border-white shadow"></button>
                                <!-- ม่วง/เข้ม -->
                                <button type="button" onclick="setStudioFlexColor('#8B5CF6')" data-color="#8B5CF6" class="studio-color-btn w-8 h-8 rounded-full bg-[#8B5CF6] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#7C3AED')" data-color="#7C3AED" class="studio-color-btn w-8 h-8 rounded-full bg-[#7C3AED] border-2 border-white shadow"></button>
                                <button type="button" onclick="setStudioFlexColor('#1F2937')" data-color="#1F2937" class="studio-color-btn w-8 h-8 rounded-full bg-[#1F2937] border-2 border-white shadow"></button>
                                <!-- กำหนดเอง -->
                                <label class="relative w-8 h-8 rounded-full border-2 border-dashed border-gray-300 shadow flex items-center justify-center cursor-pointer hover:border-gray-400" title="กำหนดสีเอง">
                                    <i class="fas fa-eye-dropper text-gray-400 text-xs"></i>
                                    <input type="color" id="studioFlexColorCustom" onchange="setStudioFlexColor(this.value, true)" class="absolute inset-0 opacity-0 cursor-pointer">
                                </label>
                            </div>
                        </div>
                        
                        <button onclick="generateStudioFlex()" id="btnStudioGenFlex" class="w-full py-3 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-xl font-bold hover:opacity-90 transition-all">
                            <i class="fas fa-magic mr-2"></i>สร้าง Flex Message
                        </button>
                    </div>
                </div>
                
                <div class="flex flex-col gap-6">
                    <div class="bg-white rounded-2xl shadow-xl overflow-hidden flex-1">
                        <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                            <h3 class="font-bold text-gray-700"><i class="fas fa-eye mr-2"></i>Preview (ตัวอย่างบน LINE)</h3>
                            <div>
                                <button onclick="copyStudioFlexJson()" class="text-sm text-blue-600 hover:underline font-medium"><i class="far fa-copy mr-1"></i>Copy JSON</button>
                                <button id="btnFlexUseBroadcast" onclick="useFlexInBroadcast()" class="hidden text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg ml-2 font-medium transition-all shadow-sm"><i class="fas fa-paper-plane mr-1"></i>ใช้ใน Broadcast</button>
                            </div>
                        </div>
                        <div id="studioFlexPreview" class="p-6 min-h-[400px] bg-gray-800 flex items-center justify-center">
                            <div class="text-center text-gray-500">
                                <i class="fas fa-layer-group text-6xl mb-4"></i>
                                <p>Flex Message จะแสดงที่นี่</p>
                            </div>
                        </div>
                    </div>

                    <!-- AI Refinement Section -->
                    <div id="studioFlexRefineSection" class="hidden bg-white rounded-2xl shadow-xl overflow-hidden border border-teal-100">
                        <div class="bg-gradient-to-r from-teal-500 to-emerald-500 p-4 text-white">
                            <h4 class="font-bold text-sm"><i class="fas fa-magic mr-1"></i>ปรับปรุงด้วย AI (AI Refinement)</h4>
                            <p class="text-xs text-teal-100">ส่งคำสั่งภาษาธรรมชาติเพื่อแก้ไขการ์ดชุดเดิม</p>
                        </div>
                        <div class="p-4">
                            <div class="flex gap-2">
                                <input type="text" id="studioFlexInstruction" class="flex-1 px-4 py-2 border rounded-xl text-sm focus:ring-2 focus:ring-teal-500" placeholder="เช่น 'เปลี่ยนสีเป็นสีส้ม', 'เปลี่ยนราคาเดิมเป็น 500 บาท'">
                                <button onclick="refineStudioFlex()" id="btnStudioRefineFlex" class="px-4 py-2 bg-gradient-to-r from-teal-500 to-emerald-500 text-white text-sm rounded-xl font-bold hover:opacity-90 transition-all flex items-center gap-1.5 shrink-0">
                                    <i class="fas fa-paper-plane text-xs"></i>ส่งคำสั่ง
                                </button>
                            </div>
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
<script src="assets/js/flex-preview.js"></script>
<script src="assets/js/flex-product-builder.js"></script>
<script>
// State
let studioCurrentApiKey = '<?= addslashes($geminiApiKey) ?>';
let studioCurrentImageStyle = 'realistic';
let studioCurrentFlexColor = '#06C755';
let studioCurrentFlexJson = null;
let studioFlexImages = []; // Array of reference image data URLs for Flex
let studioPickedProducts = []; // Real products chosen from the product picker
let studioFlexCopy = {};       // AI-written (or default) marketing copy for the product builder
let studioChatHistory = [];
let studioChatImageData = null;
let studioCaptionImageData = null;

// --- Product picker handoff (popup postMessage + sessionStorage fallback) ---
window.addEventListener('message', function (e) {
    if (e.origin !== location.origin) return;
    if (!e.data || e.data.type !== 'reya:products') return;
    if (e.data.returnTo && e.data.returnTo !== 'studio') return;
    applyPickedProducts(e.data.products || []);
});

function applyPickedProducts(products) {
    studioPickedProducts = Array.isArray(products) ? products : [];
    renderStudioPickedChips();
    if (studioPickedProducts.length) {
        switchStudioTab('flex');
        buildFlexFromProducts();
        showStudioToast('นำเข้าสินค้า ' + studioPickedProducts.length + ' ชิ้นแล้ว');
    }
}

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
async function callStudioGemini(text, systemInstruction = '', imageData = null) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${studioCurrentApiKey}`;
    const parts = [];
    
    if (imageData) {
        parts.push({ inlineData: { mimeType: imageData.mimeType, data: imageData.data } });
    }
    parts.push({ text });
    
    const body = { contents: [{ parts }] };
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
function setStudioFlexColor(color, isCustom) {
    studioCurrentFlexColor = color;
    document.querySelectorAll('.studio-color-btn').forEach(btn => {
        btn.classList.toggle('ring-4', !isCustom && btn.dataset.color === color);
        btn.classList.toggle('ring-offset-2', !isCustom && btn.dataset.color === color);
        btn.classList.toggle('ring-blue-400', !isCustom && btn.dataset.color === color);
    });
    if (studioPickedProducts.length) buildFlexFromProducts();
}

function applyMarketingPreset() {
    const preset = document.getElementById('studioFlexMarketingPreset').value;
    const promptArea = document.getElementById('studioFlexPrompt');
    const presets = {
        pain_outcome: `[ปัญหาคนไข้]: เภสัชกรไม่มีเวลาโทรตามคนไข้ หรือคนไข้ลืมกินยา\n[ฟีเจอร์หลัก]: บริการจัดส่งยาถึงบ้านพร้อมระบบแจ้งเตือนผ่าน LINE\n[ผลลัพธ์ที่ได้]: คนไข้ได้รับยาต่อเนื่อง สุขภาพดีขึ้น ร้านยามียอดขายสม่ำเสมอ\n[รายละเอียดเสนอขาย]: แนะนำแพ็กเกจจัดส่งยาโรคเรื้อรังรายเดือน ฟรีค่าส่งและแถมกล่องใส่ยาเตือนความจำ`,
        symptom_care: `[อาการ/กลุ่มโรค]: มีอาการภูมิแพ้ จาม คัดจมูกช่วงหน้าฝน\n[การดูแลเบื้องต้น]: ล้างจมูกด้วยน้ำเกลือและทานยาแก้แพ้ที่ไม่ง่วง\n[สินค้าแนะนำ]: เซ็ตล้างจมูกและยาแก้แพ้กลุ่มพรีเมียม\n[ข้อเสนอพิเศษ]: ซื้อเซ็ตล้างจมูกคู่ยาแก้แพ้ ลดทันที 15% พร้อมคำปรึกษาจากเภสัชกรฟรีผ่าน LINE`,
        refill_reminder: `[กลุ่มโรคเรื้อรัง]: คนไข้โรคความดันโลหิตสูง/เบาหวาน ที่ยาใกล้จะหมดรอบ 30 วัน\n[ความสำคัญของการทานยา]: ทานยาต่อเนื่องควบคุมระดับความดัน/น้ำตาล ป้องกันโรคแทรกซ้อน\n[การอำนวยความสะดวก]: บริการจัดยาและส่งตรงถึงบ้าน หรือนัดรับยาที่ร้านล่วงหน้าไม่ต้องรอคิว\n[CTA]: ทักแชทเพื่อแจ้งยืนยันรับยาต่อเนื่องรอบใหม่ได้ทันที`,
        loyalty_points: `[คะแนนสะสม]: ใช้คะแนนสะสม 100 คะแนน\n[แลกของรางวัล]: แลกรับส่วนลด 50 บาท หรือแลกรับหน้ากากอนามัยแพ็ก 50 ชิ้น ฟรี!\n[ระยะเวลา]: เฉพาะเดือนนี้เท่านั้น\n[ความคุ้มค่า]: คืนกำไรให้ลูกค้าประจำที่สนับสนุนร้านยาของเราเสมอมา`,
        special_discount: `[รายละเอียดโปรโมชั่น]: โปรโมชั่นดูแลสุขภาพต้อนรับฤดูกาล\n[สินค้าจัดเซ็ต]: วิตามินซี 1000mg + ซิงค์ (Zinc) แพ็กคู่ราคาพิเศษ\n[ราคาปกติ]: 750 บาท\n[ราคาพิเศษ]: 590 บาท (ประหยัด 160 บาท)\n[CTA]: สั่งซื้อด่วนผ่าน LINE Mini App สินค้ามีจำนวนจำกัด`
    };
    if (presets[preset]) {
        promptArea.value = presets[preset];
        showStudioToast('นำเข้าโครงสร้าง Prompt การตลาดแล้ว!');
    }
}

function handleStudioFlexImages(e) {
    const files = e.target.files;
    if (!files || files.length === 0) return;
    
    const container = document.getElementById('studioFlexImagePreviewContainer');
    const placeholder = document.getElementById('studioFlexImagePlaceholder');
    
    studioFlexImages = [];
    container.innerHTML = '';
    
    const count = Math.min(files.length, 10);
    for (let i = 0; i < count; i++) {
        const file = files[i];
        const reader = new FileReader();
        reader.onload = function(event) {
            const dataUrl = event.target.result;
            studioFlexImages.push(dataUrl);
            
            const div = document.createElement('div');
            div.className = 'relative w-16 h-16 border rounded-lg overflow-hidden group shadow-sm bg-gray-100';
            div.innerHTML = `
                <img src="${dataUrl}" class="w-full h-full object-cover">
                <button type="button" class="absolute top-0 right-0 bg-red-600 text-white w-5 h-5 rounded-full flex items-center justify-center text-xs opacity-80 hover:opacity-100" onclick="event.stopPropagation(); removeStudioFlexImage(${studioFlexImages.length - 1})">
                    <i class="fas fa-times text-[10px]"></i>
                </button>
            `;
            container.appendChild(div);
        };
        reader.readAsDataURL(file);
    }
    
    placeholder.classList.add('hidden');
    container.classList.remove('hidden');
}

function removeStudioFlexImage(index) {
    studioFlexImages.splice(index, 1);
    renderStudioFlexImagePreviews();
}

function renderStudioFlexImagePreviews() {
    const container = document.getElementById('studioFlexImagePreviewContainer');
    const placeholder = document.getElementById('studioFlexImagePlaceholder');
    
    container.innerHTML = '';
    if (studioFlexImages.length === 0) {
        placeholder.classList.remove('hidden');
        container.classList.add('hidden');
        document.getElementById('studioFlexImageInput').value = '';
        return;
    }
    
    studioFlexImages.forEach((dataUrl, i) => {
        const div = document.createElement('div');
        div.className = 'relative w-16 h-16 border rounded-lg overflow-hidden group shadow-sm bg-gray-100';
        div.innerHTML = `
            <img src="${dataUrl}" class="w-full h-full object-cover">
            <button type="button" class="absolute top-0 right-0 bg-red-600 text-white w-5 h-5 rounded-full flex items-center justify-center text-xs opacity-80 hover:opacity-100" onclick="event.stopPropagation(); removeStudioFlexImage(${i})">
                <i class="fas fa-times text-[10px]"></i>
            </button>
        `;
        container.appendChild(div);
    });
}

// ===== Real-product picker + hybrid builder =====
function escapeStudioHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function openProductPicker() {
    const w = 980, h = 740;
    const left = Math.max(0, (screen.width - w) / 2), top = Math.max(0, (screen.height - h) / 2);
    const popup = window.open('product-picker.php?return=studio', 'reyaProductPicker',
        `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
    if (!popup) showStudioToast('เบราว์เซอร์บล็อก popup — โปรดอนุญาตแล้วลองอีกครั้ง', true);
}

function renderStudioPickedChips() {
    const wrap = document.getElementById('studioPickedChips');
    const empty = document.getElementById('studioPickedEmpty');
    const opts = document.getElementById('studioProductOptions');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!studioPickedProducts.length) {
        empty.classList.remove('hidden');
        opts.classList.add('hidden');
        return;
    }
    empty.classList.add('hidden');
    opts.classList.remove('hidden');
    studioPickedProducts.forEach((p, i) => {
        const price = p.promotionPrice != null ? p.promotionPrice : p.basePrice;
        const chip = document.createElement('span');
        chip.className = 'inline-flex items-center gap-1.5 bg-white border border-emerald-300 rounded-full pl-1 pr-2 py-1 text-xs shadow-sm';
        chip.innerHTML =
            `<img src="${escapeStudioHtml(p.image)}" class="w-6 h-6 rounded-full object-cover bg-gray-100" onerror="this.style.visibility='hidden'">` +
            `<span class="font-medium text-gray-700 max-w-[150px] truncate" title="${escapeStudioHtml(p.name)}">${escapeStudioHtml(p.name)}</span>` +
            `<span class="text-emerald-600 font-bold">฿${Math.round(price)}</span>` +
            `<button type="button" onclick="removePickedProduct(${i})" class="text-gray-400 hover:text-red-500 ml-0.5"><i class="fas fa-times-circle"></i></button>`;
        wrap.appendChild(chip);
    });
}

function removePickedProduct(index) {
    studioPickedProducts.splice(index, 1);
    renderStudioPickedChips();
    if (studioPickedProducts.length) buildFlexFromProducts();
}

// Assemble the Flex from real products (deterministic) + current copy. No network.
function buildFlexFromProducts() {
    if (!studioPickedProducts.length) { showStudioToast('ยังไม่ได้เลือกสินค้า', true); return; }
    if (typeof FlexProductBuilder === 'undefined') { showStudioToast('ตัวสร้าง Flex ยังไม่พร้อม', true); return; }
    const type = document.getElementById('studioFlexType').value;
    const layout = (document.getElementById('studioFlexLayout') || {}).value || '2up';
    const flex = FlexProductBuilder.build({
        products: studioPickedProducts,
        copy: studioFlexCopy,
        theme: type,
        color: studioCurrentFlexColor,
        layout: layout,
    });
    if (!flex) { showStudioToast('สร้าง Flex ไม่สำเร็จ', true); return; }
    studioCurrentFlexJson = flex;
    if (typeof FlexPreview !== 'undefined') FlexPreview.render('studioFlexPreview', flex);
    document.getElementById('studioFlexJson').textContent = JSON.stringify(flex, null, 2);
    document.getElementById('studioFlexRefineSection').classList.remove('hidden');
    document.getElementById('btnFlexUseBroadcast').classList.remove('hidden');
}

// Hybrid: ask Gemini for marketing copy only (no prices), then rebuild deterministically.
async function generateFlexCopy() {
    if (!studioPickedProducts.length) { showStudioToast('เลือกสินค้าก่อนให้ AI เขียนคำโปรย', true); return; }
    const btn = document.getElementById('btnStudioGenCopy');
    const status = document.getElementById('studioCopyStatus');
    btn.disabled = true;
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังเขียน...';
    if (status) status.textContent = '';
    try {
        const payload = {
            mode: 'copy',
            type: document.getElementById('studioFlexType').value,
            theme: document.getElementById('studioFlexType').value,
            product_names: studioPickedProducts.map(p => p.name).filter(Boolean),
            hint: document.getElementById('studioFlexPrompt').value.trim(),
            api_key: studioCurrentApiKey,
        };
        const res = await fetch('api/ai-studio-flex.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || `HTTP ${res.status}`);
        studioFlexCopy = data.copy || {};
        buildFlexFromProducts();
        if (status) status.textContent = '✓ เขียนคำโปรยแล้ว';
        showStudioToast('AI เขียนคำโปรยให้แล้ว!');
    } catch (err) {
        showStudioToast('เขียนคำโปรยไม่สำเร็จ: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

async function generateStudioFlex() {
    // Real products selected → deterministic builder (prices locked). AI path otherwise.
    if (studioPickedProducts.length) { buildFlexFromProducts(); return; }
    const prompt = document.getElementById('studioFlexPrompt').value.trim();
    if (!prompt) { showStudioToast('กรุณากรอกรายละเอียด หรือเลือกสินค้าจริง', true); return; }

    const btn = document.getElementById('btnStudioGenFlex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const type = document.getElementById('studioFlexType').value;
        const bubbleCount = parseInt(document.getElementById('studioFlexBubbleCount').value) || 1;
        
        const payload = {
            prompt: prompt,
            type: type,
            color: studioCurrentFlexColor,
            bubble_count: bubbleCount,
            reference_images: studioFlexImages,
            api_key: studioCurrentApiKey
        };
        
        const res = await fetch('api/ai-studio-flex.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        if (!res.ok) {
            const errData = await res.json();
            throw new Error(errData.error || `HTTP ${res.status}`);
        }
        
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'สร้าง Flex ไม่สำเร็จ');
        }
        
        studioCurrentFlexJson = data.flex;
        
        if (typeof FlexPreview !== 'undefined') {
            FlexPreview.render('studioFlexPreview', studioCurrentFlexJson);
        } else {
            document.getElementById('studioFlexPreview').innerHTML = '<div class="text-center text-white"><i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i><p>Flex Message สร้างสำเร็จ!</p></div>';
        }
        
        document.getElementById('studioFlexJson').textContent = JSON.stringify(studioCurrentFlexJson, null, 2);
        
        // Show refinement section & broadcast button
        document.getElementById('studioFlexRefineSection').classList.remove('hidden');
        document.getElementById('btnFlexUseBroadcast').classList.remove('hidden');
        
        showStudioToast('สร้าง Flex Message สำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>สร้าง Flex Message';
    }
}

async function refineStudioFlex() {
    const instruction = document.getElementById('studioFlexInstruction').value.trim();
    if (!instruction) { showStudioToast('กรุณากรอกคำสั่งปรับปรุง', true); return; }
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex ปัจจุบันเพื่อแก้ไข', true); return; }

    // Product-built Flex → refine COPY only, then rebuild deterministically so
    // prices/SKUs/images stay locked. AI can never corrupt the numbers.
    if (studioPickedProducts.length) {
        const btnP = document.getElementById('btnStudioRefineFlex');
        btnP.disabled = true;
        const origP = btnP.innerHTML;
        btnP.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังปรับปรุง...';
        try {
            const res = await fetch('api/ai-studio-flex.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mode: 'copy',
                    type: document.getElementById('studioFlexType').value,
                    theme: document.getElementById('studioFlexType').value,
                    product_names: studioPickedProducts.map(p => p.name).filter(Boolean),
                    hint: instruction,
                    api_key: studioCurrentApiKey,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.error || `HTTP ${res.status}`);
            studioFlexCopy = data.copy || studioFlexCopy;
            buildFlexFromProducts();
            document.getElementById('studioFlexInstruction').value = '';
            showStudioToast('ปรับปรุงคำโปรยแล้ว (ราคาคงเดิม)');
        } catch (err) {
            showStudioToast('ปรับปรุงไม่สำเร็จ: ' + err.message, true);
        } finally {
            btnP.disabled = false;
            btnP.innerHTML = origP;
        }
        return;
    }

    const btn = document.getElementById('btnStudioRefineFlex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังปรับปรุง...';

    try {
        const payload = {
            mode: 'edit',
            instruction: instruction,
            flex: studioCurrentFlexJson,
            api_key: studioCurrentApiKey
        };
        
        const res = await fetch('api/ai-studio-flex.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        if (!res.ok) {
            const errData = await res.json();
            throw new Error(errData.error || `HTTP ${res.status}`);
        }
        
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'ปรับปรุง Flex ไม่สำเร็จ');
        }
        
        studioCurrentFlexJson = data.flex;
        
        if (typeof FlexPreview !== 'undefined') {
            FlexPreview.render('studioFlexPreview', studioCurrentFlexJson);
        }
        document.getElementById('studioFlexJson').textContent = JSON.stringify(studioCurrentFlexJson, null, 2);
        document.getElementById('studioFlexInstruction').value = '';
        
        showStudioToast('ปรับปรุง Flex Message สำเร็จ!');
    } catch (err) {
        showStudioToast('เกิดข้อผิดพลาด: ' + err.message, true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่งคำสั่ง';
    }
}

function copyStudioFlexJson() {
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex JSON', true); return; }
    navigator.clipboard.writeText(JSON.stringify(studioCurrentFlexJson, null, 2));
    showStudioToast('คัดลอก JSON แล้ว!');
}

function useFlexInBroadcast() {
    if (!studioCurrentFlexJson) { showStudioToast('ยังไม่มี Flex JSON', true); return; }
    
    let altText = 'แคมเปญพิเศษจากร้านยา';
    try {
        if (studioCurrentFlexJson.type === 'bubble') {
            altText = studioCurrentFlexJson.altText || studioCurrentFlexJson.header?.contents?.[0]?.text || studioCurrentFlexJson.body?.contents?.[0]?.text || altText;
        } else if (studioCurrentFlexJson.type === 'carousel') {
            altText = studioCurrentFlexJson.altText || studioCurrentFlexJson.contents?.[0]?.header?.contents?.[0]?.text || altText;
        }
    } catch (e) {}
    
    sessionStorage.setItem('flex_broadcast', JSON.stringify(studioCurrentFlexJson));
    sessionStorage.setItem('flex_broadcast_alttext', altText);
    
    showStudioToast('ส่งข้อมูลไปยังระบบ Broadcast...');
    setTimeout(() => {
        window.location.href = 'broadcast.php?flex=1';
    }, 800);
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
    if (!studioCurrentApiKey) { openStudioApiModal(); return; }
    const prompt = document.getElementById('studioCaptionPrompt').value.trim();
    if (!prompt && !studioCaptionImageData) { showStudioToast('กรุณากรอกรายละเอียดหรืออัปโหลดรูป', true); return; }
    
    const btn = document.getElementById('btnStudioGenCaption');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง...';
    
    try {
        const type = document.getElementById('studioCaptionType').value;
        const includeHashtags = document.getElementById('studioIncludeHashtags').checked;
        const includeEmoji = document.getElementById('studioIncludeEmoji').checked;
        
        let systemPrompt = `สร้างแคปชั่นโซเชียลมีเดียภาษาไทย ประเภท: ${type} ${includeHashtags ? 'ใส่ Hashtags 5-10 อัน' : ''} ${includeEmoji ? 'ใส่ Emoji' : ''} สร้าง 3 เวอร์ชัน: สั้น, กลาง, ยาว`;
        let finalPrompt = prompt;
        
        if (studioCaptionImageData) {
            systemPrompt = `วิเคราะห์รูปภาพและ` + systemPrompt;
            finalPrompt = prompt ? `${prompt}\n\nวิเคราะห์รูปภาพและสร้างแคปชั่นที่เหมาะสม` : 'วิเคราะห์รูปภาพนี้และสร้างแคปชั่นที่น่าสนใจ';
        }
        
        const caption = await callStudioGemini(finalPrompt, systemPrompt, studioCaptionImageData);
        
        document.getElementById('studioCaptionResult').innerHTML = `<div class="prose prose-sm max-w-none">${typeof marked !== 'undefined' ? marked.parse(caption) : caption}</div>`;
        showStudioToast('สร้างแคปชั่นสำเร็จ!');
        
        if (studioCaptionImageData) clearStudioCaptionImage();
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

// Fallback: consume products handed off via sessionStorage when popup was blocked.
(function consumePickedFromSession() {
    try {
        const raw = sessionStorage.getItem('reya_picked_products');
        if (!raw) return;
        sessionStorage.removeItem('reya_picked_products');
        const products = JSON.parse(raw);
        if (Array.isArray(products) && products.length) applyPickedProducts(products);
    } catch (e) { /* ignore */ }
})();
</script>
