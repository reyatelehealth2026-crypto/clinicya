export interface APIResponse<T = unknown> {
    success: boolean;
    data?: T;
    error?: APIError;
    meta?: ResponseMeta;
}
export interface APIError {
    code: string;
    message: string;
    details?: Record<string, unknown>;
    timestamp: string;
}
export interface ResponseMeta {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
}
export interface PaginationParams {
    page?: number;
    limit?: number;
    sort?: string;
    order?: 'asc' | 'desc';
}
export interface FilterParams {
    dateFrom?: string;
    dateTo?: string;
    status?: string[];
    search?: string;
    customerId?: string;
}
export interface JWTPayload {
    userId: string;
    role: string;
    lineAccountId: string;
    permissions: string[];
    iat: number;
    exp: number;
}
export interface RequestContext {
    user: JWTPayload;
    requestId: string;
    ipAddress: string;
    userAgent: string;
}
//# sourceMappingURL=index.d.ts.map