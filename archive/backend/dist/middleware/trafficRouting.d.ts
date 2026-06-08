import { Request, Response, NextFunction } from 'express';
import { FeatureFlagService } from '../services/FeatureFlagService';
import { Logger } from '../services/LoggingService';
import { Redis } from 'ioredis';
export interface RoutingDecision {
    useNewSystem: boolean;
    reason: string;
    featureFlag?: string;
    abTestVariant?: string;
    routingTimestamp: Date;
}
export interface RoutingConfig {
    legacyBaseUrl: string;
    newSystemBaseUrl: string;
    defaultToLegacy: boolean;
    enableLogging: boolean;
    enableMetrics: boolean;
}
export declare class TrafficRoutingMiddleware {
    private featureFlagService;
    private logger;
    private redis;
    private config;
    private legacyProxy;
    private routingMetrics;
    constructor(featureFlagService: FeatureFlagService, logger: Logger, redis: Redis, config: RoutingConfig);
    routeTraffic(): (req: Request, res: Response, next: NextFunction) => Promise<void>;
    private makeRoutingDecision;
    private getRouteFeatureFlag;
    private checkGradualRollout;
    private getGuestFeatureFlags;
    private hashUserId;
    private logRoutingDecision;
    private updateRoutingMetrics;
    getRoutingMetrics(date?: string): Promise<Record<string, any>>;
    healthCheck(): Promise<{
        status: 'healthy' | 'degraded' | 'unhealthy';
        checks: Record<string, any>;
    }>;
}
declare global {
    namespace Express {
        interface Request {
            routingDecision?: RoutingDecision;
        }
    }
}
//# sourceMappingURL=trafficRouting.d.ts.map