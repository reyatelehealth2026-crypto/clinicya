"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.performanceRoutes = performanceRoutes;
const CacheService_1 = require("@/services/CacheService");
const DatabasePoolService_1 = require("@/services/DatabasePoolService");
const logger_1 = require("@/utils/logger");
async function performanceRoutes(fastify) {
    const cacheService = new CacheService_1.CacheService(fastify);
    const dbPool = new DatabasePoolService_1.DatabasePoolService();
    fastify.post('/analytics/performance', async (request, reply) => {
        try {
            const { metrics, session, timestamp } = request.body;
            if (!Array.isArray(metrics) || metrics.length === 0) {
                return reply.status(400).send({
                    success: false,
                    error: { code: 'INVALID_METRICS', message: 'Invalid metrics data' },
                });
            }
            const cacheKey = `performance:session:${session}`;
            await cacheService.set(cacheKey, { metrics, timestamp }, { ttl: 3600 });
            await aggregatePerformanceMetrics(metrics, session);
            const slowMetrics = metrics.filter(m => (m.name.includes('web_vital') && m.value > getPerformanceThreshold(m.name)) ||
                (m.name === 'api_call' && m.value > 1000) ||
                (m.name === 'component_render' && m.value > 100));
            if (slowMetrics.length > 0) {
                logger_1.logger.warn('Slow frontend performance detected', {
                    session,
                    slowMetrics: slowMetrics.length,
                    metrics: slowMetrics,
                });
            }
            reply.send({ success: true });
        }
        catch (error) {
            logger_1.logger.error('Failed to record performance metrics', { error: String(error) });
            reply.status(500).send({
                success: false,
                error: { code: 'INTERNAL_ERROR', message: 'Failed to record metrics' },
            });
        }
    });
    fastify.get('/analytics/performance/stats', async (request, reply) => {
        try {
            const stats = await getPerformanceStats(cacheService, dbPool);
            reply.send({ success: true, data: stats });
        }
        catch (error) {
            logger_1.logger.error('Failed to get performance stats', { error: String(error) });
            reply.status(500).send({
                success: false,
                error: { code: 'INTERNAL_ERROR', message: 'Failed to get stats' },
            });
        }
    });
    fastify.get('/analytics/cache/stats', async (request, reply) => {
        try {
            const cacheStats = cacheService.getStats();
            reply.send({ success: true, data: cacheStats });
        }
        catch (error) {
            logger_1.logger.error('Failed to get cache stats', { error: String(error) });
            reply.status(500).send({
                success: false,
                error: { code: 'INTERNAL_ERROR', message: 'Failed to get cache stats' },
            });
        }
    });
    fastify.get('/analytics/database/stats', async (request, reply) => {
        try {
            const dbStats = dbPool.getPoolStats();
            const slowQueries = dbPool.getSlowQueries(1000);
            reply.send({
                success: true,
                data: {
                    pool: dbStats,
                    slowQueries: slowQueries.slice(0, 10),
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to get database stats', { error: String(error) });
            reply.status(500).send({
                success: false,
                error: { code: 'INTERNAL_ERROR', message: 'Failed to get database stats' },
            });
        }
    });
    fastify.get('/health/performance', async (request, reply) => {
        try {
            const [cacheHealth, dbHealth] = await Promise.all([
                checkCacheHealth(cacheService),
                dbPool.healthCheck(),
            ]);
            const overallHealth = cacheHealth.status === 'healthy' && dbHealth.status === 'healthy'
                ? 'healthy' : 'unhealthy';
            reply.send({
                success: true,
                data: {
                    status: overallHealth,
                    cache: cacheHealth,
                    database: dbHealth,
                    timestamp: new Date().toISOString(),
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Performance health check failed', { error: String(error) });
            reply.status(500).send({
                success: false,
                error: { code: 'HEALTH_CHECK_FAILED', message: 'Health check failed' },
            });
        }
    });
}
async function aggregatePerformanceMetrics(metrics, session) {
    const metricGroups = metrics.reduce((groups, metric) => {
        if (!groups[metric.name]) {
            groups[metric.name] = [];
        }
        groups[metric.name].push(metric.value);
        return groups;
    }, {});
    const aggregates = Object.entries(metricGroups).map(([name, values]) => ({
        name,
        count: values.length,
        avg: values.reduce((sum, val) => sum + val, 0) / values.length,
        min: Math.min(...values),
        max: Math.max(...values),
        p95: calculatePercentile(values, 95),
    }));
    logger_1.logger.info('Performance metrics aggregated', {
        session,
        aggregates,
    });
}
function getPerformanceThreshold(metricName) {
    const thresholds = {
        'web_vital_cls': 0.25,
        'web_vital_fid': 300,
        'web_vital_fcp': 3000,
        'web_vital_lcp': 4000,
        'web_vital_ttfb': 800,
    };
    return thresholds[metricName] || Infinity;
}
function calculatePercentile(values, percentile) {
    const sorted = values.sort((a, b) => a - b);
    const index = Math.ceil((percentile / 100) * sorted.length) - 1;
    return sorted[index] || 0;
}
async function getPerformanceStats(cacheService, dbPool) {
    const cacheStats = cacheService.getStats();
    const dbStats = dbPool.getPoolStats();
    const slowQueries = dbPool.getSlowQueries(1000);
    return {
        cache: {
            hitRate: cacheStats.hitRate,
            totalRequests: cacheStats.hits + cacheStats.misses,
            performance: cacheStats.hitRate >= 85 ? 'good' : 'poor',
        },
        database: {
            activeConnections: dbStats.activeConnections,
            totalConnections: dbStats.totalConnections,
            utilization: (dbStats.activeConnections / dbStats.totalConnections) * 100,
            averageQueryTime: dbStats.averageQueryTime,
            slowQueries: slowQueries.length,
            performance: dbStats.averageQueryTime < 300 ? 'good' : 'poor',
        },
        overall: {
            status: cacheStats.hitRate >= 85 && dbStats.averageQueryTime < 300 ? 'good' : 'poor',
            timestamp: new Date().toISOString(),
        },
    };
}
async function checkCacheHealth(cacheService) {
    try {
        const testKey = 'health_check_' + Date.now();
        const testValue = { test: true, timestamp: Date.now() };
        await cacheService.set(testKey, testValue, { ttl: 10 });
        const retrieved = await cacheService.get(testKey);
        await cacheService.delete(testKey);
        const stats = cacheService.getStats();
        return {
            status: 'healthy',
            details: {
                hitRate: stats.hitRate,
                totalRequests: stats.hits + stats.misses,
                performance: stats.hitRate >= 85 ? 'good' : 'poor',
            },
        };
    }
    catch (error) {
        return {
            status: 'unhealthy',
            details: {
                error: String(error),
            },
        };
    }
}
//# sourceMappingURL=performance.js.map