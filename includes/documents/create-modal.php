<?php
/**
 * Documents — Create / Edit Modal (universal form).
 * Modal สากลสำหรับสร้าง/แก้ไขเอกสารทุกประเภท
 *
 * Renders the modal shell + the JS controller that wires all list-page buttons
 * (`window.__docCreateInit`, `__docView`, `__docApprove`, `__docCancel`, `__docConvert`).
 *
 * The controller talks to api/documents.php and refreshes the page on success.
 *
 * Expected in scope:
 *   $db, $lineAccountId, $currentDocType
 *
 * @package Documents
 * @version 1.0.0
 */

require_once __DIR__ . '/../components/modal.php';
require_once __DIR__ . '/../document-helpers.php';

$currentDocType = $currentDocType ?? 'QT';

// Preload customer suggestion list (recent users for this tenant).
$customers = [];
try {
    $stmt = $db->prepare("SELECT id, display_name, real_name, phone, email, address
                            FROM users
                           WHERE line_account_id = ?
                           ORDER BY last_message_at DESC, id DESC
                           LIMIT 200");
    $stmt->execute([$lineAccountId]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Non-fatal — manual entry still works.
}

// Preload product suggestion list (active products).
$products = [];
try {
    $stmt = $db->prepare("SELECT id, sku, name, price
                            FROM business_items
                           WHERE line_account_id = ?
                             AND (is_active = 1 OR is_active IS NULL)
                           ORDER BY name ASC
                           LIMIT 500");
    $stmt->execute([$lineAccountId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Non-fatal.
}

$body = <<<HTML
<form id="docCreateForm" onsubmit="return false;">
    <input type="hidden" id="docId" name="id" value="">
    <input type="hidden" id="docTypeField" name="doc_type" value="">

    <!-- doc-type quick picker -->
    <div class="mb-4">
        <label class="block text-xs font-semibold text-slate-600 mb-2">ประเภทเอกสาร</label>
        <div id="docTypePicker" class="flex flex-wrap gap-2"></div>
    </div>

    <!-- Customer -->
    <fieldset class="border border-slate-200 rounded-lg p-4 mb-4">
        <legend class="text-sm font-semibold text-slate-700 px-1">ข้อมูลลูกค้า</legend>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="md:col-span-2">
                <label class="block text-xs text-slate-500 mb-1">เลือกลูกค้า (ตัวเลือก)</label>
                <select id="customerPicker" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                    <option value="">— กรอกข้อมูลเอง —</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">ชื่อลูกค้า</label>
                <input type="text" id="customer_name" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">เลขประจำตัวผู้เสียภาษี</label>
                <input type="text" id="customer_tax_id" maxlength="20" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">รหัสสาขา</label>
                <input type="text" id="customer_branch_code" placeholder="00000" maxlength="20" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">เบอร์โทร</label>
                <input type="text" id="customer_phone" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-slate-500 mb-1">ที่อยู่</label>
                <textarea id="customer_address" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
            </div>
        </div>
    </fieldset>

    <!-- Dates -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
        <div>
            <label class="block text-xs text-slate-500 mb-1">วันที่ออกเอกสาร</label>
            <input type="date" id="issue_date" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">ครบกำหนด (BL/INV)</label>
            <input type="date" id="due_date" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">ใช้ได้ถึง (QT)</label>
            <input type="date" id="valid_until" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
        </div>
    </div>

    <!-- Line items -->
    <fieldset class="border border-slate-200 rounded-lg p-4 mb-4">
        <legend class="text-sm font-semibold text-slate-700 px-1">รายการสินค้า/บริการ</legend>

        <div class="mb-2 flex items-center gap-2">
            <select id="productPicker" class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <option value="">— เลือกสินค้าเพื่อเพิ่มแถว —</option>
            </select>
            <button type="button" onclick="window.__docAddRow()" class="px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm hover:bg-slate-200">
                <i class="fas fa-plus"></i> เพิ่มแถวว่าง
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse" id="itemsTable">
                <thead class="bg-slate-50 text-xs text-slate-600">
                    <tr>
                        <th class="px-2 py-2 text-left" style="width:32%">รายการ</th>
                        <th class="px-2 py-2 text-right" style="width:12%">จำนวน</th>
                        <th class="px-2 py-2 text-left"  style="width:12%">หน่วย</th>
                        <th class="px-2 py-2 text-right" style="width:16%">ราคา/หน่วย</th>
                        <th class="px-2 py-2 text-right" style="width:12%">ส่วนลด</th>
                        <th class="px-2 py-2 text-right" style="width:14%">รวม</th>
                        <th style="width:32px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </fieldset>

    <!-- Totals + notes -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs text-slate-500 mb-1">หมายเหตุ (พิมพ์บนเอกสาร)</label>
            <textarea id="note" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
            <label class="block text-xs text-slate-500 mb-1 mt-3">หมายเหตุภายใน</label>
            <textarea id="internal_note" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
        </div>
        <div class="bg-slate-50 rounded-lg p-4 text-sm">
            <div class="flex justify-between py-1"><span>ก่อนภาษี</span><span id="totSubtotal">0.00</span></div>
            <div class="flex justify-between py-1"><span>ส่วนลด</span><span id="totDiscount">0.00</span></div>
            <div class="flex justify-between py-1 items-center">
                <label class="flex items-center gap-2">
                    VAT
                    <input type="number" step="0.01" id="vat_rate" value="7.00" class="w-16 px-2 py-1 border border-slate-200 rounded text-right">
                    %
                </label>
                <span id="totVAT">0.00</span>
            </div>
            <div class="flex justify-between py-2 border-t border-slate-300 mt-2 font-bold text-base">
                <span>ยอดสุทธิ</span><span id="totGrand">0.00</span>
            </div>
        </div>
    </div>
</form>
HTML;

$footer = '<button type="button" data-modal-close="docCreateModal" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200">ยกเลิก</button>
<button type="button" onclick="window.__docSubmit(false)" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 hover:bg-slate-50">บันทึกร่าง</button>
<button type="button" onclick="window.__docSubmit(true)" class="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">บันทึก + อนุมัติ</button>';

echo getModalStyles();
echo renderModal('docCreateModal', 'สร้างเอกสาร', $body, $footer, ['size' => 'xl']);

// JSON payload for client-side use.
$payload = [
    'currentDocType' => $currentDocType,
    'docTypes' => REYA_DOCUMENT_TYPES,
    'customers' => $customers,
    'products' => $products,
];
?>
<script>
window.__docInitData = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(function() {
    'use strict';
    if (window.__docModalInit) return;
    window.__docModalInit = true;

    var data = window.__docInitData;
    var $ = function(id) { return document.getElementById(id); };

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fmt(n) { return (Number(n) || 0).toFixed(2); }

    // -- doc-type picker pills --
    function renderDocTypePicker(selected) {
        var box = $('docTypePicker');
        if (!box) return;
        box.innerHTML = '';
        Object.keys(data.docTypes).forEach(function(code) {
            var t = data.docTypes[code];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = t.label + ' (' + code + ')';
            btn.className = 'px-3 py-1.5 rounded-full border text-xs font-medium transition ' +
                (code === selected
                    ? 'bg-slate-900 text-white border-slate-900'
                    : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100');
            btn.onclick = function() {
                $('docTypeField').value = code;
                renderDocTypePicker(code);
            };
            box.appendChild(btn);
        });
    }

    // -- customer/product dropdowns --
    function fillCustomerSelect() {
        var sel = $('customerPicker');
        if (!sel) return;
        data.customers.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            var nm = c.real_name || c.display_name || ('User #' + c.id);
            opt.textContent = nm + (c.phone ? ' · ' + c.phone : '');
            opt.dataset.payload = JSON.stringify(c);
            sel.appendChild(opt);
        });
        sel.onchange = function() {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.dataset.payload) return;
            try {
                var c = JSON.parse(opt.dataset.payload);
                $('customer_name').value = c.real_name || c.display_name || '';
                $('customer_phone').value = c.phone || '';
                $('customer_address').value = c.address || '';
            } catch (e) {}
        };
    }

    function fillProductSelect() {
        var sel = $('productPicker');
        if (!sel) return;
        data.products.forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = (p.sku ? '[' + p.sku + '] ' : '') + p.name + '  ฿' + fmt(p.price);
            opt.dataset.payload = JSON.stringify(p);
            sel.appendChild(opt);
        });
        sel.onchange = function() {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.dataset.payload) return;
            try {
                var p = JSON.parse(opt.dataset.payload);
                addRow({ product_id: p.id, product_sku: p.sku, product_name: p.name, unit_price: p.price, quantity: 1 });
                sel.value = '';
            } catch (e) {}
        };
    }

    // -- items table --
    function addRow(item) {
        item = item || {};
        var body = $('itemsBody');
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-100';
        tr.innerHTML =
            '<td class="px-2 py-2">' +
                '<input type="hidden" data-k="product_id" value="' + escapeHtml(item.product_id || '') + '">' +
                '<input type="hidden" data-k="product_sku" value="' + escapeHtml(item.product_sku || '') + '">' +
                '<input type="text" data-k="product_name" value="' + escapeHtml(item.product_name || '') + '" placeholder="ชื่อรายการ" class="w-full px-2 py-1 border border-slate-200 rounded text-sm">' +
            '</td>' +
            '<td class="px-2 py-2"><input type="number" step="0.01" data-k="quantity" value="' + (item.quantity || 1) + '" class="w-full px-2 py-1 border border-slate-200 rounded text-right text-sm"></td>' +
            '<td class="px-2 py-2"><input type="text" data-k="unit" value="' + escapeHtml(item.unit || '') + '" class="w-full px-2 py-1 border border-slate-200 rounded text-sm"></td>' +
            '<td class="px-2 py-2"><input type="number" step="0.01" data-k="unit_price" value="' + (item.unit_price || 0) + '" class="w-full px-2 py-1 border border-slate-200 rounded text-right text-sm"></td>' +
            '<td class="px-2 py-2"><input type="number" step="0.01" data-k="discount_amount" value="' + (item.discount_amount || 0) + '" class="w-full px-2 py-1 border border-slate-200 rounded text-right text-sm"></td>' +
            '<td class="px-2 py-2 text-right text-sm" data-k="line_total">0.00</td>' +
            '<td class="px-2 py-2 text-center"><button type="button" class="text-rose-500 hover:text-rose-700" data-act="del"><i class="fas fa-times"></i></button></td>';
        body.appendChild(tr);
        tr.querySelectorAll('input').forEach(function(inp) { inp.addEventListener('input', recalc); });
        tr.querySelector('[data-act=del]').onclick = function() { tr.remove(); recalc(); };
        recalc();
    }
    window.__docAddRow = function() { addRow({}); };

    function readItems() {
        var rows = [];
        $('itemsBody').querySelectorAll('tr').forEach(function(tr) {
            var item = {};
            tr.querySelectorAll('[data-k]').forEach(function(el) {
                if (el.tagName === 'TD') return;
                item[el.dataset.k] = el.value;
            });
            if ((item.product_name || '').trim() === '') return;
            rows.push(item);
        });
        return rows;
    }

    function recalc() {
        var subtotal = 0, discount = 0;
        $('itemsBody').querySelectorAll('tr').forEach(function(tr) {
            var qty = parseFloat(tr.querySelector('[data-k=quantity]').value) || 0;
            var price = parseFloat(tr.querySelector('[data-k=unit_price]').value) || 0;
            var discA = parseFloat(tr.querySelector('[data-k=discount_amount]').value) || 0;
            var gross = qty * price;
            var line = Math.max(0, gross - discA);
            tr.querySelector('[data-k=line_total]').textContent = fmt(line);
            subtotal += gross;
            discount += discA;
        });
        var vatRate = parseFloat($('vat_rate').value) || 0;
        var base = subtotal - discount;
        var vat = base * (vatRate / 100);
        $('totSubtotal').textContent = fmt(subtotal);
        $('totDiscount').textContent = fmt(discount);
        $('totVAT').textContent = fmt(vat);
        $('totGrand').textContent = fmt(base + vat);
    }
    $('vat_rate').addEventListener('input', recalc);

    function resetForm(docType) {
        $('docId').value = '';
        $('docTypeField').value = docType || data.currentDocType || 'QT';
        $('customer_name').value = '';
        $('customer_tax_id').value = '';
        $('customer_branch_code').value = '00000';
        $('customer_phone').value = '';
        $('customer_address').value = '';
        $('issue_date').value = new Date().toISOString().slice(0, 10);
        $('due_date').value = '';
        $('valid_until').value = '';
        $('note').value = '';
        $('internal_note').value = '';
        $('vat_rate').value = '7.00';
        $('itemsBody').innerHTML = '';
        renderDocTypePicker($('docTypeField').value);
        addRow({});
        recalc();
    }
    window.__docCreateInit = function(docType) { resetForm(docType); };

    // -- submit --
    window.__docSubmit = function(approveAfter) {
        var docType = $('docTypeField').value;
        if (!docType) { alert('กรุณาเลือกประเภทเอกสาร'); return; }
        var items = readItems();
        if (items.length === 0) { alert('กรุณาเพิ่มอย่างน้อย 1 รายการ'); return; }
        var payload = {
            doc_type: docType,
            customer_user_id: parseInt($('customerPicker').value, 10) || null,
            customer_name: $('customer_name').value,
            customer_tax_id: $('customer_tax_id').value,
            customer_branch_code: $('customer_branch_code').value,
            customer_phone: $('customer_phone').value,
            customer_address: $('customer_address').value,
            issue_date: $('issue_date').value,
            due_date: $('due_date').value || null,
            valid_until: $('valid_until').value || null,
            note: $('note').value,
            internal_note: $('internal_note').value,
            vat_rate: parseFloat($('vat_rate').value) || 7.00,
            items: items
        };

        fetch('api/documents.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); })
          .then(function(res) {
              if (!res.success) {
                  alert(res.message || res.error || 'สร้างเอกสารไม่สำเร็จ');
                  return;
              }
              if (approveAfter && res.data && res.data.id) {
                  return fetch('api/documents.php?action=approve', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                      body: JSON.stringify({ id: res.data.id }),
                      credentials: 'same-origin'
                  }).then(function(r) { return r.json(); }).then(function() {
                      window.location.reload();
                  });
              }
              window.location.reload();
          })
          .catch(function(err) { alert('Network error: ' + err); });
    };

    // -- action buttons on the list table --
    window.__docView = function(id) {
        window.open('api/documents.php?action=pdf&id=' + id, '_blank');
    };
    window.__docApprove = function(id) {
        if (!confirm('ยืนยันการอนุมัติเอกสารนี้? เมื่ออนุมัติแล้วจะไม่สามารถแก้ไขได้')) return;
        fetch('api/documents.php?action=approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id: id }), credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(res) {
            if (!res.success) { alert(res.message || res.error || 'อนุมัติไม่สำเร็จ'); return; }
            window.location.reload();
        });
    };
    window.__docCancel = function(id) {
        var reason = prompt('ระบุเหตุผลการยกเลิก:');
        if (reason == null) return;
        reason = reason.trim();
        if (reason === '') { alert('ต้องระบุเหตุผล'); return; }
        fetch('api/documents.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id: id, cancel_reason: reason }), credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(res) {
            if (!res.success) { alert(res.message || res.error || 'ยกเลิกไม่สำเร็จ'); return; }
            window.location.reload();
        });
    };
    window.__docConvert = function(sourceId, sourceType) {
        var chain = { QT: 'INV', BL: 'INV', INV: 'TAX' };
        var target = chain[sourceType] || 'TAX';
        if (!confirm('แปลงเอกสารนี้เป็น ' + target + '?')) return;
        fetch('api/documents.php?action=convert', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ source_id: sourceId, target_doc_type: target }), credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(res) {
            if (!res.success) { alert(res.message || res.error || 'แปลงไม่สำเร็จ'); return; }
            window.location.href = 'documents.php?doc_type=' + target;
        });
    };

    // init dropdowns once
    fillCustomerSelect();
    fillProductSelect();
})();
</script>
