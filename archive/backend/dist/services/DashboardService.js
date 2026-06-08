"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DashboardService = void 0;
const BaseService_1 = require("./BaseService");
class DashboardService extends BaseService_1.BaseService {
    constructor(prisma) {
        super(prisma);
    }
    async getOverviewMetrics(lineAccountId, dateFrom, dateTo) {
        try {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const actualDateFrom = dateFrom || today;
            const actualDateTo = dateTo || tomorrow;
            const cachedMetrics = await this.getCachedMetrics(lineAccountId, actualDateFrom);
            if (cachedMetrics) {
                return cachedMetrics;
            }
            const orderMetrics = await this.getOrderMetrics(lineAccountId, actualDateFrom, actualDateTo);
            const paymentMetrics = await this.getPaymentMetrics(lineAccountId, actualDateFrom, actualDateTo);
            const webhookMetrics = await this.getWebhookMetrics(lineAccountId, actualDateFrom, actualDateTo);
            const customerMetrics = await this.getCustomerMetrics(lineAccountId, actualDateFrom, actualDateTo);
            const metrics = {
                orders: orderMetrics,
                payments: paymentMetrics,
                webhooks: webhookMetrics,
                customers: customerMetrics,
                updatedAt: new Date(),
            };
            await this.cacheMetrics(lineAccountId, actualDateFrom, metrics);
            return metrics;
        }
        catch (error) {
            this.handleError(error, 'DashboardService.getOverviewMetrics');
        }
    }
    async getCachedMetrics(lineAccountId, dateKey) {
        try {
            const cached = await this.prisma.dashboardMetricsCache.findFirst({
                where: {
                    lineAccountId,
                    dateKey,
                    expiresAt: {
                        gt: new Date(),
                    },
                },
            });
            if (cached) {
                return cached.data;
            }
            return null;
        }
        catch (error) {
            console.error('Error retrieving cached metrics:', error);
            return null;
        }
    }
    async cacheMetrics(lineAccountId, dateKey, metrics) {
        try {
            const expiresAt = new Date();
            expiresAt.setMinutes(expiresAt.getMinutes() + 30);
            await this.prisma.dashboardMetricsCache.upsert({
                where: {
                    lineAccountId_metricType_dateKey: {
                        lineAccountId,
                        metricType: 'ORDERS',
                        dateKey,
                    },
                },
                update: {
                    data: metrics,
                    expiresAt,
                    updatedAt: new Date(),
                },
                create: {
                    lineAccountId,
                    metricType: 'ORDERS',
                    dateKey,
                    data: metrics,
                    expiresAt,
                },
            });
        }
        catch (error) {
            console.error('Error caching metrics:', error);
        }
    }
    async getOrderMetrics(lineAccountId, dateFrom, dateTo) {
        try {
            const todayOrders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lt: dateTo,
                    },
                },
            });
            const pendingOrders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    status: {
                        in: ['draft', 'pending', 'confirmed'],
                    },
                },
            });
            const completedOrders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    status: {
                        in: ['done', 'delivered', 'completed'],
                    },
                },
            });
            const todayCount = todayOrders.length;
            const todayTotal = todayOrders.reduce((sum, order) => sum + Number(order.totalAmount), 0);
            const pendingCount = pendingOrders.length;
            const completedCount = completedOrders.length;
            const averageOrderValue = todayCount > 0 ? todayTotal / todayCount : 0;
            return {
                todayCount,
                todayTotal,
                pendingCount,
                completedCount,
                averageOrderValue,
            };
        }
        catch (error) {
            console.error('Error calculating order metrics:', error);
            return {
                todayCount: 0,
                todayTotal: 0,
                pendingCount: 0,
                completedCount: 0,
                averageOrderValue: 0,
            };
        }
    }
    async getPaymentMetrics(lineAccountId, dateFrom, dateTo) {
        try {
            const todayProcessed = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    status: 'MATCHED',
                    processedAt: {
                        gte: dateFrom,
                        lt: dateTo,
                    },
                },
            });
            const pendingSlips = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    status: 'PENDING',
                },
            });
            const allProcessed = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    status: {
                        in: ['MATCHED', 'REJECTED'],
                    },
                },
            });
            const processedToday = todayProcessed.length;
            const pendingCount = pendingSlips.length;
            const totalAmount = todayProcessed.reduce((sum, slip) => sum + (Number(slip.amount) || 0), 0);
            const matchedCount = allProcessed.filter(slip => slip.status === 'MATCHED').length;
            const matchingRate = allProcessed.length > 0 ? (matchedCount / allProcessed.length) * 100 : 0;
            const processedWithTimes = todayProcessed.filter(slip => slip.processedAt);
            const averageProcessingTime = processedWithTimes.length > 0
                ? processedWithTimes.reduce((sum, slip) => {
                    const processingTime = slip.processedAt
                        ? (slip.processedAt.getTime() - slip.createdAt.getTime()) / (1000 * 60)
                        : 0;
                    return sum + processingTime;
                }, 0) / processedWithTimes.length
                : 0;
            return {
                pendingSlips: pendingCount,
                processedToday,
                matchingRate: Math.round(matchingRate * 10) / 10,
                totalAmount,
                averageProcessingTime: Math.round(averageProcessingTime),
            };
        }
        catch (error) {
            console.error('Error calculating payment metrics:', error);
            return {
                pendingSlips: 0,
                processedToday: 0,
                matchingRate: 0,
                totalAmount: 0,
                averageProcessingTime: 0,
            };
        }
    }
    async getWebhookMetrics(lineAccountId, dateFrom, dateTo) {
        try {
            const todayWebhooks = await this.prisma.odooWebhookLog.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lt: dateTo,
                    },
                },
            });
            const failedWebhooks = todayWebhooks.filter(webhook => webhook.status === 'FAILED');
            const processedWebhooks = todayWebhooks.filter(webhook => webhook.status === 'PROCESSED');
            const todayCount = todayWebhooks.length;
            const failedCount = failedWebhooks.length;
            const successRate = todayCount > 0
                ? (processedWebhooks.length / todayCount) * 100
                : 0;
            const processedWithTimes = processedWebhooks.filter(webhook => webhook.processedAt);
            const averageResponseTime = processedWithTimes.length > 0
                ? processedWithTimes.reduce((sum, webhook) => {
                    const responseTime = webhook.processedAt
                        ? (webhook.processedAt.getTime() - webhook.createdAt.getTime())
                        : 0;
                    return sum + responseTime;
                }, 0) / processedWithTimes.length
                : 0;
            return {
                todayCount,
                successRate: Math.round(successRate * 10) / 10,
                failedCount,
                averageResponseTime: Math.round(averageResponseTime),
            };
        }
        catch (error) {
            console.error('Error calculating webhook metrics:', error);
            return {
                todayCount: 0,
                successRate: 0,
                failedCount: 0,
                averageResponseTime: 0,
            };
        }
    }
    async getCustomerMetrics(lineAccountId, dateFrom, dateTo) {
        try {
            const activeFollowers = await this.prisma.accountFollower.findMany({
                where: {
                    lineAccount: {
                        id: parseInt(lineAccountId),
                    },
                    isFollowing: true,
                },
            });
            const newFollowersToday = await this.prisma.accountFollower.findMany({
                where: {
                    lineAccount: {
                        id: parseInt(lineAccountId),
                    },
                    followedAt: {
                        gte: dateFrom,
                        lt: dateTo,
                    },
                    isFollowing: true,
                },
            });
            const lineConnectedFollowers = activeFollowers.filter(follower => follower.userId !== null);
            const totalOrders = await this.prisma.odooOrder.count({
                where: {
                    lineAccountId,
                },
            });
            const averageOrdersPerCustomer = activeFollowers.length > 0
                ? totalOrders / activeFollowers.length
                : 0;
            return {
                totalActive: activeFollowers.length,
                newToday: newFollowersToday.length,
                lineConnected: lineConnectedFollowers.length,
                averageOrdersPerCustomer: Math.round(averageOrdersPerCustomer * 10) / 10,
            };
        }
        catch (error) {
            console.error('Error calculating customer metrics:', error);
            return {
                totalActive: 0,
                newToday: 0,
                lineConnected: 0,
                averageOrdersPerCustomer: 0,
            };
        }
    }
    async getDetailedMetrics(lineAccountId, metricType, dateFrom, dateTo) {
        try {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const actualDateFrom = dateFrom || new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
            const actualDateTo = dateTo || today;
            switch (metricType) {
                case 'orders':
                    return await this.getOrderTrends(lineAccountId, actualDateFrom, actualDateTo);
                case 'payments':
                    return await this.getPaymentTrends(lineAccountId, actualDateFrom, actualDateTo);
                case 'webhooks':
                    return await this.getWebhookTrends(lineAccountId, actualDateFrom, actualDateTo);
                case 'customers':
                    return await this.getCustomerTrends(lineAccountId, actualDateFrom, actualDateTo);
                default:
                    throw new Error(`Unknown metric type: ${metricType}`);
            }
        }
        catch (error) {
            this.handleError(error, 'DashboardService.getDetailedMetrics');
        }
    }
    async getChartData(lineAccountId, chartType, dateFrom, dateTo) {
        try {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const actualDateFrom = dateFrom || new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
            const actualDateTo = dateTo || today;
            switch (chartType) {
                case 'orderTrends':
                    return await this.getOrderChartData(lineAccountId, actualDateFrom, actualDateTo);
                case 'paymentTrends':
                    return await this.getPaymentChartData(lineAccountId, actualDateFrom, actualDateTo);
                case 'webhookStats':
                    return await this.getWebhookChartData(lineAccountId, actualDateFrom, actualDateTo);
                default:
                    throw new Error(`Unknown chart type: ${chartType}`);
            }
        }
        catch (error) {
            this.handleError(error, 'DashboardService.getChartData');
        }
    }
    async getOrderTrends(lineAccountId, dateFrom, dateTo) {
        try {
            const orders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                orderBy: {
                    createdAt: 'desc',
                },
                take: 100,
            });
            return {
                totalOrders: orders.length,
                totalValue: orders.reduce((sum, order) => sum + Number(order.totalAmount), 0),
                statusBreakdown: this.groupByStatus(orders),
                dailyTrends: this.groupByDate(orders, 'createdAt'),
            };
        }
        catch (error) {
            console.error('Error getting order trends:', error);
            return { totalOrders: 0, totalValue: 0, statusBreakdown: {}, dailyTrends: [] };
        }
    }
    async getPaymentTrends(lineAccountId, dateFrom, dateTo) {
        try {
            const payments = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                orderBy: {
                    createdAt: 'desc',
                },
                take: 100,
            });
            return {
                totalPayments: payments.length,
                totalAmount: payments.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0),
                statusBreakdown: this.groupByStatus(payments),
                dailyTrends: this.groupByDate(payments, 'createdAt'),
            };
        }
        catch (error) {
            console.error('Error getting payment trends:', error);
            return { totalPayments: 0, totalAmount: 0, statusBreakdown: {}, dailyTrends: [] };
        }
    }
    async getWebhookTrends(lineAccountId, dateFrom, dateTo) {
        try {
            const webhooks = await this.prisma.odooWebhookLog.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                orderBy: {
                    createdAt: 'desc',
                },
                take: 100,
            });
            return {
                totalWebhooks: webhooks.length,
                statusBreakdown: this.groupByStatus(webhooks),
                typeBreakdown: this.groupByField(webhooks, 'webhookType'),
                dailyTrends: this.groupByDate(webhooks, 'createdAt'),
            };
        }
        catch (error) {
            console.error('Error getting webhook trends:', error);
            return { totalWebhooks: 0, statusBreakdown: {}, typeBreakdown: {}, dailyTrends: [] };
        }
    }
    async getCustomerTrends(lineAccountId, dateFrom, dateTo) {
        try {
            const followers = await this.prisma.accountFollower.findMany({
                where: {
                    lineAccount: {
                        id: parseInt(lineAccountId),
                    },
                    followedAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                orderBy: {
                    followedAt: 'desc',
                },
                take: 100,
            });
            return {
                totalNewFollowers: followers.length,
                activeFollowers: followers.filter(f => f.isFollowing).length,
                dailyTrends: this.groupByDate(followers, 'followedAt'),
            };
        }
        catch (error) {
            console.error('Error getting customer trends:', error);
            return { totalNewFollowers: 0, activeFollowers: 0, dailyTrends: [] };
        }
    }
    async getOrderChartData(lineAccountId, dateFrom, dateTo) {
        try {
            const orders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                select: {
                    createdAt: true,
                    totalAmount: true,
                    status: true,
                },
            });
            return this.generateChartData(orders, 'createdAt', 'totalAmount');
        }
        catch (error) {
            console.error('Error getting order chart data:', error);
            return [];
        }
    }
    async getPaymentChartData(lineAccountId, dateFrom, dateTo) {
        try {
            const payments = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                select: {
                    createdAt: true,
                    amount: true,
                    status: true,
                },
            });
            return this.generateChartData(payments, 'createdAt', 'amount');
        }
        catch (error) {
            console.error('Error getting payment chart data:', error);
            return [];
        }
    }
    async getWebhookChartData(lineAccountId, dateFrom, dateTo) {
        try {
            const webhooks = await this.prisma.odooWebhookLog.findMany({
                where: {
                    lineAccountId,
                    createdAt: {
                        gte: dateFrom,
                        lte: dateTo,
                    },
                },
                select: {
                    createdAt: true,
                    status: true,
                    webhookType: true,
                },
            });
            return this.generateWebhookChartData(webhooks);
        }
        catch (error) {
            console.error('Error getting webhook chart data:', error);
            return [];
        }
    }
    groupByStatus(items) {
        return items.reduce((acc, item) => {
            const status = item.status || 'unknown';
            acc[status] = (acc[status] || 0) + 1;
            return acc;
        }, {});
    }
    groupByField(items, field) {
        return items.reduce((acc, item) => {
            const value = item[field] || 'unknown';
            acc[value] = (acc[value] || 0) + 1;
            return acc;
        }, {});
    }
    groupByDate(items, dateField) {
        const grouped = items.reduce((acc, item) => {
            const dateValue = item[dateField];
            if (!dateValue)
                return acc;
            const date = new Date(dateValue).toISOString().split('T')[0];
            if (!acc[date]) {
                acc[date] = { date, count: 0, value: 0 };
            }
            acc[date].count += 1;
            acc[date].value += Number(item.totalAmount || item.amount || 0);
            return acc;
        }, {});
        return Object.values(grouped).sort((a, b) => a.date.localeCompare(b.date));
    }
    generateChartData(items, dateField, valueField) {
        const grouped = items.reduce((acc, item) => {
            const dateValue = item[dateField];
            if (!dateValue)
                return acc;
            const date = new Date(dateValue).toISOString().split('T')[0];
            if (!acc[date]) {
                acc[date] = { date, value: 0, count: 0 };
            }
            acc[date].value += Number(item[valueField] || 0);
            acc[date].count += 1;
            return acc;
        }, {});
        return Object.values(grouped).sort((a, b) => a.date.localeCompare(b.date));
    }
    generateWebhookChartData(webhooks) {
        const grouped = webhooks.reduce((acc, webhook) => {
            const dateValue = webhook.createdAt;
            if (!dateValue)
                return acc;
            const date = new Date(dateValue).toISOString().split('T')[0];
            if (!acc[date]) {
                acc[date] = { date, success: 0, failed: 0, total: 0 };
            }
            acc[date].total += 1;
            if (webhook.status === 'PROCESSED') {
                acc[date].success += 1;
            }
            else if (webhook.status === 'FAILED') {
                acc[date].failed += 1;
            }
            return acc;
        }, {});
        return Object.values(grouped).sort((a, b) => a.date.localeCompare(b.date));
    }
}
exports.DashboardService = DashboardService;
//# sourceMappingURL=DashboardService.js.map