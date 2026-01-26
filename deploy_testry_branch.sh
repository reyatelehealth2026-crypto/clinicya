#!/bin/bash
# Deploy testry branch to server
# วิธีใช้: รันสคริปต์นี้บน server

echo "=== Deploy testry branch ==="
echo ""

# 1. Stash การแก้ไขที่ยังไม่ได้ commit
echo "1. Stashing local changes..."
git stash save "Auto-stash before switching to testry"

# 2. Checkout ไปที่ branch testry
echo "2. Switching to testry branch..."
git checkout testry

# 3. Pull การเปลี่ยนแปลงล่าสุด
echo "3. Pulling latest changes..."
git pull origin testry

# 4. แสดงสถานะ
echo "4. Current status:"
git status

echo ""
echo "=== Deployment completed ==="
echo ""
echo "📋 Next steps:"
echo "   1. ตรวจสอบปัญหา: php install/check_profile_issues.php"
echo "   2. แก้ไขข้อมูล: php install/fix_missing_profile_pictures.php"
echo "   3. ยืนยันผลลัพธ์: php install/check_profile_issues.php"
echo ""
echo "📖 อ่านเอกสาร: cat install/QUICK_FIX_GUIDE.txt"
echo ""
