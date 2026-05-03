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

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: users.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $displayName = trim($_POST['display_name'] ?? '');
        $realName = trim($_POST['real_name'] ?? '');
        $memberId = trim($_POST['member_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthday = $_POST['birthday'] ?: null;
        $gender = $_POST['gender'] ?: null;
        $address = trim($_POST['address'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $note = trim($_POST['note'] ?? '');

        $stmt = $db->prepare("UPDATE users SET 
            display_name = ?, real_name = ?, member_id = ?, phone = ?, email = ?, birthday = ?, gender = ?,
            address = ?, province = ?, postal_code = ?, note = ?
            WHERE id = ?");
        $stmt->execute([$displayName, $realName, $memberId ?: null, $phone, $email, $birthday, $gender, $address, $province, $postalCode, $note, $userId]);

        header("Location: user-detail.php?id={$userId}&updated=1");
        exit;
    }

    if ($action === 'add_points') {
        $points = (int) $_POST['points'];
        $description = trim($_POST['description'] ?? 'เพิ่มแต้มโดยแอดมิน');
        if ($points != 0) {
            try {
                require_once 'classes/LoyaltyPoints.php';
                require_once 'classes/TierService.php';
                $loyalty = new LoyaltyPoints($db, $currentBotId ?? 1);

                if ($points > 0) {
                    $loyalty->addPoints($userId, $points, 'admin', null, $description);
                } else {
                    $loyalty->deductPoints($userId, abs($points), 'admin_deduct', null, $description);
                }

                // Update user tier after points change
                $tierService = new TierService($db, $currentBotId ?? 1);
                $tierService->updateUserTier($userId);

            } catch (Exception $e) {
                error_log("Points adjustment error: " . $e->getMessage());
            }
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
    } catch (Exception $e2) {
    }
}

// Get all tags - ใช้ user_tags เป็นหลัก
$allTags = [];
try {
    $currentBotId = $_SESSION['current_bot_id'] ?? null;
    $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Get transactions (orders) - ใช้ transactions table
$transactions = [];
try {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Get messages count
$messageCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ?");
    $stmt->execute([$userId]);
    $messageCount = $stmt->fetchColumn();
} catch (Exception $e) {
}

// Calculate stats from transactions
$orderCount = 0;
$totalSpent = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total FROM transactions WHERE user_id = ? AND status NOT IN ('cancelled', 'pending')");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $orderCount = $stats['cnt'] ?? 0;
    $totalSpent = $stats['total'] ?? 0;
} catch (Exception $e) {
}

// Get loyalty points and tier
$points = ['available_points' => 0, 'total_points' => 0, 'used_points' => 0];
$pointsHistory = [];
$tier = ['name' => 'Member', 'icon' => '🥉', 'color' => '#9CA3AF'];
try {
    require_once 'classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, $currentBotId ?? 1);
    $points = $loyalty->getUserPoints($userId);
    $pointsHistory = $loyalty->getPointsHistory($userId, 5);

    // Get tier from unified TierService via LoyaltyPoints
    $tierInfo = $loyalty->getUserTier($userId);
    $tier = [
        'name' => $tierInfo['name'] ?? 'Member',
        'icon' => $tierInfo['icon'] ?? '🥉',
        'color' => $tierInfo['color'] ?? '#9CA3AF'
    ];
} catch (Exception $e) {
}

// Get shop name
$shopName = 'LINE Shop';
try {
    $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
    $s = $stmt->fetch();
    if ($s)
        $shopName = $s['shop_name'];
} catch (Exception $e) {
}

// Get LIFF health profile data (from customer_health_profiles table)
$liffHealthProfile = null;
try {
    require_once 'classes/CustomerHealthEngineService.php';
    $healthEngine = new CustomerHealthEngineService($db, $currentBotId ?? 1);
    $liffHealthProfile = $healthEngine->getHealthProfile($userId);
} catch (Exception $e) {
    error_log("Error loading LIFF health profile: " . $e->getMessage());
}

// ============ Odoo ERP Integration ============
$odooLinked = false;
$odooLinkData = null;
$odooProfile = null;
$odooOrders = [];
$odooOrdersTotal = 0;
$odooInvoices = [];
$odooInvoicesTotal = 0;
$odooInvoiceSummary = [
    'total' => 0,
    'paid' => 0,
    'unpaid' => 0,
    'overdue' => 0,
    'total_due' => 0,
    'overdue_amount' => 0
];
$odooCreditStatus = null;
$odooWebhooks = [];
$odooWebhooksCount = 0;
$odooError = null;
$odooOrdersError = null;
$odooWebhooksError = null;
$odooCustomer360 = null;
$odooLatestOrder = null;
$odooWebhookSummary = [
    'total' => 0,
    'success' => 0,
    'failed' => 0,
    'retry' => 0,
    'dead_letter' => 0,
    'duplicate' => 0,
    'last_event_at' => null
];
$odooFrequentProducts = [];

$lineUserId = $user['line_user_id'] ?? null;

if ($lineUserId) {
    // Check if linked in odoo_line_users
    try {
        $stmt = $db->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $odooLinkData = $stmt->fetch(PDO::FETCH_ASSOC);
        $odooLinked = !empty($odooLinkData);
    } catch (Exception $e) {
        // Table might not exist
    }

    if ($odooLinked) {
        // Fetch Customer 360 data from unified service
        try {
            require_once 'classes/OdooCustomerDashboardService.php';

            $odooService = new OdooCustomerDashboardService($db, $odooLinkData['line_account_id'] ?? ($user['line_account_id'] ?? null));
            $odooCustomer360 = $odooService->buildByLineUserId($lineUserId, [
                'orders_limit' => 10,
                'timeline_limit' => 20,
                'invoices_limit' => 10,
                'top_products' => 5
            ]);

            if (is_array($odooCustomer360)) {
                if (!empty($odooCustomer360['link']) && is_array($odooCustomer360['link'])) {
                    // Keep DB link as source of truth but allow enriched fields from service.
                    $odooLinkData = array_merge($odooLinkData ?: [], $odooCustomer360['link']);
                }

                $odooProfile = $odooCustomer360['profile'] ?? null;
                $odooCreditStatus = $odooCustomer360['credit'] ?? null;
                $odooLatestOrder = $odooCustomer360['latest_order'] ?? null;
                $odooFrequentProducts = is_array($odooCustomer360['frequent_products'] ?? null)
                    ? $odooCustomer360['frequent_products']
                    : [];

                $ordersPayload = $odooCustomer360['orders'] ?? [];
                $odooOrders = $ordersPayload['recent'] ?? [];
                $odooOrdersTotal = (int) ($ordersPayload['total'] ?? count($odooOrders));

                $invoicesPayload = $odooCustomer360['invoices'] ?? [];
                $odooInvoices = $invoicesPayload['recent'] ?? [];
                $odooInvoicesTotal = (int) ($invoicesPayload['total'] ?? count($odooInvoices));
                $odooInvoiceSummary = array_merge($odooInvoiceSummary, ['total' => $odooInvoicesTotal]);

                foreach ($odooInvoices as $invoice) {
                    $state = strtolower((string) ($invoice['state'] ?? ''));
                    $isPaid = $state === 'paid';
                    $isCancelled = in_array($state, ['cancel', 'cancelled'], true);
                    $isOverdue = !empty($invoice['is_overdue']);
                    $amountResidual = (float) ($invoice['amount_residual'] ?? 0);

                    if ($isPaid) {
                        $odooInvoiceSummary['paid'] += 1;
                        continue;
                    }

                    if ($isCancelled) {
                        continue;
                    }

                    $odooInvoiceSummary['unpaid'] += 1;
                    $odooInvoiceSummary['total_due'] += $amountResidual;

                    if ($isOverdue) {
                        $odooInvoiceSummary['overdue'] += 1;
                        $odooInvoiceSummary['overdue_amount'] += $amountResidual;
                    }
                }

                $timelinePayload = $odooCustomer360['timeline'] ?? [];
                $odooWebhooks = array_map(function ($row) {
                    return [
                        'event_type' => $row['event_type'] ?? null,
                        'status' => $row['status'] ?? null,
                        'error_message' => $row['error_message'] ?? null,
                        'order_name' => $row['order_name'] ?? null,
                        'new_state_display' => $row['state_display'] ?? null,
                        'amount_total' => $row['amount_total'] ?? null,
                        'processed_at' => $row['processed_at'] ?? null
                    ];
                }, is_array($timelinePayload) ? $timelinePayload : []);

                $summaryPayload = $odooCustomer360['webhook_summary'] ?? [];
                $odooWebhooksCount = (int) ($summaryPayload['total'] ?? count($odooWebhooks));
                $odooWebhookSummary = array_merge($odooWebhookSummary, is_array($summaryPayload) ? $summaryPayload : []);

                $warnings = $odooCustomer360['warnings'] ?? [];
                foreach ($warnings as $warning) {
                    if (strpos($warning, 'orders_api:') === 0 && $odooOrdersError === null) {
                        $odooOrdersError = trim(substr($warning, strlen('orders_api:')));
                    }
                    if (strpos($warning, 'timeline') !== false && $odooWebhooksError === null) {
                        $odooWebhooksError = $warning;
                    }
                }

                if (!empty($warnings) && $odooError === null) {
                    $odooError = implode(' | ', array_slice($warnings, 0, 2));
                }
            }
        } catch (Exception $e) {
            $odooError = $e->getMessage();
            error_log("Odoo customer360 error for user {$userId}: " . $e->getMessage());
        }
    }
}
?>

<div class="mb-6 flex items-center justify-between">
    <a href="users.php" class="text-gray-600 hover:text-gray-800 flex items-center gap-2">
        <i class="fas fa-arrow-left"></i> กลับไปหน้า Users
    </a>
    <a href="messages.php?user=<?= $userId ?>"
        class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
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
            <div class="p-6 text-center text-white"
                style="background: linear-gradient(135deg, <?= $tier['color'] ?> 0%, <?= $tier['color'] ?>dd 100%);">
                <p class="text-sm opacity-80"><?= htmlspecialchars($shopName) ?></p>
                <p class="text-xl font-bold mt-1"><?= $tier['icon'] ?> MEMBER CARD</p>
            </div>

            <div class="p-5 bg-gray-50 flex items-center gap-4">
                <div class="relative">
                    <img src="<?= $user['picture_url'] ?: 'https://via.placeholder.com/80' ?>"
                        class="w-20 h-20 rounded-full border-4 shadow" style="border-color: <?= $tier['color'] ?>">
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-800">
                        <?= htmlspecialchars($user['display_name'] ?: 'Unknown') ?>
                    </h2>
                    <p class="text-sm font-semibold" style="color: <?= $tier['color'] ?>"><?= $tier['icon'] ?>
                        <?= $tier['name'] ?>
                    </p>
                    <?php if (!empty($user['member_id'])): ?>
                        <p class="text-sm font-mono font-bold text-green-600"><?= htmlspecialchars($user['member_id']) ?>
                        </p>
                    <?php else: ?>
                        <p class="text-xs text-gray-500">ID: <?= str_pad($userId, 6, '0', STR_PAD_LEFT) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 bg-green-50 rounded-xl">
                        <p class="text-2xl font-bold text-green-600"><?= number_format($points['available_points']) ?>
                        </p>
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

                <p class="text-xs text-gray-400 text-center mt-3">สมาชิกตั้งแต่:
                    <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                </p>
            </div>
        </div>

        <!-- Add/Deduct Points -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">💎 จัดการแต้ม</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_points">
                <div>
                    <label class="text-sm text-gray-600">จำนวนแต้ม (ติดลบ = หักแต้ม)</label>
                    <input type="number" name="points" class="w-full px-4 py-2 border rounded-lg mt-1"
                        placeholder="เช่น 100 หรือ -50">
                </div>
                <div>
                    <label class="text-sm text-gray-600">หมายเหตุ</label>
                    <input type="text" name="description" class="w-full px-4 py-2 border rounded-lg mt-1"
                        placeholder="เหตุผล...">
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
                                <?= $h['type'] === 'earn' ? '+' : '-' ?>         <?= number_format(abs($h['points'])) ?>
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
                    <span class="font-bold" style="color: <?= $tier['color'] ?>"><?= $tier['icon'] ?>
                        <?= $tier['name'] ?></span>
                </div>
            </div>
        </div>

        <!-- Tags -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold mb-3">🏷️ Tags</h3>
            <div class="flex flex-wrap gap-2 mb-3">
                <?php foreach ($userTags as $tag): ?>
                    <span class="px-3 py-1 rounded-full text-sm text-white"
                        style="background-color: <?= $tag['color'] ?? '#6B7280' ?>">
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
                        <label class="block text-sm font-medium mb-1">ชื่อที่แสดง (Display Name)</label>
                        <input type="text" name="display_name"
                            value="<?= htmlspecialchars($user['display_name'] ?? '') ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                            placeholder="ชื่อที่แสดงในระบบ">
                        <p class="text-xs text-gray-400 mt-1">ชื่อที่แสดงในระบบ (สามารถแก้ไขได้)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อจริง</label>
                        <input type="text" name="real_name" value="<?= htmlspecialchars($user['real_name'] ?? '') ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                            placeholder="ชื่อ-นามสกุล">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">เลขสมาชิก (Member ID)</label>
                    <input type="text" name="member_id" value="<?= htmlspecialchars($user['member_id'] ?? '') ?>"
                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono"
                        placeholder="เช่น PC10000">
                    <p class="text-xs text-gray-400 mt-1">เลขสมาชิกที่กำหนดเอง สำหรับใช้อ้างอิงในระบบภายนอก</p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">เบอร์โทร</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                        placeholder="08x-xxx-xxxx">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">อีเมล</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                            placeholder="email@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">วันเกิด</label>
                        <input type="date" name="birthday" value="<?= $user['birthday'] ?? '' ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เพศ</label>
                        <select name="gender"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือก --</option>
                            <option value="male" <?= ($user['gender'] ?? '') === 'male' ? 'selected' : '' ?>>ชาย</option>
                            <option value="female" <?= ($user['gender'] ?? '') === 'female' ? 'selected' : '' ?>>หญิง
                            </option>
                            <option value="other" <?= ($user['gender'] ?? '') === 'other' ? 'selected' : '' ?>>อื่นๆ
                            </option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ที่อยู่</label>
                    <textarea name="address" rows="2"
                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                        placeholder="บ้านเลขที่ ซอย ถนน แขวง/ตำบล เขต/อำเภอ"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">จังหวัด</label>
                        <input type="text" name="province" value="<?= htmlspecialchars($user['province'] ?? '') ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                            placeholder="กรุงเทพมหานคร">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">รหัสไปรษณีย์</label>
                        <input type="text" name="postal_code"
                            value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                            placeholder="10xxx">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                    <textarea name="note" rows="2"
                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                        placeholder="บันทึกเพิ่มเติมเกี่ยวกับลูกค้า..."><?= htmlspecialchars($user['note'] ?? '') ?></textarea>
                </div>

                <button type="submit"
                    class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                    <i class="fas fa-save mr-2"></i>บันทึกข้อมูล
                </button>
            </form>
        </div>

        <!-- Health Info Section -->
        <?php
        // Check if we have LIFF health profile data
        $hasLiffHealth = !empty($liffHealthProfile) && (
            !empty($liffHealthProfile['allergies']) ||
            !empty($liffHealthProfile['medications']) ||
            !empty($liffHealthProfile['conditions']) ||
            !empty($liffHealthProfile['weight']) ||
            !empty($liffHealthProfile['height'])
        );

        // Fallback to users table data if no LIFF data
        $hasUserHealth = !empty($user['weight']) || !empty($user['height']) || !empty($user['medical_conditions']) || !empty($user['drug_allergies']);
        $hasHealthInfo = $hasLiffHealth || $hasUserHealth;

        // Use LIFF data if available, otherwise fall back to users table
        $displayWeight = $liffHealthProfile['weight'] ?? $user['weight'] ?? null;
        $displayHeight = $liffHealthProfile['height'] ?? $user['height'] ?? null;
        $displayBloodType = $liffHealthProfile['bloodType'] ?? $user['blood_type'] ?? null;
        ?>
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold">💊 ข้อมูลสุขภาพ (จาก LIFF)</h3>
                <?php if ($hasLiffHealth): ?>
                    <span class="px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full">
                        <i class="fas fa-check-circle mr-1"></i>อัพเดทจาก LIFF
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($hasHealthInfo): ?>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                    <div class="p-4 bg-blue-50 rounded-xl text-center">
                        <p class="text-2xl font-bold text-blue-600">
                            <?= $displayWeight ? number_format($displayWeight, 1) : '-' ?>
                        </p>
                        <p class="text-xs text-gray-500">น้ำหนัก (กก.)</p>
                    </div>
                    <div class="p-4 bg-green-50 rounded-xl text-center">
                        <p class="text-2xl font-bold text-green-600">
                            <?= $displayHeight ? number_format($displayHeight, 1) : '-' ?>
                        </p>
                        <p class="text-xs text-gray-500">ส่วนสูง (ซม.)</p>
                    </div>
                    <div class="p-4 bg-purple-50 rounded-xl text-center">
                        <?php
                        $bmi = '-';
                        $bmiClass = 'text-gray-600';
                        if (!empty($displayWeight) && !empty($displayHeight) && $displayHeight > 0) {
                            $heightM = $displayHeight / 100;
                            $bmiVal = $displayWeight / ($heightM * $heightM);
                            $bmi = number_format($bmiVal, 1);
                            if ($bmiVal < 18.5)
                                $bmiClass = 'text-blue-600';
                            elseif ($bmiVal < 25)
                                $bmiClass = 'text-green-600';
                            elseif ($bmiVal < 30)
                                $bmiClass = 'text-yellow-600';
                            else
                                $bmiClass = 'text-red-600';
                        }
                        ?>
                        <p class="text-2xl font-bold <?= $bmiClass ?>"><?= $bmi ?></p>
                        <p class="text-xs text-gray-500">BMI</p>
                    </div>
                    <div class="p-4 bg-pink-50 rounded-xl text-center">
                        <?php
                        $genderText = '-';
                        $genderIcon = '👤';
                        if (($user['gender'] ?? '') === 'male') {
                            $genderText = 'ชาย';
                            $genderIcon = '👨';
                        } elseif (($user['gender'] ?? '') === 'female') {
                            $genderText = 'หญิง';
                            $genderIcon = '👩';
                        } elseif (($user['gender'] ?? '') === 'other') {
                            $genderText = 'อื่นๆ';
                            $genderIcon = '🧑';
                        }
                        ?>
                        <p class="text-2xl"><?= $genderIcon ?></p>
                        <p class="text-xs text-gray-500"><?= $genderText ?></p>
                    </div>
                    <div class="p-4 bg-red-50 rounded-xl text-center">
                        <p class="text-2xl font-bold text-red-600"><?= $displayBloodType ?: '-' ?></p>
                        <p class="text-xs text-gray-500">กรุ๊ปเลือด</p>
                    </div>
                </div>

                <!-- Conditions from LIFF -->
                <?php
                $conditions = !empty($liffHealthProfile['conditions']) ? $liffHealthProfile['conditions'] : [];
                if (empty($conditions) && !empty($user['medical_conditions'])) {
                    $conditions = array_filter(array_map('trim', preg_split('/[,\n]+/', $user['medical_conditions'])));
                }
                if (!empty($conditions)):
                    ?>
                    <div class="mb-4 p-4 bg-orange-50 border border-orange-200 rounded-xl">
                        <p class="text-sm font-medium text-orange-700 mb-2"><i class="fas fa-heartbeat mr-1"></i>โรคประจำตัว</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($conditions as $condition): ?>
                                <span class="px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-sm">
                                    <?= htmlspecialchars($condition) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Odoo Invoice Summary -->
                <?php if ($odooInvoicesTotal > 0): ?>
                    <div class="mb-5">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-file-invoice-dollar mr-1"></i> สรุปใบแจ้งหนี้</h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                            <div class="p-3 bg-gray-50 rounded-xl text-center">
                                <p class="text-xl font-bold text-gray-700"><?= number_format($odooInvoiceSummary['total'] ?? 0) ?></p>
                                <p class="text-xs text-gray-500">ทั้งหมด</p>
                            </div>
                            <div class="p-3 bg-green-50 rounded-xl text-center">
                                <p class="text-xl font-bold text-green-600"><?= number_format($odooInvoiceSummary['paid'] ?? 0) ?></p>
                                <p class="text-xs text-gray-500">ชำระแล้ว</p>
                            </div>
                            <div class="p-3 bg-yellow-50 rounded-xl text-center">
                                <p class="text-xl font-bold text-yellow-600"><?= number_format($odooInvoiceSummary['unpaid'] ?? 0) ?></p>
                                <p class="text-xs text-gray-500">ค้างชำระ</p>
                            </div>
                            <div class="p-3 bg-red-50 rounded-xl text-center">
                                <p class="text-xl font-bold text-red-600"><?= number_format($odooInvoiceSummary['overdue'] ?? 0) ?></p>
                                <p class="text-xs text-gray-500">เกินกำหนด</p>
                            </div>
                            <div class="p-3 bg-orange-50 rounded-xl text-center">
                                <p class="text-lg font-bold text-orange-600">฿<?= number_format((float) ($odooInvoiceSummary['total_due'] ?? 0), 2) ?></p>
                                <p class="text-xs text-gray-500">ยอดค้างรวม</p>
                            </div>
                            <div class="p-3 bg-rose-50 rounded-xl text-center">
                                <p class="text-lg font-bold text-rose-600">฿<?= number_format((float) ($odooInvoiceSummary['overdue_amount'] ?? 0), 2) ?></p>
                                <p class="text-xs text-gray-500">ค้างเกินกำหนด</p>
                            </div>
                        </div>

                        <?php if (!empty($odooInvoices)): ?>
                            <div class="mt-4 space-y-2">
                                <?php
                                $invoiceStateColors = [
                                    'draft' => 'bg-gray-100 text-gray-600',
                                    'posted' => 'bg-blue-100 text-blue-700',
                                    'paid' => 'bg-green-100 text-green-700',
                                    'cancel' => 'bg-red-100 text-red-700',
                                    'cancelled' => 'bg-red-100 text-red-700'
                                ];
                                $invoiceStateLabels = [
                                    'draft' => 'ร่าง',
                                    'posted' => 'ออกแล้ว',
                                    'paid' => 'ชำระแล้ว',
                                    'cancel' => 'ยกเลิก',
                                    'cancelled' => 'ยกเลิก'
                                ];
                                foreach ($odooInvoices as $invoice):
                                    $invoiceState = strtolower((string) ($invoice['state'] ?? 'draft'));
                                    $invoiceBadge = $invoiceStateColors[$invoiceState] ?? 'bg-gray-100 text-gray-600';
                                    $invoiceLabel = $invoice['state_display'] ?? $invoiceStateLabels[$invoiceState] ?? $invoiceState;
                                    $invoiceNumber = $invoice['name'] ?? $invoice['invoice_number'] ?? $invoice['number'] ?? '-';
                                    $invoiceDate = $invoice['invoice_date'] ?? $invoice['date'] ?? null;
                                    $invoiceDue = $invoice['invoice_date_due'] ?? $invoice['due_date'] ?? null;
                                    $invoiceTotal = (float) ($invoice['amount_total'] ?? 0);
                                    $invoiceResidual = (float) ($invoice['amount_residual'] ?? 0);
                                    $invoiceOverdue = !empty($invoice['is_overdue']);
                                    $invoicePdf = $invoice['pdf_url'] ?? $invoice['pdf'] ?? null;
                                    ?>
                                    <div class="flex flex-wrap items-center justify-between gap-3 p-3 bg-gray-50 rounded-lg text-sm">
                                        <div class="min-w-[180px]">
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($invoiceNumber) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <?= $invoiceDate ? date('d/m/Y', strtotime($invoiceDate)) : '-' ?>
                                                <?php if ($invoiceDue): ?> · ครบกำหนด <?= date('d/m/Y', strtotime($invoiceDue)) ?><?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold text-green-600">฿<?= number_format($invoiceTotal, 2) ?></p>
                                            <p class="text-xs <?= $invoiceOverdue ? 'text-red-600' : 'text-gray-500' ?>">
                                                ค้าง ฿<?= number_format($invoiceResidual, 2) ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $invoiceBadge ?>">
                                                <?= htmlspecialchars($invoiceLabel) ?>
                                            </span>
                                            <?php if ($invoiceOverdue): ?>
                                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Overdue</span>
                                            <?php endif; ?>
                                            <?php if (!empty($invoicePdf)): ?>
                                                <a href="<?= htmlspecialchars($invoicePdf) ?>" target="_blank" rel="noopener"
                                                    class="text-xs text-blue-600 hover:underline">PDF</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Allergies from LIFF -->
                <?php
                $allergies = $liffHealthProfile['allergies'] ?? [];
                if (!empty($allergies)):
                    ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl">
                        <p class="text-sm font-medium text-red-700 mb-2"><i
                                class="fas fa-exclamation-triangle mr-1"></i>ยาที่แพ้</p>
                        <div class="space-y-2">
                            <?php foreach ($allergies as $allergy): ?>
                                <div class="flex items-center justify-between bg-red-100 px-3 py-2 rounded-lg">
                                    <span class="text-red-800 font-medium"><?= htmlspecialchars($allergy['name']) ?></span>
                                    <?php if (!empty($allergy['severity']) && $allergy['severity'] !== 'unknown'): ?>
                                        <span class="px-2 py-0.5 bg-red-200 text-red-700 text-xs rounded">
                                            <?= htmlspecialchars($allergy['severity']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif (!empty($user['drug_allergies'])): ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl">
                        <p class="text-sm font-medium text-red-700 mb-1"><i
                                class="fas fa-exclamation-triangle mr-1"></i>ยาที่แพ้</p>
                        <p class="text-gray-700"><?= nl2br(htmlspecialchars($user['drug_allergies'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Medications from LIFF -->
                <?php
                $medications = $liffHealthProfile['medications'] ?? [];
                if (!empty($medications)):
                    ?>
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl">
                        <p class="text-sm font-medium text-blue-700 mb-2"><i class="fas fa-pills mr-1"></i>ยาที่ใช้อยู่</p>
                        <div class="space-y-2">
                            <?php foreach ($medications as $med): ?>
                                <div class="flex items-center justify-between bg-blue-100 px-3 py-2 rounded-lg">
                                    <div>
                                        <span class="text-blue-800 font-medium"><?= htmlspecialchars($med['name']) ?></span>
                                        <?php if (!empty($med['dosage'])): ?>
                                            <span class="text-blue-600 text-sm ml-2"><?= htmlspecialchars($med['dosage']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($med['frequency'])): ?>
                                        <span class="px-2 py-0.5 bg-blue-200 text-blue-700 text-xs rounded">
                                            <?= htmlspecialchars($med['frequency']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-notes-medical text-4xl mb-3"></i>
                    <p>ยังไม่มีข้อมูลสุขภาพ</p>
                    <p class="text-sm mt-2">ลูกค้าสามารถกรอกข้อมูลสุขภาพผ่าน LIFF ได้</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Orders History from transactions -->
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold">🛒 ประวัติการสั่งซื้อ</h3>
                <a href="shop/orders.php?user=<?= $userId ?>" class="text-sm text-blue-600 hover:underline">ดูทั้งหมด
                    →</a>
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
                        <a href="shop/order-detail.php?id=<?= $order['id'] ?>"
                            class="block p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-semibold text-gray-800">
                                        #<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                                    </p>
                                    <?php if (!empty($order['shipping_name'])): ?>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <i class="fas fa-user mr-1"></i><?= htmlspecialchars($order['shipping_name']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-green-600 text-lg">
                                        ฿<?= number_format($order['grand_total'] ?? 0, 2) ?></p>
                                    <span
                                        class="inline-block mt-1 px-3 py-1 rounded-full text-xs font-medium <?= $statusColors[$status] ?? 'bg-gray-100' ?>">
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
                                <?php endif;
                            } catch (Exception $e) {
                            } ?>
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

        <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
        <!-- Odoo ERP Section -->
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold">🔗 Odoo ERP</h3>
                <?php if ($odooLinked): ?>
                    <span class="px-3 py-1 bg-green-100 text-green-700 text-xs rounded-full font-medium">
                        <i class="fas fa-check-circle mr-1"></i>เชื่อมต่อแล้ว
                    </span>
                <?php else: ?>
                    <span class="px-3 py-1 bg-gray-100 text-gray-500 text-xs rounded-full font-medium">
                        <i class="fas fa-unlink mr-1"></i>ยังไม่ลิงค์
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$lineUserId): ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-user-slash text-4xl mb-3"></i>
                    <p>ไม่มี LINE User ID</p>
                    <p class="text-sm mt-1">ผู้ใช้นี้ยังไม่ได้เชื่อมต่อ LINE</p>
                </div>

            <?php elseif (!$odooLinked): ?>
                <div class="text-center py-8">
                    <div class="w-20 h-20 bg-orange-50 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-link text-3xl text-orange-400"></i>
                    </div>
                    <p class="text-gray-600 font-medium text-lg mb-1">ยังไม่ลิงค์กับ Odoo</p>
                    <p class="text-gray-400 text-sm mb-4">ลูกค้ายังไม่ได้เชื่อมบัญชี LINE กับระบบ Odoo ERP</p>
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg text-sm text-gray-500">
                        <i class="fas fa-info-circle"></i>
                        ลูกค้าสามารถลิงค์ผ่าน LIFF ด้วยเบอร์โทร หรือรหัสลูกค้า
                    </div>
                </div>

            <?php else: ?>
                <!-- Odoo Partner Info -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                    <div class="p-3 bg-blue-50 rounded-xl text-center">
                        <p class="text-lg font-bold text-blue-700"><?= htmlspecialchars($odooLinkData['odoo_partner_name'] ?? '-') ?></p>
                        <p class="text-xs text-gray-500">ชื่อใน Odoo</p>
                    </div>
                    <div class="p-3 bg-indigo-50 rounded-xl text-center">
                        <p class="text-lg font-bold text-indigo-700 font-mono"><?= htmlspecialchars($odooLinkData['odoo_customer_code'] ?? '-') ?></p>
                        <p class="text-xs text-gray-500">รหัสลูกค้า</p>
                    </div>
                    <div class="p-3 bg-purple-50 rounded-xl text-center">
                        <p class="text-lg font-bold text-purple-700">#<?= htmlspecialchars($odooLinkData['odoo_partner_id'] ?? '-') ?></p>
                        <p class="text-xs text-gray-500">Partner ID</p>
                    </div>
                    <div class="p-3 bg-green-50 rounded-xl text-center">
                        <p class="text-lg font-bold text-green-700"><?= htmlspecialchars(ucfirst($odooLinkData['linked_via'] ?? '-')) ?></p>
                        <p class="text-xs text-gray-500">ลิงค์ผ่าน</p>
                    </div>
                </div>

                <!-- Odoo Profile Details (from API) -->
                <?php if ($odooProfile): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-5">
                        <?php if (!empty($odooProfile['phone'] ?? $odooProfile['mobile'] ?? $odooLinkData['odoo_phone'] ?? null)): ?>
                            <div class="p-3 bg-gray-50 rounded-lg flex items-center gap-3">
                                <i class="fas fa-phone text-gray-400"></i>
                                <div>
                                    <p class="text-xs text-gray-500">เบอร์โทร (Odoo)</p>
                                    <p class="font-medium"><?= htmlspecialchars($odooProfile['phone'] ?? $odooProfile['mobile'] ?? $odooLinkData['odoo_phone'] ?? '-') ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($odooProfile['email'] ?? $odooLinkData['odoo_email'] ?? null)): ?>
                            <div class="p-3 bg-gray-50 rounded-lg flex items-center gap-3">
                                <i class="fas fa-envelope text-gray-400"></i>
                                <div>
                                    <p class="text-xs text-gray-500">อีเมล (Odoo)</p>
                                    <p class="font-medium"><?= htmlspecialchars($odooProfile['email'] ?? $odooLinkData['odoo_email'] ?? '-') ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($odooProfile['credit_limit'])): ?>
                            <div class="p-3 bg-gray-50 rounded-lg flex items-center gap-3">
                                <i class="fas fa-credit-card text-gray-400"></i>
                                <div>
                                    <p class="text-xs text-gray-500">วงเงินเครดิต</p>
                                    <p class="font-medium text-blue-600">฿<?= number_format($odooProfile['credit_limit'], 2) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($odooProfile['total_due'])): ?>
                            <div class="p-3 bg-gray-50 rounded-lg flex items-center gap-3">
                                <i class="fas fa-file-invoice-dollar text-gray-400"></i>
                                <div>
                                    <p class="text-xs text-gray-500">ยอดค้างชำระ</p>
                                    <p class="font-medium <?= ($odooProfile['total_due'] ?? 0) > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                        ฿<?= number_format($odooProfile['total_due'] ?? 0, 2) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($odooError): ?>
                    <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700 mb-4">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        ไม่สามารถดึงข้อมูลจาก Odoo API: <?= htmlspecialchars($odooError) ?>
                    </div>
                <?php endif; ?>

                <!-- Credit Status -->
                <?php if ($odooCreditStatus): ?>
                    <div class="mb-5">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-chart-pie mr-1"></i> สถานะเครดิต</h4>
                        <div class="grid grid-cols-3 gap-3">
                            <?php if (isset($odooCreditStatus['credit_limit'])): ?>
                                <div class="p-3 bg-blue-50 rounded-xl text-center">
                                    <p class="text-xl font-bold text-blue-600">฿<?= number_format($odooCreditStatus['credit_limit'] ?? 0) ?></p>
                                    <p class="text-xs text-gray-500">วงเงิน</p>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($odooCreditStatus['credit_used'])): ?>
                                <div class="p-3 bg-orange-50 rounded-xl text-center">
                                    <p class="text-xl font-bold text-orange-600">฿<?= number_format($odooCreditStatus['credit_used'] ?? 0) ?></p>
                                    <p class="text-xs text-gray-500">ใช้ไป</p>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($odooCreditStatus['credit_remaining'])): ?>
                                <div class="p-3 bg-green-50 rounded-xl text-center">
                                    <p class="text-xl font-bold text-green-600">฿<?= number_format($odooCreditStatus['credit_remaining'] ?? 0) ?></p>
                                    <p class="text-xs text-gray-500">คงเหลือ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Customer 360: Latest Order Snapshot -->
                <?php if (!empty($odooLatestOrder)): ?>
                    <div class="mb-5 p-4 bg-indigo-50 border border-indigo-100 rounded-xl">
                        <div class="flex justify-between items-start mb-2">
                            <h4 class="text-sm font-semibold text-indigo-700"><i class="fas fa-bolt mr-1"></i> สถานะออเดอร์ล่าสุด</h4>
                            <?php
                            $latestState = strtolower((string) ($odooLatestOrder['state'] ?? ''));
                            $latestStateClass = 'bg-gray-100 text-gray-700';
                            if (in_array($latestState, ['sale', 'done', 'delivered'], true)) {
                                $latestStateClass = 'bg-green-100 text-green-700';
                            } elseif (in_array($latestState, ['picking', 'packing', 'to_delivery', 'in_delivery', 'processing'], true)) {
                                $latestStateClass = 'bg-blue-100 text-blue-700';
                            } elseif (in_array($latestState, ['cancel', 'failed', 'dead_letter'], true)) {
                                $latestStateClass = 'bg-red-100 text-red-700';
                            }
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $latestStateClass ?>">
                                <?= htmlspecialchars($odooLatestOrder['state_display'] ?? $odooLatestOrder['state'] ?? '-') ?>
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div class="p-3 bg-white rounded-lg">
                                <p class="text-gray-500 text-xs">เลขที่ออเดอร์</p>
                                <p class="font-semibold text-gray-800">
                                    <?= htmlspecialchars($odooLatestOrder['order_name'] ?? ('#' . ($odooLatestOrder['order_id'] ?? '-'))) ?>
                                </p>
                            </div>
                            <div class="p-3 bg-white rounded-lg">
                                <p class="text-gray-500 text-xs">ยอดรวม</p>
                                <p class="font-semibold text-green-600">
                                    ฿<?= number_format((float) ($odooLatestOrder['amount_total'] ?? 0), 2) ?>
                                </p>
                            </div>
                            <div class="p-3 bg-white rounded-lg">
                                <p class="text-gray-500 text-xs">เวลาอัปเดตล่าสุด</p>
                                <p class="font-semibold text-gray-700">
                                    <?= !empty($odooLatestOrder['date_order']) ? date('d/m/Y H:i', strtotime($odooLatestOrder['date_order'])) : '-' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Odoo Orders -->
                <div class="mb-5">
                    <div class="flex justify-between items-center mb-3">
                        <h4 class="text-sm font-semibold text-gray-700"><i class="fas fa-shopping-bag mr-1"></i> ออเดอร์ Odoo (<?= $odooOrdersTotal ?>)</h4>
                    </div>
                    <?php if ($odooOrdersError): ?>
                        <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700 mb-2">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            <?= htmlspecialchars($odooOrdersError) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($odooOrders)): ?>
                        <div class="space-y-2">
                            <?php
                            $odooStateColors = [
                                'draft' => 'bg-gray-100 text-gray-600',
                                'sent' => 'bg-blue-100 text-blue-700',
                                'sale' => 'bg-green-100 text-green-700',
                                'done' => 'bg-green-100 text-green-700',
                                'cancel' => 'bg-red-100 text-red-700',
                            ];
                            $odooStateLabels = [
                                'draft' => 'ร่าง',
                                'sent' => 'ส่งแล้ว',
                                'sale' => 'ยืนยัน',
                                'done' => 'เสร็จสิ้น',
                                'cancel' => 'ยกเลิก',
                            ];
                            foreach ($odooOrders as $oOrder):
                                $oState = $oOrder['state'] ?? $oOrder['new_state'] ?? 'draft';
                                $oStateColor = $odooStateColors[$oState] ?? 'bg-gray-100 text-gray-600';
                                $oStateLabel = $oOrder['state_display'] ?? $oOrder['new_state_display'] ?? $odooStateLabels[$oState] ?? $oState;
                                $oOrderDate = $oOrder['order_date'] ?? $oOrder['date_order'] ?? $oOrder['create_date'] ?? null;
                                $oExpected = $oOrder['expected_delivery'] ?? $oOrder['commitment_date'] ?? null;
                                $oItemsCount = $oOrder['items_count'] ?? $oOrder['order_lines_count'] ?? (is_array($oOrder['order_lines'] ?? null) ? count($oOrder['order_lines']) : null);
                            ?>
                                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition text-sm">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($oOrder['name'] ?? $oOrder['order_name'] ?? '#' . ($oOrder['id'] ?? $oOrder['order_id'] ?? '')) ?></p>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            <?= !empty($oOrderDate) ? date('d/m/Y H:i', strtotime($oOrderDate)) : '' ?>
                                            <?php if (!empty($oExpected)): ?> · ส่ง <?= date('d/m/Y', strtotime($oExpected)) ?><?php endif; ?>
                                        </p>
                                        <?php if (!empty($oItemsCount)): ?>
                                            <p class="text-xs text-gray-500 mt-0.5">สินค้า <?= number_format((int) $oItemsCount) ?> รายการ</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-green-600">฿<?= number_format($oOrder['amount_total'] ?? 0, 2) ?></p>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $oStateColor ?>">
                                            <?= htmlspecialchars($oStateLabel) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-400 text-sm">
                            <p>ยังไม่มีออเดอร์ใน Odoo</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Frequent Products -->
                <?php if (!empty($odooFrequentProducts)): ?>
                    <div class="mb-5">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-star mr-1"></i> สินค้าซื้อบ่อย (Customer 360)</h4>
                        <div class="space-y-2">
                            <?php foreach ($odooFrequentProducts as $idx => $fp): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg text-sm">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate">
                                            #<?= $idx + 1 ?> <?= htmlspecialchars($fp['product_name'] ?? '-') ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            จำนวน <?= number_format((float) ($fp['qty'] ?? 0), 2) ?>
                                            <?php if (!empty($fp['last_purchased_at'])): ?>
                                                · ล่าสุด <?= date('d/m/Y', strtotime($fp['last_purchased_at'])) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="font-semibold text-green-600">฿<?= number_format((float) ($fp['amount'] ?? 0), 2) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Webhook History -->
                <div>
                    <div class="flex justify-between items-center mb-3">
                        <h4 class="text-sm font-semibold text-gray-700"><i class="fas fa-satellite-dish mr-1"></i> Webhook Events (<?= $odooWebhooksCount ?>)</h4>
                        <a href="odoo-dashboard.php" class="text-xs text-blue-600 hover:underline">ดู Dashboard →</a>
                    </div>
                    <?php if ($odooWebhooksError): ?>
                        <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700 mb-2">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            <?= htmlspecialchars($odooWebhooksError) ?>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 md:grid-cols-6 gap-2 mb-3 text-xs">
                        <div class="p-2 bg-green-50 rounded-lg text-center">
                            <p class="text-gray-500">Success</p>
                            <p class="font-semibold text-green-700"><?= number_format((int) ($odooWebhookSummary['success'] ?? 0)) ?></p>
                        </div>
                        <div class="p-2 bg-red-50 rounded-lg text-center">
                            <p class="text-gray-500">Failed</p>
                            <p class="font-semibold text-red-700"><?= number_format((int) ($odooWebhookSummary['failed'] ?? 0)) ?></p>
                        </div>
                        <div class="p-2 bg-yellow-50 rounded-lg text-center">
                            <p class="text-gray-500">Retry</p>
                            <p class="font-semibold text-yellow-700"><?= number_format((int) ($odooWebhookSummary['retry'] ?? 0)) ?></p>
                        </div>
                        <div class="p-2 bg-red-50 rounded-lg text-center">
                            <p class="text-gray-500">DLQ</p>
                            <p class="font-semibold text-red-700"><?= number_format((int) ($odooWebhookSummary['dead_letter'] ?? 0)) ?></p>
                        </div>
                        <div class="p-2 bg-indigo-50 rounded-lg text-center">
                            <p class="text-gray-500">Duplicate</p>
                            <p class="font-semibold text-indigo-700"><?= number_format((int) ($odooWebhookSummary['duplicate'] ?? 0)) ?></p>
                        </div>
                        <div class="p-2 bg-gray-50 rounded-lg text-center">
                            <p class="text-gray-500">Last Event</p>
                            <p class="font-semibold text-gray-700 text-[11px]">
                                <?= !empty($odooWebhookSummary['last_event_at']) ? date('d/m H:i', strtotime($odooWebhookSummary['last_event_at'])) : '-' ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($odooWebhooks)): ?>
                        <div class="space-y-1.5 max-h-80 overflow-y-auto">
                            <?php
                            $eventIcons = [
                                'order.validated' => '✅', 'order.picker_assigned' => '👤', 'order.picking' => '📦',
                                'order.picked' => '✅', 'order.packing' => '📦', 'order.packed' => '✅',
                                'order.to_delivery' => '🚚', 'order.in_delivery' => '🚚', 'order.delivered' => '✅',
                                'delivery.departed' => '🚚', 'delivery.completed' => '✅',
                                'payment.confirmed' => '💰', 'payment.done' => '✅',
                                'invoice.created' => '📄', 'invoice.overdue' => '⚠️',
                            ];
                            foreach ($odooWebhooks as $wh):
                                $whIcon = $eventIcons[$wh['event_type']] ?? '📌';
                                $whStateDisplay = (!empty($wh['new_state_display']) && $wh['new_state_display'] !== 'null') ? $wh['new_state_display'] : (explode('.', $wh['event_type'])[1] ?? $wh['event_type']);
                                $whOrderName = (!empty($wh['order_name']) && $wh['order_name'] !== 'null') ? $wh['order_name'] : '';
                                $whAmount = (!empty($wh['amount_total']) && $wh['amount_total'] !== 'null') ? '฿' . number_format((float) $wh['amount_total']) : '';
                                $whStatusBg = $wh['status'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                            ?>
                                <div class="flex items-center justify-between p-2.5 bg-gray-50 rounded-lg text-xs hover:bg-gray-100 transition">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="text-base flex-shrink-0"><?= $whIcon ?></span>
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-800 truncate">
                                                <?= htmlspecialchars($whStateDisplay) ?>
                                                <?php if ($whOrderName): ?>
                                                    <span class="text-blue-600 ml-1"><?= htmlspecialchars($whOrderName) ?></span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-gray-400"><?= date('d/m H:i:s', strtotime($wh['processed_at'])) ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <?php if ($whAmount): ?>
                                            <span class="font-semibold text-gray-700"><?= $whAmount ?></span>
                                        <?php endif; ?>
                                        <span class="px-1.5 py-0.5 rounded-full <?= $whStatusBg ?> text-[10px]">
                                            <?= $wh['status'] === 'success' ? 'OK' : 'FAIL' ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-400 text-sm">
                            <p>ยังไม่มี Webhook Events</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Link Info Footer -->
                <div class="mt-4 pt-3 border-t border-gray-100 flex justify-between items-center text-xs text-gray-400">
                    <span>ลิงค์เมื่อ: <?= $odooLinkData['linked_at'] ? date('d/m/Y H:i', strtotime($odooLinkData['linked_at'])) : '-' ?></span>
                    <span>
                        แจ้งเตือน LINE: 
                        <?php if ($odooLinkData['line_notification_enabled'] ?? false): ?>
                            <span class="text-green-600 font-medium">เปิด</span>
                        <?php else: ?>
                            <span class="text-red-500 font-medium">ปิด</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Disconnect Section -->
                <div class="mt-4 pt-4 border-t border-red-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-700">ยกเลิกการเชื่อมต่อ Odoo</p>
                            <p class="text-xs text-gray-400 mt-0.5">ลบการเชื่อมโยงระหว่าง LINE และบัญชี Odoo ของลูกค้ารายนี้</p>
                        </div>
                        <button onclick="openOdooUnlinkModal()"
                            class="flex items-center gap-2 px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-lg text-sm font-medium transition">
                            <i class="fas fa-unlink"></i>
                            ยกเลิกเชื่อมต่อ
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Odoo Unlink Confirm Modal -->
        <?php if ($odooLinked && $lineUserId): ?>
        <div id="odooUnlinkModal"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
             style="display:none !important;">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                <!-- Header -->
                <div class="bg-red-50 px-6 py-4 border-b border-red-100 flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-unlink text-red-500 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-800">ยืนยันการยกเลิกเชื่อมต่อ</h3>
                        <p class="text-xs text-gray-500">การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
                    </div>
                </div>

                <!-- Body -->
                <div class="px-6 py-5">
                    <div class="bg-gray-50 rounded-xl p-4 mb-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">ชื่อ LINE</span>
                            <span class="font-medium text-gray-800"><?= htmlspecialchars($user['display_name'] ?? '-') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">ชื่อใน Odoo</span>
                            <span class="font-medium text-gray-800"><?= htmlspecialchars($odooLinkData['odoo_partner_name'] ?? '-') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">รหัสลูกค้า</span>
                            <span class="font-mono font-medium text-gray-800"><?= htmlspecialchars($odooLinkData['odoo_customer_code'] ?? '-') ?></span>
                        </div>
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-700 mb-4">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        หลังยกเลิก ลูกค้าจะไม่ได้รับแจ้งเตือนสถานะออเดอร์จาก Odoo อีกต่อไป จนกว่าจะเชื่อมต่อใหม่
                    </div>

                    <div id="odooUnlinkError"
                         class="hidden bg-red-50 border border-red-200 rounded-lg p-3 text-xs text-red-700 mb-4">
                        <i class="fas fa-times-circle mr-1"></i>
                        <span id="odooUnlinkErrorMsg"></span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 pb-5 flex gap-3">
                    <button onclick="closeOdooUnlinkModal()"
                        id="odooUnlinkCancelBtn"
                        class="flex-1 px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-sm font-medium transition">
                        ยกเลิก
                    </button>
                    <button onclick="confirmOdooUnlink()"
                        id="odooUnlinkConfirmBtn"
                        class="flex-1 px-4 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium transition flex items-center justify-center gap-2">
                        <i class="fas fa-unlink"></i>
                        <span id="odooUnlinkBtnText">ยืนยันยกเลิกเชื่อมต่อ</span>
                    </button>
                </div>
            </div>
        </div>

        <script>
        const _odooUnlinkLineUserId = <?= json_encode($lineUserId) ?>;

        function openOdooUnlinkModal() {
            const modal = document.getElementById('odooUnlinkModal');
            modal.style.removeProperty('display');
            modal.style.display = 'flex';
            document.getElementById('odooUnlinkError').classList.add('hidden');
        }

        function closeOdooUnlinkModal() {
            document.getElementById('odooUnlinkModal').style.display = 'none';
        }

        // Close on backdrop click
        document.getElementById('odooUnlinkModal').addEventListener('click', function(e) {
            if (e.target === this) closeOdooUnlinkModal();
        });

        async function confirmOdooUnlink() {
            const confirmBtn = document.getElementById('odooUnlinkConfirmBtn');
            const cancelBtn  = document.getElementById('odooUnlinkCancelBtn');
            const btnText    = document.getElementById('odooUnlinkBtnText');
            const errBox     = document.getElementById('odooUnlinkError');
            const errMsg     = document.getElementById('odooUnlinkErrorMsg');

            confirmBtn.disabled = true;
            cancelBtn.disabled  = true;
            btnText.textContent = 'กำลังดำเนินการ...';
            confirmBtn.querySelector('i').className = 'fas fa-spinner fa-spin';
            errBox.classList.add('hidden');

            try {
                const res = await fetch('api/odoo-user-link.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'unlink', line_user_id: _odooUnlinkLineUserId })
                });
                const json = await res.json();

                if (json.success) {
                    closeOdooUnlinkModal();
                    // Reload page to reflect unlinked state
                    window.location.reload();
                } else {
                    errMsg.textContent = json.error || 'เกิดข้อผิดพลาด';
                    errBox.classList.remove('hidden');
                    confirmBtn.disabled = false;
                    cancelBtn.disabled  = false;
                    btnText.textContent = 'ยืนยันยกเลิกเชื่อมต่อ';
                    confirmBtn.querySelector('i').className = 'fas fa-unlink';
                }
            } catch (err) {
                errMsg.textContent = 'ไม่สามารถเชื่อมต่อ server ได้: ' + err.message;
                errBox.classList.remove('hidden');
                confirmBtn.disabled = false;
                cancelBtn.disabled  = false;
                btnText.textContent = 'ยืนยันยกเลิกเชื่อมต่อ';
                confirmBtn.querySelector('i').className = 'fas fa-unlink';
            }
        }
        </script>
        <?php endif; ?>
        <?php endif; /* ODOO_INTEGRATION_ENABLED */ ?>

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