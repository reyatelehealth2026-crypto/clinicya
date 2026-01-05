# 📋 CLINICYA SYSTEM WORKFLOW DOCUMENTATION
## ระบบจัดการร้านขายยาและคลังสินค้าครบวงจร

**Version:** 2.0  
**Last Updated:** January 2026

---

## 📑 สารบัญ

1. [ภาพรวมระบบ](#1-ภาพรวมระบบ)
2. [Procurement - การจัดซื้อ](#2-procurement---การจัดซื้อ)
3. [Inventory - คลังสินค้า](#3-inventory---คลังสินค้า)
4. [WMS - Pick Pack Ship](#4-wms---pick-pack-ship)
5. [Sales - การขาย](#5-sales---การขาย)
6. [Pharmacy - ระบบเภสัชกรรม](#6-pharmacy---ระบบเภสัชกรรม)
7. [CRM & Communication](#7-crm--communication)
8. [Accounting - บัญชี](#8-accounting---บัญชี)
9. [Database Schema](#9-database-schema)
10. [API Reference](#10-api-reference)

---

## 1. ภาพรวมระบบ

### 🔄 Main Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                    CLINICYA SYSTEM                                       │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│   ┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐           │
│   │  SUPPLIER   │────▶│ PROCUREMENT │────▶│  INVENTORY  │────▶│    SALES    │           │
│   │  ผู้จำหน่าย   │     │   จัดซื้อ     │     │   คลังสินค้า  │     │    ขาย      │           │
│   └─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘           │
│         │                   │                   │                   │                    │
│         │                   ▼                   ▼                   ▼                    │
│         │            ┌─────────────┐     ┌─────────────┐     ┌─────────────┐           │
│         │            │     PO      │     │   STOCK     │     │   ORDER     │           │
│         │            │  ใบสั่งซื้อ   │     │  + BATCH    │     │  คำสั่งซื้อ   │           │
│         │            └─────────────┘     │  + LOCATION │     └─────────────┘           │
│         │                   │            └─────────────┘           │                    │
│         │                   ▼                   │                   ▼                    │
│         │            ┌─────────────┐           │            ┌─────────────┐           │
│         └───────────▶│     GR      │───────────┘            │    WMS      │           │
│                      │  รับสินค้า    │                        │ Pick/Pack/  │           │
│                      │ + Lot/Exp   │                        │   Ship      │           │
│                      └─────────────┘                        └─────────────┘           │
│                             │                                      │                    │
│                             ▼                                      ▼                    │
│                      ┌─────────────┐                        ┌─────────────┐           │
│                      │  PUT-AWAY   │                        │  DELIVERY   │           │
│                      │ เก็บ Location│                        │   จัดส่ง     │           │
│                      └─────────────┘                        └─────────────┘           │
│                                                                                          │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                    SUPPORTING MODULES                                    │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│   ┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐           │
│   │  PHARMACY   │     │     CRM     │     │ ACCOUNTING  │     │  ANALYTICS  │           │
│   │  เภสัชกรรม   │     │  ลูกค้าสัมพันธ์ │     │    บัญชี     │     │   วิเคราะห์   │           │
│   └─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘           │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### 🎯 ช่องทางการขาย (Sales Channels)

| Channel | Description | Entry Point |
|---------|-------------|-------------|
| LINE LIFF | ร้านค้าออนไลน์ผ่าน LINE | `liff/index.php` |
| LINE Chat | สั่งซื้อผ่านแชท | `webhook.php` |
| Admin Panel | สร้างออเดอร์โดย Admin | `shop/orders.php` |
| Walk-in | ขายหน้าร้าน | `pharmacy.php` |

---

## 2. PROCUREMENT - การจัดซื้อ

### 📥 Flow การจัดซื้อ

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         PROCUREMENT WORKFLOW                                  │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  STEP 1: Low Stock Detection                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  Cron Job / Manual Check                                                │ │
│  │  ├── ตรวจสอบ: stock <= reorder_point                                    │ │
│  │  ├── แจ้งเตือน: LINE Notify / Email                                     │ │
│  │  └── แนะนำ: สร้าง PO อัตโนมัติ                                           │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 2: Create Purchase Order (PO)                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  procurement.php?tab=po                                                 │ │
│  │  ├── เลือก Supplier                                                     │ │
│  │  ├── เพิ่มรายการสินค้า + จำนวน + ราคา                                    │ │
│  │  ├── กำหนดวันส่งสินค้า                                                   │ │
│  │  └── Status: draft → approved → ordered                                 │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 3: Goods Receipt (GR)                                                   │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  procurement.php?tab=gr                                                 │ │
│  │  ├── เลือก PO ที่จะรับสินค้า                                             │ │
│  │  ├── ตรวจสอบจำนวนที่รับจริง                                              │ │
│  │  ├── บันทึก Lot Number                                                  │ │
│  │  ├── บันทึก Expiry Date                                                 │ │
│  │  ├── บันทึก Manufacturing Date (optional)                               │ │
│  │  └── สร้าง Batch Record → product_batches                               │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 4: Put-Away                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  inventory/index.php?tab=put-away                                       │ │
│  │  ├── เลือก Batch ที่รับเข้า                                              │ │
│  │  ├── Scan/เลือก Location (Zone + Shelf + Bin)                           │ │
│  │  ├── ระบุจำนวนที่จัดเก็บ                                                 │ │
│  │  ├── ตรวจสอบ Storage Condition (อุณหภูมิ)                                │ │
│  │  └── บันทึก → stock_by_location                                         │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 📁 Files ที่เกี่ยวข้อง

| File | Description |
|------|-------------|
| `procurement.php` | หน้าหลัก Procurement (Tab-based) |
| `includes/procurement/po.php` | จัดการใบสั่งซื้อ |
| `includes/procurement/gr.php` | รับสินค้า + Lot/Exp |
| `includes/procurement/suppliers.php` | จัดการ Suppliers |
| `includes/inventory/put-away.php` | จัดเก็บเข้า Location |
| `classes/BatchService.php` | จัดการ Batch/Lot |
| `classes/PutAwayService.php` | Logic Put-Away |

### 📊 Status Flow

```
PO Status:
draft → approved → ordered → partial_received → received → closed

GR Status:
pending → completed
```

---

## 3. INVENTORY - คลังสินค้า

### 📦 โครงสร้างคลังสินค้า

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         INVENTORY STRUCTURE                                   │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  WAREHOUSE                                                                    │
│  └── ZONE (Storage Condition)                                                 │
│      ├── Zone A: Ambient (15-25°C)                                           │
│      ├── Zone B: Cool (8-15°C)                                               │
│      ├── Zone C: Cold (2-8°C)                                                │
│      └── Zone D: Controlled                                                   │
│          └── LOCATION (Shelf/Bin)                                            │
│              ├── A-01-01 (Zone-Shelf-Bin)                                    │
│              ├── A-01-02                                                      │
│              └── ...                                                          │
│                  └── STOCK                                                    │
│                      ├── Product ID                                          │
│                      ├── Batch ID (Lot + Exp)                                │
│                      └── Quantity                                            │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 📋 Inventory Features

| Feature | URL | Description |
|---------|-----|-------------|
| Stock Overview | `/inventory/index.php?tab=stock` | ดู Stock ทั้งหมด |
| Batches | `/inventory/index.php?tab=batches` | จัดการ Lot/Batch |
| Locations | `/inventory/index.php?tab=locations` | จัดการ Location |
| Put-Away | `/inventory/index.php?tab=put-away` | จัดเก็บสินค้า |
| Low Stock | `/inventory/index.php?tab=low-stock` | สินค้าใกล้หมด |
| Movements | `/inventory/index.php?tab=movements` | ประวัติเคลื่อนไหว |
| Adjustment | `/inventory/index.php?tab=adjustment` | ปรับปรุง Stock |
| Reports | `/inventory/index.php?tab=reports` | รายงาน |

### 🔄 Stock Movement Types

```
IN:  purchase, return_in, adjustment_in, transfer_in
OUT: sale, return_out, adjustment_out, transfer_out, expired, damaged
```

---

## 4. WMS - Pick Pack Ship

### 📤 Outbound Flow (FEFO)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         WMS - PICK PACK SHIP                                  │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  STEP 1: ORDER RECEIVED                                                       │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  Sources:                                                               │ │
│  │  ├── LIFF Shop → liff-checkout.php                                      │ │
│  │  ├── LINE Chat → webhook.php                                            │ │
│  │  └── Admin → shop/orders.php                                            │ │
│  │                                                                         │ │
│  │  Order Status: pending → processing                                     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 2: PICK (หยิบสินค้า)                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  inventory/index.php?tab=wms (Pick Tab)                                 │ │
│  │                                                                         │ │
│  │  FEFO Algorithm (First Expired, First Out):                             │ │
│  │  ┌─────────────────────────────────────────────────────────────────┐   │ │
│  │  │  SELECT location, batch, quantity                               │   │ │
│  │  │  FROM stock_by_location sbl                                     │   │ │
│  │  │  JOIN product_batches pb ON sbl.batch_id = pb.id               │   │ │
│  │  │  WHERE product_id = ? AND quantity > 0                          │   │ │
│  │  │  ORDER BY pb.expiry_date ASC  ← หมดอายุก่อน ออกก่อน              │   │ │
│  │  └─────────────────────────────────────────────────────────────────┘   │ │
│  │                                                                         │ │
│  │  Process:                                                               │ │
│  │  ├── ระบบแนะนำ Location + Batch ตาม FEFO                               │ │
│  │  ├── พนักงานไปหยิบสินค้าตาม Pick List                                   │ │
│  │  ├── Scan Barcode ยืนยันการหยิบ                                        │ │
│  │  ├── ตัด Stock จาก stock_by_location                                   │ │
│  │  └── บันทึก wms_pick_items                                             │ │
│  │                                                                         │ │
│  │  Order Status: processing → picked                                      │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 3: PACK (แพ็คสินค้า)                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  inventory/index.php?tab=wms (Pack Tab)                                 │ │
│  │                                                                         │ │
│  │  Process:                                                               │ │
│  │  ├── ดึง Orders ที่ Pick แล้ว                                           │ │
│  │  ├── Scan ตรวจสอบสินค้าทุกชิ้น                                          │ │
│  │  ├── เลือกขนาดกล่อง/บรรจุภัณฑ์                                          │ │
│  │  ├── พิมพ์ Packing Slip                                                │ │
│  │  ├── พิมพ์ Shipping Label                                              │ │
│  │  └── บันทึกน้ำหนักพัสดุ                                                  │ │
│  │                                                                         │ │
│  │  Order Status: picked → packed                                          │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                              │                                                │
│                              ▼                                                │
│  STEP 4: SHIP (จัดส่ง)                                                        │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  inventory/index.php?tab=wms (Ship Tab)                                 │ │
│  │                                                                         │ │
│  │  Process:                                                               │ │
│  │  ├── ดึง Orders ที่ Pack แล้ว                                           │ │
│  │  ├── เลือก Shipping Method (Kerry/Flash/Thailand Post/etc.)            │ │
│  │  ├── พิมพ์ใบส่งสินค้า                                                   │ │
│  │  ├── บันทึก Tracking Number                                            │ │
│  │  ├── ส่ง Tracking ให้ลูกค้าผ่าน LINE                                    │ │
│  │  └── บันทึก wms_shipments                                              │ │
│  │                                                                         │ │
│  │  Order Status: packed → shipped → delivered                             │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 📁 WMS Files

| File | Description |
|------|-------------|
| `includes/inventory/wms.php` | WMS Main (Tab Container) |
| `includes/inventory/wms-dashboard.php` | WMS Dashboard |
| `includes/inventory/wms-pick.php` | Pick Module |
| `includes/inventory/wms-pack.php` | Pack Module |
| `includes/inventory/wms-ship.php` | Ship Module |
| `includes/inventory/wms-exceptions.php` | Exception Handling |
| `classes/WMSService.php` | WMS Business Logic |
| `classes/WMSPrintService.php` | Print Labels/Slips |
| `api/wms.php` | WMS API Endpoints |

### 📊 Order Status Flow

```
pending → processing → picked → packed → shipped → delivered
                 │         │        │
                 └─────────┴────────┴──→ cancelled (any stage)
```

---

## 5. SALES - การขาย

### 🛒 Sales Channels Flow

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                            SALES CHANNELS                                     │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  CHANNEL 1: LINE LIFF SHOP                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  liff/index.php → Vue.js SPA                                            │ │
│  │                                                                         │ │
│  │  Flow:                                                                  │ │
│  │  ├── ลูกค้าเปิด LIFF จาก Rich Menu                                       │ │
│  │  ├── เลือกสินค้า → เพิ่มตะกร้า                                           │ │
│  │  ├── Checkout → กรอกที่อยู่                                              │ │
│  │  ├── เลือกวิธีชำระเงิน                                                   │ │
│  │  ├── ยืนยันคำสั่งซื้อ                                                    │ │
│  │  └── api/checkout.php → สร้าง Order                                     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  CHANNEL 2: LINE CHAT                                                         │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  webhook.php → BusinessBot                                              │ │
│  │                                                                         │ │
│  │  Commands:                                                              │ │
│  │  ├── "shop" / "สินค้า" → แสดงหมวดหมู่                                    │ │
│  │  ├── "หมวด [id]" → แสดงสินค้าในหมวด                                     │ │
│  │  ├── "เพิ่ม [id]" → เพิ่มตะกร้า                                          │ │
│  │  ├── "ตะกร้า" → แสดงตะกร้า                                               │ │
│  │  ├── "สั่งซื้อ" → Checkout                                               │ │
│  │  └── "คำสั่งซื้อ" → ดูประวัติ                                             │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  CHANNEL 3: ADMIN PANEL                                                       │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  shop/orders.php                                                        │ │
│  │                                                                         │ │
│  │  Features:                                                              │ │
│  │  ├── สร้าง Order ให้ลูกค้า                                               │ │
│  │  ├── จัดการ Order Status                                                │ │
│  │  ├── พิมพ์ใบเสร็จ                                                       │ │
│  │  └── ดูประวัติการสั่งซื้อ                                                 │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  CHANNEL 4: WALK-IN (POS)                                                     │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  pharmacy.php?tab=dispense                                              │ │
│  │                                                                         │ │
│  │  Features:                                                              │ │
│  │  ├── ขายหน้าร้าน                                                        │ │
│  │  ├── Scan Barcode                                                       │ │
│  │  ├── จ่ายยาตามใบสั่งแพทย์                                                │ │
│  │  └── พิมพ์ใบเสร็จ                                                       │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 💳 Payment Methods

| Method | Description |
|--------|-------------|
| Cash | เงินสด (หน้าร้าน) |
| Transfer | โอนเงิน |
| PromptPay | พร้อมเพย์ QR |
| Credit Card | บัตรเครดิต |
| COD | เก็บเงินปลายทาง |

---

## 6. PHARMACY - ระบบเภสัชกรรม

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         PHARMACY MODULE                                       │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  FEATURES:                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  1. Dashboard (pharmacy.php?tab=dashboard)                              │ │
│  │     ├── สรุปยอดขายวันนี้                                                 │ │
│  │     ├── รายการรอจ่ายยา                                                  │ │
│  │     ├── แจ้งเตือนยาใกล้หมดอายุ                                           │ │
│  │     └── แจ้งเตือนยาใกล้หมด Stock                                         │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  2. Dispense (pharmacy.php?tab=dispense)                                │ │
│  │     ├── จ่ายยาตามใบสั่งแพทย์                                             │ │
│  │     ├── ตรวจสอบ Drug Interactions                                       │ │
│  │     ├── ตรวจสอบ Allergies                                               │ │
│  │     ├── พิมพ์ฉลากยา                                                     │ │
│  │     └── บันทึกประวัติการจ่ายยา                                           │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  3. Drug Interactions (pharmacy.php?tab=interactions)                   │ │
│  │     ├── ตรวจสอบยาตีกัน                                                  │ │
│  │     ├── แสดงระดับความรุนแรง                                              │ │
│  │     └── คำแนะนำการใช้ยา                                                 │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  4. Pharmacists (pharmacy.php?tab=pharmacists)                          │ │
│  │     ├── จัดการข้อมูลเภสัชกร                                              │ │
│  │     ├── ตารางเวรเภสัชกร                                                 │ │
│  │     └── ใบอนุญาตประกอบวิชาชีพ                                            │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  AI FEATURES:                                                                 │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  AI Pharmacy Assistant (modules/AIChat/)                                │ │
│  │     ├── Triage Assessment - ประเมินอาการเบื้องต้น                        │ │
│  │     ├── Drug Information - ข้อมูลยา                                     │ │
│  │     ├── Product Recommendation - แนะนำสินค้า                            │ │
│  │     └── Pharmacist Escalation - ส่งต่อเภสัชกร                           │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 📁 Pharmacy Files

| File | Description |
|------|-------------|
| `pharmacy.php` | หน้าหลัก Pharmacy |
| `includes/pharmacy/dashboard.php` | Dashboard |
| `includes/pharmacy/dispense.php` | จ่ายยา |
| `includes/pharmacy/interactions.php` | Drug Interactions |
| `includes/pharmacy/pharmacists.php` | จัดการเภสัชกร |
| `modules/AIChat/` | AI Pharmacy Module |

---

## 7. CRM & Communication

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         CRM & COMMUNICATION                                   │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  LINE INTEGRATION:                                                            │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  ├── Webhook (webhook.php)                                              │ │
│  │  │   ├── รับข้อความจากลูกค้า                                             │ │
│  │  │   ├── ตอบกลับอัตโนมัติ (Auto-Reply)                                   │ │
│  │  │   ├── AI Chatbot                                                     │ │
│  │  │   └── ส่งต่อ Admin (Human Mode)                                      │ │
│  │  │                                                                      │ │
│  │  ├── Rich Menu (rich-menu.php)                                          │ │
│  │  │   ├── Static Rich Menu                                               │ │
│  │  │   ├── Dynamic Rich Menu (ตามเงื่อนไข)                                 │ │
│  │  │   └── Switch Rich Menu (สลับหน้า)                                    │ │
│  │  │                                                                      │ │
│  │  ├── LIFF Apps (liff/)                                                  │ │
│  │  │   ├── Shop - ร้านค้า                                                 │ │
│  │  │   ├── Member Card - บัตรสมาชิก                                       │ │
│  │  │   ├── Points - แต้มสะสม                                              │ │
│  │  │   ├── Appointments - นัดหมาย                                         │ │
│  │  │   └── AI Assistant - ผู้ช่วย AI                                      │ │
│  │  │                                                                      │ │
│  │  └── Broadcast (broadcast.php)                                          │ │
│  │      ├── ส่งข้อความหาลูกค้าทั้งหมด                                       │ │
│  │      ├── ส่งตาม Segment                                                 │ │
│  │      └── ตั้งเวลาส่ง                                                     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  INBOX (inbox.php):                                                           │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  ├── ดูข้อความทั้งหมด                                                    │ │
│  │  ├── ตอบกลับลูกค้า                                                      │ │
│  │  ├── ส่งรูป/ไฟล์                                                        │ │
│  │  ├── ดูประวัติการสนทนา                                                   │ │
│  │  └── Tag ลูกค้า                                                         │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  MEMBERS (members.php):                                                       │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  ├── ดูข้อมูลสมาชิก                                                     │ │
│  │  ├── ประวัติการซื้อ                                                     │ │
│  │  ├── แต้มสะสม                                                           │ │
│  │  ├── Tags                                                               │ │
│  │  └── Segments                                                           │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 8. ACCOUNTING - บัญชี

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         ACCOUNTING MODULE                                     │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  FEATURES:                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  1. Dashboard (accounting.php?tab=dashboard)                            │ │
│  │     ├── สรุปรายรับ-รายจ่าย                                               │ │
│  │     ├── กราฟแนวโน้ม                                                     │ │
│  │     ├── AR/AP Aging                                                     │ │
│  │     └── Cash Flow                                                       │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  2. Account Receivable (accounting.php?tab=ar)                          │ │
│  │     ├── ลูกหนี้การค้า                                                    │ │
│  │     ├── ใบแจ้งหนี้                                                       │ │
│  │     ├── ใบเสร็จรับเงิน                                                   │ │
│  │     └── Aging Report                                                    │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  3. Account Payable (accounting.php?tab=ap)                             │ │
│  │     ├── เจ้าหนี้การค้า                                                   │ │
│  │     ├── ใบสั่งซื้อ (PO)                                                  │ │
│  │     ├── ใบสำคัญจ่าย                                                     │ │
│  │     └── Aging Report                                                    │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │  4. Expenses (accounting.php?tab=expenses)                              │ │
│  │     ├── บันทึกค่าใช้จ่าย                                                 │ │
│  │     ├── หมวดหมู่ค่าใช้จ่าย                                               │ │
│  │     └── รายงานค่าใช้จ่าย                                                 │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 📁 Accounting Files

| File | Description |
|------|-------------|
| `accounting.php` | หน้าหลัก Accounting |
| `includes/accounting/dashboard.php` | Dashboard |
| `includes/accounting/ar.php` | Account Receivable |
| `includes/accounting/ap.php` | Account Payable |
| `includes/accounting/expenses.php` | Expenses |
| `classes/AccountingDashboardService.php` | Dashboard Service |
| `classes/AccountReceivableService.php` | AR Service |
| `classes/AccountPayableService.php` | AP Service |
| `classes/ExpenseService.php` | Expense Service |

---

## 9. DATABASE SCHEMA

### 📊 Core Tables

```sql
-- =============================================
-- PRODUCTS & INVENTORY
-- =============================================
business_items              -- สินค้า
product_categories          -- หมวดหมู่สินค้า
product_batches             -- Lot/Batch + Expiry
warehouse_locations         -- Location (Zone/Shelf/Bin)
stock_by_location           -- Stock แยกตาม Location + Batch
inventory_movements         -- ประวัติเคลื่อนไหว Stock

-- =============================================
-- PROCUREMENT
-- =============================================
suppliers                   -- ผู้จำหน่าย
purchase_orders             -- ใบสั่งซื้อ
purchase_order_items        -- รายการสินค้าใน PO
goods_receipts              -- ใบรับสินค้า
goods_receipt_items         -- รายการสินค้าที่รับ

-- =============================================
-- SALES & ORDERS
-- =============================================
transactions                -- คำสั่งซื้อ (Orders)
transaction_items           -- รายการสินค้าในคำสั่งซื้อ
cart_items                  -- ตะกร้าสินค้า

-- =============================================
-- WMS
-- =============================================
wms_pick_lists              -- รายการหยิบสินค้า
wms_pick_items              -- รายละเอียดการหยิบ
wms_shipments               -- ข้อมูลการจัดส่ง

-- =============================================
-- CRM
-- =============================================
users                       -- ลูกค้า/สมาชิก
user_tags                   -- Tags
user_tag_assignments        -- User-Tag Mapping
customer_segments           -- Segments
messages                    -- ข้อความ LINE

-- =============================================
-- PHARMACY
-- =============================================
pharmacists                 -- เภสัชกร
drug_interactions           -- ยาตีกัน
dispensing_records          -- ประวัติจ่ายยา
ai_triage_assessments       -- AI Triage

-- =============================================
-- ACCOUNTING
-- =============================================
account_receivables         -- ลูกหนี้
account_payables            -- เจ้าหนี้
expenses                    -- ค่าใช้จ่าย
expense_categories          -- หมวดหมู่ค่าใช้จ่าย
receipt_vouchers            -- ใบเสร็จรับเงิน
payment_vouchers            -- ใบสำคัญจ่าย
```

---

## 10. API REFERENCE

### 🔌 Main API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/wms.php` | GET/POST | WMS Operations |
| `/api/put-away.php` | GET/POST | Put-Away Operations |
| `/api/batches.php` | GET/POST | Batch Management |
| `/api/locations.php` | GET/POST | Location Management |
| `/api/checkout.php` | POST | Checkout/Create Order |
| `/api/shop-products.php` | GET | Product Listing |
| `/api/accounting.php` | GET/POST | Accounting Operations |
| `/api/inbox.php` | GET/POST | Inbox/Messages |
| `/webhook.php` | POST | LINE Webhook |

### 📝 API Examples

```bash
# Get WMS Dashboard Stats
GET /api/wms.php?action=dashboard_stats

# Create Pick List
POST /api/wms.php
{
    "action": "create_pick_list",
    "order_ids": [1, 2, 3]
}

# Confirm Pick Item
POST /api/wms.php
{
    "action": "confirm_pick",
    "pick_item_id": 1,
    "location_id": 5,
    "batch_id": 10,
    "quantity": 2
}

# Put-Away
POST /api/put-away.php
{
    "action": "put_away",
    "batch_id": 10,
    "location_id": 5,
    "quantity": 100
}
```

---

## 📌 Quick Reference URLs

| Module | URL |
|--------|-----|
| Dashboard | `/dashboard.php` |
| Inbox | `/inbox.php` |
| Members | `/members.php` |
| Shop Products | `/shop/products.php` |
| Shop Orders | `/shop/orders.php` |
| Inventory | `/inventory/index.php` |
| Procurement | `/procurement.php` |
| Pharmacy | `/pharmacy.php` |
| Accounting | `/accounting.php` |
| Settings | `/settings.php` |
| Rich Menu | `/rich-menu.php` |
| Broadcast | `/broadcast.php` |
| Analytics | `/analytics.php` |
| Dev Dashboard | `/dev-dashboard.php` |

---

**Document Version:** 2.0  
**Generated:** January 2026  
**System:** CLINICYA - Pharmacy Management System


---

## 11. SYSTEM ARCHITECTURE & STATISTICS

### 📊 Project Statistics

| Category | Files | Lines of Code |
|----------|-------|---------------|
| **PHP Files** | 467 | ~172,893 |
| **JavaScript Files** | 23 | ~20,330 |
| **CSS Files** | 3 | ~500 |
| **SQL Files** | 8 | ~1,808 |
| **Markdown Docs** | 65 | ~5,000 |
| **Total** | **566+** | **~200,000+** |

### 📁 Directory Structure

```
clinicya/
├── 📂 api/                    (49 files)  - REST API Endpoints
├── 📂 admin/                  (3 files)   - Admin Setup Pages
├── 📂 assets/                            - Static Assets
│   ├── css/                   (3 files)
│   └── js/                    (23 files)
├── 📂 auth/                   (4 files)   - Authentication
├── 📂 classes/                (45 files)  - PHP Service Classes
├── 📂 config/                 (6 files)   - Configuration
├── 📂 cron/                   (14 files)  - Scheduled Jobs
├── 📂 database/               (8 files)   - SQL Migrations
├── 📂 docs/                              - Documentation
├── 📂 includes/               (74 files)  - Tab Components
│   ├── accounting/            (4 files)
│   ├── analytics/             (3 files)
│   ├── inventory/             (15 files)
│   ├── pharmacy/              (4 files)
│   ├── procurement/           (3 files)
│   ├── rich-menu/             (3 files)
│   ├── scheduled/             (2 files)
│   ├── settings/              (5 files)
│   └── shop/                  (4 files)
├── 📂 install/                (38 files)  - Migration Scripts
├── 📂 inventory/              (10 files)  - Inventory Pages
├── 📂 liff/                   (1 file)    - LIFF SPA Entry
├── 📂 modules/                (25 files)  - Feature Modules
│   └── AIChat/                           - AI Pharmacy Module
├── 📂 shop/                   (15 files)  - Shop Pages
├── 📂 tests/                  (42 files)  - PHPUnit Tests
├── 📂 user/                              - User Pages
├── 📂 vendor/                            - Composer Dependencies
└── 📄 *.php                   (50+ files) - Root Pages
```

### 🏗️ Architecture Pattern

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION LAYER                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐    │
│  │  Admin Panel │  │  LIFF Apps   │  │  LINE Chat   │  │   API        │    │
│  │  (PHP/HTML)  │  │  (Vue.js)    │  │  (Webhook)   │  │  (REST)      │    │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘    │
│                                                                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                           BUSINESS LOGIC LAYER                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                        classes/ (Service Classes)                    │   │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐   │   │
│  │  │ WMSService  │ │ BatchService│ │ LineAPI     │ │ AIAdapter   │   │   │
│  │  │ PutAway     │ │ Location    │ │ Accounting  │ │ Triage      │   │   │
│  │  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                        modules/ (Feature Modules)                    │   │
│  │  ┌─────────────────────────────────────────────────────────────┐   │   │
│  │  │  AIChat Module (Adapters, Services, Engines)                │   │   │
│  │  └─────────────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                           DATA ACCESS LAYER                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                      │
│  │   Database   │  │  LINE API    │  │  OpenAI API  │                      │
│  │   (MySQL)    │  │  (Messaging) │  │  (AI/Chat)   │                      │
│  └──────────────┘  └──────────────┘  └──────────────┘                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 🔧 Technology Stack

| Layer | Technology |
|-------|------------|
| **Frontend** | HTML5, Tailwind CSS, Alpine.js, Vue.js (LIFF) |
| **Backend** | PHP 8.x |
| **Database** | MySQL 8.x / MariaDB |
| **API** | REST JSON |
| **Messaging** | LINE Messaging API, LINE LIFF |
| **AI** | OpenAI GPT-4 |
| **Testing** | PHPUnit |
| **Server** | Apache/Nginx |

### 📦 Key Service Classes

| Class | Purpose | Lines |
|-------|---------|-------|
| `WMSService.php` | WMS Pick/Pack/Ship Logic | ~800 |
| `BatchService.php` | Batch/Lot Management | ~400 |
| `LocationService.php` | Location Management | ~350 |
| `PutAwayService.php` | Put-Away Logic | ~300 |
| `LineAPI.php` | LINE API Integration | ~900 |
| `AccountingDashboardService.php` | Accounting Dashboard | ~500 |
| `PharmacyAIAdapter.php` | AI Pharmacy Integration | ~900 |
| `TriageEngine.php` | AI Triage Assessment | ~1,800 |

### 🗄️ Database Tables Count

| Category | Tables |
|----------|--------|
| Products & Inventory | 8 |
| Procurement | 5 |
| Sales & Orders | 4 |
| WMS | 3 |
| CRM & Users | 6 |
| Pharmacy | 4 |
| Accounting | 6 |
| Settings & Config | 5 |
| **Total** | **~41 tables** |

### 🔌 External Integrations

| Service | Purpose |
|---------|---------|
| LINE Messaging API | Chat, Push Messages |
| LINE LIFF | In-app Web Views |
| LINE Login | Authentication |
| OpenAI API | AI Chatbot, Triage |
| MIMS Database | Drug Information |

---

**Total Codebase:** ~200,000+ lines across 566+ files  
**Architecture:** MVC-like with Service Layer  
**Last Updated:** January 2026
