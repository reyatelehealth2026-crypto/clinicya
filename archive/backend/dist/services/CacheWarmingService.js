"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CacheWarmingService = void 0;
const logger_1 = require("@/utils/logger");
class CacheWarmingService {
    cacheService;
    dashboardCacheService;
    strategies = new Map();
    stats = {
        totalJobs: 0,
        completedJobs: 0,
        failedJobs: 0,
        averageExecutionTime: 0,
        lastExecutionTime: new Date(),
        cacheHitRateImprovement: 0,
    };
    constructor(cacheService, dashboardCacheService) {
        this.cacheService = cacheService;
        this.dashboardCacheService = dashboardCacheService;
        this.setupWarmingStrategies();
    }
    registerStrategy(name, strategy) {
        this.strategies.set(name, strategy);
        logger_1.logger.info('Cache warming strategy registered', { name, priority: strategy.priority });
    }
    async executeAllStrategies() {
        const enabledStrategies = Array.from(this.strategies.values()).filter(s => s.enabled);
        logger_1.logger.info('Starting cache warming execution', {
            totalStrategies: enabledStrategies.length
        });
        const startTime = Date.now();
        let completedCount = 0;
        let failedCount = 0;
        const priorityOrder = ['critical', 'high', 'medium', 'low'];
        for (const priority of priorityOrder) {
            const strategiesForPriority = enabledStrategies.filter(s => s.priority === priority);
            if (priority === 'critical' || priority === 'high') {
                for (const strategy of strategiesForPriority) {
                    try {
                        await this.executeStrategy(strategy);
                        completedCount++;
                    }
                    catch (error) {
                        failedCount++;
                        logger_1.logger.error('Cache warming strategy failed', {
                            strategy: strategy.name,
                            error: String(error)
                        });
                    }
                }
            }
            else {
                const results = await Promise.allSettled(strategiesForPriority.map(strategy => this.executeStrategy(strategy)));
                results.forEach((result, index) => {
                    if (result.status === 'fulfilled') {
                        completedCount++;
                    }
                    else {
                        failedCount++;
                        logger_1.logger.error('Cache warming strategy failed', {
                            strategy: strategiesForPriority[index].name,
                            error: String(result.reason)
                        });
                    }
                });
            }
        }
        const totalTime = Date.now() - startTime;
        this.stats.totalJobs += enabledStrategies.length;
        this.stats.completedJobs += completedCount;
        this.stats.failedJobs += failedCount;
        this.stats.averageExecutionTime =
            (this.stats.averageExecutionTime + totalTime) / 2;
        this.stats.lastExecutionTime = new Date();
        logger_1.logger.info('Cache warming execution completed', {
            completed: completedCount,
            failed: failedCount,
            totalTime,
        });
    }
    async executeStrategy(strategy) {
        const startTime = Date.now();
        logger_1.logger.info('Executing cache warming strategy', {
            name: strategy.name,
            priority: strategy.priority
        });
        try {
            await Promise.race([
                strategy.execute(),
                new Promise((_, reject) => setTimeout(() => reject(new Error(`Strategy timeout after ${strategy.maxExecutionTime}ms`)), strategy.maxExecutionTime)),
            ]);
            const executionTime = Date.now() - startTime;
            logger_1.logger.info('Cache warming strategy completed', {
                name: strategy.name,
                executionTime
            });
        }
        catch (error) {
            const executionTime = Date.now() - startTime;
            logger_1.logger.error('Cache warming strategy failed', {
                name: strategy.name,
                executionTime,
                error: String(error)
            });
            throw error;
        }
    }
    getStats() {
        return { ...this.stats };
    }
    resetStats() {
        this.stats = {
            totalJobs: 0,
            completedJobs: 0,
            failedJobs: 0,
            averageExecutionTime: 0,
            lastExecutionTime: new Date(),
            cacheHitRateImprovement: 0,
        };
    }
    setupWarmingStrategies() {
        this.registerStrategy('dashboard-overview', {
            name: 'Dashboard Overview',
            priority: 'critical',
            schedule: '*/2 * * * *',
            enabled: true,
            maxExecutionTime: 30000,
            execute: async () => {
                const accounts = await this.getActiveAccounts();
                await Promise.all(accounts.map(account => this.dashboardCacheService.getDashboardMetrics(account.id)));
            },
        });
        this.registerStrategy('realtime-metrics', {
            name: 'Real-time Metrics',
            priority: 'high',
            schedule: '*/1 * * * *',
            enabled: true,
            maxExecutionTime: 15000,
            execute: async () => {
                const accounts = await this.getActiveAccounts();
                await Promise.all(accounts.map(account => this.dashboardCacheService.getRealTimeMetrics(account.id)));
            },
        });
        this.registerStrategy('historical-data', {
            name: 'Historical Data',
            priority: 'medium',
            schedule: '0 */6 * * *',
            enabled: true,
            maxExecutionTime: 120000,
            execute: async () => {
                const accounts = await this.getActiveAccounts();
                const dateRanges = this.getCommonDateRanges();
                for (const account of accounts) {
                    await Promise.all(dateRanges.map(range => this.dashboardCacheService.getDashboardMetrics(account.id, range)));
                }
            },
        });
        this.registerStrategy('api-responses', {
            name: 'API Responses',
            priority: 'low',
            schedule: '0 2 * * *',
            enabled: true,
            maxExecutionTime: 300000,
            execute: async () => {
                await this.warmCommonAPIResponses();
            },
        });
        this.registerStrategy('static-content', {
            name: 'Static Content',
            priority: 'low',
            schedule: '0 3 * * *',
            enabled: true,
            maxExecutionTime: 60000,
            execute: async () => {
                await this.warmStaticContent();
            },
        });
    }
    async warmCommonAPIResponses() {
        const commonEndpoints = [
            '/api/v1/dashboard/overview',
            '/api/v1/orders?limit=20',
            '/api/v1/payments/slips?status=pending',
            '/api/v1/webhooks/stats',
        ];
        const accounts = await this.getActiveAccounts();
        for (const account of accounts) {
            for (const endpoint of commonEndpoints) {
                const cacheKey = `api:${endpoint}:${account.id}`;
                const exists = await this.cacheService.exists(cacheKey);
                if (!exists) {
                    await this.cacheService.set(cacheKey, { warmed: true, timestamp: new Date() }, { ttl: 900 });
                }
            }
        }
    }
    async warmStaticContent() {
        const staticKeys = [
            'config:app-settings',
            'config:feature-flags',
            'lookup:order-statuses',
            'lookup:payment-methods',
            'lookup:currencies',
        ];
        for (const key of staticKeys) {
            const exists = await this.cacheService.exists(key);
            if (!exists) {
                await this.cacheService.set(key, { warmed: true, timestamp: new Date() }, { ttl: 86400 });
            }
        }
    }
    async getActiveAccounts() {
        return [
            { id: '1', name: 'Main Account' },
            { id: '2', name: 'Test Account' },
        ];
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
                to: now,
            },
            {
                from: new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000),
                to: now,
            },
            {
                from: new Date(now.getFullYear(), now.getMonth(), 1),
                to: now,
            },
        ];
    }
}
exports.CacheWarmingService = CacheWarmingService;
//# sourceMappingURL=CacheWarmingService.js.map