import { PoolConnection } from 'mysql2/promise';
export interface PoolStats {
    totalConnections: number;
    activeConnections: number;
    idleConnections: number;
    queuedRequests: number;
    totalQueries: number;
    averageQueryTime: number;
    slowQueries: number;
}
export interface QueryMetrics {
    query: string;
    executionTime: number;
    rowsAffected: number;
    timestamp: Date;
}
export declare class DatabasePoolService {
    private pool;
    private queryMetrics;
    private totalQueries;
    private totalQueryTime;
    private slowQueryThreshold;
    constructor();
    query<T = any>(sql: string, params?: any[]): Promise<T[]>;
    queryOne<T = any>(sql: string, params?: any[]): Promise<T | null>;
    transaction<T>(callback: (connection: PoolConnection) => Promise<T>): Promise<T>;
    getPoolStats(): PoolStats;
    getQueryMetrics(limit?: number): QueryMetrics[];
    getSlowQueries(threshold?: number): QueryMetrics[];
    healthCheck(): Promise<{
        status: 'healthy' | 'unhealthy';
        details: any;
    }>;
    close(): Promise<void>;
    private setupEventHandlers;
    private recordQueryMetrics;
    private sanitizeQuery;
    private startMetricsCollection;
}
export declare const getDatabasePool: () => DatabasePoolService;
export declare const closeDatabasePool: () => Promise<void>;
//# sourceMappingURL=DatabasePoolService.d.ts.map