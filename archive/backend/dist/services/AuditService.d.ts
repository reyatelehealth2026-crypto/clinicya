import { PrismaClient } from '@prisma/client';
import { BaseService } from './BaseService';
export interface AuditLogEntry {
    id?: string;
    userId: string;
    action: string;
    resourceType: string;
    resourceId?: string;
    oldValues?: Record<string, any>;
    newValues?: Record<string, any>;
    ipAddress?: string;
    userAgent?: string;
    sessionId?: string;
    requestId?: string;
    success: boolean;
    errorMessage?: string;
    metadata?: Record<string, any>;
    createdAt?: Date;
}
export interface SecurityEvent {
    id?: string;
    eventType: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    userId?: string;
    ipAddress?: string;
    userAgent?: string;
    details: Record<string, any>;
    createdAt?: Date;
}
export interface AuditQuery {
    userId?: string;
    action?: string;
    resourceType?: string;
    resourceId?: string;
    dateFrom?: Date;
    dateTo?: Date;
    success?: boolean;
    page?: number;
    limit?: number;
}
export interface AuditReport {
    totalEntries: number;
    successfulActions: number;
    failedActions: number;
    topActions: Array<{
        action: string;
        count: number;
    }>;
    topUsers: Array<{
        userId: string;
        username: string;
        count: number;
    }>;
    securityEvents: Array<{
        eventType: string;
        severity: string;
        count: number;
    }>;
    timeRange: {
        from: Date;
        to: Date;
    };
    generatedAt: Date;
}
export declare class AuditService extends BaseService {
    constructor(prisma: PrismaClient);
    logAction(entry: AuditLogEntry): Promise<string>;
    logLogin(userId: string, success: boolean, ipAddress?: string, userAgent?: string, errorMessage?: string): Promise<string>;
    logLogout(userId: string, sessionId: string, ipAddress?: string): Promise<string>;
    logTokenRefresh(userId: string, oldTokenHash: string, newTokenHash: string, ipAddress?: string): Promise<string>;
    logOrderUpdate(userId: string, orderId: string, oldValues: any, newValues: any, ipAddress?: string): Promise<string>;
    logOrderCreation(userId: string, orderId: string, orderData: any, ipAddress?: string): Promise<string>;
    logPaymentProcessing(userId: string, paymentId: string, paymentData: any, success: boolean, errorMessage?: string, ipAddress?: string): Promise<string>;
    logPaymentSlipUpload(userId: string, slipId: string, uploadData: any, ipAddress?: string): Promise<string>;
    logWebhookEvent(userId: string, webhookId: string, eventType: string, payload: any, success: boolean, errorMessage?: string): Promise<string>;
    logSecurityEvent(event: SecurityEvent): Promise<string>;
    getAuditTrail(resourceType: string, resourceId: string, limit?: number): Promise<any[]>;
    queryAuditLogs(query: AuditQuery): Promise<{
        data: any[];
        total: number;
        page: number;
        limit: number;
    }>;
    generateAuditReport(dateFrom: Date, dateTo: Date): Promise<AuditReport>;
    cleanupOldLogs(retentionDays?: number): Promise<number>;
    exportAuditLogs(query: AuditQuery): Promise<string>;
    private generateUUID;
    private generateRequestId;
}
//# sourceMappingURL=AuditService.d.ts.map