"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.assertionHelpers = exports.cleanupHelpers = exports.performanceHelpers = exports.testConstants = exports.setupTestEnvironment = exports.TEST_CONFIG = void 0;
exports.TEST_CONFIG = {
    database: {
        testTimeout: 30000,
        connectionTimeout: 10000,
        maxConnections: 10,
    },
    propertyTesting: {
        numRuns: 100,
        timeout: 5000,
        verbose: false,
        seed: 42,
    },
    performance: {
        apiResponseTime: 300,
        databaseQueryTime: 100,
        memoryUsage: 50 * 1024 * 1024,
    },
    mocks: {
        redis: {
            defaultTTL: 3600,
            maxMemory: '100mb',
        },
        odoo: {
            timeout: 5000,
            retryAttempts: 3,
        },
        line: {
            timeout: 3000,
            retryAttempts: 2,
        },
    },
    testData: {
        maxOrders: 1000,
        maxPaymentSlips: 500,
        maxWebhooks: 2000,
        maxUsers: 100,
    },
};
const setupTestEnvironment = () => {
    process.env.NODE_ENV = 'test';
    process.env.LOG_LEVEL = 'error';
    process.env.REDIS_URL = 'redis://localhost:6379/15';
    vi.setConfig({
        testTimeout: exports.TEST_CONFIG.database.testTimeout,
        hookTimeout: exports.TEST_CONFIG.database.connectionTimeout,
    });
};
exports.setupTestEnvironment = setupTestEnvironment;
exports.testConstants = {
    TEST_USER_ID: '123e4567-e89b-12d3-a456-426614174000',
    TEST_LINE_ACCOUNT_ID: '123e4567-e89b-12d3-a456-426614174001',
    TEST_ORDER_ID: '123e4567-e89b-12d3-a456-426614174002',
    TEST_PAYMENT_SLIP_ID: '123e4567-e89b-12d3-a456-426614174003',
    TEST_DATE_START: new Date('2024-01-01T00:00:00Z'),
    TEST_DATE_END: new Date('2024-01-31T23:59:59Z'),
    TEST_AMOUNTS: {
        SMALL: 100.00,
        MEDIUM: 1000.00,
        LARGE: 10000.00,
        VERY_LARGE: 100000.00,
    },
    TEST_CREDENTIALS: {
        VALID_PASSWORD: 'TestPassword123!',
        INVALID_PASSWORD: 'wrong_password',
        WEAK_PASSWORD: '123',
    },
};
exports.performanceHelpers = {
    measureExecutionTime: async (fn) => {
        const start = performance.now();
        const result = await fn();
        const duration = performance.now() - start;
        return { result, duration };
    },
    measureMemoryUsage: (fn) => {
        const before = process.memoryUsage().heapUsed;
        const result = fn();
        const after = process.memoryUsage().heapUsed;
        const memoryDelta = after - before;
        return { result, memoryDelta };
    },
    assertPerformance: (duration, threshold, operation) => {
        if (duration > threshold) {
            throw new Error(`Performance assertion failed: ${operation} took ${duration}ms, expected < ${threshold}ms`);
        }
    },
};
exports.cleanupHelpers = {
    cleanupDatabase: async (prisma) => {
        await prisma.auditLog.deleteMany();
        await prisma.userSession.deleteMany();
        await prisma.webhookLog.deleteMany();
        await prisma.paymentSlip.deleteMany();
        await prisma.orderItem.deleteMany();
        await prisma.order.deleteMany();
        await prisma.user.deleteMany();
    },
    cleanupRedis: async (redis) => {
        await redis.flushdb();
    },
    resetMocks: () => {
        vi.clearAllMocks();
        vi.resetAllMocks();
    },
};
exports.assertionHelpers = {
    assertValidUUID: (uuid) => {
        const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
        expect(uuid).toMatch(uuidRegex);
    },
    assertValidDate: (date) => {
        expect(date).toBeInstanceOf(Date);
        expect(date.getTime()).not.toBeNaN();
    },
    assertValidAmount: (amount) => {
        expect(amount).toBeTypeOf('number');
        expect(amount).toBeGreaterThanOrEqual(0);
        expect(Number.isFinite(amount)).toBe(true);
    },
    assertValidPercentage: (percentage) => {
        expect(percentage).toBeTypeOf('number');
        expect(percentage).toBeGreaterThanOrEqual(0);
        expect(percentage).toBeLessThanOrEqual(1);
    },
    assertAPIResponse: (response) => {
        expect(response).toHaveProperty('success');
        expect(typeof response.success).toBe('boolean');
        if (response.success) {
            expect(response).toHaveProperty('data');
        }
        else {
            expect(response).toHaveProperty('error');
            expect(response.error).toHaveProperty('code');
            expect(response.error).toHaveProperty('message');
        }
    },
};
exports.default = exports.TEST_CONFIG;
//# sourceMappingURL=testConfig.js.map