// ===== Odoo Customer Detail Page JS =====
// Reads ?ref=&partner_id=&name= from URL

const WH_API_CANDIDATES=['api/odoo-webhooks-dashboard.php','/api/odoo-webhooks-dashboard.php','../api/odoo-webhooks-dashboard.php'];
let WH_API_ACTIVE=WH_API_CANDIDATES[0];

// URL params
const _params=new URLSearchParams(window.location.search);
const P_REF=_params.get('ref')||'';
const P_PID=_params.get('partner_id')||'';
const P_NAME=_params.get('name')||'';
const P_TAB=_params.get('tab')||'';

// State
let _allOrders=[], _allInvoices=[], _allSlips=[], _allBdos=[];
let _notesMap={}, _overridesMap={}; // entityRef → [items]
let _profileData=null, _creditData=null, _pointsData=null, _linkData=null;

// ===== UTILS =====
function escapeHtml(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// ===== SESSION CACHE (TTL-based, sessionStorage) =====
const _CACHE_TTL=180000; // 3 minutes
function _cacheSet(key,data,ttlMs){
    try{sessionStorage.setItem('_c:'+key,JSON.stringify({t:Date.now(),ttl:ttlMs||_CACHE_TTL,d:data}));}catch(e){}
}
function _cacheGet(key){
    try{
        const raw=sessionStorage.getItem('_c:'+key);
        if(!raw)return null;
        const item=JSON.parse(raw);
        if(Date.now()-item.t>(item.ttl||_CACHE_TTL)){sessionStorage.removeItem('_c:'+key);return null;}
        return item.d;
    }catch(e){return null;}
}
function _cacheClear(prefix){
    try{
        const p='_c:'+(prefix||'');
        Object.keys(sessionStorage).filter(function(k){return k.startsWith(p);}).forEach(function(k){sessionStorage.removeItem(k);});
    }catch(e){}
}

async function whApiCall(data){
    const endpoints=[WH_API_ACTIVE,...WH_API_CANDIDATES.filter(u=>u!==WH_API_ACTIVE)];
    for(const url of endpoints){
        try{
            const r=await fetch(url+'?_t='+Date.now(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
            const raw=await r.text();
            let p=null;try{p=JSON.parse(raw);}catch(_){}
            if(p&&typeof p==='object'&&'success' in p){WH_API_ACTIVE=url;return p;}
        }catch(e){}
    }
    return{success:false,error:'API unreachable'};
}

function fmtThDate(raw){
    if(!raw)return '-';
    const d=new Date(raw);
    if(isNaN(d))return String(raw).slice(0,10)||'-';
    return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit'});
}
function fmtThDateTime(raw){
    if(!raw)return '-';
    const d=new Date(raw);
    if(isNaN(d))return String(raw).slice(0,16)||'-';
    return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit'})+' '+d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'});
}
function fmtBaht(v){
    if(v==null||v===''||isNaN(v))return '-';
    return '฿'+Number(v).toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2});
}
function slipThumbHtml(slip){
    if(!slip||!slip.image_full_url)return '';
    return '<img class="slip-thumb" src="'+escapeHtml(slip.image_full_url)+'" onclick="openSlipPreview(\''+escapeHtml(slip.image_full_url)+'\')" onerror="this.style.display=\'none\'">';
}
function openSlipPreview(url){
    document.getElementById('slipPreviewImg').src=url;
    document.getElementById('slipPreviewModal').classList.add('show');
}
function closeModal(id){document.getElementById(id).classList.remove('show');}

// Status badge HTML
const ORDER_STATUSES={
    'เตรียมส่ง':'to_delivery','กำลังจัดส่ง':'packed','ส่งแล้ว':'done',
    'ยืนยัน':'confirmed','ขาย':'sale','ยกเลิก':'cancel','ร่าง':'draft','รอตรวจสอบ':'pending'
};
const INVOICE_STATUSES={
    'ค้างชำระ':'open','ชำระแล้ว':'paid','เกินกำหนด':'overdue','ยกเลิก':'cancel'
};

function stBadge(state, label){
    const cls='st-'+(state||'').toLowerCase().replace(/\s/g,'_');
    return '<span class="st '+cls+'">'+escapeHtml(label||state||'-')+'</span>';
}

// ===== SLIP MATCHING (reuse from dashboard) =====
function _findBestSlip(slips,used,iAmt,iDate,tolPct){
    tolPct=tolPct||0;let best=-1,bestS=Infinity;
    slips.forEach(function(s,si){
        if(used.has(si))return;
        const sA=parseFloat(s.amount||0);
        if(iAmt>0){if(Math.abs(sA-iAmt)>Math.max(1,iAmt*tolPct))return;}
        else if(sA>0)return;
        const sD=s.transfer_date?new Date(s.transfer_date):null;
        let dd=9999;
        if(iDate&&sD&&!isNaN(iDate)&&!isNaN(sD))dd=Math.abs((iDate-sD)/(864e5));
        if(dd<bestS){bestS=dd;best=si;}
    });
    return(best>=0&&bestS<=180)?best:-1;
}
function matchSlipsToItems(slips,items,getAmt,getDate){
    const used=new Set(),result=new Map();
    items.forEach(function(it,i){
        const a=parseFloat(getAmt(it)||0),d=getDate(it)?new Date(getDate(it)):null;
        const si=_findBestSlip(slips,used,a,d,0);
        if(si>=0){const sD=slips[si].transfer_date?new Date(slips[si].transfer_date):null;
            let dd=9999;if(d&&sD&&!isNaN(d)&&!isNaN(sD))dd=Math.abs((d-sD)/864e5);
            if(dd<=90){used.add(si);result.set(i,slips[si]);}
        }
    });
    items.forEach(function(it,i){
        if(result.has(i))return;
        const a=parseFloat(getAmt(it)||0),d=getDate(it)?new Date(getDate(it)):null;
        const si=_findBestSlip(slips,used,a,d,0.05);
        if(si>=0){used.add(si);result.set(i,slips[si]);}
    });
    return result;
}

// ===== TAB SWITCHING =====
function switchTab(name){
    document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active');});
    document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active');});
    const panel=document.getElementById('panel-'+name);
    if(panel)panel.classList.add('active');
    // Highlight button
    document.querySelectorAll('.tab-btn').forEach(function(b){
        if(b.textContent.trim().toLowerCase().indexOf(name)>=0||b.getAttribute('onclick').indexOf("'"+name+"'")>=0)b.classList.add('active');
    });
    // Lazy load
    if(name==='timeline'&&!_timelineLoaded)loadTimeline();
    if(name==='activity'&&!_activityLoaded)loadActivity();
    if(name==='matching') renderMatchingTab();
}

// ===== INIT =====
let _timelineLoaded=false,_activityLoaded=false;

document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('topTitle').textContent=P_NAME||'รายละเอียดลูกค้า';
    if(P_TAB) switchTab(P_TAB);
    loadAll();
});

const _CUST_CACHE_KEY='cust_'+P_REF+'_'+P_PID;

async function loadAll(forceRefresh){
    const pidParam=(P_PID&&P_PID!=='-')?P_PID:'';

    // --- Cache check (skip on force refresh) ---
    if(!forceRefresh){
        const cached=_cacheGet(_CUST_CACHE_KEY);
        if(cached){
            _restoreFromCache(cached);
            _showCustCacheIndicator(cached.cachedAt);
            return;
        }
    }

    // Parallel fetch: detail + orders + invoices + slips + notes
    const [detailRes,ordRes,invRes,slipRes,notesRes,bdoRes]=await Promise.all([
        whApiCall({action:'customer_detail',partner_id:pidParam,customer_ref:P_REF}),
        whApiCall({action:'odoo_orders',limit:100,offset:0,partner_id:pidParam,customer_ref:P_REF}),
        whApiCall({action:'odoo_invoices',limit:100,offset:0,partner_id:pidParam,customer_ref:P_REF}),
        whApiCall({action:'odoo_slips',partner_id:pidParam}),
        whApiCall({action:'order_notes_list',partner_id:pidParam}),
        whApiCall({action:'odoo_bdos',limit:100,offset:0,partner_id:pidParam,customer_ref:P_REF})
    ]);

    // Store detail data for re-rendering
    let _detailData=null;

    // Detail
    if(detailRes&&detailRes.success&&detailRes.data){
        _detailData=detailRes.data;
        _profileData=_detailData.profile;_creditData=_detailData.credit;_pointsData=_detailData.points;_linkData=_detailData.link;
        renderProfileCard(_detailData);
        renderSummaryCards(_detailData);
        renderProfileTab(_detailData);
    }

    // Slips
    _allSlips=(slipRes&&slipRes.success&&slipRes.data&&slipRes.data.slips)||[];

    // Notes & overrides
    if(notesRes&&notesRes.success&&notesRes.data){
        (notesRes.data.notes||[]).forEach(function(n){
            const k=n.entity_ref;if(!_notesMap[k])_notesMap[k]=[];_notesMap[k].push(n);
        });
        (notesRes.data.overrides||[]).forEach(function(o){
            const k=o.entity_ref;if(!_overridesMap[k])_overridesMap[k]=[];_overridesMap[k].push(o);
        });
    }

    // Build paid invoice lookup (by amount AND by order_name)
    _allInvoices=(invRes&&invRes.success?(invRes.data.invoices||[]):[]).slice().sort(function(a,b){
        return new Date(b.invoice_date||b.due_date||b.processed_at||0)-new Date(a.invoice_date||a.due_date||a.processed_at||0);
    });
    const paidInvByAmt=new Map();
    const paidInvByOrder=new Map();
    _allInvoices.forEach(function(inv){
        const isPaid=inv.is_paid||String(inv.invoice_state||'').toLowerCase()==='paid'||String(inv.latest_event||'')==='invoice.paid'||String(inv.payment_state||'').toLowerCase()==='paid';
        if(!isPaid)return;
        const a=parseFloat(inv.amount_total||0);
        if(a>0&&!paidInvByAmt.has(a))paidInvByAmt.set(a,inv);
        if(inv.order_name&&!paidInvByOrder.has(inv.order_name))paidInvByOrder.set(inv.order_name,inv);
    });

    // Orders
    _allOrders=(ordRes&&ordRes.success?(ordRes.data.orders||[]):[]).slice().sort(function(a,b){
        return new Date(b.date_order||b.last_updated_at||0)-new Date(a.date_order||a.last_updated_at||0);
    });

    // Re-render summary cards with orders/invoices data for fallback computation
    if(_detailData){
        renderSummaryCards(_detailData, _allOrders, _allInvoices);
    }

    // BDOs
    _allBdos=(bdoRes&&bdoRes.success&&bdoRes.data&&bdoRes.data.bdos)||[];

    renderOrders(_allOrders, paidInvByAmt, paidInvByOrder);
    renderInvoices(_allInvoices);
    renderBdos(_allBdos);
    renderSlips(_allSlips);

    // Tab counts
    document.getElementById('tabCountOrders').textContent='('+_allOrders.length+')';
    document.getElementById('tabCountInvoices').textContent='('+_allInvoices.length+')';
    document.getElementById('tabCountBdos').textContent='('+_allBdos.length+')';
    document.getElementById('tabCountSlips').textContent='('+_allSlips.length+')';

    // Matching tab count
    const _pendingSlipsForBadge=_allSlips.filter(function(s){return s.status==='pending'||s.status==='new';});
    const _pendingBdosForBadge=_allBdos.filter(function(b){const st=String(b.payment_status||b.state||'').toLowerCase();return st!=='done'&&st!=='cancelled'&&st!=='cancel'&&st!=='paid'&&st!=='fully_paid';});
    const _mtcEl=document.getElementById('tabCountMatching');
    if(_mtcEl){
        if(_pendingSlipsForBadge.length>0||_pendingBdosForBadge.length>0){
            _mtcEl.textContent='🟡 สลิปรอ '+_pendingSlipsForBadge.length+' / BDO รอ '+_pendingBdosForBadge.length;
        } else {
            _mtcEl.textContent='';
        }
    }

    // If matching tab is active, re-render with fresh data
    const matchPanel=document.getElementById('panel-matching');
    if(matchPanel&&matchPanel.classList.contains('active')) renderMatchingTab();

    // --- Save to cache ---
    _cacheSet(_CUST_CACHE_KEY,{
        detailData: _profileData||_creditData||_pointsData||_linkData ? {profile:_profileData,credit:_creditData,points:_pointsData,link:_linkData} : null,
        detailRaw: (detailRes&&detailRes.success&&detailRes.data)||null,
        orders:    _allOrders,
        invoices:  _allInvoices,
        slips:     _allSlips,
        bdos:      _allBdos,
        notesMap:  _notesMap,
        overridesMap: _overridesMap,
        cachedAt:  Date.now()
    });
}

// ===== CACHE RESTORE =====
function _restoreFromCache(c){
    _allOrders    = c.orders    || [];
    _allInvoices  = c.invoices  || [];
    _allSlips     = c.slips     || [];
    _allBdos      = c.bdos      || [];
    _notesMap     = c.notesMap  || {};
    _overridesMap = c.overridesMap || {};
    if(c.detailRaw){
        const d=c.detailRaw;
        _profileData=d.profile||null;_creditData=d.credit||null;_pointsData=d.points||null;_linkData=d.link||null;
        renderProfileCard(d);renderSummaryCards(d,_allOrders,_allInvoices);renderProfileTab(d);
    }
    // Rebuild paid invoice maps for orders render
    const paidInvByAmt=new Map(),paidInvByOrder=new Map();
    _allInvoices.forEach(function(inv){
        const isPaid=inv.is_paid||String(inv.invoice_state||'').toLowerCase()==='paid'||String(inv.latest_event||'')==='invoice.paid'||String(inv.payment_state||'').toLowerCase()==='paid';
        if(!isPaid)return;
        const a=parseFloat(inv.amount_total||0);
        if(a>0&&!paidInvByAmt.has(a))paidInvByAmt.set(a,inv);
        if(inv.order_name&&!paidInvByOrder.has(inv.order_name))paidInvByOrder.set(inv.order_name,inv);
    });
    renderOrders(_allOrders,paidInvByAmt,paidInvByOrder);
    renderInvoices(_allInvoices);renderBdos(_allBdos);renderSlips(_allSlips);
    document.getElementById('tabCountOrders').textContent='('+_allOrders.length+')';
    document.getElementById('tabCountInvoices').textContent='('+_allInvoices.length+')';
    document.getElementById('tabCountBdos').textContent='('+_allBdos.length+')';
    document.getElementById('tabCountSlips').textContent='('+_allSlips.length+')';
    const _pendingSlipsForBadge=_allSlips.filter(function(s){return s.status==='pending'||s.status==='new';});
    const _pendingBdosForBadge=_allBdos.filter(function(b){const st=String(b.payment_status||b.state||'').toLowerCase();return st!=='done'&&st!=='cancelled'&&st!=='cancel'&&st!=='paid'&&st!=='fully_paid';});
    const _mtcEl=document.getElementById('tabCountMatching');
    if(_mtcEl){
        if(_pendingSlipsForBadge.length>0||_pendingBdosForBadge.length>0){
            _mtcEl.textContent='\uD83D\uDFE1 สลิปรอ '+_pendingSlipsForBadge.length+' / BDO รอ '+_pendingBdosForBadge.length;
        } else { _mtcEl.textContent=''; }
    }
    const matchPanelC=document.getElementById('panel-matching');
    if(matchPanelC&&matchPanelC.classList.contains('active')) renderMatchingTab();
}

function _showCustCacheIndicator(cachedAt){
    if(!cachedAt) return;
    const ageS=Math.round((Date.now()-cachedAt)/1000);
    let ind=document.getElementById('_custCacheNote');
    if(!ind){
        ind=document.createElement('div');
        ind.id='_custCacheNote';
        ind.style.cssText='font-size:0.72rem;color:var(--gray-400);text-align:right;padding:2px 8px 0;margin-bottom:4px;';
        const tabBar=document.getElementById('tabBar');
        if(tabBar&&tabBar.parentNode) tabBar.parentNode.insertBefore(ind,tabBar);
    }
    ind.innerHTML='<i class="bi bi-lightning-charge"></i> จาก cache · '+ageS+'วิที่แล้ว &nbsp;'
        +'<a href="javascript:void(0)" onclick="_cacheClear(\''+_CUST_CACHE_KEY+'\');loadAll(true)" '
        +'style="color:var(--primary);text-decoration:none;">รีเฟรช</a>';
}

// ===== RENDER: Profile Card =====
function renderProfileCard(d){
    const p=d.profile||{};const lk=d.link;
    const lp=d.line_profile||{};
    const name=p.name||p.customer_name||P_NAME||'-';
    const ref=p.ref||p.customer_ref||d.customer_ref||P_REF||'-';
    const pid=p.partner_id||d.partner_id||P_PID||'-';
    const phone=p.phone||p.mobile||'-';
    const email=p.email||'-';
    const addrParts=[p.street,p.street2,p.city,p.state_name||p.state,p.zip,p.country_name||p.country].filter(Boolean);
    const addr=p.delivery_address||addrParts.join(', ')||'-';
    const hasLine=!!(lk&&lk.line_user_id);
    const lineBadge=hasLine?'<span class="badge-line"><i class="bi bi-check-lg"></i> LINE เชื่อมแล้ว</span>':'<span class="badge-no-line">ยังไม่เชื่อม LINE</span>';
    const picUrl=lp.picture_url||null;
    const avatarHtml=picUrl
        ?'<img src="'+escapeHtml(picUrl)+'" style="width:64px;height:64px;border-radius:50%;object-fit:cover;flex-shrink:0;" onerror="this.outerHTML=\'<div class=profile-avatar><i class=bi bi-person></i></div>\';">'
        :'<div class="profile-avatar"><i class="bi bi-person"></i></div>';

    document.getElementById('profileCard').innerHTML=
        avatarHtml
        +'<div class="profile-info">'
        +'<div class="profile-name">'+escapeHtml(name)+'</div>'
        +'<div class="profile-meta">'
        +'<span><i class="bi bi-hash"></i> '+escapeHtml(ref)+'</span>'
        +'<span><i class="bi bi-key"></i> Partner ID: '+escapeHtml(pid)+'</span>'
        +'<span><i class="bi bi-telephone"></i> '+escapeHtml(phone)+'</span>'
        +'<span><i class="bi bi-envelope"></i> '+escapeHtml(email)+'</span>'
        +'<span>'+lineBadge+'</span>'
        +'</div>'
        +'<div style="font-size:0.8rem;color:var(--gray-500);margin-top:0.4rem;"><i class="bi bi-geo-alt"></i> '+escapeHtml(addr)+'</div>'
        +(p.salesperson_name?'<div style="font-size:0.78rem;color:var(--gray-500);margin-top:0.2rem;"><i class="bi bi-person-badge"></i> พนักงานขาย: '+escapeHtml(p.salesperson_name)+'</div>':'')
        +'</div>';
}

// ===== RENDER: Summary Cards =====
function renderSummaryCards(d, orders, invoices){
    const cr=d.credit||{};
    const pts=d.points||{};

    // Compute totals from loaded data if API credit is empty
    let totalSpend=cr.credit_used||cr.total_spend||null;
    let totalDue=cr.total_due||null;

    if(totalSpend==null&&orders&&orders.length){
        totalSpend=0;
        orders.forEach(function(o){totalSpend+=parseFloat(o.amount_total||0);});
    }
    if(totalDue==null&&invoices&&invoices.length){
        totalDue=0;
        invoices.forEach(function(inv){
            const st=String(inv.invoice_state||inv.state||'').toLowerCase();
            const invIsPaid=inv.is_paid||st==='paid'||String(inv.latest_event||'')==='invoice.paid'||String(inv.payment_state||'').toLowerCase()==='paid';
            if(!invIsPaid&&st!=='cancel'&&st!=='cancelled'){
                totalDue+=parseFloat(inv.amount_residual||inv.amount_total||0);
            }
        });
    }

    document.getElementById('sumTotal').textContent=fmtBaht(totalSpend);
    document.getElementById('sumDue').textContent=fmtBaht(totalDue);
    document.getElementById('sumCredit').textContent=fmtBaht(cr.credit_remaining||cr.credit_limit||null);
    const ptVal=pts.available_points!=null?Number(pts.available_points).toLocaleString():'-';
    document.getElementById('sumPoints').textContent=ptVal;
}

// ===== RENDER: Orders Tab =====
function renderOrders(orders, paidInvByAmt, paidInvByOrder){
    const c=document.getElementById('ordersContent');
    if(!orders.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-bag" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบออเดอร์</div>';return;}
    const slipMap=matchSlipsToItems(_allSlips,orders,function(o){return o.amount_total;},function(o){return o.date_order||o.last_updated_at;});
    let h='<div style="overflow-x:auto;"><table class="tbl"><thead><tr>';
    h+='<th>เลขออเดอร์</th><th>สถานะ</th><th style="text-align:right;">ยอดรวม</th><th>วันที่</th><th>ใบแจ้งหนี้ / สลิป</th><th>โน้ต</th><th>จัดการ</th>';
    h+='</tr></thead><tbody>';
    orders.forEach(function(o,idx){
        const ref=o.order_name||'-';
        const oAmt=parseFloat(o.amount_total||0);
        // Match paid invoice by order_name first, then by amount
        const matchedInv=(paidInvByOrder&&paidInvByOrder.get(ref))||(oAmt>0?paidInvByAmt.get(oAmt):null);
        // Check if order itself is marked as paid
        const orderIsPaid=o.is_paid||o.payment_status==='paid';

        // Check for manual override
        const overrides=_overridesMap[ref]||[];
        const latestOverride=overrides.length?overrides[0]:null;

        let stateLabel, stateBadge;
        if(latestOverride){
            stateLabel=latestOverride.new_status;
            stateBadge='<span class="st st-override" title="แก้ไขโดย '+escapeHtml(latestOverride.admin_name)+'">'+escapeHtml(stateLabel)+'</span>';
        } else if(matchedInv||orderIsPaid){
            stateLabel='ชำระแล้ว';
            stateBadge='<span class="st st-paid">✔ ชำระแล้ว</span>';
        } else {
            const rawState=String(o.state||'').toLowerCase();
            stateLabel=o.state_display||o.state||'-';
            stateBadge=stBadge(rawState, stateLabel);
        }

        const slip=slipMap.get(idx);
        let infoCell='';
        if(matchedInv){
            infoCell+='<span style="font-size:0.75rem;color:#1d4ed8;">'+escapeHtml(matchedInv.invoice_number||'-')+'</span>';
            if(matchedInv.invoice_date)infoCell+=' <span style="font-size:0.72rem;color:var(--gray-400);">'+fmtThDate(matchedInv.invoice_date)+'</span>';
        }
        if(slip){
            const sa=slip.amount!=null?fmtBaht(slip.amount):'';
            const sd=fmtThDate(slip.transfer_date||slip.uploaded_at);
            if(infoCell)infoCell+='<br>';
            infoCell+='<span style="font-size:0.75rem;color:#16a34a;">'+sa+' · '+sd+'</span> '+slipThumbHtml(slip);
        }
        if(!infoCell)infoCell='-';

        // Notes inline
        const notes=_notesMap[ref]||[];
        let noteHtml='';
        notes.forEach(function(n){
            noteHtml+='<div class="note-item">'+escapeHtml(n.note)+'<div class="note-meta">'+escapeHtml(n.admin_name)+' · '+fmtThDateTime(n.created_at)+'</div></div>';
        });
        overrides.forEach(function(ov){
            noteHtml+='<div class="override-item"><i class="bi bi-pencil-square"></i> '+escapeHtml(ov.old_status)+' → <strong>'+escapeHtml(ov.new_status)+'</strong>: '+escapeHtml(ov.reason)+'<div class="note-meta">'+escapeHtml(ov.admin_name)+' · '+fmtThDateTime(ov.created_at)+'</div></div>';
        });
        if(!noteHtml)noteHtml='<span style="color:var(--gray-300);">-</span>';

        const bg=(matchedInv||orderIsPaid)?'#eff6ff':(slip?'#f0fdf4':'transparent');
        h+='<tr style="background:'+bg+';">';
        h+='<td style="font-weight:600;">'+escapeHtml(ref)+'</td>';
        h+='<td>'+stateBadge+'</td>';
        h+='<td style="text-align:right;font-weight:600;">'+fmtBaht(o.amount_total)+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);">'+fmtThDate(o.date_order||o.last_updated_at)+'</td>';
        h+='<td>'+infoCell+'</td>';
        h+='<td style="max-width:250px;">'+noteHtml+'</td>';
        h+='<td style="white-space:nowrap;">'
          +'<button class="btn-sm btn-amber" onclick="openOverrideModal(\'order\',\''+escapeHtml(ref)+'\',\''+escapeHtml(stateLabel)+'\')"><i class="bi bi-pencil"></i></button> '
          +'<button class="btn-sm btn-blue" onclick="openNoteModal(\'order\',\''+escapeHtml(ref)+'\')"><i class="bi bi-chat-left-text"></i></button>'
          +'</td>';
        h+='</tr>';
    });
    h+='</tbody></table></div>';
    c.innerHTML=h;
}

// ===== RENDER: Invoices Tab =====
function renderInvoices(invoices){
    const c=document.getElementById('invoicesContent');
    if(!invoices.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-file-text" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบใบแจ้งหนี้</div>';return;}
    const slipMap=matchSlipsToItems(_allSlips,invoices,function(inv){return inv.amount_total;},function(inv){return inv.invoice_date||inv.due_date||inv.processed_at;});
    let h='<div style="overflow-x:auto;"><table class="tbl"><thead><tr>';
    h+='<th>เลขที่</th><th>ออเดอร์</th><th>วันที่</th><th>ครบกำหนด</th><th>สถานะ</th><th style="text-align:right;">ยอดรวม</th><th style="text-align:right;">ค้างชำระ</th><th>วิธีชำระ</th><th>สลิป</th><th>โน้ต</th><th>จัดการ</th>';
    h+='</tr></thead><tbody>';
    invoices.forEach(function(inv,idx){
        const ref=inv.invoice_number||inv.name||'-';
        const stateVal=String(inv.invoice_state||inv.state||'').toLowerCase();

        const overrides=_overridesMap[ref]||[];
        const latestOv=overrides.length?overrides[0]:null;

        // Determine paid status from multiple signals
        const isPaidFromData=inv.is_paid||stateVal==='paid'||String(inv.latest_event||'')==='invoice.paid'||String(inv.payment_state||'').toLowerCase()==='paid';

        let dispState, dispBadge;
        if(latestOv){
            dispState=latestOv.new_status;
            dispBadge='<span class="st st-override">'+escapeHtml(dispState)+'</span>';
        } else if(isPaidFromData){
            dispState='paid';
            dispBadge='<span class="st st-paid">✔ ชำระแล้ว</span>';
        } else if(stateVal==='overdue'||inv.is_overdue){
            dispState='overdue';
            dispBadge='<span class="st st-overdue">เกินกำหนด</span>';
        } else {
            dispState=inv.invoice_state||inv.state||'-';
            dispBadge=stBadge(stateVal,dispState);
        }

        const isPaid=(latestOv?latestOv.new_status==='ชำระแล้ว':isPaidFromData);
        const residualRaw=inv.amount_residual!=null&&inv.amount_residual!==''?parseFloat(inv.amount_residual):null;
        const effectiveResidual=isPaid?0:(residualRaw!=null?residualRaw:parseFloat(inv.amount_total||0));
        const resAmt=effectiveResidual;
        const resHtml=isPaid?'<span style="color:var(--gray-400);">฿0</span>':fmtBaht(effectiveResidual);
        const resColor=(!isPaid&&resAmt>0)?'#dc2626':'inherit';

        const slip=slipMap.get(idx);
        let slipCell='-';
        if(slip){
            slipCell='<span style="font-size:0.75rem;color:#16a34a;">'+fmtBaht(slip.amount)+'<br>'+fmtThDate(slip.transfer_date||slip.uploaded_at)+'</span> '+slipThumbHtml(slip);
        }

        const notes=_notesMap[ref]||[];
        let noteHtml='';
        notes.forEach(function(n){
            noteHtml+='<div class="note-item">'+escapeHtml(n.note)+'<div class="note-meta">'+escapeHtml(n.admin_name)+' · '+fmtThDateTime(n.created_at)+'</div></div>';
        });
        overrides.forEach(function(ov){
            noteHtml+='<div class="override-item"><i class="bi bi-pencil-square"></i> '+escapeHtml(ov.old_status)+' → <strong>'+escapeHtml(ov.new_status)+'</strong>: '+escapeHtml(ov.reason)+'<div class="note-meta">'+escapeHtml(ov.admin_name)+' · '+fmtThDateTime(ov.created_at)+'</div></div>';
        });
        if(!noteHtml)noteHtml='<span style="color:var(--gray-300);">-</span>';

        const bg=(isPaid||slip)?'#f0fdf4':'transparent';
        const payMethod=inv.payment_term||inv.payment_method||'-';
        h+='<tr style="background:'+bg+';">';
        h+='<td style="font-weight:600;">'+escapeHtml(ref)+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);">'+escapeHtml(inv.order_name||'-')+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);">'+fmtThDate(inv.invoice_date||inv.processed_at||inv.updated_at||inv.synced_at)+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);">'+fmtThDate(inv.due_date)+'</td>';
        h+='<td>'+dispBadge+'</td>';
        h+='<td style="text-align:right;font-weight:600;">'+fmtBaht(inv.amount_total)+'</td>';
        h+='<td style="text-align:right;color:'+resColor+';">'+resHtml+'</td>';
        h+='<td style="font-size:0.78rem;color:var(--gray-500);">'+escapeHtml(payMethod)+'</td>';
        h+='<td>'+slipCell+'</td>';
        h+='<td style="max-width:250px;">'+noteHtml+'</td>';
        h+='<td style="white-space:nowrap;">'
          +'<button class="btn-sm btn-amber" onclick="openOverrideModal(\'invoice\',\''+escapeHtml(ref)+'\',\''+escapeHtml(dispState)+'\')"><i class="bi bi-pencil"></i></button> '
          +'<button class="btn-sm btn-blue" onclick="openNoteModal(\'invoice\',\''+escapeHtml(ref)+'\')"><i class="bi bi-chat-left-text"></i></button>'
          +'</td>';
        h+='</tr>';
    });
    h+='</tbody></table></div>';
    c.innerHTML=h;
}

// ===== RENDER: Slips Tab =====
function slipStatusBadge(status){
    const s=String(status||'').toLowerCase();
    if(s==='sent_to_odoo'||s==='sent')return '<span class="st st-done">ส่ง Odoo แล้ว</span>';
    if(s==='matched')return '<span class="st st-posted">จับคู่แล้ว</span>';
    if(s==='failed')return '<span class="st st-overdue">ไม่สำเร็จ</span>';
    return '<span class="st st-open">รอตรวจสอบ</span>';
}

function renderSlips(slips){
    const c=document.getElementById('slipsContent');
    if(!slips.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-receipt" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบสลิป</div>';return;}
    let h='<div style="overflow-x:auto;"><table class="tbl"><thead><tr>';
    h+='<th>รูป</th><th style="text-align:right;">ยอด</th><th>วันที่โอน</th><th>สถานะ</th><th>บันทึกโดย</th><th>วันที่บันทึก</th>';
    h+='</tr></thead><tbody>';
    slips.forEach(function(s,i){
        const bg=i%2===0?'white':'var(--gray-50)';
        const thumb=s.image_full_url?'<img class="slip-thumb" src="'+escapeHtml(s.image_full_url)+'" onclick="openSlipPreview(\''+escapeHtml(s.image_full_url)+'\')" onerror="this.style.display=\'none\'">':'';
        h+='<tr style="background:'+bg+';">';
        h+='<td>'+thumb+'</td>';
        h+='<td style="text-align:right;font-weight:600;color:#16a34a;">'+fmtBaht(s.amount)+'</td>';
        h+='<td style="font-size:0.8rem;">'+fmtThDate(s.transfer_date)+'</td>';
        h+='<td>'+slipStatusBadge(s.status)+'</td>';
        h+='<td style="font-size:0.78rem;color:var(--gray-500);">'+escapeHtml(s.uploaded_by||'-')+'</td>';
        h+='<td style="font-size:0.78rem;color:var(--gray-400);">'+fmtThDateTime(s.uploaded_at||s.created_at)+'</td>';
        h+='</tr>';
    });
    h+='</tbody></table></div>';
    c.innerHTML=h;
}

// ===== RENDER: Matching Tab =====
function renderMatchingTab(){
    const c=document.getElementById('matchingContent');
    if(!c)return;

    // If data not loaded yet, show loading
    if(!_allSlips.length&&!_allBdos.length){
        c.innerHTML='<div class="loading" style="padding:2rem;"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลดข้อมูล...</div>';
        return;
    }

    const pendingSlips=_allSlips.filter(function(s){return s.status==='pending'||s.status==='new'||(!s.status&&s.id);});
    const pendingBdos=_allBdos.filter(function(b){
        const st=String(b.payment_status||b.state||'').toLowerCase();
        return st!=='done'&&st!=='cancelled'&&st!=='cancel'&&st!=='paid'&&st!=='fully_paid';
    });

    let h='<div style="padding:0.5rem 0;">';

    // Summary bar
    h+='<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;padding:0.75rem 1rem;background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;">';
    h+='<i class="bi bi-link-45deg" style="font-size:1.3rem;color:#d97706;"></i>';
    h+='<div style="font-weight:600;font-size:0.95rem;color:#92400e;">จับคู่สลิป ↔ BDO สำหรับ '+(P_REF?escapeHtml(P_REF):'')+(P_NAME?' — '+escapeHtml(P_NAME):'')+'</div>';
    h+='<div style="margin-left:auto;display:flex;gap:0.75rem;align-items:center;">';
    h+='<span style="background:#fef3c7;color:#d97706;padding:3px 10px;border-radius:50px;font-size:0.78rem;font-weight:600;"><i class="bi bi-clock-fill" style="font-size:0.65rem;"></i> สลิปรอ '+pendingSlips.length+'</span>';
    h+='<span style="background:#ede9fe;color:#7c3aed;padding:3px 10px;border-radius:50px;font-size:0.78rem;font-weight:600;"><i class="bi bi-file-earmark-check" style="font-size:0.65rem;"></i> BDO รอ '+pendingBdos.length+'</span>';
    h+='<button onclick="loadAll()" style="background:var(--gray-100);color:var(--gray-700);border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;"><i class="bi bi-arrow-repeat"></i> รีเฟรช</button>';
    h+='</div></div>';

    // Split view
    h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';

    // LEFT: Pending slips
    h+='<div class="card" style="max-height:60vh;overflow-y:auto;">';
    h+='<div class="card-title"><i class="bi bi-receipt" style="color:#16a34a;"></i> สลิปยังไม่จับคู่ ('+pendingSlips.length+')</div>';
    if(!pendingSlips.length){
        h+='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-receipt" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่มีสลิปรอจับคู่</div>';
    } else {
        pendingSlips.forEach(function(s){
            const amt=s.amount!=null?'฿'+Number(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2}):'-';
            const dt=s.transfer_date?new Date(s.transfer_date).toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'numeric'}):'-';
            const thumb=s.image_full_url
                ?'<img src="'+escapeHtml(s.image_full_url)+'" onclick="openSlipPreview(\''+escapeHtml(s.image_full_url)+'\')" style="width:52px;height:64px;object-fit:cover;border-radius:8px;cursor:pointer;border:1.5px solid #bbf7d0;flex-shrink:0;" onerror="this.style.display=\'none\'">'
                :'<div style="width:52px;height:64px;background:var(--gray-100);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>';
            h+='<div style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.6rem 0;border-bottom:1px solid var(--gray-100);">';
            h+=thumb;
            h+='<div style="flex:1;">';
            h+='<div style="font-weight:700;font-size:1rem;color:#16a34a;">'+amt+'</div>';
            h+='<div style="font-size:0.78rem;color:var(--gray-500);">วันที่โอน: '+dt+'</div>';
            h+='<div style="margin-top:3px;"><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:50px;font-size:0.7rem;">รอจับคู่</span></div>';
            h+='</div></div>';
        });
    }
    h+='</div>';

    // RIGHT: Pending BDOs
    h+='<div class="card" style="max-height:60vh;overflow-y:auto;">';
    h+='<div class="card-title"><i class="bi bi-file-earmark-check" style="color:#7c3aed;"></i> BDO ยังไม่จับคู่ ('+pendingBdos.length+')</div>';
    if(!pendingBdos.length){
        h+='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-file-earmark-check" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่มี BDO รอจับคู่</div>';
    } else {
        pendingBdos.forEach(function(b, idx){
            const bName=escapeHtml(b.bdo_name||('BDO-'+b.bdo_id)||'-');
            const oName=escapeHtml(b.order_name||'-');
            const _netAmt=b.amount_net_to_pay!=null?b.amount_net_to_pay:b.amount_total;
            const amt=_netAmt!=null?'฿'+Number(_netAmt).toLocaleString('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2}):'-';
            const dt=b.bdo_date?new Date(b.bdo_date).toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'numeric'}):'-';
            const st=String(b.payment_status||b.state||'').toLowerCase();
            let stBg='#fef3c7';let stClr='#d97706';let stLbl='รอชำระ';
            if(st==='partial'||st==='partially_paid'){stBg='#ffedd5';stClr='#ea580c';stLbl='ชำระบางส่วน';}
            else if(st==='slip_uploaded'||st==='uploaded'){stBg='#dbeafe';stClr='#1d4ed8';stLbl='แนบสลิปแล้ว';}
            else if(st==='matched'||st==='reconciled'){stBg='#dcfce7';stClr='#16a34a';stLbl='จับคู่แล้ว';}
            else if(st==='confirmed'){stBg='#e0f2fe';stClr='#0369a1';stLbl='ยืนยัน';}

            h+='<div style="padding:0.65rem 0;border-bottom:1px solid var(--gray-100);">';
            h+='<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;">';
            h+='<div>';
            h+='<div style="font-weight:700;font-size:0.92rem;color:var(--gray-800);">'+bName+'</div>';
            h+='<div style="font-size:0.78rem;color:var(--gray-500);">'+oName+' · '+dt+'</div>';
            h+='<div style="margin-top:4px;display:flex;align-items:center;gap:6px;">';
            h+='<span style="font-weight:700;font-size:1rem;color:#7c3aed;">'+amt+'</span>';
            h+='<span style="background:'+stBg+';color:'+stClr+';padding:2px 8px;border-radius:50px;font-size:0.7rem;font-weight:600;">'+stLbl+'</span>';
            h+='</div>';
            h+='</div>';
            h+='<button onclick="openBdoSlipAttach(window._matchingBdos['+idx+'], window._pendingSlipsForMatch||[])" '
               +'style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;">'
               +'<i class="bi bi-paperclip"></i> แนบสลิป</button>';
            h+='</div></div>';
        });
    }
    h+='</div>';

    h+='</div>'; // split grid
    h+='</div>';

    // Store data globally for onclick access
    window._pendingSlipsForMatch = pendingSlips;
    window._matchingBdos = pendingBdos;

    c.innerHTML=h;
}

// ===== RENDER: BDOs Tab =====
function bdoStateBadge(state){
    const s=String(state||'').toLowerCase();
    if(s==='done')return '<span class="st st-done">เสร็จสิ้น</span>';
    if(s==='confirmed')return '<span class="st st-confirmed">ยืนยัน</span>';
    if(s==='cancelled'||s==='cancel')return '<span class="st st-cancel">ยกเลิก</span>';
    return stBadge(s,state||'-');
}

function renderBdos(bdos){
    const c=document.getElementById('bdosContent');
    if(!bdos.length){c.innerHTML='<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-truck" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ไม่พบ BDO</div>';return;}
    let h='<div style="overflow-x:auto;"><table class="tbl"><thead><tr>';
    h+='<th>BDO</th><th>ออเดอร์</th><th>วันที่</th><th style="text-align:right;">ยอดสุทธิ</th><th>สถานะ</th>';
    h+='</tr></thead><tbody>';
    bdos.forEach(function(b,i){
        const bg=i%2===0?'white':'var(--gray-50)';
        h+='<tr style="background:'+bg+';">';
        h+='<td style="font-weight:600;">'+escapeHtml(b.bdo_name||'-')+'</td>';
        h+='<td style="font-size:0.85rem;color:var(--gray-600);">'+escapeHtml(b.order_name||'-')+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);">'+fmtThDate(b.bdo_date||b.synced_at||b.updated_at)+'</td>';
        h+='<td style="text-align:right;font-weight:600;">'+fmtBaht(b.amount_net_to_pay!=null?b.amount_net_to_pay:b.amount_total)+'</td>';
        h+='<td>'+bdoStateBadge(b.state)+'</td>';
        h+='</tr>';
    });
    h+='</tbody></table></div>';
    c.innerHTML=h;
}

// ===== RENDER: Profile Tab =====
function renderProfileTab(d){
    const c=document.getElementById('profileContent');
    const p=d.profile||{};
    const cr=d.credit||{};
    const lk=d.link||{};
    const pts=d.points||{};

    let h='<div class="info-grid">';
    const fields=[
        ['ชื่อ',p.name||p.customer_name||P_NAME],
        ['รหัสลูกค้า',p.ref||p.customer_ref||P_REF],
        ['Partner ID',p.partner_id||P_PID],
        ['โทรศัพท์',p.phone||p.mobile],
        ['อีเมล',p.email],
        ['ที่อยู่',[p.street,p.street2].filter(Boolean).join(' ')],
        ['เมือง',p.city],
        ['จังหวัด',p.state_name||p.state],
        ['รหัสไปรษณีย์',p.zip],
        ['ประเทศ',p.country_name||p.country],
        ['เครดิตลิมิต',fmtBaht(cr.credit_limit)],
        ['เครดิตใช้ไป',fmtBaht(cr.credit_used)],
        ['เครดิตคงเหลือ',fmtBaht(cr.credit_remaining)],
        ['ค้างชำระ',fmtBaht(cr.total_due)],
        ['เกินกำหนด',fmtBaht(cr.overdue_amount)],
        ['คะแนนสะสม',pts.available_points!=null?Number(pts.available_points).toLocaleString():'-'],
        ['คะแนนทั้งหมด',pts.total_points!=null?Number(pts.total_points).toLocaleString():'-'],
        ['คะแนนที่ใช้ไป',pts.used_points!=null?Number(pts.used_points).toLocaleString():'-'],
        ['LINE User ID',lk.line_user_id||'-'],
        ['LINE Account ID',lk.line_account_id||'-'],
        ['เชื่อมต่อเมื่อ',fmtThDateTime(lk.linked_at||lk.created_at)],
    ];
    fields.forEach(function(f){
        h+='<div class="info-box"><div class="lbl">'+escapeHtml(f[0])+'</div><div class="val">'+escapeHtml(f[1]||'-')+'</div></div>';
    });
    h+='</div>';

    if(d.warnings&&d.warnings.length){
        h+='<div style="margin-top:1rem;background:#fef9c3;padding:0.75rem;border-radius:8px;font-size:0.78rem;color:#92400e;">';
        h+='<strong>Warnings:</strong><br>'+d.warnings.map(escapeHtml).join('<br>');
        h+='</div>';
    }
    c.innerHTML=h;
}

// ===== RENDER: Timeline Tab =====
async function loadTimeline(){
    _timelineLoaded=true;
    const c=document.getElementById('timelineContent');
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div>';
    const pidParam=(P_PID&&P_PID!=='-')?P_PID:'';
    // Use order_timeline for each order, or just show webhook events
    const res=await whApiCall({action:'order_grouped_today',limit:50,offset:0,partner_id:pidParam,customer_ref:P_REF,search:P_REF||P_NAME});
    // Fallback: show activity log if no dedicated timeline
    if(!res||!res.success||!res.data){
        c.innerHTML='<div style="color:var(--gray-400);text-align:center;padding:2rem;">ไม่พบข้อมูล Timeline</div>';
        return;
    }
    const events=res.data.orders||res.data.events||[];
    if(!events.length){c.innerHTML='<div style="color:var(--gray-400);text-align:center;padding:2rem;">ไม่พบข้อมูล Timeline</div>';return;}
    let h='<div style="position:relative;padding-left:24px;border-left:3px solid var(--gray-200);margin-left:8px;">';
    events.forEach(function(e,i){
        const name=e.order_name||e.event_type||'-';
        const state=e.state_display||e.latest_state||e.state||'';
        const t=fmtThDateTime(e.last_updated_at||e.processed_at||e.date_order);
        const amt=e.amount_total?fmtBaht(e.amount_total):'';
        const dot=i===0?'var(--primary)':'var(--gray-400)';
        h+='<div style="position:relative;margin-bottom:1.25rem;padding-left:16px;">'
          +'<div style="position:absolute;left:-32px;top:2px;width:14px;height:14px;border-radius:50%;background:'+dot+';border:3px solid white;box-shadow:0 0 0 2px '+dot+';"></div>'
          +'<div style="font-weight:600;font-size:0.88rem;">'+escapeHtml(name)+' '+stBadge(state,state)+'</div>'
          +'<div style="font-size:0.8rem;color:var(--gray-500);margin-top:2px;">'+t+(amt?' · '+amt:'')+'</div>'
          +'</div>';
    });
    h+='</div>';
    c.innerHTML=h;
}

// ===== RENDER: Activity Log Tab =====
async function loadActivity(){
    _activityLoaded=true;
    const c=document.getElementById('activityContent');
    c.innerHTML='<div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div>';
    const pidParam=(P_PID&&P_PID!=='-')?P_PID:'';
    const res=await whApiCall({action:'activity_log_list',partner_id:pidParam,limit:100});
    if(!res||!res.success||!res.data||!res.data.items||!res.data.items.length){
        c.innerHTML='<div style="color:var(--gray-400);text-align:center;padding:2rem;"><i class="bi bi-journal-text" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>ยังไม่มีประวัติ</div>';
        return;
    }
    let h='<div style="overflow-x:auto;"><table class="tbl"><thead><tr>';
    h+='<th>ประเภท</th><th>รายการ</th><th>รายละเอียด</th><th>ผู้ดำเนินการ</th><th>วันเวลา</th>';
    h+='</tr></thead><tbody>';
    res.data.items.forEach(function(it){
        const kind=it.log_kind;
        let kindBadge='';
        if(kind==='override')kindBadge='<span class="st" style="background:#fef3c7;color:#92400e;"><i class="bi bi-pencil-square"></i> แก้สถานะ</span>';
        else if(kind==='note')kindBadge='<span class="st" style="background:#dbeafe;color:#1d4ed8;"><i class="bi bi-chat-left-text"></i> โน้ต</span>';
        else kindBadge='<span class="st" style="background:var(--gray-100);color:var(--gray-600);">'+escapeHtml(kind)+'</span>';

        let detail=escapeHtml(it.description||'-');
        if(kind==='override'&&it.old_status){
            detail=escapeHtml(it.old_status)+' → <strong>'+escapeHtml(it.new_status)+'</strong><br><span style="color:var(--gray-500);">'+escapeHtml(it.description)+'</span>';
        }

        h+='<tr>';
        h+='<td>'+kindBadge+'</td>';
        h+='<td style="font-weight:500;">'+escapeHtml(it.entity_type)+': '+escapeHtml(it.entity_ref)+'</td>';
        h+='<td>'+detail+'</td>';
        h+='<td style="font-size:0.8rem;">'+escapeHtml(it.admin_name||'-')+'</td>';
        h+='<td style="font-size:0.8rem;color:var(--gray-500);white-space:nowrap;">'+fmtThDateTime(it.created_at)+'</td>';
        h+='</tr>';
    });
    h+='</tbody></table></div>';
    c.innerHTML=h;
}

// ===== MODALS: Override Status =====
function openOverrideModal(entityType, entityRef, currentStatus){
    document.getElementById('ovEntityType').value=entityType;
    document.getElementById('ovEntityRef').value=entityRef;
    document.getElementById('ovOldStatus').value=currentStatus;
    document.getElementById('ovRefDisplay').textContent=entityRef;
    document.getElementById('ovOldDisplay').textContent=currentStatus;

    const sel=document.getElementById('ovNewStatus');
    const opts=entityType==='order'?ORDER_STATUSES:INVOICE_STATUSES;
    sel.innerHTML='<option value="">-- เลือกสถานะ --</option>';
    Object.keys(opts).forEach(function(label){
        sel.innerHTML+='<option value="'+escapeHtml(label)+'">'+escapeHtml(label)+'</option>';
    });
    document.getElementById('ovReason').value='';
    document.getElementById('overrideModal').classList.add('show');
}

async function submitOverride(){
    const entityType=document.getElementById('ovEntityType').value;
    const entityRef=document.getElementById('ovEntityRef').value;
    const oldStatus=document.getElementById('ovOldStatus').value;
    const newStatus=document.getElementById('ovNewStatus').value;
    const reason=document.getElementById('ovReason').value.trim();
    const adminName=document.getElementById('ovAdminName').value.trim();

    if(!newStatus){alert('กรุณาเลือกสถานะใหม่');return;}
    if(!reason){alert('กรุณาระบุเหตุผล');return;}
    if(!adminName){alert('กรุณาระบุชื่อผู้ดำเนินการ');return;}

    const btn=document.getElementById('ovSubmitBtn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat spin"></i> บันทึก...';

    const res=await whApiCall({
        action:'order_status_override',
        entity_type:entityType, entity_ref:entityRef,
        old_status:oldStatus, new_status:newStatus,
        reason:reason, admin_name:adminName,
        partner_id:P_PID
    });

    btn.disabled=false;btn.innerHTML='<i class="bi bi-check-lg"></i> บันทึก';

    if(res&&res.success){
        closeModal('overrideModal');
        alert('บันทึกสำเร็จ');
        _cacheClear(_CUST_CACHE_KEY);
        loadAll(true);
    } else {
        alert('เกิดข้อผิดพลาด: '+(res&&res.error||'Unknown'));
    }
}

// ===== MODALS: Add Note =====
function openNoteModal(entityType, entityRef){
    document.getElementById('ntEntityType').value=entityType;
    document.getElementById('ntEntityRef').value=entityRef;
    document.getElementById('ntRefDisplay').textContent=entityType+': '+entityRef;
    document.getElementById('ntNote').value='';
    document.getElementById('noteModal').classList.add('show');
}

async function submitNote(){
    const entityType=document.getElementById('ntEntityType').value;
    const entityRef=document.getElementById('ntEntityRef').value;
    const note=document.getElementById('ntNote').value.trim();
    const adminName=document.getElementById('ntAdminName').value.trim();

    if(!note){alert('กรุณาเขียนโน้ต');return;}
    if(!adminName){alert('กรุณาระบุชื่อผู้ดำเนินการ');return;}

    const btn=document.getElementById('ntSubmitBtn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat spin"></i> บันทึก...';

    const res=await whApiCall({
        action:'order_note_add',
        entity_type:entityType, entity_ref:entityRef,
        note:note, admin_name:adminName,
        partner_id:P_PID
    });

    btn.disabled=false;btn.innerHTML='<i class="bi bi-check-lg"></i> บันทึก';

    if(res&&res.success){
        closeModal('noteModal');
        alert('เพิ่มโน้ตสำเร็จ');
        _cacheClear(_CUST_CACHE_KEY);
        loadAll(true);
    } else {
        alert('เกิดข้อผิดพลาด: '+(res&&res.error||'Unknown'));
    }
}

// ===== BDO SLIP ATTACH MODAL =====
let _bsaBdoData=null, _bsaSlips=[], _bsaSelectedSlipId=null, _bsaFileBase64=null;

function openBdoSlipAttach(bdoData, pendingSlips){
    _bsaBdoData=bdoData;_bsaSlips=pendingSlips||[];_bsaSelectedSlipId=null;_bsaFileBase64=null;
    document.getElementById('bsaBdoName').textContent=bdoData.bdo_name||('BDO-'+bdoData.bdo_id);
    document.getElementById('bsaOrderName').textContent=bdoData.order_name||'-';
    const _bsaNetAmt=bdoData.amount_net_to_pay!=null?bdoData.amount_net_to_pay:bdoData.amount_total;
    document.getElementById('bsaAmount').textContent=_bsaNetAmt!=null?'฿'+Number(_bsaNetAmt).toLocaleString():'-';
    document.getElementById('bsaAmountInput').value=_bsaNetAmt||'';
    document.getElementById('bsaDateInput').value=new Date().toISOString().slice(0,10);
    document.getElementById('bsaPreview').style.display='none';
    document.getElementById('bsaFileInput').value='';
    document.getElementById('bsaConfirmBtn').disabled=true;
    const grid=document.getElementById('bsaUnmatchedSlips');
    if(!_bsaSlips.length){
        grid.innerHTML='<span style="color:var(--gray-400);font-size:0.8rem;grid-column:1/-1;text-align:center;padding:1rem;">ไม่มีสลิปที่รอจับคู่</span>';
    } else {
        let gh='';
        _bsaSlips.forEach(function(s){
            const thumb=s.image_full_url||'';
            const amt=s.amount!=null?'฿'+Number(s.amount).toLocaleString():'';
            gh+='<div id="bsaSlip'+s.id+'" onclick="bsaSelectSlip('+s.id+')" style="cursor:pointer;border:2px solid var(--gray-200);border-radius:8px;overflow:hidden;transition:all 0.15s;text-align:center;">';
            if(thumb) gh+='<img src="'+escapeHtml(thumb)+'" style="width:100%;height:70px;object-fit:cover;" onerror="this.style.display=\'none\'">';
            else gh+='<div style="width:100%;height:70px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>';
            gh+='<div style="font-size:0.7rem;padding:3px;color:var(--gray-600);">'+amt+'</div></div>';
        });
        grid.innerHTML=gh;
    }
    document.getElementById('bdoSlipAttachModal').classList.add('show');
}

function bsaSelectSlip(slipId){
    _bsaSelectedSlipId=slipId;_bsaFileBase64=null;
    document.getElementById('bsaPreview').style.display='none';
    document.getElementById('bsaFileInput').value='';
    _bsaSlips.forEach(function(s){
        const el=document.getElementById('bsaSlip'+s.id);
        if(el) el.style.borderColor=s.id===slipId?'#059669':'var(--gray-200)';
    });
    const slip=_bsaSlips.find(function(s){return s.id===slipId;});
    if(slip&&slip.amount) document.getElementById('bsaAmountInput').value=slip.amount;
    document.getElementById('bsaConfirmBtn').disabled=false;
}

function bsaHandleFileSelect(evt){
    const file=evt.target.files&&evt.target.files[0];
    if(!file||!file.type.startsWith('image/'))return;
    _bsaSelectedSlipId=null;
    _bsaSlips.forEach(function(s){const el=document.getElementById('bsaSlip'+s.id);if(el)el.style.borderColor='var(--gray-200)';});
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

function getBsaSelectedSlip(){
    return _bsaSlips.find(function(s){ return (s.id || s.slip_id) === _bsaSelectedSlipId; }) || null;
}

async function bsaConfirmAttach(){
    if(!_bsaBdoData)return;
    const btn=document.getElementById('bsaConfirmBtn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat spin"></i> กำลังบันทึก...';
    try{
        const amount=parseFloat(document.getElementById('bsaAmountInput').value)||null;
        const transferDate=document.getElementById('bsaDateInput').value||null;
        if(_bsaSelectedSlipId){
            const slip = getBsaSelectedSlip();
            if(!slip) throw new Error('ไม่พบข้อมูลสลิปที่เลือก');
            const j=await whApiCall({
                action:'slip_match_bdo',
                slip_inbox_id:slip.slip_inbox_id || slip.odoo_slip_id || slip.id || slip.slip_id,
                line_user_id:slip.line_user_id || '',
                matches:[{bdo_id:_bsaBdoData.bdo_id,amount:amount != null ? amount : parseFloat(_bsaBdoData.amount_net_to_pay || _bsaBdoData.amount_total || 0)}],
                note:'Attach slip from customer detail modal',
                local_slip_id:slip.id || slip.slip_id
            });
            if(!j.success) throw new Error(j.error||'จับคู่ไม่สำเร็จ');
        } else if(_bsaFileBase64){
            const r=await fetch('api/odoo-slip-upload.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({line_user_id:'_dashboard_upload_',image_base64:_bsaFileBase64.replace(/^data:image\/\w+;base64,/,''),bdo_id:_bsaBdoData.bdo_id,amount:amount,transfer_date:transferDate,uploaded_by:'dashboard',skip_line_notify:true})});
            const j=await r.json();if(!j.success) throw new Error(j.error||'อัพโหลดไม่สำเร็จ');
        } else { throw new Error('กรุณาเลือกสลิปหรืออัพโหลดรูป'); }
        closeModal('bdoSlipAttachModal');
        alert('✅ แนบสลิปเรียบร้อยแล้ว');
        _cacheClear(_CUST_CACHE_KEY);
        loadAll(true);
    }catch(e){
        alert('❌ '+e.message);
        btn.disabled=false;btn.innerHTML='<i class="bi bi-check-lg"></i> แนบสลิป';
    }
}

async function unmatchBdoSlip(slipId, bdoId){
    if(!confirm('ยกเลิกการจับคู่สลิปกับ BDO นี้ ใช่ไหม?'))return;
    try{
        const slip = (_bsaSlips || []).find(function(item){ return (item.id || item.slip_id) == slipId; }) || null;
        const j=await whApiCall({
            action:'slip_unmatch',
            slip_inbox_id:(slip && (slip.slip_inbox_id || slip.odoo_slip_id || slip.id || slip.slip_id)) || slipId,
            line_user_id:(slip && slip.line_user_id) || '',
            reason:'ยกเลิกจาก customer detail dashboard',
            local_slip_id:(slip && (slip.id || slip.slip_id)) || slipId,
            bdo_id:bdoId
        });
        if(j.success){alert('✅ ยกเลิกการจับคู่เรียบร้อยแล้ว');_cacheClear(_CUST_CACHE_KEY);loadAll(true);}
        else{alert('❌ '+(j.error||'เกิดข้อผิดพลาด'));}
    }catch(e){alert('❌ Network error: '+e.message);}
}
