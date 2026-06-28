import { BaseService } from './BaseService';
import { AuditService } from './AuditService';
import { PrismaClient } from '@prisma/client';
export interface SecurityThreat {
    id: string;
    type: 'brute_force' | 'sql_injection' | 'xss_attempt' | 'suspicious_activity' | 'rate_limit_violation' | 'unauthorized_access';
    severity: 'low' | 'medium' | 'high' | 'critical';
    source: {
        ip: string;
        userAgent?: string;
        userId?: string;
        sessionId?: string;
    };
    details: Record<string, any>;
    detectedAt: Date;
    status: 'active' | 'mitigated' | 'false_positive';
    mitigationActions: string[];
}
export interface SecurityMetrics {
    totalThreats: number;
    activeThreats: number;
    blockedIPs: number;
    failedLogins: number;
    suspiciousRequests: number;
    timeRange: {
        from: Date;
        to: Date;
    };
    threatsByType: Array<{
        type: string;
        count: number;
    }>;
    threatsBySeverity: Array<{
        severity: string;
        count: number;
    }>;
    topAttackerIPs: Array<{
        ip: string;
        threatCount: number;
        lastSeen: Date;
    }>;
}
export interface SecurityAlert {
    id: string;
    type: 'threat_detected' | 'threshold_exceeded' | 'system_anomaly';
    severity: 'low' | 'medium' | 'high' | 'critical';
    message: string;
    details: Record<string, any>;
    createdAt: Date;
    acknowledged: boolean;
    acknowledgedBy?: string;
    acknowledgedAt?: Date;
}
export declare class SecurityMonitoringService extends BaseService {
    private auditService;
    private redis;
    private threats;
    private alerts;
    private readonly thresholds;
    constructor(prisma: PrismaClient, auditService: AuditService, redisClient: any);
    detectThreat(type: SecurityThreat['type'], source: SecurityThreat['source'], details: Record<string, any>): Promise<SecurityThreat | null>;
    monitorBruteForce(ip: string, userId?: string): Promise<void>;
    monitorSqlInjection(ip: string, userAgent: string, payload: any, userId?: string): Promise<void>;
    monitorXssAttempts(ip: string, userAgent: string, payload: any, userId?: string): Promise<void>;
    monitorSuspiciousActivity(ip: string, userAgent: string, userId?: string): Promise<void>;
    private applyAutomaticMitigation;
    private blockIP;
    private calculateThreatSeverity;
    private createSecurityAlert;
    getSecurityMetrics(days?: number): Promise<SecurityMetrics>;
    getActiveAlerts(): Promise<SecurityAlert[]>;
    acknowledgeAlert(alertId: string, acknowledgedBy: string): Promise<boolean>;
    private storeThreatInRedis;
    private storeAlertInRedis;
    private generateUUID;
}
//# sourceMappingURL=SecurityMonitoringService.d.ts.map