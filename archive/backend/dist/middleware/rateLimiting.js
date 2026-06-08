"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createRateLimitMiddleware = exports.RateLimitService = exports.securityConfig = exports.rateLimitConfigs = void 0;
const logger_1 = require("@/utils/logger");
exports.rateLimitConfigs = {
    auth: {
        windowMs: 15 * 60 * 1000,
        maxRequests: 5,
        progressivePenalty: true,
    },
    passwordReset: {
        windowMs: 60 * 60 * 1000,
        maxRequests: 3,
        progressivePenalty: true,
    },
    upload: {
        windowMs: 60 * 1000,
        maxRequests: 10,
        progressivePenalty: false,
    },
    api: {
        windowMs: 60 * 1000,
        maxRequests: 100,
        progressivePenalty: false,
    },
    dashboard: {
        windowMs: 60 * 1000,
        maxRequests: 200,
        progressivePenalty: false,
    },
    search: {
        windowMs: 60 * 1000,
        maxRequests: 50,
        progressivePenalty: false,
    },
    webhook: {
        windowMs: 60 * 1000,
        maxRequests: 30,
        progressivePenalty: true,
    },
};
exports.securityConfig = {
    maxFailedAttempts: 10,
    blockDurationMs: 30 * 60 * 1000,
    suspiciousThreshold: 300,
    whitelistedIPs: [
        '127.0.0.1',
        '::1',
    ],
    blacklistedIPs: [],
};
class RateLimitService {
    redis;
    securityEvents = new Map();
    constructor(redisClient) {
        this.redis = redisClient;
    }
    createRateLimit(config) {
        return async (request, reply) => {
            const key = config.keyGenerator ? config.keyGenerator(request) : this.getDefaultKey(request);
            const now = Date.now();
            const windowStart = now - config.windowMs;
            try {
                if (await this.isBlacklisted(request.ip)) {
                    return this.sendBlockedResponse(reply, 'IP_BLACKLISTED');
                }
                if (await this.isTemporarilyBlocked(request.ip)) {
                    return this.sendBlockedResponse(reply, 'IP_TEMPORARILY_BLOCKED');
                }
                const requestCount = await this.getRequestCount(key, windowStart, now);
                if (requestCount >= config.maxRequests) {
                    await this.handleLimitExceeded(request, reply, config);
                    return;
                }
                await this.recordRequest(key, now);
                this.setRateLimitHeaders(reply, config, requestCount);
                await this.checkSuspiciousActivity(request);
            }
            catch (error) {
                logger_1.logger.error('Rate limiting error', {
                    error: String(error),
                    ip: request.ip,
                    url: request.url,
                });
            }
        };
    }
    async getRequestCount(key, windowStart, now) {
        const pipeline = this.redis.pipeline();
        pipeline.zremrangebyscore(key, 0, windowStart);
        pipeline.zcard(key);
        pipeline.expire(key, Math.ceil((now - windowStart) / 1000));
        const results = await pipeline.exec();
        return results[1][1] || 0;
    }
    async recordRequest(key, timestamp) {
        const requestId = `${timestamp}-${Math.random()}`;
        await this.redis.zadd(key, timestamp, requestId);
    }
    async handleLimitExceeded(request, reply, config) {
        const ip = request.ip;
        await this.recordFailedAttempt(ip);
        if (config.progressivePenalty) {
            const failedAttempts = await this.getFailedAttempts(ip);
            if (failedAttempts >= exports.securityConfig.maxFailedAttempts) {
                await this.blockIP(ip, exports.securityConfig.blockDurationMs);
                logger_1.logger.warn('IP blocked due to excessive rate limit violations', {
                    ip,
                    failedAttempts,
                    url: request.url,
                });
            }
        }
        logger_1.logger.warn('Rate limit exceeded', {
            ip,
            url: request.url,
            method: request.method,
            userAgent: request.headers['user-agent'],
        });
        if (config.onLimitReached) {
            config.onLimitReached(request, reply);
            return;
        }
        const retryAfter = Math.ceil(config.windowMs / 1000);
        reply.header('Retry-After', retryAfter.toString());
        return reply.status(429).send({
            success: false,
            error: {
                code: 'RATE_LIMIT_EXCEEDED',
                message: 'Too many requests. Please try again later.',
                retryAfter,
                timestamp: new Date().toISOString(),
            },
        });
    }
    async checkSuspiciousActivity(request) {
        const ip = request.ip;
        const now = Date.now();
        const windowStart = now - 60 * 1000;
        const recentRequests = await this.getRequestCount(`suspicious:${ip}`, windowStart, now);
        if (recentRequests > exports.securityConfig.suspiciousThreshold) {
            await this.recordSecurityEvent(ip, 'SUSPICIOUS_ACTIVITY', {
                requestCount: recentRequests,
                url: request.url,
                userAgent: request.headers['user-agent'],
            });
            await this.blockIP(ip, exports.securityConfig.blockDurationMs);
            logger_1.logger.warn('Suspicious activity detected - IP blocked', {
                ip,
                requestCount: recentRequests,
                threshold: exports.securityConfig.suspiciousThreshold,
            });
        }
        await this.recordRequest(`suspicious:${ip}`, now);
    }
    async recordFailedAttempt(ip) {
        const key = `failed:${ip}`;
        const count = await this.redis.incr(key);
        if (count === 1) {
            await this.redis.expire(key, 3600);
        }
    }
    async getFailedAttempts(ip) {
        const count = await this.redis.get(`failed:${ip}`);
        return parseInt(count) || 0;
    }
    async blockIP(ip, durationMs) {
        const key = `blocked:${ip}`;
        const expirationSeconds = Math.ceil(durationMs / 1000);
        await this.redis.setex(key, expirationSeconds, Date.now().toString());
    }
    async isTemporarilyBlocked(ip) {
        const blocked = await this.redis.get(`blocked:${ip}`);
        return blocked !== null;
    }
    async isBlacklisted(ip) {
        if (exports.securityConfig.blacklistedIPs.includes(ip)) {
            return true;
        }
        const blacklisted = await this.redis.get(`blacklist:${ip}`);
        return blacklisted !== null;
    }
    isWhitelisted(ip) {
        return exports.securityConfig.whitelistedIPs.includes(ip);
    }
    async recordSecurityEvent(ip, eventType, details) {
        const event = {
            ip,
            eventType,
            details,
            timestamp: new Date().toISOString(),
        };
        const key = `security_events:${ip}`;
        await this.redis.lpush(key, JSON.stringify(event));
        await this.redis.ltrim(key, 0, 99);
        await this.redis.expire(key, 86400);
        if (!this.securityEvents.has(ip)) {
            this.securityEvents.set(ip, []);
        }
        const events = this.securityEvents.get(ip);
        events.unshift(event);
        if (events.length > 50) {
            events.splice(50);
        }
    }
    getDefaultKey(request) {
        const user = request.user;
        if (user && user.userId) {
            return `rate_limit:user:${user.userId}`;
        }
        return `rate_limit:ip:${request.ip}`;
    }
    setRateLimitHeaders(reply, config, currentCount) {
        reply.header('X-RateLimit-Limit', config.maxRequests.toString());
        reply.header('X-RateLimit-Remaining', Math.max(0, config.maxRequests - currentCount).toString());
        reply.header('X-RateLimit-Reset', new Date(Date.now() + config.windowMs).toISOString());
    }
    sendBlockedResponse(reply, reason) {
        return reply.status(403).send({
            success: false,
            error: {
                code: reason,
                message: 'Access denied due to security policy',
                timestamp: new Date().toISOString(),
            },
        });
    }
    async getSecurityEvents(ip) {
        try {
            const key = `security_events:${ip}`;
            const events = await this.redis.lrange(key, 0, -1);
            return events.map((event) => JSON.parse(event));
        }
        catch (error) {
            logger_1.logger.error('Failed to get security events', { error: String(error), ip });
            return [];
        }
    }
    async blacklistIP(ip, reason, duration) {
        const key = `blacklist:${ip}`;
        const data = {
            reason,
            timestamp: new Date().toISOString(),
        };
        if (duration) {
            await this.redis.setex(key, Math.ceil(duration / 1000), JSON.stringify(data));
        }
        else {
            await this.redis.set(key, JSON.stringify(data));
        }
        logger_1.logger.info('IP blacklisted', { ip, reason, duration });
    }
    async removeFromBlacklist(ip) {
        await this.redis.del(`blacklist:${ip}`);
        logger_1.logger.info('IP removed from blacklist', { ip });
    }
    async getStatistics() {
        try {
            const stats = {
                blockedIPs: 0,
                blacklistedIPs: 0,
                recentSecurityEvents: 0,
                topBlockedIPs: [],
            };
            const blockedKeys = await this.redis.keys('blocked:*');
            stats.blockedIPs = blockedKeys.length;
            const blacklistedKeys = await this.redis.keys('blacklist:*');
            stats.blacklistedIPs = blacklistedKeys.length;
            const eventKeys = await this.redis.keys('security_events:*');
            stats.recentSecurityEvents = eventKeys.length;
            return stats;
        }
        catch (error) {
            logger_1.logger.error('Failed to get rate limit statistics', { error: String(error) });
            return {};
        }
    }
}
exports.RateLimitService = RateLimitService;
const createRateLimitMiddleware = (redisClient) => {
    const rateLimitService = new RateLimitService(redisClient);
    return {
        auth: rateLimitService.createRateLimit(exports.rateLimitConfigs.auth),
        passwordReset: rateLimitService.createRateLimit(exports.rateLimitConfigs.passwordReset),
        upload: rateLimitService.createRateLimit(exports.rateLimitConfigs.upload),
        api: rateLimitService.createRateLimit(exports.rateLimitConfigs.api),
        dashboard: rateLimitService.createRateLimit(exports.rateLimitConfigs.dashboard),
        search: rateLimitService.createRateLimit(exports.rateLimitConfigs.search),
        webhook: rateLimitService.createRateLimit(exports.rateLimitConfigs.webhook),
        custom: (config) => rateLimitService.createRateLimit(config),
        service: rateLimitService,
    };
};
exports.createRateLimitMiddleware = createRateLimitMiddleware;
//# sourceMappingURL=rateLimiting.js.map