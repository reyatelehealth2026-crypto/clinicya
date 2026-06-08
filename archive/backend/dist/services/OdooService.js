"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OdooService = void 0;
const BaseService_1 = require("./BaseService");
const CircuitBreaker_1 = require("@/utils/CircuitBreaker");
const RetryHandler_1 = require("@/utils/RetryHandler");
const logger_1 = require("@/utils/logger");
const config_1 = require("@/config/config");
class OdooService extends BaseService_1.BaseService {
    circuitBreaker;
    retryHandler;
    baseUrl;
    apiKey;
    constructor(prisma) {
        super(prisma);
        this.baseUrl = config_1.config.ODOO_API_URL || '';
        this.apiKey = config_1.config.ODOO_API_KEY || '';
        if (!this.baseUrl || !this.apiKey) {
            logger_1.logger.warn('Odoo API configuration missing, service will be disabled');
        }
        this.circuitBreaker = new CircuitBreaker_1.CircuitBreaker('OdooService', {
            failureThreshold: 5,
            recoveryTimeout: 60000,
            successThreshold: 3,
            timeout: 10000,
        });
        this.retryHandler = new RetryHandler_1.RetryHandler('OdooService', {
            maxRetries: 3,
            baseDelay: 1000,
            maxDelay: 10000,
            backoffMultiplier: 2,
            jitter: true,
        });
    }
    async getOrders(filters = {}) {
        if (!this.isConfigured()) {
            return this.getCachedOrders(filters);
        }
        return this.circuitBreaker.call(async () => {
            return this.retryHandler.executeWithRetry(async () => {
                const response = await this.makeRequest('/api/orders', {
                    method: 'GET',
                    params: filters,
                });
                await this.cacheOrders(response.data);
                return response.data;
            }, RetryHandler_1.RetryHandler.shouldRetryError);
        });
    }
    async getCustomers(filters = {}) {
        if (!this.isConfigured()) {
            return this.getCachedCustomers(filters);
        }
        return this.circuitBreaker.call(async () => {
            return this.retryHandler.executeWithRetry(async () => {
                const response = await this.makeRequest('/api/customers', {
                    method: 'GET',
                    params: filters,
                });
                await this.cacheCustomers(response.data);
                return response.data;
            }, RetryHandler_1.RetryHandler.shouldRetryError);
        });
    }
    async getInvoices(filters = {}) {
        if (!this.isConfigured()) {
            return this.getCachedInvoices(filters);
        }
        return this.circuitBreaker.call(async () => {
            return this.retryHandler.executeWithRetry(async () => {
                const response = await this.makeRequest('/api/invoices', {
                    method: 'GET',
                    params: filters,
                });
                await this.cacheInvoices(response.data);
                return response.data;
            }, RetryHandler_1.RetryHandler.shouldRetryError);
        });
    }
    async updateOrderStatus(orderId, status) {
        if (!this.isConfigured()) {
            throw new Error('Odoo API not configured');
        }
        return this.circuitBreaker.call(async () => {
            return this.retryHandler.executeWithRetry(async () => {
                await this.makeRequest(`/api/orders/${orderId}`, {
                    method: 'PUT',
                    body: { state: status },
                });
                await this.invalidateOrdersCache();
            }, RetryHandler_1.RetryHandler.shouldRetryError);
        });
    }
    getCircuitBreakerStats() {
        return this.circuitBreaker.getStats();
    }
    async healthCheck() {
        if (!this.isConfigured()) {
            return { status: 'error', message: 'Odoo API not configured' };
        }
        try {
            await this.circuitBreaker.call(async () => {
                await this.makeRequest('/api/health', { method: 'GET' });
            });
            return { status: 'ok', message: 'Odoo API is healthy' };
        }
        catch (error) {
            return {
                status: 'error',
                message: `Odoo API health check failed: ${error.message}`
            };
        }
    }
    async makeRequest(endpoint, options) {
        const url = new URL(endpoint, this.baseUrl);
        if (options.params) {
            Object.entries(options.params).forEach(([key, value]) => {
                if (value !== undefined) {
                    url.searchParams.append(key, String(value));
                }
            });
        }
        const fetchOptions = {
            method: options.method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${this.apiKey}`,
            },
        };
        if (options.body) {
            fetchOptions.body = JSON.stringify(options.body);
        }
        const response = await fetch(url.toString(), fetchOptions);
        if (!response.ok) {
            const error = new Error(`Odoo API error: ${response.status} ${response.statusText}`);
            error.status = response.status;
            throw error;
        }
        return response.json();
    }
    isConfigured() {
        return !!(this.baseUrl && this.apiKey);
    }
    async getCachedOrders(filters) {
        try {
            logger_1.logger.info('Using cached orders fallback', { filters });
            return [];
        }
        catch (error) {
            logger_1.logger.error('Failed to get cached orders', { error: String(error) });
            return [];
        }
    }
    async getCachedCustomers(filters) {
        try {
            logger_1.logger.info('Using cached customers fallback', { filters });
            return [];
        }
        catch (error) {
            logger_1.logger.error('Failed to get cached customers', { error: String(error) });
            return [];
        }
    }
    async getCachedInvoices(filters) {
        try {
            logger_1.logger.info('Using cached invoices fallback', { filters });
            return [];
        }
        catch (error) {
            logger_1.logger.error('Failed to get cached invoices', { error: String(error) });
            return [];
        }
    }
    async cacheOrders(orders) {
        logger_1.logger.debug(`Would cache ${orders.length} orders`);
    }
    async cacheCustomers(customers) {
        logger_1.logger.debug(`Would cache ${customers.length} customers`);
    }
    async cacheInvoices(invoices) {
        logger_1.logger.debug(`Would cache ${invoices.length} invoices`);
    }
    async invalidateOrdersCache() {
        logger_1.logger.debug('Would invalidate orders cache');
    }
}
exports.OdooService = OdooService;
//# sourceMappingURL=OdooService.js.map