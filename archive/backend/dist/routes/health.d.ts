import { FastifyInstance } from 'fastify';
import { WebSocketService } from '@/services/WebSocketService';
declare module 'fastify' {
    interface FastifyInstance {
        webSocketService: WebSocketService;
    }
}
export default function healthRoutes(fastify: FastifyInstance): Promise<void>;
//# sourceMappingURL=health.d.ts.map