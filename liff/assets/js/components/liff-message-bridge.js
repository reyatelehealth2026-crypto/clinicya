/**
 * LIFF Message Bridge Component
 * Handles sending action messages to LINE OA bot via liff.sendMessages()
 * with API fallback for external browser
 * 
 * Requirements: 20.1, 20.2, 20.4, 20.5, 20.6, 20.7, 20.8, 20.10
 * - Send action messages via liff.sendMessages()
 * - Handle different action types
 * - Fallback to API when liff.sendMessages() unavailable
 */

class LiffMessageBridge {
    constructor() {
        this.config = window.APP_CONFIG || {};
        this.baseUrl = this.config.BASE_URL || '';
        this.accountId = this.config.ACCOUNT_ID || 1;
        
        // Message templates for different actions
        // Requirements: 20.4, 20.5, 20.6, 20.7, 20.8
        this.messageTemplates = {
            'order_placed': (data) => `สั่งซื้อสำเร็จ #${data.orderId}`,
            'appointment_booked': (data) => `นัดหมายสำเร็จ ${data.date} ${data.time}`,
            'consult_request': (data) => `ขอปรึกษาเภสัชกร`,
            'points_redeemed': (data) => `แลกแต้มสำเร็จ ${data.points} แต้ม`,
            'health_updated': (data) => `อัพเดทข้อมูลสุขภาพ`,
            'prescription_request': (data) => `ขออนุมัติยา Rx #${data.productId || ''}`,
            'video_call_ended': (data) => `สิ้นสุดการปรึกษา ${data.duration || ''}`,
            'cart_checkout': (data) => `ชำระเงินสำเร็จ ฿${data.total || 0}`
        };
    }

    /**
     * Check if LIFF sendMessages is available
     * Requirements: 20.10
     * @returns {boolean}
     */
    isLiffSendAvailable() {
        return typeof liff !== 'undefined' && 
               liff.isInClient && 
               liff.isInClient() && 
               typeof liff.sendMessages === 'function';
    }

    /**
     * Send action message to LINE OA bot
     * Requirements: 20.1, 20.2
     * @param {string} action - Action type (order_placed, appointment_booked, etc.)
     * @param {object} data - Action data
     * @returns {Promise<{success: boolean, method: string, error?: string}>}
     */
    async sendActionMessage(action, data = {}) {
        // Generate message from template
        const messageGenerator = this.messageTemplates[action];
        if (!messageGenerator) {
            console.warn(`Unknown action type: ${action}`);
            return { success: false, method: 'none', error: 'Unknown action type' };
        }

        const message = messageGenerator(data);
        
        // Show loading state (Requirement 20.11)
        this.showLoadingState();

        try {
            let result;
            
            // Try LIFF sendMessages first
            if (this.isLiffSendAvailable()) {
                result = await this.sendViaLiff(message, action, data);
            } else {
                // Fallback to API (Requirement 20.10)
                result = await this.sendViaApi(action, data, message);
            }

            // Show success feedback
            if (result.success) {
                this.showSuccessFeedback(action);
            } else {
                this.showErrorFeedback(result.error);
            }

            return result;

        } catch (error) {
            console.error('LiffMessageBridge error:', error);
            this.showErrorFeedback(error.message);
            return { success: false, method: 'error', error: error.message };
        } finally {
            this.hideLoadingState();
        }
    }

    /**
     * Send message via LIFF SDK
     * Requirements: 20.1
     * @param {string} message - Message text
     * @param {string} action - Action type
     * @param {object} data - Action data
     * @returns {Promise<{success: boolean, method: string}>}
     */
    async sendViaLiff(message, action, data) {
        try {
            await liff.sendMessages([{
                type: 'text',
                text: message
            }]);
            
            console.log('✅ LIFF message sent:', message);
            return { success: true, method: 'liff' };
            
        } catch (error) {
            console.error('LIFF sendMessages failed:', error);
            
            // Fallback to API on LIFF error
            console.log('Falling back to API...');
            return await this.sendViaApi(action, data, message);
        }
    }

    /**
     * Send message via API (fallback)
     * Requirements: 20.10
     * @param {string} action - Action type
     * @param {object} data - Action data
     * @param {string} message - Message text
     * @returns {Promise<{success: boolean, method: string}>}
     */
    async sendViaApi(action, data, message) {
        try {
            const userId = window.store?.get('profile')?.userId || '';
            
            if (!userId) {
                console.warn('No user ID available for API fallback');
                return { success: false, method: 'api', error: 'No user ID' };
            }

            const response = await fetch(`${this.baseUrl}/api/liff-bridge.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: action,
                    data: data,
                    message: message,
                    line_user_id: userId,
                    line_account_id: this.accountId
                })
            });

            // Handle empty or non-JSON responses
            const text = await response.text();
            if (!text || text.trim() === '') {
                console.warn('API returned empty response');
                return { success: false, method: 'api', error: 'Empty response from server' };
            }

            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                console.error('API returned invalid JSON:', text.substring(0, 200));
                return { success: false, method: 'api', error: 'Invalid JSON response' };
            }
            
            if (result.success) {
                console.log('✅ API message sent:', action);
                return { success: true, method: 'api' };
            } else {
                return { success: false, method: 'api', error: result.message };
            }

        } catch (error) {
            console.error('API fallback failed:', error);
            return { success: false, method: 'api', error: error.message };
        }
    }

    /**
     * Show loading state
     * Requirements: 20.11
     */
    showLoadingState() {
        // Create or show loading overlay
        let overlay = document.getElementById('liff-bridge-loading');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'liff-bridge-loading';
            overlay.className = 'liff-bridge-loading';
            overlay.innerHTML = `
                <div class="liff-bridge-loading-content">
                    <div class="liff-bridge-spinner"></div>
                    <p>กำลังส่งข้อมูล...</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.classList.add('visible');
    }

    /**
     * Hide loading state
     */
    hideLoadingState() {
        const overlay = document.getElementById('liff-bridge-loading');
        if (overlay) {
            overlay.classList.remove('visible');
        }
    }

    /**
     * Show success feedback
     * Requirements: 20.11
     * @param {string} action - Action type
     */
    showSuccessFeedback(action) {
        const messages = {
            'order_placed': 'ส่งข้อมูลออเดอร์สำเร็จ',
            'appointment_booked': 'ส่งข้อมูลนัดหมายสำเร็จ',
            'consult_request': 'ส่งคำขอปรึกษาสำเร็จ',
            'points_redeemed': 'ส่งข้อมูลแลกแต้มสำเร็จ',
            'health_updated': 'ส่งข้อมูลสุขภาพสำเร็จ'
        };

        const message = messages[action] || 'ส่งข้อมูลสำเร็จ';
        
        if (window.liffApp?.showToast) {
            window.liffApp.showToast(message, 'success');
        }
    }

    /**
     * Show error feedback
     * @param {string} error - Error message
     */
    showErrorFeedback(error) {
        if (window.liffApp?.showToast) {
            window.liffApp.showToast('ไม่สามารถส่งข้อมูลได้', 'error');
        }
    }

    // ==================== Convenience Methods ====================

    /**
     * Send order placed message
     * Requirements: 20.4
     * @param {string} orderId - Order ID
     * @param {object} orderData - Additional order data
     */
    async sendOrderPlaced(orderId, orderData = {}) {
        return this.sendActionMessage('order_placed', {
            orderId,
            ...orderData
        });
    }

    /**
     * Send appointment booked message
     * Requirements: 20.5
     * @param {string} date - Appointment date
     * @param {string} time - Appointment time
     * @param {object} appointmentData - Additional appointment data
     */
    async sendAppointmentBooked(date, time, appointmentData = {}) {
        return this.sendActionMessage('appointment_booked', {
            date,
            time,
            ...appointmentData
        });
    }

    /**
     * Send pharmacist consultation request
     * Requirements: 20.6
     * @param {object} consultData - Consultation data
     */
    async sendConsultRequest(consultData = {}) {
        return this.sendActionMessage('consult_request', consultData);
    }

    /**
     * Send points redeemed message
     * Requirements: 20.7
     * @param {number} points - Points redeemed
     * @param {object} redeemData - Additional redeem data
     */
    async sendPointsRedeemed(points, redeemData = {}) {
        return this.sendActionMessage('points_redeemed', {
            points,
            ...redeemData
        });
    }

    /**
     * Send health profile updated message
     * Requirements: 20.8
     * @param {object} healthData - Health profile data
     */
    async sendHealthUpdated(healthData = {}) {
        return this.sendActionMessage('health_updated', healthData);
    }

    /**
     * Send prescription request message
     * @param {number} productId - Product ID
     * @param {object} prescriptionData - Prescription data
     */
    async sendPrescriptionRequest(productId, prescriptionData = {}) {
        return this.sendActionMessage('prescription_request', {
            productId,
            ...prescriptionData
        });
    }
}

// Create global instance
window.LiffMessageBridge = LiffMessageBridge;
window.liffMessageBridge = new LiffMessageBridge();
