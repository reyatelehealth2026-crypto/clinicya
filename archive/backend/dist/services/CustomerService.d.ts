import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';
export interface CustomerFilters {
    search?: string;
    name?: string;
    reference?: string;
    partnerId?: string;
    lineConnected?: boolean;
    tier?: string;
    dateFrom?: Date;
    dateTo?: Date;
}
export interface PaginationOptions {
    page: number;
    limit: number;
    sort?: string;
    order?: 'asc' | 'desc';
}
export interface Customer {
    id: string;
    lineAccountId: string;
    lineUserId: string;
    displayName: string | null;
    realName: string | null;
    phone: string | null;
    email: string | null;
    totalOrders: number;
    totalSpent: number;
    availablePoints: number;
    tier: string | null;
    membershipLevel: string | null;
    lastOrderAt: Date | null;
    lastInteractionAt: Date | null;
    isBlocked: boolean;
    createdAt: Date;
    updatedAt: Date;
}
export interface CustomerProfile extends Customer {
    address: string | null;
    province: string | null;
    district: string | null;
    postalCode: string | null;
    birthday: Date | null;
    gender: string | null;
    notes: string | null;
    tags: string | null;
    customerScore: number;
    medicalConditions: string | null;
    drugAllergies: string | null;
    currentMedications: string | null;
    emergencyContact: string | null;
    bloodType: string | null;
}
export interface CustomerOrder {
    id: string;
    odooOrderId: string;
    status: string;
    totalAmount: number;
    currency: string;
    orderDate: Date | null;
    deliveryDate: Date | null;
    createdAt: Date;
}
export interface PaginatedCustomers {
    data: Customer[];
    meta: {
        page: number;
        limit: number;
        total: number;
        totalPages: number;
    };
}
export interface PaginatedOrders {
    data: CustomerOrder[];
    meta: {
        page: number;
        limit: number;
        total: number;
        totalPages: number;
    };
}
export declare class CustomerService extends BaseService {
    constructor(prisma: PrismaClient);
    searchCustomers(lineAccountId: string, filters?: CustomerFilters, pagination?: PaginationOptions): Promise<PaginatedCustomers>;
    getCustomerById(customerId: string, lineAccountId: string): Promise<CustomerProfile | null>;
    getCustomerOrders(customerId: string, lineAccountId: string, pagination?: PaginationOptions): Promise<PaginatedOrders>;
    updateLineConnection(customerId: string, lineAccountId: string, lineUserId: string | null): Promise<Customer>;
    getCustomerStatistics(lineAccountId: string, dateFrom?: Date, dateTo?: Date): Promise<{
        totalCustomers: number;
        newCustomers: number;
        activeCustomers: number;
        lineConnected: number;
        averageOrderValue: number;
        topTiers: Record<string, number>;
    }>;
}
//# sourceMappingURL=CustomerService.d.ts.map