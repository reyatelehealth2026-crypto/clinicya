#!/bin/bash
# Force Deploy testry branch (ลบการแก้ไขที่ยังไม่ได้ commit)
# ⚠️ คำเตือน: สคริปต์นี้จะลบการแก้ไขทั้งหมดที่ยังไม่ได้ commit

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║                    FORCE DEPLOY main BRANCH                                ║"
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

# 2. Checkout ไปที่ main
echo "2. Checking out origin main..."
git checkout main

if [ $? -ne 0 ]; then
    echo "❌ Failed to checkout main branch"
    exit 1
fi

# 3. Pull ล่าสุด
echo "3. Pulling latest changes..."
git pull origin main
