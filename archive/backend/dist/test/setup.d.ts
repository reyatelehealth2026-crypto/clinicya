import { PrismaClient } from '@prisma/client';
declare let prisma: PrismaClient;
export { prisma };
export declare const mockRedis: {
    get: any;
    set: any;
    del: any;
    exists: any;
    expire: any;
    flushall: any;
};
export declare const mockOdooService: {
    authenticate: any;
    getOrders: any;
    getCustomers: any;
    updateOrderStatus: any;
};
export declare const mockLineAPI: {
    sendMessage: any;
    broadcastMessage: any;
    getUserProfile: any;
};
export declare const createTestUser: (overrides?: {}) => {
    id: `${string}-${string}-${string}-${string}-${string}`;
    username: string;
    email: string;
    role: string;
    lineAccountId: `${string}-${string}-${string}-${string}-${string}`;
    permissions: string[];
    createdAt: Date;
    updatedAt: Date;
};
export declare const createTestOrder: (overrides?: {}) => {
    id: `${string}-${string}-${string}-${string}-${string}`;
    odooOrderId: string;
    customerRef: string;
    status: string;
    totalAmount: number;
    currency: string;
    createdAt: Date;
    updatedAt: Date;
};
export declare const createTestPaymentSlip: (overrides?: {}) => {
    id: `${string}-${string}-${string}-${string}-${string}`;
    imageUrl: string;
    amount: number;
    uploadedBy: `${string}-${string}-${string}-${string}-${string}`;
    status: string;
    createdAt: Date;
};
//# sourceMappingURL=setup.d.ts.map