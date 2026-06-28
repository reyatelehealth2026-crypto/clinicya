import { FastifyRequest, FastifyReply } from 'fastify';
import { SecurityMonitoringService } from '@/services/SecurityMonitoringService';
import { AuditService } from '@/services/AuditService';
export declare class SecurityIntegrationMiddleware {
    private securityService;
    private auditService;
    constructor(securityService: SecurityMonitoringService, auditService: AuditService);
    createSecurityMiddleware(): (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    private handleFailedRequest;
    private handleSuccessfulRequest;
    private addSecurityHeaders;
    private isSensitiveEndpoint;
    private getResourceType;
    private extractResourceId;
    private getRequestSize;
    private getResponseSize;
}
export declare const createSecurityIntegration: (securityService: SecurityMonitoringService, auditService: AuditService) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=securityIntegration.d.ts.map