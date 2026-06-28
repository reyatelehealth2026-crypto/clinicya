import { LoggingService } from '../services/LoggingService.js';
import { ErrorCode } from '../types/errors.js';
export interface RetryConfig {
    maxAttempts: number;
    baseDelay: number;
    maxDelay: number;
    backoffMultiplier: number;
    jitterType: 'none' | 'full' | 'equal' | 'decorrelated';
    retryableErrors: ErrorCode[];
    timeoutMs?: number;
}
export interface RetryContext {
    attempt: number;
    totalAttempts: number;
    lastError?: Error;
    startTime: number;
    operationName: string;
    metadata?: Record<string, any>;
}
export interface RetryResult<T> {
    success: boolean;
    data?: T;
    error?: Error;
    attempts: number;
    totalTime: number;
    retryHistory: RetryAttempt[];
}
export interface RetryAttempt {
    attempt: number;
    timestamp: number;
    delay: number;
    error?: string;
    success: boolean;
}
export declare class RetryMechanism {
    private loggingService;
    private defaultConfig;
    constructor(loggingService: LoggingService);
    execute<T>(operation: (context: RetryContext) => Promise<T>, operationName: string, config?: Partial<RetryConfig>, metadata?: Record<string, any>): Promise<RetryResult<T>>;
    private calculateDelay;
    private applyJitter;
    private isRetryableError;
    private sleep;
    private logRetryAttempt;
    private logRetrySuccess;
    private logRetryExhausted;
    private logNonRetryableError;
    private logRetryFailure;
    static createConfig(type: 'database' | 'external_api' | 'cache' | 'file_upload'): RetryConfig;
}
//# sourceMappingURL=RetryMechanism.d.ts.map