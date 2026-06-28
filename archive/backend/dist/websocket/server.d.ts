import { Server as HTTPServer } from 'http';
import { Socket } from 'socket.io';
import { PrismaClient } from '@prisma/client';
export interface AuthenticatedSocket extends Socket {
    userId: string;
    username: string;
    lineAccountId: string;
    role: string;
    permissions: string[];
}
export interface DashboardUpdateEvent {
    type: 'metrics_updated' | 'order_status_changed' | 'payment_processed' | 'webhook_received';
    data: any;
    lineAccountId: string;
    timestamp: number;
}
export declare class DashboardWebSocketServer {
    private io;
    private prisma;
    private redisClient;
    private redisSubscriber;
    private connections;
    private heartbeatInterval;
    constructor(httpServer: HTTPServer, prisma: PrismaClient);
    private setupRedisAdapter;
    private setupEventHandlers;
    private authenticationMiddleware;
    private handleConnection;
    private setupSocketEventHandlers;
    private handleDisconnection;
    private handleDashboardUpdate;
    broadcastDashboardUpdate(event: DashboardUpdateEvent): Promise<void>;
    private startHeartbeat;
    private getUserPermissions;
    getConnectionStats(): {
        totalConnections: number;
        accountsConnected: number;
        connectionsByAccount: Array<{
            accountId: string;
            connections: number;
        }>;
    };
    shutdown(): Promise<void>;
}
//# sourceMappingURL=server.d.ts.map