import { FastifyRequest, FastifyReply } from 'fastify';
export interface RateLimitConfig {
    windowMs: number;
    maxRequests: number;
    skipSuccessfulRequests?: boolean;
    skipFailedRequests?: boolean;
    keyGenerator?: (req: FastifyRequest) => string;
    onLimitReached?: (req: FastifyRequest, reply: FastifyReply) => void;
    progressivePenalty?: boolean;
}
export interface SecurityConfig {
    maxFailedAttempts: number;
    blockDurationMs: number;
    suspiciousThreshold: number;
    whitelistedIPs: string[];
    blacklistedIPs: string[];
}
export declare const rateLimitConfigs: {
    auth: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    passwordReset: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    upload: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    api: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    dashboard: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    search: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
    webhook: {
        windowMs: number;
        maxRequests: number;
        progressivePenalty: boolean;
    };
};
export declare const securityConfig: SecurityConfig;
export declare class RateLimitService {
    private redis;
    private securityEvents;
    constructor(redisClient: any);
    createRateLimit(config: RateLimitConfig): (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    private getRequestCount;
    private recordRequest;
    private handleLimitExceeded;
    private checkSuspiciousActivity;
    private recordFailedAttempt;
    private getFailedAttempts;
    private blockIP;
    private isTemporarilyBlocked;
    private isBlacklisted;
    private isWhitelisted;
    private recordSecurityEvent;
    private getDefaultKey;
    private setRateLimitHeaders;
    private sendBlockedResponse;
    getSecurityEvents(ip: string): Promise<any[]>;
    blacklistIP(ip: string, reason: string, duration?: number): Promise<void>;
    removeFromBlacklist(ip: string): Promise<void>;
    getStatistics(): Promise<any>;
}
export declare const createRateLimitMiddleware: (redisClient: any) => {
    auth: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    passwordReset: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    upload: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    api: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    dashboard: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    search: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    webhook: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    custom: (config: RateLimitConfig) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    service: RateLimitService;
};
//# sourceMappingURL=rateLimiting.d.ts.map