"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PaymentUploadService = void 0;
const client_1 = require("@prisma/client");
const BaseService_1 = require("./BaseService");
const fs_1 = require("fs");
const path_1 = __importDefault(require("path"));
const crypto_1 = require("crypto");
const sharp_1 = __importDefault(require("sharp"));
class PaymentUploadService extends BaseService_1.BaseService {
    UPLOAD_DIR = process.env['UPLOAD_DIR'] || './uploads/payment-slips';
    MAX_FILE_SIZE = 10 * 1024 * 1024;
    ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    ALLOWED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.webp'];
    MAX_DIMENSION = 4096;
    constructor(prisma) {
        super(prisma);
        this.ensureUploadDirectory();
    }
    async ensureUploadDirectory() {
        try {
            await fs_1.promises.mkdir(this.UPLOAD_DIR, { recursive: true });
        }
        catch (error) {
            console.error('Failed to create upload directory:', error);
        }
    }
    async validateFile(file) {
        try {
            if (file.size > this.MAX_FILE_SIZE) {
                return {
                    isValid: false,
                    error: `File size exceeds maximum limit of ${this.MAX_FILE_SIZE / 1024 / 1024}MB`,
                };
            }
            if (!this.ALLOWED_MIME_TYPES.includes(file.mimetype)) {
                return {
                    isValid: false,
                    error: `Invalid file type. Allowed types: ${this.ALLOWED_MIME_TYPES.join(', ')}`,
                };
            }
            const ext = path_1.default.extname(file.originalname).toLowerCase();
            if (!this.ALLOWED_EXTENSIONS.includes(ext)) {
                return {
                    isValid: false,
                    error: `Invalid file extension. Allowed extensions: ${this.ALLOWED_EXTENSIONS.join(', ')}`,
                };
            }
            try {
                const metadata = await (0, sharp_1.default)(file.buffer).metadata();
                if (!metadata.width || !metadata.height) {
                    return {
                        isValid: false,
                        error: 'Invalid image file - unable to read dimensions',
                    };
                }
                if (metadata.width > this.MAX_DIMENSION || metadata.height > this.MAX_DIMENSION) {
                    return {
                        isValid: false,
                        error: `Image dimensions exceed maximum limit of ${this.MAX_DIMENSION}px`,
                    };
                }
                return {
                    isValid: true,
                    fileInfo: {
                        size: file.size,
                        mimeType: file.mimetype,
                        dimensions: {
                            width: metadata.width,
                            height: metadata.height,
                        },
                    },
                };
            }
            catch (sharpError) {
                return {
                    isValid: false,
                    error: 'Invalid or corrupted image file',
                };
            }
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.validateFile');
        }
    }
    async processImage(buffer, filename) {
        try {
            const ext = path_1.default.extname(filename).toLowerCase();
            const uniqueFilename = `${(0, crypto_1.randomUUID)()}${ext}`;
            const processedBuffer = await (0, sharp_1.default)(buffer)
                .resize(2048, 2048, {
                fit: 'inside',
                withoutEnlargement: true
            })
                .jpeg({
                quality: 85,
                progressive: true
            })
                .toBuffer();
            return {
                processedBuffer,
                filename: uniqueFilename.replace(ext, '.jpg'),
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.processImage');
        }
    }
    async saveImage(buffer, filename) {
        try {
            const filePath = path_1.default.join(this.UPLOAD_DIR, filename);
            await fs_1.promises.writeFile(filePath, buffer);
            return `/uploads/payment-slips/${filename}`;
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.saveImage');
        }
    }
    async extractAmountFromImage(_buffer) {
        return null;
    }
    async uploadPaymentSlip(file, uploadedBy, lineAccountId, amount) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const validation = await this.validateFile(file);
            if (!validation.isValid) {
                return {
                    success: false,
                    message: validation.error || 'File validation failed',
                };
            }
            const { processedBuffer, filename } = await this.processImage(file.buffer, file.originalname);
            const imageUrl = await this.saveImage(processedBuffer, filename);
            let extractedAmount = amount;
            if (!extractedAmount) {
                const ocrAmount = await this.extractAmountFromImage(processedBuffer);
                extractedAmount = ocrAmount || undefined;
            }
            const slip = await this.prisma.odooSlipUpload.create({
                data: {
                    lineAccountId,
                    imageUrl,
                    amount: extractedAmount || null,
                    uploadedBy,
                    status: client_1.SlipStatus.PENDING,
                },
            });
            let potentialMatches = [];
            if (extractedAmount) {
                const { PaymentMatchingService } = await Promise.resolve().then(() => __importStar(require('./PaymentMatchingService')));
                const matchingService = new PaymentMatchingService(this.prisma);
                potentialMatches = await matchingService.findPotentialMatches(extractedAmount, lineAccountId);
            }
            return {
                success: true,
                message: 'Payment slip uploaded successfully',
                slipId: slip.id,
                imageUrl,
                potentialMatches,
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.uploadPaymentSlip');
        }
    }
    async updateSlipAmount(slipId, amount, lineAccountId) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            if (amount <= 0) {
                return {
                    success: false,
                    message: 'Amount must be greater than zero',
                };
            }
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
                data: { amount },
            });
            const { PaymentMatchingService } = await Promise.resolve().then(() => __importStar(require('./PaymentMatchingService')));
            const matchingService = new PaymentMatchingService(this.prisma);
            const potentialMatches = await matchingService.findPotentialMatches(amount, lineAccountId);
            return {
                success: true,
                message: 'Payment slip amount updated successfully',
                slipId,
                potentialMatches,
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.updateSlipAmount');
        }
    }
    async bulkUploadPaymentSlips(files, uploadedBy, lineAccountId) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const result = {
                totalFiles: files.length,
                successfulUploads: 0,
                failedUploads: 0,
                results: [],
            };
            const concurrencyLimit = 5;
            const chunks = [];
            for (let i = 0; i < files.length; i += concurrencyLimit) {
                chunks.push(files.slice(i, i + concurrencyLimit));
            }
            for (const chunk of chunks) {
                const promises = chunk.map(async (file) => {
                    try {
                        const uploadResult = await this.uploadPaymentSlip(file, uploadedBy, lineAccountId);
                        if (uploadResult.success) {
                            result.successfulUploads++;
                            result.results.push({
                                filename: file.originalname,
                                success: true,
                                slipId: uploadResult.slipId,
                            });
                        }
                        else {
                            result.failedUploads++;
                            result.results.push({
                                filename: file.originalname,
                                success: false,
                                error: uploadResult.message,
                            });
                        }
                    }
                    catch (error) {
                        result.failedUploads++;
                        result.results.push({
                            filename: file.originalname,
                            success: false,
                            error: error instanceof Error ? error.message : 'Unknown error',
                        });
                    }
                });
                await Promise.all(promises);
            }
            return result;
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.bulkUploadPaymentSlips');
        }
    }
    async getPaymentSlip(slipId, lineAccountId) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const slip = await this.prisma.odooSlipUpload.findFirst({
                where: {
                    id: slipId,
                    lineAccountId,
                },
            });
            if (!slip) {
                throw new Error('Payment slip not found');
            }
            let matchedOrder = null;
            if (slip.matchedOrderId) {
                matchedOrder = await this.prisma.odooOrder.findUnique({
                    where: { id: slip.matchedOrderId },
                    select: {
                        id: true,
                        odooOrderId: true,
                        customerRef: true,
                        customerName: true,
                        totalAmount: true,
                        status: true,
                        orderDate: true,
                    },
                });
            }
            return {
                ...slip,
                matchedOrder,
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.getPaymentSlip');
        }
    }
    async listPaymentSlips(lineAccountId, options = {}) {
        try {
            this.validateLineAccountAccess(lineAccountId);
            const { status, dateFrom, dateTo, page = 1, limit = 20, search, } = options;
            const whereClause = { lineAccountId };
            if (status) {
                whereClause.status = status;
            }
            if (dateFrom || dateTo) {
                whereClause.createdAt = {};
                if (dateFrom)
                    whereClause.createdAt.gte = dateFrom;
                if (dateTo)
                    whereClause.createdAt.lte = dateTo;
            }
            if (search) {
                whereClause.OR = [
                    { notes: { contains: search } },
                    { uploadedBy: { contains: search } },
                ];
            }
            const [slips, total] = await Promise.all([
                this.prisma.odooSlipUpload.findMany({
                    where: whereClause,
                    orderBy: { createdAt: 'desc' },
                    skip: (page - 1) * limit,
                    take: limit,
                    select: {
                        id: true,
                        imageUrl: true,
                        amount: true,
                        status: true,
                        uploadedBy: true,
                        matchedOrderId: true,
                        processedAt: true,
                        createdAt: true,
                        notes: true,
                    },
                }),
                this.prisma.odooSlipUpload.count({ where: whereClause }),
            ]);
            return {
                data: slips,
                meta: {
                    page,
                    limit,
                    total,
                    totalPages: Math.ceil(total / limit),
                },
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.listPaymentSlips');
        }
    }
    async deletePaymentSlip(slipId, lineAccountId) {
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
                    message: 'Payment slip not found or cannot be deleted',
                };
            }
            try {
                const filePath = path_1.default.join(process.cwd(), 'public', slip.imageUrl);
                await fs_1.promises.unlink(filePath);
            }
            catch (fileError) {
                console.warn('Failed to delete image file:', fileError);
            }
            await this.prisma.odooSlipUpload.delete({
                where: { id: slipId },
            });
            return {
                success: true,
                message: 'Payment slip deleted successfully',
            };
        }
        catch (error) {
            this.handleError(error, 'PaymentUploadService.deletePaymentSlip');
        }
    }
}
exports.PaymentUploadService = PaymentUploadService;
//# sourceMappingURL=PaymentUploadService.js.map