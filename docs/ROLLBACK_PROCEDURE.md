# Rollback Procedure for Inbox v2 Performance Upgrade

Emergency rollback guide for reverting inbox v2 performance upgrade features in case of critical issues.

## Table of Contents

1. [When to Rollback](#when-to-rollback)
2. [Quick Rollback (Emergency)](#quick-rollback-emergency)
3. [Partial Rollback](#partial-rollback)
4. [Full Rollback](#full-rollback)
5. [Post-Rollback Steps](#post-rollback-steps)
6. [Troubleshooting](#troubleshooting)

## When to Rollback

### Critical Issues (Immediate Rollback Required)

- **Data Loss**: Users losing messages or conversations
- **System Down**: Inbox completely inaccessible
- **Security Breach**: Unauthorized access or data exposure
- **Database Corruption**: Data integrity issues
- **Widespread Errors**: > 50% of users affected

### Major Issues (Rollback Recommended)

- **Performance Degradation**: Page load > 5s consistently
- **High Error Rate**: > 10% of requests failing
- **Memory Leaks**: Server memory usage growing unbounded
- **WebSocket Failures**: Real-time updates not working for > 25% of users
- **User Complaints**: Significant increase in support tickets

### Minor Issues (Partial Rollback or Fix)

- **Isolated Bugs**: Affecting < 5% of users
- **UI Glitches**: Visual issues without functional impact
- **Slow Performance**: Specific features slower than expected
- **Browser Compatibility**: Issues with specific browsers

## Quick Rollback (Emergency)

**Time Required:** 2-5 minutes  
**Impact:** Disables all performance features immediately

### Step 1: Disable Performance Features (Database)

```bash
# Connect to database
mysql -u root -p telepharmacy

# Disable performance upgrade
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key = 'performance_upgrade_enabled';

# Disable WebSocket
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key = 'websocket_enabled';

# Verify
SELECT setting_key, setting_value 
FROM vibe_selling_settings 
WHERE setting_key IN ('performance_upgrade_enabled', 'websocket_enabled');

# Exit
exit;
```

### Step 2: Clear Application Cache

```bash
# Clear PHP opcache
sudo systemctl reload php7.4-fpm
# Or
sudo systemctl reload php8.1-fpm

# Clear Redis cache (if used)
redis-cli -a your_password FLUSHDB
```

### Step 3: Verify Rollback

```bash
# Check inbox loads
curl -I https://yourdomain.com/inbox-v2.php

# Should return 200 OK
# Performance features should be disabled
```

### Step 4: Notify Team

```bash
# Send notification
echo "Performance features disabled at $(date)" | mail -s "Rollback Executed" team@yourdomain.com
```

**Done!** Users will now use the standard inbox without performance features.

## Partial Rollback

Roll back specific features while keeping others enabled.

### Rollback WebSocket Only

If WebSocket is causing issues but other features work:

```sql
-- Disable WebSocket, keep other features
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key = 'websocket_enabled';
```

Users will automatically fall back to polling.

### Rollback for Specific User Group

If issues affect only certain users:

```sql
-- Reduce rollout percentage to 0% (internal team only)
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key = 'performance_rollout_percentage';

-- Or exclude specific users
-- (Add to internal users list to force enable, or remove to disable)
```

### Rollback Virtual Scrolling

If virtual scrolling causes issues:

```javascript
// In inbox-v2.php, temporarily disable virtual scrolling
// Comment out or set to false:
const ENABLE_VIRTUAL_SCROLLING = false;
```

## Full Rollback

Complete rollback including code and database changes.

**Time Required:** 15-30 minutes  
**Impact:** Reverts all performance upgrade changes

### Step 1: Stop WebSocket Server

```bash
# Stop PM2 process
pm2 stop telepharmacy-ws

# Or stop systemd service
sudo systemctl stop telepharmacy-ws

# Verify stopped
pm2 status
# Or
sudo systemctl status telepharmacy-ws
```

### Step 2: Disable Performance Features

```sql
-- Connect to database
mysql -u root -p telepharmacy

-- Disable all performance features
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key IN (
    'performance_upgrade_enabled',
    'websocket_enabled'
);

-- Reset rollout percentage
UPDATE vibe_selling_settings 
SET setting_value = '0' 
WHERE setting_key = 'performance_rollout_percentage';

-- Verify
SELECT setting_key, setting_value 
FROM vibe_selling_settings 
WHERE setting_key LIKE 'performance%' OR setting_key = 'websocket_enabled';

exit;
```

### Step 3: Revert Code Changes (Optional)

If you need to revert code to previous version:

```bash
# Navigate to project directory
cd /var/www/telepharmacy

# Check current branch
git branch

# View recent commits
git log --oneline -10

# Revert to specific commit (before performance upgrade)
git revert <commit-hash>

# Or reset to previous tag
git checkout v1.0.0

# Or restore from backup
cp -r /backup/telepharmacy-pre-performance/* /var/www/telepharmacy/
```

### Step 4: Restore Database (If Needed)

If database changes need to be reverted:

```bash
# Stop application
sudo systemctl stop php7.4-fpm nginx

# Restore database from backup
mysql -u root -p telepharmacy < /backup/telepharmacy-pre-performance.sql

# Verify restoration
mysql -u root -p telepharmacy -e "SHOW TABLES;"

# Start application
sudo systemctl start php7.4-fpm nginx
```

### Step 5: Clear All Caches

```bash
# Clear PHP opcache
sudo systemctl reload php7.4-fpm

# Clear Redis
redis-cli -a your_password FLUSHALL

# Clear browser cache (instruct users)
# Ctrl+Shift+R or Cmd+Shift+R
```

### Step 6: Restart Services

```bash
# Restart PHP-FPM
sudo systemctl restart php7.4-fpm

# Restart Nginx
sudo systemctl restart nginx

# Restart MySQL (if needed)
sudo systemctl restart mysql

# Verify all services running
sudo systemctl status php7.4-fpm nginx mysql
```

### Step 7: Verify System

```bash
# Test inbox access
curl -I https://yourdomain.com/inbox.php

# Test API endpoints
curl https://yourdomain.com/api/inbox-v2.php?action=getConversations

# Check logs for errors
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/php7.4-fpm.log
```

## Post-Rollback Steps

### 1. Document the Issue

Create incident report:

```markdown
# Incident Report: Performance Upgrade Rollback

**Date:** YYYY-MM-DD HH:MM
**Severity:** Critical/Major/Minor
**Duration:** X hours
**Affected Users:** X users (X%)

## Issue Description
[Describe what went wrong]

## Root Cause
[What caused the issue]

## Rollback Actions Taken
- [ ] Disabled performance features
- [ ] Stopped WebSocket server
- [ ] Reverted code changes
- [ ] Restored database
- [ ] Cleared caches

## Impact
- Users affected: X
- Downtime: X minutes
- Data loss: Yes/No

## Prevention
[How to prevent this in the future]

## Next Steps
[What needs to be done]
```

### 2. Notify Stakeholders

```bash
# Email template
Subject: Inbox v2 Performance Features Rolled Back

Dear Team,

The inbox v2 performance upgrade features have been rolled back due to [reason].

Status: All users now using standard inbox
Impact: [describe impact]
Next Steps: [describe next steps]

Timeline:
- Issue detected: [time]
- Rollback initiated: [time]
- Rollback completed: [time]
- System stable: [time]

We will investigate the root cause and provide an update within 24 hours.

Thank you for your patience.
```

### 3. Analyze Logs

```bash
# Collect logs for analysis
mkdir -p /tmp/rollback-analysis

# Copy relevant logs
cp /var/log/nginx/error.log /tmp/rollback-analysis/
cp /var/log/php7.4-fpm.log /tmp/rollback-analysis/
cp ~/.pm2/logs/telepharmacy-ws-error.log /tmp/rollback-analysis/

# Database slow query log
cp /var/log/mysql/slow-query.log /tmp/rollback-analysis/

# Compress for sharing
tar -czf rollback-analysis-$(date +%Y%m%d).tar.gz /tmp/rollback-analysis/
```

### 4. Monitor System

```bash
# Monitor for 24 hours after rollback
watch -n 60 'curl -s https://yourdomain.com/api/inbox-v2.php?action=health | jq'

# Monitor error logs
tail -f /var/log/nginx/error.log | grep -i error

# Monitor system resources
htop
```

### 5. Plan Next Steps

- [ ] Identify root cause
- [ ] Fix the issue
- [ ] Test fix in staging
- [ ] Create new rollout plan
- [ ] Update documentation
- [ ] Schedule re-deployment

## Troubleshooting

### Issue: Rollback doesn't take effect

**Symptoms:**
- Performance features still active after disabling
- Users still see new UI

**Solutions:**

1. Clear all caches:
```bash
# PHP opcache
sudo systemctl reload php7.4-fpm

# Redis
redis-cli -a your_password FLUSHALL

# Browser cache (instruct users)
```

2. Verify database settings:
```sql
SELECT * FROM vibe_selling_settings 
WHERE setting_key LIKE 'performance%';
```

3. Check for cached sessions:
```bash
# Clear PHP sessions
sudo rm -rf /var/lib/php/sessions/*
```

### Issue: WebSocket won't stop

**Symptoms:**
- PM2 shows process still running
- Port 3000 still in use

**Solutions:**

```bash
# Force stop PM2
pm2 delete telepharmacy-ws

# Kill process manually
sudo lsof -i :3000
sudo kill -9 <PID>

# Or stop systemd service
sudo systemctl stop telepharmacy-ws
sudo systemctl disable telepharmacy-ws
```

### Issue: Database restore fails

**Symptoms:**
- Restore command errors
- Tables missing after restore

**Solutions:**

```bash
# Check backup file
ls -lh /backup/telepharmacy-pre-performance.sql

# Test restore to temporary database
mysql -u root -p -e "CREATE DATABASE telepharmacy_test;"
mysql -u root -p telepharmacy_test < /backup/telepharmacy-pre-performance.sql

# If successful, restore to production
mysql -u root -p telepharmacy < /backup/telepharmacy-pre-performance.sql
```

### Issue: Users still see errors

**Symptoms:**
- 500 errors after rollback
- Blank pages
- JavaScript errors

**Solutions:**

1. Check PHP errors:
```bash
sudo tail -f /var/log/php7.4-fpm.log
```

2. Check Nginx errors:
```bash
sudo tail -f /var/log/nginx/error.log
```

3. Check file permissions:
```bash
sudo chown -R www-data:www-data /var/www/telepharmacy
sudo chmod -R 755 /var/www/telepharmacy
```

4. Restart services:
```bash
sudo systemctl restart php7.4-fpm nginx
```

## Rollback Checklist

Use this checklist during rollback:

### Emergency Rollback
- [ ] Disable performance features in database
- [ ] Clear PHP opcache
- [ ] Clear Redis cache
- [ ] Verify inbox loads
- [ ] Notify team

### Full Rollback
- [ ] Stop WebSocket server
- [ ] Disable performance features in database
- [ ] Revert code changes (if needed)
- [ ] Restore database (if needed)
- [ ] Clear all caches
- [ ] Restart services
- [ ] Verify system
- [ ] Document incident
- [ ] Notify stakeholders
- [ ] Analyze logs
- [ ] Monitor system
- [ ] Plan next steps

## Prevention

To avoid needing rollbacks in the future:

1. **Thorough Testing**
   - Test in staging environment
   - Load testing with realistic data
   - Browser compatibility testing
   - Mobile device testing

2. **Gradual Rollout**
   - Start with internal team (1 week)
   - Expand to 10% of users (1 week)
   - Monitor metrics closely
   - Increase gradually (25%, 50%, 100%)

3. **Monitoring**
   - Set up alerts for errors
   - Monitor performance metrics
   - Track user feedback
   - Review logs daily

4. **Backup Strategy**
   - Daily database backups
   - Code version control
   - Configuration backups
   - Test restore procedures

5. **Rollback Plan**
   - Document rollback steps
   - Test rollback procedure
   - Train team on rollback
   - Keep backups accessible

## Additional Resources

- [Deployment Guide](WEBSOCKET_DEPLOYMENT.md)
- [Environment Configuration](ENVIRONMENT_CONFIG.md)
- [Redis Setup](REDIS_SETUP.md)
- [Troubleshooting Guide](../WEBSOCKET_SERVER_README.md#troubleshooting)

## Support

For rollback assistance:
1. Follow this procedure step by step
2. Document all actions taken
3. Collect logs and error messages
4. Contact development team if issues persist

**Emergency Contact:** [Your emergency contact info]

