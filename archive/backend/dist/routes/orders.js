"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = orderRoutes;
const zod_1 = require("zod");
const auth_1 = require("@/middleware/auth");
const validation_1 = require("@/middleware/validation");
const OrderService_1 = require("@/services/OrderService");
const client_1 = require("@prisma/client");
const orderListQuerySchema = zod_1.z.object({
    page: zod_1.z.string().transform(Number).optional().default('1'),
    limit: zod_1.z.string().transform(Number).optional().default('20'),
    sort: zod_1.z.string().optional().default('createdAt'),
    order: zod_1.z.enum(['asc', 'desc']).optional().default('desc'),
    status: zod_1.z.string().optional(),
    customerRef: zod_1.z.string().optional(),
    customerName: zod_1.z.string().optional(),
    dateFrom: zod_1.z.string().optional(),
    dateTo: zod_1.z.string().optional(),
    search: zod_1.z.string().optional(),
    lineAccountId: zod_1.z.string().optional(),
});
async function orderRoutes(fastify) {
    const prisma = new client_1.PrismaClient();
    const orderService = new OrderService_1.OrderService(prisma);
    fastify.get('/', {
        preHandler: [auth_1.authenticate, (0, validation_1.validateQuery)(orderListQuerySchema)],
        schema: {
            tags: ['Orders'],
            summary: 'List orders with pagination and filtering',
            querystring: {
                type: 'object',
                properties: {
                    page: { type: 'number', minimum: 1, default: 1 },
                    limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
                    sort: { type: 'string', default: 'createdAt' },
                    order: { type: 'string', enum: ['asc', 'desc'], default: 'desc' },
                    status: { type: 'string' },
                    customerRef: { type: 'string' },
                    customerName: { type: 'string' },
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                    search: { type: 'string' },
                    lineAccountId: { type: 'string' },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                data: {
                                    type: 'array',
                                    items: {
                                        type: 'object',
                                        properties: {
                                            id: { type: 'string' },
                                            odooOrderId: { type: 'string' },
                                            customerRef: { type: 'string' },
                                            customerName: { type: 'string' },
                                            status: { type: 'string' },
                                            totalAmount: { type: 'number' },
                                            currency: { type: 'string' },
                                            orderDate: { type: 'string', format: 'date-time' },
                                            createdAt: { type: 'string', format: 'date-time' },
                                            timeline: {
                                                type: 'array',
                                                items: {
                                                    type: 'object',
                                                    properties: {
                                                        id: { type: 'string' },
                                                        status: { type: 'string' },
                                                        changedAt: { type: 'string', format: 'date-time' },
                                                        source: { type: 'string' }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                },
                                meta: {
                                    type: 'object',
                                    properties: {
                                        page: { type: 'number' },
                                        limit: { type: 'number' },
                                        total: { type: 'number' },
                                        totalPages: { type: 'number' }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const query = request.query;
            const user = request.user;
            const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';
            const filters = {
                ...(query.status && { status: query.status.split(',') }),
                ...(query.customerRef && { customerRef: query.customerRef }),
                ...(query.customerName && { customerName: query.customerName }),
                ...(query.dateFrom && { dateFrom: new Date(query.dateFrom) }),
                ...(query.dateTo && { dateTo: new Date(query.dateTo) }),
                ...(query.search && { search: query.search }),
            };
            const pagination = {
                page: query.page,
                limit: Math.min(query.limit, 100),
                sort: query.sort,
                order: query.order,
            };
            const result = await orderService.getOrders(lineAccountId, filters, pagination);
            return reply.send({
                success: true,
                data: result,
            });
        }
        catch (error) {
            console.error('Orders list error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDERS_LIST_ERROR',
                    message: 'Failed to retrieve orders',
                },
            });
        }
    });
    fastify.get('/:id', {
        preHandler: [auth_1.authenticate],
        schema: {
            tags: ['Orders'],
            summary: 'Get specific order details with timeline',
            params: {
                type: 'object',
                properties: {
                    id: { type: 'string' }
                },
                required: ['id']
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                id: { type: 'string' },
                                odooOrderId: { type: 'string' },
                                customerRef: { type: 'string' },
                                customerName: { type: 'string' },
                                status: { type: 'string' },
                                totalAmount: { type: 'number' },
                                currency: { type: 'string' },
                                orderDate: { type: 'string', format: 'date-time' },
                                deliveryDate: { type: 'string', format: 'date-time' },
                                notes: { type: 'string' },
                                createdAt: { type: 'string', format: 'date-time' },
                                updatedAt: { type: 'string', format: 'date-time' },
                                timeline: {
                                    type: 'array',
                                    items: {
                                        type: 'object',
                                        properties: {
                                            id: { type: 'string' },
                                            status: { type: 'string' },
                                            previousStatus: { type: 'string' },
                                            notes: { type: 'string' },
                                            changedBy: { type: 'string' },
                                            changedAt: { type: 'string', format: 'date-time' },
                                            source: { type: 'string' }
                                        }
                                    }
                                }
                            }
                        }
                    }
                },
                404: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        error: {
                            type: 'object',
                            properties: {
                                code: { type: 'string' },
                                message: { type: 'string' }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const { id } = request.params;
            const user = request.user;
            const lineAccountId = user?.lineAccountId || '1';
            const order = await orderService.getOrderById(id, lineAccountId);
            if (!order) {
                return reply.status(404).send({
                    success: false,
                    error: {
                        code: 'ORDER_NOT_FOUND',
                        message: 'Order not found',
                    },
                });
            }
            return reply.send({
                success: true,
                data: order,
            });
        }
        catch (error) {
            console.error('Order details error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDER_DETAILS_ERROR',
                    message: 'Failed to retrieve order details',
                },
            });
        }
    });
    fastify.put('/:id/status', {
        preHandler: [auth_1.authenticate],
        schema: {
            tags: ['Orders'],
            summary: 'Update order status with audit trail',
            params: {
                type: 'object',
                properties: {
                    id: { type: 'string' }
                },
                required: ['id']
            },
            body: {
                type: 'object',
                properties: {
                    status: { type: 'string' },
                    notes: { type: 'string' },
                    notifyCustomer: { type: 'boolean', default: false }
                },
                required: ['status']
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                id: { type: 'string' },
                                status: { type: 'string' },
                                updatedAt: { type: 'string', format: 'date-time' },
                                timeline: {
                                    type: 'array',
                                    items: {
                                        type: 'object',
                                        properties: {
                                            id: { type: 'string' },
                                            status: { type: 'string' },
                                            changedAt: { type: 'string', format: 'date-time' },
                                            source: { type: 'string' }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const { id } = request.params;
            const { status, notes, notifyCustomer } = request.body;
            const user = request.user;
            const lineAccountId = user?.lineAccountId || '1';
            const updatedOrder = await orderService.updateOrderStatus(id, lineAccountId, status, notes, user?.userId);
            if (notifyCustomer) {
                console.log(`TODO: Notify customer about order ${id} status change to ${status}`);
            }
            const webSocketService = fastify.webSocketService;
            if (webSocketService) {
                webSocketService.broadcastOrderUpdate(lineAccountId, {
                    orderId: id,
                    status,
                    updatedAt: updatedOrder.updatedAt,
                });
            }
            return reply.send({
                success: true,
                data: {
                    id: updatedOrder.id,
                    status: updatedOrder.status,
                    updatedAt: updatedOrder.updatedAt,
                    timeline: updatedOrder.timeline,
                },
            });
        }
        catch (error) {
            console.error('Order status update error:', error);
            if (error instanceof Error && error.message === 'Order not found') {
                return reply.status(404).send({
                    success: false,
                    error: {
                        code: 'ORDER_NOT_FOUND',
                        message: 'Order not found',
                    },
                });
            }
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDER_STATUS_UPDATE_ERROR',
                    message: 'Failed to update order status',
                },
            });
        }
    });
    fastify.get('/:id/timeline', {
        preHandler: [auth_1.authenticate],
        schema: {
            tags: ['Orders'],
            summary: 'Get order status timeline',
            params: {
                type: 'object',
                properties: {
                    id: { type: 'string' }
                },
                required: ['id']
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'array',
                            items: {
                                type: 'object',
                                properties: {
                                    id: { type: 'string' },
                                    orderId: { type: 'string' },
                                    status: { type: 'string' },
                                    previousStatus: { type: 'string' },
                                    notes: { type: 'string' },
                                    changedBy: { type: 'string' },
                                    changedAt: { type: 'string', format: 'date-time' },
                                    source: { type: 'string' }
                                }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const { id } = request.params;
            const user = request.user;
            const lineAccountId = user?.lineAccountId || '1';
            const order = await orderService.getOrderById(id, lineAccountId);
            if (!order) {
                return reply.status(404).send({
                    success: false,
                    error: {
                        code: 'ORDER_NOT_FOUND',
                        message: 'Order not found',
                    },
                });
            }
            const timeline = await orderService.getOrderTimeline(id);
            return reply.send({
                success: true,
                data: timeline,
            });
        }
        catch (error) {
            console.error('Order timeline error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDER_TIMELINE_ERROR',
                    message: 'Failed to retrieve order timeline',
                },
            });
        }
    });
    fastify.post('/search', {
        preHandler: [auth_1.authenticate],
        schema: {
            tags: ['Orders'],
            summary: 'Advanced order search',
            body: {
                type: 'object',
                properties: {
                    query: { type: 'string' },
                    page: { type: 'number', minimum: 1, default: 1 },
                    limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
                    status: { type: 'string' },
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                },
                required: ['query']
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                data: { type: 'array' },
                                meta: {
                                    type: 'object',
                                    properties: {
                                        page: { type: 'number' },
                                        limit: { type: 'number' },
                                        total: { type: 'number' },
                                        totalPages: { type: 'number' }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const { query, page = 1, limit = 20, status, dateFrom, dateTo } = request.body;
            const user = request.user;
            const lineAccountId = user?.lineAccountId || '1';
            const filters = {
                ...(status && { status: [status] }),
                ...(dateFrom && { dateFrom: new Date(dateFrom) }),
                ...(dateTo && { dateTo: new Date(dateTo) }),
            };
            const pagination = {
                page,
                limit: Math.min(limit, 100),
                sort: 'createdAt',
                order: 'desc',
            };
            const result = await orderService.searchOrders(lineAccountId, query, filters, pagination);
            return reply.send({
                success: true,
                data: result,
            });
        }
        catch (error) {
            console.error('Order search error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDER_SEARCH_ERROR',
                    message: 'Failed to search orders',
                },
            });
        }
    });
    fastify.get('/statistics', {
        preHandler: [auth_1.authenticate],
        schema: {
            tags: ['Orders'],
            summary: 'Get order statistics for dashboard',
            querystring: {
                type: 'object',
                properties: {
                    dateFrom: { type: 'string', format: 'date' },
                    dateTo: { type: 'string', format: 'date' },
                    lineAccountId: { type: 'string' },
                },
            },
            response: {
                200: {
                    type: 'object',
                    properties: {
                        success: { type: 'boolean' },
                        data: {
                            type: 'object',
                            properties: {
                                totalOrders: { type: 'number' },
                                totalValue: { type: 'number' },
                                statusBreakdown: { type: 'object' },
                                averageOrderValue: { type: 'number' },
                                topCustomers: {
                                    type: 'array',
                                    items: {
                                        type: 'object',
                                        properties: {
                                            customerName: { type: 'string' },
                                            orderCount: { type: 'number' },
                                            totalValue: { type: 'number' }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        },
    }, async (request, reply) => {
        try {
            const query = request.query;
            const user = request.user;
            const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';
            const dateFrom = query.dateFrom ? new Date(query.dateFrom) : undefined;
            const dateTo = query.dateTo ? new Date(query.dateTo) : undefined;
            const statistics = await orderService.getOrderStatistics(lineAccountId, dateFrom, dateTo);
            return reply.send({
                success: true,
                data: statistics,
            });
        }
        catch (error) {
            console.error('Order statistics error:', error);
            return reply.status(500).send({
                success: false,
                error: {
                    code: 'ORDER_STATISTICS_ERROR',
                    message: 'Failed to retrieve order statistics',
                },
            });
        }
    });
}
//# sourceMappingURL=orders.js.map