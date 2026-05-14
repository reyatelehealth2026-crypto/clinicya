/* REYA Dashboard — customer mini-app (iPhone preview)
   Loaded via <script type="text/babel" src="reya-customer.jsx"></script>
*/

const { useState: useStateC } = React;

function MiniStatusBar() {
  return (
    <div className="status">
      <span>9:41</span>
      <span className="right">
        <Lic name="signal" size={12} />
        <Lic name="wifi" size={12} />
        <Lic name="battery-full" size={14} />
      </span>
    </div>
  );
}

function MiniHeader({ title, meta, showBack, onBack }) {
  return (
    <div className="mini-header">
      {showBack ? (
        <button className="ibtn" onClick={onBack}><Lic name="chevron-left" size={16} /></button>
      ) : (
        <div className="logo">R</div>
      )}
      <div style={{ flex: 1, minWidth: 0 }}>
        <h1>{title}</h1>
        {meta && <div className="meta">{meta}</div>}
      </div>
      <button className="ibtn"><Lic name="search" size={14} /></button>
      <button className="ibtn"><Lic name="bell" size={14} /></button>
    </div>
  );
}

function HomeScreenC({ go, points }) {
  return (
    <>
      <MiniHeader title="REYA" meta="พร้อมให้บริการ" />
      <div className="svc-banner-r">
        <span className="dot" />
        มีคำปรึกษา 1 รายการ รอเภสัชกร
      </div>

      <div className="member-card-r">
        <div className="top">
          <div className="star"><Lic name="star" size={16} /></div>
          <div className="row">
            <div className="av" />
            <div>
              <div className="nm">คุณ ปริมา ศิริ</div>
              <div className="id">ID: M-002847</div>
            </div>
          </div>
          <div className="points-row">
            <div>
              <div className="pts-lbl">แต้มสะสม</div>
              <div className="pts">{points.toLocaleString()}</div>
            </div>
            <div className="tier">Silver</div>
          </div>
        </div>
        <div className="bot">
          <div className="bot-row">
            <span style={{display:'inline-flex',alignItems:'center',gap:4}}>
              <Lic name="trending-up" size={12} /> ไปยัง Gold
            </span>
            <span className="pct">78%</span>
          </div>
          <div className="bar"><div className="bar-fill" style={{width:'78%'}} /></div>
          <div className="note">เหลืออีก <b style={{color:'var(--fg-2)'}}>200</b> แต้ม เพื่อเลื่อนเป็น Gold</div>
        </div>
      </div>

      <div className="quick-r">
        <button onClick={() => go('chat')}>
          <div className="qi"><Lic name="message-circle" size={14} /></div>
          <span>แชท AI<br/>เภสัชกร</span>
        </button>
        <button onClick={() => go('shop')}>
          <div className="qi info"><Lic name="store" size={14} /></div>
          <span>ร้านยา</span>
        </button>
        <button onClick={() => go('rewards')}>
          <div className="qi amber"><Lic name="gift" size={14} /></div>
          <span>แลก<br/>ของรางวัล</span>
        </button>
        <button onClick={() => go('orders')}>
          <div className="qi danger"><Lic name="package" size={14} /></div>
          <span>ออเดอร์<br/>ของฉัน</span>
        </button>
      </div>

      <div className="flash-r">
        <div className="head">
          <h3><Lic name="zap" size={13} /> Flash Sale</h3>
          <div className="timer"><span>02</span><span>14</span><span>33</span></div>
        </div>
        <div className="scroll">
          {PRODUCTS.slice(0, 4).map((p, i) => (
            <div key={i} className="product-r">
              <div className="img">
                <span className="discount">-{p.discount}%</span>
                <Lic name="pill" size={30} />
              </div>
              <div className="body">
                <div className="nm">{p.name}</div>
                <div className="price-row">
                  <span className="pr">฿{p.price}</span>
                  <span className="strike">฿{p.original}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div style={{height: 16}} />
    </>
  );
}

function RewardsScreenC({ go, points }) {
  const [redeemed, setRedeemed] = useStateC(new Set());

  return (
    <>
      <MiniHeader title="ของรางวัล" meta={`${points.toLocaleString()} แต้มสะสม`} showBack onBack={() => go('home')} />
      <div className="section-title-r">
        <span>หมวดหมู่</span>
        <span className="more">ดูทั้งหมด ›</span>
      </div>
      <div className="reward-grid-r">
        {REWARDS.map((r, i) => {
          const isRedeemed = redeemed.has(i);
          const canAfford = points >= r.pts;
          return (
            <div key={i} className="reward-r">
              <div className="img"><Lic name={r.ic} size={26} /></div>
              <div className="body">
                <div className="nm">{r.name}</div>
                <div className="pts">{r.pts.toLocaleString()}<span>แต้ม</span></div>
                <button
                  className={`btn${isRedeemed ? ' redeemed' : ''}`}
                  disabled={r.soldOut || (!canAfford && !isRedeemed)}
                  onClick={() => {
                    if (isRedeemed) return;
                    setRedeemed(new Set([...redeemed, i]));
                  }}
                >
                  {r.soldOut ? 'ของรางวัลหมด' : isRedeemed ? '✓ แลกแล้ว' : canAfford ? 'แลกเลย' : 'แต้มไม่พอ'}
                </button>
              </div>
            </div>
          );
        })}
      </div>
      <div style={{height: 16}} />
    </>
  );
}

function ShopScreenC({ go }) {
  return (
    <>
      <MiniHeader title="ร้านยา" meta="ค้นหาสินค้าและยา" showBack onBack={() => go('home')} />
      <div style={{ padding: 10 }}>
        <div style={{ position: 'relative' }}>
          <input
            style={{
              width: '100%', background: '#fff',
              border: '1px solid var(--border)', borderRadius: 12,
              padding: '9px 12px 9px 32px', fontSize: 11,
              fontFamily: 'inherit', outline: 'none',
            }}
            placeholder="ค้นหายา, วิตามิน, แบรนด์..."
          />
          <span style={{position:'absolute',left:10,top:10,color:'var(--fg-3)'}}>
            <Lic name="search" size={13} />
          </span>
        </div>
      </div>

      <div className="section-title-r">หมวดหมู่</div>
      <div style={{padding:'0 10px',display:'grid',gridTemplateColumns:'repeat(3,1fr)',gap:6}}>
        {[
          ['ยาทั่วไป','pill'],
          ['วิตามิน','leaf'],
          ['แม่และเด็ก','baby'],
          ['ความงาม','sparkles'],
          ['ช่องปาก','smile'],
          ['อุปกรณ์','stethoscope'],
        ].map(([nm, ic]) => (
          <div key={nm} style={{
            background:'#fff',borderRadius:10,padding:9,textAlign:'center',
            boxShadow:'var(--shadow-soft)',
          }}>
            <div style={{
              width:30,height:30,background:'var(--line-soft)',
              borderRadius:9,margin:'0 auto',display:'grid',placeItems:'center',
              color:'var(--brand-600)',
            }}><Lic name={ic} size={15} /></div>
            <div style={{fontSize:9,fontWeight:700,marginTop:6,lineHeight:1.2}}>{nm}</div>
          </div>
        ))}
      </div>

      <div className="flash-r">
        <div className="head">
          <h3><Lic name="zap" size={13} /> Flash Sale</h3>
          <div className="timer"><span>02</span><span>14</span><span>33</span></div>
        </div>
        <div className="scroll">
          {PRODUCTS.map((p, i) => (
            <div key={i} className="product-r">
              <div className="img">
                <span className="discount">-{p.discount}%</span>
                <Lic name="pill" size={30} />
              </div>
              <div className="body">
                <div className="nm">{p.name}</div>
                <div className="price-row">
                  <span className="pr">฿{p.price}</span>
                  <span className="strike">฿{p.original}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
      <div style={{height:16}} />
    </>
  );
}

function OrdersScreenC({ go }) {
  const orders = [
    { id: 'ORD-1280', name: 'พาราเซตามอล 500mg + 2 รายการ', date: '8 พ.ค. 2026', total: 425, status: 'paid', label: 'ชำระแล้ว' },
    { id: 'ORD-1279', name: 'วิตามิน C 1000mg', date: '6 พ.ค. 2026', total: 249, status: 'shipped', label: 'จัดส่งแล้ว' },
    { id: 'ORD-1278', name: 'ครีมกันแดด SPF 50', date: '5 พ.ค. 2026', total: 420, status: 'pending', label: 'รอชำระ' },
  ];
  return (
    <>
      <MiniHeader title="ออเดอร์ของฉัน" meta={`${orders.length} รายการ`} showBack onBack={() => go('home')} />
      <div style={{padding:10,display:'flex',flexDirection:'column',gap:8}}>
        {orders.map(o => (
          <div key={o.id} style={{
            background:'#fff',borderRadius:12,padding:10,
            boxShadow:'var(--shadow-soft)',
          }}>
            <div style={{display:'flex',justifyContent:'space-between',alignItems:'center'}}>
              <span style={{fontSize:10,fontFamily:'var(--font-mono)',color:'var(--fg-3)'}}>#{o.id}</span>
              <span style={{
                fontSize:9,fontWeight:700,padding:'2px 7px',borderRadius:9999,
                background: o.status==='paid'?'var(--success-bg)':o.status==='shipped'?'var(--info-bg)':'var(--warning-bg)',
                color: o.status==='paid'?'var(--success-fg)':o.status==='shipped'?'var(--info-fg)':'var(--warning-fg)',
              }}>● {o.label}</span>
            </div>
            <div style={{display:'flex',gap:8,marginTop:8,alignItems:'center'}}>
              <div style={{
                width:42,height:42,borderRadius:10,
                background:'linear-gradient(135deg, var(--line-soft), var(--brand-100))',
                display:'grid',placeItems:'center',color:'var(--brand-500)',
                flexShrink:0,
              }}><Lic name="package" size={20} /></div>
              <div style={{flex:1,minWidth:0}}>
                <div style={{fontSize:11,fontWeight:600,lineHeight:1.3}}>{o.name}</div>
                <div style={{fontSize:10,color:'var(--fg-3)',marginTop:2}}>{o.date}</div>
              </div>
              <div style={{fontSize:13,fontWeight:800,fontVariantNumeric:'tabular-nums'}}>฿{o.total}</div>
            </div>
          </div>
        ))}
      </div>
      <div style={{height:16}} />
    </>
  );
}

function ChatScreenC({ go, customerMsgs }) {
  return (
    <>
      <MiniHeader title="เภสัชกร REYA" meta="● ออนไลน์" showBack onBack={() => go('home')} />
      <div className="mini-chat">
        {customerMsgs.map((m, i) => (
          <div key={i} className={`bub ${m.from}`} style={{whiteSpace:'pre-wrap'}}>{m.text}</div>
        ))}
      </div>
    </>
  );
}

function CustomerApp({ currentConv }) {
  const [screen, setScreen] = useStateC('home');
  const [points] = useStateC(2847);

  // Mirror admin chat into customer view (translate the flow)
  const customerMsgs = currentConv ? currentConv.messages.filter(m => m.from !== 'ai-suggest').map(m => ({
    from: m.from === 'them' ? 'you' : 'ai',
    text: m.text,
  })) : [];

  const screens = {
    home: <HomeScreenC go={setScreen} points={points} />,
    rewards: <RewardsScreenC go={setScreen} points={points} />,
    shop: <ShopScreenC go={setScreen} />,
    orders: <OrdersScreenC go={setScreen} />,
    chat: <ChatScreenC go={setScreen} customerMsgs={customerMsgs} />,
  };

  return (
    <div className="iphone">
      <div className="notch" />
      <MiniStatusBar />
      <div className="mini-screen">
        {screens[screen]}
      </div>
      <div className="bn-r">
        {[
          ['home','หน้าหลัก','home'],
          ['shop','ร้านค้า','store'],
          ['cart','ตะกร้า','shopping-cart',2],
          ['orders','ออเดอร์','package'],
          ['profile','โปรไฟล์','user-round'],
        ].map(([id, label, ic, badge]) => (
          <button key={id} className={screen===id?'active':''} onClick={() => setScreen(id==='cart'||id==='profile'?'home':id)}>
            <div className="bi">
              <Lic name={ic} size={16} />
              {badge && <span className="badge-c">{badge}</span>}
            </div>
            <span>{label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { CustomerApp });
