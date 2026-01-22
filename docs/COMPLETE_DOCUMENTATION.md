# 🏥 LINE Telepharmacy CRM Platform - Complete Documentation

> **Version 3.2** | **Last Updated**: January 2026  
> Complete reference guide for developers, administrators, and stakeholders

---

## 📑 Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Architecture](#2-system-architecture)
3. [Technology Stack](#3-technology-stack)
4. [Database Schema](#4-database-schema)
5. [API Reference](#5-api-reference)
6. [Core Features](#6-core-features)
7. [Module Documentation](#7-module-documentation)
8. [Installation Guide](#8-installation-guide)
9. [Configuration](#9-configuration)
10. [Security & Compliance](#10-security--compliance)
11. [Deployment](#11-deployment)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Executive Summary

### 1.1 Overview

**LINE Telepharmacy CRM** is a comprehensive multi-tenant platform designed for pharmacy businesses in Thailand. It integrates with LINE Messaging API to provide a complete Customer Relationship Management system with e-commerce, AI-powered consultations, loyalty programs, and telepharmacy services.

### 1.2 Key Capabilities

| Category | Features |
|----------|----------|
| **CRM & Communication** | Multi-bot LINE OA management, Real-time chat inbox, Broadcast & scheduled messages, Auto-reply rules, Drip campaigns, Rich Menu management |
| **E-commerce** | Product catalog, Shopping cart & checkout, Order management, Payment verification, Inventory tracking, POS system |
| **Loyalty Program** | Points earning rules, Tier-based membership (Bronze/Silver/Gold/Platinum), Rewards redemption, Points expiration, Birthday rewards |
| **AI Assistant** | Pharmacy AI (Gemini), Symptom assessment, Drug interaction check, Health profile integration, Red flag detection |
| **Telepharmacy** | Pharmacist profiles, Video call appointments, Consultation notes, Prescription management, Medication reminders |
| **Analytics** | Customer analytics, Sales reports, Campaign performance, Executive dashboard |

### 1.3 Target Users

- **Pharmacies** - Independent and chain pharmacies
- **Clinics** - Medical clinics with pharmacy services
- **Healthcare Businesses** - Health & wellness retailers
- **E-commerce Sellers** - Online health product sellers

---

## 2. System Architecture

### 2.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     LINE Telepharmacy CRM Platform                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                   │
│  │   LINE App   │    │  Web Browser │    │  Admin Panel │                   │
│  │   (LIFF)     │    │  (Landing)   │    │  (Backend)   │                   │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘                   │
│         │                   │                   │                            │
│         ▼                   ▼                   ▼                            │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                        Entry Points                                  │    │
│  │  /liff/index.php   │   /index.php    │   /admin/    │  /webhook.php │    │
│  │  (LIFF SPA)        │   (Landing)     │   (Admin)    │  (LINE Hook)  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                          API Layer (/api/)                           │    │
│  │  checkout │ member │ orders │ ai-chat │ pharmacy-ai │ inbox-v2 │... │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Service Classes (/classes/)                       │    │
│  │  LineAPI │ LoyaltyPoints │ GeminiAI │ WMSService │ POSService │...  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                       Database (MySQL/MariaDB)                       │    │
│  │                    228 Tables | UTF8MB4 Encoding                     │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Directory Structure

```
/
├── api/                       # REST API endpoints (61 files)
│   ├── checkout.php           # E-commerce checkout
│   ├── member.php             # Member management
│   ├── orders.php             # Order management
│   ├── ai-chat.php            # AI assistant
│   ├── pharmacy-ai.php        # Pharmacy AI engine
│   ├── inbox-v2.php           # Chat inbox API
│   ├── points.php             # Loyalty points
│   ├── rewards.php            # Rewards system
│   ├── wms.php                # Warehouse management
│   └── ...
│
├── classes/                   # Service classes (75 files)
│   ├── LineAPI.php            # LINE Messaging API wrapper
│   ├── LoyaltyPoints.php      # Points & rewards management
│   ├── GeminiAI.php           # Google Gemini integration
│   ├── BusinessBot.php        # Main business logic (172KB)
│   ├── WMSService.php         # Warehouse management
│   ├── POSService.php         # Point of sale
│   ├── InboxService.php       # Chat inbox operations
│   ├── NotificationService.php # Push notifications
│   ├── TierService.php        # Membership tiers
│   ├── FlexTemplates.php      # LINE Flex message templates
│   └── ...
│
├── config/                    # Configuration
│   ├── config.php             # Main configuration
│   ├── database.php           # Database connection
│   └── installed.lock         # Installation marker
│
├── cron/                      # Scheduled tasks (14 files)
│   ├── medication_reminder.php
│   ├── appointment_reminder.php
│   ├── process_broadcast_queue.php
│   ├── process_drip_campaigns.php
│   ├── scheduled_reports.php
│   └── ...
│
├── database/                  # SQL files (36 files)
│   ├── install_complete_latest.sql  # Full schema (228 tables)
│   ├── migration_*.sql              # Incremental migrations
│   └── ...
│
├── includes/                  # Shared includes (97 files)
│   ├── header.php
│   ├── footer.php
│   ├── auth.php
│   └── ...
│
├── install/                   # Installation wizard (137 files)
│   ├── wizard.php             # 7-step installer
│   └── wizard-api.php
│
├── liff/                      # LIFF SPA Application
│   ├── index.php              # SPA entry point
│   └── assets/
│       ├── css/liff-app.css
│       └── js/
│           ├── store.js       # State management
│           ├── router.js      # Client-side routing
│           └── liff-app.js    # Main controller
│
├── modules/                   # Feature modules
│   ├── AIChat/                # AI chat module (19 files)
│   ├── Onboarding/            # User onboarding (5 files)
│   └── Core/                  # Core utilities
│
├── admin/                     # Admin panel pages
├── shop/                      # Shop management (15 files)
├── inventory/                 # Inventory management (10 files)
├── user/                      # User panel pages (16 files)
├── docs/                      # Documentation (31 files)
│
├── index.php                  # Landing page
├── webhook.php                # LINE webhook handler (220KB)
├── inbox-v2.php               # Chat inbox UI (520KB)
├── websocket-server.js        # Real-time chat server
└── ...
```

---

## 3. Technology Stack

| Layer | Technology | Details |
|-------|------------|---------|
| **Frontend** | HTML5, CSS3, JavaScript (ES6+) | LIFF SDK integration |
| **Backend** | PHP 8.0+ | Object-oriented architecture |
| **Database** | MySQL 5.7+ / MariaDB 10.2+ | UTF8MB4 encoding, 228 tables |
| **AI** | Google Gemini AI | Primary AI engine |
| **AI Fallback** | OpenAI GPT | Optional alternative |
| **Messaging** | LINE Messaging API | Push, Reply, Broadcast |
| **LIFF** | LINE Front-end Framework | In-app web experience |
| **Real-time** | WebSocket (Node.js) | Live chat updates |
| **Notifications** | Telegram Bot API | Admin alerts |
| **Package Manager** | Composer | PHP dependencies |

### 3.1 Required PHP Extensions

- PDO, PDO_MySQL
- cURL
- JSON
- mbstring
- OpenSSL
- GD (for image processing)

---

## 4. Database Schema

### 4.1 Overview

The system contains **228 database tables** organized into functional groups:

### 4.2 Core Tables

| Table | Description |
|-------|-------------|
| `line_accounts` | LINE OA configurations (multi-bot support) |
| `admin_users` | System administrators and staff |
| `users` | LINE users/customers |
| `members` | Extended member profiles |
| `health_profiles` | User health information |

### 4.3 Messaging Tables

| Table | Description |
|-------|-------------|
| `messages` | Chat history (all conversations) |
| `conversation_assignments` | Multi-assignee support |
| `user_notes` | Internal staff notes |
| `message_templates` | Quick reply templates |
| `auto_replies` | Auto-reply rules |
| `auto_reply_rules` | Extended auto-reply conditions |

### 4.4 E-commerce Tables

| Table | Description |
|-------|-------------|
| `business_items` / `products` | Product catalog |
| `business_categories` | Product categories |
| `orders` | Customer orders |
| `order_items` | Order line items |
| `cart_items` | Shopping cart |
| `transactions` | Payment transactions |
| `coupons` | Discount coupons |

### 4.5 Loyalty System Tables

| Table | Description |
|-------|-------------|
| `points_transactions` | Points history |
| `points_rules` | Earning rules |
| `rewards` | Redeemable rewards |
| `redemptions` | Redemption records |
| `user_tiers` | Member tier tracking |
| `tier_settings` | Tier configurations |

### 4.6 AI & Pharmacy Tables

| Table | Description |
|-------|-------------|
| `ai_chat_settings` | AI configuration |
| `ai_conversation_history` | AI chat logs |
| `ai_triage_assessments` | Symptom assessments |
| `ai_pharmacy_settings` | Pharmacy AI config |
| `pharmacists` | Pharmacist profiles |
| `appointments` | Consultation bookings |
| `prescriptions` | Prescription records |
| `drug_interactions` | Drug interaction database |

### 4.7 Inventory & WMS Tables

| Table | Description |
|-------|-------------|
| `inventory_locations` | Storage locations |
| `inventory_batches` | Batch/lot tracking |
| `stock_movements` | Stock transactions |
| `goods_receipts` | Goods receiving |
| `put_away_tasks` | Put away operations |
| `suppliers` | Supplier management |
| `purchase_orders` | PO management |

### 4.8 Broadcast & Campaign Tables

| Table | Description |
|-------|-------------|
| `broadcasts` | Broadcast messages |
| `broadcast_campaigns` | Campaign management |
| `broadcast_queue` | Sending queue |
| `drip_campaigns` | Drip campaign sequences |
| `drip_campaign_steps` | Campaign steps |
| `drip_campaign_enrollments` | User enrollments |

### 4.9 Analytics Tables

| Table | Description |
|-------|-------------|
| `analytics` | Event tracking |
| `account_daily_stats` | Daily statistics |
| `account_events` | User events |
| `activity_logs` | System activity logs |
| `admin_activity_log` | Admin actions |

---

## 5. API Reference

### 5.1 API Conventions

- **Base URL**: `/api/`
- **Format**: JSON
- **Authentication**: Session-based or LINE ID Token
- **Response Structure**:

```json
{
    "success": true,
    "data": { ... },
    "message": "Operation completed"
}
```

### 5.2 API Endpoints Summary

#### Member APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/member.php?action=register` | POST | Register new member |
| `/api/member.php?action=profile` | GET | Get member profile |
| `/api/member.php?action=update` | POST | Update profile |
| `/api/health-profile.php` | GET/POST | Health profile management |

#### E-commerce APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/shop-products.php?action=list` | GET | List products |
| `/api/shop-products.php?action=detail` | GET | Product details |
| `/api/checkout.php?action=add_to_cart` | POST | Add to cart |
| `/api/checkout.php?action=get_cart` | GET | Get cart |
| `/api/checkout.php?action=create_order` | POST | Create order |
| `/api/orders.php` | GET/POST | Order management |

#### Points & Rewards APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/points.php?action=balance` | GET | Get points balance |
| `/api/points.php?action=history` | GET | Points history |
| `/api/rewards.php?action=list` | GET | Available rewards |
| `/api/rewards.php?action=redeem` | POST | Redeem reward |

#### AI APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/ai-chat.php` | POST | AI chat message |
| `/api/pharmacy-ai.php` | POST | Pharmacy AI assistant |
| `/api/symptom-assessment.php` | POST | Symptom triage |
| `/api/drug-interactions.php` | POST | Drug interaction check |

#### Communication APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/inbox-v2.php` | GET/POST | Inbox operations |
| `/api/messages.php` | GET/POST | Message management |
| `/api/broadcast.php` | POST | Send broadcasts |

#### Pharmacy APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/pharmacist.php` | GET/POST | Pharmacist profiles |
| `/api/appointments.php` | GET/POST | Appointment booking |
| `/api/video-call.php` | GET/POST | Video call management |
| `/api/prescription-approval.php` | POST | Prescription approval |

#### Inventory APIs
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/inventory.php` | GET/POST | Inventory management |
| `/api/wms.php` | GET/POST | Warehouse operations |
| `/api/batches.php` | GET/POST | Batch tracking |
| `/api/locations.php` | GET/POST | Location management |

---

## 6. Core Features

### 6.1 Multi-Bot LINE OA Management

The system supports managing multiple LINE Official Accounts from a single dashboard:

```
Admin Dashboard
    │
    ├── LINE Account 1 (Pharmacy A)
    │   ├── Users, Messages, Orders
    │   └── Settings, Rich Menu
    │
    ├── LINE Account 2 (Pharmacy B)
    │   └── ...
    │
    └── LINE Account N
```

**Key Classes**:
- `LineAPI.php` - LINE Messaging API wrapper
- `LineAccountManager.php` - Multi-account management
- `DynamicRichMenu.php` - Rich menu assignment

### 6.2 Real-time Chat Inbox

**File**: `inbox-v2.php` (520KB)

Features:
- Virtual scrolling for performance
- Multi-assignee support
- Message templates
- Quick replies
- Media preview (images, videos, stickers)
- Keyboard navigation
- Offline support

**API**: `/api/inbox-v2.php`

### 6.3 Loyalty Points System

**Class**: `LoyaltyPoints.php`

Features:
- Configurable earning rules
- Tier-based multipliers
- Points expiration
- Birthday bonus
- Manual adjustments
- Points import

Tiers:
| Tier | Min Points | Multiplier |
|------|------------|------------|
| Bronze | 0 | 1.0x |
| Silver | 500 | 1.25x |
| Gold | 2,000 | 1.5x |
| Platinum | 5,000 | 2.0x |

### 6.4 AI Pharmacy Assistant

**Classes**: 
- `GeminiAI.php` - AI engine
- `PharmacyIntegrationService.php` - Pharmacy AI
- `DrugRecommendEngineService.php` - Drug recommendations

Features:
- Symptom triage
- Drug information
- Interaction checking
- Allergy warnings
- Red flag detection
- Product recommendations

### 6.5 E-commerce & POS

**Classes**:
- `ShopBot.php` - E-commerce logic
- `POSService.php` - Point of sale
- `UnifiedShop.php` - Unified shopping

Features:
- Product management
- Cart & checkout
- Order tracking
- Payment verification
- Inventory sync
- POS integration

---

## 7. Module Documentation

### 7.1 AIChat Module

**Location**: `/modules/AIChat/` (19 files)

Components:
- Chat interface
- AI model integration
- Context management
- Response formatting

### 7.2 Onboarding Module

**Location**: `/modules/Onboarding/` (5 files)

Features:
- New user welcome flow
- Profile completion
- Feature introduction
- Setup wizard

### 7.3 Warehouse Management (WMS)

**Classes**:
- `WMSService.php` (95KB)
- `WMSPrintService.php` (48KB)
- `BatchService.php`
- `LocationService.php`
- `PutAwayService.php`

Features:
- Multi-location inventory
- Batch/lot tracking
- Expiry management
- Put away operations
- Stock movements
- Barcode printing

### 7.4 Accounting Module

**Classes**:
- `AccountPayableService.php`
- `AccountReceivableService.php`
- `AccountingDashboardService.php`
- `ExpenseService.php`

Features:
- Accounts Payable (AP)
- Accounts Receivable (AR)
- Expense tracking
- Payment vouchers
- Receipt vouchers
- Aging reports

---

## 8. Installation Guide

### 8.1 Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.0+ |
| MySQL | 5.7+ |
| MariaDB | 10.2+ |
| HTTPS | Required for webhook |

### 8.2 Installation Wizard (Recommended)

1. Upload files to your web server
2. Navigate to: `https://yourdomain.com/install/wizard.php`
3. Complete the 7-step wizard:
   - Welcome
   - System requirements check
   - Database configuration
   - Application settings
   - LINE API configuration
   - Admin account creation
   - Installation complete
4. Delete the `install/` folder after installation

### 8.3 Manual Installation

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p telepharmacy < database/install_complete_latest.sql

# 3. Copy config
cp config/config.example.php config/config.php

# 4. Edit config.php with your settings
nano config/config.php

# 5. Install PHP dependencies
composer install

# 6. Create admin user (in MySQL)
INSERT INTO admin_users (username, email, password, role, is_active) 
VALUES ('admin', 'admin@example.com', '$2y$10$...', 'super_admin', 1);
```

---

## 9. Configuration

### 9.1 Environment Configuration

**File**: `.env.example`

```env
# Database
DB_HOST=localhost
DB_NAME=telepharmacy
DB_USER=root
DB_PASS=password

# Application
APP_URL=https://yourdomain.com
APP_DEBUG=false

# LINE Messaging API
LINE_CHANNEL_ID=
LINE_CHANNEL_SECRET=
LINE_CHANNEL_ACCESS_TOKEN=

# LIFF
LIFF_ID=

# AI
GEMINI_API_KEY=
OPENAI_API_KEY=

# Telegram (Admin Notifications)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

### 9.2 LINE Messaging API Setup

1. Go to [LINE Developers Console](https://developers.line.biz/console/)
2. Create a **Messaging API** channel
3. Get **Channel Secret** and **Channel Access Token**
4. Set **Webhook URL**: `https://yourdomain.com/webhook.php?account=1`
5. Enable **Use webhook**, disable **Auto-reply**

### 9.3 LIFF Apps Setup

Create LIFF apps for:
| Type | Size | URL |
|------|------|-----|
| Main App | Full | `/liff/` |
| Share | Tall | `/liff/?page=share` |

### 9.4 Cron Jobs

```bash
# Medication reminders (every 15 min)
*/15 * * * * php /path/to/cron/medication_reminder.php

# Appointment reminders (every 30 min)
*/30 * * * * php /path/to/cron/appointment_reminder.php

# Broadcast queue (every 5 min)
*/5 * * * * php /path/to/cron/process_broadcast_queue.php

# Drip campaigns (every 10 min)
*/10 * * * * php /path/to/cron/process_drip_campaigns.php

# Daily reports (7 AM)
0 7 * * * php /path/to/cron/scheduled_reports.php

# Refill reminders (9 AM)
0 9 * * * php /path/to/cron/medication_refill_reminder.php

# Reward expiry (10 AM)
0 10 * * * php /path/to/cron/reward_expiry_reminder.php

# Sync worker (every minute)
* * * * * php /path/to/cron/sync_worker.php
```

---

## 10. Security & Compliance

### 10.1 Authentication

| Type | Method |
|------|--------|
| Admin | Session-based (PHP sessions) |
| LIFF | LINE ID Token validation |
| API | Token/Session validation |

### 10.2 Security Features

- **Password Hashing**: bcrypt
- **CSRF Protection**: Token-based
- **SQL Injection Prevention**: PDO prepared statements
- **XSS Prevention**: htmlspecialchars
- **HTTPS**: Required for LINE webhook
- **Rate Limiting**: Per-IP and per-user limits

### 10.3 User Roles

| Role | Access |
|------|--------|
| **Super Admin** | Full system access |
| **Admin** | All features except system settings |
| **Pharmacist** | Consultations, prescriptions, video calls |
| **Staff** | Chat, orders, basic operations |
| **User** | Own LINE account only |

### 10.4 Data Privacy

- Health data encryption
- PDPA compliance support
- Consent management
- Data retention policies
- Audit logging

---

## 11. Deployment

### 11.1 Server Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| CPU | 2 cores | 4+ cores |
| RAM | 4 GB | 8+ GB |
| Storage | 20 GB | 100+ GB (SSD) |
| PHP Workers | 4 | 16+ |

### 11.2 Performance Optimization

- Database indexes on frequently queried columns
- Lazy loading for large datasets
- Caching for static content
- Async processing for broadcasts
- Virtual scrolling for large lists

### 11.3 WebSocket Server

**File**: `websocket-server.js`

```bash
# Install dependencies
npm install

# Start WebSocket server
node websocket-server.js

# Or use PM2 for production
pm2 start websocket-server.js --name "crm-websocket"
```

---

## 12. Troubleshooting

### 12.1 Common Issues

#### Webhook not working
- Ensure URL is HTTPS
- Verify Channel Secret is correct
- Check webhook.php permissions
- Check server error logs

#### Cannot send messages
- Check Channel Access Token
- Verify token hasn't expired
- Test connection in LINE Accounts settings

#### Upload issues
- Check `uploads/` permissions (755)
- Verify `upload_max_filesize` in php.ini
- Check `post_max_size` setting

#### AI not responding
- Verify Gemini API key
- Check API quota limits
- Review error logs

### 12.2 Debug Mode

Enable debug mode in `config/config.php`:

```php
define('APP_DEBUG', true);
```

### 12.3 System Status

Access system status page: `/system-status.php`

---

## Appendices

### A. File Size Reference

| File | Size | Purpose |
|------|------|---------|
| `webhook.php` | 220 KB | LINE event handling |
| `inbox-v2.php` | 520 KB | Chat inbox UI |
| `BusinessBot.php` | 172 KB | Main business logic |
| `WMSService.php` | 95 KB | Warehouse management |
| `FlexTemplates.php` | 86 KB | LINE Flex templates |
| `pharmacy-ai.php` | 121 KB | Pharmacy AI API |

### B. Related Documentation

> **หมายเหตุ**: เอกสารเหล่านี้อยู่ในโฟลเดอร์โปรเจคหลัก

| ไฟล์ | คำอธิบาย | ตำแหน่ง |
|------|----------|---------|
| `ARCHITECTURE.md` | System architecture | `/ARCHITECTURE.md` |
| `CRM_WORKFLOW_COMPLETE.md` | Detailed workflows | `/CRM_WORKFLOW_COMPLETE.md` |
| `USER_MANUAL.md` | End-user documentation | `/USER_MANUAL.md` |
| `SETUP_GUIDE_COMPLETE.md` | Detailed setup guide | `/SETUP_GUIDE_COMPLETE.md` |
| `SAAS_ARCHITECTURE.md` | Multi-tenant architecture | `/docs/SAAS_ARCHITECTURE.md` |

### C. Contact & Support

For technical support and feature requests, please refer to the project repository.

---

*Made with ❤️ for LINE Telepharmacy Management*
