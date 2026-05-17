# Documentation Index — LINE Telepharmacy CRM

> Quick access to all documentation for the telepharmacy platform

---

## 📚 Core Documentation

### System Overview

| Document | Description | Status |
|----------|-------------|--------|
| **[README](README.md)** | Project overview and quick start | ✅ Current |
| **[Architecture](ARCHITECTURE.md)** | System design and components | ✅ Current (2026-05-17) |
| **[Master User Manual](MASTER_USER_MANUAL.md)** | Complete user guide | ✅ Current |

### Admin Guides

| Document | Description | Status |
|----------|-------------|--------|
| **[Admin User Guide](ADMIN_USER_GUIDE.md)** | Admin panel walkthrough for beginners | ✅ Current |
| **[Customer User Guide](CUSTOMER_USER_GUIDE.md)** | Customer/user features guide | ✅ Current |
| **[Webhook Management](WEBHOOK_MANAGEMENT_SYSTEM.md)** | Webhook logging and monitoring | ✅ Current (2026-05-17) |

### Technical Documentation

| Document | Description | Status |
|----------|-------------|--------|
| **[API Documentation](API_CUSTOMER_MANAGEMENT.md)** | Customer management API endpoints | ✅ Current |
| **[Audit Logging](AUDIT_LOGGING.md)** | System audit and activity logs | ✅ Current |
| **[Deployment Guide (Thai)](DEPLOYMENT_GUIDE_TH.md)** | Production deployment steps | ✅ Current (2026-05-17) |

---

## 🎯 Key Features

### Pharmacy Operations
- **Dispensing System** — Track medication dispensing and auto-refill reminders
- **Refill Tracking** — 3-day threshold check, daily 9:00 AM reminder cron job
- **Video Consultations** — WebRTC-enabled pharmacist video calls

### E-commerce & Shop
- **LINE Mini App** — Next.js 15 shop UI with cart, checkout, orders
- **Product Management** — Catalog, inventory, business_items unified
- **Loyalty Program** — Points earning, tier membership, redemptions

### CRM & Communication
- **Multi-Account LINE OA** — Support multiple LINE Official Accounts
- **Real-time Chat Inbox** — 3-pane inbox with 200+ conversation pagination
- **Broadcast & Drip Campaigns** — Scheduled messaging
- **AI Assistant** — Gemini/OpenAI symptom assessment

### Admin Features
- **Onboarding Wizard** — 7-step setup (shop, LINE, products, pharmacist)
- **Help Hub** — Task-based tutorial system
- **Admin Tour** — Shepherd-style guided overlay
- **Dashboard** — Analytics, sales, customer segments

---

## 📖 Getting Started

### For New Admins
1. Read [Admin User Guide](ADMIN_USER_GUIDE.md) intro section
2. Complete [Onboarding Wizard](/onboarding/wizard.php)
3. Explore [Help Hub](/help/) for task-based tutorials

### For Developers
1. Review [Architecture](ARCHITECTURE.md) for system design
2. Check [API Documentation](API_CUSTOMER_MANAGEMENT.md) for endpoints
3. Follow [Deployment Guide](DEPLOYMENT_GUIDE_TH.md) for setup

### For Shop Owners
1. Read [Customer User Guide](CUSTOMER_USER_GUIDE.md)
2. Use LINE Mini App at `/miniapp/` for shopping
3. Check order history in customer profile

---

## 🔗 Admin Tutorials

Detailed step-by-step guides in `docs/admin-tutorials/`:
- `01-dispense.md` — Medication dispensing workflow
- `02-video-call.md` — Pharmacist video consultations
- `03-product.md` — Product management and inventory
- `04-chat-tag.md` — Chat tagging and automation
- `05-broadcast.md` — Broadcast campaigns
- `06-membership.md` — Membership tier system
- `07-refill.md` — Refill tracking workflow

---

## 📸 Visual Resources

Screenshots organized by feature in `docs/screenshots/`:
- `00-landing/` — Public landing page
- `01-miniapp/` — LINE Mini App shop UI
- `02-inbox/` — Admin chat inbox
- `03-dashboard/` — Analytics & reporting
- `04-ai/` — AI chat and settings
- `05-marketing/` — Broadcast, campaigns
- `06-sales/` — Orders, inventory, POS
- `08-telepharmacy/` — Video calls, consultations
- `2026-05-17-onboarding/` — Latest onboarding flow

---

## 📝 Version Info

**Last Updated**: May 17, 2026  
**Version**: 3.0  
**Status**: Production ✅

---

*For questions, check the relevant admin tutorial or contact support.*
