"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DashboardCacheService = void 0;
const BaseService_1 = require("./BaseService");
const CacheInvalidationService_1 = require("./CacheInvalidationService");
const logger_1 = require("@/utils/logger");
class DashboardCacheService extends BaseService_1.BaseService {
    cacheService;
    invalidationService;
    warmingJobs = new Map();
    CACHE_TTL = {
        DASHBOARD_METRICS: 30 * 60,
        REAL_TIME_METRICS: 30,
        HISTORICAL_DATA: 24 * 60 * 60,
        USER_SPECIFIC: 15 * 60,
        AGGREGATED_REPORTS: 60 * 60,
    };
    constructor(prisma, cacheService) {
        super(prisma);
        this.cacheService = cacheService;
        this.invalidationService = new CacheInvalidationService_1.CacheInvalidationService(cacheService);
        this.setupCacheWarmingJobs();
    }
    async getDashboardMetrics(lineAccountId, dateRange) {
        const cacheKey = this.buildMetricsCacheKey(lineAccountId, dateRange);
        return this.cacheService.getWithFallback(cacheKey, async () => {
            logger_1.logger.info('Cache miss - calculating dashboard metrics', {
                lineAccountId,
                dateRange
            });
            return this.calculateDashboardMetrics(lineAccountId, dateRange);
        }, {
            ttl: this.CACHE_TTL.DASHBOARD_METRICS,
            prefix: 'dashboard',
        });
    }
    async getRealTimeMetrics(lineAccountId) {
        const cacheKey = `realtime:${lineAccountId}`;
        return this.cacheService.getWithFallback(cacheKey, async () => {
            return this.calculateRealTimeMetrics(lineAccountId);
        }, {
            ttl: this.CACHE_TTL.REAL_TIME_METRICS,
            prefix: 'dashboard',
        });
    }
    async warmDashboardCache(lineAccountIds) {
        const warmingPromises = lineAccountIds.map(async (accountId) => {
            try {
                await this.getDashboardMetrics(accountId);
                await this.getRealTimeMetrics(accountId);
                const commonRanges = this.getCommonDateRanges();
                for (const range of commonRanges) {
                    await this.getDashboardMetrics(accountId, range);
                }
                logger_1.logger.debug('Cache warming completed for account', { accountId });
            }
            catch (error) {
                logger_1.logger.error('Cache warming failed for account', {
                    accountId,
                    error: String(error)
                });
            }
        });
        await Promise.allSettled(warmingPromises);
        logger_1.logger.info(`Cache warming completed for ${lineAccountIds.length} accounts`);
    }
    async invalidateDashboardCache(lineAccountId, eventType) {
        const patterns = [
            `dashboard:*:${lineAccountId}:*`,
            `dashboard:realtime:${lineAccountId}`,
            `dashboard:metrics:${lineAccountId}:*`,
        ];
        for (const pattern of patterns) {
            await this.cacheService.invalidatePattern(pattern);
        }
        logger_1.logger.info('Dashboard cache invalidated', { lineAccountId, eventType });
    }
    async getCacheStats() {
        const stats = this.cacheService.getStats();
        return {
            hitRate: stats.hitRate,
            totalRequests: stats.hits + stats.misses,
            cacheSize: stats.sets,
            topKeys: [],
        };
    }
    setupCacheWarmingJobs() {
        this.warmingJobs.set('critical-metrics', {
            name: 'Critical Dashboard Metrics',
            schedule: '*/5 * * * *',
            cacheKeys: ['dashboard:metrics:*', 'dashboard:realtime:*'],
            priority: 'high',
            maxExecutionTime: 30000,
            enabled: true,
        });
        this.warmingJobs.set('historical-reports', {
            name: 'Historical Reports',
            schedule: '0 */6 * * *',
            cacheKeys: ['dashboard:historical:*', 'reports:*'],
            priority: 'medium',
            maxExecutionTime: 120000,
            enabled: true,
        });
        this.warmingJobs.set('analytics', {
            name: 'Analytics Data',
            schedule: '0 2 * * *',
            cacheKeys: ['analytics:*', 'aggregated:*'],
            priority: 'low',
            maxExecutionTime: 300000,
            enabled: true,
        });
    }
    async executeCacheWarmingJob(jobName) {
        const job = this.warmingJobs.get(jobName);
        if (!job || !job.enabled) {
            return;
        }
        const startTime = Date.now();
        logger_1.logger.info('Starting cache warming job', { jobName, priority: job.priority });
        try {
            const accounts = await this.getActiveLineAccounts();
            await Promise.race([
                this.warmDashboardCache(accounts.map(a => a.id)),
                new Promise((_, reject) => setTimeout(() => reject(new Error('Cache warming timeout')), job.maxExecutionTime)),
            ]);
            const duration = Date.now() - startTime;
            logger_1.logger.info('Cache warming job completed', {
                jobName,
                duration,
                accountCount: accounts.length
            });
        }
        catch (error) {
            logger_1.logger.error('Cache warming job failed', {
                jobName,
                error: String(error)
            });
        }
    }
    async calculateDashboardMetrics(lineAccountId, dateRange) {
        const today = new Date();
        const startOfDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const endOfDay = new Date(startOfDay.getTime() + 24 * 60 * 60 * 1000);
        const from = dateRange?.from || startOfDay;
        const to = dateRange?.to || endOfDay;
        const orderMetrics = await this.calculateOrderMetrics(lineAccountId, from, to);
        const paymentMetrics = await this.calculatePaymentMetrics(lineAccountId, from, to);
        const webhookMetrics = await this.calculateWebhookMetrics(lineAccountId, from, to);
        const customerMetrics = await this.calculateCustomerMetrics(lineAccountId, from, to);
        return {
            orders: orderMetrics,
            payments: paymentMetrics,
            webhooks: webhookMetrics,
            customers: customerMetrics,
            updatedAt: new Date(),
        };
    }
    async calculateRealTimeMetrics(lineAccountId) {
        const now = new Date();
        const fiveMinutesAgo = new Date(now.getTime() - 5 * 60 * 1000);
        const recentOrdersCount = await this.prisma.odooOrders.count({
            where: {
                lineAccountId,
                createdAt: {
                    gte: fiveMinutesAgo,
                },
            },
        });
        const pendingPaymentsCount = await this.prisma.odooSlipUploads.count({
            where: {
                lineAccountId,
                status: 'PENDING',
            },
        });
        return {
            orders: {
                todayCount: recentOrdersCount,
                todayTotal: 0,
                pendingCount: 0,
                completedCount: 0,
                averageOrderValue: 0,
            },
            payments: {
                pendingSlips: pendingPaymentsCount,
                processedToday: 0,
                matchingRate: 0,
                totalAmount: 0,
                averageProcessingTime: 0,
            },
            updatedAt: new Date(),
        };
    }
    async calculateOrderMetrics(lineAccountId, from, to) {
        const orders = await this.prisma.odooOrders.findMany({
            where: {
                lineAccountId,
                orderDate: {
                    gte: from,
                    lte: to,
                },
            },
        });
        const totalAmount = orders.reduce((sum, order) => sum + Number(order.totalAmount), 0);
        const completedOrders = orders.filter(order => order.status === 'done');
        const pendingOrders = orders.filter(order => ['draft', 'sent'].includes(order.status));
        return {
            todayCount: orders.length,
            todayTotal: totalAmount,
            pendingCount: pendingOrders.length,
            completedCount: completedOrders.length,
            averageOrderValue: orders.length > 0 ? totalAmount / orders.length : 0,
        };
    }
    async calculatePaymentMetrics(lineAccountId, from, to) {
        const payments = await this.prisma.odooSlipUploads.findMany({
            where: {
                lineAccountId,
                createdAt: {
                    gte: from,
                    lte: to,
                },
            },
        });
        const processedPayments = payments.filter(p => p.status === 'MATCHED');
        const pendingPayments = payments.filter(p => p.status === 'PENDING');
        const totalAmount = processedPayments.reduce((sum, p) => sum + Number(p.amount || 0), 0);
        return {
            pendingSlips: pendingPayments.length,
            processedToday: processedPayments.length,
            matchingRate: payments.length > 0 ? (processedPayments.length / payments.length) * 100 : 0,
            totalAmount,
            averageProcessingTime: 0,
        };
    }
    async calculateWebhookMetrics(lineAccountId, from, to) {
        const webhooks = await this.prisma.odooWebhooksLog.findMany({
            where: {
                lineAccountId,
                createdAt: {
                    gte: from,
                    lte: to,
                },
            },
        });
        const successfulWebhooks = webhooks.filter(w => w.status === 'PROCESSED');
        const failedWebhooks = webhooks.filter(w => w.status === 'FAILED');
        return {
            totalToday: webhooks.length,
            successRate: webhooks.length > 0 ? (successfulWebhooks.length / webhooks.length) * 100 : 0,
            failedCount: failedWebhooks.length,
            averageResponseTime: 0,
        };
    }
    async calculateCustomerMetrics(lineAccountId, from, to) {
        return {
            totalActive: 0,
            newToday: 0,
            topCustomers: [],
        };
    }
    buildMetricsCacheKey(lineAccountId, dateRange) {
        if (dateRange) {
            const fromStr = dateRange.from.toISOString().split('T')[0];
            const toStr = dateRange.to.toISOString().split('T')[0];
            return `metrics:${lineAccountId}:${fromStr}:${toStr}`;
        }
        const today = new Date().toISOString().split('T')[0];
        return `metrics:${lineAccountId}:${today}`;
    }
    getCommonDateRanges() {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        return [
            {
                from: new Date(today.getTime() - 24 * 60 * 60 * 1000),
                to: today,
            },
            {
                from: new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000),
                to: today,
            },
            {
                from: new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000),
                to: today,
            },
        ];
    }
    async getActiveLineAccounts() {
        return [{ id: '1' }];
    }
}
exports.DashboardCacheService = DashboardCacheService;
//# sourceMappingURL=DashboardCacheService.js.map