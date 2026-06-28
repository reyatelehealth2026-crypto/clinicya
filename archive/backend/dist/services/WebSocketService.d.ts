import { DashboardWebSocketServer } from '../websocket/server';
import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';
export interface DashboardMetricsUpdate {
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
        todayCount: number;
        successRate: number;
        failedCount: number;
        averageResponseTime: number;
    };
    customers: {
        totalActive: number;
        newToday: number;
        lineConnected: number;
        averageOrdersPerCustomer: number;
    };
    updatedAt: string;
}
export interface OrderStatusUpdate {
    orderId: string;
    oldStatus: string;
    newStatus: string;
    updatedBy: string;
    updatedAt: string;
    customerRef?: string;
    totalAmount?: number;
}
export interface PaymentProcessedUpdate {
    paymentId: string;
    orderId?: string;
    amount: number;
    status: 'matched' | 'processed' | 'failed';
    processedBy: string;
    processedAt: string;
    matchingRate?: number;
}
export interface WebhookReceivedUpdate {
    webhookId: string;
    type: string;
    status: 'success' | 'failed' | 'pending';
    responseTime: number;
    receivedAt: string;
    payload?: any;
}
export declare class WebSocketService extends BaseService {
    private webSocketServer;
    constructor(prisma: PrismaClient);
    setWebSocketServer(server: DashboardWebSocketServer): void;
    broadcastMetricsUpdate(lineAccountId: string, metrics: DashboardMetricsUpdate): Promise<void>;
    broadcastOrderStatusChange(lineAccountId: string, orderUpdate: OrderStatusUpdate): Promise<void>;
    broadcastPaymentProcessed(lineAccountId: string, paymentUpdate: PaymentProcessedUpdate): Promise<void>;
    broadcastWebhookReceived(lineAccountId: string, webhookUpdate: WebhookReceivedUpdate): Promise<void>;
    getConnectionStats(): {
        totalConnections: number;
        accountsConnected: number;
        connectionsByAccount: Array<{
            accountId: string;
            connections: number;
        }>;
    } | null;
    isWebSocketAvailable(): boolean;
    broadcastCustomEvent(lineAccountId: string, eventType: string, data: any): Promise<void>;
    startPeriodicUpdates(intervalMs?: number): NodeJS.Timeout;
}
//# sourceMappingURL=WebSocketService.d.ts.map