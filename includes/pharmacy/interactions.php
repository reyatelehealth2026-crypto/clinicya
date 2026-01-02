<?php
/**
 * Drug Interactions Tab Content
 * จัดการฐานข้อมูลยาตีกัน
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_interaction') {
        try {
            $stmt = $db->prepare("INSERT INTO drug_interactions 
                (drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['drug1_name']),
                trim($_POST['drug1_generic']),
                trim($_POST['drug2_name']),
                trim($_POST['drug2_generic']),
                $_POST['severity'],
                trim($_POST['description']),
                trim($_POST['recommendation']),
            ]);
            $success = 'เพิ่มข้อมูลยาตีกันสำเร็จ';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
    
    if ($action === 'delete_interaction') {
        try {
            $stmt = $db->prepare("DELETE FROM drug_interactions WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success = 'ลบข้อมูลสำเร็จ';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

// Get interactions
$interactions = [];
try {
    $stmt = $db->query("SELECT * FROM drug_interactions ORDER BY severity DESC, drug1_name ASC");
    $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<style>
.severity-contraindicated { background: #FEE2E2; color: #991B1B; }
.severity-severe { background: #FEF3C7; color: #92400E; }
.severity-moderate { background: #DBEAFE; color: #1E40AF; }
.severity-mild { background: #D1FAE5; color: #065F46; }
</style>

<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-gray-500">ฐานข้อมูล Drug Interactions</p>
    </div>
    <button onclick="showAddInteractionModal()" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
        <i class="fas fa-plus mr-2"></i>เพิ่มข้อมูล
    </button>
</div>

<!-- Stats -->
<div class="grid grid-cols-4 gap-4 mb-6">
    <?php
    $severityCounts = ['contraindicated' => 0, 'severe' => 0, 'moderate' => 0, 'mild' => 0];
    foreach ($interactions as $i) {
        $severityCounts[$i['severity']] = ($severityCounts[$i['severity']] ?? 0) + 1;
    }
    ?>
    <div class="bg-red-50 rounded-xl p-4">
        <p class="text-red-600 text-2xl font-bold"><?= $severityCounts['contraindicated'] ?></p>
        <p class="text-red-500 text-sm">ห้ามใช้ร่วมกัน</p>
    </div>
    <div class="bg-yellow-50 rounded-xl p-4">
        <p class="text-yellow-600 text-2xl font-bold"><?= $severityCounts['severe'] ?></p>
        <p class="text-yellow-500 text-sm">รุนแรง</p>
    </div>
    <div class="bg-blue-50 rounded-xl p-4">
        <p class="text-blue-600 text-2xl font-bold"><?= $severityCounts['moderate'] ?></p>
        <p class="text-blue-500 text-sm">ปานกลาง</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4">
        <p class="text-green-600 text-2xl font-bold"><?= $severityCounts['mild'] ?></p>
        <p class="text-green-500 text-sm">เล็กน้อย</p>
    </div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ยา 1</th>
                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ยา 2</th>
                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ความรุนแรง</th>
                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ผลกระทบ</th>
                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">คำแนะนำ</th>
                <th class="px-4 py-3 text-center text-sm font-medium text-gray-600">จัดการ</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($interactions as $item): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <p class="font-medium"><?= htmlspecialchars($item['drug1_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($item['drug1_generic']) ?></p>
                </td>
                <td class="px-4 py-3">
                    <p class="font-medium"><?= htmlspecialchars($item['drug2_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($item['drug2_generic']) ?></p>
                </td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 rounded-full text-xs font-medium severity-<?= $item['severity'] ?>">
                        <?= ucfirst($item['severity']) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($item['description']) ?></td>
                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($item['recommendation']) ?></td>
                <td class="px-4 py-3 text-center">
                    <form method="POST" class="inline" onsubmit="return confirm('ยืนยันลบ?')">
                        <input type="hidden" name="action" value="delete_interaction">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="text-red-500 hover:text-red-700">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($interactions)): ?>
            <tr>
                <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                    ยังไม่มีข้อมูลยาตีกัน
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Modal -->
<div id="addInteractionModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black/50" onclick="hideAddInteractionModal()"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="p-4 border-b">
                <h3 class="font-bold text-lg">เพิ่มข้อมูลยาตีกัน</h3>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="add_interaction">
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อยา 1</label>
                        <input type="text" name="drug1_name" required class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อสามัญ 1</label>
                        <input type="text" name="drug1_generic" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อยา 2</label>
                        <input type="text" name="drug2_name" required class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อสามัญ 2</label>
                        <input type="text" name="drug2_generic" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ความรุนแรง</label>
                    <select name="severity" required class="w-full px-3 py-2 border rounded-lg">
                        <option value="mild">Mild - เล็กน้อย</option>
                        <option value="moderate" selected>Moderate - ปานกลาง</option>
                        <option value="severe">Severe - รุนแรง</option>
                        <option value="contraindicated">Contraindicated - ห้ามใช้ร่วมกัน</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ผลกระทบ</label>
                    <textarea name="description" rows="2" required class="w-full px-3 py-2 border rounded-lg"></textarea>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-1">คำแนะนำ</label>
                    <textarea name="recommendation" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="hideAddInteractionModal()" class="flex-1 py-2 bg-gray-100 text-gray-700 rounded-lg">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 py-2 bg-purple-500 text-white rounded-lg">
                        บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddInteractionModal() { document.getElementById('addInteractionModal').classList.remove('hidden'); }
function hideAddInteractionModal() { document.getElementById('addInteractionModal').classList.add('hidden'); }
</script>
