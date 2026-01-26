#!/bin/bash
# Force Deploy testry branch (ลบการแก้ไขที่ยังไม่ได้ commit)
# ⚠️ คำเตือน: สคริปต์นี้จะลบการแก้ไขทั้งหมดที่ยังไม่ได้ commit

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║                    FORCE DEPLOY TESTRY BRANCH                                ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""
echo "⚠️  WARNING: This will DELETE all uncommitted changes!"
echo ""
read -p "Are you sure you want to continue? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ Deployment cancelled"
    exit 1
fi

echo ""
echo "🔄 Starting deployment..."
echo ""

# 1. Reset การแก้ไข
echo "1. Resetting local changes..."
git reset --hard HEAD

if [ $? -ne 0 ]; then
    echo "❌ Failed to reset changes"
    exit 1
fi

# 2. Checkout ไปที่ testry
echo "2. Checking out testry branch..."
git checkout testry

if [ $? -ne 0 ]; then
    echo "❌ Failed to checkout testry branch"
    exit 1
fi

# 3. Pull ล่าสุด
echo "3. Pulling latest changes..."
git pull origin testry

if [ $? -ne 0 ]; then
    echo "❌ Failed to pull changes"
    exit 1
fi

# 4. แสดงสถานะ
echo ""
echo "4. Current status:"
git status
echo ""
git log --oneline -5
echo ""

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║                        ✅ DEPLOYMENT COMPLETED                                ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""
echo "📋 Next Steps:"
echo ""
echo "   1️⃣  ตรวจสอบปัญหา:"
echo "      php install/check_profile_issues.php"
echo ""
echo "   2️⃣  แก้ไขข้อมูล:"
echo "      php install/fix_missing_profile_pictures.php"
echo ""
echo "   3️⃣  ยืนยันผลลัพธ์:"
echo "      php install/check_profile_issues.php"
echo ""
echo "📖 Documentation:"
echo "   cat install/QUICK_FIX_GUIDE.txt"
echo "   cat install/PROFILE_PICTURE_FIX.md"
echo ""
