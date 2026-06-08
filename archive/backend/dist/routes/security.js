"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = securityRoutes;
const zod_1 = require("zod");
const SecurityMonitoringService_1 = require("@/services/SecurityMonitoringService");
const AuditService_1 = require("@/services/AuditService");
const auth_1 = require("@/middleware/auth");
const rbac_1 = require("@/middleware/rbac");
const validation_1 = require("@/middleware/validation");
const rateLimiting_1 = require("@/middleware/rateLimiting");
const logger_1 = require("@/utils/logger");
const securityMetricsSchema = zod_1.z.object({
    days: zod_1.z.number().int().min(1).max(90).default(7),
});
const acknowledgeAlertSchema = zod_1.z.object({
    alertId: zod_1.z.string().uuid(),
    notes: zod_1.z.string().max(500).optional(),
});
const blockIPSchema = zod_1.z.object({
    ip: zod_1.z.string().ip(),
    durationMinutes: zod_1.z.number().int().min(1).max(1440).default(30),
    reason: zod_1.z.string().min(1).max(200),
});
const unblockIPSchema = zod_1.z.object({
    ip: zod_1.z.string().ip(),
    reason: zod_1.z.string().min(1).max(200),
});
async function securityRoutes(fastify) {
    const auditService = new AuditService_1.AuditService(fastify.prisma);
    const securityService = new SecurityMonitoringService_1.SecurityMonitoringService(fastify.prisma, auditService, fastify.redis);
    const rateLimiter = (0, rateLimiting_1.createRateLimitMiddleware)(fastify.redis);
    fastify.addHook('preHandler', auth_1.authenticate);
    fastify.get('/metrics', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_security_metrics', 'admin_access']),
            (0, validation_1.validateQuery)(securityMetricsSchema),
        ],
        schema: {
            description: 'Get security metrics and threat statistics',
            tags: ['Security'],
            querystring: {
                type: 'object',
                properties: {
                    days: { type: 'integer', minimum: 1, maximum: 90, default: 7 },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                totalThreats: { type: 'integer' },
                                activeThreats: { type: 'integer' },
                                blockedIPs: { type: 'integer' },
                                failedLogins: { type: 'integer' },
                                suspiciousRequests: { type: 'integer' },
                                timeRange: {
                                    type: 'object',
                                    properties: {
                                        from: { type: 'string', format: 'date-time' },
                                        to: { type: 'string', format: 'date-time' },
                                    },
                                },
                                threatsByType: { type: 'array' },
                                threatsBySeverity: { type: 'array' },
                                topAttackerIPs: { type: 'array' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { days = 7 } = request.query;
            const user = request.user;
            const metrics = await securityService.getSecurityMetrics(days);
            await auditService.logAction({
                userId: user.userId,
                action: 'security_metrics_viewed',
                resourceType: 'security',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    days,
                    totalThreats: metrics.totalThreats,
                    activeThreats: metrics.activeThreats,
                },
            });
            return reply.send({
                success: true,
                data: metrics,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get security metrics', {
                error: String(error),
                query: request.query,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'SECURITY_METRICS_FAILED',
                    message: 'Failed to retrieve security metrics',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/alerts', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_security_alerts', 'admin_access']),
        ],
        schema: {
            description: 'Get active security alerts',
            tags: ['Security'],
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: { type: 'array' },
                        meta: {
                            type: 'object',
                            properties: {
                                count: { type: 'integer' },
                                criticalCount: { type: 'integer' },
                                highCount: { type: 'integer' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const alerts = await securityService.getActiveAlerts();
            const criticalCount = alerts.filter(a => a.severity === 'critical').length;
            const highCount = alerts.filter(a => a.severity === 'high').length;
            await auditService.logAction({
                userId: user.userId,
                action: 'security_alerts_viewed',
                resourceType: 'security',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    alertCount: alerts.length,
                    criticalCount,
                    highCount,
                },
            });
            return reply.send({
                success: true,
                data: alerts,
                meta: {
                    count: alerts.length,
                    criticalCount,
                    highCount,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get security alerts', {
                error: String(error),
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'SECURITY_ALERTS_FAILED',
                    message: 'Failed to retrieve security alerts',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.put('/alerts/acknowledge', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['acknowledge_security_alerts', 'admin_access']),
            (0, validation_1.validateRequest)(acknowledgeAlertSchema),
        ],
        schema: {
            description: 'Acknowledge a security alert',
            tags: ['Security'],
            body: {
                type: 'object',
                required: ['alertId'],
                properties: {
                    alertId: { type: 'string', format: 'uuid' },
                    notes: { type: 'string', maxLength: 500 },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { alertId, notes } = request.body;
            const user = request.user;
            const success = await securityService.acknowledgeAlert(alertId, user.userId);
            if (!success) {
                return reply.status(404).send({
                    success: false,
                    error: {
                        code: 'ALERT_NOT_FOUND',
                        message: 'Security alert not found',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            await auditService.logAction({
                userId: user.userId,
                action: 'security_alert_acknowledged',
                resourceType: 'security',
                resourceId: alertId,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    alertId,
                    notes,
                },
            });
            return reply.send({
                success: true,
                data: {
                    alertId,
                    acknowledgedBy: user.userId,
                    acknowledgedAt: new Date().toISOString(),
                    message: 'Security alert acknowledged successfully',
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to acknowledge security alert', {
                error: String(error),
                body: request.body,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ALERT_ACKNOWLEDGE_FAILED',
                    message: 'Failed to acknowledge security alert',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/block-ip', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['block_ip_addresses', 'admin_access']),
            (0, validation_1.validateRequest)(blockIPSchema),
        ],
        schema: {
            description: 'Manually block an IP address',
            tags: ['Security'],
            body: {
                type: 'object',
                required: ['ip', 'reason'],
                properties: {
                    ip: { type: 'string', format: 'ipv4' },
                    durationMinutes: { type: 'integer', minimum: 1, maximum: 1440, default: 30 },
                    reason: { type: 'string', minLength: 1, maxLength: 200 },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { ip, durationMinutes = 30, reason } = request.body;
            const user = request.user;
            const whitelistedIPs = ['127.0.0.1', '::1'];
            if (whitelistedIPs.includes(ip)) {
                return reply.status(400).send({
                    success: false,
                    error: {
                        code: 'IP_WHITELISTED',
                        message: 'Cannot block whitelisted IP address',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            const durationMs = durationMinutes * 60 * 1000;
            const key = `blocked:${ip}`;
            const expirationSeconds = Math.ceil(durationMs / 1000);
            const blockData = {
                reason,
                blockedBy: user.userId,
                blockedAt: new Date().toISOString(),
                expiresAt: new Date(Date.now() + durationMs).toISOString(),
                manual: true,
            };
            await fastify.redis.setex(key, expirationSeconds, JSON.stringify(blockData));
            await auditService.logAction({
                userId: user.userId,
                action: 'ip_blocked_manually',
                resourceType: 'security',
                resourceId: ip,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    blockedIP: ip,
                    durationMinutes,
                    reason,
                    expiresAt: blockData.expiresAt,
                },
            });
            await auditService.logSecurityEvent({
                eventType: 'ip_blocked_manually',
                severity: 'medium',
                userId: user.userId,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                details: {
                    blockedIP: ip,
                    reason,
                    durationMinutes,
                    blockedBy: user.userId,
                },
            });
            logger_1.logger.warn('IP blocked manually', {
                ip,
                reason,
                durationMinutes,
                blockedBy: user.userId,
                expiresAt: blockData.expiresAt,
            });
            return reply.send({
                success: true,
                data: {
                    ip,
                    blocked: true,
                    reason,
                    durationMinutes,
                    expiresAt: blockData.expiresAt,
                    blockedBy: user.userId,
                    message: `IP ${ip} blocked for ${durationMinutes} minutes`,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to block IP address', {
                error: String(error),
                body: request.body,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'IP_BLOCK_FAILED',
                    message: 'Failed to block IP address',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.delete('/unblock-ip', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['unblock_ip_addresses', 'admin_access']),
            (0, validation_1.validateRequest)(unblockIPSchema),
        ],
        schema: {
            description: 'Unblock an IP address',
            tags: ['Security'],
            body: {
                type: 'object',
                required: ['ip', 'reason'],
                properties: {
                    ip: { type: 'string', format: 'ipv4' },
                    reason: { type: 'string', minLength: 1, maxLength: 200 },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { ip, reason } = request.body;
            const user = request.user;
            const blockData = await fastify.redis.get(`blocked:${ip}`);
            if (!blockData) {
                return reply.status(404).send({
                    success: false,
                    error: {
                        code: 'IP_NOT_BLOCKED',
                        message: 'IP address is not currently blocked',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            await fastify.redis.del(`blocked:${ip}`);
            await auditService.logAction({
                userId: user.userId,
                action: 'ip_unblocked_manually',
                resourceType: 'security',
                resourceId: ip,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    unblockedIP: ip,
                    reason,
                    previousBlockData: JSON.parse(blockData),
                },
            });
            await auditService.logSecurityEvent({
                eventType: 'ip_unblocked_manually',
                severity: 'low',
                userId: user.userId,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                details: {
                    unblockedIP: ip,
                    reason,
                    unblockedBy: user.userId,
                },
            });
            logger_1.logger.info('IP unblocked manually', {
                ip,
                reason,
                unblockedBy: user.userId,
            });
            return reply.send({
                success: true,
                data: {
                    ip,
                    unblocked: true,
                    reason,
                    unblockedBy: user.userId,
                    unblockedAt: new Date().toISOString(),
                    message: `IP ${ip} unblocked successfully`,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to unblock IP address', {
                error: String(error),
                body: request.body,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'IP_UNBLOCK_FAILED',
                    message: 'Failed to unblock IP address',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/blocked-ips', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_blocked_ips', 'admin_access']),
        ],
        schema: {
            description: 'Get list of currently blocked IP addresses',
            tags: ['Security'],
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: { type: 'array' },
                        meta: {
                            type: 'object',
                            properties: {
                                count: { type: 'integer' },
                                manualBlocks: { type: 'integer' },
                                automaticBlocks: { type: 'integer' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const blockedKeys = await fastify.redis.keys('blocked:*');
            const blockedIPs = [];
            for (const key of blockedKeys) {
                const ip = key.replace('blocked:', '');
                const blockData = await fastify.redis.get(key);
                const ttl = await fastify.redis.ttl(key);
                if (blockData) {
                    const data = JSON.parse(blockData);
                    blockedIPs.push({
                        ip,
                        ...data,
                        remainingSeconds: ttl > 0 ? ttl : 0,
                    });
                }
            }
            blockedIPs.sort((a, b) => new Date(b.blockedAt).getTime() - new Date(a.blockedAt).getTime());
            const manualBlocks = blockedIPs.filter(block => block.manual).length;
            const automaticBlocks = blockedIPs.length - manualBlocks;
            await auditService.logAction({
                userId: user.userId,
                action: 'blocked_ips_viewed',
                resourceType: 'security',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    totalBlocked: blockedIPs.length,
                    manualBlocks,
                    automaticBlocks,
                },
            });
            return reply.send({
                success: true,
                data: blockedIPs,
                meta: {
                    count: blockedIPs.length,
                    manualBlocks,
                    automaticBlocks,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get blocked IPs', {
                error: String(error),
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'BLOCKED_IPS_FAILED',
                    message: 'Failed to retrieve blocked IP addresses',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/rate-limit-stats', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_rate_limit_stats', 'admin_access']),
        ],
        schema: {
            description: 'Get rate limiting statistics',
            tags: ['Security'],
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const stats = await rateLimiter.service.getStatistics();
            await auditService.logAction({
                userId: user.userId,
                action: 'rate_limit_stats_viewed',
                resourceType: 'security',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: stats,
            });
            return reply.send({
                success: true,
                data: stats,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get rate limit statistics', {
                error: String(error),
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'RATE_LIMIT_STATS_FAILED',
                    message: 'Failed to retrieve rate limiting statistics',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
}
//# sourceMappingURL=security.js.map