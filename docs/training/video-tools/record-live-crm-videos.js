const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'videos');
const CAPTURE_DIR = path.join(OUT_DIR, 'live-captures');
const SLIDE_DIR = path.join(OUT_DIR, 'slides');
const FRAME_DIR = path.join(OUT_DIR, 'frames');
const BASE_URL = process.env.CLINICYA_TRAINING_BASE_URL || 'https://tenant-0001.re-ya.com';
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const FFMPEG = process.env.FFMPEG_PATH || 'ffmpeg';

for (const dir of [OUT_DIR, CAPTURE_DIR, SLIDE_DIR, FRAME_DIR]) {
  fs.mkdirSync(dir, { recursive: true });
}

const episodes = [
  ['crm-01-overview', 'ตอนที่ 1: ภาพรวม CRM ของร้าน', 'Owner, Admin, Pharmacist', [
    ['dashboard-crm', 'เริ่มจาก Dashboard CRM', ['ดูภาพรวมลูกค้าใหม่ แท็ก และ automation', 'ใช้เป็นจุดเริ่มต้นก่อนลงงานรายวัน']],
    ['inbox-main', 'Inbox คือหัวใจของ CRM', ['ซ้ายคือคิวแชท กลางคือบทสนทนา ขวาคือข้อมูลลูกค้า', 'ก่อนตอบต้องดูบริบทลูกค้าก่อนเสมอ']],
    ['users', 'ฐานลูกค้าเชื่อมกับทุกงาน', ['ใช้ค้นหาและดูข้อมูลลูกค้าหลายราย', 'งานสื่อสารรายวันยังเริ่มที่ Inbox']],
  ]],
  ['crm-02-dashboard', 'ตอนที่ 2: อ่าน Dashboard CRM', 'Owner, Admin', [
    ['dashboard-crm', 'ดู KPI ลูกค้าและ Automation', ['ลูกค้าทั้งหมด ลูกค้าใหม่ Tags และ Auto Rules', 'ใช้ดูแนวโน้ม ไม่ใช่แก้ข้อมูลรายคน']],
    ['dashboard-crm', 'Recent Customers และ Quick Actions', ['ดูคนที่เพิ่งเข้าระบบ', 'ต่อยอดไป Inbox, Users, Broadcast หรือ Analytics']],
    ['analytics-crm', 'เชื่อมต่อไป Analytics', ['เลือกช่วงเวลาเพื่อดูผล CRM', 'ใช้ข้อมูลประกอบการตัดสินใจรายสัปดาห์']],
  ]],
  ['crm-03-inbox-layout', 'ตอนที่ 3: รู้จัก Inbox v2 แบบ 3 คอลัมน์', 'Pharmacist, Admin, Staff', [
    ['inbox-main', 'คอลัมน์ซ้าย: รายการแชท', ['เรียงจากข้อความล่าสุด', 'ใช้ค้นหาและจัดคิวลูกค้าที่ต้องตอบ']],
    ['inbox-main', 'คอลัมน์กลาง: ห้องสนทนา', ['พิมพ์ตอบ ส่งรูป และดูข้อความระบบ', 'ใช้ร่วมกับ template หรือ quick replies']],
    ['inbox-main', 'คอลัมน์ขวา: CRM HUD', ['ดู tag, note, ประวัติยา และประวัติออเดอร์', 'ใช้เช็คความเสี่ยงก่อนตอบหรือจ่ายยา']],
  ]],
  ['crm-04-inbox-queue', 'ตอนที่ 4: ค้นหา กรอง และจัดคิวแชท', 'Admin, Pharmacist, Staff', [
    ['inbox-main', 'ค้นหาแชทจากรายการซ้าย', ['ค้นหาจากชื่อ เบอร์ หรือข้อมูล LINE', 'เลือกเคสที่ต้องตอบก่อน']],
    ['inbox-main', 'ใช้ filter เพื่อโฟกัสงานค้าง', ['ยังไม่อ่าน ยังไม่ตอบ หรือกลุ่ม tag สำคัญ', 'ตรวจ badge และเวลาข้อความล่าสุด']],
    ['inbox-analytics', 'ตรวจภาพรวมแชท', ['ใช้ analytics เพื่อดูโหลดงานของทีม', 'แยกงานด่วนออกจากงานติดตามทั่วไป']],
  ]],
  ['crm-05-chat-replies', 'ตอนที่ 5: ตอบแชทและใช้ข้อความช่วยตอบ', 'Pharmacist, Admin, Staff', [
    ['inbox-main', 'ตอบแชทจากหน้าหลัก', ['อ่านบริบทก่อนส่งข้อความ', 'ตอบด้วยภาษาสั้น ชัดเจน และสุภาพ']],
    ['quick-replies', 'ใช้ Quick Access / Replies', ['เตรียมข้อความที่ส่งบ่อย', 'ตรวจเนื้อหาก่อนส่งทุกครั้ง']],
    ['inbox-main', 'โอนเคสให้คนที่เกี่ยวข้อง', ['เคสยา อาการป่วย หรือข้อมูลเฉพาะให้เภสัชกรดู', 'ระบุเหตุผลในการส่งต่อให้ชัด']],
  ]],
  ['crm-06-crm-hud', 'ตอนที่ 6: อ่าน CRM HUD ก่อนตอบลูกค้า', 'Pharmacist, Admin', [
    ['inbox-main', 'ดูข้อมูลลูกค้าทางขวา', ['ข้อมูลติดต่อ แท็ก โน้ต และระดับสมาชิก', 'ใช้ข้อมูลจริงของ tenant นี้ตามคำสั่งผู้ใช้']],
    ['inbox-main', 'อ่านประวัติยาและประวัติออเดอร์', ['ใช้ประกอบการตอบคำถามและจ่ายยาซ้ำ', 'อย่าเดาจากประวัติเก่าโดยไม่ถามอาการปัจจุบัน']],
    ['dispense-tracking', 'ตรวจประวัติ Dispense เพิ่มเติม', ['ดูรายการที่เคยจ่ายและวันที่', 'ใช้เป็นฐานในการติดตาม refill']],
  ]],
  ['crm-07-tags-notes', 'ตอนที่ 7: ใช้ Tag และ Note ให้ทีมทำงานต่อได้', 'Admin, Pharmacist, Marketing', [
    ['user-tags', 'จัดการ Tag ลูกค้า', ['ใช้ชื่อ tag ให้สั้นและทีมเข้าใจตรงกัน', 'หลีกเลี่ยง tag ซ้ำความหมาย']],
    ['inbox-main', 'ติด tag จากหน้า Inbox', ['tag ช่วยกรองแชทและเตรียม broadcast', 'ใช้กับเคส VIP, refill, ต้องติดตาม']],
    ['auto-tag-rules', 'Auto Tag Rules', ['ตั้ง rule เพื่อช่วยจัดกลุ่มอัตโนมัติ', 'ตรวจผลลัพธ์ก่อนใช้กับ campaign จริง']],
  ]],
  ['crm-08-history', 'ตอนที่ 8: ดูประวัติออเดอร์และประวัติลูกค้า', 'Pharmacist, Admin', [
    ['users', 'เปิดฐานลูกค้า', ['ค้นหาลูกค้าและดูข้อมูลหลายราย', 'เลือกดูรายละเอียดเมื่อจำเป็น']],
    ['shop-orders', 'ดูออเดอร์ของร้าน', ['ตรวจสถานะออเดอร์และการชำระเงิน', 'เชื่อมบริบทก่อนตอบลูกค้า']],
    ['inbox-main', 'กลับมาที่แชทเพื่อคุยต่อ', ['ใช้ประวัติช่วยตอบเร็วขึ้น', 'ถ้าข้อมูลไม่ครบให้ถามลูกค้าเพิ่ม']],
  ]],
  ['crm-09-dispense', 'ตอนที่ 9: จ่ายยาและส่งฉลากผ่าน LINE', 'Pharmacist', [
    ['inbox-main', 'เริ่มจ่ายยาจาก Inbox', ['ตรวจข้อมูลสุขภาพก่อนเปิด flow จ่ายยา', 'เลือกเคสจากแชทลูกค้าที่เกี่ยวข้อง']],
    ['dispense-tracking', 'ติดตามรายการจ่ายยา', ['ตรวจรายการยา วันที่ และสถานะ', 'ใช้ตรวจซ้ำหลังส่งฉลาก']],
    ['inbox-main', 'ยืนยันผลในห้องแชท', ['ลูกค้าจะเห็นฉลากยาใน LINE', 'ถ้ามียาหลายตัวใช้รูปแบบ carousel']],
  ]],
  ['crm-10-refill-followup', 'ตอนที่ 10: ติดตาม Refill และดูแลต่อเนื่อง', 'Pharmacist, Admin', [
    ['dispense-tracking', 'ใช้ประวัติ Dispense เพื่อวางแผนติดตาม', ['ดูวันที่จ่ายและรายการยา', 'แยกเคสขายซ้ำทั่วไปกับเคสที่ต้องประเมิน']],
    ['user-tags', 'ตั้ง tag สำหรับติดตาม', ['เช่น refill, ต้องติดตาม, เคสเภสัชกร', 'ช่วยให้ทีมเห็นงานต่อเนื่อง']],
    ['broadcast', 'ต่อยอดเป็นการสื่อสารกลุ่ม', ['ใช้เฉพาะกลุ่มที่เหมาะสม', 'ตรวจข้อความก่อนส่งจริงทุกครั้ง']],
  ]],
  ['crm-11-users', 'ตอนที่ 11: ฐานลูกค้า Users / Customer 360', 'Admin, Owner, Marketing', [
    ['users', 'ดูรายการลูกค้าทั้งหมด', ['ค้นหาและเปิดข้อมูลลูกค้ารายคน', 'ใช้เมื่ออยากดูฐานลูกค้า ไม่ใช่ตอบแชททันที']],
    ['users', 'อ่านข้อมูลลูกค้าจาก tenant จริง', ['ข้อมูลในวิดีโอนี้ไม่ถูกเบลอตามคำสั่งผู้ใช้', 'ใช้สำหรับทีมที่มีสิทธิ์เห็นข้อมูลจริงเท่านั้น']],
    ['inbox-main', 'เชื่อมกลับไปงานแชท', ['เมื่อต้องคุยกับลูกค้ารายคน ให้กลับมาที่ Inbox', 'ให้ข้อมูลใน Users ช่วยเติมบริบท']],
  ]],
  ['crm-12-segments', 'ตอนที่ 12: Customer Segments', 'Marketing, Admin', [
    ['customer-segments', 'เข้าใจ Segment', ['Segment คือกลุ่มเป้าหมายสำหรับการสื่อสาร', 'สร้างจาก tag หรือเงื่อนไขลูกค้า']],
    ['user-tags', 'Tag คือฐานของ Segment', ['tag ที่ดีทำให้ segment แม่นขึ้น', 'ตรวจชื่อและความหมายก่อนใช้']],
    ['broadcast', 'ใช้ Segment กับ Broadcast', ['เลือกกลุ่มให้ตรง campaign', 'ระวังข้อความที่เกี่ยวกับสุขภาพ']],
  ]],
  ['crm-13-auto-rules', 'ตอนที่ 13: Auto Tag และ Automation', 'Admin, Marketing', [
    ['auto-tag-rules', 'ตั้ง Auto Tag Rules', ['ช่วยจัดกลุ่มจาก keyword หรือเงื่อนไข', 'ตั้ง priority และทดสอบก่อนใช้งานจริง']],
    ['inbox-main', 'ดูผลที่กลับมาใน Inbox', ['ลูกค้าถูกจัดกลุ่มเพื่อให้ทีมทำงานเร็วขึ้น', 'ยังต้องให้คนตรวจความถูกต้อง']],
    ['analytics-crm', 'วัดผลหลังใช้งาน', ['ดู tag และ segment ที่เพิ่มขึ้น', 'ปรับ rule หากข้อมูลเริ่มผิดกลุ่ม']],
  ]],
  ['crm-14-broadcast', 'ตอนที่ 14: Broadcast และ Marketing Hub', 'Marketing, Admin', [
    ['broadcast', 'เตรียม Broadcast', ['เลือกกลุ่มเป้าหมาย ข้อความ และช่วงเวลาส่ง', 'ตรวจ preview ก่อนส่งจริง']],
    ['customer-segments', 'เลือกกลุ่มจาก Segment', ['ส่งให้ตรงกลุ่ม ลดข้อความรบกวน', 'ห้ามใช้ข้อมูลสุขภาพผิดบริบท']],
    ['drip-campaigns', 'Drip Campaigns', ['ใช้สำหรับการสื่อสารเป็นลำดับ', 'เหมาะกับ onboarding หรือ follow-up campaign']],
  ]],
  ['crm-15-analytics', 'ตอนที่ 15: CRM Analytics', 'Owner, Manager, Marketing', [
    ['analytics-crm', 'อ่านผล CRM ตามช่วงเวลา', ['เลือก 7, 30 หรือ 90 วันตามคำถามที่ต้องตอบ', 'ดูแนวโน้ม ไม่ใช่ตัวเลขเดี่ยว']],
    ['inbox-analytics', 'อ่านผลแชท', ['ดูปริมาณงานและประสิทธิภาพการตอบ', 'ใช้ช่วยแบ่งงานในทีม']],
    ['link-tracking', 'ติดตามลิงก์และ campaign', ['ดูว่าลูกค้าคลิกอะไร', 'ใช้ปรับข้อความและกลุ่มเป้าหมาย']],
  ]],
  ['crm-16-weekly-report', 'ตอนที่ 16: สรุปงาน CRM รายสัปดาห์', 'Owner, Manager, Admin', [
    ['dashboard-crm', 'เริ่ม weekly review ที่ Dashboard CRM', ['สรุปลูกค้าใหม่ งานค้าง และ automation', 'ดูภาพรวมก่อนลงรายละเอียด']],
    ['analytics-crm', 'อ่านรายงานผลลัพธ์', ['ดูช่วงเวลาเดียวกันทุกสัปดาห์', 'จดสิ่งที่ต้องปรับปรุง']],
    ['broadcast', 'วางแผน action ถัดไป', ['เลือก campaign หรือ follow-up ที่ควรทำ', 'ปิดท้ายด้วยเจ้าของงานและวันติดตาม']],
  ]],
];

function escapeHtml(text) {
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fileUrl(filePath) {
  return 'file:///' + filePath.replace(/\\/g, '/').replace(/#/g, '%23');
}

function slideHtml({ id, title, audience, scene, sceneIndex, totalScenes }) {
  const [pageKey, heading, bullets] = scene;
  const imagePath = path.join(CAPTURE_DIR, `${pageKey}.png`);
  const bulletHtml = bullets.map((b) => `<li>${escapeHtml(b)}</li>`).join('');
  return `<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<style>
@font-face{font-family:ThaiLooped;src:url("file:///C:/Windows/Fonts/NotoSansThaiLooped-wdth-wght.ttf");}
*{box-sizing:border-box}
body{margin:0;width:1280px;height:720px;background:#071015;font-family:ThaiLooped,Tahoma,sans-serif;color:#f8fafc;overflow:hidden}
.stage{position:relative;width:1280px;height:720px;background:#0b1220}
.shot-wrap{position:absolute;left:24px;top:78px;width:900px;height:563px;border:1px solid #334155;border-radius:18px;overflow:hidden;background:#111827;box-shadow:0 22px 55px rgba(0,0,0,.38)}
.shot{position:absolute;left:0;top:0;width:900px;height:563px;object-fit:cover}
.panel{position:absolute;right:28px;top:78px;width:328px;height:563px;border-radius:18px;background:#f8fafc;color:#0f172a;padding:24px;box-shadow:0 22px 55px rgba(0,0,0,.32);display:flex;flex-direction:column}
.eyebrow{font-size:13px;font-weight:800;color:#047857;margin-bottom:8px}
h1{font-size:26px;line-height:1.25;margin:0 0 16px;font-weight:850;color:#0f172a}
.audience{font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:18px}
h2{font-size:22px;line-height:1.22;margin:0 0 14px;color:#111827}
ul{margin:0;padding-left:20px;display:grid;gap:12px;font-size:17px;line-height:1.42}
.footer{margin-top:auto;border-top:1px solid #e2e8f0;padding-top:16px;display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#64748b}
.top{position:absolute;left:30px;right:30px;top:24px;display:flex;justify-content:space-between;align-items:center}
.brand{font-weight:850;font-size:18px;color:#dcfce7}.domain{font-size:13px;color:#86efac}.warn{font-size:12px;color:#fef3c7}
</style>
</head>
<body>
<div class="stage">
  <div class="top">
    <div><span class="brand">REYA CRM Live Manual</span> <span class="domain">${escapeHtml(BASE_URL)}</span></div>
    <div class="warn">ใช้ข้อมูลจริงของ tenant นี้ ไม่มีการเบลอข้อมูล</div>
  </div>
  <div class="shot-wrap"><img class="shot" src="${fileUrl(imagePath)}"></div>
  <aside class="panel">
    <div class="eyebrow">คู่มือ CRM</div>
    <h1>${escapeHtml(title)}</h1>
    <div class="audience">ผู้ชมหลัก: ${escapeHtml(audience)}</div>
    <h2>${escapeHtml(heading)}</h2>
    <ul>${bulletHtml}</ul>
    <div class="footer"><span>ฉาก ${sceneIndex + 1}/${totalScenes}</span><span>${escapeHtml(pageKey)}</span></div>
  </aside>
</div>
</body>
</html>`;
}

function screenshotHtml(htmlPath, pngPath) {
  execFileSync(CHROME, [
    '--headless=new',
    '--disable-gpu',
    '--hide-scrollbars',
    '--window-size=1280,720',
    `--screenshot=${pngPath}`,
    fileUrl(htmlPath),
  ], { stdio: 'pipe' });
}

function buildVideo(id, frames) {
  const listPath = path.join(FRAME_DIR, `${id}.ffconcat.txt`);
  const lines = [];
  for (const frame of frames) {
    lines.push(`file '${frame.replace(/\\/g, '/').replace(/'/g, "'\\''")}'`);
    lines.push('duration 5.5');
  }
  lines.push(`file '${frames[frames.length - 1].replace(/\\/g, '/').replace(/'/g, "'\\''")}'`);
  fs.writeFileSync(listPath, lines.join('\n'), 'utf8');
  const outPath = path.join(OUT_DIR, `${id}.mp4`);
  execFileSync(FFMPEG, [
    '-y',
    '-f', 'concat',
    '-safe', '0',
    '-i', listPath,
    '-vf', 'fps=30,format=yuv420p',
    '-c:v', 'libx264',
    '-preset', 'medium',
    '-crf', '23',
    outPath,
  ], { stdio: 'pipe' });
  return outPath;
}

const manifest = {
  generated_at: new Date().toISOString(),
  base_url: BASE_URL,
  privacy: 'Live tenant screenshots are used without redaction per user instruction.',
  videos: [],
};

for (const [id, title, audience, scenes] of episodes) {
  const usableScenes = scenes.filter(([pageKey]) => fs.existsSync(path.join(CAPTURE_DIR, `${pageKey}.png`)));
  const frames = [];
  usableScenes.forEach((scene, index) => {
    const htmlPath = path.join(SLIDE_DIR, `${id}-${index + 1}.html`);
    const pngPath = path.join(FRAME_DIR, `${id}-${index + 1}.png`);
    fs.writeFileSync(htmlPath, slideHtml({ id, title, audience, scene, sceneIndex: index, totalScenes: usableScenes.length }), 'utf8');
    screenshotHtml(htmlPath, pngPath);
    frames.push(pngPath);
  });
  const video = buildVideo(id, frames);
  for (const frame of frames) {
    fs.rmSync(frame, { force: true });
  }
  fs.rmSync(path.join(FRAME_DIR, `${id}.ffconcat.txt`), { force: true });
  manifest.videos.push({
    id,
    title,
    audience,
    file: path.relative(ROOT, video).replace(/\\/g, '/'),
    scenes: usableScenes.map(([pageKey, heading]) => ({ pageKey, heading })),
  });
  console.log(`Built ${id}`);
}

fs.writeFileSync(path.join(OUT_DIR, 'crm-live-video-manifest.json'), JSON.stringify(manifest, null, 2), 'utf8');
console.log(`Done. Videos: ${manifest.videos.length}`);
