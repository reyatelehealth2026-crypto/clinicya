"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.closeDatabasePool = exports.getDatabasePool = exports.DatabasePoolService = void 0;
const promise_1 = require("mysql2/promise");
const logger_1 = require("@/utils/logger");
const config_1 = require("@/config/config");
class DatabasePoolService {
    pool;
    queryMetrics = [];
    totalQueries = 0;
    totalQueryTime = 0;
    slowQueryThreshold = 1000;
    constructor() {
        this.pool = (0, promise_1.createPool)({
            host: config_1.config.DB_HOST || 'localhost',
            port: parseInt(config_1.config.DB_PORT || '3306'),
            user: config_1.config.DB_USER || 'root',
            password: config_1.config.DB_PASSWORD || '',
            database: config_1.config.DB_NAME || 'telepharmacy',
            charset: 'utf8mb4',
            timezone: '+07:00',
            connectionLimit: 20,
            acquireTimeout: 60000,
            timeout: 60000,
            reconnect: true,
            multipleStatements: false,
            dateStrings: false,
            supportBigNumbers: true,
            bigNumberStrings: false,
            idleTimeout: 300000,
            maxIdle: 10,
            ssl: config_1.config.DB_SSL_ENABLED === 'true' ? {
                rejectUnauthorized: false,
            } : false,
        });
        this.setupEventHandlers();
        this.startMetricsCollection();
    }
    async query(sql, params) {
        const startTime = Date.now();
        let connection = null;
        try {
            connection = await this.pool.getConnection();
            const [rows, fields] = await connection.execute(sql, params);
            const executionTime = Date.now() - startTime;
            this.recordQueryMetrics(sql, executionTime, Array.isArray(rows) ? rows.length : 0);
            return rows;
        }
        catch (error) {
            const executionTime = Date.now() - startTime;
            logger_1.logger.error('Database query failed', {
                sql: this.sanitizeQuery(sql),
                executionTime,
                error: String(error),
            });
            throw error;
        }
        finally {
            if (connection) {
                connection.release();
            }
        }
    }
    async queryOne(sql, params) {
        const results = await this.query(sql, params);
        return results.length > 0 ? results[0] : null;
    }
    async transaction(callback) {
        const connection = await this.pool.getConnection();
        try {
            await connection.beginTransaction();
            const result = await callback(connection);
            await connection.commit();
            return result;
        }
        catch (error) {
            await connection.rollback();
            logger_1.logger.error('Transaction failed', { error: String(error) });
            throw error;
        }
        finally {
            connection.release();
        }
    }
    getPoolStats() {
        const poolConfig = this.pool.config;
        return {
            totalConnections: poolConfig.connectionLimit || 0,
            activeConnections: this.pool.pool._allConnections.length - this.pool.pool._freeConnections.length,
            idleConnections: this.pool.pool._freeConnections.length,
            queuedRequests: this.pool.pool._connectionQueue.length,
            totalQueries: this.totalQueries,
            averageQueryTime: this.totalQueries > 0 ? this.totalQueryTime / this.totalQueries : 0,
            slowQueries: this.queryMetrics.filter(m => m.executionTime > this.slowQueryThreshold).length,
        };
    }
    getQueryMetrics(limit = 100) {
        return this.queryMetrics
            .slice(-limit)
            .sort((a, b) => b.executionTime - a.executionTime);
    }
    getSlowQueries(threshold = 1000) {
        return this.queryMetrics
            .filter(m => m.executionTime > threshold)
            .sort((a, b) => b.executionTime - a.executionTime);
    }
    async healthCheck() {
        try {
            const startTime = Date.now();
            await this.query('SELECT 1 as health_check');
            const responseTime = Date.now() - startTime;
            const stats = this.getPoolStats();
            return {
                status: 'healthy',
                details: {
                    responseTime,
                    ...stats,
                },
            };
        }
        catch (error) {
            return {
                status: 'unhealthy',
                details: {
                    error: String(error),
                    ...this.getPoolStats(),
                },
            };
        }
    }
    async close() {
        try {
            await this.pool.end();
            logger_1.logger.info('Database connection pool closed');
        }
        catch (error) {
            logger_1.logger.error('Error closing database pool', { error: String(error) });
        }
    }
    setupEventHandlers() {
        this.pool.on('connection', (connection) => {
            logger_1.logger.debug('New database connection established', {
                connectionId: connection.threadId
            });
        });
        this.pool.on('error', (error) => {
            logger_1.logger.error('Database pool error', { error: String(error) });
        });
        this.pool.on('release', (connection) => {
            logger_1.logger.debug('Database connection released', {
                connectionId: connection.threadId
            });
        });
    }
    recordQueryMetrics(query, executionTime, rowsAffected) {
        this.totalQueries++;
        this.totalQueryTime += executionTime;
        if (this.queryMetrics.length > 1000) {
            this.queryMetrics = this.queryMetrics.slice(-500);
        }
        this.queryMetrics.push({
            query: this.sanitizeQuery(query),
            executionTime,
            rowsAffected,
            timestamp: new Date(),
        });
        if (executionTime > this.slowQueryThreshold) {
            logger_1.logger.warn('Slow query detected', {
                query: this.sanitizeQuery(query),
                executionTime,
                rowsAffected,
            });
        }
    }
    sanitizeQuery(query) {
        return query
            .replace(/password\s*=\s*'[^']*'/gi, "password='***'")
            .replace(/token\s*=\s*'[^']*'/gi, "token='***'")
            .replace(/secret\s*=\s*'[^']*'/gi, "secret='***'")
            .substring(0, 200);
    }
    startMetricsCollection() {
        setInterval(() => {
            const stats = this.getPoolStats();
            logger_1.logger.info('Database pool statistics', stats);
            const utilizationRate = stats.activeConnections / stats.totalConnections;
            if (utilizationRate > 0.8) {
                logger_1.logger.warn('High database pool utilization', {
                    utilizationRate: Math.round(utilizationRate * 100),
                    activeConnections: stats.activeConnections,
                    totalConnections: stats.totalConnections,
                });
            }
        }, 5 * 60 * 1000);
        setInterval(() => {
            const oneHourAgo = new Date(Date.now() - 60 * 60 * 1000);
            this.queryMetrics = this.queryMetrics.filter(m => m.timestamp > oneHourAgo);
        }, 60 * 60 * 1000);
    }
}
exports.DatabasePoolService = DatabasePoolService;
let databasePool = null;
const getDatabasePool = () => {
    if (!databasePool) {
        databasePool = new DatabasePoolService();
    }
    return databasePool;
};
exports.getDatabasePool = getDatabasePool;
const closeDatabasePool = async () => {
    if (databasePool) {
        await databasePool.close();
        databasePool = null;
    }
};
exports.closeDatabasePool = closeDatabasePool;
//# sourceMappingURL=DatabasePoolService.js.map