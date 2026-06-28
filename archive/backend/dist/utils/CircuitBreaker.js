"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CircuitBreaker = exports.CircuitState = void 0;
const logger_1 = require("@/utils/logger");
var CircuitState;
(function (CircuitState) {
    CircuitState["CLOSED"] = "CLOSED";
    CircuitState["OPEN"] = "OPEN";
    CircuitState["HALF_OPEN"] = "HALF_OPEN";
})(CircuitState || (exports.CircuitState = CircuitState = {}));
class CircuitBreaker {
    name;
    options;
    state = CircuitState.CLOSED;
    failureCount = 0;
    successCount = 0;
    lastFailureTime;
    lastSuccessTime;
    totalRequests = 0;
    totalFailures = 0;
    totalSuccesses = 0;
    constructor(name, options = {
        failureThreshold: 5,
        recoveryTimeout: 60000,
        successThreshold: 3,
        timeout: 10000,
    }) {
        this.name = name;
        this.options = options;
    }
    async call(operation) {
        this.totalRequests++;
        if (this.state === CircuitState.OPEN) {
            if (this.shouldAttemptReset()) {
                this.state = CircuitState.HALF_OPEN;
                this.successCount = 0;
                logger_1.logger.info(`Circuit breaker ${this.name} moved to HALF_OPEN state`);
            }
            else {
                const error = new Error(`Circuit breaker ${this.name} is OPEN`);
                error.circuitBreakerOpen = true;
                throw error;
            }
        }
        try {
            const result = await this.executeWithTimeout(operation);
            this.onSuccess();
            return result;
        }
        catch (error) {
            this.onFailure();
            throw error;
        }
    }
    async executeWithTimeout(operation) {
        return new Promise((resolve, reject) => {
            const timeoutId = setTimeout(() => {
                reject(new Error(`Operation timeout after ${this.options.timeout}ms`));
            }, this.options.timeout);
            operation()
                .then((result) => {
                clearTimeout(timeoutId);
                resolve(result);
            })
                .catch((error) => {
                clearTimeout(timeoutId);
                reject(error);
            });
        });
    }
    onSuccess() {
        this.totalSuccesses++;
        this.lastSuccessTime = new Date();
        this.failureCount = 0;
        if (this.state === CircuitState.HALF_OPEN) {
            this.successCount++;
            if (this.successCount >= this.options.successThreshold) {
                this.state = CircuitState.CLOSED;
                this.successCount = 0;
                logger_1.logger.info(`Circuit breaker ${this.name} moved to CLOSED state`);
            }
        }
    }
    onFailure() {
        this.totalFailures++;
        this.failureCount++;
        this.lastFailureTime = new Date();
        if (this.state === CircuitState.HALF_OPEN) {
            this.state = CircuitState.OPEN;
            logger_1.logger.warn(`Circuit breaker ${this.name} moved to OPEN state from HALF_OPEN`);
        }
        else if (this.failureCount >= this.options.failureThreshold) {
            this.state = CircuitState.OPEN;
            logger_1.logger.warn(`Circuit breaker ${this.name} moved to OPEN state`, {
                failureCount: this.failureCount,
                threshold: this.options.failureThreshold,
            });
        }
    }
    shouldAttemptReset() {
        return !!(this.lastFailureTime &&
            Date.now() - this.lastFailureTime.getTime() > this.options.recoveryTimeout);
    }
    getStats() {
        return {
            state: this.state,
            failureCount: this.failureCount,
            successCount: this.successCount,
            lastFailureTime: this.lastFailureTime,
            lastSuccessTime: this.lastSuccessTime,
            totalRequests: this.totalRequests,
            totalFailures: this.totalFailures,
            totalSuccesses: this.totalSuccesses,
        };
    }
    reset() {
        this.state = CircuitState.CLOSED;
        this.failureCount = 0;
        this.successCount = 0;
        logger_1.logger.info(`Circuit breaker ${this.name} manually reset to CLOSED state`);
    }
    forceOpen() {
        this.state = CircuitState.OPEN;
        logger_1.logger.warn(`Circuit breaker ${this.name} manually forced to OPEN state`);
    }
}
exports.CircuitBreaker = CircuitBreaker;
//# sourceMappingURL=CircuitBreaker.js.map