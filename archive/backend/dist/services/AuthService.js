"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AuthService = void 0;
const bcryptjs_1 = __importDefault(require("bcryptjs"));
const jsonwebtoken_1 = __importDefault(require("jsonwebtoken"));
const config_1 = require("@/config/config");
const BaseService_1 = require("./BaseService");
const logger_1 = require("@/utils/logger");
class AuthService extends BaseService_1.BaseService {
    constructor(prisma) {
        super(prisma);
    }
    async login(credentials, ipAddress, userAgent) {
        try {
            const user = await this.prisma.user.findFirst({
                where: {
                    username: credentials.username,
                    lineAccountId: credentials.lineAccountId,
                    isActive: true,
                },
            });
            if (!user) {
                throw new Error('Invalid credentials');
            }
            const isPasswordValid = await bcryptjs_1.default.compare(credentials.password, user.passwordHash);
            if (!isPasswordValid) {
                throw new Error('Invalid credentials');
            }
            const tokens = await this.generateTokens(user);
            await this.createSession(user.id, tokens, ipAddress, userAgent);
            await this.prisma.user.update({
                where: { id: user.id },
                data: { lastLoginAt: new Date() },
            });
            logger_1.logger.info('User logged in successfully', {
                userId: user.id,
                username: user.username,
                ipAddress,
            });
            return {
                tokens,
                user: this.mapUserToProfile(user),
            };
        }
        catch (error) {
            logger_1.logger.error('Login failed', {
                username: credentials.username,
                error: String(error),
            });
            throw error;
        }
    }
    async refreshToken(refreshToken, ipAddress) {
        try {
            const payload = jsonwebtoken_1.default.verify(refreshToken, config_1.config.JWT_REFRESH_SECRET);
            const session = await this.prisma.userSession.findFirst({
                where: {
                    userId: payload.userId,
                    refreshTokenHash: this.hashToken(refreshToken),
                    isActive: true,
                    expiresAt: {
                        gt: new Date(),
                    },
                },
                include: {
                    user: true,
                },
            });
            if (!session || !session.user.isActive) {
                throw new Error('Invalid refresh token');
            }
            const newTokens = await this.generateTokens(session.user);
            await this.prisma.userSession.update({
                where: { id: session.id },
                data: {
                    tokenHash: this.hashToken(newTokens.accessToken),
                    refreshTokenHash: this.hashToken(newTokens.refreshToken),
                    expiresAt: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000),
                    lastActivity: new Date(),
                },
            });
            logger_1.logger.info('Token refreshed successfully', {
                userId: session.userId,
                sessionId: session.id,
                ipAddress,
            });
            return newTokens;
        }
        catch (error) {
            logger_1.logger.error('Token refresh failed', { error: String(error) });
            throw new Error('Invalid refresh token');
        }
    }
    async logout(accessToken, userId) {
        try {
            const tokenHash = this.hashToken(accessToken);
            const session = await this.prisma.userSession.findFirst({
                where: {
                    userId,
                    tokenHash,
                    isActive: true,
                },
            });
            if (session) {
                await this.prisma.userSession.update({
                    where: { id: session.id },
                    data: { isActive: false },
                });
            }
            const payload = jsonwebtoken_1.default.decode(accessToken);
            if (payload && payload.exp) {
                const expiresAt = new Date(payload.exp * 1000);
                logger_1.logger.info('Token blacklisted', {
                    userId,
                    tokenHash: tokenHash.substring(0, 8) + '...',
                    expiresAt,
                });
            }
            logger_1.logger.info('User logged out successfully', { userId });
        }
        catch (error) {
            logger_1.logger.error('Logout failed', {
                userId,
                error: String(error),
            });
            throw error;
        }
    }
    async validateToken(token) {
        try {
            const payload = jsonwebtoken_1.default.verify(token, config_1.config.JWT_SECRET);
            const user = await this.prisma.user.findFirst({
                where: {
                    id: payload.userId,
                    isActive: true,
                },
            });
            if (!user) {
                throw new Error('User not found or inactive');
            }
            return payload;
        }
        catch (error) {
            logger_1.logger.error('Token validation failed', { error: String(error) });
            throw new Error('Invalid token');
        }
    }
    async getUserProfile(userId) {
        const user = await this.prisma.user.findUnique({
            where: { id: userId },
        });
        if (!user || !user.isActive) {
            throw new Error('User not found');
        }
        return this.mapUserToProfile(user);
    }
    async revokeAllSessions(userId) {
        await this.prisma.userSession.updateMany({
            where: { userId },
            data: { isActive: false },
        });
        logger_1.logger.info('All sessions revoked for user', { userId });
    }
    async cleanupExpiredSessions() {
        const result = await this.prisma.userSession.deleteMany({
            where: {
                OR: [
                    { expiresAt: { lt: new Date() } },
                    { isActive: false },
                ],
            },
        });
        logger_1.logger.info('Cleaned up expired sessions', { count: result.count });
    }
    async generateTokens(user) {
        const permissions = this.getUserPermissions(user.role);
        const payload = {
            userId: user.id,
            role: user.role,
            lineAccountId: user.lineAccountId,
            permissions,
        };
        const accessToken = jsonwebtoken_1.default.sign(payload, config_1.config.JWT_SECRET, {
            expiresIn: config_1.config.JWT_EXPIRES_IN,
        });
        const refreshToken = jsonwebtoken_1.default.sign(payload, config_1.config.JWT_REFRESH_SECRET, {
            expiresIn: config_1.config.JWT_REFRESH_EXPIRES_IN,
        });
        const accessTokenDecoded = jsonwebtoken_1.default.decode(accessToken);
        const refreshTokenDecoded = jsonwebtoken_1.default.decode(refreshToken);
        return {
            accessToken,
            refreshToken,
            expiresIn: accessTokenDecoded.exp - accessTokenDecoded.iat,
            refreshExpiresIn: refreshTokenDecoded.exp - refreshTokenDecoded.iat,
        };
    }
    async createSession(userId, tokens, ipAddress, userAgent) {
        const refreshTokenDecoded = jsonwebtoken_1.default.decode(tokens.refreshToken);
        await this.prisma.userSession.create({
            data: {
                userId,
                tokenHash: this.hashToken(tokens.accessToken),
                refreshTokenHash: this.hashToken(tokens.refreshToken),
                expiresAt: new Date(refreshTokenDecoded.exp * 1000),
                ipAddress: ipAddress || null,
                userAgent: userAgent || null,
            },
        });
    }
    hashToken(token) {
        return require('crypto').createHash('sha256').update(token).digest('hex');
    }
    getUserPermissions(role) {
        const rolePermissions = {
            SUPER_ADMIN: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'manage_webhooks',
                'admin_access',
                'manage_users',
                'system_settings',
            ],
            ADMIN: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'manage_webhooks',
                'admin_access',
            ],
            PHARMACIST: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'pharmacist_access',
            ],
            STAFF: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
            ],
        };
        return rolePermissions[role] || [];
    }
    mapUserToProfile(user) {
        return {
            id: user.id,
            username: user.username,
            email: user.email,
            role: user.role,
            lineAccountId: user.lineAccountId,
            permissions: this.getUserPermissions(user.role),
            lastLoginAt: user.lastLoginAt,
        };
    }
}
exports.AuthService = AuthService;
//# sourceMappingURL=AuthService.js.map