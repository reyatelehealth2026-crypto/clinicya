/* REYA Dashboard — admin view components
   Loaded via <script type="text/babel" src="reya-admin.jsx"></script>
*/

const { useState, useEffect, useRef, useMemo } = React;

// ─── Icon helper (Lucide via CSS mask) ─────────────────────────────────
function Lic({ name, size = 18 }) {
  // Prefer pre-bundled blob URL from window.__resources (standalone build),
  // fall back to lucide-static CDN for online dev.
  const url = (window.__resources && window.__resources['icon-' + name])
    || `https://unpkg.com/lucide-static@latest/icons/${name}.svg`;
  return (
    <span
      className="lic"
      style={{
        width: size, height: size,
        WebkitMask: `url(${url}) center/contain no-repeat`,
        mask: `url(${url}) center/contain no-repeat`,
      }}
    />
  );
}

// Avatar with gradient
function Avatar({ initial, colors, size = 40, online = false }) {
  return (
    <div
      className={`av${online ? ' online' : ''}`}
      style={{
        width: size, height: size,
        background: `linear-gradient(135deg, ${colors[0]}, ${colors[1]})`,
        borderRadius: '50%',
        display: 'grid', placeItems: 'center',
        color: '#fff', fontWeight: 700,
        fontSize: size * 0.4,
        flexShrink: 0,
        position: 'relative',
      }}
    >
      {initial}
      {online && (
        <span style={{
          position: 'absolute', right: -1, bottom: -1,
          width: size * 0.26, height: size * 0.26,
          borderRadius: '50%',
          background: 'var(--success)',
          border: '2px solid #fff',
        }} />
      )}
    </div>
  );
}

// ─── Sidebar ────────────────────────────────────────────────────────────
function Sidebar({ active, onNav, unreadCount }) {
  const main = [
    ['dashboard', 'แดชบอร์ด', 'layout-dashboard', null],
    ['inbox', 'Inbox', 'message-circle', unreadCount],
    ['customers', 'ลูกค้า CRM', 'users-round', null],
    ['orders', 'ออเดอร์', 'package', 12],
    ['products', 'สินค้า', 'pill', null],
    ['rewards', 'ของรางวัล', 'gift', null],
  ];
  const ops = [
    ['analytics', 'Analytics', 'trending-up'],
    ['telepharmacy', 'Telepharmacy', 'video'],
    ['ai-copilot', 'AI Co-Pilot', 'sparkles'],
    ['settings', 'ตั้งค่า', 'settings'],
  ];
  return (
    <aside className="side">
      <div className="brand">
        <div className="mk">R</div>
        <div>
          <div className="name">REYA</div>
          <div className="sub">Pharmacy · Admin</div>
        </div>
      </div>

      <div className="group">หลัก</div>
      {main.map(([id, label, ic, badge]) => (
        <a key={id} className={active === id ? 'active' : ''} onClick={() => onNav(id)}>
          <Lic name={ic} size={17} />
          <span>{label}</span>
          {badge ? <span className="badge">{badge}</span> : null}
        </a>
      ))}

      <div className="group">Operations</div>
      {ops.map(([id, label, ic]) => (
        <a key={id} className={active === id ? 'active' : ''} onClick={() => onNav(id)}>
          <Lic name={ic} size={17} />
          <span>{label}</span>
        </a>
      ))}

      <div className="pharmacist-card">
        <div className="av">น</div>
        <div>
          <div className="nm">ภญ. นภัสสร</div>
          <div className="rl">Senior Pharmacist</div>
        </div>
        <div className="status" />
      </div>
    </aside>
  );
}

// ─── Inbox list ─────────────────────────────────────────────────────────
function InboxList({ items, activeId, onSelect }) {
  const [q, setQ] = useState('');
  const [tab, setTab] = useState('all');

  const filtered = useMemo(() => {
    return items.filter(it => {
      if (tab === 'vip' && !it.vip) return false;
      if (tab === 'unread' && !it.unread) return false;
      if (tab === 'online' && !it.online) return false;
      if (q && !it.name.includes(q) && !it.preview.includes(q)) return false;
      return true;
    });
  }, [items, tab, q]);

  const counts = {
    all: items.length,
    vip: items.filter(i => i.vip).length,
    unread: items.filter(i => i.unread).length,
    online: items.filter(i => i.online).length,
  };

  return (
    <div className="inbox">
      <div className="inbox-head">
        <div className="row">
          <h2>INBOX</h2>
          <span className="count">{filtered.length}</span>
        </div>
        <div className="search">
          <span className="ic"><Lic name="search" size={14} /></span>
          <input
            placeholder="ค้นหาชื่อ, อาการ, ยา..."
            value={q}
            onChange={e => setQ(e.target.value)}
          />
        </div>
      </div>

      <div className="inbox-tabs">
        {[
          ['all', 'ทั้งหมด'],
          ['vip', 'VIP'],
          ['unread', 'ยังไม่อ่าน'],
          ['online', 'ออนไลน์'],
        ].map(([id, label]) => (
          <button
            key={id}
            className={tab === id ? 'active' : ''}
            onClick={() => setTab(id)}
          >
            {label}<span className="n">{counts[id]}</span>
          </button>
        ))}
      </div>

      <div className="inbox-list">
        {filtered.map(it => (
          <div
            key={it.id}
            className={`inbox-item${activeId === it.id ? ' active' : ''}`}
            onClick={() => onSelect(it.id)}
          >
            <Avatar initial={it.initial} colors={it.avatarColors} size={42} online={it.online} />
            <div className="body">
              <div className="top">
                <span className="nm">{it.name}</span>
                {it.vip && <span className="vip-pill">VIP</span>}
                <span className="time">{it.time}</span>
              </div>
              <div className="preview">{it.preview}</div>
            </div>
            {it.unread > 0 && <div className="unread">{it.unread}</div>}
          </div>
        ))}
        {filtered.length === 0 && (
          <div style={{ textAlign: 'center', padding: 32, color: 'var(--fg-3)', fontSize: 12 }}>
            ไม่พบรายการ
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Chat thread ───────────────────────────────────────────────────────
function ChatThread({ conv, onSendMessage, onUseAiReply }) {
  const [input, setInput] = useState('');
  const bodyRef = useRef(null);

  useEffect(() => {
    if (bodyRef.current) {
      bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
    }
  }, [conv.messages.length, conv.id]);

  const send = () => {
    if (!input.trim()) return;
    onSendMessage(input.trim());
    setInput('');
  };

  return (
    <div className="thread">
      <div className="thread-head">
        <Avatar initial={conv.initial} colors={conv.avatarColors} size={40} />
        <div className="meta">
          <div className="nm-row">
            <span className="nm">{conv.name}</span>
            {conv.vip && <span className="vip-pill">VIP</span>}
            <span className="pts-pill">{conv.points.toLocaleString()} แต้ม · {conv.tier}</span>
          </div>
          <div className="sub">
            {conv.online && <span className="dot-on" />}
            <span>{conv.lastSeen}</span>
            <span>·</span>
            <span>{conv.age} ปี · {conv.gender}</span>
            {conv.allergies.length > 0 && (
              <><span>·</span><span style={{color:'var(--warning-fg)'}}>แพ้: {conv.allergies.join(', ')}</span></>
            )}
          </div>
        </div>
        <div className="actions">
          <button className="ibtn" title="โทร"><Lic name="phone" size={16} /></button>
          <button className="ibtn" title="วิดีโอ"><Lic name="video" size={16} /></button>
          <button className="ibtn" title="ข้อมูลลูกค้า"><Lic name="info" size={16} /></button>
          <button className="ibtn" title="เพิ่มเติม"><Lic name="more-horizontal" size={16} /></button>
        </div>
      </div>

      <div className="thread-body" ref={bodyRef}>
        <div className="day-divider">วันนี้</div>
        {conv.messages.map((m, i) => {
          if (m.from === 'ai-suggest') {
            return (
              <div key={i} className="ai-suggest fade-in" onClick={() => onUseAiReply(m.text)}>
                <div className="label"><Lic name="sparkles" size={11} /> AI SUGGEST</div>
                <div className="body">{m.text}</div>
                <div className="hint">คลิกเพื่อใช้เป็นคำตอบ</div>
              </div>
            );
          }
          if (m.from === 'them') {
            return (
              <div key={i} className="msg-row them">
                <Avatar initial={conv.initial} colors={conv.avatarColors} size={28} />
                <div className="bubble">{m.text}</div>
                <span className="time">{m.time}</span>
              </div>
            );
          }
          return (
            <div key={i} className="msg-row you">
              <span className="time">{m.time}</span>
              <div className="bubble" style={{whiteSpace:'pre-wrap'}}>{m.text}</div>
            </div>
          );
        })}
      </div>

      <div className="composer">
        {conv.copilot.suggestedFollowups.length > 0 && (
          <div className="ai-suggest-strip">
            <span style={{
              fontSize: 10, fontWeight: 800, color: 'var(--brand-700)',
              letterSpacing: '0.06em', alignSelf: 'center',
              marginRight: 4, display: 'inline-flex', gap: 3, alignItems: 'center',
            }}>
              <Lic name="sparkles" size={11} />
              คำถามถัดไป:
            </span>
            {conv.copilot.suggestedFollowups.map((s, i) => (
              <button
                key={i}
                className="ai-chip"
                onClick={() => setInput(s)}
              >
                {s}
              </button>
            ))}
          </div>
        )}
        <div className="input-row">
          <button className="iicon" title="แนบไฟล์"><Lic name="paperclip" size={16} /></button>
          <button className="iicon" title="รูปภาพ"><Lic name="image" size={16} /></button>
          <input
            placeholder="พิมพ์ข้อความถึงลูกค้า..."
            value={input}
            onChange={e => setInput(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') send(); }}
          />
          <button
            className="send"
            onClick={send}
            disabled={!input.trim()}
            title="ส่ง"
          >
            <Lic name="send" size={16} />
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── AI Co-Pilot panel ─────────────────────────────────────────────────
function CoPilot({ conv, onUseReply, onQuickAction }) {
  const cp = conv.copilot;
  const replyRef = useRef(null);

  // Stream-in the suggested reply when conversation changes
  useEffect(() => {
    if (replyRef.current && window.reyaStream) {
      window.reyaStream(replyRef.current, cp.reply, { speed: 14 });
    } else if (replyRef.current) {
      replyRef.current.textContent = cp.reply;
    }
  }, [conv.id]);
  return (
    <aside className="copilot">
      <div className="copilot-head">
        <div className="badge"><Lic name="sparkles" size={16} /></div>
        <div>
          <h3>AI CO-PILOT</h3>
          <div className="sub"><span className="dot-live" /> วิเคราะห์การสนทนาแบบ real-time</div>
        </div>
      </div>

      {/* Conversation summary */}
      <div className="copilot-section">
        <div className="label">สรุปบทสนทนา</div>
        <div style={{
          fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.55,
          background: '#fff', border: '1px solid var(--border)',
          borderRadius: 10, padding: '10px 12px',
        }}>
          {cp.summary}
        </div>
      </div>

      {/* Suggested reply */}
      <div className="copilot-section">
        <div className="label">
          คำตอบที่แนะนำ
          <span className="ai-tag">AI</span>
        </div>
        <div className="suggested-reply">
          <div className="text" ref={replyRef} style={{whiteSpace:'pre-wrap'}}>{cp.reply}</div>
          <div className="actions">
            <button onClick={() => {
              onQuickAction('regenerate');
              if (replyRef.current && window.reyaStream) {
                window.reyaStream(replyRef.current, cp.reply, { speed: 14 });
              }
            }}>
              <Lic name="refresh-cw" size={11} /> ใหม่
            </button>
            <button onClick={() => onQuickAction('edit')}>
              <Lic name="pen" size={11} /> แก้ไข
            </button>
            <button className="primary" onClick={() => onUseReply(cp.reply)}>
              <Lic name="send" size={11} /> ใช้คำตอบนี้
            </button>
          </div>
          <div className="meta">
            <Lic name="badge-check" size={11} />
            <span>ความมั่นใจ {cp.confidence}% · อ้างอิงจาก Medical KB</span>
          </div>
        </div>
      </div>

      {/* Drug interaction warning (if any) */}
      {cp.warning && (
        <div className="copilot-section">
          <div className="label" style={{color:'var(--warning-fg)'}}>
            <Lic name="alert-triangle" size={11} /> คำเตือน
          </div>
          <div className="warning-card">
            <div className="ic"><Lic name="alert-triangle" size={18} /></div>
            <div>
              <div className="nm">หลีกเลี่ยง {cp.warning.drug}</div>
              <div className="desc">{cp.warning.reason}</div>
            </div>
          </div>
        </div>
      )}

      {/* Customer info */}
      <div className="copilot-section">
        <div className="label">ข้อมูลลูกค้า</div>
        <div className="cust-info">
          <div className="row"><span className="k">ระดับสมาชิก</span><span className="v brand">{conv.tier} · {conv.points.toLocaleString()} แต้ม</span></div>
          <div className="row"><span className="k">อายุ / เพศ</span><span className="v">{conv.age} ปี · {conv.gender}</span></div>
          <div className="row"><span className="k">โรคประจำตัว</span><span className="v">{conv.chronic.length ? conv.chronic.join(', ') : '—'}</span></div>
          <div className="row"><span className="k">ประวัติแพ้ยา</span>
            <span className={`v ${conv.allergies.length ? 'warn' : ''}`}>
              {conv.allergies.length ? conv.allergies.join(', ') : '—'}
            </span>
          </div>
        </div>
      </div>

      {/* Quick actions */}
      <div className="copilot-section">
        <div className="label">QUICK ACTIONS</div>
        <div className="quick-grid">
          {cp.quickActions.map((qa, i) => (
            <button key={i} className="quick-action" onClick={() => onQuickAction(qa.label)}>
              <div className="ic"><Lic name={qa.ic} size={14} /></div>
              <span>{qa.label}</span>
            </button>
          ))}
        </div>
      </div>

      {/* Recent orders */}
      {conv.recentOrders.length > 0 && (
        <div className="copilot-section">
          <div className="label">ออเดอร์ล่าสุด</div>
          <div className="cust-orders">
            {conv.recentOrders.map(o => (
              <div key={o.id} className="cust-order">
                <span className="id">#{o.id.slice(-4)}</span>
                <span className="nm">{o.name}</span>
                <span className="total">฿{o.total}</span>
                <span className={`pill ${o.status}`}>{o.label}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </aside>
  );
}

Object.assign(window, { Sidebar, InboxList, ChatThread, CoPilot, Lic, Avatar });
