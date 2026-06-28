"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.TrafficRoutingMiddleware = void 0;
const http_proxy_middleware_1 = require("http-proxy-middleware");
class TrafficRoutingMiddleware {
    featureFlagService;
    logger;
    redis;
    config;
    legacyProxy;
    routingMetrics = new Map();
    constructor(featureFlagService, logger, redis, config) {
        this.featureFlagService = featureFlagService;
        this.logger = logger;
        this.redis = redis;
        this.config = config;
        this.legacyProxy = (0, http_proxy_middleware_1.createProxyMiddleware)({
            target: config.legacyBaseUrl,
            changeOrigin: true,
            pathRewrite: {
                '^/api/v1': '/api'
            },
            onError: (err, req, res) => {
                this.logger.error('Legacy proxy error', { error: err.message, path: req.url });
                res.status(502).json({
                    success: false,
                    error: {
                        code: 'LEGACY_SYSTEM_ERROR',
                        message: 'Legacy system temporarily unavailable'
                    }
                });
            },
            onProxyReq: (proxyReq, req) => {
                proxyReq.setHeader('X-Routed-From', 'new-system');
                proxyReq.setHeader('X-Routing-Timestamp', new Date().toISOString());
            }
        });
    }
    routeTraffic() {
        return async (req, res, next) => {
            try {
                const decision = await this.makeRoutingDecision(req);
                req.routingDecision = decision;
                if (this.config.enableLogging) {
                    this.logRoutingDecision(req, decision);
                }
                if (this.config.enableMetrics) {
                    await this.updateRoutingMetrics(req.path, decision);
                }
                if (decision.useNewSystem) {
                    next();
                }
                else {
                    this.legacyProxy(req, res, next);
                }
            }
            catch (error) {
                this.logger.error('Traffic routing error', { error, path: req.path });
                if (this.config.defaultToLegacy) {
                    this.legacyProxy(req, res, next);
                }
                else {
                    next();
                }
            }
        };
    }
    async makeRoutingDecision(req) {
        const userId = req.user?.userId;
        const userRole = req.user?.role || 'guest';
        const lineAccountId = req.user?.lineAccountId || req.headers['x-line-account-id'];
        const path = req.path;
        const featureFlags = userId
            ? await this.featureFlagService.getFeatureFlags(userId, userRole, lineAccountId)
            : await this.getGuestFeatureFlags();
        const routeFeatureFlag = this.getRouteFeatureFlag(path);
        if (!routeFeatureFlag) {
            return {
                useNewSystem: false,
                reason: 'No feature flag defined for route',
                routingTimestamp: new Date()
            };
        }
        const useNewSystem = featureFlags[routeFeatureFlag];
        if (!useNewSystem && userId) {
            const rolloutDecision = await this.checkGradualRollout(routeFeatureFlag, userId);
            if (rolloutDecision.useNewSystem) {
                return rolloutDecision;
            }
        }
        return {
            useNewSystem,
            reason: useNewSystem ? 'Feature flag enabled' : 'Feature flag disabled',
            featureFlag: routeFeatureFlag,
            routingTimestamp: new Date()
        };
    }
    getRouteFeatureFlag(path) {
        const routeMap = {
            '/api/v1/dashboard': 'useNewDashboard',
            '/api/v1/orders': 'useNewOrderManagement',
            '/api/v1/payments': 'useNewPaymentProcessing',
            '/api/v1/webhooks': 'useNewWebhookManagement',
            '/api/v1/customers': 'useNewCustomerManagement'
        };
        for (const [pattern, flag] of Object.entries(routeMap)) {
            if (path.startsWith(pattern)) {
                return flag;
            }
        }
        return null;
    }
    async checkGradualRollout(featureFlag, userId) {
        try {
            const rolloutPercentage = await this.featureFlagService.getRolloutPercentage(featureFlag);
            if (rolloutPercentage === 0) {
                return {
                    useNewSystem: false,
                    reason: 'Gradual rollout at 0%',
                    featureFlag,
                    routingTimestamp: new Date()
                };
            }
            if (rolloutPercentage === 100) {
                return {
                    useNewSystem: true,
                    reason: 'Gradual rollout at 100%',
                    featureFlag,
                    routingTimestamp: new Date()
                };
            }
            const userHash = this.hashUserId(userId + featureFlag);
            const userPercentile = userHash % 100;
            const useNewSystem = userPercentile < rolloutPercentage;
            return {
                useNewSystem,
                reason: `Gradual rollout ${rolloutPercentage}% - user ${useNewSystem ? 'included' : 'excluded'}`,
                featureFlag,
                routingTimestamp: new Date()
            };
        }
        catch (error) {
            this.logger.error('Gradual rollout check failed', { featureFlag, userId, error });
            return {
                useNewSystem: false,
                reason: 'Gradual rollout check failed',
                featureFlag,
                routingTimestamp: new Date()
            };
        }
    }
    async getGuestFeatureFlags() {
        return {
            useNewDashboard: false,
            useNewOrderManagement: false,
            useNewPaymentProcessing: false,
            useNewWebhookManagement: false,
            useNewCustomerManagement: false,
            enableRealTimeUpdates: false,
            enablePerformanceOptimizations: false,
            enableAdvancedAuditLogging: false
        };
    }
    hashUserId(input) {
        let hash = 0;
        for (let i = 0; i < input.length; i++) {
            const char = input.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash);
    }
    logRoutingDecision(req, decision) {
        this.logger.info('Traffic routing decision', {
            path: req.path,
            method: req.method,
            userId: req.user?.userId,
            userRole: req.user?.role,
            lineAccountId: req.user?.lineAccountId,
            decision: decision.useNewSystem ? 'new-system' : 'legacy-system',
            reason: decision.reason,
            featureFlag: decision.featureFlag,
            abTestVariant: decision.abTestVariant,
            userAgent: req.get('User-Agent'),
            ip: req.ip,
            timestamp: decision.routingTimestamp
        });
    }
    async updateRoutingMetrics(path, decision) {
        try {
            const metricsKey = `routing_metrics:${new Date().toISOString().split('T')[0]}`;
            const system = decision.useNewSystem ? 'new' : 'legacy';
            const metricField = `${path}:${system}`;
            await this.redis.hincrby(metricsKey, metricField, 1);
            await this.redis.expire(metricsKey, 86400 * 7);
            const currentCount = this.routingMetrics.get(metricField) || 0;
            this.routingMetrics.set(metricField, currentCount + 1);
        }
        catch (error) {
            this.logger.error('Failed to update routing metrics', { path, error });
        }
    }
    async getRoutingMetrics(date) {
        try {
            const targetDate = date || new Date().toISOString().split('T')[0];
            const metricsKey = `routing_metrics:${targetDate}`;
            const metrics = await this.redis.hgetall(metricsKey);
            const processed = {
                date: targetDate,
                totalRequests: 0,
                newSystemRequests: 0,
                legacySystemRequests: 0,
                routeBreakdown: {}
            };
            for (const [key, value] of Object.entries(metrics)) {
                const count = parseInt(value);
                processed.totalRequests += count;
                const [route, system] = key.split(':');
                if (system === 'new') {
                    processed.newSystemRequests += count;
                }
                else {
                    processed.legacySystemRequests += count;
                }
                if (!processed.routeBreakdown[route]) {
                    processed.routeBreakdown[route] = { new: 0, legacy: 0, total: 0 };
                }
                processed.routeBreakdown[route][system] = count;
                processed.routeBreakdown[route].total += count;
            }
            if (processed.totalRequests > 0) {
                processed.newSystemPercentage = Math.round((processed.newSystemRequests / processed.totalRequests) * 100);
                processed.legacySystemPercentage = Math.round((processed.legacySystemRequests / processed.totalRequests) * 100);
            }
            return processed;
        }
        catch (error) {
            this.logger.error('Failed to get routing metrics', { date, error });
            return {};
        }
    }
    async healthCheck() {
        const checks = {};
        try {
            checks.featureFlagService = {
                status: 'healthy',
                message: 'Feature flag service accessible'
            };
        }
        catch (error) {
            checks.featureFlagService = {
                status: 'unhealthy',
                message: 'Feature flag service error',
                error: error.message
            };
        }
        try {
            await this.redis.ping();
            checks.redis = {
                status: 'healthy',
                message: 'Redis connection active'
            };
        }
        catch (error) {
            checks.redis = {
                status: 'unhealthy',
                message: 'Redis connection failed',
                error: error.message
            };
        }
        try {
            const response = await fetch(`${this.config.legacyBaseUrl}/api/health`, {
                method: 'GET',
                timeout: 5000
            });
            checks.legacySystem = {
                status: response.ok ? 'healthy' : 'degraded',
                message: `Legacy system HTTP ${response.status}`,
                responseTime: Date.now()
            };
        }
        catch (error) {
            checks.legacySystem = {
                status: 'unhealthy',
                message: 'Legacy system unreachable',
                error: error.message
            };
        }
        const unhealthyCount = Object.values(checks).filter(check => check.status === 'unhealthy').length;
        const degradedCount = Object.values(checks).filter(check => check.status === 'degraded').length;
        let overallStatus;
        if (unhealthyCount > 0) {
            overallStatus = 'unhealthy';
        }
        else if (degradedCount > 0) {
            overallStatus = 'degraded';
        }
        else {
            overallStatus = 'healthy';
        }
        return {
            status: overallStatus,
            checks
        };
    }
}
exports.TrafficRoutingMiddleware = TrafficRoutingMiddleware;
//# sourceMappingURL=trafficRouting.js.map