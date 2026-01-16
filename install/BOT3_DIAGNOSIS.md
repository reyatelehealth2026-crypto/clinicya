# Bot ID 3 - Diagnosis Report

## 🔴 Problem Summary
Bot ID 3 logs messages successfully but messages don't reach actual LINE users. Bot ID 4 works correctly.

## 📊 Current Status

### Bot ID 3 Configuration
- **Bot Mode**: Changed from "shop" to "general"
- **Channel Access Token**: Updated to `j8N6tUUknvjBWe+FThiV5+WrBH43hgNR8gqbgSE/jhDjEE/7yc0G0FbHu7sTvS6X...`
- **Logs**: Show `has_reply: true` and `bot_mode: "general"`
- **Issue**: Messages logged but not delivered to LINE

### Test User
- **User ID**: `Ua1156d646cad2237e878457833bc07b3`

## 🔍 Diagnostic Tools Created

### 1. **diagnose_bot3.php** (Comprehensive Diagnostic)
**URL**: https://cny.re-ya.com/install/diagnose_bot3.php

**Features**:
- ✅ Shows Bot ID 3 configuration details
- ✅ Compares with Bot ID 4 (working bot)
- ✅ Tests LINE API token validity
- ✅ Tests sending actual message to LINE
- ✅ Shows recent logs for Bot ID 3
- ✅ Provides troubleshooting recommendations

**How to Use**:
1. Open https://cny.re-ya.com/install/diagnose_bot3.php
2. Review Bot ID 3 configuration
3. Click "ทดสอบ Token และส่งข้อความ" button
4. Check results:
   - If Token test fails → Need to issue new token from LINE Developers Console
   - If Token test passes but message fails → Check user ID or user hasn't added bot as friend
   - If both pass → Check webhook logs for other issues

### 2. **test_line_simple.php** (Manual Token Testing)
**URL**: https://cny.re-ya.com/install/test_line_simple.php

**Features**:
- Manual form to test any token
- No database dependency
- Direct LINE API testing

**How to Use**:
1. Open https://cny.re-ya.com/install/test_line_simple.php
2. Enter Channel Access Token manually
3. Enter User ID: `Ua1156d646cad2237e878457833bc07b3`
4. Send test message

## 🔧 Common Issues & Solutions

### Issue 1: Invalid Access Token
**Symptoms**: HTTP 401 or "Invalid access token" error

**Solution**:
1. Go to [LINE Developers Console](https://developers.line.biz/console/)
2. Select Provider and Channel for Bot ID 3
3. Go to "Messaging API" tab
4. Issue new Channel Access Token
5. Copy new token
6. Update in database:
```sql
UPDATE line_accounts 
SET channel_access_token = 'NEW_TOKEN_HERE' 
WHERE id = 3;
```

### Issue 2: User Not Found
**Symptoms**: HTTP 404 or "Not found" error

**Solution**:
- User must add Bot as friend first
- Verify User ID is correct (starts with 'U')
- Check if user blocked the bot

### Issue 3: Webhook URL Issues
**Symptoms**: Messages logged but not sent

**Solution**:
1. Check webhook URL in LINE Developers Console
2. Should be: `https://cny.re-ya.com/webhook.php?account=3`
3. Verify SSL certificate is valid
4. Test webhook with LINE's verification tool

### Issue 4: Bot Inactive
**Symptoms**: No messages sent at all

**Solution**:
```sql
UPDATE line_accounts 
SET is_active = 1 
WHERE id = 3;
```

## 📝 Next Steps

1. **Run Diagnostic Tool**
   - Open https://cny.re-ya.com/install/diagnose_bot3.php
   - Click "ทดสอบ Token และส่งข้อความ"
   - Review results

2. **If Token Invalid**
   - Issue new token from LINE Developers Console
   - Update in database
   - Test again

3. **If Token Valid but Message Fails**
   - Verify user added bot as friend
   - Check webhook URL configuration
   - Review LINE Developers Console for errors

4. **If Everything Passes**
   - Check webhook logs for LINE API response errors
   - Compare Bot ID 3 and Bot ID 4 configurations
   - Check for rate limiting or quota issues

## 🔗 Useful Links

- [LINE Developers Console](https://developers.line.biz/console/)
- [LINE Messaging API Documentation](https://developers.line.biz/en/docs/messaging-api/)
- [Webhook Debugging Guide](https://developers.line.biz/en/docs/messaging-api/receiving-messages/#webhook-event-object)

## 📞 Support

If issue persists after running diagnostics:
1. Check Bot ID 3 in LINE Developers Console
2. Verify webhook URL and SSL
3. Compare all settings with Bot ID 4
4. Check LINE API quota and rate limits

---
**Generated**: 2026-01-16
**Status**: Diagnostic tools deployed and ready for testing
