"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ErrorSeverity = exports.ServiceUnavailableError = exports.RateLimitError = exports.CacheError = exports.DatabaseError = exports.ExternalServiceError = exports.BusinessLogicError = exports.NotFoundError = exports.AuthorizationError = exports.AuthenticationError = exports.ValidationError = exports.AppError = exports.ErrorCode = void 0;
var ErrorCode;
(function (ErrorCode) {
    ErrorCode["INVALID_TOKEN"] = "INVALID_TOKEN";
    ErrorCode["TOKEN_EXPIRED"] = "TOKEN_EXPIRED";
    ErrorCode["INSUFFICIENT_PERMISSIONS"] = "INSUFFICIENT_PERMISSIONS";
    ErrorCode["INVALID_REQUEST"] = "INVALID_REQUEST";
    ErrorCode["MISSING_REQUIRED_FIELD"] = "MISSING_REQUIRED_FIELD";
    ErrorCode["INVALID_DATE_RANGE"] = "INVALID_DATE_RANGE";
    ErrorCode["INVALID_FILE_FORMAT"] = "INVALID_FILE_FORMAT";
    ErrorCode["ORDER_NOT_FOUND"] = "ORDER_NOT_FOUND";
    ErrorCode["PAYMENT_ALREADY_MATCHED"] = "PAYMENT_ALREADY_MATCHED";
    ErrorCode["INSUFFICIENT_BALANCE"] = "INSUFFICIENT_BALANCE";
    ErrorCode["WEBHOOK_PROCESSING_FAILED"] = "WEBHOOK_PROCESSING_FAILED";
    ErrorCode["DATABASE_ERROR"] = "DATABASE_ERROR";
    ErrorCode["EXTERNAL_SERVICE_ERROR"] = "EXTERNAL_SERVICE_ERROR";
    ErrorCode["CACHE_ERROR"] = "CACHE_ERROR";
    ErrorCode["CIRCUIT_BREAKER_OPEN"] = "CIRCUIT_BREAKER_OPEN";
    ErrorCode["RATE_LIMIT_EXCEEDED"] = "RATE_LIMIT_EXCEEDED";
    ErrorCode["SERVICE_UNAVAILABLE"] = "SERVICE_UNAVAILABLE";
    ErrorCode["MAINTENANCE_MODE"] = "MAINTENANCE_MODE";
})(ErrorCode || (exports.ErrorCode = ErrorCode = {}));
class AppError extends Error {
    code;
    statusCode;
    details;
    isOperational;
    constructor(code, message, statusCode = 500, details, isOperational = true) {
        super(message);
        this.name = 'AppError';
        this.code = code;
        this.statusCode = statusCode;
        this.details = details;
        this.isOperational = isOperational;
        Error.captureStackTrace(this, this.constructor);
    }
}
exports.AppError = AppError;
class ValidationError extends AppError {
    constructor(message, details) {
        super(ErrorCode.INVALID_REQUEST, message, 400, details);
    }
}
exports.ValidationError = ValidationError;
class AuthenticationError extends AppError {
    constructor(code = ErrorCode.INVALID_TOKEN, message = 'Authentication failed') {
        super(code, message, 401);
    }
}
exports.AuthenticationError = AuthenticationError;
class AuthorizationError extends AppError {
    constructor(message = 'Insufficient permissions') {
        super(ErrorCode.INSUFFICIENT_PERMISSIONS, message, 403);
    }
}
exports.AuthorizationError = AuthorizationError;
class NotFoundError extends AppError {
    constructor(resource, id) {
        const message = id ? `${resource} with ID ${id} not found` : `${resource} not found`;
        super(ErrorCode.ORDER_NOT_FOUND, message, 404);
    }
}
exports.NotFoundError = NotFoundError;
class BusinessLogicError extends AppError {
    constructor(code, message, details) {
        super(code, message, 422, details);
    }
}
exports.BusinessLogicError = BusinessLogicError;
class ExternalServiceError extends AppError {
    constructor(service, message, details) {
        super(ErrorCode.EXTERNAL_SERVICE_ERROR, `External service error: ${service} - ${message}`, 502, details);
    }
}
exports.ExternalServiceError = ExternalServiceError;
class DatabaseError extends AppError {
    constructor(message, details) {
        super(ErrorCode.DATABASE_ERROR, message, 500, details);
    }
}
exports.DatabaseError = DatabaseError;
class CacheError extends AppError {
    constructor(message, details) {
        super(ErrorCode.CACHE_ERROR, message, 500, details);
    }
}
exports.CacheError = CacheError;
class RateLimitError extends AppError {
    constructor(message = 'Rate limit exceeded') {
        super(ErrorCode.RATE_LIMIT_EXCEEDED, message, 429);
    }
}
exports.RateLimitError = RateLimitError;
class ServiceUnavailableError extends AppError {
    constructor(message = 'Service temporarily unavailable') {
        super(ErrorCode.SERVICE_UNAVAILABLE, message, 503);
    }
}
exports.ServiceUnavailableError = ServiceUnavailableError;
var ErrorSeverity;
(function (ErrorSeverity) {
    ErrorSeverity["LOW"] = "low";
    ErrorSeverity["MEDIUM"] = "medium";
    ErrorSeverity["HIGH"] = "high";
    ErrorSeverity["CRITICAL"] = "critical";
})(ErrorSeverity || (exports.ErrorSeverity = ErrorSeverity = {}));
//# sourceMappingURL=errors.js.map