"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.FeatureFlagService = void 0;
class FeatureFlagService {
    redis;
    logger;
    cachePrefix = 'feature_flags:';
    abTestPrefix = 'ab_tests:';
    userAssignmentPrefix = 'user_assignments:';
    constructor(redis, logger) {
        this.redis = redis;
        this.logger = logger;
    }
    async getFeatureFlags(userId, userRole, lineAccountId) {
        try {
            const cachedConfig = await this.getCachedUserConfig(userId);
            if (cachedConfig) {
                return cachedConfig;
            }
            const baseFlags = await this.getBaseFeatureFlags();
            const userFlags = await this.getUserSpecificFlags(userId, userRole, lineAccountId);
            const abTestFlags = await this.getABTestAssignment(userId, userRole);
            const finalConfig = {
                ...baseFlags,
                ...userFlags,
                ...abTestFlags
            };
            await this.cacheUserConfig(userId, finalConfig, 300);
            this.logger.info('Feature flags retrieved', {
                userId,
                userRole,
                lineAccountId,
                config: finalConfig
            });
            return finalConfig;
        }
        catch (error) {
            this.logger.error('Failed to get feature flags', { userId, error });
            return this.getDefaultFeatureFlags();
        }
    }
    async updateFeatureFlag(flagName, config, updatedBy) {
        try {
            const existingFlag = await this.getFeatureFlag(flagName);
            const updatedFlag = {
                ...existingFlag,
                ...config,
                updatedAt: new Date()
            };
            await this.redis.hset(`${this.cachePrefix}${flagName}`, 'config', JSON.stringify(updatedFlag));
            await this.invalidateUserCaches();
            this.logger.info('Feature flag updated', {
                flagName,
                updatedBy,
                config: updatedFlag
            });
        }
        catch (error) {
            this.logger.error('Failed to update feature flag', { flagName, error });
            throw error;
        }
    }
    async createABTest(testConfig, createdBy) {
        try {
            this.validateABTestConfig(testConfig);
            const testData = {
                ...testConfig,
                createdBy,
                createdAt: new Date(),
                status: 'active'
            };
            await this.redis.hset(`${this.abTestPrefix}${testConfig.testName}`, 'config', JSON.stringify(testData));
            const ttl = Math.floor((testConfig.endDate.getTime() - Date.now()) / 1000);
            if (ttl > 0) {
                await this.redis.expire(`${this.abTestPrefix}${testConfig.testName}`, ttl);
            }
            this.logger.info('A/B test created', {
                testName: testConfig.testName,
                createdBy,
                variants: testConfig.variants.length
            });
        }
        catch (error) {
            this.logger.error('Failed to create A/B test', { testConfig, error });
            throw error;
        }
    }
    async getABTestAssignment(userId, userRole) {
        try {
            const existingAssignment = await this.redis.get(`${this.userAssignmentPrefix}${userId}`);
            if (existingAssignment) {
                return JSON.parse(existingAssignment);
            }
            const activeTests = await this.getActiveABTests();
            let finalConfig = {};
            for (const test of activeTests) {
                if (this.isUserEligibleForTest(test, userRole)) {
                    const assignment = this.assignUserToVariant(userId, test);
                    finalConfig = { ...finalConfig, ...assignment.config };
                    this.logger.info('User assigned to A/B test variant', {
                        userId,
                        testName: test.testName,
                        variant: assignment.name,
                        userRole
                    });
                }
            }
            if (Object.keys(finalConfig).length > 0) {
                await this.redis.setex(`${this.userAssignmentPrefix}${userId}`, 3600, JSON.stringify(finalConfig));
            }
            return finalConfig;
        }
        catch (error) {
            this.logger.error('Failed to get A/B test assignment', { userId, error });
            return {};
        }
    }
    async isFeatureEnabled(featureName, userId, userRole, lineAccountId) {
        try {
            const config = await this.getFeatureFlags(userId, userRole, lineAccountId);
            return config[featureName] || false;
        }
        catch (error) {
            this.logger.error('Failed to check feature flag', { featureName, userId, error });
            return false;
        }
    }
    async getRolloutPercentage(flagName) {
        try {
            const flag = await this.getFeatureFlag(flagName);
            return flag.rolloutPercentage;
        }
        catch (error) {
            this.logger.error('Failed to get rollout percentage', { flagName, error });
            return 0;
        }
    }
    async updateRolloutPercentage(flagName, percentage, updatedBy) {
        try {
            if (percentage < 0 || percentage > 100) {
                throw new Error('Rollout percentage must be between 0 and 100');
            }
            await this.updateFeatureFlag(flagName, { rolloutPercentage: percentage }, updatedBy);
            this.logger.info('Rollout percentage updated', {
                flagName,
                percentage,
                updatedBy
            });
        }
        catch (error) {
            this.logger.error('Failed to update rollout percentage', { flagName, error });
            throw error;
        }
    }
    async getABTestAnalytics(testName) {
        try {
            const analyticsKey = `ab_analytics:${testName}`;
            const analytics = await this.redis.hgetall(analyticsKey);
            return {
                testName,
                totalUsers: parseInt(analytics.totalUsers || '0'),
                variantDistribution: JSON.parse(analytics.variantDistribution || '{}'),
                conversionRates: JSON.parse(analytics.conversionRates || '{}'),
                lastUpdated: analytics.lastUpdated
            };
        }
        catch (error) {
            this.logger.error('Failed to get A/B test analytics', { testName, error });
            return null;
        }
    }
    async getBaseFeatureFlags() {
        const flags = await this.redis.hgetall(`${this.cachePrefix}base`);
        if (Object.keys(flags).length === 0) {
            return this.getDefaultFeatureFlags();
        }
        return JSON.parse(flags.config || '{}');
    }
    async getUserSpecificFlags(userId, userRole, lineAccountId) {
        const roleFlags = await this.redis.hgetall(`${this.cachePrefix}role:${userRole}`);
        const accountFlags = await this.redis.hgetall(`${this.cachePrefix}account:${lineAccountId}`);
        const userFlags = await this.redis.hgetall(`${this.cachePrefix}user:${userId}`);
        const merged = {
            ...(roleFlags.config ? JSON.parse(roleFlags.config) : {}),
            ...(accountFlags.config ? JSON.parse(accountFlags.config) : {}),
            ...(userFlags.config ? JSON.parse(userFlags.config) : {})
        };
        return merged;
    }
    async getFeatureFlag(flagName) {
        const flag = await this.redis.hget(`${this.cachePrefix}${flagName}`, 'config');
        if (!flag) {
            throw new Error(`Feature flag '${flagName}' not found`);
        }
        return JSON.parse(flag);
    }
    async getCachedUserConfig(userId) {
        const cached = await this.redis.get(`user_config:${userId}`);
        return cached ? JSON.parse(cached) : null;
    }
    async cacheUserConfig(userId, config, ttl) {
        await this.redis.setex(`user_config:${userId}`, ttl, JSON.stringify(config));
    }
    async invalidateUserCaches() {
        const keys = await this.redis.keys('user_config:*');
        if (keys.length > 0) {
            await this.redis.del(...keys);
        }
    }
    async getActiveABTests() {
        const testKeys = await this.redis.keys(`${this.abTestPrefix}*`);
        const tests = [];
        for (const key of testKeys) {
            const testData = await this.redis.hget(key, 'config');
            if (testData) {
                const test = JSON.parse(testData);
                const now = new Date();
                if (new Date(test.startDate) <= now && new Date(test.endDate) >= now) {
                    tests.push(test);
                }
            }
        }
        return tests;
    }
    isUserEligibleForTest(test, userRole) {
        if (!test.targetUserGroups || test.targetUserGroups.length === 0) {
            return true;
        }
        return test.targetUserGroups.includes(userRole);
    }
    assignUserToVariant(userId, test) {
        const hash = this.hashUserId(userId + test.testName);
        const percentage = hash % 100;
        let cumulativePercentage = 0;
        for (const variant of test.variants) {
            cumulativePercentage += variant.percentage;
            if (percentage < cumulativePercentage) {
                return variant;
            }
        }
        return test.variants[0];
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
    validateABTestConfig(config) {
        if (!config.testName || config.testName.trim() === '') {
            throw new Error('Test name is required');
        }
        if (!config.variants || config.variants.length === 0) {
            throw new Error('At least one variant is required');
        }
        const totalPercentage = config.variants.reduce((sum, variant) => sum + variant.percentage, 0);
        if (Math.abs(totalPercentage - 100) > 0.01) {
            throw new Error('Variant percentages must sum to 100');
        }
        if (config.startDate >= config.endDate) {
            throw new Error('Start date must be before end date');
        }
    }
    getDefaultFeatureFlags() {
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
}
exports.FeatureFlagService = FeatureFlagService;
//# sourceMappingURL=FeatureFlagService.js.map