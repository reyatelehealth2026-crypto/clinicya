import { FastifyRequest, FastifyReply } from 'fastify';
declare module 'fastify' {
    interface FastifyReply {
        success<T>(data: T, meta?: any): FastifyReply;
        error(code: string, message: string, details?: any, statusCode?: number): FastifyReply;
    }
}
export declare const responseFormatter: (_request: FastifyRequest, reply: FastifyReply) => Promise<void>;
//# sourceMappingURL=responseFormatter.d.ts.map