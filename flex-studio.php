<?php
/**
 * Flex Studio — รวม Flex ทุก slot + ตั้งค่าธีมต่อร้าน + live preview ข้อมูลจริง
 *
 * - แกลเลอรี: รวม Flex ~25 slot ที่ระบบส่งให้ลูกค้า พร้อม preview เหมือนจริง
 * - ธีมร้าน: ตั้งค่า brand token (สี/โลโก้/ผู้ส่ง/footer) มีผลกับ Flex ทุกใบพร้อมกัน
 * - ปรับเฉพาะใบ: override ราย slot ด้วย flex_templates (ผูก slot_key)
 *
 * Tenant-scoped by $_SESSION['current_bot_id']. Same-page POST AJAX gated on X-Requested-With.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/classes/FlexTemplates.php';
require_once __DIR__ . '/includes/flex-slots.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? ($_SESSION['line_account_id'] ?? null);

/** Normalise a #RRGGBB hex or return null. */
function fs_hex(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $v = ltrim($v, '#');
    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $v)) {
        return null;
    }
    return '#' . strtoupper($v);
}

/** Allow only http(s) URLs (block javascript:, data:, etc.). */
function fs_url(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $v) || mb_strlen($v) > 500) {
        return null;
    }
    return $v;
}

// ── Same-page AJAX ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if (!$lineAccountId) {
        echo json_encode(['success' => false, 'error' => 'no tenant']);
        exit;
    }
    $lineAccountId = (int) $lineAccountId;

    // Writes touch shop-wide branding/overrides → restrict to admin/marketing
    // (matches the sidebar gate: 'roles' => ['admin', 'marketing']).
    $role = $GLOBALS['currentUser']['role'] ?? ($_SESSION['user_role'] ?? '');
    $canManageFlex = in_array($role, ['admin', 'super_admin', 'marketing'], true);
    $writeActions = ['save_theme', 'bind_template', 'set_override_active'];
    if (in_array($action, $writeActions, true) && !$canManageFlex) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    try {
        switch ($action) {
            case 'get_theme': {
                $stmt = $db->prepare("SELECT * FROM flex_brand_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                echo json_encode(['success' => true, 'theme' => $row, 'defaults' => [
                    'primary_color' => FlexTemplates::BRAND_PRIMARY,
                    'primary_dark'  => FlexTemplates::BRAND_PRIMARY_DARK,
                ]]);
                break;
            }

            case 'save_theme': {
                $fields = [
                    'primary_color'     => fs_hex($_POST['primary_color'] ?? null),
                    'primary_dark'      => fs_hex($_POST['primary_dark'] ?? null),
                    'accent_color'      => fs_hex($_POST['accent_color'] ?? null),
                    'logo_url'          => fs_url($_POST['logo_url'] ?? null),
                    'sender_name'       => (trim((string) ($_POST['sender_name'] ?? '')) ?: null),
                    'sender_icon_url'   => fs_url($_POST['sender_icon_url'] ?? null),
                    'shop_display_name' => (trim((string) ($_POST['shop_display_name'] ?? '')) ?: null),
                    'footer_text'       => (trim((string) ($_POST['footer_text'] ?? '')) ?: null),
                    'corner_style'      => (in_array($_POST['corner_style'] ?? '', ['none', 'sm', 'md', 'lg'], true) ? $_POST['corner_style'] : null),
                ];
                if ($fields['sender_name'] !== null) {
                    $fields['sender_name'] = mb_substr($fields['sender_name'], 0, 255);
                }
                if ($fields['shop_display_name'] !== null) {
                    $fields['shop_display_name'] = mb_substr($fields['shop_display_name'], 0, 255);
                }
                if ($fields['footer_text'] !== null) {
                    $fields['footer_text'] = mb_substr($fields['footer_text'], 0, 500);
                }

                $cols = array_keys($fields);
                $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
                $updateSet = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
                $sql = "INSERT INTO flex_brand_settings (line_account_id, " . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ")
                        VALUES ($placeholders)
                        ON DUPLICATE KEY UPDATE $updateSet";
                $stmt = $db->prepare($sql);
                $stmt->execute(array_merge([$lineAccountId], array_values($fields)));
                echo json_encode(['success' => true]);
                break;
            }

            case 'get_slot_overrides': {
                $slot = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['slot'] ?? ''));
                $stmt = $db->prepare("SELECT id, name, is_active, created_at FROM flex_templates WHERE line_account_id = ? AND slot_key = ? ORDER BY id DESC");
                $stmt->execute([$lineAccountId, $slot]);
                echo json_encode(['success' => true, 'overrides' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
                break;
            }

            case 'set_override_active': {
                $slot = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['slot'] ?? ''));
                $id = (int) ($_POST['id'] ?? 0);
                $active = (int) ($_POST['active'] ?? 0) === 1 ? 1 : 0;
                $db->beginTransaction();
                // one active override per (shop, slot)
                $stmt = $db->prepare("UPDATE flex_templates SET is_active = 0 WHERE line_account_id = ? AND slot_key = ?");
                $stmt->execute([$lineAccountId, $slot]);
                if ($active && $id > 0) {
                    $stmt = $db->prepare("UPDATE flex_templates SET is_active = 1 WHERE id = ? AND line_account_id = ? AND slot_key = ?");
                    $stmt->execute([$id, $lineAccountId, $slot]);
                }
                $db->commit();
                echo json_encode(['success' => true]);
                break;
            }

            case 'list_templates': {
                // Shop's saved Flex Builder templates, available to bind to a slot.
                $stmt = $db->prepare("SELECT id, name, category, slot_key FROM flex_templates WHERE line_account_id = ? ORDER BY id DESC LIMIT 100");
                $stmt->execute([$lineAccountId]);
                echo json_encode(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
                break;
            }

            case 'bind_template': {
                $slot = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['slot'] ?? ''));
                $id = (int) ($_POST['id'] ?? 0);
                if ($slot === '' || $id <= 0) {
                    echo json_encode(['success' => false, 'error' => 'bad params']);
                    break;
                }
                // Verify the template belongs to this shop before binding.
                $own = $db->prepare("SELECT id FROM flex_templates WHERE id = ? AND line_account_id = ? LIMIT 1");
                $own->execute([$id, $lineAccountId]);
                if (!$own->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'not found']);
                    break;
                }
                $db->beginTransaction();
                // Bind chosen template to this slot + make it the sole active one for the slot.
                $stmt = $db->prepare("UPDATE flex_templates SET is_active = 0 WHERE line_account_id = ? AND slot_key = ?");
                $stmt->execute([$lineAccountId, $slot]);
                $stmt = $db->prepare("UPDATE flex_templates SET slot_key = ?, is_active = 1 WHERE id = ? AND line_account_id = ?");
                $stmt->execute([$slot, $id, $lineAccountId]);
                $db->commit();
                echo json_encode(['success' => true]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'unknown action']);
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Flex Studio';
require_once __DIR__ . '/includes/header.php';
// header.php provides $currentBotId and $isOdooMode

$slots = flex_studio_slots();
// group, honouring Odoo kill-switch
$grouped = [];
foreach ($slots as $s) {
    if (!empty($s['odoo']) && empty($isOdooMode)) {
        continue;
    }
    $grouped[$s['group']][] = $s;
}
?>
<script src="assets/js/flex-preview.js"></script>

<div class="max-w-[1400px] mx-auto px-4 py-4">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-800">🎨 Flex Studio</h1>
            <p class="text-xs text-gray-500">รวม Flex ทุกระบบที่ส่งให้ลูกค้า • ตั้งค่าธีมแยกแต่ละร้าน • พรีวิวเหมือนจริง</p>
        </div>
        <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
            <button onclick="fsTab('gallery')" id="tab-gallery" class="fs-tab px-4 py-1.5 rounded-md text-sm font-semibold">แกลเลอรี</button>
            <button onclick="fsTab('theme')" id="tab-theme" class="fs-tab px-4 py-1.5 rounded-md text-sm font-semibold">ธีมร้าน</button>
        </div>
    </div>

    <!-- ==================== Gallery ==================== -->
    <div id="pane-gallery" class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4">
        <!-- slot list -->
        <div class="bg-white rounded-xl border overflow-hidden">
            <div class="max-h-[calc(100vh-220px)] overflow-y-auto p-2">
                <?php foreach ($grouped as $group => $items): ?>
                    <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider px-2 pt-3 pb-1"><?= htmlspecialchars($group) ?></div>
                    <?php foreach ($items as $s): ?>
                        <button type="button"
                            class="fs-slot w-full text-left flex items-center gap-2 px-2 py-2 rounded-lg hover:bg-gray-50 transition"
                            data-slot="<?= htmlspecialchars($s['key']) ?>"
                            data-label="<?= htmlspecialchars($s['label']) ?>"
                            data-producer="<?= htmlspecialchars($s['producer']) ?>"
                            onclick="fsSelect(this)">
                            <span class="text-lg w-6 text-center"><?= $s['icon'] ?></span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm text-gray-800 truncate"><?= htmlspecialchars($s['label']) ?></span>
                                <span class="block text-[10px] text-gray-400 truncate"><?= htmlspecialchars($s['producer']) ?></span>
                            </span>
                            <span class="fs-badge hidden text-[9px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full font-bold">ปรับแล้ว</span>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- preview panel -->
        <div class="bg-gray-50 rounded-xl border p-4">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 id="fs-title" class="font-bold text-gray-800">เลือก Flex เพื่อดูตัวอย่าง</h2>
                    <p id="fs-producer" class="text-xs text-gray-400">— ส่งจาก —</p>
                </div>
                <div class="flex gap-2">
                    <button id="fs-refresh" onclick="fsLoadPreview()" class="hidden px-3 py-1.5 text-xs rounded-lg bg-white border hover:bg-gray-50">🔄 รีเฟรช</button>
                    <a id="fs-edit" href="#" class="hidden px-3 py-1.5 text-xs rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">✏️ ปรับดีไซน์เฉพาะใบนี้</a>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,360px)_1fr] gap-4">
                <!-- LINE phone frame -->
                <div class="fs-phone">
                    <div class="fs-phone-top">
                        <span class="fs-dot"></span>
                        <span class="text-xs font-semibold" id="fs-phone-name">LINE</span>
                    </div>
                    <div class="fs-phone-body">
                        <div id="fs-preview" class="w-full"></div>
                    </div>
                </div>

                <!-- override panel -->
                <div>
                    <div class="bg-white rounded-lg border p-3">
                        <h3 class="text-sm font-bold text-gray-700 mb-1">ปรับเฉพาะใบนี้ (Override)</h3>
                        <p class="text-[11px] text-gray-400 mb-2">ถ้าไม่ตั้ง จะใช้ดีไซน์มาตรฐาน + ธีมร้านของคุณ</p>
                        <div id="fs-overrides" class="space-y-1 text-sm text-gray-500">—</div>
                        <div class="border-t mt-3 pt-3">
                            <div class="text-[11px] text-gray-500 mb-1">ผูกเทมเพลตจาก Flex Builder เข้ากับ slot นี้:</div>
                            <div class="flex gap-2">
                                <select id="fs-bind-select" class="flex-1 border rounded-lg px-2 py-1.5 text-sm"><option value="">— เลือกเทมเพลต —</option></select>
                                <button onclick="fsBindTemplate()" class="px-3 py-1.5 text-xs rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">ผูก</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-[11px] text-gray-400 leading-relaxed">
                        💡 ตัวอย่างนี้ใช้โลโก้/ชื่อร้าน/สีจากการตั้งค่าจริงของร้านคุณ + เนื้อหาตัวอย่าง
                        เพื่อให้เหมือนที่ลูกค้าเห็นมากที่สุด
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== Theme ==================== -->
    <div id="pane-theme" class="hidden grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4">
        <div class="bg-white rounded-xl border p-5 space-y-4">
            <h2 class="font-bold text-gray-800">ธีมแบรนด์ร้าน</h2>
            <p class="text-xs text-gray-500 -mt-2">แก้ที่นี่ครั้งเดียว มีผลกับ Flex ทุกใบที่ระบบส่งให้ลูกค้า</p>

            <div class="grid grid-cols-2 gap-4">
                <label class="block text-sm">สีหลักแบรนด์
                    <input type="color" id="t_primary_color" class="mt-1 w-full h-10 rounded border cursor-pointer">
                </label>
                <label class="block text-sm">สีเข้ม (ฉลากยา)
                    <input type="color" id="t_primary_dark" class="mt-1 w-full h-10 rounded border cursor-pointer">
                </label>
            </div>

            <label class="block text-sm">ชื่อร้านที่แสดงใน Flex
                <input type="text" id="t_shop_display_name" placeholder="เว้นว่าง = ใช้ชื่อร้านเดิม" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
            </label>

            <div class="grid grid-cols-2 gap-4">
                <label class="block text-sm">ชื่อผู้ส่ง (บน LINE)
                    <input type="text" id="t_sender_name" placeholder="เช่น ร้านยาของคุณ" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
                </label>
                <label class="block text-sm">ไอคอนผู้ส่ง (URL)
                    <input type="url" id="t_sender_icon_url" placeholder="https://..." class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
                </label>
            </div>

            <label class="block text-sm">โลโก้ร้าน (URL)
                <input type="url" id="t_logo_url" placeholder="https://..." class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
            </label>

            <label class="block text-sm">ข้อความท้าย Flex
                <input type="text" id="t_footer_text" placeholder="เช่น ขอบคุณที่ใช้บริการ" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
            </label>

            <div class="flex gap-2 pt-2">
                <button onclick="fsSaveTheme()" class="px-5 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700">💾 บันทึกธีม</button>
                <button onclick="fsResetThemeForm()" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm">ล้างค่า</button>
                <span id="fs-theme-status" class="self-center text-xs text-gray-400"></span>
            </div>
        </div>

        <!-- live theme preview -->
        <div class="bg-gray-50 rounded-xl border p-4">
            <div class="text-xs text-gray-400 mb-2">ตัวอย่างสด (ใบเสร็จ)</div>
            <div class="fs-phone">
                <div class="fs-phone-top"><span class="fs-dot"></span><span class="text-xs font-semibold">LINE</span></div>
                <div class="fs-phone-body"><div id="fs-theme-preview" class="w-full"></div></div>
            </div>
        </div>
    </div>
</div>

<style>
.fs-tab { color:#6b7280; }
.fs-tab.active { background:#fff; color:#111827; box-shadow:0 1px 2px rgba(0,0,0,.08); }
.fs-slot.active { background:#eef2ff; }
.fs-phone { background:#7494c0; border-radius:22px; padding:10px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
.fs-phone-top { display:flex; align-items:center; gap:6px; color:#fff; padding:2px 8px 8px; }
.fs-dot { width:8px; height:8px; border-radius:50%; background:#fff; opacity:.8; }
.fs-phone-body { background:#8ba9d0; border-radius:14px; padding:12px; min-height:360px; max-height:calc(100vh-260px); overflow-y:auto; }
</style>

<script>
function fsEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
const FS_SLOT_META = {};
document.querySelectorAll('.fs-slot').forEach(b => {
    FS_SLOT_META[b.dataset.slot] = { label: b.dataset.label, producer: b.dataset.producer, el: b };
});
let fsCurrentSlot = null;

function fsTab(name) {
    document.getElementById('pane-gallery').classList.toggle('hidden', name !== 'gallery');
    document.getElementById('pane-theme').classList.toggle('hidden', name !== 'theme');
    document.getElementById('tab-gallery').classList.toggle('active', name === 'gallery');
    document.getElementById('tab-theme').classList.toggle('active', name === 'theme');
    if (name === 'theme') fsLoadTheme();
}

function fsSelect(el) {
    document.querySelectorAll('.fs-slot').forEach(s => s.classList.remove('active'));
    el.classList.add('active');
    fsCurrentSlot = el.dataset.slot;
    document.getElementById('fs-title').textContent = el.dataset.label;
    document.getElementById('fs-producer').textContent = 'ส่งจาก: ' + el.dataset.producer;
    document.getElementById('fs-refresh').classList.remove('hidden');
    const edit = document.getElementById('fs-edit');
    edit.classList.remove('hidden');
    edit.href = 'flex-builder.php?slot=' + encodeURIComponent(fsCurrentSlot);
    fsLoadPreview();
    fsLoadOverrides();
    fsLoadBindOptions();
}

function fsLoadPreview() {
    if (!fsCurrentSlot) return;
    const box = document.getElementById('fs-preview');
    box.innerHTML = '<div style="color:#fff;text-align:center;padding:40px">กำลังโหลด…</div>';
    fetch('api/flex-preview.php?slot=' + encodeURIComponent(fsCurrentSlot))
        .then(r => r.json())
        .then(d => {
            if (d.success) FlexPreview.render('fs-preview', d.contents);
            else box.innerHTML = '<div style="color:#fff;padding:20px">โหลดไม่สำเร็จ: ' + (d.error || '') + '</div>';
        })
        .catch(() => box.innerHTML = '<div style="color:#fff;padding:20px">เกิดข้อผิดพลาด</div>');
}

function fsPost(action, data) {
    const body = new URLSearchParams(Object.assign({ action }, data));
    return fetch('flex-studio.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    }).then(r => r.json());
}

function fsLoadOverrides() {
    const box = document.getElementById('fs-overrides');
    box.innerHTML = '—';
    fsPost('get_slot_overrides', { slot: fsCurrentSlot }).then(d => {
        const meta = FS_SLOT_META[fsCurrentSlot];
        let hasActive = false;
        if (!d.success || !d.overrides || !d.overrides.length) {
            box.innerHTML = '<div class="text-gray-400 text-xs">ยังไม่มี override — ใช้ดีไซน์มาตรฐาน</div>';
        } else {
            box.innerHTML = d.overrides.map(o => {
                if (o.is_active == 1) hasActive = true;
                return `<label class="flex items-center gap-2 py-1">
                    <input type="radio" name="ovr" ${o.is_active == 1 ? 'checked' : ''} onchange="fsSetOverride(${parseInt(o.id, 10)})">
                    <span class="text-sm text-gray-700">${fsEsc(o.name || 'Template #' + o.id)}</span>
                    ${o.is_active == 1 ? '<span class="text-[9px] bg-green-100 text-green-700 px-1.5 rounded-full">ใช้งาน</span>' : ''}
                </label>`;
            }).join('') +
            `<label class="flex items-center gap-2 py-1 border-t mt-1 pt-2">
                <input type="radio" name="ovr" ${!hasActive ? 'checked' : ''} onchange="fsSetOverride(0)">
                <span class="text-sm text-gray-500">ใช้ดีไซน์มาตรฐาน</span>
            </label>`;
        }
        if (meta) meta.el.querySelector('.fs-badge').classList.toggle('hidden', !hasActive);
    });
}

function fsSetOverride(id) {
    fsPost('set_override_active', { slot: fsCurrentSlot, id, active: id > 0 ? 1 : 0 }).then(d => {
        if (d.success) { fsLoadPreview(); fsLoadOverrides(); }
    });
}

function fsLoadBindOptions() {
    const sel = document.getElementById('fs-bind-select');
    fsPost('list_templates', {}).then(d => {
        sel.innerHTML = '<option value="">— เลือกเทมเพลต —</option>';
        if (d.success) (d.templates || []).forEach(t => {
            const bound = t.slot_key ? ' [' + fsEsc(t.slot_key) + ']' : '';
            sel.insertAdjacentHTML('beforeend', `<option value="${parseInt(t.id, 10)}">${fsEsc(t.name || 'Template #' + t.id)}${bound}</option>`);
        });
    });
}

function fsBindTemplate() {
    const id = document.getElementById('fs-bind-select').value;
    if (!id) return;
    fsPost('bind_template', { slot: fsCurrentSlot, id }).then(d => {
        if (d.success) { fsLoadPreview(); fsLoadOverrides(); }
        else alert('ผูกไม่สำเร็จ: ' + (d.error || ''));
    });
}

// ── Theme ──
const FS_THEME_FIELDS = ['primary_color', 'primary_dark', 'accent_color', 'logo_url', 'sender_name', 'sender_icon_url', 'shop_display_name', 'footer_text'];
let fsThemeDefaults = { primary_color: '#06C755', primary_dark: '#006400' };

function fsLoadTheme() {
    fsPost('get_theme', {}).then(d => {
        if (!d.success) return;
        fsThemeDefaults = d.defaults || fsThemeDefaults;
        const t = d.theme || {};
        document.getElementById('t_primary_color').value = t.primary_color || fsThemeDefaults.primary_color;
        document.getElementById('t_primary_dark').value = t.primary_dark || fsThemeDefaults.primary_dark;
        ['shop_display_name', 'sender_name', 'sender_icon_url', 'logo_url', 'footer_text'].forEach(f => {
            const el = document.getElementById('t_' + f);
            if (el) el.value = t[f] || '';
        });
        fsThemePreview();
    });
}

function fsSaveTheme() {
    const data = {};
    FS_THEME_FIELDS.forEach(f => { const el = document.getElementById('t_' + f); if (el) data[f] = el.value; });
    const st = document.getElementById('fs-theme-status');
    st.textContent = 'กำลังบันทึก…';
    fsPost('save_theme', data).then(d => {
        st.textContent = d.success ? '✓ บันทึกแล้ว' : ('ผิดพลาด: ' + (d.error || ''));
        if (d.success) { fsThemePreview(); setTimeout(() => st.textContent = '', 2500); }
    });
}

function fsResetThemeForm() {
    document.getElementById('t_primary_color').value = fsThemeDefaults.primary_color;
    document.getElementById('t_primary_dark').value = fsThemeDefaults.primary_dark;
    ['shop_display_name', 'sender_name', 'sender_icon_url', 'logo_url', 'footer_text'].forEach(f => document.getElementById('t_' + f).value = '');
}

function fsThemePreview() {
    fetch('api/flex-preview.php?slot=order_receipt')
        .then(r => r.json())
        .then(d => { if (d.success) FlexPreview.render('fs-theme-preview', d.contents); });
}

// init
fsTab('gallery');
const firstSlot = document.querySelector('.fs-slot');
if (firstSlot) fsSelect(firstSlot);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
