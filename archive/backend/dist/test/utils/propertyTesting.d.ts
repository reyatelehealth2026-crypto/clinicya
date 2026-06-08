import * as fc from 'fast-check';
export declare const arbitraries: {
    userId: () => fc.Arbitrary<string>;
    username: () => fc.Arbitrary<string>;
    email: () => fc.Arbitrary<string>;
    userRole: () => fc.Arbitrary<string>;
    orderId: () => fc.Arbitrary<string>;
    odooOrderId: () => fc.Arbitrary<string>;
    customerRef: () => fc.Arbitrary<string>;
    orderStatus: () => fc.Arbitrary<string>;
    currency: () => fc.Arbitrary<string>;
    amount: () => fc.Arbitrary<number>;
    paymentSlipId: () => fc.Arbitrary<string>;
    imageUrl: () => fc.Arbitrary<string>;
    slipStatus: () => fc.Arbitrary<string>;
    pastDate: () => fc.Arbitrary<Date>;
    futureDate: () => fc.Arbitrary<Date>;
    dateRange: () => fc.Arbitrary<{
        start: Date;
        end: Date;
    }>;
    dashboardMetrics: () => fc.Arbitrary<{
        orders: {
            todayCount: number;
            todayTotal: number;
            pendingCount: number;
            completedCount: number;
            averageOrderValue: number;
        };
        payments: {
            pendingSlips: number;
            processedToday: number;
            matchingRate: number;
            totalAmount: number;
        };
        webhooks: {
            totalEvents: number;
            successRate: number;
            failedEvents: number;
            averageResponseTime: number;
        };
    }>;
    jwtPayload: () => fc.Arbitrary<{
        userId: string;
        role: string;
        lineAccountId: string;
        permissions: string[];
        iat: number;
        exp: number;
    }>;
    paginationParams: () => fc.Arbitrary<{
        page: number;
        limit: number;
        sort: string;
        order: string;
    }>;
    filterParams: () => fc.Arbitrary<{
        dateFrom: Date | null;
        dateTo: Date | null;
        status: string[] | null;
        search: string | null;
        customerId: string | null;
    }>;
};
export declare const propertyTestConfig: {
    numRuns: number;
    timeout: number;
    verbose: boolean;
};
export declare const properties: {
    idempotent: <T>(fn: (x: T) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
    commutative: <T, R>(fn: (x: T, y: T) => R, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T, y: T]>;
    associative: <T>(fn: (x: T, y: T) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T, y: T, z: T]>;
    invariant: <T>(predicate: (x: T) => boolean, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
    roundTrip: <T, U>(encode: (x: T) => U, decode: (x: U) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
};
export declare const generators: {
    validDashboardFilters: () => fc.Arbitrary<{
        dateRange: {
            start: Date;
            end: Date;
        };
        orderStatuses: string[];
        paymentStatuses: string[];
        minAmount: number | null;
        maxAmount: number | null;
    }>;
    apiResponse: <T>(dataArbitrary: fc.Arbitrary<T>) => fc.Arbitrary<{
        success: boolean;
        data: T | null;
        error: {
            code: string;
            message: string;
            details: Record<string, unknown> | null;
        } | null;
        meta: {
            page: number;
            limit: number;
            total: number;
            totalPages: number;
        } | null;
    }>;
    webhookPayload: () => fc.Arbitrary<{
        id: string;
        type: string;
        timestamp: Date;
        data: Record<string, unknown>;
        signature: string;
    }>;
};
export declare const performanceProperties: {
    responseTimeUnder: (threshold: number) => (fn: () => Promise<any>) => () => Promise<boolean>;
    memoryUsageUnder: (threshold: number) => (fn: () => any) => () => boolean;
};
declare const _default: {
    arbitraries: {
        userId: () => fc.Arbitrary<string>;
        username: () => fc.Arbitrary<string>;
        email: () => fc.Arbitrary<string>;
        userRole: () => fc.Arbitrary<string>;
        orderId: () => fc.Arbitrary<string>;
        odooOrderId: () => fc.Arbitrary<string>;
        customerRef: () => fc.Arbitrary<string>;
        orderStatus: () => fc.Arbitrary<string>;
        currency: () => fc.Arbitrary<string>;
        amount: () => fc.Arbitrary<number>;
        paymentSlipId: () => fc.Arbitrary<string>;
        imageUrl: () => fc.Arbitrary<string>;
        slipStatus: () => fc.Arbitrary<string>;
        pastDate: () => fc.Arbitrary<Date>;
        futureDate: () => fc.Arbitrary<Date>;
        dateRange: () => fc.Arbitrary<{
            start: Date;
            end: Date;
        }>;
        dashboardMetrics: () => fc.Arbitrary<{
            orders: {
                todayCount: number;
                todayTotal: number;
                pendingCount: number;
                completedCount: number;
                averageOrderValue: number;
            };
            payments: {
                pendingSlips: number;
                processedToday: number;
                matchingRate: number;
                totalAmount: number;
            };
            webhooks: {
                totalEvents: number;
                successRate: number;
                failedEvents: number;
                averageResponseTime: number;
            };
        }>;
        jwtPayload: () => fc.Arbitrary<{
            userId: string;
            role: string;
            lineAccountId: string;
            permissions: string[];
            iat: number;
            exp: number;
        }>;
        paginationParams: () => fc.Arbitrary<{
            page: number;
            limit: number;
            sort: string;
            order: string;
        }>;
        filterParams: () => fc.Arbitrary<{
            dateFrom: Date | null;
            dateTo: Date | null;
            status: string[] | null;
            search: string | null;
            customerId: string | null;
        }>;
    };
    properties: {
        idempotent: <T>(fn: (x: T) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
        commutative: <T, R>(fn: (x: T, y: T) => R, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T, y: T]>;
        associative: <T>(fn: (x: T, y: T) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T, y: T, z: T]>;
        invariant: <T>(predicate: (x: T) => boolean, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
        roundTrip: <T, U>(encode: (x: T) => U, decode: (x: U) => T, arbitrary: fc.Arbitrary<T>) => fc.IPropertyWithHooks<[x: T]>;
    };
    generators: {
        validDashboardFilters: () => fc.Arbitrary<{
            dateRange: {
                start: Date;
                end: Date;
            };
            orderStatuses: string[];
            paymentStatuses: string[];
            minAmount: number | null;
            maxAmount: number | null;
        }>;
        apiResponse: <T>(dataArbitrary: fc.Arbitrary<T>) => fc.Arbitrary<{
            success: boolean;
            data: T | null;
            error: {
                code: string;
                message: string;
                details: Record<string, unknown> | null;
            } | null;
            meta: {
                page: number;
                limit: number;
                total: number;
                totalPages: number;
            } | null;
        }>;
        webhookPayload: () => fc.Arbitrary<{
            id: string;
            type: string;
            timestamp: Date;
            data: Record<string, unknown>;
            signature: string;
        }>;
    };
    performanceProperties: {
        responseTimeUnder: (threshold: number) => (fn: () => Promise<any>) => () => Promise<boolean>;
        memoryUsageUnder: (threshold: number) => (fn: () => any) => () => boolean;
    };
    propertyTestConfig: {
        numRuns: number;
        timeout: number;
        verbose: boolean;
    };
};
export default _default;
//# sourceMappingURL=propertyTesting.d.ts.map