import { FastifyRequest, FastifyReply } from 'fastify';
import { z } from 'zod';
export declare const commonSchemas: {
    uuid: z.ZodString;
    email: z.ZodString;
    password: z.ZodString;
    username: z.ZodString;
    phoneNumber: z.ZodString;
    url: z.ZodString;
    ipAddress: z.ZodString;
    dateString: z.ZodString;
    positiveNumber: z.ZodNumber;
    nonEmptyString: z.ZodString;
    safeString: z.ZodEffects<z.ZodString, string, string>;
};
export declare const validationSchemas: {
    login: z.ZodObject<{
        username: z.ZodString;
        password: z.ZodString;
        lineAccountId: z.ZodString;
        rememberMe: z.ZodOptional<z.ZodBoolean>;
    }, "strip", z.ZodTypeAny, {
        username: string;
        lineAccountId: string;
        password: string;
        rememberMe?: boolean | undefined;
    }, {
        username: string;
        lineAccountId: string;
        password: string;
        rememberMe?: boolean | undefined;
    }>;
    refreshToken: z.ZodObject<{
        refreshToken: z.ZodString;
    }, "strip", z.ZodTypeAny, {
        refreshToken: string;
    }, {
        refreshToken: string;
    }>;
    dashboardMetrics: z.ZodObject<{
        dateFrom: z.ZodOptional<z.ZodString>;
        dateTo: z.ZodOptional<z.ZodString>;
        metricTypes: z.ZodOptional<z.ZodArray<z.ZodEnum<["orders", "payments", "webhooks", "customers"]>, "many">>;
        lineAccountId: z.ZodOptional<z.ZodString>;
    }, "strip", z.ZodTypeAny, {
        lineAccountId?: string | undefined;
        dateFrom?: string | undefined;
        dateTo?: string | undefined;
        metricTypes?: ("webhooks" | "orders" | "payments" | "customers")[] | undefined;
    }, {
        lineAccountId?: string | undefined;
        dateFrom?: string | undefined;
        dateTo?: string | undefined;
        metricTypes?: ("webhooks" | "orders" | "payments" | "customers")[] | undefined;
    }>;
    orderUpdate: z.ZodObject<{
        status: z.ZodEnum<["pending", "processing", "completed", "cancelled", "refunded"]>;
        notes: any;
        notifyCustomer: z.ZodDefault<z.ZodBoolean>;
        internalNotes: any;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        status?: unknown;
        notes?: unknown;
        notifyCustomer?: unknown;
        internalNotes?: unknown;
    }, {
        [x: string]: any;
        status?: unknown;
        notes?: unknown;
        notifyCustomer?: unknown;
        internalNotes?: unknown;
    }>;
    orderSearch: z.ZodObject<{
        query: any;
        status: z.ZodOptional<z.ZodArray<z.ZodString, "many">>;
        customerId: z.ZodOptional<z.ZodString>;
        dateFrom: z.ZodOptional<z.ZodString>;
        dateTo: z.ZodOptional<z.ZodString>;
        page: z.ZodDefault<z.ZodNumber>;
        limit: z.ZodDefault<z.ZodNumber>;
        sortBy: z.ZodDefault<z.ZodEnum<["created_at", "updated_at", "total_amount", "status"]>>;
        sortOrder: z.ZodDefault<z.ZodEnum<["asc", "desc"]>>;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        query?: unknown;
        status?: unknown;
        customerId?: unknown;
        dateFrom?: unknown;
        dateTo?: unknown;
        page?: unknown;
        limit?: unknown;
        sortBy?: unknown;
        sortOrder?: unknown;
    }, {
        [x: string]: any;
        query?: unknown;
        status?: unknown;
        customerId?: unknown;
        dateFrom?: unknown;
        dateTo?: unknown;
        page?: unknown;
        limit?: unknown;
        sortBy?: unknown;
        sortOrder?: unknown;
    }>;
    paymentSlipUpload: z.ZodObject<{
        orderId: z.ZodOptional<z.ZodString>;
        amount: z.ZodNumber;
        currency: z.ZodDefault<z.ZodString>;
        notes: any;
        bankAccount: any;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        orderId?: unknown;
        amount?: unknown;
        currency?: unknown;
        notes?: unknown;
        bankAccount?: unknown;
    }, {
        [x: string]: any;
        orderId?: unknown;
        amount?: unknown;
        currency?: unknown;
        notes?: unknown;
        bankAccount?: unknown;
    }>;
    paymentMatch: z.ZodObject<{
        slipId: z.ZodString;
        orderId: z.ZodString;
        matchType: z.ZodDefault<z.ZodEnum<["automatic", "manual"]>>;
        confidence: z.ZodOptional<z.ZodNumber>;
        notes: any;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        slipId?: unknown;
        orderId?: unknown;
        matchType?: unknown;
        confidence?: unknown;
        notes?: unknown;
    }, {
        [x: string]: any;
        slipId?: unknown;
        orderId?: unknown;
        matchType?: unknown;
        confidence?: unknown;
        notes?: unknown;
    }>;
    webhookRetry: z.ZodObject<{
        webhookId: z.ZodString;
        reason: any;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        webhookId?: unknown;
        reason?: unknown;
    }, {
        [x: string]: any;
        webhookId?: unknown;
        reason?: unknown;
    }>;
    customerSearch: z.ZodObject<{
        query: any;
        customerRef: any;
        partnerId: z.ZodOptional<z.ZodString>;
        email: z.ZodOptional<z.ZodString>;
        phone: z.ZodOptional<z.ZodString>;
        page: z.ZodDefault<z.ZodNumber>;
        limit: z.ZodDefault<z.ZodNumber>;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        query?: unknown;
        customerRef?: unknown;
        partnerId?: unknown;
        email?: unknown;
        phone?: unknown;
        page?: unknown;
        limit?: unknown;
    }, {
        [x: string]: any;
        query?: unknown;
        customerRef?: unknown;
        partnerId?: unknown;
        email?: unknown;
        phone?: unknown;
        page?: unknown;
        limit?: unknown;
    }>;
    auditLogQuery: z.ZodObject<{
        userId: z.ZodOptional<z.ZodString>;
        action: any;
        resourceType: any;
        resourceId: z.ZodOptional<z.ZodString>;
        dateFrom: z.ZodOptional<z.ZodString>;
        dateTo: z.ZodOptional<z.ZodString>;
        success: z.ZodOptional<z.ZodBoolean>;
        page: z.ZodDefault<z.ZodNumber>;
        limit: z.ZodDefault<z.ZodNumber>;
    }, "strip", z.ZodTypeAny, {
        [x: string]: any;
        userId?: unknown;
        action?: unknown;
        resourceType?: unknown;
        resourceId?: unknown;
        dateFrom?: unknown;
        dateTo?: unknown;
        success?: unknown;
        page?: unknown;
        limit?: unknown;
    }, {
        [x: string]: any;
        userId?: unknown;
        action?: unknown;
        resourceType?: unknown;
        resourceId?: unknown;
        dateFrom?: unknown;
        dateTo?: unknown;
        success?: unknown;
        page?: unknown;
        limit?: unknown;
    }>;
};
export declare const contentSecurityPolicy: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare class InputSanitizer {
    static sanitizeHtml(input: string): string;
    static sanitizeSql(input: string): string;
    static sanitizeFileName(fileName: string): string;
    static sanitizeJson(input: any): any;
}
export declare const sanitizeRequest: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const validateFileUpload: (allowedTypes?: string[], maxSize?: number) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const detectSqlInjection: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const limitRequestSize: (maxSize?: number) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=security.d.ts.map