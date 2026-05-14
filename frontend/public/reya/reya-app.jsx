/* REYA Dashboard — main app shell */

const { useState: useStateApp, useEffect: useEffectApp, useRef: useRefApp } = React;

function App() {
  const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
    "density": "comfortable",
    "showCustomerFrame": false,
    "showCopilot": true,
    "accent": "emerald"
  }/*EDITMODE-END*/;

  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);

  // State
  const [conversations, setConversations] = useStateApp(window.CONVERSATIONS);
  const [activeId, setActiveId] = useStateApp(window.CONVERSATIONS[0].id);
  const [activeNav, setActiveNav] = useStateApp('dashboard');
  const [toast, setToast] = useStateApp(null);
  const toastTimer = useRefApp(null);

  const showToast = (msg, icon = 'check') => {
    setToast({ msg, icon });
    if (toastTimer.current) clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(null), 2200);
  };

  const activeConv = conversations.find(c => c.id === activeId) || conversations[0];
  const unreadCount = conversations.reduce((sum, c) => sum + (c.unread || 0), 0);

  // Mark conversation read when selected
  useEffectApp(() => {
    setConversations(prev => prev.map(c => c.id === activeId ? { ...c, unread: 0 } : c));
  }, [activeId]);

  // Send a message
  const sendMessage = (text) => {
    const now = new Date();
    const time = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
    setConversations(prev => prev.map(c =>
      c.id === activeId
        ? { ...c, messages: [...c.messages, { from: 'you', text, time }], preview: text.slice(0, 50), time }
        : c
    ));
    // Simulate AI "their" reply for some convs
    setTimeout(() => {
      const follow = activeConv.copilot.suggestedFollowups[0];
      if (!follow) return;
      // Customer responds with a follow-up snippet (only sometimes)
    }, 1500);
  };

  // Use AI reply (auto-send)
  const useAiReply = (text) => {
    sendMessage(text);
    showToast('ส่งคำตอบ AI ให้ลูกค้าแล้ว', 'sparkles');
  };

  // Quick action handlers
  const onQuickAction = (label) => {
    if (label === 'regenerate') {
      showToast('กำลังสร้างคำตอบใหม่...', 'refresh-cw');
      return;
    }
    if (label === 'edit') {
      showToast('โหลดเข้าช่องพิมพ์แล้ว', 'pen');
      return;
    }
    showToast(`${label} เรียบร้อย`, 'check');
  };

  // Non-inbox views — render the corresponding page
  const renderNonInbox = () => {
    const pageMap = {
      dashboard: <DashboardPage onNav={setActiveNav} />,
      customers: <CustomersPage />,
      orders: <OrdersPage />,
      products: <ProductsPage />,
      rewards: <RewardsPage />,
      analytics: <AnalyticsPage />,
      telepharmacy: <TelepharmacyPage />,
      'ai-copilot': <AICopilotPage />,
      settings: <SettingsPage />,
    };
    return pageMap[activeNav] || (
      <div style={{flex:1,display:'grid',placeItems:'center',background:'#f3f6f4'}}>
        <div style={{fontSize:14,color:'var(--fg-2)'}}>หน้านี้ยังไม่พร้อม</div>
      </div>
    );
  };

  return (
    <div className="stage">
      <div className={`workspace${t.density==='compact'?' compact':''}${!t.showCopilot?' no-copilot':''}${activeNav!=='inbox'?' page-mode':''}`}>
        <Sidebar active={activeNav} onNav={setActiveNav} unreadCount={unreadCount} />

        {activeNav === 'inbox' ? (
          <>
            <InboxList
              items={conversations}
              activeId={activeId}
              onSelect={setActiveId}
            />
            <ChatThread
              conv={activeConv}
              onSendMessage={sendMessage}
              onUseAiReply={useAiReply}
            />
            {t.showCopilot && (
              <CoPilot
                conv={activeConv}
                onUseReply={useAiReply}
                onQuickAction={onQuickAction}
              />
            )}
          </>
        ) : (
          renderNonInbox()
        )}
      </div>

      {/* Floating peek button → toggle customer iPhone */}
      {!t.showCustomerFrame && (
        <button
          className="peek-customer"
          onClick={() => setTweak('showCustomerFrame', true)}
          data-screen-label="Customer Preview Button"
        >
          <div className="ic"><Lic name="smartphone" size={14} /></div>
          <div className="lbl-row">
            <span className="l1">ดูมุมมองลูกค้า</span>
            <span className="l2">LINE Mini App preview</span>
          </div>
        </button>
      )}

      {t.showCustomerFrame && (
        <div className="customer-frame">
          <button
            onClick={() => setTweak('showCustomerFrame', false)}
            style={{
              position: 'absolute', top: -10, right: -10, zIndex: 30,
              width: 28, height: 28, borderRadius: '50%',
              background: '#fff', border: '1px solid var(--border)',
              cursor: 'pointer', boxShadow: 'var(--shadow-card)',
              display: 'grid', placeItems: 'center',
              color: 'var(--fg-2)',
            }}
            title="ปิดมุมมองลูกค้า"
          >
            <Lic name="x" size={14} />
          </button>
          <CustomerApp currentConv={activeConv} />
        </div>
      )}

      {/* Toast */}
      {toast && (
        <div className="toast">
          <Lic name={toast.icon} size={14} />
          <span>{toast.msg}</span>
        </div>
      )}

      {/* Tweaks Panel */}
      <TweaksPanel title="Tweaks">
        <TweakSection label="Layout">
          <TweakRadio
            label="ความหนาแน่น"
            value={t.density}
            options={[
              { value: 'comfortable', label: 'สบายตา' },
              { value: 'compact', label: 'แน่น' },
            ]}
            onChange={v => setTweak('density', v)}
          />
          <TweakToggle
            label="AI Co-Pilot Panel"
            value={t.showCopilot}
            onChange={v => setTweak('showCopilot', v)}
          />
          <TweakToggle
            label="มุมมองลูกค้า (iPhone)"
            value={t.showCustomerFrame}
            onChange={v => setTweak('showCustomerFrame', v)}
          />
        </TweakSection>

        <TweakSection label="Demo flow">
          <TweakButton
            label="ลองใช้ AI Suggest"
            onClick={() => {
              setActiveNav('inbox');
              setActiveId('c1');
              showToast('คลิกที่ "ใช้คำตอบนี้" ในพาเนลขวา', 'sparkles');
            }}
          />
          <TweakButton
            label="โชว์ Drug Warning"
            onClick={() => {
              setActiveNav('inbox');
              setActiveId('c1');
              showToast('ดูคำเตือน "หลีกเลี่ยง Aspirin" ในพาเนลขวา', 'alert-triangle');
            }}
          />
          <TweakButton
            label="ดูเคส VIP สูงวัย"
            onClick={() => {
              setActiveNav('inbox');
              setActiveId('c6');
              showToast('เคสคุณอนงค์ — แนะนำวิดีโอคอล', 'video');
            }}
          />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<App />);
