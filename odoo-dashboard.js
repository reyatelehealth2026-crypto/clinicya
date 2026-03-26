const WH_API_FAST='/api/odoo-dashboard-fast.php';
const WH_API_CANDIDATES=[
    '/api/odoo-dashboard-api.php',
    '/api/odoo-webhooks-dashboard.php'
];
let WH_API_ACTIVE=WH_API_CANDIDATES[0];

 const ODOO_PROD_BASE = 'https://erp.cnyrxapp.com';

const EVENT_LABELS={'sale.order.created':'สร้างออเดอร์','sale.order.confirmed':'ยืนยันออเดอร์','sale.order.done':'ออเดอร์สำเร็จ','sale.order.cancelled':'ยกเลิกออเดอร์','delivery.validated':'เริ่มจัดเตรียม','delivery.in_transit':'กำลังจัดส่ง','delivery.done':'ส่งเสร็จแล้ว','delivery.cancelled':'ยกเลิกการส่ง','delivery.back_order':'ส่งบางส่วน','invoice.created':'สร้างใบแจ้งหนี้','invoice.posted':'ออกใบแจ้งหนี้','invoice.paid':'ชำระเงินแล้ว','invoice.cancelled':'ยกเลิกใบแจ้งหนี้','invoice.overdue':'เกินกำหนดชำระ','payment.received':'รับชำระเงิน','payment.confirmed':'ยืนยันชำระเงิน','order.validated':'ยืนยันออเดอร์','order.picker_assigned':'มอบหมาย Picker','order.picking':'กำลังจัดสินค้า','order.picked':'จัดสินค้าเสร็จ','order.packing':'กำลังแพ็ค','order.packed':'แพ็คเสร็จ','order.reserved':'จองสินค้าแล้ว','order.awaiting_payment':'รอชำระเงิน','order.paid':'ชำระเงินแล้ว','order.to_delivery':'เตรียมจัดส่ง','order.in_delivery':'กำลังจัดส่ง','order.delivered':'จัดส่งสำเร็จ','order.cancelled':'ยกเลิกออเดอร์'};
const SKIP_REASON_LABELS={'disabled':'ปิดการแจ้งเตือน','no_line_user':'ไม่มี LINE','duplicate':'ซ้ำ','preference':'ตั้งค่าไม่รับ','no_preference':'ไม่พบการตั้งค่า','throttle':'จำกัดความถี่','invalid':'ข้อมูลไม่ถูกต้อง'};
const EVENT_ICONS={'sale.order.confirmed':'🛒','sale.order.cancelled':'❌','sale.order.done':'✅','sale.order.created':'📝','delivery.validated':'📦','delivery.cancelled':'❌','delivery.back_order':'🔄','delivery.in_transit':'🚚','delivery.done':'✅','invoice.posted':'🧾','invoice.paid':'💰','invoice.cancelled':'❌','invoice.overdue':'⚠️','invoice.created':'📄','payment.received':'💳','payment.confirmed':'💳','order.validated':'✅','order.picker_assigned':'👤','order.picking':'📦','order.picked':'✅','order.packing':'📦','order.packed':'✅','order.reserved':'🔒','order.awaiting_payment':'💰','order.paid':'💳','order.to_delivery':'🚚','order.in_delivery':'🚚','order.delivered':'✅','order.cancelled':'❌'};

function escapeHtml(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// Runtime fallback in case production serves an older PHP template without these modal roots.
function ensureDashboardModalRoots(){
    if(!document.body) return;
    const missing=[];
    if(!document.getElementById('bdoDetailModal')){
        missing.push([
            '<div id="bdoDetailModal" class="modal-backdrop-custom" onclick="if(event.target===this){this.classList.remove(\'active\');}">',
                '<div class="detail-modal-container" style="max-width:960px;">',
                    '<div class="detail-modal-header">',
                        '<h5><i class="bi bi-file-earmark-check" style="color:var(--success);"></i> <span id="bdoDetailTitle">รายละเอียด BDO</span></h5>',
                        '<button class="detail-modal-close" onclick="document.getElementById(\'bdoDetailModal\').classList.remove(\'active\')">&times;</button>',
                    '</div>',
                    '<div class="detail-modal-body" id="bdoDetailContent">',
                        '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลดรายละเอียด...</div></div>',
                    '</div>',
                '</div>',
            '</div>'
        ].join(''));
    }
    if(!document.getElementById('paymentDetailModal')){
        missing.push([
            '<div id="paymentDetailModal" class="modal-backdrop-custom" onclick="if(event.target===this){this.classList.remove(\'active\');}">',
                '<div class="detail-modal-container" style="max-width:700px;">',
                    '<div class="detail-modal-header">',
                        '<h5><i class="bi bi-credit-card" style="color:var(--success);"></i> <span id="paymentDetailTitle">รายละเอียดการชำระเงิน</span></h5>',
                        '<button class="detail-modal-close" onclick="document.getElementById(\'paymentDetailModal\').classList.remove(\'active\')">&times;</button>',
                    '</div>',
                    '<div class="detail-modal-body" id="paymentDetailContent">',
                        '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>',
                    '</div>',
                '</div>',
            '</div>'
        ].join(''));
    }
    if(!document.getElementById('pdfViewerModal')){
        missing.push([
            '<div id="pdfViewerModal" class="modal-backdrop-custom" onclick="if(event.target===this){closePdfViewer();}">',
                '<div class="detail-modal-container" style="max-width:950px;">',
                    '<div class="detail-modal-header">',
                        '<h5><i class="bi bi-file-earmark-pdf" style="color:#dc2626;"></i> <span id="pdfViewerTitle">ดูเอกสาร PDF</span></h5>',
                        '<div class="d-flex gap-2 align-items-center">',
                            '<a id="pdfDownloadLink" href="#" target="_blank" class="chip" style="font-size:0.78rem;text-decoration:none;"><i class="bi bi-download"></i> ดาวน์โหลด</a>',
                            '<button class="detail-modal-close" onclick="closePdfViewer()">&times;</button>',
                        '</div>',
                    '</div>',
                    '<div class="detail-modal-body" style="padding:0;">',
                        '<div id="pdfViewerContent" class="pdf-viewer-container">',
                            '<div class="loading" style="color:var(--gray-400);padding:4rem;"><i class="bi bi-file-earmark-pdf" style="font-size:3rem;"></i><div>เลือกเอกสารเพื่อดู</div></div>',
                        '</div>',
                    '</div>',
                '</div>',
            '</div>'
        ].join(''));
    }
    if(missing.length){
        document.body.insertAdjacentHTML('beforeend', missing.join(''));
        console.warn('[odoo-dashboard] Injected missing modal roots:', missing.length);
    }
}

// ===== DEBOUNCE UTILITY =====
function debounce(func, wait){
    let timeout;
    return function executedFunction(...args){
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// ===== SESSION CACHE (TTL-based, sessionStorage) =====
const _CACHE_TTL = 300000; // 5 minutes (was 3 min)
function _cacheSet(key, data, ttlMs){
    try{ sessionStorage.setItem('_c:'+key, JSON.stringify({t:Date.now(), ttl:ttlMs||_CACHE_TTL, d:data})); }catch(e){}
}
function _cacheGet(key){
    try{
        const raw=sessionStorage.getItem('_c:'+key);
        if(!raw) return null;
        const item=JSON.parse(raw);
        if(Date.now()-item.t > (item.ttl||_CACHE_TTL)){ sessionStorage.removeItem('_c:'+key); return null; }
        return item.d;
    }catch(e){ return null; }
}
function _cacheClear(prefix){
    try{
        const p='_c:'+(prefix||'');
        Object.keys(sessionStorage).filter(function(k){return k.startsWith(p);}).forEach(function(k){sessionStorage.removeItem(k);});
    }catch(e){}
}

function _dashCacheKey(section, extra){
    return 'dash:'+section+(extra?':'+extra:'');
}
function _dashRenderFromCache(sectionId, html, meta){
    const el=document.getElementById(sectionId);
    if(!el || !html) return false;
    el.innerHTML=html;
    if(meta && meta.cachedAt){ _renderSectionCacheNote(el, meta.cachedAt, meta.refreshFn || ''); }
    return true;
}
function _renderSectionCacheNote(container, cachedAt, refreshFn){
    if(!container || !cachedAt) return;
    let note=container.parentNode ? container.parentNode.querySelector('[data-cache-note-for="'+container.id+'"]') : null;
    if(!note){
        note=document.createElement('div');
        note.setAttribute('data-cache-note-for', container.id);
        note.style.cssText='font-size:0.72rem;color:var(--gray-400);text-align:right;padding:2px 4px 0;';
        if(container.parentNode) container.parentNode.insertBefore(note, container);
    }
    const ageS=Math.max(0, Math.round((Date.now()-cachedAt)/1000));
    note.innerHTML='<i class="bi bi-lightning-charge"></i> จาก cache · '+ageS+'วิที่แล้ว'
        +(refreshFn ? ' &nbsp;<a href="javascript:void(0)" onclick="'+refreshFn+'" style="color:var(--primary);text-decoration:none;">รีเฟรช</a>' : '');
}
function _dashCacheSaveHtml(key, containerId, refreshFn){
    const el=document.getElementById(containerId);
    if(!el) return;
    _cacheSet(key, { html: el.innerHTML, cachedAt: Date.now(), refreshFn: refreshFn || '' });
}

function webhookEventShortName(t){if(!t)return '-';const p=String(t).split('.');return p.length>1?p.slice(1).join('.'):t;}
function generateOdooUrl(model,id){if(!model||id==null||id==='')return '';return ODOO_PROD_BASE+'/web#id='+encodeURIComponent(String(id))+'&model='+encodeURIComponent(String(model))+'&view_type=form';}
function deliveryTypeBadge(deliveryType){const label=deliveryType==='company'?'สายส่ง':(deliveryType==='private'?'ขนส่งเอกชน':'-');const bg=deliveryType==='private'?'#fef3c7':'#e0f2fe';const clr=deliveryType==='private'?'#b45309':'#0369a1';return label==='-'?'<span style="color:var(--gray-300);font-size:0.75rem;">-</span>':'<span style="background:'+bg+';color:'+clr+';padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;">'+escapeHtml(label)+'</span>';}
function matchConfidenceBadge(confidence){const map={exact_bdo:['#dcfce7','#166534','ตรง BDO'],bdo_prepayment:['#ede9fe','#6d28d9','มัดจำ BDO'],exact:['#dbeafe','#1d4ed8','ตรงยอด'],partial:['#fef3c7','#b45309','บางส่วน'],manual:['#f3f4f6','#4b5563','manual'],unmatched:['#fee2e2','#b91c1c','ยังไม่จับคู่']};const cfg=map[confidence]||['#f3f4f6','#4b5563',confidence||'-'];return '<span style="background:'+cfg[0]+';color:'+cfg[1]+';padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;">'+escapeHtml(cfg[2])+'</span>';}
function slipBdoInfo(s){if(!(s.bdo_name||s.bdo_id))return '<span style="color:var(--gray-300);font-size:0.75rem;">-</span>';const bdoId=s.bdo_id||'';const bdoName=s.bdo_name||('BDO-'+bdoId);const raw=encodeURIComponent(JSON.stringify({bdo_id:bdoId,bdo_name:bdoName,amount_total:s.bdo_amount,delivery_type:s.delivery_type,customer_name:s.customer_name,odoo_id:s.bdo_odoo_id||s.bdo_id}));return '<a href="javascript:void(0)" onclick="openBdoDetail(\''+escapeHtml(String(bdoId))+'\',\''+escapeHtml(String(bdoName))+'\',decodeURIComponent(\''+raw+'\'))" class="ref-link">'+escapeHtml(String(bdoName))+'</a>';}

function normalizeBdoPaymentStatus(bdo){
    const payState=String(bdo?.payment_state||bdo?.payment_status||'').toLowerCase().trim();
    const wfState=String(bdo?.state||'').toLowerCase().trim();
    const paidAmount=parseFloat(bdo?.paid_amount_total ?? bdo?.paid_amount ?? bdo?.matched_amount_total ?? bdo?.matched_total ?? 0) || 0;
    const totalAmount=parseFloat(bdo?.amount_total ?? 0) || 0;
    const netRaw=bdo?.amount_net_to_pay;
    const hasNet=netRaw!=null&&netRaw!=='';
    const netToPay=hasNet?parseFloat(netRaw):null;
    const linkedSlipCount=Array.isArray(bdo?.linked_slips)?bdo.linked_slips.length:(Array.isArray(bdo?.slips)?bdo.slips.length:0);

    if(wfState==='cancel'||wfState==='cancelled'){
        return {key:'cancelled',label:'ยกเลิก'};
    }
    if(payState==='paid'||payState==='fully_paid'||payState==='reversed'){
        return {key:'paid',label:'ชำระแล้ว'};
    }
    if(payState==='partial'||payState==='partially_paid'||payState==='in_payment'){
        return {key:'partial',label:'ชำระบางส่วน'};
    }
    if(payState==='matched'||payState==='reconciled'){
        return {key:'matched',label:'จับคู่แล้ว'};
    }
    if(payState==='slip_uploaded'||payState==='uploaded'){
        return {key:'slip_uploaded',label:'แนบสลิปแล้ว'};
    }
    if(hasNet&&!isNaN(netToPay)&&netToPay<=0.009){
        return {key:'paid',label:'ชำระแล้ว'};
    }
    if(totalAmount>0&&paidAmount>=totalAmount){
        return {key:'paid',label:'ชำระแล้ว'};
    }
    if(paidAmount>0&&totalAmount>0&&paidAmount<totalAmount){
        return {key:'partial',label:'ชำระบางส่วน'};
    }
    if(linkedSlipCount>0){
        return {key:'slip_uploaded',label:'แนบสลิปแล้ว'};
    }
    return {key:'pending',label:'รอชำระ'};
}

/** BDO ที่ยังไม่ปิดยอดชำระ (ใช้ในการจับคู่สลิป — ไม่รวม paid / ยกเลิก) */
function isBdoOutstandingPayment(b){
    const ps = normalizeBdoPaymentStatus(b);
    return ps.key !== 'paid' && ps.key !== 'cancelled';
}

// ===== SECTION-LOADED GUARDS — prevent redundant API calls on tab switch =====
const _sectionLoadedAt = {};    // sectionId → timestamp
const _SECTION_STALE_MS = 300000; // 5 minutes before re-fetching
function _sectionNeedsLoad(id){
    const loadedAt = _sectionLoadedAt[id];
    if(!loadedAt) return true;
    return (Date.now() - loadedAt) > _SECTION_STALE_MS;
}
function _sectionMarkLoaded(id){
    _sectionLoadedAt[id] = Date.now();
}

function showSection(id){
    // 'webhooks-raw' maps to the same webhooks section but forces list mode
    let sectionId = id;
    let forceListMode = false;
    if(id === 'webhooks-raw'){ sectionId = 'webhooks'; forceListMode = true; }

    document.querySelectorAll('.section-panel').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.menu-card').forEach(c=>c.classList.remove('active'));
    const t=document.getElementById('section-'+sectionId);if(t)t.classList.add('active');
    const m=document.querySelector(`.menu-card[onclick="showSection('${id}')"]`);if(m)m.classList.add('active');

    if(id==='overview'){if(typeof loadTodayOverview==='function')loadTodayOverview();}
    else if(id==='webhooks'){if(_sectionNeedsLoad('webhooks')){loadWebhookStats();_sectionMarkLoaded('webhooks');}if(whViewMode==='grouped'){if(!document.getElementById('webhookList').querySelector('div[style*="border-radius:10px"]'))loadOrdersGrouped();}else{if(!document.getElementById('webhookList').querySelector('table'))loadWebhooks();}}
    else if(id==='webhooks-raw'){if(_sectionNeedsLoad('webhooks')){loadWebhookStats();_sectionMarkLoaded('webhooks');}if(forceListMode){setWhViewMode('list');}else{if(!document.getElementById('webhookList').querySelector('table'))loadWebhooks();}}
    else if(id==='customers'){loadSalespersonDropdown();if(_sectionNeedsLoad('customers')||!document.getElementById('customerList').querySelector('table')){loadCustomers();_sectionMarkLoaded('customers');}}
    else if(id==='notifications'){if(_sectionNeedsLoad('notifications')||!document.getElementById('notifList').querySelector('table')){loadNotifications();_sectionMarkLoaded('notifications');}}
    else if(id==='daily-summary'){if(dailySummaryData.length===0)loadDailySummary();if(_sectionNeedsLoad('auto-send-settings'))loadAutoSendSettings();}
    else if(id==='health'){loadSystemHealth();}
    else if(id==='slips'){if(_sectionNeedsLoad('slips')||!document.getElementById('slipList').querySelector('table')){loadSlips();_sectionMarkLoaded('slips');}}
    else if(id==='matching'){sessionStorage.setItem('_visited_matching','1');loadSalespersonDropdown();if(_sectionNeedsLoad('matching')){loadMatchingCustomerGrid();_sectionMarkLoaded('matching');}else{loadMatchingCustomerGrid();}}
}

// Last API response timing (for performance monitoring)
let _lastApiDurationMs = null;

// Actions supported by the lightweight fast endpoint
const WH_FAST_ACTIONS=new Set(['health','overview_fast','orders_today_fast','customers_fast','circuit_breaker_status','circuit_breaker_reset','customer_profile_fast','customer_slip_bdo_summary']);

// Read-only actions that benefit from Nginx fastcgi_cache (no _t cache buster)
const WH_CACHEABLE_ACTIONS=new Set(['health','overview_fast','orders_today_fast','customers_fast']);

async function whApiCall(data){
    const tried=[];
    const action=String(data&&data.action||'').trim();
    const heavyActions=new Set([
        'stats','list','customer_list','notification_log','daily_summary_preview',
        'order_grouped_today','overview_today','overview_fast','overview_combined','customer_detail',
        'customer_full_detail','odoo_orders','odoo_invoices','odoo_slips','odoo_bdos',
        'odoo_bdo_list_api','odoo_bdo_detail_api','bdo_detail','bdo_detail_live','pending_bdo_orders','activity_log_list','customer_360'
    ]);
    const timeoutMs=heavyActions.has(action)?30000:8000;
    // Skip cache buster for cacheable read-only actions — lets Nginx fastcgi_cache work
    const cacheBuster=WH_CACHEABLE_ACTIONS.has(action)?'':'?_t='+Date.now();

    // Try fast endpoint first for supported actions (file is tiny, <100 lines)
    if(WH_FAST_ACTIONS.has(action)){
        try{
            const ctrl=new AbortController();
            const timer=setTimeout(()=>ctrl.abort(),12000);
            const r=await fetch(WH_API_FAST+cacheBuster,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data),signal:ctrl.signal});
            clearTimeout(timer);
            const parsed=await r.json();
            if(parsed&&parsed.success){
                if(parsed._meta&&parsed._meta.duration_ms!=null) _lastApiDurationMs=parsed._meta.duration_ms;
                return parsed;
            }
            if(parsed&&parsed.fallback){/* not supported by fast, try heavy */}
            else tried.push(WH_API_FAST+' (err:'+(parsed&&parsed.error||r.status)+')');
        }catch(e){
            tried.push(WH_API_FAST+' ('+(e.name==='AbortError'?'timeout':e.message)+')');
        }
    }

    // Try heavy endpoints (large file, slower to parse)
    const endpoints=[WH_API_ACTIVE,...WH_API_CANDIDATES.filter(u=>u!==WH_API_ACTIVE)];
    for(const apiUrl of endpoints){
        try{
            const ctrl=new AbortController();
            const timer=setTimeout(()=>ctrl.abort(),timeoutMs);
            const r=await fetch(apiUrl+(cacheBuster||('?_t='+Date.now())),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data),signal:ctrl.signal});
            clearTimeout(timer);
            const raw=await r.text();
            let parsed=null;
            try{parsed=JSON.parse(raw);}catch(_e){parsed=null;}
            if(parsed&&typeof parsed==='object'&&Object.prototype.hasOwnProperty.call(parsed,'success')){
                if(parsed.success){
                    WH_API_ACTIVE=apiUrl;
                    if(parsed._meta&&parsed._meta.duration_ms!=null) _lastApiDurationMs=parsed._meta.duration_ms;
                    return parsed;
                }
                // success: false (e.g. Unknown action) — try next endpoint instead of sticking to this one
                tried.push(apiUrl+' ('+(parsed.error||r.status)+')');
                continue;
            }
            tried.push(apiUrl+' (non-json:'+r.status+')');
        }catch(e){
            tried.push(apiUrl+' ('+(e.name==='AbortError'?'timeout '+Math.round(timeoutMs/1000)+'s':e.message)+')');
        }
    }
    return{success:false,error:'API unreachable: '+tried.join(' | ')};
}

async function testConnection(){
    const el=document.getElementById('connectionStatus');
    try{
        const r=await whApiCall({action:'health'});
        if(r&&r.success){
            el.className='status-badge online';
            const ms=_lastApiDurationMs!=null?' · '+_lastApiDurationMs+'ms':'';
            // Show OPcache + Redis status indicators
            const d=r.data||{};
            const opcIcon=d.opcache&&d.opcache.enabled?'⚡':'';
            const redisIcon=d.redis&&d.redis.connected?'🔴':'';
            const perfTags=(opcIcon||redisIcon)?' '+[opcIcon,redisIcon].filter(Boolean).join(''):'';
            el.innerHTML='<span class="status-dot"></span><span>เชื่อมต่อแล้ว'+ms+perfTags+'</span>';
        }else{
            el.className='status-badge offline';
            const errMsg=(r&&r.error)?r.error:'ไม่สามารถเชื่อมต่อได้';
            const isCircuitOpen=errMsg.indexOf('CIRCUIT_OPEN')!==-1;
            el.innerHTML='<span class="status-dot"></span><span>'+(isCircuitOpen?'Odoo ไม่พร้อม (Circuit Open)':escapeHtml(errMsg).substring(0,40))+'</span>';
        }
    }catch(e){
        el.className='status-badge offline';
        el.innerHTML='<span class="status-dot"></span><span>Error</span>';
    }
}

// ===== WEBHOOKS =====
let whCurrentOffset=0;const whPageSize=30;

// Debounced search for webhooks
const debouncedLoadWebhooks = debounce(function(){
    whCurrentOffset = 0;
    loadWebhooks();
}, 400);

function populateWebhookEventFilter(types){const sel=document.getElementById('whFilterEvent');if(!sel)return;const cur=sel.value;let opts='<option value="">ทั้งหมด</option>';(types||[]).filter(Boolean).forEach(et=>{opts+='<option value="'+escapeHtml(et)+'"'+(cur===et?' selected':'')+'>'+escapeHtml(webhookEventShortName(et))+'</option>';});sel.innerHTML=opts;}
function applyWebhookEventFilter(et){const s=document.getElementById('whFilterEvent');if(s)s.value=et;whCurrentOffset=0;loadWebhooks();}
function safeParseWebhookPayload(d,r){if(d&&typeof d==='object')return JSON.stringify(d,null,2);if(typeof r==='string'&&r.trim()){try{return JSON.stringify(JSON.parse(r),null,2);}catch(e){return JSON.stringify({raw:r},null,2);}}return '{}';}
function resetWebhookFilters(){['whFilterEvent','whFilterStatus','whFilterSearch','whFilterDateFrom','whFilterDateTo'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});whCurrentOffset=0;loadWebhooks();}
function whGoPage(p){whCurrentOffset=p*whPageSize;loadWebhooks();}
function closeTimelineModal(){document.getElementById('orderTimelineModal').classList.remove('active');}

async function loadWebhookStats(){
    const c=document.getElementById('webhookStats');
    const res=await whApiCall({action:'stats'});
    if(!res||!res.success){c.innerHTML='<div class="result-card"><p style="color:var(--gray-500)">'+escapeHtml((res&&res.error)||'Error')+'</p></div>';return;}
    const s=res.data,rate=s.total>0?((s.success/s.total)*100).toFixed(1):0;
    const lastD=s.last_webhook?new Date(s.last_webhook):null,lastT=lastD&&!isNaN(lastD)?lastD.toLocaleString('th-TH'):'-';
    const lat=s.avg_latency_ms!=null?parseFloat(s.avg_latency_ms).toFixed(1)+' ms':'-';
    const inFlight=Number(s.received||0)+Number(s.processing||0);
    const hBg=s.dead_letter>0?'#fee2e2':s.retry>0||inFlight>0?'#fef3c7':'#dcfce7';
    const hTxt=s.dead_letter>0?'DLQ '+s.dead_letter:s.retry>0?'Retry '+s.retry:inFlight>0?'In Flight '+inFlight:'Healthy';
    const hClr=s.dead_letter>0?'#b91c1c':s.retry>0||inFlight>0?'#b45309':'#15803d';
    const box=(lbl,val,bg,clr)=>'<div class="info-box" style="background:'+(bg||'white')+';border:1px solid var(--gray-200);"><div class="info-label">'+lbl+'</div><div class="info-value" style="font-size:1.3rem;color:'+(clr||'inherit')+';">'+val+'</div></div>';
    let eC='';(s.events_by_type||[]).forEach(e=>{const et=String(e.event_type||'');if(!et)return;const enc=encodeURIComponent(et);eC+='<span class="chip" onclick="applyWebhookEventFilter(decodeURIComponent(\''+enc+'\'))" style="font-size:0.8rem;cursor:pointer;">'+escapeHtml(webhookEventShortName(et))+' <b>'+Number(e.count||0)+'</b></span> ';});
    let fC='';(s.top_failed_events||[]).forEach(e=>{const et=String(e.event_type||'');if(!et)return;const enc=encodeURIComponent(et);fC+='<span class="chip" onclick="applyWebhookEventFilter(decodeURIComponent(\''+enc+'\'))" style="font-size:0.8rem;background:#fff1f2;color:#be123c;cursor:pointer;">'+escapeHtml(webhookEventShortName(et))+' <b>'+Number(e.count||0)+'</b></span> ';});
    c.innerHTML='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1rem;">'
        +box('วันนี้',Number(s.today||0).toLocaleString(),'white','var(--primary)')
        +box('ทั้งหมด',Number(s.total||0).toLocaleString())
        +box('สำเร็จ',Number(s.success||0).toLocaleString()+' <small>('+rate+'%)</small>','#dcfce7','#16a34a')
        +box('ล้มเหลว',Number(s.failed||0).toLocaleString(),s.failed>0?'#fee2e2':'white',s.failed>0?'#dc2626':'var(--gray-400)')
        +box('Retry',Number(s.retry||0).toLocaleString(),s.retry>0?'#fef3c7':'white',s.retry>0?'#d97706':'var(--gray-400)')
        +box('Dead Letter',Number(s.dead_letter||0).toLocaleString(),s.dead_letter>0?'#fee2e2':'white',s.dead_letter>0?'#dc2626':'var(--gray-400)')
        +box('ออเดอร์วันนี้',Number(s.unique_orders_today||0).toLocaleString())
        +box('แจ้งเตือน LINE',Number(s.notified_today||0).toLocaleString(),'white','#0284c7')
        +box('Latency',lat,'white','#0f766e')
        +box('Health',hTxt,hBg,hClr)
        +'</div>'
        +(eC?'<div class="quick-section"><div class="quick-title">Events วันนี้</div><div class="quick-chips">'+eC+'</div></div>':'')
        +(fC?'<div class="quick-section" style="margin-top:0.5rem;"><div class="quick-title" style="color:#be123c;">Events ที่มีปัญหา</div><div class="quick-chips">'+fC+'</div></div>':'')
        +'<div style="font-size:0.75rem;color:var(--gray-400);text-align:right;margin-top:0.5rem;">ล่าสุด: '+lastT+'</div>';
}

async function loadWebhooks(){
    const c=document.getElementById('webhookList');
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    const p={action:'list',limit:whPageSize,offset:whCurrentOffset,
        event_type:document.getElementById('whFilterEvent')?.value||'',
        status:document.getElementById('whFilterStatus')?.value||'',
        search:document.getElementById('whFilterSearch')?.value||'',
        date_from:document.getElementById('whFilterDateFrom')?.value||'',
        date_to:document.getElementById('whFilterDateTo')?.value||''};
    const result=await whApiCall(p);
    if(!result||!result.success){c.innerHTML='<p style="padding:1rem;color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
    const {webhooks,total}=result.data;
    populateWebhookEventFilter(result.data.event_types||[]);
    const tc=document.getElementById('whTotalCount');if(tc)tc.textContent=Number(total||0).toLocaleString()+' รายการ';
    if(!webhooks||!webhooks.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;"></i>ไม่พบข้อมูล</div>';return;}
    const sm={success:'<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:50px;font-size:0.75rem;">OK</span>',retry:'<span style="background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:50px;font-size:0.75rem;">RETRY</span>',dead_letter:'<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:50px;font-size:0.75rem;">DLQ</span>',processing:'<span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:50px;font-size:0.75rem;">PROC</span>',received:'<span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:50px;font-size:0.75rem;">RCV</span>',duplicate:'<span style="background:#eef2ff;color:#4f46e5;padding:2px 8px;border-radius:50px;font-size:0.75rem;">DUP</span>'};
    let html='<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;"><thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);"><th style="padding:0.5rem;">เวลา</th><th style="padding:0.5rem;">Event</th><th style="padding:0.5rem;">ออเดอร์</th><th style="padding:0.5rem;">ลูกค้า</th><th style="padding:0.5rem;">สถานะ</th><th style="padding:0.5rem;text-align:center;">LINE</th><th style="padding:0.5rem;text-align:right;">ยอด</th><th></th></tr></thead><tbody>';
    webhooks.forEach(w=>{
        const pd=w.processed_at||w.created_at,pDate=pd?new Date(pd):null,time=pDate&&!isNaN(pDate)?pDate.toLocaleString('th-TH',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'}):'-';
        const evShort=webhookEventShortName(w.event_type),stateDisp=w.new_state_display&&w.new_state_display!=='null'?w.new_state_display:evShort;
        const status=String(w.status||'').toLowerCase(),sb=sm[status]||'<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:50px;font-size:0.75rem;">FAIL</span>';
        const hasLine=!!(w.line_user_id||(w.customer_line_user_id&&w.customer_line_user_id!=='null'));
        const lb=hasLine?'<i class="bi bi-check-circle-fill" style="color:#06c755;"></i>':'<i class="bi bi-dash-circle" style="color:var(--gray-300);"></i>';
        const numAmt=parseFloat(w.amount_total),amount=w.amount_total&&w.amount_total!=='null'&&isFinite(numAmt)?'฿'+numAmt.toLocaleString():'';
        const oNm=w.order_name&&w.order_name!=='null'?w.order_name:w.order_id||'-';
        const cNm=w.customer_name&&w.customer_name!=='null'?w.customer_name:'',cRef=w.customer_ref&&w.customer_ref!=='null'?w.customer_ref:'';
        const cDisp=cNm?cNm+(cRef?' ('+cRef+')':''):cRef||'-';
        const eOI=encodeURIComponent(w.order_id&&w.order_id!=='null'?String(w.order_id):''),eON=encodeURIComponent(oNm!=='-'?String(oNm):'');
        html+='<tr style="border-bottom:1px solid var(--gray-100);" onmouseover="this.style.background=\'var(--gray-50)\'" onmouseout="this.style.background=\'transparent\'">'
            +'<td style="padding:0.4rem 0.5rem;white-space:nowrap;color:var(--gray-500);font-size:0.8rem;">'+escapeHtml(time)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;"><span title="'+escapeHtml(w.event_type||'')+'">'+escapeHtml(stateDisp)+'</span></td>'
            +'<td style="padding:0.4rem 0.5rem;"><a href="javascript:void(0)" onclick="showOrderTimeline(decodeURIComponent(\''+eOI+'\'),decodeURIComponent(\''+eON+'\'))" style="color:var(--primary);text-decoration:none;font-weight:500;">'+escapeHtml(oNm)+'</a></td>'
            +'<td style="padding:0.4rem 0.5rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+escapeHtml(cDisp)+'">'+escapeHtml(cDisp)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;">'+sb+'</td>'
            +'<td style="padding:0.4rem 0.5rem;text-align:center;">'+lb+'</td>'
            +'<td style="padding:0.4rem 0.5rem;text-align:right;">'+escapeHtml(amount)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;"><button onclick="showWebhookDetail('+w.id+')" style="background:none;border:1px solid var(--gray-200);border-radius:6px;padding:2px 7px;cursor:pointer;font-size:0.75rem;"><i class="bi bi-code-slash"></i></button></td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';c.innerHTML=html;
    const pag=document.getElementById('webhookPagination');
    if(pag){if(total>whPageSize){const tp=Math.ceil(total/whPageSize),cp=Math.floor(whCurrentOffset/whPageSize)+1;pag.style.cssText='display:flex !important;justify-content:center;gap:0.5rem;margin-top:1rem;';let ph=cp>1?'<button class="chip" onclick="whGoPage('+(cp-2)+')"><i class="bi bi-chevron-left"></i></button>':'';ph+='<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า '+cp+' / '+tp+'</span>';if(cp<tp)ph+='<button class="chip" onclick="whGoPage('+cp+')"><i class="bi bi-chevron-right"></i></button>';pag.innerHTML=ph;}else pag.style.cssText='display:none !important;';}
}

async function showWebhookDetail(id){
    const _wCacheKey='webhook_detail:'+String(id);
    let result=_cacheGet(_wCacheKey);
    if(!result){
        result=await whApiCall({action:'detail',id});
        if(result&&result.success) _cacheSet(_wCacheKey,result,300000);
    }
    if(!result||!result.success){alert(String((result&&result.error)||'Error'));return;}
    const w=result.data,modal=document.getElementById('orderTimelineModal'),content=document.getElementById('orderTimelineContent');
    const payloadText=escapeHtml(safeParseWebhookPayload(w.payload_decoded,w.payload));
    const pd=w.processed_at||w.created_at,pDate=pd?new Date(pd):null,pTime=pDate&&!isNaN(pDate)?pDate.toLocaleString('th-TH'):'-';
    content.innerHTML='<h5 style="margin-bottom:1rem;"><i class="bi bi-code-slash"></i> Webhook #'+escapeHtml(String(w.id))+'</h5>'
        +'<div class="partner-info-grid" style="margin-bottom:1rem;">'
        +'<div class="info-box"><div class="info-label">Event</div><div class="info-value">'+escapeHtml(w.event_type)+'</div></div>'
        +'<div class="info-box"><div class="info-label">Status</div><div class="info-value">'+escapeHtml(w.status)+'</div></div>'
        +'<div class="info-box"><div class="info-label">Delivery ID</div><div class="info-value" style="font-size:0.75rem;word-break:break-all;">'+escapeHtml(w.delivery_id)+'</div></div>'
        +'<div class="info-box"><div class="info-label">เวลา</div><div class="info-value">'+escapeHtml(pTime)+'</div></div></div>'
        +(w.error_message?'<div style="background:#fee2e2;padding:0.75rem;border-radius:8px;margin-bottom:1rem;font-size:0.85rem;color:#dc2626;"><b>Error:</b> '+escapeHtml(w.error_message)+'</div>':'')
        +'<div class="content-title"><i class="bi bi-braces"></i> Payload</div><pre class="json-display">'+payloadText+'</pre>';
    modal.classList.add('active');
}

async function showOrderTimeline(orderId,orderName){
    if(!orderId&&!orderName)return;
    const modal=document.getElementById('orderTimelineModal'),content=document.getElementById('orderTimelineContent');
    modal.classList.add('active');
    const _otCacheKey='order_timeline:'+String(orderId||'')+'_'+String(orderName||'');
    const cached=_cacheGet(_otCacheKey);
    if(cached){
        if(!cached.success){content.innerHTML='<p style="color:var(--gray-500);">'+escapeHtml((cached&&cached.error)||'Error')+'</p>';return;}
        const {events,order_name:oName}=cached.data;
        renderOrderTimelineEvents(content,events,oName,orderId);
        return;
    }
    content.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    const params={action:'order_timeline'};
    if(orderId&&orderId!=='null')params.order_id=orderId;
    if(orderName&&orderName!=='null'&&orderName!=='-')params.order_name=orderName;
    const result=await whApiCall(params);
    if(result&&result.success) _cacheSet(_otCacheKey,result,60000);
    if(!result||!result.success){content.innerHTML='<p style="color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
    const {events,order_name:oName}=result.data;
    renderOrderTimelineEvents(content,events,oName,orderId);
}

function renderOrderTimelineEvents(content,events,oName,orderId){
    let html='<h5 style="margin-bottom:1rem;"><i class="bi bi-clock-history"></i> Timeline: '+escapeHtml(oName||orderId||'-')+'</h5>';
    if(!events||!events.length){html+='<p style="color:var(--gray-400);">ไม่พบข้อมูล</p>';}
    else{
        html+='<div style="position:relative;padding-left:24px;border-left:3px solid var(--gray-200);margin-left:8px;">';
        events.forEach((e,i)=>{
            const et=String(e.event_type||''),icon=EVENT_ICONS[et]||'📌';
            const pd=e.processed_at?new Date(e.processed_at):null,t=pd&&!isNaN(pd)?pd.toLocaleString('th-TH'):'-';
            const state=e.new_state_display&&e.new_state_display!=='null'?e.new_state_display:et?et.split('.').pop():'-';
            const dot=i===events.length-1?'var(--primary)':'var(--gray-400)';
            const lTag=e.line_user_id?'<span style="background:#dcfce7;color:#06c755;padding:1px 6px;border-radius:50px;font-size:0.7rem;margin-left:4px;">LINE ✓</span>':'';
            html+='<div style="position:relative;margin-bottom:1.5rem;padding-left:16px;">'
                +'<div style="position:absolute;left:-32px;top:2px;width:16px;height:16px;border-radius:50%;background:'+dot+';border:3px solid white;box-shadow:0 0 0 2px '+dot+';"></div>'
                +'<div style="font-weight:600;font-size:0.9rem;">'+icon+' '+escapeHtml(state)+' '+lTag+'</div>'
                +'<div style="font-size:0.8rem;color:var(--gray-500);margin-top:2px;">'+escapeHtml(t)+'</div>'
                +'<div style="font-size:0.75rem;color:var(--gray-400);">'+escapeHtml(et)+' &middot; '+escapeHtml(e.status||'-')+'</div>'
                +'</div>';
        });
        html+='</div>';
    }
    content.innerHTML=html;
}

// ===== CUSTOMERS =====
let custCurrentOffset=0;const custPageSize=30;
let _salespersonDropdownLoaded=false;
let _salespersonDropdownPromise=null;

// Debounced search functions
const debouncedLoadCustomers = debounce(function(){
    custCurrentOffset = 0;
    loadCustomers();
}, 500);

function resetCustomerFilter(){const el=document.getElementById('custSearch');if(el)el.value='';const fi=document.getElementById('custInvoiceFilter');if(fi)fi.value='';const sb=document.getElementById('custSortBy');if(sb)sb.value='';const sp=document.getElementById('custSalesperson');if(sp)sp.value='';custCurrentOffset=0;loadCustomers();}
async function loadSalespersonDropdown(){
    const sel=document.getElementById('custSalesperson');if(!sel)return;
    if(_salespersonDropdownLoaded && sel.options.length>1)return;
    if(_salespersonDropdownPromise)return _salespersonDropdownPromise;
    _salespersonDropdownPromise=(async function(){
        const res=await whApiCall({action:'salesperson_list'});
        if(!res||!res.success||!res.data||!res.data.salespersons||!res.data.salespersons.length)return;
        const cur=sel.value;
        let opts='<option value="">พนักงานขาย: ทั้งหมด</option>';
        res.data.salespersons.forEach(function(s){
            const nm=escapeHtml(s.name||s.id||'-');
            const cnt=s.customer_count?' ('+s.customer_count+')':'';
            opts+='<option value="'+escapeHtml(String(s.id||''))+'"'+(cur===String(s.id)?' selected':'')+'>'+nm+cnt+'</option>';
        });
        sel.innerHTML=opts;
        if(cur)sel.value=cur;
        _salespersonDropdownLoaded=true;
    })();
    try{return await _salespersonDropdownPromise;}finally{_salespersonDropdownPromise=null;}
}
function custGoPage(p){custCurrentOffset=p*custPageSize;loadCustomers();}
function closeCustomerInvoiceModal(){const m=document.getElementById('customerInvoiceModal');if(m)m.classList.remove('active');}

async function loadCustomers(){
    const c=document.getElementById('customerList');
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    const invoiceFilter=document.getElementById('custInvoiceFilter')?.value||'';
    const sortBy=document.getElementById('custSortBy')?.value||'';
    const salespersonId=document.getElementById('custSalesperson')?.value||'';
    const search=document.getElementById('custSearch')?.value||'';
    const fastMode=!search && !invoiceFilter && !sortBy && !salespersonId;
    const result=await whApiCall({action:'customer_list',limit:custPageSize,offset:custCurrentOffset,search,invoice_filter:invoiceFilter,sort_by:sortBy,salesperson_id:salespersonId,fast:fastMode?1:0});
    if(!result||!result.success){c.innerHTML='<p style="padding:1rem;color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
    const {customers,total}=result.data;
    const tc=document.getElementById('custTotalCount');if(tc)tc.textContent=Number(total||0).toLocaleString()+' รายการ';
    if(!customers||!customers.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-people" style="font-size:2rem;display:block;"></i>ไม่พบข้อมูล</div>';return;}
    let html='<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;"><thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);"><th style="padding:0.5rem;">ชื่อลูกค้า</th><th style="padding:0.5rem;">รหัส</th><th style="padding:0.5rem;">Partner ID</th><th style="padding:0.5rem;text-align:right;">ยอดรวม</th><th style="padding:0.5rem;text-align:right;">ออเดอร์</th><th style="padding:0.5rem;">พนักงานขาย</th><th style="padding:0.5rem;text-align:center;">LINE</th><th style="padding:0.5rem;text-align:center;">ออเดอร์/ใบแจ้งหนี้</th><th style="padding:0.5rem;text-align:center;">จัดการ</th></tr></thead><tbody>';
    customers.forEach(cu=>{
        const nm=escapeHtml(cu.customer_name||cu.name||'-'),ref=escapeHtml(cu.customer_ref||cu.ref||'-');
        const pid=String(cu.partner_id||cu.customer_id||cu.odoo_id||'-');
        const rawAmt=cu.spend_30d??cu.total_amount??cu.total_due??null;
        const amt=rawAmt!=null&&Number(rawAmt)>0?'฿'+Number(rawAmt).toLocaleString():'-';
        const rawOrd=cu.orders_total??cu.orders_30d??cu.order_count??null;
        const orders=rawOrd!=null?Number(rawOrd):'-';
        const hasLine=!!(cu.line_user_id);
        const lineBadge=hasLine?'<span style="background:#06c755;color:white;padding:2px 7px;border-radius:50px;font-size:0.72rem;"><i class="bi bi-check-lg"></i> เชื่อม</span>':'<span style="background:var(--gray-100);color:var(--gray-400);padding:2px 7px;border-radius:50px;font-size:0.72rem;">ยังไม่</span>';
        const encRef=encodeURIComponent(cu.customer_ref||cu.ref||''),encId=encodeURIComponent(pid),encNm=encodeURIComponent(cu.customer_name||cu.name||'');
        const encLineId=encodeURIComponent(cu.line_user_id||'');
        const unlinkBtn=hasLine?'<button onclick="openDashUnlinkModal(decodeURIComponent(\''+encLineId+'\'),\''+escapeHtml(nm)+'\',this)" style="background:#dc2626;color:white;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:0.75rem;font-weight:500;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;"><i class="bi bi-unlink"></i> ยกเลิก</button>':'';
        const spName=escapeHtml(cu.salesperson_name||'-');
        html+='<tr style="border-bottom:1px solid var(--gray-100);" onmouseover="this.style.background=\'var(--gray-50)\'" onmouseout="this.style.background=\'transparent\'"><td style="padding:0.5rem;font-weight:500;">'+nm+'</td><td style="padding:0.5rem;color:var(--gray-500);">'+ref+'</td><td style="padding:0.5rem;color:var(--gray-500);">'+escapeHtml(pid)+'</td><td style="padding:0.5rem;text-align:right;font-weight:500;">'+amt+'</td><td style="padding:0.5rem;text-align:right;">'+orders+'</td><td style="padding:0.5rem;font-size:0.8rem;color:var(--gray-600);">'+spName+'</td><td style="padding:0.5rem;text-align:center;">'+lineBadge+'</td><td style="padding:0.5rem;text-align:center;white-space:nowrap;"><button onclick="showCustomerDetail(decodeURIComponent(\''+encRef+'\'),decodeURIComponent(\''+encId+'\'),decodeURIComponent(\''+encNm+'\'))" style="background:var(--gray-800);color:white;border:none;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:0.75rem;margin-right:4px;"><i class="bi bi-eye"></i> ดูเร็ว</button><button onclick="window.open(\'odoo-customer-detail.php?ref=\'+encodeURIComponent(decodeURIComponent(\''+encRef+'\'))+\'&partner_id=\'+encodeURIComponent(decodeURIComponent(\''+encId+'\'))+\'&name=\'+encodeURIComponent(decodeURIComponent(\''+encNm+'\')),\'_blank\')" style="background:var(--primary);color:white;border:none;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:0.75rem;"><i class="bi bi-box-arrow-up-right"></i> เต็ม</button></td><td style="padding:0.5rem;text-align:center;">'+unlinkBtn+'</td></tr>';
    });
    html+='</tbody></table></div>';c.innerHTML=html;
    const pag=document.getElementById('customerPagination');
    if(pag){if(total>custPageSize){const tp=Math.ceil(total/custPageSize),cp=Math.floor(custCurrentOffset/custPageSize)+1;pag.style.cssText='display:flex !important;justify-content:center;gap:0.5rem;margin-top:1rem;';let ph=cp>1?'<button class="chip" onclick="custGoPage('+(cp-2)+')"><i class="bi bi-chevron-left"></i></button>':'';ph+='<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า '+cp+' / '+tp+'</span>';if(cp<tp)ph+='<button class="chip" onclick="custGoPage('+cp+')"><i class="bi bi-chevron-right"></i></button>';pag.innerHTML=ph;}else pag.style.cssText='display:none !important;';}
}

// ---- Slip matching helpers ----
// Find best slip for a single item. Returns slip or null.
// tolerancePct: e.g. 0.05 = 5% tolerance on amount.
function _findBestSlip(slips, usedSet, iAmt, iDate, tolerancePct){
    tolerancePct = tolerancePct || 0;
    let bestIdx=-1, bestScore=Infinity;
    slips.forEach(function(slip, si){
        if(usedSet.has(si)) return;
        const sAmt = parseFloat(slip.amount || 0);
        if(iAmt > 0){
            const diff = Math.abs(sAmt - iAmt);
            // Allow up to max(1 baht, tolerancePct * amount)
            const maxDiff = Math.max(1, iAmt * tolerancePct);
            if(diff > maxDiff) return;
        } else if(sAmt > 0) return;
        const sDate = slip.transfer_date ? new Date(slip.transfer_date) : null;
        let dayDiff = 9999;
        if(iDate && sDate && !isNaN(iDate) && !isNaN(sDate)){
            dayDiff = Math.abs((iDate - sDate) / (1000 * 86400));
        }
        if(dayDiff < bestScore){ bestScore = dayDiff; bestIdx = si; }
    });
    if(bestIdx >= 0 && bestScore <= 180) return bestIdx;
    return -1;
}

// Match slips to a list of items. Returns Map: item index → slip object.
// Each slip is used at most once. Tries strict tolerance first, then widens.
function matchSlipsToItems(slips, items, getAmt, getDate){
    const used = new Set();
    const result = new Map();
    // Pass 1: tight match — within 1 baht, within 90 days
    items.forEach(function(item, idx){
        const iAmt = parseFloat(getAmt(item) || 0);
        const iDate = getDate(item) ? new Date(getDate(item)) : null;
        const si = _findBestSlip(slips, used, iAmt, iDate, 0);
        if(si >= 0){
            // Check 90 day window for pass 1
            const sDate = slips[si].transfer_date ? new Date(slips[si].transfer_date) : null;
            let dayDiff = 9999;
            if(iDate && sDate && !isNaN(iDate) && !isNaN(sDate)){
                dayDiff = Math.abs((iDate - sDate) / (1000 * 86400));
            }
            if(dayDiff <= 90){ used.add(si); result.set(idx, slips[si]); }
        }
    });
    // Pass 2: wider tolerance (5%) for unmatched items — up to 180 days
    items.forEach(function(item, idx){
        if(result.has(idx)) return;
        const iAmt = parseFloat(getAmt(item) || 0);
        const iDate = getDate(item) ? new Date(getDate(item)) : null;
        const si = _findBestSlip(slips, used, iAmt, iDate, 0.05);
        if(si >= 0){ used.add(si); result.set(idx, slips[si]); }
    });
    return result;
}

function fmtThDate(raw){
    if(!raw) return '-';
    const d = new Date(raw);
    if(isNaN(d)) return String(raw).slice(0,10) || '-';
    return d.toLocaleDateString('th-TH', {day:'2-digit', month:'short', year:'2-digit', timeZone:'Asia/Bangkok'});
}
function slipThumb(slip){
    if(!slip || !slip.image_full_url) return '';
    return '<img src="'+escapeHtml(slip.image_full_url)+'" onclick="openSlipPreview(\''+escapeHtml(slip.image_full_url)+'\')" title="\u0e14\u0e39\u0e2a\u0e25\u0e34\u0e1b" style="width:32px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid #d1fae5;vertical-align:middle;margin-left:4px;" onerror="this.style.display=\'none\'">';
}
// ---- End helpers ----

// ── Customer detail tab render helpers (extracted for lazy-load reuse) ──

function _custRenderInvoicesHtml(invoicesAll, invData, slipByInvoiceId, stMap, PAYMENT_METHODS, _fmtDt){
    let h = '';
    if(!invoicesAll.length) return '<p style="color:var(--gray-400);text-align:center;padding:2rem;">ไม่พบใบแจ้งหนี้</p>';
    h += '<p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:0.5rem;">ทั้งหมด ' + Number(invData.total||invoicesAll.length).toLocaleString() + ' รายการ</p>';
    h += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
    h += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
    h += '<th style="padding:0.5rem;">เลขที่</th>';
    h += '<th style="padding:0.5rem;">ออเดอร์</th>';
    h += '<th style="padding:0.5rem;">วันที่</th>';
    h += '<th style="padding:0.5rem;">ครบกำหนด</th>';
    h += '<th style="padding:0.5rem;">สถานะ</th>';
    h += '<th style="padding:0.5rem;text-align:right;">ยอดรวม</th>';
    h += '<th style="padding:0.5rem;text-align:right;">ค้างชำระ</th>';
    h += '<th style="padding:0.5rem;">วิธีชำระ</th>';
    h += '<th style="padding:0.5rem;">สลิป</th>';
    h += '</tr></thead><tbody>';
    invoicesAll.forEach(function(inv){
        const rawDate  = inv.invoice_date || inv.due_date || inv.processed_at || inv.updated_at || inv.synced_at || null;
        const dt       = _fmtDt(rawDate);
        const dueDate  = inv.due_date || inv.invoice_date_due || null;
        const dueDt    = _fmtDt(dueDate);
        const stateVal = String(inv.invoice_state || inv.state || '').toLowerCase();
        const isPaid   = stateVal === 'paid' || inv.is_paid
            || String(inv.latest_event || '') === 'invoice.paid'
            || String(inv.payment_state || '').toLowerCase() === 'paid'
            || (parseFloat(inv.amount_residual) === 0 && parseFloat(inv.amount_total || 0) > 0);
        const dueDateObj = dueDate ? new Date(dueDate) : null;
        const isOverdue = !isPaid && !!(dueDateObj && !isNaN(dueDateObj) && stateVal !== 'cancel' && dueDateObj < new Date());
        const effectiveState = isPaid ? 'paid' : (isOverdue ? 'overdue' : stateVal);
        const sb       = stMap[effectiveState] || '<span style="background:var(--gray-100);padding:2px 6px;border-radius:50px;font-size:0.75rem;">'+escapeHtml(inv.invoice_state||inv.state||'-')+'</span>';
        const amt      = inv.amount_total != null ? '฿'+Number(inv.amount_total).toLocaleString() : '-';
        const residualRaw = inv.amount_residual != null && inv.amount_residual !== '' ? parseFloat(inv.amount_residual) : null;
        const effectiveResidual = isPaid ? 0 : (residualRaw != null ? residualRaw : parseFloat(inv.amount_total || 0));
        const res      = isPaid ? '<span style="color:var(--gray-400);">฿0</span>' : '฿'+Number(effectiveResidual).toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2});
        const resColor = (!isPaid && effectiveResidual > 0) ? '#dc2626' : 'inherit';
        const invNum   = inv.invoice_number || inv.name || '-';
        const dueDtColor = isOverdue ? '#dc2626' : 'var(--gray-500)';
        const payMethod  = inv.payment_method || inv.payment_type || null;
        const payMethodLabel = payMethod ? (PAYMENT_METHODS[payMethod] || payMethod) : '-';
        const slip     = slipByInvoiceId ? slipByInvoiceId.get(String(inv.invoice_id || inv.id || '')) : null;
        let slipCell   = '-';
        if(slip){
            const payDt  = fmtThDate(slip.transfer_date || slip.uploaded_at);
            const slipAmt= slip.amount != null ? '฿'+Number(slip.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '';
            slipCell = '<span style="font-size:0.75rem;color:#16a34a;font-weight:500;">' + slipAmt + '<br>' + payDt + '</span>' + slipThumb(slip);
        }
        const rowBg = (isPaid || slip) ? '#f0fdf4' : (isOverdue ? '#fef2f2' : 'transparent');
        h += '<tr style="border-bottom:1px solid var(--gray-100);background:'+rowBg+';">';
        const invOrderName = inv.order_name || null;
        const invOrderLink = invOrderName ? '<br><span style="font-size:0.7rem;color:#1d4ed8;"><i class="bi bi-bag"></i> '+escapeHtml(invOrderName)+'</span>' : '';
        const _invId = inv.id || inv.invoice_id || '';
        h += '<td style="padding:0.5rem;font-weight:500;"><a class="ref-link" href="javascript:void(0)" onclick="openInvoiceDetail(\'' + escapeHtml(String(_invId)) + '\',\'' + escapeHtml(invNum) + '\')">' + escapeHtml(invNum) + '</a>' + invOrderLink + '</td>';
        h += '<td style="padding:0.5rem;color:var(--gray-500);font-size:0.8rem;">' + dt + '</td>';
        h += '<td style="padding:0.5rem;color:'+dueDtColor+';font-size:0.8rem;">' + dueDt + (isOverdue?' <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;font-size:0.7rem;"></i>':'') + '</td>';
        h += '<td style="padding:0.5rem;">' + sb + '</td>';
        h += '<td style="padding:0.5rem;text-align:right;font-weight:600;">' + amt + '</td>';
        h += '<td style="padding:0.5rem;text-align:right;color:'+resColor+';">' + res + '</td>';
        h += '<td style="padding:0.5rem;font-size:0.78rem;">' + escapeHtml(payMethodLabel) + '</td>';
        h += '<td style="padding:0.5rem;">' + slipCell + '</td>';
        h += '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
}

function _custRenderBdosHtml(bdos, bdoData, slipByBdoId, invoicesAll, pidParam, partnerId, slips, _fmtDt){
    if(!bdos || !bdos.length) return '<p style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-file-earmark-check" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบ BDO</p>';
    let h = '<p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:0.75rem;">ทั้งหมด ' + Number(bdoData.total||bdos.length).toLocaleString() + ' รายการ</p>';
    const ODOO_BASE = 'https://cny.cnyrxapp.com';
    const BDO_PAY_STATUS = {pending:{bg:'#fef3c7',clr:'#d97706',lbl:'รอชำระ',icon:'clock'},partial:{bg:'#ffedd5',clr:'#ea580c',lbl:'ชำระบางส่วน',icon:'hourglass-split'},slip_uploaded:{bg:'#dbeafe',clr:'#1d4ed8',lbl:'อัพสลิปแล้ว',icon:'cloud-upload'},matched:{bg:'#dcfce7',clr:'#16a34a',lbl:'จับคู่แล้ว',icon:'check-circle'},paid:{bg:'#dcfce7',clr:'#16a34a',lbl:'ชำระแล้ว',icon:'check-circle-fill'}};
    const PAYMENT_LABELS = {promptpay:'พร้อมเพย์',bank_transfer:'โอนเงิน'};
    h += '<div style="display:flex;flex-direction:column;gap:0.75rem;">';
    bdos.slice().sort(function(a,b){
        const aState = normalizeBdoPaymentStatus(a);
        const bState = normalizeBdoPaymentStatus(b);
        const rank = {pending:0, partial:1, slip_uploaded:2, matched:3, paid:4};
        return (rank[aState.key] ?? 99) - (rank[bState.key] ?? 99);
    }).forEach(function(bdo){
        const _bdoId = bdo.bdo_id || bdo.id || '';
        const bdoName = bdo.bdo_name || ('BDO-'+_bdoId);
        const orderName = bdo.order_name || '-';
        const orderId = bdo.order_id || '';
        const dt = _fmtDt(bdo.bdo_date || bdo.updated_at || bdo.synced_at || bdo.processed_at);
        const amtNum = bdo.amount_net_to_pay != null ? bdo.amount_net_to_pay : bdo.amount_total;
        const amt = amtNum != null ? '฿'+Number(amtNum).toLocaleString() : '-';
        const paymentState = normalizeBdoPaymentStatus(bdo);
        const payStatus = paymentState.key;
        const ps = BDO_PAY_STATUS[payStatus] || BDO_PAY_STATUS.pending;
        const payMethod = PAYMENT_LABELS[bdo.payment_method] || bdo.payment_method || '';
        const deliveryTypeLabel = bdo.delivery_type === 'company' ? 'สายส่ง (จ่ายทีหลัง)' : (bdo.delivery_type === 'private' ? 'ขนส่งเอกชน (จ่ายก่อนส่ง)' : '');
        const customerLabel = bdo.customer_name || bdo.customer_ref || '';
        const statementUrl = 'api/odoo-dashboard-api.php?action=odoo_bdo_statement_pdf&bdo_id=' + encodeURIComponent(String(_bdoId));
        const isPending = payStatus === 'pending' || payStatus === 'partial';
        const isMatched = payStatus === 'matched' || payStatus === 'slip_uploaded';
        const isPaid = payStatus === 'paid';
        const linkedSlip = slipByBdoId ? slipByBdoId.get(String(_bdoId)) : null;
        const linkedInv = invoicesAll && invoicesAll.length ? invoicesAll.find(function(inv){ return inv.order_name && inv.order_name === bdo.order_name; }) : null;
        const cardBg = isPending ? '#fffbeb' : (isMatched ? '#eff6ff' : (isPaid ? '#f0fdf4' : 'white'));
        const cardBorder = isPending ? '#fde68a' : (isMatched ? '#bfdbfe' : (isPaid ? '#bbf7d0' : 'var(--gray-200)'));
        h += '<div style="background:'+cardBg+';border:1.5px solid '+cardBorder+';border-radius:12px;padding:0.85rem 1rem;">';
        h += '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.4rem;">';
        h += '<div style="flex:1;min-width:0;">';
        h += '<div style="font-size:0.62rem;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">เลข BDO</div>';
        h += '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        h += '<a class="ref-link" href="javascript:void(0)" onclick="openBdoDetail(\'' + escapeHtml(String(_bdoId)) + '\',\'' + escapeHtml(bdoName) + '\', decodeURIComponent(\'' + encodeURIComponent(JSON.stringify(bdo)) + '\'))" style="font-weight:600;font-size:0.9rem;">' + escapeHtml(bdoName) + '</a>';
        h += '<span style="background:'+ps.bg+';color:'+ps.clr+';padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;"><i class="bi bi-'+ps.icon+'" style="font-size:0.68rem;"></i> '+escapeHtml(paymentState.label || ps.lbl)+'</span>';
        if(deliveryTypeLabel){ h += '<span style="background:#f0f9ff;color:#0369a1;padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;border:1px solid #bae6fd;"><i class="bi bi-truck" style="font-size:0.68rem;"></i> '+escapeHtml(deliveryTypeLabel)+'</span>'; }
        h += '</div>';
        h += '<div style="font-size:0.7rem;color:var(--gray-400);margin-top:3px;">Odoo ID: #'+escapeHtml(String(_bdoId))+'</div>';
        if(customerLabel){ h += '<div style="font-size:0.74rem;color:var(--gray-500);margin-top:2px;">ลูกค้า: '+escapeHtml(customerLabel)+'</div>'; }
        h += '</div>';
        h += '<a href="'+ODOO_BASE+'/web#id='+_bdoId+'&model=cny.bill.invoice.before.delivery&view_type=form" target="_blank" title="เปิดใน Odoo" style="color:var(--gray-400);font-size:0.85rem;text-decoration:none;flex-shrink:0;margin-left:6px;"><i class="bi bi-box-arrow-up-right"></i></a>';
        h += '</div>';
        h += '<div style="display:flex;gap:8px;flex-wrap:wrap;font-size:0.8rem;color:var(--gray-500);margin-bottom:0.4rem;">';
        if(orderId){ h += '<a href="'+ODOO_BASE+'/web#id='+orderId+'&model=sale.order&view_type=form" target="_blank" style="color:#1d4ed8;text-decoration:none;font-weight:500;">'+escapeHtml(orderName)+'</a>'; }
        else { h += '<span>'+escapeHtml(orderName)+'</span>'; }
        h += '<span><i class="bi bi-calendar3" style="font-size:0.7rem;"></i> '+dt+'</span>';
        if(payMethod) h += '<span>ชำระ: '+escapeHtml(payMethod)+'</span>';
        if(paymentState.residual > 0) h += '<span>คงเหลือ: ฿'+Number(paymentState.residual).toLocaleString()+'</span>';
        if(bdo.statement_pdf_path){ h += '<a href="'+statementUrl+'" target="_blank" rel="noopener noreferrer" style="color:#0369a1;text-decoration:none;font-weight:500;"><i class="bi bi-file-earmark-pdf"></i> Statement PDF</a>'; }
        h += '</div>';
        h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.3rem;">';
        h += '<span style="font-weight:700;font-size:1rem;color:var(--gray-800);">'+amt+'</span>';
        h += '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
        if(isPending){
            h += '<button id="btnNotifyBdo'+escapeHtml(String(_bdoId))+'" onclick="sendBdoPaymentNotify(\'' + escapeHtml(String(_bdoId)) + '\',\'' + escapeHtml(String(pidParam||partnerId||'')) + '\',decodeURIComponent(\'' + encodeURIComponent(bdoName) + '\'))" style="background:#06C755;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:0.78rem;cursor:pointer;font-weight:500;font-family:inherit;" title="ส่งแจ้งยอดชำระให้ลูกค้าทาง LINE"><i class="bi bi-send"></i> ส่งแจ้งยอด</button>';
            h += '<button onclick="openBdoSlipAttach(JSON.parse(decodeURIComponent(\'' + encodeURIComponent(JSON.stringify(bdo)) + '\')),JSON.parse(decodeURIComponent(\'' + encodeURIComponent(JSON.stringify((slips||[]).filter(function(s){return s.status==='pending';}))) + '\')))" style="background:#059669;color:#fff;border:none;border-radius:6px;padding:4px 12px;font-size:0.78rem;cursor:pointer;font-weight:500;font-family:inherit;"><i class="bi bi-paperclip"></i> แนบสลิป</button>';
        }
        if(isPaid){ h += '<span style="background:#ecfdf5;color:#16a34a;border:1px solid #bbf7d0;border-radius:6px;padding:4px 10px;font-size:0.75rem;font-weight:600;"><i class="bi bi-lock-fill"></i> ปิดแนบสลิปแล้ว</span>'; }
        if(isMatched){
            const slipUpId = linkedSlip ? (linkedSlip.id||'') : '';
            const slipInboxId = linkedSlip ? (linkedSlip.slip_inbox_id || linkedSlip.odoo_slip_id || 0) : 0;
            h += '<button onclick="unmatchBdoSlip(\'' + escapeHtml(String(slipUpId)) + '\',\'' + escapeHtml(String(slipInboxId)) + '\')" style="background:var(--gray-200);color:var(--gray-700);border:none;border-radius:6px;padding:4px 10px;font-size:0.75rem;cursor:pointer;font-family:inherit;" title="ยกเลิกการจับคู่"><i class="bi bi-x-circle"></i> ยกเลิก</button>';
        }
        h += '</div></div>';
        if(linkedSlip && linkedSlip.image_full_url){
            h += '<div style="margin-top:0.5rem;display:flex;align-items:center;gap:8px;padding:6px 8px;background:rgba(16,185,129,0.06);border-radius:8px;">';
            h += '<img src="'+escapeHtml(linkedSlip.image_full_url)+'" onclick="openSlipPreview(\'' + escapeHtml(linkedSlip.image_full_url) + '\')" style="width:36px;height:44px;object-fit:cover;border-radius:5px;cursor:pointer;border:1px solid #bbf7d0;" onerror="this.style.display=\'none\'">';
            h += '<div style="flex:1;font-size:0.78rem;"><span style="color:#16a34a;font-weight:500;">✔ สลิปแนบแล้ว</span>';
            if(linkedSlip.amount) h += '<span style="color:var(--gray-500);margin-left:6px;">฿'+Number(linkedSlip.amount).toLocaleString()+'</span>';
            if(linkedSlip.transfer_date) h += '<span style="color:var(--gray-400);margin-left:6px;">'+fmtThDate(linkedSlip.transfer_date)+'</span>';
            h += '</div></div>';
        }
        if(linkedInv){
            const invIsPaid = String(linkedInv.invoice_state||'').toLowerCase()==='paid' || linkedInv.is_paid;
            h += '<div style="margin-top:0.35rem;font-size:0.75rem;color:#7c3aed;"><i class="bi bi-file-text"></i> '+escapeHtml(linkedInv.invoice_number||'-')+(invIsPaid?' ✔':'')+'</div>';
        }
        h += '</div>';
    });
    h += '</div>';
    return h;
}

function _custRenderActivityHtml(activityItems, _fmtDtTime){
    if(!activityItems || !activityItems.length) return '<div style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-journal-text" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ยังไม่มีประวัติ</div>';
    let h = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.84rem;">';
    h += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
    h += '<th style="padding:0.5rem;">ประเภท</th>';
    h += '<th style="padding:0.5rem;">รายการ</th>';
    h += '<th style="padding:0.5rem;">รายละเอียด</th>';
    h += '<th style="padding:0.5rem;">ผู้ดำเนินการ</th>';
    h += '<th style="padding:0.5rem;">วันเวลา</th>';
    h += '</tr></thead><tbody>';
    activityItems.forEach(function(it){
        const kind = it.log_kind;
        let kindBadge = '';
        if(kind==='override') kindBadge='<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:50px;font-size:0.73rem;"><i class="bi bi-pencil-square"></i> แก้สถานะ</span>';
        else if(kind==='note') kindBadge='<span style="background:#dbeafe;color:#1d4ed8;padding:2px 6px;border-radius:50px;font-size:0.73rem;"><i class="bi bi-chat-left-text"></i> โน้ต</span>';
        else kindBadge='<span style="background:var(--gray-100);color:var(--gray-600);padding:2px 6px;border-radius:50px;font-size:0.73rem;">'+escapeHtml(kind)+'</span>';
        let detail = escapeHtml(it.description||'-');
        if(kind==='override' && it.old_status){
            detail = escapeHtml(it.old_status)+' → <strong>'+escapeHtml(it.new_status)+'</strong><br><span style="color:var(--gray-500);">'+escapeHtml(it.description)+'</span>';
        }
        h += '<tr style="border-bottom:1px solid var(--gray-100);" onmouseover="this.style.background=\'var(--gray-50)\'" onmouseout="this.style.background=\'transparent\'">';
        h += '<td style="padding:0.4rem 0.5rem;">'+kindBadge+'</td>';
        h += '<td style="padding:0.4rem 0.5rem;font-weight:500;">'+escapeHtml(it.entity_type)+': '+escapeHtml(it.entity_ref)+'</td>';
        h += '<td style="padding:0.4rem 0.5rem;">'+detail+'</td>';
        h += '<td style="padding:0.4rem 0.5rem;font-size:0.8rem;">'+escapeHtml(it.admin_name||'-')+'</td>';
        h += '<td style="padding:0.4rem 0.5rem;font-size:0.8rem;color:var(--gray-500);white-space:nowrap;">'+_fmtDtTime(it.created_at)+'</td>';
        h += '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
}

async function showCustomerDetail(ref, partnerId, custName){
    const modal   = document.getElementById('customerInvoiceModal');
    const content = document.getElementById('customerInvoiceContent');
    const titleEl = document.getElementById('customerInvoiceTitle');
    if(!modal || !content) return;
    modal.classList.add('active');
    if(titleEl) titleEl.textContent = (custName||'') + (partnerId && partnerId!=='-' ? ' (ID: '+partnerId+')' : '');
    content.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>\u0e01\u0e33\u0e25\u0e31\u0e07\u0e42\u0e2b\u0e25\u0e14...</div></div>';

    const pidParam = partnerId && partnerId!=='-' ? partnerId : '';

    // ── Cache keys ──
    const _custCacheKey      = 'cust_full:'     + (pidParam||'_') + ':' + (ref||'_');
    const _profileCacheKey   = 'cust_profile:'  + (pidParam||'_') + ':' + (ref||'_');
    const _ordersCacheKey    = 'cust_orders:'   + (pidParam||'_') + ':' + (ref||'_');
    const _invoicesCacheKey  = 'cust_invoices:' + (pidParam||'_') + ':' + (ref||'_');
    const _bdosCacheKey      = 'cust_bdos:'     + (pidParam||'_') + ':' + (ref||'_');
    const _slipsCacheKey     = 'cust_slips:'    + (pidParam||'_');
    const _activityCacheKey  = 'cust_activity:' + (pidParam||'_');
    const _TTL = 300000; // 5 minutes

    // ── Warm path: if full batch cache exists, unpack all tabs immediately ──
    let batchData = _cacheGet(_custCacheKey);
    if (batchData) {
        // Populate individual tab caches from batch so lazy loaders find them
        if (batchData.orders       && !_cacheGet(_ordersCacheKey))   _cacheSet(_ordersCacheKey,   batchData.orders,          _TTL);
        if (batchData.invoices     && !_cacheGet(_invoicesCacheKey)) _cacheSet(_invoicesCacheKey, batchData.invoices,        _TTL);
        if (batchData.bdos         && !_cacheGet(_bdosCacheKey))     _cacheSet(_bdosCacheKey,     batchData.bdos,            _TTL);
        if (batchData.slips        && !_cacheGet(_slipsCacheKey))    _cacheSet(_slipsCacheKey,    batchData.slips,           _TTL);
        if (batchData.activity_log && !_cacheGet(_activityCacheKey)) _cacheSet(_activityCacheKey, batchData.activity_log,   _TTL);
        if (batchData.customer_detail && !_cacheGet(_profileCacheKey)) {
            _cacheSet(_profileCacheKey, batchData.customer_detail, _TTL);
        }
    }

    // ── Step 1: Load profile/credit fast (DB-only via customer_profile_fast) ──
    let profileRes = _cacheGet(_profileCacheKey);
    if (!profileRes) {
        const fastRes = await whApiCall({
            action: 'customer_profile_fast',
            partner_id: pidParam,
            customer_ref: ref
        });
        if (fastRes && fastRes.success && fastRes.data) {
            profileRes = fastRes.data;
            _cacheSet(_profileCacheKey, profileRes, _TTL);
        } else {
            // Fallback: use customer_detail from heavy endpoint
            const detailFallback = await whApiCall({action:'customer_detail', partner_id:pidParam, customer_ref:ref});
            profileRes = (detailFallback && detailFallback.success) ? detailFallback.data : {};
            if (profileRes) _cacheSet(_profileCacheKey, profileRes, _TTL);
        }
    }

    const detailData  = profileRes || {};
    const profileData = detailData.profile || {};
    const creditData  = detailData.credit  || {};
    const linkData    = detailData.link    || {};
    const pointsData  = detailData.points  || {};

    // ── Determine totals from credit data (orders/invoices loaded lazily) ──
    const creditSpend = parseFloat(creditData.credit_used || creditData.total_spend || 0);
    let totalSpend = creditSpend > 0 ? creditSpend : null;
    let totalDue   = creditData.total_due != null ? creditData.total_due : null;

    // If warm batch data exists, compute accurate totals from it
    if (batchData) {
        const ordersArr = (batchData.orders && batchData.orders.orders) || [];
        if (ordersArr.length) {
            let s = 0;
            ordersArr.forEach(function(o){ s += parseFloat(o.amount_total || 0); });
            if (s > 0) totalSpend = s;
        }
        const invoicesAll = (batchData.invoices && batchData.invoices.invoices) || [];
        if ((totalSpend === null || totalSpend === 0) && invoicesAll.length) {
            let s = 0;
            invoicesAll.forEach(function(inv){ s += parseFloat(inv.amount_total || 0); });
            if (s > 0) totalSpend = s;
        }
        if (totalDue == null && invoicesAll.length) {
            totalDue = 0;
            invoicesAll.forEach(function(inv){
                const st = String(inv.invoice_state || inv.state || '').toLowerCase();
                if (st !== 'paid' && st !== 'cancel' && st !== 'cancelled') {
                    totalDue += parseFloat(inv.amount_residual || inv.amount_total || 0);
                }
            });
        }
    }

    // Re-apply credit override for total spend
    if (creditSpend > 0 && (totalSpend === null || creditSpend > totalSpend)) totalSpend = creditSpend;

    // ── Shared rendering helpers ──
    const _fmtBaht = function(v){ if(v==null||v===''||isNaN(v))return '-'; return '\u0e3f'+Number(v).toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2}); };
    const _fmtDt = function(raw){ if(!raw)return '-'; const d=new Date(raw); if(isNaN(d))return String(raw).slice(0,10)||'-'; return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit',timeZone:'Asia/Bangkok'}); };
    const _fmtDtTime = function(raw){ if(!raw)return '-'; const d=new Date(raw); if(isNaN(d))return String(raw).slice(0,16)||'-'; return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit',timeZone:'Asia/Bangkok'})+' '+d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit',timeZone:'Asia/Bangkok'}); };
    const PAYMENT_METHODS = {cash:'\u0e40\u0e07\u0e34\u0e19\u0e2a\u0e14',bank_transfer:'\u0e42\u0e2d\u0e19\u0e40\u0e07\u0e34\u0e19',promptpay:'\u0e1e\u0e23\u0e49\u0e2d\u0e21\u0e40\u0e1e\u0e22\u0e4c',cheque:'\u0e40\u0e0a\u0e47\u0e04',credit_card:'\u0e1a\u0e31\u0e15\u0e23\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15'};
    const stateColor = {sale:'#16a34a', done:'#1d4ed8', cancel:'#64748b', draft:'#854d0e', to_delivery:'#7c3aed', packed:'#0891b2', confirmed:'#0369a1'};
    const stMap = {
        posted:  '<span style="background:#dcfce7;color:#16a34a;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e22\u0e37\u0e19\u0e22\u0e31\u0e19</span>',
        paid:    '<span style="background:#dbeafe;color:#1d4ed8;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e0a\u0e33\u0e23\u0e30\u0e41\u0e25\u0e49\u0e27</span>',
        open:    '<span style="background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e04\u0e49\u0e32\u0e07\u0e0a\u0e33\u0e23\u0e30</span>',
        overdue: '<span style="background:#fee2e2;color:#dc2626;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e40\u0e01\u0e34\u0e19\u0e01\u0e33\u0e2b\u0e19\u0e14</span>',
        cancel:  '<span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e22\u0e01\u0e40\u0e25\u0e34\u0e01</span>',
        draft:   '<span style="background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:50px;font-size:0.75rem;">\u0e23\u0e48\u0e32\u0e07</span>'
    };
    const paidBadge      = '<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:50px;font-size:0.75rem;font-weight:600;">\u2714 \u0e0a\u0e33\u0e23\u0e30\u0e40\u0e07\u0e34\u0e19\u0e40\u0e23\u0e35\u0e22\u0e1a\u0e23\u0e49\u0e2d\u0e22</span>';
    const deliveredBadge = '<span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:50px;font-size:0.75rem;font-weight:600;">\u2714 \u0e2a\u0e48\u0e07\u0e41\u0e25\u0e49\u0e27 / \u0e0a\u0e33\u0e23\u0e30\u0e41\u0e25\u0e49\u0e27</span>';
    // ── Preload orders + slips data now if warm batch cache available ──
    // These are needed for the Orders tab which is active by default
    let ordResData   = batchData ? (batchData.orders  || {}) : null;
    let invResData   = batchData ? (batchData.invoices || {}) : null;
    let slipsData    = batchData ? (batchData.slips    || {}) : null;

    // If no warm batch, load orders + slips in parallel for the default tab
    if (!ordResData || !slipsData) {
        const cachedOrders = _cacheGet(_ordersCacheKey);
        const cachedSlips  = _cacheGet(_slipsCacheKey);
        if (cachedOrders && cachedSlips) {
            ordResData = cachedOrders;
            slipsData  = cachedSlips;
        } else {
            const [oR, sR] = await Promise.all([
                cachedOrders ? null : whApiCall({action:'odoo_orders', limit:100, offset:0, partner_id:pidParam, customer_ref:ref}),
                cachedSlips  ? null : whApiCall({action:'odoo_slips', partner_id:pidParam})
            ]);
            ordResData = cachedOrders || ((oR && oR.success) ? oR.data : {});
            slipsData  = cachedSlips  || ((sR && sR.success) ? sR.data : {});
            _cacheSet(_ordersCacheKey, ordResData, _TTL);
            _cacheSet(_slipsCacheKey,  slipsData,  _TTL);
        }
    } else {
        // Ensure individual caches are populated from batch
        if (!_cacheGet(_ordersCacheKey)) _cacheSet(_ordersCacheKey, ordResData, _TTL);
        if (!_cacheGet(_slipsCacheKey))  _cacheSet(_slipsCacheKey,  slipsData,  _TTL);
    }

    const ordRes  = { success: true, data: ordResData };
    const slipRes = { success: true, data: slipsData  };
    const slips = (slipRes.data && slipRes.data.slips) || [];

    // Build slip lookup maps (needed by Orders tab and lazy tabs)
    const slipByOrderId   = new Map();
    const slipByInvoiceId = new Map();
    const slipByBdoId     = new Map();
    slips.forEach(function(slip){
        if(slip.order_id   != null && !slipByOrderId.has(String(slip.order_id)))     slipByOrderId.set(String(slip.order_id),   slip);
        if(slip.invoice_id != null && !slipByInvoiceId.has(String(slip.invoice_id))) slipByInvoiceId.set(String(slip.invoice_id), slip);
        if(slip.bdo_id     != null && !slipByBdoId.has(String(slip.bdo_id)))         slipByBdoId.set(String(slip.bdo_id),   slip);
    });

    // Precompute paid-invoice maps from warm invoices data (if available)
    const paidInvByAmt   = new Map();
    const paidInvByRef   = new Map();
    const paidInvByOrder = new Map();
    let invoicesAllWarm  = [];
    if (invResData) {
        invoicesAllWarm = (invResData.invoices || []).slice().sort((a,b)=>{
            const da = new Date(a.invoice_date || a.due_date || a.processed_at || 0);
            const db = new Date(b.invoice_date || b.due_date || b.processed_at || 0);
            return db - da;
        });
        invoicesAllWarm.forEach(function(inv){
            const state  = String(inv.invoice_state || '').toLowerCase();
            const isPaid = state==='paid' || inv.is_paid
                || String(inv.latest_event||'') === 'invoice.paid'
                || String(inv.payment_state||'').toLowerCase() === 'paid'
                || (parseFloat(inv.amount_residual)===0 && parseFloat(inv.amount_total||0)>0);
            if(!isPaid) return;
            const amt = parseFloat(inv.amount_total||0);
            if(amt>0 && !paidInvByAmt.has(amt)) paidInvByAmt.set(amt, inv);
            if(inv.invoice_number) paidInvByRef.set(inv.invoice_number, inv);
            if(inv.order_name && !paidInvByOrder.has(inv.order_name)) paidInvByOrder.set(inv.order_name, inv);
        });
    }

    // ── Compute totals using orders data (now available) ──
    const ordersArr = (ordRes && ordRes.success ? (ordRes.data.orders || []) : []);
    if (ordersArr.length && (totalSpend === null || totalSpend === 0)) {
        let s = 0;
        ordersArr.forEach(function(o){ s += parseFloat(o.amount_total || 0); });
        if (s > 0) totalSpend = s;
    }
    if (creditSpend > 0 && (totalSpend === null || creditSpend > totalSpend)) totalSpend = creditSpend;

    // ---- CREDIT/PAYMENT SUMMARY CARDS ----
    const sumBox = function(lbl,val,clr){
        return '<div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:0.6rem 0.8rem;min-width:120px;flex:1;">'
            +'<div style="font-size:0.72rem;color:var(--gray-500);margin-bottom:0.15rem;">'+lbl+'</div>'
            +'<div style="font-size:1rem;font-weight:700;color:'+(clr||'var(--gray-800)')+';">'+val+'</div></div>';
    };
    let html = '<div style="display:flex;flex-wrap:wrap;gap:0.6rem;margin-bottom:1rem;">';
    html += sumBox('\u0e22\u0e2d\u0e14\u0e23\u0e27\u0e21', _fmtBaht(totalSpend), '#16a34a');
    html += sumBox('\u0e04\u0e49\u0e32\u0e07\u0e0a\u0e33\u0e23\u0e30', _fmtBaht(totalDue), totalDue>0?'#dc2626':'var(--gray-400)');
    html += sumBox('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e25\u0e34\u0e21\u0e34\u0e15', _fmtBaht(creditData.credit_limit));
    html += sumBox('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e43\u0e0a\u0e49\u0e44\u0e1b', _fmtBaht(creditData.credit_used), '#d97706');
    html += sumBox('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e04\u0e07\u0e40\u0e2b\u0e25\u0e37\u0e2d', _fmtBaht(creditData.credit_remaining), '#1d4ed8');
    html += sumBox('\u0e40\u0e01\u0e34\u0e19\u0e01\u0e33\u0e2b\u0e19\u0e14', _fmtBaht(creditData.overdue_amount), creditData.overdue_amount>0?'#dc2626':'var(--gray-400)');
    html += '</div>';

    // ---- TAB BAR ----
    // Counts for lazy tabs (Invoices, BDOs) are shown as '…' until their data loads
    const slipCount = slips.length;
    const ordCount = ordRes&&ordRes.success?Number(ordRes.data.total||0):0;
    const tabBtn = function(id,icon,label,count,isActive){
        return '<button id="tabBtn'+id+'" onclick="custSwitchTab(\''+id.toLowerCase()+'\')" style="padding:0.4rem 0.75rem;border:none;border-bottom:2px solid '+(isActive?'var(--primary)':'transparent')+';background:none;'+(isActive?'font-weight:600;':'')+'cursor:pointer;color:'+(isActive?'var(--primary)':'var(--gray-500)')+';font-size:0.85rem;white-space:nowrap;"><i class="bi bi-'+icon+'"></i> '+label+(count!=null?' ('+count+')':'')+'</button>';
    };
    html += '<div style="display:flex;gap:0;margin-bottom:1rem;border-bottom:2px solid var(--gray-200);overflow-x:auto;">';
    html += tabBtn('Orders','bag','\u0e2d\u0e2d\u0e40\u0e14\u0e2d\u0e23\u0e4c',ordCount,true);
    html += tabBtn('Invoices','file-text','\u0e43\u0e1a\u0e41\u0e08\u0e49\u0e07\u0e2b\u0e19\u0e35\u0e49',null,false);
    html += tabBtn('Bdos','file-earmark-check','BDO',null,false);
    html += tabBtn('Slips','receipt','\u0e2a\u0e25\u0e34\u0e1b',slipCount,false);
    html += tabBtn('Profile','person-vcard','\u0e42\u0e1b\u0e23\u0e44\u0e1f\u0e25\u0e4c Odoo',null,false);
    html += tabBtn('Timeline','clock-history','Timeline',null,false);
    html += tabBtn('Activity','journal-text','Activity Log',null,false);
    html += '</div>';

    // ---- ORDERS TAB ----
    html += '<div id="tabOrders">';
    if(!ordRes || !ordRes.success){
        html += '<p style="color:var(--gray-500);">' + escapeHtml((ordRes&&ordRes.error)||'Error') + '</p>';
    } else {
        const orders = (ordRes.data.orders || []).slice().sort(function(a, b){
            return new Date(b.date_order||b.last_updated_at||0) - new Date(a.date_order||a.last_updated_at||0);
        });
        if(!orders.length){
            html += '<p style="color:var(--gray-400);text-align:center;padding:2rem;">\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e2d\u0e2d\u0e40\u0e14\u0e2d\u0e23\u0e4c</p>';
        } else {
            html += '<p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:0.5rem;">\u0e17\u0e31\u0e49\u0e07\u0e2b\u0e21\u0e14 ' + Number(ordRes.data.total||0).toLocaleString() + ' \u0e23\u0e32\u0e22\u0e01\u0e32\u0e23</p>';
            html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
            html += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
            html += '<th style="padding:0.5rem;">\u0e40\u0e25\u0e02\u0e17\u0e35\u0e48\u0e2d\u0e2d\u0e40\u0e14\u0e2d\u0e23\u0e4c</th>';
            html += '<th style="padding:0.5rem;">\u0e2a\u0e16\u0e32\u0e19\u0e30</th>';
            html += '<th style="padding:0.5rem;text-align:right;">\u0e22\u0e2d\u0e14\u0e23\u0e27\u0e21</th>';
            html += '<th style="padding:0.5rem;">\u0e27\u0e31\u0e19\u0e17\u0e35\u0e48\u0e2d\u0e2d\u0e40\u0e14\u0e2d\u0e23\u0e4c</th>';
            html += '<th style="padding:0.5rem;">\u0e43\u0e1a\u0e41\u0e08\u0e49\u0e07\u0e2b\u0e19\u0e35\u0e49 / \u0e2a\u0e25\u0e34\u0e1b</th>';
            html += '</tr></thead><tbody>';
            orders.forEach(function(o, idx){
                const oAmt = parseFloat(o.amount_total || 0);
                const oName = o.order_name || '';
                // Check if a paid invoice exists: by order_name first (accurate), then by amount (fallback)
                const matchedPaidInv = (oName && paidInvByOrder.get(oName)) || (oAmt > 0 ? paidInvByAmt.get(oAmt) : null);
                // Also check order's own paid flags from backend
                const orderIsPaid = o.is_paid || o.payment_status === 'paid';
                const hasDelivered = !!matchedPaidInv || orderIsPaid;

                let stateLabel, stateBg;
                if(hasDelivered){
                    // Override: order has a paid invoice → treat as delivered
                    stateLabel = '\u0e2a\u0e48\u0e07\u0e41\u0e25\u0e49\u0e27'; // ส่งแล้ว
                    stateBg    = deliveredBadge;
                } else {
                    const sc   = stateColor[String(o.state||'').toLowerCase()] || '#64748b';
                    stateLabel = o.state_display || o.state || '-';
                    stateBg    = '<span style="background:'+sc+'22;color:'+sc+';padding:2px 8px;border-radius:50px;font-size:0.75rem;">'+escapeHtml(stateLabel)+'</span>';
                }

                const amt     = oAmt > 0 ? '฿' + Number(o.amount_total).toLocaleString() : '-';
                const orderDt = fmtThDate(o.date_order || o.last_updated_at);
                const slip    = slipByOrderId.get(String(o.order_id || o.id || '')) || null;

                let infoCell = '';
                if(matchedPaidInv){
                    infoCell += '<span style="font-size:0.75rem;color:#1d4ed8;">' + escapeHtml(matchedPaidInv.invoice_number||'-') + '</span>';
                    if(matchedPaidInv.invoice_date) infoCell += '<br><span style="font-size:0.72rem;color:var(--gray-400);">' + fmtThDate(matchedPaidInv.invoice_date) + '</span>';
                }
                if(slip){
                    const payDt  = fmtThDate(slip.transfer_date || slip.uploaded_at);
                    const slipAmt= slip.amount != null ? '฿'+Number(slip.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '';
                    if(infoCell) infoCell += '<br>';
                    infoCell += paidBadge + '<br><span style="font-size:0.75rem;color:#16a34a;">' + slipAmt + ' · ' + payDt + '</span>' + slipThumb(slip);
                }
                if(!infoCell) infoCell = '-';

                const rowBg = hasDelivered ? '#eff6ff' : (slip ? '#f0fdf4' : 'transparent');
                html += '<tr style="border-bottom:1px solid var(--gray-100);background:'+rowBg+';">';
                const _oName = escapeHtml(o.order_name||'-');
                const _oId = o.order_id || o.id || '';
                html += '<td style="padding:0.5rem;font-weight:500;"><a class="ref-link" href="javascript:void(0)" onclick="openOrderDetail(\''+escapeHtml(String(_oId))+'\',\''+_oName+'\')">' + _oName + '</a></td>';
                html += '<td style="padding:0.5rem;">' + stateBg + '</td>';
                html += '<td style="padding:0.5rem;text-align:right;font-weight:600;">' + amt + '</td>';
                html += '<td style="padding:0.5rem;color:var(--gray-500);font-size:0.8rem;">' + orderDt + '</td>';
                html += '<td style="padding:0.5rem;">' + infoCell + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
    }
    html += '</div>';

    // ---- INVOICES TAB (lazy-loaded on first click) ----
    html += '<div id="tabInvoices" style="display:none;">';
    if (invResData && invResData.invoices) {
        const _invListWarm = invoicesAllWarm;
        if(!_invListWarm.length){
            html += '<p style="color:var(--gray-400);text-align:center;padding:2rem;">ไม่พบใบแจ้งหนี้</p>';
        } else {
            html += _custRenderInvoicesHtml(_invListWarm, invResData, slipByInvoiceId, stMap, PAYMENT_METHODS, _fmtDt);
        }
    } else {
        html += '<div id="lazyInvoicesPlaceholder" style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-hourglass-split" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;"></i>คลิกเพื่อโหลด</div>';
    }
    html += '</div>';

    // ---- BDO TAB (lazy-loaded on first click) ----
    html += '<div id="tabBdos" style="display:none;">';
    if (batchData && batchData.bdos && batchData.bdos.bdos) {
        html += _custRenderBdosHtml(batchData.bdos.bdos, batchData.bdos, slipByBdoId, invoicesAllWarm, pidParam, partnerId, slips, _fmtDt);
    } else {
        html += '<div id="lazyBdosPlaceholder" style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-hourglass-split" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;"></i>คลิกเพื่อโหลด</div>';
    }
    html += '</div>';

    // ---- SLIPS TAB ----
    html += '<div id="tabSlips" style="display:none;">';
    if(!slips.length){
        html += '<p style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-receipt" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e2a\u0e25\u0e34\u0e1b</p>';
    } else {
        html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
        html += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
        html += '<th style="padding:0.5rem;">\u0e23\u0e39\u0e1b</th>';
        html += '<th style="padding:0.5rem;text-align:right;">\u0e22\u0e2d\u0e14</th>';
        html += '<th style="padding:0.5rem;">\u0e27\u0e31\u0e19\u0e17\u0e35\u0e48\u0e42\u0e2d\u0e19</th>';
        html += '<th style="padding:0.5rem;">\u0e2a\u0e16\u0e32\u0e19\u0e30</th>';
        html += '<th style="padding:0.5rem;">\u0e1a\u0e31\u0e19\u0e17\u0e36\u0e01\u0e42\u0e14\u0e22</th>';
        html += '</tr></thead><tbody>';
        slips.forEach(function(s, i){
            const bg     = i%2===0 ? 'white' : 'var(--gray-50)';
            const amt    = s.amount != null ? '\u0e3f'+Number(s.amount).toLocaleString('th-TH',{minimumFractionDigits:2}) : '-';
            const payDt  = fmtThDate(s.transfer_date);
            const thumb  = s.image_full_url ? '<img src="'+escapeHtml(s.image_full_url)+'" onclick="openSlipPreview(\''+escapeHtml(s.image_full_url)+'\')" style="width:36px;height:45px;object-fit:cover;border-radius:5px;cursor:pointer;" onerror="this.style.display=\'none\'">' : '';
            html += '<tr style="border-bottom:1px solid var(--gray-100);background:'+bg+';">';
            html += '<td style="padding:0.5rem;">' + thumb + '</td>';
            html += '<td style="padding:0.5rem;text-align:right;font-weight:600;color:#16a34a;">' + amt + '</td>';
            html += '<td style="padding:0.5rem;font-size:0.8rem;color:var(--gray-600);">' + payDt + '</td>';
            html += '<td style="padding:0.5rem;">' + slipStatusBadge(s.status) + '</td>';
            html += '<td style="padding:0.5rem;font-size:0.75rem;color:var(--gray-400);">' + escapeHtml(s.uploaded_by||'-') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }
    html += '</div>';

    // ---- PROFILE TAB ----
    html += '<div id="tabProfile" style="display:none;">';
    (function(){
        const p = profileData;
        const cr = creditData;
        const lk = linkData;
        const pts = pointsData;
        const hasLine = !!(lk && lk.line_user_id);
        const lineBadge = hasLine
            ? '<span style="background:#06c755;color:#fff;padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;"><i class="bi bi-check-lg"></i> LINE \u0e40\u0e0a\u0e37\u0e48\u0e2d\u0e21\u0e41\u0e25\u0e49\u0e27</span>'
            : '<span style="background:var(--gray-100);color:var(--gray-400);padding:2px 8px;border-radius:50px;font-size:0.72rem;">\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e40\u0e0a\u0e37\u0e48\u0e2d\u0e21 LINE</span>';

        const infoRow = function(lbl, val){
            return '<div style="background:var(--gray-50);padding:0.6rem 0.8rem;border-radius:8px;">'
                +'<div style="font-size:0.72rem;color:var(--gray-500);margin-bottom:0.1rem;">'+escapeHtml(lbl)+'</div>'
                +'<div style="font-size:0.88rem;font-weight:600;">'+escapeHtml(val||'-')+'</div></div>';
        };

        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">';
        html += infoRow('\u0e0a\u0e37\u0e48\u0e2d', p.name||p.customer_name||custName||'-');
        html += infoRow('\u0e23\u0e2b\u0e31\u0e2a\u0e25\u0e39\u0e01\u0e04\u0e49\u0e32', p.ref||p.customer_ref||ref||'-');
        html += infoRow('Partner ID', p.partner_id||partnerId||'-');
        html += infoRow('\u0e42\u0e17\u0e23\u0e28\u0e31\u0e1e\u0e17\u0e4c', p.phone||p.mobile||'-');
        html += infoRow('\u0e2d\u0e35\u0e40\u0e21\u0e25', p.email||'-');
        const addrParts = [p.street,p.street2,p.city,p.state_name||p.state,p.zip,p.country_name||p.country].filter(Boolean);
        html += infoRow('\u0e17\u0e35\u0e48\u0e2d\u0e22\u0e39\u0e48', p.delivery_address||addrParts.join(', ')||'-');
        html += infoRow('\u0e1e\u0e19\u0e31\u0e01\u0e07\u0e32\u0e19\u0e02\u0e32\u0e22', p.salesperson_name||'-');
        html += '</div>';

        html += '<div style="margin-top:1rem;font-weight:600;font-size:0.9rem;margin-bottom:0.5rem;"><i class="bi bi-credit-card"></i> \u0e02\u0e49\u0e2d\u0e21\u0e39\u0e25\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">';
        html += infoRow('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e25\u0e34\u0e21\u0e34\u0e15', _fmtBaht(cr.credit_limit));
        html += infoRow('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e43\u0e0a\u0e49\u0e44\u0e1b', _fmtBaht(cr.credit_used));
        html += infoRow('\u0e40\u0e04\u0e23\u0e14\u0e34\u0e15\u0e04\u0e07\u0e40\u0e2b\u0e25\u0e37\u0e2d', _fmtBaht(cr.credit_remaining));
        html += infoRow('\u0e04\u0e49\u0e32\u0e07\u0e0a\u0e33\u0e23\u0e30', _fmtBaht(cr.total_due));
        html += infoRow('\u0e40\u0e01\u0e34\u0e19\u0e01\u0e33\u0e2b\u0e19\u0e14', _fmtBaht(cr.overdue_amount));
        html += '</div>';

        html += '<div style="margin-top:1rem;font-weight:600;font-size:0.9rem;margin-bottom:0.5rem;"><i class="bi bi-link-45deg"></i> LINE & \u0e04\u0e30\u0e41\u0e19\u0e19</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">';
        html += infoRow('\u0e2a\u0e16\u0e32\u0e19\u0e30 LINE', hasLine?'\u0e40\u0e0a\u0e37\u0e48\u0e2d\u0e21\u0e41\u0e25\u0e49\u0e27':'\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e40\u0e0a\u0e37\u0e48\u0e2d\u0e21');
        html += infoRow('LINE User ID', lk.line_user_id||'-');
        html += infoRow('LINE Account ID', lk.line_account_id||'-');
        html += infoRow('\u0e40\u0e0a\u0e37\u0e48\u0e2d\u0e21\u0e15\u0e48\u0e2d\u0e40\u0e21\u0e37\u0e48\u0e2d', _fmtDtTime(lk.linked_at||lk.created_at));
        html += infoRow('\u0e04\u0e30\u0e41\u0e19\u0e19\u0e2a\u0e30\u0e2a\u0e21', pts.available_points!=null?Number(pts.available_points).toLocaleString():'-');
        html += infoRow('\u0e04\u0e30\u0e41\u0e19\u0e19\u0e17\u0e31\u0e49\u0e07\u0e2b\u0e21\u0e14', pts.total_points!=null?Number(pts.total_points).toLocaleString():'-');
        html += infoRow('\u0e04\u0e30\u0e41\u0e19\u0e19\u0e17\u0e35\u0e48\u0e43\u0e0a\u0e49\u0e44\u0e1b', pts.used_points!=null?Number(pts.used_points).toLocaleString():'-');
        html += '</div>';

        if(detailData.warnings && detailData.warnings.length){
            html += '<div style="margin-top:1rem;background:#fef9c3;padding:0.75rem;border-radius:8px;font-size:0.78rem;color:#92400e;">';
            html += '<strong>Warnings:</strong><br>' + detailData.warnings.map(escapeHtml).join('<br>');
            html += '</div>';
        }
    })();
    html += '</div>';

    // ---- TIMELINE TAB ----
    html += '<div id="tabTimeline" style="display:none;">';
    (function(){
        const orders = (ordRes && ordRes.success ? (ordRes.data.orders || []) : []).slice().sort(function(a,b){
            return new Date(b.date_order||b.last_updated_at||0) - new Date(a.date_order||a.last_updated_at||0);
        });
        if(!orders.length){
            html += '<div style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-clock-history" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e02\u0e49\u0e2d\u0e21\u0e39\u0e25 Timeline</div>';
        } else {
            html += '<div style="position:relative;padding-left:24px;border-left:3px solid var(--gray-200);margin-left:8px;">';
            orders.forEach(function(e,i){
                const name = e.order_name || e.name || '-';
                const state = e.state_display || e.state || '';
                const t = _fmtDtTime(e.date_order || e.last_updated_at);
                const amt = e.amount_total ? _fmtBaht(e.amount_total) : '';
                const dot = i===0 ? 'var(--primary)' : 'var(--gray-400)';
                const rawState = String(e.state||'').toLowerCase();
                const sc = stateColor[rawState] || '#64748b';
                const stBadge = '<span style="background:'+sc+'22;color:'+sc+';padding:2px 8px;border-radius:50px;font-size:0.73rem;">'+escapeHtml(state)+'</span>';
                html += '<div style="position:relative;margin-bottom:1.25rem;padding-left:16px;">'
                    +'<div style="position:absolute;left:-32px;top:2px;width:14px;height:14px;border-radius:50%;background:'+dot+';border:3px solid white;box-shadow:0 0 0 2px '+dot+';"></div>'
                    +'<div style="font-weight:600;font-size:0.88rem;">'+escapeHtml(name)+' '+stBadge+'</div>'
                    +'<div style="font-size:0.8rem;color:var(--gray-500);margin-top:2px;">'+t+(amt?' \u00b7 '+amt:'')+'</div>'
                    +'</div>';
            });
            html += '</div>';
        }
    })();
    html += '</div>';

    // ---- ACTIVITY LOG TAB (lazy-loaded on first click) ----
    html += '<div id="tabActivity" style="display:none;">';
    if (batchData && batchData.activity_log) {
        const _actItems = (batchData.activity_log && batchData.activity_log.items) || [];
        if(!_actItems.length){
            html += '<div style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-journal-text" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e21\u0e35\u0e1b\u0e23\u0e30\u0e27\u0e31\u0e15\u0e34</div>';
        } else {
            html += _custRenderActivityHtml(_actItems, _fmtDtTime);
        }
    } else {
        html += '<div id="lazyActivityPlaceholder" style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-hourglass-split" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;"></i>\u0e04\u0e25\u0e34\u0e04\u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e42\u0e2b\u0e25\u0e14</div>';
    }
    html += '</div>';

    content.innerHTML = html;

    // ── Attach lazy-load click handlers for tabs rendered with placeholders ──
    (function(){
        function _updateTabCount(btnId, count){
            const btn = document.getElementById(btnId);
            if(!btn) return;
            btn.textContent = btn.textContent.replace(/\s*\(\d+\)$/, '') + (count != null ? ' (' + count + ')' : '');
        }

        // Invoices lazy handler
        if(document.getElementById('lazyInvoicesPlaceholder')){
            const _invBtn = document.getElementById('tabBtnInvoices');
            const _invTab = document.getElementById('tabInvoices');
            if(_invBtn && _invTab){
                _invBtn.addEventListener('click', function _invLazy(){
                    if(!document.getElementById('lazyInvoicesPlaceholder')) return;
                    _invBtn.removeEventListener('click', _invLazy);
                    _invTab.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-arrow-repeat spin"></i> \u0e01\u0e33\u0e25\u0e31\u0e07\u0e42\u0e2b\u0e25\u0e14...</div>';
                    const _invCk = _cacheGet(_invoicesCacheKey);
                    Promise.resolve(_invCk ? {success:true,data:_invCk} : whApiCall({action:'odoo_invoices',limit:100,offset:0,partner_id:pidParam,customer_ref:ref})).then(function(r){
                        const d = (r && r.success) ? r.data : {};
                        if(!_invCk) _cacheSet(_invoicesCacheKey, d, _TTL);
                        const invs = (d.invoices || []).slice().sort(function(a,b){ return new Date(b.invoice_date||b.due_date||b.processed_at||0)-new Date(a.invoice_date||a.due_date||a.processed_at||0); });
                        _invTab.innerHTML = invs.length ? _custRenderInvoicesHtml(invs, d, slipByInvoiceId, stMap, PAYMENT_METHODS, _fmtDt) : '<p style="color:var(--gray-400);text-align:center;padding:2rem;">\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e43\u0e1a\u0e41\u0e08\u0e49\u0e07\u0e2b\u0e19\u0e35\u0e49</p>';
                        _updateTabCount('tabBtnInvoices', d.total || invs.length);
                    }).catch(function(){ _invTab.innerHTML = '<p style="color:#dc2626;text-align:center;padding:2rem;">\u0e42\u0e2b\u0e25\u0e14\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08</p>'; });
                });
            }
        }

        // BDOs lazy handler
        if(document.getElementById('lazyBdosPlaceholder')){
            const _bdoBtn = document.getElementById('tabBtnBdos');
            const _bdoTab = document.getElementById('tabBdos');
            if(_bdoBtn && _bdoTab){
                _bdoBtn.addEventListener('click', function _bdoLazy(){
                    if(!document.getElementById('lazyBdosPlaceholder')) return;
                    _bdoBtn.removeEventListener('click', _bdoLazy);
                    _bdoTab.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-arrow-repeat spin"></i> \u0e01\u0e33\u0e25\u0e31\u0e07\u0e42\u0e2b\u0e25\u0e14...</div>';
                    const _bdoCk = _cacheGet(_bdosCacheKey);
                    Promise.resolve(_bdoCk ? {success:true,data:_bdoCk} : whApiCall({action:'odoo_bdo_list_api',limit:100,offset:0,partner_id:pidParam,customer_ref:ref})).then(function(r){
                        const d = (r && r.success) ? r.data : {};
                        if(!_bdoCk) _cacheSet(_bdosCacheKey, d, _TTL);
                        const bdoList = d.bdos || [];
                        _bdoTab.innerHTML = bdoList.length ? _custRenderBdosHtml(bdoList, d, slipByBdoId, [], pidParam, partnerId, slips, _fmtDt) : '<p style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-file-earmark-check" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>\u0e44\u0e21\u0e48\u0e1e\u0e1a BDO</p>';
                        _updateTabCount('tabBtnBdos', d.total || bdoList.length);
                    }).catch(function(){ _bdoTab.innerHTML = '<p style="color:#dc2626;text-align:center;padding:2rem;">\u0e42\u0e2b\u0e25\u0e14\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08</p>'; });
                });
            }
        }

        // Activity lazy handler
        if(document.getElementById('lazyActivityPlaceholder')){
            const _actBtn = document.getElementById('tabBtnActivity');
            const _actTab = document.getElementById('tabActivity');
            if(_actBtn && _actTab){
                _actBtn.addEventListener('click', function _actLazy(){
                    if(!document.getElementById('lazyActivityPlaceholder')) return;
                    _actBtn.removeEventListener('click', _actLazy);
                    _actTab.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-arrow-repeat spin"></i> \u0e01\u0e33\u0e25\u0e31\u0e07\u0e42\u0e2b\u0e25\u0e14...</div>';
                    const _actCk = _cacheGet(_activityCacheKey);
                    Promise.resolve(_actCk ? {success:true,data:_actCk} : whApiCall({action:'activity_log_list',partner_id:pidParam,limit:100})).then(function(r){
                        const d = (r && r.success) ? r.data : {};
                        if(!_actCk) _cacheSet(_activityCacheKey, d, _TTL);
                        const items = d.items || [];
                        _actTab.innerHTML = items.length ? _custRenderActivityHtml(items, _fmtDtTime) : '<div style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-journal-text" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e21\u0e35\u0e1b\u0e23\u0e30\u0e27\u0e31\u0e15\u0e34</div>';
                    }).catch(function(){ _actTab.innerHTML = '<p style="color:#dc2626;text-align:center;padding:2rem;">\u0e42\u0e2b\u0e25\u0e14\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08</p>'; });
                });
            }
        }
    })();
}
function custSwitchTab(tab){
    ['tabOrders','tabInvoices','tabBdos','tabSlips','tabProfile','tabTimeline','tabActivity'].forEach(id=>{
        const el=document.getElementById(id);
        if(el)el.style.display=(id==='tab'+tab.charAt(0).toUpperCase()+tab.slice(1))?'':'none';
    });
    [['tabBtnOrders','orders'],['tabBtnInvoices','invoices'],['tabBtnBdos','bdos'],['tabBtnSlips','slips'],['tabBtnProfile','profile'],['tabBtnTimeline','timeline'],['tabBtnActivity','activity']].forEach(([btnId,t])=>{
        const b=document.getElementById(btnId);
        if(!b)return;
        const active=tab===t;
        b.style.borderBottomColor=active?'var(--primary)':'transparent';
        b.style.color=active?'var(--primary)':'var(--gray-500)';
        b.style.fontWeight=active?'600':'400';
    });
}
function showCustomerInvoices(ref,partnerId,custName){showCustomerDetail(ref,partnerId,custName);}

// ===== NOTIFICATIONS =====
let notifCurrentOffset=0;const notifPageSize=30;
function resetNotifFilters(){['notifFilterStatus','notifFilterEvent','notifFilterDateFrom','notifFilterDateTo'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});notifCurrentOffset=0;loadNotifications();}
function notifGoPage(p){notifCurrentOffset=p*notifPageSize;loadNotifications();}

async function loadNotificationStats(){
    const c=document.getElementById('notifStats');if(!c)return;
    const res=await whApiCall({action:'notification_log',limit:1,offset:0});
    if(!res||!res.success){c.innerHTML='';return;}
    const s=res.data.stats||{};
    const box=(lbl,val,bg,clr)=>'<div class="info-box" style="background:'+(bg||'white')+';border:1px solid var(--gray-200);"><div class="info-label">'+lbl+'</div><div class="info-value" style="font-size:1.3rem;color:'+(clr||'inherit')+';">'+val+'</div></div>';
    c.innerHTML='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;margin-bottom:1rem;">'
        +box('ทั้งหมด',Number(s.total||0).toLocaleString())
        +box('ส่งสำเร็จ',Number(s.sent||0).toLocaleString(),'#dcfce7','#16a34a')
        +box('ล้มเหลว',Number(s.failed||0).toLocaleString(),s.failed>0?'#fee2e2':'white',s.failed>0?'#dc2626':'var(--gray-400)')
        +box('วันนี้',Number(s.today_total||0).toLocaleString(),'white','var(--primary)')
        +box('ผู้ใช้ไม่ซ้ำ',Number(s.unique_users||0).toLocaleString(),'white','#0284c7')
        +'</div>';
}

function populateNotifEventFilter(types){
    const sel=document.getElementById('notifFilterEvent');if(!sel)return;
    const cur=sel.value;
    let opts='<option value="">ทั้งหมด</option>';
    (types||[]).filter(Boolean).forEach(et=>{
        const lbl=EVENT_LABELS[et]||et.split('.').pop();
        opts+='<option value="'+escapeHtml(et)+'"'+(cur===et?' selected':'')+'>'+escapeHtml(lbl)+'</option>';
    });
    sel.innerHTML=opts;
}

async function loadNotifications(){
    const c=document.getElementById('notifList');
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    const params={action:'notification_log',limit:notifPageSize,offset:notifCurrentOffset,
        status:document.getElementById('notifFilterStatus')?.value||'',
        event_type:document.getElementById('notifFilterEvent')?.value||'',
        date_from:document.getElementById('notifFilterDateFrom')?.value||'',
        date_to:document.getElementById('notifFilterDateTo')?.value||''};
    const result=await whApiCall(params);
    if(!result||!result.success){c.innerHTML='<p style="padding:1rem;color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
    const logs=result.data.records||[];const total=result.data.total||0;
    populateNotifEventFilter(result.data.event_types||[]);
    const tc=document.getElementById('notifTotalCount');if(tc)tc.textContent=Number(total||0).toLocaleString()+' รายการ';
    if(!result.data.available){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);">ไม่พบตาราง odoo_notification_log</div>';return;}
    if(!logs.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-bell-slash" style="font-size:2rem;display:block;"></i>ไม่พบข้อมูล</div>';return;}
    const notifSm={sent:'<span style="background:#dcfce7;color:#16a34a;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ส่งแล้ว</span>',failed:'<span style="background:#fee2e2;color:#dc2626;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ล้มเหลว</span>',skipped:'<span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ข้าม</span>',success:'<span style="background:#dcfce7;color:#16a34a;padding:2px 6px;border-radius:50px;font-size:0.75rem;">สำเร็จ</span>',pending:'<span style="background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:50px;font-size:0.75rem;">รอ</span>'};
    const orderStageBg={'sale.order.confirmed':'#dbeafe','sale.order.done':'#dcfce7','sale.order.cancelled':'#fee2e2','delivery.validated':'#fef3c7','delivery.in_transit':'#dbeafe','delivery.done':'#dcfce7','delivery.cancelled':'#fee2e2','invoice.paid':'#dcfce7','invoice.overdue':'#fee2e2'};
    const orderStageClr={'sale.order.confirmed':'#1d4ed8','sale.order.done':'#16a34a','sale.order.cancelled':'#dc2626','delivery.validated':'#b45309','delivery.in_transit':'#1d4ed8','delivery.done':'#16a34a','delivery.cancelled':'#dc2626','invoice.paid':'#16a34a','invoice.overdue':'#dc2626'};
    let html='<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;"><thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);"><th style="padding:0.5rem;">เวลา</th><th style="padding:0.5rem;">ลูกค้า</th><th style="padding:0.5rem;">ออเดอร์</th><th style="padding:0.5rem;">สถานะออเดอร์</th><th style="padding:0.5rem;text-align:center;">แจ้งเตือน</th><th style="padding:0.5rem;">เหตุผล</th></tr></thead><tbody>';
    logs.forEach(log=>{
        const d=log.sent_at,pDate=d?new Date(d):null,time=pDate&&!isNaN(pDate)?pDate.toLocaleString('th-TH',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'}):'-';
        const status=String(log.status||'').toLowerCase(),sb=notifSm[status]||'<span style="background:var(--gray-100);padding:2px 6px;border-radius:50px;font-size:0.75rem;">'+escapeHtml(log.status||'-')+'</span>';
        const custNm=log.user_name||log.line_user_id||'-';
        const orderNm=log.order_name&&log.order_name!=='null'?log.order_name:log.delivery_id||'-';
        const et=String(log.event_type||'');
        const etLabel=EVENT_LABELS[et]||(et?et.split('.').pop():'-');
        const etBg=orderStageBg[et]||'#f1f5f9',etClr=orderStageClr[et]||'#475569';
        const etBadge='<span style="background:'+etBg+';color:'+etClr+';padding:2px 8px;border-radius:50px;font-size:0.8rem;font-weight:500;">'+escapeHtml(etLabel)+'</span>';
        const skipRaw=String(log.skip_reason||log.error_message||'');
        const skipLbl=SKIP_REASON_LABELS[skipRaw]||skipRaw;
        html+='<tr style="border-bottom:1px solid var(--gray-100);" onmouseover="this.style.background=\'var(--gray-50)\'" onmouseout="this.style.background=\'transparent\'">'
            +'<td style="padding:0.4rem 0.5rem;white-space:nowrap;color:var(--gray-500);font-size:0.8rem;">'+escapeHtml(time)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;font-weight:500;">'+escapeHtml(custNm)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;font-size:0.8rem;color:var(--gray-600);">'+escapeHtml(orderNm)+'</td>'
            +'<td style="padding:0.4rem 0.5rem;">'+etBadge+'</td>'
            +'<td style="padding:0.4rem 0.5rem;text-align:center;">'+sb+'</td>'
            +'<td style="padding:0.4rem 0.5rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.8rem;color:var(--gray-500);" title="'+escapeHtml(skipLbl)+'">'+escapeHtml(skipLbl)+'</td>'
            +'</tr>';
    });
    html+='</tbody></table></div>';c.innerHTML=html;
    const pag=document.getElementById('notifPagination');
    if(pag){if(total>notifPageSize){const tp=Math.ceil(total/notifPageSize),cp=Math.floor(notifCurrentOffset/notifPageSize)+1;pag.style.cssText='display:flex !important;justify-content:center;gap:0.5rem;margin-top:1rem;';let ph=cp>1?'<button class="chip" onclick="notifGoPage('+(cp-2)+')"><i class="bi bi-chevron-left"></i></button>':'';ph+='<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า '+cp+' / '+tp+'</span>';if(cp<tp)ph+='<button class="chip" onclick="notifGoPage('+cp+')"><i class="bi bi-chevron-right"></i></button>';pag.innerHTML=ph;}else pag.style.cssText='display:none !important;';}
}

// ===== DAILY SUMMARY =====
let dailySummaryData = [];
async function loadDailySummary() {
    const container = document.getElementById('dailySummaryList');
    if (container) container.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังดึงข้อมูลออเดอร์...</div></div>';
    
    const result = await whApiCall({ action: 'daily_summary_preview' });
    if (!result || !result.success) {
        if (container) container.innerHTML = '<p style="padding:1rem;color:var(--danger);">' + escapeHtml((result && result.error) || 'เกิดข้อผิดพลาดในการดึงข้อมูล') + '</p>';
        return;
    }
    
    dailySummaryData = result.data.records || [];
    filterDailySummaryList();
}

function filterDailySummaryList() {
    const container = document.getElementById('dailySummaryList');
    if (!container) return;
    
    const search = (document.getElementById('dailySummarySearch')?.value || '').toLowerCase();
    const status = document.getElementById('dailySummaryFilterStatus')?.value || 'all';
    
    let filtered = dailySummaryData;
    
    if (status === 'pending') {
        filtered = filtered.filter(item => !item.sent_today);
    } else if (status === 'sent') {
        filtered = filtered.filter(item => item.sent_today);
    }
    
    if (search) {
        filtered = filtered.filter(item => 
            (item.display_name && item.display_name.toLowerCase().includes(search)) ||
            (item.line_user_id && item.line_user_id.toLowerCase().includes(search))
        );
    }
    
    document.getElementById('dailySummaryCount').textContent = filtered.length;
    
    if (!filtered.length) {
        container.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่มีรายการที่ตรงกับเงื่อนไข</div>';
        return;
    }
    
    let html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;background:white;border-radius:8px;overflow:hidden;">';
    html += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
    html += '<th style="padding:0.75rem;">ลูกค้า (LINE User)</th>';
    html += '<th style="padding:0.75rem;">จำนวนออเดอร์</th>';
    html += '<th style="padding:0.75rem;">รายละเอียดออเดอร์</th>';
    html += '<th style="padding:0.75rem;text-align:center;">สถานะวันนี้</th>';
    html += '<th style="padding:0.75rem;text-align:center;">จัดการ</th>';
    html += '</tr></thead><tbody>';
    
    filtered.forEach(item => {
        const orderCount = item.orders ? item.orders.length : 0;
        let ordersHtml = '<ul style="margin:0;padding-left:1.2rem;color:var(--gray-600);">';
        if (item.orders) {
            item.orders.slice(0, 3).forEach(o => {
                const badgeColor = o.status === 'success' ? '#16a34a' : '#4b5563';
                ordersHtml += `<li><b>${escapeHtml(o.order_ref)}</b> - <span style="color:${badgeColor}">${escapeHtml(o.event_label || o.event_type)}</span></li>`;
            });
            if (item.orders.length > 3) {
                ordersHtml += `<li><i>...และอีก ${item.orders.length - 3} รายการ</i></li>`;
            }
        }
        ordersHtml += '</ul>';
        
        const statusBadge = item.sent_today 
            ? '<span style="background:#dcfce7;color:#16a34a;padding:4px 8px;border-radius:4px;font-size:0.75rem;"><i class="bi bi-check-circle"></i> ส่งแล้ว</span>'
            : '<span style="background:#fef3c7;color:#b45309;padding:4px 8px;border-radius:4px;font-size:0.75rem;"><i class="bi bi-clock"></i> รอส่ง</span>';
            
        const actionBtn = item.sent_today
            ? `<button class="btn btn-sm" style="background:var(--gray-100);color:var(--gray-500);border:none;font-size:0.75rem;padding:4px 8px;" disabled>ส่งแล้ว</button>`
            : `<button class="btn btn-sm" style="background:#16a34a;color:white;border:none;font-size:0.75rem;padding:4px 8px;cursor:pointer;" onclick="sendDailySummarySingle('${item.line_user_id}')">ส่งแจ้งเตือน</button>`;
        
        html += `<tr style="border-bottom:1px solid var(--gray-100);${item.sent_today ? 'background:#f9fafb;opacity:0.8;' : ''}">`;
        html += `<td style="padding:0.75rem;">
            <div style="font-weight:600;color:var(--gray-800);">${escapeHtml(item.display_name || 'ไม่ระบุชื่อ')}</div>
            <div style="font-size:0.7rem;color:var(--gray-500);font-family:monospace;">${escapeHtml(item.line_user_id)}</div>
        </td>`;
        html += `<td style="padding:0.75rem;text-align:center;"><b>${orderCount}</b></td>`;
        html += `<td style="padding:0.75rem;">${ordersHtml}</td>`;
        html += `<td style="padding:0.75rem;text-align:center;">${statusBadge}</td>`;
        html += `<td style="padding:0.75rem;text-align:center;">${actionBtn}</td>`;
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

async function sendDailySummaryAll() {
    const pendingList = dailySummaryData.filter(item => !item.sent_today);
    if (!pendingList.length) {
        alert('ไม่มีรายการที่ต้องส่งแจ้งเตือน (ส่งครบหมดแล้ว)');
        return;
    }
    
    if (!confirm(`ต้องการส่งแจ้งเตือนสรุปประจำวันให้ลูกค้าจำนวน ${pendingList.length} รายการ ใช่หรือไม่?`)) return;
    
    const userIds = pendingList.map(item => item.line_user_id);
    await executeSendDailySummary(userIds);
}

async function sendDailySummarySingle(lineUserId) {
    if (!confirm('ต้องการส่งแจ้งเตือนให้ลูกค้ารายนี้ ใช่หรือไม่?')) return;
    await executeSendDailySummary([lineUserId]);
}

async function executeSendDailySummary(userIds) {
    document.body.style.cursor = 'wait';
    const result = await whApiCall({ 
        action: 'send_daily_summary',
        user_ids: userIds
    }, 'POST');
    document.body.style.cursor = 'default';
    
    if (!result || !result.success) {
        alert('เกิดข้อผิดพลาด: ' + (result?.error || 'Unknown error'));
        return;
    }
    
    alert(`ส่งแจ้งเตือนสำเร็จ ${result.data.success_count} รายการ\nล้มเหลว ${result.data.failed_count} รายการ`);
    loadDailySummary(); // Reload to update status
}

// ===== AUTO-SEND SETTINGS =====
const AUTO_SEND_API = 'api/odoo-daily-summary-settings.php';

async function autoSendApiCall(data) {
    try {
        const r = await fetch(AUTO_SEND_API + '?_t=' + Date.now(), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        return await r.json();
    } catch(e) {
        return {success: false, error: e.message};
    }
}

async function loadAutoSendSettings() {
    const container = document.getElementById('autoSendSettingsContent');
    if (!container) return;
    _sectionMarkLoaded('auto-send-settings');

    container.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    
    const result = await autoSendApiCall({action: 'get_settings'});
    
    if (!result || !result.success) {
        container.innerHTML = '<p style="color:var(--gray-500);padding:1rem;">เกิดข้อผิดพลาด: ' + escapeHtml((result?.error || 'Unknown')) + '</p>';
        return;
    }
    
    const data = result.data;
    
    if (!data.available) {
        container.innerHTML = '<div style="padding:1rem;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;color:#92400e;"><i class="bi bi-exclamation-triangle"></i> ' + escapeHtml(data.message || 'ยังไม่พร้อมใช้งาน') + '</div>';
        return;
    }
    
    const settings = data.settings || {};
    const autoEnabled = settings.auto_send_enabled?.value === '1';
    const sendTime = settings.send_time?.value || '09:00';
    const lookbackDays = settings.lookback_days?.value || '1';
    const lastSent = settings.last_sent_date?.value || '-';
    const lastExec = data.last_execution;
    
    let html = '<div class="row">';
    html += '<div class="col-md-6">';
    html += '<div class="form-group">';
    html += '<label class="form-label"><i class="bi bi-toggle-on"></i> เปิดใช้งานส่งอัตโนมัติ</label>';
    html += '<div class="d-flex align-items-center gap-2">';
    html += '<label class="switch" style="position:relative;display:inline-block;width:50px;height:24px;">';
    html += '<input type="checkbox" id="autoSendEnabled" ' + (autoEnabled ? 'checked' : '') + ' onchange="saveAutoSendSettings()">';
    html += '<span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.4s;border-radius:24px;"></span>';
    html += '</label>';
    html += '<span id="autoSendStatusText" style="font-size:0.9rem;color:' + (autoEnabled ? '#16a34a' : 'var(--gray-500)') + ';font-weight:500;">' + (autoEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span>';
    html += '</div>';
    html += '</div>';
    html += '</div>';
    
    html += '<div class="col-md-3">';
    html += '<div class="form-group">';
    html += '<label class="form-label"><i class="bi bi-clock"></i> เวลาส่งอัตโนมัติ</label>';
    html += '<input type="time" class="form-control" id="autoSendTime" value="' + escapeHtml(sendTime) + '" onchange="saveAutoSendSettings()">';
    html += '</div>';
    html += '</div>';
    
    html += '<div class="col-md-3">';
    html += '<div class="form-group">';
    html += '<label class="form-label"><i class="bi bi-calendar-range"></i> ย้อนหลัง (วัน)</label>';
    html += '<input type="number" class="form-control" id="autoSendLookback" value="' + escapeHtml(lookbackDays) + '" min="1" max="7" onchange="saveAutoSendSettings()">';
    html += '</div>';
    html += '</div>';
    html += '</div>';
    
    html += '<div style="margin-top:1rem;padding:1rem;background:var(--gray-50);border-radius:8px;border:1px solid var(--gray-200);">';
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;">';
    html += '<div><div style="font-size:0.75rem;color:var(--gray-500);">ส่งครั้งล่าสุด</div><div style="font-weight:600;color:var(--gray-800);">' + escapeHtml(lastSent) + '</div></div>';
    
    if (lastExec) {
        html += '<div><div style="font-size:0.75rem;color:var(--gray-500);">ผู้รับทั้งหมด</div><div style="font-weight:600;color:var(--gray-800);">' + (lastExec.total_recipients || 0) + '</div></div>';
        html += '<div><div style="font-size:0.75rem;color:var(--gray-500);">ส่งสำเร็จ</div><div style="font-weight:600;color:#16a34a;">' + (lastExec.sent_count || 0) + '</div></div>';
        html += '<div><div style="font-size:0.75rem;color:var(--gray-500);">ล้มเหลว</div><div style="font-weight:600;color:#dc2626;">' + (lastExec.failed_count || 0) + '</div></div>';
        html += '<div><div style="font-size:0.75rem;color:var(--gray-500);">เวลาประมวลผล</div><div style="font-weight:600;color:var(--gray-800);">' + (lastExec.execution_duration_ms || 0) + ' ms</div></div>';
    }
    
    html += '</div>';
    html += '</div>';
    
    html += '<div style="margin-top:1rem;padding:0.75rem;background:#dbeafe;border:1px solid #60a5fa;border-radius:8px;font-size:0.85rem;color:#1e40af;">';
    html += '<i class="bi bi-info-circle"></i> <b>คำแนะนำ:</b> เมื่อเปิดใช้งาน ระบบจะส่งสรุปออเดอร์อัตโนมัติทุกวันตามเวลาที่กำหนด โดยจะส่งให้ลูกค้าที่มีกิจกรรมออเดอร์ในวันที่ผ่านมา (ส่งได้ 1 ครั้ง/วัน/คน)';
    html += '</div>';
    
    container.innerHTML = html;
    
    // Add CSS for toggle switch
    if (!document.getElementById('toggleSwitchCSS')) {
        const style = document.createElement('style');
        style.id = 'toggleSwitchCSS';
        style.textContent = `
            .switch input {opacity:0;width:0;height:0;}
            .switch input:checked + span {background-color:#16a34a;}
            .switch input:checked + span:before {transform:translateX(26px);}
            .switch span:before {
                position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;
                background-color:white;transition:.4s;border-radius:50%;
            }
        `;
        document.head.appendChild(style);
    }
    
    // Show history if there are logs
    if (lastExec) {
        document.getElementById('autoSendHistoryCard').style.display = 'block';
        loadAutoSendHistory();
    }
}

async function saveAutoSendSettings() {
    const enabled = document.getElementById('autoSendEnabled')?.checked ? 1 : 0;
    const time = document.getElementById('autoSendTime')?.value || '09:00';
    const lookback = parseInt(document.getElementById('autoSendLookback')?.value || '1');
    
    const result = await autoSendApiCall({
        action: 'save_settings',
        auto_send_enabled: enabled,
        send_time: time,
        lookback_days: lookback
    });
    
    if (!result || !result.success) {
        alert('เกิดข้อผิดพลาดในการบันทึก: ' + (result?.error || 'Unknown'));
        return;
    }
    
    // Update status text
    const statusText = document.getElementById('autoSendStatusText');
    if (statusText) {
        statusText.textContent = enabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
        statusText.style.color = enabled ? '#16a34a' : 'var(--gray-500)';
    }
    
    // Show success indicator briefly
    const container = document.getElementById('autoSendSettingsContent');
    const successMsg = document.createElement('div');
    successMsg.style.cssText = 'position:fixed;top:20px;right:20px;background:#16a34a;color:white;padding:12px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:9999;';
    successMsg.innerHTML = '<i class="bi bi-check-circle"></i> บันทึกสำเร็จ';
    document.body.appendChild(successMsg);
    setTimeout(() => successMsg.remove(), 2000);
}

async function loadAutoSendHistory() {
    const container = document.getElementById('autoSendHistoryContent');
    if (!container) return;
    
    container.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    
    const result = await autoSendApiCall({action: 'get_logs', limit: 10});
    
    if (!result || !result.success || !result.data.available) {
        container.innerHTML = '<p style="color:var(--gray-500);padding:1rem;">ไม่พบข้อมูล</p>';
        return;
    }
    
    const logs = result.data.logs || [];
    
    if (!logs.length) {
        container.innerHTML = '<p style="text-align:center;padding:2rem;color:var(--gray-400);">ยังไม่มีประวัติการส่งอัตโนมัติ</p>';
        return;
    }
    
    let html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
    html += '<thead><tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200);">';
    html += '<th style="padding:0.5rem;text-align:left;">วันที่</th>';
    html += '<th style="padding:0.5rem;text-align:center;">เวลาที่ตั้ง</th>';
    html += '<th style="padding:0.5rem;text-align:center;">ผู้รับ</th>';
    html += '<th style="padding:0.5rem;text-align:center;">สำเร็จ</th>';
    html += '<th style="padding:0.5rem;text-align:center;">ล้มเหลว</th>';
    html += '<th style="padding:0.5rem;text-align:center;">ข้าม</th>';
    html += '<th style="padding:0.5rem;text-align:right;">เวลา (ms)</th>';
    html += '<th style="padding:0.5rem;text-align:center;">สถานะ</th>';
    html += '</tr></thead><tbody>';
    
    logs.forEach(log => {
        const execDate = log.execution_date || '-';
        const execTime = log.execution_time ? new Date(log.execution_time).toLocaleString('th-TH', {hour: '2-digit', minute: '2-digit'}) : '-';
        const schedTime = log.scheduled_time || '-';
        const statusColors = {success: '#16a34a', partial: '#d97706', failed: '#dc2626'};
        const statusLabels = {success: 'สำเร็จ', partial: 'บางส่วน', failed: 'ล้มเหลว'};
        const statusColor = statusColors[log.status] || '#6b7280';
        const statusLabel = statusLabels[log.status] || log.status;
        
        html += '<tr style="border-bottom:1px solid var(--gray-100);">';
        html += '<td style="padding:0.5rem;">' + escapeHtml(execDate) + '<br><small style="color:var(--gray-500);">' + escapeHtml(execTime) + '</small></td>';
        html += '<td style="padding:0.5rem;text-align:center;">' + escapeHtml(schedTime) + '</td>';
        html += '<td style="padding:0.5rem;text-align:center;"><b>' + (log.total_recipients || 0) + '</b></td>';
        html += '<td style="padding:0.5rem;text-align:center;color:#16a34a;"><b>' + (log.sent_count || 0) + '</b></td>';
        html += '<td style="padding:0.5rem;text-align:center;color:#dc2626;"><b>' + (log.failed_count || 0) + '</b></td>';
        html += '<td style="padding:0.5rem;text-align:center;color:var(--gray-500);">' + (log.skipped_count || 0) + '</td>';
        html += '<td style="padding:0.5rem;text-align:right;">' + (log.execution_duration_ms || 0) + '</td>';
        html += '<td style="padding:0.5rem;text-align:center;"><span style="background:' + statusColor + '20;color:' + statusColor + ';padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;">' + escapeHtml(statusLabel) + '</span></td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

// ===== SYSTEM HEALTH =====
let healthRefreshTimer=null;
async function loadSystemHealth(){
    const c=document.getElementById('healthContent');
    if(!c)return;
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังตรวจสอบสุขภาพระบบ...</div></div>';
    try{
        const ctrl=new AbortController();
        const timer=setTimeout(()=>ctrl.abort(),10000);
        const r=await fetch('api/system-health.php?_t='+Date.now(),{signal:ctrl.signal});
        clearTimeout(timer);
        const result=await r.json();
        if(!result||!result.success){c.innerHTML='<p style="color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
        const d=result.data;
        const light=(status)=>status==='healthy'?'🟢':status==='degraded'?'🟡':'🔴';
        const barColor=(score)=>score>=90?'#16a34a':score>=70?'#d97706':'#dc2626';
        const scoreBar=(label,icon,score,status,details)=>{
            const clr=barColor(score);
            let detailHtml='';
            if(details){
                const entries=Object.entries(details).filter(([k,v])=>v!==null&&k!=='error'&&k!=='note');
                if(entries.length){
                    detailHtml='<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">';
                    const labelMap={last_webhook:'Webhook ล่าสุด',minutes_since_last:'นาทีที่แล้ว',bot_accounts:'Bot Accounts',errors_today:'Errors วันนี้',events_today:'Events วันนี้',total:'ทั้งหมด',today:'วันนี้',failed:'ล้มเหลว',dlq:'Dead Letter',retry:'Retry',fail_rate:'อัตราล้มเหลว %',notif_today:'แจ้งเตือนวันนี้',notif_sent:'ส่งสำเร็จ',notif_success_rate:'อัตราส่งสำเร็จ %',overdue_pending:'รอส่งเกินเวลา',failed_24h:'ล้มเหลว 24ชม.',last_sent:'ส่งล่าสุด',total_scheduled:'รอส่ง',daily_summary_last:'สรุปรายวันล่าสุด'};
                    entries.forEach(([k,v])=>{
                        const lbl=labelMap[k]||k;
                        detailHtml+='<span style="font-size:0.72rem;background:var(--gray-100);padding:2px 6px;border-radius:4px;color:var(--gray-600);">'+escapeHtml(lbl)+': <b>'+escapeHtml(String(v))+'</b></span>';
                    });
                    detailHtml+='</div>';
                }
                if(details.error)detailHtml+='<div style="font-size:0.8rem;color:#dc2626;margin-top:4px;">'+escapeHtml(details.error)+'</div>';
                if(details.note)detailHtml+='<div style="font-size:0.8rem;color:var(--gray-500);margin-top:4px;">'+escapeHtml(details.note)+'</div>';
            }
            return '<div style="background:white;border:1px solid var(--gray-200);border-radius:10px;padding:1rem;margin-bottom:0.75rem;">'
                +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
                +'<div style="font-weight:600;font-size:0.95rem;"><i class="bi bi-'+icon+'"></i> '+escapeHtml(label)+' '+light(status)+'</div>'
                +'<div style="font-weight:700;font-size:1.1rem;color:'+clr+';">'+score+'</div>'
                +'</div>'
                +'<div style="height:10px;background:var(--gray-200);border-radius:5px;overflow:hidden;"><div style="width:'+score+'%;height:100%;background:'+clr+';border-radius:5px;transition:width 0.4s;"></div></div>'
                +detailHtml
                +'</div>';
        };
        let html='';
        // Overall score hero
        const o=d.overall;
        const oclr=barColor(o.score);
        html+='<div style="text-align:center;padding:1.5rem 1rem;margin-bottom:1rem;background:linear-gradient(135deg,'+oclr+'10,'+oclr+'05);border:2px solid '+oclr+'30;border-radius:12px;">';
        html+='<div style="font-size:2.5rem;font-weight:700;color:'+oclr+';">'+light(o.status)+' '+o.score+'<span style="font-size:1rem;color:var(--gray-500);">/100</span></div>';
        html+='<div style="font-size:0.9rem;color:var(--gray-600);margin-top:4px;">Overall System Health</div>';
        html+='</div>';
        // Subsystems
        html+=scoreBar('LINE Messaging','chat-dots',d.line.score,d.line.status,d.line.details);
        html+=scoreBar('Odoo Webhooks','box-seam',d.odoo.score,d.odoo.status,d.odoo.details);
        html+=scoreBar('Scheduler & Broadcasts','clock-history',d.scheduler.score,d.scheduler.status,d.scheduler.details);

        // Circuit Breaker Status (from new API)
        try{
            const cbRes=await whApiCall({action:'circuit_breaker_status'});
            if(cbRes&&cbRes.success&&cbRes.data){
                const cbData=cbRes.data;
                html+='<div style="background:white;border:1px solid var(--gray-200);border-radius:10px;padding:1rem;margin-bottom:0.75rem;">';
                html+='<div style="font-weight:600;font-size:0.95rem;margin-bottom:10px;"><i class="bi bi-shield-check"></i> Circuit Breakers</div>';
                html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
                Object.entries(cbData).forEach(function([svc,cb]){
                    const st=cb.status||'closed';
                    const stColor=st==='closed'?'#16a34a':st==='open'?'#dc2626':'#d97706';
                    const stLabel=st==='closed'?'ปกติ':st==='open'?'เปิด (ตัดวงจร)':'กำลังทดสอบ';
                    const stIcon=st==='closed'?'🟢':st==='open'?'🔴':'🟡';
                    html+='<div style="background:var(--gray-50);border-radius:8px;padding:10px 12px;">';
                    html+='<div style="font-weight:600;font-size:0.85rem;">'+stIcon+' '+escapeHtml(svc)+'</div>';
                    html+='<div style="font-size:0.78rem;color:'+stColor+';font-weight:500;margin-top:2px;">'+escapeHtml(stLabel)+'</div>';
                    if(cb.consecutive_failures>0){
                        html+='<div style="font-size:0.72rem;color:var(--gray-500);margin-top:2px;">ล้มเหลวติดต่อกัน: '+cb.consecutive_failures+'/'+cb.failure_threshold+'</div>';
                    }
                    if(cb.last_error){
                        html+='<div style="font-size:0.72rem;color:#dc2626;margin-top:2px;">'+escapeHtml(cb.last_error.substring(0,80))+'</div>';
                    }
                    if(st==='open'&&cb.time_until_probe!=null){
                        html+='<div style="font-size:0.72rem;color:var(--gray-500);margin-top:2px;">ทดสอบใหม่ใน: '+cb.time_until_probe+'วิ</div>';
                        html+='<button onclick="resetCircuitBreaker(\''+escapeHtml(svc)+'\')" style="margin-top:6px;background:#dc2626;color:white;border:none;border-radius:6px;padding:3px 10px;font-size:0.72rem;cursor:pointer;">Reset</button>';
                    }
                    html+='</div>';
                });
                html+='</div></div>';
            }
        }catch(_cbErr){}

        html+='<div style="font-size:0.75rem;color:var(--gray-400);text-align:right;margin-top:0.5rem;">อัปเดต: '+new Date(d.timestamp).toLocaleString('th-TH')+'</div>';
        c.innerHTML=html;
        // Update header badge with overall score
        const hBadge=document.getElementById('connectionStatus');
        if(hBadge){
            const st=o.status;
            hBadge.className='status-badge '+(st==='healthy'?'online':'offline');
            hBadge.innerHTML='<span class="status-dot"></span><span>'+(st==='healthy'?'ระบบปกติ':st==='degraded'?'ต้องตรวจสอบ':'มีปัญหา')+' ('+o.score+')</span>';
        }
        // Auto-refresh every 60s while health section is visible
        if(healthRefreshTimer)clearInterval(healthRefreshTimer);
        healthRefreshTimer=setInterval(()=>{
            if(document.hidden)return; // Performance: skip API calls when tab not visible
            const panel=document.getElementById('section-health');
            if(panel&&panel.classList.contains('active'))loadSystemHealth();
            else{clearInterval(healthRefreshTimer);healthRefreshTimer=null;}
        },60000);
    }catch(e){c.innerHTML='<p style="color:var(--gray-500);">Error: '+escapeHtml(e.message)+'</p>';}
}

async function resetCircuitBreaker(service){
    if(!confirm('รีเซ็ต Circuit Breaker สำหรับ '+service+' ?'))return;
    const res=await whApiCall({action:'circuit_breaker_reset',service:service});
    if(res&&res.success){
        alert('รีเซ็ตสำเร็จ');
        loadSystemHealth();
    }else{
        alert('เกิดข้อผิดพลาด: '+(res&&res.error?res.error:'Unknown'));
    }
}

// ===== ORDER GROUPED VIEW =====
const ORDER_STAGES=[
    {key:'sale.order.created',label:'สร้าง',pct:5},
    {key:'order.validated',label:'ยืนยัน',pct:10},
    {key:'order.picker_assigned',label:'Picker',pct:20},
    {key:'order.picking',label:'จัดสินค้า',pct:28},
    {key:'order.picked',label:'จัดเสร็จ',pct:36},
    {key:'order.packing',label:'แพ็ค',pct:44},
    {key:'order.packed',label:'แพ็คเสร็จ',pct:52},
    {key:'order.reserved',label:'จองแล้ว',pct:58},
    {key:'order.awaiting_payment',label:'รอชำระ',pct:65},
    {key:'order.paid',label:'ชำระแล้ว',pct:75},
    {key:'order.to_delivery',label:'เตรียมส่ง',pct:82},
    {key:'order.in_delivery',label:'กำลังส่ง',pct:90},
    {key:'order.delivered',label:'ส่งแล้ว',pct:100},
    {key:'invoice.paid',label:'ใบแจ้งหนี้OK',pct:100}
];
let whViewMode='grouped'; // 'grouped' or 'list'
let grpCurrentOffset=0;const grpPageSize=30;

// Debounced search for grouped orders
const debouncedLoadOrdersGrouped = debounce(function(){
    grpCurrentOffset = 0;
    loadOrdersGrouped();
}, 400);

function setWhViewMode(mode){
    whViewMode=mode;
    const btnList=document.getElementById('whViewBtnList'),btnGrp=document.getElementById('whViewBtnGrouped');
    if(btnList){btnList.style.background=mode==='list'?'var(--primary)':'var(--gray-100)';btnList.style.color=mode==='list'?'white':'var(--gray-600)';}
    if(btnGrp){btnGrp.style.background=mode==='grouped'?'var(--primary)':'var(--gray-100)';btnGrp.style.color=mode==='grouped'?'white':'var(--gray-600)';}
    const filterCard=document.getElementById('whFilterCard');
    if(filterCard)filterCard.style.display=mode==='list'?'':'none';
    const grpBar=document.getElementById('grpSearchBar');
    if(grpBar)grpBar.style.display=mode==='grouped'?'flex':'none';
    if(mode==='grouped'){grpCurrentOffset=0;loadOrdersGrouped();}
    else{loadWebhooks();}
}

function grpGoPage(p){grpCurrentOffset=p*grpPageSize;loadOrdersGrouped();}

function renderProgressBar(progress,isCancelled){
    if(isCancelled)return '<div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;height:8px;background:#fee2e2;border-radius:4px;overflow:hidden;"><div style="width:100%;height:100%;background:#dc2626;border-radius:4px;"></div></div><span style="font-size:0.75rem;color:#dc2626;font-weight:600;white-space:nowrap;">ยกเลิก</span></div>';
    const pct=Math.max(0,Math.min(100,progress));
    const clr=pct>=100?'#16a34a':pct>=65?'#0284c7':pct>=25?'#d97706':'#6b7280';
    return '<div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;height:8px;background:var(--gray-200);border-radius:4px;overflow:hidden;"><div style="width:'+pct+'%;height:100%;background:'+clr+';border-radius:4px;transition:width 0.3s;"></div></div><span style="font-size:0.75rem;color:'+clr+';font-weight:600;white-space:nowrap;">'+pct+'%</span></div>';
}

function renderStageTimeline(events){
    if(!events||!events.length)return '';
    const reached=new Set(events.map(e=>e.stage_key||e.event_type));
    let html='<div style="display:flex;align-items:center;gap:2px;margin-top:6px;flex-wrap:wrap;">';
    ORDER_STAGES.forEach((st,i)=>{
        const active=reached.has(st.key);
        const bg=active?'var(--primary)':'var(--gray-200)';
        const clr=active?'white':'var(--gray-400)';
        html+='<span title="'+escapeHtml(st.label)+'" style="font-size:0.65rem;padding:1px 5px;border-radius:3px;background:'+bg+';color:'+clr+';white-space:nowrap;">'+escapeHtml(st.label)+'</span>';
        if(i<ORDER_STAGES.length-1)html+='<span style="color:var(--gray-300);font-size:0.6rem;">→</span>';
    });
    html+='</div>';
    return html;
}

async function loadOrdersGrouped(){
    const c=document.getElementById('webhookList');
    const cacheKey=_dashCacheKey('orders-grouped', JSON.stringify({o:grpCurrentOffset,q:document.getElementById('grpSearchInput')?.value||'',d:document.getElementById('grpDateInput')?.value||''}));
    const cached=_cacheGet(cacheKey);
    if(cached && _dashRenderFromCache('webhookList', cached.html, {cachedAt:cached.cachedAt, refreshFn:'_cacheClear(\'dash:orders-grouped\');loadOrdersGrouped()'})) return;
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลดออเดอร์...</div></div>';
    const searchVal=document.getElementById('grpSearchInput')?.value||'';
    const dateVal=document.getElementById('grpDateInput')?.value||'';
    const params={action:'order_grouped_today',limit:grpPageSize,offset:grpCurrentOffset};
    if(searchVal)params.search=searchVal;
    if(dateVal)params.date=dateVal;
    const result=await whApiCall(params);
    if(!result||!result.success){c.innerHTML='<p style="padding:1rem;color:var(--gray-500);">'+escapeHtml((result&&result.error)||'Error')+'</p>';return;}
    const {orders,total,date}=result.data;
    const tc=document.getElementById('whTotalCount');
    if(tc)tc.textContent=total+' ออเดอร์'+(date?' ('+date+')':'');
    const dateScopeBadge=document.getElementById('whDateScopeBadge');
    if(dateScopeBadge)dateScopeBadge.style.display=date===new Date().toISOString().slice(0,10)?'inline-block':'none';
    if(!orders||!orders.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;"></i>ไม่พบออเดอร์สำหรับวันนี้</div>';return;}
    let html='';
    orders.forEach(o=>{
        const nm=escapeHtml(o.order_name||'-');
        const cust=escapeHtml(o.customer_name||'')+((o.customer_ref&&o.customer_ref!=='null')?' ('+escapeHtml(o.customer_ref)+')':'');
        const amt=o.amount_total?'฿'+Number(o.amount_total).toLocaleString():'';
        const hasLine=!!(o.customer_line_user_id);
        const lineBadge=hasLine?'<span style="background:#06c755;color:white;padding:1px 6px;border-radius:50px;font-size:0.7rem;">LINE ✓</span>':'';
        const errBadge=o.has_error?'<span style="background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:50px;font-size:0.7rem;margin-left:4px;">⚠ Error</span>':'';
        const d=o.last_updated_at?new Date(o.last_updated_at):null;
        const timeStr=d&&!isNaN(d)?d.toLocaleString('th-TH',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'}):'-';
        const stateLabel=o.latest_state_display&&o.latest_state_display!=='null'?o.latest_state_display:(EVENT_LABELS[o.latest_event_type]||o.latest_event_type||'-');
        const eOI=encodeURIComponent(o.order_id||''),eON=encodeURIComponent(o.order_name||'');
        html+='<div style="background:white;border:1px solid var(--gray-200);border-radius:10px;padding:0.85rem 1rem;margin-bottom:0.6rem;'+(o.is_cancelled?'opacity:0.7;':'')+'">';
        html+='<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;flex-wrap:wrap;gap:4px;">';
        html+='<div><a href="javascript:void(0)" onclick="showOrderTimeline(decodeURIComponent(\''+eOI+'\'),decodeURIComponent(\''+eON+'\'))" style="color:var(--primary);text-decoration:none;font-weight:600;font-size:0.95rem;">'+nm+'</a> '+lineBadge+errBadge+'</div>';
        html+='<div style="text-align:right;"><span style="font-weight:600;color:var(--gray-800);">'+amt+'</span></div>';
        html+='</div>';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:4px;">';
        html+='<span style="color:var(--gray-600);font-size:0.82rem;">'+escapeHtml(cust||'-')+'</span>';
        html+='<span style="font-size:0.78rem;color:var(--gray-500);">'+escapeHtml(stateLabel)+' · '+o.event_count+' events · '+escapeHtml(timeStr)+'</span>';
        html+='</div>';
        html+=renderProgressBar(o.progress,o.is_cancelled);
        html+=renderStageTimeline(o.events);
        html+='</div>';
    });
    c.innerHTML=html;
    _dashCacheSaveHtml(cacheKey,'webhookList','_cacheClear(\'dash:orders-grouped\');loadOrdersGrouped()');
    // Pagination
    const pag=document.getElementById('webhookPagination');
    if(pag){if(total>grpPageSize){const tp=Math.ceil(total/grpPageSize),cp=Math.floor(grpCurrentOffset/grpPageSize)+1;pag.style.cssText='display:flex !important;justify-content:center;gap:0.5rem;margin-top:1rem;';let ph=cp>1?'<button class="chip" onclick="grpGoPage('+(cp-2)+')"><i class="bi bi-chevron-left"></i></button>':'';ph+='<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า '+cp+' / '+tp+'</span>';if(cp<tp)ph+='<button class="chip" onclick="grpGoPage('+cp+')"><i class="bi bi-chevron-right"></i></button>';pag.innerHTML=ph;}else pag.style.cssText='display:none !important;';}
}

// ===== SLIPS =====
let slipCurrentOffset=0;const slipPageSize=30;

// Debounced search for slips
const debouncedLoadSlips = debounce(function(){
    slipCurrentOffset = 0;
    loadSlips();
}, 300);

function slipGoPage(p){slipCurrentOffset=p*slipPageSize;loadSlips();}
function slipStatusBadge(s){
    const map={pending:'<span style="background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:50px;font-size:0.75rem;font-weight:500;">⏳ รอตรวจสอบ</span>',matched:'<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:50px;font-size:0.75rem;font-weight:500;">✅ จับคู่แล้ว</span>',failed:'<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:50px;font-size:0.75rem;font-weight:500;">❌ ไม่สำเร็จ</span>'};
    return map[s]||'<span style="background:var(--gray-100);color:var(--gray-500);padding:2px 8px;border-radius:50px;font-size:0.75rem;">'+escapeHtml(s)+'</span>';
}
function slipOrderInfo(s){
    const parts=[];
    if(s.order_id)parts.push('<span style="color:#0284c7;font-size:0.75rem;"><i class="bi bi-cart3"></i> SO-'+s.order_id+'</span>');
    if(s.invoice_id)parts.push('<span style="color:#7c3aed;font-size:0.75rem;"><i class="bi bi-receipt"></i> INV-'+s.invoice_id+'</span>');
    if(s.odoo_slip_id)parts.push('<span style="color:#15803d;font-size:0.72rem;">Odoo#'+s.odoo_slip_id+'</span>');
    if(s.matched_at){const md=new Date(s.matched_at).toLocaleString('th-TH',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});parts.push('<span style="color:var(--gray-400);font-size:0.7rem;">'+md+'</span>');}
    return parts.length?'<div style="display:flex;flex-direction:column;gap:2px;">'+parts.join('')+'</div>':'<span style="color:var(--gray-300);font-size:0.75rem;">-</span>';
}
async function loadSlips(){
    const el=document.getElementById('slipList');
    const cacheKey=_dashCacheKey('slips', JSON.stringify({o:slipCurrentOffset,q:document.getElementById('slipSearch')?.value||'',s:document.getElementById('slipStatusFilter')?.value||'',d:document.getElementById('slipDateFilter')?.value||''}));
    const cached=_cacheGet(cacheKey);
    if(cached && _dashRenderFromCache('slipList', cached.html, {cachedAt:cached.cachedAt, refreshFn:'_cacheClear(\'dash:slips\');loadSlips()'})) return;
    el.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    const search=document.getElementById('slipSearch')?.value||'';
    const status=document.getElementById('slipStatusFilter')?.value||'';
    const date=document.getElementById('slipDateFilter')?.value||'';
    const params=new URLSearchParams({limit:slipPageSize,offset:slipCurrentOffset});
    if(search)params.append('search',search);
    if(status)params.append('status',status);
    if(date)params.append('date',date);
    try{
        const _sc=new AbortController();const _st=setTimeout(()=>_sc.abort(),10000);
        const r=await fetch('api/slips-list.php?'+params.toString(),{signal:_sc.signal});
        clearTimeout(_st);
        const json=await r.json();
        if(!json.success){el.innerHTML='<p style="color:var(--danger);padding:1rem;">'+escapeHtml(json.error||'เกิดข้อผิดพลาด')+'</p>';return;}
        const {slips,total}=json.data;
        const tc=document.getElementById('slipTotalCount');
        if(tc)tc.textContent=total+' รายการ';
        if(!slips||slips.length===0){el.innerHTML='<p style="color:var(--gray-500);padding:1.5rem;text-align:center;"><i class="bi bi-inbox" style="font-size:2rem;"></i><br>ไม่พบสลิป</p>';return;}
        let html='<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.875rem;"><thead><tr style="background:var(--gray-50);">';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">รูปสลิป</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">ลูกค้า</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">จำนวนเงิน</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">สถานะ</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">ออเดอร์/ใบแจ้งหนี้</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">บันทึกโดย</th>';
        html+='<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">วันที่บันทึก</th>';
        html+='<th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">การดำเนินการ</th>';
        html+='</tr></thead><tbody>';
        slips.forEach((s,i)=>{
            const bg=i%2===0?'white':'var(--gray-50)';
            const amt=s.amount!=null?'฿'+parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:2}):'-';
            const dt=s.uploaded_at?new Date(s.uploaded_at).toLocaleString('th-TH',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}):'-';
            const thumb=s.image_full_url?'<img src="'+escapeHtml(s.image_full_url)+'" loading="lazy" onclick="openSlipPreview(\''+escapeHtml(s.image_full_url)+'\')" style="width:48px;height:60px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid var(--gray-200);" onerror="this.style.display=\'none\'">':'<span style="color:var(--gray-400);font-size:0.75rem;">ไม่มีรูป</span>';
            const custName=escapeHtml(s.customer_name||s.line_user_id||'-');
            const custLine=s.customer_name?'<div style="font-size:0.75rem;color:var(--gray-400);">'+escapeHtml(s.line_user_id||'')+'</div>':'';
            if(s.status==='failed')window._slipErrors=window._slipErrors||{},(window._slipErrors[s.id]=s.match_reason||'ไม่มีข้อมูล');
            // Store slip meta for multi-order modal
            window._slipMeta=window._slipMeta||{};
            window._slipMeta[s.id]={
                line_user_id:s.line_user_id,
                line_account_id:s.line_account_id,
                amount:s.amount,
                status:s.status,
                customer_name:s.customer_name||s.line_user_id,
                slip_inbox_id:s.slip_inbox_id||s.odoo_slip_id||0
            };
            // Action buttons
            let actionBtn='';
            if(s.status==='pending'){
                actionBtn='<div style="display:flex;flex-direction:column;gap:4px;align-items:center;">'
                    +'<button id="slip-btn-'+s.id+'" class="chip" onclick="sendOneSlipToOdoo('+s.id+',false)" style="font-size:0.75rem;padding:3px 10px;white-space:nowrap;"><i class="bi bi-cloud-upload"></i> ส่ง Odoo</button>'
                    +'<button class="chip" onclick="openMultiOrderMatch('+s.id+')" style="font-size:0.72rem;padding:2px 8px;white-space:nowrap;border-color:#7c3aed;color:#7c3aed;"><i class="bi bi-diagram-3"></i> จับคู่ออเดอร์</button>'
                    +'</div>';
            }else if(s.status==='matched'){
                actionBtn='<div style="display:flex;flex-direction:column;gap:4px;align-items:center;">'
                    +'<span style="color:#16a34a;font-size:0.75rem;">✓ ส่งแล้ว</span>'
                    +'<button class="chip" onclick="unMatchSlip('+s.id+')" style="font-size:0.7rem;padding:2px 6px;border-color:#6b7280;color:#6b7280;" title="รีเซ็ตกลับเป็น pending"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ต</button>'
                    +'</div>';
            }else{
                actionBtn='<div style="display:flex;flex-direction:column;gap:3px;align-items:center;">'
                    +'<span style="color:#dc2626;font-size:0.72rem;cursor:pointer;text-decoration:underline;" onclick="showSlipError('+s.id+');">[ดูข้อผิดพลาด]</span>'
                    +'<button id="slip-btn-'+s.id+'" class="chip" onclick="sendOneSlipToOdoo('+s.id+',true)" style="font-size:0.72rem;padding:2px 8px;border-color:#dc2626;color:#dc2626;white-space:nowrap;"><i class="bi bi-arrow-clockwise"></i> ส่งซ้ำ</button>'
                    +'<button class="chip" onclick="openMultiOrderMatch('+s.id+')" style="font-size:0.7rem;padding:2px 6px;border-color:#7c3aed;color:#7c3aed;white-space:nowrap;"><i class="bi bi-diagram-3"></i> จับคู่</button>'
                    +'</div>';
            }
            html+='<tr style="background:'+bg+';border-bottom:1px solid var(--gray-100);" id="slip-row-'+s.id+'">'
                +'<td style="padding:10px 12px;">'+thumb+'</td>'
                +'<td style="padding:10px 12px;"><div style="font-weight:500;">'+custName+'</div>'+custLine+'</td>'
                +'<td style="padding:10px 12px;font-weight:600;color:#16a34a;">'+amt+'</td>'
                +'<td style="padding:10px 12px;">'+slipStatusBadge(s.status)+'</td>'
                +'<td style="padding:10px 12px;" id="slip-orders-'+s.id+'">'+slipOrderInfo(s)+'</td>'
                +'<td style="padding:10px 12px;color:var(--gray-500);font-size:0.8rem;">'+escapeHtml(s.uploaded_by||'-')+'</td>'
                +'<td style="padding:10px 12px;color:var(--gray-500);font-size:0.8rem;">'+dt+'</td>'
                +'<td style="padding:10px 12px;text-align:center;" id="slip-action-'+s.id+'">'+actionBtn+'</td>'
                +'</tr>';
        });
        html+='</tbody></table></div>';
        el.innerHTML=html;
        _dashCacheSaveHtml(cacheKey,'slipList','_cacheClear(\'dash:slips\');loadSlips()');
        // Pagination
        const pag=document.getElementById('slipPagination');
        if(pag){
            if(total>slipPageSize){
                const tp=Math.ceil(total/slipPageSize),cp=Math.floor(slipCurrentOffset/slipPageSize)+1;
                let ph=cp>1?'<button class="chip" onclick="slipGoPage('+(cp-2)+')"><i class="bi bi-chevron-left"></i></button>':'';
                ph+='<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า '+cp+' / '+tp+'</span>';
                if(cp<tp)ph+='<button class="chip" onclick="slipGoPage('+cp+')"><i class="bi bi-chevron-right"></i></button>';
                pag.innerHTML=ph;
            }else pag.innerHTML='';
        }
    }catch(e){
        el.innerHTML='<p style="color:var(--danger);padding:1rem;">'+escapeHtml(e.message)+'</p>';
    }
}
function openSlipPreview(url){
    let modal=document.getElementById('slipPreviewModal');
    if(!modal){
        modal=document.createElement('div');
        modal.id='slipPreviewModal';
        modal.style.cssText='display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:2000;align-items:center;justify-content:center;';
        modal.innerHTML='<div style="max-width:90vw;max-height:90vh;position:relative;"><button onclick="document.getElementById(\"slipPreviewModal\").style.display=\"none\";" style="position:absolute;top:-12px;right:-12px;background:white;border:none;border-radius:50%;width:32px;height:32px;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.3);">×</button><img id="slipPreviewImg" src="" style="max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;"></div>';
        document.body.appendChild(modal);
        modal.addEventListener('click',function(e){if(e.target===modal)modal.style.display='none';});
    }
    document.getElementById('slipPreviewImg').src=url;
    modal.style.display='flex';
}

function showSlipError(id,msg){
    if(!msg&&window._slipErrors)msg=window._slipErrors[id]||'ไม่มีข้อมูล';
    let modal=document.getElementById('slipErrModal');
    if(!modal){
        modal=document.createElement('div');
        modal.id='slipErrModal';
        modal.style.cssText='display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.55);z-index:3000;align-items:center;justify-content:center;';
        modal.innerHTML='<div style="background:#fff;border-radius:14px;padding:24px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.18);position:relative;">'
            +'<div style="font-weight:600;font-size:1rem;color:#dc2626;margin-bottom:12px;"><i class="bi bi-x-octagon"></i> รายละเอียดข้อผิดพลาด</div>'
            +'<pre id="slipErrText" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px;font-size:0.8rem;color:#7f1d1d;white-space:pre-wrap;word-break:break-all;max-height:260px;overflow:auto;"></pre>'
            +'<div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">'
            +'<button id="slipErrRetryBtn" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:0.85rem;cursor:pointer;"><i class="bi bi-arrow-clockwise"></i> ส่งซ้ำ</button>'
            +'<button onclick="document.getElementById(\"slipErrModal\").style.display=\"none\";" style="background:#f3f4f6;color:#374151;border:none;border-radius:8px;padding:7px 18px;font-size:0.85rem;cursor:pointer;">ปิด</button>'
            +'</div></div>';
        document.body.appendChild(modal);
        modal.addEventListener('click',function(e){if(e.target===modal)modal.style.display='none';});
    }
    document.getElementById('slipErrText').textContent=msg||'ไม่มีข้อมูล';
    const retryBtn=document.getElementById('slipErrRetryBtn');
    retryBtn.onclick=function(){
        modal.style.display='none';
        sendOneSlipToOdoo(id,true);
    };
    modal.style.display='flex';
}
async function sendOneSlipToOdoo(id,retry){
    const btn=document.getElementById('slip-btn-'+id);
    if(btn){btn.disabled=true;btn.innerHTML='<i class="bi bi-hourglass-split"></i> กำลังส่ง...';}
    try{
        const payload={ids:[id]};
        if(retry)payload.retry=true;
        const r=await fetch('api/send-slips-to-odoo.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const json=await r.json();
        // PHP returns id as string, JS passes number — use == not ===
        const result=json.data?.results?.find(x=>x.id==id);
        if(result?.success===true||(json.success&&json.data?.sent>0&&!result)){
            _cacheClear('dash:slips');
            _cacheClear('dash:overview');
            _cacheClear('match_grid');
            const row=document.getElementById('slip-row-'+id);
            if(row){
                const tdAction=row.querySelector('td[id^="slip-action-"]');
                if(tdAction)tdAction.innerHTML='<div style="display:flex;flex-direction:column;gap:4px;align-items:center;"><span style="color:#16a34a;font-size:0.75rem;">✓ ส่งแล้ว</span><button class="chip" onclick="unMatchSlip('+id+')" style="font-size:0.7rem;padding:2px 6px;border-color:#6b7280;color:#6b7280;"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ต</button></div>';
                const tdStatus=row.cells[3];if(tdStatus)tdStatus.innerHTML=slipStatusBadge('matched');
                // Update orders cell if odoo result has ids
                if(result?.odoo_slip_id){const tdOrd=document.getElementById('slip-orders-'+id);if(tdOrd)tdOrd.innerHTML='<span style="color:#15803d;font-size:0.72rem;">Odoo#'+result.odoo_slip_id+'</span>';}
                row.style.background='#f0fdf4';
            }
        }else{
            const errMsg=result?.error||json.error||json.message||JSON.stringify(json);
            window._slipErrors=window._slipErrors||{};
            window._slipErrors[id]=errMsg;
            if(btn){btn.disabled=false;btn.innerHTML=retry?'<i class="bi bi-arrow-clockwise"></i> ส่งซ้ำ':'<i class="bi bi-cloud-upload"></i> ส่ง Odoo';}
            showSlipError(id,errMsg);
        }
    }catch(e){
        const errMsg='Network error: '+e.message;
        window._slipErrors=window._slipErrors||{};
        window._slipErrors[id]=errMsg;
        if(btn){btn.disabled=false;btn.innerHTML=retry?'<i class="bi bi-arrow-clockwise"></i> ส่งซ้ำ':'<i class="bi bi-cloud-upload"></i> ส่ง Odoo';}
        showSlipError(id,errMsg);
    }
}
async function unMatchSlip(id){
    if(!confirm('รีเซ็ตสลิปนี้กลับเป็นสถานะ "รอตรวจสอบ" ใช่ไหม?'))return;
    const meta=window._slipMeta&&window._slipMeta[id];
    try{
        const json=await whApiCall({
            action:'odoo_slip_unmatch_api',
            local_slip_id:id,
            slip_inbox_id:meta?.slip_inbox_id||0,
            line_user_id:meta?.line_user_id||'',
            line_account_id:meta?.line_account_id||0,
            reason:'รีเซ็ตจากรายการสลิป'
        });
        if(json.success){
            _cacheClear('dash:slips');
            _cacheClear('dash:overview');
            _cacheClear('match_grid');
            loadSlips();
        }else{
            alert('เกิดข้อผิดพลาด: '+(json.error||'ไม่ทราบสาเหตุ'));
        }
    }catch(e){alert('Network error: '+e.message);}
}
async function sendAllSlipsToOdoo(){
    const btn=document.getElementById('sendAllOdooBtn');
    if(!confirm('ต้องการส่งสลิปที่รอดำเนินการทั้งหมดไปยัง Odoo ใช่ไหม?'))return;
    if(btn){btn.disabled=true;btn.innerHTML='<i class="bi bi-hourglass-split"></i> กำลังส่ง...';}
    try{
        const r=await fetch('api/send-slips-to-odoo.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
        const json=await r.json();
        if(btn){btn.disabled=false;btn.innerHTML='<i class="bi bi-cloud-upload"></i> ส่งทั้งหมดไปยัง Odoo';}
        if(json.success){
            alert('✅ '+json.message);
            _cacheClear('dash:slips');
            _cacheClear('dash:overview');
            _cacheClear('match_grid');
            loadSlips();
        }else{
            alert('❌ เกิดข้อผิดพลาด: '+(json.error||'ไม่ทราบสาเหตุ'));
        }
    }catch(e){
        if(btn){btn.disabled=false;btn.innerHTML='<i class="bi bi-cloud-upload"></i> ส่งทั้งหมดไปยัง Odoo';}
        alert('เกิดข้อผิดพลาด: '+e.message);
    }
}

// ===== MULTI-ORDER MATCH MODAL =====
let _multiMatchSlipId=null;
let _multiMatchTargets={}; // id => {type,id,name,amount,checked}

async function openMultiOrderMatch(slipId){
    _multiMatchSlipId=slipId;
    _multiMatchTargets={};
    const meta=window._slipMeta&&window._slipMeta[slipId];
    if(!meta){alert('ไม่พบข้อมูลสลิป กรุณารีเฟรชรายการก่อน');return;}

    const modal=document.getElementById('multiMatchModal');
    if(!modal)return;
    modal.classList.add('active');

    document.getElementById('mmSlipId').textContent='#'+slipId;
    document.getElementById('mmCustomer').textContent=meta.customer_name||'-';
    document.getElementById('mmAmount').textContent=meta.amount!=null?'฿'+parseFloat(meta.amount).toLocaleString('th-TH',{minimumFractionDigits:2}):'-';
    document.getElementById('mmOrderList').innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลดออเดอร์...</div>';
    document.getElementById('mmSuggestions').innerHTML='';
    document.getElementById('mmSelected').innerHTML='<span style="color:var(--gray-400);">ยังไม่เลือก</span>';
    document.getElementById('mmConfirmBtn').disabled=true;

    try{
        const r=await fetch('api/slip-match-orders.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
            action:'search_orders',
            line_user_id:meta.line_user_id,
            line_account_id:meta.line_account_id,
            slip_amount:meta.amount?parseFloat(meta.amount):null
        })});
        const json=await r.json();
        if(!json.success){
            document.getElementById('mmOrderList').innerHTML='<p style="color:var(--danger);">'+escapeHtml(json.error||'เกิดข้อผิดพลาด')+'</p>';
            return;
        }
        const {orders,invoices,bdos,suggestions,partner,odoo_error,using_fallback}=json.data;
        console.log('[slip-match] API response:', JSON.stringify(json.data, null, 2));

        // Partner info
        if(partner){
            document.getElementById('mmCustomer').textContent=(partner.odoo_partner_name||meta.customer_name||'-')+' ('+partner.odoo_customer_code+')';
        }

        // Build order/invoice/BDO list — invoices first, then BDOs, then orders
        // Handle multiple possible field names from Odoo API vs webhook fallback
        const allItems=[];
        (invoices||[]).forEach((inv,idx)=>{
            const iid=inv.id!=null?inv.id:(inv.invoice_id!=null?inv.invoice_id:(inv.order_id!=null?inv.order_id:idx+1));
            const iname=inv.name||inv.invoice_number||inv.number||('INV-'+iid);
            const amt=(v=>(isNaN(v)?0:v))(parseFloat(inv.amount_residual??inv.amount_total??0));
            allItems.push({type:'invoice',id:iid,name:iname,amount:amt,due:inv.invoice_date_due||inv.due_date,state:inv.state,source:inv._source});
        });
        (bdos||[]).forEach((bdo,idx)=>{
            const bid=bdo.id!=null?bdo.id:idx+1;
            const bname=bdo.bdo_name||('BDO-'+bid);
            const amt=(v=>(isNaN(v)?0:v))(parseFloat(bdo.amount_total??0));
            allItems.push({type:'bdo',id:bid,name:bname,amount:amt,order_name:bdo.order_name,bdo_date:bdo.bdo_date,state:bdo.state,source:bdo._source});
        });
        (orders||[]).forEach((ord,idx)=>{
            const oid=ord.id!=null?ord.id:(ord.order_id!=null?ord.order_id:idx+1);
            const oname=ord.name||ord.order_name||ord.order_ref||('SO-'+oid);
            const amt=(v=>(isNaN(v)?0:v))(parseFloat(ord.amount_total??0));
            allItems.push({type:'order',id:oid,name:oname,amount:amt,state:ord.state,source:ord._source});
        });

        // Data source notice
        let sourceNotice='';
        if(using_fallback){
            sourceNotice='<div style="font-size:0.75rem;color:#b45309;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-bottom:8px;"><i class="bi bi-info-circle"></i> แสดงออเดอร์/ใบแจ้งหนี้จากประวัติ webhook (Odoo API ไม่ตอบสนอง)</div>';
        }else if(odoo_error){
            sourceNotice='<div style="font-size:0.75rem;color:#dc2626;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:6px 10px;margin-bottom:8px;"><i class="bi bi-exclamation-triangle"></i> Odoo: '+escapeHtml(odoo_error)+'</div>';
        }

        if(allItems.length===0){
            document.getElementById('mmOrderList').innerHTML=sourceNotice
                +'<p style="color:var(--gray-500);padding:1rem;text-align:center;"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:6px;"></i>'
                +'ไม่พบออเดอร์/ใบแจ้งหนี้<br>'
                +'<span style="font-size:0.78rem;color:var(--gray-400);">ลูกค้ารายนี้ยังไม่มีประวัติออเดอร์หรือยังไม่เชื่อม Odoo</span></p>';
        }else{
            let html=sourceNotice+'<div style="max-height:300px;overflow-y:auto;">';
            allItems.forEach(item=>{
                const key=item.type+'-'+item.id;
                let icon='<i class="bi bi-cart3" style="color:#0284c7;"></i>';
                if(item.type==='invoice') icon='<i class="bi bi-receipt" style="color:#7c3aed;"></i>';
                if(item.type==='bdo') icon='<i class="bi bi-file-earmark-check" style="color:#16a34a;"></i>';
                const amtFmt=item.amount>0?'฿'+item.amount.toLocaleString('th-TH',{minimumFractionDigits:2}):'-';
                let extraInfo='';
                if(item.due) extraInfo='<span style="color:var(--gray-400);font-size:0.72rem;">ครบ '+item.due+'</span>';
                if(item.type==='bdo' && item.order_name) extraInfo='<span style="color:var(--gray-400);font-size:0.72rem;">'+escapeHtml(item.order_name)+'</span>';
                const srcBadge=item.source==='webhook_log'?'<span style="font-size:0.65rem;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 5px;">webhook</span>':'';
                const itemJsonAttr=escapeHtml(JSON.stringify(item));
                html+='<label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--gray-200);border-radius:8px;margin-bottom:6px;cursor:pointer;background:white;" id="mm-label-'+key+'">'
                    +'<input type="checkbox" id="mm-chk-'+key+'" data-item="'+itemJsonAttr+'" onchange="mmToggleItem(\''+key+'\',JSON.parse(this.dataset.item||\"{}\"))" style="width:16px;height:16px;cursor:pointer;flex-shrink:0;">'
                    +'<div style="flex:1;min-width:0;">'
                        +'<div style="font-weight:500;font-size:0.875rem;display:flex;align-items:center;gap:6px;">'+icon+' <span>'+escapeHtml(item.name)+'</span>'+srcBadge+'</div>'
                        +'<div style="display:flex;gap:8px;align-items:center;margin-top:2px;">'
                            +'<span style="color:#16a34a;font-weight:600;font-size:0.875rem;">'+amtFmt+'</span>'
                            +extraInfo
                        +'</div>'
                    +'</div>'
                    +'</label>';
            });
            html+='</div>';
            document.getElementById('mmOrderList').innerHTML=html;
        }

        // Suggestions
        if(suggestions&&suggestions.length>0){
            let sugHtml='<div style="margin-top:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">'
                +'<div style="font-weight:600;color:#15803d;font-size:0.85rem;margin-bottom:6px;"><i class="bi bi-stars"></i> รายการที่รวมยอดตรง ฿'+parseFloat(meta.amount||0).toLocaleString('th-TH',{minimumFractionDigits:2})+'</div>';
            suggestions.forEach((set,si)=>{
                const names=set.map(x=>x.name).join(' + ');
                const total=set.reduce((a,x)=>a+x.amount,0);
                sugHtml+='<button class="chip" onclick="mmApplySuggestion('+si+')" style="margin-bottom:4px;font-size:0.8rem;padding:4px 12px;border-color:#16a34a;color:#16a34a;" id="mm-sug-'+si+'">'
                    +'<i class="bi bi-check2-circle"></i> '+escapeHtml(names)+' = ฿'+total.toLocaleString('th-TH',{minimumFractionDigits:2})
                    +'</button>';
            });
            sugHtml+='</div>';
            document.getElementById('mmSuggestions').innerHTML=sugHtml;
            // Store suggestions for apply
            window._mmSuggestions=suggestions;
        }else{
            window._mmSuggestions=[];
        }

    }catch(e){
        document.getElementById('mmOrderList').innerHTML='<p style="color:var(--danger);">Network error: '+escapeHtml(e.message)+'</p>';
    }
}

function mmToggleItem(key,item){
    const chk=document.getElementById('mm-chk-'+key);
    const lbl=document.getElementById('mm-label-'+key);
    if(chk&&chk.checked){
        _multiMatchTargets[key]={...item,checked:true};
        if(lbl)lbl.style.background='#eff6ff';
    }else{
        delete _multiMatchTargets[key];
        if(lbl)lbl.style.background='white';
    }
    mmRefreshSelected();
}

function mmApplySuggestion(si){
    const set=(window._mmSuggestions||[])[si];
    if(!set)return;
    // Uncheck all first
    document.querySelectorAll('[id^="mm-chk-"]').forEach(c=>{c.checked=false;});
    document.querySelectorAll('[id^="mm-label-"]').forEach(l=>{l.style.background='white';});
    _multiMatchTargets={};
    set.forEach(item=>{
        const key=item.type+'-'+item.id;
        const chk=document.getElementById('mm-chk-'+key);
        const lbl=document.getElementById('mm-label-'+key);
        if(chk){chk.checked=true;}
        if(lbl)lbl.style.background='#eff6ff';
        _multiMatchTargets[key]={...item,checked:true};
    });
    mmRefreshSelected();
}

function mmRefreshSelected(){
    const keys=Object.keys(_multiMatchTargets);
    const confirmBtn=document.getElementById('mmConfirmBtn');
    if(keys.length===0){
        document.getElementById('mmSelected').innerHTML='<span style="color:var(--gray-400);">ยังไม่เลือก</span>';
        if(confirmBtn)confirmBtn.disabled=true;
        return;
    }
    const total=keys.reduce((a,k)=>a+(_multiMatchTargets[k].amount||0),0);
    const slipAmt=window._slipMeta&&window._slipMeta[_multiMatchSlipId]?.amount;
    const diff=slipAmt!=null?(parseFloat(slipAmt)-total):null;
    let html='<div style="display:flex;flex-direction:column;gap:4px;">';
    keys.forEach(k=>{
        const it=_multiMatchTargets[k];
        html+='<div style="font-size:0.8rem;">'+escapeHtml(it.name)+' — ฿'+it.amount.toLocaleString('th-TH',{minimumFractionDigits:2})+'</div>';
    });
    html+='<div style="font-weight:600;margin-top:4px;">รวม: ฿'+total.toLocaleString('th-TH',{minimumFractionDigits:2});
    if(diff!==null){
        const diffAbs=Math.abs(diff);
        if(diffAbs<=1){html+=' <span style="color:#16a34a;font-size:0.8rem;">✓ ตรงยอด</span>';}
        else{html+=' <span style="color:#d97706;font-size:0.8rem;">(ต่าง ฿'+diffAbs.toLocaleString('th-TH',{minimumFractionDigits:2})+')</span>';}
    }
    html+='</div></div>';
    document.getElementById('mmSelected').innerHTML=html;
    if(confirmBtn)confirmBtn.disabled=false;
}

async function mmConfirmMatch(){
    if(!_multiMatchSlipId)return;
    const targets=Object.values(_multiMatchTargets).map(it=>({type:it.type,id:it.id}));
    if(targets.length===0){alert('กรุณาเลือกออเดอร์หรือใบแจ้งหนี้อย่างน้อย 1 รายการ');return;}
    const meta=window._slipMeta&&window._slipMeta[_multiMatchSlipId];
    const confirmBtn=document.getElementById('mmConfirmBtn');
    if(confirmBtn){confirmBtn.disabled=true;confirmBtn.innerHTML='<i class="bi bi-hourglass-split"></i> กำลังจับคู่...';}
    try{
        const r=await fetch('api/slip-match-orders.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
            action:'match',
            slip_id:_multiMatchSlipId,
            line_account_id:meta?.line_account_id||0,
            targets
        })});
        const json=await r.json();
        if(confirmBtn){confirmBtn.disabled=false;confirmBtn.innerHTML='<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';}
        if(json.success){
            document.getElementById('multiMatchModal').classList.remove('active');
            alert('✅ '+json.message);
            loadSlips();
        }else{
            alert('❌ เกิดข้อผิดพลาด: '+(json.error||'ไม่ทราบสาเหตุ'));
        }
    }catch(e){
        if(confirmBtn){confirmBtn.disabled=false;confirmBtn.innerHTML='<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';}
        alert('Network error: '+e.message);
    }
}

// ===== ORDER DETAIL MODAL =====
async function openOrderDetail(orderId, orderName){
    const modal=document.getElementById('orderDetailModal');
    const content=document.getElementById('orderDetailContent');
    const title=document.getElementById('orderDetailTitle');
    if(!modal||!content)return;
    title.textContent='ออเดอร์: '+(orderName||orderId||'-');
    content.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลดรายละเอียด...</div></div>';
    modal.classList.add('active');

    // Fetch timeline + detail
    const params={action:'order_timeline'};
    if(orderId&&orderId!=='null'&&orderId!=='')params.order_id=orderId;
    if(orderName&&orderName!=='null'&&orderName!=='-')params.order_name=orderName;
    const result=await whApiCall(params);

    if(!result||!result.success){
        content.innerHTML='<p style="color:var(--gray-500);">'+escapeHtml((result&&result.error)||'ไม่สามารถโหลดข้อมูลได้')+'</p>';
        return;
    }

    const {events,order_name:oName}=result.data;
    let html='';

    // Summary cards from first/last event
    if(events&&events.length){
        const first=events[0],last=events[events.length-1];
        const custName=first.customer_name||last.customer_name||'-';
        const amt=first.amount_total||last.amount_total;
        const amtFmt=amt?'฿'+Number(amt).toLocaleString():'-';
        const lastState=last.new_state_display||last.event_type||'-';
        const lastTime=last.processed_at?new Date(last.processed_at).toLocaleString('th-TH'):'-';

        html+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.6rem;margin-bottom:1.25rem;">';
        html+='<div class="info-box"><div class="info-label">ออเดอร์</div><div class="info-value" style="font-size:0.9rem;">'+escapeHtml(oName||orderName||'-')+'</div></div>';
        html+='<div class="info-box"><div class="info-label">ลูกค้า</div><div class="info-value" style="font-size:0.9rem;">'+escapeHtml(custName)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">ยอดรวม</div><div class="info-value" style="color:#059669;">'+amtFmt+'</div></div>';
        html+='<div class="info-box"><div class="info-label">สถานะล่าสุด</div><div class="info-value" style="font-size:0.85rem;">'+escapeHtml(lastState)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">อัปเดตล่าสุด</div><div class="info-value" style="font-size:0.82rem;">'+escapeHtml(lastTime)+'</div></div>';
        html+='</div>';

        // Timeline
        html+='<div style="font-weight:600;font-size:0.9rem;margin-bottom:0.75rem;color:var(--gray-700);"><i class="bi bi-clock-history" style="color:var(--primary);"></i> ไทม์ไลน์</div>';
        html+='<div style="position:relative;padding-left:24px;border-left:3px solid var(--gray-200);margin-left:8px;">';
        events.forEach(function(e,i){
            const et=String(e.event_type||''),icon=EVENT_ICONS[et]||'📌';
            const pd=e.processed_at?new Date(e.processed_at):null,t=pd&&!isNaN(pd)?pd.toLocaleString('th-TH'):'-';
            const state=e.new_state_display&&e.new_state_display!=='null'?e.new_state_display:et?et.split('.').pop():'-';
            const dot=i===events.length-1?'var(--primary)':'var(--gray-300)';
            const lTag=e.line_user_id?'<span class="badge-status badge-success" style="margin-left:4px;">LINE ✓</span>':'';
            html+='<div style="position:relative;margin-bottom:1.25rem;padding-left:16px;">'
                +'<div style="position:absolute;left:-32px;top:2px;width:14px;height:14px;border-radius:50%;background:'+dot+';border:3px solid white;box-shadow:0 0 0 2px '+dot+';"></div>'
                +'<div style="font-weight:600;font-size:0.88rem;">'+icon+' '+escapeHtml(state)+' '+lTag+'</div>'
                +'<div style="font-size:0.78rem;color:var(--gray-500);margin-top:2px;">'+escapeHtml(t)+'</div>'
                +'<div style="font-size:0.72rem;color:var(--gray-400);">'+escapeHtml(et)+' · '+escapeHtml(e.status||'-')+'</div>'
                +'</div>';
        });
        html+='</div>';

        // Raw payload button
        html+='<div style="margin-top:1rem;"><button class="chip" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'"><i class="bi bi-code-slash"></i> ดู Payload ล่าสุด</button>';
        const lastPayload=last.payload_decoded||last.payload;
        const payloadText=lastPayload?escapeHtml(typeof lastPayload==='object'?JSON.stringify(lastPayload,null,2):String(lastPayload)):'ไม่มีข้อมูล';
        html+='<div style="display:none;margin-top:0.5rem;"><pre class="json-display">'+payloadText+'</pre></div></div>';
    } else {
        html+='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบข้อมูลออเดอร์นี้</div>';
    }

    content.innerHTML=html;
}

// ===== INVOICE DETAIL MODAL =====
async function openInvoiceDetail(invoiceId, invoiceName){
    const modal=document.getElementById('invoiceDetailModal');
    const content=document.getElementById('invoiceDetailContent');
    const title=document.getElementById('invoiceDetailTitle');
    if(!modal||!content)return;
    title.textContent='ใบแจ้งหนี้: '+(invoiceName||invoiceId||'-');
    content.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลดรายละเอียด...</div></div>';
    modal.classList.add('active');

    // Try to find this invoice in webhook logs
    const result=await whApiCall({action:'list',limit:20,offset:0,search:invoiceName||invoiceId,event_type:''});
    const invoiceEvents=[];

    if(result&&result.success&&result.data.webhooks){
        result.data.webhooks.forEach(function(w){
            const et=String(w.event_type||'');
            if(et.startsWith('invoice.')||et.startsWith('payment.')){
                invoiceEvents.push(w);
            }
        });
    }

    let html='';

    // Invoice info cards
    html+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.6rem;margin-bottom:1.25rem;">';
    html+='<div class="info-box"><div class="info-label">เลขที่ใบแจ้งหนี้</div><div class="info-value" style="font-size:0.9rem;">'+escapeHtml(invoiceName||'-')+'</div></div>';

    if(invoiceEvents.length){
        const last=invoiceEvents[0];
        const payload=last.payload_decoded||null;
        let payloadObj=null;
        if(payload&&typeof payload==='object') payloadObj=payload;
        else if(typeof last.payload==='string'){try{payloadObj=JSON.parse(last.payload);}catch(e){}}

        const amt=last.amount_total||payloadObj?.amount_total;
        const amtFmt=amt?'฿'+Number(amt).toLocaleString():'-';
        const custName=last.customer_name||payloadObj?.partner_name||'-';
        const state=last.new_state_display||last.event_type?.split('.').pop()||'-';
        const dueDate=payloadObj?.invoice_date_due||payloadObj?.due_date||null;

        html+='<div class="info-box"><div class="info-label">ลูกค้า</div><div class="info-value" style="font-size:0.9rem;">'+escapeHtml(custName)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">ยอดรวม</div><div class="info-value" style="color:#059669;">'+amtFmt+'</div></div>';
        html+='<div class="info-box"><div class="info-label">สถานะ</div><div class="info-value" style="font-size:0.85rem;">'+escapeHtml(state)+'</div></div>';
        if(dueDate) html+='<div class="info-box"><div class="info-label">ครบกำหนด</div><div class="info-value" style="font-size:0.85rem;">'+escapeHtml(dueDate)+'</div></div>';

        // Payment info from payload
        const paymentMethod=payloadObj?.payment_method||payloadObj?.payment_type||null;
        const paymentRef=payloadObj?.payment_reference||payloadObj?.ref||null;
        if(paymentMethod||paymentRef){
            html+='<div class="info-box"><div class="info-label">วิธีชำระ</div><div class="info-value" style="font-size:0.85rem;">'+escapeHtml(paymentMethod||'-')+'</div></div>';
        }
    }
    html+='</div>';

    // Invoice events timeline
    if(invoiceEvents.length){
        html+='<div style="font-weight:600;font-size:0.9rem;margin-bottom:0.75rem;color:var(--gray-700);"><i class="bi bi-clock-history" style="color:var(--violet);"></i> ประวัติใบแจ้งหนี้</div>';
        html+='<div style="position:relative;padding-left:24px;border-left:3px solid var(--violet-light);margin-left:8px;">';
        invoiceEvents.reverse().forEach(function(e,i){
            const et=String(e.event_type||''),icon=EVENT_ICONS[et]||'📄';
            const pd=e.processed_at?new Date(e.processed_at):null,t=pd&&!isNaN(pd)?pd.toLocaleString('th-TH'):'-';
            const state=e.new_state_display&&e.new_state_display!=='null'?e.new_state_display:et?et.split('.').pop():'-';
            const dot=i===invoiceEvents.length-1?'var(--violet)':'var(--gray-300)';
            html+='<div style="position:relative;margin-bottom:1.25rem;padding-left:16px;">'
                +'<div style="position:absolute;left:-32px;top:2px;width:14px;height:14px;border-radius:50%;background:'+dot+';border:3px solid white;box-shadow:0 0 0 2px '+dot+';"></div>'
                +'<div style="font-weight:600;font-size:0.88rem;">'+icon+' '+escapeHtml(state)+'</div>'
                +'<div style="font-size:0.78rem;color:var(--gray-500);margin-top:2px;">'+escapeHtml(t)+'</div>'
                +'<div style="font-size:0.72rem;color:var(--gray-400);">'+escapeHtml(et)+' · '+escapeHtml(e.status||'-')+'</div>'
                +'</div>';
        });
        html+='</div>';

        // Show payment detail button if there's a payment event
        const paymentEvt=invoiceEvents.find(function(e){return String(e.event_type||'').startsWith('payment.');});
        if(paymentEvt){
            html+='<div style="margin-top:0.75rem;">';
            html+='<button class="chip" style="border-color:var(--success);color:var(--success);" onclick="openPaymentDetail(\''+escapeHtml(String(paymentEvt.id))+'\')">';
            html+='<i class="bi bi-credit-card"></i> ดูรายละเอียดการชำระเงิน</button></div>';
        }

        // Raw data toggle
        html+='<div style="margin-top:1rem;"><button class="chip" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'"><i class="bi bi-code-slash"></i> ดู Payload</button>';
        const lastPayload=invoiceEvents[invoiceEvents.length-1];
        const payloadText=escapeHtml(safeParseWebhookPayload(lastPayload.payload_decoded,lastPayload.payload));
        html+='<div style="display:none;margin-top:0.5rem;"><pre class="json-display">'+payloadText+'</pre></div></div>';
    } else {
        html+='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบข้อมูล webhook สำหรับใบแจ้งหนี้นี้<br><span style="font-size:0.8rem;">อาจเป็นข้อมูลจาก Odoo API ที่ยังไม่ส่ง webhook</span></div>';
    }

    content.innerHTML=html;
}

// ===== SEND BDO PAYMENT NOTIFICATION =====
async function sendBdoPaymentNotify(bdoId, partnerId, bdoName){
    if(!bdoId){alert('ไม่พบ BDO ID');return;}
    const displayName = bdoName || ('BDO-'+bdoId);
    if(!confirm('ส่งแจ้งยอดชำระ '+displayName+' ให้ลูกค้าทาง LINE?\n\nระบบจะส่ง Flex Message สรุปยอดพร้อมข้อมูลชำระเงิน')){
        return;
    }
    // Find and disable the button
    const btn = document.getElementById('btnNotifyBdo'+bdoId);
    const origHtml = btn ? btn.innerHTML : '';
    if(btn){btn.disabled=true;btn.innerHTML='<i class="bi bi-hourglass-split spin"></i> กำลังส่ง...';}
    try{
        const res = await whApiCall({
            action:'send_bdo_payment_notification',
            bdo_id: bdoId,
            partner_id: partnerId||''
        });
        if(!res || !res.success){
            throw new Error(res?.error || 'ส่งแจ้งเตือนไม่สำเร็จ');
        }
        const d = res.data || {};
        const amtStr = d.amount ? '฿'+Number(d.amount).toLocaleString('th-TH',{minimumFractionDigits:2}) : '';
        alert('✅ ส่งแจ้งยอดชำระ '+displayName+' สำเร็จ\n'+amtStr);
        if(btn){btn.innerHTML='<i class="bi bi-check-lg"></i> ส่งแล้ว';btn.style.background='#dcfce7';btn.style.color='#16a34a';btn.style.borderColor='#bbf7d0';}
    }catch(e){
        alert('❌ '+e.message);
        if(btn){btn.disabled=false;btn.innerHTML=origHtml;}
    }
}

// ===== BDO DETAIL MODAL =====
async function openBdoDetail(bdoId, bdoName, rawBdo){
    ensureDashboardModalRoots();
    const modal=document.getElementById('bdoDetailModal');
    const content=document.getElementById('bdoDetailContent');
    const title=document.getElementById('bdoDetailTitle');
    if(!modal||!content)return;
    title.textContent='BDO: '+(bdoName||bdoId||'-');
    content.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลดรายละเอียด...</div></div>';
    modal.classList.add('active');

    let rawBdoData=null;

    if(rawBdo){
        try{
            rawBdoData=JSON.parse(rawBdo);
        }catch(_e){
            rawBdoData=null;
        }
    }

    const partnerId = rawBdoData && rawBdoData.partner_id ? rawBdoData.partner_id : '';
    const _bdoCacheKey='bdo_detail:'+String(bdoId);
    let detail=null;
    const _bdoCached=_cacheGet(_bdoCacheKey);
    if(_bdoCached){
        detail=_bdoCached;
    }else{
        const detailResult = await whApiCall({
            action:'odoo_bdo_detail_api',
            bdo_id:bdoId,
            partner_id:partnerId
        });
        if(detailResult&&detailResult.success){ detail=detailResult.data||{}; _cacheSet(_bdoCacheKey,detail,120000); }
    }
    const bdo = detail && detail.bdo ? detail.bdo : rawBdoData;

    if(!bdo){
        content.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบข้อมูล BDO นี้</div>';
        return;
    }

    let html='';
    const _fmtDt=function(raw){if(!raw)return '-';const d=new Date(raw);if(isNaN(d))return String(raw).slice(0,10);return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit'});};
    const _fmtMoney=function(val){return val!=null&&!isNaN(Number(val))?'฿'+Number(val).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}):'-';};
    const sectionTitle=function(icon,color,label){
        return '<div style="font-weight:600;font-size:0.92rem;margin:1rem 0 0.55rem;color:var(--gray-700);"><i class="bi bi-'+icon+'" style="color:'+color+';"></i> '+label+'</div>';
    };

    const deliveryLabel = bdo.delivery_type === 'company' ? 'สายส่ง' : (bdo.delivery_type === 'private' ? 'ขนส่งเอกชน' : '-');
    const statementUrl = detail && detail.statement_pdf_url
        ? 'api/odoo-dashboard-api.php?action=odoo_bdo_statement_pdf&bdo_id=' + encodeURIComponent(String(bdoId))
        : (rawBdoData && rawBdoData.statement_pdf_path ? rawBdoData.statement_pdf_path : '');

    html+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.7rem;margin-bottom:1rem;">';
    html+='<div class="info-box"><div class="info-label">BDO</div><div class="info-value">'+escapeHtml(bdo.name||bdo.bdo_name||'-')+'</div></div>';
    html+='<div class="info-box"><div class="info-label">สถานะ</div><div class="info-value">'+escapeHtml(bdo.state||rawBdoData?.state||'-')+'</div></div>';
    html+='<div class="info-box"><div class="info-label">วันที่</div><div class="info-value">'+_fmtDt(bdo.doc_date||bdo.bdo_date||rawBdoData?.updated_at)+'</div></div>';
    html+='<div class="info-box"><div class="info-label">ประเภทขนส่ง</div><div class="info-value">'+escapeHtml(deliveryLabel)+'</div></div>';
    html+='<div class="info-box"><div class="info-label">ยอดสุทธิ</div><div class="info-value" style="color:#059669;">'+_fmtMoney(bdo.amount_net_to_pay||bdo.amount_total||rawBdoData?.amount_total)+'</div></div>';
    html+='</div>';

    if(statementUrl || detail?.odoo_url || partnerId){
        html+='<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;">';
        if(statementUrl){
            html+='<button class="chip" onclick="openPdfViewer(\''+escapeHtml(statementUrl)+'\',\'Statement PDF - '+escapeHtml(String(bdo.name||bdo.bdo_name||bdoId))+'\')"><i class="bi bi-file-earmark-pdf"></i> ดู Statement PDF</button>';
        }
        if(detail?.odoo_url){
            html+='<a class="chip" href="'+escapeHtml(detail.odoo_url)+'" target="_blank" rel="noopener noreferrer" style="text-decoration:none;"><i class="bi bi-box-arrow-up-right"></i> เปิดใน Odoo</a>';
        }
        // Send payment notification button
        const bdoState = String(bdo.state||rawBdoData?.state||'').toLowerCase();
        const isPaidBdo = bdoState==='paid'||bdoState==='cancelled';
        if(!isPaidBdo){
            html+='<button id="btnNotifyBdo'+escapeHtml(String(bdoId))+'" class="chip" onclick="sendBdoPaymentNotify(\''+escapeHtml(String(bdoId))+'\',\''+escapeHtml(String(partnerId))+'\',\''+escapeHtml(String(bdo.name||bdo.bdo_name||bdoId))+'\')" style="background:#06C755;color:#fff;border-color:#06C755;"><i class="bi bi-send"></i> ส่งแจ้งยอดผ่าน LINE</button>';
        }
        html+='</div>';
    }

    if(detail && detail.summary){
        html+=sectionTitle('cash-stack','#059669','สรุปยอด');
        html+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.65rem;">';
        html+='<div class="info-box"><div class="info-label">SO รอบนี้</div><div class="info-value">'+_fmtMoney(detail.summary.so_amount)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">Invoice ค้างชำระ</div><div class="info-value">'+_fmtMoney(detail.summary.outstanding_amount)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">Credit Note</div><div class="info-value">'+_fmtMoney(detail.summary.credit_note_amount)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">เงินมัดจำ</div><div class="info-value">'+_fmtMoney(detail.summary.deposit_amount)+'</div></div>';
        html+='<div class="info-box"><div class="info-label">Net To Pay</div><div class="info-value" style="color:#059669;font-weight:700;">'+_fmtMoney(detail.summary.net_to_pay)+'</div></div>';
        html+='</div>';
    }

    if(detail && Array.isArray(detail.sale_orders) && detail.sale_orders.length){
        html+=sectionTitle('boxes','#2563eb','รายการสินค้า');
        html+='<div style="display:flex;flex-direction:column;gap:0.7rem;">';
        detail.sale_orders.forEach(function(order){
            html+='<div style="border:1px solid var(--gray-200);border-radius:10px;padding:0.8rem;">';
            html+='<div style="display:flex;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.45rem;">';
            html+='<div style="font-weight:600;color:var(--gray-800);">'+escapeHtml(order.name||'-')+'</div>';
            html+='<div style="font-weight:700;color:#2563eb;">'+_fmtMoney(order.amount_total)+'</div>';
            html+='</div>';
            if(Array.isArray(order.lines) && order.lines.length){
                html+='<div style="overflow-x:auto;"><table class="data-table"><thead><tr><th>สินค้า</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">ราคา</th><th style="text-align:right;">รวม</th></tr></thead><tbody>';
                order.lines.forEach(function(line){
                    html+='<tr>';
                    html+='<td>'+escapeHtml(line.product_name||line.name||'-')+(line.product_code?' <span style="color:var(--gray-400);font-size:0.72rem;">('+escapeHtml(line.product_code)+')</span>':'')+'</td>';
                    html+='<td style="text-align:right;">'+escapeHtml(String(line.quantity||0))+' '+escapeHtml(line.uom||'')+'</td>';
                    html+='<td style="text-align:right;">'+_fmtMoney(line.unit_price||line.price_unit)+'</td>';
                    html+='<td style="text-align:right;font-weight:600;">'+_fmtMoney(line.subtotal||line.price_subtotal)+'</td>';
                    html+='</tr>';
                });
                html+='</tbody></table></div>';
            }
            html+='</div>';
        });
        html+='</div>';
    }

    const renderFinanceList=function(title, icon, color, rows, getTitle, getAmount, extra){
        if(!Array.isArray(rows) || !rows.length) return;
        html+=sectionTitle(icon,color,title);
        html+='<div style="display:flex;flex-direction:column;gap:0.45rem;">';
        rows.forEach(function(row){
            html+='<div style="display:flex;justify-content:space-between;gap:0.75rem;padding:0.65rem 0.8rem;border:1px solid var(--gray-200);border-radius:10px;background:#fff;">';
            html+='<div style="min-width:0;"><div style="font-weight:600;color:var(--gray-800);">'+escapeHtml(getTitle(row))+'</div>';
            if(extra){ html+='<div style="font-size:0.75rem;color:var(--gray-500);margin-top:2px;">'+extra(row)+'</div>'; }
            html+='</div>';
            html+='<div style="font-weight:700;color:'+color+';white-space:nowrap;">'+_fmtMoney(getAmount(row))+'</div>';
            html+='</div>';
        });
        html+='</div>';
    };

    renderFinanceList('ใบแจ้งหนี้ค้างชำระ','receipt','#d97706',detail?.outstanding_invoices||[],function(row){
        return row.number || row.name || '-';
    },function(row){
        return row.residual ?? row.amount_total;
    },function(row){
        return [row.date ? _fmtDt(row.date) : '', row.origin || ''].filter(Boolean).join(' · ');
    });

    renderFinanceList('Credit Notes','file-earmark-minus','#7c3aed',detail?.credit_notes||[],function(row){
        return row.number || row.name || '-';
    },function(row){
        return row.residual ?? row.amount_total;
    });

    renderFinanceList('เงินมัดจำ','wallet2','#0f766e',detail?.deposits||[],function(row){
        return row.name || '-';
    },function(row){
        return row.amount;
    });

    if(detail && Array.isArray(detail.slips) && detail.slips.length){
        html+=sectionTitle('paperclip','#16a34a','สลิปที่จับคู่แล้ว');
        html+='<div style="display:flex;flex-direction:column;gap:0.45rem;">';
        detail.slips.forEach(function(slip){
            html+='<div style="display:flex;justify-content:space-between;gap:0.75rem;padding:0.65rem 0.8rem;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4;">';
            html+='<div><div style="font-weight:600;color:#166534;">'+escapeHtml(slip.slip_inbox_name||slip.name||'Slip')+'</div>';
            html+='<div style="font-size:0.75rem;color:#15803d;">'+escapeHtml(fmtThDate(slip.transfer_date||slip.created_at))+'</div></div>';
            html+='<div style="font-weight:700;color:#166534;">'+_fmtMoney(slip.amount)+'</div>';
            html+='</div>';
        });
        html+='</div>';
    }

    const qrPayloadRaw=detail?.bdo?.qr_payment_data?.raw_payload||detail?.bdo?.qr_payload||detail?.qr_payload||'';
    if(qrPayloadRaw){
        html+=sectionTitle('qr-code','#7c3aed','QR Payment (พร้อมเพย์)');
        html+='<div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:0.8rem;">';
        html+='<div style="font-size:0.75rem;color:var(--gray-500);margin-bottom:0.3rem;">Payload</div>';
        html+='<div style="font-family:JetBrains Mono,monospace;font-size:0.78rem;word-break:break-all;margin-bottom:0.5rem;">'+escapeHtml(String(qrPayloadRaw))+'</div>';
        html+='<div style="font-size:0.72rem;color:#7c3aed;"><i class="bi bi-info-circle"></i> QR Payload สำหรับอ้างอิง</div>';
        html+='</div>';
    }

    // Data source indicator
    if(detail?.source === 'local_db'){
        html+='<div style="margin-top:0.75rem;padding:0.5rem 0.75rem;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;font-size:0.75rem;color:#92400e;"><i class="bi bi-database"></i> ข้อมูลจาก Local DB (Odoo API ไม่สามารถเชื่อมต่อได้ — ข้อมูลบางส่วนอาจไม่ครบ)</div>';
    }

    html+='<div style="margin-top:1rem;"><button class="chip" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'"><i class="bi bi-code-slash"></i> ดูข้อมูลดิบ</button>';
    html+='<div style="display:none;margin-top:0.5rem;"><pre class="json-display">'+escapeHtml(JSON.stringify(detail||bdo,null,2))+'</pre></div></div>';

    content.innerHTML=html;
}

// ===== PAYMENT DETAIL MODAL =====
async function openPaymentDetail(webhookId){
    ensureDashboardModalRoots();
    const modal=document.getElementById('paymentDetailModal');
    const content=document.getElementById('paymentDetailContent');
    const title=document.getElementById('paymentDetailTitle');
    if(!modal||!content)return;
    title.textContent='รายละเอียดการชำระเงิน';
    content.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    modal.classList.add('active');

    const result=await whApiCall({action:'detail',id:webhookId});
    if(!result||!result.success){
        content.innerHTML='<p style="color:var(--gray-500);">'+escapeHtml((result&&result.error)||'ไม่สามารถโหลดข้อมูลได้')+'</p>';
        return;
    }

    const w=result.data;
    let payloadObj=null;
    if(w.payload_decoded&&typeof w.payload_decoded==='object') payloadObj=w.payload_decoded;
    else if(typeof w.payload==='string'){try{payloadObj=JSON.parse(w.payload);}catch(e){}}

    const PAYMENT_METHODS={cash:'เงินสด',bank_transfer:'โอนเงิน',promptpay:'พร้อมเพย์',cheque:'เช็ค',credit_card:'บัตรเครดิต',qr_code:'QR Code'};

    let html='';

    // Payment summary
    const amt=w.amount_total||payloadObj?.amount||payloadObj?.amount_total;
    const amtFmt=amt?'฿'+Number(amt).toLocaleString():'-';
    const payMethod=payloadObj?.payment_method||payloadObj?.payment_type||payloadObj?.journal_name||'-';
    const payMethodLabel=PAYMENT_METHODS[payMethod]||payMethod;
    const payRef=payloadObj?.payment_reference||payloadObj?.ref||payloadObj?.communication||'-';
    const payDate=payloadObj?.payment_date||payloadObj?.date||w.processed_at;
    const custName=w.customer_name||payloadObj?.partner_name||'-';

    html+='<div style="text-align:center;padding:1.5rem;margin-bottom:1rem;background:linear-gradient(135deg,#d1fae5 0%,#cffafe 100%);border-radius:12px;">';
    html+='<div style="font-size:0.8rem;color:#059669;font-weight:500;margin-bottom:0.25rem;">ยอดชำระ</div>';
    html+='<div style="font-size:2rem;font-weight:700;color:#059669;">'+amtFmt+'</div>';
    html+='</div>';

    html+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.6rem;margin-bottom:1.25rem;">';
    html+='<div class="info-box"><div class="info-label">ลูกค้า</div><div class="info-value" style="font-size:0.88rem;">'+escapeHtml(custName)+'</div></div>';
    html+='<div class="info-box"><div class="info-label">วิธีชำระ</div><div class="info-value" style="font-size:0.88rem;">'+escapeHtml(payMethodLabel)+'</div></div>';
    html+='<div class="info-box"><div class="info-label">อ้างอิง</div><div class="info-value" style="font-size:0.82rem;word-break:break-all;">'+escapeHtml(payRef)+'</div></div>';
    if(payDate){
        const pd=new Date(payDate);
        const pdFmt=!isNaN(pd)?pd.toLocaleString('th-TH'):payDate;
        html+='<div class="info-box"><div class="info-label">วันที่ชำระ</div><div class="info-value" style="font-size:0.85rem;">'+escapeHtml(pdFmt)+'</div></div>';
    }
    html+='</div>';

    // Bank/PromptPay details
    if(payloadObj){
        const bankName=payloadObj.bank_name||payloadObj.journal_name||null;
        const bankAcct=payloadObj.bank_account||payloadObj.account_number||null;
        const qrData=payloadObj.qr_code||payloadObj.promptpay_qr||null;

        if(bankName||bankAcct){
            html+='<div style="font-weight:600;font-size:0.88rem;margin-bottom:0.5rem;color:var(--gray-700);"><i class="bi bi-bank" style="color:var(--info);"></i> ข้อมูลธนาคาร</div>';
            html+='<div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:0.85rem;margin-bottom:1rem;">';
            if(bankName) html+='<div style="margin-bottom:0.3rem;"><span style="font-size:0.75rem;color:var(--gray-500);">ธนาคาร:</span> <span style="font-weight:600;">'+escapeHtml(bankName)+'</span></div>';
            if(bankAcct) html+='<div><span style="font-size:0.75rem;color:var(--gray-500);">เลขบัญชี:</span> <span style="font-weight:600;font-family:JetBrains Mono,monospace;">'+escapeHtml(bankAcct)+'</span></div>';
            html+='</div>';
        }

        if(qrData){
            html+='<div style="font-weight:600;font-size:0.88rem;margin-bottom:0.5rem;color:var(--gray-700);"><i class="bi bi-qr-code" style="color:var(--violet);"></i> QR Code</div>';
            html+='<div style="background:white;border:2px solid var(--gray-200);border-radius:12px;padding:1rem;text-align:center;max-width:220px;margin:0 auto 1rem;">';
            html+='<img src="'+escapeHtml(qrData)+'" style="max-width:180px;max-height:180px;" onerror="this.parentElement.innerHTML=\'<span style=color:var(--gray-400)>ไม่สามารถแสดง QR ได้</span>\'">';
            html+='</div>';
        }
    }

    // Raw payload
    html+='<div style="margin-top:1rem;"><button class="chip" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'"><i class="bi bi-code-slash"></i> ดู Payload</button>';
    const payloadText=escapeHtml(safeParseWebhookPayload(w.payload_decoded,w.payload));
    html+='<div style="display:none;margin-top:0.5rem;"><pre class="json-display">'+payloadText+'</pre></div></div>';

    content.innerHTML=html;
}

// ===== PDF VIEWER MODAL =====
function openPdfViewer(pdfUrl, docTitle){
    ensureDashboardModalRoots();
    const modal=document.getElementById('pdfViewerModal');
    const content=document.getElementById('pdfViewerContent');
    const title=document.getElementById('pdfViewerTitle');
    const dlLink=document.getElementById('pdfDownloadLink');
    if(!modal||!content)return;

    title.textContent=docTitle||'เอกสาร PDF';
    dlLink.href=pdfUrl;
    content.innerHTML='<iframe src="'+escapeHtml(pdfUrl)+'" style="width:100%;height:600px;border:none;"></iframe>';
    modal.classList.add('active');
}

function closePdfViewer(){
    const modal=document.getElementById('pdfViewerModal');
    if(modal) modal.classList.remove('active');
    const content=document.getElementById('pdfViewerContent');
    if(content) content.innerHTML='<div class="loading" style="color:var(--gray-400);padding:4rem;"><i class="bi bi-file-earmark-pdf" style="font-size:3rem;"></i><div>เลือกเอกสารเพื่อดู</div></div>';
}

// ===== ADMIN MODE TOGGLE =====
function toggleAdminMode(){
    document.body.classList.toggle('admin-mode');
    const isAdmin=document.body.classList.contains('admin-mode');
    localStorage.setItem('odoo_admin_mode',isAdmin?'1':'0');
    const label=document.getElementById('adminToggleLabel');
    if(label)label.textContent=isAdmin?'Admin ON':'Admin';
}
function restoreAdminMode(){
    if(localStorage.getItem('odoo_admin_mode')==='1'){
        document.body.classList.add('admin-mode');
        const label=document.getElementById('adminToggleLabel');
        if(label)label.textContent='Admin ON';
    }
}

// ===== CACHE WARMING =====
async function warmCriticalCaches(){
    console.log('[Cache] Warming critical caches...');

    // Warm overview only if it hasn't already been kicked off by the DOMContentLoaded
    // 250 ms timer (prevents two simultaneous overview_fast requests on cold cache).
    if(!_overviewLoaded && typeof _cacheGet === 'function' && typeof _dashCacheKey === 'function'){
        if(!_cacheGet(_dashCacheKey('overview', 'today'))){
            console.log('[Cache] Warming overview cache...');
            if(typeof loadTodayOverview === 'function') loadTodayOverview();
        }
    }

    // Matching-grid cache is only warmed if the user has previously visited the
    // matching section (flag stored in sessionStorage). Unconditionally fetching
    // slip_center_bdo_overview on every cold page load adds ~500 ms of unnecessary
    // network + DB load for users who never use that section.
    if(typeof _cacheGet === 'function' && sessionStorage.getItem('_visited_matching') && !_cacheGet('match_grid')){
        console.log('[Cache] Warming matching grid cache...');
        if(typeof loadMatchingCustomerGrid === 'function') loadMatchingCustomerGrid();
    }
}

// ===== TODAY OVERVIEW =====
let _overviewLoaded=false;
function _isSectionActive(id){
    const el=document.getElementById('section-'+id);
    return !!(el&&el.classList.contains('active'));
}
function _cacheOverviewState(cacheKey){
    _cacheSet(cacheKey,{
        cachedAt:Date.now(),
        texts:{
            kpiOrdersToday:document.getElementById('kpiOrdersToday')?.textContent||'',
            kpiSalesToday:document.getElementById('kpiSalesToday')?.textContent||'',
            kpiSlipsPending:document.getElementById('kpiSlipsPending')?.textContent||'',
            kpiOverdueCustomers:document.getElementById('kpiOverdueCustomers')?.textContent||'',
            kpiBdosPending:document.getElementById('kpiBdosPending')?.textContent||'',
            kpiPaymentsToday:document.getElementById('kpiPaymentsToday')?.textContent||''
        },
        blocks:{
            overviewLineNotifs:document.getElementById('overviewLineNotifs')?.innerHTML||'',
            overviewRecentOrders:document.getElementById('overviewRecentOrders')?.innerHTML||'',
            overviewPendingSlips:document.getElementById('overviewPendingSlips')?.innerHTML||'',
            overviewOverdueCustomers:document.getElementById('overviewOverdueCustomers')?.innerHTML||''
        }
    });
}
async function loadTodayOverview(){
    _overviewLoaded=true;
    const cacheKey=_dashCacheKey('overview','today');
    const cached=_cacheGet(cacheKey);
    if(cached){
        ['kpiOrdersToday','kpiSalesToday','kpiSlipsPending','kpiOverdueCustomers','kpiBdosPending','kpiPaymentsToday'].forEach(function(id){
            const el=document.getElementById(id);
            if(el && cached.texts && cached.texts[id]!=null) el.textContent=cached.texts[id];
        });
        ['overviewLineNotifs','overviewRecentOrders','overviewPendingSlips','overviewOverdueCustomers'].forEach(function(id){
            const el=document.getElementById(id);
            if(el && cached.blocks && cached.blocks[id]!=null) el.innerHTML=cached.blocks[id];
        });
        const recent=document.getElementById('overviewRecentOrders');
        if(recent) _renderSectionCacheNote(recent, cached.cachedAt, '_cacheClear(\'dash:overview\');loadTodayOverview()');
        return;
    }

    // Strategy: try overview_fast first (uses indexed sync tables, <500ms),
    // then fall back to overview_today (heavier, may timeout on large tables).
    let res = null;

    // 1st: Try ultra-fast overview (avoids slow webhooks_log table)
    try {
        const fastRes = await whApiCall({ action: 'overview_fast' });
        if (fastRes && fastRes.success && fastRes.data) {
            const f = fastRes.data;
            res = {
                success: true,
                data: {
                    stats: {
                        unique_orders_today: f.orders_today || 0,
                        notified_today: f.webhook_total_today || 0,
                        total: f.webhook_total_today || 0,
                        success: Math.round((f.webhook_success_rate || 0) * (f.webhook_total_today || 0) / 100),
                        dead_letter: 0,
                        last_webhook: f.last_webhook
                    },
                    orders: f.orders || [],
                    orders_total: f.orders_today || 0,
                    overdue_customers: [],
                    overdue_total: f.overdue_customers || 0,
                    pending_bdo: {
                        orders: [],
                        bdos: Array(f.bdos_pending || 0).fill({ amount_net_to_pay: f.bdos_pending_amount / Math.max(1, f.bdos_pending) }),
                        total: f.bdos_pending || 0
                    },
                    slips_pending: [],
                    slips_pending_total: f.slips_pending || 0,
                    slips_matched_today_sum: f.payments_today || 0,
                    _source: 'overview_fast'
                }
            };
        }
    } catch (_e) { /* fall through */ }

    // 2nd: If fast overview failed or returned nothing, try the full overview_today (whApiCall tries all endpoints so dashboard-api handles it if webhooks doesn't)
    if (!res) {
        const overviewRes = await whApiCall({ action: 'overview_today' });
        if (overviewRes && overviewRes.success) res = overviewRes;
    }
    const kpiOrders=document.getElementById('kpiOrdersToday');
    const kpiSales=document.getElementById('kpiSalesToday');
    const kpiSlips=document.getElementById('kpiSlipsPending');
    const kpiOverdue=document.getElementById('kpiOverdueCustomers');
    const kpiBdo=document.getElementById('kpiBdosPending');
    const kpiPaid=document.getElementById('kpiPaymentsToday');

    if(!res||!res.success){
        if(kpiOrders)kpiOrders.textContent='-';
        if(kpiSales)kpiSales.textContent='-';
        if(kpiSlips)kpiSlips.textContent='-';
        if(kpiOverdue)kpiOverdue.textContent='-';
        if(kpiBdo)kpiBdo.textContent='-';
        if(kpiPaid)kpiPaid.textContent='-';
        ['overviewLineNotifs','overviewRecentOrders','overviewPendingSlips','overviewOverdueCustomers'].forEach(function(id){
            const el=document.getElementById(id);
            if(el)el.innerHTML='<div style="text-align:center;padding:1rem;color:var(--gray-500);">'+(res&&res.error?escapeHtml(res.error):'โหลดไม่สำเร็จ')+'</div>';
        });
        _cacheOverviewState(cacheKey);
        return;
    }
    const d=res.data;
    const s=d.stats||{};
    const orders=d.orders||[];
    const ordersTotal=d.orders_total||0;
    const overdueCustomers=d.overdue_customers||[];
    const overdueTotal=d.overdue_total||0;
    const pendingBdoData=d.pending_bdo||{};
    const bdos=pendingBdoData.orders||pendingBdoData.bdos||[];
    const slipsPending=d.slips_pending||[];
    const slipsPendingTotal=d.slips_pending_total||0;
    const matchedTodaySum=d.slips_matched_today_sum||0;

    if(kpiOrders)kpiOrders.textContent=Number(s.unique_orders_today||0).toLocaleString();
    if(kpiSlips)kpiSlips.textContent=Number(slipsPendingTotal).toLocaleString();
    if(kpiOverdue)kpiOverdue.textContent=Number(overdueTotal).toLocaleString();
    if(kpiBdo){
        kpiBdo.textContent=Number(bdos.length||0).toLocaleString();
        const pendingTotal=bdos.reduce(function(sm,b){return sm+parseFloat(b.amount_net_to_pay||b.amount_total||0);},0);
        const sub=kpiBdo.parentElement&&kpiBdo.parentElement.querySelector('.kpi-sub');
        if(sub)sub.textContent=pendingTotal>0?'฿'+pendingTotal.toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:0}):'ยอดรอจับคู่';
    }
    if(kpiPaid)kpiPaid.textContent=matchedTodaySum>0?'฿'+matchedTodaySum.toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:0}):'-';

    const notifEl=document.getElementById('overviewLineNotifs');
    if(notifEl){
        const sent=Number(s.notified_today||0);
        const rate=s.total>0?((s.success/s.total)*100).toFixed(0):'0';
        notifEl.innerHTML='<div style="display:flex;gap:0.6rem;flex-wrap:wrap;">'
            +'<div style="background:#dcfce7;border-radius:8px;padding:0.6rem 0.8rem;flex:1;min-width:100px;"><div style="font-size:0.72rem;color:#16a34a;">LINE แจ้งเตือนวันนี้</div><div style="font-size:1.2rem;font-weight:700;color:#16a34a;">'+sent+'</div></div>'
            +'<div style="background:#dbeafe;border-radius:8px;padding:0.6rem 0.8rem;flex:1;min-width:100px;"><div style="font-size:0.72rem;color:#1d4ed8;">Webhook สำเร็จ</div><div style="font-size:1.2rem;font-weight:700;color:#1d4ed8;">'+rate+'%</div></div>'
            +'<div style="background:'+(s.dead_letter>0?'#fee2e2':'#f0fdf4')+';border-radius:8px;padding:0.6rem 0.8rem;flex:1;min-width:100px;"><div style="font-size:0.72rem;color:'+(s.dead_letter>0?'#dc2626':'#16a34a')+';">สถานะระบบ</div><div style="font-size:1.2rem;font-weight:700;color:'+(s.dead_letter>0?'#dc2626':'#16a34a')+';">'+(s.dead_letter>0?'มีปัญหา '+s.dead_letter:'ปกติ')+'</div></div>'
            +'</div>';
    }
    let totalSales=0;
    orders.forEach(function(o){ totalSales+=parseFloat(o.amount_total||0); });
    if(kpiSales){
        kpiSales.textContent=totalSales>0?'฿'+totalSales.toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:0}):'-';
        const kpiSalesSub=kpiSales.parentElement&&kpiSales.parentElement.querySelector('.kpi-sub');
        if(kpiSalesSub&&ordersTotal)kpiSalesSub.textContent=ordersTotal+' ออเดอร์ทั้งหมด';
    }
    const recentEl=document.getElementById('overviewRecentOrders');
    if(recentEl){
        if(!orders.length){
            recentEl.innerHTML='<div style="text-align:center;padding:1.5rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;"></i>ยังไม่มีออเดอร์วันนี้</div>';
        }else{
            let html='';
            orders.forEach(function(o){
                const nm=escapeHtml(o.order_name||'-');
                const cust=escapeHtml(o.customer_name||'-');
                const amt=o.amount_total?'฿'+Number(o.amount_total).toLocaleString():'';
                const stateLabel=o.latest_state_display&&o.latest_state_display!=='null'?o.latest_state_display:(EVENT_LABELS[o.latest_event_type]||'-');
                const hasLine=!!(o.customer_line_user_id);
                const lineBadge=hasLine?'<span style="background:#06c755;color:white;padding:1px 5px;border-radius:50px;font-size:0.65rem;">LINE</span>':'';
                const eOI=encodeURIComponent(o.order_id||''),eON=encodeURIComponent(o.order_name||'');
                const pct=Math.max(0,Math.min(100,o.progress||0));
                const pClr=pct>=100?'#16a34a':pct>=65?'#0284c7':pct>=25?'#d97706':'#6b7280';
                html+='<div style="display:flex;align-items:center;gap:0.6rem;padding:0.5rem 0;border-bottom:1px solid var(--gray-100);">'
                    +'<div style="flex:1;min-width:0;">'
                    +'<div style="display:flex;align-items:center;gap:0.35rem;">'
                    +'<a href="javascript:void(0)" onclick="showOrderTimeline(decodeURIComponent(\''+eOI+'\'),decodeURIComponent(\''+eON+'\'))" style="color:var(--primary);text-decoration:none;font-weight:600;font-size:0.85rem;">'+nm+'</a> '+lineBadge
                    +'</div>'
                    +'<div style="font-size:0.75rem;color:var(--gray-500);margin-top:1px;">'+cust+' · '+escapeHtml(stateLabel)+'</div>'
                    +'</div>'
                    +'<div style="text-align:right;white-space:nowrap;">'
                    +'<div style="font-weight:600;font-size:0.85rem;">'+amt+'</div>'
                    +'<div style="width:60px;height:5px;background:var(--gray-200);border-radius:3px;margin-top:3px;"><div style="width:'+pct+'%;height:100%;background:'+pClr+';border-radius:3px;"></div></div>'
                    +'</div></div>';
            });
            recentEl.innerHTML=html;
        }
    }
    const slipsEl=document.getElementById('overviewPendingSlips');
    if(slipsEl){
        if(!slipsPending.length){
            slipsEl.innerHTML='<div style="text-align:center;padding:1.5rem;color:var(--gray-400);"><i class="bi bi-check-circle" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;color:#16a34a;"></i>ไม่มีสลิปรอตรวจสอบ</div>';
        }else{
            let html='';
            slipsPending.forEach(function(s){
                const amt=s.amount!=null?'฿'+parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0}):'?';
                const custName=escapeHtml(s.customer_name||s.line_user_id||'-');
                const dt=s.uploaded_at?new Date(s.uploaded_at).toLocaleString('th-TH',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}):'-';
                const thumb=s.image_full_url?'<img src="'+escapeHtml(s.image_full_url)+'" style="width:32px;height:40px;object-fit:cover;border-radius:4px;border:1px solid var(--gray-200);" onerror="this.style.display=\'none\'">':'<div style="width:32px;height:40px;background:var(--gray-100);border-radius:4px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>';
                html+='<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid var(--gray-100);">'
                    +thumb
                    +'<div style="flex:1;min-width:0;">'
                    +'<div style="font-size:0.82rem;font-weight:500;">'+custName+'</div>'
                    +'<div style="font-size:0.7rem;color:var(--gray-400);">'+dt+'</div>'
                    +'</div>'
                    +'<div style="font-weight:600;color:#d97706;font-size:0.85rem;">'+amt+'</div>'
                    +'</div>';
            });
            slipsEl.innerHTML=html;
        }
    }
    const overdueEl=document.getElementById('overviewOverdueCustomers');
    if(overdueEl){
        if(!overdueCustomers.length){
            overdueEl.innerHTML='<div style="text-align:center;padding:1.5rem;color:var(--gray-400);"><i class="bi bi-check-circle" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;color:#16a34a;"></i>ไม่มีลูกค้าค้างชำระ</div>';
        }else{
            let html='';
            overdueCustomers.forEach(function(cu){
                const nm=escapeHtml(cu.customer_name||cu.name||'-');
                const ref=escapeHtml(cu.customer_ref||cu.ref||'');
                const pid=String(cu.partner_id||cu.customer_id||cu.odoo_id||'');
                const rawDue=cu.total_due||cu.overdue_amount||0;
                const due=rawDue>0?'฿'+Number(rawDue).toLocaleString():'';
                const hasLine=!!(cu.line_user_id);
                const lineDot=hasLine?'<span style="color:#06c755;font-size:0.6rem;" title="LINE เชื่อมแล้ว">●</span>':'';
                const encRef=encodeURIComponent(cu.customer_ref||cu.ref||'');
                const encId=encodeURIComponent(pid);
                const encNm=encodeURIComponent(cu.customer_name||cu.name||'');
                html+='<div style="display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--gray-100);cursor:pointer;" onclick="showCustomerDetail(decodeURIComponent(\''+encRef+'\'),decodeURIComponent(\''+encId+'\'),decodeURIComponent(\''+encNm+'\'))">'
                    +'<div style="flex:1;min-width:0;">'
                    +'<div style="font-size:0.82rem;font-weight:500;">'+nm+' '+lineDot+'</div>'
                    +'<div style="font-size:0.7rem;color:var(--gray-400);">'+ref+(ref?' · ':'')+escapeHtml(pid)+'</div>'
                    +'</div>'
                    +'<div style="font-weight:600;color:#dc2626;font-size:0.85rem;">'+due+'</div>'
                    +'</div>';
            });
            overdueEl.innerHTML=html;
        }
    }
    _cacheOverviewState(cacheKey);
}

// ===== MATCHING DASHBOARD (Slip BDO) =====
let _matchSlips = [], _matchBdos = [], _matchSuggestions = [];
let _matchSelectedSlips = new Set(), _matchSelectedBdos = new Set();
let _matchMatchedToday = [];
let _matchActiveCustomer = null; // null=grid, {ref,name,partnerId,salespersonId,salespersonName}=detail
let _matchAllCustomers   = [];
let _matchSlipCountByRef = {};
let _matchBdoCountByRef  = {};
let _matchRecentChatImages = [];
let _matchImageSlipContext = null;

var MATCH_CUSTOMER_PAGE = 50;
var _matchCustomerOffset = 0;
var _matchCustomerTotal = 0;
var _matchCustomerLoading = false;
var _matchGridObserver = null;
var _matchCustomerSearchTimer = null;

// ===== CUSTOMER GRID (full list + server sort + lazy load) =====

function matchCustomerSearchDebounced(){
    clearTimeout(_matchCustomerSearchTimer);
    _matchCustomerSearchTimer = setTimeout(function(){ loadMatchingCustomerGrid(true); }, 400);
}

function _matchGridDisconnectObserver(){
    if(_matchGridObserver){
        try{ _matchGridObserver.disconnect(); }catch(e){}
        _matchGridObserver = null;
    }
}

function _matchMergeSalespersonOptions(customers){
    const spSel = document.getElementById('matchSalespersonFilter');
    if(!spSel) return;
    const existing = new Set();
    for(let i = 0; i < spSel.options.length; i++){
        existing.add(String(spSel.options[i].value));
    }
    (customers || []).forEach(function(c){
        const sid = c.salesperson_id;
        const snm = c.salesperson_name;
        if(sid && snm && !existing.has(String(sid))){
            const opt = document.createElement('option');
            opt.value = String(sid);
            opt.textContent = snm;
            spSel.appendChild(opt);
            existing.add(String(sid));
        }
    });
}

function _matchUpdateGridMeta(){
    const el = document.getElementById('matchCustomerGridMeta');
    if(!el) return;
    const n = _matchAllCustomers.length;
    const t = _matchCustomerTotal || 0;
    const sortSel = document.getElementById('matchCustomerSort');
    const sortLabel = sortSel && sortSel.options[sortSel.selectedIndex] ? sortSel.options[sortSel.selectedIndex].textContent : '';
    el.textContent = 'แสดง ' + n + (t ? (' / ' + t) : '') + ' รายการ · ' + sortLabel;
}

function _setupMatchGridObserver(){
    _matchGridDisconnectObserver();
    if(_matchCustomerTotal > 0 && _matchAllCustomers.length >= _matchCustomerTotal) return;
    const sen = document.getElementById('matchCustomerGridSentinel');
    if(!sen) return;
    _matchGridObserver = new IntersectionObserver(function(entries){
        if(!entries[0] || !entries[0].isIntersecting) return;
        if(_matchCustomerLoading) return;
        if(_matchCustomerTotal > 0 && _matchAllCustomers.length >= _matchCustomerTotal) return;
        loadMatchingCustomerGrid(false);
    }, { root: null, rootMargin: '120px', threshold: 0 });
    _matchGridObserver.observe(sen);
}

function _matchBuildCustomerCardHtml(cu){
    const ref    = normalizeMatchCustomerRef(cu.customer_ref || cu.ref || '');
    const name   = cu.customer_name || cu.name || '-';
    const pid    = String(cu.partner_id || cu.customer_id || cu.odoo_id || '');
    const spName = cu.salesperson_name || '';
    const slipCnt = _matchSlipCountByRef[ref] || 0;
    const bdoCnt  = _matchBdoCountByRef[ref]  || 0;
    const hasPending = slipCnt > 0;

    const ordN = (cu.orders_total != null && cu.orders_total !== '') ? cu.orders_total : ((cu.orders_30d != null && cu.orders_30d !== '') ? cu.orders_30d : '—');
    const lastAt = cu.latest_order_at ? fmtThDate(cu.latest_order_at) : '—';

    const cardBg     = hasPending ? '#fffbeb' : '#ffffff';
    const cardBorder = hasPending ? '#fde68a' : 'var(--gray-200)';
    const cardShadow = hasPending ? '0 2px 8px rgba(251,191,36,0.18)' : '0 1px 3px rgba(0,0,0,0.06)';

    const slipBadge = hasPending
        ? '<span style="background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:600;"><i class="bi bi-clock-fill" style="font-size:0.65rem;"></i> สลิปรอจับคู่ ' + slipCnt + '</span>'
        : '';
    const bdoBadge = bdoCnt > 0
        ? '<span style="background:#ede9fe;color:#7c3aed;padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;">BDO ค้างชำระ ' + bdoCnt + '</span>'
        : '<span style="font-size:0.72rem;color:var(--gray-300);">BDO ค้างชำระ 0</span>';

    const encRef = encodeURIComponent(ref);
    const encName = encodeURIComponent(name);
    const encSp = encodeURIComponent(spName);

    let html = '';
    html += '<div onclick="openMatchingForCustomer(decodeURIComponent(\'' + encRef + '\'),decodeURIComponent(\'' + encName + '\'),\'' + escapeHtml(pid) + '\',decodeURIComponent(\'' + encSp + '\'))" '
        + 'style="background:' + cardBg + ';border:1.5px solid ' + cardBorder + ';border-radius:14px;padding:0.9rem 1rem;cursor:pointer;box-shadow:' + cardShadow + ';transition:box-shadow 0.15s,border-color 0.15s;" '
        + 'onmouseover="this.style.boxShadow=\'0 4px 16px rgba(0,0,0,0.12)\';this.style.borderColor=\'' + (hasPending ? '#f59e0b' : '#94a3b8') + '\'" '
        + 'onmouseout="this.style.boxShadow=\'' + cardShadow + '\';this.style.borderColor=\'' + cardBorder + '\'">';
    html += '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.4rem;margin-bottom:0.35rem;">';
    html += '<div style="font-weight:700;font-size:0.95rem;color:var(--gray-800);">' + escapeHtml(ref || '-') + '</div>';
    if(hasPending) html += '<i class="bi bi-exclamation-circle-fill" style="color:#f59e0b;font-size:0.95rem;flex-shrink:0;"></i>';
    html += '</div>';
    html += '<div style="font-size:0.82rem;color:var(--gray-600);margin-bottom:0.35rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escapeHtml(name) + '">' + escapeHtml(name) + '</div>';
    html += '<div style="font-size:0.68rem;color:var(--gray-400);margin-bottom:0.45rem;">Order ' + escapeHtml(String(ordN)) + ' · ล่าสุด ' + escapeHtml(String(lastAt)) + '</div>';
    html += '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:0.5rem;">' + slipBadge + bdoBadge + '</div>';
    if(spName){
        html += '<div style="font-size:0.7rem;color:var(--gray-400);"><i class="bi bi-person-badge" style="font-size:0.65rem;"></i> ' + escapeHtml(spName) + '</div>';
    }
    html += '<div style="margin-top:0.55rem;text-align:right;">';
    html += '<span style="font-size:0.75rem;color:' + (hasPending ? '#d97706' : 'var(--gray-400)') + ';font-weight:500;">เปิดจับคู่ <i class="bi bi-arrow-right-short"></i></span>';
    html += '</div>';
    html += '</div>';
    return html;
}

async function loadMatchingCustomerGrid(forceRefresh){
    const cardsEl = document.getElementById('matchCustomerGridCards');
    const loaderEl = document.getElementById('matchCustomerGridLoader');

    if(forceRefresh){
        _matchCustomerOffset = 0;
        _matchAllCustomers = [];
        _matchCustomerTotal = 0;
        _matchGridDisconnectObserver();
        if(cardsEl){
            cardsEl.innerHTML = '<div class="loading" style="text-align:center;padding:2rem;"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
        }
        if(loaderEl) loaderEl.style.display = 'none';
    }
    if(!forceRefresh){
        if(_matchCustomerTotal > 0 && _matchAllCustomers.length >= _matchCustomerTotal) return;
    }
    if(_matchCustomerLoading) return;
    _matchCustomerLoading = true;

    const isFirstPage = _matchCustomerOffset === 0;
    if(!isFirstPage && loaderEl) loaderEl.style.display = 'block';

    const search = (document.getElementById('matchCustomerSearch')?.value || '').trim();
    const sp = document.getElementById('matchSalespersonFilter')?.value || '';
    const sortBy = document.getElementById('matchCustomerSort')?.value || '';

    const fast = (!search && !sp && sortBy === '') ? 1 : 0;
    const custParams = { action: 'customer_list', limit: MATCH_CUSTOMER_PAGE, offset: _matchCustomerOffset, fast: fast };
    if(sortBy) custParams.sort_by = sortBy;
    if(search) custParams.search = search;
    if(sp) custParams.salesperson_id = sp;

    // ── Summary cache key for slip/BDO aggregate counts (3 min TTL) ──
    const _summaryCacheKey = 'match_slip_bdo_summary';
    const _summaryTTL = 180000; // 3 minutes

    try{
        let custRes;
        if(isFirstPage){
            // Check if summary is already cached
            const cachedSummary = _cacheGet(_summaryCacheKey);
            const [pr0, summaryRes] = await Promise.all([
                whApiCall(custParams),
                cachedSummary ? Promise.resolve(null) : whApiCall({ action: 'customer_slip_bdo_summary' })
            ]);
            custRes = pr0;

            // Use cached summary or fresh response
            const summary = cachedSummary || ((summaryRes && summaryRes.success && summaryRes.data) ? summaryRes.data : null);
            if(summary && !cachedSummary) _cacheSet(_summaryCacheKey, summary, _summaryTTL);

            // Populate badge count maps from aggregate summary
            _matchSlipCountByRef = {};
            _matchBdoCountByRef  = {};
            if(summary){
                const slipsMap = summary.slips || {};
                const bdosMap  = summary.bdos  || {};
                Object.keys(slipsMap).forEach(function(rawRef){
                    const ref = normalizeMatchCustomerRef(rawRef);
                    if(ref) _matchSlipCountByRef[ref] = (slipsMap[rawRef] || 0);
                });
                Object.keys(bdosMap).forEach(function(rawRef){
                    const ref = normalizeMatchCustomerRef(rawRef);
                    if(ref) _matchBdoCountByRef[ref] = (bdosMap[rawRef] || 0);
                });
            }
        } else {
            custRes = await whApiCall(custParams);
        }

        if(!custRes || !custRes.success || !custRes.data){
            if(isFirstPage && cardsEl){
                cardsEl.innerHTML = '<div style="text-align:center;padding:2.5rem;color:var(--gray-400);">โหลดรายการลูกค้าไม่สำเร็จ</div>';
            }
            if(loaderEl) loaderEl.style.display = 'none';
            _matchCustomerLoading = false;
            return;
        }

        const batch = custRes.data.customers || [];
        _matchCustomerTotal = parseInt(custRes.data.total, 10) || _matchCustomerTotal || 0;
        _matchAllCustomers = _matchAllCustomers.concat(batch);
        _matchCustomerOffset += batch.length;

        if(batch.length === 0){
            if(isFirstPage && cardsEl){
                cardsEl.innerHTML = '<div style="text-align:center;padding:2.5rem;color:var(--gray-400);"><i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบลูกค้า</div>';
            }
            _matchCustomerTotal = _matchAllCustomers.length;
            _matchUpdateGridMeta();
            if(loaderEl) loaderEl.style.display = 'none';
            _matchCustomerLoading = false;
            return;
        }

        _matchMergeSalespersonOptions(batch);

        if(isFirstPage && cardsEl){
            cardsEl.innerHTML = '';
            cardsEl.style.display = 'grid';
            cardsEl.style.gridTemplateColumns = 'repeat(3, 1fr)';
            cardsEl.style.gap = '0.75rem';
        }
        let html = '';
        batch.forEach(function(cu){ html += _matchBuildCustomerCardHtml(cu); });
        if(cardsEl) cardsEl.insertAdjacentHTML('beforeend', html);

        _matchUpdateGridMeta();
        if(loaderEl) loaderEl.style.display = 'none';
        _matchCustomerLoading = false;
        _setupMatchGridObserver();
    } catch(e){
        if(loaderEl) loaderEl.style.display = 'none';
        _matchCustomerLoading = false;
        if(isFirstPage && cardsEl){
            cardsEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#dc2626;">เกิดข้อผิดพลาดในการโหลด</div>';
        }
    }
}

function renderMatchingCustomerGrid(){
    loadMatchingCustomerGrid(true);
}

function openMatchingForCustomer(ref, name, partnerId, salespersonName){
    // Store active customer context
    _matchActiveCustomer = {
        ref: ref,
        name: name,
        partnerId: partnerId,
        salespersonName: salespersonName,
        lineUserId: ''
    };
    
    // Toggle zones
    const gridZone = document.getElementById('matchCustomerGridZone');
    const detailZone = document.getElementById('matchCustomerDetailZone');
    
    if(gridZone) gridZone.style.display = 'none';
    if(detailZone) detailZone.style.display = 'block';
    
    // Update header with back button
    const header = document.getElementById('matchCustomerDetailHeader');
    if(header){
        header.innerHTML = `
            <div class="content-card" style="margin-bottom:0.75rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <button class="chip" onclick="closeMatchingCustomer()" style="font-size:0.85rem;">
                        <i class="bi bi-arrow-left"></i> กลับรายการลูกค้า
                    </button>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:1.05rem;color:var(--gray-800);">
                            ${escapeHtml(name)}
                        </div>
                        <div style="font-size:0.8rem;color:var(--gray-500);">
                            รหัส: ${escapeHtml(ref)}
                            ${salespersonName ? ' · พนักงานขาย: ' + escapeHtml(salespersonName) : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Load matching data first, then resolve LINE user + chat images (needs line_user_id for slip upload)
    void loadMatchingDashboard().then(function(){
        loadCustomerRecentImages();
    });
}

function closeMatchingCustomer(){
    _matchActiveCustomer = null;
    _matchRecentChatImages = [];
    const gal = document.getElementById('matchChatImageGallery');
    if(gal) gal.innerHTML = '—';
    const gridZone   = document.getElementById('matchCustomerGridZone');
    const detailZone = document.getElementById('matchCustomerDetailZone');
    if(detailZone) detailZone.style.display = 'none';
    if(gridZone)   gridZone.style.display   = '';
}

function _matchGalleryHiddenKey(){
    const id = _matchActiveCustomer && _matchActiveCustomer.lineUserId ? String(_matchActiveCustomer.lineUserId) : 'x';
    return 'match_gallery_hidden_' + id;
}

function _absoluteUrlForSlipUpload(u){
    if(!u) return '';
    const s = String(u).trim();
    if(/^https?:\/\//i.test(s)) return s;
    const base = (typeof window !== 'undefined' && window.location && window.location.origin) ? window.location.origin : '';
    return base + '/' + s.replace(/^\//, '');
}

async function loadCustomerRecentImages(){
    const el = document.getElementById('matchChatImageGallery');
    if(!el || !_matchActiveCustomer) return;
    el.innerHTML = '<div class="loading" style="padding:0.5rem;"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลดรูปจากแชท...</div>';
    const ref = _matchActiveCustomer.ref || '';
    const pid = _matchActiveCustomer.partnerId || '';
    let url = 'api/customer-recent-images.php?';
    if(ref) url += 'customer_ref=' + encodeURIComponent(ref) + '&';
    if(pid) url += 'partner_id=' + encodeURIComponent(String(pid)) + '&';
    try{
        const res = await fetch(url).then(function(r){ return r.json(); });
        if(res && res.success && res.data){
            if(res.data.line_user_id && _matchActiveCustomer){
                _matchActiveCustomer.lineUserId = res.data.line_user_id;
            }
            _matchRecentChatImages = res.data.images || [];
            renderRecentImageGallery(_matchRecentChatImages);
        } else {
            el.innerHTML = '<span style="color:var(--gray-400);">' + escapeHtml((res && res.error) || 'โหลดรูปไม่สำเร็จ') + '</span>';
        }
    } catch(e){
        el.innerHTML = '<span style="color:#dc2626;">โหลดรูปจากแชทไม่สำเร็จ</span>';
    }
}

function renderRecentImageGallery(images){
    const el = document.getElementById('matchChatImageGallery');
    if(!el) return;
    let hidden = [];
    try{
        hidden = JSON.parse(sessionStorage.getItem(_matchGalleryHiddenKey()) || '[]');
    } catch(e){ hidden = []; }
    const hiddenSet = new Set(hidden.map(function(x){ return parseInt(x, 10); }));
    const visible = (images || []).filter(function(img){ return !hiddenSet.has(img.id); });
    if(!visible.length){
        el.innerHTML = '<span style="color:var(--gray-400);">ไม่มีรูปภาพจากแชทในช่วง 10 วัน หรือซ่อนหมดแล้ว</span>';
        return;
    }
    let html = '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;">';
    visible.forEach(function(img){
        const thumb = img.thumb_url || img.image_url;
        const dt = img.created_at ? fmtThDate(img.created_at) : '';
        const src = thumb ? escapeHtml(_absoluteUrlForSlipUpload(thumb)) : '';
        html += '<div style="position:relative;width:72px;">';
        if(src){
            html += '<img src="' + src + '" alt="" style="width:72px;height:88px;object-fit:cover;border-radius:8px;border:1px solid var(--gray-200);cursor:pointer;background:var(--gray-100);" onclick="selectImageForSlipById(' + img.id + ')">';
        } else {
            html += '<div style="width:72px;height:88px;border-radius:8px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:var(--gray-400);text-align:center;padding:4px;">ไม่มีตัวอย่าง</div>';
        }
        html += '<div style="font-size:0.62rem;color:var(--gray-500);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(dt) + '</div>';
        html += '<button type="button" onclick="event.stopPropagation();hideRecentImage(' + img.id + ')" style="position:absolute;top:-4px;right:-4px;width:22px;height:22px;border-radius:50%;border:none;background:var(--gray-800);color:#fff;font-size:0.65rem;cursor:pointer;line-height:1;" title="ซ่อนรูปนี้">×</button>';
        html += '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
}

function hideRecentImage(messageDbId){
    let hidden = [];
    try{
        hidden = JSON.parse(sessionStorage.getItem(_matchGalleryHiddenKey()) || '[]');
    } catch(e){ hidden = []; }
    hidden.push(messageDbId);
    sessionStorage.setItem(_matchGalleryHiddenKey(), JSON.stringify(hidden));
    renderRecentImageGallery(_matchRecentChatImages);
}

function selectImageForSlipById(messageDbId){
    const item = (_matchRecentChatImages || []).find(function(x){ return x.id === messageDbId; });
    if(item) selectImageForSlip(item);
}

function selectImageForSlip(item){
    _matchImageSlipContext = item || null;
    const prev = document.getElementById('matchImageSlipPreview');
    const src = item && (item.thumb_url || item.image_url) ? _absoluteUrlForSlipUpload(item.thumb_url || item.image_url) : '';
    if(prev) prev.src = src || '';
    const amt = document.getElementById('matchImageSlipAmount');
    const dt = document.getElementById('matchImageSlipDate');
    if(amt) amt.value = '';
    if(dt && item && item.created_at){
        dt.value = String(item.created_at).slice(0, 10);
    } else if(dt){
        dt.value = '';
    }
    fillMatchImageSlipBdoSelect();
    const modal = document.getElementById('matchImageSlipModal');
    if(modal){
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
    }
}

function fillMatchImageSlipBdoSelect(){
    const sel = document.getElementById('matchImageSlipBdoId');
    if(!sel) return;
    sel.innerHTML = '<option value="">— เลือก BDO —</option>';
    (_matchBdos || []).forEach(function(b){
        if(!isBdoOutstandingPayment(b)) return;
        const id = b.bdo_id || b.id;
        const amtNum = b.amount_net_to_pay != null ? Number(b.amount_net_to_pay) : (b.amount_total != null ? Number(b.amount_total) : 0);
        const amt = amtNum;
        const label = (b.bdo_name || ('BDO-' + id)) + ' · ฿' + amt.toLocaleString('th-TH', {minimumFractionDigits: 0});
        const opt = document.createElement('option');
        opt.value = String(id);
        opt.textContent = label;
        sel.appendChild(opt);
    });
}

function closeMatchImageSlipModal(){
    const modal = document.getElementById('matchImageSlipModal');
    if(modal){
        modal.style.display = 'none';
    }
    _matchImageSlipContext = null;
}

async function submitImageAsSlip(){
    const ctx = _matchImageSlipContext;
    if(!ctx || !_matchActiveCustomer || !_matchActiveCustomer.lineUserId){
        alert('ไม่พบข้อมูลลูกค้า LINE — รีเฟรชแล้วลองใหม่');
        return;
    }
    const amtEl = document.getElementById('matchImageSlipAmount');
    const dtEl = document.getElementById('matchImageSlipDate');
    const bdoSel = document.getElementById('matchImageSlipBdoId');
    const amount = amtEl ? parseFloat(amtEl.value) : NaN;
    const transferDate = dtEl && dtEl.value ? dtEl.value : null;
    const bdoId = bdoSel && bdoSel.value ? parseInt(bdoSel.value, 10) : 0;
    if(!amount || amount <= 0){
        alert('กรุณากรอกจำนวนเงิน');
        return;
    }
    if(!bdoId){
        alert('กรุณาเลือก BDO');
        return;
    }
    const imageUrl = ctx.image_url ? _absoluteUrlForSlipUpload(ctx.image_url) : '';
    if(!imageUrl && !ctx.line_message_id){
        alert('ไม่พบไฟล์รูปสำหรับแนบ');
        return;
    }
    const btn = document.getElementById('matchImageSlipSubmitBtn');
    if(btn){
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';
    }
    const payload = {
        line_user_id: _matchActiveCustomer.lineUserId,
        image_url: imageUrl || undefined,
        message_id: ctx.line_message_id || undefined,
        amount: amount,
        transfer_date: transferDate,
        bdo_id: bdoId,
        skip_line_notify: true
    };
    try{
        const res = await fetch('api/odoo-slip-upload.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json; charset=utf-8'},
            body: JSON.stringify(payload)
        }).then(function(r){ return r.json(); });
        if(res && res.success){
            closeMatchImageSlipModal();
            await loadMatchingDashboard();
            await loadCustomerRecentImages();
        } else {
            throw new Error((res && res.error) || 'บันทึกไม่สำเร็จ');
        }
    } catch(e){
        alert('❌ ' + (e.message || e));
    } finally {
        if(btn){
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> บันทึกสลิป';
        }
    }
}

async function loadMatchingDashboard(){
    const slipEl = document.getElementById('matchSlipList');
    const bdoEl = document.getElementById('matchBdoList');
    if(slipEl) slipEl.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    if(bdoEl) bdoEl.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
    _matchSelectedSlips.clear(); _matchSelectedBdos.clear();
    updateMatchSummaryBar();

    const filterMode = document.getElementById('matchFilterMode')?.value || 'pending';
    const search = document.getElementById('matchSearchInput')?.value || '';

    // Scope to active customer if set
    const custRef = _matchActiveCustomer ? _matchActiveCustomer.ref : '';
    const custPid = _matchActiveCustomer ? _matchActiveCustomer.partnerId : '';

    const slipStatus = filterMode === 'matched' ? 'matched' : (filterMode === 'pending' ? 'pending' : '');
    let slipUrl = 'api/slips-list.php?limit=200&offset=0';
    if(slipStatus) slipUrl += '&status=' + slipStatus;
    if(search)     slipUrl += '&search=' + encodeURIComponent(search);
    if(custRef)    slipUrl += '&customer_ref=' + encodeURIComponent(custRef);

    const bdoParams = {action:'odoo_bdo_list_api', limit:200, offset:0, search: search};
    if(custRef) bdoParams.customer_ref = custRef;
    if(custPid) bdoParams.partner_id   = custPid;
    if(filterMode !== 'matched') bdoParams.payment_filter = 'unpaid';

    const [slipRes, bdoRes] = await Promise.all([
        fetch(slipUrl).then(r=>r.json()).catch(()=>({success:false})),
        whApiCall(bdoParams)
    ]);

    _matchSlips = (slipRes && slipRes.success && slipRes.data) ? (slipRes.data.slips || []) : [];
    _matchBdos = (bdoRes && bdoRes.success && bdoRes.data) ? (bdoRes.data.bdos || []) : [];

    // Compute smart matches
    _matchSuggestions = computeSmartMatches(_matchSlips, _matchBdos);

    // Separate matched today
    const today = new Date().toISOString().slice(0, 10);
    _matchMatchedToday = _matchSlips.filter(function(s){
        return s.status === 'matched' && s.matched_at && s.matched_at.slice(0, 10) === today;
    });

    // KPI
    const pendingSlips = _matchSlips.filter(function(s){ return s.status === 'pending' || s.status === 'new'; });
    const pendingBdos = _matchBdos.filter(function(b){
        return isBdoOutstandingPayment(b);
    });
    const problemSlips = _matchSlips.filter(function(s){ return s.status === 'failed'; });

    const kpiPending = document.getElementById('matchKpiPending');
    const kpiSugg = document.getElementById('matchKpiSuggested');
    const kpiSuccess = document.getElementById('matchKpiSuccess');
    const kpiProblem = document.getElementById('matchKpiProblem');
    if(kpiPending) kpiPending.textContent = pendingSlips.length + ' / ' + pendingBdos.length;
    if(kpiSugg) kpiSugg.textContent = _matchSuggestions.length;
    if(kpiSuccess) kpiSuccess.textContent = _matchMatchedToday.length;
    if(kpiProblem) kpiProblem.textContent = problemSlips.length;

    // Enable batch confirm if suggestions exist
    const batchBtn = document.getElementById('matchBatchConfirmBtn');
    if(batchBtn) batchBtn.disabled = _matchSuggestions.length === 0;

    // Auto-confirm exact matches (bdo_id + exact amount)
    const autoConfirmable = _matchSuggestions.filter(function(m){ return m.confidence === 'exact_bdo_id'; });
    if(autoConfirmable.length > 0){
        autoConfirmExactMatches(autoConfirmable);
    }

    // Compute unmatched (not in any suggestion)
    const suggestedSlipIds = new Set(_matchSuggestions.map(function(m){ return m.slip.id || m.slip.slip_id; }));
    const suggestedBdoIds  = new Set(_matchSuggestions.map(function(m){ return m.bdo.bdo_id || m.bdo.id; }));
    const unmatchedSlips = pendingSlips.filter(function(s){ return !suggestedSlipIds.has(s.id || s.slip_id); });
    const unmatchedBdos  = pendingBdos.filter(function(b){ return !suggestedBdoIds.has(b.bdo_id || b.id); });

    // Render 3 zones
    renderMatchSuggestions(filterMode === 'matched' ? [] : _matchSuggestions);
    renderMatchSlipList(filterMode === 'matched' ? _matchSlips : unmatchedSlips);
    renderMatchBdoList(filterMode === 'matched' ? _matchBdos : unmatchedBdos);
    renderMatchedTodayList();
}

function normalizeMatchCustomerRef(value){
    return String(value || '').trim().toUpperCase();
}

function getSlipCustomerName(slip){
    return slip.customer_name || slip.line_display_name || slip.display_name || slip.line_user_id || '-';
}

function getSlipCustomerRef(slip){
    return slip.customer_ref || slip.customer_code || slip.partner_ref || slip.ref || '';
}

function getBdoCustomerName(bdo){
    return bdo.customer_name || bdo.partner_name || bdo.patient_name || bdo.line_display_name || '-';
}

function getBdoCustomerRef(bdo){
    return bdo.customer_ref || bdo.customer_code || bdo.partner_ref || bdo.partner_id || bdo.customer_id || '';
}

function getCustomerDisplayLabel(name, ref){
    const safeName = String(name || '-').trim() || '-';
    const safeRef = String(ref || '').trim();
    return {
        name: safeName,
        ref: safeRef || '-'
    };
}

function getSelectedMatchCustomerState(){
    const slipRefs = [];
    const bdoRefs = [];

    _matchSelectedSlips.forEach(function(sid){
        const slip = _matchSlips.find(function(item){ return (item.id || item.slip_id) == sid; });
        if(slip){
            const ref = normalizeMatchCustomerRef(getSlipCustomerRef(slip));
            if(ref) slipRefs.push(ref);
        }
    });

    _matchSelectedBdos.forEach(function(bid){
        const bdo = _matchBdos.find(function(item){ return (item.bdo_id || item.id) == bid; });
        if(bdo){
            const ref = normalizeMatchCustomerRef(getBdoCustomerRef(bdo));
            if(ref) bdoRefs.push(ref);
        }
    });

    const uniqueSlipRefs = Array.from(new Set(slipRefs));
    const uniqueBdoRefs = Array.from(new Set(bdoRefs));
    const matched = uniqueSlipRefs.length === 1 && uniqueBdoRefs.length === 1 && uniqueSlipRefs[0] === uniqueBdoRefs[0];

    return {
        slipRefs: uniqueSlipRefs,
        bdoRefs: uniqueBdoRefs,
        matched: matched,
        customerRef: matched ? uniqueSlipRefs[0] : ''
    };
}

function renderMatchSlipList(slips){
    const el = document.getElementById('matchSlipList');
    if(!el) return;
    if(!slips.length){
        el.innerHTML = '<div style="text-align:center;padding:1.25rem;color:var(--gray-400);font-size:0.82rem;"><i class="bi bi-receipt" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;"></i>ไม่มีสลิปรอจับคู่</div>';
        return;
    }
    el.innerHTML = slips.map(function(slip){ return _renderMatchSlipCard(slip); }).join('');
}

function _renderMatchSlipCard(slip){
    const slipId = slip.id || slip.slip_id;
    const selected = _matchSelectedSlips.has(slipId);
    const suggestion = _getSuggestionForSlip(slipId);
    const amount = slip.amount != null ? '฿' + parseFloat(slip.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
    const customer = getCustomerDisplayLabel(getSlipCustomerName(slip), getSlipCustomerRef(slip));
    const slipDate = fmtThDate(slip.transfer_date || slip.uploaded_at);
    const borderColor = selected ? '#16a34a' : (suggestion ? '#1d4ed8' : 'var(--gray-200)');
    const bgColor = selected ? '#f0fdf4' : (suggestion ? '#eff6ff' : 'white');
    const thumb = slip.image_full_url
        ? '<img src="' + escapeHtml(slip.image_full_url) + '" onclick="event.stopPropagation();openSlipPreview(\'' + escapeHtml(slip.image_full_url) + '\')" style="width:42px;height:52px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);flex-shrink:0;cursor:pointer;" onerror="this.style.display=\'none\'">'
        : '<div style="width:42px;height:52px;background:var(--gray-100);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>';
    const badge = suggestion ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:0.65rem;font-weight:600;padding:2px 7px;border-radius:999px;">แนะนำ</span>' : '';

    return '<div onclick="toggleMatchSlip(' + slipId + ')" style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.7rem;border:1.5px solid ' + borderColor + ';background:' + bgColor + ';border-radius:12px;cursor:pointer;margin-bottom:0.55rem;">'
        + '<input type="checkbox" ' + (selected ? 'checked' : '') + ' onclick="event.stopPropagation();toggleMatchSlip(' + slipId + ')" style="margin-top:0.2rem;">'
        + thumb
        + '<div style="flex:1;min-width:0;">'
        + '<div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">'
        + '<div style="font-weight:700;font-size:0.95rem;color:#16a34a;">' + amount + '</div>'
        + badge
        + '</div>'
        + '<div style="font-size:0.8rem;color:var(--gray-800);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(customer.name) + '</div>'
        + '<div style="font-size:0.72rem;color:var(--gray-500);font-weight:500;">' + escapeHtml(customer.ref) + '</div>'
        + '<div style="font-size:0.68rem;color:var(--gray-400);">' + slipDate + '</div>'
        + '</div>'
        + '</div>';
}

function renderMatchBdoList(bdos){
    const el = document.getElementById('matchBdoList');
    if(!el) return;
    if(!bdos.length){
        el.innerHTML = '<div style="text-align:center;padding:1.25rem;color:var(--gray-400);font-size:0.82rem;"><i class="bi bi-file-earmark-text" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;"></i>ไม่มี BDO ที่รอชำระ</div>';
        return;
    }
    el.innerHTML = bdos.map(function(bdo){ return _renderMatchBdoCard(bdo); }).join('');
}

function _renderMatchBdoCard(bdo){
    const bdoId = bdo.bdo_id || bdo.id;
    const selected = _matchSelectedBdos.has(bdoId);
    const suggestion = _getSuggestionForBdo(bdoId);
    const amountNum = bdo.amount_net_to_pay != null ? bdo.amount_net_to_pay : bdo.amount_total;
    const amount = amountNum != null ? '฿' + Number(amountNum).toLocaleString() : '-';
    const customer = getCustomerDisplayLabel(getBdoCustomerName(bdo), getBdoCustomerRef(bdo));
    const bdoName = bdo.bdo_name || ('BDO-' + bdoId);
    const bdoDate = fmtThDate(bdo.bdo_date || bdo.updated_at || bdo.synced_at);
    const borderColor = selected ? '#d97706' : (suggestion ? '#1d4ed8' : 'var(--gray-200)');
    const bgColor = selected ? '#fff7ed' : (suggestion ? '#eff6ff' : 'white');
    const badge = suggestion ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:0.65rem;font-weight:600;padding:2px 7px;border-radius:999px;">แนะนำ</span>' : '';

    const rawBdoEnc = encodeURIComponent(JSON.stringify(bdo));
    return '<div onclick="toggleMatchBdo(' + bdoId + ')" style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.7rem;border:1.5px solid ' + borderColor + ';background:' + bgColor + ';border-radius:12px;cursor:pointer;margin-bottom:0.55rem;">'
        + '<input type="checkbox" ' + (selected ? 'checked' : '') + ' onclick="event.stopPropagation();toggleMatchBdo(' + bdoId + ')" style="margin-top:0.2rem;">'
        + '<div style="flex:1;min-width:0;">'
        + '<div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">'
        + '<a href="javascript:void(0)" onclick="event.stopPropagation();openBdoDetail(\'' + escapeHtml(String(bdoId)) + '\',\'' + escapeHtml(String(bdoName)) + '\',decodeURIComponent(\'' + rawBdoEnc + '\'))" class="ref-link" style="font-weight:700;font-size:0.88rem;">' + escapeHtml(bdoName) + '</a>'
        + badge
        + '</div>'
        + '<div style="font-weight:700;font-size:0.95rem;color:#d97706;">' + amount + '</div>'
        + '<div style="font-size:0.8rem;color:var(--gray-800);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(customer.name) + '</div>'
        + '<div style="font-size:0.72rem;color:var(--gray-500);font-weight:500;">' + escapeHtml(customer.ref) + '</div>'
        + '<div style="font-size:0.68rem;color:var(--gray-400);">' + escapeHtml(bdo.order_name||'-') + ' · ' + bdoDate + '</div>'
        + '</div>'
        + '</div>';
}

function renderMatchedTodayList(){
    const el = document.getElementById('matchMatchedTodayList');
    if(!el) return;
    if(!_matchMatchedToday.length){
        el.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--gray-400);font-size:0.8rem;">ยังไม่มีรายการจับคู่สำเร็จวันนี้</div>';
        return;
    }
    el.innerHTML = _matchMatchedToday.map(function(slip){
        const amount = slip.amount != null ? '฿' + parseFloat(slip.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
        const customer = getCustomerDisplayLabel(getSlipCustomerName(slip), getSlipCustomerRef(slip));
        const matchedAt = fmtThDate(slip.matched_at || slip.updated_at || slip.uploaded_at);
        const slipId = slip.odoo_slip_id || slip.id || slip.slip_id;
        return '<div style="display:flex;align-items:center;justify-content:space-between;gap:0.6rem;padding:0.65rem 0;border-bottom:1px solid var(--gray-100);">'
            + '<div style="min-width:0;flex:1;">'
            + '<div style="font-size:0.82rem;font-weight:600;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(customer.name) + '</div>'
            + '<div style="font-size:0.72rem;color:var(--gray-500);">' + escapeHtml(customer.ref) + ' · ' + matchedAt + '</div>'
            + '</div>'
            + '<div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">'
            + '<div style="font-weight:700;color:#16a34a;font-size:0.86rem;">' + amount + '</div>'
            + '<button onclick="unmatchFromDashboard(' + slipId + ')" style="background:var(--gray-100);color:var(--gray-600);border:none;border-radius:8px;padding:4px 10px;font-size:0.74rem;cursor:pointer;font-family:inherit;"><i class="bi bi-x-circle"></i> ยกเลิก</button>'
            + '</div>'
            + '</div>';
    }).join('');
}

function toggleMatchSlip(slipId){
    if(_matchSelectedSlips.has(slipId)) _matchSelectedSlips.delete(slipId);
    else _matchSelectedSlips.add(slipId);
    renderMatchSlipList(_matchSlips.filter(function(s){
        return document.getElementById('matchFilterMode')?.value === 'matched'
            ? true
            : (s.status === 'pending' || s.status === 'new') && !_getSuggestionForSlip(s.id || s.slip_id);
    }));
    updateMatchSummaryBar();
}

function toggleMatchBdo(bdoId){
    if(_matchSelectedBdos.has(bdoId)) _matchSelectedBdos.delete(bdoId);
    else _matchSelectedBdos.add(bdoId);
    renderMatchBdoList(_matchBdos.filter(function(b){
        return document.getElementById('matchFilterMode')?.value === 'matched'
            ? true
            : (function(){
                return isBdoOutstandingPayment(b) && !_getSuggestionForBdo(b.bdo_id || b.id);
            })();
    }));
    updateMatchSummaryBar();
}

function updateMatchSummaryBar(){
    const bar = document.getElementById('matchSummaryBar');
    const textEl = document.getElementById('matchSummaryText');
    const confirmBtn = document.getElementById('matchConfirmBtn');
    if(!bar || !textEl) return;

    const selectedSlips = Array.from(_matchSelectedSlips).map(function(sid){
        return _matchSlips.find(function(item){ return (item.id || item.slip_id) == sid; });
    }).filter(Boolean);
    const selectedBdos = Array.from(_matchSelectedBdos).map(function(bid){
        return _matchBdos.find(function(item){ return (item.bdo_id || item.id) == bid; });
    }).filter(Boolean);

    if(!selectedSlips.length && !selectedBdos.length){
        bar.style.display = 'none';
        if(confirmBtn) confirmBtn.disabled = true;
        return;
    }

    const slipTotal = selectedSlips.reduce(function(sum, slip){ return sum + parseFloat(slip.amount || 0); }, 0);
    const bdoTotal = selectedBdos.reduce(function(sum, bdo){ return sum + parseFloat(bdo.amount_net_to_pay || bdo.amount_total || 0); }, 0);
    const diff = slipTotal - bdoTotal;
    const customerState = getSelectedMatchCustomerState();
    const customerText = customerState.matched
        ? ('ลูกค้า: ' + customerState.customerRef)
        : 'เลือกได้เฉพาะ customer_ref เดียวกัน';

    textEl.innerHTML = 'เลือกสลิป <strong>' + selectedSlips.length + '</strong> รายการ (฿' + slipTotal.toLocaleString() + ') '
        + 'จับคู่กับ BDO <strong>' + selectedBdos.length + '</strong> รายการ (฿' + bdoTotal.toLocaleString() + ') '
        + '<span style="color:' + (diff === 0 ? '#16a34a' : '#d97706') + ';font-weight:700;">ผลต่าง ฿' + Math.abs(diff).toLocaleString() + '</span>'
        + '<span style="margin-left:0.6rem;color:' + (customerState.matched ? '#16a34a' : '#dc2626') + ';font-weight:600;">' + escapeHtml(customerText) + '</span>';
    bar.style.display = 'flex';
    if(confirmBtn) confirmBtn.disabled = !selectedSlips.length || !selectedBdos.length || !customerState.matched;
}

function clearMatchSelection(){
    _matchSelectedSlips.clear();
    _matchSelectedBdos.clear();
    loadMatchingDashboard();
}

async function confirmSuggestion(slipId, bdoId, btnEl){
    const match = _matchSuggestions.find(function(item){
        return (item.slip.id || item.slip.slip_id) == slipId && (item.bdo.bdo_id || item.bdo.id) == bdoId;
    });
    if(!match) return;
    if(btnEl){
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';
    }
    try{
        const result = await whApiCall({
            action: 'odoo_slip_match_api',
            local_slip_id: match.slip.id || match.slip.slip_id,
            slip_inbox_id: match.slip.slip_inbox_id || match.slip.odoo_slip_id || 0,
            line_user_id: match.slip.line_user_id || '',
            matches: [{bdo_id: match.bdo.bdo_id || match.bdo.id, amount: parseFloat(match.bdo.amount_net_to_pay || match.bdo.amount_total || 0)}],
            note: 'Suggestion confirm: ' + match.confidence
        });
        if(result && result.success){
            loadMatchingDashboard();
        } else {
            throw new Error((result && result.error) || 'จับคู่ไม่สำเร็จ');
        }
    } catch(e){
        alert('❌ ' + e.message);
        if(btnEl){
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยัน';
        }
    }
}

function dismissSuggestion(idx){
    _matchSuggestions.splice(idx, 1);
    renderMatchSuggestions(_matchSuggestions);
}

function computeSmartMatches(slips, bdos){
    const suggestions = [];
    const usedSlips = new Set();
    const usedBdos = new Set();
    const pendingSlips = slips.filter(function(s){ return s.status === 'pending' || s.status === 'new'; });
    const pendingBdos = bdos.filter(function(b){
        const paymentState = normalizeBdoPaymentStatus(b);
        return paymentState.key === 'pending';
    });

    // Priority 1: bdo_id direct match
    pendingSlips.forEach(function(slip, si){
        if(usedSlips.has(si)) return;
        if(!slip.bdo_id) return;
        const slipRef = normalizeMatchCustomerRef(getSlipCustomerRef(slip));
        if(!slipRef) return;
        pendingBdos.forEach(function(bdo, bi){
            if(usedBdos.has(bi)) return;
            const bdoRef = normalizeMatchCustomerRef(getBdoCustomerRef(bdo));
            if(!bdoRef || slipRef !== bdoRef) return;
            const bdoId = bdo.bdo_id || bdo.id;
            if(String(slip.bdo_id) === String(bdoId)){
                const slipAmt = parseFloat(slip.amount || 0);
                const bdoAmt = parseFloat(bdo.amount_net_to_pay || bdo.amount_total || 0);
                const isExact = Math.abs(slipAmt - bdoAmt) <= 1;
                suggestions.push({
                    slip: slip, slipIdx: si, bdo: bdo, bdoIdx: bi,
                    confidence: isExact ? 'exact_bdo_id' : 'bdo_id_amount_diff',
                    diff: slipAmt - bdoAmt,
                    label: isExact ? '✅ bdo_id + ยอดตรง' : '⚠️ bdo_id ตรง แต่ยอดต่าง ฿' + (slipAmt - bdoAmt).toLocaleString()
                });
                usedSlips.add(si); usedBdos.add(bi);
            }
        });
    });

    // Priority 2: exact amount match (only when unique to reduce false positives)
    pendingSlips.forEach(function(slip, si){
        if(usedSlips.has(si)) return;
        const slipRef = normalizeMatchCustomerRef(getSlipCustomerRef(slip));
        if(!slipRef) return;
        const slipAmt = parseFloat(slip.amount || 0);
        if(slipAmt <= 0) return;
        const candidates = [];
        pendingBdos.forEach(function(bdo, bi){
            if(usedBdos.has(bi)) return;
            const bdoRef = normalizeMatchCustomerRef(getBdoCustomerRef(bdo));
            if(!bdoRef || slipRef !== bdoRef) return;
            const bdoAmt = parseFloat(bdo.amount_net_to_pay || bdo.amount_total || 0);
            if(Math.abs(slipAmt - bdoAmt) <= 1){
                candidates.push({bdo:bdo, bi:bi});
            }
        });
        if(candidates.length === 1){
            suggestions.push({
                slip: slip, slipIdx: si, bdo: candidates[0].bdo, bdoIdx: candidates[0].bi,
                confidence: 'exact_amount',
                diff: 0,
                label: '💰 ยอดตรงเป๊ะ'
            });
            usedSlips.add(si);
            usedBdos.add(candidates[0].bi);
        }
    });

    return suggestions;
}

function _getSuggestionForSlip(slipId){
    return _matchSuggestions.find(function(m){ return (m.slip.id || m.slip.slip_id) == slipId; });
}

function _getSuggestionForBdo(bdoId){
    return _matchSuggestions.find(function(m){ return (m.bdo.bdo_id || m.bdo.id) == bdoId; });
}

function renderMatchSuggestions(suggestions){
    const el = document.getElementById('matchSuggestedList');
    if(!el) return;
    if(!suggestions.length){
        el.innerHTML = '<div style="text-align:center;padding:1.25rem;color:var(--gray-400);font-size:0.82rem;"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:0.3rem;"></i>ระบบยังไม่พบคู่ที่แนะนำได้ — เลือกสลิปและ BDO ด้านล่างเพื่อจับคู่เอง</div>';
        return;
    }
    const confColors = {
        exact_bdo_id: {bg:'#dcfce7',clr:'#16a34a',icon:'✅',lbl:'bdo_id + ยอดตรง'},
        exact_amount:  {bg:'#dbeafe',clr:'#1d4ed8',icon:'💰',lbl:'ยอดตรงเป๊ะ'},
        bdo_id_amount_diff: {bg:'#fef9c3',clr:'#92400e',icon:'⚠️',lbl:'bdo_id ตรง แต่ยอดต่าง'}
    };
    let html = '<div style="display:flex;flex-direction:column;gap:0.6rem;">';
    suggestions.forEach(function(m, idx){
        const s = m.slip;
        const b = m.bdo;
        const sid = s.id || s.slip_id;
        const bid = b.bdo_id || b.id;
        const slipAmt = s.amount != null ? '฿' + parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
        const bdoAmtNum = b.amount_net_to_pay != null ? b.amount_net_to_pay : b.amount_total;
        const bdoAmt = bdoAmtNum != null ? '฿' + Number(bdoAmtNum).toLocaleString() : '-';
        const slipCust = getCustomerDisplayLabel(getSlipCustomerName(s), getSlipCustomerRef(s));
        const bdoCust = getCustomerDisplayLabel(getBdoCustomerName(b), getBdoCustomerRef(b));
        const slipDt = fmtThDate(s.transfer_date || s.uploaded_at);
        const bdoDt  = fmtThDate(b.bdo_date || b.updated_at || b.synced_at);
        const bdoName = b.bdo_name || ('BDO-' + bid);
        const conf = confColors[m.confidence] || confColors.bdo_id_amount_diff;
        const diffStr = m.diff !== 0 ? ' (ต่าง ฿' + Math.abs(m.diff).toLocaleString() + ')' : '';
        const thumb = s.image_full_url
            ? '<img src="' + escapeHtml(s.image_full_url) + '" onclick="event.stopPropagation();openSlipPreview(\'' + escapeHtml(s.image_full_url) + '\')" style="width:44px;height:54px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid var(--gray-200);flex-shrink:0;" onerror="this.style.display=\'none\'">'
            : '<div style="width:44px;height:54px;background:var(--gray-100);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>';
        html += '<div style="display:flex;align-items:center;gap:0.75rem;background:white;border:1.5px solid ' + conf.clr + '33;border-radius:12px;padding:0.65rem 0.75rem;transition:box-shadow 0.15s;" onmouseenter="this.style.boxShadow=\'0 2px 12px ' + conf.clr + '22\'" onmouseleave="this.style.boxShadow=\'\'">';
        html += '<div style="display:flex;align-items:center;gap:0.5rem;flex:1;min-width:0;">';
        html += thumb;
        html += '<div style="min-width:0;">';
        html += '<div style="font-weight:700;font-size:0.95rem;color:#16a34a;">' + slipAmt + '</div>';
        html += '<div style="font-size:0.78rem;color:var(--gray-700);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(slipCust.name) + '</div>';
        html += '<div style="font-size:0.72rem;color:var(--gray-500);font-weight:500;">' + escapeHtml(slipCust.ref) + '</div>';
        html += '<div style="font-size:0.68rem;color:var(--gray-400);">' + slipDt + '</div>';
        html += '</div></div>';
        html += '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;flex-shrink:0;">';
        html += '<div style="font-size:1.1rem;color:' + conf.clr + ';">→</div>';
        html += '<div style="background:' + conf.bg + ';color:' + conf.clr + ';font-size:0.65rem;font-weight:600;padding:2px 7px;border-radius:50px;white-space:nowrap;">' + conf.icon + ' ' + conf.lbl + diffStr + '</div>';
        html += '</div>';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-weight:700;font-size:0.88rem;color:var(--gray-800);">' + escapeHtml(bdoName) + '</div>';
        html += '<div style="font-weight:700;font-size:0.95rem;color:#d97706;">' + bdoAmt + '</div>';
        html += '<div style="font-size:0.78rem;color:var(--gray-700);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(bdoCust.name) + '</div>';
        html += '<div style="font-size:0.72rem;color:var(--gray-500);font-weight:500;">' + escapeHtml(bdoCust.ref) + '</div>';
        html += '<div style="font-size:0.68rem;color:var(--gray-400);">' + escapeHtml(bdo.order_name||'-') + ' · ' + bdoDt + '</div>';
        html += '</div>';
        html += '<div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">';
        html += '<button onclick="confirmSuggestion(' + sid + ',' + bid + ',this)" style="background:linear-gradient(135deg,#16a34a,#059669);color:white;border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;white-space:nowrap;"><i class="bi bi-check2-circle"></i> ยืนยัน</button>';
        html += '<button onclick="dismissSuggestion(' + idx + ',this)" style="background:var(--gray-100);color:var(--gray-600);border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;white-space:nowrap;"><i class="bi bi-x"></i> ข้าม</button>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
}

async function confirmManualMatch(){
    if(_matchSelectedSlips.size === 0 || _matchSelectedBdos.size === 0){
        alert('กรุณาเลือกสลิปและ BDO อย่างน้อยอย่างละ 1 รายการ');
        return;
    }
    const note = document.getElementById('matchNote')?.value || '';
    const btn = document.getElementById('matchConfirmBtn');
    if(btn){btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';}

    const selectedSlips = [];
    _matchSelectedSlips.forEach(function(sid){
        const s = _matchSlips.find(function(x){ return (x.id || x.slip_id) == sid; });
        if(s) selectedSlips.push(s);
    });
    const selectedBdos = [];
    _matchSelectedBdos.forEach(function(bid){
        const b = _matchBdos.find(function(x){ return (x.bdo_id || x.id) == bid; });
        if(b) selectedBdos.push(b);
    });

    const customerState = getSelectedMatchCustomerState();
    if(!customerState.matched){
        if(btn){btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';}
        alert('จับคู่ได้เฉพาะรายการที่มี customer_ref ตรงกันเท่านั้น');
        return;
    }

    let successCount = 0, failCount = 0;
    for(const slip of selectedSlips){
        const matches = selectedBdos.map(function(bdo){
            return {bdo_id: bdo.bdo_id || bdo.id, amount: parseFloat(bdo.amount_net_to_pay || bdo.amount_total || 0)};
        });
        try {
            const result = await whApiCall({
                action: 'odoo_slip_match_api',
                local_slip_id: slip.id || slip.slip_id,
                slip_inbox_id: slip.slip_inbox_id || slip.odoo_slip_id || 0,
                line_user_id: slip.line_user_id || '',
                matches: matches,
                note: note
            });
            if(result && result.success) successCount++;
            else failCount++;
        } catch(e){ failCount++; }
    }

    if(btn){btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';}
    const noteEl = document.getElementById('matchNote');
    if(noteEl) noteEl.value = '';

    if(successCount > 0){
        alert('✅ จับคู่สำเร็จ ' + successCount + ' รายการ' + (failCount > 0 ? '\n❌ ล้มเหลว ' + failCount + ' รายการ' : ''));
    } else {
        alert('❌ จับคู่ไม่สำเร็จ');
    }
    loadMatchingDashboard();
}

async function batchConfirmMatches(){
    if(!_matchSuggestions.length){
        alert('ไม่มีคู่ที่แนะนำ');
        return;
    }
    // Filter only non-exact (exact already auto-confirmed)
    const toConfirm = _matchSuggestions.filter(function(m){ return m.confidence !== 'exact_bdo_id'; });
    if(!toConfirm.length){
        alert('คู่ที่ exact ถูก auto-confirm ไปแล้ว ไม่มีรายการเพิ่มเติม');
        return;
    }
    if(!confirm('ยืนยันจับคู่ทั้งหมด ' + toConfirm.length + ' คู่ ที่ระบบแนะนำ?')) return;

    const btn = document.getElementById('matchBatchConfirmBtn');
    if(btn){btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';}

    let successCount = 0, failCount = 0;
    for(const m of toConfirm){
        try{
            const result = await whApiCall({
                action: 'odoo_slip_match_api',
                local_slip_id: m.slip.id || m.slip.slip_id,
                slip_inbox_id: m.slip.slip_inbox_id || m.slip.odoo_slip_id || 0,
                line_user_id: m.slip.line_user_id || '',
                matches: [{bdo_id: m.bdo.bdo_id || m.bdo.id, amount: parseFloat(m.bdo.amount_net_to_pay || m.bdo.amount_total || 0)}],
                note: 'Batch match: ' + m.confidence
            });
            if(result && result.success) successCount++;
            else failCount++;
        } catch(e){ failCount++; }
    }

    if(btn){btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-all"></i> ยืนยันที่แนะนำทั้งหมด';}
    alert('✅ จับคู่สำเร็จ ' + successCount + ' รายการ' + (failCount > 0 ? '\n❌ ล้มเหลว ' + failCount + ' รายการ' : ''));
    loadMatchingDashboard();
}

async function autoConfirmExactMatches(exactList){
    let count = 0;
    for(const m of exactList){
        try{
            const result = await whApiCall({
                action: 'odoo_slip_match_api',
                local_slip_id: m.slip.id || m.slip.slip_id,
                slip_inbox_id: m.slip.slip_inbox_id || m.slip.odoo_slip_id || 0,
                line_user_id: m.slip.line_user_id || '',
                matches: [{bdo_id: m.bdo.bdo_id || m.bdo.id, amount: parseFloat(m.bdo.amount_net_to_pay || m.bdo.amount_total || 0)}],
                note: 'Auto-confirm: bdo_id + exact amount'
            });
            if(result && result.success) count++;
        } catch(e){}
    }
    if(count > 0){
        // Show toast notification
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:20px;right:20px;background:#16a34a;color:white;padding:12px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:9999;font-size:0.88rem;';
        toast.innerHTML = '<i class="bi bi-check-circle"></i> Auto-match สำเร็จ ' + count + ' คู่ (bdo_id + ยอดตรง)';
        document.body.appendChild(toast);
        setTimeout(function(){ toast.remove(); }, 4000);
    }
}

async function unmatchFromDashboard(slipInboxId){
    if(!confirm('ยกเลิกการจับคู่สลิปนี้ ใช่ไหม?')) return;
    try{
        const result = await whApiCall({
            action: 'odoo_slip_unmatch_api',
            slip_inbox_id: slipInboxId,
            reason: 'ยกเลิกจาก Matching Dashboard'
        });
        if(result && result.success){
            alert('✅ ยกเลิกการจับคู่เรียบร้อยแล้ว');
            loadMatchingDashboard();
        } else {
            alert('❌ ' + ((result && result.error) || 'เกิดข้อผิดพลาด'));
        }
    } catch(e){
        alert('❌ Network error: ' + e.message);
    }
}

// ===== BDO SLIP ATTACH MODAL =====
let _bsaBdoData=null, _bsaSlips=[], _bsaSelectedSlipId=null, _bsaFileBase64=null;

async function openBdoSlipAttach(bdoData, pendingSlips){
    const paymentState = normalizeBdoPaymentStatus(bdoData || {});
    if(paymentState.key === 'paid'){
        alert('BDO นี้ชำระแล้ว จึงปิดการแนบสลิป');
        return;
    }
    _bsaBdoData=bdoData;
    _bsaSlips=pendingSlips||[];
    _bsaSelectedSlipId=null;
    _bsaFileBase64=null;
    document.getElementById('bsaBdoName').textContent=bdoData.bdo_name||('BDO-'+bdoData.bdo_id);
    document.getElementById('bsaOrderName').textContent=bdoData.order_name||'-';
    const finalAmt = bdoData.amount_net_to_pay != null ? bdoData.amount_net_to_pay : bdoData.amount_total;
    document.getElementById('bsaAmount').textContent=finalAmt!=null?'\u0e3f'+Number(finalAmt).toLocaleString():'-';
    document.getElementById('bsaAmountInput').value=finalAmt||'';
    document.getElementById('bsaDateInput').value=new Date().toISOString().slice(0,10);
    document.getElementById('bsaPreview').style.display='none';
    document.getElementById('bsaFileInput').value='';
    document.getElementById('bsaConfirmBtn').disabled=true;

    // If no pending slips passed, try to fetch customer's uploaded slips from API
    if(!_bsaSlips.length && (bdoData.partner_id || bdoData.customer_ref || bdoData.line_user_id)){
        const grid=document.getElementById('bsaUnmatchedSlips');
        grid.innerHTML='<span style="color:var(--gray-400);font-size:0.8rem;grid-column:1/-1;text-align:center;padding:1rem;"><i class="bi bi-arrow-repeat spin"></i> กำลังค้นหาสลิปที่ลูกค้าอัพโหลด...</span>';
        document.getElementById('bdoSlipAttachModal').classList.add('active');
        try{
            const slipRes = await whApiCall({
                action:'odoo_slips',
                partner_id: bdoData.partner_id||'',
                customer_ref: bdoData.customer_ref||'',
                status:'pending',
                limit:50
            });
            if(slipRes && slipRes.success && slipRes.data && slipRes.data.slips){
                _bsaSlips = slipRes.data.slips.filter(function(s){return s.status==='pending';});
            }
        }catch(e){
            // Silently ignore — grid will show empty state
        }
        _renderBsaSlipGrid();
        return; // modal already shown above
    }

    // Render unmatched slips grid
    _renderBsaSlipGrid();
    document.getElementById('bdoSlipAttachModal').classList.add('active');
}

function _renderBsaSlipGrid(){
    const grid=document.getElementById('bsaUnmatchedSlips');
    if(!grid)return;
    if(!_bsaSlips.length){
        grid.innerHTML='<span style="color:var(--gray-400);font-size:0.8rem;grid-column:1/-1;text-align:center;padding:1rem;">ไม่มีสลิปที่รอจับคู่ — สามารถอัพโหลดรูปสลิปใหม่ได้ด้านล่าง</span>';
    } else {
        let gh='<div style="grid-column:1/-1;font-size:0.75rem;color:var(--gray-500);margin-bottom:4px;">พบ '+_bsaSlips.length+' สลิปที่รอจับคู่ — เลือกสลิปด้านล่างเพื่อแนบกับ BDO</div>';
        _bsaSlips.forEach(function(s){
            const thumb=s.image_full_url||'';
            const amt=s.amount!=null?'\u0e3f'+Number(s.amount).toLocaleString():'';
            const dateStr=s.transfer_date?s.transfer_date.slice(0,10):'';
            gh+='<div id="bsaSlip'+s.id+'" onclick="bsaSelectSlip('+s.id+')" style="cursor:pointer;border:2px solid var(--gray-200);border-radius:8px;overflow:hidden;transition:all 0.15s;text-align:center;">';
            if(thumb){
                gh+='<img src="'+escapeHtml(thumb)+'" style="width:100%;height:70px;object-fit:cover;" onerror="this.style.display=\'none\'">';
            } else {
                gh+='<div style="width:100%;height:70px;background:var(--gray-100);border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-image" style="color:var(--gray-400);font-size:1.2rem;"></i></div>';
            }
            gh+='<div style="font-size:0.7rem;padding:3px;color:var(--gray-600);">'+amt+(dateStr?' · '+dateStr:'')+'</div>';
            gh+='</div>';
        });
        grid.innerHTML=gh;
    }
}

function closeBdoSlipAttach(){
    document.getElementById('bdoSlipAttachModal').classList.remove('active');
    _bsaBdoData=null;_bsaSlips=[];_bsaSelectedSlipId=null;_bsaFileBase64=null;
}

function bsaSelectSlip(slipId){
    _bsaSelectedSlipId=slipId;
    _bsaFileBase64=null;
    document.getElementById('bsaPreview').style.display='none';
    document.getElementById('bsaFileInput').value='';
    // Highlight selected
    _bsaSlips.forEach(function(s){
        const el=document.getElementById('bsaSlip'+s.id);
        if(el) el.style.borderColor=s.id===slipId?'#059669':'var(--gray-200)';
    });
    // Auto-fill amount from slip
    const slip=_bsaSlips.find(function(s){return s.id===slipId;});
    if(slip&&slip.amount) document.getElementById('bsaAmountInput').value=slip.amount;
    document.getElementById('bsaConfirmBtn').disabled=false;
}

function bsaHandleFileSelect(evt){
    const file=evt.target.files&&evt.target.files[0];
    if(!file||!file.type.startsWith('image/'))return;
    _bsaSelectedSlipId=null;
    _bsaSlips.forEach(function(s){
        const el=document.getElementById('bsaSlip'+s.id);
        if(el) el.style.borderColor='var(--gray-200)';
    });
    const reader=new FileReader();
    reader.onload=function(e){
        _bsaFileBase64=e.target.result;
        document.getElementById('bsaPreviewImg').src=_bsaFileBase64;
        document.getElementById('bsaPreview').style.display='block';
        document.getElementById('bsaConfirmBtn').disabled=false;
    };
    reader.readAsDataURL(file);
}

function bsaClearPreview(){
    _bsaFileBase64=null;
    document.getElementById('bsaPreview').style.display='none';
    document.getElementById('bsaFileInput').value='';
    if(!_bsaSelectedSlipId) document.getElementById('bsaConfirmBtn').disabled=true;
}

async function bsaConfirmAttach(){
    if(!_bsaBdoData)return;
    const paymentState = normalizeBdoPaymentStatus(_bsaBdoData || {});
    if(paymentState.key === 'paid'){
        alert('BDO นี้ชำระแล้ว จึงไม่สามารถแนบสลิปเพิ่มได้');
        closeBdoSlipAttach();
        return;
    }
    const btn=document.getElementById('bsaConfirmBtn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';
    try{
        const amount=parseFloat(document.getElementById('bsaAmountInput').value)||null;
        const transferDate=document.getElementById('bsaDateInput').value||null;
        if(_bsaSelectedSlipId){
            const slip = _bsaSlips.find(function(s){ return s.id === _bsaSelectedSlipId; });
            const j=await whApiCall({
                action:'odoo_slip_match_api',
                local_slip_id:_bsaSelectedSlipId,
                slip_inbox_id: slip ? (slip.slip_inbox_id || slip.odoo_slip_id || 0) : 0,
                line_user_id: slip ? (slip.line_user_id || _bsaBdoData.line_user_id || '') : (_bsaBdoData.line_user_id || ''),
                matches:[{bdo_id:_bsaBdoData.bdo_id,amount:amount || parseFloat(_bsaBdoData.amount_net_to_pay || _bsaBdoData.amount_total || 0)}],
                note:'Attach slip from BDO modal'
            });
            if(!j.success) throw new Error(j.error||'\u0e08\u0e31\u0e1a\u0e04\u0e39\u0e48\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08');
        } else if(_bsaFileBase64){
            // Upload new file + attach to BDO
            const r=await fetch('api/odoo-slip-upload.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
                line_user_id:_bsaBdoData.line_user_id || '_dashboard_upload_',
                image_base64:_bsaFileBase64.replace(/^data:image\/\w+;base64,/,''),
                bdo_id:_bsaBdoData.bdo_id,
                amount:amount,
                transfer_date:transferDate,
                uploaded_by:'dashboard',
                skip_line_notify:true
            })});
            const j=await r.json();
            if(!j.success) throw new Error(j.error||'\u0e2d\u0e31\u0e1e\u0e42\u0e2b\u0e25\u0e14\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08');
            const localSlipId = j.data && j.data.id ? j.data.id : 0;
            const matchResult = await whApiCall({
                action:'odoo_slip_match_api',
                local_slip_id: localSlipId,
                line_user_id: j.data?.line_user_id || _bsaBdoData.line_user_id || '',
                matches:[{bdo_id:_bsaBdoData.bdo_id,amount:amount || parseFloat(_bsaBdoData.amount_net_to_pay || _bsaBdoData.amount_total || 0)}],
                note:'Upload and attach from BDO modal'
            });
            if(!matchResult.success) throw new Error(matchResult.error||'\u0e08\u0e31\u0e1a\u0e04\u0e39\u0e48\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08');
        } else {
            throw new Error('ไม่มีข้อมูลสลิปหรือไฟล์สำหรับแนบ');
        }
        closeBdoSlipAttach();
        alert('✅ แนบสลิปเรียบร้อยแล้ว');
        // Refresh slips & customer detail if open
        if(typeof loadSlips==='function') loadSlips();
    }catch(e){
        alert('❌ '+e.message);
        btn.disabled=false;btn.innerHTML='<i class="bi bi-check2-circle"></i> แนบสลิป';
    }
}

// ===== UNMATCH BDO SLIP =====
async function unmatchBdoSlip(slipId, slipInboxId){
    if(!confirm('\u0e22\u0e01\u0e40\u0e25\u0e34\u0e01\u0e01\u0e32\u0e23\u0e08\u0e31\u0e1a\u0e04\u0e39\u0e48\u0e2a\u0e25\u0e34\u0e1b\u0e01\u0e31\u0e1a BDO \u0e19\u0e35\u0e49 \u0e43\u0e0a\u0e48\u0e44\u0e2b\u0e21?'))return;
    try{
        const localSlip = (_bsaSlips || []).find(function(s){ return s.id === slipId; }) || _matchSlips.find(function(s){ return (s.id || s.slip_id) == slipId; });
        const j=await whApiCall({
            action:'odoo_slip_unmatch_api',
            local_slip_id: slipId,
            slip_inbox_id: slipInboxId || (localSlip ? (localSlip.slip_inbox_id || localSlip.odoo_slip_id || 0) : 0),
            line_user_id: localSlip ? (localSlip.line_user_id || '') : '',
            reason:'ยกเลิกจาก BDO customer view'
        });
        if(j.success){
            alert('✅ ยกเลิกการจับคู่เรียบร้อยแล้ว');
            if(typeof loadSlips==='function') loadSlips();
        }else{
            alert('❌ '+(j.error||'เกิดข้อผิดพลาด'));
        }
    }catch(e){
        alert('❌ Network error: '+e.message);
    }
}

// ===== INIT =====
document.addEventListener('DOMContentLoaded',()=>{
    ensureDashboardModalRoots();
    restoreAdminMode();

    // Test API connectivity immediately (uses fast 'health' action, no DB query)
    testConnection().then(() => {
        // Warm critical caches after connection established
        setTimeout(warmCriticalCaches, 500);
    });

    const params = new URLSearchParams(window.location.search);
    const initialTab = (params.get('tab') || '').trim();
    // Pre-set date filter to today for flat list view
    const dfEl=document.getElementById('whFilterDateFrom');
    if(dfEl&&!dfEl.value)dfEl.value=new Date().toISOString().slice(0,10);
    // Pre-set grouped view date to today
    const gdEl=document.getElementById('grpDateInput');
    if(gdEl&&!gdEl.value)gdEl.value=new Date().toISOString().slice(0,10);
    if(initialTab){
        showSection(initialTab);
    }else{
        setTimeout(function(){ if(!_overviewLoaded && _isSectionActive('overview')) loadTodayOverview(); }, 250);
    }
    // Re-check connection every 60s — skip when tab is hidden
    setInterval(()=>{ if(!document.hidden) testConnection(); }, 60000);
    document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) testConnection(); });
    
    // Log page load performance
    window.addEventListener('load', () => {
        const perfData = performance.getEntriesByType('navigation')[0];
        if(perfData){
            console.log('[Perf] Page load:', {
                domContentLoaded: Math.round(perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart) + 'ms',
                loadComplete: Math.round(perfData.loadEventEnd - perfData.loadEventStart) + 'ms',
                total: Math.round(perfData.loadEventEnd - perfData.fetchStart) + 'ms'
            });
        }
    });
});
