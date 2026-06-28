import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';
export interface OrderFilters {
    status?: string[];
    customerRef?: string;
    customerName?: string;
    dateFrom?: Date;
    dateTo?: Date;
    search?: string;
}
export interface PaginationOptions {
    page: number;
    limit: number;
    sort?: string;
    order?: 'asc' | 'desc';
}
export interface OrderWithTimeline {
    id: string;
    odooOrderId: string;
    lineAccountId: string;
    customerRef: string | null;
    customerName: string | null;
    status: string;
    totalAmount: number;
    currency: string;
    orderDate: Date | null;
    deliveryDate: Date | null;
    notes: string | null;
    webhookProcessed: boolean;
    createdAt: Date;
    updatedAt: Date;
    timeline: OrderTimelineEntry[];
}
export interface OrderTimelineEntry {
    id: string;
    orderId: string;
    status: string;
    previousStatus: string | null;
    notes: string | null;
    changedBy: string | null;
    changedAt: Date;
    source: 'system' | 'manual' | 'webhook';
}
export interface PaginatedOrders {
    data: OrderWithTimeline[];
    meta: {
        page: number;
        limit: number;
        total: number;
        totalPages: number;
    };
}
export declare class OrderService extends BaseService {
    constructor(prisma: PrismaClient);
    getOrders(lineAccountId: string, filters?: OrderFilters, pagination?: PaginationOptions): Promise<PaginatedOrders>;
    getOrderById(orderId: string, lineAccountId: string): Promise<OrderWithTimeline | null>;
    updateOrderStatus(orderId: string, lineAccountId: string, newStatus: string, notes?: string, changedBy?: string): Promise<OrderWithTimeline>;
    getOrderTimeline(orderId: string): Promise<OrderTimelineEntry[]>;
    private createTimelineEntry;
    getOrderStatistics(lineAccountId: string, dateFrom?: Date, dateTo?: Date): Promise<{
        totalOrders: number;
        totalValue: number;
        statusBreakdown: Record<string, number>;
        averageOrderValue: number;
        topCustomers: Array<{
            customerName: string;
            orderCount: number;
            totalValue: number;
        }>;
    }>;
    searchOrders(lineAccountId: string, searchQuery: string, filters?: OrderFilters, pagination?: PaginationOptions): Promise<PaginatedOrders>;
    getOrdersByStatus(lineAccountId: string, status: string, pagination?: PaginationOptions): Promise<PaginatedOrders>;
    getRecentOrders(lineAccountId: string, limit?: number): Promise<OrderWithTimeline[]>;
}
//# sourceMappingURL=OrderService.d.ts.map