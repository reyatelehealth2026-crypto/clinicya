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
    <div class="db-kpi">
        <div class="db-kpi-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;">
            <i class="fas fa-users"></i>
        </div>
        <div class="db-kpi-copy">
            <div class="db-kpi-label">ลูกค้าทั้งหมด</div>
            <div class="db-kpi-value"><?= number_format($crmStats['total_customers']) ?></div>
            <div class="db-kpi-meta">+<?= number_format($crmStats['new_7days']) ?> ใน 7 วัน</div>
        </div>
    </div>

    <div class="db-kpi">
        <div class="db-kpi-icon" style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#059669;">
            <i class="fas fa-user-plus"></i>
        </div>
        <div class="db-kpi-copy">
            <div class="db-kpi-label">ใหม่วันนี้</div>
            <div class="db-kpi-value"><?= number_format($crmStats['new_today']) ?></div>
            <div class="db-kpi-meta">ลูกค้า</div>
        </div>
    </div>

    <div class="db-kpi">
        <div class="db-kpi-icon" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);color:#7c3aed;">
            <i class="fas fa-tags"></i>
        </div>
        <div class="db-kpi-copy">
            <div class="db-kpi-label">Tags</div>
            <div class="db-kpi-value"><?= number_format($crmStats['total_tags']) ?></div>
            <div class="db-kpi-meta">กลุ่มลูกค้า</div>
        </div>
    </div>

    <div class="db-kpi">
        <div class="db-kpi-icon" style="background:linear-gradient(135deg,#ffedd5,#fed7aa);color:#ea580c;">
            <i class="fas fa-robot"></i>
        </div>
        <div class="db-kpi-copy">
            <div class="db-kpi-label">Auto Rules</div>
            <div class="db-kpi-value"><?= number_format($crmStats['auto_rules']) ?></div>
            <div class="db-kpi-meta">กฎอัตโนมัติ</div>
        </div>
    </div>
</div>

<!-- ─── Modular Panels: Tags / Auto Rules / Recent Customers ─── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Tags Overview -->
    <div class="db-section">
        <div class="db-section-header">
            <div class="db-section-title">
                <i class="fas fa-tags" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);color:#7c3aed;"></i>
                Tags
            </div>
            <a href="user-tags.php" class="db-action-link">
                <i class="fas fa-cog"></i> จัดการ
            </a>
        </div>
        <div class="db-section-body-flush" style="max-height:340px;overflow-y:auto;">
            <?php if (empty($tags)): ?>
                <div class="db-empty">
                    <i class="fas fa-tags"></i>
                    <p>ยังไม่มี Tags</p>
                </div>
            <?php else: ?>
                <?php foreach ($tags as $tag): ?>
                    <div class="db-list-item" style="padding:12px 20px;">
                        <span style="width:10px;height:10px;border-radius:999px;flex-shrink:0;box-shadow:0 0 0 2px rgba(255,255,255,0.8),0 0 0 3px <?= htmlspecialchars($tag['color'] ?? '#3B82F6') ?>40;background:<?= htmlspecialchars($tag['color'] ?? '#3B82F6') ?>;"></span>
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
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Auto Tag Rules -->
    <div class="db-section">
        <div class="db-section-header">
            <div class="db-section-title">
                <i class="fas fa-robot" style="background:linear-gradient(135deg,#ffedd5,#fed7aa);color:#ea580c;"></i>
                Auto Tag Rules
            </div>
            <a href="auto-tag-rules.php" class="db-action-link">
                <i class="fas fa-cog"></i> จัดการ
            </a>
        </div>
        <div class="db-section-body-flush" style="max-height:340px;overflow-y:auto;">
            <?php if (empty($autoRules)): ?>
                <div class="db-empty">
                    <i class="fas fa-robot"></i>
                    <p>ยังไม่มี Auto Rules</p>
                </div>
            <?php else: ?>
                <?php foreach ($autoRules as $rule): ?>
                    <div class="db-list-item" style="padding:14px 20px;flex-direction:column;align-items:stretch;gap:8px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-size:13px;font-weight:600;color:#132235;"><?= htmlspecialchars($rule['rule_name']) ?></span>
                            <span style="padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;<?= $rule['is_active'] ? 'background:#d1fae5;color:#059669;border:1px solid #a7f3d0;' : 'background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;' ?>">
                                <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;font-size:11px;color:#74869a;">
                            <span style="padding:2px 8px;border-radius:6px;background:#eff6ff;color:#2563eb;font-weight:600;border:1px solid #bfdbfe;"><?= htmlspecialchars($rule['trigger_type']) ?></span>
                            <i class="fas fa-arrow-right" style="font-size:9px;color:#cbd5e1;"></i>
                            <span style="padding:2px 8px;border-radius:6px;font-weight:600;background:<?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>14;color:<?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>;border:1px solid <?= htmlspecialchars($rule['tag_color'] ?? '#3B82F6') ?>30;">
                                <?= htmlspecialchars($rule['tag_name']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Customers -->
    <div class="db-section">
        <div class="db-section-header">
            <div class="db-section-title">
                <i class="fas fa-user-clock" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;"></i>
                ลูกค้าล่าสุด
            </div>
            <a href="users.php" class="db-action-link">
                <i class="fas fa-external-link-alt"></i> ดูทั้งหมด
            </a>
        </div>
        <div class="db-section-body-flush" style="max-height:340px;overflow-y:auto;">
            <?php if (empty($recentCustomers)): ?>
                <div class="db-empty">
                    <i class="fas fa-users"></i>
                    <p>ยังไม่มีลูกค้า</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentCustomers as $customer): ?>
                    <div class="db-list-item">
                        <img src="<?= $customer['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                            style="width:40px;height:40px;border-radius:12px;object-fit:cover;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:#132235;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($customer['display_name'] ?? 'Unknown') ?></div>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                                <?php if (!empty($customer['tags'])): ?>
                                    <?php foreach (explode(', ', $customer['tags']) as $tagName): ?>
                                        <span style="padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><?= htmlspecialchars($tagName) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#cbd5e1;">ไม่มี tag</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="user-detail.php?id=<?= $customer['id'] ?>" style="color:#0d9488;font-size:13px;">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ─── Action Rail: Quick Actions ─── -->
<div class="db-section">
    <div class="db-section-header">
        <div class="db-section-title">
            <i class="fas fa-bolt" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#d97706;"></i>
            Quick Actions
        </div>
    </div>
    <div class="db-section-body">
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php
            $actions = [
                ['href' => 'users.php', 'icon' => 'fa-users', 'color' => '#2563eb', 'bg' => '#dbeafe', 'label' => 'ดูลูกค้าทั้งหมด'],
                ['href' => 'user-tags.php', 'icon' => 'fa-tags', 'color' => '#7c3aed', 'bg' => '#ede9fe', 'label' => 'จัดการ Tags'],
                ['href' => 'auto-tag-rules.php', 'icon' => 'fa-robot', 'color' => '#ea580c', 'bg' => '#ffedd5', 'label' => 'Auto Tag Rules'],
                ['href' => 'customer-segments.php', 'icon' => 'fa-layer-group', 'color' => '#059669', 'bg' => '#d1fae5', 'label' => 'Segments'],
                ['href' => 'drip-campaigns.php', 'icon' => 'fa-paper-plane', 'color' => '#db2777', 'bg' => '#fce7f3', 'label' => 'Drip Campaigns'],
                ['href' => 'broadcast.php', 'icon' => 'fa-bullhorn', 'color' => '#dc2626', 'bg' => '#fee2e2', 'label' => 'Broadcast'],
                ['href' => 'analytics.php?tab=crm', 'icon' => 'fa-chart-pie', 'color' => '#4f46e5', 'bg' => '#e0e7ff', 'label' => 'Analytics'],
                ['href' => 'link-tracking.php', 'icon' => 'fa-link', 'color' => '#0891b2', 'bg' => '#cffafe', 'label' => 'Link Tracking'],
            ];
            foreach ($actions as $act):
            ?>
                <a href="<?= $act['href'] ?>"
                   style="display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:14px;background:<?= $act['bg'] ?>;border:1px solid <?= $act['color'] ?>20;text-decoration:none;font-size:13px;font-weight:600;color:<?= $act['color'] ?>;transition:all 0.15s ease;"
                   onmouseover="this.style.boxShadow='0 6px 16px <?= $act['color'] ?>18';this.style.transform='translateY(-1px)';"
                   onmouseout="this.style.boxShadow='none';this.style.transform='none';">
                    <i class="fas <?= $act['icon'] ?>"></i>
                    <?= $act['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
