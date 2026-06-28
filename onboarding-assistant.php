<?php
/**
 * AI Onboarding Assistant
 * ผู้ช่วย AI สำหรับการตั้งค่าและใช้งานระบบ
 */

require_once 'config/config.php';
require_once 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['admin_user']['id'])) {
    header('Location: /auth/login.php');
    exit;
}

$adminName = $_SESSION['admin_user']['display_name'] ?? $_SESSION['admin_user']['username'] ?? 'User';
$pageTitle = 'REYA ผู้ช่วยตั้งค่า';
?>

<div class="p-4 lg:p-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Chat Area -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 text-white px-4 py-3 flex justify-between items-center">
                    <div>
                        <h5 class="font-semibold flex items-center gap-2">
                            <i class="fas fa-robot"></i>REYA ผู้ช่วยตั้งค่า
                            <span id="aiStatusBadge" class="text-xs px-2 py-0.5 rounded-full bg-orange-500">Fallback Mode</span>
                        </h5>
                        <small class="text-emerald-100 text-xs">ผู้ช่วย AI สำหรับการตั้งค่าและใช้งานระบบ</small>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="runHealthCheck()" title="Health Check" class="p-2 hover:bg-white/20 rounded-lg transition">
                            <i class="fas fa-heartbeat"></i>
                        </button>
                        <button onclick="clearHistory()" title="Clear History" class="p-2 hover:bg-white/20 rounded-lg transition">
                            <i class="fas fa-trash"></i>

                        </button>
                    </div>
                </div>
                
                <!-- Chat Messages -->
                <div id="chatMessages" class="h-96 overflow-y-auto p-4 bg-gray-50">
                    <div class="text-center text-gray-400 py-10" id="loadingIndicator">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-emerald-600 mx-auto mb-3"></div>
                        <p>กำลังโหลด...</p>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div id="quickActions" class="border-t p-3 bg-gray-50 hidden">
                    <div class="flex flex-wrap gap-2" id="quickActionButtons"></div>
                </div>
                
                <!-- Chat Input -->
                <div class="border-t p-3">
                    <form id="chatForm" class="flex gap-2">
                        <input type="text" 
                               id="chatInput" 
                               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent outline-none"
                               placeholder="พิมพ์ข้อความ... (เช่น วิธีเชื่อมต่อ LINE)"
                               autocomplete="off">
                        <button type="submit" id="sendBtn" class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="space-y-4">
            <!-- Progress Card -->
            <div class="bg-white rounded-xl shadow-sm p-4">
                <h6 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-tasks text-emerald-600"></i>ความคืบหน้าการตั้งค่า
                </h6>
                <div class="w-full bg-gray-200 rounded-full h-5 mb-2">
                    <div id="progressBar" class="bg-green-500 h-5 rounded-full transition-all duration-500 flex items-center justify-center text-white text-xs font-medium" style="width: 0%">
                        <span id="progressText">0%</span>
                    </div>
                </div>
                <small class="text-gray-500" id="progressStatus">กำลังตรวจสอบ...</small>
            </div>
            
            <!-- Checklist Card -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b">
                    <h6 class="font-semibold text-gray-700 flex items-center gap-2">
                        <i class="fas fa-clipboard-check"></i>รายการตั้งค่า
                    </h6>
                </div>
                <div id="checklistContainer" class="max-h-80 overflow-y-auto"></div>
            </div>
            
            <!-- Help Card -->
            <div class="bg-white rounded-xl shadow-sm p-4">
                <h6 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-lightbulb text-yellow-500"></i>ลองถาม
                </h6>
                
                <!-- Basic Setup -->
                <p class="text-xs text-gray-500 mb-2">📌 ตั้งค่าพื้นฐาน</p>
                <div class="space-y-2 mb-4">
                    <button onclick="askQuestion('วิธีเชื่อมต่อ LINE OA')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fab fa-line text-green-500"></i>วิธีเชื่อมต่อ LINE OA
                    </button>
                    <button onclick="askQuestion('วิธีตั้งค่าร้านค้า')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-store text-blue-500"></i>วิธีตั้งค่าร้านค้า
                    </button>
                    <button onclick="askQuestion('ระบบนี้ทำอะไรได้บ้าง')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-question-circle text-emerald-500"></i>ระบบนี้ทำอะไรได้บ้าง
                    </button>
                </div>
                
                <!-- Advanced Marketing -->
                <p class="text-xs text-gray-500 mb-2">🚀 การตลาดขั้นสูง</p>
                <div class="space-y-2">
                    <button onclick="askQuestion('วิธีสร้าง Drip Campaign')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-tint text-blue-400"></i>Drip Campaign
                    </button>
                    <button onclick="askQuestion('วิธีติดแท็กลูกค้า')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-tags text-orange-500"></i>การติดแท็กลูกค้า
                    </button>
                    <button onclick="askQuestion('วิธีสร้าง Customer Segment')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-users text-indigo-500"></i>Customer Segments
                    </button>
                    <button onclick="askQuestion('วิธีตั้งเวลาส่ง Broadcast')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-clock text-teal-500"></i>ตั้งเวลาส่ง Broadcast
                    </button>
                    <button onclick="askQuestion('วิธีสร้างโปรโมชั่น')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-gift text-pink-500"></i>โปรโมชั่น/คูปอง
                    </button>
                    <button onclick="askQuestion('วิธีใช้ Flex Builder')" class="w-full text-left px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                        <i class="fas fa-palette text-emerald-500"></i>Flex Message Builder
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.message {
    max-width: 85%;
    margin-bottom: 1rem;
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.message-user { margin-left: auto; }
.message-user .message-content {
    background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
    color: white;
    border-radius: 18px 18px 4px 18px;
}
.message-assistant { margin-right: auto; }
.message-assistant .message-content {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 18px 18px 18px 4px;
}
.message-content {
    padding: 12px 16px;
    word-wrap: break-word;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.message-content a { text-decoration: underline; }
.checklist-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background 0.2s;
}
.checklist-item:hover { background: #f9fafb; }
.checklist-item.completed { background: #dcfce7; }
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 12px 16px;
}
.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #9ca3af;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-10px); }
}
</style>

<script>
const API_URL = '/api/onboarding-assistant.php';
let isLoading = false;

document.addEventListener('DOMContentLoaded', function() {
    loadWelcomeMessage();
    loadChecklist();
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
});

async function loadWelcomeMessage() {
    try {
        const response = await fetch(`${API_URL}?action=welcome`);
        const data = await response.json();
        document.getElementById('loadingIndicator').style.display = 'none';
        if (data.success) {
            addMessage(data.message, 'assistant');
            // Update AI status badge
            updateAiStatusBadge(data.ai_available);
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('loadingIndicator').innerHTML = '<p class="text-red-500">เกิดข้อผิดพลาด</p>';
    }
}

function updateAiStatusBadge(aiAvailable) {
    const badge = document.getElementById('aiStatusBadge');
    if (aiAvailable) {
        badge.textContent = 'Gemini AI';
        badge.className = 'text-xs px-2 py-0.5 rounded-full bg-green-500';
    } else {
        badge.textContent = 'Fallback Mode';
        badge.className = 'text-xs px-2 py-0.5 rounded-full bg-orange-500';
        badge.title = 'ใส่ Gemini API Key ที่ AI Settings เพื่อเปิดใช้ AI';
    }
}

async function loadChecklist() {
    try {
        const response = await fetch(`${API_URL}?action=checklist`);
        const data = await response.json();
        if (data.success) {
            renderChecklist(data.data);
            updateProgress(data.data.completion_percent);
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

function renderChecklist(checklistData) {
    const container = document.getElementById('checklistContainer');
    const status = checklistData.status;
    const labels = { 'essential': '🔴 จำเป็น', 'recommended': '🟡 แนะนำ', 'advanced': '🟢 ขั้นสูง' };
    
    let html = '';
    for (const [category, items] of Object.entries(status)) {
        html += `<div class="px-4 py-2 bg-gray-100 text-sm font-semibold text-gray-600">${labels[category] || category}</div>`;
        for (const [key, item] of Object.entries(items)) {
            const done = item.completed;
            html += `<div class="checklist-item ${done ? 'completed' : ''}" onclick="handleChecklistClick('${key}', '${item.url}')">
                <div class="flex items-center gap-3">
                    <i class="${item.icon || 'fas fa-circle'} ${done ? 'text-green-500' : 'text-gray-400'}"></i>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-sm text-gray-800 truncate">${item.label}</div>
                        <div class="text-xs text-gray-500 truncate">${item.description}</div>
                    </div>
                    <i class="fas ${done ? 'fa-check-circle text-green-500' : 'fa-chevron-right text-gray-300'}"></i>
                </div>
            </div>`;
        }
    }
    container.innerHTML = html;
}

function updateProgress(percent) {
    const bar = document.getElementById('progressBar');
    const text = document.getElementById('progressText');
    const status = document.getElementById('progressStatus');
    bar.style.width = percent + '%';
    text.textContent = percent + '%';
    if (percent === 100) {
        status.textContent = '🎉 ตั้งค่าครบถ้วนแล้ว!';
        bar.className = 'bg-green-500 h-5 rounded-full transition-all duration-500 flex items-center justify-center text-white text-xs font-medium';
    } else if (percent >= 50) {
        status.textContent = 'กำลังไปได้ดี!';
        bar.className = 'bg-yellow-500 h-5 rounded-full transition-all duration-500 flex items-center justify-center text-white text-xs font-medium';
    } else {
        status.textContent = 'เริ่มต้นตั้งค่ากันเลย';
    }
}

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
            addMessage(data.message, 'assistant', data.ai_source);
            if (data.setup_status) renderChecklist({ status: data.setup_status });
            if (data.completion_percent !== undefined) updateProgress(data.completion_percent);
        } else {
            addMessage('ขออภัย เกิดข้อผิดพลาด', 'assistant');
        }
    } catch (error) {
        hideTypingIndicator();
        addMessage('ขออภัย ไม่สามารถเชื่อมต่อได้', 'assistant');
    }
    isLoading = false;
    document.getElementById('sendBtn').disabled = false;
}

function addMessage(content, role, aiSource = null) {
    const container = document.getElementById('chatMessages');
    content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="underline">$1</a>')
                     .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                     .replace(/\n/g, '<br>');
    const div = document.createElement('div');
    div.className = `message message-${role}`;
    
    // Add AI source indicator for assistant messages
    let sourceIndicator = '';
    if (role === 'assistant' && aiSource) {
        if (aiSource === 'gemini') {
            sourceIndicator = '<div class="text-xs text-green-600 mt-1 flex items-center gap-1"><i class="fas fa-robot"></i> Gemini AI</div>';
        } else if (aiSource === 'fallback') {
            sourceIndicator = '<div class="text-xs text-orange-500 mt-1 flex items-center gap-1"><i class="fas fa-book"></i> คำตอบสำเร็จรูป</div>';
        }
    }
    
    div.innerHTML = `<div class="message-content">${content}${sourceIndicator}</div>`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function showTypingIndicator() {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.id = 'typingIndicator';
    div.className = 'message message-assistant';
    div.innerHTML = '<div class="message-content typing-indicator"><span></span><span></span><span></span></div>';
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function hideTypingIndicator() {
    const el = document.getElementById('typingIndicator');
    if (el) el.remove();
}

function askQuestion(q) {
    document.getElementById('chatInput').value = q;
    sendMessage();
}

function handleChecklistClick(key, url) {
    askQuestion(`ช่วยแนะนำเรื่อง ${key} หน่อย`);
}

async function runHealthCheck() {
    addMessage('กำลังตรวจสอบสถานะระบบ...', 'assistant');
    showTypingIndicator();
    try {
        const response = await fetch(`${API_URL}?action=health`);
        const data = await response.json();
        hideTypingIndicator();
        let msg = data.message + '\n\n';
        for (const [key, check] of Object.entries(data.checks || {})) {
            const icon = check.status === 'ok' ? '✅' : (check.status === 'warning' ? '⚠️' : '❌');
            msg += `${icon} ${key}: ${check.message}\n`;
        }
        addMessage(msg, 'assistant');
    } catch (error) {
        hideTypingIndicator();
        addMessage('❌ ไม่สามารถตรวจสอบสถานะได้', 'assistant');
    }
}

async function clearHistory() {
    if (!confirm('ต้องการล้างประวัติการสนทนาหรือไม่?')) return;
    try {
        await fetch(`${API_URL}?action=clear_history`, { method: 'POST' });
        document.getElementById('chatMessages').innerHTML = '';
        loadWelcomeMessage();
    } catch (error) {}
}
</script>

<?php require_once 'includes/footer.php'; ?>
