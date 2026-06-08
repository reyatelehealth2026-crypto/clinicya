import { BaseService } from './BaseService';
import { CacheService } from './CacheService';
export interface DashboardMetrics {
    orders: {
        todayCount: number;
        todayTotal: number;
        pendingCount: number;
        completedCount: number;
        averageOrderValue: number;
    };
    payments: {
        pendingSlips: number;
        processedToday: number;
        matchingRate: number;
        totalAmount: number;
        averageProcessingTime: number;
    };
    webhooks: {
        totalToday: number;
        successRate: number;
        failedCount: number;
        averageResponseTime: number;
    };
    customers: {
        totalActive: number;
        newToday: number;
        topCustomers: Array<{
            id: string;
            name: string;
            totalOrders: number;
            totalAmount: number;
        }>;
    };
    updatedAt: Date;
}
export interface CacheWarmingJob {
    name: string;
    schedule: string;
    cacheKeys: string[];
    priority: 'high' | 'medium' | 'low';
    maxExecutionTime: number;
    enabled: boolean;
}
export declare class DashboardCacheService extends BaseService {
    private cacheService;
    private invalidationService;
    private warmingJobs;
    private readonly CACHE_TTL;
    constructor(prisma: any, cacheService: CacheService);
    getDashboardMetrics(lineAccountId: string, dateRange?: {
        from: Date;
        to: Date;
    }): Promise<DashboardMetrics>;
    getRealTimeMetrics(lineAccountId: string): Promise<Partial<DashboardMetrics>>;
    warmDashboardCache(lineAccountIds: string[]): Promise<void>;
    invalidateDashboardCache(lineAccountId: string, eventType: 'order_updated' | 'payment_processed' | 'webhook_received'): Promise<void>;
    getCacheStats(): Promise<{
        hitRate: number;
        totalRequests: number;
        cacheSize: number;
        topKeys: Array<{
            key: string;
            hits: number;
        }>;
    }>;
    private setupCacheWarmingJobs;
    executeCacheWarmingJob(jobName: string): Promise<void>;
    private calculateDashboardMetrics;
    private calculateRealTimeMetrics;
    private calculateOrderMetrics;
    private calculatePaymentMetrics;
    private calculateWebhookMetrics;
    private calculateCustomerMetrics;
    private buildMetricsCacheKey;
    private getCommonDateRanges;
    private getActiveLineAccounts;
}
//# sourceMappingURL=DashboardCacheService.d.ts.map