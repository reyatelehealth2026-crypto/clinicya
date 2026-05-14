<?php
/**
 * CRM Dashboard Tab Content
 * ศูนย์กลางจัดการลูกค้า
 */

require_once __DIR__ . '/../../classes/AutoTagManager.php';

$autoTagManager = new AutoTagManager($db, $currentBotId);

// รัน migration ถ้ายังไม่มีตาราง
try {
    $db->query("SELECT 1 FROM auto_tag_rules LIMIT 1");
} catch (Exception $e) {
    $migrationFile = __DIR__ . '/../../database/migration_auto_tags.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        $db->exec($sql);
    }
}

// สถิติ
$crmStats = [];

// จำนวนลูกค้าทั้งหมด
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND is_blocked = 0");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['total_customers'] = $stmt->fetchColumn();

// ลูกค้าใหม่วันนี้
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND DATE(created_at) = CURDATE()");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['new_today'] = $stmt->fetchColumn();

// ลูกค้าใหม่ 7 วัน
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['new_7days'] = $stmt->fetchColumn();

// จำนวน Tags
$stmt = $db->prepare("SELECT COUNT(*) FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL");
$stmt->execute([$currentBotId]);
$crmStats['total_tags'] = $stmt->fetchColumn();

// จำนวน Auto Rules
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM auto_tag_rules WHERE line_account_id = ? OR line_account_id IS NULL");
    $stmt->execute([$currentBotId]);
    $crmStats['auto_rules'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $crmStats['auto_rules'] = 0;
}

// Tags พร้อมจำนวนลูกค้า
$stmt = $db->prepare("
    SELECT t.*, COUNT(a.user_id) as customer_count 
    FROM user_tags t 
    LEFT JOIN user_tag_assignments a ON t.id = a.tag_id 
    WHERE t.line_account_id = ? OR t.line_account_id IS NULL 
    GROUP BY t.id 
    ORDER BY customer_count DESC
");
$stmt->execute([$currentBotId]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ลูกค้าล่าสุด
$stmt = $db->prepare("
    SELECT u.*, 
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t JOIN user_tag_assignments a ON t.id = a.tag_id WHERE a.user_id = u.id) as tags
    FROM users u 
    WHERE (u.line_account_id = ? OR ? IS NULL) AND u.is_blocked = 0
    ORDER BY u.created_at DESC 
    LIMIT 10
");
$stmt->execute([$currentBotId, $currentBotId]);
$recentCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto Tag Rules
$autoRules = $autoTagManager->getRules();
?>

<!-- ─── CRM KPI Row ─── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?= renderKpiCard('indigo',  'ลูกค้าทั้งหมด', number_format($crmStats['total_customers']),
        '+' . number_format($crmStats['new_7days']) . ' ใน 7 วัน', 'fas fa-users') ?>
    <?= renderKpiCard('emerald', 'ใหม่วันนี้',    number_format($crmStats['new_today']),
        'ลูกค้า', 'fas fa-user-plus') ?>
    <?= renderKpiCard('violet',  'Tags',           number_format($crmStats['total_tags']),
        'กลุ่มลูกค้า', 'fas fa-tags') ?>
    <?= renderKpiCard('amber',   'Auto Rules',     number_format($crmStats['auto_rules']),
        'กฎอัตโนมัติ', 'fas fa-robot') ?>
</div>

<!-- ─── Modular Panels: Tags / Auto Rules / Recent Customers ─── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Tags Overview -->
    <?php
    ob_start();
    if (empty($tags)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-tags" aria-hidden="true"></i></div>
            <p class="sc-empty__title">ยังไม่มี Tags</p>
            <p class="sc-empty__sub">สร้าง tag เพื่อแบ่งกลุ่มลูกค้าและส่งแคมเปญได้ตรงกลุ่ม</p>
            <a href="user-tags.php" class="sc-empty__cta"><i class="fas fa-plus" aria-hidden="true"></i> สร้าง Tag แรก</a>
        </div>
    <?php else:
        foreach ($tags as $tag):
            $tagColor = htmlspecialchars($tag['color'] ?? '#3B82F6');
        ?>
            <div class="sc-row" style="padding:12px 20px;">
                <span style="width:8px;height:8px;border-radius:999px;flex-shrink:0;background:<?= $tagColor ?>;"></span>
                <div style="flex:1;min-width:0;display:flex;align-items:center;gap:8px;">
                    <span style="font-size:13px;font-weight:600;color:#132235;"><?= htmlspecialchars($tag['name']) ?></span>
                    <?php if (isset($tag['tag_type']) && $tag['tag_type'] === 'auto'): ?>
                        <span style="padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;">Auto</span>
                    <?php elseif (isset($tag['tag_type']) && $tag['tag_type'] === 'system'): ?>
                        <span style="padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">System</span>
                    <?php endif; ?>
                </div>
                <span style="font-size:14px;font-weight:700;color:#5f7286;"><?= number_format($tag['customer_count']) ?></span>
            </div>
        <?php endforeach;
    endif;
    echo renderSectionCardFlush('Tags', 'fas fa-tags',
        '<div style="max-height:340px;overflow-y:auto;">' . ob_get_clean() . '</div>',
        renderSectionActionLink('user-tags.php', 'จัดการ'), 'violet');
    ?>

    <!-- Auto Tag Rules -->
    <?php
    ob_start();
    if (empty($autoRules)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-robot" aria-hidden="true"></i></div>
            <p class="sc-empty__title">ยังไม่มี Auto Rules</p>
            <p class="sc-empty__sub">ตั้งกฎติด tag อัตโนมัติเพื่อแบ่งกลุ่มลูกค้าโดยไม่ต้องทำมือ</p>
            <a href="auto-tag-rules.php" class="sc-empty__cta"><i class="fas fa-plus" aria-hidden="true"></i> สร้างกฎแรก</a>
        </div>
    <?php else:
        foreach ($autoRules as $rule): ?>
            <div class="sc-row" style="padding:14px 20px;flex-direction:column;align-items:stretch;gap:8px;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:13px;font-weight:600;color:#132235;"><?= htmlspecialchars($rule['rule_name']) ?></span>
                    <span style="padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;<?= $rule['is_active'] ? 'background:#d1fae5;color:#059669;border:1px solid #a7f3d0;' : 'background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;' ?>">
                        <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;font-size:11px;color:#74869a;">
                    <span style="padding:2px 8px;border-radius:6px;background:#eff6ff;color:#2563eb;font-weight:600;border:1px solid #bfdbfe;"><?= htmlspecialchars($rule['trigger_type']) ?></span>
                    <i class="fas fa-arrow-right" style="font-size:9px;color:#cbd5e1;" aria-hidden="true"></i>
                    <span style="padding:2px 8px;border-radius:6px;font-weight:600;background:<?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>14;color:<?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>;border:1px solid <?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>30;">
                        <?= htmlspecialchars($rule['tag_name']) ?>
                    </span>
                </div>
            </div>
        <?php endforeach;
    endif;
    echo renderSectionCardFlush('Auto Tag Rules', 'fas fa-robot',
        '<div style="max-height:340px;overflow-y:auto;">' . ob_get_clean() . '</div>',
        renderSectionActionLink('auto-tag-rules.php', 'จัดการ'), 'amber');
    ?>

    <!-- Recent Customers -->
    <?php
    ob_start();
    if (empty($recentCustomers)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-users" aria-hidden="true"></i></div>
            <p class="sc-empty__title">ยังไม่มีลูกค้า</p>
            <p class="sc-empty__sub">ลูกค้าที่ลงทะเบียนผ่าน LINE จะแสดงที่นี่</p>
        </div>
    <?php else:
        foreach ($recentCustomers as $customer): ?>
            <div class="sc-row">
                <img src="<?= htmlspecialchars($customer['picture_url'] ?: 'https://via.placeholder.com/40') ?>"
                    style="width:40px;height:40px;border-radius:12px;object-fit:cover;flex-shrink:0;" alt="">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:#132235;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($customer['display_name'] ?? 'Unknown') ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                        <?php if (!empty($customer['tags'])): ?>
                            <?php foreach (explode(', ', $customer['tags']) as $tagName): ?>
                                <span style="padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;background:var(--color-primary-50);color:var(--color-primary-700);border:1px solid var(--color-primary-200);"><?= htmlspecialchars($tagName) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="font-size:11px;color:#cbd5e1;">ไม่มี tag</span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="user-detail.php?id=<?= (int)$customer['id'] ?>" style="color:var(--color-primary-500);font-size:13px;" aria-label="ดูรายละเอียด">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
        <?php endforeach;
    endif;
    echo renderSectionCardFlush('ลูกค้าล่าสุด', 'fas fa-user-clock',
        '<div style="max-height:340px;overflow-y:auto;">' . ob_get_clean() . '</div>',
        renderSectionActionLink('users.php', 'ดูทั้งหมด'), 'indigo');
    ?>
</div>

<!-- ─── Action Rail: Quick Actions ─── -->
<?php
ob_start(); ?>
<div style="display:flex;flex-wrap:wrap;gap:10px;">
    <?php
    $actions = [
        ['href' => 'users.php',             'icon' => 'fa-users',       'color' => '#4f46e5', 'bg' => 'var(--color-primary-50)',  'label' => 'ดูลูกค้าทั้งหมด'],
        ['href' => 'user-tags.php',         'icon' => 'fa-tags',        'color' => '#7c3aed', 'bg' => '#ede9fe',                  'label' => 'จัดการ Tags'],
        ['href' => 'auto-tag-rules.php',    'icon' => 'fa-robot',       'color' => '#d97706', 'bg' => 'var(--color-amber-50)',    'label' => 'Auto Tag Rules'],
        ['href' => 'customer-segments.php', 'icon' => 'fa-layer-group', 'color' => '#059669', 'bg' => 'var(--color-emerald-50)', 'label' => 'Segments'],
        ['href' => 'drip-campaigns.php',    'icon' => 'fa-paper-plane', 'color' => '#db2777', 'bg' => '#fce7f3',                  'label' => 'Drip Campaigns'],
        ['href' => 'broadcast.php',         'icon' => 'fa-bullhorn',    'color' => '#dc2626', 'bg' => '#fee2e2',                  'label' => 'Broadcast'],
        ['href' => 'analytics.php?tab=crm', 'icon' => 'fa-chart-pie',   'color' => '#4f46e5', 'bg' => 'var(--color-primary-50)', 'label' => 'Analytics'],
        ['href' => 'link-tracking.php',     'icon' => 'fa-link',        'color' => '#0891b2', 'bg' => '#cffafe',                  'label' => 'Link Tracking'],
    ];
    foreach ($actions as $act): ?>
        <a href="<?= htmlspecialchars($act['href']) ?>"
           style="display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:14px;background:<?= $act['bg'] ?>;border:1px solid <?= $act['color'] ?>20;text-decoration:none;font-size:13px;font-weight:600;color:<?= $act['color'] ?>;transition:all 0.15s ease;"
           onmouseover="this.style.boxShadow='0 6px 16px <?= $act['color'] ?>18';this.style.transform='translateY(-1px)';"
           onmouseout="this.style.boxShadow='none';this.style.transform='none';">
            <i class="fas <?= htmlspecialchars($act['icon']) ?>" aria-hidden="true"></i>
            <?= htmlspecialchars($act['label']) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php
echo renderSectionCard('Quick Actions', 'fas fa-bolt', ob_get_clean(), null, 'amber');
?>
