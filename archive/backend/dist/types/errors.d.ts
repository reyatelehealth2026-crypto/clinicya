export declare enum ErrorCode {
    INVALID_TOKEN = "INVALID_TOKEN",
    TOKEN_EXPIRED = "TOKEN_EXPIRED",
    INSUFFICIENT_PERMISSIONS = "INSUFFICIENT_PERMISSIONS",
    INVALID_REQUEST = "INVALID_REQUEST",
    MISSING_REQUIRED_FIELD = "MISSING_REQUIRED_FIELD",
    INVALID_DATE_RANGE = "INVALID_DATE_RANGE",
    INVALID_FILE_FORMAT = "INVALID_FILE_FORMAT",
    ORDER_NOT_FOUND = "ORDER_NOT_FOUND",
    PAYMENT_ALREADY_MATCHED = "PAYMENT_ALREADY_MATCHED",
    INSUFFICIENT_BALANCE = "INSUFFICIENT_BALANCE",
    WEBHOOK_PROCESSING_FAILED = "WEBHOOK_PROCESSING_FAILED",
    DATABASE_ERROR = "DATABASE_ERROR",
    EXTERNAL_SERVICE_ERROR = "EXTERNAL_SERVICE_ERROR",
    CACHE_ERROR = "CACHE_ERROR",
    CIRCUIT_BREAKER_OPEN = "CIRCUIT_BREAKER_OPEN",
    RATE_LIMIT_EXCEEDED = "RATE_LIMIT_EXCEEDED",
    SERVICE_UNAVAILABLE = "SERVICE_UNAVAILABLE",
    MAINTENANCE_MODE = "MAINTENANCE_MODE"
}
export interface APIError {
    code: ErrorCode;
    message: string;
    details?: Record<string, any>;
    timestamp: string;
    requestId: string;
    traceId?: string;
}
export interface APIResponse<T = any> {
    success: boolean;
    data?: T;
    error?: APIError;
    meta?: ResponseMeta;
}
export interface ResponseMeta {
    page?: number;
    limit?: number;
    total?: number;
    totalPages?: number;
    requestId: string;
    processingTime: number;
}
export declare class AppError extends Error {
    readonly code: ErrorCode;
    readonly statusCode: number;
    readonly details?: Record<string, any>;
    readonly isOperational: boolean;
    constructor(code: ErrorCode, message: string, statusCode?: number, details?: Record<string, any>, isOperational?: boolean);
}
export declare class ValidationError extends AppError {
    constructor(message: string, details?: Record<string, any>);
}
export declare class AuthenticationError extends AppError {
    constructor(code?: ErrorCode, message?: string);
}
export declare class AuthorizationError extends AppError {
    constructor(message?: string);
}
export declare class NotFoundError extends AppError {
    constructor(resource: string, id?: string);
}
export declare class BusinessLogicError extends AppError {
    constructor(code: ErrorCode, message: string, details?: Record<string, any>);
}
export declare class ExternalServiceError extends AppError {
    constructor(service: string, message: string, details?: Record<string, any>);
}
export declare class DatabaseError extends AppError {
    constructor(message: string, details?: Record<string, any>);
}
export declare class CacheError extends AppError {
    constructor(message: string, details?: Record<string, any>);
}
export declare class RateLimitError extends AppError {
    constructor(message?: string);
}
export declare class ServiceUnavailableError extends AppError {
    constructor(message?: string);
}
export declare enum ErrorSeverity {
    LOW = "low",
    MEDIUM = "medium",
    HIGH = "high",
    CRITICAL = "critical"
}
export interface ErrorLogEntry {
    id: string;
    timestamp: string;
    level: ErrorSeverity;
    code: ErrorCode;
    message: string;
    stack?: string;
    details?: Record<string, any>;
    requestId?: string;
    userId?: string;
    endpoint?: string;
    userAgent?: string;
    ipAddress?: string;
}
//# sourceMappingURL=errors.d.ts.map