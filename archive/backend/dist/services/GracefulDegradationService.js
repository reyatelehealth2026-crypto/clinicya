"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.GracefulDegradationService = void 0;
class GracefulDegradationService {
    cacheService;
    loggingService;
    strategies = new Map();
    serviceHealth = new Map();
    fallbackData = new Map();
    constructor(cacheService, loggingService) {
        this.cacheService = cacheService;
        this.loggingService = loggingService;
        this.initializeStrategies();
        this.initializeFallbackData();
    }
    initializeStrategies() {
        this.strategies.set('database', {
            name: 'Database Fallback',
            priority: 1,
            condition: (error) => error.message.includes('database') || error.message.includes('connection'),
            fallback: async (context) => {
                const cacheKey = `fallback:${context.endpoint}:${JSON.stringify(context.params)}`;
                const cachedData = await this.cacheService.get(cacheKey);
                if (cachedData) {
                    await this.loggingService.logEvent('warn', 'Using cached data due to database error', {
                        endpoint: context.endpoint,
                        cacheKey
                    });
                    return cachedData;
                }
                return this.getStaticFallback(context.endpoint);
            }
        });
        this.strategies.set('external_service', {
            name: 'External Service Fallback',
            priority: 2,
            condition: (error) => error.message.includes('external') || error.message.includes('timeout'),
            fallback: async (context) => {
                const cacheKey = `service:${context.service}:${JSON.stringify(context.params)}`;
                const cachedData = await this.cacheService.get(cacheKey);
                if (cachedData) {
                    return {
                        ...cachedData,
                        _degraded: true,
                        _degradationReason: 'External service unavailable, using cached data'
                    };
                }
                return this.getMinimalFunctionality(context.service);
            }
        });
        this.strategies.set('cache', {
            name: 'Cache Fallback',
            priority: 3,
            condition: (error) => error.message.includes('cache') || error.message.includes('redis'),
            fallback: async (context) => {
                await this.loggingService.logEvent('warn', 'Cache unavailable, proceeding without caching', {
                    endpoint: context.endpoint
                });
                return {
                    _degraded: true,
                    _degradationReason: 'Cache service unavailable',
                    _cacheDisabled: true
                };
            }
        });
        this.strategies.set('realtime', {
            name: 'Real-time Fallback',
            priority: 4,
            condition: (error) => error.message.includes('websocket') || error.message.includes('socket'),
            fallback: async (context) => {
                return {
                    _degraded: true,
                    _degradationReason: 'Real-time updates unavailable, using polling',
                    _pollingInterval: 30000
                };
            }
        });
    }
    initializeFallbackData() {
        this.fallbackData.set('/api/v1/dashboard/overview', {
            orders: {
                todayCount: 0,
                todayTotal: 0,
                pendingCount: 0,
                completedCount: 0,
                averageOrderValue: 0
            },
            payments: {
                pendingSlips: 0,
                processedToday: 0,
                matchingRate: 0,
                totalAmount: 0
            },
            webhooks: {
                successRate: 0,
                totalEvents: 0,
                failedEvents: 0
            },
            _degraded: true,
            _degradationReason: 'Service unavailable, showing default values'
        });
        this.fallbackData.set('/api/v1/orders', {
            data: [],
            total: 0,
            page: 1,
            totalPages: 0,
            _degraded: true,
            _degradationReason: 'Order service unavailable'
        });
        this.fallbackData.set('/api/v1/payments/slips', {
            data: [],
            total: 0,
            page: 1,
            totalPages: 0,
            _degraded: true,
            _degradationReason: 'Payment service unavailable'
        });
    }
    async applyDegradation(error, context) {
        const strategy = this.findApplicableStrategy(error);
        if (!strategy) {
            return this.createGenericFallback(error, context);
        }
        try {
            const fallbackData = await strategy.fallback(context);
            await this.loggingService.logEvent('warn', `Applied degradation strategy: ${strategy.name}`, {
                error: error.message,
                strategy: strategy.name,
                endpoint: context.endpoint,
                requestId: context.requestId
            });
            this.updateServiceHealth(context.service || 'unknown', false);
            return {
                success: true,
                data: fallbackData,
                meta: {
                    requestId: context.requestId,
                    processingTime: 0,
                    degraded: true,
                    degradationReason: `${strategy.name}: ${error.message}`,
                    degradationStrategy: strategy.name
                }
            };
        }
        catch (degradationError) {
            await this.loggingService.logEvent('error', 'Degradation strategy failed', {
                originalError: error.message,
                degradationError: degradationError.message,
                strategy: strategy.name,
                requestId: context.requestId
            });
            return this.createGenericFallback(error, context);
        }
    }
    findApplicableStrategy(error) {
        const applicableStrategies = Array.from(this.strategies.values())
            .filter(strategy => strategy.condition(error))
            .sort((a, b) => a.priority - b.priority);
        return applicableStrategies[0] || null;
    }
    createGenericFallback(error, context) {
        const fallbackData = this.getStaticFallback(context.endpoint);
        return {
            success: true,
            data: fallbackData,
            meta: {
                requestId: context.requestId,
                processingTime: 0,
                degraded: true,
                degradationReason: `Service temporarily unavailable: ${error.message}`,
                degradationStrategy: 'generic'
            }
        };
    }
    getStaticFallback(endpoint) {
        return this.fallbackData.get(endpoint) || {
            _degraded: true,
            _degradationReason: 'Service temporarily unavailable',
            _message: 'Please try again later'
        };
    }
    getMinimalFunctionality(service) {
        switch (service) {
            case 'odoo':
                return {
                    orders: [],
                    customers: [],
                    _degraded: true,
                    _degradationReason: 'Odoo ERP service unavailable'
                };
            case 'line':
                return {
                    messaging: false,
                    notifications: false,
                    _degraded: true,
                    _degradationReason: 'LINE API service unavailable'
                };
            case 'payment':
                return {
                    processing: false,
                    matching: false,
                    _degraded: true,
                    _degradationReason: 'Payment processing service unavailable'
                };
            default:
                return {
                    _degraded: true,
                    _degradationReason: `${service} service unavailable`
                };
        }
    }
    updateServiceHealth(service, healthy) {
        const currentHealth = this.serviceHealth.get(service) || {
            service,
            healthy: true,
            lastCheck: new Date(),
            errorCount: 0,
            degradationLevel: 'none'
        };
        currentHealth.healthy = healthy;
        currentHealth.lastCheck = new Date();
        if (!healthy) {
            currentHealth.errorCount++;
        }
        else {
            currentHealth.errorCount = Math.max(0, currentHealth.errorCount - 1);
        }
        if (currentHealth.errorCount >= 10) {
            currentHealth.degradationLevel = 'full';
        }
        else if (currentHealth.errorCount >= 5) {
            currentHealth.degradationLevel = 'partial';
        }
        else {
            currentHealth.degradationLevel = 'none';
        }
        this.serviceHealth.set(service, currentHealth);
    }
    getServiceHealth() {
        const health = {};
        for (const [service, status] of this.serviceHealth.entries()) {
            health[service] = { ...status };
        }
        return health;
    }
    isServiceDegraded(service) {
        const health = this.serviceHealth.get(service);
        return health ? health.degradationLevel !== 'none' : false;
    }
    getDegradationLevel(service) {
        const health = this.serviceHealth.get(service);
        return health ? health.degradationLevel : 'none';
    }
    resetServiceHealth(service) {
        const health = this.serviceHealth.get(service);
        if (health) {
            health.healthy = true;
            health.errorCount = 0;
            health.degradationLevel = 'none';
            health.lastCheck = new Date();
        }
    }
    getDegradationStatistics() {
        const services = Array.from(this.serviceHealth.values());
        return {
            totalServices: services.length,
            healthyServices: services.filter(s => s.degradationLevel === 'none').length,
            degradedServices: services.filter(s => s.degradationLevel === 'partial').length,
            criticalServices: services.filter(s => s.degradationLevel === 'full').length,
            degradationStrategies: Array.from(this.strategies.keys())
        };
    }
}
exports.GracefulDegradationService = GracefulDegradationService;
//# sourceMappingURL=GracefulDegradationService.js.map