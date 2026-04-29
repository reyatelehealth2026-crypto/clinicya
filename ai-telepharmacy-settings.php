<?php
/**
 * AI Telepharmacy Settings — admin UI for managing:
 *   Tab 1: Products (inline list + AI recommendable toggle)
 *   Tab 2: Symptom -> Product map
 *   Tab 3: Red Flag + Triage Questions
 *   Tab 4: Sandbox preview
 *
 * Backend CRUD: api/ai-telepharmacy-admin.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = 'AI Telepharmacy — ตั้งค่าครบชุด';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

require_once __DIR__ . '/includes/header.php';
?>

<style>
.tabbtn { padding: 0.75rem 1rem; border-bottom: 2px solid transparent; cursor: pointer; font-weight: 500; }
.tabbtn.active { border-color: #7c3aed; color: #7c3aed; }
.tabpane { display: none; }
.tabpane.active { display: block; }
.severity-critical { background-color: #fee2e2; color: #b91c1c; }
.severity-urgent   { background-color: #fef3c7; color: #b45309; }
.severity-warning  { background-color: #dbeafe; color: #1e40af; }
.product-row.dimmed { opacity: 0.4; }
</style>

<div class="max-w-7xl mx-auto">
  <h1 class="text-2xl font-bold mb-1">💊 AI Telepharmacy</h1>
  <p class="text-sm text-gray-600 mb-4">จัดการระบบ triage Yes/No, อาการ → สินค้า, red flag และทดสอบ AI ก่อน publish</p>

  <div class="flex gap-1 border-b mb-6">
    <div class="tabbtn active" data-tab="products">📦 สินค้า (AI แนะนำได้)</div>
    <div class="tabbtn" data-tab="map">🔗 อาการ → สินค้า</div>
    <div class="tabbtn" data-tab="triage">🩺 Red Flag &amp; คำถาม Yes/No</div>
    <div class="tabbtn" data-tab="knowledge">📚 Knowledge (RAG)</div>
    <div class="tabbtn" data-tab="sandbox">🧪 Sandbox</div>
  </div>

  <!-- TAB 1: PRODUCTS -->
  <section id="tab-products" class="tabpane active">
    <div class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap items-center gap-3">
      <input id="prodSearch" type="search" placeholder="ค้นหาชื่อ / SKU / ตัวยา"
             class="flex-1 min-w-[200px] px-3 py-2 border rounded-lg text-sm" />
      <select id="prodDrugType" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">ทุกประเภทยา</option>
        <option value="household">ยาสามัญประจำบ้าน</option>
        <option value="dangerous">ยาอันตราย</option>
        <option value="controlled">ยาควบคุมพิเศษ</option>
        <option value="traditional">ยาแผนโบราณ</option>
      </select>
      <button id="prodReload" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">โหลดสินค้า</button>
      <span id="prodTotal" class="text-sm text-gray-500">—</span>
    </div>
    <div class="bg-white rounded-xl shadow overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
          <tr>
            <th class="p-3 w-12">รูป</th>
            <th class="p-3">ชื่อสินค้า / SKU</th>
            <th class="p-3">ประเภท</th>
            <th class="p-3 text-right">ราคา</th>
            <th class="p-3 text-center">Stock</th>
            <th class="p-3 text-center w-32">AI แนะนำได้</th>
          </tr>
        </thead>
        <tbody id="prodList"><tr><td colspan="6" class="p-6 text-center text-gray-400">กดโหลดสินค้า</td></tr></tbody>
      </table>
    </div>
    <div class="mt-3 flex items-center gap-2 text-sm">
      <button id="prodPrev" class="px-3 py-1 border rounded">ก่อนหน้า</button>
      <span id="prodPageInfo">หน้า 1</span>
      <button id="prodNext" class="px-3 py-1 border rounded">ถัดไป</button>
    </div>
  </section>

  <!-- TAB 2: SYMPTOM MAP -->
  <section id="tab-map" class="tabpane">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white rounded-xl shadow p-4">
        <h3 class="font-semibold mb-3">อาการ (symptom_code)</h3>
        <input id="newSymptomCode" placeholder="เช่น fever_heatstroke"
               class="w-full px-3 py-2 border rounded-lg text-sm mb-2" />
        <button id="newSymptomBtn" class="w-full px-3 py-2 bg-emerald-500 text-white rounded-lg text-sm mb-3">
          เลือกอาการนี้
        </button>
        <ul id="symptomList" class="space-y-1"></ul>
      </div>

      <div class="md:col-span-2 bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold">สินค้าใน <span id="currentSymptomCode" class="text-purple-600">—</span></h3>
          <button id="addProductMapBtn" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm">+ เพิ่มสินค้า</button>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left">
            <tr>
              <th class="p-2">สินค้า</th>
              <th class="p-2 w-32">น้ำหนัก (1-100)</th>
              <th class="p-2 w-24 text-center">First-line</th>
              <th class="p-2">Notes</th>
              <th class="p-2 w-24"></th>
            </tr>
          </thead>
          <tbody id="mapList"><tr><td colspan="5" class="p-6 text-center text-gray-400">เลือกอาการจากซ้าย</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- TAB 3: RED FLAG + TRIAGE QUESTIONS -->
  <section id="tab-triage" class="tabpane space-y-6">
    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold">🚨 Red Flag Symptoms</h3>
        <button id="addRedFlagBtn" class="px-3 py-1.5 bg-rose-600 text-white rounded-lg text-sm">+ เพิ่ม</button>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
          <tr>
            <th class="p-2">Code</th>
            <th class="p-2">ชื่ออาการ (TH)</th>
            <th class="p-2">Severity</th>
            <th class="p-2">Action</th>
            <th class="p-2 w-24"></th>
          </tr>
        </thead>
        <tbody id="redFlagList"></tbody>
      </table>
    </div>

    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="font-semibold">❓ Triage Questions</h3>
        <div class="flex items-center gap-2">
          <select id="conditionFilter" class="px-3 py-1.5 border rounded-lg text-sm">
            <option value="">— เลือก condition —</option>
          </select>
          <input id="newCondition" placeholder="condition_code ใหม่" class="px-3 py-1.5 border rounded-lg text-sm" />
          <button id="addQuestionBtn" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm">+ เพิ่มคำถาม</button>
        </div>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
          <tr>
            <th class="p-2 w-12">#</th>
            <th class="p-2">คำถาม (TH)</th>
            <th class="p-2 w-28">ชนิดคำตอบ</th>
            <th class="p-2 w-24 text-center">Red flag if Yes</th>
            <th class="p-2">Symptom codes (CSV)</th>
            <th class="p-2 w-24"></th>
          </tr>
        </thead>
        <tbody id="triageQuestionList"></tbody>
      </table>
    </div>
  </section>

  <!-- TAB 5: KNOWLEDGE (RAG) -->
  <section id="tab-knowledge" class="tabpane space-y-4">
    <div class="bg-white rounded-xl shadow p-4">
      <h3 class="font-semibold mb-2">📥 Import จาก docs/*.md</h3>
      <p class="text-sm text-gray-600 mb-3">
        นำเข้า 3 ไฟล์ความรู้หลัก (ระบบประเมินอาการเบื้องต้น.md, ข้อมูลโรค.md, Thailand MIMS Clinical Guidelines.md) — ระบบจะ chunk ตาม markdown headings และเก็บใน <code>ai_knowledge_base</code> เพื่อ inject เข้า AI prompt
      </p>
      <div class="flex flex-wrap gap-2">
        <button id="kbImportAllBtn" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">
          🚀 Import ทั้ง 3 ไฟล์
        </button>
        <button data-file="ระบบประเมินอาการเบื้องต้น.md" class="kb-import-one px-3 py-2 bg-gray-100 rounded-lg text-sm">📄 ระบบประเมินอาการ</button>
        <button data-file="ข้อมูลโรค.md" class="kb-import-one px-3 py-2 bg-gray-100 rounded-lg text-sm">📄 ข้อมูลโรค</button>
        <button data-file="Thailand MIMS Clinical Guidelines.md" class="kb-import-one px-3 py-2 bg-gray-100 rounded-lg text-sm">📄 MIMS Guidelines</button>
      </div>
      <div id="kbImportResult" class="mt-3 text-sm"></div>
    </div>

    <div class="bg-white rounded-xl shadow p-4">
      <h3 class="font-semibold mb-2">📋 วาง markdown ตรง ๆ (ถ้าไฟล์ docs/ ไม่ได้ถูก deploy)</h3>
      <p class="text-sm text-gray-600 mb-2">
        เปิดไฟล์ .md ในเครื่องคุณ → copy ทั้งหมด → paste ลงช่องด้านล่าง → กด Import
      </p>
      <div class="flex gap-2 mb-2">
        <input id="kbPasteSource" placeholder="source label เช่น mims_guidelines"
               value="custom_paste" class="px-3 py-2 border rounded-lg text-sm w-64" />
        <button id="kbPasteBtn" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm">
          Import จากช่อง paste
        </button>
      </div>
      <textarea id="kbPasteContent" rows="10"
                class="w-full px-3 py-2 border rounded-lg text-sm font-mono"
                placeholder="# Heading 1&#10;&#10;## Heading 2&#10;เนื้อหา..."></textarea>
      <div id="kbPasteResult" class="mt-2 text-sm"></div>
    </div>

    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="font-semibold">📚 Sources</h3>
        <button id="kbReloadBtn" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm">โหลด</button>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
          <tr><th class="p-2">Source</th><th class="p-2 text-center">Chunks</th><th class="p-2">Last update</th></tr>
        </thead>
        <tbody id="kbSourceList"></tbody>
      </table>
    </div>

    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="font-semibold">📝 Chunks</h3>
        <div class="flex gap-2">
          <select id="kbSourceFilter" class="px-3 py-1.5 border rounded-lg text-sm">
            <option value="">— ทุก source —</option>
          </select>
          <button id="kbAddChunkBtn" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm">+ เพิ่ม chunk</button>
        </div>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
          <tr>
            <th class="p-2">Source</th>
            <th class="p-2">หัวข้อ</th>
            <th class="p-2">Preview</th>
            <th class="p-2 w-28">Conditions</th>
            <th class="p-2 w-16 text-center">Pri</th>
            <th class="p-2 w-24"></th>
          </tr>
        </thead>
        <tbody id="kbChunkList"><tr><td colspan="6" class="p-6 text-center text-gray-400">กดโหลด</td></tr></tbody>
      </table>
    </div>

    <div class="bg-white rounded-xl shadow p-4">
      <h3 class="font-semibold mb-2">🔎 ทดลอง retrieval</h3>
      <div class="flex gap-2 mb-2">
        <input id="kbTestQuery" placeholder="พิมพ์คำถามทดสอบ เช่น &quot;ปวดหัวมา 2 วัน&quot;"
               class="flex-1 px-3 py-2 border rounded-lg text-sm" />
        <button id="kbTestBtn" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">ค้นหา</button>
      </div>
      <div id="kbTestResult" class="space-y-2 text-sm"></div>
    </div>
  </section>

  <!-- TAB 4: SANDBOX -->
  <section id="tab-sandbox" class="tabpane">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-white rounded-xl shadow p-4">
        <h3 class="font-semibold mb-3">🧪 ทดลอง Recommend</h3>
        <label class="block text-sm mb-1">Symptom codes (คั่นด้วย comma)</label>
        <input id="sandboxSymptomInput" placeholder="fever, cough"
               class="w-full px-3 py-2 border rounded-lg text-sm mb-2" />
        <button id="sandboxRunBtn" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">
          ลอง recommend
        </button>
        <div id="sandboxResult" class="mt-3 space-y-2 text-sm"></div>
      </div>
      <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold">📋 Triage Sessions ล่าสุด</h3>
          <button id="sandboxLoadSessionsBtn" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm">โหลด</button>
        </div>
        <table class="w-full text-xs">
          <thead class="bg-gray-50 text-left">
            <tr>
              <th class="p-2">ID</th>
              <th class="p-2">Condition</th>
              <th class="p-2">Status</th>
              <th class="p-2">Outcome</th>
              <th class="p-2">เวลา</th>
            </tr>
          </thead>
          <tbody id="sandboxSessionList"></tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script>
const LINE_ACCOUNT_ID = <?= $currentBotId ? (int) $currentBotId : 'null' ?>;
const ADMIN_API = '/api/ai-telepharmacy-admin.php';

async function adminCall(action, params = {}) {
  const res = await fetch(ADMIN_API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, line_account_id: LINE_ACCOUNT_ID, ...params })
  });
  return res.json();
}

document.querySelectorAll('.tabbtn').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tabbtn').forEach((b) => b.classList.remove('active'));
    document.querySelectorAll('.tabpane').forEach((p) => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});

let prodPage = 1;
const PROD_PER_PAGE = 50;

async function loadProducts() {
  const search = document.getElementById('prodSearch').value;
  const drugType = document.getElementById('prodDrugType').value;
  const r = await adminCall('list_products', {
    search, drug_type: drugType, page: prodPage, per_page: PROD_PER_PAGE
  });
  const tbody = document.getElementById('prodList');
  if (!r.success) { tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-red-500">' + (r.error || 'error') + '</td></tr>'; return; }
  document.getElementById('prodTotal').textContent = 'พบ ' + r.total + ' รายการ';
  document.getElementById('prodPageInfo').textContent = 'หน้า ' + r.page;
  if (r.data.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-gray-400">ไม่มีสินค้า</td></tr>'; return; }
  tbody.innerHTML = r.data.map((p) => {
    const dimmed = String(p.ai_recommendable) === '0' ? 'dimmed' : '';
    const rxBadge = String(p.requires_prescription) === '1'
      ? '<span class="text-[10px] bg-red-100 text-red-700 px-1 rounded ml-1">RX</span>' : '';
    const img = p.image_url
      ? '<img src="' + p.image_url + '" class="w-10 h-10 rounded object-cover" alt="" />'
      : '<div class="w-10 h-10 rounded bg-gray-100"></div>';
    return '<tr class="product-row ' + dimmed + ' border-t">'
      + '<td class="p-3">' + img + '</td>'
      + '<td class="p-3"><div class="font-medium">' + (p.name || '-') + rxBadge + '</div>'
      + '<div class="text-xs text-gray-500">' + (p.sku || '-') + ' • ' + (p.active_ingredient || '') + ' ' + (p.strength || '') + '</div></td>'
      + '<td class="p-3 text-xs">' + (p.drug_type || '-') + '</td>'
      + '<td class="p-3 text-right">' + Number(p.price || 0).toLocaleString() + '฿</td>'
      + '<td class="p-3 text-center">' + (p.stock || 0) + '</td>'
      + '<td class="p-3 text-center">'
      +   '<label class="inline-flex items-center cursor-pointer">'
      +     '<input type="checkbox" data-pid="' + p.id + '" class="ai-rec-toggle w-4 h-4" '
      +       (String(p.ai_recommendable) === '1' ? 'checked' : '') + ' />'
      +   '</label>'
      + '</td>'
      + '</tr>';
  }).join('');

  document.querySelectorAll('.ai-rec-toggle').forEach((cb) => {
    cb.addEventListener('change', async (e) => {
      const pid = e.target.dataset.pid;
      const r = await adminCall('toggle_product_recommendable', {
        product_id: parseInt(pid, 10),
        ai_recommendable: e.target.checked ? 1 : 0
      });
      if (!r.success) alert(r.error || 'error');
    });
  });
}

document.getElementById('prodReload').addEventListener('click', () => { prodPage = 1; loadProducts(); });
document.getElementById('prodNext').addEventListener('click', () => { prodPage += 1; loadProducts(); });
document.getElementById('prodPrev').addEventListener('click', () => { if (prodPage > 1) { prodPage -= 1; loadProducts(); } });
document.getElementById('prodSearch').addEventListener('keydown', (e) => { if (e.key === 'Enter') { prodPage = 1; loadProducts(); } });

let currentSymptomCode = '';

async function loadSymptomCodes() {
  const r = await adminCall('list_symptom_codes');
  const ul = document.getElementById('symptomList');
  if (!r.success || !Array.isArray(r.data)) { ul.innerHTML = ''; return; }
  ul.innerHTML = r.data.map((row) =>
    '<li><button data-code="' + row.symptom_code + '" class="symptom-pick w-full text-left px-3 py-2 hover:bg-purple-50 rounded text-sm">'
    + row.symptom_code + (row.label ? ' <span class="text-gray-400">— ' + row.label + '</span>' : '')
    + '</button></li>'
  ).join('');
  document.querySelectorAll('.symptom-pick').forEach((btn) => {
    btn.addEventListener('click', () => pickSymptom(btn.dataset.code));
  });
}

async function pickSymptom(code) {
  currentSymptomCode = code;
  document.getElementById('currentSymptomCode').textContent = code;
  const r = await adminCall('list_symptom_map', { symptom_code: code });
  const tbody = document.getElementById('mapList');
  if (!r.success) { tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-red-500">' + (r.error || 'error') + '</td></tr>'; return; }
  if (r.data.length === 0) { tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-gray-400">ยังไม่มีสินค้า</td></tr>'; return; }
  tbody.innerHTML = r.data.map((row) =>
    '<tr class="border-t">'
    + '<td class="p-2"><div class="font-medium">' + row.product_name + '</div>'
    + '<div class="text-xs text-gray-500">' + (row.active_ingredient || '') + ' ' + (row.strength || '') + '</div></td>'
    + '<td class="p-2"><input type="number" min="1" max="100" value="' + row.weight + '" data-id="' + row.id + '" class="weight-input w-16 px-2 py-1 border rounded" /></td>'
    + '<td class="p-2 text-center"><input type="checkbox" data-id="' + row.id + '" class="firstline-input" ' + (String(row.is_first_line) === '1' ? 'checked' : '') + ' /></td>'
    + '<td class="p-2"><input type="text" value="' + (row.notes || '').replace(/"/g, '&quot;') + '" data-id="' + row.id + '" data-pid="' + row.product_id + '" class="notes-input w-full px-2 py-1 border rounded text-xs" /></td>'
    + '<td class="p-2"><button data-id="' + row.id + '" class="del-map text-red-500 text-xs">ลบ</button></td>'
    + '</tr>'
  ).join('');

  document.querySelectorAll('.weight-input,.firstline-input,.notes-input').forEach((input) => {
    input.addEventListener('change', async () => {
      const tr = input.closest('tr');
      const pid = parseInt(tr.querySelector('.notes-input').dataset.pid, 10);
      const weight = parseInt(tr.querySelector('.weight-input').value || '50', 10);
      const fl = tr.querySelector('.firstline-input').checked ? 1 : 0;
      const notes = tr.querySelector('.notes-input').value;
      await adminCall('save_symptom_map', {
        product_id: pid, symptom_code: currentSymptomCode, weight, is_first_line: fl, notes
      });
    });
  });
  document.querySelectorAll('.del-map').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('ลบรายการนี้?')) return;
      const r2 = await adminCall('delete_symptom_map', { id: parseInt(btn.dataset.id, 10) });
      if (r2.success) pickSymptom(currentSymptomCode);
    });
  });
}

document.getElementById('newSymptomBtn').addEventListener('click', () => {
  const code = document.getElementById('newSymptomCode').value.trim();
  if (code) pickSymptom(code);
});

document.getElementById('addProductMapBtn').addEventListener('click', async () => {
  if (!currentSymptomCode) { alert('เลือกอาการก่อน'); return; }
  const pid = parseInt(prompt('product_id ที่ต้องการเพิ่ม:'), 10);
  if (!pid) return;
  const r = await adminCall('save_symptom_map', {
    product_id: pid, symptom_code: currentSymptomCode, weight: 50, is_first_line: 0
  });
  if (r.success) pickSymptom(currentSymptomCode); else alert(r.error || 'error');
});

async function loadRedFlags() {
  const r = await adminCall('list_red_flags');
  const tbody = document.getElementById('redFlagList');
  if (!r.success) return;
  tbody.innerHTML = r.data.map((row) =>
    '<tr class="border-t" data-id="' + row.id + '">'
    + '<td class="p-2 text-xs font-mono">' + row.symptom_code + '</td>'
    + '<td class="p-2">' + row.symptom_name_th + '</td>'
    + '<td class="p-2"><span class="px-2 py-0.5 rounded text-xs severity-' + row.severity + '">' + row.severity + '</span></td>'
    + '<td class="p-2 text-xs">' + (row.action_required || '-') + '</td>'
    + '<td class="p-2"><button class="del-flag text-red-500 text-xs">ลบ</button></td>'
    + '</tr>'
  ).join('');
  document.querySelectorAll('.del-flag').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.closest('tr').dataset.id, 10);
      if (!confirm('ลบ red flag นี้?')) return;
      const r2 = await adminCall('delete_red_flag', { id });
      if (r2.success) loadRedFlags();
    });
  });
}

document.getElementById('addRedFlagBtn').addEventListener('click', async () => {
  const code = prompt('symptom_code (a-z0-9_):');
  if (!code) return;
  const name = prompt('ชื่ออาการภาษาไทย:');
  if (!name) return;
  const sev = prompt('severity (critical/urgent/warning):', 'warning');
  if (!['critical', 'urgent', 'warning'].includes(sev)) return alert('severity ไม่ถูกต้อง');
  const action = prompt('action ที่ต้องทำ:', '');
  const r = await adminCall('save_red_flag', {
    symptom_code: code, symptom_name_th: name, severity: sev, action_required: action || ''
  });
  if (r.success) loadRedFlags(); else alert(r.error || 'error');
});

async function loadConditions() {
  const r = await adminCall('list_triage_questions', { condition_code: '' });
  const sel = document.getElementById('conditionFilter');
  if (!r.success || !Array.isArray(r.conditions)) return;
  sel.innerHTML = '<option value="">— เลือก condition —</option>'
    + r.conditions.map((c) => '<option value="' + c + '">' + c + '</option>').join('');
}

async function loadTriageQuestions(conditionCode) {
  if (!conditionCode) { document.getElementById('triageQuestionList').innerHTML = ''; return; }
  const r = await adminCall('list_triage_questions', { condition_code: conditionCode });
  const tbody = document.getElementById('triageQuestionList');
  if (!r.success || !Array.isArray(r.data)) { tbody.innerHTML = ''; return; }
  tbody.innerHTML = r.data.map((q) =>
    '<tr class="border-t" data-id="' + q.id + '">'
    + '<td class="p-2 text-xs">' + q.sort_order + '</td>'
    + '<td class="p-2">' + q.question_th + '</td>'
    + '<td class="p-2 text-xs">' + q.answer_type + '</td>'
    + '<td class="p-2 text-center">' + (String(q.red_flag_if_yes) === '1' ? '✓' : '') + '</td>'
    + '<td class="p-2 text-xs font-mono">' + (q.recommend_symptom_codes || '-') + '</td>'
    + '<td class="p-2"><button class="del-question text-red-500 text-xs">ลบ</button></td>'
    + '</tr>'
  ).join('');
  document.querySelectorAll('.del-question').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.closest('tr').dataset.id, 10);
      if (!confirm('ลบคำถามนี้?')) return;
      const r2 = await adminCall('delete_triage_question', { id });
      if (r2.success) loadTriageQuestions(conditionCode);
    });
  });
}

document.getElementById('conditionFilter').addEventListener('change', (e) => {
  loadTriageQuestions(e.target.value);
});

document.getElementById('addQuestionBtn').addEventListener('click', async () => {
  const cc = (document.getElementById('newCondition').value || document.getElementById('conditionFilter').value || '').trim();
  if (!cc) return alert('เลือก/พิมพ์ condition_code ก่อน');
  const qth = prompt('คำถาม (ภาษาไทย):');
  if (!qth) return;
  const type = prompt('ชนิดคำตอบ (yes_no / scale_1_10 / multi_choice):', 'yes_no');
  if (!['yes_no', 'scale_1_10', 'multi_choice'].includes(type)) return alert('ชนิดคำตอบไม่ถูกต้อง');
  const rf = confirm('ตอบ "ใช่" จะ trigger red flag escalation หรือไม่?');
  const sympCodes = prompt('symptom_codes (คั่นด้วย comma) ที่จะเพิ่มเมื่อตอบใช่:', '');
  const r = await adminCall('save_triage_question', {
    condition_code: cc,
    question_th: qth,
    answer_type: type,
    red_flag_if_yes: rf ? 1 : 0,
    recommend_symptom_codes: sympCodes,
    sort_order: 99,
    is_active: 1
  });
  if (r.success) {
    document.getElementById('newCondition').value = '';
    await loadConditions();
    document.getElementById('conditionFilter').value = cc;
    loadTriageQuestions(cc);
  } else { alert(r.error || 'error'); }
});

document.getElementById('sandboxRunBtn').addEventListener('click', async () => {
  const codes = document.getElementById('sandboxSymptomInput').value
    .split(',').map((s) => s.trim()).filter(Boolean);
  if (codes.length === 0) return;
  const r = await adminCall('sandbox_preview_recommendation', { symptom_codes: codes });
  const out = document.getElementById('sandboxResult');
  if (!r.success) { out.innerHTML = '<div class="text-red-500">' + (r.error || 'error') + '</div>'; return; }
  if (r.data.length === 0) { out.innerHTML = '<div class="text-gray-400">ไม่พบสินค้าที่ตรงกับ symptom เหล่านี้ — ลองเพิ่มใน Tab 2</div>'; return; }
  out.innerHTML = r.data.map((p, i) =>
    '<div class="flex gap-2 p-2 border rounded">'
    + '<div class="text-xs text-purple-600 font-bold w-6">#' + (i + 1) + '</div>'
    + '<div class="flex-1"><div class="font-medium text-sm">' + p.name + '</div>'
    + '<div class="text-xs text-gray-500">weight=' + (p.best_weight || '-') + ' ' + (p.is_first_line ? '• first-line' : '') + '</div></div>'
    + '<div class="text-sm">' + Number(p.price || 0).toLocaleString() + '฿</div>'
    + '</div>'
  ).join('');
});

document.getElementById('sandboxLoadSessionsBtn').addEventListener('click', async () => {
  const r = await adminCall('sandbox_recent_sessions');
  const tbody = document.getElementById('sandboxSessionList');
  if (!r.success) return;
  tbody.innerHTML = r.data.map((s) =>
    '<tr class="border-t">'
    + '<td class="p-2">' + s.id + '</td>'
    + '<td class="p-2">' + (s.current_state || '-') + '</td>'
    + '<td class="p-2">' + s.status + '</td>'
    + '<td class="p-2">' + (s.outcome || '-') + '</td>'
    + '<td class="p-2 text-xs">' + s.created_at + '</td>'
    + '</tr>'
  ).join('');
});

// ---------------- TAB 5: KNOWLEDGE (RAG) ----------------
async function kbLoadSources() {
  const r = await adminCall('list_knowledge_sources');
  const tbody = document.getElementById('kbSourceList');
  const filter = document.getElementById('kbSourceFilter');
  if (!r.success) { tbody.innerHTML = ''; return; }
  tbody.innerHTML = r.data.map((s) =>
    '<tr class="border-t">'
    + '<td class="p-2 font-mono text-xs">' + s.source + '</td>'
    + '<td class="p-2 text-center">' + s.chunks + '</td>'
    + '<td class="p-2 text-xs text-gray-500">' + (s.last_update || '-') + '</td>'
    + '</tr>'
  ).join('');
  filter.innerHTML = '<option value="">— ทุก source —</option>'
    + r.data.map((s) => '<option value="' + s.source + '">' + s.source + '</option>').join('');
}

async function kbLoadChunks(source) {
  const r = await adminCall('list_knowledge_chunks', { source: source || '' });
  const tbody = document.getElementById('kbChunkList');
  if (!r.success) return;
  if (r.data.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-gray-400">ยังไม่มีข้อมูล</td></tr>'; return; }
  tbody.innerHTML = r.data.map((c) =>
    '<tr class="border-t" data-id="' + c.id + '">'
    + '<td class="p-2 text-xs">' + c.source + '</td>'
    + '<td class="p-2 text-xs">' + (c.title || '-') + '<div class="text-[10px] text-gray-400">' + (c.heading_path || '') + '</div></td>'
    + '<td class="p-2 text-xs text-gray-600">' + (c.preview || '').replace(/[<>]/g, '') + '…</td>'
    + '<td class="p-2 text-xs font-mono">' + (c.condition_codes || '-') + '</td>'
    + '<td class="p-2 text-center text-xs">' + c.priority + '</td>'
    + '<td class="p-2"><button class="kb-del text-red-500 text-xs">ลบ</button></td>'
    + '</tr>'
  ).join('');
  document.querySelectorAll('.kb-del').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.closest('tr').dataset.id, 10);
      if (!confirm('ลบ chunk นี้?')) return;
      const r2 = await adminCall('delete_knowledge_chunk', { id });
      if (r2.success) kbLoadChunks(document.getElementById('kbSourceFilter').value);
    });
  });
}

document.getElementById('kbReloadBtn').addEventListener('click', () => {
  kbLoadSources();
  kbLoadChunks('');
});

document.getElementById('kbSourceFilter').addEventListener('change', (e) => {
  kbLoadChunks(e.target.value);
});

document.getElementById('kbImportAllBtn').addEventListener('click', async () => {
  const out = document.getElementById('kbImportResult');
  out.innerHTML = '<div class="text-purple-600">⏳ กำลัง import...</div>';
  const r = await adminCall('import_knowledge_md');
  if (!r.success) { out.innerHTML = '<div class="text-red-500">' + (r.error || 'error') + '</div>'; return; }
  const colorClass = r.total_chunks > 0 ? 'text-green-600' : 'text-amber-600';
  out.innerHTML = '<div class="' + colorClass + '">✅ รวม ' + r.total_chunks + ' chunks</div>'
    + '<div class="text-xs text-gray-500 mt-1">docs_dir: ' + (r.docs_dir || '-') + '</div>'
    + '<ul class="mt-2 text-xs space-y-1">'
    + r.data.map((d) =>
        '<li>📄 ' + d.file + ': ' + d.chunks + ' chunks'
        + ' <span class="text-gray-400">(exists=' + d.exists + ', size=' + d.size_bytes + ')</span>'
        + '</li>'
      ).join('')
    + '</ul>'
    + (r.total_chunks === 0
        ? '<div class="mt-2 p-2 bg-amber-50 text-amber-700 rounded text-xs">⚠️ ไฟล์ docs/*.md ไม่อยู่บน server — ใช้ "วาง markdown ตรงๆ" ด้านล่างแทน</div>'
        : '');
  kbLoadSources();
  kbLoadChunks('');
});

document.getElementById('kbPasteBtn').addEventListener('click', async () => {
  const source = document.getElementById('kbPasteSource').value.trim() || 'custom_paste';
  const md = document.getElementById('kbPasteContent').value;
  const out = document.getElementById('kbPasteResult');
  if (md.trim().length < 50) { out.innerHTML = '<div class="text-red-500">เนื้อหาสั้นเกินไป (>=50 chars)</div>'; return; }
  out.innerHTML = '<div class="text-purple-600">⏳ กำลัง import...</div>';
  const r = await adminCall('import_knowledge_paste', { source, markdown: md });
  if (!r.success) { out.innerHTML = '<div class="text-red-500">' + (r.error || 'error') + '</div>'; return; }
  out.innerHTML = '<div class="text-green-600">✅ ' + r.source + ': ' + r.chunks_imported + ' chunks</div>';
  document.getElementById('kbPasteContent').value = '';
  kbLoadSources();
  kbLoadChunks('');
});

document.querySelectorAll('.kb-import-one').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const file = btn.dataset.file;
    const out = document.getElementById('kbImportResult');
    out.innerHTML = '<div class="text-purple-600">⏳ กำลัง import ' + file + '...</div>';
    const r = await adminCall('import_knowledge_md', { filename: file });
    if (!r.success) { out.innerHTML = '<div class="text-red-500">' + (r.error || 'error') + '</div>'; return; }
    out.innerHTML = '<div class="text-green-600">✅ ' + file + ': ' + r.chunks_imported + ' chunks</div>';
    kbLoadSources();
  });
});

document.getElementById('kbAddChunkBtn').addEventListener('click', async () => {
  const source = prompt('source label (เช่น custom_pharmacy_note):', 'custom');
  if (!source) return;
  const title = prompt('หัวข้อ:');
  const content = prompt('เนื้อหา (ขั้นต่ำ 20 chars):');
  if (!content || content.length < 20) return alert('เนื้อหาสั้นเกินไป');
  const cc = prompt('condition_codes (csv) เช่น fever,cough:', '');
  const r = await adminCall('save_knowledge_chunk', {
    source, title, content, condition_codes: cc, priority: 60, is_active: 1
  });
  if (r.success) kbLoadSources();
  else alert(r.error || 'error');
});

document.getElementById('kbTestBtn').addEventListener('click', async () => {
  const q = document.getElementById('kbTestQuery').value.trim();
  if (!q) return;
  const out = document.getElementById('kbTestResult');
  out.innerHTML = '<div class="text-purple-600">⏳</div>';
  const r = await adminCall('sandbox_test_retrieve', { query: q });
  if (!r.success) { out.innerHTML = '<div class="text-red-500">' + (r.error || 'error') + '</div>'; return; }

  const conds = Array.isArray(r.matched_conditions) ? r.matched_conditions : [];
  const diag = '<div class="p-2 bg-gray-50 rounded text-xs space-y-0.5">'
    + '<div>🔍 query: <code>' + q + '</code></div>'
    + '<div>📊 chunks ใน KB ทั้งหมด: <strong>' + (r.total_chunks_in_kb || 0) + '</strong></div>'
    + '<div>📊 chunks สำหรับ account นี้: <strong>' + (r.chunks_for_account || 0) + '</strong></div>'
    + '<div>🆔 account_id ที่ใช้: <code>' + (r.account_id_used === null ? 'NULL' : r.account_id_used) + '</code></div>'
    + '<div>🏷️ condition_codes detect ได้: <code>' + (conds.join(', ') || '-') + '</code></div>'
    + '</div>';

  if (!Array.isArray(r.data) || r.data.length === 0) {
    out.innerHTML = diag
      + '<div class="mt-2 p-3 bg-amber-50 text-amber-700 rounded text-sm">'
      + '⚠️ ไม่พบ chunk — '
      + (r.total_chunks_in_kb === 0
          ? 'KB ว่างเปล่า ให้ import ก่อน'
          : 'มี chunks ใน KB แต่ retrieval ไม่เจอ — ลองพิมพ์ keyword ใกล้เคียง เช่น "ปวดศีรษะ" หรือ "ไมเกรน"')
      + '</div>';
    return;
  }

  out.innerHTML = diag
    + '<div class="mt-2 space-y-2">'
    + r.data.map((c, i) =>
      '<div class="p-2 border rounded">'
      + '<div class="text-xs text-purple-600 font-bold">#' + (i + 1) + ' • ' + c.source + ' • score=' + Number(c.score || 0).toFixed(1) + '</div>'
      + '<div class="text-xs font-medium mt-1">' + (c.heading_path || c.title || '-') + '</div>'
      + '<div class="text-xs text-gray-700 mt-1 line-clamp-3">' + (c.content || '').replace(/[<>]/g, '').substring(0, 300) + '…</div>'
      + '</div>'
    ).join('')
    + '</div>';
});

loadSymptomCodes();
loadRedFlags();
loadConditions();
kbLoadSources();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
