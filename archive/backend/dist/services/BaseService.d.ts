import { PrismaClient } from '@prisma/client';
export declare abstract class BaseService {
    protected prisma: PrismaClient;
    constructor(prisma: PrismaClient);
    protected handleError(error: unknown, context: string): never;
    protected validateLineAccountAccess(userLineAccountId: string, requestedLineAccountId?: string): string;
}
//# sourceMappingURL=BaseService.d.ts.map