"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.BaseService = void 0;
class BaseService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    handleError(error, context) {
        console.error(`Error in ${context}`, error);
        if (error instanceof Error) {
            throw error;
        }
        throw new Error(`Unknown error in ${context}`);
    }
    validateLineAccountAccess(userLineAccountId, requestedLineAccountId) {
        if (!requestedLineAccountId) {
            return userLineAccountId;
        }
        if (requestedLineAccountId !== userLineAccountId) {
            throw new Error('Access denied to requested line account');
        }
        return requestedLineAccountId;
    }
}
exports.BaseService = BaseService;
//# sourceMappingURL=BaseService.js.map