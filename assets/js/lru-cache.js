/**
 * LRU (Least Recently Used) Cache Implementation
 * 
 * A cache that automatically evicts the least recently used items when the size limit is exceeded.
 * Uses a Map to maintain insertion order and track access patterns.
 * 
 * @class LRUCache
 * @example
 * const cache = new LRUCache(10); // Max 10 items
 * cache.set('key1', 'value1');
 * const value = cache.get('key1'); // Returns 'value1' and marks as recently used
 * cache.has('key1'); // Returns true
 * cache.clear(); // Removes all items
 */
class LRUCache {
    /**
     * Create an LRU cache
     * @param {number} maxSize - Maximum number of items to store in cache
     */
    constructor(maxSize) {
        if (typeof maxSize !== 'number' || maxSize <= 0) {
            throw new Error('maxSize must be a positive number');
        }
        
        this.maxSize = maxSize;
        this.cache = new Map();
    }
    
    /**
     * Get item from cache
     * When an item is accessed, it's moved to the end (most recently used position)
     * 
     * @param {string} key - Cache key
     * @returns {any} Cached value or null if not found
     */
    get(key) {
        if (!this.cache.has(key)) {
            return null;
        }
        
        // Get the value
        const value = this.cache.get(key);
        
        // Move to end (most recently used) by deleting and re-adding
        this.cache.delete(key);
        this.cache.set(key, value);
        
        return value;
    }
    
    /**
     * Set item in cache
     * If the key already exists, it's updated and moved to the end
     * If adding a new item exceeds maxSize, the least recently used item (first item) is evicted
     * 
     * @param {string} key - Cache key
     * @param {any} value - Value to cache
     */
    set(key, value) {
        // If key exists, remove it first (will be re-added at the end)
        if (this.cache.has(key)) {
            this.cache.delete(key);
        }
        
        // Add to end (most recently used position)
        this.cache.set(key, value);
        
        // Evict oldest (least recently used) if over limit
        // The first item in the Map is the least recently used
        if (this.cache.size > this.maxSize) {
            const firstKey = this.cache.keys().next().value;
            this.cache.delete(firstKey);
        }
    }
    
    /**
     * Check if key exists in cache
     * Note: This does NOT update the access order (doesn't mark as recently used)
     * 
     * @param {string} key - Cache key
     * @returns {boolean} True if key exists, false otherwise
     */
    has(key) {
        return this.cache.has(key);
    }
    
    /**
     * Clear all items from cache
     */
    clear() {
        this.cache.clear();
    }
    
    /**
     * Get current cache size
     * @returns {number} Number of items in cache
     */
    get size() {
        return this.cache.size;
    }
    
    /**
     * Get all keys in cache (in LRU order: oldest to newest)
     * @returns {Array<string>} Array of cache keys
     */
    keys() {
        return Array.from(this.cache.keys());
    }
    
    /**
     * Delete a specific key from cache
     * @param {string} key - Cache key to delete
     * @returns {boolean} True if key was deleted, false if it didn't exist
     */
    delete(key) {
        return this.cache.delete(key);
    }
}

// Export for use in other modules (if using ES6 modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LRUCache;
}
