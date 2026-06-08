"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CustomerService = void 0;
const BaseService_1 = require("./BaseService");
class CustomerService extends BaseService_1.BaseService {
    constructor(prisma) {
        super(prisma);
    }
    async searchCustomers(lineAccountId, filters = {}, pagination = { page: 1, limit: 20 }) {
        try {
            const { page, limit, sort = 'updatedAt', order = 'desc' } = pagination;
            const skip = (page - 1) * limit;
            let whereConditions = ['line_account_id = ?'];
            const params = [lineAccountId];
            if (filters.search) {
                whereConditions.push('(display_name LIKE ? OR real_name LIKE ? OR phone LIKE ? OR email LIKE ? OR member_id LIKE ?)');
                const searchPattern = `%${filters.search}%`;
                params.push(searchPattern, searchPattern, searchPattern, searchPattern, searchPattern);
            }
            if (filters.name) {
                whereConditions.push('(display_name LIKE ? OR real_name LIKE ?)');
                const namePattern = `%${filters.name}%`;
                params.push(namePattern, namePattern);
            }
            if (filters.reference) {
                whereConditions.push('member_id LIKE ?');
                params.push(`%${filters.reference}%`);
            }
            if (filters.lineConnected !== undefined) {
                whereConditions.push('line_user_id IS NOT NULL');
            }
            if (filters.tier) {
                whereConditions.push('tier = ?');
                params.push(filters.tier);
            }
            if (filters.dateFrom) {
                whereConditions.push('created_at >= ?');
                params.push(filters.dateFrom);
            }
            if (filters.dateTo) {
                whereConditions.push('created_at <= ?');
                params.push(filters.dateTo);
            }
            const whereClause = whereConditions.join(' AND ');
            const countQuery = `SELECT COUNT(*) as total FROM users WHERE ${whereClause}`;
            const countResult = await this.prisma.$queryRawUnsafe(countQuery, ...params);
            const total = Number(countResult[0]?.total || 0);
            const orderByClause = `ORDER BY ${sort} ${order.toUpperCase()}`;
            const query = `
        SELECT 
          id,
          line_account_id as lineAccountId,
          line_user_id as lineUserId,
          display_name as displayName,
          real_name as realName,
          phone,
          email,
          total_orders as totalOrders,
          total_spent as totalSpent,
          available_points as availablePoints,
          tier,
          membership_level as membershipLevel,
          last_order_at as lastOrderAt,
          last_interaction as lastInteractionAt,
          is_blocked as isBlocked,
          created_at as createdAt,
          updated_at as updatedAt
        FROM users 
        WHERE ${whereClause}
        ${orderByClause}
        LIMIT ? OFFSET ?
      `;
            const customers = await this.prisma.$queryRawUnsafe(query, ...params, limit, skip);
            return {
                data: customers.map(c => ({
                    ...c,
                    id: String(c.id),
                    totalOrders: Number(c.totalOrders),
                    totalSpent: Number(c.totalSpent),
                    availablePoints: Number(c.availablePoints),
                    isBlocked: Boolean(c.isBlocked),
                })),
                meta: {
                    page,
                    limit,
                    total,
                    totalPages: Math.ceil(total / limit),
                },
            };
        }
        catch (error) {
            this.handleError(error, 'CustomerService.searchCustomers');
        }
    }
    async getCustomerById(customerId, lineAccountId) {
        try {
            const query = `
        SELECT 
          id,
          line_account_id as lineAccountId,
          line_user_id as lineUserId,
          display_name as displayName,
          real_name as realName,
          phone,
          email,
          address,
          province,
          district,
          postal_code as postalCode,
          birthday,
          gender,
          notes,
          tags,
          total_orders as totalOrders,
          total_spent as totalSpent,
          available_points as availablePoints,
          tier,
          membership_level as membershipLevel,
          customer_score as customerScore,
          medical_conditions as medicalConditions,
          drug_allergies as drugAllergies,
          current_medications as currentMedications,
          emergency_contact as emergencyContact,
          blood_type as bloodType,
          last_order_at as lastOrderAt,
          last_interaction as lastInteractionAt,
          is_blocked as isBlocked,
          created_at as createdAt,
          updated_at as updatedAt
        FROM users 
        WHERE id = ? AND line_account_id = ?
      `;
            const result = await this.prisma.$queryRawUnsafe(query, customerId, lineAccountId);
            if (result.length === 0) {
                return null;
            }
            const customer = result[0];
            return {
                ...customer,
                id: String(customer.id),
                totalOrders: Number(customer.totalOrders),
                totalSpent: Number(customer.totalSpent),
                availablePoints: Number(customer.availablePoints),
                customerScore: Number(customer.customerScore),
                isBlocked: Boolean(customer.isBlocked),
            };
        }
        catch (error) {
            this.handleError(error, 'CustomerService.getCustomerById');
        }
    }
    async getCustomerOrders(customerId, lineAccountId, pagination = { page: 1, limit: 20 }) {
        try {
            const { page, limit, sort = 'createdAt', order = 'desc' } = pagination;
            const skip = (page - 1) * limit;
            const customer = await this.getCustomerById(customerId, lineAccountId);
            if (!customer) {
                throw new Error('Customer not found');
            }
            const customerRefQuery = `
        SELECT member_id, display_name, real_name 
        FROM users 
        WHERE id = ?
      `;
            const customerData = await this.prisma.$queryRawUnsafe(customerRefQuery, customerId);
            if (customerData.length === 0) {
                return {
                    data: [],
                    meta: { page, limit, total: 0, totalPages: 0 },
                };
            }
            const customerRef = customerData[0].member_id;
            const customerName = customerData[0].real_name || customerData[0].display_name;
            const whereConditions = ['line_account_id = ?'];
            const params = [lineAccountId];
            if (customerRef) {
                whereConditions.push('(customer_ref = ? OR customer_name = ?)');
                params.push(customerRef, customerName);
            }
            else if (customerName) {
                whereConditions.push('customer_name = ?');
                params.push(customerName);
            }
            else {
                return {
                    data: [],
                    meta: { page, limit, total: 0, totalPages: 0 },
                };
            }
            const whereClause = whereConditions.join(' AND ');
            const countQuery = `SELECT COUNT(*) as total FROM odoo_orders WHERE ${whereClause}`;
            const countResult = await this.prisma.$queryRawUnsafe(countQuery, ...params);
            const total = Number(countResult[0]?.total || 0);
            const orderByClause = `ORDER BY ${sort} ${order.toUpperCase()}`;
            const query = `
        SELECT 
          id,
          odoo_order_id as odooOrderId,
          status,
          total_amount as totalAmount,
          currency,
          order_date as orderDate,
          delivery_date as deliveryDate,
          created_at as createdAt
        FROM odoo_orders 
        WHERE ${whereClause}
        ${orderByClause}
        LIMIT ? OFFSET ?
      `;
            const orders = await this.prisma.$queryRawUnsafe(query, ...params, limit, skip);
            return {
                data: orders.map(o => ({
                    ...o,
                    id: String(o.id),
                    totalAmount: Number(o.totalAmount),
                })),
                meta: {
                    page,
                    limit,
                    total,
                    totalPages: Math.ceil(total / limit),
                },
            };
        }
        catch (error) {
            this.handleError(error, 'CustomerService.getCustomerOrders');
        }
    }
    async updateLineConnection(customerId, lineAccountId, lineUserId) {
        try {
            const customer = await this.getCustomerById(customerId, lineAccountId);
            if (!customer) {
                throw new Error('Customer not found');
            }
            const updateQuery = `
        UPDATE users 
        SET line_user_id = ?, updated_at = NOW()
        WHERE id = ? AND line_account_id = ?
      `;
            await this.prisma.$executeRawUnsafe(updateQuery, lineUserId, customerId, lineAccountId);
            const updatedCustomer = await this.getCustomerById(customerId, lineAccountId);
            if (!updatedCustomer) {
                throw new Error('Failed to retrieve updated customer');
            }
            await this.prisma.auditLog.create({
                data: {
                    userId: 'system',
                    action: 'update_line_connection',
                    resourceType: 'customer',
                    resourceId: customerId,
                    oldValues: { lineUserId: customer.lineUserId },
                    newValues: { lineUserId },
                },
            });
            return updatedCustomer;
        }
        catch (error) {
            this.handleError(error, 'CustomerService.updateLineConnection');
        }
    }
    async getCustomerStatistics(lineAccountId, dateFrom, dateTo) {
        try {
            let whereConditions = ['line_account_id = ?'];
            const params = [lineAccountId];
            if (dateFrom) {
                whereConditions.push('created_at >= ?');
                params.push(dateFrom);
            }
            if (dateTo) {
                whereConditions.push('created_at <= ?');
                params.push(dateTo);
            }
            const whereClause = whereConditions.join(' AND ');
            const statsQuery = `
        SELECT 
          COUNT(*) as totalCustomers,
          SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as newCustomers,
          SUM(CASE WHEN last_interaction >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as activeCustomers,
          SUM(CASE WHEN line_user_id IS NOT NULL THEN 1 ELSE 0 END) as lineConnected,
          AVG(CASE WHEN total_orders > 0 THEN total_spent / total_orders ELSE 0 END) as averageOrderValue
        FROM users 
        WHERE ${whereClause}
      `;
            const statsResult = await this.prisma.$queryRawUnsafe(statsQuery, ...params);
            const stats = statsResult[0];
            const tierQuery = `
        SELECT tier, COUNT(*) as count
        FROM users 
        WHERE ${whereClause} AND tier IS NOT NULL
        GROUP BY tier
      `;
            const tierResult = await this.prisma.$queryRawUnsafe(tierQuery, ...params);
            const topTiers = tierResult.reduce((acc, row) => {
                acc[row.tier] = Number(row.count);
                return acc;
            }, {});
            return {
                totalCustomers: Number(stats.totalCustomers || 0),
                newCustomers: Number(stats.newCustomers || 0),
                activeCustomers: Number(stats.activeCustomers || 0),
                lineConnected: Number(stats.lineConnected || 0),
                averageOrderValue: Number(stats.averageOrderValue || 0),
                topTiers,
            };
        }
        catch (error) {
            this.handleError(error, 'CustomerService.getCustomerStatistics');
        }
    }
}
exports.CustomerService = CustomerService;
//# sourceMappingURL=CustomerService.js.map