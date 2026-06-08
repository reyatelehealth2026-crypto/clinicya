const fs = require('fs');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'videos');
const ACTION_DIR = path.join(OUT_DIR, 'action-recordings');
const FRAME_DIR = path.join(ACTION_DIR, 'frames-inbox-points');
const PROFILE_DIR = path.join(ACTION_DIR, '.chrome-profile-inbox-points');

function requireEnv(name) {
  const value = process.env[name];
  if (!value || !value.trim()) {
    throw new Error(`${name} is required for live tenant recording`);
  }
  return value.trim();
}

const BASE_URL = process.env.CLINICYA_TRAINING_BASE_URL || 'https://tenant-0001.re-ya.com';
const USERNAME = requireEnv('CLINICYA_TRAINING_USERNAME');
const PASSWORD = requireEnv('CLINICYA_TRAINING_PASSWORD');
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const FFMPEG = process.env.FFMPEG_PATH || 'ffmpeg';
const PORT = Number(process.env.CLINICYA_CDP_PORT || 9224);
const POINTS_TO_ADD = process.env.CLINICYA_POINTS_TO_ADD || '1';
const MODE = process.env.CLINICYA_POINTS_MODE || 'direct';
const VALID_MODES = new Set(['direct', 'qr', 'overview', 'membership', 'rewards']);

if (!VALID_MODES.has(MODE)) {
  throw new Error(`CLINICYA_POINTS_MODE must be one of: ${Array.from(VALID_MODES).join(', ')}`);
}

fs.mkdirSync(ACTION_DIR, { recursive: true });
fs.rmSync(FRAME_DIR, { recursive: true, force: true });
fs.rmSync(PROFILE_DIR, { recursive: true, force: true });
fs.mkdirSync(FRAME_DIR, { recursive: true });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitFor(predicate, timeoutMs = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const value = await predicate();
    if (value) return value;
    await sleep(250);
  }
  throw new Error('Timed out waiting for condition');
}

function launchChrome() {
  return spawn(CHROME, [
    '--headless=new',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${PROFILE_DIR}`,
    '--window-size=1440,900',
    '--force-device-scale-factor=1',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    'about:blank',
  ], { stdio: ['ignore', 'pipe', 'pipe'] });
}

async function connectCdp() {
  await waitFor(async () => {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/version`);
      return res.ok ? await res.json() : null;
    } catch {
      return null;
    }
  }, 20000);
  const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
  const target = targets.find((item) => item.type === 'page' && item.webSocketDebuggerUrl);
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let id = 0;
  const pending = new Map();
  const listeners = new Map();
  socket.addEventListener('message', (event) => {
    const msg = JSON.parse(event.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result || {});
      return;
    }
    if (msg.method && listeners.has(msg.method)) {
      for (const fn of listeners.get(msg.method)) fn(msg.params || {});
    }
  });

  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id;
    pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  const on = (method, fn) => {
    if (!listeners.has(method)) listeners.set(method, []);
    listeners.get(method).push(fn);
  };
  return { socket, send, on };
}

async function waitForLoad(cdp, extraMs = 1200) {
  await waitFor(async () => {
    const res = await cdp.send('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true });
    return res.result && res.result.value === 'complete';
  }, 30000);
  await sleep(extraMs);
}

async function evalJs(cdp, expression) {
  return cdp.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  });
}

async function login(cdp) {
  await cdp.send('Page.navigate', { url: `${BASE_URL}/auth/login.php` });
  await waitForLoad(cdp);
  await evalJs(cdp, `
    (() => {
      document.querySelector('input[name="username"]').value = ${JSON.stringify(USERNAME)};
      document.querySelector('input[name="password"]').value = ${JSON.stringify(PASSWORD)};
      const remember = document.querySelector('input[name="remember"]');
      if (remember) remember.checked = true;
      document.querySelector('form').submit();
      return true;
    })()
  `);
  await waitFor(async () => {
    const res = await evalJs(cdp, 'location.href');
    return res.result && /dashboard/.test(res.result.value);
  }, 30000);
  await waitForLoad(cdp, 1800);
}

async function injectTrainerUi(cdp) {
  await evalJs(cdp, `
    (() => {
      const old = document.getElementById('trainer-cursor');
      if (old) old.remove();
      const style = document.createElement('style');
      style.id = 'trainer-style';
      style.textContent = \`
        #trainer-cursor{position:fixed;left:20px;top:20px;width:30px;height:30px;border:3px solid #ef4444;border-radius:999px;z-index:2147483647;pointer-events:none;box-shadow:0 0 0 7px rgba(239,68,68,.16);transition:left .45s ease,top .45s ease,transform .16s ease;background:rgba(255,255,255,.2)}
        #trainer-cursor.click{transform:scale(.72);background:rgba(239,68,68,.34)}
        #trainer-caption{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:2147483647;background:rgba(15,23,42,.94);color:#fff;padding:12px 18px;border-radius:12px;font:700 20px "Noto Sans Thai","Tahoma",sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.28);max-width:980px;text-align:center}
      \`;
      document.head.appendChild(style);
      const cursor = document.createElement('div');
      cursor.id = 'trainer-cursor';
      document.body.appendChild(cursor);
      const caption = document.createElement('div');
      caption.id = 'trainer-caption';
      caption.textContent = 'เริ่มอัด: เพิ่มแต้มจากหน้า Inbox จริง';
      document.body.appendChild(caption);
      window.__trainer = {
        move(x, y, text) {
          cursor.style.left = (x - 15) + 'px';
          cursor.style.top = (y - 15) + 'px';
          if (text) caption.textContent = text;
        },
        click() {
          cursor.classList.add('click');
          setTimeout(() => cursor.classList.remove('click'), 220);
        },
        caption(text) { caption.textContent = text; }
      };
      return true;
    })()
  `);
}

async function rectFor(cdp, selector) {
  const res = await evalJs(cdp, `
    (() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) return null;
      el.scrollIntoView({ block: 'center', inline: 'center' });
      const r = el.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2, width: r.width, height: r.height };
    })()
  `);
  const rect = res.result && res.result.value;
  if (!rect) throw new Error(`Selector not found: ${selector}`);
  await sleep(650);
  return rect;
}

async function moveCursor(cdp, x, y, caption) {
  await evalJs(cdp, `window.__trainer.move(${Math.round(x)}, ${Math.round(y)}, ${JSON.stringify(caption || '')})`);
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
  await sleep(650);
}

async function clickAt(cdp, x, y, caption) {
  await moveCursor(cdp, x, y, caption);
  await evalJs(cdp, 'window.__trainer.click()');
  await cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
  await sleep(80);
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  await sleep(900);
}

async function clickSelector(cdp, selector, caption) {
  const rect = await rectFor(cdp, selector);
  await clickAt(cdp, rect.x, rect.y, caption);
}

async function typeText(cdp, text) {
  for (const ch of text) {
    await cdp.send('Input.dispatchKeyEvent', { type: 'char', text: ch });
    await sleep(120);
  }
}

async function captureScreencast(cdp) {
  let frameCount = 0;
  cdp.on('Page.screencastFrame', async (params) => {
    frameCount += 1;
    const file = path.join(FRAME_DIR, `frame-${String(frameCount).padStart(5, '0')}.jpg`);
    fs.writeFileSync(file, Buffer.from(params.data, 'base64'));
    await cdp.send('Page.screencastFrameAck', { sessionId: params.sessionId });
  });
  await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 78, everyNthFrame: 1 });
  return () => frameCount;
}

function buildVideo(frameCount) {
  if (frameCount < 2) throw new Error(`Too few screencast frames: ${frameCount}`);
  const outName = MODE === 'qr'
    ? 'crm-inbox-points-qr-click-recording.mp4'
    : MODE === 'overview'
      ? 'crm-overview-click-recording.mp4'
    : MODE === 'membership'
      ? 'crm-membership-points-click-recording.mp4'
    : MODE === 'rewards'
      ? 'crm-rewards-redemption-click-recording.mp4'
    : 'crm-inbox-add-points-click-recording.mp4';
  const out = path.join(OUT_DIR, outName);
  execFileSync(FFMPEG, [
    '-y',
    '-framerate', '12',
    '-i', path.join(FRAME_DIR, 'frame-%05d.jpg'),
    '-vf', 'fps=30,pad=ceil(iw/2)*2:ceil(ih/2)*2,format=yuv420p',
    '-c:v', 'libx264',
    '-preset', 'medium',
    '-crf', '24',
    out,
  ], { stdio: 'pipe' });
  return out;
}

async function main() {
  const chrome = launchChrome();
  try {
    const cdp = await connectCdp();
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await login(cdp);
    await cdp.send('Page.navigate', { url: `${BASE_URL}/inbox-v2?user=1` });
    await waitForLoad(cdp, 4500);
    await injectTrainerUi(cdp);
    const getFrameCount = await captureScreencast(cdp);

    if (MODE === 'membership' || MODE === 'rewards') {
      const steps = MODE === 'membership'
        ? [
            ['/membership', 'ระบบสมาชิก: ดูจำนวนสมาชิก ระดับสมาชิก และภาพรวมแต้มสะสม'],
            ['/membership?tab=rewards', 'รางวัลแลกแต้ม: ดูของรางวัล แต้มที่ใช้ และสถานะสต็อก'],
            ['/membership?tab=settings', 'ตั้งค่าแต้ม: ตรวจอัตราคำนวณแต้มและกติกาหลักของร้าน'],
            ['/inbox-v2?user=1', 'เชื่อมกลับมาที่ Inbox: ให้แต้มจากหน้าแชทหรือสร้าง QR รับแต้มได้'],
          ]
        : [
            ['/membership?tab=rewards', 'รางวัลแลกแต้ม: จุดที่แอดมินจัดการของรางวัลให้ลูกค้า'],
            ['/membership', 'สมาชิก: ตรวจแต้มและระดับสมาชิกก่อนอธิบายการแลก'],
            ['/inbox-v2?user=1', 'Inbox: ลูกค้าได้รับแต้มจากการขายหน้าร้านหรือ QR แล้วนำไปแลกรางวัล'],
          ];

      for (const [route, caption] of steps) {
        await evalJs(cdp, `window.__trainer.caption(${JSON.stringify(caption)})`);
        await sleep(900);
        await cdp.send('Page.navigate', { url: `${BASE_URL}${route}` });
        await waitForLoad(cdp, 3000);
        await injectTrainerUi(cdp);
        await evalJs(cdp, `window.__trainer.caption(${JSON.stringify(caption)})`);
        await sleep(5000);
      }

      await evalJs(cdp, `window.__trainer.caption(${JSON.stringify(MODE === 'membership'
        ? 'จบตอนระบบสมาชิกและแต้ม: ใช้หน้านี้ดูสมาชิก ตั้งค่ากติกา และต่อยอดไปให้แต้มจาก Inbox'
        : 'จบตอนรางวัลแลกแต้ม: ใช้หน้านี้ควบคุมของรางวัลและติดตามการแลกของลูกค้า'
      )})`);
      await sleep(3400);
      await cdp.send('Page.stopScreencast');
      await sleep(500);
      const frameCount = getFrameCount();
      const out = buildVideo(frameCount);
      fs.writeFileSync(path.join(ACTION_DIR, `${path.basename(out, '.mp4')}.json`), JSON.stringify({
        generated_at: new Date().toISOString(),
        base_url: BASE_URL,
        action: MODE === 'membership' ? 'membership_points_overview' : 'rewards_redemption_overview',
        mode: MODE,
        output: path.relative(ROOT, out).replace(/\\/g, '/'),
        frames: frameCount,
        note: 'Read-only recording against tenant-0001; no points or rewards are changed.',
      }, null, 2), 'utf8');
      fs.rmSync(FRAME_DIR, { recursive: true, force: true });
      console.log(`DONE ${out} frames=${frameCount}`);
      return;
    }

    if (MODE === 'overview') {
      await evalJs(cdp, 'window.__trainer.caption("เริ่มแนะนำ CRM ทั้งหมดจาก Dashboard CRM")');
      await sleep(2400);

      const steps = [
        ['/inbox-v2?user=1', 'ไปหน้า Inbox: แชทลูกค้า + CRM HUD + แต้ม'],
        ['/users', 'ไปหน้า Users: ฐานลูกค้าและ Customer 360'],
        ['/user-tags.php', 'ไปหน้า Tags: จัดกลุ่มลูกค้าเพื่อใช้งาน CRM'],
        ['/customer-segments.php', 'ไปหน้า Segments: กลุ่มเป้าหมายสำหรับ CRM / Marketing'],
        ['/broadcast', 'ไปหน้า Broadcast: ส่งข้อความตามกลุ่มลูกค้า'],
        ['/analytics?tab=crm', 'ไปหน้า CRM Analytics: อ่านผลลัพธ์และแนวโน้ม'],
        ['/membership', 'ไปหน้า Membership: แต้มสะสมและสิทธิสมาชิก'],
      ];

      for (const [route, caption] of steps) {
        await evalJs(cdp, `window.__trainer.caption(${JSON.stringify(caption)})`);
        await sleep(900);
        await cdp.send('Page.navigate', { url: `${BASE_URL}${route}` });
        await waitForLoad(cdp, 2800);
        await injectTrainerUi(cdp);
        await evalJs(cdp, `window.__trainer.caption(${JSON.stringify(caption)})`);
        await sleep(4200);
      }

      await evalJs(cdp, 'window.__trainer.caption("จบภาพรวม CRM: ใช้ Dashboard ดูภาพรวม, Inbox ทำงานรายวัน, Analytics วัดผล")');
      await sleep(3600);
      await cdp.send('Page.stopScreencast');
      await sleep(500);
      const frameCount = getFrameCount();
      const out = buildVideo(frameCount);
      fs.writeFileSync(path.join(ACTION_DIR, 'crm-overview-click-recording.json'), JSON.stringify({
        generated_at: new Date().toISOString(),
        base_url: BASE_URL,
        action: 'crm_overview_navigation',
        mode: MODE,
        output: path.relative(ROOT, out).replace(/\\/g, '/'),
        frames: frameCount,
      }, null, 2), 'utf8');
      fs.rmSync(FRAME_DIR, { recursive: true, force: true });
      console.log(`DONE ${out} frames=${frameCount}`);
      return;
    }

    await evalJs(cdp, 'window.__trainer.caption("หน้า Inbox จริง: เลือกลูกค้า user=1 แล้ว")');
    await sleep(1800);

    await clickSelector(cdp, 'button[onclick="openGivePointsModal()"]', 'คลิกปุ่ม 🎁 ให้แต้ม ใน CRM HUD');
    await sleep(900);
    await evalJs(cdp, `
      (() => {
        const modal = document.getElementById('givePointsModal');
        if (modal && modal.classList.contains('hidden') && typeof window.openGivePointsModal === 'function') {
          window.openGivePointsModal();
        }
        return !document.getElementById('givePointsModal')?.classList.contains('hidden');
      })()
    `);
    await waitFor(async () => {
      const res = await evalJs(cdp, '!document.getElementById("givePointsModal")?.classList.contains("hidden")');
      return res.result && res.result.value === true;
    }, 10000);

    await clickSelector(cdp, '#givePointsPoints', `คลิกช่องแต้มที่จะให้ แล้วพิมพ์ ${POINTS_TO_ADD} แต้ม`);
    await typeText(cdp, POINTS_TO_ADD);
    await evalJs(cdp, `
      (() => {
        const el = document.getElementById('givePointsPoints');
        el.value = ${JSON.stringify(POINTS_TO_ADD)};
        el.dispatchEvent(new Event('input', { bubbles: true }));
        if (typeof window.givePointsOnPointsInput === 'function') window.givePointsOnPointsInput();
        return el.value;
      })()
    `);
    await sleep(850);

    await clickSelector(cdp, '#givePointsPayment', 'เลือกวิธีชำระเงินใน modal');
    await evalJs(cdp, `
      (() => {
        const el = document.getElementById('givePointsPayment');
        el.value = 'cash';
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      })()
    `);
    await sleep(1000);

    if (MODE === 'qr') {
      await clickSelector(cdp, '#givePointsGenerateBtn', 'คลิก สร้าง QR แทน เพื่อให้ลูกค้าสแกนรับแต้ม');
      await sleep(1800);
      await evalJs(cdp, `
        (async () => {
          const done = !document.getElementById("givePointsResult")?.classList.contains("hidden");
          const err = !document.getElementById("givePointsError")?.classList.contains("hidden");
          if (!done && !err && typeof window.givePointsGenerate === 'function') {
            await window.givePointsGenerate();
          }
          return !document.getElementById("givePointsResult")?.classList.contains("hidden");
        })()
      `);
      await waitFor(async () => {
        const res = await evalJs(cdp, '!document.getElementById("givePointsResult")?.classList.contains("hidden")');
        return res.result && res.result.value === true;
      }, 20000);
      await evalJs(cdp, 'window.__trainer.caption("สำเร็จ: ระบบสร้าง QR รับแต้มให้ลูกค้าสแกนแล้ว")');
    } else {
      await clickSelector(cdp, '#givePointsDirectBtn', 'คลิก ให้ทันที เพื่อเพิ่มแต้มเข้าลูกค้าคนนี้จริง');
      await sleep(1800);
      await evalJs(cdp, `
        (async () => {
          const done = !document.getElementById("givePointsDirectResult")?.classList.contains("hidden");
          const err = !document.getElementById("givePointsError")?.classList.contains("hidden");
          if (!done && !err && typeof window.givePointsGiveDirect === 'function') {
            await window.givePointsGiveDirect();
          }
          return !document.getElementById("givePointsDirectResult")?.classList.contains("hidden");
        })()
      `);
      await waitFor(async () => {
        const res = await evalJs(cdp, '!document.getElementById("givePointsDirectResult")?.classList.contains("hidden")');
        return res.result && res.result.value === true;
      }, 20000);
      await evalJs(cdp, 'window.__trainer.caption("สำเร็จ: ระบบเพิ่มแต้มและแสดงผลใน modal แล้ว")');
    }
    await sleep(3200);

    await cdp.send('Page.stopScreencast');
    await sleep(500);
    const frameCount = getFrameCount();
    const out = buildVideo(frameCount);
    fs.writeFileSync(path.join(ACTION_DIR, `${path.basename(out, '.mp4')}.json`), JSON.stringify({
      generated_at: new Date().toISOString(),
      base_url: BASE_URL,
      route: '/inbox-v2?user=1',
      action: MODE === 'qr' ? 'create_points_qr' : 'give_direct_points',
      mode: MODE,
      points_added: Number(POINTS_TO_ADD),
      output: path.relative(ROOT, out).replace(/\\/g, '/'),
      frames: frameCount,
      note: 'This recording performs the real add-points action against tenant-0001.',
    }, null, 2), 'utf8');
    fs.rmSync(FRAME_DIR, { recursive: true, force: true });
    console.log(`DONE ${out} frames=${frameCount}`);
  } finally {
    chrome.kill();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
