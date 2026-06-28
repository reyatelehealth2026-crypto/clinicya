"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.LoggingService = void 0;
const fs_1 = require("fs");
const path_1 = require("path");
const promises_1 = require("fs/promises");
class LoggingService {
    prisma;
    config;
    logStreams = new Map();
    constructor(prisma, config) {
        this.prisma = prisma;
        this.config = {
            enableFileLogging: true,
            enableDatabaseLogging: true,
            logDirectory: './logs',
            maxFileSize: 10 * 1024 * 1024,
            retentionDays: 30,
            ...config
        };
        this.initializeLogging();
    }
    async initializeLogging() {
        if (this.config.enableFileLogging) {
            try {
                await (0, promises_1.mkdir)(this.config.logDirectory, { recursive: true });
            }
            catch (error) {
                console.error('Failed to create log directory:', error);
            }
        }
        this.scheduleLogCleanup();
    }
    async logError(logEntry) {
        const promises = [];
        if (this.config.enableDatabaseLogging) {
            promises.push(this.logToDatabase(logEntry));
        }
        if (this.config.enableFileLogging) {
            promises.push(this.logToFile(logEntry));
        }
        try {
            await Promise.allSettled(promises);
        }
        catch (error) {
            console.error('Failed to log error:', error);
        }
    }
    async logToDatabase(logEntry) {
        try {
            await this.prisma.errorLog.create({
                data: {
                    id: logEntry.id,
                    timestamp: new Date(logEntry.timestamp),
                    level: logEntry.level,
                    code: logEntry.code,
                    message: logEntry.message,
                    stack: logEntry.stack,
                    details: logEntry.details ? JSON.stringify(logEntry.details) : null,
                    requestId: logEntry.requestId,
                    userId: logEntry.userId,
                    endpoint: logEntry.endpoint,
                    userAgent: logEntry.userAgent,
                    ipAddress: logEntry.ipAddress
                }
            });
        }
        catch (error) {
            console.error('Failed to log to database:', error);
            await this.logToFile(logEntry);
        }
    }
    async logToFile(logEntry) {
        try {
            const logFileName = `error-${new Date().toISOString().split('T')[0]}.log`;
            const logFilePath = (0, path_1.join)(this.config.logDirectory, logFileName);
            let stream = this.logStreams.get(logFileName);
            if (!stream) {
                stream = (0, fs_1.createWriteStream)(logFilePath, { flags: 'a' });
                this.logStreams.set(logFileName, stream);
            }
            const logLine = JSON.stringify({
                ...logEntry,
                timestamp: new Date(logEntry.timestamp).toISOString()
            }) + '\n';
            stream.write(logLine);
        }
        catch (error) {
            console.error('Failed to log to file:', error);
        }
    }
    async logEvent(level, message, details, requestId) {
        const logEntry = {
            id: `event-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
            timestamp: new Date().toISOString(),
            level: level,
            message,
            details,
            requestId
        };
        if (this.config.enableFileLogging) {
            const logFileName = `app-${new Date().toISOString().split('T')[0]}.log`;
            const logFilePath = (0, path_1.join)(this.config.logDirectory, logFileName);
            let stream = this.logStreams.get(logFileName);
            if (!stream) {
                stream = (0, fs_1.createWriteStream)(logFilePath, { flags: 'a' });
                this.logStreams.set(logFileName, stream);
            }
            const logLine = JSON.stringify(logEntry) + '\n';
            stream.write(logLine);
        }
    }
    async getErrorLogs(filters) {
        const page = filters.page || 1;
        const limit = Math.min(filters.limit || 50, 100);
        const skip = (page - 1) * limit;
        const where = {};
        if (filters.level) {
            where.level = filters.level;
        }
        if (filters.code) {
            where.code = filters.code;
        }
        if (filters.dateFrom || filters.dateTo) {
            where.timestamp = {};
            if (filters.dateFrom) {
                where.timestamp.gte = filters.dateFrom;
            }
            if (filters.dateTo) {
                where.timestamp.lte = filters.dateTo;
            }
        }
        if (filters.userId) {
            where.userId = filters.userId;
        }
        const [logs, total] = await Promise.all([
            this.prisma.errorLog.findMany({
                where,
                orderBy: { timestamp: 'desc' },
                skip,
                take: limit
            }),
            this.prisma.errorLog.count({ where })
        ]);
        return {
            logs: logs.map(log => ({
                id: log.id,
                timestamp: log.timestamp.toISOString(),
                level: log.level,
                code: log.code,
                message: log.message,
                stack: log.stack || undefined,
                details: log.details ? JSON.parse(log.details) : undefined,
                requestId: log.requestId || undefined,
                userId: log.userId || undefined,
                endpoint: log.endpoint || undefined,
                userAgent: log.userAgent || undefined,
                ipAddress: log.ipAddress || undefined
            })),
            total,
            page,
            totalPages: Math.ceil(total / limit)
        };
    }
    async getErrorStatistics(timeRange) {
        const [totalErrors, errorsByLevel, errorsByCode, errorTrends] = await Promise.all([
            this.prisma.errorLog.count({
                where: {
                    timestamp: {
                        gte: timeRange.from,
                        lte: timeRange.to
                    }
                }
            }),
            this.prisma.errorLog.groupBy({
                by: ['level'],
                _count: { level: true },
                where: {
                    timestamp: {
                        gte: timeRange.from,
                        lte: timeRange.to
                    }
                }
            }),
            this.prisma.errorLog.groupBy({
                by: ['code'],
                _count: { code: true },
                where: {
                    timestamp: {
                        gte: timeRange.from,
                        lte: timeRange.to
                    }
                },
                orderBy: {
                    _count: {
                        code: 'desc'
                    }
                },
                take: 10
            }),
            this.prisma.$queryRaw `
        SELECT 
          DATE(timestamp) as date,
          COUNT(*) as count
        FROM error_logs 
        WHERE timestamp >= ${timeRange.from} AND timestamp <= ${timeRange.to}
        GROUP BY DATE(timestamp)
        ORDER BY date ASC
      `
        ]);
        return {
            totalErrors,
            errorsByLevel: errorsByLevel.reduce((acc, item) => {
                acc[item.level] = item._count.level;
                return acc;
            }, {}),
            errorsByCode: errorsByCode.reduce((acc, item) => {
                acc[item.code] = item._count.code;
                return acc;
            }, {}),
            errorTrends: errorTrends.map(item => ({
                date: item.date.toISOString().split('T')[0],
                count: Number(item.count)
            }))
        };
    }
    scheduleLogCleanup() {
        const now = new Date();
        const tomorrow2AM = new Date(now);
        tomorrow2AM.setDate(tomorrow2AM.getDate() + 1);
        tomorrow2AM.setHours(2, 0, 0, 0);
        const msUntil2AM = tomorrow2AM.getTime() - now.getTime();
        setTimeout(() => {
            this.cleanupOldLogs();
            setInterval(() => this.cleanupOldLogs(), 24 * 60 * 60 * 1000);
        }, msUntil2AM);
    }
    async cleanupOldLogs() {
        try {
            const cutoffDate = new Date();
            cutoffDate.setDate(cutoffDate.getDate() - this.config.retentionDays);
            const deletedCount = await this.prisma.errorLog.deleteMany({
                where: {
                    timestamp: {
                        lt: cutoffDate
                    }
                }
            });
            console.log(`Cleaned up ${deletedCount.count} old error log entries`);
            const today = new Date().toISOString().split('T')[0];
            for (const [fileName, stream] of this.logStreams.entries()) {
                if (!fileName.includes(today)) {
                    stream.end();
                    this.logStreams.delete(fileName);
                }
            }
        }
        catch (error) {
            console.error('Failed to cleanup old logs:', error);
        }
    }
    async close() {
        for (const stream of this.logStreams.values()) {
            stream.end();
        }
        this.logStreams.clear();
    }
}
exports.LoggingService = LoggingService;
//# sourceMappingURL=LoggingService.js.map