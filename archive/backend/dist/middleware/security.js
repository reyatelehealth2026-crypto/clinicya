"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.limitRequestSize = exports.detectSqlInjection = exports.validateFileUpload = exports.sanitizeRequest = exports.InputSanitizer = exports.contentSecurityPolicy = exports.validationSchemas = exports.commonSchemas = void 0;
const zod_1 = require("zod");
const logger_1 = require("@/utils/logger");
exports.commonSchemas = {
    uuid: zod_1.z.string().uuid('Invalid UUID format'),
    email: zod_1.z.string().email('Invalid email format'),
    password: zod_1.z.string().min(8, 'Password must be at least 8 characters')
        .regex(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/, 'Password must contain uppercase, lowercase, number and special character'),
    username: zod_1.z.string().min(3, 'Username must be at least 3 characters')
        .max(50, 'Username must be less than 50 characters')
        .regex(/^[a-zA-Z0-9_-]+$/, 'Username can only contain letters, numbers, underscore and dash'),
    phoneNumber: zod_1.z.string().regex(/^\+?[1-9]\d{1,14}$/, 'Invalid phone number format'),
    url: zod_1.z.string().url('Invalid URL format'),
    ipAddress: zod_1.z.string().ip('Invalid IP address format'),
    dateString: zod_1.z.string().datetime('Invalid date format'),
    positiveNumber: zod_1.z.number().positive('Must be a positive number'),
    nonEmptyString: zod_1.z.string().min(1, 'Field cannot be empty').trim(),
    safeString: zod_1.z.string().max(1000, 'String too long').trim()
        .refine(val => !/<script|javascript:|data:|vbscript:/i.test(val), 'Potentially dangerous content detected'),
};
exports.validationSchemas = {
    login: zod_1.z.object({
        username: exports.commonSchemas.username,
        password: zod_1.z.string().min(1, 'Password required'),
        lineAccountId: exports.commonSchemas.uuid,
        rememberMe: zod_1.z.boolean().optional(),
    }),
    refreshToken: zod_1.z.object({
        refreshToken: zod_1.z.string().min(1, 'Refresh token required'),
    }),
    dashboardMetrics: zod_1.z.object({
        dateFrom: exports.commonSchemas.dateString.optional(),
        dateTo: exports.commonSchemas.dateString.optional(),
        metricTypes: zod_1.z.array(zod_1.z.enum(['orders', 'payments', 'webhooks', 'customers'])).optional(),
        lineAccountId: exports.commonSchemas.uuid.optional(),
    }),
    orderUpdate: zod_1.z.object({
        status: zod_1.z.enum(['pending', 'processing', 'completed', 'cancelled', 'refunded']),
        notes: exports.commonSchemas.safeString.max(500).optional(),
        notifyCustomer: zod_1.z.boolean().default(false),
        internalNotes: exports.commonSchemas.safeString.max(1000).optional(),
    }),
    orderSearch: zod_1.z.object({
        query: exports.commonSchemas.safeString.max(100).optional(),
        status: zod_1.z.array(zod_1.z.string()).optional(),
        customerId: exports.commonSchemas.uuid.optional(),
        dateFrom: exports.commonSchemas.dateString.optional(),
        dateTo: exports.commonSchemas.dateString.optional(),
        page: zod_1.z.number().int().min(1).default(1),
        limit: zod_1.z.number().int().min(1).max(100).default(20),
        sortBy: zod_1.z.enum(['created_at', 'updated_at', 'total_amount', 'status']).default('created_at'),
        sortOrder: zod_1.z.enum(['asc', 'desc']).default('desc'),
    }),
    paymentSlipUpload: zod_1.z.object({
        orderId: exports.commonSchemas.uuid.optional(),
        amount: exports.commonSchemas.positiveNumber,
        currency: zod_1.z.string().length(3, 'Currency must be 3 characters').default('THB'),
        notes: exports.commonSchemas.safeString.max(500).optional(),
        bankAccount: exports.commonSchemas.safeString.max(100).optional(),
    }),
    paymentMatch: zod_1.z.object({
        slipId: exports.commonSchemas.uuid,
        orderId: exports.commonSchemas.uuid,
        matchType: zod_1.z.enum(['automatic', 'manual']).default('manual'),
        confidence: zod_1.z.number().min(0).max(1).optional(),
        notes: exports.commonSchemas.safeString.max(500).optional(),
    }),
    webhookRetry: zod_1.z.object({
        webhookId: exports.commonSchemas.uuid,
        reason: exports.commonSchemas.safeString.max(200).optional(),
    }),
    customerSearch: zod_1.z.object({
        query: exports.commonSchemas.safeString.max(100).optional(),
        customerRef: exports.commonSchemas.safeString.max(50).optional(),
        partnerId: zod_1.z.string().max(50).optional(),
        email: exports.commonSchemas.email.optional(),
        phone: exports.commonSchemas.phoneNumber.optional(),
        page: zod_1.z.number().int().min(1).default(1),
        limit: zod_1.z.number().int().min(1).max(100).default(20),
    }),
    auditLogQuery: zod_1.z.object({
        userId: exports.commonSchemas.uuid.optional(),
        action: exports.commonSchemas.safeString.max(100).optional(),
        resourceType: exports.commonSchemas.safeString.max(50).optional(),
        resourceId: exports.commonSchemas.uuid.optional(),
        dateFrom: exports.commonSchemas.dateString.optional(),
        dateTo: exports.commonSchemas.dateString.optional(),
        success: zod_1.z.boolean().optional(),
        page: zod_1.z.number().int().min(1).default(1),
        limit: zod_1.z.number().int().min(1).max(100).default(50),
    }),
};
const contentSecurityPolicy = async (request, reply) => {
    const cspDirectives = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
        "img-src 'self' data: https: blob:",
        "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
        "connect-src 'self' wss: https://api.line.me https://*.odoo.com",
        "media-src 'self' blob:",
        "object-src 'none'",
        "frame-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "upgrade-insecure-requests",
    ].join('; ');
    reply.header('Content-Security-Policy', cspDirectives);
    reply.header('X-Content-Type-Options', 'nosniff');
    reply.header('X-Frame-Options', 'DENY');
    reply.header('X-XSS-Protection', '1; mode=block');
    reply.header('Referrer-Policy', 'strict-origin-when-cross-origin');
    reply.header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
};
exports.contentSecurityPolicy = contentSecurityPolicy;
class InputSanitizer {
    static sanitizeHtml(input) {
        if (typeof input !== 'string')
            return '';
        return input
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#x27;')
            .replace(/\//g, '&#x2F;')
            .replace(/&/g, '&amp;');
    }
    static sanitizeSql(input) {
        if (typeof input !== 'string')
            return '';
        const sqlPatterns = [
            /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b)/gi,
            /(--|\/\*|\*\/|;|'|"|`)/g,
            /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
            /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
        ];
        let sanitized = input;
        sqlPatterns.forEach(pattern => {
            sanitized = sanitized.replace(pattern, '');
        });
        return sanitized.trim();
    }
    static sanitizeFileName(fileName) {
        if (typeof fileName !== 'string')
            return '';
        return fileName
            .replace(/[^a-zA-Z0-9._-]/g, '_')
            .replace(/\.{2,}/g, '.')
            .replace(/^\.+|\.+$/g, '')
            .substring(0, 255);
    }
    static sanitizeJson(input) {
        if (typeof input === 'string') {
            try {
                input = JSON.parse(input);
            }
            catch {
                throw new Error('Invalid JSON format');
            }
        }
        if (typeof input === 'object' && input !== null) {
            if (Array.isArray(input)) {
                return input.map(item => this.sanitizeJson(item));
            }
            else {
                const sanitized = {};
                for (const [key, value] of Object.entries(input)) {
                    const sanitizedKey = this.sanitizeHtml(key);
                    sanitized[sanitizedKey] = this.sanitizeJson(value);
                }
                return sanitized;
            }
        }
        if (typeof input === 'string') {
            return this.sanitizeHtml(input);
        }
        return input;
    }
}
exports.InputSanitizer = InputSanitizer;
const sanitizeRequest = async (request, reply) => {
    try {
        if (request.body && typeof request.body === 'object') {
            request.body = InputSanitizer.sanitizeJson(request.body);
        }
        if (request.query && typeof request.query === 'object') {
            const sanitizedQuery = {};
            for (const [key, value] of Object.entries(request.query)) {
                const sanitizedKey = InputSanitizer.sanitizeHtml(key);
                if (typeof value === 'string') {
                    sanitizedQuery[sanitizedKey] = InputSanitizer.sanitizeHtml(value);
                }
                else {
                    sanitizedQuery[sanitizedKey] = value;
                }
            }
            request.query = sanitizedQuery;
        }
        if (request.params && typeof request.params === 'object') {
            const sanitizedParams = {};
            for (const [key, value] of Object.entries(request.params)) {
                const sanitizedKey = InputSanitizer.sanitizeHtml(key);
                if (typeof value === 'string') {
                    sanitizedParams[sanitizedKey] = InputSanitizer.sanitizeHtml(value);
                }
                else {
                    sanitizedParams[sanitizedKey] = value;
                }
            }
            request.params = sanitizedParams;
        }
    }
    catch (error) {
        logger_1.logger.error('Request sanitization failed', {
            error: String(error),
            url: request.url,
            method: request.method,
        });
        return reply.status(400).send({
            success: false,
            error: {
                code: 'INVALID_INPUT',
                message: 'Request contains invalid or potentially dangerous content',
                timestamp: new Date().toISOString(),
            },
        });
    }
};
exports.sanitizeRequest = sanitizeRequest;
const validateFileUpload = (allowedTypes = ['image/jpeg', 'image/png', 'image/gif'], maxSize = 5 * 1024 * 1024) => {
    return async (request, reply) => {
        const contentType = request.headers['content-type'];
        const contentLength = parseInt(request.headers['content-length'] || '0');
        if (contentLength > maxSize) {
            return reply.status(413).send({
                success: false,
                error: {
                    code: 'FILE_TOO_LARGE',
                    message: `File size exceeds maximum allowed size of ${maxSize} bytes`,
                    timestamp: new Date().toISOString(),
                },
            });
        }
        if (contentType && !allowedTypes.some(type => contentType.includes(type))) {
            return reply.status(415).send({
                success: false,
                error: {
                    code: 'UNSUPPORTED_MEDIA_TYPE',
                    message: `File type not allowed. Allowed types: ${allowedTypes.join(', ')}`,
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.validateFileUpload = validateFileUpload;
const detectSqlInjection = async (request, reply) => {
    const suspiciousPatterns = [
        /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b.*\b(FROM|WHERE|INTO|VALUES|SET)\b)/gi,
        /(--|\/\*|\*\/|;)\s*(SELECT|INSERT|UPDATE|DELETE|DROP)/gi,
        /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
        /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
        /'.*(\bOR\b|\bAND\b).*'/gi,
    ];
    const checkForSqlInjection = (obj, path = '') => {
        if (typeof obj === 'string') {
            return suspiciousPatterns.some(pattern => pattern.test(obj));
        }
        if (typeof obj === 'object' && obj !== null) {
            for (const [key, value] of Object.entries(obj)) {
                if (checkForSqlInjection(value, `${path}.${key}`)) {
                    return true;
                }
            }
        }
        return false;
    };
    const requestData = {
        body: request.body,
        query: request.query,
        params: request.params,
    };
    if (checkForSqlInjection(requestData)) {
        logger_1.logger.warn('Potential SQL injection attempt detected', {
            url: request.url,
            method: request.method,
            ip: request.ip,
            userAgent: request.headers['user-agent'],
            body: request.body,
            query: request.query,
            params: request.params,
        });
        return reply.status(400).send({
            success: false,
            error: {
                code: 'SUSPICIOUS_INPUT',
                message: 'Request contains potentially malicious content',
                timestamp: new Date().toISOString(),
            },
        });
    }
};
exports.detectSqlInjection = detectSqlInjection;
const limitRequestSize = (maxSize = 1024 * 1024) => {
    return async (request, reply) => {
        const contentLength = parseInt(request.headers['content-length'] || '0');
        if (contentLength > maxSize) {
            return reply.status(413).send({
                success: false,
                error: {
                    code: 'REQUEST_TOO_LARGE',
                    message: `Request size exceeds maximum allowed size of ${maxSize} bytes`,
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.limitRequestSize = limitRequestSize;
//# sourceMappingURL=security.js.map