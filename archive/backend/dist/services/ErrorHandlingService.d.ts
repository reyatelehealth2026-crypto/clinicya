import { FastifyRequest, FastifyReply } from 'fastify';
import { APIResponse } from '../types/errors.js';
import { LoggingService } from './LoggingService.js';
import { NotificationService } from './NotificationService.js';
export declare class ErrorHandlingService {
    private loggingService;
    private notificationService;
    private errorThresholds;
    private errorCounts;
    constructor(loggingService: LoggingService, notificationService: NotificationService);
    private initializeErrorThresholds;
    handleError(error: Error, request: FastifyRequest, reply: FastifyReply): Promise<void>;
    private logError;
    private determineErrorSeverity;
    private sanitizeErrorMessage;
    private generateTraceId;
    private checkErrorThresholds;
    private sendErrorAlert;
    createGracefulDegradationResponse<T>(fallbackData: T, degradationReason: string, requestId: string): APIResponse<T>;
    handleValidationError(validationError: any, requestId: string): APIResponse;
    getErrorStatistics(): Record<string, any>;
}
//# sourceMappingURL=ErrorHandlingService.d.ts.map