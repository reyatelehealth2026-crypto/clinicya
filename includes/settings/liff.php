<?php
/**
 * LIFF Settings Tab
 * ตั้งค่า LIFF ครบทุกฟังก์ชัน
 */

// Get all LINE accounts
$accounts = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC")->fetchAll(PDO::FETCH_ASSOC);
$currentAccount = $accounts[0] ?? null;

// Get config LIFF IDs
$configLiffId = defined('LIFF_ID') ? LIFF_ID : '';
$configLiffShareId = defined('LIFF_SHARE_ID') ? LIFF_SHARE_ID : '';
$baseUrl = rtrim(BASE_URL, '/');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account_liff') {
    $accountId = intval($_POST['account_id']);
    $liffId = trim($_POST['liff_id']);                          // legacy /liff/ SPA → line_accounts.liff_id
    $miniappLiffId = trim($_POST['miniapp_liff_id'] ?? '');     // new /miniapp app → routing table
    try {
        $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE id = ?")->execute([$liffId, $accountId]);
        // Mini app liff_id is stored in the platform routing table, NOT in line_accounts —
        // so the two LIFF apps never overwrite each other's id (coexistence, no collision).
        $miniappSaved = liff_save_miniapp_route_id($accountId, $miniappLiffId);
        $success = $miniappSaved === false
            ? "บันทึก LIFF เก่าสำเร็จ — แต่ LIFF mini app ยังบันทึกไม่ได้ (ตรวจ routing table/สิทธิ์ tenant)"
            : "บันทึก LIFF ID สำเร็จ!";
        // Refresh accounts
        $accounts = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC")->fetchAll(PDO::FETCH_ASSOC);
        $currentAccount = $accounts[0] ?? null;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * Write the mini app's liff_id onto the platform routing table for the current tenant.
 * Returns true on success, false if the route table/tenant context is unavailable,
 * null if nothing to do. Never throws — legacy liff_id is always saved regardless.
 */
function liff_save_miniapp_route_id(int $accountId, string $miniappLiffId)
{
    if (!class_exists('TenantContext') || !class_exists('Database')) return null;
    try {
        $tenantId = (int) (TenantContext::getCurrentTenantId() ?? 0);
        if ($tenantId <= 0) return false;
        $platform = Database::platform()->getConnection();
        $upd = $platform->prepare(
            "UPDATE tenant_line_account_routes SET liff_id = ? WHERE line_account_id = ? AND tenant_id = ?"
        );
        $upd->execute([$miniappLiffId !== '' ? $miniappLiffId : null, $accountId, $tenantId]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

// Load mini app liff_ids (routing table) keyed by line_account_id so each row can show both fields.
$miniappLiffByAccount = [];
$routeTableReady = false;
if (class_exists('TenantContext') && class_exists('Database')) {
    try {
        $tenantId = (int) (TenantContext::getCurrentTenantId() ?? 0);
        if ($tenantId > 0) {
            $platform = Database::platform()->getConnection();
            $rs = $platform->prepare(
                "SELECT line_account_id, liff_id FROM tenant_line_account_routes WHERE tenant_id = ?"
            );
            $rs->execute([$tenantId]);
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $miniappLiffByAccount[(int) $r['line_account_id']] = (string) ($r['liff_id'] ?? '');
            }
            $routeTableReady = true;
        }
    } catch (\Throwable $e) {
        $routeTableReady = false; // pre-migration / no platform DB → hide mini app field gracefully
    }
}
?>

<style>
    .liff-card {
        transition: all 0.2s;
    }

    .liff-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .copy-btn:active {
        transform: scale(0.95);
    }
</style>

<div class="space-y-6">
    <!-- Unified LIFF Recommendation -->
    <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-start gap-4">
            <div class="text-4xl">🚀</div>
            <div class="flex-1">
                <h2 class="text-xl font-bold mb-2">แนะนำ: Unified LIFF App (ระบบใหม่)</h2>
                <p class="text-green-100 mb-4">ใช้ LIFF ID เดียวสำหรับทุกฟังก์ชัน! ง่ายต่อการจัดการ ไม่ต้องสร้างหลาย
                    LIFF</p>
                <div class="bg-white/20 rounded-lg p-4 mb-4">
                    <p class="text-sm font-medium mb-2">Endpoint URL สำหรับสร้าง LIFF (ระบบใหม่):</p>
                    <div class="flex gap-2">
                        <input type="text" value="<?= $baseUrl ?>/miniapp/" readonly
                            class="flex-1 px-3 py-2 bg-white/30 rounded-lg font-mono text-sm" onclick="this.select()">
                        <button onclick="copyLiffText('<?= $baseUrl ?>/miniapp/')"
                            class="px-4 py-2 bg-white/30 hover:bg-white/40 rounded-lg">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs">
                    <span class="bg-white/20 px-2 py-1 rounded">✅ หน้าหลัก</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ร้านค้า</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ตะกร้า</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ออเดอร์</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ โปรไฟล์</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ นัดหมาย</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ วิดีโอคอล</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ AI Chat</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ แต้มสะสม</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ Health Profile</span>
                </div>
            </div>
        </div>
    </div>

    <!-- LIFF Status Overview -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center">
            <span class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">📱</span>
            สถานะ LIFF
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div
                class="bg-purple-50 rounded-lg p-4 border-2 <?= !empty($currentAccount['liff_id'] ?? '') ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">🛒</div>
                <h3 class="font-semibold">LIFF Shop</h3>
                <p class="text-sm text-gray-600">หน้าร้านค้า+Checkout</p>
                <p
                    class="text-xs mt-2 <?= !empty($currentAccount['liff_id'] ?? '') ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($currentAccount['liff_id'] ?? '') ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4 border-2 border-gray-200">
                <div class="text-2xl mb-2">💳</div>
                <h3 class="font-semibold">Checkout</h3>
                <p class="text-sm text-gray-600">ใช้ร่วมกับ Shop</p>
                <p class="text-xs mt-2 text-blue-500">ไม่ต้องสร้างแยก</p>
            </div>
            <div
                class="bg-green-50 rounded-lg p-4 border-2 <?= !empty($configLiffId) ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">📹</div>
                <h3 class="font-semibold">Video Call</h3>
                <p class="text-sm text-gray-600">วิดีโอคอลใน LINE</p>
                <p class="text-xs mt-2 <?= !empty($configLiffId) ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($configLiffId) ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
            <div
                class="bg-orange-50 rounded-lg p-4 border-2 <?= !empty($configLiffShareId) ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">📤</div>
                <h3 class="font-semibold">Share</h3>
                <p class="text-sm text-gray-600">แชร์ข้อความ</p>
                <p class="text-xs mt-2 <?= !empty($configLiffShareId) ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($configLiffShareId) ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- LIFF IDs per Account -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-indigo-500 to-purple-600 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fab fa-line mr-2"></i>LIFF ID ตามบัญชี LINE
            </h2>
            <p class="text-indigo-100 text-sm mt-1">แต่ละบัญชีตั้ง LIFF ได้ 2 ตัวพร้อมกัน — <b>เก่า (/liff)</b> + <b>mini app ใหม่ (/miniapp)</b> อยู่คู่กันไม่ชนกัน</p>
        </div>
        <div class="p-5">
            <?php if (empty($accounts)): ?>
                <p class="text-gray-500 text-center py-8">ยังไม่มีบัญชี LINE <a href="settings.php?tab=line"
                        class="text-blue-500 hover:underline">เพิ่มบัญชี</a></p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($accounts as $acc): ?>
                        <form method="POST" class="liff-card border rounded-xl p-4">
                            <input type="hidden" name="action" value="update_account_liff">
                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-3">
                                    <?php if (!empty($acc['picture_url'])): ?>
                                        <img src="<?= htmlspecialchars($acc['picture_url']) ?>" class="w-10 h-10 rounded-full">
                                    <?php else: ?>
                                        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                                            <i class="fab fa-line text-green-500"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h3 class="font-semibold"><?= htmlspecialchars($acc['name']) ?></h3>
                                        <p class="text-xs text-gray-500">
                                            <?= htmlspecialchars($acc['basic_id'] ?: 'Channel: ' . $acc['channel_id']) ?></p>
                                    </div>
                                </div>
                                <?php if ($acc['is_default']): ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full">⭐ หลัก</span>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-xs font-medium text-purple-700 mb-1">
                                        🛒 LIFF เก่า <span class="text-gray-400">(/liff — SPA เดิม)</span>
                                    </label>
                                    <input type="text" name="liff_id" value="<?= htmlspecialchars($acc['liff_id'] ?? '') ?>"
                                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 font-mono text-sm"
                                        placeholder="LIFF ID เก่า เช่น 2001234567-aBcDeFgH">
                                </div>
                                <?php if ($routeTableReady): ?>
                                <div>
                                    <label class="block text-xs font-medium text-green-700 mb-1">
                                        🚀 LIFF mini app ใหม่ <span class="text-gray-400">(/miniapp — routing table)</span>
                                    </label>
                                    <input type="text" name="miniapp_liff_id" value="<?= htmlspecialchars($miniappLiffByAccount[(int) $acc['id']] ?? '') ?>"
                                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono text-sm"
                                        placeholder="LIFF ID ของ mini app ใหม่ (แยกจากตัวเก่า)">
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="miniapp_liff_id" value="">
                                <p class="text-xs text-amber-600">⚠️ routing table ยังไม่พร้อม — ตั้งค่า LIFF mini app ไม่ได้ (รัน migration_2026-06-02_route_liff_id.sql ก่อน)</p>
                                <?php endif; ?>
                                <div class="flex justify-end">
                                    <button type="submit" class="px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600">
                                        <i class="fas fa-save mr-1"></i> บันทึกทั้ง 2 ตัว
                                    </button>
                                </div>
                            </div>
                            <?php if (!empty($acc['liff_id'])): ?>
                                <div class="mt-2 flex items-center gap-2 text-sm">
                                    <span class="text-gray-500">URL:</span>
                                    <a href="https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>" target="_blank"
                                        class="text-blue-500 hover:underline font-mono text-xs">
                                        https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>
                                    </a>
                                    <button type="button"
                                        onclick="copyLiffText('https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>')"
                                        class="copy-btn text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Global LIFF IDs (config.php) -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-orange-500 to-red-500 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-cog mr-2"></i>LIFF ID ใน config.php (Video Call & Share)
            </h2>
            <p class="text-orange-100 text-sm mt-1">ใช้สำหรับฟีเจอร์ที่ไม่ผูกกับบัญชี LINE เฉพาะ</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                    class="border rounded-lg p-4 <?= $configLiffId ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' ?>">
                    <label class="block text-sm font-medium mb-2">
                        📹 LIFF_ID <span class="text-gray-500">(Video Call)</span>
                        <?= $configLiffId ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗ ต้องตั้งค่า</span>' ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="text" value="<?= htmlspecialchars($configLiffId ?: 'ยังไม่ได้ตั้งค่า') ?>" readonly
                            class="flex-1 px-3 py-2 bg-white border rounded-lg font-mono text-sm <?= $configLiffId ? 'text-green-600' : 'text-red-400' ?>">
                        <?php if ($configLiffId): ?>
                            <button onclick="copyLiffText('https://liff.line.me/<?= htmlspecialchars($configLiffId) ?>')"
                                class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg"><i
                                    class="fas fa-copy"></i></button>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Endpoint: <code
                            class="bg-gray-100 px-1 rounded"><?= $baseUrl ?>/api/video-call.php</code></p>
                </div>
                <div
                    class="border rounded-lg p-4 <?= $configLiffShareId ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' ?>">
                    <label class="block text-sm font-medium mb-2">
                        📤 LIFF_SHARE_ID <span class="text-gray-500">(Share)</span>
                        <?= $configLiffShareId ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗ ต้องตั้งค่า</span>' ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="text" value="<?= htmlspecialchars($configLiffShareId ?: 'ยังไม่ได้ตั้งค่า') ?>"
                            readonly
                            class="flex-1 px-3 py-2 bg-white border rounded-lg font-mono text-sm <?= $configLiffShareId ? 'text-green-600' : 'text-red-400' ?>">
                        <?php if ($configLiffShareId): ?>
                            <button
                                onclick="copyLiffText('https://liff.line.me/<?= htmlspecialchars($configLiffShareId) ?>')"
                                class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg"><i
                                    class="fas fa-copy"></i></button>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Endpoint: <code
                            class="bg-gray-100 px-1 rounded"><?= $baseUrl ?>/liff-share.php</code></p>
                </div>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="font-medium text-blue-700 mb-2"><i class="fas fa-edit mr-1"></i>วิธีแก้ไข LIFF ID ใน
                    config.php</p>
                <ol class="text-blue-600 text-sm space-y-1 list-decimal list-inside">
                    <li>เปิดไฟล์ <code class="bg-blue-100 px-1 rounded">config/config.php</code></li>
                    <li>หาบรรทัด <code class="bg-blue-100 px-1 rounded">define('LIFF_ID', '');</code></li>
                    <li>ใส่ LIFF ID ที่สร้างจาก LINE Developers Console</li>
                    <li>บันทึกไฟล์</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Endpoint URLs -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-cyan-500 to-blue-500 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-link mr-2"></i>Endpoint URLs สำหรับตั้งค่า LIFF
            </h2>
            <p class="text-cyan-100 text-sm mt-1">ใช้ URL เหล่านี้เมื่อสร้าง LIFF App ใน LINE Developers Console</p>
        </div>
        <div class="p-5">
            <div class="space-y-3">
                <div class="flex items-center gap-4 p-4 bg-green-50 rounded-xl border border-green-200">
                    <div class="text-3xl">🚀</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-green-700">🆕 LIFF App ใหม่ (แนะนำ)</div>
                        <div class="text-xs text-gray-600">ระบบ LIFF ใหม่ครบทุกฟังก์ชัน</div>
                    </div>
                    <div class="flex-1">
                        <input type="text" value="<?= $baseUrl ?>/miniapp/" readonly
                            class="w-full px-3 py-2 bg-white border rounded-lg font-mono text-xs"
                            onclick="this.select()">
                    </div>
                    <button onclick="copyLiffText('<?= $baseUrl ?>/miniapp/')"
                        class="copy-btn px-3 py-2 bg-green-200 hover:bg-green-300 rounded-lg text-green-700">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="flex items-center gap-4 p-4 bg-purple-50 rounded-xl border border-purple-200">
                    <div class="text-3xl">🛒</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-purple-700">LIFF Shop (เดิม)</div>
                        <div class="text-xs text-gray-600">หน้าร้านค้าและชำระเงิน (ระบบเดิม)</div>
                    </div>
                    <div class="flex-1">
                        <input type="text" value="<?= $baseUrl ?>/liff-shop.php" readonly
                            class="w-full px-3 py-2 bg-white border rounded-lg font-mono text-xs"
                            onclick="this.select()">
                    </div>
                    <button onclick="copyLiffText('<?= $baseUrl ?>/liff-shop.php')"
                        class="copy-btn px-3 py-2 bg-purple-200 hover:bg-purple-300 rounded-lg text-purple-700">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="flex items-center gap-4 p-4 bg-blue-50 rounded-xl border border-blue-200">
                    <div class="text-3xl">📹</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-blue-700">LIFF Video Call</div>
                        <div class="text-xs text-gray-600">วิดีโอคอลกับลูกค้า</div>
                    </div>
                    <div class="flex-1">
                        <input type="text" value="<?= $baseUrl ?>/api/video-call.php" readonly
                            class="w-full px-3 py-2 bg-white border rounded-lg font-mono text-xs"
                            onclick="this.select()">
                    </div>
                    <button onclick="copyLiffText('<?= $baseUrl ?>/api/video-call.php')"
                        class="copy-btn px-3 py-2 bg-blue-200 hover:bg-blue-300 rounded-lg text-blue-700">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="flex items-center gap-4 p-4 bg-orange-50 rounded-xl border border-orange-200">
                    <div class="text-3xl">📤</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-orange-700">LIFF Share</div>
                        <div class="text-xs text-gray-600">แชร์ข้อความไปยังเพื่อน</div>
                    </div>
                    <div class="flex-1">
                        <input type="text" value="<?= $baseUrl ?>/liff-share.php" readonly
                            class="w-full px-3 py-2 bg-white border rounded-lg font-mono text-xs"
                            onclick="this.select()">
                    </div>
                    <button onclick="copyLiffText('<?= $baseUrl ?>/liff-share.php')"
                        class="copy-btn px-3 py-2 bg-orange-200 hover:bg-orange-300 rounded-lg text-orange-700">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Setup Guide -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-green-500 to-emerald-600 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-book mr-2"></i>วิธีสร้าง LIFF App
            </h2>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                <div class="flex gap-4">
                    <div
                        class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">
                        1</div>
                    <div>
                        <h3 class="font-semibold">เข้า LINE Developers Console</h3>
                        <p class="text-gray-600 text-sm">ไปที่ <a href="https://developers.line.biz/console/"
                                target="_blank" class="text-blue-500 hover:underline">developers.line.biz/console</a>
                        </p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div
                        class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">
                        2</div>
                    <div>
                        <h3 class="font-semibold">เลือก Provider → LINE Login Channel</h3>
                        <p class="text-gray-600 text-sm">ถ้ายังไม่มี ให้สร้าง LINE Login Channel ใหม่</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div
                        class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">
                        3</div>
                    <div>
                        <h3 class="font-semibold">ไปที่แท็บ LIFF → Add</h3>
                        <div class="bg-gray-50 rounded-lg p-4 mt-2 text-sm">
                            <p><strong>LIFF app name:</strong> LINE Shop</p>
                            <p><strong>Size:</strong> Full</p>
                            <p><strong>Endpoint URL:</strong> <span class="text-green-600"><?= $baseUrl ?>/miniapp/</span>
                            </p>
                            <p><strong>Scope:</strong> ✅ profile, ✅ openid</p>
                            <p><strong>Bot link feature:</strong> On (Aggressive)</p>
                        </div>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div
                        class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">
                        4</div>
                    <div>
                        <h3 class="font-semibold">คัดลอก LIFF ID</h3>
                        <p class="text-gray-600 text-sm">นำ LIFF ID ที่ได้มาใส่ในช่องด้านบน</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div
                        class="w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">
                        5</div>
                    <div>
                        <h3 class="font-semibold">ตั้งค่า Linked OA (สำคัญ!)</h3>
                        <p class="text-gray-600 text-sm">ใน LIFF settings → Linked OA → เลือก LINE OA ของคุณ</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Section -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h2 class="text-lg font-bold mb-4">🧪 ทดสอบ LIFF</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <a href="liff/" target="_blank"
                class="block p-4 bg-green-100 rounded-xl hover:bg-green-200 text-center border-2 border-green-300">
                <div class="text-3xl mb-2">🚀</div>
                <div class="font-medium text-green-700">LIFF ใหม่</div>
                <div class="text-xs text-green-600">ระบบใหม่ครบทุกฟังก์ชัน</div>
            </a>
            <a href="liff/#/shop" target="_blank"
                class="block p-4 bg-purple-50 rounded-xl hover:bg-purple-100 text-center">
                <div class="text-3xl mb-2">🛒</div>
                <div class="font-medium">ร้านค้า</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <a href="liff/#/appointments" target="_blank"
                class="block p-4 bg-blue-50 rounded-xl hover:bg-blue-100 text-center">
                <div class="text-3xl mb-2">📅</div>
                <div class="font-medium">นัดหมาย</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <a href="liff/#/video-call" target="_blank"
                class="block p-4 bg-teal-50 rounded-xl hover:bg-teal-100 text-center">
                <div class="text-3xl mb-2">📹</div>
                <div class="font-medium">Video Call</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <?php if ($currentAccount && !empty($currentAccount['liff_id'])): ?>
                <a href="https://liff.line.me/<?= htmlspecialchars($currentAccount['liff_id']) ?>" target="_blank"
                    class="block p-4 bg-green-100 rounded-xl hover:bg-green-200 text-center border-2 border-green-300">
                    <div class="text-3xl mb-2">📱</div>
                    <div class="font-medium text-green-700">เปิดใน LINE</div>
                    <div class="text-xs text-green-600">ใช้ LIFF URL จริง</div>
                </a>
            <?php else: ?>
                <div class="block p-4 bg-gray-100 rounded-xl text-center opacity-50">
                    <div class="text-3xl mb-2">📱</div>
                    <div class="font-medium">เปิดใน LINE</div>
                    <div class="text-xs text-gray-500">ต้องตั้งค่า LIFF ID ก่อน</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function copyLiffText(text) {
        navigator.clipboard.writeText(text).then(() => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'คัดลอกแล้ว!', timer: 1500, showConfirmButton: false });
            } else {
                alert('คัดลอกแล้ว!');
            }
        });
    }
</script>