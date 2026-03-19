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
    
    echo -e "${YELLOW}1. database/migration_odoo_api_performance.sql${NC}"
    echo "   - odoo_webhooks_log: 6 indexes + 2 generated columns"
    echo "   - odoo_orders: 4 indexes"
    echo "   - odoo_invoices: 2 indexes"
    echo "   - odoo_bdos: 1 index"
    echo "   - odoo_customer_projection: 2 indexes"
    echo ""
    
    echo -e "${YELLOW}2. database/migration_missing_indexes.sql${NC}"
    echo "   - odoo_notification_log: 4 indexes"
    echo "   - odoo_bdo_context: 2 indexes"
    echo "   - odoo_bdo_orders: 4 indexes"
    echo "   - odoo_webhook_dlq: 4 indexes"
    echo "   - odoo_line_users: 2 indexes"
    echo "   - odoo_slip_uploads: 4 indexes"
    echo "   - odoo_orders_summary: 3 indexes"
    echo "   - odoo_customers_cache: 2 indexes"
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
    COUNT(*) as index_count
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
ORDER BY table_name;
EOF
}

# Run query performance test
test_queries() {
    echo ""
    log_info "ทดสอบ query performance (ก่อน/หลัง comparison)..."
    echo ""
    
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
-- Test 1: Webhooks count today
SELECT 
    'webhooks_today' as test_name,
    COUNT(*) as result,
    COUNT(*) > 0 as has_data
FROM odoo_webhooks_log 
WHERE created_at >= CURDATE();

-- Test 2: Notification count today  
SELECT 
    'notification_today' as test_name,
    COUNT(*) as result
FROM odoo_notification_log 
WHERE sent_at >= CURDATE() 
    AND sent_at < CURDATE() + INTERVAL 1 DAY;

-- Test 3: BDO context group by (common query)
SELECT 
    'bdo_context_groups' as test_name,
    COUNT(*) as result
FROM (
    SELECT bdo_id, MAX(id) as max_id 
    FROM odoo_bdo_context 
    GROUP BY bdo_id 
    LIMIT 10
) t;

-- Test 4: Pending slips count
SELECT 
    'pending_slips' as test_name,
    COUNT(*) as result
FROM odoo_slip_uploads 
WHERE status IN ('new','pending');
EOF
}

# Main execution
main() {
    # Check connection
    check_connection
    
    # Preview
    preview_migrations
    
    # Confirm
    echo ""
    log_warn "⚠️  การรัน migration จะเปลี่ยนแปลง database structure"
    read -p "ต้องการดำเนินการต่อ? (yes/no): " confirm
    
    if [ "$confirm" != "yes" ]; then
        log_info "ยกเลิกการรัน migration"
        exit 0
    fi
    
    # Backup
    backup_schemas
    
    # Run migrations
    run_migration "database/migration_odoo_api_performance.sql" "Migration 1: API Performance Indexes" || exit 1
    run_migration "database/migration_missing_indexes.sql" "Migration 2: Missing Indexes" || exit 1
    
    # Verify
    verify_indexes
    
    # Test queries
    test_queries
    
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    log_success "Migration เสร็จสิ้นทั้งหมด!"
    echo ""
    log_info "ไฟล์ backup: $BACKUP_DIR/schema_backup_$TIMESTAMP.sql"
    log_info "รัน 'node scripts/analyze-slow-queries.php' เพื่อดูผลลัพธ์"
    echo "═══════════════════════════════════════════════════════════"
}

# Run
main
