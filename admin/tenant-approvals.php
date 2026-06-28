<?php
/**
 * admin/tenant-approvals.php — Platform Owner approval queue for self-serve shops.
 *
 * Lists tenants in status 'pending_setup' (created by Google self-serve signup)
 * and lets a platform admin APPROVE (→ active, unlocks the subdomain) or
 * REJECT (→ terminated). Uses the shared platform shell.
 *
 * Auth: requires $_SESSION['platform_user_id'].
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SelfServeProvisioning.php';

if (empty($_SESSION['platform_user_id'])) {
    header('Location: /admin/platform-login.php');
    exit;
}

$platformUserId = (int) $_SESSION['platform_user_id'];
$db = Database::platform()->getConnection();
$h  = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------------------
// POST — approve / reject
// ---------------------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid       = (int) ($_POST['tenant_id'] ?? 0);
    $action    = (string) ($_POST['action'] ?? '');
    $reason    = trim((string) ($_POST['reason'] ?? ''));
    $decidedBy = (string) ($_SESSION['platform_user_name'] ?? 'Platform Owner');

    // Snapshot the owner details BEFORE the status change so we can email them.
    $tRow = [];
    if ($tid > 0) {
        $ts = $db->prepare('SELECT id, slug, display_name, owner_name, owner_email FROM tenants WHERE id = ? LIMIT 1');
        $ts->execute([$tid]);
        $tRow = $ts->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ($tid > 0 && $action === 'approve') {
        try {
            $ok = SelfServeProvisioning::approve($db, $tid, $platformUserId);
            if ($ok) {
                try {
                    require_once __DIR__ . '/../classes/SiteNotifier.php';
                    SiteNotifier::notifyTenantDecision('approved', $tRow, $reason, $decidedBy);
                } catch (\Throwable $eN) {
                    error_log('[tenant-approvals] approve notify: ' . $eN->getMessage());
                }
            }
            $flash = $ok
                ? ['type' => 'ok', 'msg' => "อนุมัติร้าน #{$tid} แล้ว — แจ้งเมลเจ้าของและผู้ดูแลเรียบร้อย"]
                : ['type' => 'error', 'msg' => "ร้าน #{$tid} ไม่ได้อยู่ในสถานะรออนุมัติ"];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Approve failed: ' . $e->getMessage()];
        }
    } elseif ($tid > 0 && $action === 'reject') {
        try {
            $stmt = $db->prepare('UPDATE tenants SET status = "terminated", terminated_at = NOW() WHERE id = ? AND status = "pending_setup"');
            $stmt->execute([$tid]);
            $rejected = $stmt->rowCount() > 0;
            if ($rejected) {
                try {
                    require_once __DIR__ . '/../classes/SiteNotifier.php';
                    SiteNotifier::notifyTenantDecision('rejected', $tRow, $reason, $decidedBy);
                } catch (\Throwable $eN) {
                    error_log('[tenant-approvals] reject notify: ' . $eN->getMessage());
                }
            }
            $flash = $rejected
                ? ['type' => 'ok', 'msg' => "ปฏิเสธร้าน #{$tid} แล้ว — แจ้งเมลเจ้าของและผู้ดูแลเรียบร้อย"]
                : ['type' => 'error', 'msg' => "ร้าน #{$tid} ไม่ได้อยู่ในสถานะรออนุมัติ"];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Reject failed: ' . $e->getMessage()];
        }
    }
}

$rows = $db->query(
    "SELECT id, slug, display_name, owner_name, owner_email, owner_phone, created_at
       FROM tenants WHERE status = 'pending_setup' ORDER BY created_at DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$baseDomain = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('approvals', 'รออนุมัติเปิดร้าน', 'ร้านที่สมัครเองผ่าน Google — รออนุมัติ');
?>

<?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
        <?= $h($flash['msg']) ?>
    </div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="pf-card pf-empty">
        <i class="fas fa-check-double text-5xl mb-4 text-slate-300"></i>
        <p>ไม่มีร้านรออนุมัติ</p>
    </div>
<?php else: ?>
    <div class="space-y-3">
        <?php foreach ($rows as $r): ?>
            <div class="pf-card pf-card-pad pf-int flex items-center justify-between gap-4 flex-wrap">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-slate-900 truncate"><?= $h($r['display_name']) ?></span>
                        <span class="pf-pill" data-st="pending_setup">รออนุมัติ</span>
                    </div>
                    <div class="text-xs text-slate-500 flex items-center gap-3 flex-wrap mt-1">
                        <a href="https://<?= $h($r['slug']) ?>.<?= $h($baseDomain) ?>/" target="_blank" class="font-mono text-emerald-700">
                            <?= $h($r['slug']) ?>.<?= $h($baseDomain) ?> <i class="fas fa-external-link-alt text-[10px]"></i>
                        </a>
                        <?php if ($r['owner_name']): ?><span><i class="fas fa-user text-slate-400 mr-1"></i><?= $h($r['owner_name']) ?></span><?php endif; ?>
                        <?php if ($r['owner_email']): ?><span><i class="fas fa-envelope text-slate-400 mr-1"></i><?= $h($r['owner_email']) ?></span><?php endif; ?>
                        <?php if ($r['owner_phone']): ?><span><i class="fas fa-phone text-slate-400 mr-1"></i><?= $h($r['owner_phone']) ?></span><?php endif; ?>
                        <span><i class="fas fa-clock text-slate-400 mr-1"></i><?= $h(date('d M H:i', strtotime((string)$r['created_at']))) ?></span>
                    </div>
                </div>
                <form method="POST" class="flex items-center gap-2 flex-shrink-0 flex-wrap justify-end">
                    <input type="hidden" name="tenant_id" value="<?= (int)$r['id'] ?>">
                    <input type="text" name="reason" maxlength="300" placeholder="เหตุผล / โน้ต (ส่งให้เจ้าของ)"
                           class="pf-input" style="width:210px;">
                    <button name="action" value="approve" class="pf-btn pf-btn-primary"
                            onclick="return confirm('อนุมัติเปิดร้าน <?= $h($r['slug']) ?> ?\nระบบจะส่งเมลแจ้งเจ้าของและผู้ดูแล');">
                        <i class="fas fa-check"></i> อนุมัติ
                    </button>
                    <button name="action" value="reject" class="pf-btn pf-btn-danger"
                            onclick="return confirm('ปฏิเสธร้าน <?= $h($r['slug']) ?> ?\nระบบจะส่งเมลแจ้งเจ้าของพร้อมเหตุผล');">
                        <i class="fas fa-times"></i> ปฏิเสธ
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php platform_shell_bottom(); ?>
