"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.build = void 0;
const fastify_1 = __importDefault(require("fastify"));
const plugins_1 = require("@/plugins");
const routes_1 = require("@/routes");
const build = async () => {
    const fastify = (0, fastify_1.default)({
        logger: false,
        requestIdHeader: 'x-request-id',
        requestIdLogLabel: 'requestId',
        genReqId: () => {
            return `test_req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
        },
    });
    await (0, plugins_1.registerPlugins)(fastify);
    await (0, routes_1.registerRoutes)(fastify);
    return fastify;
};
exports.build = build;
//# sourceMappingURL=helper.js.map