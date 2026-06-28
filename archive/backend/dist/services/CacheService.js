"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CacheService = void 0;
const logger_1 = require("@/utils/logger");
class CacheService {
    redis;
    stats = {
        hits: 0,
        misses: 0,
        sets: 0,
        deletes: 0,
        hitRate: 0,
    };
    constructor(fastify) {
        this.redis = fastify.redis;
    }
    async get(key, options = {}) {
        try {
            const fullKey = this.buildKey(key, options.prefix);
            const value = await this.redis.get(fullKey);
            if (value === null) {
                this.stats.misses++;
                this.updateHitRate();
                return null;
            }
            this.stats.hits++;
            this.updateHitRate();
            try {
                return JSON.parse(value);
            }
            catch {
                return value;
            }
        }
        catch (error) {
            logger_1.logger.error('Cache get error', { key, error: String(error) });
            this.stats.misses++;
            this.updateHitRate();
            return null;
        }
    }
    async set(key, value, options = {}) {
        try {
            const fullKey = this.buildKey(key, options.prefix);
            const serializedValue = typeof value === 'string' ? value : JSON.stringify(value);
            if (options.ttl) {
                await this.redis.setex(fullKey, options.ttl, serializedValue);
            }
            else {
                await this.redis.set(fullKey, serializedValue);
            }
            this.stats.sets++;
            return true;
        }
        catch (error) {
            logger_1.logger.error('Cache set error', { key, error: String(error) });
            return false;
        }
    }
    async delete(key, options = {}) {
        try {
            const fullKey = this.buildKey(key, options.prefix);
            const result = await this.redis.del(fullKey);
            this.stats.deletes++;
            return result > 0;
        }
        catch (error) {
            logger_1.logger.error('Cache delete error', { key, error: String(error) });
            return false;
        }
    }
    async exists(key, options = {}) {
        try {
            const fullKey = this.buildKey(key, options.prefix);
            const result = await this.redis.exists(fullKey);
            return result === 1;
        }
        catch (error) {
            logger_1.logger.error('Cache exists error', { key, error: String(error) });
            return false;
        }
    }
    async expire(key, ttl, options = {}) {
        try {
            const fullKey = this.buildKey(key, options.prefix);
            const result = await this.redis.expire(fullKey, ttl);
            return result === 1;
        }
        catch (error) {
            logger_1.logger.error('Cache expire error', { key, ttl, error: String(error) });
            return false;
        }
    }
    async getOrSet(key, factory, options = {}) {
        const cached = await this.get(key, options);
        if (cached !== null) {
            return cached;
        }
        const value = await factory();
        await this.set(key, value, options);
        return value;
    }
    async invalidatePattern(pattern, options = {}) {
        try {
            const fullPattern = this.buildKey(pattern, options.prefix);
            const keys = await this.redis.keys(fullPattern);
            if (keys.length === 0) {
                return 0;
            }
            const result = await this.redis.del(...keys);
            this.stats.deletes += result;
            logger_1.logger.info(`Invalidated ${result} cache keys matching pattern`, { pattern: fullPattern });
            return result;
        }
        catch (error) {
            logger_1.logger.error('Cache invalidate pattern error', { pattern, error: String(error) });
            return 0;
        }
    }
    async flush() {
        try {
            await this.redis.flushdb();
            logger_1.logger.info('Cache flushed');
            return true;
        }
        catch (error) {
            logger_1.logger.error('Cache flush error', { error: String(error) });
            return false;
        }
    }
    getStats() {
        return { ...this.stats };
    }
    resetStats() {
        this.stats = {
            hits: 0,
            misses: 0,
            sets: 0,
            deletes: 0,
            hitRate: 0,
        };
    }
    buildKey(key, prefix) {
        const parts = ['odoo-dashboard'];
        if (prefix) {
            parts.push(prefix);
        }
        parts.push(key);
        return parts.join(':');
    }
    updateHitRate() {
        const total = this.stats.hits + this.stats.misses;
        this.stats.hitRate = total > 0 ? (this.stats.hits / total) * 100 : 0;
    }
    async getWithFallback(key, fallbackFactory, options = {}) {
        const cached = await this.get(key, options);
        if (cached !== null) {
            return cached;
        }
        const value = await fallbackFactory();
        const cacheOptions = {
            ...options,
        };
        if (options.fallbackTtl !== undefined) {
            cacheOptions.ttl = options.fallbackTtl;
        }
        else if (options.ttl !== undefined) {
            cacheOptions.ttl = options.ttl;
        }
        await this.set(key, value, cacheOptions);
        return value;
    }
    async warmCache(keys, factory, options = {}) {
        const promises = keys.map(async (key) => {
            try {
                const exists = await this.exists(key, options);
                if (!exists) {
                    const value = await factory(key);
                    await this.set(key, value, options);
                }
            }
            catch (error) {
                logger_1.logger.error('Cache warming error', { key, error: String(error) });
            }
        });
        await Promise.allSettled(promises);
        logger_1.logger.info(`Cache warming completed for ${keys.length} keys`);
    }
}
exports.CacheService = CacheService;
//# sourceMappingURL=CacheService.js.map