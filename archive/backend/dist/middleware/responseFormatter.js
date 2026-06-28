"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.responseFormatter = void 0;
const responseFormatter = async (_request, reply) => {
    reply.success = function (data, meta) {
        const response = {
            success: true,
            data,
            meta,
        };
        return this.send(response);
    };
    reply.error = function (code, message, details, statusCode = 400) {
        const response = {
            success: false,
            error: {
                code,
                message,
                details,
                timestamp: new Date().toISOString(),
            },
        };
        return this.status(statusCode).send(response);
    };
};
exports.responseFormatter = responseFormatter;
//# sourceMappingURL=responseFormatter.js.map