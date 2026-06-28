export interface RetryOptions {
    maxRetries: number;
    baseDelay: number;
    maxDelay: number;
    backoffMultiplier: number;
    jitter: boolean;
}
export declare class RetryHandler {
    private name;
    private options;
    constructor(name: string, options?: RetryOptions);
    executeWithRetry<T>(operation: () => Promise<T>, shouldRetry?: (error: Error) => boolean): Promise<T>;
    private calculateDelay;
    private sleep;
    static shouldRetryError(error: Error): boolean;
}
//# sourceMappingURL=RetryHandler.d.ts.map