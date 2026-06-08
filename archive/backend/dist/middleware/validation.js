"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.validateQuery = exports.validateRequest = void 0;
const zod_1 = require("zod");
const logger_1 = require("@/utils/logger");
const validateRequest = (schema) => {
    return async (request, reply) => {
        try {
            const validatedData = schema.parse(request.body);
            request.body = validatedData;
        }
        catch (error) {
            if (error instanceof zod_1.z.ZodError) {
                logger_1.logger.warn('Request validation failed', {
                    errors: error.errors,
                    path: request.url,
                    method: request.method,
                });
                return reply.status(400).send({
                    success: false,
                    error: {
                        code: 'INVALID_REQUEST',
                        message: 'Validation failed',
                        details: error.errors.map(err => ({
                            field: err.path.join('.'),
                            message: err.message,
                            code: err.code,
                        })),
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            logger_1.logger.error('Unexpected validation error', { error: String(error) });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Internal server error',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.validateRequest = validateRequest;
const validateQuery = (schema) => {
    return async (request, reply) => {
        try {
            const validatedQuery = schema.parse(request.query);
            request.query = validatedQuery;
        }
        catch (error) {
            if (error instanceof zod_1.z.ZodError) {
                return reply.status(400).send({
                    success: false,
                    error: {
                        code: 'INVALID_QUERY',
                        message: 'Query validation failed',
                        details: error.errors,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            throw error;
        }
    };
};
exports.validateQuery = validateQuery;
//# sourceMappingURL=validation.js.map