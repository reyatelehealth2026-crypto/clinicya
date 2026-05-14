/* =========================================================================
   REYA — Polish JS (count-up, typewriter, confetti, simulator)
   Plain JS — load AFTER React app
   ========================================================================= */
(function () {
  'use strict';

  // ───── Count-up KPI numbers ─────────────────────────────────────────
  const countedUp = new WeakSet();

  function countUp(el, opts = {}) {
    if (countedUp.has(el)) return;

    const raw = el.textContent;
    // Skip if React hasn't filled the text yet
    if (!raw || raw.trim().length === 0) return;

    // Extract numeric portion (handle ฿, ,, K, M, %, etc.)
    const m = raw.match(/(-?[\d,]+(?:\.\d+)?)(.*)$/);
    if (!m) return;
    const numStr = m[1].replace(/,/g, '');
    const target = parseFloat(numStr);
    if (isNaN(target)) return;

    // Skip tiny "0" placeholders — likely pre-React state
    if (target === 0 && raw.trim().length < 2) return;

    // Mark only AFTER we know we'll animate
    countedUp.add(el);

    const prefix = raw.slice(0, raw.indexOf(m[1]));
    const suffix = m[2];
    const hasComma = m[1].includes(',');
    const decimals = (numStr.split('.')[1] || '').length;

    const duration = opts.duration || 900;
    const fmt = (n) => {
      const v = decimals ? n.toFixed(decimals) : Math.round(n).toString();
      return hasComma
        ? v.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
        : v;
    };

    // Double rAF so we paint the start state AFTER React's commit
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const start = performance.now();
      function step(now) {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
        const cur = target * eased;
        el.textContent = prefix + fmt(cur) + suffix;
        if (t < 1) requestAnimationFrame(step);
        else el.textContent = raw;
      }
      el.textContent = prefix + fmt(0) + suffix;
      requestAnimationFrame(step);
    }));
  }

  // Run on visible KPI/.val nodes
  function runCountUps(root = document) {
    root.querySelectorAll('.kpi .val, .ai-stat .val').forEach(el => countUp(el));
  }

  // Observe DOM mutations — when a new .page renders, count up its KPIs
  // Debounced via rAF so we batch updates from a single React commit
  let pending = false;
  const obs = new MutationObserver(() => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => {
      pending = false;
      runCountUps();
    });
  });
  obs.observe(document.body, { childList: true, subtree: true });

  // Initial
  setTimeout(() => runCountUps(), 300);

  // ───── Confetti (lightweight, vanilla) ──────────────────────────────
  let confettiCanvas, confettiCtx, confettiRAF;
  let particles = [];

  function ensureCanvas() {
    if (confettiCanvas) return;
    confettiCanvas = document.createElement('canvas');
    confettiCanvas.id = 'reya-confetti';
    document.body.appendChild(confettiCanvas);
    confettiCtx = confettiCanvas.getContext('2d');
    resizeConfetti();
    window.addEventListener('resize', resizeConfetti);
  }
  function resizeConfetti() {
    if (!confettiCanvas) return;
    const dpr = window.devicePixelRatio || 1;
    confettiCanvas.width = window.innerWidth * dpr;
    confettiCanvas.height = window.innerHeight * dpr;
    confettiCanvas.style.width = window.innerWidth + 'px';
    confettiCanvas.style.height = window.innerHeight + 'px';
    confettiCtx.scale(dpr, dpr);
  }
  function tickConfetti() {
    const w = window.innerWidth, h = window.innerHeight;
    confettiCtx.clearRect(0, 0, w, h);
    particles = particles.filter(p => p.y < h + 30 && p.life > 0);
    if (particles.length === 0) {
      confettiCanvas.style.display = 'none';
      return;
    }
    particles.forEach(p => {
      p.x += p.vx;
      p.y += p.vy;
      p.vy += 0.18;
      p.vx *= 0.99;
      p.rot += p.vr;
      p.life -= 0.005;
      confettiCtx.save();
      confettiCtx.translate(p.x, p.y);
      confettiCtx.rotate(p.rot);
      confettiCtx.globalAlpha = Math.max(0, Math.min(1, p.life));
      confettiCtx.fillStyle = p.color;
      if (p.shape === 'square') {
        confettiCtx.fillRect(-p.size/2, -p.size/2, p.size, p.size*0.6);
      } else {
        confettiCtx.beginPath();
        confettiCtx.arc(0, 0, p.size/2, 0, Math.PI*2);
        confettiCtx.fill();
      }
      confettiCtx.restore();
    });
    confettiRAF = requestAnimationFrame(tickConfetti);
  }
  function burst(x, y, count = 50, palette) {
    ensureCanvas();
    confettiCanvas.style.display = 'block';
    const colors = palette || ['#2c7656', '#7eb89c', '#aed6c2', '#f59e0b', '#fde68a', '#bae6fd', '#0ea5e9'];
    for (let i = 0; i < count; i++) {
      const angle = (Math.PI * 2 * Math.random());
      const speed = 4 + Math.random() * 7;
      particles.push({
        x, y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - 4,
        size: 6 + Math.random() * 5,
        rot: Math.random() * Math.PI * 2,
        vr: (Math.random() - 0.5) * 0.3,
        life: 1.4,
        color: colors[Math.floor(Math.random() * colors.length)],
        shape: Math.random() > 0.5 ? 'square' : 'circle',
      });
    }
    if (!confettiRAF || particles.length === count) {
      cancelAnimationFrame(confettiRAF);
      tickConfetti();
    }
  }

  window.reyaConfetti = function (count = 60) {
    burst(window.innerWidth / 2, window.innerHeight / 2, count);
  };
  window.reyaConfettiAt = function (el, count = 40) {
    const r = el.getBoundingClientRect();
    burst(r.left + r.width / 2, r.top + r.height / 2, count);
  };

  // ───── Typewriter / streaming text helper ───────────────────────────
  window.reyaStream = function (el, fullText, opts = {}) {
    const speed = opts.speed || 18;
    const onDone = opts.onDone || (() => {});
    let i = 0;
    el.classList.add('streaming');
    el.textContent = '';
    function tick() {
      // Speed up later, slow at start
      const chunk = Math.max(1, Math.floor(Math.random() * 3));
      i = Math.min(fullText.length, i + chunk);
      el.textContent = fullText.slice(0, i);
      if (i < fullText.length) {
        setTimeout(tick, speed + Math.random() * 12);
      } else {
        el.classList.remove('streaming');
        onDone();
      }
    }
    tick();
  };

  // ───── Live inbox simulator — drip "new message" toasts ─────────────
  const SIM_MESSAGES = [
    { who: 'ขวัญใจ พงษ์', text: 'มีน้องในร้านมั้ยคะ', initial: 'ข', av: ['#b45309','#78350f'] },
    { who: 'ภคพร เจริญสุข', text: 'อยากปรึกษาวิตามินสำหรับเด็ก', initial: 'ภ', av: ['#2c7656','#1c4d39'] },
    { who: 'กิตติ พัฒนะ', text: 'มียาทาแก้สิวมั้ยครับ', initial: 'ก', av: ['#0891b2','#155e75'] },
  ];
  let simIdx = 0;
  let simTimer = null;

  function showLiveToast(msg) {
    const wrap = document.createElement('div');
    wrap.className = 'live-toast';
    Object.assign(wrap.style, {
      position: 'fixed', right: '20px', top: '20px',
      background: '#fff', border: '1px solid var(--border)',
      borderRadius: '12px', padding: '10px 14px',
      display: 'flex', alignItems: 'center', gap: '10px',
      boxShadow: '0 12px 28px rgba(15,23,42,0.15)',
      zIndex: '9998', fontFamily: 'inherit',
      maxWidth: '300px',
      transform: 'translateX(330px)',
      transition: 'transform 320ms cubic-bezier(0.34, 1.56, 0.64, 1)',
    });
    wrap.innerHTML = `
      <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg, ${msg.av[0]}, ${msg.av[1]});color:#fff;font-weight:700;display:grid;place-items:center;font-size:14px;flex-shrink:0;">${msg.initial}</div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:12px;font-weight:700;">${msg.who}</div>
        <div style="font-size:11px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${msg.text}</div>
      </div>
      <div style="width:8px;height:8px;border-radius:50%;background:#16a34a;flex-shrink:0;animation:dot-pulse 2s infinite;"></div>
    `;
    document.body.appendChild(wrap);
    requestAnimationFrame(() => {
      wrap.style.transform = 'translateX(0)';
    });
    setTimeout(() => {
      wrap.style.transform = 'translateX(330px)';
      setTimeout(() => wrap.remove(), 400);
    }, 3200);
  }

  function startSimulator() {
    if (simTimer) return;
    const tick = () => {
      // Only run if the document is visible
      if (document.visibilityState === 'visible') {
        const msg = SIM_MESSAGES[simIdx % SIM_MESSAGES.length];
        showLiveToast(msg);
        simIdx++;
      }
      simTimer = setTimeout(tick, 28000 + Math.random() * 12000);
    };
    simTimer = setTimeout(tick, 18000);
  }
  // start after initial paint
  setTimeout(startSimulator, 8000);

  // ───── Hover ripple on inbox + sidebar (optional spice) ──────────────
  document.addEventListener('click', (e) => {
    // Confetti when clicking "ใช้คำตอบนี้" or reward redeem
    const target = e.target.closest('button');
    if (!target) return;
    const txt = target.textContent || '';
    if (txt.includes('ใช้คำตอบ') || txt.includes('ใช้คำตอบนี้')) {
      window.reyaConfettiAt(target, 50);
    } else if (txt.includes('แลกเลย')) {
      window.reyaConfettiAt(target, 35);
    }
  }, { capture: false });

  // Expose runner so React can poke it
  window.reyaRunCountUps = runCountUps;
})();
