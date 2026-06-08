"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.registerPlugins = void 0;
const cors_1 = __importDefault(require("@fastify/cors"));
const helmet_1 = __importDefault(require("@fastify/helmet"));
const rate_limit_1 = __importDefault(require("@fastify/rate-limit"));
const jwt_1 = __importDefault(require("@fastify/jwt"));
const redis_1 = __importDefault(require("@fastify/redis"));
const swagger_1 = __importDefault(require("@fastify/swagger"));
const swagger_ui_1 = __importDefault(require("@fastify/swagger-ui"));
const config_1 = require("@/config/config");
const errorHandler_1 = require("@/middleware/errorHandler");
const requestLogger_1 = require("@/middleware/requestLogger");
const responseFormatter_1 = require("@/middleware/responseFormatter");
const auth_1 = require("@/middleware/auth");
const rbac_1 = require("@/middleware/rbac");
const registerPlugins = async (fastify) => {
    await fastify.register(async (fastify) => {
        fastify.addHook('onRequest', requestLogger_1.requestLogger);
        fastify.addHook('onRequest', responseFormatter_1.responseFormatter);
    });
    fastify.setErrorHandler(errorHandler_1.errorHandler);
    fastify.setNotFoundHandler(errorHandler_1.notFoundHandler);
    await fastify.register(helmet_1.default, {
        contentSecurityPolicy: {
            directives: {
                defaultSrc: ["'self'"],
                styleSrc: ["'self'", "'unsafe-inline'"],
                scriptSrc: ["'self'"],
                imgSrc: ["'self'", 'data:', 'https:'],
                connectSrc: ["'self'", 'wss:', 'ws:'],
                fontSrc: ["'self'", 'https://fonts.gstatic.com'],
                objectSrc: ["'none'"],
                mediaSrc: ["'self'"],
                frameSrc: ["'none'"],
            },
        },
    });
    await fastify.register(cors_1.default, {
        origin: config_1.config.CORS_ORIGIN.split(','),
        credentials: true,
        methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        allowedHeaders: ['Content-Type', 'Authorization', 'X-Line-Account-ID', 'X-Request-ID'],
    });
    await fastify.register(rate_limit_1.default, {
        max: config_1.config.RATE_LIMIT_MAX,
        timeWindow: config_1.config.RATE_LIMIT_WINDOW_MS,
        keyGenerator: (request) => {
            const user = request.user;
            return user ? `user:${user.userId}` : `ip:${request.ip}`;
        },
        errorResponseBuilder: (_request, context) => {
            return {
                success: false,
                error: {
                    code: 'RATE_LIMIT_EXCEEDED',
                    message: `Too many requests. Limit: ${context.max} per ${Math.floor(context.ttl / 1000)} seconds`,
                    timestamp: new Date().toISOString(),
                },
            };
        },
    });
    await fastify.register(jwt_1.default, {
        secret: config_1.config.JWT_SECRET,
        sign: {
            expiresIn: config_1.config.JWT_EXPIRES_IN,
        },
    });
    await fastify.register(redis_1.default, {
        url: config_1.config.REDIS_URL,
        password: config_1.config.REDIS_PASSWORD,
        lazyConnect: true,
    });
    fastify.decorate('authenticate', auth_1.authenticate);
    fastify.decorate('requirePermission', rbac_1.requirePermission);
    if (config_1.config.NODE_ENV === 'development') {
        await fastify.register(swagger_1.default, {
            openapi: {
                info: {
                    title: 'Odoo Dashboard API',
                    description: 'Modern API for Odoo Dashboard modernization',
                    version: '1.0.0',
                },
                servers: [
                    {
                        url: `http://localhost:${config_1.config.PORT}${config_1.config.API_PREFIX}`,
                        description: 'Development server',
                    },
                ],
                components: {
                    securitySchemes: {
                        bearerAuth: {
                            type: 'http',
                            scheme: 'bearer',
                            bearerFormat: 'JWT',
                        },
                    },
                },
            },
        });
        await fastify.register(swagger_ui_1.default, {
            routePrefix: '/docs',
            uiConfig: {
                docExpansion: 'list',
                deepLinking: false,
            },
            staticCSP: true,
            transformStaticCSP: (header) => header,
        });
    }
};
exports.registerPlugins = registerPlugins;
//# sourceMappingURL=index.js.map