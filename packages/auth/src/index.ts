// @reya/auth public entrypoint.
//
// Everything in the Phase 1 batch 2 interfaceContract (cookie name constants,
// Realm/TenantRole/PlatformRole/Session types, AuthError/AuthResult,
// LoginInput/LoginValue, login/logout/getSession/requireRole/switchBot/
// switchTenant, BridgeAction/BridgePhpSessionKeys/BridgeSyncPayload/
// syncToPhpBridge, verifyLegacyPassword) is exported below with the exact
// names/signatures given — see types.ts for the two flagged, additive-only
// deviations (RoleOf helper type, BridgeSyncPayload.sid field).
//
// A handful of supporting building blocks beyond the literal contract are
// also exported (SessionStore + its implementations, canAccessBot/ACL types,
// role hierarchies, the tenant-db ambient context, the audit writer) — these
// are the internals mig-ui/mig-verify may reasonably need direct access to
// (tests, future extension), never a replacement for the contract names.
export * from './types';
export * from './passwords';
export * from './rbac';
export * from './sessionStore';
export * from './redisClient';
export * from './tenantDbContext';
export * from './impersonation';
export * from './bridgeClient';
export * from './session';
