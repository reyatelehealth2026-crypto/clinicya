<?php
/**
 * Flex Message Builder V3 - Advanced Drag & Drop with JSON Editor
 * สร้าง Flex Message สำหรับ LINE แบบลากวาง + แก้ไข JSON ตรง
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php'; // enforce login + start session before AJAX
require_once 'includes/flex-slots.php'; // slot override allowlist

$db = Database::getInstance()->getConnection();
$pageTitle = 'Flex Message Builder';
// Resolve tenant before header.php so AJAX saves are correctly scoped (header runs later).
$currentBotId = $_SESSION['current_bot_id'] ?? ($_SESSION['line_account_id'] ?? null);

// Handle AJAX actions — gated on X-Requested-With per the same-page-AJAX convention
// (CLAUDE.md) so a cross-site form POST can't create/delete/bind templates (CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_template') {
        $name = trim($_POST['name'] ?? 'Untitled');
        $flexJson = $_POST['flex_json'] ?? '{}';
        $category = $_POST['category'] ?? 'custom';
        // Optional slot binding (Flex Studio): sanitize to [a-z0-9_].
        $slotKey = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['slot_key'] ?? ($_GET['slot'] ?? '')));
        $slotKey = $slotKey !== '' ? $slotKey : null;
        // Only bind to override-safe (static) slots; dynamic/unknown slots stay unbound.
        if ($slotKey !== null && function_exists('flex_slot_allows_override') && !flex_slot_allows_override($slotKey)) {
            $slotKey = null;
        }
        try {
            if ($slotKey !== null) {
                $stmt = $db->prepare("INSERT INTO flex_templates (name, category, flex_json, line_account_id, slot_key, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $category, $flexJson, $currentBotId ?? null, $slotKey]);
            } else {
                $stmt = $db->prepare("INSERT INTO flex_templates (name, category, flex_json, line_account_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $category, $flexJson, $currentBotId ?? null]);
            }
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_templates') {
        try {
            $stmt = $db->prepare("SELECT id, name, category, flex_json, created_at FROM flex_templates WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY created_at DESC LIMIT 50");
            $stmt->execute([$currentBotId ?? null]);
            echo json_encode(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'templates' => []]);
        }
        exit;
    }

    if ($_POST['action'] === 'delete_template') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // Tenant-scoped: only delete templates owned by this shop (or legacy global).
            $stmt = $db->prepare("DELETE FROM flex_templates WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$id, $currentBotId ?? null]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

require_once 'includes/header.php';
?>

<script src="assets/js/flex-preview.js"></script>
<script src="assets/js/flex-product-builder.js"></script>
<script>
// Flex Studio: slot this builder session is editing (from ?slot=), bound on save.
const FLEX_SLOT = <?= json_encode(preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['slot'] ?? ''))) ?>;
</script>

<!-- Toast Container -->
<div id="toast-container" class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none"></div>

<!-- Main Layout -->
<div class="flex h-[calc(100vh-130px)] gap-0">

    <!-- ==================== Left Panel: Components ==================== -->
    <div class="w-56 bg-white border-r flex flex-col shrink-0">
        <div class="p-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
            <h2 class="font-bold text-sm">Components</h2>
            <p class="text-[10px] opacity-75">ลากไปวางในพื้นที่ออกแบบ</p>
        </div>
        <div class="flex-1 overflow-y-auto p-2 space-y-1" id="component-list">
            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider px-1 pt-1">Layout</div>
            <div class="comp-item" draggable="true" data-type="bubble">
                <div class="ci ci-blue"><span>📦</span><div><b>Bubble</b><small>กล่องข้อความ</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="carousel">
                <div class="ci ci-purple"><span>🎠</span><div><b>Carousel</b><small>หลาย Bubble</small></div></div>
            </div>

            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider px-1 pt-3">Content</div>
            <div class="comp-item" draggable="true" data-type="hero">
                <div class="ci ci-amber"><span>🖼️</span><div><b>Hero Image</b><small>รูปภาพหลัก</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="text">
                <div class="ci ci-green"><span>Aa</span><div><b>Text</b><small>ข้อความ</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="button">
                <div class="ci ci-rose"><span>⬡</span><div><b>Button</b><small>ปุ่มกด</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="separator">
                <div class="ci ci-gray"><span>—</span><div><b>Separator</b><small>เส้นแบ่ง</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="spacer">
                <div class="ci ci-gray"><span>↕</span><div><b>Spacer</b><small>ช่องว่าง</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="box">
                <div class="ci ci-indigo"><span>▦</span><div><b>Box</b><small>กล่องจัดเรียง</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="filler">
                <div class="ci ci-gray"><span>⇔</span><div><b>Filler</b><small>เติมช่องว่าง</small></div></div>
            </div>
            <div class="comp-item" draggable="true" data-type="icon">
                <div class="ci ci-yellow"><span>★</span><div><b>Icon</b><small>ไอคอน</small></div></div>
            </div>
        </div>
        <div class="p-2 border-t space-y-1.5">
            <button onclick="showTemplatesModal()" class="w-full py-2 bg-gradient-to-r from-violet-500 to-fuchsia-500 text-white rounded-lg text-xs font-semibold hover:opacity-90 transition">
                📚 เทมเพลตสำเร็จรูป
            </button>
        </div>
    </div>

    <!-- ==================== Center Panel: Canvas ==================== -->
    <div class="flex-1 flex flex-col bg-gray-100 min-w-0">
        <!-- Canvas Toolbar -->
        <div class="flex items-center gap-1.5 px-3 py-2 bg-white border-b">
            <span class="text-sm font-semibold text-gray-700 mr-2">🎨 Canvas</span>
            <div class="flex-1"></div>
            <button onclick="undoAction()" id="btn-undo" class="tb-btn" title="Undo (Ctrl+Z)" disabled>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4m-4 4l4 4"/></svg>
            </button>
            <button onclick="redoAction()" id="btn-redo" class="tb-btn" title="Redo (Ctrl+Y)" disabled>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 00-5 5v2m15-7l-4-4m4 4l-4 4"/></svg>
            </button>
            <div class="w-px h-5 bg-gray-200 mx-1"></div>
            <button onclick="clearCanvas()" class="tb-btn text-red-500 hover:bg-red-50" title="Clear All">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </div>

        <!-- Canvas Area -->
        <div class="flex-1 overflow-auto p-4">
            <div id="canvas" class="canvas-drop-zone min-h-full rounded-xl border-2 border-dashed border-gray-300 p-4 transition-all"
                 ondragover="handleDragOver(event)" ondrop="handleDrop(event)" ondragleave="handleDragLeave(event)">
                <div id="canvas-empty" class="flex flex-col items-center justify-center py-16 text-gray-400">
                    <div class="w-16 h-16 rounded-2xl bg-gray-200 flex items-center justify-center text-2xl mb-3">📦</div>
                    <p class="text-sm font-medium">ลาก Component มาวางที่นี่</p>
                    <p class="text-xs mt-1">หรือ วาง JSON / เลือกจากเทมเพลต</p>
                </div>
                <div id="canvas-content" class="hidden"></div>
            </div>
        </div>
    </div>

    <!-- ==================== Right Panel: Preview + Tabs ==================== -->
    <div class="w-[380px] bg-white border-l flex flex-col shrink-0">
        <!-- LINE Preview -->
        <div class="flex flex-col" style="height:45%">
            <div class="flex items-center gap-2 px-3 py-2 bg-gradient-to-r from-[#06C755] to-[#05a648] text-white shrink-0">
                <span class="text-sm font-bold">📱 LINE Preview</span>
            </div>
            <div class="flex-1 overflow-auto bg-[#7494A5] p-3 flex justify-center items-start">
                <div id="flex-preview" class="w-full max-w-[300px]">
                    <div class="text-center text-white/50 py-8">
                        <div class="text-3xl mb-1">💬</div>
                        <p class="text-xs">Preview จะแสดงที่นี่</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabbed Panel: Properties + JSON Editor -->
        <div class="flex-1 flex flex-col border-t min-h-0">
            <div class="flex border-b shrink-0">
                <button onclick="switchTab('properties')" class="tab-btn active" data-tab="properties">
                    ⚙️ Properties
                </button>
                <button onclick="switchTab('json')" class="tab-btn" data-tab="json">
                    { } JSON Editor
                </button>
            </div>

            <!-- Properties Tab -->
            <div id="tab-properties" class="tab-panel flex-1 overflow-y-auto p-3">
                <p class="text-gray-400 text-xs text-center py-6">คลิกที่ Component บน Canvas เพื่อแก้ไข</p>
            </div>

            <!-- JSON Editor Tab -->
            <div id="tab-json" class="tab-panel hidden flex-1 flex flex-col min-h-0">
                <div class="flex items-center gap-1 px-2 py-1.5 bg-gray-50 border-b shrink-0">
                    <button onclick="formatJson()" class="jt-btn" title="Format">Format</button>
                    <button onclick="minifyJson()" class="jt-btn" title="Minify">Mini</button>
                    <button onclick="copyJsonEditor()" class="jt-btn" title="Copy">Copy</button>
                    <div class="flex-1"></div>
                    <span id="json-status" class="text-[10px] font-medium text-green-600 hidden">✓ Valid</span>
                    <button onclick="applyJson()" class="px-3 py-1 bg-blue-600 text-white rounded text-[11px] font-semibold hover:bg-blue-700 transition">
                        Apply ▶
                    </button>
                </div>
                <textarea id="json-editor"
                    class="flex-1 font-mono text-[11px] leading-relaxed p-3 border-0 resize-none focus:outline-none bg-gray-50 text-gray-800 min-h-0"
                    spellcheck="false"
                    placeholder="วาง JSON ที่นี่ หรือสร้างจาก Canvas แล้วกด Apply เพื่อโหลด..."
                    oninput="onJsonEditorInput()"></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Bottom Action Bar ==================== -->
<div class="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg py-2.5 px-4 ml-64 z-40">
    <div class="flex justify-between items-center">
        <div class="flex gap-2">
            <button onclick="openImportModal()" class="act-btn">
                📥 วาง/Import JSON
            </button>
            <button onclick="showFullJson()" class="act-btn">
                📄 ดู JSON เต็ม
            </button>
            <button onclick="openBuilderProductPicker()" class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition inline-flex items-center gap-1">
                🛒 เลือกสินค้าจริง
            </button>
            <a href="https://developers.line.biz/flex-simulator/" target="_blank" rel="noopener" class="act-btn inline-flex items-center gap-1">
                🔗 Flex Simulator
            </a>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 mr-3">
                <label class="text-[10px] text-gray-400 whitespace-nowrap">altText:</label>
                <input type="text" id="alt-text-input" value="Flex Message" class="px-2 py-1 border rounded text-xs w-28 focus:ring-1 focus:ring-blue-400 focus:outline-none" placeholder="altText">
            </div>
            <span class="text-[10px] text-gray-400 hidden sm:inline" id="shortcut-hint">Ctrl+Z/Y Undo/Redo | Ctrl+S Save | Del ลบ</span>
            <button onclick="saveTemplate()" class="act-btn-primary">
                💾 บันทึกเทมเพลต
            </button>
            <button onclick="useToBroadcast()" class="px-5 py-2 bg-gradient-to-r from-[#06C755] to-emerald-600 text-white rounded-lg text-sm font-semibold hover:opacity-90 transition shadow-sm">
                📤 ใช้ใน Broadcast
            </button>
        </div>
    </div>
</div>

<!-- ==================== Templates Modal ==================== -->
<div id="modal-templates" class="modal-overlay hidden">
    <div class="modal-box max-w-4xl">
        <div class="flex items-center justify-between p-4 border-b">
            <h3 class="font-bold text-lg">📚 เทมเพลตสำเร็จรูป</h3>
            <button onclick="closeModal('modal-templates')" class="modal-close">&times;</button>
        </div>
        <div class="p-4">
            <!-- Built-in tabs -->
            <div class="flex gap-2 mb-4">
                <button onclick="showTemplateTab('builtin')" class="tmpl-tab active" data-tmpl-tab="builtin">สำเร็จรูป</button>
                <button onclick="showTemplateTab('saved')" class="tmpl-tab" data-tmpl-tab="saved">ที่บันทึกไว้</button>
            </div>
            <!-- Built-in Templates -->
            <div id="tmpl-builtin" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div onclick="useBuiltinTemplate('product')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-orange-100 to-red-100">🛍️</div>
                    <h4>Product Card</h4><p>การ์ดสินค้า</p>
                </div>
                <div onclick="useBuiltinTemplate('promotion')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-pink-100 to-purple-100">🎁</div>
                    <h4>Promotion</h4><p>โปรโมชั่น</p>
                </div>
                <div onclick="useBuiltinTemplate('news')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-blue-100 to-indigo-100">📰</div>
                    <h4>News</h4><p>ข่าวสาร</p>
                </div>
                <div onclick="useBuiltinTemplate('receipt')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-green-100 to-teal-100">🧾</div>
                    <h4>Receipt</h4><p>ใบเสร็จ</p>
                </div>
                <div onclick="useBuiltinTemplate('menu')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-yellow-100 to-orange-100">📋</div>
                    <h4>Menu</h4><p>เมนูร้าน</p>
                </div>
                <div onclick="useBuiltinTemplate('contact')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-cyan-100 to-blue-100">📞</div>
                    <h4>Contact</h4><p>ติดต่อเรา</p>
                </div>
                <div onclick="useBuiltinTemplate('coupon')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-rose-100 to-pink-100">🎟️</div>
                    <h4>Coupon</h4><p>คูปองส่วนลด</p>
                </div>
                <div onclick="useBuiltinTemplate('appointment')" class="tmpl-card">
                    <div class="tmpl-icon bg-gradient-to-br from-emerald-100 to-green-100">📅</div>
                    <h4>Appointment</h4><p>นัดหมาย</p>
                </div>
            </div>
            <!-- Saved Templates -->
            <div id="tmpl-saved" class="hidden">
                <div id="saved-templates-list" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div class="text-center py-8 text-gray-400 col-span-full">
                        <p class="text-sm">กำลังโหลด...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Import JSON Modal ==================== -->
<div id="modal-import" class="modal-overlay hidden">
    <div class="modal-box max-w-2xl">
        <div class="flex items-center justify-between p-4 border-b">
            <h3 class="font-bold text-lg">📥 Import / วาง JSON</h3>
            <button onclick="closeModal('modal-import')" class="modal-close">&times;</button>
        </div>
        <div class="p-4">
            <p class="text-sm text-gray-500 mb-3">วาง LINE Flex Message JSON แล้วกด Import เพื่อโหลดเข้า Canvas</p>
            <textarea id="import-json-input"
                class="w-full h-64 font-mono text-xs border rounded-lg p-3 bg-gray-50 focus:ring-2 focus:ring-blue-300 focus:outline-none"
                placeholder='{"type":"bubble","body":{"type":"box","layout":"vertical","contents":[...]}}'
                spellcheck="false"></textarea>
            <div id="import-error" class="text-red-500 text-xs mt-2 hidden"></div>
            <div class="flex justify-end gap-2 mt-4">
                <button onclick="closeModal('modal-import')" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">ยกเลิก</button>
                <button onclick="doImportJson()" class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold">Import & โหลดเข้า Canvas</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Full JSON View Modal ==================== -->
<div id="modal-json-view" class="modal-overlay hidden">
    <div class="modal-box max-w-3xl">
        <div class="flex items-center justify-between p-4 border-b">
            <h3 class="font-bold text-lg">📄 Flex Message JSON (Full)</h3>
            <button onclick="closeModal('modal-json-view')" class="modal-close">&times;</button>
        </div>
        <div class="p-4">
            <textarea id="full-json-output" class="w-full h-[400px] font-mono text-xs border rounded-lg p-3 bg-gray-50" readonly></textarea>
            <div class="flex justify-end gap-2 mt-3">
                <button onclick="copyFullJson()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold">📋 Copy JSON</button>
                <button onclick="closeModal('modal-json-view')" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== Save Template Modal ==================== -->
<div id="modal-save" class="modal-overlay hidden">
    <div class="modal-box max-w-md">
        <div class="flex items-center justify-between p-4 border-b">
            <h3 class="font-bold text-lg">💾 บันทึกเทมเพลต</h3>
            <button onclick="closeModal('modal-save')" class="modal-close">&times;</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">ชื่อเทมเพลต</label>
                <input type="text" id="save-template-name" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none" placeholder="เช่น โปรโมชั่นเดือน ก.พ.">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">หมวดหมู่</label>
                <select id="save-template-category" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:outline-none">
                    <option value="custom">ทั่วไป</option>
                    <option value="product">สินค้า</option>
                    <option value="promotion">โปรโมชั่น</option>
                    <option value="news">ข่าวสาร</option>
                    <option value="receipt">ใบเสร็จ</option>
                    <option value="other">อื่นๆ</option>
                </select>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeModal('modal-save')" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">ยกเลิก</button>
                <button onclick="doSaveTemplate()" class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold">บันทึก</button>
            </div>
        </div>
    </div>
</div>


<script>
// ========================================================================
// STATE MANAGEMENT
// ========================================================================
let flexData = null;
let selectedPath = null;
let historyStack = [null];
let historyIndex = 0;
let jsonEditorFocused = false;
let activeTab = 'properties';

// ========================================================================
// BUILT-IN TEMPLATES
// ========================================================================
const builtinTemplates = {
    product: {
        type:"bubble",
        hero:{type:"image",url:"https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png",size:"full",aspectRatio:"20:13",aspectMode:"cover",action:{type:"uri",uri:"https://example.com"}},
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"ชื่อสินค้า",weight:"bold",size:"xl"},
            {type:"text",text:"รายละเอียดสินค้า สั้นๆ กระชับ",size:"sm",color:"#888888",margin:"md",wrap:true},
            {type:"box",layout:"baseline",margin:"md",contents:[
                {type:"text",text:"฿",size:"sm",color:"#FF5551",flex:0},
                {type:"text",text:"999",size:"xl",color:"#FF5551",weight:"bold",flex:0},
                {type:"text",text:" ฿1,299",size:"xs",color:"#AAAAAA",decoration:"line-through",margin:"sm",flex:0}
            ]}
        ]},
        footer:{type:"box",layout:"vertical",spacing:"sm",contents:[
            {type:"button",style:"primary",color:"#06C755",action:{type:"uri",label:"🛒 สั่งซื้อเลย",uri:"https://example.com"}},
            {type:"button",style:"secondary",action:{type:"uri",label:"ดูรายละเอียด",uri:"https://example.com"}}
        ]}
    },
    promotion: {
        type:"bubble",
        styles:{hero:{backgroundColor:"#FF6B6B"}},
        hero:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"🎉 SALE",size:"3xl",weight:"bold",color:"#FFFFFF",align:"center"},
            {type:"text",text:"ลดสูงสุด 50%",size:"xl",color:"#FFFFFF",align:"center",margin:"md"}
        ],paddingAll:"30px"},
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"โปรโมชั่นพิเศษ!",weight:"bold",size:"lg"},
            {type:"text",text:"สินค้าลดราคาพิเศษ เฉพาะวันนี้เท่านั้น!",size:"sm",color:"#666666",wrap:true,margin:"md"},
            {type:"text",text:"📅 วันนี้ - สิ้นเดือนนี้",size:"xs",color:"#999999",margin:"lg"}
        ]},
        footer:{type:"box",layout:"vertical",contents:[
            {type:"button",style:"primary",color:"#FF6B6B",action:{type:"uri",label:"🛍️ ช้อปเลย!",uri:"https://example.com"}}
        ]}
    },
    news: {
        type:"bubble",
        hero:{type:"image",url:"https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png",size:"full",aspectRatio:"20:13",aspectMode:"cover"},
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"📰 ข่าวสาร",size:"xs",color:"#1DB446",weight:"bold"},
            {type:"text",text:"หัวข้อข่าว",weight:"bold",size:"lg",margin:"md"},
            {type:"text",text:"รายละเอียดข่าวสาร ข้อมูลสำคัญที่ต้องการสื่อสาร...",size:"sm",color:"#666666",wrap:true,margin:"md"},
            {type:"text",text:"15 ก.พ. 2569",size:"xs",color:"#999999",margin:"lg"}
        ]},
        footer:{type:"box",layout:"vertical",contents:[
            {type:"button",style:"link",action:{type:"uri",label:"อ่านเพิ่มเติม →",uri:"https://example.com"}}
        ]}
    },
    receipt: {
        type:"bubble",
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"🧾 ใบเสร็จ",weight:"bold",color:"#1DB446",size:"sm"},
            {type:"text",text:"ร้านค้าของคุณ",weight:"bold",size:"xxl",margin:"md"},
            {type:"separator",margin:"lg"},
            {type:"box",layout:"vertical",margin:"lg",spacing:"sm",contents:[
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"สินค้า A x1",size:"sm",color:"#555555",flex:0},
                    {type:"text",text:"฿100",size:"sm",color:"#111111",align:"end"}
                ]},
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"สินค้า B x2",size:"sm",color:"#555555",flex:0},
                    {type:"text",text:"฿400",size:"sm",color:"#111111",align:"end"}
                ]}
            ]},
            {type:"separator",margin:"lg"},
            {type:"box",layout:"horizontal",margin:"lg",contents:[
                {type:"text",text:"รวมทั้งสิ้น",size:"sm",color:"#555555",flex:0,weight:"bold"},
                {type:"text",text:"฿500",size:"lg",color:"#1DB446",align:"end",weight:"bold"}
            ]}
        ]}
    },
    menu: {
        type:"bubble",
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"📋 เมนูหลัก",weight:"bold",size:"xl",align:"center"},
            {type:"separator",margin:"lg"}
        ]},
        footer:{type:"box",layout:"vertical",spacing:"sm",contents:[
            {type:"button",style:"primary",color:"#06C755",action:{type:"message",label:"🛒 ดูสินค้า",text:"shop"}},
            {type:"button",style:"secondary",action:{type:"message",label:"📦 เช็คออเดอร์",text:"orders"}},
            {type:"button",style:"secondary",action:{type:"message",label:"💬 ติดต่อเรา",text:"contact"}}
        ]}
    },
    contact: {
        type:"bubble",
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"📞 ติดต่อเรา",weight:"bold",size:"xl"},
            {type:"separator",margin:"lg"},
            {type:"box",layout:"vertical",margin:"lg",spacing:"md",contents:[
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"📍",flex:0},
                    {type:"text",text:"123 ถนนสุขุมวิท กรุงเทพฯ",size:"sm",color:"#666666",flex:5,wrap:true,margin:"md"}
                ]},
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"📱",flex:0},
                    {type:"text",text:"02-xxx-xxxx",size:"sm",color:"#666666",flex:5,margin:"md"}
                ]},
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"⏰",flex:0},
                    {type:"text",text:"จ-ส 9:00-18:00",size:"sm",color:"#666666",flex:5,margin:"md"}
                ]}
            ]}
        ]},
        footer:{type:"box",layout:"vertical",contents:[
            {type:"button",style:"primary",color:"#06C755",action:{type:"uri",label:"📍 ดูแผนที่",uri:"https://maps.google.com"}}
        ]}
    },
    coupon: {
        type:"bubble",
        styles:{body:{backgroundColor:"#FFF4E6"}},
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"🎟️ คูปองส่วนลด",weight:"bold",size:"sm",color:"#E67E22"},
            {type:"text",text:"ลด 20%",weight:"bold",size:"3xl",color:"#E67E22",align:"center",margin:"lg"},
            {type:"text",text:"สำหรับการสั่งซื้อครั้งถัดไป",size:"sm",color:"#888888",align:"center",margin:"sm"},
            {type:"separator",margin:"lg"},
            {type:"box",layout:"horizontal",margin:"lg",contents:[
                {type:"text",text:"Code:",size:"sm",color:"#888888",flex:0},
                {type:"text",text:"SAVE20",size:"sm",weight:"bold",color:"#E67E22",align:"end"}
            ]},
            {type:"text",text:"หมดอายุ: 28 ก.พ. 2569",size:"xs",color:"#AAAAAA",margin:"md",align:"center"}
        ]},
        footer:{type:"box",layout:"vertical",contents:[
            {type:"button",style:"primary",color:"#E67E22",action:{type:"uri",label:"ใช้คูปอง",uri:"https://example.com"}}
        ]}
    },
    appointment: {
        type:"bubble",
        body:{type:"box",layout:"vertical",contents:[
            {type:"text",text:"📅 ยืนยันนัดหมาย",weight:"bold",size:"lg",color:"#06C755"},
            {type:"separator",margin:"md"},
            {type:"box",layout:"vertical",margin:"lg",spacing:"md",contents:[
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"วันที่",size:"sm",color:"#888888",flex:2},
                    {type:"text",text:"15 ก.พ. 2569",size:"sm",weight:"bold",flex:3}
                ]},
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"เวลา",size:"sm",color:"#888888",flex:2},
                    {type:"text",text:"14:00 น.",size:"sm",weight:"bold",flex:3}
                ]},
                {type:"box",layout:"horizontal",contents:[
                    {type:"text",text:"เภสัชกร",size:"sm",color:"#888888",flex:2},
                    {type:"text",text:"ภก. สมชาย ใจดี",size:"sm",weight:"bold",flex:3}
                ]}
            ]},
            {type:"text",text:"กรุณามาก่อนเวลา 10 นาที",size:"xs",color:"#AAAAAA",margin:"lg"}
        ]},
        footer:{type:"box",layout:"vertical",spacing:"sm",contents:[
            {type:"button",style:"primary",color:"#06C755",action:{type:"uri",label:"✅ ยืนยัน",uri:"https://example.com"}},
            {type:"button",style:"secondary",action:{type:"uri",label:"เลื่อนนัด",uri:"https://example.com"}}
        ]}
    }
};

// ========================================================================
// UTILITY FUNCTIONS
// ========================================================================
function esc(str) {
    return (str||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function toast(msg, type='success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `pointer-events-auto px-4 py-2.5 rounded-lg text-sm font-medium shadow-lg transform translate-x-full transition-transform duration-300 ${
        type==='success'?'bg-green-600 text-white':type==='error'?'bg-red-600 text-white':'bg-gray-800 text-white'
    }`;
    t.textContent = msg;
    c.appendChild(t);
    requestAnimationFrame(()=> t.classList.remove('translate-x-full'));
    setTimeout(()=>{t.classList.add('translate-x-full');setTimeout(()=>t.remove(),300);},2500);
}

function deepClone(obj) { return JSON.parse(JSON.stringify(obj)); }

function openModal(id) {
    const m=document.getElementById(id);
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function closeModal(id) {
    const m=document.getElementById(id);
    m.classList.add('hidden');
    m.classList.remove('flex');
}

// ========================================================================
// TAB MANAGEMENT
// ========================================================================
function switchTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b=>{
        b.classList.toggle('active', b.dataset.tab===tab);
    });
    document.getElementById('tab-properties').classList.toggle('hidden', tab!=='properties');
    document.getElementById('tab-json').classList.toggle('hidden', tab!=='json');
    if(tab==='json') syncJsonEditor();
}

function showTemplateTab(tab) {
    document.querySelectorAll('.tmpl-tab').forEach(b=>{
        b.classList.toggle('active', b.dataset.tmplTab===tab);
    });
    document.getElementById('tmpl-builtin').classList.toggle('hidden', tab!=='builtin');
    document.getElementById('tmpl-saved').classList.toggle('hidden', tab!=='saved');
    if(tab==='saved') loadSavedTemplates();
}

// ========================================================================
// HISTORY (UNDO / REDO)
// ========================================================================
function pushHistory() {
    historyStack = historyStack.slice(0, historyIndex+1);
    historyStack.push(flexData ? deepClone(flexData) : null);
    historyIndex = historyStack.length - 1;
    if(historyStack.length > 60){ historyStack.shift(); historyIndex--; }
    updateHistoryButtons();
}
function undoAction() {
    if(historyIndex > 0){ historyIndex--; flexData = historyStack[historyIndex] ? deepClone(historyStack[historyIndex]) : null; updateAll(); }
}
function redoAction() {
    if(historyIndex < historyStack.length-1){ historyIndex++; flexData = historyStack[historyIndex] ? deepClone(historyStack[historyIndex]) : null; updateAll(); }
}
function updateHistoryButtons() {
    document.getElementById('btn-undo').disabled = historyIndex <= 0;
    document.getElementById('btn-redo').disabled = historyIndex >= historyStack.length-1;
}

// ========================================================================
// DRAG & DROP
// ========================================================================
function handleDragStart(e) {
    const type = e.target.closest('.comp-item')?.dataset.type;
    if(type) { e.dataTransfer.setData('componentType', type); e.dataTransfer.effectAllowed='copy'; }
}
function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect='copy';
    e.currentTarget.classList.add('drop-active');
}
function handleDragLeave(e) {
    e.currentTarget.classList.remove('drop-active');
}
function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drop-active');
    const type = e.dataTransfer.getData('componentType');
    if(type) addComponent(type);
}
function handleSectionDrop(e, bubblePath, section) {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.remove('drop-active');
    const type = e.dataTransfer.getData('componentType');
    if(type) addComponentToSection(type, bubblePath, section);
}

// ========================================================================
// COMPONENT FACTORY
// ========================================================================
function createComponent(type) {
    switch(type) {
        case 'hero': return {type:"image",url:"https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png",size:"full",aspectRatio:"20:13",aspectMode:"cover"};
        case 'text': return {type:"text",text:"ข้อความ",size:"md",color:"#333333"};
        case 'button': return {type:"button",style:"primary",color:"#06C755",action:{type:"uri",label:"ปุ่มกด",uri:"https://example.com"}};
        case 'separator': return {type:"separator",margin:"md"};
        case 'spacer': return {type:"spacer",size:"md"};
        case 'box': return {type:"box",layout:"horizontal",contents:[]};
        case 'icon': return {type:"icon",url:"https://developers-resource.landpress.line.me/fx/img/review_gold_star_28.png",size:"sm"};
        case 'filler': return {type:"filler"};
        default: return {type:"text",text:type};
    }
}

// ========================================================================
// ADD COMPONENT LOGIC
// ========================================================================
function addComponent(type) {
    pushHistory();
    if(type==='bubble'||type==='carousel') {
        if(!flexData) {
            flexData = type==='bubble'
                ? {type:'bubble',body:{type:'box',layout:'vertical',contents:[]}}
                : {type:'carousel',contents:[{type:'bubble',body:{type:'box',layout:'vertical',contents:[]}}]};
        }
    } else if(type==='hero') {
        // Hero goes to bubble.hero
        const bubble = getTargetBubble();
        if(bubble) bubble.hero = createComponent('hero');
        else { flexData={type:'bubble',hero:createComponent('hero'),body:{type:'box',layout:'vertical',contents:[]}}; }
    } else if(flexData) {
        const comp = createComponent(type);
        const bubble = getTargetBubble();
        if(bubble) {
            if(!bubble.body) bubble.body={type:'box',layout:'vertical',contents:[]};
            bubble.body.contents.push(comp);
        }
    } else {
        flexData = {type:'bubble',body:{type:'box',layout:'vertical',contents:[createComponent(type)]}};
    }
    updateAll();
}

function addComponentToSection(type, bubblePath, section) {
    pushHistory();
    if(section==='_box') {
        // Dropping into a box element
        const box = getElementByPath(bubblePath);
        if(box && box.type==='box') {
            if(!box.contents) box.contents=[];
            box.contents.push(createComponent(type));
        }
        updateAll();
        return;
    }
    const bubble = getElementByPath(bubblePath);
    if(!bubble) return;
    if(section==='hero') {
        bubble.hero = createComponent('hero');
    } else if(section==='header'||section==='footer') {
        if(!bubble[section]) bubble[section]={type:'box',layout:'vertical',spacing:'sm',contents:[]};
        bubble[section].contents.push(createComponent(type));
    } else {
        if(!bubble.body) bubble.body={type:'box',layout:'vertical',contents:[]};
        bubble.body.contents.push(createComponent(type));
    }
    updateAll();
}

function getTargetBubble() {
    if(!flexData) return null;
    if(flexData.type==='bubble') return flexData;
    if(flexData.type==='carousel'&&flexData.contents?.length) return flexData.contents[flexData.contents.length-1];
    return null;
}

function addSection(bubblePath, section) {
    pushHistory();
    const bubble = getElementByPath(bubblePath);
    if(!bubble) return;
    if(section==='header') {
        bubble.header={type:'box',layout:'vertical',contents:[{type:'text',text:'Header',weight:'bold',size:'lg'}]};
    } else if(section==='hero') {
        bubble.hero={type:'image',url:'https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png',size:'full',aspectRatio:'20:13',aspectMode:'cover'};
    } else if(section==='footer') {
        bubble.footer={type:'box',layout:'vertical',spacing:'sm',contents:[{type:'button',style:'primary',color:'#06C755',action:{type:'uri',label:'Button',uri:'https://example.com'}}]};
    }
    updateAll();
}

function removeSection(bubblePath, section) {
    pushHistory();
    const bubble = getElementByPath(bubblePath);
    if(bubble) { delete bubble[section]; updateAll(); }
}

function addBubbleToCarousel() {
    if(flexData?.type==='carousel') {
        pushHistory();
        flexData.contents.push({type:'bubble',body:{type:'box',layout:'vertical',contents:[]}});
        updateAll();
    }
}

// ========================================================================
// PATH UTILITIES
// ========================================================================
function getElementByPath(path) {
    if(path==='root') return flexData;
    const parts = path.replace('root.','').split('.');
    let cur = flexData;
    for(const p of parts) {
        if(!cur) return null;
        const m = p.match(/^(\w+)\[(\d+)\]$/);
        cur = m ? cur[m[1]]?.[parseInt(m[2])] : cur[p];
    }
    return cur;
}

function getParentInfo(path) {
    if(path==='root') return null;
    const parts = path.replace('root.','').split('.');
    const last = parts[parts.length-1];
    const m = last.match(/^(\w+)\[(\d+)\]$/);
    if(!m) return null;
    const parentPath = parts.length>1 ? 'root.'+parts.slice(0,-1).join('.') : 'root';
    const parent = getElementByPath(parentPath);
    return parent ? {parent, key:m[1], index:parseInt(m[2]), parentPath} : null;
}

// ========================================================================
// COMPONENT OPERATIONS (move, duplicate, delete)
// ========================================================================
function moveComponent(path, dir) {
    const info = getParentInfo(path);
    if(!info) return;
    const arr = info.parent[info.key];
    if(!arr) return;
    const ni = info.index + dir;
    if(ni<0||ni>=arr.length) return;
    pushHistory();
    [arr[info.index], arr[ni]] = [arr[ni], arr[info.index]];
    // Update selected path
    const parts = path.replace('root.','').split('.');
    parts[parts.length-1] = `${info.key}[${ni}]`;
    selectedPath = 'root.'+parts.join('.');
    updateAll();
    selectElement(selectedPath);
}

function duplicateComponent(path) {
    const info = getParentInfo(path);
    if(!info) return;
    const arr = info.parent[info.key];
    if(!arr) return;
    pushHistory();
    arr.splice(info.index+1, 0, deepClone(arr[info.index]));
    updateAll();
    toast('Duplicated');
}

function deleteElement(path) {
    pushHistory();
    if(path==='root') {
        flexData=null;
    } else {
        const parts = path.replace('root.','').split('.');
        const last = parts[parts.length-1];
        const parentPath = parts.length>1 ? 'root.'+parts.slice(0,-1).join('.') : 'root';
        const parent = getElementByPath(parentPath);
        if(!parent) return;
        const m = last.match(/^(\w+)\[(\d+)\]$/);
        if(m && parent[m[1]]) parent[m[1]].splice(parseInt(m[2]),1);
        else delete parent[last];
    }
    selectedPath=null;
    document.getElementById('tab-properties').innerHTML='<p class="text-gray-400 text-xs text-center py-6">คลิกที่ Component บน Canvas เพื่อแก้ไข</p>';
    updateAll();
}

// ========================================================================
// MASTER UPDATE
// ========================================================================
function updateAll() {
    updateCanvas();
    updatePreview();
    if(activeTab==='json' && !jsonEditorFocused) syncJsonEditor();
    updateHistoryButtons();
}

// ========================================================================
// CANVAS RENDERING
// ========================================================================
function updateCanvas() {
    const empty = document.getElementById('canvas-empty');
    const content = document.getElementById('canvas-content');
    if(!flexData) {
        empty.classList.remove('hidden'); content.classList.add('hidden');
        return;
    }
    empty.classList.add('hidden'); content.classList.remove('hidden');
    content.innerHTML = renderCanvas(flexData,'root');
}

function renderCanvas(el, path) {
    if(!el) return '';
    const sel = selectedPath===path ? 'ring-2 ring-blue-500 ring-offset-1':'';
    const t = el.type;

    if(t==='carousel') {
        return `<div class="ce ce-carousel ${sel}" data-path="${path}">
            <div class="ce-label text-purple-600">🎠 Carousel</div>
            <div class="flex gap-3 overflow-x-auto pb-2 pt-1">
                ${(el.contents||[]).map((b,i)=>renderCanvas(b,path+'.contents['+i+']')).join('')}
                <button onclick="addBubbleToCarousel()" class="shrink-0 w-24 min-h-[120px] border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center text-gray-400 hover:border-purple-400 hover:text-purple-500 transition text-xs gap-1">
                    <span class="text-lg">+</span>Bubble
                </button>
            </div>
        </div>`;
    }

    if(t==='bubble') {
        let h = `<div class="ce ce-bubble ${sel}" data-path="${path}" onclick="selectElement('${path}')">
            <div class="ce-label text-blue-600">📦 Bubble</div>`;

        // Header
        if(el.header) {
            h+=`<div class="ce-section" data-path="${path}.header" ondragover="handleDragOver(event)" ondrop="handleSectionDrop(event,'${path}','header')" ondragleave="handleDragLeave(event)">
                <div class="ce-section-label">Header <button onclick="removeSection('${path}','header');event.stopPropagation();" class="ce-section-rm">✕</button></div>
                ${renderCanvasContents(el.header, path+'.header')}
            </div>`;
        } else {
            h+=`<button onclick="addSection('${path}','header');event.stopPropagation();" class="ce-add-section">+ Header</button>`;
        }
        // Hero
        if(el.hero) {
            h+=`<div class="ce-section ce-section-hero" onclick="selectElement('${path}.hero');event.stopPropagation();" data-path="${path}.hero">
                <div class="ce-section-label">Hero <button onclick="removeSection('${path}','hero');event.stopPropagation();" class="ce-section-rm">✕</button></div>
                ${el.hero.type==='image'?`<img src="${esc(el.hero.url)}" class="w-full h-16 object-cover rounded" onerror="this.src='https://placehold.co/300x100/eee/999?text=Image'">`:renderCanvasContents(el.hero,path+'.hero')}
            </div>`;
        } else {
            h+=`<button onclick="addSection('${path}','hero');event.stopPropagation();" class="ce-add-section">+ Hero Image</button>`;
        }
        // Body (main drop zone)
        h+=`<div class="ce-section ce-section-body" data-path="${path}.body"
                ondragover="handleDragOver(event)" ondrop="handleSectionDrop(event,'${path}','body')" ondragleave="handleDragLeave(event)">
            <div class="ce-section-label">Body</div>
            ${el.body ? renderCanvasContents(el.body, path+'.body') : '<div class="text-xs text-gray-400 text-center py-2">ลาก Component มาวางที่นี่</div>'}
        </div>`;
        // Footer
        if(el.footer) {
            h+=`<div class="ce-section" data-path="${path}.footer" ondragover="handleDragOver(event)" ondrop="handleSectionDrop(event,'${path}','footer')" ondragleave="handleDragLeave(event)">
                <div class="ce-section-label">Footer <button onclick="removeSection('${path}','footer');event.stopPropagation();" class="ce-section-rm">✕</button></div>
                ${renderCanvasContents(el.footer, path+'.footer')}
            </div>`;
        } else {
            h+=`<button onclick="addSection('${path}','footer');event.stopPropagation();" class="ce-add-section">+ Footer</button>`;
        }
        h+='</div>';
        return h;
    }

    // Inline elements (inside sections)
    return renderCanvasItem(el, path);
}

function renderCanvasContents(box, path) {
    if(!box || !box.contents) return '';
    return box.contents.map((c,i)=>renderCanvasItem(c, path+'.contents['+i+']')).join('');
}

function renderCanvasItem(el, path) {
    if(!el) return '';
    const sel = selectedPath===path ? 'ring-2 ring-blue-500':'';
    const t = el.type;
    const acts = `<div class="ce-actions">
        <button onclick="moveComponent('${path}',-1);event.stopPropagation();" title="Move up">▲</button>
        <button onclick="moveComponent('${path}',1);event.stopPropagation();" title="Move down">▼</button>
        <button onclick="duplicateComponent('${path}');event.stopPropagation();" title="Duplicate">⧉</button>
        <button onclick="deleteElement('${path}');event.stopPropagation();" title="Delete" class="text-red-500">✕</button>
    </div>`;

    if(t==='text') {
        return `<div class="ce-item ce-text ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<span class="text-xs truncate">${esc(el.text||'Text')}</span>
        </div>`;
    }
    if(t==='button') {
        return `<div class="ce-item ce-btn ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<span class="text-xs font-medium truncate" style="color:${el.color||'#06C755'}">${esc(el.action?.label||'Button')}</span>
        </div>`;
    }
    if(t==='image') {
        return `<div class="ce-item ce-img ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<img src="${esc(el.url)}" class="w-full h-12 object-cover rounded" onerror="this.src='https://placehold.co/300x60/eee/999?text=Image'">
        </div>`;
    }
    if(t==='separator') {
        return `<div class="ce-item ce-sep ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<hr class="border-gray-300 w-full">
        </div>`;
    }
    if(t==='spacer') {
        return `<div class="ce-item ce-spacer ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<div class="text-[10px] text-gray-400 text-center w-full">↕ Spacer (${el.size||'md'})</div>
        </div>`;
    }
    if(t==='box') {
        const dir = el.layout==='horizontal'?'flex-row':'flex-col';
        return `<div class="ce-item ce-box ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();"
                    ondragover="handleDragOver(event)" ondrop="handleSectionDrop(event,'${path}','_box')" ondragleave="handleDragLeave(event)">
            ${acts}<div class="text-[10px] text-indigo-500 mb-0.5">▦ Box (${el.layout||'vertical'})</div>
            <div class="flex ${dir} gap-0.5 w-full">
                ${(el.contents||[]).map((c,i)=>renderCanvasItem(c,path+'.contents['+i+']')).join('')}
            </div>
        </div>`;
    }
    if(t==='filler') {
        return `<div class="ce-item ce-spacer ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<div class="text-[10px] text-gray-400 text-center w-full">⇔ Filler</div>
        </div>`;
    }
    if(t==='icon') {
        return `<div class="ce-item ce-icon ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">
            ${acts}<img src="${esc(el.url)}" class="w-4 h-4"> <span class="text-[10px] text-gray-400">Icon</span>
        </div>`;
    }
    return `<div class="ce-item ${sel}" data-path="${path}" onclick="selectElement('${path}');event.stopPropagation();">${acts}<span class="text-xs text-gray-400">${t}</span></div>`;
}

// ========================================================================
// PREVIEW RENDERING (uses FlexPreview from flex-preview.js)
// ========================================================================
function updatePreview() {
    const container = document.getElementById('flex-preview');
    if(!flexData) {
        container.innerHTML = '<div class="text-center text-white/50 py-8"><div class="text-3xl mb-1">💬</div><p class="text-xs">Preview จะแสดงที่นี่</p></div>';
        return;
    }
    if(typeof FlexPreview !== 'undefined') {
        FlexPreview.render('flex-preview', flexData);
    } else {
        // Fallback simple preview
        container.innerHTML = renderSimplePreview(flexData);
    }
}

function renderSimplePreview(el) {
    if(!el) return '';
    if(el.type==='bubble') {
        return `<div class="bg-white rounded-2xl overflow-hidden shadow-lg">
            ${el.hero ? (el.hero.type==='image' ? `<img src="${esc(el.hero.url)}" class="w-full" style="aspect-ratio:${(el.hero.aspectRatio||'20:13').replace(':','/')};object-fit:cover;">` : renderSimplePreview(el.hero)) : ''}
            ${el.header ? `<div class="p-3 border-b">${renderSimplePreview(el.header)}</div>` : ''}
            ${el.body ? `<div class="p-4">${renderSimplePreview(el.body)}</div>` : ''}
            ${el.footer ? `<div class="px-4 pb-4">${renderSimplePreview(el.footer)}</div>` : ''}
        </div>`;
    }
    if(el.type==='carousel') {
        return `<div class="flex gap-2 overflow-x-auto snap-x">${(el.contents||[]).map(b=>`<div class="min-w-[260px] snap-center">${renderSimplePreview(b)}</div>`).join('')}</div>`;
    }
    if(el.type==='box') {
        const dir=el.layout==='horizontal'?'flex-row items-center':'flex-col';
        const sp=el.spacing==='sm'?'gap-1':el.spacing==='lg'?'gap-3':'gap-2';
        return `<div class="flex ${dir} ${sp}">${(el.contents||[]).map(c=>renderSimplePreview(c)).join('')}</div>`;
    }
    if(el.type==='text') {
        const sz={'xxs':'text-[10px]','xs':'text-xs','sm':'text-sm','md':'text-base','lg':'text-lg','xl':'text-xl','xxl':'text-2xl','3xl':'text-3xl'}[el.size]||'text-base';
        const w=el.weight==='bold'?'font-bold':'';
        const a={'center':'text-center','end':'text-right'}[el.align]||'';
        return `<div class="${sz} ${w} ${a}" style="color:${el.color||'#333'}">${esc(el.text||'')}</div>`;
    }
    if(el.type==='button') {
        const bg=el.style==='primary'?(el.color||'#06C755'):'transparent';
        const tc=el.style==='primary'?'#fff':(el.color||'#06C755');
        return `<button class="w-full py-2 px-4 rounded-lg text-sm font-medium" style="background:${bg};color:${tc}">${esc(el.action?.label||'Button')}</button>`;
    }
    if(el.type==='separator') return '<hr class="border-gray-200 my-2">';
    if(el.type==='spacer') return `<div style="height:${{'xs':'4px','sm':'8px','md':'16px','lg':'24px','xl':'32px'}[el.size]||'16px'}"></div>`;
    if(el.type==='filler') return '<div style="flex:1"></div>';
    if(el.type==='icon') return `<img src="${esc(el.url)}" class="w-4 h-4 inline">`;
    return '';
}

// ========================================================================
// SELECTION & PROPERTIES PANEL
// ========================================================================
function selectElement(path) {
    selectedPath = path;
    // Highlight in canvas
    document.querySelectorAll('.ce-item,.ce-bubble,.ce-carousel,.ce-section-hero').forEach(el=>{
        el.classList.remove('ring-2','ring-blue-500','ring-offset-1');
    });
    const el = document.querySelector(`[data-path="${path}"]`);
    if(el) el.classList.add('ring-2','ring-blue-500','ring-offset-1');
    if(activeTab!=='properties') switchTab('properties');
    showProperties(path);
}

function showProperties(path) {
    const el = getElementByPath(path);
    if(!el) return;
    const panel = document.getElementById('tab-properties');
    let h = '<div class="space-y-2.5">';

    // Header
    h+=`<div class="flex items-center justify-between">
        <span class="text-xs font-bold text-gray-700 uppercase">${el.type}</span>
        <div class="flex gap-1">`;
    const info = getParentInfo(path);
    if(info) {
        h+=`<button onclick="moveComponent('${path}',-1)" class="p-btn" title="Move up">▲</button>
            <button onclick="moveComponent('${path}',1)" class="p-btn" title="Move down">▼</button>
            <button onclick="duplicateComponent('${path}')" class="p-btn" title="Duplicate">⧉</button>`;
    }
    h+=`<button onclick="deleteElement('${path}')" class="p-btn text-red-500" title="Delete">✕</button>
        </div></div>`;

    // Type-specific properties
    if(el.type==='text') {
        h+=propInput('ข้อความ', path, 'text', el.text||'');
        h+=propRow(
            propSelect('ขนาด', path, 'size', ['xxs','xs','sm','md','lg','xl','xxl','3xl'], el.size||'md'),
            propColor('สี', path, 'color', el.color||'#333333')
        );
        h+=propRow(
            propSelect('น้ำหนัก', path, 'weight', [['','ปกติ'],['bold','Bold']], el.weight||''),
            propSelect('จัดตำแหน่ง', path, 'align', [['','ซ้าย'],['center','กลาง'],['end','ขวา']], el.align||'')
        );
        h+=propCheckbox('ตัดบรรทัดอัตโนมัติ', path, 'wrap', el.wrap||false);
        h+=propSelect('Decoration', path, 'decoration', [['','None'],['line-through','Strikethrough'],['underline','Underline']], el.decoration||'');
    }
    else if(el.type==='image') {
        h+=propInput('URL รูปภาพ', path, 'url', el.url||'', 'url');
        h+=propRow(
            propSelect('อัตราส่วน', path, 'aspectRatio', ['1:1','4:3','16:9','20:13','2:1','3:1'], el.aspectRatio||'20:13'),
            propSelect('Fit Mode', path, 'aspectMode', ['cover','fit'], el.aspectMode||'cover')
        );
        h+=propSelect('Size', path, 'size', ['xxs','xs','sm','md','lg','xl','xxl','full'], el.size||'full');
    }
    else if(el.type==='button') {
        h+=propInput('ข้อความบนปุ่ม', path, '_action.label', el.action?.label||'');
        h+=propRow(
            propSelect('สไตล์', path, 'style', [['primary','Primary'],['secondary','Secondary'],['link','Link']], el.style||'primary'),
            propColor('สี', path, 'color', el.color||'#06C755')
        );
        h+=propRow(
            propSelect('Action', path, '_action.type', [['uri','เปิด URL'],['message','ส่งข้อความ'],['postback','Postback']], el.action?.type||'uri'),
            propSelect('ความสูง', path, 'height', [['','ปกติ'],['sm','เล็ก']], el.height||'')
        );
        const actionField = el.action?.type==='uri'?'uri':el.action?.type==='message'?'text':'data';
        h+=propInput(el.action?.type==='uri'?'URL':'ข้อความ/Data', path, '_action.'+actionField, el.action?.[actionField]||'');
    }
    else if(el.type==='box') {
        h+=propSelect('Layout', path, 'layout', [['vertical','Vertical'],['horizontal','Horizontal'],['baseline','Baseline']], el.layout||'vertical');
        h+=propRow(
            propSelect('Spacing', path, 'spacing', [['','None'],['xs','XS'],['sm','SM'],['md','MD'],['lg','LG'],['xl','XL']], el.spacing||''),
            propSelect('Padding', path, 'paddingAll', [['','None'],['xs','XS'],['sm','SM'],['md','MD'],['lg','LG'],['xl','XL']], el.paddingAll||'')
        );
        h+=propColor('Background', path, 'backgroundColor', el.backgroundColor||'#ffffff');
        h+=propRow(
            propSelect('Corner', path, 'cornerRadius', [['','Default'],['none','None'],['xs','XS'],['sm','SM'],['md','MD'],['lg','LG'],['xl','XL'],['xxl','XXL']], el.cornerRadius||''),
            propSelect('Border', path, 'borderWidth', [['','None'],['light','Light'],['normal','Normal'],['medium','Medium'],['semi-bold','Semi-bold'],['bold','Bold']], el.borderWidth||'')
        );
        if(el.borderWidth) h+=propColor('Border Color', path, 'borderColor', el.borderColor||'#000000');
    }
    else if(el.type==='separator') {
        h+=propColor('สี', path, 'color', el.color||'#E0E0E0');
    }
    else if(el.type==='spacer') {
        h+=propSelect('Size', path, 'size', ['xs','sm','md','lg','xl','xxl'], el.size||'md');
    }
    else if(el.type==='icon') {
        h+=propInput('URL ไอคอน', path, 'url', el.url||'', 'url');
        h+=propSelect('Size', path, 'size', ['xxs','xs','sm','md','lg','xl'], el.size||'md');
    }
    else if(el.type==='bubble') {
        h+=propSelect('Size', path, 'size', [['','mega'],['nano','Nano'],['micro','Micro'],['kilo','Kilo'],['mega','Mega'],['giga','Giga']], el.size||'');
    }

    // Common: margin, flex
    if(el.type!=='bubble'&&el.type!=='carousel') {
        h+=`<div class="border-t pt-2 mt-2"><div class="text-[10px] font-bold text-gray-400 uppercase mb-1.5">Layout</div>`;
        h+=propRow(
            propSelect('Margin', path, 'margin', [['','None'],['xs','XS'],['sm','SM'],['md','MD'],['lg','LG'],['xl','XL']], el.margin||''),
            propInput('Flex', path, 'flex', el.flex!==undefined?el.flex:'', 'number')
        );
        h+=`</div>`;
    }

    h+='</div>';
    panel.innerHTML = h;
}

// Property helpers
function propInput(label, path, prop, val, type='text') {
    return `<div><label class="prop-label">${label}</label>
        <input type="${type}" value="${esc(''+val)}" onchange="updateProp('${path}','${prop}',this.value)" class="prop-input" ${type==='number'?'min="-1" max="10" step="1"':''}></div>`;
}
function propSelect(label, path, prop, options, val) {
    const opts = options.map(o=>{
        const [v,l] = Array.isArray(o)?o:[o,o];
        return `<option value="${v}" ${val===v?'selected':''}>${l}</option>`;
    }).join('');
    return `<div><label class="prop-label">${label}</label><select onchange="updateProp('${path}','${prop}',this.value)" class="prop-input">${opts}</select></div>`;
}
function propColor(label, path, prop, val) {
    return `<div><label class="prop-label">${label}</label><div class="flex gap-1">
        <input type="color" value="${val}" onchange="updateProp('${path}','${prop}',this.value)" class="w-8 h-8 border rounded cursor-pointer">
        <input type="text" value="${esc(val)}" onchange="updateProp('${path}','${prop}',this.value)" class="prop-input flex-1" maxlength="7">
    </div></div>`;
}
function propCheckbox(label, path, prop, val) {
    return `<label class="flex items-center gap-2 text-xs cursor-pointer"><input type="checkbox" ${val?'checked':''} onchange="updateProp('${path}','${prop}',this.checked)" class="rounded"> ${label}</label>`;
}
function propRow(a, b) { return `<div class="grid grid-cols-2 gap-2">${a}${b}</div>`; }

function updateProp(path, prop, value) {
    pushHistory();
    const el = getElementByPath(path);
    if(!el) return;

    // Handle nested action properties
    if(prop.startsWith('_action.')) {
        const actionProp = prop.replace('_action.','');
        if(!el.action) el.action = {type:'uri',label:'Button',uri:'https://example.com'};
        if(actionProp==='type') {
            const label = el.action.label||'Button';
            el.action = {type:value, label};
            if(value==='uri') el.action.uri='https://example.com';
            else if(value==='message') el.action.text='message';
            else if(value==='postback') el.action.data='action=click';
            updateAll();
            showProperties(path);
            return;
        }
        el.action[actionProp] = value;
    } else {
        // Clean empty/false values
        if(value===''||value===false||value==='false') {
            delete el[prop];
        } else if(prop==='flex') {
            el[prop] = value===''?undefined:parseInt(value);
            if(isNaN(el[prop])) delete el[prop];
        } else {
            el[prop] = value===true||value==='true'?true:value;
        }
    }
    updateAll();
}

// ========================================================================
// JSON EDITOR
// ========================================================================
function syncJsonEditor() {
    const ta = document.getElementById('json-editor');
    if(!ta) return;
    ta.value = flexData ? JSON.stringify(flexData, null, 2) : '';
    validateJsonInput(ta.value);
}

let jsonAutoSyncTimer = null;
function onJsonEditorInput() {
    const ta = document.getElementById('json-editor');
    validateJsonInput(ta.value);
    // Auto-sync: parse and apply valid JSON after debounce
    clearTimeout(jsonAutoSyncTimer);
    jsonAutoSyncTimer = setTimeout(()=>{
        const text = ta.value.trim();
        if(!text) return;
        try {
            const parsed = JSON.parse(text);
            let newData = null;
            if(parsed.type==='bubble'||parsed.type==='carousel') newData=parsed;
            else if(parsed.type==='flex'&&parsed.contents) newData=parsed.contents;
            else if(parsed.type==='box') newData={type:'bubble',body:parsed};
            if(newData) {
                pushHistory();
                flexData = newData;
                updateCanvas();
                updatePreview();
                updateHistoryButtons();
            }
        } catch(e) { /* invalid JSON, wait for user to fix */ }
    }, 800);
}

function validateJsonInput(text) {
    const status = document.getElementById('json-status');
    if(!text.trim()) { status.classList.add('hidden'); return; }
    try {
        JSON.parse(text);
        status.textContent = '✓ Valid JSON';
        status.className = 'text-[10px] font-medium text-green-600';
        status.classList.remove('hidden');
    } catch(e) {
        status.textContent = '✗ ' + e.message.substring(0,40);
        status.className = 'text-[10px] font-medium text-red-500';
        status.classList.remove('hidden');
    }
}

function applyJson() {
    const ta = document.getElementById('json-editor');
    const text = ta.value.trim();
    if(!text) { toast('JSON ว่างเปล่า','error'); return; }
    try {
        const parsed = JSON.parse(text);
        if(!parsed.type || !['bubble','carousel'].includes(parsed.type)) {
            // Try wrapping in bubble if it looks like body content
            if(parsed.type==='box') {
                pushHistory();
                flexData = {type:'bubble',body:parsed};
            } else {
                toast('JSON ต้องเป็น bubble หรือ carousel','error');
                return;
            }
        } else {
            pushHistory();
            flexData = parsed;
        }
        updateCanvas();
        updatePreview();
        updateHistoryButtons();
        toast('โหลด JSON สำเร็จ');
    } catch(e) {
        toast('JSON ไม่ถูกต้อง: '+e.message, 'error');
    }
}

function formatJson() {
    const ta = document.getElementById('json-editor');
    try {
        const obj = JSON.parse(ta.value);
        ta.value = JSON.stringify(obj, null, 2);
        toast('Formatted');
    } catch(e) { toast('JSON ไม่ถูกต้อง','error'); }
}

function minifyJson() {
    const ta = document.getElementById('json-editor');
    try {
        const obj = JSON.parse(ta.value);
        ta.value = JSON.stringify(obj);
        toast('Minified');
    } catch(e) { toast('JSON ไม่ถูกต้อง','error'); }
}

function copyJsonEditor() {
    const ta = document.getElementById('json-editor');
    navigator.clipboard.writeText(ta.value).then(()=>toast('Copied!')).catch(()=>{ta.select();document.execCommand('copy');toast('Copied!');});
}

// ========================================================================
// IMPORT JSON MODAL
// ========================================================================
function openImportModal() {
    document.getElementById('import-json-input').value = '';
    document.getElementById('import-error').classList.add('hidden');
    openModal('modal-import');
    setTimeout(()=>document.getElementById('import-json-input').focus(),100);
}

function doImportJson() {
    const text = document.getElementById('import-json-input').value.trim();
    const errEl = document.getElementById('import-error');
    if(!text) { errEl.textContent='กรุณาวาง JSON'; errEl.classList.remove('hidden'); return; }
    try {
        const parsed = JSON.parse(text);
        pushHistory();
        if(parsed.type==='flex') {
            // Handle full LINE message format
            flexData = parsed.contents;
        } else {
            flexData = parsed;
        }
        updateAll();
        closeModal('modal-import');
        toast('Import สำเร็จ!');
    } catch(e) {
        errEl.textContent = 'JSON ไม่ถูกต้อง: ' + e.message;
        errEl.classList.remove('hidden');
    }
}

// ========================================================================
// FULL JSON VIEW
// ========================================================================
function getAltText() {
    return document.getElementById('alt-text-input')?.value?.trim() || 'Flex Message';
}
function getFullFlexJson() {
    // Returns the full LINE Flex Message format with altText
    return { type:'flex', altText:getAltText(), contents:flexData };
}
function showFullJson() {
    if(!flexData) { toast('ยังไม่มีข้อมูล','error'); return; }
    // Show two formats: raw contents and full LINE format
    const full = getFullFlexJson();
    document.getElementById('full-json-output').value = JSON.stringify(full, null, 2);
    openModal('modal-json-view');
}
function copyFullJson() {
    const ta = document.getElementById('full-json-output');
    navigator.clipboard.writeText(ta.value).then(()=>toast('Copied!')).catch(()=>{ta.select();document.execCommand('copy');toast('Copied!');});
}

// ========================================================================
// TEMPLATES
// ========================================================================
function showTemplatesModal() {
    showTemplateTab('builtin');
    openModal('modal-templates');
}

function useBuiltinTemplate(name) {
    if(builtinTemplates[name]) {
        pushHistory();
        flexData = deepClone(builtinTemplates[name]);
        updateAll();
        closeModal('modal-templates');
        toast('โหลดเทมเพลตแล้ว');
    }
}

// ===== Real product picker → load a product Flex (deterministic) into the builder =====
function openBuilderProductPicker() {
    const w = 980, h = 740;
    const left = Math.max(0,(screen.width-w)/2), top = Math.max(0,(screen.height-h)/2);
    const popup = window.open('product-picker.php?return=builder','reyaProductPicker',
        `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
    if(!popup) toast('เบราว์เซอร์บล็อก popup — โปรดอนุญาตแล้วลองใหม่');
}

function loadProductsIntoBuilder(products) {
    if(!Array.isArray(products) || !products.length) return;
    if(typeof FlexProductBuilder === 'undefined') { toast('ตัวสร้าง Flex ยังไม่พร้อม'); return; }
    const flex = FlexProductBuilder.build({ products, theme:'product_catalog', layout:'2up' });
    if(!flex) { toast('สร้าง Flex ไม่สำเร็จ'); return; }
    pushHistory();
    flexData = deepClone(flex);
    const alt = document.getElementById('alt-text-input');
    if(alt) alt.value = FlexProductBuilder.altText({});
    updateAll();
    toast('นำเข้าสินค้า ' + products.length + ' ชิ้นแล้ว แก้ไขต่อได้เลย');
}

window.addEventListener('message', function(e){
    if(e.origin !== location.origin) return;
    if(!e.data || e.data.type !== 'reya:products') return;
    if(e.data.returnTo && e.data.returnTo !== 'builder') return;
    loadProductsIntoBuilder(e.data.products || []);
});

// Fallback: products handed off via sessionStorage when popup was blocked.
(function(){
    try {
        const raw = sessionStorage.getItem('reya_picked_products');
        if(!raw) return;
        sessionStorage.removeItem('reya_picked_products');
        const products = JSON.parse(raw);
        if(Array.isArray(products) && products.length) loadProductsIntoBuilder(products);
    } catch(e){}
})();

function loadSavedTemplates() {
    const list = document.getElementById('saved-templates-list');
    list.innerHTML = '<div class="text-center py-6 text-gray-400 col-span-full text-sm">กำลังโหลด...</div>';
    fetch('flex-builder.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:'action=get_templates'
    }).then(r=>r.json()).then(data=>{
        if(!data.success||!data.templates.length) {
            list.innerHTML='<div class="text-center py-8 text-gray-400 col-span-full"><p class="text-sm">ยังไม่มีเทมเพลตที่บันทึก</p><p class="text-xs mt-1">สร้าง Flex แล้วกด "บันทึกเทมเพลต"</p></div>';
            return;
        }
        list.innerHTML = data.templates.map(t=>`
            <div class="tmpl-card group relative">
                <div onclick="useSavedTemplate(${t.id}, '${esc(t.flex_json)}')" class="cursor-pointer">
                    <div class="tmpl-icon bg-gradient-to-br from-gray-100 to-gray-200">📄</div>
                    <h4 class="truncate">${esc(t.name)}</h4>
                    <p>${esc(t.category)} · ${new Date(t.created_at).toLocaleDateString('th-TH')}</p>
                </div>
                <button onclick="deleteSavedTemplate(${t.id}, this)" class="absolute top-1 right-1 w-6 h-6 bg-red-100 text-red-500 rounded-full text-xs hidden group-hover:flex items-center justify-center hover:bg-red-200" title="ลบ">✕</button>
            </div>
        `).join('');
    }).catch(()=>{
        list.innerHTML='<div class="text-center py-6 text-red-400 col-span-full text-sm">โหลดไม่สำเร็จ</div>';
    });
}

function useSavedTemplate(id, jsonStr) {
    try {
        pushHistory();
        flexData = JSON.parse(jsonStr);
        updateAll();
        closeModal('modal-templates');
        toast('โหลดเทมเพลตแล้ว');
    } catch(e) { toast('ข้อมูลเทมเพลตผิดพลาด','error'); }
}

function deleteSavedTemplate(id, btn) {
    if(!confirm('ลบเทมเพลตนี้?')) return;
    fetch('flex-builder.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:'action=delete_template&id='+id
    }).then(r=>r.json()).then(data=>{
        if(data.success) { toast('ลบแล้ว'); loadSavedTemplates(); }
        else toast('ลบไม่สำเร็จ','error');
    });
}

// ========================================================================
// SAVE TEMPLATE
// ========================================================================
function saveTemplate() {
    if(!flexData) { toast('ยังไม่มีข้อมูล','error'); return; }
    document.getElementById('save-template-name').value = '';
    openModal('modal-save');
    setTimeout(()=>document.getElementById('save-template-name').focus(),100);
}

function doSaveTemplate() {
    const name = document.getElementById('save-template-name').value.trim();
    const category = document.getElementById('save-template-category').value;
    if(!name) { toast('กรุณาตั้งชื่อ','error'); return; }
    fetch('flex-builder.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:`action=save_template&name=${encodeURIComponent(name)}&category=${encodeURIComponent(category)}&slot_key=${encodeURIComponent(FLEX_SLOT||'')}&flex_json=${encodeURIComponent(JSON.stringify(flexData))}`
    }).then(r=>r.json()).then(data=>{
        if(data.success) { toast(FLEX_SLOT ? 'บันทึก + ผูกกับ slot แล้ว!' : 'บันทึกเรียบร้อย!'); closeModal('modal-save'); }
        else toast('เกิดข้อผิดพลาด','error');
    }).catch(()=>toast('เกิดข้อผิดพลาด','error'));
}

// ========================================================================
// BROADCAST
// ========================================================================
function useToBroadcast() {
    if(!flexData) { toast('ยังไม่มีข้อมูล','error'); return; }
    sessionStorage.setItem('flex_broadcast', JSON.stringify(flexData));
    sessionStorage.setItem('flex_broadcast_alttext', getAltText());
    window.location.href = 'broadcast.php?flex=1';
}

// ========================================================================
// CLEAR CANVAS
// ========================================================================
function clearCanvas() {
    if(!flexData) return;
    if(!confirm('ล้างทั้งหมด?')) return;
    pushHistory();
    flexData=null;
    selectedPath=null;
    document.getElementById('tab-properties').innerHTML='<p class="text-gray-400 text-xs text-center py-6">คลิกที่ Component บน Canvas เพื่อแก้ไข</p>';
    updateAll();
}

// ========================================================================
// KEYBOARD SHORTCUTS
// ========================================================================
document.addEventListener('keydown', function(e) {
    // Don't intercept when typing in inputs
    if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.tagName==='SELECT') {
        // Only handle Escape in inputs
        if(e.key==='Escape') e.target.blur();
        return;
    }
    if(e.ctrlKey && e.key==='z' && !e.shiftKey) { e.preventDefault(); undoAction(); }
    else if(e.ctrlKey && (e.key==='y'||(e.shiftKey && e.key==='Z'))) { e.preventDefault(); redoAction(); }
    else if(e.ctrlKey && e.key==='s') { e.preventDefault(); saveTemplate(); }
    else if(e.key==='Delete'||e.key==='Backspace') {
        if(selectedPath) { e.preventDefault(); deleteElement(selectedPath); }
    }
    else if(e.ctrlKey && e.key==='d') {
        if(selectedPath) { e.preventDefault(); duplicateComponent(selectedPath); }
    }
    else if(e.key==='Escape') {
        selectedPath=null;
        document.querySelectorAll('.ce-item,.ce-bubble').forEach(el=>el.classList.remove('ring-2','ring-blue-500','ring-offset-1'));
        document.getElementById('tab-properties').innerHTML='<p class="text-gray-400 text-xs text-center py-6">คลิกที่ Component บน Canvas เพื่อแก้ไข</p>';
    }
});

// ========================================================================
// INITIALIZE
// ========================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Setup drag events
    document.querySelectorAll('.comp-item').forEach(item=>{
        item.addEventListener('dragstart', handleDragStart);
    });

    // JSON editor focus tracking
    const jsonEd = document.getElementById('json-editor');
    if(jsonEd) {
        jsonEd.addEventListener('focus', ()=>jsonEditorFocused=true);
        jsonEd.addEventListener('blur', ()=>jsonEditorFocused=false);
    }

    // Check for imported flex from other pages
    const params = new URLSearchParams(window.location.search);
    if(params.get('edit')) {
        const stored = sessionStorage.getItem('flex_edit');
        if(stored) { try { flexData=JSON.parse(stored); updateAll(); } catch(e){} }
    }

    // Handle drop on section for box type
    // (box drops are handled via handleSectionDrop with '_box' section)
});
</script>

<style>
/* Component Items */
.comp-item { user-select:none; }
.ci { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:8px; border:1.5px dashed transparent; cursor:grab; transition:all .15s; }
.ci:hover { border-color:currentColor; }
.ci span:first-child { font-size:16px; width:24px; text-align:center; flex-shrink:0; }
.ci b { display:block; font-size:12px; font-weight:600; }
.ci small { display:block; font-size:10px; color:#888; }
.ci-blue { background:#eff6ff; color:#3b82f6; } .ci-blue:hover { border-color:#93c5fd; }
.ci-purple { background:#f5f3ff; color:#8b5cf6; } .ci-purple:hover { border-color:#c4b5fd; }
.ci-amber { background:#fffbeb; color:#d97706; } .ci-amber:hover { border-color:#fcd34d; }
.ci-green { background:#f0fdf4; color:#16a34a; } .ci-green:hover { border-color:#86efac; }
.ci-rose { background:#fff1f2; color:#e11d48; } .ci-rose:hover { border-color:#fda4af; }
.ci-gray { background:#f9fafb; color:#6b7280; } .ci-gray:hover { border-color:#d1d5db; }
.ci-indigo { background:#eef2ff; color:#6366f1; } .ci-indigo:hover { border-color:#a5b4fc; }
.ci-yellow { background:#fefce8; color:#ca8a04; } .ci-yellow:hover { border-color:#fde047; }

/* Toolbar buttons */
.tb-btn { padding:4px 8px; border-radius:6px; color:#6b7280; transition:all .15s; }
.tb-btn:hover:not(:disabled) { background:#f3f4f6; color:#374151; }
.tb-btn:disabled { opacity:.35; cursor:not-allowed; }

/* Canvas */
.canvas-drop-zone.drop-active { border-color:#4ade80!important; background:#f0fdf4!important; }
.ce-section.drop-active { background:#dbeafe!important; }

/* Canvas elements */
.ce { padding:8px; margin-bottom:4px; }
.ce-carousel { background:#faf5ff; border:2px solid #e9d5ff; border-radius:10px; padding:8px; }
.ce-bubble { background:#fff; border:2px solid #bfdbfe; border-radius:10px; padding:8px; }
.ce-label { font-size:11px; font-weight:700; margin-bottom:6px; }
.ce-section { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:6px; margin-bottom:4px; position:relative; }
.ce-section-hero { background:#fff7ed; border-color:#fed7aa; }
.ce-section-body { background:#f0f9ff; border-color:#bae6fd; min-height:40px; border-style:dashed; }
.ce-section-label { font-size:10px; font-weight:600; color:#9ca3af; margin-bottom:4px; display:flex; justify-content:space-between; align-items:center; }
.ce-section-rm { background:none; border:none; color:#d1d5db; cursor:pointer; font-size:12px; padding:0 2px; line-height:1; }
.ce-section-rm:hover { color:#ef4444; }
.ce-add-section { display:block; width:100%; padding:4px; margin-bottom:4px; border:1.5px dashed #d1d5db; border-radius:6px; text-align:center; font-size:11px; color:#9ca3af; cursor:pointer; background:transparent; transition:all .15s; }
.ce-add-section:hover { border-color:#93c5fd; color:#3b82f6; background:#eff6ff; }

/* Canvas items */
.ce-item { position:relative; padding:4px 6px; border-radius:5px; cursor:pointer; transition:all .15s; border:1px solid transparent; margin-bottom:2px; display:flex; align-items:center; gap:4px; }
.ce-item:hover { border-color:#93c5fd; }
.ce-text { background:#f0fdf4; }
.ce-btn { background:#fff1f2; }
.ce-img { background:#fffbeb; padding:2px; }
.ce-sep { background:#f9fafb; padding:6px 4px; }
.ce-spacer { background:#f9fafb; justify-content:center; }
.ce-box { background:#eef2ff; flex-direction:column; align-items:stretch; }
.ce-icon { background:#fefce8; }

/* Element actions (show on hover) */
.ce-actions { display:none; position:absolute; top:1px; right:1px; z-index:10; background:white; border-radius:4px; box-shadow:0 1px 4px rgba(0,0,0,.15); padding:1px; gap:0; }
.ce-item:hover > .ce-actions { display:flex; }
.ce-actions button { width:18px; height:18px; display:flex; align-items:center; justify-content:center; border:none; background:none; cursor:pointer; font-size:10px; color:#6b7280; border-radius:3px; }
.ce-actions button:hover { background:#f3f4f6; color:#111; }
.ce-actions button.text-red-500:hover { background:#fef2f2; color:#ef4444; }

/* Tabs */
.tab-btn { flex:1; padding:8px 12px; font-size:12px; font-weight:600; color:#9ca3af; border-bottom:2px solid transparent; transition:all .15s; background:none; cursor:pointer; }
.tab-btn.active { color:#3b82f6; border-bottom-color:#3b82f6; }
.tab-btn:hover:not(.active) { color:#6b7280; }

/* JSON toolbar */
.jt-btn { padding:2px 8px; background:white; border:1px solid #e5e7eb; border-radius:4px; font-size:10px; color:#6b7280; cursor:pointer; transition:all .1s; }
.jt-btn:hover { background:#f9fafb; color:#374151; }

/* Properties */
.prop-label { display:block; font-size:10px; color:#9ca3af; margin-bottom:2px; font-weight:500; }
.prop-input { width:100%; padding:4px 8px; border:1px solid #e5e7eb; border-radius:5px; font-size:12px; outline:none; transition:border .15s; }
.prop-input:focus { border-color:#93c5fd; box-shadow:0 0 0 2px rgba(59,130,246,.1); }
.p-btn { width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #e5e7eb; border-radius:4px; background:white; cursor:pointer; font-size:10px; color:#6b7280; }
.p-btn:hover { background:#f3f4f6; color:#111; }

/* Action bar buttons */
.act-btn { padding:6px 14px; background:#f3f4f6; border-radius:8px; font-size:13px; color:#374151; cursor:pointer; transition:all .15s; border:none; }
.act-btn:hover { background:#e5e7eb; }
.act-btn-primary { padding:6px 14px; background:#3b82f6; color:white; border-radius:8px; font-size:13px; cursor:pointer; transition:all .15s; border:none; font-weight:600; }
.act-btn-primary:hover { background:#2563eb; }

/* Templates */
.tmpl-tab { padding:6px 16px; border-radius:8px; font-size:12px; font-weight:600; background:#f3f4f6; color:#6b7280; border:none; cursor:pointer; transition:all .15s; }
.tmpl-tab.active { background:#3b82f6; color:white; }
.tmpl-card { cursor:pointer; border-radius:12px; border:2px solid transparent; padding:12px; transition:all .15s; }
.tmpl-card:hover { border-color:#93c5fd; background:#f0f9ff; }
.tmpl-card h4 { font-size:13px; font-weight:600; margin-top:8px; }
.tmpl-card p { font-size:11px; color:#9ca3af; }
.tmpl-icon { width:100%; aspect-ratio:1; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:32px; }

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:50; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-box { background:white; border-radius:16px; width:100%; margin:16px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-close { width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; background:none; font-size:24px; color:#9ca3af; cursor:pointer; transition:all .15s; }
.modal-close:hover { background:#f3f4f6; color:#374151; }
</style>

<?php require_once 'includes/footer.php'; ?>
