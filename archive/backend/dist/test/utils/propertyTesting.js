"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.performanceProperties = exports.generators = exports.properties = exports.propertyTestConfig = exports.arbitraries = void 0;
const fc = __importStar(require("fast-check"));
exports.arbitraries = {
    userId: () => fc.uuid(),
    username: () => fc.string({ minLength: 3, maxLength: 50 }).filter(s => /^[a-zA-Z0-9_]+$/.test(s)),
    email: () => fc.emailAddress(),
    userRole: () => fc.constantFrom('super_admin', 'admin', 'pharmacist', 'staff'),
    orderId: () => fc.uuid(),
    odooOrderId: () => fc.string({ minLength: 5, maxLength: 20 }).map(s => `ORD_${s}`),
    customerRef: () => fc.string({ minLength: 5, maxLength: 20 }).map(s => `CUST_${s}`),
    orderStatus: () => fc.constantFrom('pending', 'processing', 'completed', 'cancelled'),
    currency: () => fc.constantFrom('THB', 'USD', 'EUR'),
    amount: () => fc.float({ min: 0.01, max: 999999.99, noNaN: true }),
    paymentSlipId: () => fc.uuid(),
    imageUrl: () => fc.webUrl().filter(url => url.endsWith('.jpg') || url.endsWith('.png')),
    slipStatus: () => fc.constantFrom('pending', 'matched', 'rejected'),
    pastDate: () => fc.date({ max: new Date() }),
    futureDate: () => fc.date({ min: new Date() }),
    dateRange: () => fc.tuple(fc.date(), fc.date()).map(([d1, d2]) => {
        const start = d1 < d2 ? d1 : d2;
        const end = d1 < d2 ? d2 : d1;
        return { start, end };
    }),
    dashboardMetrics: () => fc.record({
        orders: fc.record({
            todayCount: fc.nat({ max: 10000 }),
            todayTotal: fc.float({ min: 0, max: 1000000, noNaN: true }),
            pendingCount: fc.nat({ max: 1000 }),
            completedCount: fc.nat({ max: 10000 }),
            averageOrderValue: fc.float({ min: 0, max: 10000, noNaN: true }),
        }),
        payments: fc.record({
            pendingSlips: fc.nat({ max: 1000 }),
            processedToday: fc.nat({ max: 1000 }),
            matchingRate: fc.float({ min: 0, max: 1, noNaN: true }),
            totalAmount: fc.float({ min: 0, max: 1000000, noNaN: true }),
        }),
        webhooks: fc.record({
            totalEvents: fc.nat({ max: 100000 }),
            successRate: fc.float({ min: 0, max: 1, noNaN: true }),
            failedEvents: fc.nat({ max: 1000 }),
            averageResponseTime: fc.float({ min: 0, max: 5000, noNaN: true }),
        }),
    }),
    jwtPayload: () => fc.record({
        userId: fc.uuid(),
        role: fc.constantFrom('super_admin', 'admin', 'pharmacist', 'staff'),
        lineAccountId: fc.uuid(),
        permissions: fc.array(fc.constantFrom('view_dashboard', 'manage_orders', 'process_payments', 'manage_webhooks', 'admin_access'), { minLength: 1, maxLength: 5 }),
        iat: fc.nat(),
        exp: fc.nat(),
    }),
    paginationParams: () => fc.record({
        page: fc.nat({ min: 1, max: 1000 }),
        limit: fc.nat({ min: 1, max: 100 }),
        sort: fc.constantFrom('createdAt', 'updatedAt', 'amount', 'status'),
        order: fc.constantFrom('asc', 'desc'),
    }),
    filterParams: () => fc.record({
        dateFrom: fc.option(fc.date()),
        dateTo: fc.option(fc.date()),
        status: fc.option(fc.array(fc.constantFrom('pending', 'processing', 'completed', 'cancelled'))),
        search: fc.option(fc.string({ minLength: 1, maxLength: 100 })),
        customerId: fc.option(fc.uuid()),
    }),
};
exports.propertyTestConfig = {
    numRuns: 100,
    timeout: 5000,
    verbose: false,
};
exports.properties = {
    idempotent: (fn, arbitrary) => fc.property(arbitrary, (x) => {
        const result1 = fn(x);
        const result2 = fn(result1);
        return JSON.stringify(result1) === JSON.stringify(result2);
    }),
    commutative: (fn, arbitrary) => fc.property(arbitrary, arbitrary, (x, y) => {
        const result1 = fn(x, y);
        const result2 = fn(y, x);
        return JSON.stringify(result1) === JSON.stringify(result2);
    }),
    associative: (fn, arbitrary) => fc.property(arbitrary, arbitrary, arbitrary, (x, y, z) => {
        const result1 = fn(fn(x, y), z);
        const result2 = fn(x, fn(y, z));
        return JSON.stringify(result1) === JSON.stringify(result2);
    }),
    invariant: (predicate, arbitrary) => fc.property(arbitrary, predicate),
    roundTrip: (encode, decode, arbitrary) => fc.property(arbitrary, (x) => {
        const encoded = encode(x);
        const decoded = decode(encoded);
        return JSON.stringify(x) === JSON.stringify(decoded);
    }),
};
exports.generators = {
    validDashboardFilters: () => fc.record({
        dateRange: exports.arbitraries.dateRange(),
        orderStatuses: fc.array(exports.arbitraries.orderStatus(), { minLength: 0, maxLength: 4 }),
        paymentStatuses: fc.array(exports.arbitraries.slipStatus(), { minLength: 0, maxLength: 3 }),
        minAmount: fc.option(fc.float({ min: 0, max: 1000, noNaN: true })),
        maxAmount: fc.option(fc.float({ min: 1000, max: 1000000, noNaN: true })),
    }),
    apiResponse: (dataArbitrary) => fc.record({
        success: fc.boolean(),
        data: fc.option(dataArbitrary),
        error: fc.option(fc.record({
            code: fc.string({ minLength: 5, maxLength: 50 }),
            message: fc.string({ minLength: 10, maxLength: 200 }),
            details: fc.option(fc.object()),
        })),
        meta: fc.option(fc.record({
            page: fc.nat({ min: 1 }),
            limit: fc.nat({ min: 1, max: 100 }),
            total: fc.nat(),
            totalPages: fc.nat({ min: 1 }),
        })),
    }),
    webhookPayload: () => fc.record({
        id: fc.uuid(),
        type: fc.constantFrom('order.created', 'order.updated', 'payment.processed', 'invoice.generated'),
        timestamp: fc.date(),
        data: fc.object(),
        signature: fc.string({ minLength: 64, maxLength: 64 }),
    }),
};
exports.performanceProperties = {
    responseTimeUnder: (threshold) => (fn) => async () => {
        const start = Date.now();
        await fn();
        const duration = Date.now() - start;
        return duration < threshold;
    },
    memoryUsageUnder: (threshold) => (fn) => () => {
        const before = process.memoryUsage().heapUsed;
        fn();
        const after = process.memoryUsage().heapUsed;
        const increase = after - before;
        return increase < threshold;
    },
};
exports.default = {
    arbitraries: exports.arbitraries,
    properties: exports.properties,
    generators: exports.generators,
    performanceProperties: exports.performanceProperties,
    propertyTestConfig: exports.propertyTestConfig,
};
//# sourceMappingURL=propertyTesting.js.map