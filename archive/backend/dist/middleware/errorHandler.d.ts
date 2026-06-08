import { FastifyRequest, FastifyReply, FastifyError } from 'fastify';
import { ErrorHandlingService } from '../services/ErrorHandlingService.js';
import { GracefulDegradationService } from '../services/GracefulDegradationService.js';
export interface ErrorHandlerConfig {
    enableStackTrace: boolean;
    enableDetailedErrors: boolean;
    logAllErrors: boolean;
    enableGracefulDegradation: boolean;
}
export declare class ErrorHandlerMiddleware {
    private errorHandlingService;
    private gracefulDegradationService;
    private config;
    constructor(errorHandlingService: ErrorHandlingService, gracefulDegradationService: GracefulDegradationService, config?: Partial<ErrorHandlerConfig>);
    handleError(error: FastifyError, request: FastifyRequest, reply: FastifyReply): Promise<void>;
    private handleValidationError;
    private handleAuthenticationError;
    private handleRateLimitError;
    private handleAppError;
    private handleUnknownError;
    private isValidationError;
    private isAuthenticationError;
    private isRateLimitError;
    private isAppError;
    private shouldApplyDegradation;
    private extractServiceFromError;
    preHandler(request: FastifyRequest, reply: FastifyReply): Promise<void>;
    postHandler(request: FastifyRequest, reply: FastifyReply): Promise<void>;
    handleUncaughtException(error: Error): void;
    handleUnhandledRejection(reason: any, promise: Promise<any>): void;
    setupGlobalHandlers(): void;
}
//# sourceMappingURL=errorHandler.d.ts.map