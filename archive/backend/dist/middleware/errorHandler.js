"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ErrorHandlerMiddleware = void 0;
const errors_js_1 = require("../types/errors.js");
const uuid_1 = require("uuid");
class ErrorHandlerMiddleware {
    errorHandlingService;
    gracefulDegradationService;
    config;
    constructor(errorHandlingService, gracefulDegradationService, config) {
        this.errorHandlingService = errorHandlingService;
        this.gracefulDegradationService = gracefulDegradationService;
        this.config = {
            enableStackTrace: process.env.NODE_ENV === 'development',
            enableDetailedErrors: process.env.NODE_ENV === 'development',
            logAllErrors: true,
            enableGracefulDegradation: true,
            ...config
        };
    }
    async handleError(error, request, reply) {
        if (!request.id) {
            request.id = (0, uuid_1.v4)();
        }
        if (this.isValidationError(error)) {
            await this.handleValidationError(error, request, reply);
        }
        else if (this.isAuthenticationError(error)) {
            await this.handleAuthenticationError(error, request, reply);
        }
        else if (this.isRateLimitError(error)) {
            await this.handleRateLimitError(error, request, reply);
        }
        else if (this.isAppError(error)) {
            await this.handleAppError(error, request, reply);
        }
        else {
            await this.handleUnknownError(error, request, reply);
        }
    }
    async handleValidationError(error, request, reply) {
        const validationError = new errors_js_1.ValidationError('Request validation failed', { validationErrors: error.validation });
        await this.errorHandlingService.handleError(validationError, request, reply);
    }
    async handleAuthenticationError(error, request, reply) {
        const authError = new errors_js_1.AppError(errors_js_1.ErrorCode.INVALID_TOKEN, 'Authentication failed', 401);
        await this.errorHandlingService.handleError(authError, request, reply);
    }
    async handleRateLimitError(error, request, reply) {
        const rateLimitError = new errors_js_1.AppError(errors_js_1.ErrorCode.RATE_LIMIT_EXCEEDED, 'Rate limit exceeded', 429, { retryAfter: error.statusCode === 429 ? '60' : undefined });
        await this.errorHandlingService.handleError(rateLimitError, request, reply);
    }
    async handleAppError(error, request, reply) {
        if (this.config.enableGracefulDegradation && this.shouldApplyDegradation(error)) {
            try {
                const degradationResponse = await this.gracefulDegradationService.applyDegradation(error, {
                    endpoint: `${request.method} ${request.url}`,
                    service: this.extractServiceFromError(error),
                    params: { ...request.query, ...request.body },
                    requestId: request.id
                });
                reply.status(200).send(degradationResponse);
                return;
            }
            catch (degradationError) {
                console.error('Graceful degradation failed:', degradationError);
            }
        }
        await this.errorHandlingService.handleError(error, request, reply);
    }
    async handleUnknownError(error, request, reply) {
        const appError = new errors_js_1.AppError(errors_js_1.ErrorCode.DATABASE_ERROR, this.config.enableDetailedErrors ? error.message : 'Internal server error', error.statusCode || 500, this.config.enableDetailedErrors ? {
            originalError: error.name,
            stack: this.config.enableStackTrace ? error.stack : undefined
        } : undefined, false);
        await this.errorHandlingService.handleError(appError, request, reply);
    }
    isValidationError(error) {
        return error.validation !== undefined || error.statusCode === 400;
    }
    isAuthenticationError(error) {
        return error.statusCode === 401 ||
            error.message.toLowerCase().includes('unauthorized') ||
            error.message.toLowerCase().includes('authentication');
    }
    isRateLimitError(error) {
        return error.statusCode === 429 ||
            error.message.toLowerCase().includes('rate limit');
    }
    isAppError(error) {
        return error instanceof errors_js_1.AppError;
    }
    shouldApplyDegradation(error) {
        const degradableErrors = [
            errors_js_1.ErrorCode.EXTERNAL_SERVICE_ERROR,
            errors_js_1.ErrorCode.DATABASE_ERROR,
            errors_js_1.ErrorCode.CACHE_ERROR,
            errors_js_1.ErrorCode.CIRCUIT_BREAKER_OPEN,
            errors_js_1.ErrorCode.SERVICE_UNAVAILABLE
        ];
        return degradableErrors.includes(error.code);
    }
    extractServiceFromError(error) {
        const message = error.message.toLowerCase();
        if (message.includes('odoo') || message.includes('erp')) {
            return 'odoo';
        }
        else if (message.includes('line') || message.includes('messaging')) {
            return 'line';
        }
        else if (message.includes('payment') || message.includes('banking')) {
            return 'payment';
        }
        else if (message.includes('cache') || message.includes('redis')) {
            return 'cache';
        }
        else if (message.includes('database') || message.includes('mysql')) {
            return 'database';
        }
        return 'unknown';
    }
    async preHandler(request, reply) {
        if (!request.id) {
            request.id = (0, uuid_1.v4)();
        }
        request.startTime = Date.now();
        reply.headers({
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Options': 'DENY',
            'X-XSS-Protection': '1; mode=block',
            'Referrer-Policy': 'strict-origin-when-cross-origin'
        });
    }
    async postHandler(request, reply) {
        const processingTime = Date.now() - (request.startTime || Date.now());
        reply.header('X-Processing-Time', processingTime.toString());
        reply.header('X-Request-ID', request.id);
    }
    handleUncaughtException(error) {
        console.error('Uncaught Exception:', error);
        this.errorHandlingService.handleError(new errors_js_1.AppError(errors_js_1.ErrorCode.DATABASE_ERROR, 'Uncaught exception', 500, { originalError: error.message, stack: error.stack }, false), {}, {}).catch(console.error);
        process.exit(1);
    }
    handleUnhandledRejection(reason, promise) {
        console.error('Unhandled Rejection at:', promise, 'reason:', reason);
        this.errorHandlingService.handleError(new errors_js_1.AppError(errors_js_1.ErrorCode.DATABASE_ERROR, 'Unhandled promise rejection', 500, { reason: reason?.toString(), stack: reason?.stack }, false), {}, {}).catch(console.error);
    }
    setupGlobalHandlers() {
        process.on('uncaughtException', this.handleUncaughtException.bind(this));
        process.on('unhandledRejection', this.handleUnhandledRejection.bind(this));
    }
}
exports.ErrorHandlerMiddleware = ErrorHandlerMiddleware;
//# sourceMappingURL=errorHandler.js.map