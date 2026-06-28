import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';
interface DashboardMetrics {
    orders: OrderMetrics;
    payments: PaymentMetrics;
    webhooks: WebhookMetrics;
    customers: CustomerMetrics;
    updatedAt: Date;
}
interface OrderMetrics {
    todayCount: number;
    todayTotal: number;
    pendingCount: number;
    completedCount: number;
    averageOrderValue: number;
}
interface PaymentMetrics {
    pendingSlips: number;
    processedToday: number;
    matchingRate: number;
    totalAmount: number;
    averageProcessingTime: number;
}
interface WebhookMetrics {
    todayCount: number;
    successRate: number;
    failedCount: number;
    averageResponseTime: number;
}
interface CustomerMetrics {
    totalActive: number;
    newToday: number;
    lineConnected: number;
    averageOrdersPerCustomer: number;
}
export declare class DashboardService extends BaseService {
    constructor(prisma: PrismaClient);
    getOverviewMetrics(lineAccountId: string, dateFrom?: Date, dateTo?: Date): Promise<DashboardMetrics>;
    private getCachedMetrics;
    private cacheMetrics;
    private getOrderMetrics;
    private getPaymentMetrics;
    private getWebhookMetrics;
    private getCustomerMetrics;
    getDetailedMetrics(lineAccountId: string, metricType: 'orders' | 'payments' | 'webhooks' | 'customers', dateFrom?: Date, dateTo?: Date): Promise<any>;
    getChartData(lineAccountId: string, chartType: 'orderTrends' | 'paymentTrends' | 'webhookStats', dateFrom?: Date, dateTo?: Date): Promise<any>;
    private getOrderTrends;
    private getPaymentTrends;
    private getWebhookTrends;
    private getCustomerTrends;
    private getOrderChartData;
    private getPaymentChartData;
    private getWebhookChartData;
    private groupByStatus;
    private groupByField;
    private groupByDate;
    private generateChartData;
    private generateWebhookChartData;
}
export {};
//# sourceMappingURL=DashboardService.d.ts.map