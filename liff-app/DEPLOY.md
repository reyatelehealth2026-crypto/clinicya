# LIFF App Deploy & Integration Guide

## สถาปัตยกรรม

```
┌─────────────────────────────────────────────────┐
│  LINE App (LIFF Browser)                        │
│  Endpoint URL: https://cny.re-ya.com/app        │
└──────────────────────┬──────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────┐
│  nginx (cny.re-ya.com)                          │
│                                                 │
│  /app/*     → liff-app/dist/ (Static SPA)       │
│  /api/*     → PHP-FPM (เดิมทุกอย่าง)            │
│  /liff/*    → PHP legacy (redirect ไปหน้าเก่า)   │
└─────────────────────────────────────────────────┘
```

---

## ขั้นตอน Deploy

### 1. Build บนเครื่อง Dev

```bash
cd C:\Users\Administrator\odoo\liff-app

# สร้าง .env (ต้องทำครั้งแรก)
copy .env.example .env
# แก้ VITE_LIFF_ID ให้ตรงกับ LIFF ID ใน line_accounts table

# Build
npm run build
```

ผลลัพธ์อยู่ที่ `dist/` (~300KB gzipped)

### 2. Upload ไปเซิร์ฟเวอร์

```bash
# จาก Windows → เซิร์ฟเวอร์ (aaPanel)
scp -r dist/* root@YOUR_SERVER:/www/wwwroot/cny.re-ya.com/app/
```

หรือใช้ rsync:
```bash
rsync -avz --delete dist/ root@YOUR_SERVER:/www/wwwroot/cny.re-ya.com/app/
```

### 3. เพิ่ม nginx config

เปิด aaPanel → Website → cny.re-ya.com → Conf

**เพิ่มก่อน** `location /` block ที่มีอยู่:

```nginx
# ── LIFF App v2 (Vite SPA) ───────────────────────────────────────
location /app {
    alias /www/wwwroot/cny.re-ya.com/app;
    try_files $uri $uri/ /app/index.html;

    # Cache-bust: hash ใน filename อยู่แล้ว
    location ~* \.(js|css|svg|png|jpg|webp|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # index.html ห้าม cache (เพราะ reference ไป hashed chunks)
    location = /app/index.html {
        expires -1;
        add_header Cache-Control "no-cache, no-store, must-revalidate";
    }
}
```

Save → nginx จะ reload อัตโนมัติ

### 4. ตั้ง .env บน Server

สร้างไฟล์ `.env` ไม่จำเป็น — env vars ถูก bake ตอน build เข้าไปใน JS แล้ว
เพียงแค่ตั้งค่าใน `.env` ก่อน build:

```env
VITE_LIFF_ID=2006633xxx-xxxxxxxx
VITE_API_BASE_URL=https://cny.re-ya.com
VITE_DEFAULT_ACCOUNT_ID=1
```

### 5. ดึง LIFF ID จาก DB

```sql
SELECT id, name, liff_id FROM line_accounts WHERE liff_id IS NOT NULL;
```

ใส่ LIFF ID ที่ได้ลงใน `VITE_LIFF_ID`

---

## เชื่อมต่อระบบเดิม

### A. เปลี่ยน LIFF Endpoint URL (LINE Developers Console)

1. เข้า https://developers.line.biz/console/
2. เลือก Provider → Channel → LIFF
3. แก้ **Endpoint URL** จาก:
   ```
   https://cny.re-ya.com/liff/index.php
   ```
   เป็น:
   ```
   https://cny.re-ya.com/app
   ```
4. Save

> ⚠️ **ทำทีละ LIFF ID** — ถ้ามีหลาย line_accounts ค่อยย้ายทีละตัว

### B. Rich Menu ลิงก์

Rich Menu ที่ชี้ไปหน้าเก่า เช่น:
- `https://liff.line.me/2006633xxx-xxxxxxxx` → ยังทำงานได้ เพราะ LIFF ID เดิม แค่ endpoint เปลี่ยน
- ไม่ต้องแก้ Rich Menu

### C. PHP API — ไม่ต้องแก้

LIFF app ใหม่เรียก PHP API เดิมทุกอย่าง:
| LIFF App Hook | PHP API |
|---------------|---------|
| `useMember` | `/api/member.php?action=check` / `get_card` |
| `useProducts` | `/api/checkout.php?action=products` |
| `useOrders` | `/api/checkout.php?action=get_order` |
| `useCheckout` | `/api/checkout.php?action=create_order` |
| `usePharmacists` | `/api/appointments.php?action=pharmacists` |
| `useAppointments` | `/api/appointments.php?action=my_appointments` |
| `useHealthProfile` | `/api/health-profile.php?action=get` |
| `useRewards` | `/api/admin/rewards.php?action=list` |
| `usePointsHistory` | `/api/member.php?action=points_history` |
| AI Chat (SSE) | `/api/ai-chat.php` |

### D. Legacy URL Redirect (Optional)

ถ้ามีคนบุ๊คมาร์คหน้าเก่า เพิ่ม redirect ใน nginx:

```nginx
# Redirect old LIFF pages to new app
location = /liff/index.php {
    # Map old ?page= to new SPA routes
    if ($arg_page = "home")         { return 301 /app; }
    if ($arg_page = "shop")         { return 301 /app/shop; }
    if ($arg_page = "orders")       { return 301 /app/orders; }
    if ($arg_page = "member")       { return 301 /app/member; }
    if ($arg_page = "register")     { return 301 /app/register; }
    if ($arg_page = "points")       { return 301 /app/points; }
    if ($arg_page = "redeem")       { return 301 /app/redeem; }
    if ($arg_page = "consult")      { return 301 /app/video-call; }
    if ($arg_page = "appointments") { return 301 /app/appointments; }
    if ($arg_page = "wishlist")     { return 301 /app/wishlist; }
    if ($arg_page = "settings")     { return 301 /app/profile; }

    # Default: go to home
    return 301 /app;
}
```

---

## Rollback Plan

ถ้ามีปัญหา เปลี่ยน LIFF Endpoint URL กลับเป็น:
```
https://cny.re-ya.com/liff/index.php
```

ระบบเก่ายังอยู่ครบ — ไม่ถูกลบ

---

## Checklist

- [ ] `npm run build` สำเร็จ
- [ ] Upload `dist/` ไป `/www/wwwroot/cny.re-ya.com/app/`
- [ ] เพิ่ม nginx `location /app` config
- [ ] ทดสอบ `https://cny.re-ya.com/app` ในเบราว์เซอร์
- [ ] เปลี่ยน LIFF Endpoint URL ใน LINE Developers Console
- [ ] ทดสอบเปิดผ่าน LINE app (LIFF browser)
- [ ] ตรวจ member auto-register ทำงาน
- [ ] ตรวจ shop/orders/checkout ทำงาน
- [ ] ตรวจ AI Chat streaming ทำงาน
- [ ] (Optional) เพิ่ม legacy redirect
