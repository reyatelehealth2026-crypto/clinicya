import { FastifyRequest, FastifyReply } from 'fastify';
export declare const authenticate: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
export declare const authorize: (permissions: string[]) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=auth.d.ts.map