/**
 * Mor Ruj — animated sprite mascot v2
 * Draggable + resizable + click-to-open AI chat (uses the system's configured
 * AI via POST /api/ai-admin.php  ->  {success, response}).
 * Sprite: /assets/images/mor-ruj-sprite.png  (8 cols x 5 rows)
 *
 *   MorRuj.init({ steps:[{selector,text}], chatEndpoint:'api/ai-admin.php',
 *                 quickActions:[{label,msg}], welcome:'...' });
 *   MorRuj.openChat();  MorRuj.tour();  MorRuj.say('...');
 * @version 2.0.0
 */
(function (global) {
  'use strict';

  var COLS = 8, ROWS = 5;
  var STATES = {
    idle:  { row: 0, cols: [0, 1, 2, 3],            fps: 2,   loop: true  },
    walk:  { row: 1, cols: [0, 1, 2, 3, 4, 5, 6, 7], fps: 9,  loop: true  },
    cheer: { row: 2, cols: [0, 1, 2, 3, 4],         fps: 6,   loop: false },
    wave:  { row: 2, cols: [5, 6, 7, 6, 7, 5],      fps: 5,   loop: false },
    talk:  { row: 3, cols: [2, 3, 2, 3],            fps: 5,   loop: true  },
    pointR:{ row: 3, cols: [6, 7],                  fps: 3,   loop: true  },
    pointL:{ row: 3, cols: [0, 1],                  fps: 3,   loop: true  },
    sleep: { row: 4, cols: [4, 5, 6, 7],            fps: 1.6, loop: true  }
  };
  var SKEY = 'mrj_v2';

  var el, bubble, closeBtn, resizeBtn, launcher, chat, chatBody, chatInput, chatSend, raf;
  var cur = null, idx = 0, lastTs = 0, curEnd = null;
  var busy = false, idleTimer = null, dismissed = false, chatOpen = false, started = false;
  var hist = [], sending = false;
  var pos = { left: 120, top: 0 }, home = { left: 120, top: 0 }, scale = 1, flipped = false;
  var cfg = {
    name: 'ผู้จัดการร้าน Re-ya', sub: 'ผู้ช่วย AI ประจำร้าน',
    chatEndpoint: 'api/ai-admin.php',
    welcome: 'สวัสดีครับ! ผมผู้จัดการร้าน Re-ya ดูแลร้านให้คุณ ถามอะไรก็ได้เลย — ยอดขาย ออเดอร์ สต๊อก ลูกค้า หรือให้ผมพาทัวร์ระบบก็ได้ครับ 😊',
    quickActions: [
      { label: '📊 สรุปวันนี้', msg: 'สรุปวันนี้' },
      { label: '📦 ออเดอร์รอ', msg: 'ออเดอร์รอดำเนินการ' },
      { label: '🧾 สลิปรอตรวจ', msg: 'สลิปรอตรวจ' },
      { label: '🚨 แจ้งเตือน', msg: 'แจ้งเตือน' },
      { label: '⚙️ วิธีตั้งค่า', msg: 'วิธีตั้งค่าระบบเริ่มต้นมีขั้นตอนอะไรบ้าง' },
      { label: '❓ วิธีใช้งาน', msg: 'แนะนำการใช้งานระบบ REYA ให้หน่อย' },
      { label: '▶ พาทัวร์ระบบ', action: 'tour' }
    ],
    steps: []
  };

  /* ---------- sprite frames ---------- */
  function setFrame(r, c) { el.style.backgroundPosition = (c * 100 / (COLS - 1)) + '% ' + (r * 100 / (ROWS - 1)) + '%'; }
  function play(name, onEnd) { var s = STATES[name]; if (!s) return; cur = s; idx = 0; curEnd = onEnd || null; setFrame(s.row, s.cols[0]); }
  function tick(ts) {
    if (cur && ts - lastTs >= 1000 / cur.fps) {
      lastTs = ts; idx++;
      if (idx >= cur.cols.length) {
        if (cur.loop) idx = 0;
        else { idx = cur.cols.length - 1; setFrame(cur.row, cur.cols[idx]); var f = curEnd; cur = null; curEnd = null; if (f) f(); raf = requestAnimationFrame(tick); return; }
      }
      setFrame(cur.row, cur.cols[idx]);
    }
    raf = requestAnimationFrame(tick);
  }

  /* ---------- geometry ---------- */
  function vw() { return global.innerWidth || document.documentElement.clientWidth; }
  function vh() { return global.innerHeight || document.documentElement.clientHeight; }
  function applyTransform() { el.style.transform = 'scale(' + scale + ')' + (flipped ? ' scaleX(-1)' : ''); }
  function applyPos() { el.style.left = pos.left + 'px'; el.style.top = pos.top + 'px'; }
  function face(d) { flipped = d < 0; applyTransform(); }
  function persist() { try { localStorage.setItem(SKEY, JSON.stringify({ left: pos.left, top: pos.top, scale: scale })); } catch (e) {} }
  function placeChrome() {
    var r = el.getBoundingClientRect();
    closeBtn.style.left = (r.right - 16) + 'px'; closeBtn.style.top = (r.top - 6) + 'px';
    resizeBtn.style.left = (r.right - 16) + 'px'; resizeBtn.style.top = (r.bottom - 16) + 'px';
  }
  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function fmt(s) { return escapeHtml(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>'); }

  /* ---------- tour / bubble ---------- */
  function say(text, keep) {
    bubble.innerHTML = '<span class="mrj-bubble__name">' + cfg.name + '</span>' + escapeHtml(text);
    var r = el.getBoundingClientRect();
    bubble.style.left = Math.min(Math.max(r.left - 8, 8), vw() - 282) + 'px';
    bubble.classList.add('mrj-show');
    requestAnimationFrame(function () { var b = el.getBoundingClientRect(); var ny = b.top - bubble.offsetHeight - 12; bubble.style.top = (ny < 8 ? b.bottom + 12 : ny) + 'px'; });
    clearTimeout(bubble._t); if (!keep) bubble._t = setTimeout(function () { bubble.classList.remove('mrj-show'); }, 3600);
  }
  function clearHl() { var n = document.querySelectorAll('.mrj-hl'); for (var i = 0; i < n.length; i++) n[i].classList.remove('mrj-hl'); }
  function walkTo(x, y, cb) {
    var mw = el.offsetWidth * scale, mh = el.offsetHeight * scale;
    x = Math.min(Math.max(x, 4), vw() - mw - 4);
    y = (y == null) ? pos.top : Math.min(Math.max(y, 4), vh() - mh - 4);
    var dx = x - pos.left, dy = y - pos.top, dist = Math.sqrt(dx * dx + dy * dy);
    face(dx < -2 ? -1 : 1);
    el.style.transitionDuration = Math.min(Math.max(dist / 230 * 1000, 350), 1700) + 'ms';
    play('walk'); pos.left = x; pos.top = y; applyPos();
    var done = false; function fin() { if (done) return; done = true; el.removeEventListener('transitionend', fin); play('idle'); placeChrome(); if (cb) cb(); }
    el.addEventListener('transitionend', fin); setTimeout(fin, 1950);
  }
  function pointAt(t, text, cb) {
    var node = typeof t === 'string' ? document.querySelector(t) : t; if (!node) { if (cb) cb(); return; }
    var r = node.getBoundingClientRect();
    var mw = el.offsetWidth * scale, mh = el.offsetHeight * scale;
    // stand BESIDE the item (right of it, vertically centred); if no room, stand below it
    var tx = r.right + 6, ty = r.top + r.height / 2 - mh / 2;
    if (tx > vw() - mw - 6) { tx = Math.max(r.left, 6); ty = r.bottom + 4; }
    walkTo(tx, ty, function () {
      clearHl(); node.classList.add('mrj-hl');
      var e = el.getBoundingClientRect(); flipped = false; applyTransform();
      play((e.left + e.width / 2) >= (r.left + r.width / 2) ? 'pointL' : 'pointR');
      say(text, true); if (cb) cb();
    });
  }
  function tour() {
    if (busy || !cfg.steps.length) return; closeChat(); busy = true; wake(); var i = 0;
    (function step() {
      if (i >= cfg.steps.length) { busy = false; walkTo(home.left, home.top, function () { clearHl(); play('cheer', function () { play('idle'); }); say('จบทัวร์ครับ! กดที่ผมเพื่อเปิดแชตถามต่อได้เลยนะครับ 😊'); resetIdle(); }); return; }
      var s = cfg.steps[i++]; pointAt(s.selector, s.text, function () { setTimeout(step, s.hold || 3000); });
    })();
  }

  /* ---------- sleep / idle ---------- */
  function sleep() { if (dismissed || chatOpen || busy) return; clearHl(); play('sleep'); bubble.classList.remove('mrj-show'); }
  function resetIdle() { clearTimeout(idleTimer); if (!chatOpen) idleTimer = setTimeout(sleep, 12000); }
  function wake() { if (cur === STATES.sleep) play('idle'); resetIdle(); }

  /* ---------- drag ---------- */
  function startDrag(ev) {
    if (ev.button === 2) return; var p = ev.touches ? ev.touches[0] : ev;
    var sx = p.clientX, sy = p.clientY, sl = pos.left, st = pos.top, moved = false;
    el.classList.add('mrj-notrans'); clearTimeout(idleTimer); bubble.classList.remove('mrj-show');
    function mv(e) {
      var q = e.touches ? e.touches[0] : e, dx = q.clientX - sx, dy = q.clientY - sy;
      if (!moved && Math.abs(dx) + Math.abs(dy) > 4) { moved = true; el.classList.add('mrj-drag'); if (cur === STATES.sleep) play('idle'); }
      if (moved) {
        var w = el.offsetWidth * scale, h = el.offsetHeight * scale;
        pos.left = Math.min(Math.max(sl + dx, 0), vw() - w);
        pos.top = Math.min(Math.max(st + dy, 0), vh() - h);
        applyPos(); placeChrome(); if (e.cancelable) e.preventDefault();
      }
    }
    function up() {
      global.removeEventListener('pointermove', mv); global.removeEventListener('pointerup', up);
      global.removeEventListener('touchmove', mv); global.removeEventListener('touchend', up);
      el.classList.remove('mrj-notrans', 'mrj-drag');
      if (moved) { persist(); resetIdle(); } else { toggleChat(); }
    }
    global.addEventListener('pointermove', mv); global.addEventListener('pointerup', up);
    global.addEventListener('touchmove', mv, { passive: false }); global.addEventListener('touchend', up);
  }

  /* ---------- resize ---------- */
  function startResize(ev) {
    ev.stopPropagation(); ev.preventDefault(); var p = ev.touches ? ev.touches[0] : ev;
    var sy = p.clientY, ss = scale; el.classList.add('mrj-notrans');
    function mv(e) { var q = e.touches ? e.touches[0] : e; scale = Math.min(Math.max(ss + (sy - q.clientY) / 140, 0.55), 2.4); applyTransform(); placeChrome(); if (e.cancelable) e.preventDefault(); }
    function up() {
      global.removeEventListener('pointermove', mv); global.removeEventListener('pointerup', up);
      global.removeEventListener('touchmove', mv); global.removeEventListener('touchend', up);
      el.classList.remove('mrj-notrans'); persist();
    }
    global.addEventListener('pointermove', mv); global.addEventListener('pointerup', up);
    global.addEventListener('touchmove', mv, { passive: false }); global.addEventListener('touchend', up);
  }

  /* ---------- chat ---------- */
  function buildChat() {
    chat = document.createElement('div'); chat.className = 'mrj-chat';
    chat.innerHTML =
      '<div class="mrj-chat__head"><div class="mrj-chat__ava"></div>' +
      '<div class="mrj-chat__t"><div class="mrj-chat__name">' + escapeHtml(cfg.name) + '</div><div class="mrj-chat__sub">' + escapeHtml(cfg.sub) + '</div></div>' +
      '<button class="mrj-chat__x" aria-label="ปิด">✕</button></div>' +
      '<div class="mrj-chat__body"></div>' +
      '<div class="mrj-chat__foot"><div class="mrj-chat__chips"></div>' +
      '<form class="mrj-chat__form"><input class="mrj-chat__in" type="text" placeholder="พิมพ์คำถาม..." autocomplete="off"><button class="mrj-chat__send" type="submit" aria-label="ส่ง"><i class="fas fa-paper-plane"></i>➤</button></form></div>';
    document.querySelector('.mrj-root').appendChild(chat);
    chatBody = chat.querySelector('.mrj-chat__body');
    chatInput = chat.querySelector('.mrj-chat__in');
    chatSend = chat.querySelector('.mrj-chat__send');
    chat.querySelector('.mrj-chat__x').addEventListener('click', closeChat);
    chat.querySelector('.mrj-chat__form').addEventListener('submit', function (e) { e.preventDefault(); var v = chatInput.value.trim(); if (v) send(v); });
    var chips = chat.querySelector('.mrj-chat__chips');
    cfg.quickActions.forEach(function (q) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'mrj-chip'; b.textContent = q.label;
      b.addEventListener('click', function () { if (q.action === 'tour') { closeChat(); tour(); } else send(q.msg || q.label); });
      chips.appendChild(b);
    });
  }
  function positionChat() {
    var r = el.getBoundingClientRect();
    var w = Math.min(340, vw() - 24), h = chat.offsetHeight || 460;
    var left = Math.min(Math.max(r.left - 10, 12), vw() - w - 12);
    var top = r.top - h - 10; if (top < 12) top = Math.min(r.bottom + 10, vh() - h - 12);
    chat.style.left = left + 'px'; chat.style.top = Math.max(top, 12) + 'px'; chat.style.width = w + 'px';
  }
  function openChat() {
    if (dismissed) show();
    chatOpen = true; clearTimeout(idleTimer); play('idle');
    chat.classList.add('mrj-open'); positionChat();
    if (!chatBody.children.length) addMsg(cfg.welcome, 'ai');
    setTimeout(function () { positionChat(); chatInput.focus(); }, 30);
  }
  function closeChat() { chatOpen = false; if (chat) chat.classList.remove('mrj-open'); resetIdle(); }
  function toggleChat() { wake(); chatOpen ? closeChat() : openChat(); }
  function addMsg(text, who) {
    var m = document.createElement('div'); m.className = 'mrj-msg mrj-msg--' + who;
    m.innerHTML = '<div class="mrj-msg__b">' + (who === 'ai' ? fmt(text) : escapeHtml(text)) + '</div>';
    chatBody.appendChild(m); chatBody.scrollTop = chatBody.scrollHeight; return m;
  }
  function typing(on) {
    var t = chatBody.querySelector('.mrj-typing-wrap');
    if (on) { if (!t) { t = document.createElement('div'); t.className = 'mrj-msg mrj-msg--ai mrj-typing-wrap'; t.innerHTML = '<div class="mrj-typing"><span></span><span></span><span></span></div>'; chatBody.appendChild(t); chatBody.scrollTop = chatBody.scrollHeight; } play('talk'); }
    else { if (t) t.remove(); play('idle'); }
  }
  function endpoint() {
    var base = (document.querySelector('meta[name="base-url"]') || {}).content || '/';
    return base.replace(/\/+$/, '') + '/' + cfg.chatEndpoint.replace(/^\/+/, '');
  }
  function pushHist(role, text) { hist.push({ role: role, text: text }); if (hist.length > 12) hist = hist.slice(-12); }
  // Hybrid: เบราว์เซอร์ยิง Gemini เอง (เลี่ยง geo-block) + ส่งประวัติบทสนทนาเพื่อให้มี context
  function callGeminiClient(p) {
    var url = 'https://generativelanguage.googleapis.com/v1beta/models/' + (p.model || 'gemini-flash-latest') + ':generateContent?key=' + encodeURIComponent(p.apiKey);
    var contents = hist.map(function (h) { return { role: h.role === 'model' ? 'model' : 'user', parts: [{ text: h.text }] }; });
    if (!contents.length) contents = [{ role: 'user', parts: [{ text: p.message || '' }] }];
    var body = {
      systemInstruction: { parts: [{ text: p.systemPrompt || '' }] },
      contents: contents,
      generationConfig: { temperature: 0.7, maxOutputTokens: 2048 }
    };
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var t = j && j.candidates && j.candidates[0] && j.candidates[0].content && j.candidates[0].content.parts && j.candidates[0].content.parts[0] && j.candidates[0].content.parts[0].text;
        if (t) return t.trim();
        throw new Error((j && j.error && j.error.message) || 'AI ตอบไม่ได้');
      });
  }
  function aiDone(text) { sending = false; typing(false); chatSend.disabled = false; pushHist('model', text); addMsg(text, 'ai'); play('cheer', function () { play('idle'); }); }
  function aiFail(text) { sending = false; typing(false); chatSend.disabled = false; addMsg('❌ ' + text, 'ai'); }
  function send(msg) {
    if (sending) return;               // กันยิงซ้อนจนคำตอบมั่ว
    sending = true;
    addMsg(msg, 'user'); pushHist('user', msg); chatInput.value = ''; chatSend.disabled = true; typing(true);
    fetch(endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ message: msg }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.success && d.type === 'client_gemini' && d.data && d.data.apiKey) {
          // คำถามทั่วไป/ตั้งค่า → เบราว์เซอร์ยิง Gemini เอง (พร้อมประวัติ)
          return callGeminiClient(d.data).then(aiDone).catch(function (e) { aiFail((e && e.message ? e.message : 'AI ตอบไม่ได้') + ' (ลองใหม่อีกครั้งครับ)'); });
        }
        if (d && d.success) { aiDone(d.response); return; }
        aiFail(((d && d.error) || 'ขออภัยครับ ตอนนี้ตอบไม่ได้ ลองใหม่อีกครั้งนะครับ') + (d && d.error === 'Unauthorized' ? ' (กรุณาเข้าสู่ระบบก่อนครับ)' : ''));
      })
      .catch(function () { aiFail('เชื่อมต่อไม่ได้ครับ ลองเช็คอินเทอร์เน็ตแล้วลองใหม่นะครับ'); });
  }

  /* ---------- dismiss / show ---------- */
  function dismiss() { dismissed = true; try { localStorage.setItem('mrj_dismissed', '1'); } catch (e) {} closeChat(); [el, closeBtn, resizeBtn, bubble].forEach(function (n) { n.style.display = 'none'; }); clearHl(); clearTimeout(idleTimer); launcher.style.display = 'flex'; }
  function show() { dismissed = false; try { localStorage.removeItem('mrj_dismissed'); } catch (e) {} el.style.display = ''; closeBtn.style.display = 'flex'; resizeBtn.style.display = 'flex'; launcher.style.display = 'none'; play('idle'); placeChrome(); resetIdle(); }

  /* ---------- init ---------- */
  function build() {
    var root = document.createElement('div'); root.className = 'mrj-root';
    el = document.createElement('div'); el.className = 'mrj'; el.setAttribute('role', 'button'); el.setAttribute('aria-label', cfg.name + ' — กดเพื่อเปิดแชต, ลากเพื่อย้าย');
    bubble = document.createElement('div'); bubble.className = 'mrj-bubble';
    closeBtn = document.createElement('div'); closeBtn.className = 'mrj-close'; closeBtn.innerHTML = '✕'; closeBtn.title = 'ซ่อน' + cfg.name;
    resizeBtn = document.createElement('div'); resizeBtn.className = 'mrj-resize'; resizeBtn.innerHTML = '⤡'; resizeBtn.title = 'ลากเพื่อย่อ/ขยาย';
    launcher = document.createElement('div'); launcher.className = 'mrj-launcher'; launcher.style.display = 'none'; launcher.innerHTML = '<i class="fas fa-user-md"></i><span>เรียก' + cfg.name + '</span>';
    [el, bubble, closeBtn, resizeBtn, launcher].forEach(function (n) { root.appendChild(n); });
    document.body.appendChild(root);
    buildChat();

    el.addEventListener('pointerdown', startDrag);
    el.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    resizeBtn.addEventListener('pointerdown', startResize);
    closeBtn.addEventListener('click', dismiss);
    launcher.addEventListener('click', show);
    global.addEventListener('mousemove', throttle(wake, 1800), { passive: true });
    global.addEventListener('resize', function () { pos.left = Math.min(pos.left, vw() - 90); pos.top = Math.min(pos.top, vh() - 90); applyPos(); placeChrome(); if (chatOpen) positionChat(); });
    global.addEventListener('scroll', function () { if (bubble.classList.contains('mrj-show')) bubble.classList.remove('mrj-show'); }, { passive: true });
  }
  function throttle(fn, ms) { var t = 0; return function () { var n = Date.now(); if (n - t > ms) { t = n; fn(); } }; }

  function init(options) {
    if (started) return; started = true;
    for (var k in (options || {})) cfg[k] = options[k];
    build();
    raf = requestAnimationFrame(tick); setFrame(0, 0);

    var saved = {}; try { saved = JSON.parse(localStorage.getItem(SKEY)) || {}; } catch (e) {}
    scale = saved.scale || 1;
    pos.left = (saved.left != null) ? saved.left : 120;
    pos.top = (saved.top != null) ? saved.top : Math.round(vh() * 0.7);
    pos.left = Math.min(Math.max(pos.left, 0), vw() - 90);
    pos.top = Math.min(Math.max(pos.top, 0), vh() - 90);
    home = { left: pos.left, top: pos.top };
    applyPos(); applyTransform();

    var wasDismissed = false; try { wasDismissed = localStorage.getItem('mrj_dismissed') === '1'; } catch (e) {}
    if (wasDismissed) { dismissed = true; el.style.display = 'none'; closeBtn.style.display = 'none'; resizeBtn.style.display = 'none'; launcher.style.display = 'flex'; return; }

    setTimeout(function () { placeChrome(); play('wave', function () { play('idle'); }); say('สวัสดีครับ! กดที่ผมเพื่อเปิดแชต หรือลากย้าย/ย่อขยายได้เลยครับ'); resetIdle(); }, 600);
  }

  global.MorRuj = { init: init, tour: tour, pointAt: pointAt, say: say, openChat: openChat, closeChat: closeChat, dismiss: dismiss, show: show, wake: wake };
})(window);
