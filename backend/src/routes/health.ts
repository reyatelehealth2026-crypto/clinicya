import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { prisma } from '@/utils/prisma';
import { OdooService } from '@/services/OdooService';
import { CacheService } from '@/services/CacheService';
import { WebSocketService } from '@/services/WebSocketService';
import { logger } from '@/utils/logger';

declare module 'fastify' {
  interface FastifyInstance {
    webSocketService: WebSocketService;
  }
}

interface HealthCheckResponse {
  status: 'healthy' | 'unhealthy' | 'degraded';
  timestamp: string;
  version: string;
  uptime: number;
  checks: {
    database: HealthCheck;
    redis: HealthCheck;
    odoo?: HealthCheck;
    memory: HealthCheck;
    websocket: HealthCheck;
    disk?: HealthCheck;
  };
  performance: {
    responseTime: number;
    cacheHitRate: number;
    circuitBreakerStats?: any;
    websocketConnections?: any;
  };
}

interface HealthCheck {
  status: 'ok' | 'error' | 'warning';
  message: string;
  responseTime?: number;
  details?: any;
}

export default async function healthRoutes(fastify: FastifyInstance) {
  const cacheService = new CacheService(fastify);
  // Gate Odoo wiring: only instantiate when env credentials are configured.
  // Tenants that do not use Odoo simply leave ODOO_API_URL / ODOO_API_KEY unset.
  const odooEnabled = Boolean(process.env['ODOO_API_URL'] && process.env['ODOO_API_KEY']);
  const odooService = odooEnabled ? new OdooService(prisma) : null;

  // Comprehensive health check
  fastify.get('/health', async (_request: FastifyRequest, reply: FastifyReply) => {
    const startTime = Date.now();

    try {
      const baseChecks = [
        checkDatabase(),
        checkRedis(fastify),
        checkMemory(),
        checkWebSocket(fastify),
      ];
      const odooCheck = odooService ? checkOdoo(odooService) : null;

      const settled = await Promise.allSettled(odooCheck ? [...baseChecks, odooCheck] : baseChecks);
      const [databaseResult, redisResult, memoryResult, websocketResult, odooResult] = settled;

      const healthResponse: HealthCheckResponse = {
        status: 'healthy',
        timestamp: new Date().toISOString(),
        version: process.env['npm_package_version'] || '1.0.0',
        uptime: process.uptime(),
        checks: {
          database: databaseResult.status === 'fulfilled' ? databaseResult.value : {
            status: 'error',
            message: 'Database check failed',
            details: databaseResult.status === 'rejected' ? databaseResult.reason : undefined,
          },
          redis: redisResult.status === 'fulfilled' ? redisResult.value : {
            status: 'error',
            message: 'Redis check failed',
            details: redisResult.status === 'rejected' ? redisResult.reason : undefined,
          },
          memory: memoryResult.status === 'fulfilled' ? memoryResult.value : {
            status: 'error',
            message: 'Memory check failed',
            details: memoryResult.status === 'rejected' ? memoryResult.reason : undefined,
          },
          websocket: websocketResult.status === 'fulfilled' ? websocketResult.value : {
            status: 'error',
            message: 'WebSocket check failed',
            details: websocketResult.status === 'rejected' ? websocketResult.reason : undefined,
          },
          ...(odooResult
            ? {
                odoo: odooResult.status === 'fulfilled' ? odooResult.value : {
                  status: 'error',
                  message: 'Odoo check failed',
                  details: odooResult.status === 'rejected' ? odooResult.reason : undefined,
                },
              }
            : {}),
        },
        performance: {
          responseTime: Date.now() - startTime,
          cacheHitRate: cacheService.getStats().hitRate,
          ...(odooService ? { circuitBreakerStats: odooService.getCircuitBreakerStats() } : {}),
          websocketConnections: fastify.webSocketService?.getConnectionStats() || null,
        },
      };

      // Determine overall status
      const checkStatuses = Object.values(healthResponse.checks).map(check => check.status);
      
      if (checkStatuses.includes('error')) {
        healthResponse.status = 'unhealthy';
      } else if (checkStatuses.includes('warning')) {
        healthResponse.status = 'degraded';
      }

      const statusCode = healthResponse.status === 'healthy' ? 200 : 
                        healthResponse.status === 'degraded' ? 200 : 503;

      reply.status(statusCode).send(healthResponse);
    } catch (error) {
      logger.error('Health check failed', { error: String(error) });
      
      reply.status(503).send({
        status: 'unhealthy',
        timestamp: new Date().toISOString(),
        error: 'Health check failed',
        details: String(error),
      });
    }
  });

  // Readiness check for load balancer
  fastify.get('/ready', async (_request: FastifyRequest, reply: FastifyReply) => {
    try {
      // Quick checks for essential services
      await Promise.all([
        checkDatabaseConnection(),
        checkRedisConnection(fastify),
      ]);

      reply.send({
        status: 'ready',
        timestamp: new Date().toISOString(),
      });
    } catch (error) {
      logger.error('Readiness check failed', { error: String(error) });
      
      reply.status(503).send({
        status: 'not_ready',
        timestamp: new Date().toISOString(),
        error: String(error),
      });
    }
  });

  // Liveness check for container orchestration
  fastify.get('/live', async (_request: FastifyRequest, reply: FastifyReply) => {
    reply.send({
      status: 'alive',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
    });
  });

  // Metrics endpoint
  fastify.get('/metrics', async (_request: FastifyRequest, reply: FastifyReply) => {
    const memUsage = process.memoryUsage();
    const cacheStats = cacheService.getStats();
    const circuitBreakerStats = odooService ? odooService.getCircuitBreakerStats() : null;

    reply.send({
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
      memory: {
        rss: memUsage.rss,
        heapTotal: memUsage.heapTotal,
        heapUsed: memUsage.heapUsed,
        external: memUsage.external,
        arrayBuffers: memUsage.arrayBuffers,
      },
      cache: cacheStats,
      circuitBreaker: circuitBreakerStats,
      process: {
        pid: process.pid,
        version: process.version,
        platform: process.platform,
        arch: process.arch,
      },
    });
  });
}

async function checkDatabase(): Promise<HealthCheck> {
  const startTime = Date.now();
  
  try {
    await prisma.$queryRaw`SELECT 1`;
    
    return {
      status: 'ok',
      message: 'Database connection successful',
      responseTime: Date.now() - startTime,
    };
  } catch (error) {
    return {
      status: 'error',
      message: 'Database connection failed',
      responseTime: Date.now() - startTime,
      details: String(error),
    };
  }
}

async function checkDatabaseConnection(): Promise<void> {
  await prisma.$queryRaw`SELECT 1`;
}

async function checkRedis(fastify: FastifyInstance): Promise<HealthCheck> {
  const startTime = Date.now();
  
  try {
    const testKey = 'health-check';
    const testValue = Date.now().toString();
    
    await fastify.redis.set(testKey, testValue);
    const result = await fastify.redis.get(testKey);
    await fastify.redis.del(testKey);
    
    if (result !== testValue) {
      throw new Error('Redis read/write test failed');
    }
    
    return {
      status: 'ok',
      message: 'Redis connection successful',
      responseTime: Date.now() - startTime,
    };
  } catch (error) {
    return {
      status: 'error',
      message: 'Redis connection failed',
      responseTime: Date.now() - startTime,
      details: String(error),
    };
  }
}

async function checkRedisConnection(fastify: FastifyInstance): Promise<void> {
  await fastify.redis.ping();
}

async function checkOdoo(odooService: OdooService): Promise<HealthCheck> {
  const startTime = Date.now();
  
  try {
    const result = await odooService.healthCheck();
    
    return {
      status: result.status === 'ok' ? 'ok' : 'warning',
      message: result.message,
      responseTime: Date.now() - startTime,
    };
  } catch (error) {
    return {
      status: 'warning', // Odoo is external, so warning instead of error
      message: 'Odoo service unavailable',
      responseTime: Date.now() - startTime,
      details: String(error),
    };
  }
}

async function checkMemory(): Promise<HealthCheck> {
  const memUsage = process.memoryUsage();
  const heapUsedMB = memUsage.heapUsed / 1024 / 1024;
  const heapTotalMB = memUsage.heapTotal / 1024 / 1024;
  const heapUsagePercent = (heapUsedMB / heapTotalMB) * 100;
  
  let status: 'ok' | 'warning' | 'error' = 'ok';
  let message = `Memory usage: ${heapUsedMB.toFixed(2)}MB / ${heapTotalMB.toFixed(2)}MB (${heapUsagePercent.toFixed(1)}%)`;
  
  if (heapUsagePercent > 90) {
    status = 'error';
    message = `High memory usage: ${heapUsagePercent.toFixed(1)}%`;
  } else if (heapUsagePercent > 80) {
    status = 'warning';
    message = `Elevated memory usage: ${heapUsagePercent.toFixed(1)}%`;
  }
  
  return {
    status,
    message,
    details: {
      heapUsed: heapUsedMB,
      heapTotal: heapTotalMB,
      heapUsagePercent,
      rss: memUsage.rss / 1024 / 1024,
      external: memUsage.external / 1024 / 1024,
    },
  };
}

async function checkWebSocket(fastify: FastifyInstance): Promise<HealthCheck> {
  const startTime = Date.now();
  
  try {
    if (!fastify.webSocketService || !fastify.webSocketService.isWebSocketAvailable()) {
      return {
        status: 'warning',
        message: 'WebSocket service not available',
        responseTime: Date.now() - startTime,
      };
    }

    const stats = fastify.webSocketService.getConnectionStats();
    
    return {
      status: 'ok',
      message: `WebSocket server running with ${stats?.totalConnections || 0} active connections`,
      responseTime: Date.now() - startTime,
      details: stats,
    };
  } catch (error) {
    return {
      status: 'error',
      message: 'WebSocket health check failed',
      responseTime: Date.now() - startTime,
      details: String(error),
    };
  }
}