import { BaseService } from './BaseService';
export interface OdooOrder {
    id: string;
    name: string;
    partner_id: number;
    partner_name: string;
    amount_total: number;
    state: string;
    date_order: string;
    currency_id: number;
}
export interface OdooCustomer {
    id: number;
    name: string;
    email?: string;
    phone?: string;
    credit_limit: number;
    total_due: number;
}
export interface OdooInvoice {
    id: string;
    name: string;
    partner_id: number;
    amount_total: number;
    state: string;
    invoice_date: string;
    due_date: string;
}
export declare class OdooService extends BaseService {
    private circuitBreaker;
    private retryHandler;
    private baseUrl;
    private apiKey;
    constructor(prisma: any);
    getOrders(filters?: {
        limit?: number;
        offset?: number;
        dateFrom?: string;
        dateTo?: string;
        state?: string;
    }): Promise<OdooOrder[]>;
    getCustomers(filters?: {
        limit?: number;
        offset?: number;
        search?: string;
    }): Promise<OdooCustomer[]>;
    getInvoices(filters?: {
        limit?: number;
        offset?: number;
        dateFrom?: string;
        dateTo?: string;
        state?: string;
    }): Promise<OdooInvoice[]>;
    updateOrderStatus(orderId: string, status: string): Promise<void>;
    getCircuitBreakerStats(): import("@/utils/CircuitBreaker").CircuitBreakerStats;
    healthCheck(): Promise<{
        status: 'ok' | 'error';
        message: string;
    }>;
    private makeRequest;
    private isConfigured;
    private getCachedOrders;
    private getCachedCustomers;
    private getCachedInvoices;
    private cacheOrders;
    private cacheCustomers;
    private cacheInvoices;
    private invalidateOrdersCache;
}
//# sourceMappingURL=OdooService.d.ts.map