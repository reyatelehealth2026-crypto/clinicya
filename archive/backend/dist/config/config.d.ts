export declare const config: {
    NODE_ENV: "development" | "production" | "test";
    PORT: number;
    API_PREFIX: string;
    DATABASE_URL: string;
    JWT_SECRET: string;
    JWT_REFRESH_SECRET: string;
    JWT_EXPIRES_IN: string;
    JWT_REFRESH_EXPIRES_IN: string;
    REDIS_URL: string;
    RATE_LIMIT_MAX: number;
    RATE_LIMIT_WINDOW_MS: number;
    CORS_ORIGIN: string;
    LOG_LEVEL: "fatal" | "error" | "warn" | "info" | "debug" | "trace";
    WEBSOCKET_PORT: number;
    UPLOAD_DIR: string;
    MAX_FILE_SIZE: number;
    ALLOWED_FILE_TYPES: string;
    REDIS_PASSWORD?: string | undefined;
    ODOO_API_URL?: string | undefined;
    ODOO_API_KEY?: string | undefined;
};
export type Config = typeof config;
//# sourceMappingURL=config.d.ts.map