import { PrismaClient, UserRole } from '@prisma/client';
import { BaseService } from './BaseService';
import { JWTPayload } from '@/types';
export interface LoginCredentials {
    username: string;
    password: string;
    lineAccountId: string;
}
export interface AuthTokens {
    accessToken: string;
    refreshToken: string;
    expiresIn: number;
    refreshExpiresIn: number;
}
export interface UserProfile {
    id: string;
    username: string;
    email: string;
    role: UserRole;
    lineAccountId: string;
    permissions: string[];
    lastLoginAt: Date | null;
}
export declare class AuthService extends BaseService {
    constructor(prisma: PrismaClient);
    login(credentials: LoginCredentials, ipAddress?: string, userAgent?: string): Promise<{
        tokens: AuthTokens;
        user: UserProfile;
    }>;
    refreshToken(refreshToken: string, ipAddress?: string): Promise<AuthTokens>;
    logout(accessToken: string, userId: string): Promise<void>;
    validateToken(token: string): Promise<JWTPayload>;
    getUserProfile(userId: string): Promise<UserProfile>;
    revokeAllSessions(userId: string): Promise<void>;
    cleanupExpiredSessions(): Promise<void>;
    private generateTokens;
    private createSession;
    private hashToken;
    private getUserPermissions;
    private mapUserToProfile;
}
//# sourceMappingURL=AuthService.d.ts.map