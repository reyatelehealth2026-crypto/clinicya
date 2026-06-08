import { FastifyRequest, FastifyReply } from 'fastify';
import { ZodSchema } from 'zod';
export declare const validateRequest: <T extends ZodSchema>(schema: T) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const validateQuery: <T extends ZodSchema>(schema: T) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=validation.d.ts.map