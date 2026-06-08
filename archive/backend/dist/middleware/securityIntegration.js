"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createSecurityIntegration = exports.SecurityIntegrationMiddleware = void 0;
const logger_1 = require("@/utils/logger");
class SecurityIntegrationMiddleware {
    securityService;
    auditService;
    constructor(securityService, auditService) {
        this.securityService = securityService;
        this.auditService = auditService;
    }
    createSecurityMiddleware() {
        return async (request, reply) => {
            const startTime = Date.now();
            const ip = request.ip;
            const userAgent = request.headers['user-agent'] || '';
            const user = request.user;
            const userId = user?.userId;
            try {
                if (request.url.includes('/auth/login') && request.method === 'POST') {
                    await this.securityService.monitorBruteForce(ip, userId);
                }
                if (request.body || request.query || request.params) {
                    const payload = {
                        body: request.body,
                        query: request.query,
                        params: request.params,
                    };
                    await this.securityService.monitorSqlInjection(ip, userAgent, payload, userId);
                }
                if (request.body && typeof request.body === 'object') {
                    await this.securityService.monitorXssAttempts(ip, userAgent, request.body, userId);
                }
                await this.securityService.monitorSuspiciousActivity(ip, userAgent, userId);
                if (this.isSensitiveEndpoint(request.url, request.method)) {
                    await this.auditService.logAction({
                        userId: userId || 'anonymous',
                        action: `${request.method.toLowerCase()}_${this.getResourceType(request.url)}`,
                        resourceType: this.getResourceType(request.url),
                        resourceId: this.extractResourceId(request.url, request.params),
                        ipAddress: ip,
                        userAgent,
                        success: true,
                        metadata: {
                            endpoint: request.url,
                            method: request.method,
                            requestSize: this.getRequestSize(request),
                        },
                    });
                }
                this.addSecurityHeaders(reply);
                reply.addHook('onSend', async (request, reply, payload) => {
                    const duration = Date.now() - startTime;
                    const statusCode = reply.statusCode;
                    if (statusCode >= 400) {
                        await this.handleFailedRequest(request, reply, statusCode, duration);
                    }
                    if (statusCode < 400 && this.isSensitiveEndpoint(request.url, request.method)) {
                        await this.handleSuccessfulRequest(request, reply, duration);
                    }
                    return payload;
                });
            }
            catch (error) {
                logger_1.logger.error('Security middleware error', {
                    error: String(error),
                    ip,
                    url: request.url,
                    method: request.method,
                });
            }
        };
    }
    async handleFailedRequest(request, reply, statusCode, duration) {
        const ip = request.ip;
        const userAgent = request.headers['user-agent'] || '';
        const user = request.user;
        const userId = user?.userId;
        if (statusCode === 401 || statusCode === 403) {
            await this.securityService.detectThreat('unauthorized_access', { ip, userAgent, userId }, {
                statusCode,
                endpoint: request.url,
                method: request.method,
                duration,
            });
        }
        if (request.url.includes('/auth/login') && statusCode === 401) {
            const key = `failed_login:${ip}`;
            const redis = request.server.redis;
            await redis.zadd(key, Date.now(), `${Date.now()}-${Math.random()}`);
            await redis.expire(key, 900);
        }
    }
    async handleSuccessfulRequest(request, reply, duration) {
        const user = request.user;
        if (!user)
            return;
        await this.auditService.logAction({
            userId: user.userId,
            action: `${request.method.toLowerCase()}_${this.getResourceType(request.url)}_success`,
            resourceType: this.getResourceType(request.url),
            resourceId: this.extractResourceId(request.url, request.params),
            ipAddress: request.ip,
            userAgent: request.headers['user-agent'],
            success: true,
            metadata: {
                endpoint: request.url,
                method: request.method,
                statusCode: reply.statusCode,
                duration,
                responseSize: this.getResponseSize(reply),
            },
        });
    }
    addSecurityHeaders(reply) {
        reply.header('X-Content-Type-Options', 'nosniff');
        reply.header('X-Frame-Options', 'DENY');
        reply.header('X-XSS-Protection', '1; mode=block');
        reply.header('Referrer-Policy', 'strict-origin-when-cross-origin');
        reply.header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        reply.header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
    isSensitiveEndpoint(url, method) {
        const sensitivePatterns = [
            /\/auth\/(login|logout|refresh)/,
            /\/audit\//,
            /\/security\//,
            /\/orders\/.*\/status/,
            /\/payments\//,
            /\/users\/.*\/(role|permissions)/,
        ];
        const sensitiveMethods = ['POST', 'PUT', 'DELETE'];
        return sensitivePatterns.some(pattern => pattern.test(url)) ||
            (sensitiveMethods.includes(method) && !url.includes('/health'));
    }
    getResourceType(url) {
        if (url.includes('/auth/'))
            return 'authentication';
        if (url.includes('/audit/'))
            return 'audit';
        if (url.includes('/security/'))
            return 'security';
        if (url.includes('/orders/'))
            return 'order';
        if (url.includes('/payments/'))
            return 'payment';
        if (url.includes('/dashboard/'))
            return 'dashboard';
        if (url.includes('/users/'))
            return 'user';
        if (url.includes('/webhooks/'))
            return 'webhook';
        return 'unknown';
    }
    extractResourceId(url, params) {
        if (params && typeof params === 'object') {
            const idFields = ['id', 'orderId', 'paymentId', 'userId', 'webhookId'];
            for (const field of idFields) {
                if (params[field]) {
                    return params[field];
                }
            }
        }
        const uuidRegex = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;
        const match = url.match(uuidRegex);
        return match ? match[0] : undefined;
    }
    getRequestSize(request) {
        const contentLength = request.headers['content-length'];
        if (contentLength) {
            return parseInt(contentLength, 10);
        }
        if (request.body) {
            return JSON.stringify(request.body).length;
        }
        return 0;
    }
    getResponseSize(reply) {
        const contentLength = reply.getHeader('content-length');
        if (contentLength) {
            return parseInt(String(contentLength), 10);
        }
        return 0;
    }
}
exports.SecurityIntegrationMiddleware = SecurityIntegrationMiddleware;
const createSecurityIntegration = (securityService, auditService) => {
    const integration = new SecurityIntegrationMiddleware(securityService, auditService);
    return integration.createSecurityMiddleware();
};
exports.createSecurityIntegration = createSecurityIntegration;
//# sourceMappingURL=securityIntegration.js.map