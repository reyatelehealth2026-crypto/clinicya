import { PrismaClient, SlipStatus } from '@prisma/client';
import { BaseService } from './BaseService';
export interface UploadResult {
    success: boolean;
    message: string;
    slipId?: string;
    imageUrl?: string;
    potentialMatches?: Array<{
        orderId: string;
        amount: number;
        confidence: number;
    }>;
}
export interface FileValidationResult {
    isValid: boolean;
    error?: string;
    fileInfo?: {
        size: number;
        mimeType: string;
        dimensions?: {
            width: number;
            height: number;
        };
    };
}
export interface BulkUploadResult {
    totalFiles: number;
    successfulUploads: number;
    failedUploads: number;
    results: Array<{
        filename: string;
        success: boolean;
        slipId?: string;
        error?: string;
    }>;
}
export declare class PaymentUploadService extends BaseService {
    private readonly UPLOAD_DIR;
    private readonly MAX_FILE_SIZE;
    private readonly ALLOWED_MIME_TYPES;
    private readonly ALLOWED_EXTENSIONS;
    private readonly MAX_DIMENSION;
    constructor(prisma: PrismaClient);
    private ensureUploadDirectory;
    validateFile(file: {
        buffer: Buffer;
        mimetype: string;
        originalname: string;
        size: number;
    }): Promise<FileValidationResult>;
    private processImage;
    private saveImage;
    private extractAmountFromImage;
    uploadPaymentSlip(file: {
        buffer: Buffer;
        mimetype: string;
        originalname: string;
        size: number;
    }, uploadedBy: string, lineAccountId: string, amount?: number): Promise<UploadResult>;
    updateSlipAmount(slipId: string, amount: number, lineAccountId: string): Promise<UploadResult>;
    bulkUploadPaymentSlips(files: Array<{
        buffer: Buffer;
        mimetype: string;
        originalname: string;
        size: number;
    }>, uploadedBy: string, lineAccountId: string): Promise<BulkUploadResult>;
    getPaymentSlip(slipId: string, lineAccountId: string): Promise<{
        matchedOrder: {
            status: string;
            id: string;
            odooOrderId: string;
            customerRef: string | null;
            customerName: string | null;
            totalAmount: import("@prisma/client/runtime/library").Decimal;
            orderDate: Date | null;
        } | null;
        status: import(".prisma/client").$Enums.SlipStatus;
        id: string;
        lineAccountId: string;
        createdAt: Date;
        updatedAt: Date;
        notes: string | null;
        imageUrl: string;
        amount: import("@prisma/client/runtime/library").Decimal | null;
        uploadedBy: string;
        matchedOrderId: string | null;
        processedAt: Date | null;
    }>;
    listPaymentSlips(lineAccountId: string, options?: {
        status?: SlipStatus;
        dateFrom?: Date;
        dateTo?: Date;
        page?: number;
        limit?: number;
        search?: string;
    }): Promise<{
        data: {
            status: import(".prisma/client").$Enums.SlipStatus;
            id: string;
            createdAt: Date;
            notes: string | null;
            imageUrl: string;
            amount: import("@prisma/client/runtime/library").Decimal | null;
            uploadedBy: string;
            matchedOrderId: string | null;
            processedAt: Date | null;
        }[];
        meta: {
            page: number;
            limit: number;
            total: number;
            totalPages: number;
        };
    }>;
    deletePaymentSlip(slipId: string, lineAccountId: string): Promise<{
        success: boolean;
        message: string;
    }>;
}
//# sourceMappingURL=PaymentUploadService.d.ts.map