"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OrderService = void 0;
const BaseService_1 = require("./BaseService");
class OrderService extends BaseService_1.BaseService {
    constructor(prisma) {
        super(prisma);
    }
    async getOrders(lineAccountId, filters = {}, pagination = { page: 1, limit: 20 }) {
        try {
            const { page, limit, sort = 'createdAt', order = 'desc' } = pagination;
            const skip = (page - 1) * limit;
            const where = {
                lineAccountId,
                ...(filters.status && filters.status.length > 0 && {
                    status: { in: filters.status }
                }),
                ...(filters.customerRef && {
                    customerRef: { contains: filters.customerRef }
                }),
                ...(filters.customerName && {
                    customerName: { contains: filters.customerName }
                }),
                ...(filters.dateFrom || filters.dateTo) && {
                    createdAt: {
                        ...(filters.dateFrom && { gte: filters.dateFrom }),
                        ...(filters.dateTo && { lte: filters.dateTo })
                    }
                },
                ...(filters.search && {
                    OR: [
                        { customerRef: { contains: filters.search } },
                        { customerName: { contains: filters.search } },
                        { odooOrderId: { contains: filters.search } },
                        { notes: { contains: filters.search } }
                    ]
                })
            };
            const total = await this.prisma.odooOrder.count({ where });
            const orders = await this.prisma.odooOrder.findMany({
                where,
                skip,
                take: limit,
                orderBy: { [sort]: order },
            });
            const ordersWithTimeline = await Promise.all(orders.map(async (order) => ({
                ...order,
                totalAmount: Number(order.totalAmount),
                timeline: await this.getOrderTimeline(order.id)
            })));
            return {
                data: ordersWithTimeline,
                meta: {
                    page,
                    limit,
                    total,
                    totalPages: Math.ceil(total / limit)
                }
            };
        }
        catch (error) {
            this.handleError(error, 'OrderService.getOrders');
        }
    }
    async getOrderById(orderId, lineAccountId) {
        try {
            const order = await this.prisma.odooOrder.findFirst({
                where: {
                    id: orderId,
                    lineAccountId
                }
            });
            if (!order) {
                return null;
            }
            const timeline = await this.getOrderTimeline(orderId);
            return {
                ...order,
                totalAmount: Number(order.totalAmount),
                timeline
            };
        }
        catch (error) {
            this.handleError(error, 'OrderService.getOrderById');
        }
    }
    async updateOrderStatus(orderId, lineAccountId, newStatus, notes, changedBy) {
        try {
            const currentOrder = await this.prisma.odooOrder.findFirst({
                where: {
                    id: orderId,
                    lineAccountId
                }
            });
            if (!currentOrder) {
                throw new Error('Order not found');
            }
            const previousStatus = currentOrder.status;
            const result = await this.prisma.$transaction(async (tx) => {
                const updatedOrder = await tx.odooOrder.update({
                    where: { id: orderId },
                    data: {
                        status: newStatus,
                        updatedAt: new Date(),
                        ...(notes && { notes })
                    }
                });
                await this.createTimelineEntry(tx, {
                    orderId,
                    status: newStatus,
                    previousStatus,
                    ...(notes && { notes }),
                    ...(changedBy && { changedBy }),
                    source: changedBy ? 'manual' : 'system'
                });
                return updatedOrder;
            });
            const timeline = await this.getOrderTimeline(orderId);
            return {
                ...result,
                totalAmount: Number(result.totalAmount),
                timeline
            };
        }
        catch (error) {
            this.handleError(error, 'OrderService.updateOrderStatus');
        }
    }
    async getOrderTimeline(orderId) {
        try {
            const auditLogs = await this.prisma.auditLog.findMany({
                where: {
                    resourceType: 'order',
                    resourceId: orderId
                },
                orderBy: {
                    createdAt: 'asc'
                }
            });
            return auditLogs.map(log => ({
                id: log.id,
                orderId,
                status: log.newValues?.status || 'unknown',
                previousStatus: log.oldValues?.status || null,
                notes: log.newValues?.notes || null,
                changedBy: log.userId,
                changedAt: log.createdAt,
                source: log.action.includes('webhook') ? 'webhook' :
                    log.action.includes('manual') ? 'manual' : 'system'
            }));
        }
        catch (error) {
            console.error('Error getting order timeline:', error);
            return [];
        }
    }
    async createTimelineEntry(tx, entry) {
        await tx.auditLog.create({
            data: {
                userId: entry.changedBy || 'system',
                action: `${entry.source}_status_update`,
                resourceType: 'order',
                resourceId: entry.orderId,
                ...(entry.previousStatus && { oldValues: { status: entry.previousStatus } }),
                newValues: {
                    status: entry.status,
                    ...(entry.notes && { notes: entry.notes })
                }
            }
        });
    }
    async getOrderStatistics(lineAccountId, dateFrom, dateTo) {
        try {
            const where = {
                lineAccountId,
                ...(dateFrom || dateTo) && {
                    createdAt: {
                        ...(dateFrom && { gte: dateFrom }),
                        ...(dateTo && { lte: dateTo })
                    }
                }
            };
            const orders = await this.prisma.odooOrder.findMany({
                where,
                select: {
                    status: true,
                    totalAmount: true,
                    customerName: true,
                    customerRef: true
                }
            });
            const totalOrders = orders.length;
            const totalValue = orders.reduce((sum, order) => sum + Number(order.totalAmount), 0);
            const averageOrderValue = totalOrders > 0 ? totalValue / totalOrders : 0;
            const statusBreakdown = orders.reduce((acc, order) => {
                acc[order.status] = (acc[order.status] || 0) + 1;
                return acc;
            }, {});
            const customerStats = orders.reduce((acc, order) => {
                const key = order.customerName || order.customerRef || 'Unknown';
                if (!acc[key]) {
                    acc[key] = { customerName: key, orderCount: 0, totalValue: 0 };
                }
                acc[key].orderCount += 1;
                acc[key].totalValue += Number(order.totalAmount);
                return acc;
            }, {});
            const topCustomers = Object.values(customerStats)
                .sort((a, b) => b.totalValue - a.totalValue)
                .slice(0, 10);
            return {
                totalOrders,
                totalValue,
                statusBreakdown,
                averageOrderValue,
                topCustomers
            };
        }
        catch (error) {
            this.handleError(error, 'OrderService.getOrderStatistics');
        }
    }
    async searchOrders(lineAccountId, searchQuery, filters = {}, pagination = { page: 1, limit: 20 }) {
        try {
            const enhancedFilters = {
                ...filters,
                search: searchQuery
            };
            return await this.getOrders(lineAccountId, enhancedFilters, pagination);
        }
        catch (error) {
            this.handleError(error, 'OrderService.searchOrders');
        }
    }
    async getOrdersByStatus(lineAccountId, status, pagination = { page: 1, limit: 20 }) {
        try {
            const filters = { status: [status] };
            return await this.getOrders(lineAccountId, filters, pagination);
        }
        catch (error) {
            this.handleError(error, 'OrderService.getOrdersByStatus');
        }
    }
    async getRecentOrders(lineAccountId, limit = 10) {
        try {
            const result = await this.getOrders(lineAccountId, {}, { page: 1, limit, sort: 'createdAt', order: 'desc' });
            return result.data;
        }
        catch (error) {
            this.handleError(error, 'OrderService.getRecentOrders');
        }
    }
}
exports.OrderService = OrderService;
//# sourceMappingURL=OrderService.js.map