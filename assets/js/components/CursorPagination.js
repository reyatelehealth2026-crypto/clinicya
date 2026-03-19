/**
 * CursorPagination — Efficient pagination for large datasets
 *
 * แทน offset/limit ด้วย cursor-based navigation ซึ่งไม่ช้าลงเมื่อ offset ใหญ่
 * (MySQL LIMIT 10000,50 ต้องสแกน 10,050 rows, cursor scan แค่ 50 rows)
 *
 * Usage:
 *   const pager = new CursorPagination({ pageSize: 50 });
 *   const page = await pager.fetchPage(params => apiCall('customer_list', params));
 *   // page.data, page.has_more, page.next_cursor
 *
 *   // Next page:
 *   const next = await pager.fetchPage(params => apiCall('customer_list', params), 'next');
 *
 * @version 1.0.0
 */
class CursorPagination {
    constructor(options = {}) {
        this.pageSize = options.pageSize || 50;
        this._cursor  = null;
        this._hasMore = false;
        this._loading = false;
        this._cache   = new Map();  // cacheKey → result
    }

    get hasMore()  { return this._hasMore; }
    get isLoading() { return this._loading; }

    /**
     * @param {Function} apiCall - async fn(params) → { data, has_more, next_cursor }
     * @param {'next'|'reset'} direction
     */
    async fetchPage(apiCall, direction = 'next') {
        if (this._loading) return null;
        if (direction === 'reset') {
            this.reset();
        }

        this._loading = true;

        const cacheKey = `${this._cursor || 'first'}`;
        if (this._cache.has(cacheKey)) {
            this._loading = false;
            return this._cache.get(cacheKey);
        }

        try {
            const params = {
                limit:  this.pageSize,
                cursor: this._cursor ?? undefined,
            };

            const result = await apiCall(params);
            if (!result) throw new Error('No response from API');

            this._cache.set(cacheKey, result);
            this._cursor  = result.next_cursor ?? null;
            this._hasMore = result.has_more    ?? false;

            return result;
        } catch (err) {
            console.error('[CursorPagination] fetchPage error:', err);
            throw err;
        } finally {
            this._loading = false;
        }
    }

    /** รีเซ็ตกลับไปหน้าแรก */
    reset() {
        this._cursor  = null;
        this._hasMore = false;
        this._cache.clear();
    }
}

if (typeof module !== 'undefined') module.exports = CursorPagination;
