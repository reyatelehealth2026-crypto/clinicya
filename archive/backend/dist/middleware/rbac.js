"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.requireLineAccountAccess = exports.canAccessLineAccount = exports.getRolePermissions = exports.requireSuperAdmin = exports.requireAdmin = exports.requireRole = exports.requirePermission = exports.hasRoleLevel = exports.hasPermission = exports.UserRole = exports.Permission = void 0;
const logger_1 = require("@/utils/logger");
var Permission;
(function (Permission) {
    Permission["VIEW_DASHBOARD"] = "view_dashboard";
    Permission["MANAGE_ORDERS"] = "manage_orders";
    Permission["PROCESS_PAYMENTS"] = "process_payments";
    Permission["MANAGE_WEBHOOKS"] = "manage_webhooks";
    Permission["ADMIN_ACCESS"] = "admin_access";
    Permission["MANAGE_USERS"] = "manage_users";
    Permission["SYSTEM_SETTINGS"] = "system_settings";
    Permission["PHARMACIST_ACCESS"] = "pharmacist_access";
})(Permission || (exports.Permission = Permission = {}));
var UserRole;
(function (UserRole) {
    UserRole["SUPER_ADMIN"] = "SUPER_ADMIN";
    UserRole["ADMIN"] = "ADMIN";
    UserRole["PHARMACIST"] = "PHARMACIST";
    UserRole["STAFF"] = "STAFF";
})(UserRole || (exports.UserRole = UserRole = {}));
const ROLE_HIERARCHY = {
    [UserRole.SUPER_ADMIN]: 4,
    [UserRole.ADMIN]: 3,
    [UserRole.PHARMACIST]: 2,
    [UserRole.STAFF]: 1,
};
const ROLE_PERMISSIONS = {
    [UserRole.SUPER_ADMIN]: [
        Permission.VIEW_DASHBOARD,
        Permission.MANAGE_ORDERS,
        Permission.PROCESS_PAYMENTS,
        Permission.MANAGE_WEBHOOKS,
        Permission.ADMIN_ACCESS,
        Permission.MANAGE_USERS,
        Permission.SYSTEM_SETTINGS,
    ],
    [UserRole.ADMIN]: [
        Permission.VIEW_DASHBOARD,
        Permission.MANAGE_ORDERS,
        Permission.PROCESS_PAYMENTS,
        Permission.MANAGE_WEBHOOKS,
        Permission.ADMIN_ACCESS,
    ],
    [UserRole.PHARMACIST]: [
        Permission.VIEW_DASHBOARD,
        Permission.MANAGE_ORDERS,
        Permission.PROCESS_PAYMENTS,
        Permission.PHARMACIST_ACCESS,
    ],
    [UserRole.STAFF]: [
        Permission.VIEW_DASHBOARD,
        Permission.MANAGE_ORDERS,
        Permission.PROCESS_PAYMENTS,
    ],
};
const hasPermission = (userRole, userPermissions, requiredPermission) => {
    if (userRole === UserRole.SUPER_ADMIN) {
        return true;
    }
    if (userPermissions.includes(requiredPermission) || userPermissions.includes('*')) {
        return true;
    }
    const rolePermissions = ROLE_PERMISSIONS[userRole] || [];
    return rolePermissions.includes(requiredPermission);
};
exports.hasPermission = hasPermission;
const hasRoleLevel = (userRole, requiredRole) => {
    const userLevel = ROLE_HIERARCHY[userRole] || 0;
    const requiredLevel = ROLE_HIERARCHY[requiredRole] || 0;
    return userLevel >= requiredLevel;
};
exports.hasRoleLevel = hasRoleLevel;
const requirePermission = (permission) => {
    return async (request, reply) => {
        const user = request.user;
        if (!user) {
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'UNAUTHORIZED',
                    message: 'Authentication required',
                    timestamp: new Date().toISOString(),
                },
            });
        }
        if (!(0, exports.hasPermission)(user.role, user.permissions, permission)) {
            logger_1.logger.warn('Access denied - insufficient permissions', {
                userId: user.userId,
                role: user.role,
                requiredPermission: permission,
                userPermissions: user.permissions,
            });
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
exports.requirePermission = requirePermission;
const requireRole = (role) => {
    return async (request, reply) => {
        const user = request.user;
        if (!user) {
            return reply.status(401).send({
                success: false,
                error: {
                    code: 'UNAUTHORIZED',
                    message: 'Authentication required',
                    timestamp: new Date().toISOString(),
                },
            });
        }
        if (!(0, exports.hasRoleLevel)(user.role, role)) {
            logger_1.logger.warn('Access denied - insufficient role level', {
                userId: user.userId,
                userRole: user.role,
                requiredRole: role,
            });
            return reply.status(403).send({
                success: false,
                error: {
                    code: 'INSUFFICIENT_ROLE',
                    message: 'Access denied - insufficient role level',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.requireRole = requireRole;
exports.requireAdmin = (0, exports.requireRole)(UserRole.ADMIN);
exports.requireSuperAdmin = (0, exports.requireRole)(UserRole.SUPER_ADMIN);
const getRolePermissions = (role) => {
    return ROLE_PERMISSIONS[role] || [];
};
exports.getRolePermissions = getRolePermissions;
const canAccessLineAccount = (user, requestedLineAccountId) => {
    if (user.role === UserRole.SUPER_ADMIN) {
        return true;
    }
    return user.lineAccountId === requestedLineAccountId;
};
exports.canAccessLineAccount = canAccessLineAccount;
const requireLineAccountAccess = (getLineAccountId) => {
    return async (request, reply) => {
        const user = request.user;
        const requestedLineAccountId = getLineAccountId(request);
        if (!(0, exports.canAccessLineAccount)(user, requestedLineAccountId)) {
            logger_1.logger.warn('Access denied - line account access violation', {
                userId: user.userId,
                userLineAccountId: user.lineAccountId,
                requestedLineAccountId,
            });
            return reply.status(403).send({
                success: false,
                error: {
                    code: 'LINE_ACCOUNT_ACCESS_DENIED',
                    message: 'Access denied to requested line account',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    };
};
exports.requireLineAccountAccess = requireLineAccountAccess;
//# sourceMappingURL=rbac.js.map