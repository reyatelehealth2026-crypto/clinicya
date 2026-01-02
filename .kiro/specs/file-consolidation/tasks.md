# Implementation Plan

## Tasks

- [x] 1. Phase 1: ลบไฟล์ซ้ำ 100%






  - [x] 1.1 ลบไฟล์ duplicate ที่ root level

    - ลบ users_new.php
    - ลบ t.php, test.php
    - _Requirements: 1.1, 1.4_

  - [x] 1.2 ลบไฟล์ duplicate ใน shop folder

    - ลบ shop/orders_new.php
    - ลบ shop/order-detail-new.php
    - _Requirements: 1.2, 1.3_

  - [x] 1.3 ลบโฟลเดอร์ Debug/Test

    - Archive และลบ New folder/
    - ตรวจสอบ _archive/debug/ ว่าไม่มี references
    - _Requirements: 9.1, 9.2, 9.3_

- [x] 2. Phase 2: รวมไฟล์เวอร์ชัน






  - [x] 2.1 รวมไฟล์ broadcast-catalog

    - ลบ broadcast-catalog.php (เวอร์ชันเก่า)
    - Rename broadcast-catalog-v2.php → broadcast-catalog.php
    - _Requirements: 2.1_

  - [x] 2.2 รวมไฟล์ flex-builder

    - ลบ flex-builder.php (เวอร์ชันเก่า)
    - Rename flex-builder-v2.php → flex-builder.php
    - _Requirements: 2.2_

  - [x] 2.3 รวมไฟล์ video-call

    - ลบ video-call.php, video-call-v2.php, video-call-simple.php
    - Rename video-call-pro.php → video-call.php
    - _Requirements: 2.4, 6.1, 6.4_

  - [x] 2.4 รวมไฟล์ messages

    - Merge AI features จาก messages-v2.php เข้า messages.php
    - ลบ messages-v2.php
    - _Requirements: 2.5_

- [ ] 3. Checkpoint - ทดสอบหลัง Phase 1-2
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 4. Phase 3: ลบไฟล์ LIFF เก่า
  - [ ] 4.1 สร้าง redirect rules สำหรับ LIFF URLs
    - สร้างไฟล์ includes/liff-redirects.php
    - Map liff-*.php → liff/index.php?page=xxx
    - _Requirements: 5.3_
  - [ ] 4.2 ลบไฟล์ LIFF ที่ root level (24 ไฟล์)
    - Archive ไฟล์ก่อนลบ
    - ลบ liff-app.php, liff-appointment.php, liff-checkout.php, liff-consent.php
    - ลบ liff-main.php, liff-member-card.php, liff-my-appointments.php, liff-my-orders.php
    - ลบ liff-order-detail.php, liff-pharmacy-consult.php, liff-points-history.php, liff-points-rules.php
    - ลบ liff-product-detail.php, liff-promotions.php, liff-redeem-points.php, liff-register.php
    - ลบ liff-settings.php, liff-share.php, liff-shop-v3.php, liff-shop.php
    - ลบ liff-symptom-assessment.php, liff-video-call-pro.php, liff-video-call.php, liff-wishlist.php
    - _Requirements: 5.1, 5.2_
  - [ ]* 4.3 Write property test for LIFF URL Redirects
    - **Property 5: Old LIFF URLs Redirect to SPA**
    - **Validates: Requirements 5.3**




- [x] 5. Phase 4: สร้าง Tab Component และ Redirect System



  - [x] 5.1 สร้าง Tab Component

    - สร้างไฟล์ includes/components/tabs.php
    - สร้าง function renderTabs($tabs, $activeTab)
    - เพิ่ม CSS styles สำหรับ tabs
    - _Requirements: 3.1, 4.1, 10.1_

  - [x] 5.2 สร้าง Redirect Handler






    - สร้างไฟล์ includes/redirects.php
    - สร้าง redirect map สำหรับ URL เก่าทั้งหมด
    - สร้าง function handleRedirect()
    - _Requirements: 8.1, 8.2, 8.3_
  - [ ]* 5.3 Write property test for Redirect System
    - **Property 2: Redirect Preserves Query Parameters**
    - **Property 3: Redirect Uses HTTP 301**
    - **Validates: Requirements 8.1, 8.2, 8.3**



- [x] 6. Phase 5: รวมหน้า Analytics




  - [x] 6.1 สร้างหน้า analytics.php แบบ Tab-based

    - สร้าง tabs: overview, advanced, crm, account
    - ย้าย content จาก advanced-analytics.php, crm-analytics.php, account-analytics.php
    - _Requirements: 3.1, 3.2, 3.5_


  - [ ] 6.2 สร้าง redirect สำหรับ analytics URLs เก่า














    - advanced-analytics.php → analytics.php?tab=advanced
    - crm-analytics.php → analytics.php?tab=crm


    - account-analytics.php → analytics.php?tab=account
    - _Req-irements: 3.3, 3.4_
  - [ ] 6.3 ลบไฟล์ analytics เก่า
    - ลบ advanced-analytics.php
    - ลบ crm-analytics.php



    - ลบ account-analytics.php
    - _Requirements: 3.3_
  - [ ]* 6.4 Write property test for Tab-based Pages
    - **Property 4: Tab-based Pages Contain All Required Tabs**
    - **Validates: Requirements 3.1, 4.1, 10.1, 12.1, 13.1**

- [x] 7. Phase 6: รวมหน้า Dashboard



  - [x] 7.1 สร้างหน้า dashboard.php แบบ Tab-based

    - สร้าง tabs: executive, crm
    - ย้าย content จาก executive-dashboard.php, crm-dashboard.php
    - _Requirements: 10.1, 10.4_

  - [x] 7.2 สร้าง redirect และลบไฟล์เก่า

    - executive-dashboard.php → dashboard.php?tab=executive
    - crm-dashboard.php → dashboard.php?tab=crm
    - ลบไฟล์เก่า
    - _Requirements: 10.2, 10.3_

- [ ] 8. Checkpoint - ทดสอบหลัง Phase 3-6
  - Ensure all tests pass, ask the user if questions arise.


- [x] 9. Phase 7: รวมหน้า AI Chat





  - [x] 9.1 สร้างหน้า ai-chat.php แบบ Tab-based

    - สร้าง tabs: chat, chatbot, settings, studio
    - ย้าย content จาก ai-chatbot.php, ai-chat-settings.php, ai-studio.php
    - _Requirements: 4.1, 4.4_

  - [x] 9.2 สร้าง redirect และลบไฟล์เก่า

    - ai-chatbot.php → ai-chat.php?tab=chatbot
    - ai-chat-settings.php → ai-chat.php?tab=settings
    - ai-studio.php → ai-chat.php?tab=studio
    - ลบไฟล์เก่า
    - _Requirements: 4.2, 4.3_

- [x] 10. Phase 8: รวมหน้า Broadcast












  - [x] 10.1 สร้างหน้า broadcast.php แบบ Tab-based


    - สร้าง tabs: send, catalog, products, stats
    - ย้าย content จาก broadcast-catalog.php, broadcast-products.php, broadcast-stats.php
    - _Requirements: 12.1, 12.4_


  - [x] 10.2 สร้าง redirect และลบไฟล์เก่า

    - broadcast-catalog.php → broadcast.php?tab=catalog
    - broadcast-products.php → broadcast.php?tab=products
    - broadcast-stats.php → broadcast.php?tab=stats



    - ลบไฟล์เก่า
    - _Requirements: 12.2, 12.3_
---
- [x] 11. Phase 9: รวมหน้า Rich Menu



  - [x] 11.1 สร้างหน้า rich-menu.php แบบ Tab-based

    - สร้าง tabs: static, dynamic, switch
    - ย้าย content จาก dynamic-rich-menu.php, rich-menu-switch.php
    - _Requirements: 13.1, 13.4_

  - [x] 11.2 สร้าง redirect และลบไฟล์เก่า

    - dynamic-rich-menu.php → rich-menu.php?tab=dynamic
    - rich-menu-switch.php → rich-menu.php?tab=switch
    - ลบไฟล์เก่า
    - _Requirements: 13.2, 13.3_


- [x] 12. Phase 10: รวมหน้า Membership





  - [x] 12.1 สร้างหน้า membership.php แบบ Tab-based

    - สร้าง tabs: members, rewards, settings
    - ย้าย content จาก members.php, admin-rewards.php, admin-points-settings.php
    - _Requirements: 19.1, 19.4_


  - [ ] 12.2 สร้าง redirect และลบไฟล์เก่า
    - admin-rewards.php → membership.php?tab=rewards
    - admin-points-settings.php → membership.php?tab=settings
    - ลบไฟล์เก่า
    - _Requirements: 19.2, 19.3_

- [ ] 13. Checkpoint - ทดสอบหลัง Phase 7-10
  - Ensure all tests pass, ask the user if questions arise.

- [x] 14. Phase 11: รวมหน้า Settings






  - [x] 14.1 สร้างหน้า settings.php แบบ Tab-based

    - สร้าง tabs: line, telegram, email, notifications, consent, quick-access
    - ย้าย content จากไฟล์ settings ต่างๆ
    - _Requirements: 22.1, 22.4_


  - [ ] 14.2 สร้าง redirect และลบไฟล์เก่า
    - line-accounts.php → settings.php?tab=line
    - telegram.php → settings.php?tab=telegram
    - email-settings.php → settings.php?tab=email
    - notification-settings.php → settings.php?tab=notifications
    - consent-management.php → settings.php?tab=consent



    - quick-access-settings.php → settings.php?tab=quick-access
    - ลบไฟล์เก่า
    - _Requirements: 22.2, 22.3_

- [x] 15. Phase 12: รวมหน้า Pharmacy



  - [x] 15.1 สร้างหน้า pharmacy.php แบบ Tab-based

    - สร้าง tabs: dashboard, pharmacists, interactions, dispense
    - ย้าย content จาก pharmacist-dashboard.php, pharmacists.php, drug-interactions.php, dispense-drugs.php
    - _Requirements: 21.1, 21.4_

  - [x] 15.2 สร้าง redirect และลบไฟล์เก่า

    - pharmacist-dashboard.php → pharmacy.php?tab=dashboard
    - pharmacists.php → pharmacy.php?tab=pharmacists
    - drug-interactions.php → pharmacy.php?tab=interactions
    - dispense-drugs.php → pharmacy.php?tab=dispense
    - ลบไฟล์เก่า
    - _Requirements: 21.2, 21.3_





- [x] 16. Phase 13: รวมหน้า Inventory & Procurement


  - [x] 16.1 สร้างหน้า inventory/index.php แบบ Tab-based

    - สร้าง tabs: stock, movements, adjustment, low-stock, reports
    - ย้าย content จากไฟล์ inventory ต่างๆ
    - _Requirements: 17.1, 17.4_

  - [x] 16.2 สร้างหน้า procurement.php แบบ Tab-based

    - สร้าง tabs: po, gr, suppliers
    - ย้าย content จาก purchase-orders.php, goods-receive.php, suppliers.php
    - _Requirements: 18.1, 18.4_

  - [x] 16.3 สร้าง redirect และลบไฟล์เก่า

    - inventory/stock-movements.php → inventory/index.php?tab=movements
    - inventory/purchase-orders.php → procurement.php?tab=po
    - ลบไฟล์เก่า
    - _Requirements: 17.2, 17.3, 18.2, 18.3_


- [x] 17. Phase 14: รวมหน้า Shop Settings & Products



  - [x] 17.1 สร้างหน้า shop/settings.php แบบ Tab-based

    - สร้าง tabs: general, liff, promotions
    - ย้าย content จาก liff-shop-settings.php, promotion-settings.php
    - _Requirements: 15.1, 15.4_


  - [x] 17.2 เพิ่ม view toggle ใน shop/products.php
    - เพิ่ม toggle: list, grid
    - รวม content จาก products-grid.php
    - ลบ products-grid.php
    - _Requirements: 16.1, 16.2, 16.3, 16.4_
  - [x] 17.3 สร้าง redirect และลบไฟล์เก่า


    - shop/liff-shop-settings.php → shop/settings.php?tab=liff
    - shop/promotion-settings.php → shop/settings.php?tab=promotions
    - ลบไฟล์เก่า
    - _Requirements: 15.2, 15.3_


- [x] 18. Phase 15: รวมหน้า Scheduled & Drip Campaign









  - [x] 18.1 สร้างหน้า scheduled.php แบบ Tab-based




    - สร้าง tabs: messages, reports
    - ย้าย content จาก scheduled-reports.php
    - _Requirements: 20.1, 20.4_

  - [x] 18.2 เพิ่ม modal editing ใน drip-campaigns.php



    - รวม content จาก drip-campaign-edit.php
    - ลบ drip-campaign-edit.php
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

  - [x] 18.3 สร้าง redirect และลบไฟล์เก่า



    - scheduled-reports.php → scheduled.php?tab=reports
    - ลบไฟล์เก่า
    - _Requirements: 20.2, 20.3_

- [ ] 19. Checkpoint - ทดสอบหลัง Phase 11-15
  - Ensure all tests pass, ask the user if questions arise.


- [x] 20. Phase 16: อัพเดท Menu References





  - [x] 20.1 อัพเดท includes/header.php

    - อัพเดท URLs ใน $menuSections array
    - ลบ menu items ที่ไม่ใช้แล้ว
    - _Requirements: 7.1, 7.2_
  - [ ]* 20.2 Write property test for Menu URLs
    - **Property 1: Menu URLs Point to Existing Files**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4**


- [x] 21. Phase 17: จัดการ User Panel





  - [x] 21.1 ตรวจสอบและ redirect user/ folder pages

    - user/analytics.php → analytics.php
    - user/messages.php → messages.php
    - user/broadcast.php → broadcast.php
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 23.1, 23.2, 23.3, 23.4_

- [x] 22. Write property test for Duplicate Files Removal






  - **Property 6: Duplicate Files Are Removed**
  - **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.3, 2.4, 2.5**


- [x] 23. Final Checkpoint - ทดสอบระบบทั้งหมด




  - Ensure all tests pass, ask the user if questions arise.
