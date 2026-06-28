import { FastifyInstance } from 'fastify';
export interface CacheOptions {
    ttl?: number;
    prefix?: string;
}
export interface CacheStats {
    hits: number;
    misses: number;
    sets: number;
    deletes: number;
    hitRate: number;
}
export declare class CacheService {
    private redis;
    private stats;
    constructor(fastify: FastifyInstance);
    get<T>(key: string, options?: CacheOptions): Promise<T | null>;
    set<T>(key: string, value: T, options?: CacheOptions): Promise<boolean>;
    delete(key: string, options?: CacheOptions): Promise<boolean>;
    exists(key: string, options?: CacheOptions): Promise<boolean>;
    expire(key: string, ttl: number, options?: CacheOptions): Promise<boolean>;
    getOrSet<T>(key: string, factory: () => Promise<T>, options?: CacheOptions): Promise<T>;
    invalidatePattern(pattern: string, options?: CacheOptions): Promise<number>;
    flush(): Promise<boolean>;
    getStats(): CacheStats;
    resetStats(): void;
    private buildKey;
    private updateHitRate;
    getWithFallback<T>(key: string, fallbackFactory: () => Promise<T>, options?: CacheOptions & {
        fallbackTtl?: number;
    }): Promise<T>;
    warmCache<T>(keys: string[], factory: (key: string) => Promise<T>, options?: CacheOptions): Promise<void>;
}
//# sourceMappingURL=CacheService.d.ts.map