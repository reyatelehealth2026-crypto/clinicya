<?php
/**
 * Dispense Tracking — ติดตามการจ่ายยาและยาคงเหลือ
 *
 * แสดงข้อมูลลูกค้า + ยาที่จ่ายไป + คำนวณวันคงเหลือ + ปุ่มส่งแจ้งเตือนแบบแมนนวล
 * ใช้ข้อมูลจาก medication_refill_tracking (เติมโดย RefillTrackingHelper เมื่อ dispense)
 *
 * @version 1.0.0
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Same-page AJAX (manual send reminder) — handle BEFORE header (which buffers HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/includes/auth_check.php';
    require_once __DIR__ . '/classes/LineAPI.php';
    require_once __DIR__ . '/classes/LineAccountManager.php';
    require_once __DIR__ . '/includes/liff-helper.php'; // reya_liff_url_or_oa()

    header('Content-Type: application/json; charset=utf-8');
    $db = Database::getInstance()->getConnection();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'send_reminder') {
            $trackingId = intval($_POST['tracking_id'] ?? 0);
            if ($trackingId <= 0) {
                throw new Exception('tracking_id required');
            }

            $stmt = $db->prepare("SELECT mrt.*, u.line_user_id, u.display_name, p.image_url, p.price, p.sale_price
                FROM medication_refill_tracking mrt
                JOIN users u ON mrt.user_id = u.id
                LEFT JOIN business_items p ON mrt.product_id = p.id
                WHERE mrt.id = ?");
            $stmt->execute([$trackingId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('tracking row not found');
            }
            if (empty($row['line_user_id']) || empty($row['line_account_id'])) {
                throw new Exception('ลูกค้ารายนี้ยังไม่ได้เชื่อม LINE');
            }

            $daysLeft = (int) floor((strtotime($row['estimated_end_date']) - strtotime('today')) / 86400);
            $lineManager = new LineAccountManager($db);
            $line = $lineManager->getLineAPI($row['line_account_id']);
            if (!$line) {
                throw new Exception('LINE account inactive');
            }

            $flexMessage = buildRefillReminderFlex($row, $daysLeft, $db);
            $result = $line->pushMessage($row['line_user_id'], [$flexMessage]);
            $httpCode = is_array($result) ? ($result['code'] ?? 0) : 0;
            if ($httpCode !== 200) {
                $apiMsg = is_array($result) && isset($result['body']['message']) ? $result['body']['message'] : 'unknown';
                $detail = is_array($result) && isset($result['body']['details']) ? json_encode($result['body']['details'], JSON_UNESCAPED_UNICODE) : '';
                error_log("dispense-tracking push fail (tracking_id={$trackingId}): code={$httpCode} msg={$apiMsg} detail={$detail} response=" . json_encode($result, JSON_UNESCAPED_UNICODE));
                $hint = '';
                if ($httpCode === 400 && stripos($apiMsg, 'invalid recipient') !== false) {
                    $hint = ' (ลูกค้าอาจยังไม่เป็นเพื่อนกับ OA หรือบล็อกบัญชี)';
                } elseif ($httpCode === 401) {
                    $hint = ' (Channel access token ไม่ถูกต้อง — ตรวจ Settings > LINE)';
                } elseif ($httpCode === 429) {
                    $hint = ' (เกินโควต้า push message ของเดือนนี้)';
                }
                throw new Exception("LINE push ล้มเหลว [HTTP {$httpCode}] {$apiMsg}{$hint}");
            }

            $stmt = $db->prepare("UPDATE medication_refill_tracking SET reminder_sent_at = NOW() WHERE id = ?");
            $stmt->execute([$trackingId]);

            // Save outgoing message to chat history
            $msgContent = json_encode($flexMessage, JSON_UNESCAPED_UNICODE);
            try {
                $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'flex', ?, 'admin:refill-reminder', NOW(), 1)");
                $stmt->execute([$row['line_account_id'], $row['user_id'], $msgContent]);
            } catch (Exception $e) {
                try {
                    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'flex', ?, NOW(), 1)");
                    $stmt->execute([$row['line_account_id'], $row['user_id'], $msgContent]);
                } catch (Exception $e2) {}
            }

            echo json_encode(['success' => true, 'days_left' => $daysLeft]);
            exit;
        }

        if ($action === 'complete_tracking') {
            $trackingId = intval($_POST['tracking_id'] ?? 0);
            if ($trackingId <= 0) {
                throw new Exception('tracking_id required');
            }
            $stmt = $db->prepare("UPDATE medication_refill_tracking SET estimated_end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?");
            $stmt->execute([$trackingId]);
            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Unknown action: ' . $action);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Build Flex bubble for refill reminder (mirrors cron/medication_refill_reminder.php)
 *
 * @param array    $row      medication_refill_tracking row (+ joined fields)
 * @param int      $daysLeft days remaining before refill
 * @param PDO|null $db       connection for LIFF-or-OA resolution
 */
function buildRefillReminderFlex($row, $daysLeft, $db = null)
{
    $productName  = $row['product_name'] ?: 'ยา';
    $currentPrice = $row['sale_price'] ?: ($row['price'] ?? 0);
    $imageUrl     = $row['image_url'] ?: 'https://via.placeholder.com/400x300?text=Medicine';

    $urgencyColor = '#F59E0B';
    $urgencyText  = "เหลือ {$daysLeft} วัน";
    if ($daysLeft <= 0) {
        $urgencyColor = '#EF4444';
        $urgencyText  = 'ครบกำหนด Refill';
    } elseif ($daysLeft <= 2) {
        $urgencyColor = '#EF4444';
        $urgencyText  = "เหลือ {$daysLeft} วัน";
    }

    // LIFF-or-OA fallback: real liff_id → Mini App product page; empty/PENDING
    // → OA chat; nothing → '' (URI button omitted below, message button remains).
    $productUrl = '';
    if ($db !== null && function_exists('reya_liff_url_or_oa')) {
        $productUrl = reya_liff_url_or_oa(
            $db,
            !empty($row['line_account_id']) ? (int) $row['line_account_id'] : null,
            '/shop/product?id=' . intval($row['product_id'])
        );
    }

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => [
            'type' => 'image',
            'url' => $imageUrl,
            'size' => 'full',
            'aspectRatio' => '4:3',
            'aspectMode' => 'cover',
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '🔁 Refill ยา', 'weight' => 'bold', 'color' => $urgencyColor, 'size' => 'sm', 'flex' => 1],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => $urgencyColor,
                            'cornerRadius' => 'md',
                            'paddingAll' => '4px',
                            'paddingStart' => '8px',
                            'paddingEnd' => '8px',
                            'flex' => 0,
                            'contents' => [
                                ['type' => 'text', 'text' => $urgencyText, 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'xs', 'align' => 'center'],
                            ],
                        ],
                    ],
                ],
                ['type' => 'text', 'text' => $productName, 'weight' => 'bold', 'size' => 'lg', 'wrap' => true, 'margin' => 'md'],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '📅 ครบกำหนด Refill', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => date('d/m/Y', strtotime($row['estimated_end_date'])), 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2],
                    ],
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => '💰 ราคา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => '฿' . number_format($currentPrice), 'size' => 'sm', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1],
                    ],
                ],
            ],
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => array_values(array_filter([
                // Mini-App button only when a real LIFF / OA URL exists; the
                // message button below always reaches the pharmacist.
                $productUrl !== '' ? [
                    'type' => 'button',
                    'action' => ['type' => 'uri', 'label' => '🛒 สั่ง Refill เลย', 'uri' => $productUrl],
                    'style' => 'primary',
                    'color' => '#11B0A6',
                    'height' => 'sm',
                ] : null,
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'message',
                        'label' => '💬 ติดต่อเภสัชกรเพื่อสั่งซ้ำ',
                        'text' => "ขอ Refill ยา: {$productName}",
                    ],
                    'style' => 'secondary',
                    'height' => 'sm',
                ],
            ])),
        ],
    ];

    return ['type' => 'flex', 'altText' => "🔁 Refill ยา: {$productName} ({$urgencyText})", 'contents' => $bubble];
}

$pageTitle = 'ติดตามการจ่ายยา';
require_once __DIR__ . '/includes/header.php';
$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Filters
$filter = $_GET['filter'] ?? 'active'; // active | expired | all
$search = trim($_GET['q'] ?? '');

$where = ['mrt.line_account_id = ?'];
$params = [$currentBotId];

if ($filter === 'active') {
    $where[] = 'mrt.estimated_end_date >= CURDATE()';
} elseif ($filter === 'expired') {
    $where[] = 'mrt.estimated_end_date < CURDATE()';
}

if ($search !== '') {
    $where[] = '(u.display_name LIKE ? OR mrt.product_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT mrt.*,
               u.display_name, u.line_user_id, u.picture_url AS profile_image,
               p.image_url AS product_image
        FROM medication_refill_tracking mrt
        JOIN users u ON mrt.user_id = u.id
        LEFT JOIN business_items p ON mrt.product_id = p.id
        WHERE $whereSql
        ORDER BY mrt.estimated_end_date ASC
        LIMIT 500";

$rows = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet — show empty
    error_log("dispense-tracking: " . $e->getMessage());
}

// Summary counts
$counts = ['active_cnt' => 0, 'soon_cnt' => 0, 'expired_cnt' => 0];
try {
    $cntStmt = $db->prepare("SELECT
        SUM(CASE WHEN estimated_end_date >= CURDATE() THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN estimated_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS soon_cnt,
        SUM(CASE WHEN estimated_end_date < CURDATE() THEN 1 ELSE 0 END) AS expired_cnt
        FROM medication_refill_tracking WHERE line_account_id = ?");
    $cntStmt->execute([$currentBotId]);
    $counts = $cntStmt->fetch(PDO::FETCH_ASSOC) ?: $counts;
} catch (Exception $e) {}
?>
<style>
    .tracking-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; transition:box-shadow .2s; }
    .tracking-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.06); }
    .badge-days { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; }
    .badge-danger { background:#FEE2E2; color:#B91C1C; }
    .badge-warn { background:#FEF3C7; color:#92400E; }
    .badge-ok { background:#D1FAE5; color:#065F46; }
    .stat-tile { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; text-align:center; }
    .stat-tile .num { font-size:28px; font-weight:700; color:#111827; }
    .stat-tile .lbl { font-size:12px; color:#6b7280; margin-top:4px; }
    .filter-btn { padding:6px 14px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:13px; text-decoration:none; color:#374151; display:inline-block; }
    .filter-btn.active { background:#11B0A6; color:#fff; border-color:#11B0A6; }
    .remind-btn { background:#11B0A6; color:#fff; border:0; padding:6px 12px; border-radius:8px; font-size:12px; cursor:pointer; }
    .remind-btn:hover { background:#0d8d85; }
    .remind-btn:disabled { background:#9ca3af; cursor:not-allowed; }
    .row-card { display:grid; grid-template-columns: minmax(180px,1.4fr) minmax(180px,1.5fr) 130px 150px 160px 140px; gap:12px; align-items:center; }
    @media (max-width: 1024px) { .row-card { grid-template-columns: 1fr; row-gap:8px; } }
</style>

<div class="px-4 md:px-6 py-4">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900">💊 ติดตามการจ่ายยา</h1>
            <p class="text-sm text-gray-500 mt-1">นับวันจากที่จ่ายยา · คำนวณยาคงเหลือ · ส่งแจ้งเตือนแบบแมนนวล</p>
        </div>
        <div class="flex gap-2">
            <a href="<?= cleanUrl('inbox-v2') ?>" class="px-3 py-2 bg-white border rounded-lg text-sm hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-1"></i> กลับ Inbox
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-5">
        <div class="stat-tile">
            <div class="num text-green-600"><?= (int) $counts['active_cnt'] ?></div>
            <div class="lbl">กำลังใช้ยาอยู่</div>
        </div>
        <div class="stat-tile">
            <div class="num text-amber-600"><?= (int) $counts['soon_cnt'] ?></div>
            <div class="lbl">ใกล้หมดใน 3 วัน</div>
        </div>
        <div class="stat-tile">
            <div class="num text-red-600"><?= (int) $counts['expired_cnt'] ?></div>
            <div class="lbl">ครบกำหนดแล้ว</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="flex flex-wrap items-center gap-2 mb-4">
        <a href="?filter=active" class="filter-btn <?= $filter === 'active' ? 'active' : '' ?>">กำลังใช้ยา</a>
        <a href="?filter=expired" class="filter-btn <?= $filter === 'expired' ? 'active' : '' ?>">ครบกำหนด</a>
        <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">ทั้งหมด</a>
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 ค้นหาลูกค้า / ชื่อยา..." class="flex-1 min-w-[200px] border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 outline-none">
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm hover:bg-teal-700">ค้นหา</button>
    </form>

    <!-- Header row (desktop) -->
    <div class="row-card hidden lg:grid text-xs font-semibold text-gray-500 uppercase px-4 mb-2">
        <div>ลูกค้า</div>
        <div>ยา</div>
        <div>วันที่จ่าย</div>
        <div>ปริมาณ / มื้อต่อวัน</div>
        <div>คาดว่าจะหมด</div>
        <div class="text-right">การจัดการ</div>
    </div>

    <div class="space-y-2">
        <?php if (empty($rows)): ?>
            <div class="tracking-card text-center text-gray-500 py-12">
                <i class="fas fa-pills text-4xl text-gray-300 mb-3"></i>
                <p>ยังไม่มีรายการติดตามการจ่ายยา</p>
                <p class="text-xs mt-1">รายการจะปรากฏที่นี่หลังจากเภสัชกรกดจ่ายยาให้ลูกค้า</p>
            </div>
        <?php else: ?>
            <?php foreach ($rows as $r):
                $purchaseDate = $r['purchase_date'] ?: date('Y-m-d');
                $endDate      = $r['estimated_end_date'] ?: date('Y-m-d');
                $daysSince    = (int) floor((strtotime('today') - strtotime($purchaseDate)) / 86400);
                $daysLeft     = (int) floor((strtotime($endDate) - strtotime('today')) / 86400);
                $consumed     = max(0, $daysSince * (int) $r['daily_dosage']);
                $totalQty     = (int) $r['quantity_purchased'];
                $remaining    = max(0, $totalQty - $consumed);
                $progress     = $totalQty > 0 ? min(100, round(($consumed / $totalQty) * 100)) : 0;

                if ($daysLeft < 0) {
                    $badgeClass = 'badge-danger'; $badgeText = "หมดแล้ว " . abs($daysLeft) . " วัน";
                } elseif ($daysLeft <= 3) {
                    $badgeClass = 'badge-warn'; $badgeText = "เหลือ {$daysLeft} วัน";
                } else {
                    $badgeClass = 'badge-ok'; $badgeText = "เหลือ {$daysLeft} วัน";
                }

                $reminderSent = !empty($r['reminder_sent_at'])
                    ? date('d/m H:i', strtotime($r['reminder_sent_at']))
                    : null;
            ?>
            <div class="tracking-card row-card" data-tracking-id="<?= (int) $r['id'] ?>">
                <!-- ลูกค้า -->
                <div class="flex items-center gap-3">
                    <?php if (!empty($r['profile_image'])): ?>
                        <img src="<?= htmlspecialchars($r['profile_image']) ?>" class="w-10 h-10 rounded-full object-cover border" alt="">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 text-white flex items-center justify-center font-bold">
                            <?= htmlspecialchars(mb_substr($r['display_name'] ?: '?', 0, 1, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 truncate"><?= htmlspecialchars($r['display_name'] ?: '— ไม่มีชื่อ —') ?></div>
                        <div class="text-xs text-gray-500 truncate">
                            <?php if (!empty($r['line_user_id'])): ?>
                                <i class="fab fa-line text-green-500"></i> เชื่อม LINE
                            <?php else: ?>
                                <span class="text-amber-600"><i class="fas fa-exclamation-circle"></i> ยังไม่เชื่อม LINE</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ยา -->
                <div class="flex items-center gap-3">
                    <?php if (!empty($r['product_image'])): ?>
                        <img src="<?= htmlspecialchars($r['product_image']) ?>" class="w-12 h-12 rounded-lg object-cover border" alt="">
                    <?php else: ?>
                        <div class="w-12 h-12 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center text-xl">💊</div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="font-medium text-gray-900 truncate"><?= htmlspecialchars($r['product_name'] ?: '—') ?></div>
                        <div class="text-xs text-gray-500">รหัสยา #<?= (int) $r['product_id'] ?></div>
                    </div>
                </div>

                <!-- วันที่จ่าย -->
                <div>
                    <div class="text-sm font-medium text-gray-900"><?= date('d/m/Y', strtotime($purchaseDate)) ?></div>
                    <div class="text-xs text-gray-500">ผ่านมา <?= $daysSince ?> วัน</div>
                </div>

                <!-- ปริมาณ -->
                <div>
                    <div class="text-sm">
                        <span class="font-semibold text-gray-900"><?= $remaining ?></span>
                        <span class="text-gray-500">/ <?= $totalQty ?></span>
                    </div>
                    <div class="text-xs text-gray-500">วันละ <?= (int) $r['daily_dosage'] ?> · ใช้ไป <?= $consumed ?></div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5 mt-1">
                        <div class="bg-teal-500 h-1.5 rounded-full" style="width:<?= $progress ?>%"></div>
                    </div>
                </div>

                <!-- คาดว่าจะหมด -->
                <div>
                    <span class="badge-days <?= $badgeClass ?>"><?= htmlspecialchars($badgeText) ?></span>
                    <div class="text-xs text-gray-500 mt-1"><?= date('d/m/Y', strtotime($endDate)) ?></div>
                    <?php if ($reminderSent): ?>
                        <div class="text-[10px] text-gray-400 mt-0.5">แจ้งเตือนล่าสุด: <?= htmlspecialchars($reminderSent) ?></div>
                    <?php endif; ?>
                </div>

                <!-- การจัดการ -->
                <div class="flex flex-col gap-1 items-stretch lg:items-end">
                    <button class="remind-btn js-remind" <?= empty($r['line_user_id']) ? 'disabled title="ลูกค้ายังไม่เชื่อม LINE"' : '' ?>>
                        <i class="fas fa-paper-plane mr-1"></i> ส่งแจ้งเตือน
                    </button>
                    <a href="<?= cleanUrl('messages') ?>?user=<?= (int) $r['user_id'] ?>" class="text-xs text-teal-600 hover:underline text-center lg:text-right">
                        <i class="fas fa-comments"></i> เปิดแชท
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="toast" class="fixed bottom-4 right-4 hidden z-50">
    <div id="toast-msg" class="px-4 py-2 rounded-lg shadow-lg text-sm bg-gray-900 text-white"></div>
</div>

<script>
(function() {
    const toast = (msg, ok=true) => {
        const t = document.getElementById('toast');
        const m = document.getElementById('toast-msg');
        m.textContent = msg;
        m.className = 'px-4 py-2 rounded-lg shadow-lg text-sm ' + (ok ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white');
        t.classList.remove('hidden');
        setTimeout(() => t.classList.add('hidden'), 2800);
    };

    document.querySelectorAll('.js-remind').forEach(btn => {
        btn.addEventListener('click', async () => {
            const card = btn.closest('[data-tracking-id]');
            const trackingId = card?.dataset?.trackingId;
            if (!trackingId) return;
            if (!confirm('ส่งแจ้งเตือนยาใกล้หมดให้ลูกค้ารายนี้ทาง LINE?')) return;

            btn.disabled = true;
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> กำลังส่ง...';

            try {
                const fd = new FormData();
                fd.append('action', 'send_reminder');
                fd.append('tracking_id', trackingId);
                const res = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'ส่งล้มเหลว');
                toast('✅ ส่งแจ้งเตือนสำเร็จ (เหลือ ' + data.days_left + ' วัน)');
                btn.innerHTML = '<i class="fas fa-check mr-1"></i> ส่งแล้ว';
                setTimeout(() => location.reload(), 1500);
            } catch (e) {
                toast('❌ ' + e.message, false);
                btn.disabled = false;
                btn.innerHTML = original;
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
