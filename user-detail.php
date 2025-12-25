<?php
/**
 * User Detail - รายละเอียดลูกค้าพร้อมข้อมูลจริง
 * เชื่อมกับ transactions, loyalty_points, member card
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'รายละเอียดลูกค้า';

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: users.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_info') {
        $realName = trim($_POST['real_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthday = $_POST['birthday'] ?: null;
        $gender = $_POST['gender'] ?: null;
        $address = trim($_POST['address'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $note = trim($_POST['note'] ?? '');
        
        $stmt = $db->prepare("UPDATE users SET 
            real_name = ?, phone = ?, email = ?, birthday = ?, gender = ?,
            address = ?, province = ?, postal_code = ?, note = ?
            WHERE id = ?");
        $stmt->execute([$realName, $phone, $email, $birthday, $gender, $address, $province, $postalCode, $note, $userId]);
        
        header("Location: user-detail.php?id={$userId}&updated=1");
        exit;
    }
    
    if ($action === 'add_points') {
        $points = (int)$_POST['points'];
        $description = trim($_POST['description'] ?? 'เพิ่มแต้มโดยแอดมิน');
        if ($points != 0) {
            try {
                require_once 'classes/LoyaltyPoints.php';
                $loyalty = new LoyaltyPoints($db, $currentBotId ?? 1);
                if ($points > 0) {
                    $loyalty->addPoints($userId, $points, 'admin', $description);
                } else {
                    $loyalty->usePoints($userId, abs($points), 'admin_deduct', $description);
                }
            } catch (Exception $e) {}
        }
        header("Location: user-detail.php?id={$userId}&points_updated=1");
        exit;
    }
}

include 'includes/header.php';

// Get user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit;
}

// Get user tags - ใช้ user_tag_assignments join กับ user_tags
$userTags = [];
try {
    // ลองใช้ user_tag_assignments ก่อน (ระบบหลัก)
    $stmt = $db->prepare("SELECT ut.*, uta.assigned_by, uta.created_at as assigned_at 
                          FROM user_tags ut 
                          JOIN user_tag_assignments uta ON ut.id = uta.tag_id 
                          WHERE uta.user_id = ?");
    $stmt->execute([$userId]);
    $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: ลองใช้ user_tags โดยตรง
    try {
        $stmt = $db->prepare("SELECT ut.* FROM user_tags ut 
                              JOIN user_tag_assignments uta ON ut.id = uta.tag_id 
                              WHERE uta.user_id = ?");
        $stmt->execute([$userId]);
        $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

// Get all tags - ใช้ user_tags เป็นหลัก
$allTags = [];
try {
    $currentBotId = $_SESSION['current_bot_id'] ?? null;
    $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get transactions (orders) - ใช้ transactions table
$transactions = [];
try {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get messages count
$messageCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ?");
    $stmt->execute([$userId]);
    $messageCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// Calculate stats from transactions
$orderCount = 0;
$totalSpent = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total FROM transactions WHERE user_id = ? AND status NOT IN ('cancelled', 'pending')");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $orderCount = $stats['cnt'] ?? 0;
    $totalSpent = $stats['total'] ?? 0;
} catch (Exception $e) {}

// Get loyalty points
$points = ['available_points' => 0, 'total_points' => 0, 'used_points' => 0];
$pointsHistory = [];
$tier = ['name' => 'Member', 'icon' => '🥉', 'color' => '#9CA3AF'];
try {
    require_once 'classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, $currentBotId ?? 1);
    $points = $loyalty->getUserPoints($userId);
    $pointsHistory = $loyalty->getPointsHistory($userId, 5);
    
    // Calculate tier
    $totalPts = $points['total_points'] ?? 0;
    if ($totalPts >= 10000) {
        $tier = ['name' => 'Platinum', 'icon' => '💎', 'color' => '#6366F1'];
    } elseif ($totalPts >= 5000) {
        $tier = ['name' => 'Gold', 'icon' => '🥇', 'color' => '#F59E0B'];
    } elseif ($totalPts >= 1000) {
        $tier = ['name' => 'Silver', 'icon' => '🥈', 'color' => '#6B7280'];
    }
} catch (Exception $e) {}

// Get shop name
$shopName = 'LINE Shop';
try {
    $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
    $s = $stmt->fetch();
    if ($s) $shopName = $s['shop_name'];
} catch (Exception $e) {}
?>

<div class="mb-6 flex items-center justify-between">
    <a href="users.php" class="text-gray-600 hover:text-gray-800 flex items-center gap-2">
        <i class="fas fa-arrow-left"></i> กลับไปหน้า Users
    </a>
    <a href="messages.php?user=<?= $userId ?>" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
        <i class="fas fa-comments mr-2"></i>แชท
    </a>
</div>

<?php if (isset($_GET['updated']) || isset($_GET['points_updated'])): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4">
    ✅ บันทึกสำเร็จ!
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column -->
    <div class="lg:col-span-1 space-y-6">
        
        <!-- Member Card -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="p-6 text-center text-white" style="background: linear-gradient(135deg, <?= $tier['color'] ?> 0%, <?= $tier['color'] ?>dd 100%);">
                <p class="text-sm opacity-80"><?= htmlspecialchars($shopName) ?></p>
                <p class="text-xl font-bold mt-1"><?= $tier['icon'] ?> MEMBER CARD</p>
            </div>
            
            <div class="p-5 bg-gray-50 flex items-center gap-4">
                <div class="relative">
                    <img src="<?= $user['picture_url'] ?: 'https://via.placeholder.com/80' ?>" class="w-20 h-20 rounded-full border-4 shadow" style="border-color: <?= $tier['color'] ?>">
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($user['display_name'] ?: 'Unknown') ?></h2>
                    <p class="text-sm font-semibold" style="color: <?= $tier['color'] ?>"><?= $tier['icon'] ?> <?= $tier['name'] ?></p>
                    <p class="text-xs text-gray-500">ID: <?= str_pad($userId, 6, '0', STR_PAD_LEFT) ?></p>
                </div>
            </div>
            
            <div class="p-5">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 bg-green-50 rounded-xl">
                        <p class="text-2xl font-bold text-green-600"><?= number_format($points['available_points']) ?></p>
                        <p class="text-xs text-gray-500">แต้มคงเหลือ</p>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-xl">
                        <p class="text-2xl font-bold text-blue-600"><?= number_format($points['total_points']) ?></p>
                        <p class="text-xs text-gray-500">สะสมทั้งหมด</p>
                    </div>
                    <div class="p-3 bg-red-50 rounded-xl">
                        <p class="text-2xl font-bold text-red-500"><?= number_format($points['used_points']) ?></p>
                        <p class="text-xs text-gray-500">ใช้ไปแล้ว</p>
                    </div>
                </div>
                
                <p class="text-xs text-gray-400 text-center mt-3">สมาชิกตั้งแต่: <?= date('d/m/Y', strtotime($user['created_at'])) ?></p>
            </div>
        </div>
        
        <!-- Add/Deduct Points -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">💎 จัดการแต้ม</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_points">
                <div>
                    <label class="text-sm text-gray-600">จำนวนแต้ม (ติดลบ = หักแต้ม)</label>
                    <input type="number" name="points" class="w-full px-4 py-2 border rounded-lg mt-1" placeholder="เช่น 100 หรือ -50">
                </div>
                <div>
                    <label class="text-sm text-gray-600">หมายเหตุ</label>
                    <input type="text" name="description" class="w-full px-4 py-2 border rounded-lg mt-1" placeholder="เหตุผล...">
                </div>
                <button type="submit" class="w-full py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                    <i class="fas fa-coins mr-2"></i>อัพเดทแต้ม
                </button>
            </form>
        </div>

        <!-- Points History -->
        <?php if (!empty($pointsHistory)): ?>
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">📊 ประวัติแต้ม</h3>
            <div class="space-y-2">
                <?php foreach ($pointsHistory as $h): ?>
                <div class="flex justify-between items-center p-2 bg-gray-50 rounded-lg text-sm">
                    <div>
                        <p class="text-gray-700"><?= htmlspecialchars(mb_substr($h['description'], 0, 25)) ?></p>
                        <p class="text-xs text-gray-400"><?= date('d/m H:i', strtotime($h['created_at'])) ?></p>
                    </div>
                    <span class="font-bold <?= $h['type'] === 'earn' ? 'text-green-600' : 'text-red-500' ?>">
                        <?= $h['type'] === 'earn' ? '+' : '-' ?><?= number_format(abs($h['points'])) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Stats Summary -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">📈 สรุปข้อมูล</h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">จำนวนออเดอร์</span>
                    <span class="font-bold text-blue-600"><?= number_format($orderCount) ?> รายการ</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">ยอดซื้อรวม</span>
                    <span class="font-bold text-green-600">฿<?= number_format($totalSpent, 2) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">ข้อความทั้งหมด</span>
                    <span class="font-bold text-purple-600"><?= number_format($messageCount) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">ระดับสมาชิก</span>
                    <span class="font-bold" style="color: <?= $tier['color'] ?>"><?= $tier['icon'] ?> <?= $tier['name'] ?></span>
                </div>
            </div>
        </div>
        
        <!-- Tags -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">🏷️ Tags</h3>
            <div class="flex flex-wrap gap-2 mb-3">
                <?php foreach ($userTags as $tag): ?>
                <span class="px-3 py-1 rounded-full text-sm text-white" style="background-color: <?= $tag['color'] ?? '#6B7280' ?>">
                    <?= htmlspecialchars($tag['name']) ?>
                </span>
                <?php endforeach; ?>
                <?php if (empty($userTags)): ?>
                <span class="text-gray-400 text-sm">ยังไม่มี Tags</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Edit Info Form -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold mb-4">📝 ข้อมูลลูกค้า</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_info">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อจริง</label>
                        <input type="text" name="real_name" value="<?= htmlspecialchars($user['real_name'] ?? '') ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="ชื่อ-นามสกุล">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เบอร์โทร</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="08x-xxx-xxxx">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">อีเมล</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="email@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">วันเกิด</label>
                        <input type="date" name="birthday" value="<?= $user['birthday'] ?? '' ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เพศ</label>
                        <select name="gender" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือก --</option>
                            <option value="male" <?= ($user['gender'] ?? '') === 'male' ? 'selected' : '' ?>>ชาย</option>
                            <option value="female" <?= ($user['gender'] ?? '') === 'female' ? 'selected' : '' ?>>หญิง</option>
                            <option value="other" <?= ($user['gender'] ?? '') === 'other' ? 'selected' : '' ?>>อื่นๆ</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ที่อยู่</label>
                    <textarea name="address" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" 
                              placeholder="บ้านเลขที่ ซอย ถนน แขวง/ตำบล เขต/อำเภอ"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">จังหวัด</label>
                        <input type="text" name="province" value="<?= htmlspecialchars($user['province'] ?? '') ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="กรุงเทพมหานคร">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">รหัสไปรษณีย์</label>
                        <input type="text" name="postal_code" value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="10xxx">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                    <textarea name="note" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" 
                              placeholder="บันทึกเพิ่มเติมเกี่ยวกับลูกค้า..."><?= htmlspecialchars($user['note'] ?? '') ?></textarea>
                </div>
                
                <button type="submit" class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                    <i class="fas fa-save mr-2"></i>บันทึกข้อมูล
                </button>
            </form>
        </div>
        
        <!-- Health Info Section -->
        <?php 
        $hasHealthInfo = !empty($user['weight']) || !empty($user['height']) || !empty($user['medical_conditions']) || !empty($user['drug_allergies']);
        ?>
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold mb-4">💊 ข้อมูลสุขภาพ</h3>
            
            <?php if ($hasHealthInfo): ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                <div class="p-4 bg-blue-50 rounded-xl text-center">
                    <p class="text-2xl font-bold text-blue-600"><?= $user['weight'] ? number_format($user['weight'], 1) : '-' ?></p>
                    <p class="text-xs text-gray-500">น้ำหนัก (กก.)</p>
                </div>
                <div class="p-4 bg-green-50 rounded-xl text-center">
                    <p class="text-2xl font-bold text-green-600"><?= $user['height'] ? number_format($user['height'], 1) : '-' ?></p>
                    <p class="text-xs text-gray-500">ส่วนสูง (ซม.)</p>
                </div>
                <div class="p-4 bg-purple-50 rounded-xl text-center">
                    <?php 
                    $bmi = '-';
                    $bmiClass = 'text-gray-600';
                    if (!empty($user['weight']) && !empty($user['height']) && $user['height'] > 0) {
                        $heightM = $user['height'] / 100;
                        $bmiVal = $user['weight'] / ($heightM * $heightM);
                        $bmi = number_format($bmiVal, 1);
                        if ($bmiVal < 18.5) $bmiClass = 'text-blue-600';
                        elseif ($bmiVal < 25) $bmiClass = 'text-green-600';
                        elseif ($bmiVal < 30) $bmiClass = 'text-yellow-600';
                        else $bmiClass = 'text-red-600';
                    }
                    ?>
                    <p class="text-2xl font-bold <?= $bmiClass ?>"><?= $bmi ?></p>
                    <p class="text-xs text-gray-500">BMI</p>
                </div>
                <div class="p-4 bg-pink-50 rounded-xl text-center">
                    <?php 
                    $genderText = '-';
                    $genderIcon = '👤';
                    if (($user['gender'] ?? '') === 'male') { $genderText = 'ชาย'; $genderIcon = '👨'; }
                    elseif (($user['gender'] ?? '') === 'female') { $genderText = 'หญิง'; $genderIcon = '👩'; }
                    elseif (($user['gender'] ?? '') === 'other') { $genderText = 'อื่นๆ'; $genderIcon = '🧑'; }
                    ?>
                    <p class="text-2xl"><?= $genderIcon ?></p>
                    <p class="text-xs text-gray-500"><?= $genderText ?></p>
                </div>
            </div>
            
            <?php if (!empty($user['medical_conditions'])): ?>
            <div class="mb-4 p-4 bg-orange-50 border border-orange-200 rounded-xl">
                <p class="text-sm font-medium text-orange-700 mb-1"><i class="fas fa-heartbeat mr-1"></i>โรคประจำตัว</p>
                <p class="text-gray-700"><?= nl2br(htmlspecialchars($user['medical_conditions'])) ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($user['drug_allergies'])): ?>
            <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                <p class="text-sm font-medium text-red-700 mb-1"><i class="fas fa-exclamation-triangle mr-1"></i>ยาที่แพ้</p>
                <p class="text-gray-700"><?= nl2br(htmlspecialchars($user['drug_allergies'])) ?></p>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="text-center py-8 text-gray-400">
                <i class="fas fa-notes-medical text-4xl mb-3"></i>
                <p>ยังไม่มีข้อมูลสุขภาพ</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Orders History from transactions -->
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold">🛒 ประวัติการสั่งซื้อ</h3>
                <a href="shop/orders.php?user=<?= $userId ?>" class="text-sm text-blue-600 hover:underline">ดูทั้งหมด →</a>
            </div>
            
            <?php if (!empty($transactions)): ?>
            <div class="space-y-3">
                <?php foreach ($transactions as $order): 
                    $statusColors = [
                        'pending' => 'bg-yellow-100 text-yellow-700',
                        'confirmed' => 'bg-blue-100 text-blue-700',
                        'paid' => 'bg-green-100 text-green-700',
                        'shipping' => 'bg-purple-100 text-purple-700',
                        'delivered' => 'bg-gray-100 text-gray-700',
                        'cancelled' => 'bg-red-100 text-red-700'
                    ];
                    $statusLabels = [
                        'pending' => 'รอยืนยัน',
                        'confirmed' => 'ยืนยันแล้ว',
                        'paid' => 'ชำระแล้ว',
                        'shipping' => 'กำลังส่ง',
                        'delivered' => 'ส่งแล้ว',
                        'cancelled' => 'ยกเลิก'
                    ];
                    $status = $order['status'] ?? 'pending';
                ?>
                <a href="shop/order-detail.php?id=<?= $order['id'] ?>" class="block p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="font-semibold text-gray-800">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></p>
                            <p class="text-xs text-gray-500 mt-1"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                            <?php if (!empty($order['shipping_name'])): ?>
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-user mr-1"></i><?= htmlspecialchars($order['shipping_name']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-green-600 text-lg">฿<?= number_format($order['grand_total'] ?? 0, 2) ?></p>
                            <span class="inline-block mt-1 px-3 py-1 rounded-full text-xs font-medium <?= $statusColors[$status] ?? 'bg-gray-100' ?>">
                                <?= $statusLabels[$status] ?? $status ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php 
                    // Get order items
                    try {
                        $stmtItems = $db->prepare("SELECT ti.*, p.name as product_name FROM transaction_items ti LEFT JOIN business_items p ON ti.product_id = p.id WHERE ti.transaction_id = ? LIMIT 3");
                        $stmtItems->execute([$order['id']]);
                        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($items)):
                    ?>
                    <div class="mt-3 pt-3 border-t border-gray-200">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($items as $item): ?>
                            <span class="px-2 py-1 bg-white rounded text-xs text-gray-600 border">
                                <?= htmlspecialchars($item['product_name'] ?? 'สินค้า') ?> x<?= $item['quantity'] ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; } catch (Exception $e) {} ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-10">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-shopping-cart text-2xl text-gray-300"></i>
                </div>
                <p class="text-gray-500">ยังไม่มีประวัติการสั่งซื้อ</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- LINE Info -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold mb-4"><i class="fab fa-line text-green-500 mr-2"></i>ข้อมูล LINE</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-gray-500 text-xs mb-1">LINE User ID</p>
                    <p class="font-mono text-xs break-all"><?= htmlspecialchars($user['line_user_id'] ?? '-') ?></p>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-gray-500 text-xs mb-1">Display Name</p>
                    <p class="font-medium"><?= htmlspecialchars($user['display_name'] ?? '-') ?></p>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-gray-500 text-xs mb-1">Status Message</p>
                    <p class="text-gray-700"><?= htmlspecialchars($user['status_message'] ?? '-') ?></p>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-gray-500 text-xs mb-1">เข้าร่วมเมื่อ</p>
                    <p class="font-medium"><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
