# Requirements Document

## Introduction

ลดความซ้ำซ้อนของไฟล์และฟีเจอร์ในโปรเจค Pharmacy LINE CRM โดยการรวมไฟล์ที่มีฟังก์ชันคล้ายกัน ลบไฟล์ที่ไม่ใช้งาน และปรับปรุงโครงสร้างให้ใช้งานง่ายขึ้น 

**เป้าหมาย:**
- ลดจำนวนไฟล์ PHP จาก ~100 ไฟล์เหลือ ~60 ไฟล์
- ลดความซับซ้อนของเมนู
- ทำให้โค้ดดูแลรักษาง่ายขึ้น
- ปรับปรุง UX ให้ผู้ใช้ไม่สับสน

## Glossary

- **File Consolidation**: การรวมไฟล์หลายไฟล์ที่มีฟังก์ชันคล้ายกันเป็นไฟล์เดียว
- **Duplicate File**: ไฟล์ที่มีโค้ดเกือบเหมือนกัน หรือเป็นเวอร์ชันเก่า/ใหม่ของไฟล์เดียวกัน
- **Tab-based UI**: การรวมหลายหน้าเป็นหน้าเดียวโดยใช้ tabs แยกส่วน
- **Redirect**: การเปลี่ยนเส้นทางจาก URL เก่าไปยัง URL ใหม่
- **Admin Panel**: หน้าจัดการระบบสำหรับ Admin (ใช้ includes/header.php)
- **User Panel**: หน้าจัดการสำหรับ User ทั่วไป (ใช้ includes/user_header.php)
- **LIFF**: LINE Front-end Framework สำหรับหน้าลูกค้า

---

## Requirements

### Requirement 1: ลบไฟล์ที่ซ้ำกัน 100% (Priority 1)

**User Story:** As a developer, I want to remove duplicate files, so that the codebase is cleaner and easier to maintain.

#### Acceptance Criteria

1. WHEN the system contains `users.php` and `users_new.php` THEN the system SHALL keep only `users.php` and remove `users_new.php`
2. WHEN the system contains `shop/orders.php` and `shop/orders_new.php` THEN the system SHALL keep only `shop/orders.php` and remove `shop/orders_new.php`
3. WHEN the system contains `shop/order-detail.php` and `shop/order-detail-new.php` THEN the system SHALL keep only `shop/order-detail.php` and remove `shop/order-detail-new.php`
4. WHEN the system contains test files `t.php` and `test.php` THEN the system SHALL remove both test files

---

### Requirement 2: รวมไฟล์เวอร์ชัน (Priority 2)

**User Story:** As a developer, I want to consolidate versioned files into single files, so that there is only one version of each feature.

#### Acceptance Criteria

1. WHEN the system contains `broadcast-catalog.php` and `broadcast-catalog-v2.php` THEN the system SHALL keep `broadcast-catalog-v2.php` and rename it to `broadcast-catalog.php`
2. WHEN the system contains `flex-builder.php` and `flex-builder-v2.php` THEN the system SHALL keep `flex-builder-v2.php` and rename it to `flex-builder.php`
3. WHEN the system contains `liff-shop.php` and `liff-shop-v3.php` THEN the system SHALL keep `liff-shop-v3.php` and rename it to `liff-shop.php`
4. WHEN the system contains multiple video-call files THEN the system SHALL keep `video-call-pro.php` and rename it to `video-call.php`
5. WHEN the system contains `messages.php` and `messages-v2.php` THEN the system SHALL merge AI features from `messages-v2.php` into `messages.php`

---

### Requirement 3: รวมหน้า Analytics (Priority 3)

**User Story:** As an admin user, I want to access all analytics in one unified page with tabs, so that I can view different statistics without navigating to multiple pages.

#### Acceptance Criteria

1. WHEN the user accesses the analytics page THEN the system SHALL display a tab-based interface with sections: Overview, Advanced, CRM, Account
2. WHEN the user clicks on a tab THEN the system SHALL display the corresponding analytics content without page reload
3. WHEN the system contains `analytics.php`, `advanced-analytics.php`, `crm-analytics.php`, and `account-analytics.php` THEN the system SHALL consolidate all into a single `analytics.php` with tabs
4. WHEN the user accesses old URLs like `/advanced-analytics` THEN the system SHALL redirect to `/analytics?tab=advanced`
5. WHEN the analytics page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 4: รวมหน้า AI Chat Settings (Priority 3)

**User Story:** As an admin user, I want to manage all AI chat settings in one page, so that I can configure AI features efficiently.

#### Acceptance Criteria

1. WHEN the user accesses AI settings THEN the system SHALL display a tab-based interface with sections: Chat Settings, Chatbot, Studio
2. WHEN the system contains `ai-chat.php`, `ai-chatbot.php`, and `ai-chat-settings.php` THEN the system SHALL consolidate all into a single `ai-chat.php` with tabs
3. WHEN the user accesses old URLs like `/ai-chatbot` THEN the system SHALL redirect to `/ai-chat?tab=chatbot`
4. WHEN the AI settings page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 5: รวมหน้า LIFF Points (Priority 4)

**User Story:** As a LINE user, I want to access all points-related features in one LIFF page, so that I can manage my points easily.

#### Acceptance Criteria

1. WHEN the user accesses LIFF points page THEN the system SHALL display a tab-based interface with sections: History, Rules, Redeem
2. WHEN the system contains `liff-points-history.php`, `liff-points-rules.php`, and `liff-redeem-points.php` THEN the system SHALL consolidate all into a single `liff-points.php` with tabs
3. WHEN the user accesses old LIFF URLs THEN the system SHALL redirect to the new unified page with appropriate tab
4. WHEN the LIFF points page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 6: รวมหน้า LIFF Video Call (Priority 4)

**User Story:** As a LINE user, I want to access video call features in one LIFF page, so that I can use video consultation easily.

#### Acceptance Criteria

1. WHEN the system contains `liff-video-call.php` and `liff-video-call-pro.php` THEN the system SHALL keep `liff-video-call-pro.php` and rename it to `liff-video-call.php`
2. WHEN the LIFF video call page loads THEN the system SHALL preserve all pro features
3. WHEN the user accesses old LIFF video call URLs THEN the system SHALL redirect to the new unified page

---

### Requirement 7: อัพเดท Menu References

**User Story:** As a developer, I want all menu links to point to correct files after consolidation, so that navigation works correctly.

#### Acceptance Criteria

1. WHEN files are renamed or consolidated THEN the system SHALL update all references in `includes/header.php`
2. WHEN a menu item URL changes THEN the system SHALL update the corresponding URL in the menu configuration
3. WHEN the sidebar renders THEN the system SHALL display correct URLs for all consolidated pages
4. WHEN the user clicks a menu item THEN the system SHALL navigate to the correct consolidated page

---

### Requirement 8: Backward Compatibility

**User Story:** As a user, I want old bookmarked URLs to still work, so that I don't get broken links.

#### Acceptance Criteria

1. WHEN the user accesses an old URL of a removed file THEN the system SHALL redirect to the new consolidated file
2. WHEN the system redirects THEN the system SHALL preserve any query parameters
3. WHEN the redirect occurs THEN the system SHALL use HTTP 301 permanent redirect
4. WHEN the user accesses a consolidated page via old URL THEN the system SHALL display the appropriate tab or section

---

### Requirement 9: ลบโฟลเดอร์ Debug/Test

**User Story:** As a developer, I want to remove debug and test folders from production, so that the codebase is clean.

#### Acceptance Criteria

1. WHEN the system contains `New folder/` directory with debug files THEN the system SHALL archive and remove the directory
2. WHEN the system contains `_archive/debug/` directory THEN the system SHALL verify no active references before removal
3. WHEN debug files are removed THEN the system SHALL ensure no production code depends on them

---

### Requirement 10: รวมหน้า Dashboard (CRM + Executive)

**User Story:** As an admin user, I want to access all dashboard views in one unified page, so that I can switch between different perspectives easily.

#### Acceptance Criteria

1. WHEN the user accesses the dashboard THEN the system SHALL display a tab-based interface with sections: Executive Overview, CRM Dashboard
2. WHEN the system contains `executive-dashboard.php` and `crm-dashboard.php` THEN the system SHALL consolidate both into a single `dashboard.php` with tabs
3. WHEN the user accesses old URLs like `/crm-dashboard` THEN the system SHALL redirect to `/dashboard?tab=crm`
4. WHEN the dashboard page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 11: รวมหน้า User Management (user/ folder)

**User Story:** As a developer, I want to consolidate duplicate user management pages, so that there is only one version of each feature.

#### Acceptance Criteria

1. WHEN the system contains both root-level and `user/` folder versions of the same page THEN the system SHALL keep only one version
2. WHEN `user/analytics.php` duplicates `analytics.php` functionality THEN the system SHALL redirect `user/analytics.php` to the main `analytics.php`
3. WHEN `user/messages.php` duplicates `messages.php` functionality THEN the system SHALL redirect `user/messages.php` to the main `messages.php`
4. WHEN the user accesses a redirected page THEN the system SHALL preserve any query parameters

---

### Requirement 12: รวมหน้า Broadcast

**User Story:** As an admin user, I want to access all broadcast features in one unified page, so that I can manage campaigns efficiently.

#### Acceptance Criteria

1. WHEN the user accesses broadcast page THEN the system SHALL display a tab-based interface with sections: Send, Catalog, Products, Stats
2. WHEN the system contains `broadcast.php`, `broadcast-catalog.php`, `broadcast-products.php`, and `broadcast-stats.php` THEN the system SHALL consolidate all into a single `broadcast.php` with tabs
3. WHEN the user accesses old URLs like `/broadcast-stats` THEN the system SHALL redirect to `/broadcast?tab=stats`
4. WHEN the broadcast page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 13: รวมหน้า Rich Menu

**User Story:** As an admin user, I want to manage all rich menu features in one page, so that I can configure LINE menus efficiently.

#### Acceptance Criteria

1. WHEN the user accesses rich menu page THEN the system SHALL display a tab-based interface with sections: Static Menu, Dynamic Menu, Switch Rules
2. WHEN the system contains `rich-menu.php`, `dynamic-rich-menu.php`, and `rich-menu-switch.php` THEN the system SHALL consolidate all into a single `rich-menu.php` with tabs
3. WHEN the user accesses old URLs like `/dynamic-rich-menu` THEN the system SHALL redirect to `/rich-menu?tab=dynamic`
4. WHEN the rich menu page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 14: รวมหน้า Drip Campaign

**User Story:** As an admin user, I want to manage drip campaigns in one unified page, so that I can create and edit campaigns efficiently.

#### Acceptance Criteria

1. WHEN the user accesses drip campaigns page THEN the system SHALL display both list and edit views in the same page
2. WHEN the system contains `drip-campaigns.php` and `drip-campaign-edit.php` THEN the system SHALL consolidate both into a single `drip-campaigns.php` with modal or inline editing
3. WHEN the user clicks edit on a campaign THEN the system SHALL display the edit form without navigating to a new page
4. WHEN the drip campaigns page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 15: รวมหน้า Shop Settings

**User Story:** As an admin user, I want to access all shop settings in one unified page, so that I can configure the shop efficiently.

#### Acceptance Criteria

1. WHEN the user accesses shop settings THEN the system SHALL display a tab-based interface with sections: General, LIFF Shop, Promotions
2. WHEN the system contains `shop/settings.php`, `shop/liff-shop-settings.php`, and `shop/promotion-settings.php` THEN the system SHALL consolidate all into a single `shop/settings.php` with tabs
3. WHEN the user accesses old URLs like `/shop/liff-shop-settings` THEN the system SHALL redirect to `/shop/settings?tab=liff`
4. WHEN the shop settings page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 16: รวมหน้า Shop Products

**User Story:** As an admin user, I want to manage products in one unified page, so that I can switch between list and grid views easily.

#### Acceptance Criteria

1. WHEN the user accesses products page THEN the system SHALL display a view toggle between list and grid views
2. WHEN the system contains `shop/products.php` and `shop/products-grid.php` THEN the system SHALL consolidate both into a single `shop/products.php` with view toggle
3. WHEN the user toggles view THEN the system SHALL switch between list and grid without page reload
4. WHEN the products page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 17: รวมหน้า Inventory

**User Story:** As an admin user, I want to access all inventory features in one unified page, so that I can manage stock efficiently.

#### Acceptance Criteria

1. WHEN the user accesses inventory page THEN the system SHALL display a tab-based interface with sections: Stock, Movements, Adjustments, Low Stock, Reports
2. WHEN the system contains multiple inventory files THEN the system SHALL consolidate into a single `inventory/index.php` with tabs
3. WHEN the user accesses old URLs like `/inventory/stock-movements` THEN the system SHALL redirect to `/inventory?tab=movements`
4. WHEN the inventory page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 18: รวมหน้า Procurement

**User Story:** As an admin user, I want to access all procurement features in one unified page, so that I can manage purchasing efficiently.

#### Acceptance Criteria

1. WHEN the user accesses procurement page THEN the system SHALL display a tab-based interface with sections: Purchase Orders, Goods Receive, Suppliers
2. WHEN the system contains `inventory/purchase-orders.php`, `inventory/goods-receive.php`, and `inventory/suppliers.php` THEN the system SHALL consolidate all into a single `procurement.php` with tabs
3. WHEN the user accesses old URLs like `/inventory/suppliers` THEN the system SHALL redirect to `/procurement?tab=suppliers`
4. WHEN the procurement page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 19: รวมหน้า Membership

**User Story:** As an admin user, I want to manage all membership features in one unified page, so that I can configure loyalty programs efficiently.

#### Acceptance Criteria

1. WHEN the user accesses membership page THEN the system SHALL display a tab-based interface with sections: Members, Rewards, Points Settings
2. WHEN the system contains `members.php`, `admin-rewards.php`, and `admin-points-settings.php` THEN the system SHALL consolidate all into a single `membership.php` with tabs
3. WHEN the user accesses old URLs like `/admin-rewards` THEN the system SHALL redirect to `/membership?tab=rewards`
4. WHEN the membership page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 20: รวมหน้า Scheduled Messages

**User Story:** As an admin user, I want to manage all scheduled content in one unified page, so that I can plan communications efficiently.

#### Acceptance Criteria

1. WHEN the user accesses scheduled page THEN the system SHALL display a tab-based interface with sections: Messages, Reports
2. WHEN the system contains `scheduled.php` and `scheduled-reports.php` THEN the system SHALL consolidate both into a single `scheduled.php` with tabs
3. WHEN the user accesses old URLs like `/scheduled-reports` THEN the system SHALL redirect to `/scheduled?tab=reports`
4. WHEN the scheduled page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 21: รวมหน้า Pharmacy Features

**User Story:** As a pharmacist, I want to access all pharmacy features in one unified page, so that I can manage clinical work efficiently.

#### Acceptance Criteria

1. WHEN the user accesses pharmacy page THEN the system SHALL display a tab-based interface with sections: Dashboard, Pharmacists, Drug Interactions, Dispense
2. WHEN the system contains `pharmacist-dashboard.php`, `pharmacists.php`, `drug-interactions.php`, and `dispense-drugs.php` THEN the system SHALL consolidate all into a single `pharmacy.php` with tabs
3. WHEN the user accesses old URLs like `/pharmacists` THEN the system SHALL redirect to `/pharmacy?tab=pharmacists`
4. WHEN the pharmacy page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 22: รวมหน้า Settings

**User Story:** As an admin user, I want to access all system settings in one unified page, so that I can configure the system efficiently.

#### Acceptance Criteria

1. WHEN the user accesses settings page THEN the system SHALL display a tab-based interface with sections: LINE Accounts, Telegram, Email, Notifications, Consent, Quick Access
2. WHEN the system contains multiple settings files THEN the system SHALL consolidate all into a single `settings.php` with tabs
3. WHEN the user accesses old URLs like `/line-accounts` THEN the system SHALL redirect to `/settings?tab=line`
4. WHEN the settings page loads THEN the system SHALL preserve all existing functionality from each original file

---

### Requirement 23: ลบ User Panel ที่ซ้ำซ้อน

**User Story:** As a developer, I want to remove duplicate user panel pages, so that there is only one version of each feature.

#### Acceptance Criteria

1. WHEN the system contains both admin and user versions of the same page THEN the system SHALL evaluate which to keep based on feature completeness
2. WHEN `user/` folder pages duplicate admin functionality THEN the system SHALL redirect user pages to admin pages with appropriate role filtering
3. WHEN the user accesses a user panel page THEN the system SHALL check user role and display appropriate content
4. WHEN user panel pages are consolidated THEN the system SHALL preserve user-specific functionality

