import { FastifyRequest, FastifyReply } from 'fastify';
import { JWTPayload } from '@/types';
export declare enum Permission {
    VIEW_DASHBOARD = "view_dashboard",
    MANAGE_ORDERS = "manage_orders",
    PROCESS_PAYMENTS = "process_payments",
    MANAGE_WEBHOOKS = "manage_webhooks",
    ADMIN_ACCESS = "admin_access",
    MANAGE_USERS = "manage_users",
    SYSTEM_SETTINGS = "system_settings",
    PHARMACIST_ACCESS = "pharmacist_access"
}
export declare enum UserRole {
    SUPER_ADMIN = "SUPER_ADMIN",
    ADMIN = "ADMIN",
    PHARMACIST = "PHARMACIST",
    STAFF = "STAFF"
}
export declare const hasPermission: (userRole: string, userPermissions: string[], requiredPermission: Permission) => boolean;
export declare const hasRoleLevel: (userRole: string, requiredRole: UserRole) => boolean;
export declare const requirePermission: (permission: Permission) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const requireRole: (role: UserRole) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const requireAdmin: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const requireSuperAdmin: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const getRolePermissions: (role: UserRole) => Permission[];
export declare const canAccessLineAccount: (user: JWTPayload, requestedLineAccountId: string) => boolean;
export declare const requireLineAccountAccess: (getLineAccountId: (request: FastifyRequest) => string) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=rbac.d.ts.map