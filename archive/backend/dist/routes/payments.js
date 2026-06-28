"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = paymentRoutes;
const client_1 = require("@prisma/client");
const PaymentUploadService_1 = require("@/services/PaymentUploadService");
const PaymentMatchingService_1 = require("@/services/PaymentMatchingService");
const prisma_1 = require("@/utils/prisma");
const rbac_1 = require("@/middleware/rbac");
const zod_1 = require("zod");
const uploadSchema = zod_1.z.object({
    amount: zod_1.z.number().positive().optional(),
});
const updateAmountSchema = zod_1.z.object({
    amount: zod_1.z.number().positive(),
});
const matchSlipSchema = zod_1.z.object({
    orderId: zod_1.z.string().uuid(),
});
const rejectSlipSchema = zod_1.z.object({
    reason: zod_1.z.string().optional(),
});
const listSlipsSchema = zod_1.z.object({
    status: zod_1.z.nativeEnum(client_1.SlipStatus).optional(),
    dateFrom: zod_1.z.string().datetime().optional(),
    dateTo: zod_1.z.string().datetime().optional(),
    page: zod_1.z.number().int().positive().default(1),
    limit: zod_1.z.number().int().positive().max(100).default(20),
    search: zod_1.z.string().optional(),
});
const multipartOptions = {
    limits: {
        fileSize: 10 * 1024 * 1024,
        files: 10,
    },
};
async function paymentRoutes(fastify) {
    const uploadService = new PaymentUploadService_1.PaymentUploadService(prisma_1.prisma);
    const matchingService = new PaymentMatchingService_1.PaymentMatchingService(prisma_1.prisma);
    await fastify.register(require('@fastify/multipart'), multipartOptions);
    fastify.get('/slips', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
        schema: {
            querystring: listSlipsSchema,
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
                                    imageUrl: { type: 'string' },
                                    amount: { type: 'number', nullable: true },
                                    status: { type: 'string' },
                                    uploadedBy: { type: 'string' },
                                    matchedOrderId: { type: 'string', nullable: true },
                                    processedAt: { type: 'string', nullable: true },
                                    createdAt: { type: 'string' },
                                    notes: { type: 'string', nullable: true },
                                },
                            },
                        },
                        meta: {
                            type: 'object',
                            properties: {
                                page: { type: 'number' },
                                limit: { type: 'number' },
                                total: { type: 'number' },
                                totalPages: { type: 'number' },
                            },
                        },
                    },
                },
            },
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const query = request.query;
            const options = {
                ...query,
                dateFrom: query.dateFrom ? new Date(query.dateFrom) : undefined,
                dateTo: query.dateTo ? new Date(query.dateTo) : undefined,
                status: query.status,
            };
            const result = await uploadService.listPaymentSlips(user.lineAccountId, options);
            const response = {
                success: true,
                data: result.data,
                meta: result.meta,
            };
            return reply.code(200).send(response);
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to fetch payment slips',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/upload', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const data = await request.file();
            if (!data) {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'MISSING_FILE',
                        message: 'No file uploaded',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            const buffer = await data.toBuffer();
            const fields = data.fields;
            const amount = fields.amount ? parseFloat(fields.amount.value) : undefined;
            const file = {
                buffer,
                mimetype: data.mimetype,
                originalname: data.filename,
                size: buffer.length,
            };
            const result = await uploadService.uploadPaymentSlip(file, user.userId, user.lineAccountId, amount);
            if (result.success) {
                const response = {
                    success: true,
                    data: {
                        slipId: result.slipId,
                        imageUrl: result.imageUrl,
                        potentialMatches: result.potentialMatches,
                    },
                };
                return reply.code(201).send(response);
            }
            else {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'UPLOAD_FAILED',
                        message: result.message,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to upload payment slip',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/bulk', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const files = request.files();
            const fileArray = [];
            for await (const file of files) {
                const buffer = await file.toBuffer();
                fileArray.push({
                    buffer,
                    mimetype: file.mimetype,
                    originalname: file.filename,
                    size: buffer.length,
                });
            }
            if (fileArray.length === 0) {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'NO_FILES',
                        message: 'No files uploaded',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            const result = await uploadService.bulkUploadPaymentSlips(fileArray, user.userId, user.lineAccountId);
            const response = {
                success: true,
                data: result,
            };
            return reply.code(201).send(response);
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to process bulk upload',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/slips/:id', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { id } = request.params;
            const slip = await uploadService.getPaymentSlip(id, user.lineAccountId);
            const response = {
                success: true,
                data: slip,
            };
            return reply.code(200).send(response);
        }
        catch (error) {
            request.log.error(error);
            if (error instanceof Error && error.message === 'Payment slip not found') {
                return reply.code(404).send({
                    success: false,
                    error: {
                        code: 'SLIP_NOT_FOUND',
                        message: 'Payment slip not found',
                        timestamp: new Date().toISOString(),
                    },
                });
            }
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to fetch payment slip',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.put('/slips/:id/amount', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
        schema: {
            body: updateAmountSchema,
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { id } = request.params;
            const { amount } = request.body;
            const result = await uploadService.updateSlipAmount(id, amount, user.lineAccountId);
            if (result.success) {
                const response = {
                    success: true,
                    data: {
                        slipId: result.slipId,
                        potentialMatches: result.potentialMatches,
                    },
                };
                return reply.code(200).send(response);
            }
            else {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'UPDATE_FAILED',
                        message: result.message,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to update payment slip amount',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.put('/slips/:id/match', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
        schema: {
            body: matchSlipSchema,
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { id } = request.params;
            const { orderId } = request.body;
            const result = await matchingService.matchPaymentSlip(id, orderId, user.lineAccountId);
            if (result.success) {
                const response = {
                    success: true,
                    data: {
                        matchedOrderId: result.matchedOrderId,
                        message: result.message,
                    },
                };
                return reply.code(200).send(response);
            }
            else {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'MATCH_FAILED',
                        message: result.message,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to match payment slip',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.put('/slips/:id/reject', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
        schema: {
            body: rejectSlipSchema,
        },
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { id } = request.params;
            const { reason } = request.body;
            const result = await matchingService.rejectPaymentSlip(id, user.lineAccountId, reason);
            if (result.success) {
                const response = {
                    success: true,
                    data: { message: result.message },
                };
                return reply.code(200).send(response);
            }
            else {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'REJECT_FAILED',
                        message: result.message,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to reject payment slip',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.delete('/slips/:id', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { id } = request.params;
            const result = await uploadService.deletePaymentSlip(id, user.lineAccountId);
            if (result.success) {
                const response = {
                    success: true,
                    data: { message: result.message },
                };
                return reply.code(200).send(response);
            }
            else {
                return reply.code(400).send({
                    success: false,
                    error: {
                        code: 'DELETE_FAILED',
                        message: result.message,
                        timestamp: new Date().toISOString(),
                    },
                });
            }
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to delete payment slip',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/pending', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const result = await uploadService.listPaymentSlips(user.lineAccountId, {
                status: client_1.SlipStatus.PENDING,
                limit: 100,
            });
            const response = {
                success: true,
                data: result.data,
                meta: result.meta,
            };
            return reply.code(200).send(response);
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to fetch pending payment slips',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.post('/auto-match', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const result = await matchingService.performAutomaticMatching(user.lineAccountId);
            const response = {
                success: true,
                data: result,
            };
            return reply.code(200).send(response);
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to perform automatic matching',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
    fastify.get('/statistics', {
        preHandler: [fastify.authenticate, fastify.requirePermission(rbac_1.Permission.PROCESS_PAYMENTS)],
    }, async (request, reply) => {
        try {
            const user = request.user;
            const { dateFrom, dateTo } = request.query;
            const result = await matchingService.getMatchingStatistics(user.lineAccountId, dateFrom ? new Date(dateFrom) : undefined, dateTo ? new Date(dateTo) : undefined);
            const response = {
                success: true,
                data: result,
            };
            return reply.code(200).send(response);
        }
        catch (error) {
            request.log.error(error);
            return reply.code(500).send({
                success: false,
                error: {
                    code: 'INTERNAL_ERROR',
                    message: 'Failed to fetch payment statistics',
                    timestamp: new Date().toISOString(),
                },
            });
        }
    });
}
//# sourceMappingURL=payments.js.map