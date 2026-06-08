"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.registerRoutes = void 0;
const config_1 = require("@/config/config");
const auth_1 = __importDefault(require("@/routes/auth"));
const dashboard_1 = __importDefault(require("@/routes/dashboard"));
const orders_1 = __importDefault(require("@/routes/orders"));
const payments_1 = __importDefault(require("@/routes/payments"));
const customers_1 = __importDefault(require("@/routes/customers"));
const health_1 = __importDefault(require("@/routes/health"));
const audit_1 = __importDefault(require("@/routes/audit"));
const security_1 = __importDefault(require("@/routes/security"));
const registerRoutes = async (fastify) => {
    await fastify.register(health_1.default);
    await fastify.register(async (fastify) => {
        await fastify.register(auth_1.default, { prefix: '/auth' });
        await fastify.register(dashboard_1.default, { prefix: '/dashboard' });
        await fastify.register(orders_1.default, { prefix: '/orders' });
        await fastify.register(payments_1.default, { prefix: '/payments' });
        await fastify.register(customers_1.default, { prefix: '/customers' });
        await fastify.register(audit_1.default, { prefix: '/audit' });
        await fastify.register(security_1.default, { prefix: '/security' });
    }, { prefix: config_1.config.API_PREFIX });
};
exports.registerRoutes = registerRoutes;
//# sourceMappingURL=index.js.map