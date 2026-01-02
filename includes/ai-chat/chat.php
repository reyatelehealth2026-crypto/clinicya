<?php
/**
 * AI Chat Tab - หน้าแชทกับ AI ทั่วไป
 * ใช้สำหรับ Admin คุยกับ AI ช่วยตอบคำถาม
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

$adminName = $_SESSION['admin_user']['display_name'] ?? $_SESSION['admin_user']['username'] ?? 'User';
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white px-4 py-3 flex justify-between items-center">
            <div>
                <h5 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-robot"></i> AI Assistant
                    <span id="aiStatusBadge" class="text-xs px-2 py-0.5 rounded-full bg-orange-500">Checking...</span>
                </h5>
                <small class="text-blue-200 text-xs">ผู้ช่วย AI สำหรับตอบคำถามทั่วไป</small>
            </div>
            <div class="flex gap-2">
                <button onclick="clearChat()" title="Clear Chat" class="p-2 hover:bg-white/20 rounded-lg transition">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        
        <!-- Chat Messages -->
        <div id="chatMessages" class="h-[500px] overflow-y-auto p-4 bg-gray-50">
            <div class="text-center text-gray-400 py-10" id="welcomeMessage">
                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-robot text-blue-500 text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">สวัสดีครับ! 👋</h3>
                <p class="text-gray-500">ผมคือ AI Assistant พร้อมช่วยตอบคำถามของคุณ</p>
                <p class="text-gray-400 text-sm mt-2">ลองถามอะไรก็ได้เลยครับ</p>
            </div>
        </div>
        
        <!-- Chat Input -->
        <div class="border-t p-4">
            <form id="chatForm" class="flex gap-2">
                <input type="text" 
                       id="chatInput" 
                       class="flex-1 px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                       placeholder="พิมพ์ข้อความ..."
                       autocomplete="off">
                <button type="submit" id="sendBtn" class="px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition flex items-center gap-2">
                    <i class="fas fa-paper-plane"></i>
                    <span class="hidden sm:inline">ส่ง</span>
                </button>
            </form>
        </div>
    </div>
    
    <!-- Quick Prompts -->
    <div class="mt-4 bg-white rounded-xl shadow-sm p-4">
        <h6 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
            <i class="fas fa-lightbulb text-yellow-500"></i> ลองถาม
        </h6>
        <div class="flex flex-wrap gap-2">
            <button onclick="askQuestion('ช่วยเขียนข้อความต้อนรับลูกค้าใหม่')" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                ✍️ เขียนข้อความต้อนรับ
            </button>
            <button onclick="askQuestion('ช่วยคิดโปรโมชั่นสำหรับร้านขายยา')" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                🎁 คิดโปรโมชั่น
            </button>
            <button onclick="askQuestion('ช่วยเขียน caption โพสต์ขายสินค้า')" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                📝 เขียน Caption
            </button>
            <button onclick="askQuestion('ช่วยตอบคำถามลูกค้าเรื่องการจัดส่ง')" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                📦 ตอบเรื่องจัดส่ง
            </button>
            <button onclick="askQuestion('ช่วยสรุปยอดขายประจำวัน')" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                📊 สรุปยอดขาย
            </button>
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
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
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
const API_URL = '/api/ai-chat.php';
let isLoading = false;
let chatHistory = [];

document.addEventListener('DOMContentLoaded', function() {
    checkAiStatus();
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
});

async function checkAiStatus() {
    try {
        const response = await fetch(`${API_URL}?action=status`);
        const data = await response.json();
        const badge = document.getElementById('aiStatusBadge');
        if (data.ai_available) {
            badge.textContent = 'Gemini AI';
            badge.className = 'text-xs px-2 py-0.5 rounded-full bg-green-500';
        } else {
            badge.textContent = 'ไม่พร้อมใช้งาน';
            badge.className = 'text-xs px-2 py-0.5 rounded-full bg-red-500';
            badge.title = 'กรุณาตั้งค่า Gemini API Key ที่ AI Settings';
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message || isLoading) return;
    
    // Hide welcome message
    document.getElementById('welcomeMessage').style.display = 'none';
    
    input.value = '';
    addMessage(message, 'user');
    showTypingIndicator();
    isLoading = true;
    document.getElementById('sendBtn').disabled = true;
    
    // Add to history
    chatHistory.push({ role: 'user', content: message });
    
    try {
        const response = await fetch(`${API_URL}?action=chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                message,
                history: chatHistory.slice(-10) // Send last 10 messages for context
            })
        });
        const data = await response.json();
        hideTypingIndicator();
        
        if (data.success) {
            addMessage(data.message, 'assistant');
            chatHistory.push({ role: 'assistant', content: data.message });
        } else {
            addMessage(data.error || 'ขออภัย เกิดข้อผิดพลาด', 'assistant');
        }
    } catch (error) {
        hideTypingIndicator();
        addMessage('ขออภัย ไม่สามารถเชื่อมต่อได้', 'assistant');
    }
    
    isLoading = false;
    document.getElementById('sendBtn').disabled = false;
    input.focus();
}

function addMessage(content, role) {
    const container = document.getElementById('chatMessages');
    
    // Format content
    content = content
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>')
        .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre class="bg-gray-800 text-green-400 p-3 rounded-lg my-2 overflow-x-auto text-sm"><code>$2</code></pre>')
        .replace(/`([^`]+)`/g, '<code class="bg-gray-200 px-1 rounded">$1</code>');
    
    const div = document.createElement('div');
    div.className = `message message-${role}`;
    div.innerHTML = `<div class="message-content">${content}</div>`;
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

function clearChat() {
    if (!confirm('ต้องการล้างประวัติการสนทนาหรือไม่?')) return;
    document.getElementById('chatMessages').innerHTML = `
        <div class="text-center text-gray-400 py-10" id="welcomeMessage">
            <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-robot text-blue-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">สวัสดีครับ! 👋</h3>
            <p class="text-gray-500">ผมคือ AI Assistant พร้อมช่วยตอบคำถามของคุณ</p>
            <p class="text-gray-400 text-sm mt-2">ลองถามอะไรก็ได้เลยครับ</p>
        </div>
    `;
    chatHistory = [];
}
</script>
