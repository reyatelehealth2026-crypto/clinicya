/* REYA Dashboard — pages part B
   Rewards admin, Analytics, Telepharmacy, AI Co-Pilot Center, Settings
*/

const { useState: useStateB } = React;

// =========================================================================
// REWARDS ADMIN
// =========================================================================
function RewardsPage() {
  const [items, setItems] = useStateB(REWARDS.map((r,i) => ({ ...r, id: i, active: !r.soldOut, redeemed: Math.floor(20 + Math.random() * 180) })));

  const toggle = (id) => {
    setItems(prev => prev.map(r => r.id === id ? { ...r, active: !r.active } : r));
  };

  return (
    <div className="page" data-screen-label="Rewards">
      <div className="page-head">
        <div className="titles">
          <h1>ของรางวัล · Loyalty</h1>
          <div className="sub"><Lic name="gift" size={12} /> {items.length} รางวัล · {items.filter(r=>r.active).length} เปิดใช้งาน · 1,248 แลกในเดือนนี้</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="bar-chart-2" size={13} /> รายงาน</button>
          <button className="btn brand"><Lic name="plus" size={13} /> เพิ่มของรางวัล</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi"><div className="top"><div className="ic"><Lic name="gift" size={13} /></div> แลกเดือนนี้</div><div className="val">1,248</div><div className="delta up"><Lic name="trending-up" size={11} /> 18.2% MoM</div></div>
          <div className="kpi"><div className="top"><div className="ic info"><Lic name="coins" size={13} /></div> แต้มที่ใช้</div><div className="val">182k</div><div className="delta up"><Lic name="trending-up" size={11} /> 12.4%</div></div>
          <div className="kpi"><div className="top"><div className="ic success"><Lic name="repeat" size={13} /></div> Repeat rate</div><div className="val">68%</div><div className="delta up"><Lic name="trending-up" size={11} /> ลูกค้าแลกซ้ำ</div></div>
          <div className="kpi"><div className="top"><div className="ic warn"><Lic name="trending-up" size={13} /></div> มูลค่ารวม</div><div className="val">฿89,420</div><div className="delta up"><Lic name="trending-up" size={11} /> 9.1%</div></div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <div>
              <h3>ของรางวัลทั้งหมด</h3>
              <div className="sub">เปิด/ปิดเพื่อแสดง/ซ่อนจากลูกค้า</div>
            </div>
            <div className="tools">
              <button className="more">เรียงตาม: ยอดแลก ›</button>
            </div>
          </div>
          <div className="panel-body">
            <div style={{display:'grid',gridTemplateColumns:'repeat(auto-fill, minmax(220px, 1fr))',gap:12}}>
              {items.map(r => (
                <div key={r.id} className="reward-admin" style={{opacity: r.active ? 1 : 0.55}}>
                  <div className="img">
                    <button className={`switch toggle${r.active?' on':''}`} onClick={()=>toggle(r.id)} />
                    <Lic name={r.ic} size={32} />
                  </div>
                  <div className="body">
                    <div className="nm">{r.name}</div>
                    <div className="pts">{r.pts.toLocaleString()}<span>แต้ม</span></div>
                    <div className="stats">
                      <div className="stat">
                        <span className="k">แลกแล้ว</span>
                        <span className="v">{r.redeemed}</span>
                      </div>
                      <div className="stat">
                        <span className="k">สต็อก</span>
                        <span className="v" style={{color: r.soldOut ? 'var(--danger)' : 'var(--fg-1)'}}>{r.soldOut ? '0' : '∞'}</span>
                      </div>
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

// =========================================================================
// ANALYTICS
// =========================================================================
function AnalyticsPage() {
  return (
    <div className="page" data-screen-label="Analytics">
      <div className="page-head">
        <div className="titles">
          <h1>Analytics</h1>
          <div className="sub"><Lic name="trending-up" size={12} /> 30 วันล่าสุด · Powered by Odoo ERP</div>
        </div>
        <div className="actions">
          <div className="seg">
            <button>7 วัน</button>
            <button className="active">30 วัน</button>
            <button>90 วัน</button>
            <button>1 ปี</button>
          </div>
          <button className="btn ghost"><Lic name="calendar" size={13} /> ช่วงเอง</button>
          <button className="btn brand"><Lic name="download" size={13} /> Export</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi">
            <div className="top"><div className="ic"><Lic name="dollar-sign" size={13} /></div> รายได้รวม</div>
            <div className="val">฿1.24M</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 14.8% YoY</div>
            <div className="spark"><Spark points={[820,860,900,920,1050,1180,1240]} /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic info"><Lic name="shopping-cart" size={13} /></div> ออเดอร์รวม</div>
            <div className="val">1,142</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 12.1%</div>
            <div className="spark"><Spark points={[800,840,900,960,1020,1080,1142]} color="var(--info)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic warn"><Lic name="users-round" size={13} /></div> ลูกค้า active</div>
            <div className="val">847</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 8.2%</div>
            <div className="spark"><Spark points={[680,710,740,770,800,820,847]} color="var(--warning)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic success"><Lic name="sparkles" size={13} /></div> AI savings</div>
            <div className="val">฿38.2k</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 24.6%</div>
            <div className="spark"><Spark points={[18,22,26,28,32,35,38]} color="var(--success)" /></div>
          </div>
        </div>

        <div className="grid-2">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <div><h3>รายได้ 30 วัน</h3><div className="sub">เทียบกับเดือนก่อน</div></div>
              <div className="tools"><button className="more">รายละเอียด ›</button></div>
            </div>
            <div className="chart-panel">
              <div className="chart"><BigChart /></div>
              <div className="legend">
                <span><span className="dot" style={{background:'var(--brand-500)'}} />เดือนนี้ ฿1.24M</span>
                <span><span className="dot" style={{background:'var(--brand-200)'}} />เดือนก่อน ฿1.08M</span>
              </div>
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>หมวดสินค้าขายดี</h3></div>
            <div className="panel-body" style={{padding:0}}>
              {[
                ['ยาทั่วไป', 482, 42],
                ['วิตามิน', 318, 28],
                ['ความงาม', 184, 16],
                ['แม่และเด็ก', 98, 9],
                ['อุปกรณ์', 60, 5],
              ].map(([nm, n, pct], i) => (
                <div key={i} style={{padding:'12px 18px',borderBottom: i<4?'1px solid var(--divider)':0}}>
                  <div style={{display:'flex',justifyContent:'space-between',marginBottom:6}}>
                    <span style={{fontSize:13,fontWeight:600}}>{nm}</span>
                    <span style={{fontSize:12,color:'var(--fg-3)',fontVariantNumeric:'tabular-nums'}}>{n} ขาย · {pct}%</span>
                  </div>
                  <div style={{height:6,background:'var(--slate-100)',borderRadius:9999,overflow:'hidden'}}>
                    <div style={{height:'100%',width:`${pct*2.3}%`,background:'var(--brand-500)',borderRadius:9999}} />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3>ยอดขายรายวัน (30 วันล่าสุด)</h3>
            <div className="tools"><button className="more">ดูแบบรายชั่วโมง ›</button></div>
          </div>
          <div className="panel-body">
            <div className="bar-chart">
              {Array.from({length: 14}).map((_, i) => {
                const v = 30 + Math.round(Math.sin(i * 0.7) * 20) + Math.round(Math.random() * 25);
                const h = (v / 80) * 160;
                return (
                  <div key={i} className="bar">
                    <div className="b" style={{height: h}}>
                      {i === 13 && <span className="val">฿{v}k</span>}
                    </div>
                    <div className="lbl">{i+1}</div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        <div className="grid-3">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>ที่มาของลูกค้า</h3></div>
            <div className="panel-body">
              {[
                ['LINE Mini App', 62, 'var(--brand-500)'],
                ['Walk-in', 22, 'var(--info)'],
                ['Telepharmacy', 11, 'var(--warning)'],
                ['Other', 5, 'var(--slate-400)'],
              ].map(([nm, pct, c], i) => (
                <div key={i} style={{marginBottom:10}}>
                  <div style={{display:'flex',justifyContent:'space-between',fontSize:12,fontWeight:600,marginBottom:4}}>
                    <span>{nm}</span><span style={{fontVariantNumeric:'tabular-nums'}}>{pct}%</span>
                  </div>
                  <div style={{height:8,background:'var(--slate-100)',borderRadius:9999,overflow:'hidden'}}>
                    <div style={{height:'100%',width:`${pct}%`,background:c,borderRadius:9999}} />
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>เวลาที่ลูกค้ามากสุด</h3></div>
            <div className="panel-body">
              <div style={{display:'flex',alignItems:'flex-end',gap:3,height:120}}>
                {[20,28,40,52,68,72,68,82,90,84,72,60,52,44,38,32].map((v,i)=>(
                  <div key={i} style={{flex:1,background: i===8?'var(--brand-500)':'var(--brand-200)',height:`${v}%`,borderRadius:'3px 3px 0 0',position:'relative'}}>
                    {i===8 && <span style={{position:'absolute',top:-18,left:'50%',transform:'translateX(-50%)',fontSize:10,fontWeight:700,whiteSpace:'nowrap'}}>14:00</span>}
                  </div>
                ))}
              </div>
              <div style={{display:'flex',justifyContent:'space-between',fontSize:10,color:'var(--fg-3)',marginTop:6,fontFamily:'var(--font-mono)'}}>
                <span>8</span><span>11</span><span>14</span><span>17</span><span>20</span><span>23</span>
              </div>
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>คะแนนความพึงพอใจ</h3></div>
            <div className="panel-body" style={{textAlign:'center',padding:'20px 18px'}}>
              <div style={{fontSize:42,fontWeight:800,letterSpacing:'-0.02em',color:'var(--brand-600)',fontVariantNumeric:'tabular-nums'}}>4.8</div>
              <div style={{fontSize:14,color:'var(--warning-fg)',letterSpacing:'0.1em'}}>★★★★★</div>
              <div style={{fontSize:11,color:'var(--fg-3)',marginTop:4}}>จาก 247 รีวิว · NPS +68</div>
              <div style={{marginTop:14,display:'flex',flexDirection:'column',gap:4}}>
                {[5,4,3,2,1].map(s=>{
                  const pct = [78,16,4,1,1][5-s];
                  return (
                    <div key={s} style={{display:'flex',alignItems:'center',gap:6,fontSize:11}}>
                      <span style={{width:14,color:'var(--fg-3)'}}>{s}★</span>
                      <div style={{flex:1,height:5,background:'var(--slate-100)',borderRadius:9999,overflow:'hidden'}}>
                        <div style={{height:'100%',width:`${pct}%`,background: s>=4?'var(--success)': s===3?'var(--warning)':'var(--danger)',borderRadius:9999}} />
                      </div>
                      <span style={{width:28,textAlign:'right',color:'var(--fg-3)',fontVariantNumeric:'tabular-nums'}}>{pct}%</span>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// TELEPHARMACY
// =========================================================================
function TelepharmacyPage() {
  const days = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];
  const dates = [12, 13, 14, 15, 16, 17, 18];
  const todayIdx = 2;
  const times = ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
  // Make a schedule grid: day × time slot
  const schedule = {};
  CONSULTS.forEach(c => {
    const dayIdx = c.date.startsWith('14') ? 2 : c.date.startsWith('15') ? 3 : 4;
    const t = c.time.split(':')[0] + ':00';
    if (!schedule[dayIdx]) schedule[dayIdx] = {};
    if (!schedule[dayIdx][t]) schedule[dayIdx][t] = [];
    schedule[dayIdx][t].push(c);
  });

  return (
    <div className="page" data-screen-label="Telepharmacy">
      <div className="page-head">
        <div className="titles">
          <h1>Telepharmacy</h1>
          <div className="sub"><Lic name="video" size={12} /> 4 นัดวันนี้ · 2 นัดสัปดาห์นี้ · เภสัชกรพร้อม 2 คน</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="calendar" size={13} /> เดือน</button>
          <button className="btn brand"><Lic name="plus" size={13} /> สร้างนัด</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi"><div className="top"><div className="ic info"><Lic name="video" size={13} /></div> วันนี้</div><div className="val">4</div><div className="delta up">2 รออยู่</div></div>
          <div className="kpi"><div className="top"><div className="ic"><Lic name="calendar" size={13} /></div> สัปดาห์นี้</div><div className="val">18</div><div className="delta up"><Lic name="trending-up" size={11} /> 24%</div></div>
          <div className="kpi"><div className="top"><div className="ic success"><Lic name="clock" size={13} /></div> เฉลี่ย/นัด</div><div className="val">18 น.</div><div className="delta up">รวดเร็ว ↓ 2 น.</div></div>
          <div className="kpi"><div className="top"><div className="ic warn"><Lic name="star" size={13} /></div> คะแนน</div><div className="val">4.9 ★</div><div className="delta up">142 รีวิว</div></div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <div>
              <h3>ตารางนัดสัปดาห์นี้</h3>
              <div className="sub">12 – 18 พ.ค. 2569</div>
            </div>
            <div className="tools">
              <button className="more"><Lic name="chevron-left" size={12} /></button>
              <span style={{fontSize:11,color:'var(--fg-2)',fontWeight:600}}>สัปดาห์ 20</span>
              <button className="more">›</button>
            </div>
          </div>
          <div className="panel-body" style={{padding:14}}>
            <div className="schedule">
              <div className="h" />
              {days.map((d, i) => (
                <div key={i} className={`h${i===todayIdx?' today':''}`}>
                  {d}
                  <div className="d">{dates[i]}</div>
                </div>
              ))}
              {times.map(t => (
                <React.Fragment key={t}>
                  <div className="t">{t}</div>
                  {days.map((_, di) => {
                    const slots = schedule[di]?.[t] || [];
                    return (
                      <div key={di} className="cell">
                        {slots.map(s => (
                          <div key={s.id} className={`slot ${s.status==='completed'?'':'video'}`}>
                            <div style={{fontWeight:700}}>{s.time} · {s.customer}</div>
                            <div style={{opacity:0.75}}>{s.topic}</div>
                          </div>
                        ))}
                      </div>
                    );
                  })}
                </React.Fragment>
              ))}
            </div>
          </div>
        </div>

        <div className="grid-2">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>นัดที่กำลังจะถึง</h3></div>
            <div style={{padding:0}}>
              {CONSULTS.filter(c=>c.status==='scheduled').slice(0,4).map((c,i,arr)=>(
                <div key={c.id} style={{padding:'12px 18px',borderBottom: i<arr.length-1?'1px solid var(--divider)':0,display:'flex',alignItems:'center',gap:12}}>
                  <div style={{
                    width:38,height:38,borderRadius:'50%',
                    background:`linear-gradient(135deg, ${c.av[0]}, ${c.av[1]})`,
                    display:'grid',placeItems:'center',color:'#fff',fontWeight:700,
                  }}>{c.initial}</div>
                  <div style={{flex:1,minWidth:0}}>
                    <div style={{fontSize:13,fontWeight:700}}>{c.customer}</div>
                    <div style={{fontSize:11,color:'var(--fg-3)',marginTop:1}}>{c.topic}</div>
                  </div>
                  <div style={{textAlign:'right'}}>
                    <div style={{fontSize:12,fontWeight:700}}>{c.date} · {c.time}</div>
                    <div style={{fontSize:10,color:'var(--fg-3)'}}>{c.duration} นาที</div>
                  </div>
                  <button style={{
                    background:'var(--brand-500)',color:'#fff',border:0,
                    fontFamily:'inherit',fontSize:11,fontWeight:700,
                    padding:'6px 12px',borderRadius:8,cursor:'pointer',
                    display:'inline-flex',alignItems:'center',gap:4,
                  }}><Lic name="video" size={12} /> Join</button>
                </div>
              ))}
            </div>
          </div>

          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head"><h3>นัดเสร็จสิ้นล่าสุด</h3></div>
            <div style={{padding:0}}>
              {CONSULTS.filter(c=>c.status==='completed').map((c,i,arr)=>(
                <div key={c.id} style={{padding:'12px 18px',borderBottom: i<arr.length-1?'1px solid var(--divider)':0,display:'flex',alignItems:'center',gap:12}}>
                  <div style={{
                    width:38,height:38,borderRadius:'50%',
                    background:`linear-gradient(135deg, ${c.av[0]}, ${c.av[1]})`,
                    display:'grid',placeItems:'center',color:'#fff',fontWeight:700,
                  }}>{c.initial}</div>
                  <div style={{flex:1,minWidth:0}}>
                    <div style={{fontSize:13,fontWeight:700}}>{c.customer}</div>
                    <div style={{fontSize:11,color:'var(--fg-3)',marginTop:1}}>{c.topic} · {c.duration} นาที</div>
                  </div>
                  <span className="pill paid" style={{fontSize:10,padding:'2px 8px',borderRadius:9999,background:'var(--success-bg)',color:'var(--success-fg)',fontWeight:700,display:'inline-flex',alignItems:'center',gap:4}}><Lic name="check" size={10} /> เสร็จ</span>
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
// AI CO-PILOT CENTER
// =========================================================================
function AICopilotPage() {
  return (
    <div className="page" data-screen-label="AI Co-Pilot Center">
      <div className="page-head">
        <div className="titles">
          <h1>AI Co-Pilot Center <span style={{background:'var(--brand-500)',color:'#fff',fontSize:10,padding:'2px 8px',borderRadius:9999,marginLeft:8,verticalAlign:'middle',fontWeight:800,letterSpacing:'0.04em'}}>BETA</span></h1>
          <div className="sub"><Lic name="sparkles" size={12} /> ผู้ช่วย AI สำหรับเภสัชกร · ปรับแต่งและติดตามผล</div>
        </div>
        <div className="actions">
          <button className="btn ghost"><Lic name="book" size={13} /> Knowledge Base</button>
          <button className="btn brand"><Lic name="settings" size={13} /> Configure AI</button>
        </div>
      </div>

      <div className="page-body">
        <div className="ai-stat-grid">
          <div className="ai-stat">
            <div className="ic"><Lic name="sparkles" size={20} /></div>
            <div className="lbl">AI Suggestions</div>
            <div className="val">2,184</div>
            <div className="sub">+18.4% สัปดาห์นี้ · 184 วันนี้</div>
          </div>
          <div className="ai-stat">
            <div className="ic"><Lic name="check-circle" size={20} /></div>
            <div className="lbl">Accept rate</div>
            <div className="val">87%</div>
            <div className="sub">เภสัชกรยอมรับ AI suggest · เป้า 90%</div>
          </div>
          <div className="ai-stat">
            <div className="ic"><Lic name="clock" size={20} /></div>
            <div className="lbl">Time saved</div>
            <div className="val">142 ชม.</div>
            <div className="sub">เดือนนี้ · ประหยัด ฿38.2k</div>
          </div>
        </div>

        <div className="grid-2">
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <h3>AI Suggestion Log</h3>
              <div className="tools">
                <button className="more">ดูทั้งหมด ›</button>
              </div>
            </div>
            <div style={{padding:0}}>
              {AI_LOG.map((l, i) => (
                <div key={i} className="ai-log-item">
                  <div className="av" style={{background:`linear-gradient(135deg, ${l.av[0]}, ${l.av[1]})`}}>{l.initial}</div>
                  <div className="body">
                    <div className="top">
                      <span className="nm">{l.customer}</span>
                      <span className="meta">· {l.topic}</span>
                      <span className="meta" style={{marginLeft:'auto',fontFamily:'var(--font-mono)'}}>{l.time}</span>
                    </div>
                    <div className="txt">{l.suggestion}</div>
                    <div style={{display:'flex',gap:6,marginTop:6,alignItems:'center'}}>
                      <span className={`outcome ${l.outcome}`}>
                        <Lic name={l.outcome==='accepted'?'check':l.outcome==='edited'?'pen':'x'} size={10} />
                        {l.outcome === 'accepted' ? 'ใช้คำตอบ' : l.outcome === 'edited' ? 'แก้ไขแล้วใช้' : 'ปฏิเสธ'}
                      </span>
                      <span style={{fontSize:10,color:'var(--fg-3)'}}>ความมั่นใจ {l.confidence}%</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div>
            <div className="panel" style={{marginTop:0}}>
              <div className="panel-head"><h3>AI Configuration</h3></div>
              <div className="panel-body">
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',padding:'8px 0',borderBottom:'1px solid var(--divider)'}}>
                  <div>
                    <div style={{fontSize:13,fontWeight:600}}>เปิด AI Co-Pilot</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>แสดง suggest แบบ real-time</div>
                  </div>
                  <button className="switch on" />
                </div>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',padding:'8px 0',borderBottom:'1px solid var(--divider)'}}>
                  <div>
                    <div style={{fontSize:13,fontWeight:600}}>ตรวจสอบ Drug Interaction</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>เตือนเมื่อมีประวัติแพ้ยา</div>
                  </div>
                  <button className="switch on" />
                </div>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',padding:'8px 0',borderBottom:'1px solid var(--divider)'}}>
                  <div>
                    <div style={{fontSize:13,fontWeight:600}}>Auto-send response</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>ส่งอัตโนมัติเมื่อ confidence &gt; 95%</div>
                  </div>
                  <button className="switch" />
                </div>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',padding:'8px 0'}}>
                  <div>
                    <div style={{fontSize:13,fontWeight:600}}>Voice mode (Beta)</div>
                    <div style={{fontSize:11,color:'var(--fg-3)'}}>แปลงเสียงเป็นข้อความ</div>
                  </div>
                  <button className="switch" />
                </div>
              </div>
            </div>

            <div className="panel" style={{marginTop:12}}>
              <div className="panel-head"><h3>Knowledge sources</h3></div>
              <div className="panel-body">
                {[
                  ['Thai Pharmacopoeia 2023', 'TPC', 12420, true, 'brand'],
                  ['Drug Interaction DB', 'DIDB', 8240, true, 'info'],
                  ['REYA Product Catalog', 'REYA', 2184, true, 'success'],
                  ['Customer History', 'CRM', 2847, true, 'warn'],
                ].map(([nm, abbr, n, on, c], i) => (
                  <div key={i} style={{display:'flex',alignItems:'center',gap:10,padding:'8px 0',borderBottom: i<3?'1px solid var(--divider)':0}}>
                    <div style={{
                      width:32,height:32,borderRadius:8,
                      background: c==='brand'?'var(--line-soft)': c==='info'?'var(--info-bg)': c==='success'?'var(--success-bg)':'var(--warning-bg)',
                      color: c==='brand'?'var(--brand-600)': c==='info'?'var(--info-fg)': c==='success'?'var(--success-fg)':'var(--warning-fg)',
                      display:'grid',placeItems:'center',fontWeight:800,fontSize:10,
                    }}>{abbr}</div>
                    <div style={{flex:1}}>
                      <div style={{fontSize:12,fontWeight:600}}>{nm}</div>
                      <div style={{fontSize:10,color:'var(--fg-3)'}}>{n.toLocaleString()} entries</div>
                    </div>
                    <button className={`switch${on?' on':''}`} />
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// SETTINGS
// =========================================================================
function SettingsPage() {
  const [sec, setSec] = useStateB('pharmacy');

  return (
    <div className="page" data-screen-label="Settings">
      <div className="page-head">
        <div className="titles">
          <h1>ตั้งค่า</h1>
          <div className="sub"><Lic name="settings" size={12} /> ร้านยา REYA สาขา สีลม · เภสัชกรหลัก ภญ. นภัสสร</div>
        </div>
        <div className="actions">
          <button className="btn brand"><Lic name="check" size={13} /> บันทึก</button>
        </div>
      </div>

      <div className="page-body">
        <div className="settings-grid">
          <nav className="settings-nav">
            {[
              ['pharmacy', 'ข้อมูลร้านยา', 'store'],
              ['staff', 'พนักงาน', 'users-round'],
              ['integrations', 'การเชื่อมต่อ', 'plug'],
              ['notifications', 'การแจ้งเตือน', 'bell'],
              ['ai', 'AI Co-Pilot', 'sparkles'],
              ['billing', 'การชำระเงิน', 'credit-card'],
              ['security', 'ความปลอดภัย', 'shield'],
            ].map(([id, lbl, ic]) => (
              <button key={id} className={sec===id?'active':''} onClick={()=>setSec(id)}>
                <Lic name={ic} size={15} />
                <span>{lbl}</span>
              </button>
            ))}
          </nav>

          <div className="settings-content">
            {sec === 'pharmacy' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>ข้อมูลร้านยา</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>ข้อมูลร้านนี้จะแสดงในใบเสร็จและ LINE Mini App</p>
                <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:14}}>
                  <div className="form-row">
                    <label>ชื่อร้าน</label>
                    <input defaultValue="ร้านยา REYA สาขาสีลม" />
                  </div>
                  <div className="form-row">
                    <label>เลขที่ใบอนุญาต</label>
                    <input defaultValue="กข.5-25-101010101010" />
                  </div>
                  <div className="form-row">
                    <label>เภสัชกรผู้รับผิดชอบ</label>
                    <input defaultValue="ภญ. นภัสสร สิทธิ์ชนะวงศ์" />
                  </div>
                  <div className="form-row">
                    <label>เลขที่ใบประกอบวิชาชีพ</label>
                    <input defaultValue="ภ.42124" />
                  </div>
                  <div className="form-row" style={{gridColumn:'1/-1'}}>
                    <label>ที่อยู่</label>
                    <textarea rows="2" defaultValue="123/45 ถนนสีลม แขวงสีลม เขตบางรัก กรุงเทพฯ 10500" />
                  </div>
                  <div className="form-row">
                    <label>เบอร์โทรศัพท์</label>
                    <input defaultValue="02-234-5678" />
                  </div>
                  <div className="form-row">
                    <label>เวลาทำการ</label>
                    <input defaultValue="08:00 – 21:00 ทุกวัน" />
                  </div>
                  <div className="form-row">
                    <label>LINE Official ID</label>
                    <input defaultValue="@reyapharma" />
                  </div>
                  <div className="form-row">
                    <label>เลขประจำตัวผู้เสียภาษี</label>
                    <input defaultValue="0123456789012" />
                  </div>
                </div>
              </>
            )}
            {sec === 'staff' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>พนักงานในร้าน</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>5 คน · ออนไลน์ 3 คน</p>
                <table className="tbl" style={{border:'1px solid var(--border)',borderRadius:10}}>
                  <thead><tr><th>ชื่อ</th><th>ตำแหน่ง</th><th>สิทธิ์</th><th>สถานะ</th><th></th></tr></thead>
                  <tbody>
                    {[
                      ['ภญ. นภัสสร สิทธิ์ชนะวงศ์', 'Senior Pharmacist', 'Admin', 'online'],
                      ['ภญ. รุ่งทิวา ศรีสวัสดิ์', 'Pharmacist', 'Admin', 'online'],
                      ['คุณ ฐิติพร พัฒนกุล', 'พนักงานขาย', 'Staff', 'online'],
                      ['คุณ ภานุพงศ์ ทิพย์เกษม', 'พนักงานขาย', 'Staff', 'offline'],
                      ['คุณ ปวีณา จิตรเอื้อ', 'พนักงานขาย', 'Staff', 'offline'],
                    ].map(([nm, role, perm, st], i) => (
                      <tr key={i}>
                        <td>
                          <div className="cust">
                            <div className="av" style={{background:`linear-gradient(135deg, ${AV_COLORS[i%AV_COLORS.length][0]}, ${AV_COLORS[i%AV_COLORS.length][1]})`}}>{nm.split(' ').pop()[0]}</div>
                            <div className="nm">{nm}</div>
                          </div>
                        </td>
                        <td>{role}</td>
                        <td><span style={{background:perm==='Admin'?'var(--line-soft)':'var(--slate-100)',color:perm==='Admin'?'var(--brand-700)':'var(--fg-2)',padding:'2px 8px',borderRadius:9999,fontSize:11,fontWeight:700}}>{perm}</span></td>
                        <td>{st==='online' ? <span className="pill paid">Online</span> : <span style={{color:'var(--fg-3)',fontSize:11}}>Offline</span>}</td>
                        <td><div className="actions"><button className="ibtn"><Lic name="more-horizontal" size={14} /></button></div></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <button className="btn brand" style={{marginTop:14}}><Lic name="user-plus" size={13} /> เพิ่มพนักงาน</button>
              </>
            )}
            {sec === 'integrations' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>การเชื่อมต่อ</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>เชื่อมต่อระบบภายนอกกับ REYA</p>
                {[
                  ['LINE Official Account', '@reyapharma · 12,420 ผู้ติดตาม', 'message-circle', true, 'var(--success)'],
                  ['Odoo ERP', 'sync ข้อมูลทุก 5 นาที', 'database', true, 'var(--success)'],
                  ['PromptPay', 'รับชำระอัตโนมัติ', 'qr-code', true, 'var(--success)'],
                  ['Kerry / Flash Express', 'จัดส่งอัตโนมัติ', 'truck', true, 'var(--success)'],
                  ['Google Maps', 'แสดงตำแหน่งร้านใน LINE', 'map-pin', false, 'var(--fg-3)'],
                  ['Stripe', 'รับบัตรเครดิตต่างประเทศ', 'credit-card', false, 'var(--fg-3)'],
                ].map(([nm, sub, ic, on, c], i, arr) => (
                  <div key={i} style={{display:'flex',alignItems:'center',gap:14,padding:'14px 0',borderBottom:i<arr.length-1?'1px solid var(--divider)':0}}>
                    <div style={{width:42,height:42,borderRadius:10,background:on?'var(--line-soft)':'var(--slate-100)',color:on?'var(--brand-600)':'var(--fg-3)',display:'grid',placeItems:'center'}}><Lic name={ic} size={20} /></div>
                    <div style={{flex:1,minWidth:0}}>
                      <div style={{fontSize:13,fontWeight:700,display:'flex',alignItems:'center',gap:6}}>
                        {nm}
                        {on && <span style={{width:6,height:6,borderRadius:'50%',background:c}} />}
                      </div>
                      <div style={{fontSize:11,color:'var(--fg-3)',marginTop:2}}>{sub}</div>
                    </div>
                    <button className={on?'btn ghost':'btn brand'} style={{fontSize:11,padding:'6px 12px'}}>{on?'จัดการ':'เชื่อมต่อ'}</button>
                  </div>
                ))}
              </>
            )}
            {sec === 'notifications' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>การแจ้งเตือน</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>เลือกเหตุการณ์ที่อยากให้แจ้งเตือน</p>
                {[
                  ['ข้อความใหม่จากลูกค้า', 'แชทใน LINE Inbox', true, true],
                  ['ออเดอร์ใหม่', 'มีคนสั่งสินค้าผ่าน Mini App', true, false],
                  ['ออเดอร์รอชำระเกิน 30 นาที', 'เตือนเมื่อมี pending order', true, true],
                  ['สต็อกใกล้หมด', 'เมื่อสต็อก < 20 ชิ้น', false, true],
                  ['ลูกค้า VIP ออนไลน์', 'แจ้งทันทีเมื่อ VIP เปิดแอป', false, false],
                  ['AI suggest ความมั่นใจต่ำ', 'เมื่อ AI ไม่แน่ใจ < 70%', true, false],
                  ['รีวิวลูกค้าใหม่', 'รีวิว 4 ดาวขึ้นไป', true, true],
                ].map(([nm, sub, push, email], i, arr) => (
                  <div key={i} style={{display:'flex',alignItems:'center',padding:'12px 0',borderBottom:i<arr.length-1?'1px solid var(--divider)':0,gap:14}}>
                    <div style={{flex:1}}>
                      <div style={{fontSize:13,fontWeight:600}}>{nm}</div>
                      <div style={{fontSize:11,color:'var(--fg-3)',marginTop:2}}>{sub}</div>
                    </div>
                    <div style={{display:'flex',gap:14,alignItems:'center'}}>
                      <div style={{display:'flex',alignItems:'center',gap:6,fontSize:11,fontWeight:600,color:'var(--fg-2)'}}>Push <button className={`switch${push?' on':''}`} /></div>
                      <div style={{display:'flex',alignItems:'center',gap:6,fontSize:11,fontWeight:600,color:'var(--fg-2)'}}>Email <button className={`switch${email?' on':''}`} /></div>
                    </div>
                  </div>
                ))}
              </>
            )}
            {sec === 'ai' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>AI Co-Pilot</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>ปรับแต่งพฤติกรรม AI ของคุณ — รายละเอียดในเมนู AI Co-Pilot Center</p>
                <div className="form-row">
                  <label>โมเดล AI</label>
                  <select defaultValue="claude-haiku">
                    <option value="claude-haiku">Claude Haiku (เร็ว ประหยัด)</option>
                    <option value="claude-sonnet">Claude Sonnet (ฉลาดขึ้น)</option>
                  </select>
                  <div className="help">โมเดล Sonnet ใช้ทรัพยากรเพิ่มขึ้น 5x แต่ accuracy สูงขึ้น 12%</div>
                </div>
                <div className="form-row">
                  <label>ความมั่นใจขั้นต่ำที่จะ suggest (%)</label>
                  <input type="number" defaultValue="70" min="50" max="95" />
                  <div className="help">AI จะแสดง suggest ก็ต่อเมื่อความมั่นใจสูงกว่าค่านี้</div>
                </div>
                <div className="form-row">
                  <label>ภาษาที่ใช้ตอบ</label>
                  <select>
                    <option>ไทย (ค่าเริ่มต้น)</option>
                    <option>ไทย + อังกฤษ (term ทางการแพทย์)</option>
                    <option>อังกฤษ</option>
                  </select>
                </div>
                <div className="form-row">
                  <label>โทนการตอบ</label>
                  <select>
                    <option>เป็นมิตร เป็นกันเอง (ค่าเริ่มต้น)</option>
                    <option>ทางการ สุภาพ</option>
                    <option>กระชับ ตรงประเด็น</option>
                  </select>
                </div>
              </>
            )}
            {sec === 'billing' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>การชำระเงิน · Plan</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>แพ็คเกจปัจจุบัน: <b style={{color:'var(--brand-600)'}}>Professional</b> · ต่ออายุ 14 มิ.ย.</p>
                <div style={{background:'var(--brand-gradient)',color:'#fff',borderRadius:14,padding:24,marginBottom:18}}>
                  <div style={{display:'flex',alignItems:'center',justifyContent:'space-between'}}>
                    <div>
                      <div style={{fontSize:11,opacity:0.85,letterSpacing:'0.1em',textTransform:'uppercase',fontWeight:700}}>Current Plan</div>
                      <div style={{fontSize:24,fontWeight:800,marginTop:4}}>Professional</div>
                      <div style={{fontSize:12,opacity:0.85,marginTop:2}}>฿2,990/เดือน · 5 staff · unlimited messages · AI Co-Pilot</div>
                    </div>
                    <button style={{background:'#fff',color:'var(--brand-600)',border:0,fontFamily:'inherit',padding:'8px 16px',borderRadius:9,fontSize:13,fontWeight:700,cursor:'pointer'}}>อัพเกรด</button>
                  </div>
                </div>
                <h4 style={{fontSize:13,fontWeight:800,margin:'18px 0 10px',color:'var(--fg-2)',textTransform:'uppercase',letterSpacing:'0.08em'}}>ประวัติการชำระ</h4>
                <table className="tbl" style={{border:'1px solid var(--border)',borderRadius:10}}>
                  <thead><tr><th>เดือน</th><th>จำนวน</th><th>สถานะ</th><th>วันที่</th><th></th></tr></thead>
                  <tbody>
                    {['พฤษภาคม 2569','เมษายน 2569','มีนาคม 2569','กุมภาพันธ์ 2569'].map((m,i) => (
                      <tr key={m}><td>{m}</td><td style={{fontWeight:700,fontVariantNumeric:'tabular-nums'}}>฿2,990</td><td><span className="pill paid">ชำระแล้ว</span></td><td style={{color:'var(--fg-2)',fontSize:12}}>{14-i} {m.split(' ')[0]}</td><td><button className="ibtn" style={{width:24,height:24,border:0,background:'transparent',color:'var(--fg-2)',cursor:'pointer'}}><Lic name="download" size={13} /></button></td></tr>
                    ))}
                  </tbody>
                </table>
              </>
            )}
            {sec === 'security' && (
              <>
                <h3 style={{margin:'0 0 4px',fontSize:16,fontWeight:800}}>ความปลอดภัย</h3>
                <p style={{fontSize:12,color:'var(--fg-2)',margin:'0 0 18px'}}>การรักษาความปลอดภัยบัญชีและข้อมูลลูกค้า</p>
                {[
                  ['2-Factor Authentication', 'ต้องยืนยันตัวตน 2 ขั้นเมื่อเข้าระบบ', true],
                  ['Audit log', 'บันทึกการเข้าถึงข้อมูลลูกค้าทุกครั้ง', true],
                  ['Auto logout', 'ออกจากระบบอัตโนมัติเมื่อไม่ได้ใช้งาน 30 นาที', true],
                  ['IP whitelist', 'อนุญาตเฉพาะ IP ที่กำหนด', false],
                  ['HIPAA-style data handling', 'จัดเก็บข้อมูลตามมาตรฐานสากล', true],
                ].map(([nm, sub, on], i, arr) => (
                  <div key={i} style={{display:'flex',alignItems:'center',padding:'12px 0',borderBottom:i<arr.length-1?'1px solid var(--divider)':0}}>
                    <div style={{flex:1}}>
                      <div style={{fontSize:13,fontWeight:600}}>{nm}</div>
                      <div style={{fontSize:11,color:'var(--fg-3)',marginTop:2}}>{sub}</div>
                    </div>
                    <button className={`switch${on?' on':''}`} />
                  </div>
                ))}
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { RewardsPage, AnalyticsPage, TelepharmacyPage, AICopilotPage, SettingsPage });
