# Reply Token Storage Diagnosis

## Problem Statement
The system is not storing `reply_token` from LINE webhook events, which prevents the system from replying to user messages using the Reply API.

## Background: LINE Reply Token
- LINE provides a `reply_token` with each webhook event (message, postback, etc.)
- Reply tokens are valid for **1 minute** after the event
- Reply tokens can only be used **once**
- After expiration or use, must use Push API instead (which counts against quota)

## Current Implementation

### 1. Webhook Code (webhook.php lines 818-830)
```php
// บันทึก reply_token ใน users table (หมดอายุใน 20 นาที)
if ($replyToken) {
    try {
        // ตรวจสอบว่ามี column หรือไม่
        $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
        if ($checkCol->rowCount() > 0) {
            $expires = date('Y-m-d H:i:s', time() + (19 * 60)); // หมดอายุใน 19 นาที (เผื่อ delay)
            $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
            $stmt->execute([$replyToken, $expires, $user['id']]);
        }
    } catch (Exception $e) {
        // Ignore error  ⚠️ THIS IS PROBLEMATIC
    }
}
```

**Issues with current code:**
1. ❌ **Errors are silently ignored** - Line 829 has `// Ignore error` which means we never know if saving fails
2. ⚠️ **Expiry time is wrong** - Code sets 19 minutes, but LINE tokens expire in 1 minute
3. ⚠️ **Column check on every request** - `SHOW COLUMNS` query runs on every webhook call (performance issue)

### 2. Database Schema

**users table:**
```sql
`reply_token` VARCHAR(255),
`reply_token_expires` DATETIME,
```

**messages table:**
```sql
`reply_token` VARCHAR(255),
```

✅ Columns exist in schema files (`database/schema_complete.sql`, `database/install_complete.sql`)

## Diagnostic Steps

### Step 1: Run Diagnostic Script
```bash
# Access via browser
https://your-domain.com/install/check_reply_token_storage.php
```

This script checks:
- ✓ Column existence in database
- ✓ Current reply_token data in users table
- ✓ Recent messages with reply tokens
- ✓ Recent webhook activity
- ✓ Webhook code status

### Step 2: Check Database Directly
```sql
-- Check if columns exist
SHOW COLUMNS FROM users LIKE 'reply_token%';
SHOW COLUMNS FROM messages LIKE 'reply_token%';

-- Check for any stored tokens
SELECT id, display_name, reply_token, reply_token_expires 
FROM users 
WHERE reply_token IS NOT NULL 
ORDER BY reply_token_expires DESC 
LIMIT 10;

-- Check messages table
SELECT id, user_id, message_type, reply_token, created_at 
FROM messages 
WHERE reply_token IS NOT NULL 
ORDER BY created_at DESC 
LIMIT 10;

-- Check recent incoming messages
SELECT id, user_id, message_type, created_at, reply_token IS NOT NULL as has_token
FROM messages 
WHERE direction = 'incoming'
ORDER BY created_at DESC 
LIMIT 10;
```

### Step 3: Test with Live Message
1. Send a message from LINE app
2. Immediately check database (within 1 minute)
3. Look for the reply_token in both users and messages tables

## Possible Root Causes

### Scenario A: Columns Don't Exist
**Symptoms:**
- Diagnostic shows "No reply_token columns found"
- `SHOW COLUMNS` query returns 0 rows

**Solution:**
```bash
# Run migration to create columns
php install/run_liff_required_tables.php
# Or manually add columns
ALTER TABLE users ADD COLUMN reply_token VARCHAR(255);
ALTER TABLE users ADD COLUMN reply_token_expires DATETIME;
```

### Scenario B: Webhook Not Receiving Token
**Symptoms:**
- Columns exist but no tokens ever saved
- No tokens in messages table either

**Possible causes:**
- Webhook URL not configured in LINE Developers Console
- Webhook failing before reaching reply_token code
- LINE not sending reply_token (check webhook payload)

**Solution:**
1. Check LINE Developers Console → Messaging API → Webhook URL
2. Check webhook logs: `tail -f /path/to/error_log`
3. Add logging to webhook.php to see incoming payload

### Scenario C: Silent Errors
**Symptoms:**
- Columns exist
- Webhook receives messages
- But tokens not saved

**Possible causes:**
- Database connection issue
- Permission issue on users table
- User ID mismatch
- Exception thrown but ignored

**Solution:**
Replace error ignoring with proper logging:

```php
} catch (Exception $e) {
    error_log('Reply token save failed: ' . $e->getMessage());
    error_log('User ID: ' . $user['id'] . ', Token: ' . substr($replyToken, 0, 20));
}
```

### Scenario D: Tokens Expire Too Fast
**Symptoms:**
- Tokens are saved
- But always expired when checked

**Issue:**
- LINE tokens expire in **1 minute**, not 19 minutes
- Current code sets expiry to 19 minutes which is misleading

**Solution:**
Update expiry time to be realistic:
```php
$expires = date('Y-m-d H:i:s', time() + 50); // 50 seconds (safe margin)
```

## Recommended Fixes

### Fix 1: Add Error Logging (CRITICAL)
**File:** `webhook.php` line 829

**Change from:**
```php
} catch (Exception $e) {
    // Ignore error
}
```

**Change to:**
```php
} catch (Exception $e) {
    error_log('Reply token save failed: ' . $e->getMessage());
    error_log('User ID: ' . ($user['id'] ?? 'unknown') . ', Token: ' . substr($replyToken, 0, 20));
}
```

### Fix 2: Correct Expiry Time
**File:** `webhook.php` line 824

**Change from:**
```php
$expires = date('Y-m-d H:i:s', time() + (19 * 60)); // หมดอายุใน 19 นาที (เผื่อ delay)
```

**Change to:**
```php
$expires = date('Y-m-d H:i:s', time() + 50); // หมดอายุใน 50 วินาที (LINE tokens expire in 1 minute)
```

### Fix 3: Remove Redundant Column Check
**File:** `webhook.php` lines 821-823

**Change from:**
```php
// ตรวจสอบว่ามี column หรือไม่
$checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
if ($checkCol->rowCount() > 0) {
```

**Change to:**
```php
// Assume columns exist (they're in schema)
try {
```

**Rationale:** Column check runs on every webhook call. If columns don't exist, we'll catch the exception.

### Fix 4: Implement Fallback to Push API
When reply_token is expired or missing, automatically use Push API:

```php
function sendLineMessage($line, $db, $userId, $messages) {
    // Try to get valid reply token
    $stmt = $db->prepare("
        SELECT reply_token 
        FROM users 
        WHERE id = ? 
        AND reply_token IS NOT NULL 
        AND reply_token_expires > NOW()
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['reply_token']) {
        // Use Reply API
        try {
            $line->replyMessage($user['reply_token'], $messages);
            // Clear token after use (can only use once)
            $db->prepare("UPDATE users SET reply_token = NULL WHERE id = ?")->execute([$userId]);
            return true;
        } catch (Exception $e) {
            error_log('Reply API failed: ' . $e->getMessage());
            // Fall through to Push API
        }
    }
    
    // Use Push API as fallback
    $lineUserId = getUserLineId($db, $userId);
    if ($lineUserId) {
        $line->pushMessage($lineUserId, $messages);
        return true;
    }
    
    return false;
}
```

## Testing Procedure

### 1. Apply Fixes
```bash
# Edit webhook.php with fixes above
# Commit changes
git add webhook.php
git commit -m "Fix reply_token storage with proper error logging"
git push emp master
```

### 2. Test Token Storage
```bash
# 1. Send message from LINE app
# 2. Immediately check database
mysql -u user -p database -e "SELECT id, display_name, reply_token, reply_token_expires FROM users WHERE reply_token IS NOT NULL ORDER BY reply_token_expires DESC LIMIT 5;"

# 3. Check error log
tail -f /path/to/error_log | grep -i "reply token"
```

### 3. Test Token Usage
```bash
# 1. Send message from LINE app
# 2. Within 50 seconds, trigger a reply from system
# 3. Check if Reply API was used (check logs)
# 4. Verify token was cleared after use
```

## Monitoring

### Add Logging to Track Token Usage
```php
// When saving token
error_log("Reply token saved for user {$user['id']}, expires: {$expires}");

// When using token
error_log("Using reply token for user {$userId}");

// When token expired
error_log("Reply token expired for user {$userId}, falling back to Push API");
```

### Dashboard Metrics
Track in admin dashboard:
- Reply API usage vs Push API usage
- Token save success rate
- Token expiry rate
- Average time between message and reply

## Related Files
- `webhook.php` - Main webhook handler (lines 818-830)
- `database/schema_complete.sql` - Database schema
- `database/install_complete.sql` - Installation schema
- `install/check_reply_token_storage.php` - Diagnostic script
- `classes/LineAPI.php` - LINE API wrapper

## References
- [LINE Messaging API - Reply Message](https://developers.line.biz/en/reference/messaging-api/#send-reply-message)
- [LINE Messaging API - Push Message](https://developers.line.biz/en/reference/messaging-api/#send-push-message)
- Reply tokens expire in 1 minute and can only be used once

## Status
- ⏳ **Investigation in progress**
- 🔍 **Diagnostic script created**
- 📝 **Fixes documented**
- ⏭️ **Next: Apply fixes and test**
