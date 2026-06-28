"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.config = void 0;
const zod_1 = require("zod");
const configSchema = zod_1.z.object({
    NODE_ENV: zod_1.z.enum(['development', 'production', 'test']).default('development'),
    PORT: zod_1.z.coerce.number().default(3001),
    API_PREFIX: zod_1.z.string().default('/api/v1'),
    DATABASE_URL: zod_1.z.string(),
    JWT_SECRET: zod_1.z.string(),
    JWT_REFRESH_SECRET: zod_1.z.string(),
    JWT_EXPIRES_IN: zod_1.z.string().default('15m'),
    JWT_REFRESH_EXPIRES_IN: zod_1.z.string().default('7d'),
    REDIS_URL: zod_1.z.string().default('redis://localhost:6379'),
    REDIS_PASSWORD: zod_1.z.string().optional(),
    RATE_LIMIT_MAX: zod_1.z.coerce.number().default(100),
    RATE_LIMIT_WINDOW_MS: zod_1.z.coerce.number().default(60000),
    CORS_ORIGIN: zod_1.z.string().default('http://localhost:3000'),
    LOG_LEVEL: zod_1.z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),
    ODOO_API_URL: zod_1.z.string().optional(),
    ODOO_API_KEY: zod_1.z.string().optional(),
    WEBSOCKET_PORT: zod_1.z.coerce.number().default(3002),
    UPLOAD_DIR: zod_1.z.string().default('./uploads'),
    MAX_FILE_SIZE: zod_1.z.coerce.number().default(10 * 1024 * 1024),
    ALLOWED_FILE_TYPES: zod_1.z.string().default('image/jpeg,image/png,image/webp'),
});
const parseConfig = () => {
    try {
        return configSchema.parse(process.env);
    }
    catch (error) {
        if (error instanceof zod_1.z.ZodError) {
            const missingVars = error.errors
                .filter(err => err.code === 'invalid_type' && err.received === 'undefined')
                .map(err => err.path.join('.'));
            throw new Error(`Missing required environment variables: ${missingVars.join(', ')}\n` +
                'Please check your .env file and ensure all required variables are set.');
        }
        throw error;
    }
};
exports.config = parseConfig();
//# sourceMappingURL=config.js.map