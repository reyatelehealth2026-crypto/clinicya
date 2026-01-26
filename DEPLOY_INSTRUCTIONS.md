# 🚀 Deploy Instructions - Branch testry

## ปัญหาที่พบ
```
error: Your local changes to the following files would be overwritten by checkout:
    webhook.php
Please commit your changes or stash them before you switch branches.
```

## วิธีแก้ไข (เลือก 1 วิธี)

---

### ✅ วิธีที่ 1: Force Deploy (แนะนำ - ง่ายที่สุด)

**ใช้เมื่อ:** ไม่ต้องการเก็บการแก้ไขบน server

```bash
# SSH เข้า server
ssh user@your-server.com

# ไปที่ directory
cd /home/zrismpsz/public_html/cny.re-ya.com

# รันสคริปต์
bash force_deploy_testry.sh
```

สคริปต์จะถาม confirmation ก่อนลบการแก้ไข

---

### ✅ วิธีที่ 2: Stash แล้ว Deploy (เก็บการแก้ไข)

**ใช้เมื่อ:** ต้องการเก็บการแก้ไขไว้ใช้ภายหลัง

```bash
# SSH เข้า server
ssh user@your-server.com

# ไปที่ directory
cd /home/zrismpsz/public_html/cny.re-ya.com

# รันสคริปต์
bash deploy_testry_branch.sh
```

การแก้ไขจะถูกเก็บไว้ใน stash สามารถกู้คืนได้ด้วย:
```bash
git stash pop
```

---

### ✅ วิธีที่ 3: Manual Commands (ควบคุมเอง)

**ใช้เมื่อ:** ต้องการควบคุมทุกขั้นตอนเอง

#### 3.1 แบบ Force (ลบการแก้ไข)
```bash
cd /home/zrismpsz/public_html/cny.re-ya.com

# ลบการแก้ไข
git reset --hard HEAD

# Checkout testry
git checkout testry

# Pull ล่าสุด
git pull origin testry

# ตรวจสอบ
git status
```

#### 3.2 แบบ Stash (เก็บการแก้ไข)
```bash
cd /home/zrismpsz/public_html/cny.re-ya.com

# เก็บการแก้ไข
git stash save "Backup before testry deployment"

# Checkout testry
git checkout testry

# Pull ล่าสุด
git pull origin testry

# ตรวจสอบ
git status

# (Optional) กู้คืนการแก้ไข
git stash pop
```

---

## 📋 หลัง Deploy เสร็จ

### 1. ตรวจสอบว่า Deploy สำเร็จ
```bash
# ตรวจสอบ branch ปัจจุบัน
git branch

# ดู commit ล่าสุด
git log --oneline -5

# ตรวจสอบไฟล์ที่แก้ไข
ls -la install/check_profile_issues.php
ls -la install/fix_missing_profile_pictures.php
```

### 2. รันสคริปต์ตรวจสอบปัญหา
```bash
php install/check_profile_issues.php
```

**ผลลัพธ์ที่คาดหวัง:**
```
=== Profile Issues Report ===

1. Users without profile pictures:
  Account ID 3: 45 users (12 in last 7 days)
```

### 3. แก้ไขข้อมูลลูกค้า
```bash
php install/fix_missing_profile_pictures.php
```

**ผลลัพธ์ที่คาดหวัง:**
```
Processing Account: Re-ya Pharmacy (ID: 3)
Found 45 users without profile pictures

User: Wichakan (U1234...)
  ✅ Fixed - Picture URL: https://profile.line-scdn.net/...

Summary:
  ✅ Fixed: 42 users
  ❌ Failed: 3 users
```

### 4. ยืนยันผลลัพธ์
```bash
php install/check_profile_issues.php
```

ตรวจสอบว่าจำนวนลูกค้าที่ไม่มีรูปลดลง

---

## 📖 เอกสารเพิ่มเติม

```bash
# คู่มือด่วน
cat install/QUICK_FIX_GUIDE.txt

# เอกสารละเอียด
cat install/PROFILE_PICTURE_FIX.md
```

---

## 🔍 Troubleshooting

### ปัญหา: Permission denied
```bash
chmod +x force_deploy_testry.sh
chmod +x deploy_testry_branch.sh
```

### ปัญหา: Branch testry ไม่มี
```bash
# ดู branch ทั้งหมด
git branch -a

# Fetch ข้อมูลจาก remote
git fetch origin

# Checkout testry
git checkout -b testry origin/testry
```

### ปัญหา: Merge conflict
```bash
# Reset ทุกอย่าง
git reset --hard origin/testry

# หรือ
git checkout --theirs webhook.php
git checkout --theirs includes/webhook_functions.php
```

---

## ⚠️ คำเตือนสำคัญ

1. **Backup ก่อน Deploy**: ถ้าไม่แน่ใจ ให้ backup ไฟล์ก่อน
   ```bash
   cp webhook.php webhook.php.backup
   cp includes/webhook_functions.php includes/webhook_functions.php.backup
   ```

2. **ตรวจสอบ Branch**: ตรวจสอบว่าอยู่ใน branch testry
   ```bash
   git branch
   ```

3. **ทดสอบก่อน**: รันสคริปต์ตรวจสอบก่อนแก้ไข
   ```bash
   php install/check_profile_issues.php
   ```

---

## 📞 ติดต่อ

หากพบปัญหา:
1. ตรวจสอบ error logs
2. อ่านเอกสาร PROFILE_PICTURE_FIX.md
3. ติดต่อทีมพัฒนา
