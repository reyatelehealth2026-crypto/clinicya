"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.SecurityMonitoringService = void 0;
const BaseService_1 = require("./BaseService");
const logger_1 = require("@/utils/logger");
class SecurityMonitoringService extends BaseService_1.BaseService {
    auditService;
    redis;
    threats = new Map();
    alerts = new Map();
    thresholds = {
        bruteForce: {
            failedAttempts: 5,
            timeWindowMs: 15 * 60 * 1000,
        },
        suspiciousActivity: {
            requestsPerMinute: 300,
            timeWindowMs: 60 * 1000,
        },
        rateLimitViolations: {
            violationsPerHour: 10,
            timeWindowMs: 60 * 60 * 1000,
        },
    };
    constructor(prisma, auditService, redisClient) {
        super(prisma);
        this.auditService = auditService;
        this.redis = redisClient;
    }
    async detectThreat(type, source, details) {
        try {
            const threatId = this.generateUUID();
            const severity = this.calculateThreatSeverity(type, details);
            const threat = {
                id: threatId,
                type,
                severity,
                source,
                details,
                detectedAt: new Date(),
                status: 'active',
                mitigationActions: [],
            };
            this.threats.set(threatId, threat);
            await this.storeThreatInRedis(threat);
            await this.auditService.logSecurityEvent({
                eventType: `threat_detected_${type}`,
                severity,
                userId: source.userId,
                ipAddress: source.ip,
                userAgent: source.userAgent,
                details: {
                    threatId,
                    threatType: type,
                    ...details,
                },
            });
            await this.applyAutomaticMitigation(threat);
            if (severity === 'high' || severity === 'critical') {
                await this.createSecurityAlert('threat_detected', severity, `${type.replace('_', ' ').toUpperCase()} threat detected from ${source.ip}`, {
                    threatId,
                    threatType: type,
                    source,
                    details,
                });
            }
            logger_1.logger.warn('Security threat detected', {
                threatId,
                type,
                severity,
                source,
                details,
            });
            return threat;
        }
        catch (error) {
            logger_1.logger.error('Failed to detect threat', {
                error: String(error),
                type,
                source,
                details,
            });
            return null;
        }
    }
    async monitorBruteForce(ip, userId) {
        try {
            const key = `brute_force:${ip}`;
            const now = Date.now();
            const windowStart = now - this.thresholds.bruteForce.timeWindowMs;
            const attempts = await this.redis.zrangebyscore(key, windowStart, now);
            if (attempts.length >= this.thresholds.bruteForce.failedAttempts) {
                await this.detectThreat('brute_force', { ip, userId }, {
                    failedAttempts: attempts.length,
                    timeWindow: this.thresholds.bruteForce.timeWindowMs,
                    attempts: attempts.slice(-5),
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to monitor brute force', {
                error: String(error),
                ip,
                userId,
            });
        }
    }
    async monitorSqlInjection(ip, userAgent, payload, userId) {
        try {
            const suspiciousPatterns = [
                /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b.*\b(FROM|WHERE|INTO|VALUES|SET)\b)/gi,
                /(--|\/\*|\*\/|;)\s*(SELECT|INSERT|UPDATE|DELETE|DROP)/gi,
                /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
                /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
                /'.*(\bOR\b|\bAND\b).*'/gi,
            ];
            const payloadString = JSON.stringify(payload);
            const detectedPatterns = suspiciousPatterns.filter(pattern => pattern.test(payloadString));
            if (detectedPatterns.length > 0) {
                await this.detectThreat('sql_injection', { ip, userAgent, userId }, {
                    payload: payloadString.substring(0, 1000),
                    detectedPatterns: detectedPatterns.map(p => p.source),
                    payloadSize: payloadString.length,
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to monitor SQL injection', {
                error: String(error),
                ip,
                userId,
            });
        }
    }
    async monitorXssAttempts(ip, userAgent, payload, userId) {
        try {
            const xssPatterns = [
                /<script[^>]*>.*?<\/script>/gi,
                /javascript:/gi,
                /on\w+\s*=\s*["'][^"']*["']/gi,
                /<iframe[^>]*>.*?<\/iframe>/gi,
                /<object[^>]*>.*?<\/object>/gi,
                /<embed[^>]*>/gi,
                /data:text\/html/gi,
            ];
            const payloadString = JSON.stringify(payload);
            const detectedPatterns = xssPatterns.filter(pattern => pattern.test(payloadString));
            if (detectedPatterns.length > 0) {
                await this.detectThreat('xss_attempt', { ip, userAgent, userId }, {
                    payload: payloadString.substring(0, 1000),
                    detectedPatterns: detectedPatterns.map(p => p.source),
                    payloadSize: payloadString.length,
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to monitor XSS attempts', {
                error: String(error),
                ip,
                userId,
            });
        }
    }
    async monitorSuspiciousActivity(ip, userAgent, userId) {
        try {
            const key = `suspicious_activity:${ip}`;
            const now = Date.now();
            const windowStart = now - this.thresholds.suspiciousActivity.timeWindowMs;
            const requestCount = await this.redis.zcount(key, windowStart, now);
            if (requestCount >= this.thresholds.suspiciousActivity.requestsPerMinute) {
                await this.detectThreat('suspicious_activity', { ip, userAgent, userId }, {
                    requestCount,
                    timeWindow: this.thresholds.suspiciousActivity.timeWindowMs,
                    threshold: this.thresholds.suspiciousActivity.requestsPerMinute,
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to monitor suspicious activity', {
                error: String(error),
                ip,
                userId,
            });
        }
    }
    async applyAutomaticMitigation(threat) {
        try {
            const mitigationActions = [];
            switch (threat.type) {
                case 'brute_force':
                    await this.blockIP(threat.source.ip, 30 * 60 * 1000, 'brute_force_detected');
                    mitigationActions.push('ip_blocked_30min');
                    break;
                case 'sql_injection':
                case 'xss_attempt':
                    await this.blockIP(threat.source.ip, 60 * 60 * 1000, `${threat.type}_detected`);
                    mitigationActions.push('ip_blocked_1hour');
                    break;
                case 'suspicious_activity':
                    if (threat.severity === 'high' || threat.severity === 'critical') {
                        await this.blockIP(threat.source.ip, 15 * 60 * 1000, 'suspicious_activity');
                        mitigationActions.push('ip_blocked_15min');
                    }
                    break;
                case 'rate_limit_violation':
                    mitigationActions.push('rate_limit_applied');
                    break;
            }
            threat.mitigationActions = mitigationActions;
            threat.status = mitigationActions.length > 0 ? 'mitigated' : 'active';
            this.threats.set(threat.id, threat);
            await this.storeThreatInRedis(threat);
            if (mitigationActions.length > 0) {
                logger_1.logger.info('Automatic mitigation applied', {
                    threatId: threat.id,
                    threatType: threat.type,
                    mitigationActions,
                    source: threat.source,
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to apply automatic mitigation', {
                error: String(error),
                threatId: threat.id,
                threatType: threat.type,
            });
        }
    }
    async blockIP(ip, durationMs, reason) {
        const key = `blocked:${ip}`;
        const expirationSeconds = Math.ceil(durationMs / 1000);
        const blockData = {
            reason,
            blockedAt: new Date().toISOString(),
            expiresAt: new Date(Date.now() + durationMs).toISOString(),
        };
        await this.redis.setex(key, expirationSeconds, JSON.stringify(blockData));
        logger_1.logger.warn('IP blocked', {
            ip,
            reason,
            durationMs,
            expiresAt: blockData.expiresAt,
        });
    }
    calculateThreatSeverity(type, details) {
        switch (type) {
            case 'sql_injection':
            case 'xss_attempt':
                return 'high';
            case 'brute_force':
                const attempts = details.failedAttempts || 0;
                if (attempts >= 20)
                    return 'critical';
                if (attempts >= 10)
                    return 'high';
                return 'medium';
            case 'suspicious_activity':
                const requestCount = details.requestCount || 0;
                if (requestCount >= 1000)
                    return 'critical';
                if (requestCount >= 500)
                    return 'high';
                return 'medium';
            case 'rate_limit_violation':
                return 'low';
            case 'unauthorized_access':
                return 'high';
            default:
                return 'medium';
        }
    }
    async createSecurityAlert(type, severity, message, details) {
        const alertId = this.generateUUID();
        const alert = {
            id: alertId,
            type,
            severity,
            message,
            details,
            createdAt: new Date(),
            acknowledged: false,
        };
        this.alerts.set(alertId, alert);
        await this.storeAlertInRedis(alert);
        logger_1.logger.warn('Security alert created', {
            alertId,
            type,
            severity,
            message,
        });
        return alertId;
    }
    async getSecurityMetrics(days = 7) {
        try {
            const toDate = new Date();
            const fromDate = new Date();
            fromDate.setDate(fromDate.getDate() - days);
            const threatKeys = await this.redis.keys('threat:*');
            const threats = [];
            for (const key of threatKeys) {
                const threatData = await this.redis.get(key);
                if (threatData) {
                    const threat = JSON.parse(threatData);
                    if (new Date(threat.detectedAt) >= fromDate) {
                        threats.push(threat);
                    }
                }
            }
            const totalThreats = threats.length;
            const activeThreats = threats.filter(t => t.status === 'active').length;
            const blockedIPKeys = await this.redis.keys('blocked:*');
            const blockedIPs = blockedIPKeys.length;
            const failedLogins = await this.prisma.auditLog.count({
                where: {
                    action: 'login',
                    success: false,
                    createdAt: {
                        gte: fromDate,
                        lte: toDate,
                    },
                },
            });
            const suspiciousRequests = threats.filter(t => t.type === 'suspicious_activity').length;
            const threatsByType = threats.reduce((acc, threat) => {
                const existing = acc.find(item => item.type === threat.type);
                if (existing) {
                    existing.count++;
                }
                else {
                    acc.push({ type: threat.type, count: 1 });
                }
                return acc;
            }, []);
            const threatsBySeverity = threats.reduce((acc, threat) => {
                const existing = acc.find(item => item.severity === threat.severity);
                if (existing) {
                    existing.count++;
                }
                else {
                    acc.push({ severity: threat.severity, count: 1 });
                }
                return acc;
            }, []);
            const ipCounts = threats.reduce((acc, threat) => {
                const ip = threat.source.ip;
                if (acc[ip]) {
                    acc[ip].count++;
                    if (new Date(threat.detectedAt) > acc[ip].lastSeen) {
                        acc[ip].lastSeen = new Date(threat.detectedAt);
                    }
                }
                else {
                    acc[ip] = {
                        count: 1,
                        lastSeen: new Date(threat.detectedAt),
                    };
                }
                return acc;
            }, {});
            const topAttackerIPs = Object.entries(ipCounts)
                .map(([ip, data]) => ({
                ip,
                threatCount: data.count,
                lastSeen: data.lastSeen,
            }))
                .sort((a, b) => b.threatCount - a.threatCount)
                .slice(0, 10);
            return {
                totalThreats,
                activeThreats,
                blockedIPs,
                failedLogins,
                suspiciousRequests,
                timeRange: { from: fromDate, to: toDate },
                threatsByType,
                threatsBySeverity,
                topAttackerIPs,
            };
        }
        catch (error) {
            logger_1.logger.error('Failed to get security metrics', {
                error: String(error),
                days,
            });
            throw error;
        }
    }
    async getActiveAlerts() {
        try {
            const alertKeys = await this.redis.keys('alert:*');
            const alerts = [];
            for (const key of alertKeys) {
                const alertData = await this.redis.get(key);
                if (alertData) {
                    const alert = JSON.parse(alertData);
                    if (!alert.acknowledged) {
                        alerts.push(alert);
                    }
                }
            }
            return alerts.sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());
        }
        catch (error) {
            logger_1.logger.error('Failed to get active alerts', {
                error: String(error),
            });
            return [];
        }
    }
    async acknowledgeAlert(alertId, acknowledgedBy) {
        try {
            const alert = this.alerts.get(alertId);
            if (!alert) {
                const alertData = await this.redis.get(`alert:${alertId}`);
                if (!alertData)
                    return false;
                const parsedAlert = JSON.parse(alertData);
                this.alerts.set(alertId, parsedAlert);
            }
            const updatedAlert = this.alerts.get(alertId);
            updatedAlert.acknowledged = true;
            updatedAlert.acknowledgedBy = acknowledgedBy;
            updatedAlert.acknowledgedAt = new Date();
            this.alerts.set(alertId, updatedAlert);
            await this.storeAlertInRedis(updatedAlert);
            logger_1.logger.info('Security alert acknowledged', {
                alertId,
                acknowledgedBy,
            });
            return true;
        }
        catch (error) {
            logger_1.logger.error('Failed to acknowledge alert', {
                error: String(error),
                alertId,
                acknowledgedBy,
            });
            return false;
        }
    }
    async storeThreatInRedis(threat) {
        const key = `threat:${threat.id}`;
        const expirationSeconds = 7 * 24 * 60 * 60;
        await this.redis.setex(key, expirationSeconds, JSON.stringify(threat));
    }
    async storeAlertInRedis(alert) {
        const key = `alert:${alert.id}`;
        const expirationSeconds = 30 * 24 * 60 * 60;
        await this.redis.setex(key, expirationSeconds, JSON.stringify(alert));
    }
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
}
exports.SecurityMonitoringService = SecurityMonitoringService;
//# sourceMappingURL=SecurityMonitoringService.js.map