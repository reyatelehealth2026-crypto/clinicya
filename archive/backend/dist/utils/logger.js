"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.logger = void 0;
const config_1 = require("@/config/config");
class Logger {
    formatMessage(level, message, context) {
        const timestamp = new Date().toISOString();
        const contextStr = context ? ` ${JSON.stringify(context)}` : '';
        return `[${timestamp}] ${level.toUpperCase()}: ${message}${contextStr}`;
    }
    shouldLog(level) {
        const levels = ['fatal', 'error', 'warn', 'info', 'debug', 'trace'];
        const currentLevelIndex = levels.indexOf(config_1.config.LOG_LEVEL);
        const messageLevelIndex = levels.indexOf(level);
        return messageLevelIndex <= currentLevelIndex;
    }
    fatal(message, context) {
        if (this.shouldLog('fatal')) {
            console.error(this.formatMessage('fatal', message, context));
        }
    }
    error(message, context) {
        if (this.shouldLog('error')) {
            console.error(this.formatMessage('error', message, context));
        }
    }
    warn(message, context) {
        if (this.shouldLog('warn')) {
            console.warn(this.formatMessage('warn', message, context));
        }
    }
    info(message, context) {
        if (this.shouldLog('info')) {
            console.info(this.formatMessage('info', message, context));
        }
    }
    debug(message, context) {
        if (this.shouldLog('debug')) {
            console.debug(this.formatMessage('debug', message, context));
        }
    }
    trace(message, context) {
        if (this.shouldLog('trace')) {
            console.trace(this.formatMessage('trace', message, context));
        }
    }
}
exports.logger = new Logger();
//# sourceMappingURL=logger.js.map