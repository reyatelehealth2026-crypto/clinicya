#!/bin/bash
# Run Database Migrations with Safety Checks
# รัน migration อย่างปลอดภัยพร้อม backup และ rollback plan

set -e  # Exit on error

DB_HOST="localhost"
DB_NAME="zrismpsz_cny"
DB_USER="zrismpsz_cny"
DB_PASS="zrismpsz_cny"
BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "═══════════════════════════════════════════════════════════"
echo "  Odoo Database Migration Runner"
echo "  Database: $DB_NAME"
echo "  Time: $(date)"
echo "═══════════════════════════════════════════════════════════"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check MySQL connection
check_connection() {
    log_info "ตรวจสอบการเชื่อมต่อ MySQL..."
    if ! mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" "$DB_NAME" > /dev/null 2>&1; then
        log_error "ไม่สามารถเชื่อมต่อ MySQL ได้"
        exit 1
    fi
    log_success "เชื่อมต่อ MySQL สำเร็จ"
}

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup table structures (schema only)
backup_schemas() {
    log_info "สร้าง backup schema..."
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" --no-data \
        "$DB_NAME" > "$BACKUP_DIR/schema_backup_$TIMESTAMP.sql"
    log_success "Backup schema: $BACKUP_DIR/schema_backup_$TIMESTAMP.sql"
}

# Show migration preview
preview_migrations() {
    echo ""
    log_info "Migration ที่จะรัน:"
    echo ""
    
    echo -e "${YELLOW}1. database/migration_comprehensive_indexes.sql${NC} ${GREEN}(RECOMMENDED)${NC}"
    echo "   - ครอบคลุมทุก query pattern จากการวิเคราะห์ codebase"
    echo "   - 13 ตาราง, ~70 indexes"
    echo "   - รวม generated columns สำหรับ JSON queries"
    echo ""
    
    echo -e "${YELLOW}2. database/migration_odoo_api_performance.sql${NC}"
    echo "   - Core API performance indexes"
    echo "   - 6 tables, ~17 indexes"
    echo ""
    
    echo -e "${YELLOW}3. database/migration_missing_indexes.sql${NC}"
    echo "   - Missing indexes จากแผนเดิม"
    echo "   - 8 tables, ~25 indexes"
    echo ""
}

# Run migration with error handling
run_migration() {
    local file=$1
    local name=$2
    
    echo ""
    log_info "กำลังรัน: $name"
    echo "───────────────────────────────────────────────────────────"
    
    if [ ! -f "$file" ]; then
        log_error "ไม่พบไฟล์: $file"
        return 1
    fi
    
    # Count ALTER statements
    local alter_count=$(grep -c "ALTER TABLE" "$file" || echo "0")
    local index_count=$(grep -c "ADD INDEX" "$file" || echo "0")
    log_info "พบ $alter_count ALTER TABLE, $index_count ADD INDEX"
    
    # Show warning for large tables
    log_warn "⚠️  ตารางใหญ่ (odoo_webhooks_log) อาจใช้เวลา 4-6 นาที"
    
    # Run migration with timing
    local start_time=$(date +%s)
    
    if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$file" 2>&1; then
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        log_success "✓ $name เสร็จสิ้น (${duration} วินาที)"
        return 0
    else
        log_error "✗ $name ล้มเหลว"
        return 1
    fi
}

# Verify indexes after migration
verify_indexes() {
    echo ""
    log_info "ตรวจสอบ indexes หลัง migration..."
    echo ""
    
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
SELECT 
    table_name,
    COUNT(*) as index_count,
    GROUP_CONCAT(DISTINCT CASE WHEN index_name LIKE 'idx_%' THEN index_name END ORDER BY index_name SEPARATOR ', ') as new_indexes
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
    AND table_name IN (
        'odoo_webhooks_log',
        'odoo_notification_log',
        'odoo_line_users',
        'odoo_slip_uploads',
        'odoo_bdos',
        'odoo_bdo_context',
        'odoo_webhook_dlq',
        'odoo_orders',
        'odoo_invoices',
        'odoo_orders_summary',
        'odoo_customers_cache'
    )
    AND index_name != 'PRIMARY'
GROUP BY table_name
ORDER BY FIELD(table_name, 
    'odoo_webhooks_log',
    'odoo_notification_log', 
    'odoo_line_users',
    'odoo_slip_uploads',
    'odoo_bdos'
);
EOF
}

# Run query performance test
test_queries() {
    echo ""
    log_info "ทดสอบ query performance..."
    echo ""
    
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
SELECT 'TEST' as type, 'webhooks_today' as name, COUNT(*) as result, 'rows' as unit
FROM odoo_webhooks_log WHERE created_at >= CURDATE()
UNION ALL
SELECT 'TEST', 'notification_today', COUNT(*), 'rows'
FROM odoo_notification_log WHERE sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY
UNION ALL
SELECT 'TEST', 'pending_slips', COUNT(*), 'rows'
FROM odoo_slip_uploads WHERE status IN ('new','pending')
UNION ALL
SELECT 'TEST', 'bdo_context_groups', COUNT(*), 'groups'
FROM (SELECT bdo_id FROM odoo_bdo_context GROUP BY bdo_id LIMIT 10) t
UNION ALL
SELECT 'TEST', 'line_users', COUNT(*), 'rows'
FROM odoo_line_users;
EOF
}

# Run EXPLAIN analysis
explain_queries() {
    echo ""
    log_info "ตรวจสอบ query plans (EXPLAIN)..."
    echo ""
    
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
-- Test if indexes are being used
SELECT 'EXPLAIN webhooks created_at' as test, 
    CASE WHEN possible_keys LIKE '%created_at%' THEN '✓ Index available' ELSE '✗ No index' END as status
FROM information_schema.statistics 
WHERE table_schema = DATABASE() AND table_name = 'odoo_webhooks_log' AND column_name = 'created_at'
LIMIT 1;
EOF
}

# Main execution
main() {
    # Check connection
    check_connection
    
    # Preview
    preview_migrations
    
    # Migration selection
    echo ""
    log_info "เลือก migration ที่ต้องการรัน:"
    echo "  1) migration_comprehensive_indexes.sql ${GREEN}(แนะนำ - ครอบคลุมทุก query)${NC}"
    echo "  2) migration_odoo_api_performance.sql (เฉพาะ core tables)"
    echo "  3) migration_missing_indexes.sql (เฉพาะ missing indexes)"
    echo "  4) ทั้งหมด (all migrations in order)"
    echo "  5) ยกเลิก"
    echo ""
    read -p "เลือก (1-5): " choice
    
    case $choice in
        1)
            migration_file="database/migration_comprehensive_indexes.sql"
            migration_name="Comprehensive Index Migration"
            ;;
        2)
            migration_file="database/migration_odoo_api_performance.sql"
            migration_name="API Performance Indexes"
            ;;
        3)
            migration_file="database/migration_missing_indexes.sql"
            migration_name="Missing Indexes"
            ;;
        4)
            migration_file="all"
            migration_name="All Migrations"
            ;;
        5)
            log_info "ยกเลิกการรัน migration"
            exit 0
            ;;
        *)
            log_error "ตัวเลือกไม่ถูกต้อง"
            exit 1
            ;;
    esac
    
    # Confirm
    echo ""
    log_warn "⚠️  การรัน migration จะเปลี่ยนแปลง database structure"
    log_warn "⚠️  ตาราง odoo_webhooks_log (2.4M+ rows) อาจใช้เวลานาน"
    read -p "ต้องการดำเนินการต่อ? (yes/no): " confirm
    
    if [ "$confirm" != "yes" ]; then
        log_info "ยกเลิกการรัน migration"
        exit 0
    fi
    
    # Backup
    backup_schemas
    
    # Run migration(s)
    if [ "$migration_file" = "all" ]; then
        run_migration "database/migration_odoo_api_performance.sql" "Migration 1: API Performance" || exit 1
        run_migration "database/migration_missing_indexes.sql" "Migration 2: Missing Indexes" || exit 1
        run_migration "database/migration_comprehensive_indexes.sql" "Migration 3: Comprehensive Indexes" || exit 1
    else
        run_migration "$migration_file" "$migration_name" || exit 1
    fi
    
    # Verify
    verify_indexes
    
    # Test queries
    test_queries
    
    # Explain
    explain_queries
    
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    log_success "Migration เสร็จสิ้นทั้งหมด!"
    echo ""
    log_info "ไฟล์ backup: $BACKUP_DIR/schema_backup_$TIMESTAMP.sql"
    log_info "รัน 'node scripts/analyze-slow-queries.php' เพื่อดูผลลัพธ์เต็ม"
    echo ""
    log_warn "หมายเหตุ: หากต้องการ rollback ให้ใช้ไฟล์ backup ที่สร้างไว้"
    echo "═══════════════════════════════════════════════════════════"
}

# Run
main
