import { CacheService } from './CacheService';
import { DashboardCacheService } from './DashboardCacheService';
export interface WarmingStrategy {
    name: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    schedule: string;
    enabled: boolean;
    maxExecutionTime: number;
    execute: () => Promise<void>;
}
export interface WarmingStats {
    totalJobs: number;
    completedJobs: number;
    failedJobs: number;
    averageExecutionTime: number;
    lastExecutionTime: Date;
    cacheHitRateImprovement: number;
}
export declare class CacheWarmingService {
    private cacheService;
    private dashboardCacheService;
    private strategies;
    private stats;
    constructor(cacheService: CacheService, dashboardCacheService: DashboardCacheService);
    registerStrategy(name: string, strategy: WarmingStrategy): void;
    executeAllStrategies(): Promise<void>;
    executeStrategy(strategy: WarmingStrategy): Promise<void>;
    getStats(): WarmingStats;
    resetStats(): void;
    private setupWarmingStrategies;
    private warmCommonAPIResponses;
    private warmStaticContent;
    private getActiveAccounts;
    private getCommonDateRanges;
}
//# sourceMappingURL=CacheWarmingService.d.ts.map