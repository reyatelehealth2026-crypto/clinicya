# Webhook Function Order Fix

## Problem
PHP Fatal Error: Call to undefined function `getOrCreateUser()` at line 644

## Root Cause
Functions are defined AFTER they are called in webhook.php:
- Event processing loop starts at line ~180
- Functions are defined starting at line ~333
- When `handleMessage()` is called at line 260, it tries to call `getOrCreateUser()` at line 644
- But `getOrCreateUser()` is not defined until line 2887

## Solution Options

### Option 1: Move all functions before event loop (RECOMMENDED)
Move all function definitions to appear before the `foreach ($events as $event)` loop

### Option 2: Extract functions to separate file
Create `includes/webhook_functions.php` and require it at the top

### Option 3: Use anonymous functions assigned to variables
Not recommended for this codebase

## Implementation Plan

1. Create `includes/webhook_functions.php` with ALL helper functions
2. Require it after config files in webhook.php
3. Remove duplicate function definitions from webhook.php
4. Test webhook with: "สวัสดี", "ติดต่อ", "เมนู"

## Functions That Need to Be Moved (in order of dependency)

### Core Helper Functions (no dependencies)
- `devLog()` ✅ DONE - moved to top
- `showLoadingAnimation()`
- `logAnalytics()`
- `saveOutgoingMessage()`
- `getAccountName()`
- `getAISenderSettings()`

### User Management Functions
- `getOrCreateUser()` - calls: saveAccountFollower
- `saveAccountFollower()`
- `checkUserConsent()`

### State Management Functions
- `getUserState()` - calls: clearUserState
- `setUserState()`
- `clearUserState()`

### Account Event Functions
- `saveAccountEvent()` - calls: updateAccountDailyStats
- `updateAccountDailyStats()`
- `updateFollowerInteraction()`

### Message Handler Functions
- `handleFollow()` - calls: saveAccountFollower, saveAccountEvent, updateAccountDailyStats, sendWelcomeMessage, logAnalytics, getAccountName, sendTelegramNotification
- `handleUnfollow()` - calls: saveAccountFollower, saveAccountEvent, updateAccountDailyStats, logAnalytics, getAccountName, sendTelegramNotification
- `handleMessage()` - calls: getOrCreateUser, checkUserConsent, saveAccountEvent, updateFollowerInteraction, checkAutoReply, checkAIChatbot, etc.
- `handleBroadcastClick()` - calls: devLog, sendTelegramNotification
- `sendWelcomeMessage()` - calls: devLog

### Group Management Functions
- `ensureGroupExists()`
- `handleJoinGroup()`
- `handleLeaveGroup()`
- `handleMemberJoined()`
- `handleMemberLeft()`
- `saveGroupMessage()`
- `updateGroupStats()`

### Auto Reply Functions
- `checkAutoReply()` - calls: addShareButtonToFlex
- `addShareButtonToFlex()`
- `checkAIChatbot()` - calls: devLog

### Order/Payment Functions
- `createOrderFromPendingState()`
- `handleSlipCommand()`
- `handlePaymentSlip()`
- `handlePaymentSlipForOrder()`
- `notifyAdminNewSlip()`

### Notification Functions
- `sendTelegramNotification()`
- `sendTelegramNotificationWithMedia()`

## Status
- ✅ Created `includes/webhook_functions.php` with basic functions
- ✅ Added require statement in webhook.php
- ⏳ Need to add remaining functions to webhook_functions.php
- ⏳ Need to remove duplicate definitions from webhook.php
- ⏳ Need to test webhook

## Next Steps
1. Copy ALL function definitions to `includes/webhook_functions.php`
2. Keep them in dependency order (functions called by others come first)
3. Remove duplicate definitions from webhook.php (keep only the require statement)
4. Test webhook
