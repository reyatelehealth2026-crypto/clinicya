<?php
/**
 * Scheduled Messages Tab Content
 * ตั้งเวลาส่งข้อความล่วงหน้า
 * 
 * @package FileConsolidation
 */

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $contentSource = $_POST['content_source'] ?? 'custom';
        $content = $_POST['content'] ?? '';
        $messageType = $_POST['message_type'] ?? 'text';
        
        // ถ้าเลือกจากเทมเพลท
        if ($contentSource === 'template' && !empty($_POST['template_id'])) {
            $stmt = $db->prepare("SELECT message_type, content FROM templates WHERE id = ?");
            $stmt->execute([$_POST['template_id']]);
            $tpl = $stmt->fetch();
            if ($tpl) {
                $messageType = $tpl['message_type'];
                $content = $tpl['content'];
            }
        } 
        // ถ้าเลือกจากสินค้า
        elseif ($contentSource === 'product' && !empty($_POST['product_ids'])) {
            $messageType = 'flex';
            $content = json_encode(['product_ids' => $_POST['product_ids'], 'source' => 'business_items']);
        }
        // ถ้าเป็น Flex Designer
        elseif ($contentSource === 'flex' && !empty($_POST['flex_json'])) {
            $messageType = 'flex';
            $content = $_POST['flex_json'];
        }
        
        $data = [
            $_POST['title'],
            $messageType,
            $content,
            $_POST['target_type'],
            $_POST['target_id'] ?: null,
            $_POST['scheduled_at'],
            $_POST['repeat_type'],
            $contentSource,
            $_POST['template_id'] ?: null,
            !empty($_POST['product_ids']) ? implode(',', $_POST['product_ids']) : null
        ];

        if ($action === 'create') {
            $data[] = $currentBotId;
            $stmt = $db->prepare("INSERT INTO scheduled_messages (title, message_type, content, target_type, target_id, scheduled_at, repeat_type, content_source, template_id, product_ids, line_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        } else {
            $data[] = $_POST['id'];
            $data[] = $currentBotId;
            $stmt = $db->prepare("UPDATE scheduled_messages SET title=?, message_type=?, content=?, target_type=?, target_id=?, scheduled_at=?, repeat_type=?, content_source=?, template_id=?, product_ids=? WHERE id=? AND (line_account_id = ? OR line_account_id IS NULL)");
        }
        $stmt->execute($data);
        header('Location: scheduled.php?tab=messages');
        exit;
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM scheduled_messages WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$_POST['id'], $currentBotId]);
        header('Location: scheduled.php?tab=messages');
        exit;
    } elseif ($action === 'cancel') {
        $stmt = $db->prepare("UPDATE scheduled_messages SET status = 'cancelled' WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$_POST['id'], $currentBotId]);
        header('Location: scheduled.php?tab=messages');
        exit;
    }
}

// Get scheduled messages
$stmt = $db->prepare("SELECT sm.*, t.name as template_name FROM scheduled_messages sm LEFT JOIN templates t ON sm.template_id = t.id WHERE (sm.line_account_id = ? OR sm.line_account_id IS NULL) ORDER BY sm.scheduled_at ASC");
$stmt->execute([$currentBotId]);
$schedules = $stmt->fetchAll();

// Get groups
$groups = [];
try {
    $stmt = $db->query("SELECT * FROM `groups` ORDER BY name");
    $groups = $stmt->fetchAll();
} catch (Exception $e) {}

// Get templates
$templates = [];
try {
    $stmt = $db->query("SELECT * FROM templates ORDER BY category, name");
    $templates = $stmt->fetchAll();
} catch (Exception $e) {}

// Get products from business_items table
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, price, sale_price, image_url, description FROM business_items WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1 ORDER BY name LIMIT 100");
    $stmt->execute([$currentBotId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="mb-4 flex justify-between items-center">
    <p class="text-gray-600">ตั้งเวลาส่งข้อความอัตโนมัติ</p>
    <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
        <i class="fas fa-plus mr-2"></i>ตั้งเวลาใหม่
    </button>
</div>

<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">หัวข้อ</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">กำหนดส่ง</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ส่งถึง</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ซ้ำ</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($schedules as $schedule): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4">
                    <p class="font-medium"><?= htmlspecialchars($schedule['title']) ?></p>
                    <p class="text-sm text-gray-500 truncate max-w-xs">
                        <?php 
                        $source = $schedule['content_source'] ?? 'custom';
                        if ($source === 'template' && $schedule['template_name']) {
                            echo '<i class="fas fa-file-alt text-purple-500 mr-1"></i>' . htmlspecialchars($schedule['template_name']);
                        } elseif ($source === 'product' && $schedule['product_ids']) {
                            $count = count(explode(',', $schedule['product_ids']));
                            echo '<i class="fas fa-box text-orange-500 mr-1"></i>สินค้า ' . $count . ' รายการ';
                        } elseif ($source === 'flex') {
                            echo '<i class="fas fa-puzzle-piece text-blue-500 mr-1"></i>Flex Message';
                        } else {
                            echo htmlspecialchars(mb_substr($schedule['content'] ?? '', 0, 50)) . '...';
                        }
                        ?>
                    </p>
                </td>
                <td class="px-6 py-4">
                    <?php
                    $sourceLabels = ['custom' => 'กำหนดเอง', 'template' => 'เทมเพลท', 'product' => 'สินค้า', 'flex' => 'Flex'];
                    $sourceColors = ['custom' => 'bg-gray-100 text-gray-600', 'template' => 'bg-purple-100 text-purple-600', 'product' => 'bg-orange-100 text-orange-600', 'flex' => 'bg-blue-100 text-blue-600'];
                    $src = $schedule['content_source'] ?? 'custom';
                    ?>
                    <span class="px-2 py-1 text-xs rounded <?= $sourceColors[$src] ?? $sourceColors['custom'] ?>"><?= $sourceLabels[$src] ?? 'กำหนดเอง' ?></span>
                </td>
                <td class="px-6 py-4"><?= date('d/m/Y H:i', strtotime($schedule['scheduled_at'])) ?></td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded bg-blue-100 text-blue-600"><?= $schedule['target_type'] ?></span>
                </td>
                <td class="px-6 py-4"><?= $schedule['repeat_type'] === 'none' ? '-' : $schedule['repeat_type'] ?></td>
                <td class="px-6 py-4">
                    <?php
                    $statusColors = ['pending' => 'bg-yellow-100 text-yellow-600', 'sent' => 'bg-green-100 text-green-600', 'cancelled' => 'bg-gray-100 text-gray-600'];
                    ?>
                    <span class="px-2 py-1 text-xs rounded <?= $statusColors[$schedule['status']] ?? 'bg-gray-100' ?>"><?= $schedule['status'] ?></span>
                </td>
                <td class="px-6 py-4 text-right space-x-2">
                    <?php if ($schedule['status'] === 'pending'): ?>
                    <button onclick='editSchedule(<?= json_encode($schedule) ?>)' class="text-blue-500 hover:text-blue-700"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                        <button type="submit" class="text-orange-500 hover:text-orange-700"><i class="fas fa-ban"></i></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" class="inline" onsubmit="return confirmDelete()">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                        <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($schedules)): ?>
            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">ยังไม่มีข้อความที่ตั้งเวลา</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="bg-white rounded-xl w-full max-w-4xl mx-4 my-8">
        <form method="POST" id="scheduleForm">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId">
            <input type="hidden" name="flex_json" id="flex_json">
            <div class="p-6 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modalTitle">ตั้งเวลาส่งข้อความ</h3>
                <button type="button" onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                <div>
                    <label class="block text-sm font-medium mb-1">หัวข้อ</label>
                    <input type="text" name="title" id="title" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                
                <!-- Content Source Tabs -->
                <div>
                    <label class="block text-sm font-medium mb-2">เลือกเนื้อหา</label>
                    <div class="flex border rounded-lg overflow-hidden">
                        <button type="button" onclick="switchContentSource('custom')" class="content-tab flex-1 py-2 px-3 text-sm font-medium bg-green-500 text-white" data-source="custom">
                            <i class="fas fa-edit mr-1"></i>ข้อความ
                        </button>
                        <button type="button" onclick="switchContentSource('flex')" class="content-tab flex-1 py-2 px-3 text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-source="flex">
                            <i class="fas fa-puzzle-piece mr-1"></i>Flex
                        </button>
                        <button type="button" onclick="switchContentSource('template')" class="content-tab flex-1 py-2 px-3 text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-source="template">
                            <i class="fas fa-file-alt mr-1"></i>เทมเพลท
                        </button>
                        <button type="button" onclick="switchContentSource('product')" class="content-tab flex-1 py-2 px-3 text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-source="product">
                            <i class="fas fa-box mr-1"></i>สินค้า
                        </button>
                    </div>
                    <input type="hidden" name="content_source" id="content_source" value="custom">
                </div>
                
                <!-- Custom Content -->
                <div id="customContent" class="content-section space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ประเภทข้อความ</label>
                        <select name="message_type" id="message_type" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="text">Text</option>
                            <option value="flex">Flex Message (JSON)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ข้อความ</label>
                        <textarea name="content" id="content" rows="4" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="พิมพ์ข้อความที่ต้องการส่ง..."></textarea>
                    </div>
                </div>
                
                <!-- Flex Designer -->
                <div id="flexContent" class="content-section hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- Flex Builder -->
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-medium">ออกแบบ Flex Message</label>
                                <div class="flex gap-2">
                                    <button type="button" onclick="loadFlexTemplate('promo')" class="text-xs px-2 py-1 bg-purple-100 text-purple-600 rounded hover:bg-purple-200">โปรโมชั่น</button>
                                    <button type="button" onclick="loadFlexTemplate('notify')" class="text-xs px-2 py-1 bg-blue-100 text-blue-600 rounded hover:bg-blue-200">แจ้งเตือน</button>
                                    <button type="button" onclick="loadFlexTemplate('product')" class="text-xs px-2 py-1 bg-orange-100 text-orange-600 rounded hover:bg-orange-200">สินค้า</button>
                                </div>
                            </div>
                            
                            <!-- Quick Settings -->
                            <div class="bg-gray-50 rounded-lg p-3 space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-500">หัวข้อ</label>
                                        <input type="text" id="flexTitle" class="w-full px-3 py-1.5 text-sm border rounded" placeholder="หัวข้อ" onchange="updateFlexPreview()">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">สีพื้นหลัง</label>
                                        <input type="color" id="flexBgColor" value="#06C755" class="w-full h-8 rounded cursor-pointer" onchange="updateFlexPreview()">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">ข้อความหลัก</label>
                                    <textarea id="flexBody" rows="2" class="w-full px-3 py-1.5 text-sm border rounded" placeholder="ข้อความหลัก..." onchange="updateFlexPreview()"></textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-500">ปุ่ม 1</label>
                                        <input type="text" id="flexBtn1" class="w-full px-3 py-1.5 text-sm border rounded" placeholder="ข้อความปุ่ม" onchange="updateFlexPreview()">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Action</label>
                                        <input type="text" id="flexBtn1Action" class="w-full px-3 py-1.5 text-sm border rounded" placeholder="shop หรือ URL" onchange="updateFlexPreview()">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">รูปภาพ (URL)</label>
                                    <input type="text" id="flexImage" class="w-full px-3 py-1.5 text-sm border rounded" placeholder="https://..." onchange="updateFlexPreview()">
                                </div>
                            </div>
                            
                            <!-- JSON Editor -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs text-gray-500">JSON (แก้ไขขั้นสูง)</label>
                                    <button type="button" onclick="formatJson()" class="text-xs text-blue-500 hover:underline">Format</button>
                                </div>
                                <textarea id="flexJsonEditor" rows="8" class="w-full px-3 py-2 text-xs font-mono border rounded-lg bg-gray-900 text-green-400" onchange="updateFlexFromJson()"></textarea>
                            </div>
                        </div>
                        
                        <!-- Preview -->
                        <div>
                            <label class="text-sm font-medium mb-2 block">ตัวอย่าง</label>
                            <div class="bg-gray-100 rounded-lg p-4 min-h-[300px] flex items-start justify-center">
                                <div id="flexPreview" class="w-full max-w-[300px] bg-white rounded-2xl shadow-lg overflow-hidden">
                                    <div class="text-center text-gray-400 py-8">กรุณาออกแบบ Flex Message</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Template Selection -->
                <div id="templateContent" class="content-section hidden">
                    <label class="block text-sm font-medium mb-1">เลือกเทมเพลท</label>
                    <select name="template_id" id="template_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" onchange="previewTemplate()">
                        <option value="">-- เลือกเทมเพลท --</option>
                        <?php 
                        $currentCat = '';
                        foreach ($templates as $tpl): 
                            if (($tpl['category'] ?? '') !== $currentCat) {
                                if ($currentCat) echo '</optgroup>';
                                $currentCat = $tpl['category'] ?? '';
                                echo '<optgroup label="' . htmlspecialchars($currentCat ?: 'ไม่มีหมวดหมู่') . '">';
                            }
                        ?>
                        <option value="<?= $tpl['id'] ?>" data-type="<?= $tpl['message_type'] ?>" data-content="<?= htmlspecialchars($tpl['content']) ?>"><?= htmlspecialchars($tpl['name']) ?> (<?= $tpl['message_type'] ?>)</option>
                        <?php endforeach; ?>
                        <?php if ($currentCat) echo '</optgroup>'; ?>
                    </select>
                    <div id="templatePreview" class="mt-3 p-3 bg-gray-50 rounded-lg hidden">
                        <p class="text-xs text-gray-500 mb-1">ตัวอย่าง:</p>
                        <pre class="text-sm whitespace-pre-wrap max-h-32 overflow-y-auto" id="templatePreviewContent"></pre>
                    </div>
                </div>
                
                <!-- Product Selection -->
                <div id="productContent" class="content-section hidden">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm font-medium">เลือกสินค้า (เลือกได้หลายรายการ)</label>
                        <span class="text-xs text-gray-500">จาก business_items</span>
                    </div>
                    <div class="mb-2">
                        <input type="text" id="productSearch" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="🔍 ค้นหาสินค้า..." oninput="filterProducts()">
                    </div>
                    <div id="productList" class="border rounded-lg max-h-64 overflow-y-auto">
                        <?php if (empty($products)): ?>
                        <p class="p-4 text-gray-500 text-center">ไม่มีสินค้า</p>
                        <?php else: ?>
                        <?php foreach ($products as $prod): ?>
                        <label class="product-item flex items-center gap-3 p-3 hover:bg-gray-50 cursor-pointer border-b last:border-b-0" data-name="<?= strtolower($prod['name']) ?>">
                            <input type="checkbox" name="product_ids[]" value="<?= $prod['id'] ?>" class="product-checkbox w-4 h-4 text-green-500 rounded">
                            <img src="<?= htmlspecialchars($prod['image_url'] ?: 'assets/images/image-placeholder.svg') ?>" class="w-12 h-12 rounded-lg object-cover bg-gray-100" onerror="this.src='assets/images/image-placeholder.svg'">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm truncate"><?= htmlspecialchars($prod['name']) ?></p>
                                <div class="flex items-center gap-2">
                                    <?php if (!empty($prod['sale_price']) && $prod['sale_price'] < $prod['price']): ?>
                                    <span class="text-green-600 text-sm font-bold">฿<?= number_format($prod['sale_price'], 0) ?></span>
                                    <span class="text-gray-400 text-xs line-through">฿<?= number_format($prod['price'], 0) ?></span>
                                    <?php else: ?>
                                    <span class="text-green-600 text-sm font-bold">฿<?= number_format($prod['price'], 0) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1"></i>สินค้าที่เลือกจะถูกส่งเป็น Flex Message แบบ Carousel</p>
                </div>
                
                <!-- Target & Schedule -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ส่งถึง</label>
                        <select name="target_type" id="target_type" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="all">ทั้งหมด</option>
                            <option value="group">เฉพาะกลุ่ม</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เลือกกลุ่ม</label>
                        <select name="target_id" id="target_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือก --</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">กำหนดส่ง</label>
                        <input type="datetime-local" name="scheduled_at" id="scheduled_at" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ส่งซ้ำ</label>
                        <select name="repeat_type" id="repeat_type" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="none">ไม่ซ้ำ</option>
                            <option value="daily">ทุกวัน</option>
                            <option value="weekly">ทุกสัปดาห์</option>
                            <option value="monthly">ทุกเดือน</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="p-6 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
// Flex Templates
const flexTemplates = {
    promo: {
        title: '🎉 โปรโมชั่นพิเศษ',
        body: 'ลดราคาสุดพิเศษ! รีบมาช้อปกันเลย',
        bgColor: '#FF6B6B',
        btn1: '🛒 ช้อปเลย',
        btn1Action: 'shop',
        image: ''
    },
    notify: {
        title: '📢 แจ้งเตือน',
        body: 'มีข่าวสารใหม่สำหรับคุณ',
        bgColor: '#3B82F6',
        btn1: '📋 ดูรายละเอียด',
        btn1Action: 'menu',
        image: ''
    },
    product: {
        title: '✨ สินค้าแนะนำ',
        body: 'สินค้าคุณภาพดี ราคาถูก',
        bgColor: '#06C755',
        btn1: '🛒 สั่งซื้อ',
        btn1Action: 'shop',
        image: ''
    }
};

function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'ตั้งเวลาส่งข้อความ';
    document.getElementById('scheduleForm').reset();
    switchContentSource('custom');
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
    // Set default datetime to now + 1 hour
    const now = new Date();
    now.setHours(now.getHours() + 1);
    document.getElementById('scheduled_at').value = now.toISOString().slice(0, 16);
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
}

function confirmDelete() {
    return confirm('ยืนยันการลบ?');
}

function switchContentSource(source) {
    document.getElementById('content_source').value = source;
    
    document.querySelectorAll('.content-tab').forEach(tab => {
        if (tab.dataset.source === source) {
            tab.classList.remove('bg-gray-100', 'text-gray-600');
            tab.classList.add('bg-green-500', 'text-white');
        } else {
            tab.classList.remove('bg-green-500', 'text-white');
            tab.classList.add('bg-gray-100', 'text-gray-600');
        }
    });
    
    document.getElementById('customContent').classList.toggle('hidden', source !== 'custom');
    document.getElementById('flexContent').classList.toggle('hidden', source !== 'flex');
    document.getElementById('templateContent').classList.toggle('hidden', source !== 'template');
    document.getElementById('productContent').classList.toggle('hidden', source !== 'product');
    
    document.getElementById('content').required = (source === 'custom');
    
    if (source === 'flex') {
        loadFlexTemplate('promo');
    }
}

function loadFlexTemplate(type) {
    const tpl = flexTemplates[type];
    document.getElementById('flexTitle').value = tpl.title;
    document.getElementById('flexBody').value = tpl.body;
    document.getElementById('flexBgColor').value = tpl.bgColor;
    document.getElementById('flexBtn1').value = tpl.btn1;
    document.getElementById('flexBtn1Action').value = tpl.btn1Action;
    document.getElementById('flexImage').value = tpl.image;
    updateFlexPreview();
}

function buildFlexJson() {
    const title = document.getElementById('flexTitle').value || 'หัวข้อ';
    const body = document.getElementById('flexBody').value || 'ข้อความ';
    const bgColor = document.getElementById('flexBgColor').value || '#06C755';
    const btn1 = document.getElementById('flexBtn1').value;
    const btn1Action = document.getElementById('flexBtn1Action').value;
    const image = document.getElementById('flexImage').value;
    
    const bubble = {
        type: 'bubble',
        header: {
            type: 'box',
            layout: 'vertical',
            contents: [
                { type: 'text', text: title, weight: 'bold', size: 'lg', color: '#FFFFFF', align: 'center' }
            ],
            backgroundColor: bgColor,
            paddingAll: 'lg'
        },
        body: {
            type: 'box',
            layout: 'vertical',
            contents: [
                { type: 'text', text: body, size: 'md', wrap: true, color: '#333333' }
            ],
            paddingAll: 'xl'
        }
    };
    
    if (image) {
        bubble.hero = {
            type: 'image',
            url: image,
            size: 'full',
            aspectRatio: '20:13',
            aspectMode: 'cover'
        };
    }
    
    if (btn1) {
        const action = btn1Action.startsWith('http') 
            ? { type: 'uri', label: btn1, uri: btn1Action }
            : { type: 'message', label: btn1, text: btn1Action || btn1 };
        
        bubble.footer = {
            type: 'box',
            layout: 'vertical',
            contents: [
                { type: 'button', action: action, style: 'primary', color: bgColor }
            ],
            paddingAll: 'lg'
        };
    }
    
    return bubble;
}

function updateFlexPreview() {
    const flex = buildFlexJson();
    document.getElementById('flexJsonEditor').value = JSON.stringify(flex, null, 2);
    document.getElementById('flex_json').value = JSON.stringify(flex);
    renderFlexPreview(flex);
}

function updateFlexFromJson() {
    try {
        const json = JSON.parse(document.getElementById('flexJsonEditor').value);
        document.getElementById('flex_json').value = JSON.stringify(json);
        renderFlexPreview(json);
    } catch (e) {
        console.error('Invalid JSON');
    }
}

function formatJson() {
    try {
        const json = JSON.parse(document.getElementById('flexJsonEditor').value);
        document.getElementById('flexJsonEditor').value = JSON.stringify(json, null, 2);
    } catch (e) {
        alert('JSON ไม่ถูกต้อง');
    }
}

function renderFlexPreview(flex) {
    const preview = document.getElementById('flexPreview');
    let html = '';
    
    if (flex.hero) {
        html += `<img src="${flex.hero.url}" class="w-full h-32 object-cover" onerror="this.style.display='none'">`;
    }
    
    if (flex.header) {
        const bgColor = flex.header.backgroundColor || '#06C755';
        const text = flex.header.contents?.[0]?.text || '';
        html += `<div style="background:${bgColor}" class="p-4 text-white font-bold text-center">${text}</div>`;
    }
    
    if (flex.body) {
        const text = flex.body.contents?.[0]?.text || '';
        html += `<div class="p-4 text-gray-700">${text}</div>`;
    }
    
    if (flex.footer) {
        const btn = flex.footer.contents?.[0];
        if (btn) {
            const label = btn.action?.label || 'Button';
            const color = btn.color || '#06C755';
            html += `<div class="p-4 pt-0"><button style="background:${color}" class="w-full py-2 text-white rounded-lg font-medium">${label}</button></div>`;
        }
    }
    
    preview.innerHTML = html || '<div class="text-center text-gray-400 py-8">กรุณาออกแบบ Flex Message</div>';
}

function previewTemplate() {
    const select = document.getElementById('template_id');
    const option = select.options[select.selectedIndex];
    const preview = document.getElementById('templatePreview');
    const previewContent = document.getElementById('templatePreviewContent');
    
    if (option.value) {
        previewContent.textContent = option.dataset.content;
        preview.classList.remove('hidden');
    } else {
        preview.classList.add('hidden');
    }
}

function filterProducts() {
    const search = document.getElementById('productSearch').value.toLowerCase();
    document.querySelectorAll('.product-item').forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(search) ? '' : 'none';
    });
}

function editSchedule(schedule) {
    openModal();
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = schedule.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขตารางเวลา';
    document.getElementById('title').value = schedule.title;
    document.getElementById('message_type').value = schedule.message_type;
    document.getElementById('content').value = schedule.content || '';
    document.getElementById('target_type').value = schedule.target_type;
    document.getElementById('target_id').value = schedule.target_id || '';
    document.getElementById('scheduled_at').value = schedule.scheduled_at.replace(' ', 'T').slice(0, 16);
    document.getElementById('repeat_type').value = schedule.repeat_type;
    
    const source = schedule.content_source || 'custom';
    switchContentSource(source);
    
    if (source === 'template' && schedule.template_id) {
        document.getElementById('template_id').value = schedule.template_id;
        previewTemplate();
    } else if (source === 'product' && schedule.product_ids) {
        const productIds = schedule.product_ids.split(',');
        document.querySelectorAll('.product-checkbox').forEach(cb => {
            cb.checked = productIds.includes(cb.value);
        });
    } else if (source === 'flex' && schedule.content) {
        try {
            const flex = JSON.parse(schedule.content);
            document.getElementById('flexJsonEditor').value = JSON.stringify(flex, null, 2);
            document.getElementById('flex_json').value = schedule.content;
            renderFlexPreview(flex);
        } catch (e) {}
    }
}

// Form submit handler
document.getElementById('scheduleForm').addEventListener('submit', function(e) {
    const source = document.getElementById('content_source').value;
    if (source === 'flex') {
        const flexJson = document.getElementById('flex_json').value;
        if (!flexJson) {
            e.preventDefault();
            alert('กรุณาออกแบบ Flex Message');
            return false;
        }
    }
});
</script>
