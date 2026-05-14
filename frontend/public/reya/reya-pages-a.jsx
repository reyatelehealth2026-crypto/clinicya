/* REYA Dashboard — pages part A
   Dashboard, Customers CRM, Orders, Products
*/

const { useState: useStateP, useMemo: useMemoP } = React;

// Shared spark line
function Spark({ points, color = 'var(--brand-500)' }) {
  const max = Math.max(...points), min = Math.min(...points);
  const w = 80, h = 32;
  const pts = points.map((v, i) => {
    const x = (i / (points.length - 1)) * w;
    const y = h - ((v - min) / (max - min || 1)) * h;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
      <defs>
        <linearGradient id={`spg-${color.replace(/[^a-z0-9]/gi,'')}`} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.3" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <polyline fill="none" stroke={color} strokeWidth="1.5" points={pts} />
      <polygon fill={`url(#spg-${color.replace(/[^a-z0-9]/gi,'')})`} points={`0,${h} ${pts} ${w},${h}`} />
    </svg>
  );
}

// =========================================================================
// DASHBOARD
// =========================================================================
function DashboardPage({ onNav }) {
  return (
    <div className="page" data-screen-label="Dashboard">
      <div className="page-head">
        <div className="titles">
          <h1>สวัสดีค่ะ ภญ. นภัสสร 👋</h1>
          <div className="sub">
            <Lic name="calendar" size={12} />
            <span>วันพฤหัส 14 พฤษภาคม 2569 · ร้านยา REYA สาขา สีลม</span>
          </div>
        </div>
        <div className="actions">
          <div className="seg">
            <button>7 วัน</button>
            <button className="active">30 วัน</button>
            <button>90 วัน</button>
          </div>
          <button className="btn ghost"><Lic name="download" size={13} /> Export</button>
          <button className="btn brand"><Lic name="plus" size={13} /> สร้างออเดอร์</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi">
            <div className="top"><div className="ic"><Lic name="dollar-sign" size={13} /></div> รายได้วันนี้</div>
            <div className="val">฿42,180</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 12.4% <span className="vs">vs เมื่อวาน</span></div>
            <div className="spark"><Spark points={[20,28,22,35,32,40,42]} /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic info"><Lic name="package" size={13} /></div> ออเดอร์</div>
            <div className="val">38</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 8.1% <span className="vs">vs เมื่อวาน</span></div>
            <div className="spark"><Spark points={[15,18,20,22,28,30,38]} color="var(--info)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic success"><Lic name="user-plus" size={13} /></div> ลูกค้าใหม่</div>
            <div className="val">12</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> +3 <span className="vs">vs เมื่อวาน</span></div>
            <div className="spark"><Spark points={[5,8,7,10,9,11,12]} color="var(--success)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic warn"><Lic name="sparkles" size={13} /></div> AI Co-Pilot ใช้</div>
            <div className="val">184</div>
            <div className="delta down"><Lic name="trending-down" size={11} /> 2.1% <span className="vs">accept rate 87%</span></div>
            <div className="spark"><Spark points={[180,195,210,200,190,185,184]} color="var(--warning)" /></div>
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <div>
              <h3>รายได้ 30 วัน</h3>
              <div className="sub">฿1.24M รวม · ค่าเฉลี่ย ฿41,330/วัน</div>
            </div>
            <div className="tools">
              <span style={{fontSize:11,color:'var(--fg-3)'}}>เปรียบเทียบ:</span>
              <button className="more">เดือนก่อน ›</button>
            </div>
          </div>
          <div className="chart-panel">
            <div className="chart">
              <BigChart />
            </div>
            <div className="legend">
              <span><span className="dot" style={{background:'var(--brand-500)'}} />รายได้ปีนี้</span>
              <span><span className="dot" style={{background:'var(--brand-200)'}} />รายได้เดือนก่อน</span>
              <span><span className="dot" style={{background:'var(--warning)'}} />AI consult</span>
            </div>
          </div>
        </div>

        <div className="grid-2">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <h3><Lic name="package" size={14} /> ออเดอร์ล่าสุด</h3>
              <button className="more" onClick={() => onNav('orders')}>ดูทั้งหมด ›</button>
            </div>
            <table className="tbl">
              <thead><tr><th>Order</th><th>ลูกค้า</th><th>รายการ</th><th>ยอด</th><th>สถานะ</th></tr></thead>
              <tbody>
                {ORDERS.slice(0, 6).map(o => (
                  <tr key={o.id}>
                    <td className="id">#{o.id}</td>
                    <td>
                      <div className="cust">
                        <div className="av" style={{background:`linear-gradient(135deg, ${o.av[0]}, ${o.av[1]})`}}>{o.initial}</div>
                        <div>
                          <div className="nm">{o.customer}</div>
                          <div className="sub">{o.date}</div>
                        </div>
                      </div>
                    </td>
                    <td style={{color:'var(--fg-2)',fontSize:12}}>{o.items}</td>
                    <td style={{fontWeight:700,fontVariantNumeric:'tabular-nums'}}>฿{o.total.toLocaleString()}</td>
                    <td><span className={`pill ${o.status}`}>{o.label}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3><Lic name="activity" size={14} /> Activity</h3></div>
            <div className="activity-list">
              <div className="activity-item">
                <div className="av info"><Lic name="message-circle" size={14} /></div>
                <div className="body">
                  <div className="txt"><b>สมชาย ใจดี</b> ส่งคำปรึกษาผ่าน LINE Inbox</div>
                  <div className="when">2 นาทีที่แล้ว · AI suggest ส่งคำตอบให้แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av success"><Lic name="check" size={14} /></div>
                <div className="body">
                  <div className="txt">ออเดอร์ <b>#ORD-1280</b> ชำระแล้ว ฿425</div>
                  <div className="when">8 นาทีที่แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av warn"><Lic name="alert-triangle" size={14} /></div>
                <div className="body">
                  <div className="txt">สต็อก <b>วิตามิน C 1000mg</b> เหลือ 12 ขวด</div>
                  <div className="when">25 นาทีที่แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av brand"><Lic name="sparkles" size={14} /></div>
                <div className="body">
                  <div className="txt"><b>AI Co-Pilot</b> แนะนำให้ตั้งโปรโมชั่นวิตามิน B-Complex (กำลังเป็นเทรนด์)</div>
                  <div className="when">42 นาทีที่แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av info"><Lic name="user-plus" size={14} /></div>
                <div className="body">
                  <div className="txt"><b>ลูกค้าใหม่ 3 ท่าน</b> สมัครผ่าน LINE Mini App</div>
                  <div className="when">1 ชม. ที่แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av success"><Lic name="truck" size={14} /></div>
                <div className="body">
                  <div className="txt">ออเดอร์ <b>#ORD-1279</b> จัดส่งสำเร็จ</div>
                  <div className="when">2 ชม. ที่แล้ว</div>
                </div>
              </div>
              <div className="activity-item">
                <div className="av danger"><Lic name="alert-circle" size={14} /></div>
                <div className="body">
                  <div className="txt"><b>คำเตือน:</b> ลูกค้า "อนงค์" มีประวัติแพ้ Penicillin</div>
                  <div className="when">3 ชม. ที่แล้ว</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="grid-3">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>🏆 ลูกค้า VIP top 5</h3></div>
            <div className="panel-body" style={{padding:0}}>
              {[
                ['สมชาย ใจดี', 8420, 'Gold', '#0369a1', '#0c4a6e'],
                ['อนงค์ วิเศษ', 4120, 'Gold', '#0891b2', '#155e75'],
                ['ปริมา ศิริ', 2847, 'Silver', '#2c7656', '#1c4d39'],
                ['ธนวัฒน์ ไทย', 1240, 'Silver', '#7c3aed', '#4c1d95'],
                ['พิมพ์ดา บางขุน', 320, 'Bronze', '#be185d', '#831843'],
              ].map(([nm, pts, tier, c1, c2], i) => (
                <div key={i} style={{display:'flex',alignItems:'center',gap:10,padding:'10px 18px',borderBottom:i<4?'1px solid var(--divider)':0}}>
                  <div style={{
                    width:30,height:30,borderRadius:'50%',
                    background:`linear-gradient(135deg, ${c1}, ${c2})`,
                    display:'grid',placeItems:'center',color:'#fff',fontWeight:700,fontSize:12,
                  }}>{nm[0]}</div>
                  <div style={{flex:1,minWidth:0}}>
                    <div style={{fontSize:13,fontWeight:600}}>{nm}</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>{tier} · {pts.toLocaleString()} แต้ม</div>
                  </div>
                  <span className={`tier ${tier.toLowerCase()}`}>{tier}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>🔥 สินค้าขายดี</h3></div>
            <div className="panel-body" style={{padding:0}}>
              {[
                ['พาราเซตามอล 500mg', 142, '+18%'],
                ['วิตามิน C 1000mg', 98, '+12%'],
                ['ครีมกันแดด SPF 50', 76, '+8%'],
                ['แอลกอฮอล์เจล 500ml', 64, '−3%'],
                ['มะแว้งแก้ไอ', 52, '+5%'],
              ].map(([nm, qty, change], i) => (
                <div key={i} style={{display:'flex',alignItems:'center',gap:10,padding:'10px 18px',borderBottom:i<4?'1px solid var(--divider)':0}}>
                  <div style={{
                    width:30,height:30,borderRadius:8,
                    background:'var(--line-soft)',
                    display:'grid',placeItems:'center',color:'var(--brand-600)',
                  }}><Lic name="pill" size={15} /></div>
                  <div style={{flex:1,minWidth:0}}>
                    <div style={{fontSize:13,fontWeight:600,whiteSpace:'nowrap',overflow:'hidden',textOverflow:'ellipsis'}}>{nm}</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>{qty} ขายแล้ว</div>
                  </div>
                  <div style={{fontSize:11,fontWeight:700,color:change.startsWith('+')?'var(--success)':'var(--danger)',fontVariantNumeric:'tabular-nums'}}>{change}</div>
                </div>
              ))}
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>⚡ Tasks วันนี้</h3></div>
            <div className="panel-body" style={{padding:'4px 0'}}>
              {[
                { txt: 'ตอบลูกค้าค้างใน Inbox', n: 3, ic: 'message-circle', urgent: true },
                { txt: 'รีวิวคำสั่งซื้อรอชำระ', n: 2, ic: 'package' },
                { txt: 'จัดส่งออเดอร์', n: 5, ic: 'truck' },
                { txt: 'เติมสต็อกใกล้หมด', n: 4, ic: 'alert-triangle', urgent: true },
                { txt: 'อนุมัติของรางวัล', n: 1, ic: 'gift' },
              ].map((t, i) => (
                <div key={i} style={{display:'flex',alignItems:'center',gap:10,padding:'10px 18px',borderBottom:i<4?'1px solid var(--divider)':0,cursor:'pointer'}}>
                  <input type="checkbox" style={{accentColor:'var(--brand-500)'}} />
                  <div style={{
                    width:26,height:26,borderRadius:7,
                    background: t.urgent ? 'var(--warning-bg)' : 'var(--slate-100)',
                    color: t.urgent ? 'var(--warning-fg)' : 'var(--fg-2)',
                    display:'grid',placeItems:'center',
                  }}><Lic name={t.ic} size={13} /></div>
                  <span style={{fontSize:13,fontWeight:500,flex:1}}>{t.txt}</span>
                  <span style={{
                    background:'var(--slate-100)',padding:'1px 7px',borderRadius:9999,
                    fontSize:11,fontWeight:700,
                    color: t.urgent ? 'var(--warning-fg)' : 'var(--fg-2)',
                  }}>{t.n}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function BigChart() {
  // Chart values match the SVG path (relative to 700×240 viewBox)
  const xLabels = [
    { t: '1 พ.ค.', x: 40 },
    { t: '5 พ.ค.', x: 160 },
    { t: '10 พ.ค.', x: 280 },
    { t: '15 พ.ค.', x: 400 },
    { t: '20 พ.ค.', x: 520 },
    { t: '25 พ.ค.', x: 640 },
  ];
  const yLabels = [
    { t: '฿60k', y: 4 },
    { t: '฿45k', y: 64 },
    { t: '฿30k', y: 124 },
    { t: '฿15k', y: 184 },
  ];
  // The last point — value callout
  const lastX = 690, lastY = 30;

  return (
    <div style={{position:'relative',width:'100%',height:'100%'}}>
      <svg viewBox="0 0 700 240" preserveAspectRatio="none" style={{width:'100%',height:'100%',display:'block',position:'absolute',inset:0}}>
        <defs>
          <linearGradient id="bg-grad" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#2c7656" stopOpacity="0.25"/>
            <stop offset="100%" stopColor="#2c7656" stopOpacity="0"/>
          </linearGradient>
        </defs>
        {/* horizontal grid */}
        {[0, 60, 120, 180, 240].map(y => (
          <line key={y} x1="40" x2="690" y1={y} y2={y} stroke="#e2e8f0" strokeWidth="1" strokeDasharray="2 4" vectorEffect="non-scaling-stroke" />
        ))}
        {/* prev month (lighter) */}
        <polyline fill="none" stroke="#aed6c2" strokeWidth="1.5" strokeDasharray="4 3" vectorEffect="non-scaling-stroke"
          points="40,160 100,150 160,170 220,140 280,150 340,120 400,130 460,110 520,125 580,100 640,115 690,95" />
        {/* current month */}
        <path d="M 40,180 L 100,160 L 160,170 L 220,120 L 280,130 L 340,80 L 400,100 L 460,60 L 520,85 L 580,40 L 640,70 L 690,30 L 690,240 L 40,240 Z" fill="url(#bg-grad)" />
        <polyline fill="none" stroke="#2c7656" strokeWidth="2.5" vectorEffect="non-scaling-stroke"
          points="40,180 100,160 160,170 220,120 280,130 340,80 400,100 460,60 520,85 580,40 640,70 690,30" />
        {/* dots */}
        {[[40,180],[100,160],[160,170],[220,120],[280,130],[340,80],[400,100],[460,60],[520,85],[580,40],[640,70],[690,30]].map(([x,y],i) => (
          <circle key={i} cx={x} cy={y} r="4" fill="#fff" stroke="#2c7656" strokeWidth="2" vectorEffect="non-scaling-stroke" />
        ))}
        {/* highlight latest */}
        <circle cx="690" cy="30" r="6" fill="#2c7656" vectorEffect="non-scaling-stroke" />
      </svg>

      {/* HTML text overlays — positioned in % so they scale with container, no stretching */}
      <div style={{position:'absolute',inset:0,pointerEvents:'none'}}>
        {/* y-axis labels */}
        {yLabels.map(({t, y}) => (
          <div key={t} style={{
            position:'absolute', left:0, top:`${(y/240)*100}%`,
            fontSize:10, color:'var(--fg-3)', fontFamily:'var(--font-mono)',
            transform:'translateY(-50%)', paddingLeft:4,
          }}>{t}</div>
        ))}
        {/* x-axis labels */}
        {xLabels.map(({t, x}) => (
          <div key={t} style={{
            position:'absolute', left:`${(x/700)*100}%`, bottom:2,
            fontSize:10, color:'var(--fg-3)',
            transform:'translateX(-50%)',
          }}>{t}</div>
        ))}
        {/* Latest value callout */}
        <div style={{
          position:'absolute', left:`${(lastX/700)*100}%`, top:`${(lastY/240)*100}%`,
          transform:'translate(-100%, -130%)',
          background:'#0f172a', color:'#fff',
          fontSize:11, fontWeight:700,
          padding:'3px 8px', borderRadius:6,
          fontFamily:'var(--font-mono)', whiteSpace:'nowrap',
        }}>฿62.4k</div>
      </div>
    </div>
  );
}

// =========================================================================
// CUSTOMERS CRM
// =========================================================================
function CustomersPage() {
  const [q, setQ] = useStateP('');
  const [tier, setTier] = useStateP('all');
  const [selected, setSelected] = useStateP(CUSTOMERS[0].id);

  const filtered = useMemoP(() => {
    return CUSTOMERS.filter(c => {
      if (tier !== 'all' && c.tier.toLowerCase() !== tier) return false;
      if (q && !c.name.includes(q) && !c.email.includes(q)) return false;
      return true;
    });
  }, [q, tier]);

  return (
    <div className="page" data-screen-label="Customers">
      <div className="page-head">
        <div className="titles">
          <h1>ลูกค้า CRM</h1>
          <div className="sub"><Lic name="users-round" size={12} /> {CUSTOMERS.length} ลูกค้า · {CUSTOMERS.filter(c=>c.vip).length} VIP · 12 สมัครใหม่เดือนนี้</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="download" size={13} /> Export</button>
          <button className="btn ghost"><Lic name="filter" size={13} /> ตัวกรอง</button>
          <button className="btn brand"><Lic name="user-plus" size={13} /> เพิ่มลูกค้า</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi">
            <div className="top"><div className="ic"><Lic name="users-round" size={13} /></div> ทั้งหมด</div>
            <div className="val">2,847</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 4.2% เดือนนี้</div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic success"><Lic name="circle-dot" size={13} /></div> Online ตอนนี้</div>
            <div className="val">84</div>
            <div className="delta up"><Lic name="message-circle" size={11} /> 12 กำลังแชท</div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic warn"><Lic name="crown" size={13} /></div> VIP</div>
            <div className="val">412</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 8 ใหม่สัปดาห์นี้</div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic info"><Lic name="dollar-sign" size={13} /></div> Avg LTV</div>
            <div className="val">฿2,180</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 6.8% YoY</div>
          </div>
        </div>

        <div style={{display:'grid',gridTemplateColumns:'1fr 280px',gap:16,marginTop:16}}>
          <div className="panel" style={{marginTop:0}}>
            <div className="filter-bar">
              <div className="search-input">
                <span className="ic"><Lic name="search" size={14} /></span>
                <input value={q} onChange={e=>setQ(e.target.value)} placeholder="ค้นหาชื่อ, email, เบอร์โทร, LINE ID..." />
              </div>
              <div className="pills">
                {[['all','ทั้งหมด',CUSTOMERS.length],['gold','Gold',CUSTOMERS.filter(c=>c.tier==='Gold').length],['silver','Silver',CUSTOMERS.filter(c=>c.tier==='Silver').length],['bronze','Bronze',CUSTOMERS.filter(c=>c.tier==='Bronze').length]].map(([id,lbl,n]) => (
                  <button key={id} className={tier===id?'active':''} onClick={()=>setTier(id)}>{lbl}<span className="n">{n}</span></button>
                ))}
              </div>
            </div>
            <div style={{overflowX:'auto'}}>
              <table className="tbl">
                <thead><tr>
                  <th>ลูกค้า</th><th>ระดับ</th><th>แต้ม</th><th>ออเดอร์</th><th>LTV</th><th>ออเดอร์ล่าสุด</th><th>สถานะ</th><th></th>
                </tr></thead>
                <tbody>
                  {filtered.map(c => (
                    <tr key={c.id} onClick={()=>setSelected(c.id)} style={{cursor:'pointer',background:selected===c.id?'var(--line-soft)':undefined}}>
                      <td>
                        <div className="cust">
                          <div className="av" style={{background:`linear-gradient(135deg, ${c.av[0]}, ${c.av[1]})`}}>{c.initial}</div>
                          <div>
                            <div className="nm" style={{display:'flex',alignItems:'center',gap:5}}>{c.name}{c.vip && <span style={{background:'var(--brand-500)',color:'#fff',fontSize:9,padding:'1px 5px',borderRadius:4,fontWeight:800}}>VIP</span>}</div>
                            <div className="sub">{c.email}</div>
                          </div>
                        </div>
                      </td>
                      <td><span className={`tier ${c.tier.toLowerCase()}`}>{c.tier}</span></td>
                      <td style={{fontWeight:700,fontVariantNumeric:'tabular-nums'}}>{c.points.toLocaleString()}</td>
                      <td style={{fontVariantNumeric:'tabular-nums'}}>{c.orderCount}</td>
                      <td style={{fontWeight:700,fontVariantNumeric:'tabular-nums'}}>฿{c.ltv.toLocaleString()}</td>
                      <td style={{color:'var(--fg-2)',fontSize:12}}>{c.lastOrder}</td>
                      <td>{c.online ? <span className="pill paid">Online</span> : <span style={{color:'var(--fg-3)',fontSize:11}}>{c.lastSeen}</span>}</td>
                      <td>
                        <div className="actions">
                          <button className="ibtn"><Lic name="message-circle" size={14} /></button>
                          <button className="ibtn"><Lic name="more-horizontal" size={14} /></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div>
            <div className="cust-aside">
              <h4>การกระจายตามระดับ</h4>
              <div style={{display:'flex',height:24,borderRadius:6,overflow:'hidden',background:'var(--slate-100)'}}>
                <div style={{width:'14%',background:'#fef9c3'}} title="Gold" />
                <div style={{width:'42%',background:'#f1f5f9'}} title="Silver" />
                <div style={{width:'44%',background:'#fef3c7'}} title="Bronze" />
              </div>
              <div style={{display:'flex',justifyContent:'space-between',fontSize:10,color:'var(--fg-3)',fontWeight:600}}>
                <span>Gold 14%</span><span>Silver 42%</span><span>Bronze 44%</span>
              </div>
            </div>

            <div className="cust-aside" style={{marginTop:12}}>
              <h4>กำลังออนไลน์ (8)</h4>
              {CUSTOMERS.filter(c=>c.online).slice(0,5).map(c=>(
                <div key={c.id} className="item">
                  <div className="av" style={{background:`linear-gradient(135deg, ${c.av[0]}, ${c.av[1]})`}}>{c.initial}</div>
                  <div>
                    <div className="nm">{c.name}</div>
                    <div className="sub">{c.tier} · {c.points.toLocaleString()} แต้ม</div>
                  </div>
                  <span className="v" style={{width:8,height:8,borderRadius:'50%',background:'var(--success)'}} />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// ORDERS
// =========================================================================
function OrdersPage() {
  const [q, setQ] = useStateP('');
  const [status, setStatus] = useStateP('all');

  const filtered = useMemoP(() => {
    return ORDERS.filter(o => {
      if (status !== 'all' && o.status !== status) return false;
      if (q && !o.customer.includes(q) && !o.id.includes(q)) return false;
      return true;
    });
  }, [q, status]);

  return (
    <div className="page" data-screen-label="Orders">
      <div className="page-head">
        <div className="titles">
          <h1>ออเดอร์</h1>
          <div className="sub"><Lic name="package" size={12} /> 38 ออเดอร์วันนี้ · ฿42,180 รายได้</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="filter" size={13} /> ตัวกรอง</button>
          <button className="btn ghost"><Lic name="download" size={13} /> Export</button>
          <button className="btn brand"><Lic name="plus" size={13} /> สร้างออเดอร์</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis cols-5">
          <div className="kpi"><div className="top"><div className="ic"><Lic name="package" size={13} /></div> ทั้งหมด</div><div className="val">{ORDERS.length}</div></div>
          <div className="kpi"><div className="top"><div className="ic warn"><Lic name="clock" size={13} /></div> รอชำระ</div><div className="val" style={{color:'var(--warning-fg)'}}>{ORDERS.filter(o=>o.status==='pending').length}</div></div>
          <div className="kpi"><div className="top"><div className="ic success"><Lic name="check" size={13} /></div> ชำระแล้ว</div><div className="val" style={{color:'var(--success-fg)'}}>{ORDERS.filter(o=>o.status==='paid').length}</div></div>
          <div className="kpi"><div className="top"><div className="ic info"><Lic name="truck" size={13} /></div> จัดส่งแล้ว</div><div className="val" style={{color:'var(--info-fg)'}}>{ORDERS.filter(o=>o.status==='shipped').length}</div></div>
          <div className="kpi"><div className="top"><div className="ic danger"><Lic name="x" size={13} /></div> ยกเลิก</div><div className="val" style={{color:'var(--danger-fg)'}}>{ORDERS.filter(o=>o.status==='cancel').length}</div></div>
        </div>

        <div className="panel">
          <div className="filter-bar">
            <div className="search-input">
              <span className="ic"><Lic name="search" size={14} /></span>
              <input value={q} onChange={e=>setQ(e.target.value)} placeholder="ค้นหา order ID, ลูกค้า..." />
            </div>
            <div className="pills">
              {[['all','ทั้งหมด'],['pending','รอชำระ'],['paid','ชำระแล้ว'],['shipped','จัดส่ง'],['cancel','ยกเลิก']].map(([id,lbl]) => (
                <button key={id} className={status===id?'active':''} onClick={()=>setStatus(id)}>{lbl}<span className="n">{id==='all'?ORDERS.length:ORDERS.filter(o=>o.status===id).length}</span></button>
              ))}
            </div>
          </div>
          <table className="tbl">
            <thead><tr><th>Order</th><th>ลูกค้า</th><th>รายการ</th><th>วันที่</th><th>ยอด</th><th>ชำระ</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
              {filtered.map(o => (
                <tr key={o.id}>
                  <td className="id">#{o.id}</td>
                  <td>
                    <div className="cust">
                      <div className="av" style={{background:`linear-gradient(135deg, ${o.av[0]}, ${o.av[1]})`}}>{o.initial}</div>
                      <div className="nm">{o.customer}</div>
                    </div>
                  </td>
                  <td style={{color:'var(--fg-2)',fontSize:12,maxWidth:180}}>{o.items}</td>
                  <td style={{color:'var(--fg-2)',fontSize:12}}>{o.date}</td>
                  <td style={{fontWeight:700,fontVariantNumeric:'tabular-nums'}}>฿{o.total.toLocaleString()}</td>
                  <td style={{fontSize:12,color:'var(--fg-2)'}}>{o.paymentMethod}</td>
                  <td><span className={`pill ${o.status}`}>{o.label}</span></td>
                  <td>
                    <div className="actions">
                      <button className="ibtn"><Lic name="eye" size={14} /></button>
                      <button className="ibtn"><Lic name="more-horizontal" size={14} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// PRODUCTS
// =========================================================================
function ProductsPage() {
  const [q, setQ] = useStateP('');
  const [cat, setCat] = useStateP('all');

  const filtered = useMemoP(() => {
    return INVENTORY.filter(p => {
      if (cat !== 'all' && p.cat !== cat) return false;
      if (q && !p.name.includes(q) && !p.sku.includes(q)) return false;
      return true;
    });
  }, [q, cat]);

  return (
    <div className="page" data-screen-label="Products">
      <div className="page-head">
        <div className="titles">
          <h1>สินค้า</h1>
          <div className="sub"><Lic name="pill" size={12} /> {INVENTORY.length} SKU · {INVENTORY.filter(p=>p.stock<20).length} ใกล้หมด · ฿1.2M มูลค่าสต็อก</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="upload" size={13} /> นำเข้า</button>
          <button className="btn ghost"><Lic name="download" size={13} /> Export</button>
          <button className="btn brand"><Lic name="plus" size={13} /> เพิ่มสินค้า</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi"><div className="top"><div className="ic"><Lic name="pill" size={13} /></div> SKU ทั้งหมด</div><div className="val">{INVENTORY.length}</div><div className="delta up"><Lic name="trending-up" size={11} /> 4 ใหม่</div></div>
          <div className="kpi"><div className="top"><div className="ic warn"><Lic name="alert-triangle" size={13} /></div> ใกล้หมด</div><div className="val" style={{color:'var(--warning-fg)'}}>{INVENTORY.filter(p=>p.stock>0&&p.stock<20).length}</div><div className="delta down"><Lic name="trending-down" size={11} /> ต้องเติม</div></div>
          <div className="kpi"><div className="top"><div className="ic danger"><Lic name="x-circle" size={13} /></div> หมดสต็อก</div><div className="val" style={{color:'var(--danger-fg)'}}>{INVENTORY.filter(p=>p.stock===0).length}</div></div>
          <div className="kpi"><div className="top"><div className="ic info"><Lic name="archive" size={13} /></div> มูลค่าสต็อก</div><div className="val">฿1.2M</div><div className="delta up"><Lic name="trending-up" size={11} /> 8.4% MoM</div></div>
        </div>

        <div className="panel">
          <div className="filter-bar">
            <div className="search-input">
              <span className="ic"><Lic name="search" size={14} /></span>
              <input value={q} onChange={e=>setQ(e.target.value)} placeholder="ค้นหาสินค้า, SKU..." />
            </div>
            <div className="pills">
              {[['all','ทั้งหมด'],['med','ยาทั่วไป'],['vit','วิตามิน'],['cosm','ความงาม'],['kids','แม่และเด็ก'],['dev','อุปกรณ์']].map(([id,lbl]) => (
                <button key={id} className={cat===id?'active':''} onClick={()=>setCat(id)}>{lbl}</button>
              ))}
            </div>
          </div>
          <div style={{padding:16}}>
            <div className="product-grid">
              {filtered.map(p => (
                <div key={p.sku} className="prod-card">
                  <div className="img">
                    {p.stock === 0 && <span className="stock-badge out"><span className="dot" />หมด</span>}
                    {p.stock > 0 && p.stock < 20 && <span className="stock-badge low"><span className="dot" />ใกล้หมด</span>}
                    {p.stock >= 20 && <span className="stock-badge"><span className="dot" />ในสต็อก</span>}
                    <Lic name={p.ic} size={36} />
                  </div>
                  <div className="body">
                    <div className="sku">{p.sku}</div>
                    <div className="nm">{p.name}</div>
                    <div className="meta">
                      <span className="price">฿{p.price}</span>
                      <span className="stock">{p.stock} ในสต็อก</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { DashboardPage, CustomersPage, OrdersPage, ProductsPage, Spark, BigChart });
