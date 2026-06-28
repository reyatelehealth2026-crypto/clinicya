"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.AuditService = void 0;
const BaseService_1 = require("./BaseService");
const logger_1 = require("@/utils/logger");
class AuditService extends BaseService_1.BaseService {
    constructor(prisma) {
        super(prisma);
    }
    async logAction(entry) {
        try {
            const auditId = this.generateUUID();
            const auditEntry = await this.prisma.auditLog.create({
                data: {
                    id: auditId,
                    userId: entry.userId,
                    action: entry.action,
                    resourceType: entry.resourceType,
                    resourceId: entry.resourceId,
                    oldValues: entry.oldValues ? JSON.stringify(entry.oldValues) : null,
                    newValues: entry.newValues ? JSON.stringify(entry.newValues) : null,
                    ipAddress: entry.ipAddress,
                    userAgent: entry.userAgent,
                    sessionId: entry.sessionId,
                    requestId: entry.requestId || this.generateRequestId(),
                    success: entry.success,
                    errorMessage: entry.errorMessage,
                    metadata: entry.metadata ? JSON.stringify(entry.metadata) : null,
                },
            });
            logger_1.logger.info('Audit event logged', {
                auditId,
                userId: entry.userId,
                action: entry.action,
                resourceType: entry.resourceType,
                success: entry.success,
            });
            return auditId;
        }
        catch (error) {
            logger_1.logger.error('Failed to log audit event', {
                error: String(error),
                entry,
            });
            throw error;
        }
    }
    async logLogin(userId, success, ipAddress, userAgent, errorMessage) {
        return this.logAction({
            userId,
            action: 'login',
            resourceType: 'authentication',
            resourceId: userId,
            newValues: { timestamp: new Date().toISOString() },
            ipAddress,
            userAgent,
            success,
            errorMessage,
            metadata: {
                loginMethod: 'jwt',
                ipAddress,
                userAgent,
            },
        });
    }
    async logLogout(userId, sessionId, ipAddress) {
        return this.logAction({
            userId,
            action: 'logout',
            resourceType: 'authentication',
            resourceId: userId,
            newValues: { sessionId, timestamp: new Date().toISOString() },
            ipAddress,
            success: true,
            metadata: {
                logoutType: 'user_initiated',
                sessionId,
            },
        });
    }
    async logTokenRefresh(userId, oldTokenHash, newTokenHash, ipAddress) {
        return this.logAction({
            userId,
            action: 'token_refresh',
            resourceType: 'authentication',
            resourceId: userId,
            oldValues: { tokenHash: oldTokenHash.substring(0, 8) + '...' },
            newValues: { tokenHash: newTokenHash.substring(0, 8) + '...' },
            ipAddress,
            success: true,
            metadata: {
                refreshTimestamp: new Date().toISOString(),
            },
        });
    }
    async logOrderUpdate(userId, orderId, oldValues, newValues, ipAddress) {
        return this.logAction({
            userId,
            action: 'order_update',
            resourceType: 'order',
            resourceId: orderId,
            oldValues,
            newValues,
            ipAddress,
            success: true,
            metadata: {
                updateType: 'status_change',
                orderId,
            },
        });
    }
    async logOrderCreation(userId, orderId, orderData, ipAddress) {
        return this.logAction({
            userId,
            action: 'order_create',
            resourceType: 'order',
            resourceId: orderId,
            newValues: orderData,
            ipAddress,
            success: true,
            metadata: {
                creationSource: 'dashboard',
                orderId,
            },
        });
    }
    async logPaymentProcessing(userId, paymentId, paymentData, success, errorMessage, ipAddress) {
        return this.logAction({
            userId,
            action: 'payment_process',
            resourceType: 'payment',
            resourceId: paymentId,
            newValues: paymentData,
            ipAddress,
            success,
            errorMessage,
            metadata: {
                paymentMethod: paymentData.method || 'unknown',
                amount: paymentData.amount || 0,
                currency: paymentData.currency || 'THB',
            },
        });
    }
    async logPaymentSlipUpload(userId, slipId, uploadData, ipAddress) {
        return this.logAction({
            userId,
            action: 'payment_slip_upload',
            resourceType: 'payment_slip',
            resourceId: slipId,
            newValues: uploadData,
            ipAddress,
            success: true,
            metadata: {
                fileSize: uploadData.fileSize || 0,
                fileType: uploadData.fileType || 'unknown',
                fileName: uploadData.fileName || 'unknown',
            },
        });
    }
    async logWebhookEvent(userId, webhookId, eventType, payload, success, errorMessage) {
        return this.logAction({
            userId,
            action: 'webhook_process',
            resourceType: 'webhook',
            resourceId: webhookId,
            newValues: { eventType, payload },
            success,
            errorMessage,
            metadata: {
                webhookType: eventType,
                payloadSize: JSON.stringify(payload).length,
            },
        });
    }
    async logSecurityEvent(event) {
        try {
            const eventId = this.generateUUID();
            await this.prisma.securityEvent.create({
                data: {
                    id: eventId,
                    eventType: event.eventType,
                    severity: event.severity,
                    userId: event.userId,
                    ipAddress: event.ipAddress,
                    userAgent: event.userAgent,
                    details: JSON.stringify(event.details),
                },
            });
            if (event.userId) {
                await this.logAction({
                    userId: event.userId,
                    action: 'security_event',
                    resourceType: 'security',
                    resourceId: eventId,
                    newValues: {
                        eventType: event.eventType,
                        severity: event.severity,
                    },
                    ipAddress: event.ipAddress,
                    userAgent: event.userAgent,
                    success: true,
                    metadata: event.details,
                });
            }
            logger_1.logger.warn('Security event logged', {
                eventId,
                eventType: event.eventType,
                severity: event.severity,
                userId: event.userId,
                ipAddress: event.ipAddress,
            });
            return eventId;
        }
        catch (error) {
            logger_1.logger.error('Failed to log security event', {
                error: String(error),
                event,
            });
            throw error;
        }
    }
    async getAuditTrail(resourceType, resourceId, limit = 50) {
        try {
            const auditEntries = await this.prisma.auditLog.findMany({
                where: {
                    resourceType,
                    resourceId,
                },
                include: {
                    user: {
                        select: {
                            username: true,
                            email: true,
                            role: true,
                        },
                    },
                },
                orderBy: {
                    createdAt: 'desc',
                },
                take: limit,
            });
            return auditEntries.map(entry => ({
                ...entry,
                oldValues: entry.oldValues ? JSON.parse(entry.oldValues) : null,
                newValues: entry.newValues ? JSON.parse(entry.newValues) : null,
                metadata: entry.metadata ? JSON.parse(entry.metadata) : null,
            }));
        }
        catch (error) {
            logger_1.logger.error('Failed to retrieve audit trail', {
                error: String(error),
                resourceType,
                resourceId,
            });
            return [];
        }
    }
    async queryAuditLogs(query) {
        try {
            const where = {};
            if (query.userId)
                where.userId = query.userId;
            if (query.action)
                where.action = { contains: query.action };
            if (query.resourceType)
                where.resourceType = query.resourceType;
            if (query.resourceId)
                where.resourceId = query.resourceId;
            if (query.success !== undefined)
                where.success = query.success;
            if (query.dateFrom || query.dateTo) {
                where.createdAt = {};
                if (query.dateFrom)
                    where.createdAt.gte = query.dateFrom;
                if (query.dateTo)
                    where.createdAt.lte = query.dateTo;
            }
            const page = query.page || 1;
            const limit = Math.min(query.limit || 50, 100);
            const skip = (page - 1) * limit;
            const [auditEntries, total] = await Promise.all([
                this.prisma.auditLog.findMany({
                    where,
                    include: {
                        user: {
                            select: {
                                username: true,
                                email: true,
                                role: true,
                            },
                        },
                    },
                    orderBy: {
                        createdAt: 'desc',
                    },
                    skip,
                    take: limit,
                }),
                this.prisma.auditLog.count({ where }),
            ]);
            const data = auditEntries.map(entry => ({
                ...entry,
                oldValues: entry.oldValues ? JSON.parse(entry.oldValues) : null,
                newValues: entry.newValues ? JSON.parse(entry.newValues) : null,
                metadata: entry.metadata ? JSON.parse(entry.metadata) : null,
            }));
            return { data, total, page, limit };
        }
        catch (error) {
            logger_1.logger.error('Failed to query audit logs', {
                error: String(error),
                query,
            });
            throw error;
        }
    }
    async generateAuditReport(dateFrom, dateTo) {
        try {
            const where = {
                createdAt: {
                    gte: dateFrom,
                    lte: dateTo,
                },
            };
            const [totalEntries, successfulActions, failedActions] = await Promise.all([
                this.prisma.auditLog.count({ where }),
                this.prisma.auditLog.count({ where: { ...where, success: true } }),
                this.prisma.auditLog.count({ where: { ...where, success: false } }),
            ]);
            const topActionsRaw = await this.prisma.auditLog.groupBy({
                by: ['action'],
                where,
                _count: {
                    action: true,
                },
                orderBy: {
                    _count: {
                        action: 'desc',
                    },
                },
                take: 10,
            });
            const topActions = topActionsRaw.map(item => ({
                action: item.action,
                count: item._count.action,
            }));
            const topUsersRaw = await this.prisma.auditLog.groupBy({
                by: ['userId'],
                where,
                _count: {
                    userId: true,
                },
                orderBy: {
                    _count: {
                        userId: 'desc',
                    },
                },
                take: 10,
            });
            const topUsers = await Promise.all(topUsersRaw.map(async (item) => {
                const user = await this.prisma.user.findUnique({
                    where: { id: item.userId },
                    select: { username: true },
                });
                return {
                    userId: item.userId,
                    username: user?.username || 'Unknown',
                    count: item._count.userId,
                };
            }));
            const securityEventsRaw = await this.prisma.securityEvent.groupBy({
                by: ['eventType', 'severity'],
                where: {
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                _count: {
                    eventType: true,
                },
            });
            const securityEvents = securityEventsRaw.map(item => ({
                eventType: item.eventType,
                severity: item.severity,
                count: item._count.eventType,
            }));
            return {
                totalEntries,
                successfulActions,
                failedActions,
                topActions,
                topUsers,
                securityEvents,
                timeRange: { from: dateFrom, to: dateTo },
                generatedAt: new Date(),
            };
        }
        catch (error) {
            logger_1.logger.error('Failed to generate audit report', {
                error: String(error),
                dateFrom,
                dateTo,
            });
            throw error;
        }
    }
    async cleanupOldLogs(retentionDays = 365) {
        try {
            const cutoffDate = new Date();
            cutoffDate.setDate(cutoffDate.getDate() - retentionDays);
            const criticalActions = ['login', 'logout', 'payment_process', 'security_event'];
            const result = await this.prisma.auditLog.deleteMany({
                where: {
                    createdAt: {
                        lt: cutoffDate,
                    },
                    action: {
                        notIn: criticalActions,
                    },
                },
            });
            await this.logAction({
                userId: 'system',
                action: 'audit_cleanup',
                resourceType: 'system',
                newValues: {
                    deletedCount: result.count,
                    retentionDays,
                    cutoffDate: cutoffDate.toISOString(),
                },
                success: true,
                metadata: {
                    cleanupType: 'scheduled',
                    retentionPolicy: `${retentionDays} days`,
                },
            });
            logger_1.logger.info('Audit logs cleanup completed', {
                deletedCount: result.count,
                retentionDays,
                cutoffDate,
            });
            return result.count;
        }
        catch (error) {
            logger_1.logger.error('Failed to cleanup old audit logs', {
                error: String(error),
                retentionDays,
            });
            throw error;
        }
    }
    async exportAuditLogs(query) {
        try {
            const { data } = await this.queryAuditLogs({ ...query, limit: 10000 });
            const headers = [
                'ID',
                'User ID',
                'Username',
                'Action',
                'Resource Type',
                'Resource ID',
                'Success',
                'IP Address',
                'Created At',
                'Error Message',
            ];
            const rows = data.map(entry => [
                entry.id,
                entry.userId,
                entry.user?.username || 'Unknown',
                entry.action,
                entry.resourceType,
                entry.resourceId || '',
                entry.success ? 'Yes' : 'No',
                entry.ipAddress || '',
                entry.createdAt.toISOString(),
                entry.errorMessage || '',
            ]);
            const csvContent = [headers, ...rows]
                .map(row => row.map(field => `"${String(field).replace(/"/g, '""')}"`).join(','))
                .join('\n');
            return csvContent;
        }
        catch (error) {
            logger_1.logger.error('Failed to export audit logs', {
                error: String(error),
                query,
            });
            throw error;
        }
    }
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    generateRequestId() {
        return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }
}
exports.AuditService = AuditService;
//# sourceMappingURL=AuditService.js.map