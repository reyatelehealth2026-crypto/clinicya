<?php
/**
 * admin/beta-signups.php — Platform Owner inbox for Beta signups
 *
 * Lists all beta_signups rows with filters (status/score/UTM).
 * Each lead expandable inline showing all fields + add internal notes + update status.
 *
 * Auth: requires $_SESSION['platform_user_id'] (super admin).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>403</title>'
       . '<body style="font-family:sans-serif;padding:40px">'
       . '<h1>403 — Platform Owner only</h1>'
       . '<p><a href="/admin/platform-login.php">Sign in</a></p></body></html>';
    exit;
}

$platformUserId = (int)$_SESSION['platform_user_id'];
$db = Database::platform()->getConnection();

$h = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------------------
// Lookup tables (Thai labels for ENUM keys)
// ---------------------------------------------------------------------------
$LABELS = [
    'business_type' => [
        'single_pharmacy' => 'ร้านขายยาเดี่ยว',
        'multi_pharmacy'  => 'ร้านขายยาหลายสาขา',
        'clinic'          => 'คลินิก',
        'medical_clinic'  => 'คลินิกเวชกรรม',
        'beauty_clinic'   => 'คลินิกความงาม',
        'pharmacy_clinic' => 'ร้านยา + คลินิก',
        'other'           => 'อื่น ๆ',
    ],
    'branch_count' => [
        '1' => '1 สาขา', '2_3' => '2–3', '4_5' => '4–5', '5_plus' => '>5',
    ],
    'current_system' => [
        'line_oa_only' => 'LINE OA only', 'spreadsheet' => 'Excel/Sheet', 'pos' => 'POS',
        'crm' => 'CRM', 'none' => 'ไม่มี', 'other' => 'อื่น ๆ',
    ],
    'trial_window' => [
        'immediate' => 'ทันที', '7_days' => '7 วัน', '15_days' => '15 วัน',
        '30_days'   => '30 วัน', 'need_more_info' => 'ขอศึกษา',
    ],
    'has_line_oa' => [
        'yes' => 'มี', 'no' => 'ไม่มี', 'barely_used' => 'มีแต่ไม่ใช้', 'unsure' => 'ไม่แน่ใจ',
    ],
    'decision_maker' => [
        'owner' => 'เจ้าของ', 'pharmacist' => 'เภสัชกร', 'manager' => 'ผจก.',
        'marketing' => 'การตลาด', 'it' => 'IT', 'exec_approval' => 'รอผู้บริหาร',
    ],
    'contact_time' => [
        'morning' => '09-12น.', 'afternoon' => '13-15น.', 'late_afternoon' => '15-18น.',
        'evening' => '>18น.', 'line_first' => 'LINE ก่อน',
    ],
    'preferred_package' => [
        'beta_trial' => 'Beta', 'single_pharm' => 'ร้านเดี่ยว',
        'multi_pharm' => 'หลายสาขา', 'clinic' => 'คลินิก', 'need_advice' => 'ขอแนะนำ',
    ],
    'demo_format' => [
        'video_call' => 'Video call', 'clip' => 'คลิป', 'phone' => 'โทร', 'line' => 'LINE', 'unsure' => 'ไม่แน่ใจ',
    ],
    'pain_points' => [
        'response_slow'    => 'ตอบ LINE ไม่ทัน',
        'data_scattered'   => 'ข้อมูลกระจัดกระจาย',
        'no_followup'      => 'ไม่มีระบบติดตาม',
        'no_appointment'   => 'นัดหมายไม่เป็นระบบ',
        'pro_line_oa'      => 'อยากใช้ LINE OA แบบมืออาชีพ',
        'video_call'       => 'อยากได้ video call',
        'consultation_log' => 'อยากเก็บประวัติปรึกษา',
        'repeat_sales'     => 'เพิ่มยอดลูกค้าเก่า',
        'customer_groups'  => 'แยกกลุ่มลูกค้า',
        'pain_other'       => 'อื่น ๆ',
    ],
    'goals' => [
        'manage_line_oa'      => 'จัดการ LINE OA',
        'online_consult'      => 'ปรึกษาออนไลน์',
        'appointment'         => 'นัดหมาย',
        'video_call'          => 'วิดีโอคอล',
        'med_followup'        => 'ติดตามการใช้ยา',
        'customer_history'    => 'ประวัติลูกค้า',
        'sell_online_shop'    => '🛒 ขาย Mini App',
        'order_payment_mgmt'  => '💳 ออเดอร์+ชำระเงิน',
        'inventory_stock'     => '📦 จัดการสต็อก',
        'loyalty_program'     => '⭐ สมาชิก/แต้ม',
        'product_broadcast'   => '📣 บรอดแคสต์สินค้า',
        'repeat_campaign'     => 'แคมเปญลูกค้าเก่า',
        'credibility'         => 'เพิ่มความน่าเชื่อถือ',
        'goals_other'         => 'อื่น ๆ',
    ],
    'status' => [
        'new' => 'ใหม่', 'contacted' => 'ติดต่อแล้ว', 'demo_booked' => 'นัด Demo',
        'trial_started' => 'เริ่มทดลอง', 'signed_up' => 'สมัครจริง',
        'disqualified' => 'ไม่ตรง target', 'spam' => 'สแปม',
    ],
];
$STATUS_COLOR = [
    'new'           => 'bg-blue-100 text-blue-700',
    'contacted'     => 'bg-cyan-100 text-cyan-700',
    'demo_booked'   => 'bg-violet-100 text-violet-700',
    'trial_started' => 'bg-amber-100 text-amber-700',
    'signed_up'     => 'bg-emerald-100 text-emerald-700',
    'disqualified'  => 'bg-slate-100 text-slate-600',
    'spam'          => 'bg-red-100 text-red-700',
];

// ---------------------------------------------------------------------------
// POST — update lead status / notes
// ---------------------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $notes  = trim((string)($_POST['internal_notes'] ?? ''));
    if ($id > 0 && isset($LABELS['status'][$status])) {
        try {
            $stmt = $db->prepare(
                'UPDATE beta_signups
                    SET status = ?, internal_notes = ?, contacted_at = COALESCE(contacted_at, IF(? = "new", NULL, NOW())),
                        contacted_by = COALESCE(contacted_by, IF(? = "new", NULL, ?))
                  WHERE id = ?'
            );
            $stmt->execute([$status, $notes ?: null, $status, $status, $platformUserId, $id]);
            $flash = ['type' => 'ok', 'msg' => "อัปเดต lead #{$id} เรียบร้อย"];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Update failed: ' . $e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------------------
// GET — filters + load list
// ---------------------------------------------------------------------------
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterScore  = trim((string)($_GET['min_score'] ?? ''));
$q            = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
if (isset($LABELS['status'][$filterStatus])) {
    $where[]  = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterScore !== '' && ctype_digit($filterScore)) {
    $where[]  = 'lead_score >= ?';
    $params[] = (int)$filterScore;
}
if ($q !== '') {
    $where[]  = '(full_name LIKE ? OR business_name LIKE ? OR phone LIKE ? OR line_id LIKE ? OR email LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = $db->prepare("SELECT * FROM beta_signups {$whereSql} ORDER BY id DESC LIMIT 200");
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->query("SELECT
    COUNT(*) AS total,
    SUM(status = 'new')           AS s_new,
    SUM(status = 'contacted')     AS s_contacted,
    SUM(status = 'demo_booked')   AS s_demo,
    SUM(status = 'signed_up')     AS s_signed,
    AVG(lead_score)               AS avg_score
  FROM beta_signups")->fetch(PDO::FETCH_ASSOC);
?>
<?php
require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('beta', 'Beta Signup Inbox', 'ผู้สมัครทดลองใช้ REYA Beta');
?>
    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
            <?= $h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <?php foreach ([
            ['Total',       (int)$stats['total'],       'fa-inbox',         'text-slate-600'],
            ['New',         (int)$stats['s_new'],       'fa-circle-plus',   'text-blue-600'],
            ['Contacted',   (int)$stats['s_contacted'], 'fa-phone',         'text-cyan-600'],
            ['Demo Booked', (int)$stats['s_demo'],      'fa-calendar-check','text-violet-600'],
            ['Signed Up',   (int)$stats['s_signed'],    'fa-check-circle',  'text-emerald-600'],
        ] as [$label, $count, $icon, $color]): ?>
            <div class="pf-card pf-card-pad">
                <div class="flex items-center gap-2 text-xs text-slate-500"><i class="fas <?= $icon ?> <?= $color ?>"></i> <?= $label ?></div>
                <div class="pf-kpi-fig mt-1" style="font-size:1.6rem"><?= number_format($count) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="pf-card pf-card-pad mb-6 flex items-center gap-3 flex-wrap">
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="ค้นชื่อ / ร้าน / โทร / LINE / email"
               class="pf-input flex-1 min-w-[200px]" style="width:auto;">
        <select name="status" class="pf-input" style="width:auto;">
            <option value="">— ทุกสถานะ —</option>
            <?php foreach ($LABELS['status'] as $k => $lab): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $h($lab) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="min_score" class="pf-input" style="width:auto;">
            <option value="">— score ≥ ทั้งหมด —</option>
            <option value="70" <?= $filterScore === '70' ? 'selected' : '' ?>>≥ 70 (hot)</option>
            <option value="50" <?= $filterScore === '50' ? 'selected' : '' ?>>≥ 50</option>
            <option value="30" <?= $filterScore === '30' ? 'selected' : '' ?>>≥ 30</option>
        </select>
        <button type="submit" class="pf-btn pf-btn-dark">
            <i class="fas fa-filter"></i> Filter
        </button>
        <a href="/admin/beta-signups.php" class="text-sm text-slate-500 hover:text-slate-800">รีเซ็ต</a>
    </form>

    <!-- List -->
    <?php if (empty($rows)): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-500">
            <i class="fas fa-inbox text-5xl mb-4 text-slate-300"></i>
            <p>ยังไม่มี lead ตามเงื่อนไขนี้</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($rows as $r):
                $pain  = json_decode((string)($r['pain_points'] ?? '[]'), true) ?: [];
                $goals = json_decode((string)($r['goals']       ?? '[]'), true) ?: [];
            ?>
                <details class="pf-card overflow-hidden group">
                    <summary class="px-5 py-4 cursor-pointer hover:bg-slate-50 list-none flex items-center justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700 font-bold flex-shrink-0 tnum">
                                <?= (int)$r['lead_score'] ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="font-semibold text-slate-900 truncate">
                                    <?= $h($r['full_name']) ?> · <?= $h($r['business_name']) ?>
                                </div>
                                <div class="text-xs text-slate-500 flex items-center gap-3 flex-wrap mt-0.5">
                                    <span><i class="fas fa-phone text-slate-400 mr-1"></i><?= $h($r['phone']) ?></span>
                                    <span><i class="fab fa-line text-slate-400 mr-1"></i><?= $h($r['line_id']) ?></span>
                                    <span><i class="fas fa-map-marker-alt text-slate-400 mr-1"></i><?= $h($r['province']) ?></span>
                                    <span><i class="fas fa-clock text-slate-400 mr-1"></i><?= $h(date('d M H:i', strtotime((string)$r['created_at']))) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-xs px-2.5 py-1 rounded-full <?= $STATUS_COLOR[$r['status']] ?? 'bg-slate-100' ?>">
                                <?= $h($LABELS['status'][$r['status']] ?? $r['status']) ?>
                            </span>
                            <i class="fas fa-chevron-down text-slate-400 group-open:rotate-180 transition"></i>
                        </div>
                    </summary>

                    <div class="border-t border-slate-100 p-5 bg-slate-50/40 grid md:grid-cols-2 gap-5">
                        <!-- Left: data -->
                        <div class="space-y-3 text-sm">
                            <div class="bg-white rounded-xl p-3 border border-slate-200">
                                <div class="font-semibold text-slate-700 text-xs mb-2 uppercase">ติดต่อ</div>
                                <div class="space-y-1.5 text-sm">
                                    <div><span class="text-slate-500 text-xs">โทร:</span> <a href="tel:<?= $h($r['phone']) ?>" class="text-emerald-700 font-medium"><?= $h($r['phone']) ?></a></div>
                                    <div><span class="text-slate-500 text-xs">LINE:</span> <?= $h($r['line_id']) ?></div>
                                    <?php if ($r['email']): ?>
                                        <div><span class="text-slate-500 text-xs">Email:</span> <a href="mailto:<?= $h($r['email']) ?>" class="text-blue-600"><?= $h($r['email']) ?></a></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="bg-white rounded-xl p-3 border border-slate-200">
                                <div class="font-semibold text-slate-700 text-xs mb-2 uppercase">ร้าน</div>
                                <div class="space-y-1.5 text-sm">
                                    <div><strong><?= $h($r['business_name']) ?></strong></div>
                                    <div class="text-xs text-slate-600">
                                        <?= $h($LABELS['business_type'][$r['business_type']] ?? $r['business_type']) ?>
                                        <?= $r['business_type_other'] ? ' (' . $h($r['business_type_other']) . ')' : '' ?>
                                        · <?= $h($LABELS['branch_count'][$r['branch_count']] ?? '?') ?>
                                        · <?= $h($r['province']) ?>
                                    </div>
                                    <?php if (!empty($r['preferred_subdomain'])): ?>
                                        <div class="mt-2 pt-2 border-t border-slate-100">
                                            <span class="text-xs text-slate-500">Subdomain ที่อยากได้:</span>
                                            <a href="https://<?= $h($r['preferred_subdomain']) ?>.re-ya.com/" target="_blank"
                                               class="text-xs font-mono bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded inline-flex items-center gap-1 ml-1">
                                                <?= $h($r['preferred_subdomain']) ?>.re-ya.com
                                                <i class="fas fa-external-link-alt text-[10px]"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="bg-white rounded-xl p-3 border border-slate-200">
                                <div class="font-semibold text-slate-700 text-xs mb-2 uppercase">ปัญหา</div>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($pain as $p): ?>
                                        <span class="text-xs bg-rose-50 text-rose-700 border border-rose-200 px-2 py-1 rounded">
                                            <?= $h($LABELS['pain_points'][$p] ?? $p) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-2">ระบบปัจจุบัน: <?= $h($LABELS['current_system'][$r['current_system']] ?? '?') ?></div>
                            </div>

                            <div class="bg-white rounded-xl p-3 border border-slate-200">
                                <div class="font-semibold text-slate-700 text-xs mb-2 uppercase">เป้าหมาย</div>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($goals as $g): ?>
                                        <span class="text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-1 rounded">
                                            <?= $h($LABELS['goals'][$g] ?? $g) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="bg-white rounded-xl p-3 border border-slate-200 grid grid-cols-2 gap-2 text-xs">
                                <div><span class="text-slate-500">พร้อม:</span> <strong><?= $h($LABELS['trial_window'][$r['trial_window']] ?? '?') ?></strong></div>
                                <div><span class="text-slate-500">LINE OA:</span> <strong><?= $h($LABELS['has_line_oa'][$r['has_line_oa']] ?? '?') ?></strong></div>
                                <div><span class="text-slate-500">ผู้ตัดสินใจ:</span> <strong><?= $h($LABELS['decision_maker'][$r['decision_maker']] ?? '?') ?></strong></div>
                                <div><span class="text-slate-500">เวลาติดต่อ:</span> <strong><?= $h($LABELS['contact_time'][$r['contact_time']] ?? '?') ?></strong></div>
                                <div><span class="text-slate-500">แพ็กเกจ:</span> <strong><?= $h($LABELS['preferred_package'][$r['preferred_package']] ?? '?') ?></strong></div>
                                <div><span class="text-slate-500">Demo:</span> <strong><?= $h($LABELS['demo_format'][$r['demo_format']] ?? '?') ?></strong></div>
                            </div>

                            <?php if (!empty($r['additional_message'])): ?>
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3">
                                    <div class="font-semibold text-amber-800 text-xs mb-1 uppercase">ข้อความเพิ่มเติม</div>
                                    <div class="text-sm text-amber-900 whitespace-pre-line"><?= $h($r['additional_message']) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right: update form -->
                        <div>
                            <form method="POST" class="bg-white rounded-xl border border-slate-200 p-4 space-y-3 sticky top-4">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">สถานะ</label>
                                    <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                        <?php foreach ($LABELS['status'] as $k => $lab): ?>
                                            <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $h($lab) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">บันทึกภายใน</label>
                                    <textarea name="internal_notes" rows="5"
                                              placeholder="วันที่โทร, สิ่งที่คุย, follow-up next…"
                                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= $h($r['internal_notes']) ?></textarea>
                                </div>
                                <button type="submit" class="pf-btn pf-btn-primary w-full">
                                    <i class="fas fa-save"></i> บันทึก
                                </button>
                                <div class="pt-2 border-t border-slate-100 grid grid-cols-2 gap-2">
                                    <a href="tel:<?= $h($r['phone']) ?>" class="text-center text-xs bg-emerald-50 hover:bg-emerald-100 text-emerald-700 py-2 rounded">
                                        <i class="fas fa-phone mr-1"></i> โทร
                                    </a>
                                    <a href="https://line.me/R/ti/p/<?= $h(ltrim($r['line_id'], '@')) ?>" target="_blank"
                                       class="text-center text-xs bg-[#06C755]/10 hover:bg-[#06C755]/20 text-[#06C755] py-2 rounded">
                                        <i class="fab fa-line mr-1"></i> LINE
                                    </a>
                                </div>
                                <?php if ($r['contacted_at']): ?>
                                    <p class="text-xs text-slate-400 text-center">ติดต่อแล้ว <?= $h(date('d M Y H:i', strtotime((string)$r['contacted_at']))) ?></p>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="mt-6 text-xs text-slate-400 text-center">
        แสดง <?= count($rows) ?> รายการ (limit 200) · stats เป็น aggregate ทั้งหมด · sorted by id DESC
    </p>
<?php platform_shell_bottom(); ?>
