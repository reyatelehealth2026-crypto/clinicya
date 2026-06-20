<?php
/**
 * Loyalty Members (phone, no LINE) — สมาชิกสะสมแต้มแบบเบอร์โทร
 * ภาพรวม + เพิ่มแต้ม + ดูประวัติแต้มรายคน สำหรับลูกค้าที่เพิ่มหน้าร้านโดยไม่มี LINE
 * (users.line_user_id LIKE 'offline:%'). ดูระบบหลักที่ api/points-claim.php.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สมาชิกเบอร์ (สะสมแต้ม)';

require_once 'includes/header.php';

$lineAccountId = (int) ($currentBotId ?? 0);
$search = trim($_GET['q'] ?? '');

/** Best display name for a phone member row. */
function lmName(array $u): string
{
    $r = trim((string) ($u['real_name'] ?? ''));
    if ($r !== '') {
        return $r;
    }
    $p = trim(trim((string) ($u['first_name'] ?? '')) . ' ' . trim((string) ($u['last_name'] ?? '')));
    if ($p !== '') {
        return $p;
    }
    $d = trim((string) ($u['display_name'] ?? ''));
    return $d !== '' ? $d : 'ลูกค้า';
}

$stats = ['total' => 0, 'points' => 0, 'today' => 0];
$members = [];

if ($lineAccountId > 0) {
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(available_points), 0) AS points,
                    COALESCE(SUM(created_at >= CURDATE()), 0) AS today
             FROM users
             WHERE line_account_id = ? AND line_user_id LIKE 'offline:%'"
        );
        $st->execute([$lineAccountId]);
        $stats = $st->fetch(PDO::FETCH_ASSOC) ?: $stats;

        $sql = "SELECT id, display_name, real_name, first_name, last_name, phone,
                       available_points, total_points, created_at
                FROM users
                WHERE line_account_id = ? AND line_user_id LIKE 'offline:%'";
        $params = [$lineAccountId];
        if ($search !== '') {
            $sql .= " AND (phone LIKE ? OR real_name LIKE ? OR display_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $sql .= " ORDER BY created_at DESC LIMIT 300";
        $q = $db->prepare($sql);
        $q->execute($params);
        $members = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[loyalty-members] ' . $e->getMessage());
    }
}
?>

<div class="max-w-5xl mx-auto px-3 py-4">
    <div class="flex items-center justify-between gap-2 mb-4 flex-wrap">
        <div>
            <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-id-card-clip text-emerald-600 mr-1"></i>สมาชิกเบอร์ (สะสมแต้ม)</h1>
            <p class="text-xs text-gray-500 mt-0.5">ลูกค้าที่เพิ่มหน้าร้านด้วยเบอร์โทร ยังไม่ได้ผูก LINE</p>
        </div>
        <button type="button" onclick="lmOpenAdd()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow">
            <i class="fas fa-plus mr-1"></i>เพิ่มแต้ม / ลูกค้าใหม่
        </button>
    </div>

    <!-- Overview -->
    <div class="grid grid-cols-3 gap-2 mb-4">
        <div class="bg-white rounded-xl border p-3 text-center">
            <div class="text-2xl font-extrabold text-gray-800"><?= number_format((int) $stats['total']) ?></div>
            <div class="text-[11px] text-gray-500 mt-0.5">สมาชิกเบอร์ทั้งหมด</div>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <div class="text-2xl font-extrabold text-emerald-600"><?= number_format((int) $stats['points']) ?></div>
            <div class="text-[11px] text-gray-500 mt-0.5">แต้มคงเหลือรวม</div>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <div class="text-2xl font-extrabold text-amber-600"><?= number_format((int) $stats['today']) ?></div>
            <div class="text-[11px] text-gray-500 mt-0.5">เพิ่มวันนี้</div>
        </div>
    </div>

    <!-- Search -->
    <form method="get" class="flex gap-2 mb-3">
        <div class="relative flex-1">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="ค้นหาด้วยเบอร์หรือชื่อ"
                class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none">
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium">ค้นหา</button>
        <?php if ($search !== ''): ?>
            <a href="loyalty-members.php" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 self-center">ล้าง</a>
        <?php endif; ?>
    </form>

    <!-- List -->
    <div class="bg-white rounded-xl border overflow-hidden">
        <?php if (empty($members)): ?>
            <div class="text-center py-10 text-gray-400 text-sm">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>
                <?= $search !== '' ? 'ไม่พบสมาชิกที่ค้นหา' : 'ยังไม่มีสมาชิกเบอร์ — กด “เพิ่มแต้ม / ลูกค้าใหม่” เพื่อเริ่ม' ?>
            </div>
        <?php else: ?>
            <div class="divide-y">
                <?php foreach ($members as $m):
                    $name = lmName($m);
                    $phone = (string) ($m['phone'] ?? '');
                    $created = $m['created_at'] ? date('d/m/y H:i', strtotime($m['created_at'])) : '-';
                ?>
                    <div class="flex items-center gap-3 p-3 hover:bg-gray-50">
                        <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-sm text-gray-800 truncate"><?= htmlspecialchars($name, ENT_QUOTES) ?></div>
                            <div class="text-xs text-gray-500"><i class="fas fa-phone text-[10px] mr-1"></i><?= htmlspecialchars($phone, ENT_QUOTES) ?> · <?= $created ?></div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-emerald-700 font-extrabold text-sm"><?= number_format((int) $m['available_points']) ?> <span class="text-[11px] font-normal text-gray-400">แต้ม</span></div>
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <button type="button" onclick="lmOpenAdd(<?= (int) $m['id'] ?>, '<?= htmlspecialchars($phone, ENT_QUOTES) ?>', '<?= htmlspecialchars($name, ENT_QUOTES) ?>')"
                                class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium" title="เพิ่มแต้ม">
                                <i class="fas fa-star"></i>
                            </button>
                            <button type="button" onclick="lmOpenDetail(<?= (int) $m['id'] ?>)"
                                class="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-medium" title="ดูประวัติแต้ม">
                                <i class="fas fa-receipt"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (count($members) >= 300): ?>
        <p class="text-[11px] text-gray-400 text-center mt-2">แสดง 300 รายการล่าสุด — ใช้ค้นหาเพื่อกรองเพิ่ม</p>
    <?php endif; ?>
</div>

<!-- Add-points modal -->
<div id="lmAddModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="p-3 border-b flex justify-between items-center bg-emerald-50">
            <h3 class="font-bold text-sm text-emerald-700"><i class="fas fa-star mr-1"></i><span id="lmAddTitle">เพิ่มแต้ม</span></h3>
            <button onclick="lmCloseAdd()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4">
            <input type="hidden" id="lmUserId" value="">
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-phone mr-1 text-emerald-600"></i>เบอร์โทร</label>
                <input type="tel" inputmode="numeric" id="lmPhone" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none" placeholder="08x-xxx-xxxx">
            </div>
            <div class="mb-3" id="lmNameBox">
                <label class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-user mr-1 text-emerald-600"></i>ชื่อลูกค้า <span class="text-gray-400">(ไม่บังคับ)</span></label>
                <input type="text" id="lmName" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none" placeholder="ชื่อลูกค้า">
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-coins mr-1 text-emerald-600"></i>ยอดเงิน (฿)</label>
                <input type="number" min="0" step="0.01" id="lmAmount" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none" placeholder="เช่น 250">
            </div>
            <div class="flex items-center gap-2 my-2">
                <div class="flex-1 border-t border-gray-200"></div><span class="text-xs text-gray-400">หรือ</span><div class="flex-1 border-t border-gray-200"></div>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-star mr-1 text-emerald-600"></i>แต้มที่จะให้ (ระบุเอง)</label>
                <input type="number" min="0" step="1" id="lmPoints" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none" placeholder="เช่น 10">
            </div>
            <div id="lmAddErr" class="text-xs text-red-600 mb-2 hidden"></div>
        </div>
        <div class="p-3 border-t bg-gray-50">
            <button type="button" id="lmAddBtn" onclick="lmSubmitAdd()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3 rounded-lg font-bold text-sm">
                <i class="fas fa-check mr-1"></i>ให้แต้ม
            </button>
        </div>
    </div>
</div>

<!-- Detail modal -->
<div id="lmDetailModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-3 border-b flex justify-between items-center bg-emerald-50 flex-shrink-0">
            <h3 class="font-bold text-sm text-emerald-700"><i class="fas fa-receipt mr-1"></i>ประวัติแต้ม</h3>
            <button onclick="lmCloseDetail()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            <div id="lmDetailHead" class="mb-3 p-3 rounded-lg bg-emerald-50 text-center">
                <div class="font-bold text-gray-800" id="lmDetailName">-</div>
                <div class="text-xs text-gray-500" id="lmDetailPhone">-</div>
                <div class="text-2xl font-extrabold text-emerald-600 mt-1"><span id="lmDetailPoints">0</span> <span class="text-sm font-normal text-gray-400">แต้ม</span></div>
            </div>
            <div id="lmDetailTx" class="space-y-1.5 text-xs"></div>
        </div>
        <div class="p-3 border-t bg-gray-50 flex-shrink-0">
            <button type="button" id="lmDetailAddBtn" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 rounded-lg font-medium text-sm">
                <i class="fas fa-star mr-1"></i>เพิ่มแต้มให้ลูกค้านี้
            </button>
        </div>
    </div>
</div>

<script>
    window.currentBotId = <?= (int) $lineAccountId ?>;

    const lmDigits = (s) => String(s || '').replace(/\D+/g, '');
    const lmEsc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    window.lmOpenAdd = function (userId, phone, name) {
        document.getElementById('lmUserId').value = userId || '';
        document.getElementById('lmPhone').value = phone || '';
        document.getElementById('lmPhone').readOnly = !!userId;
        document.getElementById('lmName').value = '';
        document.getElementById('lmAmount').value = '';
        document.getElementById('lmPoints').value = '';
        document.getElementById('lmAddErr').classList.add('hidden');
        // Name field only matters for brand-new customers.
        document.getElementById('lmNameBox').style.display = userId ? 'none' : 'block';
        document.getElementById('lmAddTitle').textContent = userId ? ('เพิ่มแต้ม · ' + (name || phone || '')) : 'เพิ่มแต้ม / ลูกค้าใหม่';
        document.getElementById('lmAddModal').classList.remove('hidden');
        setTimeout(() => document.getElementById(userId ? 'lmAmount' : 'lmPhone')?.focus(), 50);
    };
    window.lmCloseAdd = function () { document.getElementById('lmAddModal').classList.add('hidden'); };

    window.lmSubmitAdd = async function () {
        const err = document.getElementById('lmAddErr');
        const showErr = (m) => { err.textContent = m; err.classList.remove('hidden'); };
        err.classList.add('hidden');

        const phone = lmDigits(document.getElementById('lmPhone').value);
        const userId = document.getElementById('lmUserId').value;
        const name = document.getElementById('lmName').value || '';
        const amount = parseFloat(document.getElementById('lmAmount').value || '0');
        const points = parseInt(document.getElementById('lmPoints').value || '0', 10);

        if (phone.length < 8) { showErr('กรุณากรอกเบอร์ให้ถูกต้อง'); return; }
        if (!(amount > 0) && !(points > 0)) { showErr('กรุณากรอกยอดเงินหรือแต้มอย่างน้อยหนึ่งช่อง'); return; }

        const btn = document.getElementById('lmAddBtn');
        const html = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังให้แต้ม...';
        try {
            const res = await fetch('api/points-claim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    action: 'give_by_phone',
                    line_account_id: window.currentBotId,
                    phone: phone,
                    user_id: userId || '',
                    name: name,
                    amount: amount > 0 ? amount : '',
                    points: points > 0 ? points : ''
                })
            });
            const data = JSON.parse(await res.text());
            if (!data.success) { showErr(data.message || 'ให้แต้มไม่สำเร็จ'); btn.disabled = false; btn.innerHTML = html; return; }
            location.reload(); // refresh list + stats
        } catch (e) {
            console.error('give_by_phone error:', e);
            showErr('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            btn.disabled = false; btn.innerHTML = html;
        }
    };

    window.lmOpenDetail = async function (userId) {
        const modal = document.getElementById('lmDetailModal');
        const txBox = document.getElementById('lmDetailTx');
        document.getElementById('lmDetailName').textContent = '...';
        document.getElementById('lmDetailPhone').textContent = '';
        document.getElementById('lmDetailPoints').textContent = '0';
        txBox.innerHTML = '<div class="text-center text-gray-400 py-3"><i class="fas fa-spinner fa-spin"></i></div>';
        modal.classList.remove('hidden');
        try {
            const res = await fetch('api/points-claim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'member_detail', line_account_id: window.currentBotId, user_id: userId })
            });
            const data = JSON.parse(await res.text());
            if (!data.success) { txBox.innerHTML = '<div class="text-center text-red-500 py-3">' + lmEsc(data.message || 'โหลดไม่สำเร็จ') + '</div>'; return; }
            const c = data.customer;
            document.getElementById('lmDetailName').textContent = c.name;
            document.getElementById('lmDetailPhone').textContent = '📞 ' + c.phone + (c.has_line ? ' · มี LINE' : ' · ไม่มี LINE');
            document.getElementById('lmDetailPoints').textContent = Number(c.available_points || 0).toLocaleString();
            document.getElementById('lmDetailAddBtn').onclick = () => { lmCloseDetail(); lmOpenAdd(c.user_id, c.phone, c.name); };

            if (!data.transactions.length) {
                txBox.innerHTML = '<div class="text-center text-gray-400 py-3">ยังไม่มีรายการแต้ม</div>';
                return;
            }
            txBox.innerHTML = data.transactions.map((t) => {
                const earn = t.type === 'earn' || t.points >= 0;
                const sign = earn ? '+' : '';
                const color = earn ? 'text-emerald-600' : 'text-red-500';
                const when = t.created_at ? lmEsc(t.created_at.substring(0, 16).replace('T', ' ')) : '';
                return '<div class="flex items-center justify-between gap-2 p-2 rounded border border-gray-100">'
                    + '<div class="min-w-0"><div class="text-gray-700 truncate">' + lmEsc(t.description || t.type) + '</div>'
                    + '<div class="text-[10px] text-gray-400">' + when + '</div></div>'
                    + '<div class="text-right whitespace-nowrap"><div class="font-bold ' + color + '">' + sign + Number(t.points).toLocaleString() + '</div>'
                    + '<div class="text-[10px] text-gray-400">คงเหลือ ' + Number(t.balance_after).toLocaleString() + '</div></div>'
                    + '</div>';
            }).join('');
        } catch (e) {
            console.error('member_detail error:', e);
            txBox.innerHTML = '<div class="text-center text-red-500 py-3">เกิดข้อผิดพลาด</div>';
        }
    };
    window.lmCloseDetail = function () { document.getElementById('lmDetailModal').classList.add('hidden'); };

    // Close on backdrop click.
    ['lmAddModal', 'lmDetailModal'].forEach((id) => {
        document.getElementById(id)?.addEventListener('click', (e) => { if (e.target.id === id) document.getElementById(id).classList.add('hidden'); });
    });
</script>

<?php require_once 'includes/footer.php'; ?>
