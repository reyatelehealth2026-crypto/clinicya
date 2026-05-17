// Generate REYA pitch deck HTML from screenshots — print to PDF via Chrome headless
// Run: node generate-deck.js

const fs = require('fs');
const path = require('path');

const SHOTS_DIR = path.resolve(__dirname, '..', 'screenshots');
const OUT_HTML = path.join(__dirname, 'deck.html');

const fileUrl = (rel) => 'file:///' + path.join(SHOTS_DIR, rel).replace(/\\/g, '/');

// Slide data
const slides = [
  // === COVER ===
  { type: 'cover', title: 'REYA', subtitle: 'Telepharmacy & CRM Platform', tagline: 'แชท · ขาย · จ่ายยา · ERP — ในระบบเดียวบน LINE', date: 'May 2026' },

  // === INTRO ===
  { type: 'pain', title: 'ปัญหาของร้านยาออนไลน์วันนี้', items: [
    'ลูกค้าทักผ่าน LINE หลายแอป จัดการยุ่ง',
    'เภสัชกรสลับหน้าจอเยอะ ทำงานช้า',
    'ขายของผ่าน LINE ไม่มีระบบหลังบ้านครบ',
    'ไม่รู้ลูกค้าซื้ออะไร แพ้ยาอะไร',
  ]},
  { type: 'value', title: 'REYA ทำอะไร', subtitle: 'ในประโยคเดียว', items: [
    'ระบบเดียวจบ — แชท + ขาย + จ่ายยา + ERP',
    'ลูกค้าใช้ผ่าน LINE Mini App ไม่ต้องโหลดแอป',
    'เภสัชกรทำงานจากหน้าเดียว',
    'รองรับหลายร้านในระบบเดียว (Multi-tenant)',
  ]},

  // === SECTION 0 — Landing ===
  { type: 'section', title: 'หมวด 0', subtitle: 'หน้าแรกสาธารณะ — Landing Page', sectionName: 'หน้าแรก' },
  { type: 'image', image: '00-landing/landing-home-fullpage.png', title: 'Landing Page', caption: 'หน้าเว็บสาธารณะที่ re-ya.com — Hero "ปรึกษาเภสัชกรและสั่งยากับ REYA" + บริการ + คุณสมบัติเด่น + 8 SKU แนะนำ' },

  // === SECTION 1 — Mini App (with phone frame) ===
  { type: 'section', title: 'หมวด 1', subtitle: 'สิ่งที่ลูกค้าเห็น (LINE Mini App)', sectionName: 'Customer-facing experience — 14+ pages' },
  { type: 'miniapp', image: '01-miniapp/miniapp-home-viewport.png', title: 'Mini App Home (Guest)', caption: 'หน้าหลักก่อน login', bullets: ['Banner flash sale', 'Tab nav 5 menu', 'Quick links: Shop · AI Chat · Appointments · Rewards · Wishlist'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-home-authed-viewport.png', title: 'Mini App Home (Authed)', caption: 'หลัง login — แสดงข้อมูลส่วนตัว', bullets: ['Member tier + แต้มสะสม', 'ตะกร้ายังค้าง', 'ออเดอร์ล่าสุด TXN', 'ปุ่ม "เปิดแจ้งเตือน"'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-shop-viewport.png', title: 'Shop — รวมสินค้า', caption: '30 สินค้าพร้อมขาย', bullets: ['Search bar + 3 sort tabs', '12 หมวดยา', 'Brand filter', 'Card: รูป + ราคา + ใส่ตะกร้า'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-product-detail-viewport.png', title: 'Product Detail', caption: 'หน้ารายละเอียดสินค้า', bullets: ['รูป + ชื่อ generic + SKU', 'ราคา + เลือกจำนวน', 'Wishlist heart', 'Related products carousel'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-cart-with-item-viewport.png', title: 'Cart — ตะกร้า', caption: 'จัดการก่อนชำระเงิน', bullets: ['ปรับจำนวนสินค้า +/-', 'ยอดสินค้า + ค่าจัดส่ง', 'ยอดรวม', 'ปุ่ม "ไปชำระเงิน"'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-checkout-qr-viewport.png', title: 'Checkout — โอน QR', caption: 'PromptPay + ที่อยู่จัดส่ง', bullets: ['ที่อยู่ผู้รับ', 'QR PromptPay สแกนจ่าย', 'ชื่อ + เลขบัญชี copy', 'โค้ดส่วนลด + สรุปยอด'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-checkout-cod-fullpage.png', title: 'Checkout — COD', caption: 'ทางเลือก: ชำระปลายทาง', bullets: ['Toggle เปลี่ยนช่องทาง', 'รองรับ COD', 'สรุปยอดเดียวกัน', 'Push LINE notification หลังสั่ง'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-orders-list-viewport.png', title: 'Orders List', caption: 'ประวัติคำสั่งซื้อ', bullets: ['รายการ TXN ทั้งหมด', 'Filter by status', 'รอชำระ / กำลังจัดส่ง / สำเร็จ', 'Click → order detail'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-order-detail-viewport.png', title: 'Order Detail', caption: 'ติดตามสถานะออเดอร์', bullets: ['TXN + วันสั่ง', 'Status timeline', 'เลขพัสดุ tracking', 'รายการสินค้า x จำนวน'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-ai-chat-viewport.png', title: 'AI Chat — เภสัชกร 24/7', caption: 'ปรึกษาด้วย Gemini', bullets: ['AI greeting Thai-aware', 'Intent chips (ไข้หวัด/ปวดหัว/ท้องเสีย/แพ้อากาศ)', 'AI ตอบ + เสนอสินค้า', 'ปุ่ม "ปรึกษาเภสัชกร" → human'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-health-profile-viewport.png', title: 'Health Profile', caption: 'บันทึกข้อมูลสุขภาพ', bullets: ['อายุ/เพศ/น้ำหนัก/ส่วนสูง', 'โรคประจำตัว 10 ตัวเลือก', 'การแพ้ยา + ความรุนแรง', 'ยาที่ใช้ประจำ'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-appointments-viewport.png', title: 'Appointments', caption: 'จองคิวปรึกษา', bullets: ['ปฏิทินเลือกวัน/เวลา', 'จอง video consult', 'นัดของฉัน vs นัดใหม่', 'ปุ่มยกเลิก/เลื่อน'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-rewards-viewport.png', title: 'Rewards', caption: 'แลกของรางวัลด้วยแต้ม', bullets: ['รายการรางวัล', 'จำนวนแต้มต่อรางวัล', 'คูปองส่วนลด', 'Redeem flow'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-rewards-history-viewport.png', title: 'Rewards History', caption: 'ประวัติได้/ใช้แต้ม', bullets: ['Timeline แต้มเข้า/ออก', 'อ้างอิง TXN', 'รายการของรางวัลที่แลกแล้ว'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-wishlist-viewport.png', title: 'Wishlist', caption: 'สินค้าที่กดหัวใจ', bullets: ['การ์ดสินค้าที่บันทึก', 'ปุ่มใส่ตะกร้า', 'ปุ่มลบออก', 'Sync กับ LINE user'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-profile-viewport.png', title: 'Profile Hub', caption: 'ศูนย์รวมการตั้งค่า', bullets: ['Member card + tier + แต้ม', 'Progress bar ไป tier ถัดไป', '11 quick actions', 'Health + Pharmacy + Shopping'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-notifications-viewport.png', title: 'Medication Reminders', caption: 'ตั้งการแจ้งเตือนทานยา', bullets: ['สร้างการเตือนใหม่', 'ระบุชื่อยา + เวลา', 'แจ้งผ่าน LINE push', 'Snooze + done'] },
  { type: 'miniapp', image: '01-miniapp/miniapp-video-viewport.png', title: 'Video Consult', caption: 'WebRTC call กับเภสัชกร', bullets: ['เลือกเภสัชกร', 'Video call + chat', 'Share file/รูปยา', 'บันทึก session'] },

  // === SECTION 2 — Inbox / Vibe Selling ===
  { type: 'section', title: 'หมวด 2', subtitle: 'เภสัชกรทำงานอย่างไร — Vibe Selling OS', sectionName: '7 features — 3-pane inbox, dispense, AI reply, auto-tag, templates' },
  { type: 'image', image: '02-inbox/inbox-v2-3pane-fullpage.png', title: 'Inbox v2 — 3-Pane (หัวใจของระบบ)', caption: 'ซ้าย: conversation list + filter / กลาง: chat thread + Flex medicine label / ขวา: CRM HUD (โปรไฟล์ + tags + แต้ม + ออเดอร์ + health profile)' },
  { type: 'image', image: '02-inbox/auto-reply-fullpage.png', title: 'Auto-Reply Rules', caption: 'Keyword (exact/contains/regex) → reply text/Flex + priority + log' },
  { type: 'image', image: '02-inbox/triage-analytics-fullpage.png', title: 'Triage Analytics', caption: 'Session ทั้งหมด / เสร็จสมบูรณ์ / ส่งต่อเภสัชกร / เร่งด่วน + อัตราสำเร็จ + นาที/เคส' },
  { type: 'image', image: '02-inbox/templates-fullpage.png', title: 'Template Library', caption: 'เทมเพลตข้อความที่ส่งบ่อย — รวมไว้ในที่เดียว' },

  // === SECTION 3 — Dashboard ===
  { type: 'section', title: 'หมวด 3', subtitle: 'ผู้บริหารเห็นอะไร', sectionName: 'Executive + CRM dashboards' },
  { type: 'image', image: '03-dashboard/executive-dashboard-fullpage.png', title: 'Executive Dashboard', caption: '6 KPI: ข้อความ/ลูกค้าใหม่/ออเดอร์/รายได้/video/SLA + กราฟยอด + ผลงาน admin + conversation feed' },
  { type: 'image', image: '03-dashboard/crm-dashboard-fullpage.png', title: 'CRM Dashboard', caption: 'Lifecycle funnel + top tags + auto-rules + recent customers + quick actions' },
  { type: 'image', image: '03-dashboard/customer-segments-fullpage.png', title: 'Customer Segments', caption: 'Personalized marketing — สร้าง segment ตามเงื่อนไข + push targeted broadcast' },
  { type: 'image', image: '03-dashboard/analytics-overview-fullpage.png', title: 'Analytics Overview', caption: 'Followers + active users + messages + broadcast + กราฟรายได้ + top tags + export' },
  { type: 'image', image: '03-dashboard/dashboard-default-fullpage.png', title: 'Dashboard (default landing)', caption: 'หน้าแรกของระบบ — เลือก tab Executive หรือ CRM' },

  // === SECTION 4 — AI ===
  { type: 'section', title: 'หมวด 4', subtitle: 'AI Suite — Medical Copilot', sectionName: 'Powered by Gemini 2.0 + Imagen 4 + OpenAI' },
  { type: 'image', image: '04-ai/ai-studio-fullpage.png', title: 'AI Studio', caption: 'Quick presets: chatbot/image/Flex/caption/translate + chat with Gemini 2.0 Flash' },
  { type: 'image', image: '04-ai/ai-settings-fullpage.png', title: 'AI Settings (per LINE OA)', caption: 'Gemini API key + 3 modes (พนักงานขาย/ซัพพอร์ต/เภสัชกร) + system prompt + auto-load สินค้า 50 รายการ' },
  { type: 'image', image: '04-ai/ai-chatbot-fullpage.png', title: 'AI Chatbot Fallback', caption: 'GPT-3.5/4/4-Turbo + custom prompt + temperature + max tokens. Workflow: Auto-Reply ก่อน → AI' },

  // === SECTION 5 — Marketing ===
  { type: 'section', title: 'หมวด 5', subtitle: 'Marketing & Engagement', sectionName: '6 tools — Broadcast, Drip, Flex, Rich Menu, Membership, LINE Accounts' },
  { type: 'image', image: '05-marketing/broadcast-send-fullpage.png', title: 'Broadcast — Send', caption: 'Audience: ทุกคน/segment/tag/group + Type: text/image/Flex carousel + Schedule + Stats' },
  { type: 'image', image: '05-marketing/drip-campaigns-fullpage.png', title: 'Drip Campaigns', caption: 'Welcome series / Re-engagement / Post-purchase — sequences อัตโนมัติตามเวลา' },
  { type: 'image', image: '05-marketing/flex-builder-fullpage.png', title: 'Flex Builder', caption: 'Drag-drop visual editor — Bubble, Carousel, Text, Button, Image, Box + LINE Preview + JSON' },
  { type: 'image', image: '05-marketing/rich-menu-fullpage.png', title: 'Rich Menu', caption: 'Static + Dynamic + Switch — สลับเมนูตาม segment/tag, A/B test' },
  { type: 'image', image: '05-marketing/membership-fullpage.png', title: 'Membership', caption: '4 tiers: Bronze → Silver → Gold → Platinum + กฎคะแนน + รายการของรางวัล' },
  { type: 'image', image: '05-marketing/line-accounts-fullpage.png', title: 'LINE Accounts (Multi-Tenant)', caption: 'จัดการหลาย LINE OA ในระบบเดียว — Channel ID + LIFF + Webhook URL ต่อบัญชี' },

  // === SECTION 6 — Sales / Ops ===
  { type: 'section', title: 'หมวด 6', subtitle: 'Sales & Ops', sectionName: 'POS + Members + Inventory + Orders + Accounting' },
  { type: 'image', image: '06-sales/pos-open-shift-fullpage.png', title: 'POS — เปิดกะ', caption: 'ขายหน้าร้าน — เปิดกะด้วยเงินสด + ค้นสินค้า + ตะกร้า + รับชำระ + พิมพ์ใบเสร็จ + reports' },
  { type: 'image', image: '06-sales/users-list-fullpage.png', title: 'Customer Database', caption: 'รายชื่อลูกค้า + LINE IDs + tags + status + จำนวนข้อความ + quick actions' },
  { type: 'image', image: '06-sales/user-detail-fullpage.png', title: 'Customer 360', caption: 'Member card + tier + แต้ม + tags + ข้อมูลส่วนตัว + ข้อมูลสุขภาพ + ประวัติออเดอร์ + LINE info' },
  { type: 'image', image: '06-sales/inventory-list-fullpage.png', title: 'Inventory — 31 SKU', caption: '11 tabs (สต็อก/เคลื่อนไหว/batch/lot/planogram/low stock/WMS) + bulk actions' },
  { type: 'image', image: '06-sales/shop-orders-fullpage.png', title: 'Orders — ทุกประเภท', caption: 'Type: ซื้อสินค้า/จองคิว/สมัครสมาชิก/แลกของรางวัล/จ่ายยา + Status filter + เลขพัสดุ' },
  { type: 'image', image: '06-sales/accounting-dashboard-fullpage.png', title: 'Accounting', caption: 'AP/AR/สถานะสุทธิ + P&L (Revenue, COGS, Gross, OPEX, Net) + 6-month trend + quick links' },

  // === SECTION 7 — Telepharmacy ===
  { type: 'section', title: 'หมวด 7', subtitle: 'Telepharmacy', sectionName: 'Pharmacist queue + video call + dispense — remote 100%' },
  { type: 'image', image: '08-telepharmacy/pharmacy-management-fullpage.png', title: 'Pharmacy Management', caption: '4 tabs (Dashboard/เภสัชกร/ยาตีกัน/จ่ายยา) + KPI + รายการรอตรวจสอบ + sessions ล่าสุด + quick actions' },

  // === SECTION 8 — System / Trust ===
  { type: 'section', title: 'หมวด 8', subtitle: 'System Health & Trust', sectionName: 'Enterprise observability + PDPA compliance' },
  { type: 'image', image: '09-system/system-status-fullpage.png', title: 'System Status — All-Green ⭐', caption: '16+ services monitored (DB/Vibe Selling/Inbox/AI engines/all tables/LINE API) + ทุกบริการ green' },
  { type: 'image', image: '09-system/consent-management-fullpage.png', title: 'Consent Management — PDPA', caption: 'Privacy Policy + ToS tracking + counters + Consent Logs + Data Access Logs — audit-ready' },
  { type: 'image', image: '09-system/admin-users-roles-fullpage.png', title: 'Admin Users + Roles (RBAC)', caption: '6 roles (เจ้าของ/Admin/เภสัชกร/พนักงาน/Marketing/IT) + permissions per Bot' },
  { type: 'image', image: '09-system/activity-logs-fullpage.png', title: 'Activity Logs — Full Audit Trail', caption: 'ทุก action ของ admin (login/dispense/edit/send/delete) + IP tracking + filter + export' },

  // === CLOSING ===
  { type: 'value', title: 'Why REYA', subtitle: 'สรุป', items: [
    'ระบบเดียวจบ — ไม่ต้องใช้ 5-10 tool แยก',
    'Customer experience ผ่าน LINE ที่คนไทยใช้อยู่แล้ว',
    'AI ช่วยลด workload เภสัชกร 50%+',
    'Multi-tenant พร้อม scale หลายร้าน',
    'PDPA compliant + audit ready',
  ]},
  { type: 'cover', title: 'Next Step', subtitle: 'Demo + Pricing', tagline: 'ขอ demo 30 นาที · onboarding checklist · pricing per LINE OA / per pharmacist', date: 'cnyhealthymall@gmail.com' },
];

// HTML template
const css = `
  @page { size: 1920px 1080px; margin: 0; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Sarabun', 'Noto Sans Thai', -apple-system, sans-serif; background: #0f172a; }
  .slide {
    width: 1920px; height: 1080px;
    page-break-after: always;
    display: flex; flex-direction: column;
    position: relative;
    overflow: hidden;
    background: #ffffff;
  }
  .slide:last-child { page-break-after: auto; }

  /* Cover slide */
  .slide.cover {
    background: linear-gradient(135deg, #064e3b 0%, #047857 50%, #10b981 100%);
    color: white;
    align-items: center; justify-content: center;
    text-align: center;
  }
  .slide.cover h1 { font-size: 280px; font-weight: 900; letter-spacing: -8px; line-height: 1; }
  .slide.cover h2 { font-size: 80px; font-weight: 600; margin-top: 30px; opacity: 0.95; }
  .slide.cover p { font-size: 42px; font-weight: 300; margin-top: 60px; opacity: 0.85; max-width: 1400px; line-height: 1.4; }
  .slide.cover .date { position: absolute; bottom: 80px; font-size: 32px; opacity: 0.7; letter-spacing: 4px; }

  /* Section divider */
  .slide.section {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    color: white;
    align-items: flex-start; justify-content: center;
    padding: 0 200px;
  }
  .slide.section .section-num { font-size: 200px; font-weight: 900; color: #10b981; line-height: 1; }
  .slide.section h2 { font-size: 100px; font-weight: 700; margin-top: 30px; line-height: 1.1; }
  .slide.section p { font-size: 44px; font-weight: 300; margin-top: 40px; opacity: 0.7; max-width: 1500px; }

  /* Pain / Value slide */
  .slide.bullets {
    background: white;
    padding: 120px 200px;
    color: #0f172a;
  }
  .slide.bullets h2 { font-size: 96px; font-weight: 800; line-height: 1.1; }
  .slide.bullets .sub { font-size: 48px; color: #64748b; margin-top: 24px; font-weight: 400; }
  .slide.bullets ul { list-style: none; margin-top: 80px; }
  .slide.bullets li {
    font-size: 56px; font-weight: 500; line-height: 1.4;
    padding: 30px 0 30px 80px; position: relative;
  }
  .slide.bullets li::before {
    content: '✓'; position: absolute; left: 0; top: 30px;
    color: #10b981; font-weight: 900; font-size: 56px;
  }

  /* Image slide (admin desktop screenshots) */
  .slide.image {
    background: white;
    padding: 60px 80px 40px 80px;
    color: #0f172a;
  }
  .slide.image .header { display: flex; align-items: baseline; gap: 24px; margin-bottom: 24px; }
  .slide.image .header h2 { font-size: 56px; font-weight: 800; flex: 1; line-height: 1.1; }
  .slide.image .header .badge { font-size: 20px; color: #94a3b8; font-weight: 500; letter-spacing: 1px; }
  .slide.image .img-wrap {
    flex: 1; min-height: 0;
    background: #f1f5f9;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
  }
  .slide.image img {
    max-width: 100%; max-height: 100%;
    object-fit: contain;
  }
  .slide.image .caption {
    margin-top: 24px;
    font-size: 28px; font-weight: 400; color: #475569;
    line-height: 1.4;
    background: #f8fafc;
    padding: 20px 32px;
    border-left: 6px solid #10b981;
    border-radius: 8px;
  }

  /* Mini App slide — phone frame layout */
  .slide.miniapp {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    flex-direction: row;
    padding: 60px 100px;
    gap: 100px;
    color: #0f172a;
    align-items: center;
  }
  .slide.miniapp .phone-col {
    flex: 0 0 540px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .phone-frame {
    position: relative;
    width: 480px;
    height: 960px; /* 9:18 ratio matches mini app screens */
    background: #0a0a0a;
    border-radius: 60px;
    padding: 18px;
    box-shadow:
      0 40px 80px rgba(6, 78, 59, 0.35),
      inset 0 0 0 3px #1f2937,
      inset 0 0 0 6px #0a0a0a;
  }
  .phone-frame::before {
    content: '';
    position: absolute;
    top: 26px; left: 50%;
    transform: translateX(-50%);
    width: 130px; height: 32px;
    background: #000;
    border-radius: 20px;
    z-index: 3;
  }
  .phone-frame::after {
    content: '';
    position: absolute;
    bottom: 12px; left: 50%;
    transform: translateX(-50%);
    width: 130px; height: 5px;
    background: #2a2a2a;
    border-radius: 3px;
    z-index: 3;
  }
  .phone-frame .screen {
    width: 100%; height: 100%;
    background: white;
    border-radius: 44px;
    overflow: hidden;
    position: relative;
  }
  .phone-frame .screen img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top;
    display: block;
  }
  .slide.miniapp .text-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    max-width: 1100px;
  }
  .slide.miniapp .badge-route {
    display: inline-block;
    font-size: 18px; color: #047857;
    background: #d1fae5;
    padding: 8px 20px;
    border-radius: 20px;
    font-weight: 600;
    letter-spacing: 1px;
    margin-bottom: 32px;
    align-self: flex-start;
  }
  .slide.miniapp h2 {
    font-size: 88px;
    font-weight: 800;
    line-height: 1.05;
    color: #064e3b;
  }
  .slide.miniapp .tagline {
    font-size: 40px;
    color: #047857;
    margin-top: 20px;
    font-weight: 400;
  }
  .slide.miniapp ul {
    list-style: none;
    margin-top: 60px;
  }
  .slide.miniapp ul li {
    font-size: 32px;
    font-weight: 500;
    color: #1e293b;
    line-height: 1.4;
    padding: 18px 0 18px 60px;
    position: relative;
  }
  .slide.miniapp ul li::before {
    content: '';
    position: absolute;
    left: 0; top: 32px;
    width: 32px; height: 4px;
    background: #10b981;
    border-radius: 2px;
  }

  /* Footer */
  .footer-brand {
    position: absolute; bottom: 30px; right: 80px;
    font-size: 18px; color: #cbd5e1; letter-spacing: 2px; font-weight: 600;
  }
  .page-num {
    position: absolute; bottom: 30px; left: 80px;
    font-size: 18px; color: #cbd5e1; letter-spacing: 1px; font-weight: 600;
  }
  .slide.miniapp .footer-brand,
  .slide.miniapp .page-num { color: #6ee7b7; }
`;

let html = `<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8">
<title>REYA — Sales Pitch Deck</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>${css}</style>
</head><body>`;

const totalSlides = slides.length;
slides.forEach((s, i) => {
  const pageNum = i + 1;
  if (s.type === 'cover') {
    html += `<div class="slide cover">
      <h1>${s.title}</h1>
      <h2>${s.subtitle}</h2>
      ${s.tagline ? `<p>${s.tagline}</p>` : ''}
      ${s.date ? `<div class="date">${s.date}</div>` : ''}
    </div>`;
  } else if (s.type === 'pain' || s.type === 'value') {
    html += `<div class="slide bullets">
      <h2>${s.title}</h2>
      ${s.subtitle ? `<div class="sub">${s.subtitle}</div>` : ''}
      <ul>${s.items.map(it => `<li>${it}</li>`).join('')}</ul>
    </div>`;
  } else if (s.type === 'section') {
    html += `<div class="slide section">
      <div class="section-num">${s.title.replace('หมวด ', '')}</div>
      <h2>${s.subtitle}</h2>
      <p>${s.sectionName}</p>
    </div>`;
  } else if (s.type === 'image') {
    html += `<div class="slide image">
      <div class="header">
        <h2>${s.title}</h2>
        <div class="badge">${s.image}</div>
      </div>
      <div class="img-wrap"><img src="${fileUrl(s.image)}" alt="${s.title}"/></div>
      <div class="caption">${s.caption}</div>
      <div class="page-num">${pageNum} / ${totalSlides}</div>
      <div class="footer-brand">REYA · TELEPHARMACY PLATFORM</div>
    </div>`;
  } else if (s.type === 'miniapp') {
    html += `<div class="slide miniapp">
      <div class="phone-col">
        <div class="phone-frame">
          <div class="screen"><img src="${fileUrl(s.image)}" alt="${s.title}"/></div>
        </div>
      </div>
      <div class="text-col">
        <span class="badge-route">LINE MINI APP · ${s.image.replace('01-miniapp/', '').replace('.png', '')}</span>
        <h2>${s.title}</h2>
        <div class="tagline">${s.caption}</div>
        ${s.bullets ? `<ul>${s.bullets.map(b => `<li>${b}</li>`).join('')}</ul>` : ''}
      </div>
      <div class="page-num">${pageNum} / ${totalSlides}</div>
      <div class="footer-brand">REYA · TELEPHARMACY PLATFORM</div>
    </div>`;
  }
});

html += `</body></html>`;

fs.writeFileSync(OUT_HTML, html, 'utf8');
console.log(`Written ${totalSlides} slides → ${OUT_HTML}`);
