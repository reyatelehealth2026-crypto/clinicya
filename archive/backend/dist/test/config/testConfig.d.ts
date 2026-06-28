export declare const TEST_CONFIG: {
    database: {
        testTimeout: number;
        connectionTimeout: number;
        maxConnections: number;
    };
    propertyTesting: {
        numRuns: number;
        timeout: number;
        verbose: boolean;
        seed: number;
    };
    performance: {
        apiResponseTime: number;
        databaseQueryTime: number;
        memoryUsage: number;
    };
    mocks: {
        redis: {
            defaultTTL: number;
            maxMemory: string;
        };
        odoo: {
            timeout: number;
            retryAttempts: number;
        };
        line: {
            timeout: number;
            retryAttempts: number;
        };
    };
    testData: {
        maxOrders: number;
        maxPaymentSlips: number;
        maxWebhooks: number;
        maxUsers: number;
    };
};
export declare const setupTestEnvironment: () => void;
export declare const testConstants: {
    TEST_USER_ID: string;
    TEST_LINE_ACCOUNT_ID: string;
    TEST_ORDER_ID: string;
    TEST_PAYMENT_SLIP_ID: string;
    TEST_DATE_START: Date;
    TEST_DATE_END: Date;
    TEST_AMOUNTS: {
        SMALL: number;
        MEDIUM: number;
        LARGE: number;
        VERY_LARGE: number;
    };
    TEST_CREDENTIALS: {
        VALID_PASSWORD: string;
        INVALID_PASSWORD: string;
        WEAK_PASSWORD: string;
    };
};
export declare const performanceHelpers: {
    measureExecutionTime: <T>(fn: () => Promise<T>) => Promise<{
        result: T;
        duration: number;
    }>;
    measureMemoryUsage: <T>(fn: () => T) => {
        result: T;
        memoryDelta: number;
    };
    assertPerformance: (duration: number, threshold: number, operation: string) => void;
};
export declare const cleanupHelpers: {
    cleanupDatabase: (prisma: any) => Promise<void>;
    cleanupRedis: (redis: any) => Promise<void>;
    resetMocks: () => void;
};
export declare const assertionHelpers: {
    assertValidUUID: (uuid: string) => void;
    assertValidDate: (date: any) => void;
    assertValidAmount: (amount: number) => void;
    assertValidPercentage: (percentage: number) => void;
    assertAPIResponse: (response: any) => void;
};
export default TEST_CONFIG;
//# sourceMappingURL=testConfig.d.ts.map