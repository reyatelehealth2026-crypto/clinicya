"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.RetryMechanism = void 0;
const errors_js_1 = require("../types/errors.js");
class RetryMechanism {
    loggingService;
    defaultConfig;
    constructor(loggingService) {
        this.loggingService = loggingService;
        this.defaultConfig = {
            maxAttempts: 3,
            baseDelay: 1000,
            maxDelay: 30000,
            backoffMultiplier: 2,
            jitterType: 'full',
            retryableErrors: [
                errors_js_1.ErrorCode.EXTERNAL_SERVICE_ERROR,
                errors_js_1.ErrorCode.DATABASE_ERROR,
                errors_js_1.ErrorCode.CACHE_ERROR,
                errors_js_1.ErrorCode.SERVICE_UNAVAILABLE
            ],
            timeoutMs: 60000
        };
    }
    async execute(operation, operationName, config, metadata) {
        const finalConfig = { ...this.defaultConfig, ...config };
        const startTime = Date.now();
        const retryHistory = [];
        let lastError;
        for (let attempt = 1; attempt <= finalConfig.maxAttempts; attempt++) {
            const attemptStartTime = Date.now();
            if (finalConfig.timeoutMs && (attemptStartTime - startTime) > finalConfig.timeoutMs) {
                const timeoutError = new Error(`Operation timeout after ${finalConfig.timeoutMs}ms`);
                retryHistory.push({
                    attempt,
                    timestamp: attemptStartTime,
                    delay: 0,
                    error: timeoutError.message,
                    success: false
                });
                await this.logRetryFailure(operationName, attempt, timeoutError, metadata);
                return {
                    success: false,
                    error: timeoutError,
                    attempts: attempt,
                    totalTime: Date.now() - startTime,
                    retryHistory
                };
            }
            const context = {
                attempt,
                totalAttempts: finalConfig.maxAttempts,
                lastError,
                startTime,
                operationName,
                metadata
            };
            try {
                const result = await operation(context);
                retryHistory.push({
                    attempt,
                    timestamp: attemptStartTime,
                    delay: 0,
                    success: true
                });
                if (attempt > 1) {
                    await this.logRetrySuccess(operationName, attempt, metadata);
                }
                return {
                    success: true,
                    data: result,
                    attempts: attempt,
                    totalTime: Date.now() - startTime,
                    retryHistory
                };
            }
            catch (error) {
                lastError = error;
                retryHistory.push({
                    attempt,
                    timestamp: attemptStartTime,
                    delay: 0,
                    error: lastError.message,
                    success: false
                });
                if (!this.isRetryableError(lastError, finalConfig.retryableErrors)) {
                    await this.logNonRetryableError(operationName, attempt, lastError, metadata);
                    return {
                        success: false,
                        error: lastError,
                        attempts: attempt,
                        totalTime: Date.now() - startTime,
                        retryHistory
                    };
                }
                if (attempt === finalConfig.maxAttempts) {
                    await this.logRetryExhausted(operationName, attempt, lastError, metadata);
                    return {
                        success: false,
                        error: lastError,
                        attempts: attempt,
                        totalTime: Date.now() - startTime,
                        retryHistory
                    };
                }
                const delay = this.calculateDelay(attempt, finalConfig);
                retryHistory[retryHistory.length - 1].delay = delay;
                await this.logRetryAttempt(operationName, attempt, lastError, delay, metadata);
                await this.sleep(delay);
            }
        }
        return {
            success: false,
            error: lastError || new Error('Unknown error'),
            attempts: finalConfig.maxAttempts,
            totalTime: Date.now() - startTime,
            retryHistory
        };
    }
    calculateDelay(attempt, config) {
        const exponentialDelay = config.baseDelay * Math.pow(config.backoffMultiplier, attempt - 1);
        const cappedDelay = Math.min(exponentialDelay, config.maxDelay);
        return this.applyJitter(cappedDelay, config.jitterType);
    }
    applyJitter(delay, jitterType) {
        switch (jitterType) {
            case 'none':
                return delay;
            case 'full':
                return Math.random() * delay;
            case 'equal':
                return (delay / 2) + (Math.random() * delay / 2);
            case 'decorrelated':
                return Math.random() * delay * 3;
            default:
                return delay;
        }
    }
    isRetryableError(error, retryableErrors) {
        const errorMessage = error.message.toLowerCase();
        const retryablePatterns = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'unavailable',
            'overloaded',
            'rate limit',
            'circuit breaker'
        ];
        for (const errorCode of retryableErrors) {
            if (errorMessage.includes(errorCode.toLowerCase())) {
                return true;
            }
        }
        for (const pattern of retryablePatterns) {
            if (errorMessage.includes(pattern)) {
                return true;
            }
        }
        if ('status' in error) {
            const status = error.status;
            const retryableStatusCodes = [408, 429, 500, 502, 503, 504];
            return retryableStatusCodes.includes(status);
        }
        return false;
    }
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    async logRetryAttempt(operationName, attempt, error, delay, metadata) {
        await this.loggingService.logEvent('warn', `Retry attempt ${attempt} for ${operationName}`, {
            operationName,
            attempt,
            error: error.message,
            delayMs: delay,
            metadata
        });
    }
    async logRetrySuccess(operationName, attempt, metadata) {
        await this.loggingService.logEvent('info', `Operation ${operationName} succeeded after ${attempt} attempts`, {
            operationName,
            totalAttempts: attempt,
            metadata
        });
    }
    async logRetryExhausted(operationName, attempts, error, metadata) {
        await this.loggingService.logEvent('error', `Operation ${operationName} failed after ${attempts} attempts`, {
            operationName,
            totalAttempts: attempts,
            finalError: error.message,
            metadata
        });
    }
    async logNonRetryableError(operationName, attempt, error, metadata) {
        await this.loggingService.logEvent('error', `Non-retryable error in ${operationName}`, {
            operationName,
            attempt,
            error: error.message,
            metadata
        });
    }
    async logRetryFailure(operationName, attempt, error, metadata) {
        await this.loggingService.logEvent('error', `Operation ${operationName} failed due to timeout`, {
            operationName,
            attempt,
            error: error.message,
            metadata
        });
    }
    static createConfig(type) {
        const baseConfig = {
            jitterType: 'full',
            retryableErrors: [
                errors_js_1.ErrorCode.EXTERNAL_SERVICE_ERROR,
                errors_js_1.ErrorCode.DATABASE_ERROR,
                errors_js_1.ErrorCode.CACHE_ERROR,
                errors_js_1.ErrorCode.SERVICE_UNAVAILABLE
            ]
        };
        switch (type) {
            case 'database':
                return {
                    ...baseConfig,
                    maxAttempts: 3,
                    baseDelay: 500,
                    maxDelay: 5000,
                    backoffMultiplier: 2,
                    timeoutMs: 30000
                };
            case 'external_api':
                return {
                    ...baseConfig,
                    maxAttempts: 5,
                    baseDelay: 1000,
                    maxDelay: 30000,
                    backoffMultiplier: 2,
                    timeoutMs: 120000
                };
            case 'cache':
                return {
                    ...baseConfig,
                    maxAttempts: 2,
                    baseDelay: 100,
                    maxDelay: 1000,
                    backoffMultiplier: 2,
                    timeoutMs: 5000
                };
            case 'file_upload':
                return {
                    ...baseConfig,
                    maxAttempts: 3,
                    baseDelay: 2000,
                    maxDelay: 10000,
                    backoffMultiplier: 1.5,
                    timeoutMs: 300000
                };
            default:
                return baseConfig;
        }
    }
}
exports.RetryMechanism = RetryMechanism;
//# sourceMappingURL=RetryMechanism.js.map