# 🏪 Retail B2C System for CNY Re-Ya

ระบบร้านค้าปลีก B2C สำหรับขายยา OTC ผ่าน LINE Mini App

## 📋 สารบัญ

1. [Architecture](#architecture)
2. [Database Schema](#database-schema)
3. [API Endpoints](#api-endpoints)
4. [LIFF Routes](#liff-routes)
5. [Setup & Installation](#setup--installation)
6. [Cron Jobs](#cron-jobs)
7. [Payment Flow](#payment-flow)
8. [Stock Management](#stock-management)

---

## 🏗️ Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   LINE User     │────>│   LIFF App      │────>│   PHP APIs      │
│   (B2C)         │<────│   (/liff/)      │<────│   (/api/)       │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                              │                         │
                              │                    ┌────┴────┐
                              │                    │         │
                              ▼                    ▼         ▼
                       ┌──────────────┐     ┌────────┐  ┌────────┐
                       │   MySQL      │     │  Odoo  │  │ Payment│
                       │   (Retail)   │     │  API   │  │ Gateway│
                       └──────────────┘     └────────┘  └────────┘
```

---

## 🗄️ Database Schema

### Core Tables

| Table | Purpose |
|-------|---------|
| `retail_products` | สินค้า OTC (sync จาก Odoo) |
| `retail_product_stock` | สต็อก local |
| `retail_customers` | ลูกค้าปลีก (LINE users) |
| `retail_carts` | ตะกร้า + stock reservation |
| `retail_orders` | ออเดอร์ |
| `retail_order_items` | รายการสินค้าในออเดอร์ |
| `retail_payments` | การชำระเงิน |
| `retail_stock_movements` | ประวัติการเคลื่อนไหวสต็อก |
| `retail_category_mapping` | หมวดหมู่ OTC |

### Run Migration

```bash
mysql -u username -p database_name < database/retail_schema.sql
```

---

## 🔌 API Endpoints

### Products
```
GET  /api/retail-products.php              # List products (pagination)
GET  /api/retail-products.php?action=categories  # Get categories
GET  /api/retail-products.php?product_id=1 # Single product
```

### Cart
```
GET    /api/retail-cart.php?line_user_id=xxx    # Get cart
POST   /api/retail-cart.php                     # Add to cart
PUT    /api/retail-cart.php                     # Update qty
DELETE /api/retail-cart.php?cart_id=xxx         # Remove item
```

### Checkout
```
POST /api/retail-checkout.php                   # Create order
```

### Payment
```
POST /api/retail-payment.php?action=verify      # Verify payment
GET  /api/retail-payment.php?action=status      # Check status
POST /api/retail-payment.php?action=upload_slip # Upload slip
```

---

## 📱 LIFF Routes

| Route | Page | Description |
|-------|------|-------------|
| `/retail-shop` | `renderShopPage()` | หน้าร้านค้า |
| `/retail-product/:id` | `renderProductDetail()` | รายละเอียดสินค้า |
| `/retail-cart` | `renderCartPage()` | ตะกร้าสินค้า |
| `/retail-checkout` | `renderCheckoutPage()` | ชำระเงิน |
| `/retail-orders` | `renderOrdersPage()` | ประวัติออเดอร์ |
| `/retail-order/:id` | `renderOrderDetail()` | รายละเอียดออเดอร์ |

### Access Retail Mode

```
https://cny.re-ya.com/liff/?mode=retail
```

---

## ⚙️ Setup & Installation

### 1. Database Setup

```bash
# Import schema
mysql -u root -p zrismpsz_cny < database/retail_schema.sql
```

### 2. Configure PromptPay

```sql
-- Update settings
UPDATE retail_settings SET setting_value = 'YOUR_PROMPTPAY_NUMBER' 
WHERE setting_key = 'promptpay_number';

UPDATE retail_settings SET setting_value = 'YOUR_SHOP_NAME' 
WHERE setting_key = 'promptpay_name';
```

### 3. Odoo Integration

แก้ไข `cron/sync-retail-products.php`:
- ใส่ Odoo credentials ใน config
- ตรวจสอบว่า product categories ตรงกับ `retail_category_mapping`

### 4. File Permissions

```bash
chmod +x cron/sync-retail-products.php
chmod +x cron/release-expired-cart-reservations.php
mkdir -p uploads/payment_slips
chmod 755 uploads/payment_slips
```

---

## ⏰ Cron Jobs

Add to crontab:

```bash
# Sync products from Odoo every 15 minutes
*/15 * * * * cd /path/to/cny.re-ya.com && php cron/sync-retail-products.php >> logs/sync.log 2>&1

# Release expired cart reservations every 5 minutes
*/5 * * * * cd /path/to/cny.re-ya.com && php cron/release-expired-cart-reservations.php >> logs/cart.log 2>&1
```

---

## 💳 Payment Flow

### PromptPay Flow

```
1. User checkout → Create order (status: pending_payment)
2. Generate PromptPay QR
3. User scans QR and pays
4. System verifies payment (manual or webhook)
5. Update order status → confirmed
6. Deduct stock
7. Send LINE notification
```

### COD Flow

```
1. User checkout → Create order (status: confirmed)
2. Deduct stock immediately
3. Prepare shipment
4. Deliver and collect payment
5. Update payment_status → paid
```

---

## 📦 Stock Management

### Stock States

```
qty_available:  สินค้าพร้อมขาย (หลังจากหัก reserved)
qty_reserved:   สินค้าที่ถูกจองใน cart หรือ pending orders
qty_incoming:   สินค้ารอเข้า (จากซัพพลายเออร์)
qty_outgoing:   สินค้ารอส่ง
```

### Reservation Flow

```
1. Add to cart → Reserve 30 mins
   qty_reserved += qty
   
2. Checkout:
   - COD: Move reserved → deduct available
   - PromptPay: Keep reserved until payment
   
3. Payment confirmed:
   - Deduct available
   - Clear reservation
   
4. Cart expires (30 mins):
   - Release reservation
   - qty_reserved -= qty
```

---

## 🔐 Security Considerations

1. **Stock Race Conditions**: ใช้ `FOR UPDATE` ใน SQL transactions
2. **Payment Verification**: ตรวจสอบ slip หรือ webhook signature
3. **Rate Limiting**: จำกัดการเรียก API ต่อ user
4. **Input Validation**: ตรวจสอบทุก input ก่อน query

---

## 🐛 Troubleshooting

### Products not showing
```bash
# Check if sync is working
tail -f logs/sync.log

# Check products table
SELECT COUNT(*) FROM retail_products WHERE is_active = 1 AND is_otc = 1;
```

### Stock not updating
```bash
# Check stock reservations
SELECT product_id, qty_available, qty_reserved FROM retail_product_stock;

# Release stuck reservations
php cron/release-expired-cart-reservations.php
```

### Payment not confirming
```bash
# Check payment status
SELECT * FROM retail_payments WHERE order_id = xxx;

# Manual verify
curl -X POST https://cny.re-ya.com/api/retail-payment.php \
  -d '{"action":"verify","order_id":xxx}'
```

---

## 📝 TODO

- [ ] LINE Pay integration
- [ ] Credit card payment
- [ ] Shipment tracking integration (Kerry, Flash)
- [ ] Push notifications
- [ ] Admin dashboard for retail orders
- [ ] Inventory report
- [ ] Customer loyalty points

---

## 📞 Support

For issues or questions, contact:
- Email: dev@re-ya.com
- LINE: @reyadev
