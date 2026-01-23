<?php
/**
 * Auto Tag Rules - จัดการกฎติด Tag อัตโนมัติ
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/AutoTagManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Auto Tag Rules';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

$autoTagManager = new AutoTagManager($db, $currentBotId);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tagId = (int) $_POST['tag_id'];
        $ruleName = trim($_POST['rule_name']);
        $triggerType = $_POST['trigger_type'];
        $conditions = [];

        // สร้าง conditions ตาม trigger type
        switch ($triggerType) {
            case 'order_count':
                $conditions['min_orders'] = (int) $_POST['min_orders'];
                if (!empty($_POST['max_orders'])) {
                    $conditions['max_orders'] = (int) $_POST['max_orders'];
                }
                break;
            case 'total_spent':
                $conditions['min_amount'] = (float) $_POST['min_amount'];
                break;
            case 'inactivity':
                $conditions['days'] = (int) $_POST['inactive_days'];
                break;
            case 'birthday':
                $conditions['month'] = $_POST['birthday_month'] ?? 'current';
                break;
            case 'tier_upgrade':
                $conditions['old_tier'] = $_POST['old_tier'] ?? '';
                $conditions['new_tier'] = $_POST['new_tier'] ?? '';
                break;
            case 'point_milestone':
                $conditions['milestone'] = (int) $_POST['milestone_point'];
                break;
        }

        $autoTagManager->createRule($tagId, $ruleName, $triggerType, $conditions);
        header('Location: auto-tag-rules.php?success=created');
        exit;
    }

    if ($action === 'delete') {
        $ruleId = (int) $_POST['rule_id'];
        $autoTagManager->deleteRule($ruleId);
        header('Location: auto-tag-rules.php?success=deleted');
        exit;
    }

    if ($action === 'toggle') {
        $ruleId = (int) $_POST['rule_id'];
        $stmt = $db->prepare("UPDATE auto_tag_rules SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$ruleId]);
        header('Location: auto-tag-rules.php');
        exit;
    }
}

// ดึง rules ทั้งหมด
$rules = $autoTagManager->getRules();

// ดึง tags สำหรับ dropdown
$stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
$stmt->execute([$currentBotId]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="mb-6 flex justify-between items-center">
    <div>
        <h2 class="text-2xl font-bold">🤖 Auto Tag Rules</h2>
        <p class="text-gray-600">กฎสำหรับติด Tag อัตโนมัติตามพฤติกรรมลูกค้า</p>
    </div>
    <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
        <i class="fas fa-plus mr-2"></i>สร้าง Rule ใหม่
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
        <?php echo $_GET['success'] === 'created' ? '✅ สร้าง Rule สำเร็จ' : '✅ ลบ Rule สำเร็จ'; ?>
    </div>
<?php endif; ?>

<!-- Trigger Types Info -->
<div class="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
    <h4 class="font-semibold text-blue-800 mb-2">📋 ประเภท Trigger ที่รองรับ</h4>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-blue-600">follow</span>
            <p class="text-gray-500 text-xs">เมื่อ follow บอท</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-green-600">order_count</span>
            <p class="text-gray-500 text-xs">ตามจำนวน orders</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-purple-600">total_spent</span>
            <p class="text-gray-500 text-xs">ตามยอดซื้อรวม</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-orange-600">inactivity</span>
            <p class="text-gray-500 text-xs">ไม่มีกิจกรรม X วัน</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-pink-600">birthday</span>
            <p class="text-gray-500 text-xs">วันเกิดเดือนนี้</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-cyan-600">purchase</span>
            <p class="text-gray-500 text-xs">เมื่อซื้อสินค้า</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-red-600">message</span>
            <p class="text-gray-500 text-xs">เมื่อส่งข้อความ</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-indigo-600">tier_upgrade</span>
            <p class="text-gray-500 text-xs">เมื่อเลื่อน Tier</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-yellow-600">point_milestone</span>
            <p class="text-gray-500 text-xs">สะสมแต้มครบ</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-teal-600">video_call</span>
            <p class="text-gray-500 text-xs">ปรึกษาเภสัช</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-rose-600">referral</span>
            <p class="text-gray-500 text-xs">แนะนำเพื่อน</p>
        </div>
        <div class="bg-white p-2 rounded">
            <span class="font-medium text-gray-600">custom</span>
            <p class="text-gray-500 text-xs">กำหนดเอง</p>
        </div>
    </div>
</div>

<!-- Rules List -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rule</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trigger</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tag</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conditions</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($rules as $rule): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <p class="font-medium"><?php echo htmlspecialchars($rule['rule_name']); ?></p>
                        <p class="text-xs text-gray-500">ใช้งาน <?php echo number_format($rule['usage_count'] ?? 0); ?>
                            ครั้ง</p>
                    </td>
                    <td class="px-6 py-4">
                        <span
                            class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm"><?php echo $rule['trigger_type']; ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded text-sm"
                            style="background-color: <?php echo $rule['tag_color'] ?? '#3B82F6'; ?>20; color: <?php echo $rule['tag_color'] ?? '#3B82F6'; ?>">
                            <?php echo htmlspecialchars($rule['tag_name']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <?php
                        $conditions = json_decode($rule['conditions'], true) ?: [];
                        $conditionText = [];
                        foreach ($conditions as $key => $value) {
                            switch ($key) {
                                case 'min_orders':
                                    $conditionText[] = "≥ {$value} orders";
                                    break;
                                case 'max_orders':
                                    $conditionText[] = "≤ {$value} orders";
                                    break;
                                case 'min_amount':
                                    $conditionText[] = "≥ ฿" . number_format($value);
                                    break;
                                case 'days':
                                    $conditionText[] = "{$value} วัน";
                                    break;
                                case 'month':
                                    $conditionText[] = $value === 'current' ? 'เดือนปัจจุบัน' : "เดือน {$value}";
                                    break;
                                case 'old_tier':
                                    $conditionText[] = "จาก Tier: {$value}";
                                    break;
                                case 'new_tier':
                                    $conditionText[] = "เป็น Tier: {$value}";
                                    break;
                                case 'milestone':
                                    $conditionText[] = "แต้มครบ: " . number_format($value);
                                    break;
                            }
                        }
                        echo implode(', ', $conditionText) ?: '-';
                        ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                            <button type="submit"
                                class="px-3 py-1 rounded-full text-xs <?php echo $rule['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $rule['is_active'] ? 'Active' : 'Inactive'; ?>
                            </button>
                        </form>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <form method="POST" class="inline" onsubmit="return confirm('ลบ Rule นี้?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                            <button type="submit" class="text-red-500 hover:text-red-700">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($rules)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-robot text-4xl text-gray-300 mb-3 block"></i>
                        <p>ยังไม่มี Auto Tag Rules</p>
                        <button onclick="openModal()" class="mt-2 text-green-500 hover:underline">สร้าง Rule แรก</button>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Create Rule Modal -->
<div id="modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
        <form method="POST">
            <input type="hidden" name="action" value="create">

            <div class="p-6 border-b">
                <h3 class="text-xl font-semibold">🤖 สร้าง Auto Tag Rule</h3>
            </div>

            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อ Rule</label>
                    <input type="text" name="rule_name" required class="w-full px-4 py-2 border rounded-lg"
                        placeholder="เช่น ติด VIP เมื่อซื้อ 5 ครั้ง">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Tag ที่จะติด</label>
                    <select name="tag_id" required class="w-full px-4 py-2 border rounded-lg">
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Trigger Type</label>
                    <select name="trigger_type" id="triggerType" onchange="showConditionFields()" required
                        class="w-full px-4 py-2 border rounded-lg">
                        <option value="follow">follow - เมื่อ follow บอท</option>
                        <option value="order_count">order_count - ตามจำนวน orders</option>
                        <option value="total_spent">total_spent - ตามยอดซื้อรวม</option>
                        <option value="inactivity">inactivity - ไม่มีกิจกรรม X วัน</option>
                        <option value="birthday">birthday - วันเกิดเดือนนี้</option>
                        <option value="purchase">purchase - เมื่อซื้อสินค้า</option>
                        <option value="tier_upgrade">tier_upgrade - เมื่อเลื่อน Tier</option>
                        <option value="point_milestone">point_milestone - สะสมแต้มครบ</option>
                        <option value="video_call">video_call - ปรึกษาเภสัช</option>
                        <option value="referral">referral - แนะนำเพื่อน</option>
                    </select>
                </div>

                <!-- Condition Fields -->
                <div id="orderCountFields" class="hidden space-y-2">
                    <div>
                        <label class="block text-sm font-medium mb-1">จำนวน Orders ขั้นต่ำ</label>
                        <input type="number" name="min_orders" min="1" class="w-full px-4 py-2 border rounded-lg"
                            placeholder="เช่น 5">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">จำนวน Orders สูงสุด (ไม่บังคับ)</label>
                        <input type="number" name="max_orders" min="1" class="w-full px-4 py-2 border rounded-lg"
                            placeholder="เช่น 10">
                    </div>
                </div>

                <div id="totalSpentFields" class="hidden">
                    <label class="block text-sm font-medium mb-1">ยอดซื้อขั้นต่ำ (บาท)</label>
                    <input type="number" name="min_amount" min="0" step="0.01"
                        class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น 10000">
                </div>

                <div id="inactivityFields" class="hidden">
                    <label class="block text-sm font-medium mb-1">จำนวนวันที่ไม่มีกิจกรรม</label>
                    <input type="number" name="inactive_days" min="1" class="w-full px-4 py-2 border rounded-lg"
                        placeholder="เช่น 30">
                </div>

                <div id="birthdayFields" class="hidden">
                    <label class="block text-sm font-medium mb-1">เดือน</label>
                    <select name="birthday_month" class="w-full px-4 py-2 border rounded-lg">
                        <option value="current">เดือนปัจจุบัน</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div id="tierFields" class="hidden space-y-2">
                    <div>
                        <label class="block text-sm font-medium mb-1">จาก Tier (Optional)</label>
                        <select name="old_tier" class="w-full px-4 py-2 border rounded-lg">
                            <option value="">ทุก Tier</option>
                            <option value="Bronze">Bronze</option>
                            <option value="Silver">Silver</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เป็น Tier</label>
                        <select name="new_tier" class="w-full px-4 py-2 border rounded-lg">
                            <option value="Bronze">Bronze</option>
                            <option value="Silver">Silver</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                        </select>
                    </div>
                </div>

                <div id="pointFields" class="hidden">
                    <label class="block text-sm font-medium mb-1">แต้มสะสมครบ</label>
                    <input type="number" name="milestone_point" min="1" class="w-full px-4 py-2 border rounded-lg"
                        placeholder="เช่น 1000">
                </div>
            </div>

            <div class="p-6 border-t bg-gray-50 flex justify-end gap-2">
                <button type="button" onclick="closeModal()"
                    class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">สร้าง
                    Rule</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        document.getElementById('modal').classList.add('flex');
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modal').classList.remove('flex');
    }

    function showConditionFields() {
        const type = document.getElementById('triggerType').value;

        // ซ่อนทั้งหมดก่อน
        document.getElementById('orderCountFields').classList.add('hidden');
        document.getElementById('totalSpentFields').classList.add('hidden');
        document.getElementById('inactivityFields').classList.add('hidden');
        document.getElementById('birthdayFields').classList.add('hidden');
        document.getElementById('tierFields').classList.add('hidden');
        document.getElementById('pointFields').classList.add('hidden');

        // แสดงตาม type
        switch (type) {
            case 'order_count':
                document.getElementById('orderCountFields').classList.remove('hidden');
                break;
            case 'total_spent':
                document.getElementById('totalSpentFields').classList.remove('hidden');
                break;
            case 'inactivity':
                document.getElementById('inactivityFields').classList.remove('hidden');
                break;
            case 'birthday':
                document.getElementById('birthdayFields').classList.remove('hidden');
                break;
            case 'tier_upgrade':
                document.getElementById('tierFields').classList.remove('hidden');
                break;
            case 'point_milestone':
                document.getElementById('pointFields').classList.remove('hidden');
                break;
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>