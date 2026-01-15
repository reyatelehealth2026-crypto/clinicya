# Webhook AI Disabled - Performance Optimization

## Date: 2026-01-16

## Problem
- Webhook was timing out (30+ seconds)
- Bot receives messages but doesn't send replies back to LINE users
- Database connection is extremely slow
- AI Module is disabled but webhook still checks for AI calls

## Root Cause
- AI processing in webhook adds significant delay
- Even checking for AI patterns (@บอท, @bot, @ai, /commands) slows down webhook
- Database queries for AI settings add latency
- Reply tokens expire before webhook can respond

## Solution
Disabled AI processing in webhook.php to improve response time:

### Changes Made (Lines ~1115-1145)
1. **Commented out slash command AI processing** (`/ai`, `/mims`, `/triage`, etc.)
   - Lines 1115-1135: Slash command detection and AI call
   
2. **Commented out @bot AI processing** (`@บอท`, `@bot`, `@ai`)
   - Lines 1318-1343: @bot pattern matching and AI call

### What Still Works
✅ Auto-reply rules (from `auto_reply_rules` table)
✅ Contact command ("ติดต่อ")
✅ Menu command ("เมนู")
✅ Shop commands
✅ Order tracking
✅ Follow/Unfollow events
✅ Postback events
✅ Group messages

### What's Disabled
❌ AI responses via @bot or @ai
❌ Slash commands (/ai, /mims, /triage, etc.)
❌ Automatic AI chat in webhook

## Alternative: Use Inbox V2
Users should use **Inbox V2** (inbox-v2.php) for AI features:
- Ghost Draft suggestions
- HUD widgets
- Drug recommendations
- Manual AI responses from admin panel

## How to Re-enable AI (if needed)
1. Fix database performance first
2. Uncomment the AI sections in webhook.php:
   - Lines 1115-1135 (slash commands)
   - Lines 1318-1343 (@bot commands)
3. Consider using async processing or queue for AI calls
4. Use pushMessage instead of replyMessage to avoid token expiration

## Performance Impact
- Expected webhook response time: < 5 seconds (down from 30+ seconds)
- Reply tokens should not expire
- Bot should respond to commands immediately

## Testing
Test these commands to verify bot is working:
- "ติดต่อ" → Should show contact message
- "เมนู" → Should show menu
- "สวัสดี" → Should trigger auto-reply (if configured)
- "@bot test" → Will NOT trigger AI (disabled)
- "/ai test" → Will NOT trigger AI (disabled)

## Files Modified
- `webhook.php` - Commented out AI processing sections

## Commit
```
Disable AI in webhook to improve performance - comment out @bot and slash command AI calls
```

## Next Steps
1. Monitor webhook response time
2. Check if "ติดต่อ" command now works
3. If still slow, investigate database connection issues
4. Consider moving AI to separate async worker
