<?php
/**
 * Platform Connection Settings Tab Content
 * การเชื่อมต่อแพลตฟอร์ม — จัดการ Token และข้อมูลการเชื่อมต่อกับ
 * Facebook Messenger และ TikTok Shop
 *
 * Part of consolidated settings.php. POST actions are handled in settings.php
 * (save_facebook / delete_facebook / test_facebook / save_tiktok /
 *  delete_tiktok / test_tiktok).
 */

// Ensure platform-account tables exist (mirrors install/migration_add_platforms.php)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS facebook_accounts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(255) NOT NULL,
        page_id             VARCHAR(100) NOT NULL,
        app_id              VARCHAR(100) NOT NULL,
        app_secret          VARCHAR(255) NOT NULL,
        page_access_token   TEXT NOT NULL,
        verify_token        VARCHAR(255) NOT NULL,
        webhook_url         VARCHAR(500) DEFAULT NULL,
        picture_url         VARCHAR(500) DEFAULT NULL,
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        settings            JSON DEFAULT NULL,
        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_page_id (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS tiktok_shop_accounts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(255) NOT NULL,
        shop_id             VARCHAR(100) NOT NULL,
        app_key             VARCHAR(100) NOT NULL,
        app_secret          VARCHAR(255) NOT NULL,
        access_token        TEXT NOT NULL,
        refresh_token       TEXT DEFAULT NULL,
        token_expires_at    DATETIME DEFAULT NULL,
        shop_cipher         VARCHAR(255) DEFAULT NULL,
        webhook_url         VARCHAR(500) DEFAULT NULL,
        picture_url         VARCHAR(500) DEFAULT NULL,
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        settings            JSON DEFAULT NULL,
        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_shop_id (shop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
}

// Load existing connections
$facebookAccounts = [];
$tiktokAccounts = [];
try {
    $facebookAccounts = $db->query("SELECT * FROM facebook_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $tiktokAccounts = $db->query("SELECT * FROM tiktok_shop_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$fbWebhookUrl = rtrim(BASE_URL, '/') . '/facebook-webhook.php';
$ttWebhookUrl = rtrim(BASE_URL, '/') . '/tiktok-webhook.php';

/** Status badge helper for connected/disabled accounts. */
$platformStatusBadge = function (bool $active): string {
    return $active
        ? '<span class="px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-100 text-emerald-700">เชื่อมต่ออยู่</span>'
        : '<span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">ปิดใช้งาน</span>';
};
?>

<div class="mb-6">
    <h2 class="text-xl font-bold text-gray-800">
        <i class="fas fa-plug text-indigo-500 mr-2"></i>การเชื่อมต่อแพลตฟอร์ม
    </h2>
    <p class="text-sm text-gray-500 mt-1">จัดการ Token และข้อมูลการเชื่อมต่อกับ Facebook Messenger และ TikTok Shop</p>
</div>

<!-- ============================== FACEBOOK MESSENGER ============================== -->
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold">
            <i class="fab fa-facebook-messenger text-blue-500 mr-2"></i>Facebook Messenger
        </h3>
        <span class="text-sm text-gray-400"><?= count($facebookAccounts) ?> เพจที่เชื่อมต่อ</span>
    </div>

    <!-- Webhook info -->
    <div class="mb-5 p-4 bg-blue-50 border border-blue-100 rounded-lg text-sm">
        <p class="font-medium text-blue-800 mb-1"><i class="fas fa-link mr-1"></i>Webhook URL (ตั้งค่าใน Meta App Dashboard)</p>
        <code class="block bg-white border rounded px-3 py-2 text-gray-700 break-all"><?= htmlspecialchars($fbWebhookUrl) ?></code>
        <p class="text-blue-700 mt-2">Subscriptions: <code>messages</code>, <code>message_deliveries</code>, <code>message_reads</code> · ใช้ค่า <b>Verify Token</b> ตามที่ตั้งไว้ด้านล่าง</p>
    </div>

    <!-- Connected pages -->
    <?php if ($facebookAccounts): ?>
        <div class="space-y-3 mb-5">
            <?php foreach ($facebookAccounts as $fb): ?>
                <details class="border rounded-lg">
                    <summary class="flex items-center justify-between px-4 py-3 cursor-pointer select-none">
                        <span class="font-medium text-gray-800">
                            <i class="fas fa-chevron-right text-xs text-gray-400 mr-2"></i>
                            <?= htmlspecialchars($fb['name']) ?>
                            <span class="text-gray-400 font-normal text-sm ml-1">(Page ID: <?= htmlspecialchars($fb['page_id']) ?>)</span>
                        </span>
                        <?= $platformStatusBadge((bool) $fb['is_active']) ?>
                    </summary>
                    <div class="px-4 pb-4 pt-2 border-t">
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input type="hidden" name="action" value="save_facebook">
                            <input type="hidden" name="fb_id" value="<?= (int) $fb['id'] ?>">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อเรียก (Page Name)</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($fb['name']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Page ID</label>
                                <input type="text" name="page_id" value="<?= htmlspecialchars($fb['page_id']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">App ID</label>
                                <input type="text" name="app_id" value="<?= htmlspecialchars($fb['app_id']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                                <input type="text" name="app_secret" value="<?= htmlspecialchars($fb['app_secret']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Page Access Token</label>
                                <textarea name="page_access_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs" required><?= htmlspecialchars($fb['page_access_token']) ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Verify Token</label>
                                <input type="text" name="verify_token" value="<?= htmlspecialchars($fb['verify_token']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 cursor-pointer py-2">
                                    <input type="checkbox" name="is_active" class="w-4 h-4 text-blue-600" <?= $fb['is_active'] ? 'checked' : '' ?>>
                                    <span class="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
                                </label>
                            </div>

                            <div class="md:col-span-2 flex flex-wrap gap-2 pt-1">
                                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium text-sm">
                                    <i class="fas fa-save mr-1"></i>บันทึก
                                </button>
                                <button type="submit" name="action" value="test_facebook" formnovalidate class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 font-medium text-sm">
                                    <i class="fas fa-vial mr-1"></i>ทดสอบการเชื่อมต่อ
                                </button>
                                <button type="submit" name="action" value="delete_facebook" formnovalidate onclick="return confirm('ลบการเชื่อมต่อเพจนี้?')" class="px-4 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 font-medium text-sm">
                                    <i class="fas fa-trash mr-1"></i>ลบ
                                </button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-sm text-gray-400 mb-5">ยังไม่มีเพจที่เชื่อมต่อ — เพิ่มเพจแรกด้านล่าง</p>
    <?php endif; ?>

    <!-- Add new page -->
    <details class="border border-dashed rounded-lg">
        <summary class="px-4 py-3 cursor-pointer select-none font-medium text-blue-600">
            <i class="fas fa-plus mr-1"></i>เพิ่มเพจ Facebook ใหม่
        </summary>
        <div class="px-4 pb-4 pt-2 border-t">
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="save_facebook">
                <input type="hidden" name="fb_id" value="0">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อเรียก (Page Name)</label>
                    <input type="text" name="name" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="ร้านยา CNY" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Page ID</label>
                    <input type="text" name="page_id" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">App ID</label>
                    <input type="text" name="app_id" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                    <input type="text" name="app_secret" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Page Access Token</label>
                    <textarea name="page_access_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs" required></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Verify Token</label>
                    <input type="text" name="verify_token" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs" placeholder="ตั้งค่าเองให้ตรงกับ Meta Dashboard">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 cursor-pointer py-2">
                        <input type="checkbox" name="is_active" class="w-4 h-4 text-blue-600" checked>
                        <span class="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
                    </label>
                </div>
                <div class="md:col-span-2 pt-1">
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium text-sm">
                        <i class="fas fa-plus mr-1"></i>เพิ่มเพจ
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>

<!-- ============================== TIKTOK SHOP ============================== -->
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold">
            <i class="fab fa-tiktok text-gray-900 mr-2"></i>TikTok Shop
        </h3>
        <span class="text-sm text-gray-400"><?= count($tiktokAccounts) ?> ร้านที่เชื่อมต่อ</span>
    </div>

    <!-- Webhook info -->
    <div class="mb-5 p-4 bg-gray-50 border border-gray-200 rounded-lg text-sm">
        <p class="font-medium text-gray-800 mb-1"><i class="fas fa-link mr-1"></i>Webhook URL (ตั้งค่าใน TikTok Shop Partner Center)</p>
        <code class="block bg-white border rounded px-3 py-2 text-gray-700 break-all"><?= htmlspecialchars($ttWebhookUrl) ?></code>
    </div>

    <!-- Connected shops -->
    <?php if ($tiktokAccounts): ?>
        <div class="space-y-3 mb-5">
            <?php foreach ($tiktokAccounts as $tt): ?>
                <details class="border rounded-lg">
                    <summary class="flex items-center justify-between px-4 py-3 cursor-pointer select-none">
                        <span class="font-medium text-gray-800">
                            <i class="fas fa-chevron-right text-xs text-gray-400 mr-2"></i>
                            <?= htmlspecialchars($tt['name']) ?>
                            <span class="text-gray-400 font-normal text-sm ml-1">(Shop ID: <?= htmlspecialchars($tt['shop_id']) ?>)</span>
                        </span>
                        <?= $platformStatusBadge((bool) $tt['is_active']) ?>
                    </summary>
                    <div class="px-4 pb-4 pt-2 border-t">
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input type="hidden" name="action" value="save_tiktok">
                            <input type="hidden" name="tt_id" value="<?= (int) $tt['id'] ?>">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อเรียก (Shop Name)</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($tt['name']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Shop ID</label>
                                <input type="text" name="shop_id" value="<?= htmlspecialchars($tt['shop_id']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">App Key</label>
                                <input type="text" name="app_key" value="<?= htmlspecialchars($tt['app_key']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                                <input type="text" name="app_secret" value="<?= htmlspecialchars($tt['app_secret']) ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                                <textarea name="access_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs" required><?= htmlspecialchars($tt['access_token']) ?></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Refresh Token</label>
                                <textarea name="refresh_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs"><?= htmlspecialchars($tt['refresh_token'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Shop Cipher</label>
                                <input type="text" name="shop_cipher" value="<?= htmlspecialchars($tt['shop_cipher'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 cursor-pointer py-2">
                                    <input type="checkbox" name="is_active" class="w-4 h-4 text-gray-700" <?= $tt['is_active'] ? 'checked' : '' ?>>
                                    <span class="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
                                </label>
                            </div>

                            <div class="md:col-span-2 flex flex-wrap gap-2 pt-1">
                                <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-black font-medium text-sm">
                                    <i class="fas fa-save mr-1"></i>บันทึก
                                </button>
                                <button type="submit" name="action" value="test_tiktok" formnovalidate class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 font-medium text-sm">
                                    <i class="fas fa-vial mr-1"></i>ทดสอบการเชื่อมต่อ
                                </button>
                                <button type="submit" name="action" value="delete_tiktok" formnovalidate onclick="return confirm('ลบการเชื่อมต่อร้านนี้?')" class="px-4 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 font-medium text-sm">
                                    <i class="fas fa-trash mr-1"></i>ลบ
                                </button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-sm text-gray-400 mb-5">ยังไม่มีร้านที่เชื่อมต่อ — เพิ่มร้านแรกด้านล่าง</p>
    <?php endif; ?>

    <!-- Add new shop -->
    <details class="border border-dashed rounded-lg">
        <summary class="px-4 py-3 cursor-pointer select-none font-medium text-gray-800">
            <i class="fas fa-plus mr-1"></i>เพิ่มร้าน TikTok Shop ใหม่
        </summary>
        <div class="px-4 pb-4 pt-2 border-t">
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="save_tiktok">
                <input type="hidden" name="tt_id" value="0">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อเรียก (Shop Name)</label>
                    <input type="text" name="name" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500" placeholder="CNY Shop" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shop ID</label>
                    <input type="text" name="shop_id" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">App Key</label>
                    <input type="text" name="app_key" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                    <input type="text" name="app_secret" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                    <textarea name="access_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs" required></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Refresh Token</label>
                    <textarea name="refresh_token" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shop Cipher</label>
                    <input type="text" name="shop_cipher" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 font-mono text-xs">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 cursor-pointer py-2">
                        <input type="checkbox" name="is_active" class="w-4 h-4 text-gray-700" checked>
                        <span class="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
                    </label>
                </div>
                <div class="md:col-span-2 pt-1">
                    <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-black font-medium text-sm">
                        <i class="fas fa-plus mr-1"></i>เพิ่มร้าน
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
