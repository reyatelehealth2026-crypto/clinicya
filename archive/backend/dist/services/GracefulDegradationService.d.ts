import { APIResponse } from '../types/errors.js';
import { CacheService } from './CacheService.js';
import { LoggingService } from './LoggingService.js';
interface ServiceHealth {
    service: string;
    healthy: boolean;
    lastCheck: Date;
    errorCount: number;
    degradationLevel: 'none' | 'partial' | 'full';
}
export declare class GracefulDegradationService {
    private cacheService;
    private loggingService;
    private strategies;
    private serviceHealth;
    private fallbackData;
    constructor(cacheService: CacheService, loggingService: LoggingService);
    private initializeStrategies;
    private initializeFallbackData;
    applyDegradation(error: Error, context: {
        endpoint: string;
        service?: string;
        params?: any;
        requestId: string;
    }): Promise<APIResponse>;
    private findApplicableStrategy;
    private createGenericFallback;
    private getStaticFallback;
    private getMinimalFunctionality;
    private updateServiceHealth;
    getServiceHealth(): Record<string, ServiceHealth>;
    isServiceDegraded(service: string): boolean;
    getDegradationLevel(service: string): 'none' | 'partial' | 'full';
    resetServiceHealth(service: string): void;
    getDegradationStatistics(): {
        totalServices: number;
        healthyServices: number;
        degradedServices: number;
        criticalServices: number;
        degradationStrategies: string[];
    };
}
export {};
//# sourceMappingURL=GracefulDegradationService.d.ts.map