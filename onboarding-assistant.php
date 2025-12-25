<?php
/**
 * AI Onboarding Assistant
 * ผู้ช่วย AI สำหรับการตั้งค่าและใช้งานระบบ
 */

require_once 'config/config.php';
require_once 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['admin_user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'User';
?>

<div class="container-fluid py-4">
    <div class="row">
        <!-- Main Chat Area -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-robot me-2"></i>Kiro Assistant
                        </h5>
                        <small class="opacity-75">ผู้ช่วย AI สำหรับการตั้งค่าและใช้งานระบบ</small>
                    </div>
                    <div>
                        <button class="btn btn-outline-light btn-sm" onclick="runHealthCheck()" title="Health Check">
                            <i class="fas fa-heartbeat"></i>
                        </button>
                        <button class="btn btn-outline-light btn-sm ms-1" onclick="clearHistory()" title="Clear History">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <!-- Chat Messages -->
                    <div id="chatMessages" class="chat-messages p-3" style="height: 500px; overflow-y: auto;">
                        <!-- Messages will be loaded here -->
                        <div class="text-center text-muted py-5" id="loadingIndicator">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>กำลังโหลด...</p>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div id="quickActions" class="border-top p-2 bg-light" style="display: none;">
                        <div class="d-flex flex-wrap gap-2" id="quickActionButtons">
                            <!-- Quick action buttons will be loaded here -->
                        </div>
                    </div>
                    
                    <!-- Chat Input -->
                    <div class="border-top p-3">
                        <form id="chatForm" class="d-flex gap-2">
                            <input type="text" 
                                   id="chatInput" 
                                   class="form-control" 
                                   placeholder="พิมพ์ข้อความ... (เช่น วิธีเชื่อมต่อ LINE, ตั้งค่าร้านค้ายังไง)"
                                   autocomplete="off">
                            <button type="submit" class="btn btn-primary px-4" id="sendBtn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar - Checklist -->
        <div class="col-lg-4">
            <!-- Progress Card -->
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="card-title mb-3">
                        <i class="fas fa-tasks me-2 text-primary"></i>ความคืบหน้าการตั้งค่า
                    </h6>
                    <div class="progress mb-2" style="height: 20px;">
                        <div class="progress-bar bg-success" 
                             id="progressBar" 
                             role="progressbar" 
                             style="width: 0%">
                            <span id="progressText">0%</span>
                        </div>
                    </div>
                    <small class="text-muted" id="progressStatus">กำลังตรวจสอบ...</small>
                </div>
            </div>
            
            <!-- Checklist Card -->
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-clipboard-check me-2"></i>รายการตั้งค่า
                    </h6>
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <div id="checklistContainer">
                        <!-- Checklist will be loaded here -->
                    </div>
                </div>
            </div>
            
            <!-- Help Card -->
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>ลองถาม
                    </h6>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-secondary btn-sm text-start" onclick="askQuestion('วิธีเชื่อมต่อ LINE OA')">
                            <i class="fab fa-line me-2"></i>วิธีเชื่อมต่อ LINE OA
                        </button>
                        <button class="btn btn-outline-secondary btn-sm text-start" onclick="askQuestion('วิธีตั้งค่าร้านค้า')">
                            <i class="fas fa-store me-2"></i>วิธีตั้งค่าร้านค้า
                        </button>
                        <button class="btn btn-outline-secondary btn-sm text-start" onclick="askQuestion('ระบบนี้ทำอะไรได้บ้าง')">
                            <i class="fas fa-question-circle me-2"></i>ระบบนี้ทำอะไรได้บ้าง
                        </button>
                        <button class="btn btn-outline-secondary btn-sm text-start" onclick="askQuestion('แนะนำฟีเจอร์สำหรับร้านค้า')">
                            <i class="fas fa-magic me-2"></i>แนะนำฟีเจอร์
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.chat-messages {
    background: #f8f9fa;
}

.message {
    max-width: 85%;
    margin-bottom: 1rem;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.message-user {
    margin-left: auto;
}

.message-user .message-content {
    background: #0d6efd;
    color: white;
    border-radius: 18px 18px 4px 18px;
}

.message-assistant {
    margin-right: auto;
}

.message-assistant .message-content {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 18px 18px 18px 4px;
}

.message-content {
    padding: 12px 16px;
    word-wrap: break-word;
}

.message-content p:last-child {
    margin-bottom: 0;
}

.message-content a {
    color: inherit;
    text-decoration: underline;
}

.message-user .message-content a {
    color: #fff;
}

.checklist-category {
    border-bottom: 1px solid #dee2e6;
}

.checklist-category:last-child {
    border-bottom: none;
}

.checklist-item {
    padding: 10px 15px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.2s;
}

.checklist-item:hover {
    background: #f8f9fa;
}

.checklist-item:last-child {
    border-bottom: none;
}

.checklist-item.completed {
    background: #d4edda;
}

.checklist-item.completed .item-icon {
    color: #28a745;
}

.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 12px 16px;
}

.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #6c757d;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}

.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-10px); }
}

.quick-action-btn {
    font-size: 0.85rem;
    padding: 6px 12px;
    border-radius: 20px;
}
</style>

<script>
const API_URL = '/api/onboarding-assistant.php';
let isLoading = false;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadWelcomeMessage();
    loadChecklist();
    
    // Chat form submit
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
});

// Load welcome message
async function loadWelcomeMessage() {
    try {
        const response = await fetch(`${API_URL}?action=welcome`);
        const data = await response.json();
        
        document.getElementById('loadingIndicator').style.display = 'none';
        
        if (data.success) {
            addMessage(data.message, 'assistant');
        }
        
        // Load suggestions
        loadSuggestions();
    } catch (error) {
        console.error('Error loading welcome:', error);
        document.getElementById('loadingIndicator').innerHTML = '<p class="text-danger">เกิดข้อผิดพลาด</p>';
    }
}

// Load checklist
async function loadChecklist() {
    try {
        const response = await fetch(`${API_URL}?action=checklist`);
        const data = await response.json();
        
        if (data.success) {
            renderChecklist(data.data);
            updateProgress(data.data.completion_percent);
        }
    } catch (error) {
        console.error('Error loading checklist:', error);
    }
}

// Render checklist
function renderChecklist(checklistData) {
    const container = document.getElementById('checklistContainer');
    const status = checklistData.status;
    
    let html = '';
    
    const categoryLabels = {
        'essential': '🔴 จำเป็น',
        'recommended': '🟡 แนะนำ',
        'advanced': '🟢 ขั้นสูง'
    };
    
    for (const [category, items] of Object.entries(status)) {
        html += `<div class="checklist-category">
            <div class="px-3 py-2 bg-light border-bottom">
                <strong>${categoryLabels[category] || category}</strong>
            </div>`;
        
        for (const [key, item] of Object.entries(items)) {
            const isCompleted = item.completed;
            html += `
                <div class="checklist-item ${isCompleted ? 'completed' : ''}" 
                     onclick="handleChecklistClick('${key}', '${item.url}')">
                    <div class="d-flex align-items-center">
                        <i class="${item.icon || 'fas fa-circle'} me-3 item-icon ${isCompleted ? 'text-success' : 'text-muted'}"></i>
                        <div class="flex-grow-1">
                            <div class="fw-medium">${item.label}</div>
                            <small class="text-muted">${item.description}</small>
                        </div>
                        <i class="fas ${isCompleted ? 'fa-check-circle text-success' : 'fa-chevron-right text-muted'}"></i>
                    </div>
                </div>`;
        }
        
        html += '</div>';
    }
    
    container.innerHTML = html;
}

// Update progress bar
function updateProgress(percent) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressStatus = document.getElementById('progressStatus');
    
    progressBar.style.width = percent + '%';
    progressText.textContent = percent + '%';
    
    if (percent === 100) {
        progressStatus.textContent = '🎉 ตั้งค่าครบถ้วนแล้ว!';
        progressBar.classList.remove('bg-warning', 'bg-danger');
        progressBar.classList.add('bg-success');
    } else if (percent >= 50) {
        progressStatus.textContent = 'กำลังไปได้ดี!';
        progressBar.classList.remove('bg-success', 'bg-danger');
        progressBar.classList.add('bg-warning');
    } else {
        progressStatus.textContent = 'เริ่มต้นตั้งค่ากันเลย';
        progressBar.classList.remove('bg-success', 'bg-warning');
    }
}

// Load suggestions
async function loadSuggestions() {
    try {
        const response = await fetch(`${API_URL}?action=suggestions`);
        const data = await response.json();
        
        if (data.success && Object.keys(data.data).length > 0) {
            renderQuickActions(data.data);
        }
    } catch (error) {
        console.error('Error loading suggestions:', error);
    }
}

// Render quick actions
function renderQuickActions(actions) {
    const container = document.getElementById('quickActionButtons');
    const wrapper = document.getElementById('quickActions');
    
    let html = '';
    for (const [key, action] of Object.entries(actions)) {
        html += `
            <button class="btn btn-outline-primary quick-action-btn" 
                    onclick="executeAction('${key}')">
                <i class="${action.icon || 'fas fa-arrow-right'} me-1"></i>
                ${action.label}
            </button>`;
    }
    
    if (html) {
        container.innerHTML = html;
        wrapper.style.display = 'block';
    }
}

// Send message
async function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    
    if (!message || isLoading) return;
    
    input.value = '';
    addMessage(message, 'user');
    showTypingIndicator();
    
    isLoading = true;
    document.getElementById('sendBtn').disabled = true;
    
    try {
        const response = await fetch(`${API_URL}?action=chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message })
        });
        
        const data = await response.json();
        hideTypingIndicator();
        
        if (data.success) {
            addMessage(data.message, 'assistant');
            
            // Update checklist if status changed
            if (data.setup_status) {
                renderChecklist({ status: data.setup_status });
            }
            if (data.completion_percent !== undefined) {
                updateProgress(data.completion_percent);
            }
            
            // Update quick actions
            if (data.suggested_actions) {
                renderQuickActions(data.suggested_actions);
            }
        } else {
            addMessage('ขออภัย เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', 'assistant');
        }
    } catch (error) {
        console.error('Error sending message:', error);
        hideTypingIndicator();
        addMessage('ขออภัย ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่อีกครั้ง', 'assistant');
    }
    
    isLoading = false;
    document.getElementById('sendBtn').disabled = false;
}

// Add message to chat
function addMessage(content, role) {
    const container = document.getElementById('chatMessages');
    
    // Convert markdown-like formatting
    content = formatMessage(content);
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${role}`;
    messageDiv.innerHTML = `<div class="message-content">${content}</div>`;
    
    container.appendChild(messageDiv);
    container.scrollTop = container.scrollHeight;
}

// Format message content
function formatMessage(content) {
    // Convert markdown links [text](url) to HTML
    content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
    
    // Convert **bold** to <strong>
    content = content.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    
    // Convert newlines to <br>
    content = content.replace(/\n/g, '<br>');
    
    // Convert bullet points
    content = content.replace(/^• /gm, '&bull; ');
    content = content.replace(/^- /gm, '&bull; ');
    
    return content;
}

// Show typing indicator
function showTypingIndicator() {
    const container = document.getElementById('chatMessages');
    const typingDiv = document.createElement('div');
    typingDiv.id = 'typingIndicator';
    typingDiv.className = 'message message-assistant';
    typingDiv.innerHTML = `
        <div class="message-content typing-indicator">
            <span></span><span></span><span></span>
        </div>`;
    container.appendChild(typingDiv);
    container.scrollTop = container.scrollHeight;
}

// Hide typing indicator
function hideTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) indicator.remove();
}

// Ask predefined question
function askQuestion(question) {
    document.getElementById('chatInput').value = question;
    sendMessage();
}

// Handle checklist item click
function handleChecklistClick(key, url) {
    askQuestion(`ช่วยแนะนำเรื่อง ${key} หน่อย`);
}

// Execute quick action
async function executeAction(actionName) {
    try {
        const response = await fetch(`${API_URL}?action=execute`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action_name: actionName })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (data.type === 'navigate' && data.url) {
                window.location.href = data.url;
            } else if (data.message) {
                addMessage(data.message, 'assistant');
            }
        }
    } catch (error) {
        console.error('Error executing action:', error);
    }
}

// Run health check
async function runHealthCheck() {
    addMessage('กำลังตรวจสอบสถานะระบบ...', 'assistant');
    showTypingIndicator();
    
    try {
        const response = await fetch(`${API_URL}?action=health`);
        const data = await response.json();
        
        hideTypingIndicator();
        
        let message = data.message + '\n\n';
        for (const [key, check] of Object.entries(data.checks || {})) {
            const icon = check.status === 'ok' ? '✅' : (check.status === 'warning' ? '⚠️' : '❌');
            message += `${icon} ${key}: ${check.message}\n`;
        }
        
        addMessage(message, 'assistant');
    } catch (error) {
        hideTypingIndicator();
        addMessage('❌ ไม่สามารถตรวจสอบสถานะได้', 'assistant');
    }
}

// Clear history
async function clearHistory() {
    if (!confirm('ต้องการล้างประวัติการสนทนาหรือไม่?')) return;
    
    try {
        await fetch(`${API_URL}?action=clear_history`, { method: 'POST' });
        document.getElementById('chatMessages').innerHTML = '';
        loadWelcomeMessage();
    } catch (error) {
        console.error('Error clearing history:', error);
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
