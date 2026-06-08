"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.requestLogger = void 0;
const logger_1 = require("@/utils/logger");
const requestLogger = async (request, reply) => {
    const start = Date.now();
    const requestId = request.id;
    const method = request.method;
    const url = request.url;
    const userAgent = request.headers['user-agent'];
    const ip = request.ip;
    logger_1.logger.info('Incoming request', {
        requestId,
        method,
        url,
        userAgent,
        ip,
    });
    reply.raw.on('finish', () => {
        const duration = Date.now() - start;
        const statusCode = reply.statusCode;
        logger_1.logger.info('Request completed', {
            requestId,
            method,
            url,
            statusCode,
            duration: `${duration}ms`,
            ip,
        });
        if (duration > 1000) {
            logger_1.logger.warn('Slow request detected', {
                requestId,
                method,
                url,
                duration: `${duration}ms`,
            });
        }
    });
};
exports.requestLogger = requestLogger;
//# sourceMappingURL=requestLogger.js.map