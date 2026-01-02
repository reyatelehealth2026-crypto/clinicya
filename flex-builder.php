<?php
/**
 * Flex Message Builder V2 - Drag & Drop with Live Preview
 * สร้าง Flex Message สำหรับ Broadcast แบบลากวาง
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Flex Builder';

// Handle save template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'save_template') {
        $name = $_POST['name'] ?? 'Untitled';
        $flexJson = $_POST['flex_json'] ?? '{}';
        $category = $_POST['category'] ?? 'custom';
        
        try {
            $stmt = $db->prepare("INSERT INTO flex_templates (name, category, flex_json, line_account_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $category, $flexJson, $currentBotId ?? null]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_templates') {
        try {
            $stmt = $db->prepare("SELECT * FROM flex_templates WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY created_at DESC");
            $stmt->execute([$currentBotId ?? null]);
            echo json_encode(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'templates' => []]);
        }
        exit;
    }
}

require_once 'includes/header.php';
?>

<div class="flex h-[calc(100vh-120px)] gap-4">
    <!-- Left Panel: Components -->
    <div class="w-64 bg-white rounded-xl shadow-lg overflow-hidden flex flex-col">
        <div class="p-4 bg-gradient-to-r from-green-500 to-emerald-600 text-white">
            <h2 class="font-bold text-lg">🧩 Components</h2>
            <p class="text-xs opacity-80">ลากไปวางในพื้นที่ออกแบบ</p>
        </div>

        <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <!-- Layout Components -->
            <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Layout</div>
            <div class="component-item" draggable="true" data-type="bubble">
                <div class="flex items-center gap-2 p-3 bg-blue-50 rounded-lg border-2 border-dashed border-blue-200 cursor-move hover:border-blue-400 transition">
                    <span class="text-xl">📦</span>
                    <div>
                        <div class="font-medium text-sm">Bubble</div>
                        <div class="text-xs text-gray-500">กล่องข้อความ</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="carousel">
                <div class="flex items-center gap-2 p-3 bg-purple-50 rounded-lg border-2 border-dashed border-purple-200 cursor-move hover:border-purple-400 transition">
                    <span class="text-xl">🎠</span>
                    <div>
                        <div class="font-medium text-sm">Carousel</div>
                        <div class="text-xs text-gray-500">หลาย Bubble</div>
                    </div>
                </div>
            </div>
            
            <!-- Content Components -->
            <div class="text-xs font-semibold text-gray-500 uppercase mt-4 mb-2">Content</div>
            <div class="component-item" draggable="true" data-type="hero">
                <div class="flex items-center gap-2 p-3 bg-orange-50 rounded-lg border-2 border-dashed border-orange-200 cursor-move hover:border-orange-400 transition">
                    <span class="text-xl">🖼️</span>
                    <div>
                        <div class="font-medium text-sm">Hero Image</div>
                        <div class="text-xs text-gray-500">รูปภาพหลัก</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="text">
                <div class="flex items-center gap-2 p-3 bg-green-50 rounded-lg border-2 border-dashed border-green-200 cursor-move hover:border-green-400 transition">
                    <span class="text-xl">📝</span>
                    <div>
                        <div class="font-medium text-sm">Text</div>
                        <div class="text-xs text-gray-500">ข้อความ</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="button">
                <div class="flex items-center gap-2 p-3 bg-red-50 rounded-lg border-2 border-dashed border-red-200 cursor-move hover:border-red-400 transition">
                    <span class="text-xl">🔘</span>
                    <div>
                        <div class="font-medium text-sm">Button</div>
                        <div class="text-xs text-gray-500">ปุ่มกด</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="separator">
                <div class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200 cursor-move hover:border-gray-400 transition">
                    <span class="text-xl">➖</span>
                    <div>
                        <div class="font-medium text-sm">Separator</div>
                        <div class="text-xs text-gray-500">เส้นแบ่ง</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="box">
                <div class="flex items-center gap-2 p-3 bg-indigo-50 rounded-lg border-2 border-dashed border-indigo-200 cursor-move hover:border-indigo-400 transition">
                    <span class="text-xl">📐</span>
                    <div>
                        <div class="font-medium text-sm">Box</div>
                        <div class="text-xs text-gray-500">กล่องจัดเรียง</div>
                    </div>
                </div>
            </div>
            <div class="component-item" draggable="true" data-type="icon">
                <div class="flex items-center gap-2 p-3 bg-yellow-50 rounded-lg border-2 border-dashed border-yellow-200 cursor-move hover:border-yellow-400 transition">
                    <span class="text-xl">⭐</span>
                    <div>
                        <div class="font-medium text-sm">Icon</div>
                        <div class="text-xs text-gray-500">ไอคอน</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Templates -->
        <div class="p-3 border-t">
            <button onclick="showTemplates()" class="w-full py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg text-sm font-medium hover:opacity-90">
                📚 เทมเพลตสำเร็จรูป
            </button>
        </div>
    </div>

    <!-- Center Panel: Canvas -->
    <div class="flex-1 flex flex-col bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-4 bg-gradient-to-r from-blue-500 to-indigo-600 text-white flex justify-between items-center">
            <div>
                <h2 class="font-bold text-lg">🎨 Design Canvas</h2>
                <p class="text-xs opacity-80">ลาก Component มาวางที่นี่</p>
            </div>
            <div class="flex gap-2">
                <button onclick="clearCanvas()" class="px-3 py-1.5 bg-white/20 rounded-lg text-sm hover:bg-white/30">🗑️ ล้าง</button>
                <button onclick="undoAction()" class="px-3 py-1.5 bg-white/20 rounded-lg text-sm hover:bg-white/30">↩️ Undo</button>
            </div>
        </div>
        
        <div class="flex-1 overflow-auto p-6 bg-gray-100">
            <div id="canvas" class="min-h-[400px] bg-white rounded-xl border-2 border-dashed border-gray-300 p-4 transition-all"
                 ondragover="handleDragOver(event)" ondrop="handleDrop(event)">
                <div id="canvas-placeholder" class="flex flex-col items-center justify-center h-full text-gray-400 py-20">
                    <div class="text-6xl mb-4">📦</div>
                    <p class="text-lg font-medium">ลาก Component มาวางที่นี่</p>
                    <p class="text-sm">หรือเลือกจากเทมเพลตสำเร็จรูป</p>
                </div>
                <div id="canvas-content" class="hidden space-y-2"></div>
            </div>
        </div>
    </div>
    
    <!-- Right Panel: Preview & Properties -->
    <div class="w-80 flex flex-col gap-4">
        <!-- Live Preview -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden flex-1">
            <div class="p-4 bg-gradient-to-r from-green-500 to-teal-600 text-white">
                <h2 class="font-bold text-lg">📱 Live Preview</h2>
                <p class="text-xs opacity-80">ตัวอย่างบน LINE</p>
            </div>
            <div class="p-4 bg-[#7494A5] min-h-[300px] flex justify-center">
                <div id="preview-container" class="w-full max-w-[280px]">
                    <div id="preview-placeholder" class="text-center text-white/60 py-10">
                        <div class="text-4xl mb-2">💬</div>
                        <p class="text-sm">Preview จะแสดงที่นี่</p>
                    </div>
                    <div id="preview-content" class="hidden"></div>
                </div>
            </div>
        </div>
        
        <!-- Properties Panel -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 bg-gradient-to-r from-orange-500 to-red-500 text-white">
                <h2 class="font-bold text-lg">⚙️ Properties</h2>
                <p class="text-xs opacity-80">แก้ไขคุณสมบัติ</p>
            </div>
            <div id="properties-panel" class="p-4 max-h-[250px] overflow-y-auto">
                <p class="text-gray-400 text-sm text-center py-4">เลือก Component เพื่อแก้ไข</p>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Action Bar -->
<div class="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg p-4 ml-64">
    <div class="max-w-6xl mx-auto flex justify-between items-center">
        <div class="flex gap-2">
            <button onclick="showJsonModal()" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">
                📄 ดู JSON
            </button>
            <button onclick="importJson()" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm">
                📥 Import JSON
            </button>
        </div>
        <div class="flex gap-2">
            <button onclick="saveTemplate()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm">
                💾 บันทึกเทมเพลต
            </button>
            <button onclick="useToBroadcast()" class="px-6 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg hover:opacity-90 font-medium">
                📤 ใช้ใน Broadcast
            </button>
        </div>
    </div>
</div>

<!-- Templates Modal -->
<div id="templates-modal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-4xl mx-4 max-h-[80vh] overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-bold">📚 เทมเพลตสำเร็จรูป</h3>
            <button onclick="closeTemplates()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[60vh]">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4" id="templates-grid">
                <!-- Product Card -->
                <div onclick="useTemplate('product')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-orange-100 to-red-100 rounded-xl p-4 border-2 border-transparent group-hover:border-orange-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">🛍️</div>
                        <h4 class="font-medium">Product Card</h4>
                        <p class="text-xs text-gray-500">การ์ดสินค้า</p>
                    </div>
                </div>
                <!-- Promotion -->
                <div onclick="useTemplate('promotion')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-pink-100 to-purple-100 rounded-xl p-4 border-2 border-transparent group-hover:border-pink-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">🎁</div>
                        <h4 class="font-medium">Promotion</h4>
                        <p class="text-xs text-gray-500">โปรโมชั่น</p>
                    </div>
                </div>
                <!-- News -->
                <div onclick="useTemplate('news')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-blue-100 to-indigo-100 rounded-xl p-4 border-2 border-transparent group-hover:border-blue-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">📰</div>
                        <h4 class="font-medium">News</h4>
                        <p class="text-xs text-gray-500">ข่าวสาร</p>
                    </div>
                </div>
                <!-- Receipt -->
                <div onclick="useTemplate('receipt')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-green-100 to-teal-100 rounded-xl p-4 border-2 border-transparent group-hover:border-green-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">🧾</div>
                        <h4 class="font-medium">Receipt</h4>
                        <p class="text-xs text-gray-500">ใบเสร็จ</p>
                    </div>
                </div>
                <!-- Menu -->
                <div onclick="useTemplate('menu')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-yellow-100 to-orange-100 rounded-xl p-4 border-2 border-transparent group-hover:border-yellow-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">📋</div>
                        <h4 class="font-medium">Menu</h4>
                        <p class="text-xs text-gray-500">เมนูร้าน</p>
                    </div>
                </div>
                <!-- Contact -->
                <div onclick="useTemplate('contact')" class="cursor-pointer group">
                    <div class="bg-gradient-to-br from-cyan-100 to-blue-100 rounded-xl p-4 border-2 border-transparent group-hover:border-cyan-400 transition">
                        <div class="aspect-square bg-white rounded-lg mb-3 flex items-center justify-center text-4xl">📞</div>
                        <h4 class="font-medium">Contact</h4>
                        <p class="text-xs text-gray-500">ติดต่อเรา</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JSON Modal -->
<div id="json-modal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-3xl mx-4 max-h-[80vh] overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-bold">📄 Flex Message JSON</h3>
            <button onclick="closeJsonModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div class="p-4">
            <textarea id="json-output" class="w-full h-[400px] font-mono text-sm border rounded-lg p-4 bg-gray-50" readonly></textarea>
            <div class="flex justify-end gap-2 mt-4">
                <button onclick="copyJson()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">📋 Copy</button>
                <button onclick="closeJsonModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
// State Management
let flexData = null;
let selectedElement = null;
let history = [];
let historyIndex = -1;

// Templates
const templates = {
    product: {
        type: "bubble",
        hero: {
            type: "image",
            url: "https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png",
            size: "full",
            aspectRatio: "20:13",
            aspectMode: "cover",
            action: { type: "uri", uri: "https://example.com" }
        },
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "ชื่อสินค้า", weight: "bold", size: "xl" },
                { type: "text", text: "รายละเอียดสินค้า", size: "sm", color: "#666666", margin: "md", wrap: true },
                {
                    type: "box", layout: "baseline", margin: "md",
                    contents: [
                        { type: "text", text: "฿", size: "sm", color: "#FF5551", flex: 0 },
                        { type: "text", text: "999", size: "xl", color: "#FF5551", weight: "bold", flex: 0 }
                    ]
                }
            ]
        },
        footer: {
            type: "box",
            layout: "vertical",
            spacing: "sm",
            contents: [
                { type: "button", style: "primary", color: "#06C755", action: { type: "uri", label: "🛒 สั่งซื้อ", uri: "https://example.com" } },
                { type: "button", style: "secondary", action: { type: "uri", label: "ดูรายละเอียด", uri: "https://example.com" } }
            ]
        }
    },
    promotion: {
        type: "bubble",
        styles: { hero: { backgroundColor: "#FF6B6B" } },
        hero: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "🎉 SALE", size: "3xl", weight: "bold", color: "#FFFFFF", align: "center" },
                { type: "text", text: "ลดสูงสุด 50%", size: "xl", color: "#FFFFFF", align: "center", margin: "md" }
            ],
            paddingAll: "30px"
        },
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "โปรโมชั่นพิเศษ!", weight: "bold", size: "lg" },
                { type: "text", text: "สินค้าลดราคาพิเศษ เฉพาะวันนี้เท่านั้น!", size: "sm", color: "#666666", wrap: true, margin: "md" },
                { type: "text", text: "📅 1-31 ธ.ค. 2567", size: "xs", color: "#999999", margin: "lg" }
            ]
        },
        footer: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "button", style: "primary", color: "#FF6B6B", action: { type: "uri", label: "🛍️ ช้อปเลย!", uri: "https://example.com" } }
            ]
        }
    },
    news: {
        type: "bubble",
        hero: {
            type: "image",
            url: "https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png",
            size: "full",
            aspectRatio: "20:13",
            aspectMode: "cover"
        },
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "📰 ข่าวสาร", size: "xs", color: "#1DB446", weight: "bold" },
                { type: "text", text: "หัวข้อข่าว", weight: "bold", size: "lg", margin: "md" },
                { type: "text", text: "รายละเอียดข่าวสาร...", size: "sm", color: "#666666", wrap: true, margin: "md" },
                { type: "text", text: "15 ธ.ค. 2567", size: "xs", color: "#999999", margin: "lg" }
            ]
        },
        footer: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "button", style: "link", action: { type: "uri", label: "อ่านเพิ่มเติม →", uri: "https://example.com" } }
            ]
        }
    },
    receipt: {
        type: "bubble",
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "🧾 ใบเสร็จ", weight: "bold", color: "#1DB446", size: "sm" },
                { type: "text", text: "ร้านค้าของคุณ", weight: "bold", size: "xxl", margin: "md" },
                { type: "separator", margin: "lg" },
                {
                    type: "box", layout: "vertical", margin: "lg", spacing: "sm",
                    contents: [
                        { type: "box", layout: "horizontal", contents: [
                            { type: "text", text: "สินค้า A", size: "sm", color: "#555555", flex: 0 },
                            { type: "text", text: "฿100", size: "sm", color: "#111111", align: "end" }
                        ]},
                        { type: "box", layout: "horizontal", contents: [
                            { type: "text", text: "สินค้า B", size: "sm", color: "#555555", flex: 0 },
                            { type: "text", text: "฿200", size: "sm", color: "#111111", align: "end" }
                        ]}
                    ]
                },
                { type: "separator", margin: "lg" },
                { type: "box", layout: "horizontal", margin: "lg", contents: [
                    { type: "text", text: "รวม", size: "sm", color: "#555555", flex: 0, weight: "bold" },
                    { type: "text", text: "฿300", size: "lg", color: "#1DB446", align: "end", weight: "bold" }
                ]}
            ]
        }
    },
    menu: {
        type: "bubble",
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "📋 เมนูหลัก", weight: "bold", size: "xl", align: "center" },
                { type: "separator", margin: "lg" }
            ]
        },
        footer: {
            type: "box",
            layout: "vertical",
            spacing: "sm",
            contents: [
                { type: "button", style: "primary", color: "#06C755", action: { type: "message", label: "🛒 ดูสินค้า", text: "shop" } },
                { type: "button", style: "secondary", action: { type: "message", label: "📦 เช็คออเดอร์", text: "orders" } },
                { type: "button", style: "secondary", action: { type: "message", label: "💬 ติดต่อเรา", text: "contact" } }
            ]
        }
    },
    contact: {
        type: "bubble",
        body: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "text", text: "📞 ติดต่อเรา", weight: "bold", size: "xl" },
                { type: "separator", margin: "lg" },
                { type: "box", layout: "vertical", margin: "lg", spacing: "md", contents: [
                    { type: "box", layout: "horizontal", contents: [
                        { type: "text", text: "📍", flex: 0 },
                        { type: "text", text: "123 ถนนสุขุมวิท กรุงเทพฯ", size: "sm", color: "#666666", flex: 5, wrap: true, margin: "md" }
                    ]},
                    { type: "box", layout: "horizontal", contents: [
                        { type: "text", text: "📱", flex: 0 },
                        { type: "text", text: "02-xxx-xxxx", size: "sm", color: "#666666", flex: 5, margin: "md" }
                    ]},
                    { type: "box", layout: "horizontal", contents: [
                        { type: "text", text: "⏰", flex: 0 },
                        { type: "text", text: "จ-ส 9:00-18:00", size: "sm", color: "#666666", flex: 5, margin: "md" }
                    ]}
                ]}
            ]
        },
        footer: {
            type: "box",
            layout: "vertical",
            contents: [
                { type: "button", style: "primary", color: "#06C755", action: { type: "uri", label: "📍 ดูแผนที่", uri: "https://maps.google.com" } }
            ]
        }
    }
};

// Drag & Drop Handlers
function handleDragStart(e) {
    e.dataTransfer.setData('type', e.target.closest('.component-item').dataset.type);
    e.dataTransfer.effectAllowed = 'copy';
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    document.getElementById('canvas').classList.add('border-green-400', 'bg-green-50');
}

function handleDragLeave(e) {
    document.getElementById('canvas').classList.remove('border-green-400', 'bg-green-50');
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('canvas').classList.remove('border-green-400', 'bg-green-50');
    
    const type = e.dataTransfer.getData('type');
    if (type) {
        addComponent(type);
    }
}

// Add Component
function addComponent(type) {
    saveHistory();
    
    if (type === 'bubble' || type === 'carousel') {
        if (!flexData) {
            flexData = type === 'bubble' ? { type: 'bubble', body: { type: 'box', layout: 'vertical', contents: [] } } 
                                         : { type: 'carousel', contents: [{ type: 'bubble', body: { type: 'box', layout: 'vertical', contents: [] } }] };
        }
    } else if (flexData) {
        const component = createComponent(type);
        if (flexData.type === 'bubble') {
            if (!flexData.body) flexData.body = { type: 'box', layout: 'vertical', contents: [] };
            flexData.body.contents.push(component);
        } else if (flexData.type === 'carousel' && flexData.contents.length > 0) {
            const lastBubble = flexData.contents[flexData.contents.length - 1];
            if (!lastBubble.body) lastBubble.body = { type: 'box', layout: 'vertical', contents: [] };
            lastBubble.body.contents.push(component);
        }
    } else {
        // Auto create bubble if no container
        flexData = { type: 'bubble', body: { type: 'box', layout: 'vertical', contents: [createComponent(type)] } };
    }
    
    updateCanvas();
    updatePreview();
}

function createComponent(type) {
    switch (type) {
        case 'hero':
            return { type: 'image', url: 'https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png', size: 'full', aspectRatio: '20:13', aspectMode: 'cover' };
        case 'text':
            return { type: 'text', text: 'ข้อความ', size: 'md', color: '#333333' };
        case 'button':
            return { type: 'button', style: 'primary', color: '#06C755', action: { type: 'uri', label: 'ปุ่ม', uri: 'https://example.com' } };
        case 'separator':
            return { type: 'separator', margin: 'md' };
        case 'box':
            return { type: 'box', layout: 'horizontal', contents: [] };
        case 'icon':
            return { type: 'icon', url: 'https://developers-resource.landpress.line.me/fx/img/review_gold_star_28.png', size: 'sm' };
        default:
            return { type: 'text', text: type };
    }
}

// Update Canvas
function updateCanvas() {
    const placeholder = document.getElementById('canvas-placeholder');
    const content = document.getElementById('canvas-content');
    
    if (!flexData) {
        placeholder.classList.remove('hidden');
        content.classList.add('hidden');
        return;
    }
    
    placeholder.classList.add('hidden');
    content.classList.remove('hidden');
    content.innerHTML = renderCanvasElement(flexData, 'root');
}

function renderCanvasElement(element, path) {
    if (!element) return '';
    
    let html = '';
    const type = element.type;
    
    if (type === 'bubble') {
        html = `<div class="canvas-element bubble bg-white border-2 border-blue-300 rounded-lg p-3 cursor-pointer hover:border-blue-500" data-path="${path}" onclick="selectElement('${path}')">
            <div class="text-xs text-blue-500 font-medium mb-2">📦 Bubble</div>
            ${element.hero ? renderCanvasElement(element.hero, path + '.hero') : ''}
            ${element.body ? renderCanvasElement(element.body, path + '.body') : ''}
            ${element.footer ? renderCanvasElement(element.footer, path + '.footer') : ''}
        </div>`;
    } else if (type === 'carousel') {
        html = `<div class="canvas-element carousel" data-path="${path}">
            <div class="text-xs text-purple-500 font-medium mb-2">🎠 Carousel</div>
            <div class="flex gap-2 overflow-x-auto pb-2">
                ${element.contents.map((b, i) => renderCanvasElement(b, path + '.contents[' + i + ']')).join('')}
                <button onclick="addBubbleToCarousel()" class="min-w-[100px] h-[150px] border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center text-gray-400 hover:border-gray-400">+ Bubble</button>
            </div>
        </div>`;
    } else if (type === 'box') {
        const layoutClass = element.layout === 'horizontal' ? 'flex-row' : 'flex-col';
        html = `<div class="canvas-element box bg-gray-50 border border-gray-200 rounded p-2 cursor-pointer hover:border-gray-400" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <div class="text-xs text-gray-500 mb-1">📐 Box (${element.layout})</div>
            <div class="flex ${layoutClass} gap-1">
                ${element.contents ? element.contents.map((c, i) => renderCanvasElement(c, path + '.contents[' + i + ']')).join('') : ''}
            </div>
        </div>`;
    } else if (type === 'image') {
        html = `<div class="canvas-element image bg-orange-50 border border-orange-200 rounded p-2 cursor-pointer hover:border-orange-400" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <img src="${element.url}" class="w-full h-20 object-cover rounded" onerror="this.src='https://via.placeholder.com/300x100?text=Image'">
        </div>`;
    } else if (type === 'text') {
        html = `<div class="canvas-element text bg-green-50 border border-green-200 rounded px-2 py-1 cursor-pointer hover:border-green-400" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <span class="text-sm">${element.text || 'Text'}</span>
        </div>`;
    } else if (type === 'button') {
        html = `<div class="canvas-element button bg-red-50 border border-red-200 rounded p-2 cursor-pointer hover:border-red-400" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <div class="text-center text-sm font-medium" style="color: ${element.color || '#06C755'}">${element.action?.label || 'Button'}</div>
        </div>`;
    } else if (type === 'separator') {
        html = `<div class="canvas-element separator my-2 cursor-pointer" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <hr class="border-gray-300">
        </div>`;
    } else if (type === 'icon') {
        html = `<div class="canvas-element icon inline-block cursor-pointer" data-path="${path}" onclick="selectElement('${path}'); event.stopPropagation();">
            <img src="${element.url}" class="w-4 h-4">
        </div>`;
    }
    
    return html;
}

// Update Preview (LINE-like)
function updatePreview() {
    const placeholder = document.getElementById('preview-placeholder');
    const content = document.getElementById('preview-content');
    
    if (!flexData) {
        placeholder.classList.remove('hidden');
        content.classList.add('hidden');
        return;
    }
    
    placeholder.classList.add('hidden');
    content.classList.remove('hidden');
    content.innerHTML = renderPreviewElement(flexData);
}

function renderPreviewElement(element) {
    if (!element) return '';
    const type = element.type;
    
    if (type === 'bubble') {
        return `<div class="bg-white rounded-2xl overflow-hidden shadow-lg">
            ${element.hero ? renderPreviewElement(element.hero) : ''}
            ${element.body ? `<div class="p-4">${renderPreviewElement(element.body)}</div>` : ''}
            ${element.footer ? `<div class="px-4 pb-4">${renderPreviewElement(element.footer)}</div>` : ''}
        </div>`;
    } else if (type === 'carousel') {
        return `<div class="flex gap-2 overflow-x-auto snap-x">
            ${element.contents.map(b => `<div class="min-w-[260px] snap-center">${renderPreviewElement(b)}</div>`).join('')}
        </div>`;
    } else if (type === 'box') {
        const layoutClass = element.layout === 'horizontal' ? 'flex-row items-center' : 'flex-col';
        const spacing = element.spacing === 'sm' ? 'gap-1' : element.spacing === 'lg' ? 'gap-3' : 'gap-2';
        const margin = element.margin === 'lg' ? 'mt-3' : element.margin === 'md' ? 'mt-2' : '';
        return `<div class="flex ${layoutClass} ${spacing} ${margin}">
            ${element.contents ? element.contents.map(c => renderPreviewElement(c)).join('') : ''}
        </div>`;
    } else if (type === 'image') {
        return `<img src="${element.url}" class="w-full object-cover" style="aspect-ratio: ${element.aspectRatio?.replace(':', '/') || '16/9'}" onerror="this.src='https://via.placeholder.com/300x150?text=Image'">`;
    } else if (type === 'text') {
        const sizeClass = { xxs: 'text-[10px]', xs: 'text-xs', sm: 'text-sm', md: 'text-base', lg: 'text-lg', xl: 'text-xl', xxl: 'text-2xl', '3xl': 'text-3xl' }[element.size] || 'text-base';
        const weightClass = element.weight === 'bold' ? 'font-bold' : '';
        const alignClass = { center: 'text-center', end: 'text-right' }[element.align] || '';
        const margin = element.margin === 'lg' ? 'mt-3' : element.margin === 'md' ? 'mt-2' : '';
        return `<div class="${sizeClass} ${weightClass} ${alignClass} ${margin} ${element.wrap ? '' : 'truncate'}" style="color: ${element.color || '#333'}">${element.text || ''}</div>`;
    } else if (type === 'button') {
        const bgColor = element.style === 'primary' ? (element.color || '#06C755') : 'transparent';
        const textColor = element.style === 'primary' ? '#fff' : (element.color || '#06C755');
        const border = element.style === 'secondary' ? `border: 1px solid ${element.color || '#06C755'}` : '';
        return `<button class="w-full py-2 px-4 rounded-lg text-sm font-medium" style="background: ${bgColor}; color: ${textColor}; ${border}">${element.action?.label || 'Button'}</button>`;
    } else if (type === 'separator') {
        const margin = element.margin === 'lg' ? 'my-3' : element.margin === 'md' ? 'my-2' : 'my-1';
        return `<hr class="border-gray-200 ${margin}">`;
    } else if (type === 'icon') {
        const sizeClass = { xxs: 'w-3 h-3', xs: 'w-3 h-3', sm: 'w-4 h-4', md: 'w-5 h-5', lg: 'w-6 h-6' }[element.size] || 'w-4 h-4';
        return `<img src="${element.url}" class="${sizeClass}">`;
    }
    return '';
}

// Select Element & Show Properties
function selectElement(path) {
    selectedElement = path;
    document.querySelectorAll('.canvas-element').forEach(el => el.classList.remove('ring-2', 'ring-blue-500'));
    const el = document.querySelector(`[data-path="${path}"]`);
    if (el) el.classList.add('ring-2', 'ring-blue-500');
    
    showProperties(path);
}

function getElementByPath(path) {
    if (path === 'root') return flexData;
    const parts = path.replace('root.', '').split('.');
    let current = flexData;
    for (const part of parts) {
        const match = part.match(/(\w+)\[(\d+)\]/);
        if (match) {
            current = current[match[1]][parseInt(match[2])];
        } else {
            current = current[part];
        }
    }
    return current;
}

function setElementByPath(path, value) {
    if (path === 'root') { flexData = value; return; }
    const parts = path.replace('root.', '').split('.');
    let current = flexData;
    for (let i = 0; i < parts.length - 1; i++) {
        const match = parts[i].match(/(\w+)\[(\d+)\]/);
        if (match) {
            current = current[match[1]][parseInt(match[2])];
        } else {
            current = current[parts[i]];
        }
    }
    const lastPart = parts[parts.length - 1];
    const lastMatch = lastPart.match(/(\w+)\[(\d+)\]/);
    if (lastMatch) {
        current[lastMatch[1]][parseInt(lastMatch[2])] = value;
    } else {
        current[lastPart] = value;
    }
}

// Properties Panel
function showProperties(path) {
    const element = getElementByPath(path);
    if (!element) return;
    
    const panel = document.getElementById('properties-panel');
    let html = `<div class="space-y-3">
        <div class="flex justify-between items-center">
            <span class="text-sm font-medium text-gray-700">Type: ${element.type}</span>
            <button onclick="deleteElement('${path}')" class="text-red-500 hover:text-red-700 text-sm">🗑️ ลบ</button>
        </div>`;
    
    if (element.type === 'text') {
        html += `
            <div>
                <label class="block text-xs text-gray-500 mb-1">ข้อความ</label>
                <input type="text" value="${element.text || ''}" onchange="updateProperty('${path}', 'text', this.value)" class="w-full px-2 py-1 border rounded text-sm">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">ขนาด</label>
                    <select onchange="updateProperty('${path}', 'size', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                        ${['xxs','xs','sm','md','lg','xl','xxl','3xl'].map(s => `<option value="${s}" ${element.size === s ? 'selected' : ''}>${s}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">สี</label>
                    <input type="color" value="${element.color || '#333333'}" onchange="updateProperty('${path}', 'color', this.value)" class="w-full h-8 border rounded">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">น้ำหนัก</label>
                    <select onchange="updateProperty('${path}', 'weight', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                        <option value="">ปกติ</option>
                        <option value="bold" ${element.weight === 'bold' ? 'selected' : ''}>Bold</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">จัดตำแหน่ง</label>
                    <select onchange="updateProperty('${path}', 'align', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                        <option value="">ซ้าย</option>
                        <option value="center" ${element.align === 'center' ? 'selected' : ''}>กลาง</option>
                        <option value="end" ${element.align === 'end' ? 'selected' : ''}>ขวา</option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" ${element.wrap ? 'checked' : ''} onchange="updateProperty('${path}', 'wrap', this.checked)"> ตัดบรรทัดอัตโนมัติ
            </label>`;
    } else if (element.type === 'image') {
        html += `
            <div>
                <label class="block text-xs text-gray-500 mb-1">URL รูปภาพ</label>
                <input type="url" value="${element.url || ''}" onchange="updateProperty('${path}', 'url', this.value)" class="w-full px-2 py-1 border rounded text-sm" placeholder="https://...">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">อัตราส่วน</label>
                <select onchange="updateProperty('${path}', 'aspectRatio', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                    ${['1:1','4:3','16:9','20:13','2:1','3:1'].map(r => `<option value="${r}" ${element.aspectRatio === r ? 'selected' : ''}>${r}</option>`).join('')}
                </select>
            </div>`;
    } else if (element.type === 'button') {
        html += `
            <div>
                <label class="block text-xs text-gray-500 mb-1">ข้อความบนปุ่ม</label>
                <input type="text" value="${element.action?.label || ''}" onchange="updateButtonLabel('${path}', this.value)" class="w-full px-2 py-1 border rounded text-sm">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">สไตล์</label>
                    <select onchange="updateProperty('${path}', 'style', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                        <option value="primary" ${element.style === 'primary' ? 'selected' : ''}>Primary</option>
                        <option value="secondary" ${element.style === 'secondary' ? 'selected' : ''}>Secondary</option>
                        <option value="link" ${element.style === 'link' ? 'selected' : ''}>Link</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">สี</label>
                    <input type="color" value="${element.color || '#06C755'}" onchange="updateProperty('${path}', 'color', this.value)" class="w-full h-8 border rounded">
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Action Type</label>
                <select onchange="updateButtonActionType('${path}', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                    <option value="uri" ${element.action?.type === 'uri' ? 'selected' : ''}>เปิด URL</option>
                    <option value="message" ${element.action?.type === 'message' ? 'selected' : ''}>ส่งข้อความ</option>
                    <option value="postback" ${element.action?.type === 'postback' ? 'selected' : ''}>Postback</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">${element.action?.type === 'uri' ? 'URL' : 'ข้อความ/Data'}</label>
                <input type="text" value="${element.action?.uri || element.action?.text || element.action?.data || ''}" onchange="updateButtonAction('${path}', this.value)" class="w-full px-2 py-1 border rounded text-sm">
            </div>`;
    } else if (element.type === 'box') {
        html += `
            <div>
                <label class="block text-xs text-gray-500 mb-1">Layout</label>
                <select onchange="updateProperty('${path}', 'layout', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                    <option value="vertical" ${element.layout === 'vertical' ? 'selected' : ''}>Vertical</option>
                    <option value="horizontal" ${element.layout === 'horizontal' ? 'selected' : ''}>Horizontal</option>
                    <option value="baseline" ${element.layout === 'baseline' ? 'selected' : ''}>Baseline</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Spacing</label>
                <select onchange="updateProperty('${path}', 'spacing', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                    <option value="">None</option>
                    <option value="xs" ${element.spacing === 'xs' ? 'selected' : ''}>XS</option>
                    <option value="sm" ${element.spacing === 'sm' ? 'selected' : ''}>SM</option>
                    <option value="md" ${element.spacing === 'md' ? 'selected' : ''}>MD</option>
                    <option value="lg" ${element.spacing === 'lg' ? 'selected' : ''}>LG</option>
                </select>
            </div>`;
    }
    
    html += '</div>';
    panel.innerHTML = html;
}

function updateProperty(path, prop, value) {
    saveHistory();
    const element = getElementByPath(path);
    if (element) {
        if (value === '' || value === false) {
            delete element[prop];
        } else {
            element[prop] = value;
        }
        updateCanvas();
        updatePreview();
    }
}

function updateButtonLabel(path, value) {
    saveHistory();
    const element = getElementByPath(path);
    if (element && element.action) {
        element.action.label = value;
        updateCanvas();
        updatePreview();
    }
}

function updateButtonActionType(path, type) {
    saveHistory();
    const element = getElementByPath(path);
    if (element) {
        const label = element.action?.label || 'Button';
        element.action = { type, label };
        if (type === 'uri') element.action.uri = 'https://example.com';
        else if (type === 'message') element.action.text = 'message';
        else if (type === 'postback') element.action.data = 'action=click';
        updateCanvas();
        updatePreview();
        showProperties(path);
    }
}

function updateButtonAction(path, value) {
    saveHistory();
    const element = getElementByPath(path);
    if (element && element.action) {
        if (element.action.type === 'uri') element.action.uri = value;
        else if (element.action.type === 'message') element.action.text = value;
        else if (element.action.type === 'postback') element.action.data = value;
        updateCanvas();
        updatePreview();
    }
}

function deleteElement(path) {
    if (!confirm('ต้องการลบ element นี้?')) return;
    saveHistory();
    
    const parts = path.replace('root.', '').split('.');
    if (parts.length === 1 || path === 'root') {
        flexData = null;
    } else {
        const parentPath = 'root.' + parts.slice(0, -1).join('.');
        const parent = getElementByPath(parentPath);
        const lastPart = parts[parts.length - 1];
        const match = lastPart.match(/(\w+)\[(\d+)\]/);
        if (match && parent[match[1]]) {
            parent[match[1]].splice(parseInt(match[2]), 1);
        } else {
            delete parent[lastPart];
        }
    }
    
    selectedElement = null;
    document.getElementById('properties-panel').innerHTML = '<p class="text-gray-400 text-sm text-center py-4">เลือก Component เพื่อแก้ไข</p>';
    updateCanvas();
    updatePreview();
}

// History (Undo)
function saveHistory() {
    history = history.slice(0, historyIndex + 1);
    history.push(JSON.stringify(flexData));
    historyIndex = history.length - 1;
    if (history.length > 50) {
        history.shift();
        historyIndex--;
    }
}

function undoAction() {
    if (historyIndex > 0) {
        historyIndex--;
        flexData = JSON.parse(history[historyIndex]);
        updateCanvas();
        updatePreview();
    }
}

// Templates
function showTemplates() {
    document.getElementById('templates-modal').classList.remove('hidden');
    document.getElementById('templates-modal').classList.add('flex');
}

function closeTemplates() {
    document.getElementById('templates-modal').classList.add('hidden');
    document.getElementById('templates-modal').classList.remove('flex');
}

function useTemplate(name) {
    if (templates[name]) {
        saveHistory();
        flexData = JSON.parse(JSON.stringify(templates[name]));
        updateCanvas();
        updatePreview();
        closeTemplates();
    }
}

function addBubbleToCarousel() {
    if (flexData && flexData.type === 'carousel') {
        saveHistory();
        flexData.contents.push({ type: 'bubble', body: { type: 'box', layout: 'vertical', contents: [] } });
        updateCanvas();
        updatePreview();
    }
}

// Clear Canvas
function clearCanvas() {
    if (!flexData) return;
    if (!confirm('ต้องการล้างทั้งหมด?')) return;
    saveHistory();
    flexData = null;
    selectedElement = null;
    document.getElementById('properties-panel').innerHTML = '<p class="text-gray-400 text-sm text-center py-4">เลือก Component เพื่อแก้ไข</p>';
    updateCanvas();
    updatePreview();
}

// JSON Modal
function showJsonModal() {
    if (!flexData) {
        alert('ยังไม่มีข้อมูล Flex Message');
        return;
    }
    document.getElementById('json-output').value = JSON.stringify(flexData, null, 2);
    document.getElementById('json-modal').classList.remove('hidden');
    document.getElementById('json-modal').classList.add('flex');
}

function closeJsonModal() {
    document.getElementById('json-modal').classList.add('hidden');
    document.getElementById('json-modal').classList.remove('flex');
}

function copyJson() {
    const textarea = document.getElementById('json-output');
    textarea.select();
    document.execCommand('copy');
    alert('คัดลอก JSON แล้ว!');
}

function importJson() {
    const json = prompt('วาง Flex Message JSON:');
    if (json) {
        try {
            saveHistory();
            flexData = JSON.parse(json);
            updateCanvas();
            updatePreview();
        } catch (e) {
            alert('JSON ไม่ถูกต้อง: ' + e.message);
        }
    }
}

// Save Template
function saveTemplate() {
    if (!flexData) {
        alert('ยังไม่มีข้อมูล Flex Message');
        return;
    }
    const name = prompt('ตั้งชื่อเทมเพลต:', 'My Template');
    if (name) {
        fetch('flex-builder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=save_template&name=${encodeURIComponent(name)}&flex_json=${encodeURIComponent(JSON.stringify(flexData))}&category=custom`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) alert('บันทึกเทมเพลตเรียบร้อย!');
            else alert('เกิดข้อผิดพลาด: ' + data.error);
        });
    }
}

// Use in Broadcast
function useToBroadcast() {
    if (!flexData) {
        alert('ยังไม่มีข้อมูล Flex Message');
        return;
    }
    // Store in sessionStorage and redirect
    sessionStorage.setItem('flex_broadcast', JSON.stringify(flexData));
    window.location.href = 'broadcast.php?flex=1';
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Setup drag events
    document.querySelectorAll('.component-item').forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
    });
    
    document.getElementById('canvas').addEventListener('dragleave', handleDragLeave);
    
    // Check for imported flex from broadcast
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('edit')) {
        const stored = sessionStorage.getItem('flex_edit');
        if (stored) {
            flexData = JSON.parse(stored);
            updateCanvas();
            updatePreview();
        }
    }
    
    // Initialize history
    history.push(null);
    historyIndex = 0;
});
</script>

<style>
.component-item { user-select: none; }
.canvas-element { transition: all 0.2s; }
#canvas.border-green-400 { border-color: #4ade80 !important; }
#canvas.bg-green-50 { background-color: #f0fdf4 !important; }
</style>

<?php require_once 'includes/footer.php'; ?>
