import { PrismaClient } from '@prisma/client';
import { ErrorLogEntry, ErrorSeverity } from '../types/errors.js';
interface LogConfig {
    enableFileLogging: boolean;
    enableDatabaseLogging: boolean;
    logDirectory: string;
    maxFileSize: number;
    retentionDays: number;
}
export declare class LoggingService {
    private prisma;
    private config;
    private logStreams;
    constructor(prisma: PrismaClient, config?: Partial<LogConfig>);
    private initializeLogging;
    logError(logEntry: ErrorLogEntry): Promise<void>;
    private logToDatabase;
    private logToFile;
    logEvent(level: 'info' | 'warn' | 'debug', message: string, details?: Record<string, any>, requestId?: string): Promise<void>;
    getErrorLogs(filters: {
        level?: ErrorSeverity;
        code?: string;
        dateFrom?: Date;
        dateTo?: Date;
        userId?: string;
        page?: number;
        limit?: number;
    }): Promise<{
        logs: ErrorLogEntry[];
        total: number;
        page: number;
        totalPages: number;
    }>;
    getErrorStatistics(timeRange: {
        from: Date;
        to: Date;
    }): Promise<{
        totalErrors: number;
        errorsByLevel: Record<ErrorSeverity, number>;
        errorsByCode: Record<string, number>;
        errorTrends: Array<{
            date: string;
            count: number;
        }>;
    }>;
    private scheduleLogCleanup;
    private cleanupOldLogs;
    close(): Promise<void>;
}
export {};
//# sourceMappingURL=LoggingService.d.ts.map