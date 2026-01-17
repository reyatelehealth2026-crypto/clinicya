/**
 * Performance Tracker
 * 
 * Tracks and logs performance metrics for inbox-v2
 * Measures: page load, conversation switching, message rendering, API calls
 * 
 * Requirements: 12.1, 12.2, 12.3, 12.4, 12.5
 * 
 * @version 1.0
 */

class PerformanceTracker {
    constructor() {
        this.metrics = [];
        this.apiEndpoint = '/api/inbox-v2.php?action=logPerformanceMetric';
        this.batchSize = 10; // Send metrics in batches
        this.batchTimeout = 5000; // Send batch every 5 seconds
        this.batchTimer = null;
        
        // Performance thresholds for warnings (Requirements: 12.5)
        this.thresholds = {
            page_load: 2000,           // 2 seconds
            conversation_switch: 1000,  // 1 second
            message_render: 200,        // 200ms
            api_call: 500,              // 500ms
            frame_time: 16.67           // 60fps = 16.67ms per frame
        };
        
        // Frame rate monitoring state (Requirements: 5.2, 6.4)
        this.frameMonitoring = {
            active: false,
            lastFrameTime: null,
            frameCount: 0,
            droppedFrames: 0,
            scrollStartTime: null,
            scrollElement: null
        };
        
        // Start batch timer
        this.startBatchTimer();
        
        // Track page load on initialization
        this.trackPageLoad();
    }
    
    /**
     * Track page load time (Time to Interactive)
     * Requirements: 12.1
     */
    trackPageLoad() {
        if (!window.performance || !window.performance.timing) {
            console.warn('Performance API not available');
            return;
        }
        
        // Wait for page to be fully loaded
        if (document.readyState === 'complete') {
            this.measurePageLoad();
        } else {
            window.addEventListener('load', () => {
                // Give a small delay to ensure everything is interactive
                setTimeout(() => this.measurePageLoad(), 100);
            });
        }
    }
    
    /**
     * Measure page load time
     */
    measurePageLoad() {
        const timing = window.performance.timing;
        
        // Calculate Time to Interactive (TTI)
        // Using domInteractive as a proxy for TTI
        const tti = timing.domInteractive - timing.navigationStart;
        
        // Also track full load time
        const loadComplete = timing.loadEventEnd - timing.navigationStart;
        
        this.logMetric('page_load', tti, {
            load_complete: loadComplete,
            dom_content_loaded: timing.domContentLoadedEventEnd - timing.navigationStart,
            dom_interactive: tti
        });
        
        console.log(`Page Load Performance: TTI=${tti}ms, Complete=${loadComplete}ms`);
    }
    
    /**
     * Track conversation switch time
     * Requirements: 12.2
     * 
     * @param {string} userId User ID of conversation
     * @param {number} startTime Start timestamp (from performance.now())
     * @param {boolean} fromCache Whether loaded from cache
     * @param {number} messageCount Number of messages loaded
     */
    trackConversationSwitch(userId, startTime, fromCache = false, messageCount = 0) {
        const duration = Math.round(performance.now() - startTime);
        
        this.logMetric('conversation_switch', duration, {
            user_id: userId,
            from_cache: fromCache,
            message_count: messageCount
        });
        
        // Log warning if threshold exceeded (Requirements: 12.5)
        if (duration > this.thresholds.conversation_switch) {
            console.warn(`PERFORMANCE WARNING: Conversation switch took ${duration}ms (threshold: ${this.thresholds.conversation_switch}ms)`, {
                user_id: userId,
                from_cache: fromCache,
                message_count: messageCount
            });
        }
    }
    
    /**
     * Track message render time
     * Requirements: 12.3
     * 
     * @param {number} messageCount Number of messages rendered
     * @param {number} startTime Start timestamp (from performance.now())
     * @param {string} renderType Type of render: 'initial', 'append', 'prepend'
     */
    trackMessageRender(messageCount, startTime, renderType = 'initial') {
        const duration = Math.round(performance.now() - startTime);
        
        this.logMetric('message_render', duration, {
            message_count: messageCount,
            render_type: renderType,
            per_message: messageCount > 0 ? Math.round(duration / messageCount) : 0
        });
        
        // Log warning if threshold exceeded (Requirements: 12.5)
        if (duration > this.thresholds.message_render) {
            console.warn(`PERFORMANCE WARNING: Message render took ${duration}ms (threshold: ${this.thresholds.message_render}ms)`, {
                message_count: messageCount,
                render_type: renderType
            });
        }
    }
    
    /**
     * Track API call time
     * Requirements: 12.4
     * 
     * @param {string} endpoint API endpoint
     * @param {number} startTime Start timestamp (from performance.now())
     * @param {boolean} success Whether the call succeeded
     * @param {number} statusCode HTTP status code
     */
    trackApiCall(endpoint, startTime, success = true, statusCode = 200) {
        const duration = Math.round(performance.now() - startTime);
        
        this.logMetric('api_call', duration, {
            endpoint: endpoint,
            success: success,
            status_code: statusCode
        });
        
        // Log warning if threshold exceeded (Requirements: 12.5)
        if (duration > this.thresholds.api_call) {
            console.warn(`PERFORMANCE WARNING: API call took ${duration}ms (threshold: ${this.thresholds.api_call}ms)`, {
                endpoint: endpoint,
                success: success,
                status_code: statusCode
            });
        }
    }
    
    /**
     * Log a performance metric
     * 
     * @param {string} metricType Type of metric
     * @param {number} durationMs Duration in milliseconds
     * @param {object} details Additional details
     */
    logMetric(metricType, durationMs, details = {}) {
        const metric = {
            metric_type: metricType,
            duration_ms: durationMs,
            operation_details: details,
            user_agent: navigator.userAgent,
            timestamp: Date.now()
        };
        
        // Add to batch
        this.metrics.push(metric);
        
        // Send immediately if batch is full
        if (this.metrics.length >= this.batchSize) {
            this.sendMetrics();
        }
    }
    
    /**
     * Start batch timer to send metrics periodically
     */
    startBatchTimer() {
        this.batchTimer = setInterval(() => {
            if (this.metrics.length > 0) {
                this.sendMetrics();
            }
        }, this.batchTimeout);
    }
    
    /**
     * Send metrics to server
     */
    async sendMetrics() {
        if (this.metrics.length === 0) return;
        
        // Get metrics to send and clear the queue
        const metricsToSend = [...this.metrics];
        this.metrics = [];
        
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    metrics: metricsToSend
                })
            });
            
            if (!response.ok) {
                console.error('Failed to send performance metrics:', response.statusText);
                // Don't retry - just log the error
            }
        } catch (error) {
            console.error('Error sending performance metrics:', error);
            // Don't retry - just log the error
        }
    }
    
    /**
     * Flush all pending metrics (call before page unload)
     */
    flush() {
        if (this.metrics.length > 0) {
            // Use sendBeacon for reliable delivery during page unload
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify({ metrics: this.metrics })], {
                    type: 'application/json'
                });
                navigator.sendBeacon(this.apiEndpoint, blob);
            } else {
                // Fallback to synchronous XHR (not recommended but better than nothing)
                this.sendMetrics();
            }
            this.metrics = [];
        }
    }
    
    /**
     * Start monitoring frame rate during scrolling
     * Requirements: 5.2, 6.4
     * 
     * @param {HTMLElement} scrollElement Element being scrolled (conversation list or message list)
     * @param {string} scrollType Type of scroll: 'conversation_list' or 'message_list'
     */
    startFrameRateMonitoring(scrollElement, scrollType = 'conversation_list') {
        if (!scrollElement) {
            console.warn('Cannot start frame rate monitoring: no scroll element provided');
            return;
        }
        
        // Reset monitoring state
        this.frameMonitoring = {
            active: true,
            lastFrameTime: performance.now(),
            frameCount: 0,
            droppedFrames: 0,
            scrollStartTime: performance.now(),
            scrollElement: scrollElement,
            scrollType: scrollType
        };
        
        // Start measuring frames
        this.measureFrame();
        
        // Stop monitoring when scrolling ends
        let scrollTimeout;
        const stopMonitoring = () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                this.stopFrameRateMonitoring();
            }, 150); // Stop 150ms after last scroll event
        };
        
        scrollElement.addEventListener('scroll', stopMonitoring, { passive: true });
        
        // Store cleanup function
        this.frameMonitoring.cleanup = () => {
            scrollElement.removeEventListener('scroll', stopMonitoring);
            clearTimeout(scrollTimeout);
        };
    }
    
    /**
     * Measure frame time using requestAnimationFrame
     * Requirements: 5.2, 6.4
     */
    measureFrame() {
        if (!this.frameMonitoring.active) return;
        
        const currentTime = performance.now();
        const frameTime = currentTime - this.frameMonitoring.lastFrameTime;
        
        // Count frames
        this.frameMonitoring.frameCount++;
        
        // Check if frame time exceeds 60fps threshold (16.67ms)
        if (frameTime > this.thresholds.frame_time) {
            this.frameMonitoring.droppedFrames++;
            
            // Log warning for dropped frames (Requirements: 12.5)
            console.warn(`PERFORMANCE WARNING: Frame dropped during scrolling. Frame time: ${frameTime.toFixed(2)}ms (threshold: ${this.thresholds.frame_time}ms)`, {
                scroll_type: this.frameMonitoring.scrollType,
                frame_time: frameTime,
                fps: Math.round(1000 / frameTime)
            });
        }
        
        // Update last frame time
        this.frameMonitoring.lastFrameTime = currentTime;
        
        // Continue measuring
        requestAnimationFrame(() => this.measureFrame());
    }
    
    /**
     * Stop monitoring frame rate and log results
     * Requirements: 5.2, 6.4
     */
    stopFrameRateMonitoring() {
        if (!this.frameMonitoring.active) return;
        
        this.frameMonitoring.active = false;
        
        // Calculate metrics
        const totalDuration = performance.now() - this.frameMonitoring.scrollStartTime;
        const averageFps = Math.round((this.frameMonitoring.frameCount / totalDuration) * 1000);
        const droppedFramePercentage = this.frameMonitoring.frameCount > 0 
            ? Math.round((this.frameMonitoring.droppedFrames / this.frameMonitoring.frameCount) * 100)
            : 0;
        
        // Log scrolling performance metric
        this.logMetric('scroll_performance', totalDuration, {
            scroll_type: this.frameMonitoring.scrollType,
            frame_count: this.frameMonitoring.frameCount,
            dropped_frames: this.frameMonitoring.droppedFrames,
            dropped_frame_percentage: droppedFramePercentage,
            average_fps: averageFps,
            target_fps: 60
        });
        
        // Log summary
        console.log(`Scroll Performance (${this.frameMonitoring.scrollType}):`, {
            duration: `${totalDuration.toFixed(0)}ms`,
            frames: this.frameMonitoring.frameCount,
            dropped: this.frameMonitoring.droppedFrames,
            dropped_percentage: `${droppedFramePercentage}%`,
            average_fps: averageFps,
            performance: droppedFramePercentage < 5 ? 'EXCELLENT' : droppedFramePercentage < 10 ? 'GOOD' : 'NEEDS IMPROVEMENT'
        });
        
        // Clean up event listeners
        if (this.frameMonitoring.cleanup) {
            this.frameMonitoring.cleanup();
        }
        
        // Reset state
        this.frameMonitoring = {
            active: false,
            lastFrameTime: null,
            frameCount: 0,
            droppedFrames: 0,
            scrollStartTime: null,
            scrollElement: null
        };
    }
    
    /**
     * Clean up resources
     */
    destroy() {
        // Stop frame monitoring if active
        if (this.frameMonitoring.active) {
            this.stopFrameRateMonitoring();
        }
        
        if (this.batchTimer) {
            clearInterval(this.batchTimer);
            this.batchTimer = null;
        }
        this.flush();
    }
}

// Create global instance
window.performanceTracker = new PerformanceTracker();

// Flush metrics before page unload
window.addEventListener('beforeunload', () => {
    if (window.performanceTracker) {
        window.performanceTracker.flush();
    }
});

// Also flush on visibility change (when tab becomes hidden)
document.addEventListener('visibilitychange', () => {
    if (document.hidden && window.performanceTracker) {
        window.performanceTracker.flush();
    }
});
