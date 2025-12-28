/**
 * AI Chat Interface Component
 * Displays chat bubbles with animations and quick symptom selection buttons
 * 
 * Requirements: 7.1, 7.2
 * - Display a chat interface with quick symptom selection buttons
 * - Send symptom to AI and display typing indicator
 */

class AIChat {
    constructor(options = {}) {
        this.container = null;
        this.chatContainer = null;
        this.inputField = null;
        this.userId = options.userId || null;
        this.onSendMessage = options.onSendMessage || null;
        this.onSymptomSelect = options.onSymptomSelect || null;
        this.messages = [];
        this.isTyping = false;
        this.currentState = 'greeting';
        this.triageData = {};
        
        // New: Triage and AI mode settings
        this.triageMode = options.triageMode || false;
        this.useGemini = options.useGemini || false;
        this.showDrugInteractions = options.showDrugInteractions !== false;
        
        // Quick symptom buttons configuration
        this.quickSymptoms = options.quickSymptoms || [
            { icon: 'fa-head-side-virus', label: 'ปวดหัว', query: 'ปวดหัว' },
            { icon: 'fa-thermometer-half', label: 'ไข้หวัด', query: 'ไข้หวัด' },
            { icon: 'fa-lungs-virus', label: 'ไอ/เจ็บคอ', query: 'ไอ เจ็บคอ' },
            { icon: 'fa-stomach', label: 'ปวดท้อง', query: 'ปวดท้อง' },
            { icon: 'fa-allergies', label: 'แพ้อากาศ', query: 'แพ้อากาศ' },
            { icon: 'fa-bone', label: 'ปวดกล้ามเนื้อ', query: 'ปวดกล้ามเนื้อ' }
        ];
    }

    /**
     * Initialize the chat interface
     * @param {HTMLElement} container - Container element to render into
     */
    init(container) {
        this.container = container;
        this.render();
        this.setupEventListeners();
        this.loadConversationHistory();
    }

    /**
     * Render the chat interface
     * Requirements: 7.1 - Display chat interface with quick symptom selection buttons
     */
    render() {
        if (!this.container) return;

        this.container.innerHTML = `
            <div class="ai-chat-page">
                <!-- Header -->
                <div class="ai-chat-header">
                    <div class="ai-chat-header-content">
                        <button class="ai-chat-back-btn" onclick="window.router.navigate('/home')">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="ai-chat-header-info">
                            <div class="ai-chat-avatar">
                                <i class="fas fa-robot"></i>
                            </div>
                            <div class="ai-chat-header-text">
                                <h1 class="ai-chat-title">ผู้ช่วย AI ร้านยา</h1>
                                <p class="ai-chat-status">
                                    <span class="ai-chat-status-dot"></span>
                                    พร้อมให้บริการ
                                </p>
                            </div>
                        </div>
                        <button class="ai-chat-menu-btn" onclick="window.aiChat?.showMenu()">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>

                <!-- Chat Messages Container -->
                <div class="ai-chat-messages" id="ai-chat-messages">
                    <!-- Messages will be inserted here -->
                </div>

                <!-- Quick Action Menu -->
                <div class="ai-chat-quick-actions" id="ai-quick-actions">
                    <div class="ai-chat-actions-scroll">
                        <button class="ai-chat-action-btn" onclick="window.aiChat?.startTriageAssessment()">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #10B981, #059669);">
                                <i class="fas fa-stethoscope"></i>
                            </div>
                            <span>ซักประวัติ</span>
                        </button>
                        <button class="ai-chat-action-btn" onclick="window.aiChat?.requestPharmacistConsult()">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #3B82F6, #1D4ED8);">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <span>ปรึกษาเภสัชกร</span>
                        </button>
                        <button class="ai-chat-action-btn" onclick="window.router?.navigate('/shop')">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #F59E0B, #D97706);">
                                <i class="fas fa-store"></i>
                            </div>
                            <span>ร้านค้า</span>
                        </button>
                        <button class="ai-chat-action-btn" onclick="window.router?.navigate('/health-profile')">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #EC4899, #DB2777);">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <span>ข้อมูลสุขภาพ</span>
                        </button>
                        <button class="ai-chat-action-btn" onclick="window.router?.navigate('/orders')">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #8B5CF6, #7C3AED);">
                                <i class="fas fa-box"></i>
                            </div>
                            <span>ออเดอร์</span>
                        </button>
                        <button class="ai-chat-action-btn" onclick="window.router?.navigate('/points')">
                            <div class="ai-chat-action-icon" style="background: linear-gradient(135deg, #EF4444, #DC2626);">
                                <i class="fas fa-gift"></i>
                            </div>
                            <span>แต้มสะสม</span>
                        </button>
                    </div>
                </div>

                <!-- Quick Symptoms Section -->
                <div class="ai-chat-quick-symptoms" id="ai-quick-symptoms">
                    <p class="ai-chat-quick-label">เลือกอาการที่ต้องการปรึกษา</p>
                    <div class="ai-chat-symptom-grid">
                        ${this.renderQuickSymptoms()}
                    </div>
                </div>

                <!-- Input Area -->
                <div class="ai-chat-input-area">
                    <div class="ai-chat-input-container">
                        <button class="ai-chat-attach-btn" onclick="window.aiChat?.showAttachMenu()">
                            <i class="fas fa-plus"></i>
                        </button>
                        <input type="text" 
                               id="ai-chat-input" 
                               class="ai-chat-input" 
                               placeholder="พิมพ์อาการหรือคำถาม..."
                               autocomplete="off">
                        <button class="ai-chat-send-btn" id="ai-chat-send-btn" onclick="window.aiChat?.sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <!-- Attach Menu Modal -->
                <div class="ai-chat-modal hidden" id="ai-attach-menu">
                    <div class="ai-chat-modal-backdrop" onclick="window.aiChat?.hideAttachMenu()"></div>
                    <div class="ai-chat-modal-content ai-chat-attach-content">
                        <div class="ai-chat-modal-handle"></div>
                        <div class="ai-chat-attach-grid">
                            <button class="ai-chat-attach-item" onclick="window.aiChat?.takePhoto()">
                                <div class="ai-chat-attach-icon" style="background: #DBEAFE; color: #3B82F6;">
                                    <i class="fas fa-camera"></i>
                                </div>
                                <span>ถ่ายรูป</span>
                            </button>
                            <button class="ai-chat-attach-item" onclick="window.aiChat?.selectImage()">
                                <div class="ai-chat-attach-icon" style="background: #F3E8FF; color: #9333EA;">
                                    <i class="fas fa-image"></i>
                                </div>
                                <span>รูปภาพ</span>
                            </button>
                            <button class="ai-chat-attach-item" onclick="window.aiChat?.startVideoCall()">
                                <div class="ai-chat-attach-icon" style="background: #D1FAE5; color: #10B981;">
                                    <i class="fas fa-video"></i>
                                </div>
                                <span>Video Call</span>
                            </button>
                            <button class="ai-chat-attach-item" onclick="window.aiChat?.showHistory()">
                                <div class="ai-chat-attach-icon" style="background: #FEF3C7; color: #F59E0B;">
                                    <i class="fas fa-history"></i>
                                </div>
                                <span>ประวัติ</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Menu Modal -->
                <div class="ai-chat-modal hidden" id="ai-menu-modal">
                    <div class="ai-chat-modal-backdrop" onclick="window.aiChat?.hideMenu()"></div>
                    <div class="ai-chat-menu-dropdown">
                        <button class="ai-chat-menu-item" onclick="window.aiChat?.showHealthProfile()">
                            <i class="fas fa-user"></i>
                            <span>ข้อมูลสุขภาพ</span>
                        </button>
                        <button class="ai-chat-menu-item" onclick="window.aiChat?.showHistory()">
                            <i class="fas fa-clipboard-list"></i>
                            <span>ประวัติการปรึกษา</span>
                        </button>
                        <button class="ai-chat-menu-item" onclick="window.aiChat?.showMedications()">
                            <i class="fas fa-pills"></i>
                            <span>ยาที่ทานอยู่</span>
                        </button>
                        <div class="ai-chat-menu-divider"></div>
                        <button class="ai-chat-menu-item ai-chat-menu-item-danger" onclick="window.aiChat?.clearChat()">
                            <i class="fas fa-trash"></i>
                            <span>ล้างประวัติแชท</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        this.chatContainer = document.getElementById('ai-chat-messages');
        this.inputField = document.getElementById('ai-chat-input');
    }

    /**
     * Render quick symptom buttons
     * Requirements: 7.1 - Quick symptom selection buttons
     */
    renderQuickSymptoms() {
        return this.quickSymptoms.map(symptom => `
            <button class="ai-chat-symptom-btn" 
                    data-symptom="${symptom.query}"
                    onclick="window.aiChat?.selectSymptom('${symptom.query}')">
                <i class="fas ${symptom.icon}"></i>
                <span>${symptom.label}</span>
            </button>
        `).join('');
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Input field enter key
        if (this.inputField) {
            this.inputField.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Enable/disable send button based on input
            this.inputField.addEventListener('input', () => {
                const sendBtn = document.getElementById('ai-chat-send-btn');
                if (sendBtn) {
                    sendBtn.disabled = !this.inputField.value.trim();
                }
            });
        }
    }

    /**
     * Show welcome message
     */
    showWelcomeMessage() {
        const welcomeMessage = {
            type: 'ai',
            content: `สวัสดีค่ะ 👋\n\nยินดีให้บริการค่ะ ดิฉันพร้อมช่วยประเมินอาการและแนะนำยาเบื้องต้น\n\nเลือกอาการด้านล่าง หรือพิมพ์บอกอาการได้เลยค่ะ`,
            timestamp: new Date()
        };
        
        this.addMessage(welcomeMessage);
    }

    /**
     * Load conversation history from server
     */
    async loadConversationHistory() {
        if (!this.userId) {
            this.showWelcomeMessage();
            return;
        }

        try {
            const baseUrl = window.APP_CONFIG?.BASE_URL || '';
            const response = await fetch(`${baseUrl}/api/pharmacy-ai.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_history',
                    user_id: this.userId
                })
            });

            const data = await response.json();

            if (data.success && data.messages && data.messages.length > 0) {
                // Hide quick symptoms if there's history
                const quickSymptomsSection = document.getElementById('ai-quick-symptoms');
                if (quickSymptomsSection) {
                    quickSymptomsSection.classList.add('hidden');
                }

                // Render history messages
                data.messages.forEach(msg => {
                    this.messages.push(msg);
                    this.renderMessage(msg);
                });

                this.scrollToBottom();

                // Show continue message
                this.addMessage({
                    type: 'ai',
                    content: '--- ประวัติการสนทนาก่อนหน้า ---\n\nมีอะไรให้ช่วยเพิ่มเติมไหมคะ?',
                    timestamp: new Date()
                });
            } else {
                // No history, show welcome
                this.showWelcomeMessage();
            }
        } catch (error) {
            console.error('[AI Chat] Load history error:', error);
            this.showWelcomeMessage();
        }
    }

    /**
     * Add a message to the chat
     * @param {Object} message - Message object with type, content, timestamp
     */
    addMessage(message) {
        this.messages.push(message);
        this.renderMessage(message);
        this.scrollToBottom();
    }

    /**
     * Render a single message
     * @param {Object} message - Message object
     */
    renderMessage(message) {
        if (!this.chatContainer) return;

        const messageEl = document.createElement('div');
        messageEl.className = `ai-chat-message ai-chat-message-${message.type}`;
        
        if (message.type === 'ai') {
            messageEl.innerHTML = `
                <div class="ai-chat-message-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="ai-chat-bubble ai-chat-bubble-ai">
                    <div class="ai-chat-bubble-content">${this.formatMessage(message.content)}</div>
                    ${message.quickReplies ? this.renderQuickReplies(message.quickReplies) : ''}
                </div>
            `;
        } else {
            messageEl.innerHTML = `
                <div class="ai-chat-bubble ai-chat-bubble-user">
                    <div class="ai-chat-bubble-content">${this.escapeHtml(message.content)}</div>
                </div>
            `;
        }

        this.chatContainer.appendChild(messageEl);
    }

    /**
     * Format message content (convert newlines to <br>)
     * @param {string} content - Message content
     * @returns {string} Formatted HTML
     */
    formatMessage(content) {
        return this.escapeHtml(content).replace(/\n/g, '<br>');
    }

    /**
     * Escape HTML special characters
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Render quick reply buttons
     * @param {Array} quickReplies - Array of quick reply objects
     * @returns {string} HTML string
     */
    renderQuickReplies(quickReplies) {
        if (!quickReplies || quickReplies.length === 0) return '';
        
        return `
            <div class="ai-chat-quick-replies">
                ${quickReplies.map(qr => `
                    <button class="ai-chat-quick-reply-btn" 
                            onclick="window.aiChat?.handleQuickReply('${this.escapeHtml(qr.text)}')">
                        ${this.escapeHtml(qr.label)}
                    </button>
                `).join('')}
            </div>
        `;
    }

    /**
     * Handle quick reply button click
     * @param {string} text - Quick reply text
     */
    handleQuickReply(text) {
        this.addMessage({
            type: 'user',
            content: text,
            timestamp: new Date()
        });
        
        this.processMessage(text);
    }

    /**
     * Select a symptom from quick symptoms
     * Requirements: 7.2 - Send symptom to AI and display typing indicator
     * @param {string} symptom - Selected symptom
     */
    selectSymptom(symptom) {
        // Hide quick symptoms section
        const quickSymptomsSection = document.getElementById('ai-quick-symptoms');
        if (quickSymptomsSection) {
            quickSymptomsSection.classList.add('hidden');
        }

        // Add user message
        this.addMessage({
            type: 'user',
            content: symptom,
            timestamp: new Date()
        });

        // Callback if provided
        if (this.onSymptomSelect) {
            this.onSymptomSelect(symptom);
        }

        // Process with AI
        this.processMessage(symptom);
    }

    /**
     * Send message from input field
     */
    sendMessage() {
        if (!this.inputField) return;
        
        const message = this.inputField.value.trim();
        if (!message) return;

        // Clear input
        this.inputField.value = '';
        
        // Disable send button
        const sendBtn = document.getElementById('ai-chat-send-btn');
        if (sendBtn) sendBtn.disabled = true;

        // Hide quick symptoms if visible
        const quickSymptomsSection = document.getElementById('ai-quick-symptoms');
        if (quickSymptomsSection) {
            quickSymptomsSection.classList.add('hidden');
        }

        // Add user message
        this.addMessage({
            type: 'user',
            content: message,
            timestamp: new Date()
        });

        // Callback if provided
        if (this.onSendMessage) {
            this.onSendMessage(message);
        }

        // Process with AI
        this.processMessage(message);
    }

    /**
     * Process message with AI
     * Requirements: 7.2, 7.3, 7.4, 7.5 - Display typing indicator, AI responses, product recommendations, emergency detection
     * Enhanced: Triage mode, drug interactions, MIMS info
     * @param {string} message - User message
     */
    async processMessage(message) {
        // Client-side emergency check first (for immediate response)
        const clientEmergency = this.checkEmergencySymptoms(message);
        if (clientEmergency) {
            // Still process with AI but show alert immediately
            this.showEmergencyAlert(clientEmergency);
        }

        // Show typing indicator
        this.showTypingIndicator();

        try {
            const baseUrl = window.APP_CONFIG?.BASE_URL || '';
            const response = await fetch(`${baseUrl}/api/pharmacy-ai.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    user_id: this.userId,
                    state: this.currentState,
                    triage_data: this.triageData,
                    use_triage: this.triageMode,
                    use_gemini: this.useGemini
                })
            });

            const data = await response.json();

            // Simulate realistic typing delay based on response length
            const typingDelay = Math.min(500 + (data.response?.length || 0) * 10, 2000);
            await this.delay(typingDelay);

            // Hide typing indicator
            this.hideTypingIndicator();

            if (data.success) {
                this.currentState = data.state || this.currentState;
                this.triageData = data.data || this.triageData;
                
                // Update triage mode if server indicates it
                if (data.triage_mode !== undefined) {
                    this.triageMode = data.triage_mode;
                }

                // Add AI response with animation
                await this.addAIResponse({
                    content: data.response,
                    quickReplies: this.formatQuickReplies(data.quick_replies),
                    timestamp: new Date()
                });

                // Handle emergency symptoms from AI (Requirement 7.5)
                // Only show if not already shown by client-side check
                if (data.is_critical && !clientEmergency) {
                    this.showEmergencyAlert(data.emergency_info);
                }

                // Handle drug interactions warning (new feature)
                if (data.drug_interactions && data.drug_interactions.length > 0 && this.showDrugInteractions) {
                    await this.delay(300);
                    this.showDrugInteractionWarning(data.drug_interactions);
                }

                // Handle product recommendations (Requirement 7.4)
                if (data.products && data.products.length > 0) {
                    await this.delay(300);
                    this.showProductRecommendations(data.products);
                }

                // Handle MIMS information (new feature)
                if (data.mims_info) {
                    await this.delay(300);
                    this.showMIMSInfo(data.mims_info);
                }

                // Handle pharmacist consultation suggestion
                if (data.suggest_pharmacist) {
                    await this.delay(300);
                    this.showPharmacistSuggestion();
                }
                
                // Show user context info if available (allergies warning)
                if (data.user_context?.has_allergies && !this.shownAllergyWarning) {
                    this.showAllergyReminder(data.user_context.allergies);
                    this.shownAllergyWarning = true;
                }
            } else {
                this.addMessage({
                    type: 'ai',
                    content: 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง',
                    timestamp: new Date()
                });
            }
        } catch (error) {
            console.error('[AI Chat] Process error:', error);
            this.hideTypingIndicator();
            
            this.addMessage({
                type: 'ai',
                content: 'ขออภัยค่ะ ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่',
                timestamp: new Date()
            });
        }
    }

    /**
     * Add AI response with smooth animation
     * Requirements: 7.3 - Display AI response in chat bubble with smooth animation
     * @param {Object} message - AI message object
     */
    async addAIResponse(message) {
        if (!this.chatContainer) return;

        const messageEl = document.createElement('div');
        messageEl.className = 'ai-chat-message ai-chat-message-ai ai-chat-message-entering';
        messageEl.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-chat-bubble ai-chat-bubble-ai">
                <div class="ai-chat-bubble-content">${this.formatMessage(message.content)}</div>
                ${message.quickReplies ? this.renderQuickReplies(message.quickReplies) : ''}
            </div>
        `;

        this.chatContainer.appendChild(messageEl);
        this.scrollToBottom();

        // Trigger animation
        await this.delay(50);
        messageEl.classList.remove('ai-chat-message-entering');
        messageEl.classList.add('ai-chat-message-entered');

        // Store message
        this.messages.push({
            type: 'ai',
            ...message
        });
    }

    /**
     * Format quick replies from API response
     * @param {Array} quickReplies - Quick replies from API
     * @returns {Array} Formatted quick replies
     */
    formatQuickReplies(quickReplies) {
        if (!quickReplies || quickReplies.length === 0) return null;
        
        return quickReplies.map(qr => ({
            label: qr.label || qr.action?.label || qr.text,
            text: qr.text || qr.action?.text || qr.label
        }));
    }

    /**
     * Show pharmacist consultation suggestion
     * Requirements: 7.6 - Offer to initiate Video_Call_Session
     */
    showPharmacistSuggestion() {
        if (!this.chatContainer) return;

        const suggestionEl = document.createElement('div');
        suggestionEl.className = 'ai-chat-pharmacist-suggestion';
        suggestionEl.innerHTML = `
            <div class="ai-chat-suggestion-card">
                <div class="ai-chat-suggestion-icon">
                    <i class="fas fa-user-md"></i>
                </div>
                <div class="ai-chat-suggestion-content">
                    <h4>ต้องการปรึกษาเภสัชกร?</h4>
                    <p>เภสัชกรพร้อมให้คำปรึกษาผ่าน Video Call</p>
                </div>
                <button class="ai-chat-suggestion-btn" onclick="window.aiChat?.requestPharmacistConsult()">
                    <i class="fas fa-video"></i>
                    ปรึกษาเภสัชกร
                </button>
            </div>
        `;

        this.chatContainer.appendChild(suggestionEl);
        this.scrollToBottom();
    }

    /**
     * Request pharmacist consultation
     * Requirements: 7.6 - Initiate Video_Call_Session
     */
    requestPharmacistConsult() {
        // Add user message
        this.addMessage({
            type: 'user',
            content: 'ขอปรึกษาเภสัชกร',
            timestamp: new Date()
        });

        // Navigate to video call
        setTimeout(() => {
            window.router?.navigate('/video-call');
        }, 500);
    }

    /**
     * Utility delay function
     * @param {number} ms - Milliseconds to delay
     * @returns {Promise}
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Show typing indicator
     * Requirements: 7.2 - Display typing indicator
     */
    showTypingIndicator() {
        if (!this.chatContainer || this.isTyping) return;
        
        this.isTyping = true;
        
        const typingEl = document.createElement('div');
        typingEl.className = 'ai-chat-message ai-chat-message-ai';
        typingEl.id = 'ai-typing-indicator';
        typingEl.innerHTML = `
            <div class="ai-chat-message-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-chat-bubble ai-chat-bubble-ai">
                <div class="ai-chat-typing-indicator">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
        
        this.chatContainer.appendChild(typingEl);
        this.scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    hideTypingIndicator() {
        this.isTyping = false;
        const typingEl = document.getElementById('ai-typing-indicator');
        if (typingEl) {
            typingEl.remove();
        }
    }

    /**
     * Scroll chat to bottom
     */
    scrollToBottom() {
        if (this.chatContainer) {
            this.chatContainer.scrollTop = this.chatContainer.scrollHeight;
        }
    }

    /**
     * Show emergency alert
     * Requirements: 7.5 - Display prominent alert for red flags with emergency contact options
     * @param {Object} emergencyInfo - Optional emergency information from AI
     */
    showEmergencyAlert(emergencyInfo = null) {
        // Remove existing alert if any
        this.hideEmergencyAlert();

        const alertEl = document.createElement('div');
        alertEl.className = 'ai-chat-emergency-alert';
        alertEl.id = 'ai-emergency-alert';
        
        const symptoms = emergencyInfo?.symptoms || ['อาการที่ต้องระวัง'];
        const recommendation = emergencyInfo?.recommendation || 'กรุณาติดต่อแพทย์หรือเภสัชกรโดยด่วน';
        
        alertEl.innerHTML = `
            <div class="ai-chat-modal-backdrop"></div>
            <div class="ai-chat-emergency-content">
                <div class="ai-chat-emergency-header">
                    <div class="ai-chat-emergency-icon ai-chat-emergency-icon-pulse">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="ai-chat-emergency-title">⚠️ พบอาการที่ต้องระวัง</h3>
                </div>
                
                <div class="ai-chat-emergency-body">
                    <p class="ai-chat-emergency-desc">${this.escapeHtml(recommendation)}</p>
                    
                    ${symptoms.length > 0 ? `
                        <div class="ai-chat-emergency-symptoms">
                            <p class="ai-chat-emergency-symptoms-label">อาการที่ตรวจพบ:</p>
                            <ul class="ai-chat-emergency-symptoms-list">
                                ${symptoms.map(s => `<li>${this.escapeHtml(s)}</li>`).join('')}
                            </ul>
                        </div>
                    ` : ''}
                </div>
                
                <div class="ai-chat-emergency-actions">
                    <a href="tel:1669" class="ai-chat-emergency-btn ai-chat-emergency-btn-danger">
                        <i class="fas fa-ambulance"></i>
                        <span>โทร 1669 (ฉุกเฉิน)</span>
                    </a>
                    <a href="tel:1323" class="ai-chat-emergency-btn ai-chat-emergency-btn-warning">
                        <i class="fas fa-phone-alt"></i>
                        <span>โทร 1323 (สายด่วนสุขภาพ)</span>
                    </a>
                    <button class="ai-chat-emergency-btn ai-chat-emergency-btn-primary" 
                            onclick="window.aiChat?.requestEmergencyConsult()">
                        <i class="fas fa-user-md"></i>
                        <span>ปรึกษาเภสัชกรทันที</span>
                    </button>
                    <button class="ai-chat-emergency-btn ai-chat-emergency-btn-secondary" 
                            onclick="window.aiChat?.hideEmergencyAlert()">
                        <span>ปิด</span>
                    </button>
                </div>
                
                <p class="ai-chat-emergency-disclaimer">
                    <i class="fas fa-info-circle"></i>
                    หากมีอาการรุนแรง กรุณาไปพบแพทย์ที่โรงพยาบาลใกล้บ้านทันที
                </p>
            </div>
        `;
        
        document.body.appendChild(alertEl);
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
        
        // Log emergency alert for analytics
        this.logEmergencyAlert(emergencyInfo);
    }

    /**
     * Hide emergency alert
     */
    hideEmergencyAlert() {
        const alertEl = document.getElementById('ai-emergency-alert');
        if (alertEl) {
            alertEl.remove();
            document.body.style.overflow = '';
        }
    }

    /**
     * Request emergency pharmacist consultation
     * Requirements: 7.5 - Offer emergency contact options
     */
    requestEmergencyConsult() {
        this.hideEmergencyAlert();
        
        // Add message to chat
        this.addMessage({
            type: 'user',
            content: 'ขอปรึกษาเภสัชกรด่วน (อาการฉุกเฉิน)',
            timestamp: new Date()
        });

        // Mark as emergency consultation
        if (window.store) {
            window.store.setEmergencyConsultation(true);
        }

        // Navigate to video call with emergency flag
        setTimeout(() => {
            window.router?.navigate('/video-call', { emergency: true });
        }, 300);
    }

    /**
     * Log emergency alert for analytics
     * @param {Object} emergencyInfo - Emergency information
     */
    logEmergencyAlert(emergencyInfo) {
        try {
            const baseUrl = window.APP_CONFIG?.BASE_URL || '';
            fetch(`${baseUrl}/api/pharmacy-ai.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'log_emergency',
                    user_id: this.userId,
                    emergency_info: emergencyInfo,
                    timestamp: new Date().toISOString()
                })
            }).catch(err => console.error('Failed to log emergency:', err));
        } catch (error) {
            console.error('Emergency log error:', error);
        }
    }

    /**
     * Check message for emergency symptoms
     * Requirements: 7.5 - Detect emergency symptoms
     * @param {string} message - User message
     * @returns {Object|null} Emergency info if detected
     */
    checkEmergencySymptoms(message) {
        const emergencyKeywords = [
            { keywords: ['หายใจไม่ออก', 'หายใจลำบาก', 'หอบ', 'แน่นหน้าอก'], symptom: 'หายใจลำบาก/แน่นหน้าอก' },
            { keywords: ['เจ็บหน้าอก', 'แน่นหน้าอก', 'เจ็บอก'], symptom: 'เจ็บหน้าอก' },
            { keywords: ['ชัก', 'หมดสติ', 'เป็นลม', 'ไม่รู้สึกตัว'], symptom: 'หมดสติ/ชัก' },
            { keywords: ['เลือดออกมาก', 'เลือดไหลไม่หยุด', 'ตกเลือด'], symptom: 'เลือดออกมาก' },
            { keywords: ['อัมพาต', 'แขนขาอ่อนแรง', 'พูดไม่ชัด', 'หน้าเบี้ยว'], symptom: 'อาการคล้ายโรคหลอดเลือดสมอง' },
            { keywords: ['แพ้ยารุนแรง', 'บวมทั้งตัว', 'ผื่นขึ้นทั้งตัว', 'หายใจไม่ออกหลังกินยา'], symptom: 'แพ้ยารุนแรง' },
            { keywords: ['กินยาเกินขนาด', 'กินยาผิด', 'overdose'], symptom: 'กินยาเกินขนาด' },
            { keywords: ['ฆ่าตัวตาย', 'ทำร้ายตัวเอง', 'ไม่อยากมีชีวิต'], symptom: 'ความคิดทำร้ายตัวเอง' }
        ];

        const lowerMessage = message.toLowerCase();
        const detectedSymptoms = [];

        for (const emergency of emergencyKeywords) {
            for (const keyword of emergency.keywords) {
                if (lowerMessage.includes(keyword)) {
                    detectedSymptoms.push(emergency.symptom);
                    break;
                }
            }
        }

        if (detectedSymptoms.length > 0) {
            return {
                symptoms: [...new Set(detectedSymptoms)],
                recommendation: 'อาการเหล่านี้อาจเป็นอันตราย กรุณาติดต่อแพทย์หรือไปโรงพยาบาลทันที'
            };
        }

        return null;
    }

    /**
     * Show product recommendations
     * Requirements: 7.4 - Display product cards with "Add to Cart" functionality
     * @param {Array} products - Array of product objects
     */
    showProductRecommendations(products) {
        if (!this.chatContainer || !products || products.length === 0) return;

        const productsEl = document.createElement('div');
        productsEl.className = 'ai-chat-products ai-chat-products-entering';
        productsEl.innerHTML = `
            <p class="ai-chat-products-label">
                <i class="fas fa-pills"></i>
                สินค้าแนะนำสำหรับอาการของคุณ
            </p>
            <div class="ai-chat-products-scroll">
                ${products.map(product => this.renderProductCard(product)).join('')}
            </div>
        `;

        this.chatContainer.appendChild(productsEl);
        this.scrollToBottom();

        // Trigger animation
        setTimeout(() => {
            productsEl.classList.remove('ai-chat-products-entering');
            productsEl.classList.add('ai-chat-products-entered');
        }, 50);
    }

    /**
     * Render a single product card
     * Requirements: 7.4 - Product card with Add to Cart
     * @param {Object} product - Product object
     * @returns {string} HTML string
     */
    renderProductCard(product) {
        const hasDiscount = product.sale_price && product.sale_price < product.price;
        const displayPrice = hasDiscount ? product.sale_price : product.price;
        const isRx = product.is_prescription || product.requires_prescription;

        return `
            <div class="ai-chat-product-card" data-product-id="${product.id}">
                ${isRx ? '<span class="ai-chat-product-rx-badge">Rx</span>' : ''}
                ${hasDiscount ? '<span class="ai-chat-product-sale-badge">ลด</span>' : ''}
                <img src="${product.image_url || 'assets/images/image-placeholder.svg'}" 
                     alt="${this.escapeHtml(product.name)}"
                     class="ai-chat-product-image"
                     loading="lazy"
                     onerror="this.src='assets/images/image-placeholder.svg'">
                <div class="ai-chat-product-info">
                    <h4 class="ai-chat-product-name">${this.escapeHtml(product.name)}</h4>
                    <div class="ai-chat-product-price-container">
                        <span class="ai-chat-product-price">฿${this.formatPrice(displayPrice)}</span>
                        ${hasDiscount ? `<span class="ai-chat-product-original-price">฿${this.formatPrice(product.price)}</span>` : ''}
                    </div>
                </div>
                <button class="ai-chat-product-add-btn" 
                        onclick="window.aiChat?.addProductToCart(${product.id}, '${this.escapeHtml(product.name)}', ${isRx})"
                        ${isRx ? 'data-rx="true"' : ''}>
                    <i class="fas fa-cart-plus"></i>
                    ${isRx ? 'ปรึกษาก่อนซื้อ' : 'เพิ่มลงตะกร้า'}
                </button>
            </div>
        `;
    }

    /**
     * Format price with commas
     * @param {number} price - Price value
     * @returns {string} Formatted price
     */
    formatPrice(price) {
        return Number(price).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    /**
     * Add product to cart with prescription check
     * Requirements: 7.4 - Add to Cart functionality
     * @param {number} productId - Product ID
     * @param {string} productName - Product name
     * @param {boolean} isRx - Is prescription required
     */
    async addProductToCart(productId, productName, isRx = false) {
        // If prescription required, show consultation prompt
        if (isRx) {
            this.showRxConsultPrompt(productId, productName);
            return;
        }

        const btn = document.querySelector(`.ai-chat-product-card[data-product-id="${productId}"] .ai-chat-product-add-btn`);
        
        try {
            // Show loading state
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }

            if (window.store) {
                await window.store.addToCart(productId, 1);
                
                // Show success state
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check"></i> เพิ่มแล้ว';
                    btn.classList.add('ai-chat-product-add-btn-success');
                }
                
                window.liffApp?.showToast('เพิ่มลงตะกร้าแล้ว', 'success');

                // Reset button after delay
                setTimeout(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-cart-plus"></i> เพิ่มลงตะกร้า';
                        btn.classList.remove('ai-chat-product-add-btn-success');
                    }
                }, 2000);
            }
        } catch (error) {
            console.error('Add to cart error:', error);
            
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-cart-plus"></i> เพิ่มลงตะกร้า';
            }
            
            window.liffApp?.showToast('ไม่สามารถเพิ่มสินค้าได้', 'error');
        }
    }

    /**
     * Show prescription consultation prompt
     * @param {number} productId - Product ID
     * @param {string} productName - Product name
     */
    showRxConsultPrompt(productId, productName) {
        const promptEl = document.createElement('div');
        promptEl.className = 'ai-chat-rx-prompt';
        promptEl.id = 'ai-rx-prompt';
        promptEl.innerHTML = `
            <div class="ai-chat-modal-backdrop" onclick="window.aiChat?.hideRxPrompt()"></div>
            <div class="ai-chat-rx-content">
                <div class="ai-chat-rx-icon">
                    <i class="fas fa-prescription-bottle-alt"></i>
                </div>
                <h3 class="ai-chat-rx-title">ยาที่ต้องปรึกษาเภสัชกร</h3>
                <p class="ai-chat-rx-desc">
                    <strong>${this.escapeHtml(productName)}</strong><br>
                    ต้องได้รับการอนุมัติจากเภสัชกรก่อนสั่งซื้อ
                </p>
                <div class="ai-chat-rx-actions">
                    <button class="ai-chat-rx-btn ai-chat-rx-btn-primary" 
                            onclick="window.aiChat?.startRxConsultation(${productId})">
                        <i class="fas fa-video"></i> ปรึกษาเภสัชกร
                    </button>
                    <button class="ai-chat-rx-btn ai-chat-rx-btn-secondary" 
                            onclick="window.aiChat?.hideRxPrompt()">
                        ยกเลิก
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(promptEl);
    }

    /**
     * Hide prescription prompt
     */
    hideRxPrompt() {
        const promptEl = document.getElementById('ai-rx-prompt');
        if (promptEl) {
            promptEl.remove();
        }
    }

    /**
     * Start prescription consultation
     * @param {number} productId - Product ID
     */
    startRxConsultation(productId) {
        this.hideRxPrompt();
        
        // Store product for consultation
        if (window.store) {
            window.store.setPendingRxProduct(productId);
        }
        
        // Navigate to video call
        window.router?.navigate('/video-call');
    }

    /**
     * Add product to cart (legacy method for backward compatibility)
     * @param {number} productId - Product ID
     */
    async addToCart(productId) {
        await this.addProductToCart(productId, '', false);
    }

    // Modal methods
    showAttachMenu() {
        const modal = document.getElementById('ai-attach-menu');
        if (modal) modal.classList.remove('hidden');
    }

    hideAttachMenu() {
        const modal = document.getElementById('ai-attach-menu');
        if (modal) modal.classList.add('hidden');
    }

    showMenu() {
        const modal = document.getElementById('ai-menu-modal');
        if (modal) modal.classList.remove('hidden');
    }

    hideMenu() {
        const modal = document.getElementById('ai-menu-modal');
        if (modal) modal.classList.add('hidden');
    }

    // Action methods
    takePhoto() {
        this.hideAttachMenu();
        // TODO: Implement camera functionality
        window.liffApp?.showToast('ฟีเจอร์นี้จะเปิดให้บริการเร็วๆ นี้', 'info');
    }

    selectImage() {
        this.hideAttachMenu();
        // TODO: Implement image picker
        window.liffApp?.showToast('ฟีเจอร์นี้จะเปิดให้บริการเร็วๆ นี้', 'info');
    }

    startVideoCall() {
        this.hideAttachMenu();
        window.router?.navigate('/video-call');
    }

    showHistory() {
        this.hideAttachMenu();
        this.hideMenu();
        // TODO: Implement consultation history
        window.liffApp?.showToast('ฟีเจอร์นี้จะเปิดให้บริการเร็วๆ นี้', 'info');
    }

    showHealthProfile() {
        this.hideMenu();
        window.router?.navigate('/health-profile');
    }

    showMedications() {
        this.hideMenu();
        // TODO: Navigate to medications page
        window.liffApp?.showToast('ฟีเจอร์นี้จะเปิดให้บริการเร็วๆ นี้', 'info');
    }

    clearChat() {
        this.hideMenu();
        this.messages = [];
        this.currentState = 'greeting';
        this.triageData = {};
        
        if (this.chatContainer) {
            this.chatContainer.innerHTML = '';
        }
        
        // Show quick symptoms again
        const quickSymptomsSection = document.getElementById('ai-quick-symptoms');
        if (quickSymptomsSection) {
            quickSymptomsSection.classList.remove('hidden');
        }
        
        // Clear server-side history
        if (this.userId) {
            const baseUrl = window.APP_CONFIG?.BASE_URL || '';
            fetch(`${baseUrl}/api/pharmacy-ai.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'clear_history',
                    user_id: this.userId
                })
            }).catch(err => console.error('[AI Chat] Clear history error:', err));
        }
        
        this.showWelcomeMessage();
    }

    /**
     * Initialize with a pre-selected symptom (from home page)
     * @param {string} symptom - Pre-selected symptom
     */
    initWithSymptom(symptom) {
        if (symptom) {
            // Small delay to ensure UI is ready
            setTimeout(() => {
                this.selectSymptom(symptom);
            }, 300);
        }
    }

    /**
     * Enable/disable triage mode
     * @param {boolean} enabled - Whether to enable triage mode
     */
    setTriageMode(enabled) {
        this.triageMode = enabled;
    }

    /**
     * Enable/disable Gemini AI mode
     * @param {boolean} enabled - Whether to enable Gemini AI
     */
    setGeminiMode(enabled) {
        this.useGemini = enabled;
    }

    /**
     * Show drug interaction warning
     * @param {Array} interactions - Array of drug interaction objects
     */
    showDrugInteractionWarning(interactions) {
        if (!this.chatContainer || !interactions || interactions.length === 0) return;

        const warningEl = document.createElement('div');
        warningEl.className = 'ai-chat-drug-warning';
        
        const highSeverity = interactions.filter(i => i.severity === 'high' || i.type === 'allergy');
        const mediumSeverity = interactions.filter(i => i.severity === 'medium');

        warningEl.innerHTML = `
            <div class="ai-chat-warning-card ${highSeverity.length > 0 ? 'ai-chat-warning-danger' : 'ai-chat-warning-caution'}">
                <div class="ai-chat-warning-header">
                    <i class="fas ${highSeverity.length > 0 ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    <span>${highSeverity.length > 0 ? '⚠️ คำเตือนสำคัญ' : 'ℹ️ ข้อควรระวัง'}</span>
                </div>
                <div class="ai-chat-warning-body">
                    ${highSeverity.map(i => `
                        <div class="ai-chat-warning-item ai-chat-warning-item-danger">
                            <i class="fas fa-ban"></i>
                            <span>${this.escapeHtml(i.message)}</span>
                        </div>
                    `).join('')}
                    ${mediumSeverity.map(i => `
                        <div class="ai-chat-warning-item ai-chat-warning-item-caution">
                            <i class="fas fa-exclamation"></i>
                            <span>${this.escapeHtml(i.message)}</span>
                        </div>
                    `).join('')}
                </div>
                <div class="ai-chat-warning-footer">
                    <button class="ai-chat-warning-btn" onclick="window.aiChat?.requestPharmacistConsult()">
                        <i class="fas fa-user-md"></i> ปรึกษาเภสัชกร
                    </button>
                </div>
            </div>
        `;

        this.chatContainer.appendChild(warningEl);
        this.scrollToBottom();
    }

    /**
     * Show MIMS knowledge base information
     * @param {Object} mimsInfo - MIMS information object
     */
    showMIMSInfo(mimsInfo) {
        if (!this.chatContainer || !mimsInfo) return;

        const infoEl = document.createElement('div');
        infoEl.className = 'ai-chat-mims-info';

        let content = '';
        
        if (mimsInfo.diseases && mimsInfo.diseases.length > 0) {
            const disease = mimsInfo.diseases[0];
            content = `
                <div class="ai-chat-mims-card">
                    <div class="ai-chat-mims-header">
                        <i class="fas fa-book-medical"></i>
                        <span>📚 ข้อมูลจาก MIMS</span>
                    </div>
                    <div class="ai-chat-mims-body">
                        <h4>${this.escapeHtml(disease.name_th || disease.name_en || 'ข้อมูลโรค')}</h4>
                        ${disease.non_drug_advice ? `
                            <div class="ai-chat-mims-section">
                                <strong>🏠 การดูแลตัวเอง:</strong>
                                <ul>
                                    ${disease.non_drug_advice.slice(0, 3).map(a => `<li>${this.escapeHtml(a)}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${disease.referral_criteria ? `
                            <div class="ai-chat-mims-section ai-chat-mims-referral">
                                <strong>🏥 ควรพบแพทย์เมื่อ:</strong>
                                <ul>
                                    ${disease.referral_criteria.slice(0, 2).map(c => `<li>${this.escapeHtml(c)}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }

        if (mimsInfo.red_flags && mimsInfo.red_flags.length > 0) {
            content += `
                <div class="ai-chat-mims-redflags">
                    <strong>⚠️ อาการที่ต้องระวัง:</strong>
                    ${mimsInfo.red_flags.map(f => `<span class="ai-chat-redflag-tag">${this.escapeHtml(f.flag?.message || f.matched_keyword)}</span>`).join('')}
                </div>
            `;
        }

        if (content) {
            infoEl.innerHTML = content;
            this.chatContainer.appendChild(infoEl);
            this.scrollToBottom();
        }
    }

    /**
     * Show allergy reminder
     * @param {string} allergies - User's allergies
     */
    showAllergyReminder(allergies) {
        if (!this.chatContainer || !allergies) return;

        const reminderEl = document.createElement('div');
        reminderEl.className = 'ai-chat-allergy-reminder';
        reminderEl.innerHTML = `
            <div class="ai-chat-reminder-card">
                <i class="fas fa-allergies"></i>
                <div class="ai-chat-reminder-content">
                    <strong>ข้อมูลการแพ้ยาของคุณ</strong>
                    <p>${this.escapeHtml(allergies)}</p>
                </div>
                <button class="ai-chat-reminder-close" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        this.chatContainer.insertBefore(reminderEl, this.chatContainer.firstChild?.nextSibling);
    }

    /**
     * Start triage assessment
     */
    startTriageAssessment() {
        this.triageMode = true;
        this.addMessage({
            type: 'user',
            content: 'เริ่มซักประวัติ',
            timestamp: new Date()
        });
        this.processMessage('เริ่มซักประวัติ');
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AIChat;
}

// Make available globally
window.AIChat = AIChat;
