<?php
/**
 * Customer Segments - แบ่งกลุ่มลูกค้าอัจฉริยะ
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/AdvancedCRM.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Customer Segments';

include 'includes/header.php';

$crm = new AdvancedCRM($db, $currentBotId ?? null);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_segment') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $conditions = json_decode($_POST['conditions'] ?? '{}', true);

        if ($name && $conditions) {
            $crm->createSegment($name, $conditions, $description);
            header('Location: customer-segments.php?success=created');
            exit;
        }
    }

    if ($action === 'recalculate') {
        $segmentId = (int) $_POST['segment_id'];
        $count = $crm->calculateSegmentMembers($segmentId);
        header('Location: customer-segments.php?recalculated=' . $count);
        exit;
    }

    if ($action === 'delete_segment') {
        $segmentId = (int) $_POST['segment_id'];
        $stmt = $db->prepare("DELETE FROM customer_segments WHERE id = ?");
        $stmt->execute([$segmentId]);
        header('Location: customer-segments.php?success=deleted');
        exit;
    }
}

$segments = $crm->getSegments();
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h2 class="text-2xl font-bold">🎯 Customer Segments</h2>
        <p class="text-gray-600">แบ่งกลุ่มลูกค้าเพื่อ Personalized Marketing</p>
    </div>
    <button onclick="openCreateModal()" class="btn-primary">
        <i class="fas fa-plus mr-2"></i>สร้าง Segment
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
        <?= $_GET['success'] === 'created' ? 'สร้าง Segment สำเร็จ!' : 'ลบ Segment สำเร็จ!' ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['recalculated'])): ?>
    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4">
        คำนวณใหม่สำเร็จ! พบลูกค้า <?= number_format($_GET['recalculated']) ?> คน
    </div>
<?php endif; ?>

<!-- Segments Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($segments as $segment): ?>
        <div class="bg-white rounded-xl shadow hover:shadow-lg transition overflow-hidden">
            <div class="p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-lg"><?= htmlspecialchars($segment['name']) ?></h3>
                    <span
                        class="px-2 py-1 text-xs rounded-full <?= $segment['segment_type'] === 'dynamic' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700' ?>">
                        <?= $segment['segment_type'] === 'dynamic' ? '🔄 Dynamic' : '📌 Static' ?>
                    </span>
                </div>

                <?php if ($segment['description']): ?>
                    <p class="text-gray-600 text-sm mb-3"><?= htmlspecialchars($segment['description']) ?></p>
                <?php endif; ?>

                <div class="flex items-center justify-between text-sm mb-4">
                    <span class="text-gray-500">
                        <i class="fas fa-users mr-1"></i><?= number_format($segment['user_count']) ?> คน
                    </span>
                    <?php if ($segment['last_calculated_at']): ?>
                        <span class="text-gray-400 text-xs">
                            อัพเดท: <?= date('d/m H:i', strtotime($segment['last_calculated_at'])) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="flex gap-2">
                    <a href="broadcast.php?segment=<?= $segment['id'] ?>"
                        class="flex-1 py-2 text-center bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                        <i class="fas fa-paper-plane mr-1"></i>Broadcast
                    </a>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="action" value="recalculate">
                        <input type="hidden" name="segment_id" value="<?= $segment['id'] ?>">
                        <button type="submit"
                            class="w-full py-2 border border-blue-500 text-blue-500 rounded-lg hover:bg-blue-50 text-sm">
                            <i class="fas fa-sync-alt mr-1"></i>คำนวณใหม่
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('ลบ Segment นี้?')">
                        <input type="hidden" name="action" value="delete_segment">
                        <input type="hidden" name="segment_id" value="<?= $segment['id'] ?>">
                        <button type="submit"
                            class="py-2 px-3 border border-red-300 text-red-500 rounded-lg hover:bg-red-50">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($segments)): ?>
        <div class="col-span-full text-center py-12 bg-white rounded-xl">
            <i class="fas fa-layer-group text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 mb-4">ยังไม่มี Customer Segments</p>
            <button onclick="openCreateModal()" class="text-green-600 hover:text-green-700">
                <i class="fas fa-plus mr-1"></i>สร้าง Segment แรก
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Create Segment Modal -->
<div id="createModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center overflow-y-auto">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 my-8">
        <div class="p-6 border-b flex justify-between items-center">
            <h3 class="text-xl font-semibold">🎯 สร้าง Customer Segment</h3>
            <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="createSegmentForm" class="p-6">
            <input type="hidden" name="action" value="create_segment">
            <input type="hidden" name="conditions" id="conditionsJson">

            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">ชื่อ Segment</label>
                <input type="text" name="name" required
                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                    placeholder="เช่น High Value Customers">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">คำอธิบาย</label>
                <textarea name="description" rows="2"
                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"></textarea>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">เลือกจาก Template</label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="applyTemplate('active')"
                        class="p-3 border rounded-lg hover:bg-gray-50 text-left">
                        <span class="font-medium">🟢 Active Users</span>
                        <p class="text-xs text-gray-500">มี activity ใน 7 วัน</p>
                    </button>
                    <button type="button" onclick="applyTemplate('high_value')"
                        class="p-3 border rounded-lg hover:bg-gray-50 text-left">
                        <span class="font-medium">💎 High Value</span>
                        <p class="text-xs text-gray-500">ยอดซื้อ 5,000+ บาท</p>
                    </button>
                    <button type="button" onclick="applyTemplate('at_risk')"
                        class="p-3 border rounded-lg hover:bg-gray-50 text-left">
                        <span class="font-medium">⚠️ At Risk</span>
                        <p class="text-xs text-gray-500">ไม่มี activity 30+ วัน</p>
                    </button>
                    <button type="button" onclick="applyTemplate('big_spender')"
                        class="p-3 border rounded-lg hover:bg-gray-50 text-left">
                        <span class="font-medium">💰 Big Spender</span>
                        <p class="text-xs text-gray-500">ยอดซื้อ 10,000+ บาท</p>
                    </button>
                </div>
            </div>

            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <h4 class="text-sm font-medium mb-3">🛠️ Custom Filters (เลือกอย่างน้อย 1 ข้อ)</h4>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">ยอดซื้อขั้นต่ำ (บาท)</label>
                        <input type="number" id="filter_min_spend" class="w-full px-3 py-2 border rounded text-sm"
                            placeholder="เช่น 1000">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">จำนวนออเดอร์ขั้นต่ำ</label>
                        <input type="number" id="filter_min_orders" class="w-full px-3 py-2 border rounded text-sm"
                            placeholder="เช่น 5">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Active ภายใน (วัน)</label>
                        <input type="number" id="filter_last_active" class="w-full px-3 py-2 border rounded text-sm"
                            placeholder="เช่น 30">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tier</label>
                        <select id="filter_tier" class="w-full px-3 py-2 border rounded text-sm">
                            <option value="">ทั้งหมด</option>
                            <option value="Bronze">Bronze</option>
                            <option value="Silver">Silver</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeCreateModal()"
                    class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-save mr-2"></i>สร้าง Segment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateModal() {
        document.getElementById('createModal').classList.remove('hidden');
        document.getElementById('createModal').classList.add('flex');
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.add('hidden');
        document.getElementById('createModal').classList.remove('flex');
    }

    function applyTemplate(type) {
        const templates = {
            'active': { name: 'Active Users', conditions: { 'last_activity_days': { '<=': 7 } } },
            'high_value': { name: 'High Value Customers', conditions: { 'total_spent': { '>=': 5000 } } },
            'at_risk': { name: 'At Risk Customers', conditions: { 'last_activity_days': { '>=': 30 } } },
            'big_spender': { name: 'Big Spender', conditions: { 'total_spent': { '>=': 10000 } } }
        };

        const tpl = templates[type];
        if (!tpl) return;

        document.querySelector('input[name="name"]').value = tpl.name;
        document.getElementById('conditionsJson').value = JSON.stringify(tpl.conditions);

        // Fill custom filters UI
        document.getElementById('filter_min_spend').value = '';
        document.getElementById('filter_min_orders').value = '';
        document.getElementById('filter_last_active').value = '';

        if (tpl.conditions.total_spent && tpl.conditions.total_spent['>=']) {
            document.getElementById('filter_min_spend').value = tpl.conditions.total_spent['>='];
        }
        if (tpl.conditions.last_activity_days && tpl.conditions.last_activity_days['<=']) {
            document.getElementById('filter_last_active').value = tpl.conditions.last_activity_days['<='];
        }
    }

    document.getElementById('createSegmentForm').addEventListener('submit', function (e) {
        let conditions = {};
        try {
            conditions = JSON.parse(document.getElementById('conditionsJson').value || '{}');
        } catch (e) { }

        // Check custom filters
        const minSpend = document.getElementById('filter_min_spend').value;
        const minOrders = document.getElementById('filter_min_orders').value;
        const lastActive = document.getElementById('filter_last_active').value;
        const tier = document.getElementById('filter_tier').value;

        if (minSpend) conditions.total_spent = { '>=': parseFloat(minSpend) };
        if (minOrders) conditions.order_count = { '>=': parseInt(minOrders) };
        if (lastActive) conditions.last_activity_days = { '<=': parseInt(lastActive) };
        if (tier) conditions.tier = { '=': tier };

        document.getElementById('conditionsJson').value = JSON.stringify(conditions);

        if (Object.keys(conditions).length === 0) {
            e.preventDefault();
            alert('กรุณาเลือกเงื่อนไขอย่างน้อย 1 ข้อ');
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>