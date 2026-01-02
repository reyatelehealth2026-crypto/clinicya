<?php
/**
 * Supplier Management - จัดการ Supplier
 * 
 * DEPRECATED: This file has been consolidated into procurement.php
 * Redirects to: procurement.php?tab=suppliers
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SupplierService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'จัดการ Supplier';

$supplierService = new SupplierService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM suppliers LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    // Table doesn't exist
}

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'create':
                $id = $supplierService->create($_POST);
                echo json_encode(['success' => true, 'id' => $id]);
                break;
            case 'update':
                $supplierService->update((int)$_POST['id'], $_POST);
                echo json_encode(['success' => true]);
                break;
            case 'toggle':
                $id = (int)$_POST['id'];
                $active = (int)$_POST['active'];
                $active ? $supplierService->activate($id) : $supplierService->deactivate($id);
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$suppliers = $tableExists ? $supplierService->getAll() : [];

require_once __DIR__ . '/../includes/header.php';

if (!$tableExists):
?>
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <i class="fas fa-database text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold text-yellow-700 mb-2">ยังไม่ได้ติดตั้งระบบ Inventory</h3>
    <p class="text-yellow-600 mb-4">กรุณา run migration script เพื่อสร้างตาราง database</p>
    <div class="bg-white rounded-lg p-4 text-left max-w-lg mx-auto">
        <p class="text-sm text-gray-600 mb-2">Run SQL file:</p>
        <code class="text-xs bg-gray-100 p-2 rounded block">database/migration_inventory.sql</code>
    </div>
</div>
<?php
else:
?>

<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-truck text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Supplier ทั้งหมด</p>
                <p class="text-2xl font-bold"><?= count($suppliers) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Active</p>
                <p class="text-2xl font-bold text-green-600"><?= count(array_filter($suppliers, fn($s) => $s['is_active'])) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h2 class="font-semibold"><i class="fas fa-truck mr-2 text-blue-500"></i>รายการ Supplier</h2>
        <button onclick="openModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-plus mr-1"></i>เพิ่ม Supplier
        </button>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รหัส</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ชื่อ Supplier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ติดต่อ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เบอร์โทร</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ยอดซื้อรวม</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($suppliers as $s): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($s['code']) ?></td>
                    <td class="px-4 py-3 font-medium"><?= htmlspecialchars($s['name']) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($s['contact_person'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right font-medium">฿<?= number_format($s['total_purchase_amount'], 2) ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?= $s['id'] ?>, <?= $s['is_active'] ? 0 : 1 ?>)" 
                            class="px-2 py-1 rounded-full text-xs <?= $s['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="editSupplier(<?= htmlspecialchars(json_encode($s)) ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="supplierModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold" id="modalTitle">เพิ่ม Supplier</h3>
            <button onclick="closeModal()" class="p-2 hover:bg-gray-100 rounded"><i class="fas fa-times"></i></button>
        </div>
        <form id="supplierForm" class="p-4 space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" value="">
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">รหัส</label>
                    <input type="text" name="code" class="w-full px-3 py-2 border rounded-lg" placeholder="Auto">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อ Supplier *</label>
                    <input type="text" name="name" required class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ผู้ติดต่อ</label>
                    <input type="text" name="contact_person" class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เบอร์โทร</label>
                    <input type="text" name="phone" class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">อีเมล</label>
                <input type="email" name="email" class="w-full px-3 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">ที่อยู่</label>
                <textarea name="address" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">เลขประจำตัวผู้เสียภาษี</label>
                    <input type="text" name="tax_id" class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เครดิต (วัน)</label>
                    <input type="number" name="payment_terms" value="30" class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            
            <div class="flex gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 bg-gray-200 rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('modalTitle').textContent = 'เพิ่ม Supplier';
    document.getElementById('supplierForm').reset();
    document.querySelector('[name="action"]').value = 'create';
    document.querySelector('[name="id"]').value = '';
    document.getElementById('supplierModal').classList.remove('hidden');
    document.getElementById('supplierModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('supplierModal').classList.add('hidden');
    document.getElementById('supplierModal').classList.remove('flex');
}

function editSupplier(s) {
    document.getElementById('modalTitle').textContent = 'แก้ไข Supplier';
    document.querySelector('[name="action"]').value = 'update';
    document.querySelector('[name="id"]').value = s.id;
    document.querySelector('[name="code"]').value = s.code || '';
    document.querySelector('[name="name"]').value = s.name || '';
    document.querySelector('[name="contact_person"]').value = s.contact_person || '';
    document.querySelector('[name="phone"]').value = s.phone || '';
    document.querySelector('[name="email"]').value = s.email || '';
    document.querySelector('[name="address"]').value = s.address || '';
    document.querySelector('[name="tax_id"]').value = s.tax_id || '';
    document.querySelector('[name="payment_terms"]').value = s.payment_terms || 30;
    document.getElementById('supplierModal').classList.remove('hidden');
    document.getElementById('supplierModal').classList.add('flex');
}

async function toggleStatus(id, active) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('active', active);
    await fetch('suppliers.php', { method: 'POST', body: formData });
    location.reload();
}

document.getElementById('supplierForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const res = await fetch('suppliers.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
        closeModal();
        location.reload();
    } else {
        alert(data.message || 'Error');
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
