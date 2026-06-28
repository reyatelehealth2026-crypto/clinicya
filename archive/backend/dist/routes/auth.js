"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = authRoutes;
const zod_1 = require("zod");
const validation_1 = require("@/middleware/validation");
const auth_1 = require("@/middleware/auth");
const authRateLimit_1 = require("@/middleware/authRateLimit");
const auditAuth_1 = require("@/middleware/auditAuth");
const AuthService_1 = require("@/services/AuthService");
const prisma_1 = require("@/utils/prisma");
const logger_1 = require("@/utils/logger");
const loginSchema = zod_1.z.object({
    username: zod_1.z.string().min(1),
    password: zod_1.z.string().min(1),
    lineAccountId: zod_1.z.string().min(1),
});
const refreshSchema = zod_1.z.object({
    refreshToken: zod_1.z.string().min(1),
});
async function authRoutes(fastify) {
    const authService = new AuthService_1.AuthService(prisma_1.prisma);
    fastify.post('/login', {
        preHandler: [auditAuth_1.detectSuspiciousActivity, authRateLimit_1.loginRateLimit, (0, validation_1.validateRequest)(loginSchema), auditAuth_1.auditLogin],
        schema: {
            tags: ['Authentication'],
            summary: 'User login',
            body: {
                type: 'object',
                required: ['username', 'password', 'lineAccountId'],
                properties: {
                    username: { type: 'string' },
                    password: { type: 'string' },
                    lineAccountId: { type: 'string' },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                accessToken: { type: 'string' },
                                refreshToken: { type: 'string' },
                                expiresIn: { type: 'number' },
                                user: {
                                    type: 'object',
                                    properties: {
                                        id: { type: 'string' },
                                        username: { type: 'string' },
                                        email: { type: 'string' },
                                        role: { type: 'string' },
                                        lineAccountId: { type: 'string' },
                                        permissions: { type: 'array', items: { type: 'string' } },
                                    },
                                },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { username, password, lineAccountId } = request.body;
            const ipAddress = request.ip;
            const userAgent = request.headers['user-agent'];
            const result = await authService.login({ username, password, lineAccountId }, ipAddress, userAgent);
            return reply.send({
                success: true,
                data: {
                    accessToken: result.tokens.accessToken,
                    refreshToken: result.tokens.refreshToken,
                    expiresIn: result.tokens.expiresIn,
                    user: result.user,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Login endpoint error', { error: String(error) });
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'LOGIN_FAILED',
                    message: 'Invalid credentials',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/refresh', {
        preHandler: [authRateLimit_1.refreshRateLimit, (0, validation_1.validateRequest)(refreshSchema), auditAuth_1.auditTokenRefresh],
        schema: {
            tags: ['Authentication'],
            summary: 'Refresh access token',
            body: {
                type: 'object',
                required: ['refreshToken'],
                properties: {
                    refreshToken: { type: 'string' },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                accessToken: { type: 'string' },
                                refreshToken: { type: 'string' },
                                expiresIn: { type: 'number' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { refreshToken } = request.body;
            const ipAddress = request.ip;
            const tokens = await authService.refreshToken(refreshToken, ipAddress);
            return reply.send({
                success: true,
                data: {
                    accessToken: tokens.accessToken,
                    refreshToken: tokens.refreshToken,
                    expiresIn: tokens.expiresIn,
                },
            });
        }
        catch (error) {
            logger_1.logger.error('Token refresh endpoint error', { error: String(error) });
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'REFRESH_FAILED',
                    message: 'Invalid refresh token',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/logout', {
        preHandler: [authRateLimit_1.logoutRateLimit, auth_1.authenticate, auditAuth_1.auditLogout],
        schema: {
            tags: ['Authentication'],
            summary: 'User logout',
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        message: { type: 'string' },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const authHeader = request.headers.authorization;
            const token = authHeader?.replace('Bearer ', '') || '';
            await authService.logout(token, user.userId);
            const payload = require('jsonwebtoken').decode(token);
            if (payload && payload.exp) {
                const ttl = payload.exp - Math.floor(Date.now() / 1000);
                if (ttl > 0) {
                    await request.server.redis.setex(`blacklist:${token}`, ttl, 'true');
                }
            }
            return reply.send({
                success: true,
                message: 'Logged out successfully',
            });
        }
        catch (error) {
            logger_1.logger.error('Logout endpoint error', { error: String(error) });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'LOGOUT_FAILED',
                    message: 'Logout failed',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/profile', {
        preHandler: [authRateLimit_1.profileRateLimit, auth_1.authenticate, auditAuth_1.auditProfileAccess],
        schema: {
            tags: ['Authentication'],
            summary: 'Get current user profile',
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                id: { type: 'string' },
                                username: { type: 'string' },
                                email: { type: 'string' },
                                role: { type: 'string' },
                                lineAccountId: { type: 'string' },
                                permissions: { type: 'array', items: { type: 'string' } },
                                lastLoginAt: { type: 'string', format: 'date-time' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const profile = await authService.getUserProfile(user.userId);
            return reply.send({
                success: true,
                data: profile,
            });
        }
        catch (error) {
            logger_1.logger.error('Profile endpoint error', { error: String(error) });
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'PROFILE_FETCH_FAILED',
                    message: 'Failed to fetch user profile',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
}
//# sourceMappingURL=auth.js.map