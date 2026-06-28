"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ErrorHandlingService = void 0;
const errors_js_1 = require("../types/errors.js");
const uuid_1 = require("uuid");
class ErrorHandlingService {
    loggingService;
    notificationService;
    errorThresholds = new Map();
    errorCounts = new Map();
    constructor(loggingService, notificationService) {
        this.loggingService = loggingService;
        this.notificationService = notificationService;
        this.initializeErrorThresholds();
    }
    initializeErrorThresholds() {
        this.errorThresholds.set(errors_js_1.ErrorCode.DATABASE_ERROR, 5);
        this.errorThresholds.set(errors_js_1.ErrorCode.EXTERNAL_SERVICE_ERROR, 10);
        this.errorThresholds.set(errors_js_1.ErrorCode.CACHE_ERROR, 15);
        this.errorThresholds.set(errors_js_1.ErrorCode.CIRCUIT_BREAKER_OPEN, 3);
        this.errorThresholds.set(errors_js_1.ErrorCode.RATE_LIMIT_EXCEEDED, 50);
    }
    async handleError(error, request, reply) {
        const requestId = request.id || (0, uuid_1.v4)();
        const startTime = Date.now();
        try {
            let appError;
            if (error instanceof errors_js_1.AppError) {
                appError = error;
            }
            else {
                appError = new errors_js_1.AppError(errors_js_1.ErrorCode.DATABASE_ERROR, error.message || 'Internal server error', 500, { originalError: error.name }, false);
            }
            await this.logError(appError, request, requestId);
            await this.checkErrorThresholds(appError.code);
            const errorResponse = {
                success: false,
                error: {
                    code: appError.code,
                    message: this.sanitizeErrorMessage(appError.message, appError.isOperational),
                    details: appError.isOperational ? appError.details : undefined,
                    timestamp: new Date().toISOString(),
                    requestId,
                    traceId: this.generateTraceId(requestId)
                },
                meta: {
                    requestId,
                    processingTime: Date.now() - startTime
                }
            };
            reply.status(appError.statusCode);
            reply.headers({
                'X-Content-Type-Options': 'nosniff',
                'X-Frame-Options': 'DENY',
                'X-XSS-Protection': '1; mode=block'
            });
            reply.send(errorResponse);
        }
        catch (handlingError) {
            console.error('Error in error handler:', handlingError);
            reply.status(500).send({
                success: false,
                error: {
                    code: errors_js_1.ErrorCode.DATABASE_ERROR,
                    message: 'Internal server error',
                    timestamp: new Date().toISOString(),
                    requestId
                }
            });
        }
    }
    async logError(error, request, requestId) {
        const severity = this.determineErrorSeverity(error);
        const logEntry = {
            id: (0, uuid_1.v4)(),
            timestamp: new Date().toISOString(),
            level: severity,
            code: error.code,
            message: error.message,
            stack: error.stack,
            details: error.details,
            requestId,
            userId: request.user?.id,
            endpoint: `${request.method} ${request.url}`,
            userAgent: request.headers['user-agent'],
            ipAddress: request.ip
        };
        await this.loggingService.logError(logEntry);
        if (process.env.NODE_ENV === 'development') {
            console.error(`[${severity.toUpperCase()}] ${error.code}: ${error.message}`, {
                requestId,
                endpoint: logEntry.endpoint,
                stack: error.stack
            });
        }
    }
    determineErrorSeverity(error) {
        switch (error.code) {
            case errors_js_1.ErrorCode.DATABASE_ERROR:
            case errors_js_1.ErrorCode.CIRCUIT_BREAKER_OPEN:
                return errors_js_1.ErrorSeverity.CRITICAL;
            case errors_js_1.ErrorCode.EXTERNAL_SERVICE_ERROR:
            case errors_js_1.ErrorCode.CACHE_ERROR:
                return errors_js_1.ErrorSeverity.HIGH;
            case errors_js_1.ErrorCode.WEBHOOK_PROCESSING_FAILED:
            case errors_js_1.ErrorCode.SERVICE_UNAVAILABLE:
                return errors_js_1.ErrorSeverity.MEDIUM;
            default:
                return errors_js_1.ErrorSeverity.LOW;
        }
    }
    sanitizeErrorMessage(message, isOperational) {
        if (!isOperational) {
            return 'An internal error occurred. Please try again later.';
        }
        return message
            .replace(/password/gi, '[REDACTED]')
            .replace(/token/gi, '[REDACTED]')
            .replace(/key/gi, '[REDACTED]')
            .replace(/secret/gi, '[REDACTED]');
    }
    generateTraceId(requestId) {
        return `trace-${requestId}-${Date.now()}`;
    }
    async checkErrorThresholds(errorCode) {
        const threshold = this.errorThresholds.get(errorCode);
        if (!threshold)
            return;
        const currentCount = (this.errorCounts.get(errorCode) || 0) + 1;
        this.errorCounts.set(errorCode, currentCount);
        if (currentCount >= threshold) {
            await this.sendErrorAlert(errorCode, currentCount, threshold);
            this.errorCounts.set(errorCode, 0);
        }
        setTimeout(() => {
            this.errorCounts.clear();
        }, 60 * 60 * 1000);
    }
    async sendErrorAlert(errorCode, count, threshold) {
        const alertMessage = `🚨 Error Alert: ${errorCode} has occurred ${count} times (threshold: ${threshold})`;
        try {
            await this.notificationService.sendAlert({
                type: 'error_threshold',
                severity: errors_js_1.ErrorSeverity.HIGH,
                message: alertMessage,
                details: {
                    errorCode,
                    count,
                    threshold,
                    timestamp: new Date().toISOString()
                }
            });
        }
        catch (notificationError) {
            console.error('Failed to send error alert:', notificationError);
        }
    }
    createGracefulDegradationResponse(fallbackData, degradationReason, requestId) {
        return {
            success: true,
            data: fallbackData,
            meta: {
                requestId,
                processingTime: 0,
                degraded: true,
                degradationReason
            }
        };
    }
    handleValidationError(validationError, requestId) {
        const details = validationError.details?.map((detail) => ({
            field: detail.path?.join('.'),
            message: detail.message,
            value: detail.context?.value
        }));
        return {
            success: false,
            error: {
                code: errors_js_1.ErrorCode.INVALID_REQUEST,
                message: 'Request validation failed',
                details: { validationErrors: details },
                timestamp: new Date().toISOString(),
                requestId
            }
        };
    }
    getErrorStatistics() {
        return {
            errorCounts: Object.fromEntries(this.errorCounts),
            errorThresholds: Object.fromEntries(this.errorThresholds),
            timestamp: new Date().toISOString()
        };
    }
}
exports.ErrorHandlingService = ErrorHandlingService;
//# sourceMappingURL=ErrorHandlingService.js.map