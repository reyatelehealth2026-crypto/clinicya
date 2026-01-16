# Landing SEO Settings - คู่มือการใช้งาน

## ภาพรวม

ระบบตั้งค่า SEO สำหรับ Landing Page ที่ช่วยให้คุณสามารถปรับแต่ง:
- **Title** - ชื่อหน้าเว็บที่แสดงบนแท็บเบราว์เซอร์
- **ชื่อแอพ (App Name)** - ชื่อที่แสดงเมื่อบันทึกเป็น PWA
- **Favicon** - ไอคอนที่แสดงบนแท็บเบราว์เซอร์
- **Meta Tags** - Keywords, Description สำหรับ SEO
- **Structured Data** - ข้อมูลสำหรับ Google Search

## การเข้าถึง

1. เข้าสู่ระบบ Admin Panel
2. ไปที่เมนู **Landing** (หรือ Landing Page Settings)
3. เลือกแท็บ **SEO**

## ฟีเจอร์ที่เพิ่มใหม่

### 1. ตั้งค่าแบรนด์และไอคอน

#### Title (ชื่อหน้าเว็บ)
- แสดงบนแท็บเบราว์เซอร์
- แสดงในผลการค้นหา Google
- ตัวอย่าง: "ร้านยาออนไลน์ - ส่งยาถึงบ้าน"

#### ชื่อแอพ (App Name)
- แสดงเมื่อบันทึกเป็น PWA บนหน้าจอโทรศัพท์
- แสดงใน manifest.json
- ตัวอย่าง: "ร้านยาออนไลน์"

#### Favicon URL
- ไอคอนที่แสดงบนแท็บเบราว์เซอร์
- รองรับทั้ง URL เต็ม และ relative path
- แนะนำขนาด: 32x32 หรือ 64x64 px
- รูปแบบ: .ico, .png, .jpg
- ตัวอย่าง:
  - `https://example.com/favicon.ico`
  - `/assets/images/favicon.png`

### 2. Meta Tags (เดิม)

#### Meta Description
- คำอธิบายที่แสดงใน Google
- แนะนำ 150-160 ตัวอักษร
- มีตัวนับอักษรแบบ real-time

#### Meta Keywords
- คำค้นหาที่เกี่ยวข้อง
- คั่นด้วยเครื่องหมาย `,`
- ตัวอย่าง: "ร้านยาออนไลน์, เภสัชกร, ส่งยาถึงบ้าน"

### 3. ตำแหน่งที่ตั้ง (เดิม)

- Latitude / Longitude
- Google Map Embed URL
- ใช้สำหรับ Structured Data

### 4. เวลาทำการ (เดิม)

- ตั้งค่าเวลาทำการแต่ละวัน
- รูปแบบ: `09:00-21:00` หรือ `closed`
- ใช้สำหรับ Structured Data

## การใช้งานในระบบ

### 1. Landing Page (`index.php`)
```php
// Title จะถูกดึงจาก LandingSEOService
<title><?= htmlspecialchars($seoService->getPageTitle()) ?></title>

// Favicon จะถูกแสดงอัตโนมัติผ่าน seo-meta.php
<?php include 'includes/landing/seo-meta.php'; ?>
```

### 2. LIFF App (`liff/index.php`)
```php
// ใช้ชื่อแอพจาก LandingSEOService
<title><?= htmlspecialchars($currentPage['title']) ?> - <?= htmlspecialchars($appName) ?></title>

// Favicon
<?php if (!empty($faviconUrl)): ?>
<link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
<?php endif; ?>
```

### 3. PWA Manifest (`api/manifest.php`)
```json
{
  "name": "ชื่อหน้าเว็บ (Page Title)",
  "short_name": "ชื่อแอพ (App Name)",
  "icons": [
    {
      "src": "Favicon URL",
      "sizes": "192x192"
    }
  ]
}
```

## ไฟล์ที่เกี่ยวข้อง

### Backend
- `admin/landing-settings.php` - หน้าตั้งค่า Landing
- `includes/landing/admin-seo.php` - ฟอร์มตั้งค่า SEO
- `classes/LandingSEOService.php` - Service class สำหรับ SEO
- `api/manifest.php` - Dynamic PWA manifest generator

### Frontend
- `includes/landing/seo-meta.php` - Component สำหรับ meta tags
- `index.php` - Landing page
- `liff/index.php` - LIFF app

### Database
- `landing_settings` table
  - `page_title` - ชื่อหน้าเว็บ
  - `app_name` - ชื่อแอพ
  - `favicon_url` - URL ของ favicon
  - `meta_keywords` - Keywords
  - `meta_description` - Description
  - `latitude` / `longitude` - พิกัด
  - `google_map_embed` - Google Map URL
  - `operating_hours` - เวลาทำการ (JSON)

## เมธอดใหม่ใน LandingSEOService

```php
// ดึงชื่อหน้าเว็บ
$pageTitle = $seoService->getPageTitle();

// ดึงชื่อแอพ
$appName = $seoService->getAppName();

// ดึง URL ของ favicon
$faviconUrl = $seoService->getFaviconUrl();

// Render favicon tags
echo $seoService->renderFaviconTags();
```

## ตัวอย่างการใช้งาน

### ตั้งค่าพื้นฐาน

1. **Title**: "ร้านยาเรยา - ส่งยาถึงบ้าน 24 ชม."
2. **App Name**: "ร้านยาเรยา"
3. **Favicon**: `/assets/images/logo-icon.png`
4. **Description**: "ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร ส่งยาถึงบ้าน 24 ชม. ทั่วประเทศ"
5. **Keywords**: "ร้านยาออนไลน์, เภสัชกร, ส่งยาถึงบ้าน, ปรึกษาเภสัชกร, ยา, สุขภาพ"

### ผลลัพธ์

- **แท็บเบราว์เซอร์**: แสดงไอคอนและชื่อ "ร้านยาเรยา - ส่งยาถึงบ้าน 24 ชม."
- **Google Search**: แสดง title และ description ที่ตั้งค่าไว้
- **PWA**: เมื่อบันทึกบนหน้าจอโทรศัพท์ จะแสดงชื่อ "ร้านยาเรยา" พร้อมไอคอน
- **Social Share**: แสดง Open Graph tags ที่ถูกต้อง

## Tips & Best Practices

### Title
- ควรมีความยาว 50-60 ตัวอักษร
- ใส่คำสำคัญที่ต้องการให้ติดอันดับ
- ทำให้น่าสนใจและชัดเจน

### App Name
- ควรสั้นและจำง่าย (10-15 ตัวอักษร)
- ใช้ชื่อแบรนด์หลัก

### Favicon
- ใช้รูปที่มีความคมชัด
- ควรเป็นโลโก้หรือสัญลักษณ์ของแบรนด์
- ทดสอบให้แน่ใจว่าเห็นชัดบนพื้นหลังทั้งสว่างและมืด

### Meta Description
- เขียนให้น่าสนใจและชัดเจน
- ใส่ Call-to-Action
- ใช้คำสำคัญที่เกี่ยวข้อง

## การทดสอบ

### ทดสอบ Title และ Favicon
1. เปิด Landing Page (`index.php`)
2. ดูที่แท็บเบราว์เซอร์ว่าแสดงไอคอนและชื่อถูกต้อง
3. เปิด LIFF App ดูว่าแสดงชื่อถูกต้อง

### ทดสอบ PWA
1. เปิด Landing Page บนมือถือ
2. เลือก "Add to Home Screen"
3. ตรวจสอบว่าชื่อและไอคอนถูกต้อง

### ทดสอบ SEO
1. ใช้ Google Search Console
2. ตรวจสอบ Rich Results Test
3. ดู Preview ใน Google Search

## Troubleshooting

### Favicon ไม่แสดง
- ตรวจสอบ URL ว่าถูกต้อง
- ลองเคลียร์ cache เบราว์เซอร์
- ตรวจสอบว่าไฟล์มีอยู่จริง

### Title ไม่เปลี่ยน
- ตรวจสอบว่าบันทึกการตั้งค่าแล้ว
- เคลียร์ cache
- ตรวจสอบ database ว่ามีค่าใน `landing_settings`

### PWA ไม่อัปเดต
- Uninstall PWA เดิม
- เคลียร์ cache
- ติดตั้งใหม่

## สรุป

ระบบตั้งค่า SEO ใหม่นี้ช่วยให้คุณสามารถปรับแต่ง:
- ✅ Title บนแท็บเบราว์เซอร์
- ✅ ชื่อแอพสำหรับ PWA
- ✅ Favicon/ไอคอน
- ✅ Meta tags สำหรับ SEO
- ✅ Structured data สำหรับ Google

ทั้งหมดนี้ทำได้ง่ายๆ ผ่านเมนู Landing > SEO โดยไม่ต้องแก้ไขโค้ด!
