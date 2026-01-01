<?php
/**
 * Activity Logs - ดู Log กิจกรรมทั้งหมดในระบบ
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$logger = ActivityLogger::getInstance($db);

// Filters
$filters = [];
$filterType = $_GET['type'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

if ($filterType) $filters['type'] = $filterType;
if ($filterAction) $filters['action'] = $filterAction;
if ($filterSearch) $filters['search'] = $filterSearch;
if ($filterDateFrom) $filters['date_from'] = $filterDateFrom . ' 00:00:00';
if ($filterDateTo) $filters['date_to'] = $filterDateTo . ' 23:59:59';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$totalLogs = $logger->countLogs($filters);
$totalPages = ceil($totalLogs / $perPage);
$logs = $logger->getLogs($filters, $perPage, $offset);

// Log types for filter
$logTypes = [
    'auth' => 'เข้าสู่ระบบ',
    'user' => 'ผู้ใช้',
    'admin' => 'แอดมิน',
    'data' => 'ข้อมูล',
    'consent' => 'ความยินยอม',
    'message' => 'ข้อความ',
    'order' => 'คำสั่งซื้อ',
    'pharmacy' => 'เภสัชกรรม',
    'ai' => 'AI',
    'api' => 'API',
    'system' => 'ระบบ'
];

$actions = [
    'create' => 'สร้าง',
    'read' => 'ดู',
    'update' => 'แก้ไข',
    'delete' => 'ลบ',
    'login' => 'เข้าสู่ระบบ',
    'logout' => 'ออกจากระบบ',
    'export' => 'ส่งออก',
    'send' => 'ส่ง',
    'approve' => 'อนุมัติ',
    'reject' => 'ปฏิเสธ'
];

$pageTitle = 'Activity Logs';
include __DIR__ . '/includes/header.php';
?>

<style>
.log-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}
.log-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.log-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}
.log-count {
    background: #e5e7eb;
    color: #4b5563;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}
.filter-section {
    padding: 16px 20px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-group label {
    font-size: 12px;
    font-weight: 500;
    color: #6b7280;
}
.filter-group select,
.filter-group input {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    background: white;
    min-width: 140px;
}
.filter-group select:focus,
.filter-group input:focus {
    outline: none;
    border-color: #06C755;
    box-shadow: 0 0 0 3px rgba(6, 199, 85, 0.1);
}
.filter-btn {
    padding: 8px 16px;
    background: #06C755;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-btn:hover {
    background: #05a648;
}
.log-table {
    width: 100%;
    border-collapse: collapse;
}
.log-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}
.log-table td {
    padding: 12px 16px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: top;
}
.log-table tr:hover {
    background: #f9fafb;
}
.log-time {
    color: #9ca3af;
    font-size: 12px;
    white-space: nowrap;
}
.log-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.log-badge.auth { background: #dbeafe; color: #1d4ed8; }
.log-badge.user { background: #e0f2fe; color: #0369a1; }
.log-badge.admin { background: #fef3c7; color: #b45309; }
.log-badge.data { background: #f3f4f6; color: #4b5563; }
.log-badge.consent { background: #d1fae5; color: #047857; }
.log-badge.message { background: #e0f2fe; color: #0369a1; }
.log-badge.order { background: #d1fae5; color: #047857; }
.log-badge.pharmacy { background: #fee2e2; color: #b91c1c; }
.log-badge.ai { background: #ede9fe; color: #6d28d9; }
.log-badge.api { background: #f3f4f6; color: #374151; }
.log-badge.system { background: #f3f4f6; color: #4b5563; }
.action-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}
.log-desc {
    max-width: 300px;
}
.log-desc-main {
    color: #1f2937;
}
.log-desc-sub {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 2px;
}
.log-admin {
    color: #06C755;
    font-weight: 500;
}
.log-ip {
    color: #9ca3af;
    font-size: 12px;
    font-family: monospace;
}
.pagination {
    display: flex;
    justify-content: center;
    gap: 4px;
    padding: 16px;
    border-top: 1px solid #e5e7eb;
}
.pagination a, .pagination span {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 13px;
    text-decoration: none;
    color: #374151;
    background: #f3f4f6;
}
.pagination a:hover {
    background: #e5e7eb;
}
.pagination .active {
    background: #06C755;
    color: white;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}
.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}
</style>

<div class="p-6">
    <div class="log-card">
        <div class="log-header">
            <div class="log-title">
                <i class="fas fa-history text-gray-400"></i>
                Activity Logs
            </div>
            <div class="log-count"><?= number_format($totalLogs) ?> รายการ</div>
        </div>
        
        <div class="filter-section">
            <form method="GET" class="filter-row">
                <div class="filter-group">
                    <label>ประเภท</label>
                    <select name="type">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($logTypes as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>การกระทำ</label>
                    <select name="action">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($actions as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterAction === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>จากวันที่</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label>ถึงวันที่</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                <div class="filter-group">
                    <label>ค้นหา</label>
                    <input type="text" name="search" placeholder="คำอธิบาย, ชื่อผู้ใช้..." value="<?= htmlspecialchars($filterSearch) ?>" style="min-width: 200px;">
                </div>
                <button type="submit" class="filter-btn">
                    <i class="fas fa-search"></i> ค้นหา
                </button>
            </form>
        </div>
        
        <div style="overflow-x: auto;">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>ประเภท</th>
                        <th>การกระทำ</th>
                        <th>รายละเอียด</th>
                        <th>ผู้ดำเนินการ</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <div>ไม่พบข้อมูล</div>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="log-time"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                        <td>
                            <span class="log-badge <?= $log['log_type'] ?>"><?= $logTypes[$log['log_type']] ?? $log['log_type'] ?></span>
                        </td>
                        <td>
                            <span class="action-badge"><?= $actions[$log['action']] ?? $log['action'] ?></span>
                        </td>
                        <td class="log-desc">
                            <div class="log-desc-main"><?= htmlspecialchars($log['description']) ?></div>
                            <?php if ($log['entity_type']): ?>
                            <div class="log-desc-sub"><?= htmlspecialchars($log['entity_type']) ?> #<?= $log['entity_id'] ?></div>
                            <?php endif; ?>
                            <?php if ($log['user_name']): ?>
                            <div class="log-desc-sub">ลูกค้า: <?= htmlspecialchars($log['user_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['admin_name']): ?>
                            <span class="log-admin"><?= htmlspecialchars($log['admin_name']) ?></span>
                            <?php else: ?>
                            <span style="color: #9ca3af;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="log-ip"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
