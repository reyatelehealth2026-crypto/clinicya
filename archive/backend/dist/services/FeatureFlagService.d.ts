import { Redis } from 'ioredis';
import { Logger } from './LoggingService';
export interface FeatureFlag {
    name: string;
    enabled: boolean;
    rolloutPercentage: number;
    userGroups: string[];
    startDate?: Date;
    endDate?: Date;
    description: string;
    createdBy: string;
    createdAt: Date;
    updatedAt: Date;
}
export interface FeatureFlagConfig {
    useNewDashboard: boolean;
    useNewOrderManagement: boolean;
    useNewPaymentProcessing: boolean;
    useNewWebhookManagement: boolean;
    useNewCustomerManagement: boolean;
    enableRealTimeUpdates: boolean;
    enablePerformanceOptimizations: boolean;
    enableAdvancedAuditLogging: boolean;
}
export interface ABTestConfig {
    testName: string;
    variants: {
        name: string;
        percentage: number;
        config: Partial<FeatureFlagConfig>;
    }[];
    startDate: Date;
    endDate: Date;
    targetUserGroups?: string[];
}
export declare class FeatureFlagService {
    private redis;
    private logger;
    private cachePrefix;
    private abTestPrefix;
    private userAssignmentPrefix;
    constructor(redis: Redis, logger: Logger);
    getFeatureFlags(userId: string, userRole: string, lineAccountId: string): Promise<FeatureFlagConfig>;
    updateFeatureFlag(flagName: string, config: Partial<FeatureFlag>, updatedBy: string): Promise<void>;
    createABTest(testConfig: ABTestConfig, createdBy: string): Promise<void>;
    getABTestAssignment(userId: string, userRole: string): Promise<Partial<FeatureFlagConfig>>;
    isFeatureEnabled(featureName: keyof FeatureFlagConfig, userId: string, userRole: string, lineAccountId: string): Promise<boolean>;
    getRolloutPercentage(flagName: string): Promise<number>;
    updateRolloutPercentage(flagName: string, percentage: number, updatedBy: string): Promise<void>;
    getABTestAnalytics(testName: string): Promise<any>;
    private getBaseFeatureFlags;
    private getUserSpecificFlags;
    private getFeatureFlag;
    private getCachedUserConfig;
    private cacheUserConfig;
    private invalidateUserCaches;
    private getActiveABTests;
    private isUserEligibleForTest;
    private assignUserToVariant;
    private hashUserId;
    private validateABTestConfig;
    private getDefaultFeatureFlags;
}
//# sourceMappingURL=FeatureFlagService.d.ts.map