const fs = require('fs');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'videos', 'crm-manual-v2');
const ACTION_DIR = path.join(OUT_DIR, 'action-recordings');
const EPISODE = process.env.CLINICYA_CRM_EPISODE || '01';
const FRAME_DIR = path.join(ACTION_DIR, 'frames');
const PROFILE_DIR = process.env.CLINICYA_CHROME_PROFILE_DIR || path.join(ACTION_DIR, `.chrome-profile-${process.pid}-${EPISODE}`);

const BASE_URL = process.env.CLINICYA_TRAINING_BASE_URL || 'https://tenant-0001.re-ya.com';
const USERNAME = process.env.CLINICYA_TRAINING_USERNAME;
const PASSWORD = process.env.CLINICYA_TRAINING_PASSWORD;
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const FFMPEG = process.env.FFMPEG_PATH || 'ffmpeg';
const PORT = Number(process.env.CLINICYA_CDP_PORT || (20000 + Math.floor(Math.random() * 20000)));
const DRY_RUN = process.argv.includes('--dry-run');
const SCENE_SECONDS = Number(process.env.CLINICYA_SCENE_SECONDS || 5.2);
const INITIAL_SECONDS = Number(process.env.CLINICYA_INITIAL_SECONDS || 2.4);
const WIDTH = Number(process.env.CLINICYA_RECORD_WIDTH || 1600);
const HEIGHT = Number(process.env.CLINICYA_RECORD_HEIGHT || 1000);
const ZOOM = process.env.CLINICYA_BROWSER_ZOOM || '1.18';
const CAPTURE_FPS = Number(process.env.CLINICYA_CAPTURE_FPS || 4);

const episodes = [
  {
    id: '01', slug: 'crm-overview-team-roles', title: 'CRM คืออะไร และทีมแต่ละคนใช้ส่วนไหน', audience: 'เจ้าของร้าน / แอดมิน / เภสัชกร / การตลาด',
    filename: 'crm-01-overview-team-roles.mp4',
    scenes: [
      ['/dashboard', 'เปิด Dashboard: เห็นภาพรวมร้าน ลูกค้าใหม่ และงานที่ต้องตาม'],
      ['/inbox-v2?user=1', 'Inbox v2: จุดทำงานรายวันของเภสัชกรและแอดมิน'],
      ['/inbox-v2?user=1', 'แผงขวา CRM HUD: ดูข้อมูลลูกค้าก่อนตอบหรือจ่ายยา'],
      ['/users', 'Customers: ฐานลูกค้ารวม ข้อมูลติดต่อ แท็ก โน้ต และประวัติ'],
      ['/analytics?tab=crm', 'Analytics: ใช้วัดผล CRM และดูแนวโน้มการดูแลลูกค้า'],
    ],
    outro: 'จำไว้: CRM ไม่ใช่แค่ตอบแชท แต่คือการดูแลลูกค้าต่อเนื่องจากข้อมูลจริง'
  },
  {
    id: '02', slug: 'dashboard-crm', title: 'Dashboard CRM สำหรับดูภาพรวมร้าน', audience: 'เจ้าของร้าน / ผู้จัดการ / แอดมิน', filename: 'crm-02-dashboard-crm.mp4',
    scenes: [
      ['/dashboard?tab=crm', 'Dashboard CRM: ดู KPI ลูกค้าและภาพรวมงาน CRM'],
      ['/dashboard?tab=crm', 'อ่าน KPI: ลูกค้าทั้งหมด ลูกค้าใหม่ แท็ก และ Auto Rules'],
      ['/dashboard?tab=crm', 'Recent Customers: ดูลูกค้าที่เพิ่งเข้าระบบ'],
      ['/dashboard?tab=crm', 'Quick Actions: เข้า Inbox, Customers, Analytics หรือ Broadcast ได้เร็ว'],
    ],
    outro: 'Dashboard ใช้ดูแนวโน้ม ถ้าต้องแก้ข้อมูลรายคนให้ไปที่ Inbox หรือ Customers'
  },
  {
    id: '03', slug: 'inbox-three-columns', title: 'รู้จัก Inbox v2 แบบ 3 คอลัมน์', audience: 'เภสัชกร / แอดมิน / staff', filename: 'crm-03-inbox-3-column.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'Inbox v2 มี 3 ส่วน: รายการแชท ห้องสนทนา และ CRM HUD'],
      ['/inbox-v2?user=1', 'คอลัมน์ซ้าย: รายการแชทเรียงตามข้อความล่าสุด'],
      ['/inbox-v2?user=1', 'คอลัมน์กลาง: ห้องแชท ข้อความ รูป และข้อความระบบ'],
      ['/inbox-v2?user=1', 'คอลัมน์ขวา: ข้อมูลลูกค้า แท็ก โน้ต ประวัติออเดอร์ และประวัติยา'],
    ],
    outro: 'อย่าตอบจากข้อความล่าสุดอย่างเดียว ให้ดูบริบทลูกค้าทางขวาก่อนเสมอ'
  },
  {
    id: '04', slug: 'inbox-search-filter-queue', title: 'ค้นหา กรอง และจัดคิวแชทที่ต้องตอบ', audience: 'แอดมิน / เภสัชกร / staff', filename: 'crm-04-inbox-search-filter-queue.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เริ่มจากรายการแชทด้านซ้าย: ดู unread และเวลาล่าสุด'],
      ['/inbox-v2?user=1', 'ค้นหาจากชื่อ เบอร์โทร หรือ LINE user id'],
      ['/inbox-v2?user=1', 'ใช้ filter เพื่อหางานค้าง เช่น ยังไม่อ่าน ยังไม่ตอบ หรือมี tag'],
      ['/inbox-v2?user=1', 'จัดลำดับจากความเร่งด่วนและบริบทลูกค้า ไม่ใช่จาก filter อย่างเดียว'],
    ],
    outro: 'ก่อนปิดเคส ให้ตรวจว่าตอบครบหรือส่งต่อผู้รับผิดชอบแล้วจริง'
  },
  {
    id: '05', slug: 'chat-quick-reply-transfer', title: 'ตอบแชท ส่งรูป ใช้ Quick Replies และโอนแชท', audience: 'เภสัชกร / แอดมิน / staff', filename: 'crm-05-chat-quick-reply-transfer.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เลือกแชทตัวอย่าง แล้วอ่านบริบทลูกค้าก่อนตอบ'],
      ['/inbox-v2?user=1', 'กล่องพิมพ์ข้อความ: ใช้ตอบข้อความทั่วไป'],
      ['/settings?tab=quick-replies', 'Quick Replies: เตรียมข้อความที่ใช้บ่อย ลดเวลาพิมพ์ซ้ำ'],
      ['/inbox-v2?user=1', 'เคสยา อาการป่วย หรือคำถามเฉพาะ ควรส่งต่อเภสัชกร'],
    ],
    outro: 'Quick Replies ช่วยประหยัดเวลา แต่ต้องตรวจความเหมาะสมก่อนส่งทุกครั้ง'
  },
  {
    id: '06', slug: 'crm-hud-before-reply', title: 'อ่าน CRM HUD ก่อนตอบลูกค้า', audience: 'เภสัชกร / แอดมิน', filename: 'crm-06-crm-hud-before-reply.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เปิดแชทที่มีข้อมูลลูกค้าใน CRM HUD'],
      ['/inbox-v2?user=1', 'ข้อมูลพื้นฐาน: ชื่อ ช่องทางติดต่อ และสถานะลูกค้า'],
      ['/inbox-v2?user=1', 'Tag และกลุ่มลูกค้า: มีผลต่อการกรองและ Broadcast'],
      ['/inbox-v2?user=1', 'Note ภายในทีม: ลูกค้าไม่เห็น แต่ทีมอ่านต่อได้'],
      ['/inbox-v2?user=1', 'ประวัติยา แพ้ยา โรคประจำตัว: ต้องตรวจทุกครั้งก่อนจ่ายซ้ำ'],
    ],
    outro: 'ข้อมูลสุขภาพเป็นข้อมูลละเอียดอ่อน ใช้เพื่อดูแลลูกค้า ไม่ใช้พูดหรือแชร์เกินจำเป็น'
  },
  {
    id: '07', slug: 'tags-and-notes', title: 'ติด Tag ลูกค้าและเขียน Note ให้ทีมอ่านต่อได้', audience: 'แอดมิน / เภสัชกร / การตลาด', filename: 'crm-07-tags-and-notes.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เลือกลูกค้าหนึ่งราย แล้วดู tag ปัจจุบัน'],
      ['/user-tags.php', 'หน้าจัดการ Tags: ตั้งชื่อให้ทีมเข้าใจและไม่ซ้ำหลายแบบ'],
      ['/inbox-v2?user=1', 'ตัวอย่าง tag: VIP, ต้องติดตาม, refill, สนใจโปรโมชัน, เคสเภสัชกร'],
      ['/inbox-v2?user=1', 'Note ที่ดี: วันที่ เหตุการณ์ และสิ่งที่ต้องทำต่อ'],
    ],
    outro: 'Note ควรเป็นข้อเท็จจริงและงานต่อ ไม่ใช่ความเห็นส่วนตัว'
  },
  {
    id: '08', slug: 'orders-medicine-history', title: 'ดูประวัติออเดอร์ ประวัติยา และการติดตามลูกค้า', audience: 'เภสัชกร / แอดมิน', filename: 'crm-08-orders-medicine-history.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เปิดลูกค้าที่มีประวัติ แล้วดู CRM HUD'],
      ['/orders', 'Orders: ดูรายการออเดอร์ สถานะ และช่องทางชำระเงิน'],
      ['/inbox-v2?user=1', 'ประวัติยา: ใช้ประกอบการตอบคำถามและการจ่ายซ้ำ'],
      ['/users', 'Customers: ใช้ดูภาพรวมลูกค้าหลายรายและติดตามต่อ'],
    ],
    outro: 'ประวัติช่วยให้ตอบเร็วขึ้น แต่ไม่แทนการซักถามอาการปัจจุบัน'
  },
  {
    id: '09', slug: 'dispense-line-label', title: 'จ่ายยาใน Inbox และส่งฉลากยาให้ลูกค้าผ่าน LINE', audience: 'เภสัชกร', filename: 'crm-09-dispense-line-label.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เริ่มจาก Inbox และตรวจแพ้ยา โรคประจำตัว ประวัติเดิมก่อนจ่ายยา'],
      ['/inbox-v2?user=1', 'เปิด modal จ่ายยา: เลือกยา จำนวน ขนาดยา มื้อยา ก่อนหรือหลังอาหาร'],
      ['/inbox-v2?user=1', 'เลือกวิธีชำระเงินตาม flow ร้าน เช่น เงินสด โอน เครดิต หรือจ่ายทีหลัง'],
      ['/inbox-v2?user=1', 'หลังยืนยัน ระบบส่งฉลากยาแบบ LINE Flex และบันทึกประวัติจ่ายยา'],
    ],
    outro: 'ตอนนี้เป็น walkthrough เพื่อการสอน หากจะกดยืนยันจริงต้องใช้เคสที่เภสัชกรอนุญาต'
  },
  {
    id: '10', slug: 'refill-follow-up', title: 'เคสติดตาม Refill และการดูแลต่อเนื่อง', audience: 'เภสัชกร / แอดมิน', filename: 'crm-10-refill-follow-up.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'เปิดลูกค้าที่เคยจ่ายยาแล้ว ดูวันที่จ่ายยาและรายการยา'],
      ['/inbox-v2?user=1', 'เพิ่ม tag เช่น ต้องติดตาม หรือ refill เพื่อเตือนทีม'],
      ['/inbox-v2?user=1', 'เขียน note สำหรับรอบถัดไปให้ทีมอ่านต่อได้'],
      ['/users', 'ดูฐานลูกค้าเพื่อวางแผน follow-up โดยไม่ส่งข้อความวินิจฉัยอัตโนมัติ'],
    ],
    outro: 'การติดตามต้องสุภาพและให้เภสัชกรประเมินเคสสุขภาพก่อนเสมอ'
  },
  {
    id: '11', slug: 'customers-management', title: 'หน้า Customers สำหรับดูและจัดการลูกค้า', audience: 'แอดมิน / เจ้าของร้าน / การตลาด', filename: 'crm-11-customers-management.mp4',
    scenes: [
      ['/users', 'Customers: ฐานลูกค้ารวมสำหรับค้นหาและดูข้อมูลหลายราย'],
      ['/users', 'ค้นหาจากชื่อ เบอร์โทร หรือข้อมูลตัวอย่าง'],
      ['/users', 'เปิดรายละเอียดลูกค้า: tag, note, ประวัติซื้อ และสถานะล่าสุด'],
      ['/inbox-v2?user=1', 'กลับไป Inbox เพื่อแสดงว่าข้อมูลเชื่อมกับงานแชท'],
    ],
    outro: 'Customers ใช้จัดการฐานลูกค้า ส่วน Inbox ใช้สื่อสารรายวัน'
  },
  {
    id: '12', slug: 'sales-pipeline-deals', title: 'Sales Pipeline และ Deals', audience: 'เจ้าของร้าน / แอดมิน / ทีมขาย', filename: 'crm-12-sales-pipeline-deals.mp4',
    scenes: [
      ['/crm-advanced', 'CRM Advanced: เข้าเมนูงานขายและการติดตาม'],
      ['/sales-pipeline', 'Sales Pipeline: ติดตามโอกาสขายที่ยังไม่ปิด'],
      ['/deals', 'Deal: โอกาสขายหนึ่งรายการพร้อม stage และกิจกรรมติดตาม'],
      ['/inbox-v2?user=1', 'งานแชทด่วนยังควรเริ่มจาก Inbox แล้วค่อยสร้าง deal ถ้าต้องติดตามหลายวัน'],
    ],
    outro: 'Pipeline เหมาะกับงานขายหลายวัน ไม่ใช่แทน Inbox สำหรับงานตอบด่วน'
  },
  {
    id: '13', slug: 'service-center-tickets', title: 'Service Center และ Tickets', audience: 'แอดมิน / ผู้จัดการ / บริการลูกค้า', filename: 'crm-13-service-center-tickets.mp4',
    scenes: [
      ['/service-center', 'Service Center: ใช้ดูปัญหาหรือคำขอที่ต้องตามต่อ'],
      ['/tickets', 'Ticket: มีสถานะ ผู้รับผิดชอบ และประวัติการแก้ไข'],
      ['/tickets', 'ใช้ filter เพื่อดูงานค้าง งานเร่งด่วน หรือ ticket ตามผู้รับผิดชอบ'],
      ['/inbox-v2?user=1', 'ถ้าเป็นคำถามทั่วไปในแชท ไม่จำเป็นต้องเปิด ticket ทุกครั้ง'],
    ],
    outro: 'ถ้างานต้องติดตามหลายขั้น ให้เปิด ticket และปิดเมื่อแก้ครบแล้ว'
  },
  {
    id: '14', slug: 'tag-segment-broadcast', title: 'ใช้ Tag และ Segment เพื่อเตรียม Broadcast', audience: 'การตลาด / แอดมิน', filename: 'crm-14-tag-segment-broadcast.mp4',
    scenes: [
      ['/inbox-v2?user=1', 'Tag จากหน้าแชทคือจุดเริ่มต้นของการแบ่งกลุ่มลูกค้า'],
      ['/customer-segments.php', 'Segments: กลุ่มเป้าหมายสำหรับการสื่อสาร'],
      ['/broadcast', 'Broadcast: เลือกกลุ่มเป้าหมายและตรวจ preview ข้อความ'],
      ['/broadcast', 'ก่อนส่งจริงต้องตรวจชื่อกลุ่ม จำนวนผู้รับ และความเหมาะสมของเนื้อหา'],
    ],
    outro: 'Tag ที่ดีตั้งแต่หน้าแชท จะทำให้ Broadcast แม่นและปลอดภัยขึ้น'
  },
  {
    id: '15', slug: 'crm-analytics', title: 'CRM Analytics สำหรับอ่านผลลัพธ์', audience: 'เจ้าของร้าน / ผู้จัดการ / การตลาด', filename: 'crm-15-crm-analytics.mp4',
    scenes: [
      ['/analytics?tab=crm', 'CRM Analytics: เลือกช่วงเวลา 7 วัน 30 วัน หรือ 90 วัน'],
      ['/analytics?tab=crm', 'อ่านจำนวนลูกค้า การเติบโตของลูกค้า tag และ segment'],
      ['/analytics?tab=crm', 'ใช้ตัวเลขตอบคำถามธุรกิจ เช่น ลูกค้าใหม่เพิ่มไหม หรือ tag ไหนใช้บ่อย'],
      ['/dashboard?tab=crm', 'ถ้าตัวเลขผิดปกติ ให้ตรวจช่วงเวลา filter และข้อมูลที่กรอกใน CRM'],
    ],
    outro: 'Analytics ต้องอ่านร่วมกับสถานการณ์ร้านจริง ไม่ใช่ดูตัวเลขอย่างเดียว'
  },
  {
    id: '16', slug: 'crm-weekly-reports', title: 'Reports และการสรุปงาน CRM รายสัปดาห์', audience: 'เจ้าของร้าน / ผู้จัดการ / แอดมิน', filename: 'crm-16-crm-weekly-reports.mp4',
    scenes: [
      ['/reports', 'Reports: เลือกรายงานลูกค้า แชท งานค้าง หรือยอดติดตาม'],
      ['/dashboard?tab=crm', 'Dashboard CRM: ใช้สรุปงานรายสัปดาห์ร่วมกับรายงาน'],
      ['/analytics?tab=crm', 'Weekly review 3 ข้อ: งานค้าง ลูกค้าใหม่ โอกาสปรับปรุง'],
      ['/reports', 'ใช้รายงานเพื่อปรับ flow งาน ไม่ใช่เพื่อตำหนิรายบุคคล'],
    ],
    outro: 'รายงานมีค่าก็ต่อเมื่อ Inbox และ Customers ถูกกรอกข้อมูลสม่ำเสมอ'
  },
];

const episode = episodes.find((e) => e.id === EPISODE || e.slug === EPISODE);
if (!episode) {
  console.error(`Unknown CLINICYA_CRM_EPISODE=${EPISODE}`);
  console.error(`Valid episodes: ${episodes.map((e) => e.id).join(', ')}`);
  process.exit(2);
}

if (DRY_RUN) {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const manifest = episodes.map((e) => ({ id: e.id, title: e.title, audience: e.audience, output: path.join('docs/training/videos/crm-manual-v2', e.filename).replace(/\\/g, '/'), scenes: e.scenes.length }));
  fs.writeFileSync(path.join(OUT_DIR, 'crm-manual-v2-plan.json'), JSON.stringify({ generated_at: new Date().toISOString(), base_url: BASE_URL, episodes: manifest }, null, 2), 'utf8');
  console.log(`DRY_RUN ok episodes=${episodes.length} plan=${path.join(OUT_DIR, 'crm-manual-v2-plan.json')}`);
  process.exit(0);
}

if (!USERNAME || !PASSWORD) throw new Error('CLINICYA_TRAINING_USERNAME and CLINICYA_TRAINING_PASSWORD are required');
fs.mkdirSync(ACTION_DIR, { recursive: true });
fs.rmSync(FRAME_DIR, { recursive: true, force: true });
fs.rmSync(PROFILE_DIR, { recursive: true, force: true });
fs.mkdirSync(FRAME_DIR, { recursive: true });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitFor(predicate, timeoutMs = 30000) {
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
    '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${PROFILE_DIR}`,
    `--window-size=${WIDTH},${HEIGHT}`, '--force-device-scale-factor=1', '--disable-gpu',
    '--no-first-run', '--no-default-browser-check', 'about:blank',
  ], { stdio: ['ignore', 'pipe', 'pipe'] });
}

async function connectCdp() {
  await waitFor(async () => {
    try { const res = await fetch(`http://127.0.0.1:${PORT}/json/version`); return res.ok ? await res.json() : null; } catch { return null; }
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
    if (msg.method && listeners.has(msg.method)) for (const fn of listeners.get(msg.method)) fn(msg.params || {});
  });
  const send = (method, params = {}, timeoutMs = 20000) => new Promise((resolve, reject) => {
    const next = ++id;
    const timer = setTimeout(() => {
      if (pending.has(next)) {
        pending.delete(next);
        reject(new Error(`CDP timeout after ${timeoutMs}ms: ${method}`));
      }
    }, timeoutMs);
    pending.set(next, {
      resolve: (value) => { clearTimeout(timer); resolve(value); },
      reject: (error) => { clearTimeout(timer); reject(error); }
    });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  const on = (method, fn) => { if (!listeners.has(method)) listeners.set(method, []); listeners.get(method).push(fn); };
  return { socket, send, on };
}

async function evalJs(cdp, expression) {
  return cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
}

async function waitForLoad(cdp, extraMs = 1200) {
  await waitFor(async () => {
    const res = await evalJs(cdp, 'document.readyState');
    return res.result && res.result.value === 'complete';
  }, 30000);
  await sleep(extraMs);
}

async function navigateTo(cdp, route, extraMs = 2200) {
  const url = route.startsWith('http') ? route : `${BASE_URL}${route}`;
  try {
    await cdp.send('Page.navigate', { url }, 12000);
  } catch (error) {
    console.log(`[navigate warning] Page.navigate fallback for ${url}: ${error.message}`);
    await evalJs(cdp, `location.href = ${JSON.stringify(url)}`);
  }
  await waitForLoad(cdp, extraMs);
  const current = await evalJs(cdp, 'location.href');
  if (!current.result || !String(current.result.value).startsWith(url.split('#')[0])) {
    console.log(`[navigate warning] expected ${url} but current=${current.result && current.result.value}`);
  }
}

async function login(cdp) {
  await cdp.send('Page.navigate', { url: `${BASE_URL}/auth/login.php` });
  await waitForLoad(cdp);
  await evalJs(cdp, `(() => {
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    if (!u || !p) return false;
    u.value = ${JSON.stringify(USERNAME)}; p.value = ${JSON.stringify(PASSWORD)};
    const remember = document.querySelector('input[name="remember"]'); if (remember) remember.checked = true;
    document.querySelector('form').submit(); return true;
  })()`);
  await waitFor(async () => {
    const res = await evalJs(cdp, 'location.href');
    return res.result && !/auth\/login/.test(res.result.value);
  }, 30000);
  await waitForLoad(cdp, 1600);
}

async function injectTrainerUi(cdp, title, caption) {
  await evalJs(cdp, `(() => {
    document.documentElement.style.zoom = ${JSON.stringify(ZOOM)};
    for (const id of ['trainer-caption','trainer-title','trainer-safe','trainer-watermark']) document.getElementById(id)?.remove();
    const mount = document.documentElement;
    function box(id, text, css) {
      const el = document.createElement('div');
      el.id = id;
      el.textContent = text;
      el.setAttribute('style', css);
      mount.appendChild(el);
      return el;
    }
    const baseFont = "font-family:'Noto Sans Thai',Tahoma,Arial,sans-serif;box-sizing:border-box;pointer-events:none;";
    const titleEl = box('trainer-title', ${JSON.stringify(title)}, baseFont + "position:fixed!important;left:24px!important;top:20px!important;z-index:2147483647!important;background:rgba(6,37,27,.96)!important;color:#fff!important;padding:12px 16px!important;border-radius:14px!important;font-size:24px!important;font-weight:900!important;box-shadow:0 8px 24px rgba(0,0,0,.25)!important;max-width:1120px!important;line-height:1.25!important;");
    const captionEl = box('trainer-caption', ${JSON.stringify(caption)}, baseFont + "position:fixed!important;left:50%!important;bottom:26px!important;transform:translateX(-50%)!important;z-index:2147483647!important;background:rgba(15,23,42,.96)!important;color:#fff!important;padding:14px 22px!important;border-radius:14px!important;font-size:25px!important;font-weight:900!important;box-shadow:0 12px 30px rgba(0,0,0,.28)!important;max-width:1160px!important;text-align:center!important;line-height:1.35!important;");
    box('trainer-safe', 'คู่มือภายใน • ใช้ข้อมูลจริงตามสิทธิ์', baseFont + "position:fixed!important;right:20px!important;top:20px!important;z-index:2147483647!important;background:rgba(180,83,9,.96)!important;color:#fff!important;padding:9px 13px!important;border-radius:999px!important;font-size:16px!important;font-weight:800!important;");
    box('trainer-watermark', 'เสียงบรรยายสร้างด้วย AI', baseFont + "position:fixed!important;right:22px!important;bottom:22px!important;z-index:2147483647!important;color:rgba(255,255,255,.95)!important;background:rgba(0,0,0,.45)!important;padding:7px 11px!important;border-radius:10px!important;font-size:14px!important;font-weight:800!important;");
    window.__trainerV2 = { caption(text){ captionEl.textContent = text; titleEl.textContent = ${JSON.stringify(title)}; } };
    return true;
  })()`);
}

async function captureScreencast(cdp) {
  let frameCount = 0;
  let running = true;
  let transientFailures = 0;
  const intervalMs = Math.max(100, Math.round(1000 / CAPTURE_FPS));
  const loop = (async () => {
    while (running) {
      try {
        const shot = await cdp.send('Page.captureScreenshot', {
          format: 'jpeg',
          quality: 84,
          fromSurface: true,
        }, 3000);
        transientFailures = 0;
        frameCount += 1;
        fs.writeFileSync(
          path.join(FRAME_DIR, `frame-${String(frameCount).padStart(5, '0')}.jpg`),
          Buffer.from(shot.data, 'base64')
        );
      } catch (error) {
        transientFailures += 1;
        const message = error && error.message ? error.message : String(error);
        // Chrome can briefly detach the active page during navigation/reload. Treat a
        // small number of screenshot failures as a warning, not as a recorder failure.
        if (running && transientFailures === 1) console.log(`[capture warning] ${message}`);
        if (running && transientFailures >= Math.max(8, CAPTURE_FPS * 4)) {
          throw new Error(`capture failed repeatedly: ${message}`);
        }
      }
      await sleep(intervalMs);
    }
  })();
  return async () => {
    running = false;
    await loop;
    return frameCount;
  };
}

function escapeDrawtext(value) {
  return String(value)
    .replace(/\\/g, '\\\\')
    .replace(/:/g, '\\:')
    .replace(/'/g, "\\'")
    .replace(/%/g, '\\%')
    .replace(/\n/g, '\\n');
}

function buildVideo(frameCount) {
  if (frameCount < 2) throw new Error(`Too few captured frames: ${frameCount}`);
  const out = path.join(OUT_DIR, episode.filename);
  const font = 'C\\:/Windows/Fonts/NotoSansThaiLooped-wdth-wght.ttf';
  const title = escapeDrawtext(`${episode.id}. ${episode.title}`);
  const bottom = escapeDrawtext(`คู่มือภายใน • ${episode.audience} • เสียงบรรยายสร้างด้วย AI`);
  const vf = [
    'fps=30',
    'pad=ceil(iw/2)*2:ceil(ih/2)*2',
    'format=yuv420p',
    'drawbox=x=0:y=0:w=iw:h=72:color=0x06251B@0.92:t=fill',
    `drawtext=fontfile='${font}':text='${title}':fontcolor=white:fontsize=30:x=28:y=18`,
    'drawbox=x=0:y=ih-64:w=iw:h=64:color=0x0F172A@0.88:t=fill',
    `drawtext=fontfile='${font}':text='${bottom}':fontcolor=white:fontsize=24:x=(w-text_w)/2:y=h-44`,
  ].join(',');
  execFileSync(FFMPEG, ['-y', '-framerate', String(CAPTURE_FPS), '-i', path.join(FRAME_DIR, 'frame-%05d.jpg'), '-vf', vf, '-c:v', 'libx264', '-preset', 'medium', '-crf', '22', out], { stdio: 'pipe' });
  return out;
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const chrome = launchChrome();
  try {
    const cdp = await connectCdp();
    await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
    await login(cdp);
    await navigateTo(cdp, episode.scenes[0][0], 2200);
    await injectTrainerUi(cdp, `${episode.id}. ${episode.title}`, `ผู้ชมหลัก: ${episode.audience}`);
    const stopCapture = await captureScreencast(cdp);
    await sleep(INITIAL_SECONDS * 1000);

    for (const [route, caption] of episode.scenes) {
      await evalJs(cdp, `window.__trainerV2?.caption(${JSON.stringify(caption)})`);
      await sleep(700);
      await navigateTo(cdp, route, 2200);
      await injectTrainerUi(cdp, `${episode.id}. ${episode.title}`, caption);
      await sleep(SCENE_SECONDS * 1000);
    }

    await evalJs(cdp, `window.__trainerV2?.caption(${JSON.stringify(episode.outro)})`);
    await sleep(3600);
    const frameCount = await stopCapture();
    const out = buildVideo(frameCount);
    const meta = { generated_at: new Date().toISOString(), base_url: BASE_URL, episode: episode.id, title: episode.title, audience: episode.audience, output: path.relative(ROOT, out).replace(/\\/g, '/'), frames: frameCount, scenes: episode.scenes.map(([route, caption]) => ({ route, caption })), ai_voice_disclosure: 'เสียงบรรยายสร้างด้วย AI', privacy_note: 'Live tenant recording; internal use only.' };
    fs.writeFileSync(path.join(OUT_DIR, `${path.basename(out, '.mp4')}.json`), JSON.stringify(meta, null, 2), 'utf8');
    fs.rmSync(FRAME_DIR, { recursive: true, force: true });
    console.log(`DONE ${out} frames=${frameCount}`);
  } finally {
    try { chrome.kill(); } catch {}
    await sleep(800);
    try { fs.rmSync(PROFILE_DIR, { recursive: true, force: true }); } catch (error) {
      console.log(`[cleanup warning] could not remove Chrome profile ${PROFILE_DIR}: ${error.message}`);
    }
  }
}

main().catch((error) => { console.error(error); process.exit(1); });
