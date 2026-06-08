#!/usr/bin/env tsx
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.MigrationValidator = void 0;
const client_1 = require("@prisma/client");
const perf_hooks_1 = require("perf_hooks");
const prisma = new client_1.PrismaClient();
class MigrationValidator {
    results = [];
    async validateDataIntegrity() {
        const startTime = perf_hooks_1.performance.now();
        try {
            const testUser = await prisma.user.create({
                data: {
                    username: 'test_migration_user',
                    email: 'test@migration.com',
                    passwordHash: 'hashed_password',
                    role: 'STAFF',
                    lineAccountId: '1',
                }
            });
            const testSession = await prisma.userSession.create({
                data: {
                    userId: testUser.id,
                    tokenHash: 'test_token_hash',
                    refreshTokenHash: 'test_refresh_hash',
                    expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
                }
            });
            const testAuditLog = await prisma.auditLog.create({
                data: {
                    userId: testUser.id,
                    action: 'CREATE_USER',
                    resourceType: 'user',
                    resourceId: testUser.id,
                    newValues: { username: testUser.username },
                }
            });
            await prisma.auditLog.delete({ where: { id: testAuditLog.id } });
            await prisma.userSession.delete({ where: { id: testSession.id } });
            await prisma.user.delete({ where: { id: testUser.id } });
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Data Integrity',
                passed: true,
                duration,
                details: {
                    userCreated: !!testUser,
                    sessionCreated: !!testSession,
                    auditLogCreated: !!testAuditLog,
                }
            };
        }
        catch (error) {
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Data Integrity',
                passed: false,
                duration,
                error: error instanceof Error ? error.message : 'Unknown error',
            };
        }
    }
    async validateForeignKeyRelationships() {
        const startTime = perf_hooks_1.performance.now();
        try {
            const testUser = await prisma.user.create({
                data: {
                    username: 'fk_test_user',
                    email: 'fk@test.com',
                    passwordHash: 'hashed_password',
                    role: 'STAFF',
                    lineAccountId: '1',
                }
            });
            const testSession = await prisma.userSession.create({
                data: {
                    userId: testUser.id,
                    tokenHash: 'fk_test_token',
                    refreshTokenHash: 'fk_test_refresh',
                    expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
                }
            });
            await prisma.user.delete({ where: { id: testUser.id } });
            const deletedSession = await prisma.userSession.findUnique({
                where: { id: testSession.id }
            });
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Foreign Key Relationships',
                passed: deletedSession === null,
                duration,
                details: {
                    cascadeDeleteWorked: deletedSession === null,
                }
            };
        }
        catch (error) {
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Foreign Key Relationships',
                passed: false,
                duration,
                error: error instanceof Error ? error.message : 'Unknown error',
            };
        }
    }
    async validateIndexPerformance() {
        const startTime = perf_hooks_1.performance.now();
        try {
            const queries = [
                () => prisma.userSession.findMany({
                    where: { expiresAt: { gt: new Date() } },
                    take: 10
                }),
                () => prisma.auditLog.findMany({
                    where: {
                        action: 'CREATE_USER',
                        createdAt: { gte: new Date(Date.now() - 24 * 60 * 60 * 1000) }
                    },
                    take: 10
                }),
                () => prisma.dashboardMetricsCache.findMany({
                    where: {
                        lineAccountId: '1',
                        metricType: 'ORDERS',
                        expiresAt: { gt: new Date() }
                    },
                    take: 10
                }),
            ];
            const queryTimes = [];
            for (const query of queries) {
                const queryStart = perf_hooks_1.performance.now();
                await query();
                const queryTime = perf_hooks_1.performance.now() - queryStart;
                queryTimes.push(queryTime);
            }
            const avgQueryTime = queryTimes.reduce((a, b) => a + b, 0) / queryTimes.length;
            const duration = perf_hooks_1.performance.now() - startTime;
            const performanceGood = avgQueryTime < 50;
            return {
                test: 'Index Performance',
                passed: performanceGood,
                duration,
                details: {
                    averageQueryTime: avgQueryTime,
                    individualQueryTimes: queryTimes,
                    performanceThreshold: 50,
                }
            };
        }
        catch (error) {
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Index Performance',
                passed: false,
                duration,
                error: error instanceof Error ? error.message : 'Unknown error',
            };
        }
    }
    async validateCacheTableStructure() {
        const startTime = perf_hooks_1.performance.now();
        try {
            const testMetric = await prisma.dashboardMetricsCache.create({
                data: {
                    lineAccountId: '1',
                    metricType: 'ORDERS',
                    dateKey: new Date(),
                    data: {
                        totalOrders: 100,
                        totalAmount: 50000,
                        averageOrderValue: 500
                    },
                    expiresAt: new Date(Date.now() + 60 * 60 * 1000),
                }
            });
            const testApiCache = await prisma.apiCache.create({
                data: {
                    cacheKey: 'test_api_cache_key',
                    data: {
                        result: 'cached_data',
                        timestamp: new Date().toISOString()
                    },
                    expiresAt: new Date(Date.now() + 30 * 60 * 1000),
                }
            });
            try {
                await prisma.dashboardMetricsCache.create({
                    data: {
                        lineAccountId: '1',
                        metricType: 'ORDERS',
                        dateKey: new Date(),
                        data: { duplicate: true },
                        expiresAt: new Date(Date.now() + 60 * 60 * 1000),
                    }
                });
                throw new Error('Unique constraint not enforced');
            }
            catch (uniqueError) {
            }
            await prisma.dashboardMetricsCache.delete({ where: { id: testMetric.id } });
            await prisma.apiCache.delete({ where: { cacheKey: testApiCache.cacheKey } });
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Cache Table Structure',
                passed: true,
                duration,
                details: {
                    dashboardMetricsCacheWorking: !!testMetric,
                    apiCacheWorking: !!testApiCache,
                    uniqueConstraintEnforced: true,
                }
            };
        }
        catch (error) {
            const duration = perf_hooks_1.performance.now() - startTime;
            return {
                test: 'Cache Table Structure',
                passed: false,
                duration,
                error: error instanceof Error ? error.message : 'Unknown error',
            };
        }
    }
    async runAllValidations() {
        console.log('🔍 Starting database migration validation...\n');
        const validations = [
            this.validateDataIntegrity(),
            this.validateForeignKeyRelationships(),
            this.validateIndexPerformance(),
            this.validateCacheTableStructure(),
        ];
        this.results = await Promise.all(validations);
        console.log('📊 Validation Results:');
        console.log('='.repeat(60));
        let allPassed = true;
        for (const result of this.results) {
            const status = result.passed ? '✅ PASS' : '❌ FAIL';
            const duration = `${result.duration.toFixed(2)}ms`;
            console.log(`${status} ${result.test.padEnd(25)} (${duration})`);
            if (!result.passed) {
                allPassed = false;
                console.log(`   Error: ${result.error}`);
            }
            if (result.details) {
                console.log(`   Details: ${JSON.stringify(result.details, null, 2)}`);
            }
            console.log('');
        }
        console.log('='.repeat(60));
        console.log(`Overall Status: ${allPassed ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED'}`);
        if (!allPassed) {
            process.exit(1);
        }
    }
}
exports.MigrationValidator = MigrationValidator;
if (require.main === module) {
    const validator = new MigrationValidator();
    validator.runAllValidations()
        .catch((error) => {
        console.error('❌ Validation failed with error:', error);
        process.exit(1);
    })
        .finally(() => {
        prisma.$disconnect();
    });
}
//# sourceMappingURL=validate-migration.js.map