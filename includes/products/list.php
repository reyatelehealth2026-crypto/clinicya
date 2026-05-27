<?php
/**
 * Tab 1: สินค้า (Products List)
 *
 * Heavy lifting (CRUD, bulk actions, stock count, dispensing fee, duplicate
 * SKU check, lot adjust) lives in /api/products.php. This file renders the UI
 * and wires it to the API with fetch().
 *
 * Reads from products.php: $db, $lineAccountId, $activityLogger.
 */
require_once __DIR__ . '/_lookup_helpers.php';

// Pre-load filter dropdowns
$categories = $drugGroups = $units = $genericNames = $storageLocations = $labelTemplates = [];
try {
    $stmt = $db->prepare('SELECT id, name FROM item_categories WHERE line_account_id=? AND (is_active=1 OR is_active IS NULL) ORDER BY display_order, name');
    $stmt->execute([$lineAccountId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, name_th FROM drug_groups WHERE line_account_id=? AND is_active=1 ORDER BY name_th');
    $stmt->execute([$lineAccountId]);
    $drugGroups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, name FROM product_units WHERE line_account_id=? AND is_active=1 ORDER BY name');
    $stmt->execute([$lineAccountId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, generic_name FROM generic_names WHERE line_account_id=? ORDER BY generic_name');
    $stmt->execute([$lineAccountId]);
    $genericNames = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, name FROM storage_locations WHERE line_account_id=? AND is_active=1 ORDER BY code, name');
    $stmt->execute([$lineAccountId]);
    $storageLocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, name FROM drug_label_templates WHERE line_account_id=? AND is_active=1 ORDER BY name');
    $stmt->execute([$lineAccountId]);
    $labelTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    // tables may not exist yet pre-migration — graceful degrade
    $error = $error ?? ('ไม่สามารถโหลดข้อมูลอ้างอิงได้: ' . $e->getMessage() . ' (อาจยังไม่ได้รัน migration)');
}

$csrf = reya_csrf_token();
// Allow deep-link from generic-names tab: ?tab=list&generic_id=N
$preGenericId = isset($_GET['generic_id']) ? (int)$_GET['generic_id'] : 0;
?>
<style>
.product-row.selected td { background:#eef2ff; }
details.adv summary { cursor:pointer; padding:8px 0; font-weight:500; color:#475569; }
details.adv[open] summary { color:#1e293b; }
</style>

<!-- ===== Action toolbar (top) ===== -->
<div class="flex flex-wrap items-center gap-2 mb-4">
    <button class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700" onclick="openProductModal()"><i class="fas fa-plus mr-1"></i> เพิ่มสินค้า</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="dupCheck()"><i class="fas fa-tools mr-1"></i> ตรวจสอบรหัสซ้ำ</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openBulkLabelByGeneric()"><i class="fas fa-pills mr-1"></i> ตั้งฉลากตาม Generic</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openBulkLabelByUsage()"><i class="fas fa-tag mr-1"></i> ตั้งฉลากตามวิธีใช้</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openMovements()"><i class="fas fa-history mr-1"></i> ประวัติการตัดสต๊อก</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openDispensingFee()"><i class="fas fa-coins mr-1"></i> ค่าหยิบยา</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openStockCount()"><i class="fas fa-calculator mr-1"></i> นับสินค้า</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openLotAdjust()"><i class="fas fa-vials mr-1"></i> ปรับ Lot/คงเหลือ</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="printTags()"><i class="fas fa-tag mr-1"></i> ป้ายราคา</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="printStickers()"><i class="fas fa-qrcode mr-1"></i> สติ๊กเกอร์สินค้า</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openLiquidDose()"><i class="fas fa-tint mr-1"></i> ยาน้ำเด็ก</button>
    <button class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm" onclick="openStockSummary()"><i class="fas fa-chart-pie mr-1"></i> สรุปยอดคงเหลือ</button>
</div>

<!-- ===== Filters ===== -->
<div class="grid grid-cols-1 md:grid-cols-12 gap-2 mb-3">
    <input id="fSearch" placeholder="ค้นหาชื่อ / SKU / generic..." class="md:col-span-4 px-3 py-2 border border-slate-300 rounded-lg text-sm">
    <select id="fCategory" class="md:col-span-2 px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">หมวดทั้งหมด</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= reya_h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select id="fGroup" class="md:col-span-2 px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">กลุ่มยาทั้งหมด</option>
        <?php foreach ($drugGroups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= reya_h($g['name_th']) ?></option><?php endforeach; ?>
    </select>
    <select id="fStatus" class="md:col-span-2 px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">สถานะทั้งหมด</option>
        <option value="active">ขายอยู่</option>
        <option value="hidden">ซ่อนจากร้าน</option>
        <option value="out_of_stock">หมดสต๊อก</option>
    </select>
    <select id="fRx" class="md:col-span-2 px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Rx ทั้งหมด</option>
        <option value="1">ต้องใช้ใบสั่งยา</option>
        <option value="0">ไม่ต้องใช้</option>
    </select>
</div>

<!-- ===== Table ===== -->
<div id="bulkBar" class="hidden mb-2 px-3 py-2 bg-indigo-50 border border-indigo-200 rounded-lg flex items-center gap-3 text-sm">
    <span>เลือก <b id="bulkCount">0</b> รายการ</span>
    <button class="px-3 py-1 rounded-md bg-white border border-slate-300 hover:bg-slate-100" onclick="bulkSetActive(1)">เปิดขาย</button>
    <button class="px-3 py-1 rounded-md bg-white border border-slate-300 hover:bg-slate-100" onclick="bulkSetActive(0)">ซ่อน</button>
    <button class="px-3 py-1 rounded-md bg-white border border-slate-300 hover:bg-slate-100" onclick="bulkAssignLabel()">ตั้งฉลาก</button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-2 py-2 w-8"><input type="checkbox" id="chkAll" onchange="toggleAllRows(this.checked)"></th>
                <th class="px-2 py-2 text-left w-14">รูป</th>
                <th class="px-2 py-2 text-left">รหัส</th>
                <th class="px-2 py-2 text-left">ชื่อสินค้า</th>
                <th class="px-2 py-2 text-left">Generic</th>
                <th class="px-2 py-2 text-left">กลุ่มยา</th>
                <th class="px-2 py-2 text-left">หน่วย</th>
                <th class="px-2 py-2 text-right">คงเหลือ</th>
                <th class="px-2 py-2 text-right">ราคา</th>
                <th class="px-2 py-2 text-right">ทุน</th>
                <th class="px-2 py-2 text-right">%กำไร</th>
                <th class="px-2 py-2 text-center">สถานะ</th>
                <th class="px-2 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody id="prodTbody">
            <tr><td colspan="13" class="px-3 py-6 text-center text-slate-400">กำลังโหลด...</td></tr>
        </tbody>
    </table>
</div>
<div id="prodPager" class="flex items-center justify-between mt-3 text-sm">
    <div class="text-slate-500" id="prodPagerInfo"></div>
    <div class="flex gap-1" id="prodPagerBtns"></div>
</div>

<!-- ===== Add/Edit Product Modal ===== -->
<div id="pmModal" class="fixed inset-0 bg-black/40 hidden items-start justify-center z-50 overflow-y-auto py-6">
    <form id="pmForm" class="bg-white rounded-xl w-full max-w-3xl p-6 space-y-3 my-auto" onsubmit="return saveProduct(event)">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold" id="pmModalTitle">เพิ่มสินค้า</h3>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="closeProductModal()"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" name="id" id="pmId" value="">

        <!-- A. Basic -->
        <div class="border border-slate-200 rounded-lg p-3">
            <h4 class="font-medium text-slate-700 mb-2"><i class="fas fa-info-circle mr-1 text-indigo-500"></i>ข้อมูลพื้นฐาน</h4>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-slate-700 mb-1">SKU <span class="text-rose-500">*</span></label>
                    <input name="sku" id="pmSku" class="w-full border border-slate-300 rounded-lg px-3 py-2" required onblur="checkSku(this.value)">
                    <div id="pmSkuHint" class="text-xs mt-1"></div>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">หมวด</label>
                    <select name="category_id" id="pmCategory" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">— เลือกหมวด —</option>
                        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= reya_h($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">ชื่อสินค้า (TH) <span class="text-rose-500">*</span></label>
                    <input name="name" id="pmName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">ชื่อสินค้า (EN)</label>
                    <input name="name_en" id="pmNameEn" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">หน่วยขาย <span class="text-rose-500">*</span></label>
                    <select name="unit_id" id="pmUnitId" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
                        <option value="">— เลือกหน่วย —</option>
                        <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= reya_h($u['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">หรือพิมพ์หน่วย</label>
                    <input name="unit" id="pmUnit" placeholder="เช่น ชิ้น" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <!-- B. Pricing -->
        <div class="border border-slate-200 rounded-lg p-3">
            <h4 class="font-medium text-slate-700 mb-2"><i class="fas fa-tag mr-1 text-emerald-500"></i>ราคา</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><label class="block text-sm text-slate-700 mb-1">ราคาทุน</label><input type="number" step="0.01" name="cost_price" id="pmCost" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm text-slate-700 mb-1">ราคาขาย <span class="text-rose-500">*</span></label><input type="number" step="0.01" name="price" id="pmPrice" class="w-full border border-slate-300 rounded-lg px-3 py-2" required></div>
                <div><label class="block text-sm text-slate-700 mb-1">ราคาโปร</label><input type="number" step="0.01" name="sale_price" id="pmSale" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm text-slate-700 mb-1">ค่าหยิบยา</label><input type="number" step="0.01" name="dispensing_fee" id="pmFee" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
            </div>
        </div>

        <!-- C. Stock -->
        <div class="border border-slate-200 rounded-lg p-3">
            <h4 class="font-medium text-slate-700 mb-2"><i class="fas fa-boxes mr-1 text-amber-500"></i>สต๊อก</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><label class="block text-sm text-slate-700 mb-1">คงเหลือ</label><input type="number" name="stock" id="pmStock" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm text-slate-700 mb-1">Min stock</label><input type="number" name="min_stock" id="pmMin" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm text-slate-700 mb-1">Reorder point</label><input type="number" name="reorder_point" id="pmRop" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">พื้นที่เก็บ</label>
                    <select name="storage_location_id" id="pmLoc" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">—</option>
                        <?php foreach ($storageLocations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= reya_h($l['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- D. Drug info (collapsible) -->
        <details class="adv border border-slate-200 rounded-lg p-3" <?= $preGenericId ? 'open' : '' ?>>
            <summary><i class="fas fa-pills mr-1 text-rose-500"></i>ข้อมูลยา</summary>
            <div class="grid grid-cols-2 gap-3 pt-2">
                <div>
                    <label class="block text-sm text-slate-700 mb-1">Generic name</label>
                    <select name="generic_name_id" id="pmGeneric" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">—</option>
                        <?php foreach ($genericNames as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $preGenericId === (int)$g['id'] ? 'selected' : '' ?>><?= reya_h($g['generic_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">กลุ่มยา</label>
                    <select name="drug_group_id" id="pmGroup" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">—</option>
                        <?php foreach ($drugGroups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= reya_h($g['name_th']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="block text-sm text-slate-700 mb-1">รูปแบบยา (dosage form)</label><input name="dosage_form" id="pmDose" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm text-slate-700 mb-1">ตัวยาสำคัญ</label><input name="active_ingredient" id="pmAi" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">ประเภทยา (ขย.)</label>
                    <select name="drug_category" id="pmDrugCat" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">—</option>
                        <option value="otc">OTC (ขายทั่วไป)</option>
                        <option value="dangerous">อันตราย</option>
                        <option value="controlled">ควบคุมพิเศษ</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">วิธีใช้ (usage method)</label>
                    <input name="usage_method" id="pmUsageMethod" placeholder="oral / topical / injection" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="requires_prescription" id="pmRx"> ต้องใช้ใบสั่งยา</label>
                <div></div>
                <div class="col-span-2"><label class="block text-sm text-slate-700 mb-1">คำเตือน</label><textarea name="prescription_warning" id="pmWarn" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea></div>
                <div class="col-span-2"><label class="block text-sm text-slate-700 mb-1">การเก็บรักษา</label><input name="storage_condition" id="pmStorage" class="w-full border border-slate-300 rounded-lg px-3 py-2"></div>
            </div>
        </details>

        <!-- E. Label (collapsible) -->
        <details class="adv border border-slate-200 rounded-lg p-3">
            <summary><i class="fas fa-tag mr-1 text-cyan-500"></i>ฉลากยา</summary>
            <div class="grid grid-cols-2 gap-3 pt-2">
                <div>
                    <label class="block text-sm text-slate-700 mb-1">เทมเพลตฉลาก</label>
                    <select name="label_template_id" id="pmLabelTpl" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">— ไม่ผูก —</option>
                        <?php foreach ($labelTemplates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= reya_h($t['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700 mb-1">ภาษาฉลาก</label>
                    <select name="label_language" id="pmLang" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        <option value="th">ไทย</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div class="col-span-2"><label class="block text-sm text-slate-700 mb-1">วิธีใช้เริ่มต้น</label><textarea name="default_usage_text" id="pmUsage" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea></div>
                <div class="col-span-2"><label class="block text-sm text-slate-700 mb-1">เตือนเมื่อ...</label><textarea name="default_warning_text" id="pmWarn2" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea></div>
            </div>
        </details>

        <!-- F. Media -->
        <details class="adv border border-slate-200 rounded-lg p-3">
            <summary><i class="fas fa-image mr-1 text-violet-500"></i>รูปสินค้า</summary>
            <div class="pt-2">
                <label class="block text-sm text-slate-700 mb-1">URL รูปสินค้า</label>
                <input name="image_url" id="pmImg" class="w-full border border-slate-300 rounded-lg px-3 py-2" placeholder="https://...">
                <p class="text-xs text-slate-500 mt-1">การอัปโหลดไฟล์ใช้ผ่านระบบ media-server เดิม แล้ววางลิงก์ที่นี่</p>
            </div>
        </details>

        <!-- G. Visibility -->
        <div class="border border-slate-200 rounded-lg p-3">
            <h4 class="font-medium text-slate-700 mb-2"><i class="fas fa-eye mr-1 text-blue-500"></i>การแสดงผล</h4>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="is_active" id="pmActive" checked> แสดงในหน้าร้าน Mini App</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="is_featured" id="pmFeatured"> Featured</label>
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeProductModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<!-- ===== Stock Count Modal (with session storage) ===== -->
<div id="scModal" class="fixed inset-0 bg-black/40 hidden items-start justify-center z-50 overflow-y-auto py-6">
    <div class="bg-white rounded-xl w-full max-w-4xl p-6 my-auto">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold">นับสินค้า (Stock Count)</h3>
            <button class="text-slate-400 hover:text-slate-700" onclick="closeStockCount()"><i class="fas fa-times"></i></button>
        </div>
        <div class="text-sm text-slate-600 mb-3">
            พิมพ์จำนวนที่นับได้จริงในแต่ละสินค้า ระบบจะ <b>บันทึกความคืบหน้าใน sessionStorage</b> ของเบราว์เซอร์ ไม่หายเมื่อรีเฟรช
            <button class="ml-3 text-xs text-rose-600 hover:underline" onclick="clearCountProgress()">ล้างความคืบหน้า</button>
        </div>
        <div class="flex gap-2 mb-2">
            <input id="scSearch" placeholder="ค้นหาเพื่อกรองรายการนับ..." class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" oninput="renderCountRows()">
            <input id="scSessionName" placeholder="ชื่อรอบนับ เช่น สิ้นเดือน 05/2569" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
        </div>
        <div class="max-h-[55vh] overflow-y-auto border border-slate-200 rounded-lg">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 sticky top-0">
                    <tr>
                        <th class="px-2 py-2 text-left">SKU</th>
                        <th class="px-2 py-2 text-left">ชื่อ</th>
                        <th class="px-2 py-2 text-right">คงเหลือระบบ</th>
                        <th class="px-2 py-2 text-right w-32">นับได้</th>
                        <th class="px-2 py-2 text-right">Δ</th>
                    </tr>
                </thead>
                <tbody id="scTbody"></tbody>
            </table>
        </div>
        <div class="flex justify-end gap-2 mt-3">
            <button class="px-4 py-2 rounded-lg border" onclick="closeStockCount()">ปิด (เก็บค่า)</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg" onclick="submitStockCount()">บันทึกและปรับสต๊อก</button>
        </div>
    </div>
</div>

<!-- ===== Generic info/help modal (movements, stock summary, etc.) ===== -->
<div id="infoModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-3xl p-6 max-h-[80vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold" id="infoTitle">รายละเอียด</h3>
            <button class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('infoModal').classList.add('hidden');document.getElementById('infoModal').classList.remove('flex')"><i class="fas fa-times"></i></button>
        </div>
        <div id="infoBody" class="text-sm"></div>
    </div>
</div>

<script>
const PROD_API   = '/api/products.php';
const CSRF       = <?= json_encode($csrf) ?>;
let prodPage = 1, prodTotal = 0, prodPerPage = 50, prodAll = [];

// ---------- Filtering & pagination ----------
function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }
const reload = debounce(() => loadProducts(1), 300);
['fSearch','fCategory','fGroup','fStatus','fRx'].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener(id === 'fSearch' ? 'input' : 'change', reload);
});

async function loadProducts(page){
    prodPage = page || 1;
    const tbody = document.getElementById('prodTbody');
    tbody.innerHTML = '<tr><td colspan="13" class="px-3 py-6 text-center text-slate-400">กำลังโหลด...</td></tr>';
    const params = new URLSearchParams({
        action:'list', page: prodPage, per_page: prodPerPage,
        search: document.getElementById('fSearch').value || '',
        category_id: document.getElementById('fCategory').value || '',
        drug_group_id: document.getElementById('fGroup').value || '',
        status: document.getElementById('fStatus').value || '',
        rx: document.getElementById('fRx').value || ''
    });
    try {
        const r = await fetch(PROD_API + '?' + params.toString());
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'load failed');
        prodAll  = j.items || [];
        prodTotal = j.total || 0;
        renderProductRows();
        renderPager();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="13" class="px-3 py-6 text-center text-rose-500">' + (e.message || 'โหลดล้มเหลว') + '</td></tr>';
    }
}
function renderProductRows(){
    const tb = document.getElementById('prodTbody');
    if (!prodAll.length){ tb.innerHTML = '<tr><td colspan="13" class="px-3 py-6 text-center text-slate-400">ไม่พบสินค้า</td></tr>'; return; }
    tb.innerHTML = prodAll.map(p => {
        const stock = Number(p.stock||0), price = Number(p.price||0), cost = Number(p.cost_price||0);
        const margin = price > 0 && cost > 0 ? Math.round(((price - cost)/price)*100) : 0;
        const status = [];
        if (Number(p.is_active)) status.push('<span class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">ขาย</span>');
        else status.push('<span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">ซ่อน</span>');
        if (stock <= 0) status.push('<span class="px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">หมด</span>');
        if (Number(p.requires_prescription)) status.push('<span class="px-2 py-0.5 rounded-full text-xs bg-violet-100 text-violet-700">Rx</span>');
        return `<tr class="border-t border-slate-100 product-row" data-id="${p.id}">
            <td class="px-2 py-2"><input type="checkbox" class="row-chk" onchange="updateBulkBar()"></td>
            <td class="px-2 py-2">${p.image_url ? `<img src="${p.image_url}" class="w-10 h-10 object-cover rounded">` : '<div class="w-10 h-10 rounded bg-slate-100 flex items-center justify-center text-slate-300"><i class="fas fa-image"></i></div>'}</td>
            <td class="px-2 py-2 font-mono text-xs">${escapeHtml(p.sku||'')}</td>
            <td class="px-2 py-2"><div class="font-medium">${escapeHtml(p.name||'')}</div><div class="text-xs text-slate-500">${escapeHtml(p.name_en||'')}</div></td>
            <td class="px-2 py-2 text-slate-600">${escapeHtml(p.generic_name||'')}</td>
            <td class="px-2 py-2 text-slate-600">${escapeHtml(p.drug_group_name||'')}</td>
            <td class="px-2 py-2 text-slate-600">${escapeHtml(p.unit_name || p.unit || '')}</td>
            <td class="px-2 py-2 text-right tabular-nums ${stock<=0?'text-rose-600 font-semibold':''}">${stock}</td>
            <td class="px-2 py-2 text-right tabular-nums">${price.toFixed(2)}</td>
            <td class="px-2 py-2 text-right tabular-nums text-slate-500">${cost.toFixed(2)}</td>
            <td class="px-2 py-2 text-right tabular-nums">${margin}%</td>
            <td class="px-2 py-2 text-center">${status.join(' ')}</td>
            <td class="px-2 py-2 text-right whitespace-nowrap">
                <button class="text-indigo-600 hover:underline mr-2" onclick="editProduct(${p.id})">แก้ไข</button>
                <button class="text-rose-600 hover:underline" onclick="deleteProduct(${p.id})">ซ่อน</button>
            </td>
        </tr>`;
    }).join('');
}
function renderPager(){
    const pages = Math.max(1, Math.ceil(prodTotal / prodPerPage));
    document.getElementById('prodPagerInfo').textContent = `ทั้งหมด ${prodTotal} รายการ — หน้า ${prodPage} / ${pages}`;
    const btns = [];
    btns.push(`<button class="px-2 py-1 rounded border ${prodPage<=1?'opacity-40':''}" ${prodPage<=1?'disabled':''} onclick="loadProducts(${prodPage-1})">‹</button>`);
    btns.push(`<button class="px-2 py-1 rounded border ${prodPage>=pages?'opacity-40':''}" ${prodPage>=pages?'disabled':''} onclick="loadProducts(${prodPage+1})">›</button>`);
    document.getElementById('prodPagerBtns').innerHTML = btns.join('');
}

function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// ---------- Bulk select ----------
function toggleAllRows(checked){ document.querySelectorAll('.row-chk').forEach(c => c.checked = checked); updateBulkBar(); }
function getSelectedIds(){ return Array.from(document.querySelectorAll('.row-chk')).filter(c=>c.checked).map(c => Number(c.closest('tr').dataset.id)); }
function updateBulkBar(){
    const ids = getSelectedIds();
    const bar = document.getElementById('bulkBar');
    if (!ids.length){ bar.classList.add('hidden'); return; }
    bar.classList.remove('hidden');
    document.getElementById('bulkCount').textContent = ids.length;
}
async function bulkSetActive(active){
    const ids = getSelectedIds(); if (!ids.length) return;
    const r = await fetch(PROD_API + '?action=bulk_set_active', {method:'POST', body: bodyOf({ids: JSON.stringify(ids), is_active: active})});
    const j = await r.json();
    if (j.success){ fireToastSafe('อัปเดตสถานะแล้ว','success'); loadProducts(prodPage); }
    else fireToastSafe(j.error || 'ล้มเหลว', 'error');
}
async function bulkAssignLabel(){
    const ids = getSelectedIds(); if (!ids.length) return;
    const tplId = prompt('ใส่ ID ของเทมเพลตฉลากที่จะใช้:');
    if (!tplId) return;
    const r = await fetch(PROD_API + '?action=bulk_assign_label', {method:'POST', body: bodyOf({ids: JSON.stringify(ids), label_template_id: tplId})});
    const j = await r.json();
    fireToastSafe(j.success ? 'ตั้งฉลากแล้ว' : (j.error||'ล้มเหลว'), j.success ? 'success' : 'error');
    if (j.success) loadProducts(prodPage);
}

// ---------- Product modal ----------
function openProductModal(){
    const m = document.getElementById('pmModal');
    document.getElementById('pmForm').reset();
    document.getElementById('pmId').value = '';
    document.getElementById('pmActive').checked = true;
    document.getElementById('pmSkuHint').textContent = '';
    document.getElementById('pmModalTitle').textContent = 'เพิ่มสินค้า';
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeProductModal(){ const m=document.getElementById('pmModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
async function editProduct(id){
    const r = await fetch(PROD_API + '?action=get&id=' + id);
    const j = await r.json();
    if (!j.success){ fireToastSafe(j.error||'โหลดสินค้าไม่สำเร็จ','error'); return; }
    openProductModal();
    const p = j.item || {};
    document.getElementById('pmModalTitle').textContent = 'แก้ไขสินค้า';
    document.getElementById('pmId').value = p.id;
    for (const [k,v] of Object.entries(p)){
        const el = document.querySelector(`#pmForm [name="${k}"]`);
        if (!el) continue;
        if (el.type === 'checkbox') el.checked = !!Number(v);
        else el.value = v ?? '';
    }
}
async function checkSku(sku){
    if (!sku) return;
    const id = document.getElementById('pmId').value;
    const r = await fetch(PROD_API + '?action=duplicate_check&sku=' + encodeURIComponent(sku) + '&exclude_id=' + (id||''));
    const j = await r.json();
    const hint = document.getElementById('pmSkuHint');
    if (j.success && j.duplicate){ hint.innerHTML = '<span class="text-rose-600"><i class="fas fa-exclamation-triangle"></i> SKU นี้ใช้ซ้ำกับสินค้า: ' + escapeHtml(j.duplicate.name||'') + '</span>'; }
    else { hint.innerHTML = '<span class="text-emerald-600">SKU ใช้ได้</span>'; }
}
async function saveProduct(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    fd.set('_csrf', CSRF);
    fd.set('is_active', document.getElementById('pmActive').checked ? 1 : 0);
    fd.set('is_featured', document.getElementById('pmFeatured').checked ? 1 : 0);
    fd.set('requires_prescription', document.getElementById('pmRx').checked ? 1 : 0);
    const r = await fetch(PROD_API + '?action=save', {method:'POST', body: fd});
    const j = await r.json();
    if (j.success){ fireToastSafe('บันทึกแล้ว','success'); closeProductModal(); loadProducts(prodPage); }
    else fireToastSafe(j.error||'บันทึกไม่สำเร็จ', 'error');
    return false;
}
async function deleteProduct(id){
    if (!confirm('ซ่อนสินค้านี้จากหน้าร้าน?')) return;
    const r = await fetch(PROD_API + '?action=delete', {method:'POST', body: bodyOf({id})});
    const j = await r.json();
    if (j.success){ fireToastSafe('ซ่อนสินค้าแล้ว','success'); loadProducts(prodPage); }
    else fireToastSafe(j.error||'ล้มเหลว', 'error');
}

// ---------- 12 action buttons ----------
async function dupCheck(){
    const r = await fetch(PROD_API + '?action=duplicate_check');
    const j = await r.json();
    if (!j.success){ fireToastSafe(j.error||'ล้มเหลว','error'); return; }
    const dups = j.duplicates || [];
    let html = '';
    if (!dups.length) html = '<p class="text-emerald-600"><i class="fas fa-check-circle mr-1"></i>ไม่พบ SKU ซ้ำ</p>';
    else {
        html = '<table class="w-full text-sm"><thead><tr class="bg-slate-50"><th class="text-left p-2">SKU</th><th class="text-left p-2">สินค้า</th></tr></thead><tbody>';
        for (const d of dups){
            html += `<tr class="border-t"><td class="p-2 font-mono">${escapeHtml(d.sku)}</td><td class="p-2">${d.items.map(i=>escapeHtml(i.name)).join(' / ')}</td></tr>`;
        }
        html += '</tbody></table>';
    }
    showInfo('ผลการตรวจสอบรหัสซ้ำ', html);
}
function openBulkLabelByGeneric(){
    const sel = document.getElementById('pmLabelTpl');  // reuse list of templates from product modal
    const opts = Array.from(sel.options).filter(o=>o.value).map(o => `<option value="${o.value}">${escapeHtml(o.textContent)}</option>`).join('');
    const gSel = document.getElementById('pmGeneric');
    const gOpts = Array.from(gSel.options).filter(o=>o.value).map(o => `<option value="${o.value}">${escapeHtml(o.textContent)}</option>`).join('');
    showInfo('ตั้งฉลากตาม Generic', `
        <form onsubmit="return doBulkLabelByGeneric(event)" class="space-y-3">
            <div><label class="block text-sm mb-1">เลือก Generic name</label><select name="generic_id" required class="w-full border rounded-lg px-3 py-2">${gOpts}</select></div>
            <div><label class="block text-sm mb-1">เลือกเทมเพลตฉลาก</label><select name="template_id" required class="w-full border rounded-lg px-3 py-2">${opts}</select></div>
            <div class="text-right"><button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">ตั้งฉลาก</button></div>
        </form>`);
}
async function doBulkLabelByGeneric(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const r = await fetch(PROD_API + '?action=bulk_label_by_generic', {method:'POST', body: fd});
    const j = await r.json();
    fireToastSafe(j.success ? `ตั้งฉลากกับ ${j.affected||0} สินค้า` : (j.error||'ล้มเหลว'), j.success?'success':'error');
    if (j.success){ document.getElementById('infoModal').classList.add('hidden'); document.getElementById('infoModal').classList.remove('flex'); loadProducts(prodPage); }
    return false;
}
function openBulkLabelByUsage(){
    const sel = document.getElementById('pmLabelTpl');
    const opts = Array.from(sel.options).filter(o=>o.value).map(o => `<option value="${o.value}">${escapeHtml(o.textContent)}</option>`).join('');
    showInfo('ตั้งฉลากตามวิธีใช้', `
        <form onsubmit="return doBulkLabelByUsage(event)" class="space-y-3">
            <div><label class="block text-sm mb-1">วิธีใช้ (usage_method) — match แบบ exact</label><input name="usage_method" required placeholder="oral / topical ..." class="w-full border rounded-lg px-3 py-2"></div>
            <div><label class="block text-sm mb-1">เลือกเทมเพลตฉลาก</label><select name="template_id" required class="w-full border rounded-lg px-3 py-2">${opts}</select></div>
            <div class="text-right"><button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">ตั้งฉลาก</button></div>
        </form>`);
}
async function doBulkLabelByUsage(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const r = await fetch(PROD_API + '?action=bulk_label_by_usage', {method:'POST', body: fd});
    const j = await r.json();
    fireToastSafe(j.success ? `ตั้งฉลากกับ ${j.affected||0} สินค้า` : (j.error||'ล้มเหลว'), j.success?'success':'error');
    if (j.success){ document.getElementById('infoModal').classList.add('hidden'); document.getElementById('infoModal').classList.remove('flex'); loadProducts(prodPage); }
    return false;
}
async function openMovements(){
    const r = await fetch(PROD_API + '?action=stock_movements');
    const j = await r.json();
    if (!j.success){ fireToastSafe(j.error||'ล้มเหลว','error'); return; }
    const rows = j.movements || [];
    let html = '<table class="w-full text-sm"><thead><tr class="bg-slate-50"><th class="text-left p-2">เวลา</th><th class="text-left p-2">สินค้า</th><th class="text-left p-2">ประเภท</th><th class="text-right p-2">จำนวน</th><th class="text-right p-2">ก่อน</th><th class="text-right p-2">หลัง</th><th class="text-left p-2">หมายเหตุ</th></tr></thead><tbody>';
    for (const m of rows){
        html += `<tr class="border-t"><td class="p-2 text-slate-500">${escapeHtml(m.created_at||'')}</td><td class="p-2">${escapeHtml(m.product_name||('#'+m.product_id))}</td><td class="p-2">${escapeHtml(m.movement_type||'')}</td><td class="p-2 text-right ${Number(m.quantity)<0?'text-rose-600':'text-emerald-600'}">${m.quantity}</td><td class="p-2 text-right">${m.stock_before}</td><td class="p-2 text-right">${m.stock_after}</td><td class="p-2 text-slate-500">${escapeHtml(m.notes||'')}</td></tr>`;
    }
    html += '</tbody></table>';
    if (!rows.length) html = '<p class="text-slate-500">ยังไม่มีประวัติการเคลื่อนไหวสต๊อก</p>';
    showInfo('ประวัติการตัดสต๊อก (100 รายการล่าสุด)', html);
}
function openDispensingFee(){
    showInfo('ตั้งค่าหยิบยา (Dispensing Fee)', `
        <form onsubmit="return doDispensingFee(event)" class="space-y-3">
            <p class="text-sm text-slate-600">ตั้งค่าหยิบยาเริ่มต้นแบบหมู่ — จะ apply ให้สินค้าทุกตัวที่ <b>ค่าหยิบยา = 0</b></p>
            <div><label class="block text-sm mb-1">ค่าหยิบยา (บาท / หน่วย)</label><input name="dispensing_fee" type="number" step="0.01" required class="w-full border rounded-lg px-3 py-2"></div>
            <div class="text-right"><button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button></div>
        </form>`);
}
async function doDispensingFee(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const r = await fetch(PROD_API + '?action=set_default_dispensing_fee', {method:'POST', body: fd});
    const j = await r.json();
    fireToastSafe(j.success ? `อัปเดต ${j.affected||0} สินค้า` : (j.error||'ล้มเหลว'), j.success?'success':'error');
    if (j.success) document.getElementById('infoModal').classList.add('hidden');
    return false;
}
function openLotAdjust(){
    showInfo('ปรับ Lot / ยอดคงเหลือ', `
        <form onsubmit="return doLotAdjust(event)" class="space-y-3">
            <div><label class="block text-sm mb-1">สินค้า (พิมพ์ SKU)</label><input name="sku" required class="w-full border rounded-lg px-3 py-2"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm mb-1">Δ จำนวน (+/-)</label><input name="quantity_delta" type="number" required class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">Lot No.</label><input name="lot_no" class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">วันหมดอายุ</label><input name="expiry_date" type="date" class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">ประเภท</label>
                    <select name="movement_type" class="w-full border rounded-lg px-3 py-2">
                        <option value="adjustment">adjustment</option>
                        <option value="purchase_in">purchase_in</option>
                        <option value="expiry_writeoff">expiry_writeoff</option>
                        <option value="return">return</option>
                        <option value="transfer_in">transfer_in</option>
                        <option value="transfer_out">transfer_out</option>
                    </select>
                </div>
            </div>
            <div><label class="block text-sm mb-1">หมายเหตุ</label><input name="note" class="w-full border rounded-lg px-3 py-2"></div>
            <div class="text-right"><button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">ปรับสต๊อก</button></div>
        </form>`);
}
async function doLotAdjust(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const r = await fetch(PROD_API + '?action=stock_adjust', {method:'POST', body: fd});
    const j = await r.json();
    fireToastSafe(j.success ? 'ปรับสต๊อกแล้ว' : (j.error||'ล้มเหลว'), j.success?'success':'error');
    if (j.success){ document.getElementById('infoModal').classList.add('hidden'); loadProducts(prodPage); }
    return false;
}
function printTags(){
    const ids = getSelectedIds();
    if (!ids.length){ alert('กรุณาเลือกสินค้าก่อน'); return; }
    fetch(PROD_API + '?action=print_tags', {method:'POST', body: bodyOf({ids: JSON.stringify(ids), type:'price'})})
        .then(r=>r.json()).then(j => { if (j.success && j.url) window.open(j.url, '_blank'); else fireToastSafe(j.error||'พิมพ์ไม่สำเร็จ','error'); });
}
function printStickers(){
    const ids = getSelectedIds();
    if (!ids.length){ alert('กรุณาเลือกสินค้าก่อน'); return; }
    fetch(PROD_API + '?action=print_tags', {method:'POST', body: bodyOf({ids: JSON.stringify(ids), type:'sticker'})})
        .then(r=>r.json()).then(j => { if (j.success && j.url) window.open(j.url, '_blank'); else fireToastSafe(j.error||'พิมพ์ไม่สำเร็จ','error'); });
}
function openLiquidDose(){
    showInfo('ยาน้ำเด็ก — คำนวณ dose', `
        <form onsubmit="return doLiquid(event)" class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm mb-1">น้ำหนักเด็ก (kg)</label><input name="weight_kg" type="number" step="0.1" required class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">Dose (mg/kg)</label><input name="dose_mg_per_kg" type="number" step="0.1" required class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">ความเข้มข้น (mg/ml)</label><input name="concentration_mg_per_ml" type="number" step="0.01" required class="w-full border rounded-lg px-3 py-2"></div>
                <div><label class="block text-sm mb-1">ความถี่ / วัน</label><input name="frequency_per_day" type="number" value="3" class="w-full border rounded-lg px-3 py-2"></div>
            </div>
            <div id="liquidResult" class="text-center text-lg font-medium text-indigo-700"></div>
            <div class="text-right"><button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">คำนวณ</button></div>
        </form>`);
}
function doLiquid(ev){
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const w  = Number(fd.get('weight_kg')||0);
    const d  = Number(fd.get('dose_mg_per_kg')||0);
    const c  = Number(fd.get('concentration_mg_per_ml')||0);
    const f  = Math.max(1, Number(fd.get('frequency_per_day')||1));
    if (!w || !d || !c){ document.getElementById('liquidResult').textContent = 'กรอกข้อมูลให้ครบ'; return false; }
    const mgPerDose = (w * d) / f;
    const mlPerDose = mgPerDose / c;
    document.getElementById('liquidResult').innerHTML =
        `แต่ละครั้ง: <b>${mgPerDose.toFixed(2)} mg</b> = <b>${mlPerDose.toFixed(2)} ml</b><br>` +
        `<span class="text-sm text-slate-500">วันละ ${f} ครั้ง รวม ${(mgPerDose*f).toFixed(2)} mg / ${(mlPerDose*f).toFixed(2)} ml</span>`;
    return false;
}
async function openStockSummary(){
    const r = await fetch(PROD_API + '?action=stock_summary');
    const j = await r.json();
    if (!j.success){ fireToastSafe(j.error||'ล้มเหลว','error'); return; }
    const s = j.summary || {};
    const html = `
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">สินค้าทั้งหมด</div><div class="text-2xl font-bold">${s.total_products||0}</div></div>
            <div class="p-3 bg-emerald-50 rounded-lg"><div class="text-xs text-emerald-600">เปิดขาย</div><div class="text-2xl font-bold text-emerald-700">${s.active||0}</div></div>
            <div class="p-3 bg-rose-50 rounded-lg"><div class="text-xs text-rose-600">หมดสต๊อก</div><div class="text-2xl font-bold text-rose-700">${s.out_of_stock||0}</div></div>
            <div class="p-3 bg-amber-50 rounded-lg"><div class="text-xs text-amber-600">ใกล้ min</div><div class="text-2xl font-bold text-amber-700">${s.low_stock||0}</div></div>
            <div class="p-3 bg-indigo-50 rounded-lg col-span-2"><div class="text-xs text-indigo-600">มูลค่าสต๊อก (ตามทุน)</div><div class="text-2xl font-bold text-indigo-700">${Number(s.stock_value_cost||0).toLocaleString('th-TH',{minimumFractionDigits:2})} ฿</div></div>
            <div class="p-3 bg-blue-50 rounded-lg col-span-2"><div class="text-xs text-blue-600">มูลค่าสต๊อก (ตามราคาขาย)</div><div class="text-2xl font-bold text-blue-700">${Number(s.stock_value_price||0).toLocaleString('th-TH',{minimumFractionDigits:2})} ฿</div></div>
        </div>`;
    showInfo('สรุปยอดคงเหลือ', html);
}

// ---------- Stock count (with sessionStorage persistence) ----------
const SC_KEY = 'reya_products_stock_count_v1';
let scProducts = [];
async function openStockCount(){
    const r = await fetch(PROD_API + '?action=stock_count_init');
    const j = await r.json();
    if (!j.success){ fireToastSafe(j.error||'ล้มเหลว','error'); return; }
    scProducts = j.items || [];
    renderCountRows();
    const m = document.getElementById('scModal'); m.classList.remove('hidden'); m.classList.add('flex');
}
function closeStockCount(){ const m=document.getElementById('scModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function clearCountProgress(){ if (!confirm('ล้างความคืบหน้าทั้งหมด?')) return; sessionStorage.removeItem(SC_KEY); renderCountRows(); }
function getCountStore(){ try { return JSON.parse(sessionStorage.getItem(SC_KEY) || '{}'); } catch(e){ return {}; } }
function setCountStore(obj){ sessionStorage.setItem(SC_KEY, JSON.stringify(obj)); }
function onCountInput(id, val){
    const store = getCountStore();
    if (val === '' || val === null) delete store[id]; else store[id] = Number(val);
    setCountStore(store);
    const tr = document.querySelector(`#scTbody tr[data-id="${id}"]`);
    if (tr){
        const expected = Number(tr.dataset.expected);
        const counted  = (store[id] == null) ? null : Number(store[id]);
        const delta    = counted == null ? null : counted - expected;
        const dCell    = tr.querySelector('.sc-delta');
        if (delta == null){ dCell.textContent = ''; }
        else { dCell.textContent = (delta > 0 ? '+' : '') + delta; dCell.className = 'sc-delta px-2 py-2 text-right ' + (delta === 0 ? 'text-slate-500' : (delta > 0 ? 'text-emerald-600' : 'text-rose-600')); }
    }
}
function renderCountRows(){
    const search = (document.getElementById('scSearch').value || '').toLowerCase();
    const store  = getCountStore();
    const tb     = document.getElementById('scTbody');
    const filtered = scProducts.filter(p => !search || (p.name||'').toLowerCase().includes(search) || (p.sku||'').toLowerCase().includes(search));
    tb.innerHTML = filtered.map(p => {
        const c = store[p.id];
        const d = c == null ? null : c - Number(p.stock||0);
        return `<tr class="border-t" data-id="${p.id}" data-expected="${Number(p.stock||0)}">
            <td class="px-2 py-2 font-mono text-xs">${escapeHtml(p.sku||'')}</td>
            <td class="px-2 py-2">${escapeHtml(p.name||'')}</td>
            <td class="px-2 py-2 text-right tabular-nums">${Number(p.stock||0)}</td>
            <td class="px-2 py-2 text-right"><input type="number" class="w-24 border border-slate-300 rounded px-2 py-1 text-right" value="${c==null?'':c}" oninput="onCountInput(${p.id}, this.value)"></td>
            <td class="sc-delta px-2 py-2 text-right ${d==null?'':(d===0?'text-slate-500':(d>0?'text-emerald-600':'text-rose-600'))}">${d==null?'':(d>0?'+':'')+d}</td>
        </tr>`;
    }).join('');
    if (!filtered.length) tb.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">ไม่พบสินค้า</td></tr>';
}
async function submitStockCount(){
    const store = getCountStore();
    const items = Object.entries(store).map(([id, qty]) => ({product_id: Number(id), counted_qty: Number(qty)}));
    if (!items.length){ alert('ยังไม่ได้นับรายการใด'); return; }
    if (!confirm(`บันทึก ${items.length} รายการและปรับสต๊อกจริง?`)) return;
    const body = new FormData();
    body.set('_csrf', CSRF);
    body.set('session_name', document.getElementById('scSessionName').value || ('count ' + new Date().toISOString().slice(0,16)));
    body.set('items', JSON.stringify(items));
    const r = await fetch(PROD_API + '?action=stock_count_submit', {method:'POST', body});
    const j = await r.json();
    if (j.success){ sessionStorage.removeItem(SC_KEY); fireToastSafe(`บันทึกรอบนับ #${j.session_id} แล้ว (${j.adjusted||0} รายการ)`, 'success'); closeStockCount(); loadProducts(prodPage); }
    else fireToastSafe(j.error || 'บันทึกไม่สำเร็จ', 'error');
}

// ---------- helpers ----------
function bodyOf(obj){ const f = new FormData(); f.set('_csrf', CSRF); for (const k in obj) f.set(k, obj[k]); return f; }
function fireToastSafe(msg, type){ if (typeof fireToast === 'function') fireToast(msg, type); else alert(msg); }
function showInfo(title, html){
    document.getElementById('infoTitle').textContent = title;
    document.getElementById('infoBody').innerHTML   = html;
    const m = document.getElementById('infoModal'); m.classList.remove('hidden'); m.classList.add('flex');
}

// initial load
loadProducts(1);
</script>
