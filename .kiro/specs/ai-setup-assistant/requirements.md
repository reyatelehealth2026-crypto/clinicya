# Requirements Document

## Introduction

AI Onboarding Assistant เป็นระบบผู้ช่วย AI สำหรับผู้ใช้งานระบบ LINE CRM SaaS ที่เพิ่งเริ่มใช้งาน ช่วยนำทางและแนะนำฟีเจอร์ต่างๆ ให้ใช้งานได้เต็มประสิทธิภาพ ตั้งแต่การตั้งค่าเริ่มต้น การเชื่อมต่อ LINE OA จนถึงการใช้งานฟีเจอร์ขั้นสูง โดยใช้ Gemini AI เป็น backend

## Glossary

- **AI Onboarding Assistant**: ระบบผู้ช่วย AI ที่ช่วยนำทางและแนะนำการใช้งานระบบ
- **LINE Official Account (LINE OA)**: บัญชี LINE สำหรับธุรกิจที่ใช้เชื่อมต่อกับระบบ
- **LIFF (LINE Front-end Framework)**: เฟรมเวิร์คสำหรับสร้างหน้าเว็บภายใน LINE
- **Webhook**: URL ที่ LINE ส่งข้อมูลมาเมื่อมี event เกิดขึ้น
- **Onboarding Flow**: กระบวนการแนะนำผู้ใช้ใหม่ให้รู้จักระบบ
- **Setup Checklist**: รายการตรวจสอบการตั้งค่าที่จำเป็น
- **Feature Tour**: การแนะนำฟีเจอร์ต่างๆ ในระบบ
- **Quick Start Guide**: คู่มือเริ่มต้นใช้งานอย่างรวดเร็ว

## Requirements

### Requirement 1: Welcome & Status Detection

**User Story:** As a new SaaS user, I want the AI to welcome me and detect my current setup status, so that I know what I need to do next.

#### Acceptance Criteria

1. WHEN a user opens the AI Onboarding Assistant for the first time THEN the System SHALL display a personalized welcome message with the user's name
2. WHEN the system loads THEN the System SHALL automatically scan and detect the current configuration status (LINE connection, shop setup, LIFF, etc.)
3. WHEN incomplete setup items are detected THEN the System SHALL display a visual checklist showing completed and pending items
4. WHEN all setup items are complete THEN the System SHALL congratulate the user and suggest advanced features to explore

### Requirement 2: LINE OA Connection Guide

**User Story:** As a user, I want the AI to guide me through connecting my LINE Official Account, so that I can start receiving customer messages.

#### Acceptance Criteria

1. WHEN a user asks about LINE connection THEN the System SHALL provide step-by-step instructions with screenshots or links to LINE Developers Console
2. WHEN a user needs to find Channel Access Token THEN the System SHALL explain exactly where to find it in LINE Developers Console
3. WHEN a user enters LINE credentials THEN the System SHALL validate them by calling LINE API and report success or specific errors
4. WHEN LINE connection is successful THEN the System SHALL display the Webhook URL and explain how to configure it in LINE Console
5. WHEN a user asks about Webhook verification THEN the System SHALL provide troubleshooting steps for common issues

### Requirement 3: Shop Configuration Guide

**User Story:** As a user, I want the AI to help me set up my online shop, so that I can start selling products to customers.

#### Acceptance Criteria

1. WHEN a user asks about shop setup THEN the System SHALL guide through essential settings (shop name, logo, contact info, business hours)
2. WHEN a user asks about payment methods THEN the System SHALL explain available options (bank transfer, PromptPay, etc.) and how to configure each
3. WHEN a user asks about shipping THEN the System SHALL guide through shipping zone and pricing configuration
4. WHEN a user asks about products THEN the System SHALL explain how to add products manually or import from CSV/API
5. WHEN shop setup is complete THEN the System SHALL provide a preview link and suggest testing the checkout flow

### Requirement 4: LIFF Setup Guide

**User Story:** As a user, I want the AI to help me configure LIFF applications, so that customers can access features directly in LINE app.

#### Acceptance Criteria

1. WHEN a user asks about LIFF THEN the System SHALL explain what LIFF is and its benefits for customer experience
2. WHEN a user needs to create LIFF apps THEN the System SHALL guide through LINE Developers Console with specific steps
3. WHEN a user enters LIFF IDs THEN the System SHALL validate the format and save configurations
4. WHEN multiple LIFF apps are needed THEN the System SHALL explain each one (shop, member card, appointments, pharmacy consult) and their purposes

### Requirement 5: Feature Discovery & Navigation

**User Story:** As a user, I want the AI to explain system features and help me navigate, so that I can use the system effectively.

#### Acceptance Criteria

1. WHEN a user asks "what can this system do" THEN the System SHALL provide an overview of all major features categorized by function
2. WHEN a user asks about a specific feature THEN the System SHALL explain its purpose, benefits, and provide a direct link to that page
3. WHEN a user is confused about where to find something THEN the System SHALL provide navigation guidance with menu paths
4. WHEN a user asks for recommendations THEN the System SHALL suggest features based on their business type (pharmacy, retail, service, etc.)
5. WHEN a user asks about a feature they haven't used THEN the System SHALL offer to guide them through first-time setup

### Requirement 6: Best Practices & Tips

**User Story:** As a user, I want the AI to provide best practices and tips, so that I can maximize the value from the system.

#### Acceptance Criteria

1. WHEN a user asks for tips THEN the System SHALL provide actionable recommendations based on their current usage
2. WHEN a user completes a setup step THEN the System SHALL suggest related features or next steps to explore
3. WHEN a user asks about increasing sales THEN the System SHALL recommend features like broadcast, promotions, loyalty points
4. WHEN a user asks about customer engagement THEN the System SHALL suggest auto-reply, rich menu, and member card features
5. WHEN a user asks about efficiency THEN the System SHALL recommend AI chat, scheduled messages, and automation features

### Requirement 7: Troubleshooting & Health Check

**User Story:** As a user, I want the AI to help me troubleshoot issues and check system health, so that I can resolve problems quickly.

#### Acceptance Criteria

1. WHEN a user reports a problem THEN the System SHALL ask clarifying questions and provide relevant solutions
2. WHEN a user requests a health check THEN the System SHALL verify LINE API connection, database status, and key configurations
3. WHEN issues are found THEN the System SHALL explain the problem in simple terms and provide fix instructions
4. WHEN a user asks why something isn't working THEN the System SHALL check related configurations and suggest fixes

### Requirement 8: Contextual Assistance

**User Story:** As a user, I want the AI to remember my context and provide relevant help, so that I don't have to repeat information.

#### Acceptance Criteria

1. WHEN a user returns to the assistant THEN the System SHALL recall their setup progress and recent topics
2. WHEN a user is on a specific page THEN the System SHALL provide context-aware help related to that page
3. WHEN a user has partially completed a task THEN the System SHALL offer to continue from where they left off
4. WHEN a user's business type is known THEN the System SHALL tailor recommendations accordingly

### Requirement 9: Quick Actions & Shortcuts

**User Story:** As a user, I want quick action buttons, so that I can perform common tasks without navigating through menus.

#### Acceptance Criteria

1. WHEN the AI suggests a configuration THEN the System SHALL provide a clickable button to navigate to that setting
2. WHEN a user needs to perform a common action THEN the System SHALL offer quick action buttons (e.g., "Go to Shop Settings", "Test LINE Connection")
3. WHEN a quick action is clicked THEN the System SHALL either navigate to the page or perform the action and report results
4. WHEN the AI detects missing setup THEN the System SHALL provide a "Fix Now" button that navigates to the relevant configuration page

### Requirement 10: Interactive Onboarding Checklist

**User Story:** As a user, I want a visual checklist of setup tasks, so that I can track my progress and know what's remaining.

#### Acceptance Criteria

1. WHEN the assistant opens THEN the System SHALL display a progress bar showing overall setup completion percentage
2. WHEN viewing the checklist THEN the System SHALL show categories (LINE Setup, Shop Setup, LIFF Setup, Advanced Features) with sub-items
3. WHEN a checklist item is clicked THEN the System SHALL provide guidance for that specific item
4. WHEN an item is completed THEN the System SHALL automatically update the checklist and celebrate the progress
5. WHEN all essential items are complete THEN the System SHALL unlock and suggest advanced features to explore
