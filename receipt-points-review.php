<?php
/**
 * Receipt Points Review — daily admin review queue for OCR receipt-photo
 * loyalty-point claims the system couldn't confidently auto-award.
 * See docs/adr/0007-receipt-points-review.md.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Auth + $currentUser + TenantContext — emits NO HTML, so the AJAX branch
// below can return clean JSON. (header.php is included later for page render.)
require_once 'includes/auth_check.php';
require_once 'classes/ReceiptPointsAdmin.php';

$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);

// ---- Same-page AJAX handler — MUST run before header.php emits any HTML,
// or the response becomes "<html>…</html>{json}" and fetch's .json() throws
// a false "เชื่อมต่อไม่ได้" even though the award already ran. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'approve') {
            $claimId = (int) ($_POST['claim_id'] ?? 0);
            $points = (int) ($_POST['points'] ?? 0);
            if ($claimId <= 0 || $lineAccountId <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            $adminId = (int) ($currentUser['id'] ?? 0);
            $result = ReceiptPointsAdmin::awardPendingReceiptClaim(
                $db,
                $claimId,
                $lineAccountId,
                $points,
                'อนุมัติแต้มจากใบเสร็จ (ตรวจโดยแอดมิน)',
                $adminId
            );
            echo json_encode($result);
            exit;
        }
        throw new Exception('Unknown action');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---- Page render (header.php emits HTML + sets $currentBotId) ----
require_once 'includes/header.php';
require_once 'classes/LoyaltyPoints.php';
$lineAccountId = (int) ($currentBotId ?? $lineAccountId);
$pageTitle = 'ตรวจสลิปรับแต้ม';

// ---- List query ----
$statusFilter = $_GET['status'] ?? 'pending_review';
$allowedStatus = ['pending_review', 'approved', 'all'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'pending_review';
}

$claims = [];
if ($lineAccountId > 0) {
    $sql = "SELECT c.*, u.display_name, u.real_name, u.phone
            FROM receipt_point_claims c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.line_account_id = ?";
    $params = [$lineAccountId];
    if ($statusFilter !== 'all') {
        $sql .= " AND c.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY c.created_at DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$lp = new LoyaltyPoints($db, $lineAccountId);

$failReasonLabels = [
    'no_ocr_result' => 'ระบบอ่านใบเสร็จไม่ออกเลย',
    'zero_amount' => 'อ่านได้แต่จำนวนเงินเป็น 0',
    'low_confidence' => 'จำนวนเงินไม่ตรงกับยอดรวม (มั่นใจต่ำ)',
    'ocr_exception' => 'ระบบอ่านใบเสร็จขัดข้อง (error)',
    'not_recognized_as_receipt' => 'AI ไม่แน่ใจว่าเป็นใบเสร็จ (ภาพอาจเบลอ/แสงไม่พอ)',
];
$confidenceLabels = [
    'high' => ['label' => 'สูง', 'class' => 'bg-green-100 text-green-700'],
    'low' => ['label' => 'ต่ำ', 'class' => 'bg-amber-100 text-amber-700'],
    'unverified' => ['label' => 'ยังไม่ยืนยัน', 'class' => 'bg-amber-100 text-amber-700'],
    'none' => ['label' => 'อ่านไม่ได้', 'class' => 'bg-red-100 text-red-700'],
];

function rprName(array $c): string
{
    $r = trim((string) ($c['real_name'] ?? ''));
    if ($r !== '') return $r;
    $d = trim((string) ($c['display_name'] ?? ''));
    return $d !== '' ? $d : ('ลูกค้า #' . (int) $c['user_id']);
}
?>

<div class="max-w-6xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-1">🧾 ตรวจสลิปรับแต้ม</h1>
    <p class="text-sm text-gray-500 mb-4">ใบเสร็จที่ระบบอ่าน OCR ไม่มั่นใจพอจะให้แต้มอัตโนมัติ — ตรวจรูปแล้วกรอกแต้มเอง</p>

    <div class="flex gap-2 mb-4">
        <a href="?status=pending_review" class="px-3 py-1.5 rounded-lg text-sm <?= $statusFilter === 'pending_review' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700' ?>">รอตรวจ</a>
        <a href="?status=approved" class="px-3 py-1.5 rounded-lg text-sm <?= $statusFilter === 'approved' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700' ?>">อนุมัติแล้ว</a>
        <a href="?status=all" class="px-3 py-1.5 rounded-lg text-sm <?= $statusFilter === 'all' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700' ?>">ทั้งหมด</a>
    </div>

    <?php if (empty($claims)): ?>
        <div class="text-center text-gray-400 py-12">ไม่มีรายการ</div>
    <?php else: ?>
    <div class="space-y-3" id="claims-list">
        <?php foreach ($claims as $c): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-4 flex gap-4" data-claim-id="<?= (int) $c['id'] ?>">
            <div class="shrink-0 w-24 h-24 bg-gray-100 rounded-lg overflow-hidden">
                <?php if (!empty($c['image_path'])): ?>
                    <a href="/<?= htmlspecialchars($c['image_path']) ?>" target="_blank">
                        <img src="/<?= htmlspecialchars($c['image_path']) ?>" class="w-full h-full object-cover" alt="ใบเสร็จ">
                    </a>
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300 text-xs">ไม่มีรูป</div>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <span class="font-semibold"><?= htmlspecialchars(rprName($c)) ?></span>
                    <span class="text-xs text-gray-400"><?= htmlspecialchars($c['created_at']) ?></span>
                </div>
                <div class="text-sm text-gray-600 mt-1">
                    ยอดที่อ่านได้:
                    <?php if ($c['ocr_amount'] !== null): ?>
                        <span class="font-medium">฿<?= number_format((float) $c['ocr_amount'], 2) ?></span>
                    <?php elseif ($c['status'] === 'approved'): ?>
                        <span class="font-medium">฿<?= number_format((float) $c['total_amount'], 2) ?></span>
                    <?php else: ?>
                        <span class="text-gray-400">ไม่มีข้อมูล</span>
                    <?php endif; ?>
                    <?php if (!empty($c['confidence']) && isset($confidenceLabels[$c['confidence']])): ?>
                        <span class="ml-2 px-2 py-0.5 rounded-full text-xs <?= $confidenceLabels[$c['confidence']]['class'] ?>">มั่นใจ: <?= $confidenceLabels[$c['confidence']]['label'] ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($c['fail_reason'])): ?>
                    <div class="text-xs text-amber-600 mt-1"><?= htmlspecialchars($failReasonLabels[$c['fail_reason']] ?? $c['fail_reason']) ?></div>
                <?php endif; ?>

                <?php if ($c['status'] === 'pending_review'): ?>
                    <?php $suggested = $c['ocr_amount'] !== null ? $lp->calculatePoints((float) $c['ocr_amount']) : 0; ?>
                    <div class="mt-3 flex items-center gap-2">
                        <label class="text-sm text-gray-600">แต้มที่จะให้:</label>
                        <input type="number" min="1" value="<?= $suggested > 0 ? $suggested : '' ?>"
                               class="claim-points w-24 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                        <button class="approve-btn px-3 py-1.5 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">อนุมัติ</button>
                        <span class="approve-status text-xs text-gray-400"></span>
                    </div>
                <?php else: ?>
                    <div class="mt-3 text-sm text-green-700">
                        ให้แล้ว +<?= (int) $c['points_awarded'] ?> แต้ม
                        <?php if (!empty($c['reviewed_at'])): ?>
                            <span class="text-gray-400">(<?= htmlspecialchars($c['reviewed_at']) ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('claims-list')?.addEventListener('click', function (e) {
    const btn = e.target.closest('.approve-btn');
    if (!btn) return;
    const row = btn.closest('[data-claim-id]');
    const claimId = row.dataset.claimId;
    const pointsInput = row.querySelector('.claim-points');
    const statusEl = row.querySelector('.approve-status');
    const points = parseInt(pointsInput.value, 10);
    if (!points || points <= 0) {
        statusEl.textContent = 'กรอกจำนวนแต้มก่อน';
        statusEl.className = 'approve-status text-xs text-red-500';
        return;
    }
    btn.disabled = true;
    statusEl.textContent = 'กำลังบันทึก...';
    statusEl.className = 'approve-status text-xs text-gray-400';

    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=approve&claim_id=' + encodeURIComponent(claimId) + '&points=' + encodeURIComponent(points),
    })
        .then((r) => r.json())
        .then((data) => {
            if (data.success) {
                statusEl.textContent = 'ให้แต้มแล้ว +' + data.points_awarded;
                statusEl.className = 'approve-status text-xs text-green-600';
                btn.remove();
                pointsInput.disabled = true;
            } else {
                statusEl.textContent = data.error || 'เกิดข้อผิดพลาด';
                statusEl.className = 'approve-status text-xs text-red-500';
                btn.disabled = false;
            }
        })
        .catch(() => {
            statusEl.textContent = 'เชื่อมต่อไม่ได้';
            statusEl.className = 'approve-status text-xs text-red-500';
            btn.disabled = false;
        });
});
</script>

<?php require_once 'includes/footer.php'; ?>
