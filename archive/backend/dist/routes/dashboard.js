"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = dashboardRoutes;
const zod_1 = require("zod");
const auth_1 = require("@/middleware/auth");
const validation_1 = require("@/middleware/validation");
const DashboardService_1 = require("@/services/DashboardService");
const client_1 = require("@prisma/client");
const dashboardQuerySchema = zod_1.z.object({
    dateFrom: zod_1.z.string().optional(),
    dateTo: zod_1.z.string().optional(),
    lineAccountId: zod_1.z.string().optional(),
});
const metricsQuerySchema = zod_1.z.object({
    dateFrom: zod_1.z.string().optional(),
    dateTo: zod_1.z.string().optional(),
    lineAccountId: zod_1.z.string().optional(),
    metricType: zod_1.z.enum(['orders', 'payments', 'webhooks', 'customers']).optional(),
});
const chartsQuerySchema = zod_1.z.object({
    dateFrom: zod_1.z.string().optional(),
    dateTo: zod_1.z.string().optional(),
    lineAccountId: zod_1.z.string().optional(),
    chartType: zod_1.z.enum(['orderTrends', 'paymentTrends', 'webhookStats']).optional(),
});
async function dashboardRoutes(fastify) {
    const prisma = new client_1.PrismaClient();
    const dashboardService = new DashboardService_1.DashboardService(prisma);
    fastify.get('/overview', {
        preHandler: [auth_1.authenticate, (0, validation_1.validateQuery)(dashboardQuerySchema)],
        schema: {
            tags: ['Dashboard'],
            summary: 'Get dashboard overview metrics',
            querystring: {
                type: 'object',
                properties: {
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                    lineAccountId: { type: 'string' },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { dateFrom, dateTo, lineAccountId } = request.query;
            const user = request.user;
            const accountId = lineAccountId || user?.lineAccountId || '1';
            const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
            const parsedDateTo = dateTo ? new Date(dateTo) : undefined;
            const metrics = await dashboardService.getOverviewMetrics(accountId, parsedDateFrom, parsedDateTo);
            return reply.send({
                success: true,
                data: metrics,
            });
        }
        catch (error) {
            console.error('Dashboard overview error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'DASHBOARD_ERROR',
                    message: 'Failed to retrieve dashboard metrics',
                },
            });
        }
    });
    fastify.get('/metrics', {
        preHandler: [auth_1.authenticate, (0, validation_1.validateQuery)(metricsQuerySchema)],
        schema: {
            tags: ['Dashboard'],
            summary: 'Get detailed dashboard metrics',
            querystring: {
                type: 'object',
                properties: {
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                    lineAccountId: { type: 'string' },
                    metricType: {
                        type: 'string',
                        enum: ['orders', 'payments', 'webhooks', 'customers']
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { dateFrom, dateTo, lineAccountId, metricType } = request.query;
            const user = request.user;
            const accountId = lineAccountId || user?.lineAccountId || '1';
            const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
            const parsedDateTo = dateTo ? new Date(dateTo) : undefined;
            if (metricType) {
                const metrics = await dashboardService.getDetailedMetrics(accountId, metricType, parsedDateFrom, parsedDateTo);
                return reply.send({
                    success: true,
                    data: { [metricType]: metrics },
                });
            }
            else {
                const [orderMetrics, paymentMetrics, webhookMetrics, customerMetrics] = await Promise.all([
                    dashboardService.getDetailedMetrics(accountId, 'orders', parsedDateFrom, parsedDateTo),
                    dashboardService.getDetailedMetrics(accountId, 'payments', parsedDateFrom, parsedDateTo),
                    dashboardService.getDetailedMetrics(accountId, 'webhooks', parsedDateFrom, parsedDateTo),
                    dashboardService.getDetailedMetrics(accountId, 'customers', parsedDateFrom, parsedDateTo),
                ]);
                return reply.send({
                    success: true,
                    data: {
                        orderMetrics,
                        paymentMetrics,
                        webhookMetrics,
                        customerMetrics,
                    },
                });
            }
        }
        catch (error) {
            console.error('Dashboard metrics error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'METRICS_ERROR',
                    message: 'Failed to retrieve detailed metrics',
                },
            });
        }
    });
    fastify.get('/charts', {
        preHandler: [auth_1.authenticate, (0, validation_1.validateQuery)(chartsQuerySchema)],
        schema: {
            tags: ['Dashboard'],
            summary: 'Get chart data for visualizations',
            querystring: {
                type: 'object',
                properties: {
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                    lineAccountId: { type: 'string' },
                    chartType: {
                        type: 'string',
                        enum: ['orderTrends', 'paymentTrends', 'webhookStats']
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const { dateFrom, dateTo, lineAccountId, chartType } = request.query;
            const user = request.user;
            const accountId = lineAccountId || user?.lineAccountId || '1';
            const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
            const parsedDateTo = dateTo ? new Date(dateTo) : undefined;
            if (chartType) {
                const chartData = await dashboardService.getChartData(accountId, chartType, parsedDateFrom, parsedDateTo);
                return reply.send({
                    success: true,
                    data: { [chartType]: chartData },
                });
            }
            else {
                const [orderTrends, paymentTrends, webhookStats] = await Promise.all([
                    dashboardService.getChartData(accountId, 'orderTrends', parsedDateFrom, parsedDateTo),
                    dashboardService.getChartData(accountId, 'paymentTrends', parsedDateFrom, parsedDateTo),
                    dashboardService.getChartData(accountId, 'webhookStats', parsedDateFrom, parsedDateTo),
                ]);
                return reply.send({
                    success: true,
                    data: {
                        orderTrends,
                        paymentTrends,
                        webhookStats,
                    },
                });
            }
        }
        catch (error) {
            console.error('Dashboard charts error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'CHARTS_ERROR',
                    message: 'Failed to retrieve chart data',
                },
            });
        }
    });
}
//# sourceMappingURL=dashboard.js.map