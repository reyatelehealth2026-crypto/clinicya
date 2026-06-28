"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.authorize = exports.authenticate = void 0;
const prisma_1 = require("@/utils/prisma");
const logger_1 = require("@/utils/logger");
const AuthService_1 = require("@/services/AuthService");
const authenticate = async (request, reply) => {
    try {
        const authHeader = request.headers.authorization;
        if (!authHeader || !authHeader.startsWith('Bearer ')) {
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'MISSING_TOKEN',
                    message: 'Authorization token required',
                    timestamp: new Date().toISOString(),
                },
            });
        }
        const token = authHeader.replace('Bearer ', '');
        const authService = new AuthService_1.AuthService(prisma_1.prisma);
        const payload = await authService.validateToken(token);
        const blacklisted = await request.server.redis.get(`blacklist:${token}`);
        if (blacklisted) {
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'TOKEN_REVOKED',
                    message: 'Token has been revoked',
                    timestamp: new Date().toISOString(),
                },
            });
        }
        const tokenHash = require('crypto').createHash('sha256').update(token).digest('hex');
        const session = await prisma_1.prisma.userSession.findFirst({
            where: {
                userId: payload.userId,
                tokenHash,
                isActive: true,
                expiresAt: {
                    gt: new Date(),
                },
            },
        });
        if (!session) {
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'INVALID_SESSION',
                    message: 'Session not found or expired',
                    timestamp: new Date().toISOString(),
                },
            });
        }
        await prisma_1.prisma.userSession.update({
            where: { id: session.id },
            data: { lastActivity: new Date() },
        });
        request.user = payload;
    }
    catch (error) {
        logger_1.logger.error('Authentication failed', { error: String(error) });
        return reply.status(401).send({
            success: false,
            error: {
                code: 'INVALID_TOKEN',
                message: 'Invalid or expired token',
                timestamp: new Date().toISOString(),
            },
        });
    }
};
exports.authenticate = authenticate;
const authorize = (permissions) => {
    return async (request, reply) => {
        const user = request.user;
        const userPermissions = user.permissions || [];
        const hasPermission = permissions.some(permission => userPermissions.includes(permission) || userPermissions.includes('*'));
        if (!hasPermission) {
            return reply.status(403).send({
                success: false,
                error: {
                    code: 'INSUFFICIENT_PERMISSIONS',
                    message: 'Access denied',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.authorize = authorize;
//# sourceMappingURL=auth.js.map