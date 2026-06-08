"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.RetryHandler = void 0;
const logger_1 = require("@/utils/logger");
class RetryHandler {
    name;
    options;
    constructor(name, options = {
        maxRetries: 3,
        baseDelay: 1000,
        maxDelay: 30000,
        backoffMultiplier: 2,
        jitter: true,
    }) {
        this.name = name;
        this.options = options;
    }
    async executeWithRetry(operation, shouldRetry) {
        let lastError;
        let attempt = 0;
        while (attempt <= this.options.maxRetries) {
            try {
                if (attempt > 0) {
                    logger_1.logger.info(`Retry attempt ${attempt} for ${this.name}`);
                }
                return await operation();
            }
            catch (error) {
                lastError = error;
                attempt++;
                if (shouldRetry && !shouldRetry(lastError)) {
                    logger_1.logger.info(`Not retrying ${this.name} due to error type`, {
                        error: lastError.message,
                        attempt,
                    });
                    throw lastError;
                }
                if (attempt > this.options.maxRetries) {
                    break;
                }
                const delay = this.calculateDelay(attempt);
                logger_1.logger.warn(`${this.name} failed, retrying in ${delay}ms`, {
                    error: lastError.message,
                    attempt,
                    maxRetries: this.options.maxRetries,
                });
                await this.sleep(delay);
            }
        }
        logger_1.logger.error(`${this.name} failed after ${this.options.maxRetries + 1} attempts`, {
            error: lastError.message,
        });
        throw new Error(`Operation failed after ${this.options.maxRetries + 1} attempts: ${lastError.message}`);
    }
    calculateDelay(attempt) {
        let delay = this.options.baseDelay * Math.pow(this.options.backoffMultiplier, attempt - 1);
        delay = Math.min(delay, this.options.maxDelay);
        if (this.options.jitter) {
            delay = delay * (0.5 + Math.random() * 0.5);
        }
        return Math.floor(delay);
    }
    sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }
    static shouldRetryError(error) {
        if (error.status >= 400 && error.status < 500) {
            return false;
        }
        if (error.message.includes('authentication') || error.message.includes('unauthorized')) {
            return false;
        }
        if (error.message.includes('validation') || error.message.includes('invalid')) {
            return false;
        }
        return true;
    }
}
exports.RetryHandler = RetryHandler;
//# sourceMappingURL=RetryHandler.js.map