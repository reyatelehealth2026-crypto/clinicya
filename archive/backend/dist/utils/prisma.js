"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.prisma = void 0;
const client_1 = require("@prisma/client");
const createPrismaClient = () => {
    return new client_1.PrismaClient({
        log: [
            { level: 'error', emit: 'stdout' },
            { level: 'warn', emit: 'stdout' },
        ],
    });
};
exports.prisma = globalThis.__prisma ?? createPrismaClient();
if (process.env['NODE_ENV'] !== 'production') {
    globalThis.__prisma = exports.prisma;
}
process.on('beforeExit', async () => {
    await exports.prisma.$disconnect();
});
//# sourceMappingURL=prisma.js.map