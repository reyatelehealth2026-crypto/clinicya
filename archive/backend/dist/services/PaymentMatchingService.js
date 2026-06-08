"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.PaymentMatchingService = void 0;
const client_1 = require("@prisma/client");
const BaseService_1 = require("./BaseService");
class PaymentMatchingService extends BaseService_1.BaseService {
    TOLERANCE_PERCENTAGE = 0.05;
    constructor(prisma) {
        super(prisma);
    }
    async findPotentialMatches(amount, lineAccountId, excludeOrderIds = []) {
        try {
            const toleranceAmount = amount * this.TOLERANCE_PERCENTAGE;
            const minAmount = amount - toleranceAmount;
            const maxAmount = amount + toleranceAmount;
            const orders = await this.prisma.odooOrder.findMany({
                where: {
                    lineAccountId,
                    status: 'pending',
                    totalAmount: {
                        gte: minAmount,
                        lte: maxAmount,
                    },
                    id: {
                        notIn: excludeOrderIds,
                    },
                },
                select: {
                    id: true,
                    totalAmount: true,
                    orderDate: true,
                },
                orderBy: {
                    orderDate: 'desc',
                },
            });
            return orders.map(order => {
                const orderAmount = Number(order.totalAmount);
                const difference = Math.abs(orderAmount - amount);
                const percentageDiff = difference / amount;
                const confidence = Math.max(0, 1 - (percentageDiff / this.TOLERANCE_PERCENTAGE));
                return {
                    orderId: order.id,
                    amount: orderAmount,
                    confidence: Math.round(confidence * 100) / 100,
                };
            }).sort((a, b) => b.confidence - a.confidence);
        }
        catch (error) {
            this.handleError(error, 'PaymentMatchingService.findPotentialMatches');
        }
    }
    async matchPaymentSlip(slipId, orderId, lineAccountId) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const slip = await this.prisma.odooSlipUpload.findFirst({
                where: {
                    id: slipId,
                    lineAccountId,
                },
            });
            if (!slip) {
                return {
                    success: false,
                    message: 'Payment slip not found',
                };
            }
            if (slip.status !== client_1.SlipStatus.PENDING) {
                return {
                    success: false,
                    message: 'Payment slip is already processed',
                };
            }
            const order = await this.prisma.odooOrder.findFirst({
                where: {
                    id: orderId,
                    lineAccountId,
                    status: 'pending',
                },
            });
            if (!order) {
                return {
                    success: false,
                    message: 'Order not found or not available for matching',
                };
            }
            await this.prisma.$transaction(async (tx) => {
                await tx.odooSlipUpload.update({
                    where: { id: slipId },
                    data: {
                        status: client_1.SlipStatus.MATCHED,
                        matchedOrderId: orderId,
                        processedAt: new Date(),
                    },
                });
                await tx.odooOrder.update({
                    where: { id: orderId },
                    data: {
                        status: 'processing',
                        updatedAt: new Date(),
                    },
                });
            });
            return {
                success: true,
                message: 'Payment slip matched successfully',
                matchedOrderId: orderId,
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentMatchingService.matchPaymentSlip');
        }
    }
    async performAutomaticMatching(lineAccountId) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const pendingSlips = await this.prisma.odooSlipUpload.findMany({
                where: {
                    lineAccountId,
                    status: client_1.SlipStatus.PENDING,
                    amount: { not: null },
                },
                select: {
                    id: true,
                    amount: true,
                },
            });
            const result = {
                totalProcessed: pendingSlips.length,
                successfulMatches: 0,
                failedMatches: 0,
                ambiguousMatches: 0,
                matches: [],
            };
            for (const slip of pendingSlips) {
                if (!slip.amount)
                    continue;
                const potentialMatches = await this.findPotentialMatches(Number(slip.amount), lineAccountId);
                if (potentialMatches.length === 1 && potentialMatches[0].confidence >= 0.95) {
                    const matchResult = await this.matchPaymentSlip(slip.id, potentialMatches[0].orderId, lineAccountId);
                    if (matchResult.success) {
                        result.successfulMatches++;
                        result.matches.push({
                            slipId: slip.id,
                            orderId: potentialMatches[0].orderId,
                            confidence: potentialMatches[0].confidence,
                        });
                    }
                    else {
                        result.failedMatches++;
                    }
                }
                else if (potentialMatches.length > 1) {
                    result.ambiguousMatches++;
                }
                else {
                    result.failedMatches++;
                }
            }
            return result;
        }
        catch (error) {
            this.handleError(error, 'PaymentMatchingService.performAutomaticMatching');
        }
    }
    async getMatchingStatistics(lineAccountId, dateFrom, dateTo) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const whereClause = { lineAccountId };
            if (dateFrom || dateTo) {
                whereClause.createdAt = {};
                if (dateFrom)
                    whereClause.createdAt.gte = dateFrom;
                if (dateTo)
                    whereClause.createdAt.lte = dateTo;
            }
            const [totalSlips, matchedSlips, pendingSlips, rejectedSlips] = await Promise.all([
                this.prisma.odooSlipUpload.count({ where: whereClause }),
                this.prisma.odooSlipUpload.count({
                    where: { ...whereClause, status: client_1.SlipStatus.MATCHED },
                }),
                this.prisma.odooSlipUpload.count({
                    where: { ...whereClause, status: client_1.SlipStatus.PENDING },
                }),
                this.prisma.odooSlipUpload.count({
                    where: { ...whereClause, status: client_1.SlipStatus.REJECTED },
                }),
            ]);
            const processedSlips = await this.prisma.odooSlipUpload.findMany({
                where: {
                    ...whereClause,
                    status: client_1.SlipStatus.MATCHED,
                    processedAt: { not: null },
                },
                select: {
                    createdAt: true,
                    processedAt: true,
                },
            });
            let averageProcessingTime = 0;
            if (processedSlips.length > 0) {
                const totalProcessingTime = processedSlips.reduce((sum, slip) => {
                    if (slip.processedAt) {
                        return sum + (slip.processedAt.getTime() - slip.createdAt.getTime());
                    }
                    return sum;
                }, 0);
                averageProcessingTime = Math.round(totalProcessingTime / processedSlips.length / 1000 / 60);
            }
            const matchingRate = totalSlips > 0 ? (matchedSlips / totalSlips) * 100 : 0;
            return {
                totalSlips,
                matchedSlips,
                pendingSlips,
                rejectedSlips,
                matchingRate: Math.round(matchingRate * 100) / 100,
                averageProcessingTime,
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentMatchingService.getMatchingStatistics');
        }
    }
    async rejectPaymentSlip(slipId, lineAccountId, reason) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const slip = await this.prisma.odooSlipUpload.findFirst({
                where: {
                    id: slipId,
                    lineAccountId,
                    status: client_1.SlipStatus.PENDING,
                },
            });
            if (!slip) {
                return {
                    success: false,
                    message: 'Payment slip not found or already processed',
                };
            }
            await this.prisma.odooSlipUpload.update({
                where: { id: slipId },
                data: {
                    status: client_1.SlipStatus.REJECTED,
                    processedAt: new Date(),
                    notes: reason || 'Rejected by user',
                },
            });
            return {
                success: true,
                message: 'Payment slip rejected successfully',
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentMatchingService.rejectPaymentSlip');
        }
    }
}
exports.PaymentMatchingService = PaymentMatchingService;
//# sourceMappingURL=PaymentMatchingService.js.map