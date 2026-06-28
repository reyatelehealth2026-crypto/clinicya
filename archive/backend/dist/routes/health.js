"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = healthRoutes;
const prisma_1 = require("@/utils/prisma");
const OdooService_1 = require("@/services/OdooService");
const CacheService_1 = require("@/services/CacheService");
const logger_1 = require("@/utils/logger");
async function healthRoutes(fastify) {
    const cacheService = new CacheService_1.CacheService(fastify);
    const odooService = new OdooService_1.OdooService(prisma_1.prisma);
    fastify.get('/health', async (_request, reply) => {
        const startTime = Date.now();
        try {
            const checks = await Promise.allSettled([
                checkDatabase(),
                checkRedis(fastify),
                checkOdoo(odooService),
                checkMemory(),
                checkWebSocket(fastify),
            ]);
            const [databaseResult, redisResult, odooResult, memoryResult, websocketResult] = checks;
            const healthResponse = {
                status: 'healthy',
                timestamp: new Date().toISOString(),
                version: process.env['npm_package_version'] || '1.0.0',
                uptime: process.uptime(),
                checks: {
                    database: databaseResult.status === 'fulfilled' ? databaseResult.value : {
                        status: 'error',
                        message: 'Database check failed',
                        details: databaseResult.status === 'rejected' ? databaseResult.reason : undefined,
                    },
                    redis: redisResult.status === 'fulfilled' ? redisResult.value : {
                        status: 'error',
                        message: 'Redis check failed',
                        details: redisResult.status === 'rejected' ? redisResult.reason : undefined,
                    },
                    odoo: odooResult.status === 'fulfilled' ? odooResult.value : {
                        status: 'error',
                        message: 'Odoo check failed',
                        details: odooResult.status === 'rejected' ? odooResult.reason : undefined,
                    },
                    memory: memoryResult.status === 'fulfilled' ? memoryResult.value : {
                        status: 'error',
                        message: 'Memory check failed',
                        details: memoryResult.status === 'rejected' ? memoryResult.reason : undefined,
                    },
                    websocket: websocketResult.status === 'fulfilled' ? websocketResult.value : {
                        status: 'error',
                        message: 'WebSocket check failed',
                        details: websocketResult.status === 'rejected' ? websocketResult.reason : undefined,
                    },
                },
                performance: {
                    responseTime: Date.now() - startTime,
                    cacheHitRate: cacheService.getStats().hitRate,
                    circuitBreakerStats: odooService.getCircuitBreakerStats(),
                    websocketConnections: fastify.webSocketService?.getConnectionStats() || null,
                },
            };
            const checkStatuses = Object.values(healthResponse.checks).map(check => check.status);
            if (checkStatuses.includes('error')) {
                healthResponse.status = 'unhealthy';
            }
            else if (checkStatuses.includes('warning')) {
                healthResponse.status = 'degraded';
            }
            const statusCode = healthResponse.status === 'healthy' ? 200 :
                healthResponse.status === 'degraded' ? 200 : 503;
            reply.status(statusCode).send(healthResponse);
        }
        catch (error) {
            logger_1.logger.error('Health check failed', { error: String(error) });
            reply.status(503).send({
                status: 'unhealthy',
                timestamp: new Date().toISOString(),
                error: 'Health check failed',
                details: String(error),
            });
        }
    });
    fastify.get('/ready', async (_request, reply) => {
        try {
            await Promise.all([
                checkDatabaseConnection(),
                checkRedisConnection(fastify),
            ]);
            reply.send({
                status: 'ready',
                timestamp: new Date().toISOString(),
            });
        }
        catch (error) {
            logger_1.logger.error('Readiness check failed', { error: String(error) });
            reply.status(503).send({
                status: 'not_ready',
                timestamp: new Date().toISOString(),
                error: String(error),
            });
        }
    });
    fastify.get('/live', async (_request, reply) => {
        reply.send({
            status: 'alive',
            timestamp: new Date().toISOString(),
            uptime: process.uptime(),
        });
    });
    fastify.get('/metrics', async (_request, reply) => {
        const memUsage = process.memoryUsage();
        const cacheStats = cacheService.getStats();
        const circuitBreakerStats = odooService.getCircuitBreakerStats();
        reply.send({
            timestamp: new Date().toISOString(),
            uptime: process.uptime(),
            memory: {
                rss: memUsage.rss,
                heapTotal: memUsage.heapTotal,
                heapUsed: memUsage.heapUsed,
                external: memUsage.external,
                arrayBuffers: memUsage.arrayBuffers,
            },
            cache: cacheStats,
            circuitBreaker: circuitBreakerStats,
            process: {
                pid: process.pid,
                version: process.version,
                platform: process.platform,
                arch: process.arch,
            },
        });
    });
}
async function checkDatabase() {
    const startTime = Date.now();
    try {
        await prisma_1.prisma.$queryRaw `SELECT 1`;
        return {
            status: 'ok',
            message: 'Database connection successful',
            responseTime: Date.now() - startTime,
        };
    }
    catch (error) {
        return {
            status: 'error',
            message: 'Database connection failed',
            responseTime: Date.now() - startTime,
            details: String(error),
        };
    }
}
async function checkDatabaseConnection() {
    await prisma_1.prisma.$queryRaw `SELECT 1`;
}
async function checkRedis(fastify) {
    const startTime = Date.now();
    try {
        const testKey = 'health-check';
        const testValue = Date.now().toString();
        await fastify.redis.set(testKey, testValue);
        const result = await fastify.redis.get(testKey);
        await fastify.redis.del(testKey);
        if (result !== testValue) {
            throw new Error('Redis read/write test failed');
        }
        return {
            status: 'ok',
            message: 'Redis connection successful',
            responseTime: Date.now() - startTime,
        };
    }
    catch (error) {
        return {
            status: 'error',
            message: 'Redis connection failed',
            responseTime: Date.now() - startTime,
            details: String(error),
        };
    }
}
async function checkRedisConnection(fastify) {
    await fastify.redis.ping();
}
async function checkOdoo(odooService) {
    const startTime = Date.now();
    try {
        const result = await odooService.healthCheck();
        return {
            status: result.status === 'ok' ? 'ok' : 'warning',
            message: result.message,
            responseTime: Date.now() - startTime,
        };
    }
    catch (error) {
        return {
            status: 'warning',
            message: 'Odoo service unavailable',
            responseTime: Date.now() - startTime,
            details: String(error),
        };
    }
}
async function checkMemory() {
    const memUsage = process.memoryUsage();
    const heapUsedMB = memUsage.heapUsed / 1024 / 1024;
    const heapTotalMB = memUsage.heapTotal / 1024 / 1024;
    const heapUsagePercent = (heapUsedMB / heapTotalMB) * 100;
    let status = 'ok';
    let message = `Memory usage: ${heapUsedMB.toFixed(2)}MB / ${heapTotalMB.toFixed(2)}MB (${heapUsagePercent.toFixed(1)}%)`;
    if (heapUsagePercent > 90) {
        status = 'error';
        message = `High memory usage: ${heapUsagePercent.toFixed(1)}%`;
    }
    else if (heapUsagePercent > 80) {
        status = 'warning';
        message = `Elevated memory usage: ${heapUsagePercent.toFixed(1)}%`;
    }
    return {
        status,
        message,
        details: {
            heapUsed: heapUsedMB,
            heapTotal: heapTotalMB,
            heapUsagePercent,
            rss: memUsage.rss / 1024 / 1024,
            external: memUsage.external / 1024 / 1024,
        },
    };
}
async function checkWebSocket(fastify) {
    const startTime = Date.now();
    try {
        if (!fastify.webSocketService || !fastify.webSocketService.isWebSocketAvailable()) {
            return {
                status: 'warning',
                message: 'WebSocket service not available',
                responseTime: Date.now() - startTime,
            };
        }
        const stats = fastify.webSocketService.getConnectionStats();
        return {
            status: 'ok',
            message: `WebSocket server running with ${stats?.totalConnections || 0} active connections`,
            responseTime: Date.now() - startTime,
            details: stats,
        };
    }
    catch (error) {
        return {
            status: 'error',
            message: 'WebSocket health check failed',
            responseTime: Date.now() - startTime,
            details: String(error),
        };
    }
}
//# sourceMappingURL=health.js.map