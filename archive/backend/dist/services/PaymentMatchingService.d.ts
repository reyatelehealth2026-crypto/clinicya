import { PrismaClient } from '@prisma/client';
import { BaseService } from './BaseService';
export interface MatchingResult {
    success: boolean;
    message: string;
    matchedOrderId?: string;
    confidence?: number;
}
export interface AutoMatchingResult {
    totalProcessed: number;
    successfulMatches: number;
    failedMatches: number;
    ambiguousMatches: number;
    matches: Array<{
        slipId: string;
        orderId: string;
        confidence: number;
    }>;
}
export interface MatchingStatistics {
    totalSlips: number;
    matchedSlips: number;
    pendingSlips: number;
    rejectedSlips: number;
    matchingRate: number;
    averageProcessingTime: number;
}
export declare class PaymentMatchingService extends BaseService {
    private readonly TOLERANCE_PERCENTAGE;
    constructor(prisma: PrismaClient);
    findPotentialMatches(amount: number, lineAccountId: string, excludeOrderIds?: string[]): Promise<Array<{
        orderId: string;
        amount: number;
        confidence: number;
    }>>;
    matchPaymentSlip(slipId: string, orderId: string, lineAccountId: string): Promise<MatchingResult>;
    performAutomaticMatching(lineAccountId: string): Promise<AutoMatchingResult>;
    getMatchingStatistics(lineAccountId: string, dateFrom?: Date, dateTo?: Date): Promise<MatchingStatistics>;
    rejectPaymentSlip(slipId: string, lineAccountId: string, reason?: string): Promise<MatchingResult>;
}
//# sourceMappingURL=PaymentMatchingService.d.ts.map