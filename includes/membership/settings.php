<?php
/**
 * Points Settings Tab Content - ตั้งค่าระบบแต้มสะสม
 * Part of membership.php consolidated page
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// This file is included from membership.php
// Variables available: $db, $lineAccountId, $adminId, $loyalty

// Check if required tables exist
$tablesExist = true;
$missingTables = [];

try {
    $db->query("SELECT 1 FROM points_settings LIMIT 1");
} catch (PDOException $e) {
    $tablesExist = false;
    $missingTables[] = 'points_settings';
}

if (!$tablesExist) {
    echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
    echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ต้องรัน Migration ก่อน</h2>';
    echo '<p class="text-yellow-700 mb-4">ตาราง ' . implode(', ', $missingTables) . ' ยังไม่มีในฐานข้อมูล กรุณารัน migration ก่อนใช้งาน</p>';
    echo '<a href="/install/run_loyalty_migration.php" class="inline-block px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">รัน Loyalty Migration</a>';
    echo '</div>';
    return;
}

// Get settings data
$settings = $loyalty->getSettings();

try {
    $stmt = $db->prepare("SELECT *, CASE WHEN NOW() BETWEEN start_date AND end_date AND is_active = 1 THEN 'active' WHEN start_date > NOW() AND is_active = 1 THEN 'upcoming' WHEN is_active = 0 THEN 'disabled' ELSE 'expired' END as status FROM points_campaigns WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY start_date DESC");
    $stmt->execute([$lineAccountId]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $campaigns = [];
}

try {
    $stmt = $db->prepare("SELECT cb.*, ic.name as category_name FROM category_points_bonus cb LEFT JOIN item_categories ic ON cb.category_id = ic.id WHERE cb.line_account_id = ? OR cb.line_account_id IS NULL ORDER BY cb.multiplier DESC");
    $stmt->execute([$lineAccountId]);
    $categoryBonuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categoryBonuses = [];
}

try {
    $stmt = $db->prepare("SELECT * FROM tier_settings WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY min_points ASC");
    $stmt->execute([$lineAccountId]);
    $tierSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tierSettings))
        $tierSettings = [['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0], ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5], ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0]];
} catch (Exception $e) {
    $tierSettings = [['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0], ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5], ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0]];
}

try {
    $stmt = $db->prepare("SELECT id, name FROM item_categories WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1 ORDER BY name ASC");
    $stmt->execute([$lineAccountId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

$settingsTab = $_GET['settings_tab'] ?? 'rules';
?>

<!-- Sub Tabs -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <a href="?tab=settings&settings_tab=rules"
            class="px-6 py-3 font-medium whitespace-nowrap <?= $settingsTab === 'rules' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>"><i
                class="fas fa-sliders-h mr-2"></i>กฎพื้นฐาน</a>
        <a href="?tab=settings&settings_tab=campaigns"
            class="px-6 py-3 font-medium whitespace-nowrap <?= $settingsTab === 'campaigns' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>"><i
                class="fas fa-bullhorn mr-2"></i>แคมเปญโบนัส<?php $activeCampaigns = array_filter($campaigns, fn($c) => $c['status'] === 'active');
                if (count($activeCampaigns) > 0): ?><span
                    class="ml-1 px-2 py-0.5 bg-green-500 text-white text-xs rounded-full"><?= count($activeCampaigns) ?></span><?php endif; ?></a>
        <a href="?tab=settings&settings_tab=categories"
            class="px-6 py-3 font-medium whitespace-nowrap <?= $settingsTab === 'categories' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>"><i
                class="fas fa-tags mr-2"></i>โบนัสหมวดหมู่</a>
        <a href="?tab=settings&settings_tab=tiers"
            class="px-6 py-3 font-medium whitespace-nowrap <?= $settingsTab === 'tiers' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>"><i
                class="fas fa-medal mr-2"></i>ระดับสมาชิก</a>
    </div>
</div>

<?php if ($settingsTab === 'rules'): ?>
    <!-- Rules Tab -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-gray-800"><i
                    class="fas fa-sliders-h mr-2 text-purple-500"></i>กฎการได้รับแต้มพื้นฐาน</h2>
            <p class="text-sm text-gray-500 mt-1">กำหนดอัตราการได้รับแต้มและเงื่อนไขพื้นฐาน</p>
        </div>
        <form id="rulesForm" class="p-6">
            <input type="hidden" name="settings_action" value="update_rules">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i
                            class="fas fa-coins text-yellow-500 mr-1"></i>อัตราการได้รับแต้ม</label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="points_per_baht" id="points_per_baht"
                            value="<?= htmlspecialchars($settings['points_per_baht'] ?? 0.001) ?>" step="0.000001"
                            min="0.000001" max="100"
                            class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <span class="text-gray-600">แต้ม ต่อ</span>
                        <input type="number" name="baht_per_point" id="baht_per_point"
                            value="<?= $settings['points_per_baht'] > 0 ? round(1 / $settings['points_per_baht']) : 1000 ?>"
                            step="1" min="1" max="10000"
                            class="w-28 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <span class="text-gray-600">บาท</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1"></i>ตัวอย่าง: 1 แต้ม ต่อ 1000
                        บาท = ซื้อ 5000 บาท ได้ 5 แต้ม</p>
                    <div class="mt-3 p-3 bg-white rounded border border-gray-200">
                        <div class="text-sm text-gray-600">ตัวอย่างการคำนวณ:</div>
                        <div class="text-lg font-semibold text-purple-600">ซื้อ 1,000 บาท = <span
                                id="previewPoints"><?= floor(1000 * ($settings['points_per_baht'] ?? 0.001)) ?></span> แต้ม
                        </div>
                        <div class="text-sm text-gray-500 mt-1">ซื้อ 5,000 บาท = <span
                                id="previewPoints5000"><?= floor(5000 * ($settings['points_per_baht'] ?? 0.001)) ?></span>
                            แต้ม</div>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i
                            class="fas fa-shopping-cart text-blue-500 mr-1"></i>ยอดขั้นต่ำเพื่อรับแต้ม</label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="min_order_for_points" id="min_order_for_points"
                            value="<?= htmlspecialchars($settings['min_order_for_points'] ?? 0) ?>" step="1" min="0"
                            max="100000"
                            class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <span class="text-gray-600">บาท</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1"></i>ตั้งค่า 0 = ไม่มีขั้นต่ำ
                        (ทุกออเดอร์ได้รับแต้ม)</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i
                            class="fas fa-calendar-times text-red-500 mr-1"></i>อายุแต้มสะสม</label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="points_expiry_days" id="points_expiry_days"
                            value="<?= htmlspecialchars($settings['points_expiry_days'] ?? 365) ?>" step="1" min="0"
                            max="3650"
                            class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <span class="text-gray-600">วัน</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1"></i>ตั้งค่า 0 =
                        แต้มไม่มีวันหมดอายุ</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i
                            class="fas fa-power-off text-green-500 mr-1"></i>สถานะระบบแต้ม</label>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="is_active" id="is_active" <?= ($settings['is_active'] ?? 1) ? 'checked' : '' ?> class="w-5 h-5 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                        <span class="ml-2 text-gray-700">เปิดใช้งานระบบแต้มสะสม</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-2"><i
                            class="fas fa-info-circle mr-1"></i>ปิดระบบจะหยุดการให้แต้มชั่วคราว (แต้มเดิมยังคงอยู่)</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit"
                    class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"><i
                        class="fas fa-save mr-2"></i>บันทึกการตั้งค่า</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow mt-6">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-gray-800"><i
                    class="fas fa-chart-bar mr-2 text-blue-500"></i>สรุปกฎการได้รับแต้มปัจจุบัน</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-purple-50 rounded-lg">
                    <div class="text-2xl font-bold text-purple-600">
                        <?= rtrim(rtrim(number_format($settings['points_per_baht'] ?? 1, 6), '0'), '.') ?>
                    </div>
                    <div class="text-sm text-gray-600">แต้ม/บาท</div>
                </div>
                <div class="text-center p-4 bg-blue-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600">
                        ฿<?= number_format($settings['min_order_for_points'] ?? 0) ?></div>
                    <div class="text-sm text-gray-600">ยอดขั้นต่ำ</div>
                </div>
                <div class="text-center p-4 bg-orange-50 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600"><?= ($settings['points_expiry_days'] ?? 365) ?> วัน
                    </div>
                    <div class="text-sm text-gray-600">อายุแต้ม</div>
                </div>
                <div class="text-center p-4 <?= ($settings['is_active'] ?? 1) ? 'bg-green-50' : 'bg-red-50' ?> rounded-lg">
                    <div
                        class="text-2xl font-bold <?= ($settings['is_active'] ?? 1) ? 'text-green-600' : 'text-red-600' ?>">
                        <?= ($settings['is_active'] ?? 1) ? 'เปิด' : 'ปิด' ?>
                    </div>
                    <div class="text-sm text-gray-600">สถานะระบบ</div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($settingsTab === 'campaigns'): ?>
    <!-- Campaigns Tab -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold text-gray-800"><i
                                class="fas fa-bullhorn mr-2 text-orange-500"></i>แคมเปญโบนัสแต้ม</h2>
                        <p class="text-sm text-gray-500 mt-1">จัดการแคมเปญโบนัสแต้มพิเศษ เช่น Double Points Weekend</p>
                    </div>
                    <button type="button" onclick="showCampaignModal()"
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm">
                        <i class="fas fa-plus mr-1"></i>สร้างแคมเปญ
                    </button>
                </div>
                <div class="p-4">
                    <?php if (empty($campaigns)): ?>
                        <div class="text-center py-12">
                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-bullhorn text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 mb-4">ยังไม่มีแคมเปญโบนัส</p>
                            <button type="button" onclick="showCampaignModal()"
                                class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm">
                                <i class="fas fa-plus mr-1"></i>สร้างแคมเปญแรก
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($campaigns as $campaign):
                                $statusColors = ['active' => 'bg-green-100 text-green-700', 'upcoming' => 'bg-blue-100 text-blue-700', 'disabled' => 'bg-gray-100 text-gray-600', 'expired' => 'bg-red-100 text-red-600'];
                                $statusLabels = ['active' => 'กำลังใช้งาน', 'upcoming' => 'เร็วๆ นี้', 'disabled' => 'ปิดใช้งาน', 'expired' => 'หมดอายุ'];
                                $statusClass = $statusColors[$campaign['status']] ?? 'bg-gray-100 text-gray-600';
                                $statusLabel = $statusLabels[$campaign['status']] ?? $campaign['status'];
                                ?>
                                <div
                                    class="border rounded-lg p-4 hover:shadow-md transition-shadow <?= $campaign['status'] === 'disabled' ? 'opacity-60' : '' ?>">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-2">
                                                <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($campaign['name']) ?>
                                                </h3>
                                                <span
                                                    class="px-2 py-0.5 text-xs rounded-full <?= $statusClass ?>"><?= $statusLabel ?></span>
                                            </div>
                                            <div class="flex items-center gap-4 text-sm text-gray-600">
                                                <span class="flex items-center gap-1"><i
                                                        class="fas fa-times text-purple-500"></i><strong
                                                        class="text-purple-600"><?= number_format($campaign['multiplier'], 1) ?>x</strong>
                                                    แต้ม</span>
                                                <span class="flex items-center gap-1"><i
                                                        class="fas fa-calendar text-gray-400"></i><?= date('d/m/Y', strtotime($campaign['start_date'])) ?>
                                                    - <?= date('d/m/Y', strtotime($campaign['end_date'])) ?></span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleCampaign(<?= $campaign['id'] ?>)"
                                                class="p-2 rounded-lg hover:bg-gray-100 transition-colors"
                                                title="<?= $campaign['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>">
                                                <i
                                                    class="fas fa-<?= $campaign['is_active'] ? 'toggle-on text-green-500' : 'toggle-off text-gray-400' ?> text-xl"></i>
                                            </button>
                                            <button type="button"
                                                onclick="editCampaign(<?= htmlspecialchars(json_encode($campaign)) ?>)"
                                                class="p-2 rounded-lg hover:bg-gray-100 transition-colors text-blue-600"
                                                title="แก้ไข"><i class="fas fa-edit"></i></button>
                                            <button type="button"
                                                onclick="deleteCampaign(<?= $campaign['id'] ?>, '<?= htmlspecialchars($campaign['name']) ?>')"
                                                class="p-2 rounded-lg hover:bg-red-50 transition-colors text-red-600" title="ลบ"><i
                                                    class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow p-4">
                <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-chart-pie mr-2 text-blue-500"></i>สรุปแคมเปญ
                </h3>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg"><span
                            class="text-sm text-gray-600">กำลังใช้งาน</span><span
                            class="font-bold text-green-600"><?= count(array_filter($campaigns, fn($c) => $c['status'] === 'active')) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg"><span
                            class="text-sm text-gray-600">เร็วๆ นี้</span><span
                            class="font-bold text-blue-600"><?= count(array_filter($campaigns, fn($c) => $c['status'] === 'upcoming')) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"><span
                            class="text-sm text-gray-600">หมดอายุ/ปิด</span><span
                            class="font-bold text-gray-600"><?= count(array_filter($campaigns, fn($c) => in_array($c['status'], ['expired', 'disabled']))) ?></span>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-purple-500 to-indigo-600 rounded-xl shadow p-4 text-white">
                <h3 class="font-semibold mb-2"><i class="fas fa-lightbulb mr-2"></i>เคล็ดลับ</h3>
                <ul class="text-sm space-y-2 opacity-90">
                    <li>• ใช้ 2x แต้มในช่วงวันหยุดยาว</li>
                    <li>• สร้างแคมเปญล่วงหน้าได้</li>
                    <li>• แคมเปญจะเปิดใช้งานอัตโนมัติตามวันที่กำหนด</li>
                </ul>
            </div>
        </div>
    </div>

<?php elseif ($settingsTab === 'categories'): ?>
    <!-- Categories Tab -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b">
                    <h2 class="font-semibold text-gray-800"><i
                            class="fas fa-tags mr-2 text-green-500"></i>โบนัสแต้มตามหมวดหมู่</h2>
                    <p class="text-sm text-gray-500 mt-1">กำหนดตัวคูณแต้มพิเศษสำหรับสินค้าแต่ละหมวดหมู่</p>
                </div>
                <div class="p-4">
                    <?php if (empty($categoryBonuses)): ?>
                        <div class="text-center py-12">
                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4"><i
                                    class="fas fa-tags text-gray-400 text-2xl"></i></div>
                            <p class="text-gray-500 mb-2">ยังไม่มีโบนัสหมวดหมู่</p>
                            <p class="text-sm text-gray-400">เพิ่มโบนัสจากฟอร์มด้านขวา</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($categoryBonuses as $bonus): ?>
                                <div
                                    class="border rounded-lg p-4 hover:shadow-md transition-shadow flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center"><i
                                                class="fas fa-tag text-green-600"></i></div>
                                        <div>
                                            <h3 class="font-medium text-gray-800">
                                                <?= htmlspecialchars($bonus['category_name'] ?? 'หมวดหมู่ #' . $bonus['category_id']) ?>
                                            </h3>
                                            <p class="text-sm text-gray-500">ID: <?= $bonus['category_id'] ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span
                                            class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-semibold"><?= number_format($bonus['multiplier'], 1) ?>x
                                            แต้ม</span>
                                        <button type="button"
                                            onclick="deleteCategoryBonus(<?= $bonus['id'] ?>, '<?= htmlspecialchars($bonus['category_name'] ?? '') ?>')"
                                            class="p-2 rounded-lg hover:bg-red-50 transition-colors text-red-600" title="ลบ"><i
                                                class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b">
                    <h3 class="font-semibold text-gray-800"><i
                            class="fas fa-plus-circle mr-2 text-purple-500"></i>เพิ่มโบนัสหมวดหมู่</h3>
                </div>
                <form id="categoryBonusForm" class="p-4">
                    <input type="hidden" name="settings_action" value="save_category_bonus">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">เลือกหมวดหมู่ <span
                                    class="text-red-500">*</span></label>
                            <select name="category_id" id="categorySelect" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <option value="">-- เลือกหมวดหมู่ --</option>
                                <?php foreach ($categories as $cat):
                                    $alreadyAdded = in_array($cat['id'], array_column($categoryBonuses, 'category_id')); ?>
                                    <option value="<?= $cat['id'] ?>" <?= $alreadyAdded ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>         <?= $alreadyAdded ? ' (มีโบนัสแล้ว)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($categories)): ?>
                                <p class="text-xs text-orange-500 mt-1"><i
                                        class="fas fa-exclamation-triangle mr-1"></i>ไม่พบหมวดหมู่สินค้า</p><?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ตัวคูณแต้ม <span
                                    class="text-red-500">*</span></label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="multiplier" id="categoryMultiplier" value="1.5" required
                                    min="1.1" max="10" step="0.1"
                                    class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <span class="text-gray-600">x แต้ม</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">เช่น 1.5x = ได้แต้มเพิ่ม 50%</p>
                        </div>
                    </div>
                    <button type="submit"
                        class="w-full mt-4 px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"><i
                            class="fas fa-plus mr-1"></i>เพิ่มโบนัส</button>
                </form>
            </div>
            <div class="bg-blue-50 rounded-xl p-4">
                <h4 class="text-sm font-medium text-blue-800 mb-2"><i class="fas fa-info-circle mr-1"></i>วิธีการทำงาน</h4>
                <ul class="text-xs text-blue-700 space-y-1">
                    <li>• โบนัสหมวดหมู่จะคูณกับอัตราพื้นฐาน</li>
                    <li>• สามารถใช้ร่วมกับแคมเปญโบนัสได้</li>
                    <li>• เหมาะสำหรับโปรโมทสินค้าเฉพาะกลุ่ม</li>
                </ul>
            </div>
        </div>
    </div>

<?php elseif ($settingsTab === 'tiers'): ?>
    <!-- Tiers Tab -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-gray-800"><i class="fas fa-medal mr-2 text-yellow-500"></i>ตั้งค่าระดับสมาชิก</h2>
            <p class="text-sm text-gray-500 mt-1">กำหนดคะแนนขั้นต่ำสำหรับแต่ละระดับสมาชิก</p>
        </div>
        <form id="tierSettingsForm" class="p-6">
            <input type="hidden" name="settings_action" value="save_tier_settings">
            <div class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-medium text-gray-700">ระดับสมาชิกและคะแนนขั้นต่ำ</h3>
                    <button type="button" id="addTierBtn"
                        class="px-3 py-1 text-sm bg-purple-100 text-purple-700 rounded-lg hover:bg-purple-200 transition-colors"><i
                            class="fas fa-plus mr-1"></i>เพิ่มระดับ</button>
                </div>
                <div id="tiersList" class="space-y-4">
                    <?php foreach ($tierSettings as $index => $tier): ?>
                        <div class="tier-row bg-gray-50 rounded-lg p-4 border border-gray-200" data-index="<?= $index ?>">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">ชื่อระดับ</label>
                                    <input type="text" name="tier_name[]" value="<?= htmlspecialchars($tier['name']) ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                        placeholder="เช่น Silver, Gold" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">คะแนนขั้นต่ำ</label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" name="tier_points[]" value="<?= intval($tier['min_points']) ?>"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            min="0" step="100" placeholder="0" required>
                                        <span class="text-gray-500 text-sm whitespace-nowrap">คะแนน</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">ตัวคูณแต้ม</label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" name="tier_multiplier[]"
                                            value="<?= number_format(floatval($tier['multiplier']), 2) ?>"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            min="1" max="10" step="0.1" placeholder="1.0" required>
                                        <span class="text-gray-500 text-sm">x</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">สีการ์ด</label>
                                    <input type="color" name="tier_color[]"
                                        value="<?= htmlspecialchars($tier['badge_color'] ?? '#6366F1') ?>"
                                        class="w-12 h-10 border border-gray-300 rounded-lg cursor-pointer"
                                        title="เลือกสีสำหรับการ์ดสมาชิก">
                                    <div class="flex items-center gap-2">
                                        <?php if ($index > 0): ?>
                                            <button type="button"
                                                class="delete-tier-btn px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                title="ลบระดับนี้"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <span class="px-3 py-2 text-gray-400" title="ระดับแรกไม่สามารถลบได้"><i
                                                    class="fas fa-lock"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($index === 0): ?>
                                    <p class="text-xs text-gray-500 mt-2"><i
                                            class="fas fa-info-circle mr-1"></i>ระดับแรกคือระดับเริ่มต้นสำหรับสมาชิกใหม่
                                        (คะแนนขั้นต่ำควรเป็น 0)</p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="bg-blue-50 rounded-lg p-4 mb-6">
                    <h4 class="text-sm font-medium text-blue-800 mb-2"><i
                            class="fas fa-lightbulb mr-1"></i>คำแนะนำการตั้งค่า
                    </h4>
                    <ul class="text-xs text-blue-700 space-y-1">
                        <li>• คะแนนขั้นต่ำต้องเรียงจากน้อยไปมาก (ระดับแรกควรเป็น 0)</li>
                        <li>• ตัวคูณแต้มจะใช้คำนวณแต้มที่ได้รับเพิ่มเติม (เช่น 1.5x = ได้แต้มเพิ่ม 50%)</li>
                        <li>• สมาชิกจะได้รับการอัพเกรดระดับอัตโนมัติเมื่อสะสมคะแนนถึงเกณฑ์</li>
                    </ul>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" id="resetTiersBtn"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"><i
                            class="fas fa-undo mr-2"></i>รีเซ็ตเป็นค่าเริ่มต้น</button>
                    <button type="submit"
                        class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"><i
                            class="fas fa-save mr-2"></i>บันทึกการตั้งค่า</button>
                </div>
        </form>
    </div>

    <!-- Current Tier Summary -->
    <div class="bg-white rounded-xl shadow mt-6">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-gray-800"><i
                    class="fas fa-chart-pie mr-2 text-green-500"></i>สรุประดับสมาชิกปัจจุบัน</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-<?= count($tierSettings) ?> gap-4">
                <?php $tierColors = ['#9CA3AF', '#F59E0B', '#6366F1', '#10B981', '#EF4444'];
                foreach ($tierSettings as $index => $tier):
                    $color = $tier['badge_color'] ?? ($tierColors[$index % count($tierColors)]);
                    $nextTier = isset($tierSettings[$index + 1]) ? $tierSettings[$index + 1] : null;
                    ?>
                    <div class="text-center p-4 rounded-lg border-2"
                        style="border-color: <?= $color ?>; background: <?= $color ?>10;">
                        <div class="w-12 h-12 mx-auto rounded-full flex items-center justify-center mb-2"
                            style="background: <?= $color ?>;"><i class="fas fa-medal text-white text-xl"></i></div>
                        <div class="font-bold text-lg" style="color: <?= $color ?>;"><?= htmlspecialchars($tier['name']) ?>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            <?= $index === 0 ? 'เริ่มต้น' : number_format($tier['min_points']) . '+ คะแนน' ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ตัวคูณ: <?= number_format($tier['multiplier'], 1) ?>x</div>
                        <?php if ($nextTier): ?>
                            <div class="mt-2 text-xs text-gray-400"><i class="fas fa-arrow-up mr-1"></i>อีก
                                <?= number_format($nextTier['min_points'] - $tier['min_points']) ?> คะแนน
                            </div>
                        <?php else: ?>
                            <div class="mt-2 text-xs text-green-600"><i class="fas fa-crown mr-1"></i>ระดับสูงสุด</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Campaign Modal -->
<div id="campaignModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800" id="campaignModalTitle">สร้างแคมเปญใหม่</h3>
            <button type="button" onclick="closeCampaignModal()"
                class="p-2 hover:bg-gray-100 rounded-lg transition-colors"><i
                    class="fas fa-times text-gray-500"></i></button>
        </div>
        <form id="campaignForm" class="p-4">
            <input type="hidden" name="settings_action" id="campaignAction" value="create_campaign">
            <input type="hidden" name="id" id="campaignId" value="">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อแคมเปญ <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="name" id="campaignName" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                        placeholder="เช่น Double Points Weekend">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ตัวคูณแต้ม <span
                            class="text-red-500">*</span></label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="multiplier" id="campaignMultiplier" value="2.0" required min="1.1"
                            max="10" step="0.1"
                            class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <span class="text-gray-600">x แต้ม</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">เช่น 2x = ได้แต้มเป็น 2 เท่า</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">วันเริ่มต้น <span
                                class="text-red-500">*</span></label>
                        <input type="date" name="start_date" id="campaignStartDate" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">วันสิ้นสุด <span
                                class="text-red-500">*</span></label>
                        <input type="date" name="end_date" id="campaignEndDate" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    </div>
                </div>
                <div>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="is_active" id="campaignIsActive" checked
                            class="w-5 h-5 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                        <span class="ml-2 text-gray-700">เปิดใช้งานแคมเปญ</span>
                    </label>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeCampaignModal()"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">ยกเลิก</button>
                <button type="submit"
                    class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"><i
                        class="fas fa-save mr-1"></i>บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Toast notification
    function showToast(message, type = 'info') {
        const existing = document.querySelector('.toast-notification');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = `toast-notification fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-[100] ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'} text-white`;
        toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle mr-2"></i>${message}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // Rules form
    document.getElementById('rulesForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';

        fetch('membership.php?tab=settings', { method: 'POST', body: formData })
            .then(r => {
                if (!r.ok) throw new Error('HTTP error ' + r.status);
                return r.text();
            })
            .then(text => {
                console.log('Response:', text); // Debug
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                } catch (e) {
                    console.error('JSON parse error:', e, text);
                    showToast('เกิดข้อผิดพลาด: ไม่สามารถอ่าน response ได้', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                showToast('เกิดข้อผิดพลาดในการบันทึก: ' + err.message, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
    });

    // Points preview
    document.getElementById('points_per_baht')?.addEventListener('input', function () {
        const value = parseFloat(this.value) || 0;
        if (value > 0) {
            document.getElementById('baht_per_point').value = Math.round(1 / value);
            document.getElementById('previewPoints').textContent = Math.floor(1000 * value);
            const preview5000 = document.getElementById('previewPoints5000');
            if (preview5000) preview5000.textContent = Math.floor(5000 * value);
        }
    });
    document.getElementById('baht_per_point')?.addEventListener('input', function () {
        const value = parseFloat(this.value) || 1;
        if (value > 0) {
            const ppb = 1 / value;
            document.getElementById('points_per_baht').value = ppb.toFixed(6);
            document.getElementById('previewPoints').textContent = Math.floor(1000 * ppb);
            const preview5000 = document.getElementById('previewPoints5000');
            if (preview5000) preview5000.textContent = Math.floor(5000 * ppb);
        }
    });

    // Campaign functions
    function showCampaignModal() {
        document.getElementById('campaignModalTitle').textContent = 'สร้างแคมเปญใหม่';
        document.getElementById('campaignAction').value = 'create_campaign';
        document.getElementById('campaignId').value = '';
        document.getElementById('campaignName').value = '';
        document.getElementById('campaignMultiplier').value = '2.0';
        document.getElementById('campaignStartDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('campaignEndDate').value = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
        document.getElementById('campaignIsActive').checked = true;
        document.getElementById('campaignModal').classList.remove('hidden');
    }
    function closeCampaignModal() { document.getElementById('campaignModal').classList.add('hidden'); }
    function editCampaign(campaign) {
        document.getElementById('campaignModalTitle').textContent = 'แก้ไขแคมเปญ';
        document.getElementById('campaignAction').value = 'update_campaign';
        document.getElementById('campaignId').value = campaign.id;
        document.getElementById('campaignName').value = campaign.name;
        document.getElementById('campaignMultiplier').value = campaign.multiplier;
        document.getElementById('campaignStartDate').value = campaign.start_date;
        document.getElementById('campaignEndDate').value = campaign.end_date;
        document.getElementById('campaignIsActive').checked = campaign.is_active == 1;
        document.getElementById('campaignModal').classList.remove('hidden');
    }
    function toggleCampaign(id) {
        const formData = new FormData();
        formData.append('settings_action', 'toggle_campaign');
        formData.append('id', id);
        fetch('membership.php?tab=settings&settings_tab=campaigns', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    }
    function deleteCampaign(id, name) {
        if (!confirm(`ต้องการลบแคมเปญ "${name}" หรือไม่?`)) return;
        const formData = new FormData();
        formData.append('settings_action', 'delete_campaign');
        formData.append('id', id);
        fetch('membership.php?tab=settings&settings_tab=campaigns', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    }
    document.getElementById('campaignForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('membership.php?tab=settings&settings_tab=campaigns', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); closeCampaignModal(); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    });

    // Category bonus
    document.getElementById('categoryBonusForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('membership.php?tab=settings&settings_tab=categories', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    });
    function deleteCategoryBonus(id, name) {
        if (!confirm(`ต้องการลบโบนัสหมวดหมู่ "${name || 'นี้'}" หรือไม่?`)) return;
        const formData = new FormData();
        formData.append('settings_action', 'delete_category_bonus');
        formData.append('id', id);
        fetch('membership.php?tab=settings&settings_tab=categories', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    }

    // Tier settings
    document.getElementById('tierSettingsForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('membership.php?tab=settings&settings_tab=tiers', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 500); } else { showToast(data.message || 'เกิดข้อผิดพลาด', 'error'); } })
            .catch(() => showToast('เกิดข้อผิดพลาด', 'error'));
    });
    document.getElementById('addTierBtn')?.addEventListener('click', function () {
        const tiersList = document.getElementById('tiersList');
        const newIndex = tiersList.querySelectorAll('.tier-row').length;
        const newRow = document.createElement('div');
        newRow.className = 'tier-row bg-gray-50 rounded-lg p-4 border border-gray-200';
        newRow.innerHTML = `<div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end"><div><label class="block text-xs font-medium text-gray-600 mb-1">ชื่อระดับ</label><input type="text" name="tier_name[]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="เช่น VIP, Diamond" required></div><div><label class="block text-xs font-medium text-gray-600 mb-1">คะแนนขั้นต่ำ</label><div class="flex items-center gap-2"><input type="number" name="tier_points[]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" min="0" step="100" placeholder="10000" required><span class="text-gray-500 text-sm whitespace-nowrap">คะแนน</span></div></div><div><label class="block text-xs font-medium text-gray-600 mb-1">ตัวคูณแต้ม</label><div class="flex items-center gap-2"><input type="number" name="tier_multiplier[]" value="1.00" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" min="1" max="10" step="0.1" placeholder="2.5" required><span class="text-gray-500 text-sm">x</span></div></div><div class="flex items-center gap-2"><button type="button" class="delete-tier-btn px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="ลบระดับนี้"><i class="fas fa-trash"></i></button></div></div>`;
        tiersList.appendChild(newRow);
        newRow.querySelector('.delete-tier-btn').addEventListener('click', function () { if (confirm('ต้องการลบระดับนี้หรือไม่?')) this.closest('.tier-row').remove(); });
    });
    document.querySelectorAll('.delete-tier-btn').forEach(btn => btn.addEventListener('click', function () { if (confirm('ต้องการลบระดับนี้หรือไม่?')) this.closest('.tier-row').remove(); }));
</script>