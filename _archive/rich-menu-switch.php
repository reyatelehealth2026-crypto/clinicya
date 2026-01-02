<?php
/**
 * Rich Menu Switch - สร้าง Rich Menu แบบสลับหน้าได้
 * ใช้ Rich Menu Alias สำหรับการสลับหน้า
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Rich Menu สลับหน้า';

$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);

$message = '';
$messageType = '';

// สร้างตารางถ้ายังไม่มี
try {
    $db->exec("CREATE TABLE IF NOT EXISTS rich_menu_switch_sets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $db->exec("CREATE TABLE IF NOT EXISTS rich_menu_switch_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        switch_set_id INT NOT NULL,
        page_number INT NOT NULL DEFAULT 1,
        page_name VARCHAR(50) NOT NULL,
        rich_menu_id INT NOT NULL,
        line_rich_menu_id VARCHAR(100),
        alias_id VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_page (switch_set_id, page_number),
        INDEX idx_set (switch_set_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}


// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create_set':
                // สร้างชุด Rich Menu สลับหน้า
                $stmt = $db->prepare("INSERT INTO rich_menu_switch_sets (line_account_id, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$currentBotId, $_POST['name'], $_POST['description'] ?? '']);
                $setId = $db->lastInsertId();
                
                // สร้างหน้าแรก
                $stmt = $db->prepare("INSERT INTO rich_menu_switch_pages (switch_set_id, page_number, page_name, rich_menu_id) VALUES (?, 1, 'หน้า 1', 0)");
                $stmt->execute([$setId]);
                
                $message = 'สร้างชุด Rich Menu สำเร็จ';
                $messageType = 'success';
                break;
                
            case 'add_page':
                $setId = (int)$_POST['set_id'];
                
                // หาหมายเลขหน้าถัดไป
                $stmt = $db->prepare("SELECT MAX(page_number) FROM rich_menu_switch_pages WHERE switch_set_id = ?");
                $stmt->execute([$setId]);
                $maxPage = (int)$stmt->fetchColumn();
                $newPage = $maxPage + 1;
                
                if ($newPage > 10) {
                    throw new Exception('สูงสุด 10 หน้าต่อชุด');
                }
                
                $stmt = $db->prepare("INSERT INTO rich_menu_switch_pages (switch_set_id, page_number, page_name, rich_menu_id) VALUES (?, ?, ?, 0)");
                $stmt->execute([$setId, $newPage, "หน้า $newPage"]);
                
                $message = "เพิ่มหน้า $newPage สำเร็จ";
                $messageType = 'success';
                break;
                
            case 'update_page':
                $pageId = (int)$_POST['page_id'];
                $richMenuId = (int)$_POST['rich_menu_id'];
                $pageName = $_POST['page_name'];
                
                // ดึง line_rich_menu_id
                $stmt = $db->prepare("SELECT line_rich_menu_id FROM rich_menus WHERE id = ?");
                $stmt->execute([$richMenuId]);
                $lineRichMenuId = $stmt->fetchColumn();
                
                $stmt = $db->prepare("UPDATE rich_menu_switch_pages SET rich_menu_id = ?, line_rich_menu_id = ?, page_name = ? WHERE id = ?");
                $stmt->execute([$richMenuId, $lineRichMenuId, $pageName, $pageId]);
                
                $message = 'อัพเดทหน้าสำเร็จ';
                $messageType = 'success';
                break;
                
            case 'delete_page':
                $pageId = (int)$_POST['page_id'];
                $stmt = $db->prepare("DELETE FROM rich_menu_switch_pages WHERE id = ?");
                $stmt->execute([$pageId]);
                $message = 'ลบหน้าสำเร็จ';
                $messageType = 'success';
                break;
                
            case 'delete_set':
                $setId = (int)$_POST['set_id'];
                
                // ลบ aliases จาก LINE ก่อน
                $stmt = $db->prepare("SELECT alias_id FROM rich_menu_switch_pages WHERE switch_set_id = ? AND alias_id IS NOT NULL");
                $stmt->execute([$setId]);
                while ($alias = $stmt->fetch()) {
                    $line->deleteRichMenuAlias($alias['alias_id']);
                }
                
                $db->prepare("DELETE FROM rich_menu_switch_pages WHERE switch_set_id = ?")->execute([$setId]);
                $db->prepare("DELETE FROM rich_menu_switch_sets WHERE id = ?")->execute([$setId]);
                $message = 'ลบชุดสำเร็จ';
                $messageType = 'success';
                break;

            case 'deploy':
                // Deploy ชุด Rich Menu ไปยัง LINE
                $setId = (int)$_POST['set_id'];
                
                // ดึงหน้าทั้งหมด
                $stmt = $db->prepare("SELECT * FROM rich_menu_switch_pages WHERE switch_set_id = ? ORDER BY page_number");
                $stmt->execute([$setId]);
                $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($pages) < 2) {
                    throw new Exception('ต้องมีอย่างน้อย 2 หน้าเพื่อสลับ');
                }
                
                // ตรวจสอบว่าทุกหน้ามี Rich Menu
                foreach ($pages as $page) {
                    if (!$page['line_rich_menu_id']) {
                        throw new Exception("หน้า {$page['page_number']} ยังไม่ได้เลือก Rich Menu");
                    }
                }
                
                // ดึงชื่อชุด
                $stmt = $db->prepare("SELECT name FROM rich_menu_switch_sets WHERE id = ?");
                $stmt->execute([$setId]);
                $setName = $stmt->fetchColumn();
                $aliasPrefix = 'richmenu-alias-' . $setId . '-';
                
                // สร้าง/อัพเดท Alias สำหรับแต่ละหน้า
                $errors = [];
                foreach ($pages as $page) {
                    $aliasId = $aliasPrefix . 'page' . $page['page_number'];
                    
                    // ลองลบ alias เก่าก่อน (ถ้ามี)
                    $line->deleteRichMenuAlias($aliasId);
                    
                    // สร้าง alias ใหม่
                    $result = $line->createRichMenuAlias($page['line_rich_menu_id'], $aliasId);
                    
                    if ($result['code'] === 200) {
                        // บันทึก alias_id
                        $stmt = $db->prepare("UPDATE rich_menu_switch_pages SET alias_id = ? WHERE id = ?");
                        $stmt->execute([$aliasId, $page['id']]);
                    } else {
                        $errors[] = "หน้า {$page['page_number']}: " . ($result['body']['message'] ?? 'Unknown error');
                    }
                }
                
                if (empty($errors)) {
                    // ตั้งหน้าแรกเป็น default
                    $firstPage = $pages[0];
                    $line->setDefaultRichMenu($firstPage['line_rich_menu_id']);
                    
                    $message = 'Deploy สำเร็จ! Rich Menu พร้อมใช้งานแล้ว';
                    $messageType = 'success';
                } else {
                    $message = 'Deploy บางส่วนล้มเหลว: ' . implode(', ', $errors);
                    $messageType = 'warning';
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// ดึงข้อมูล
$stmt = $db->prepare("SELECT * FROM rich_menu_switch_sets WHERE line_account_id = ? ORDER BY created_at DESC");
$stmt->execute([$currentBotId]);
$switchSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ดึง Rich Menus ทั้งหมด
$stmt = $db->prepare("SELECT * FROM rich_menus WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
$stmt->execute([$currentBotId]);
$richMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ดึง Aliases จาก LINE
$aliasesResult = $line->getRichMenuAliasList();
$lineAliases = $aliasesResult['body']['aliases'] ?? [];

require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>">
    <i class="fas fa-<?= $messageType === 'success' ? 'check' : ($messageType === 'warning' ? 'exclamation' : 'times') ?>-circle mr-2"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <p class="text-gray-600">สร้าง Rich Menu หลายหน้าที่สลับไปมาได้ เช่น หน้าหลัก ↔ หน้าบริการ ↔ หน้าติดต่อ</p>
        </div>
        <button onclick="openCreateModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            <i class="fas fa-plus mr-2"></i>สร้างชุดใหม่
        </button>
    </div>
</div>

<!-- How it works -->
<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
    <h3 class="font-semibold text-blue-800 mb-2"><i class="fas fa-info-circle mr-2"></i>วิธีการทำงาน</h3>
    <div class="text-sm text-blue-700 space-y-1">
        <p>1. สร้างชุด Rich Menu และเพิ่มหน้าต่างๆ (เช่น หน้าหลัก, หน้าบริการ, หน้าติดต่อ)</p>
        <p>2. เลือก Rich Menu สำหรับแต่ละหน้า (ต้องสร้าง Rich Menu ไว้ก่อนในหน้า Rich Menu)</p>
        <p>3. ในแต่ละ Rich Menu ให้ตั้ง Action เป็น <code class="bg-blue-100 px-1 rounded">richmenuswitch</code> พร้อม Alias ID</p>
        <p>4. กด Deploy เพื่อเปิดใช้งาน</p>
    </div>
</div>

<!-- Switch Sets -->
<div class="space-y-6">
    <?php foreach ($switchSets as $set): ?>
    <?php
    $stmt = $db->prepare("SELECT p.*, rm.name as menu_name FROM rich_menu_switch_pages p LEFT JOIN rich_menus rm ON p.rich_menu_id = rm.id WHERE p.switch_set_id = ? ORDER BY p.page_number");
    $stmt->execute([$set['id']]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
            <div>
                <h3 class="font-semibold text-lg"><?= htmlspecialchars($set['name']) ?></h3>
                <?php if ($set['description']): ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($set['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex space-x-2">
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="add_page">
                    <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                    <button type="submit" class="px-3 py-1.5 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm">
                        <i class="fas fa-plus mr-1"></i>เพิ่มหน้า
                    </button>
                </form>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="deploy">
                    <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                    <button type="submit" class="px-3 py-1.5 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                        <i class="fas fa-rocket mr-1"></i>Deploy
                    </button>
                </form>
                <form method="POST" class="inline" onsubmit="return confirm('ลบชุดนี้?')">
                    <input type="hidden" name="action" value="delete_set">
                    <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                    <button type="submit" class="px-3 py-1.5 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="p-4">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <?php foreach ($pages as $page): ?>
                <div class="border rounded-lg p-3 <?= $page['alias_id'] ? 'border-green-300 bg-green-50' : 'border-gray-200' ?>">
                    <div class="flex justify-between items-start mb-2">
                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">
                            หน้า <?= $page['page_number'] ?>
                        </span>
                        <?php if ($page['alias_id']): ?>
                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">
                            <i class="fas fa-check"></i> Active
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" class="space-y-2">
                        <input type="hidden" name="action" value="update_page">
                        <input type="hidden" name="page_id" value="<?= $page['id'] ?>">
                        
                        <input type="text" name="page_name" value="<?= htmlspecialchars($page['page_name']) ?>" 
                               class="w-full px-2 py-1 border rounded text-sm" placeholder="ชื่อหน้า">
                        
                        <select name="rich_menu_id" class="w-full px-2 py-1 border rounded text-sm">
                            <option value="">-- เลือก Rich Menu --</option>
                            <?php foreach ($richMenus as $menu): ?>
                            <option value="<?= $menu['id'] ?>" <?= $page['rich_menu_id'] == $menu['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($menu['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <?php if ($page['alias_id']): ?>
                        <div class="text-xs text-gray-500 bg-gray-100 p-1 rounded break-all">
                            Alias: <?= htmlspecialchars($page['alias_id']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex space-x-1">
                            <button type="submit" class="flex-1 px-2 py-1 bg-blue-500 text-white rounded text-xs hover:bg-blue-600">
                                <i class="fas fa-save"></i> บันทึก
                            </button>
                            <?php if (count($pages) > 1): ?>
                            <button type="button" onclick="deletePage(<?= $page['id'] ?>)" class="px-2 py-1 bg-red-500 text-white rounded text-xs hover:bg-red-600">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($pages) >= 2): ?>
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                <h4 class="font-medium text-yellow-800 mb-2"><i class="fas fa-code mr-2"></i>วิธีตั้งค่า Action ใน Rich Menu</h4>
                <p class="text-sm text-yellow-700 mb-2">ในแต่ละ Rich Menu ให้ตั้ง Action ของปุ่มสลับหน้าเป็น:</p>
                <div class="space-y-1">
                    <?php foreach ($pages as $targetPage): ?>
                    <div class="flex items-center text-sm">
                        <span class="text-yellow-600 mr-2">→ ไป<?= htmlspecialchars($targetPage['page_name']) ?>:</span>
                        <code class="bg-yellow-100 px-2 py-0.5 rounded text-xs">
                            Type: richmenuswitch, Alias: richmenu-alias-<?= $set['id'] ?>-page<?= $targetPage['page_number'] ?>
                        </code>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($switchSets)): ?>
    <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
        <i class="fas fa-layer-group text-6xl mb-4"></i>
        <p>ยังไม่มีชุด Rich Menu สลับหน้า</p>
        <p class="text-sm">คลิก "สร้างชุดใหม่" เพื่อเริ่มต้น</p>
    </div>
    <?php endif; ?>
</div>


<!-- Create Modal -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-md mx-4">
        <form method="POST">
            <input type="hidden" name="action" value="create_set">
            
            <div class="p-4 border-b">
                <h3 class="text-lg font-semibold">สร้างชุด Rich Menu สลับหน้า</h3>
            </div>
            
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อชุด *</label>
                    <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น Main Menu Set">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">คำอธิบาย</label>
                    <textarea name="description" rows="2" class="w-full px-4 py-2 border rounded-lg" placeholder="อธิบายชุดนี้..."></textarea>
                </div>
            </div>
            
            <div class="p-4 border-t flex justify-end space-x-2 bg-gray-50">
                <button type="button" onclick="closeCreateModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-plus mr-2"></i>สร้าง
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Page Form (hidden) -->
<form id="deletePageForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete_page">
    <input type="hidden" name="page_id" id="deletePageId">
</form>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}

function deletePage(pageId) {
    if (confirm('ลบหน้านี้?')) {
        document.getElementById('deletePageId').value = pageId;
        document.getElementById('deletePageForm').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
