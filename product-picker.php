<?php
/**
 * Product Picker — standalone read-only product selection page.
 *
 * Opened as a popup from AI Studio Flex (ai-chat.php?tab=studio) and Flex Builder
 * (flex-builder.php). Lets an admin search and multi-select real products, then
 * returns the normalized selection to the opener via postMessage and closes.
 *
 * Read-only: reads api/shop-products.php only. No DB writes, no new tables.
 *
 * Return shape (per product), normalized client-side by the API `source`:
 *   { sku, name, image, basePrice, promotionPrice, unit, url }
 *
 * @spec ai-studio-flex-product-picker
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_user']['id'])) {
    header('Location: /auth/login.php');
    exit;
}

$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
$returnTo = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['return'] ?? 'studio'));
$cnyImgBase = 'https://manager.cnypharmacy.com';
$cnyShopBase = 'https://www.cnypharmacy.com';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เลือกสินค้า · Product Picker</title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root { --brand:#06C755; --ink:#1A202C; --muted:#718096; --line:#E2E8F0; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Thai",sans-serif;
         background:#F7FAFC; color:var(--ink); }
  header { position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--line);
           padding:12px 16px; display:flex; gap:10px; align-items:center; }
  header h1 { font-size:15px; margin:0; font-weight:700; white-space:nowrap; }
  .search { flex:1; display:flex; gap:8px; align-items:center; background:#F1F5F9;
            border:1px solid var(--line); border-radius:10px; padding:8px 12px; }
  .search input { border:0; background:transparent; outline:none; width:100%; font-size:14px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
          gap:12px; padding:16px; padding-bottom:96px; }
  .card { background:#fff; border:2px solid var(--line); border-radius:12px; overflow:hidden;
          cursor:pointer; transition:.15s; position:relative; user-select:none; }
  .card:hover { border-color:#CBD5E0; transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,.06); }
  .card.sel { border-color:var(--brand); box-shadow:0 0 0 3px rgba(6,199,85,.15); }
  .card .thumb { width:100%; aspect-ratio:1/1; object-fit:cover; background:#EDF2F7; display:block; }
  .card .meta { padding:8px 10px; }
  .card .sku { font-size:10px; font-weight:700; color:var(--brand); letter-spacing:.3px; }
  .card .nm { font-size:12.5px; font-weight:600; line-height:1.3; margin:2px 0 6px;
              display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:32px; }
  .card .price { font-size:15px; font-weight:800; color:var(--ink); }
  .card .price .promo { color:#E53E3E; }
  .card .price .base { color:#A0AEC0; text-decoration:line-through; font-size:12px; font-weight:600; margin-left:6px; }
  .card .unit { font-size:11px; color:var(--muted); }
  .card .tick { position:absolute; top:8px; right:8px; width:24px; height:24px; border-radius:50%;
                background:#fff; border:2px solid var(--line); display:flex; align-items:center; justify-content:center;
                color:#fff; font-size:12px; }
  .card.sel .tick { background:var(--brand); border-color:var(--brand); }
  .card .oos { position:absolute; top:8px; left:8px; background:#718096; color:#fff; font-size:9px;
               font-weight:700; padding:2px 6px; border-radius:6px; }
  footer { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--line);
           padding:12px 16px; display:flex; gap:12px; align-items:center; z-index:20; }
  footer .count { font-size:14px; font-weight:700; }
  footer .count span { color:var(--brand); }
  .btn { border:0; border-radius:10px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer; }
  .btn-primary { background:var(--brand); color:#fff; }
  .btn-primary:disabled { opacity:.4; cursor:not-allowed; }
  .btn-ghost { background:#EDF2F7; color:#4A5568; }
  .state { text-align:center; color:var(--muted); padding:48px 16px; grid-column:1/-1; }
  .more { grid-column:1/-1; text-align:center; padding:8px; }
  .sentinel { grid-column:1/-1; height:1px; }
</style>
</head>
<body>
<header>
  <h1><i class="fas fa-pills" style="color:var(--brand)"></i> เลือกสินค้า</h1>
  <div class="search">
    <i class="fas fa-search" style="color:var(--muted)"></i>
    <input id="q" type="search" placeholder="ค้นหาชื่อสินค้า / รหัส (SKU) / บาร์โค้ด…" autocomplete="off">
  </div>
</header>

<main class="grid" id="grid">
  <div class="state" id="state"><i class="fas fa-spinner fa-spin"></i> กำลังโหลดสินค้า…</div>
</main>

<footer>
  <div class="count">เลือกแล้ว <span id="cnt">0</span> ชิ้น</div>
  <div style="flex:1"></div>
  <button class="btn btn-ghost" id="clear">ล้าง</button>
  <button class="btn btn-ghost" onclick="window.close()">ยกเลิก</button>
  <button class="btn btn-primary" id="confirm" disabled>ยืนยัน (<span id="cnt2">0</span>)</button>
</footer>

<script>
const ACCOUNT     = <?= json_encode($lineAccountId) ?>;
const RETURN_TO   = <?= json_encode($returnTo) ?>;
const CNY_IMG     = <?= json_encode($cnyImgBase) ?>;
const CNY_SHOP    = <?= json_encode($cnyShopBase) ?>;
const PLACEHOLDER = CNY_IMG + '/uploads/product_photo/placeholder.jpg';

const state = { q:'', page:1, source:'', hasMore:true, loading:false, picked:new Map() };

const $ = id => document.getElementById(id);
const grid = $('grid');

function absImage(url, source) {
  if (!url) return PLACEHOLDER;
  if (/^https?:\/\//i.test(url)) return url;
  const path = String(url).replace(/^\/+/, '');
  // CNY photo_path is relative to the manager host; everything else is same-origin.
  if (source === 'cny_products') return CNY_IMG + '/' + path;
  return location.origin + '/' + path;
}

function productUrl(p, source) {
  if (source === 'cny_products' && p.sku) return CNY_SHOP + '/product/' + encodeURIComponent(p.sku);
  return location.origin; // valid https fallback; CTA still opens the shop
}

// API row (any source) → normalized builder shape.
function normalize(p, source) {
  const base  = Number(p.price) || 0;
  const saleR = p.sale_price != null ? Number(p.sale_price) : 0;
  const promo = saleR > 0 && saleR < base ? saleR : null;
  return {
    sku: String(p.sku || p.id || ''),
    name: String(p.name || ''),
    image: absImage(p.image_url, source),
    basePrice: base,
    promotionPrice: promo,
    unit: String(p.unit || ''),
    url: productUrl(p, source),
    stock: Number(p.stock ?? 0),
  };
}

function priceHtml(n) {
  if (n.promotionPrice != null)
    return `<span class="promo">฿${Math.round(n.promotionPrice)}</span><span class="base">฿${Math.round(n.basePrice)}</span>`;
  return `฿${Math.round(n.basePrice)}`;
}

function cardHtml(n) {
  const sel = state.picked.has(n.sku) ? ' sel' : '';
  const oos = n.stock <= 0 ? '<div class="oos">หมด</div>' : '';
  return `<div class="card${sel}" data-sku="${encodeURIComponent(n.sku)}">
    <div class="tick"><i class="fas fa-check"></i></div>${oos}
    <img class="thumb" loading="lazy" src="${n.image}" onerror="this.src='${PLACEHOLDER}'">
    <div class="meta">
      <div class="sku">SKU ${n.sku || '-'}</div>
      <div class="nm">${escapeHtml(n.name)}</div>
      <div class="price">${priceHtml(n)} <span class="unit">${escapeHtml(n.unit)}</span></div>
    </div>
  </div>`;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function load(reset) {
  if (state.loading) return;
  if (reset) { state.page = 1; state.hasMore = true; grid.innerHTML = '<div class="state"><i class="fas fa-spinner fa-spin"></i> กำลังโหลด…</div>'; }
  if (!state.hasMore) return;
  state.loading = true;

  const params = new URLSearchParams({
    action: 'products', page: String(state.page), limit: '24',
    search: state.q, account: String(ACCOUNT),
  });
  let data;
  try {
    const res = await fetch('api/shop-products.php?' + params.toString());
    data = await res.json();
  } catch (e) {
    if (reset) grid.innerHTML = '<div class="state">โหลดสินค้าไม่สำเร็จ ลองใหม่อีกครั้ง</div>';
    state.loading = false; return;
  }
  if (reset) grid.innerHTML = '';
  if (!data || !data.success) {
    if (reset) grid.innerHTML = '<div class="state">ไม่พบสินค้า</div>';
    state.loading = false; return;
  }

  state.source = data.source || '';
  const rows = (data.products || []).map(p => normalize(p, state.source));
  if (rows.length === 0 && reset) {
    grid.innerHTML = '<div class="state"><i class="far fa-folder-open"></i><br>ไม่พบสินค้าที่ตรงกับคำค้น</div>';
  } else {
    grid.insertAdjacentHTML('beforeend', rows.map(cardHtml).join(''));
    // Stash normalized rows for instant selection without re-deriving from DOM.
    rows.forEach(n => rowCache.set(n.sku, n));
  }

  state.hasMore = !!(data.pagination && data.pagination.has_more);
  state.page += 1;
  state.loading = false;
  ensureSentinel();
}

const rowCache = new Map();

function ensureSentinel() {
  let s = $('sentinel');
  if (!s && state.hasMore) {
    grid.insertAdjacentHTML('beforeend', '<div class="sentinel" id="sentinel"></div>');
    io.observe($('sentinel'));
  } else if (s && !state.hasMore) {
    io.unobserve(s); s.remove();
  }
}

const io = new IntersectionObserver(entries => {
  if (entries.some(e => e.isIntersecting) && state.hasMore && !state.loading) {
    const old = $('sentinel'); if (old) { io.unobserve(old); old.remove(); }
    load(false);
  }
}, { rootMargin: '300px' });

// --- interactions ---
grid.addEventListener('click', e => {
  const card = e.target.closest('.card');
  if (!card) return;
  const sku = decodeURIComponent(card.dataset.sku);
  if (state.picked.has(sku)) { state.picked.delete(sku); card.classList.remove('sel'); }
  else { state.picked.set(sku, rowCache.get(sku)); card.classList.add('sel'); }
  updateCount();
});

let qTimer;
$('q').addEventListener('input', e => {
  clearTimeout(qTimer);
  state.q = e.target.value.trim();
  qTimer = setTimeout(() => load(true), 300);
});

$('clear').addEventListener('click', () => {
  state.picked.clear();
  document.querySelectorAll('.card.sel').forEach(c => c.classList.remove('sel'));
  updateCount();
});

$('confirm').addEventListener('click', () => {
  const products = [...state.picked.values()].filter(Boolean).map(({stock, ...keep}) => keep);
  if (products.length === 0) return;
  const payload = { type: 'reya:products', returnTo: RETURN_TO, products };
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(payload, location.origin);
      window.close();
      return;
    }
  } catch (e) { /* fall through */ }
  // Fallback: hand off via sessionStorage then navigate back (matches useFlexInBroadcast).
  try { sessionStorage.setItem('reya_picked_products', JSON.stringify(products)); } catch (e) {}
  const back = RETURN_TO === 'builder' ? 'flex-builder.php' : 'ai-chat.php?tab=studio';
  location.href = back;
});

function updateCount() {
  const n = state.picked.size;
  $('cnt').textContent = n; $('cnt2').textContent = n;
  $('confirm').disabled = n === 0;
}

load(true);
</script>
</body>
</html>
