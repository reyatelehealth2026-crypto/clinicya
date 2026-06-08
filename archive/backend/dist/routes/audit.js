"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = auditRoutes;
const zod_1 = require("zod");
const AuditService_1 = require("@/services/AuditService");
const auth_1 = require("@/middleware/auth");
const rbac_1 = require("@/middleware/rbac");
const validation_1 = require("@/middleware/validation");
const security_1 = require("@/middleware/security");
const rateLimiting_1 = require("@/middleware/rateLimiting");
const logger_1 = require("@/utils/logger");
const auditQuerySchema = security_1.validationSchemas.auditLogQuery;
const securityEventSchema = zod_1.z.object({
    eventType: zod_1.z.string().min(1).max(100),
    severity: zod_1.z.enum(['low', 'medium', 'high', 'critical']),
    details: zod_1.z.record(zod_1.z.any()),
    userId: zod_1.z.string().uuid().optional(),
});
const auditReportSchema = zod_1.z.object({
    dateFrom: zod_1.z.string().datetime(),
    dateTo: zod_1.z.string().datetime(),
    format: zod_1.z.enum(['json', 'csv']).default('json'),
});
async function auditRoutes(fastify) {
    const auditService = new AuditService_1.AuditService(fastify.prisma);
    const rateLimiter = (0, rateLimiting_1.createRateLimitMiddleware)(fastify.redis);
    fastify.addHook('preHandler', auth_1.authenticate);
    fastify.get('/logs', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_audit_logs', 'admin_access']),
            (0, validation_1.validateQuery)(auditQuerySchema),
        ],
        schema: {
            description: 'Query audit logs with filters',
            tags: ['Audit'],
            querystring: {
                type: 'object',
                properties: {
                    userId: { type: 'string', format: 'uuid' },
                    action: { type: 'string' },
                    resourceType: { type: 'string' },
                    resourceId: { type: 'string', format: 'uuid' },
                    dateFrom: { type: 'string', format: 'date-time' },
                    dateTo: { type: 'string', format: 'date-time' },
                    success: { type: 'boolean' },
                    page: { type: 'integer', minimum: 1, default: 1 },
                    limit: { type: 'integer', minimum: 1, maximum: 100, default: 50 },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: { type: 'array' },
                        meta: {
                            type: 'object',
                            properties: {
                                total: { type: 'integer' },
                                page: { type: 'integer' },
                                limit: { type: 'integer' },
                                totalPages: { type: 'integer' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const query = request.query;
            const user = request.user;
            if (query.dateFrom)
                query.dateFrom = new Date(query.dateFrom);
            if (query.dateTo)
                query.dateTo = new Date(query.dateTo);
            if (!user.permissions.includes('admin_access') && !user.permissions.includes('view_all_audit_logs')) {
                query.userId = user.userId;
            }
            const result = await auditService.queryAuditLogs(query);
            const totalPages = Math.ceil(result.total / result.limit);
            await auditService.logAction({
                userId: user.userId,
                action: 'audit_logs_viewed',
                resourceType: 'audit',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    query: query,
                    resultCount: result.data.length,
                },
            });
            return reply.send({
                success: true,
                data: result.data,
                meta: {
                    total: result.total,
                    page: result.page,
                    limit: result.limit,
                    totalPages,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to query audit logs', {
                error: String(error),
                query: request.query,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'AUDIT_QUERY_FAILED',
                    message: 'Failed to retrieve audit logs',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/trail/:resourceType/:resourceId', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_audit_logs', 'admin_access']),
        ],
        schema: {
            description: 'Get audit trail for a specific resource',
            tags: ['Audit'],
            params: {
                type: 'object',
                required: ['resourceType', 'resourceId'],
                properties: {
                    resourceType: { type: 'string' },
                    resourceId: { type: 'string' },
                },
            },
            querystring: {
                type: 'object',
                properties: {
                    limit: { type: 'integer', minimum: 1, maximum: 100, default: 50 },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { resourceType, resourceId } = request.params;
            const { limit = 50 } = request.query;
            const user = request.user;
            const auditTrail = await auditService.getAuditTrail(resourceType, resourceId, limit);
            await auditService.logAction({
                userId: user.userId,
                action: 'audit_trail_viewed',
                resourceType: 'audit',
                resourceId: `${resourceType}:${resourceId}`,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    targetResourceType: resourceType,
                    targetResourceId: resourceId,
                    resultCount: auditTrail.length,
                },
            });
            return reply.send({
                success: true,
                data: auditTrail,
                meta: {
                    resourceType,
                    resourceId,
                    count: auditTrail.length,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get audit trail', {
                error: String(error),
                params: request.params,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'AUDIT_TRAIL_FAILED',
                    message: 'Failed to retrieve audit trail',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/security-event', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['log_security_events', 'admin_access']),
            (0, validation_1.validateRequest)(securityEventSchema),
        ],
        schema: {
            description: 'Log a security event',
            tags: ['Audit'],
            body: {
                type: 'object',
                required: ['eventType', 'severity', 'details'],
                properties: {
                    eventType: { type: 'string' },
                    severity: { type: 'string', enum: ['low', 'medium', 'high', 'critical'] },
                    details: { type: 'object' },
                    userId: { type: 'string', format: 'uuid' },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const body = request.body;
            const user = request.user;
            const eventId = await auditService.logSecurityEvent({
                eventType: body.eventType,
                severity: body.severity,
                userId: body.userId || user.userId,
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                details: body.details,
            });
            return reply.send({
                success: true,
                data: {
                    eventId,
                    message: 'Security event logged successfully',
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to log security event', {
                error: String(error),
                body: request.body,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'SECURITY_EVENT_LOG_FAILED',
                    message: 'Failed to log security event',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/report', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['generate_audit_reports', 'admin_access']),
            (0, validation_1.validateQuery)(auditReportSchema),
        ],
        schema: {
            description: 'Generate comprehensive audit report',
            tags: ['Audit'],
            querystring: {
                type: 'object',
                required: ['dateFrom', 'dateTo'],
                properties: {
                    dateFrom: { type: 'string', format: 'date-time' },
                    dateTo: { type: 'string', format: 'date-time' },
                    format: { type: 'string', enum: ['json', 'csv'], default: 'json' },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { dateFrom, dateTo, format = 'json' } = request.query;
            const user = request.user;
            const fromDate = new Date(dateFrom);
            const toDate = new Date(dateTo);
            if (fromDate >= toDate) {
                return reply.status(400).send({
                    success: false,
                    error: {
                        code: 'INVALID_DATE_RANGE',
                        message: 'dateFrom must be before dateTo',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            const maxRangeDays = 90;
            const rangeDays = Math.ceil((toDate.getTime() - fromDate.getTime()) / (1000 * 60 * 60 * 24));
            if (rangeDays > maxRangeDays) {
                return reply.status(400).send({
                    success: false,
                    error: {
                        code: 'DATE_RANGE_TOO_LARGE',
                        message: `Date range cannot exceed ${maxRangeDays} days`,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            if (format === 'csv') {
                const csvContent = await auditService.exportAuditLogs({
                    dateFrom: fromDate,
                    dateTo: toDate,
                    limit: 10000,
                });
                await auditService.logAction({
                    userId: user.userId,
                    action: 'audit_report_generated',
                    resourceType: 'audit',
                    ipAddress: request.ip,
                    userAgent: request.headers['user-agent'],
                    success: true,
                    metadata: {
                        format: 'csv',
                        dateRange: { from: fromDate, to: toDate },
                        rangeDays,
                    },
                });
                reply.header('Content-Type', 'text/csv');
                reply.header('Content-Disposition', `attachment; filename="audit-report-${fromDate.toISOString().split('T')[0]}-to-${toDate.toISOString().split('T')[0]}.csv"`);
                return reply.send(csvContent);
            }
            else {
                const report = await auditService.generateAuditReport(fromDate, toDate);
                await auditService.logAction({
                    userId: user.userId,
                    action: 'audit_report_generated',
                    resourceType: 'audit',
                    ipAddress: request.ip,
                    userAgent: request.headers['user-agent'],
                    success: true,
                    metadata: {
                        format: 'json',
                        dateRange: { from: fromDate, to: toDate },
                        rangeDays,
                        totalEntries: report.totalEntries,
                    },
                });
                return reply.send({
                    success: true,
                    data: report,
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to generate audit report', {
                error: String(error),
                query: request.query,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'AUDIT_REPORT_FAILED',
                    message: 'Failed to generate audit report',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/stats', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['view_audit_stats', 'admin_access']),
        ],
        schema: {
            description: 'Get audit statistics',
            tags: ['Audit'],
            querystring: {
                type: 'object',
                properties: {
                    days: { type: 'integer', minimum: 1, maximum: 90, default: 7 },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { days = 7 } = request.query;
            const user = request.user;
            const toDate = new Date();
            const fromDate = new Date();
            fromDate.setDate(fromDate.getDate() - days);
            const report = await auditService.generateAuditReport(fromDate, toDate);
            const stats = {
                totalEntries: report.totalEntries,
                successRate: report.totalEntries > 0 ?
                    ((report.successfulActions / report.totalEntries) * 100).toFixed(2) : '0.00',
                failedActions: report.failedActions,
                topActions: report.topActions.slice(0, 5),
                securityEvents: report.securityEvents,
                timeRange: report.timeRange,
            };
            await auditService.logAction({
                userId: user.userId,
                action: 'audit_stats_viewed',
                resourceType: 'audit',
                ipAddress: request.ip,
                userAgent: request.headers['user-agent'],
                success: true,
                metadata: {
                    days,
                    totalEntries: stats.totalEntries,
                },
            });
            return reply.send({
                success: true,
                data: stats,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get audit statistics', {
                error: String(error),
                query: request.query,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'AUDIT_STATS_FAILED',
                    message: 'Failed to retrieve audit statistics',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.delete('/cleanup', {
        preHandler: [
            rateLimiter.api,
            (0, rbac_1.requirePermission)(['manage_audit_retention', 'admin_access']),
        ],
        schema: {
            description: 'Clean up old audit logs based on retention policy',
            tags: ['Audit'],
            querystring: {
                type: 'object',
                properties: {
                    retentionDays: { type: 'integer', minimum: 30, maximum: 2555, default: 365 },
                    dryRun: { type: 'boolean', default: false },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { retentionDays = 365, dryRun = false } = request.query;
            const user = request.user;
            if (dryRun) {
                const cutoffDate = new Date();
                cutoffDate.setDate(cutoffDate.getDate() - retentionDays);
                const criticalActions = ['login', 'logout', 'payment_process', 'security_event'];
                const countToDelete = await fastify.prisma.auditLog.count({
                    where: {
                        createdAt: {
                            lt: cutoffDate,
                        },
                        action: {
                            notIn: criticalActions,
                        },
                    },
                });
                return reply.send({
                    success: true,
                    data: {
                        dryRun: true,
                        wouldDelete: countToDelete,
                        retentionDays,
                        cutoffDate: cutoffDate.toISOString(),
                    },
                });
            }
            else {
                const deletedCount = await auditService.cleanupOldLogs(retentionDays);
                return reply.send({
                    success: true,
                    data: {
                        deletedCount,
                        retentionDays,
                        message: `Successfully cleaned up ${deletedCount} old audit log entries`,
                    },
                });
            }
        }
        catch (error) {
            logger_1.logger.error('Failed to cleanup audit logs', {
                error: String(error),
                query: request.query,
                userId: request.user?.userId,
            });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'AUDIT_CLEANUP_FAILED',
                    message: 'Failed to cleanup audit logs',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
}
//# sourceMappingURL=audit.js.map