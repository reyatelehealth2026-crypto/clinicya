"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createTestPaymentSlip = exports.createTestOrder = exports.createTestUser = exports.mockLineAPI = exports.mockOdooService = exports.mockRedis = exports.prisma = void 0;
const vitest_1 = require("vitest");
const client_1 = require("@prisma/client");
const child_process_1 = require("child_process");
const crypto_1 = require("crypto");
const generateDatabaseUrl = () => {
    const testId = (0, crypto_1.randomUUID)();
    return `mysql://root:password@localhost:3306/test_odoo_dashboard_${testId.replace(/-/g, '_')}`;
};
let prisma;
let testDatabaseUrl;
(0, vitest_1.beforeAll)(async () => {
    testDatabaseUrl = generateDatabaseUrl();
    process.env.DATABASE_URL = testDatabaseUrl;
    const dbName = testDatabaseUrl.split('/').pop();
    (0, child_process_1.execSync)(`mysql -u root -ppassword -e "CREATE DATABASE IF NOT EXISTS ${dbName}"`);
    exports.prisma = prisma = new client_1.PrismaClient({
        datasources: {
            db: {
                url: testDatabaseUrl,
            },
        },
    });
    (0, child_process_1.execSync)('npx prisma migrate deploy', {
        env: { ...process.env, DATABASE_URL: testDatabaseUrl },
        stdio: 'inherit'
    });
    await prisma.$connect();
});
(0, vitest_1.afterAll)(async () => {
    await prisma.$disconnect();
    const dbName = testDatabaseUrl.split('/').pop();
    (0, child_process_1.execSync)(`mysql -u root -ppassword -e "DROP DATABASE IF EXISTS ${dbName}"`);
});
(0, vitest_1.beforeEach)(async () => {
    const tablenames = await prisma.$queryRaw `
    SELECT TABLE_NAME from information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME != '_prisma_migrations'
  `;
    const tables = tablenames
        .map(({ TABLE_NAME }) => TABLE_NAME)
        .filter(name => name !== '_prisma_migrations');
    try {
        await prisma.$executeRawUnsafe('SET FOREIGN_KEY_CHECKS = 0;');
        for (const table of tables) {
            await prisma.$executeRawUnsafe(`TRUNCATE TABLE \`${table}\`;`);
        }
        await prisma.$executeRawUnsafe('SET FOREIGN_KEY_CHECKS = 1;');
    }
    catch (error) {
        console.log('Error cleaning database:', error);
    }
});
(0, vitest_1.afterEach)(async () => {
});
exports.mockRedis = {
    get: vi.fn(),
    set: vi.fn(),
    del: vi.fn(),
    exists: vi.fn(),
    expire: vi.fn(),
    flushall: vi.fn(),
};
exports.mockOdooService = {
    authenticate: vi.fn(),
    getOrders: vi.fn(),
    getCustomers: vi.fn(),
    updateOrderStatus: vi.fn(),
};
exports.mockLineAPI = {
    sendMessage: vi.fn(),
    broadcastMessage: vi.fn(),
    getUserProfile: vi.fn(),
};
const createTestUser = (overrides = {}) => ({
    id: (0, crypto_1.randomUUID)(),
    username: `testuser_${Date.now()}`,
    email: `test_${Date.now()}@example.com`,
    role: 'staff',
    lineAccountId: (0, crypto_1.randomUUID)(),
    permissions: ['view_dashboard'],
    createdAt: new Date(),
    updatedAt: new Date(),
    ...overrides,
});
exports.createTestUser = createTestUser;
const createTestOrder = (overrides = {}) => ({
    id: (0, crypto_1.randomUUID)(),
    odooOrderId: `ORD_${Date.now()}`,
    customerRef: `CUST_${Date.now()}`,
    status: 'pending',
    totalAmount: 1000.00,
    currency: 'THB',
    createdAt: new Date(),
    updatedAt: new Date(),
    ...overrides,
});
exports.createTestOrder = createTestOrder;
const createTestPaymentSlip = (overrides = {}) => ({
    id: (0, crypto_1.randomUUID)(),
    imageUrl: `https://example.com/slip_${Date.now()}.jpg`,
    amount: 1000.00,
    uploadedBy: (0, crypto_1.randomUUID)(),
    status: 'pending',
    createdAt: new Date(),
    ...overrides,
});
exports.createTestPaymentSlip = createTestPaymentSlip;
//# sourceMappingURL=setup.js.map