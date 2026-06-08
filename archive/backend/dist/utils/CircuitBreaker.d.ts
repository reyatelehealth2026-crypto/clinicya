export declare enum CircuitState {
    CLOSED = "CLOSED",
    OPEN = "OPEN",
    HALF_OPEN = "HALF_OPEN"
}
export interface CircuitBreakerOptions {
    failureThreshold: number;
    recoveryTimeout: number;
    successThreshold: number;
    timeout: number;
}
export interface CircuitBreakerStats {
    state: CircuitState;
    failureCount: number;
    successCount: number;
    lastFailureTime?: Date;
    lastSuccessTime?: Date;
    totalRequests: number;
    totalFailures: number;
    totalSuccesses: number;
}
export declare class CircuitBreaker {
    private name;
    private options;
    private state;
    private failureCount;
    private successCount;
    private lastFailureTime?;
    private lastSuccessTime?;
    private totalRequests;
    private totalFailures;
    private totalSuccesses;
    constructor(name: string, options?: CircuitBreakerOptions);
    call<T>(operation: () => Promise<T>): Promise<T>;
    private executeWithTimeout;
    private onSuccess;
    private onFailure;
    private shouldAttemptReset;
    getStats(): CircuitBreakerStats;
    reset(): void;
    forceOpen(): void;
}
//# sourceMappingURL=CircuitBreaker.d.ts.map