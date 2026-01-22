# 🏥 LINE Telepharmacy CRM Platform

ระบบจัดการร้านขายยาและ LINE Official Account แบบครบวงจร

![Version](https://img.shields.io/badge/version-3.2-green)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-blue)
![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-orange)
![License](https://img.shields.io/badge/license-MIT-purple)

---

## ✨ Features

### 💬 CRM & Communication
- Multi-bot LINE OA management
- Real-time chat inbox with multi-assignee
- Broadcast & scheduled messages
- Auto-reply rules with priority
- Drip campaigns
- Rich Menu management

### 🛒 E-commerce
- Product catalog management
- Shopping cart & checkout
- Order management
- Payment verification
- Inventory tracking

### 🎯 Loyalty Program
- Points earning rules
- Tier-based membership
- Rewards redemption
- Points expiration
- Birthday rewards

### 🤖 AI Assistant
- Pharmacy AI (Gemini)
- Symptom assessment
- Drug interaction check
- Health profile integration
- Red flag detection

### 🏥 Telepharmacy
- Pharmacist profiles
- Video call appointments
- Consultation notes
- Prescription management
- Medication reminders

### 📊 Analytics & Reports
- Customer analytics
- Sales reports
- Campaign performance
- Executive dashboard

---

## 📋 Requirements

- **PHP** >= 8.0
- **MySQL** >= 5.7 or MariaDB >= 10.2
- **Extensions**: PDO, PDO_MySQL, cURL, JSON, mbstring, OpenSSL
- **HTTPS** (required for LINE Webhook)

---

## 🚀 Quick Start

### Option 1: Installation Wizard (Recommended)

1. **Upload files** to your web server
2. **Open browser** and navigate to:
   ```
   https://yourdomain.com/install/wizard.php
   ```
3. **Follow the 7-step wizard**:
   - Welcome
   - System requirements check
   - Database configuration
   - Application settings
   - LINE API configuration
   - Admin account creation
   - Installation

4. **Delete** the `install/` folder after installation

### Option 2: Manual Installation

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p telepharmacy < database/install_complete_latest.sql

# 3. Copy config
cp config/config.example.php config/config.php

# 4. Edit config.php with your settings
nano config/config.php

# 5. Create admin user (in MySQL)
INSERT INTO admin_users (username, email, password, role, is_active) 
VALUES ('admin', 'admin@example.com', '$2y$10$...', 'super_admin', 1);
```

---

## ⚙️ Configuration

### LINE Messaging API

1. Go to [LINE Developers Console](https://developers.line.biz/console/)
2. Create a **Messaging API** channel
3. Get **Channel Secret** and **Channel Access Token**
4. Set **Webhook URL**:
   ```
   https://yourdomain.com/webhook.php?account=1
   ```
5. Enable **Use webhook**, disable **Auto-reply**

### LIFF Apps

Create LIFF apps for:
- Main app (full mode, `/liff/`)
- Share (tall mode, `/liff/?page=share`)

### AI Configuration (Optional)

Set up in Admin > AI Settings:
- **Gemini API Key**: Get from [Google AI Studio](https://aistudio.google.com/)
- **OpenAI API Key**: Get from [OpenAI Platform](https://platform.openai.com/)

---

## 📁 Directory Structure

```
├── api/              # REST API endpoints
├── classes/          # Service classes
├── config/           # Configuration files
├── cron/             # Scheduled tasks
├── database/         # SQL migrations
├── includes/         # Shared includes
├── install/          # Installation wizard
├── liff/             # LIFF SPA application
├── admin/            # Admin panel
├── shop/             # Shop management
├── index.php         # Landing page
├── webhook.php       # LINE webhook
└── inbox-v2.php      # Chat inbox
```

---

## 📱 User Roles

| Role | Access |
|------|--------|
| **Super Admin** | Full system access |
| **Admin** | All features except system settings |
| **Pharmacist** | Consultations, prescriptions |
| **Staff** | Chat, orders |
| **User** | Own LINE account only |

---

## 🔧 Cron Jobs

```bash
# Medication reminders (every 15 min)
*/15 * * * * php /path/to/cron/medication_reminder.php

# Appointment reminders (every 30 min)
*/30 * * * * php /path/to/cron/appointment_reminder.php

# Broadcast queue (every 5 min)
*/5 * * * * php /path/to/cron/process_broadcast_queue.php
```

---

## 🛠️ Troubleshooting

### Webhook not working
- Ensure URL is HTTPS
- Verify Channel Secret is correct
- Check webhook.php permissions

### Cannot send messages
- Check Channel Access Token
- Verify token hasn't expired
- Test connection in LINE Accounts

### Upload issues
- Check `uploads/` permissions (755)
- Verify `upload_max_filesize` in php.ini

---

## 📖 Documentation

- [Architecture](ARCHITECTURE.md)
- [Project Flow](PROJECT_FLOW_DOCUMENTATION.md)
- [CRM Workflow](CRM_WORKFLOW_COMPLETE.md)
- [User Manual](USER_MANUAL.md)
- [Setup Guide](SETUP_GUIDE_COMPLETE.md)

---

## 📄 License

MIT License - Free for personal and commercial use.

---

## 🤝 Support

For issues and feature requests, please create an Issue in the repository.

---

Made with ❤️ for LINE Telepharmacy Management
