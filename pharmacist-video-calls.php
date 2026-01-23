<?php
/**
 * Pharmacist Video Calls Dashboard
 * หน้าสำหรับเภสัชกรรับสายวิดีโอคอลจากลูกค้า
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['line_account_id'] ?? $_SESSION['admin_user']['line_account_id'] ?? 1;

// Get pharmacist info if logged in as pharmacist
$pharmacistId = $_SESSION['pharmacist_id'] ?? null;
$pharmacistInfo = null;
if ($pharmacistId) {
    $stmt = $db->prepare("SELECT * FROM pharmacists WHERE id = ?");
    $stmt->execute([$pharmacistId]);
    $pharmacistInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'วิดีโอคอล - รับสาย';
include __DIR__ . '/includes/header.php';
?>

<style>
    .video-calls-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .calls-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .calls-header h1 {
        font-size: 24px;
        font-weight: 600;
        color: #1f2937;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .status-badge.online {
        background: #D1FAE5;
        color: #059669;
    }

    .status-badge .pulse-dot {
        width: 8px;
        height: 8px;
        background: #10B981;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.5;
            transform: scale(1.2);
        }
    }

    .calls-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
    }

    .call-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        transition: all 0.3s;
        border: 2px solid transparent;
    }

    .call-card.ringing {
        border-color: #10B981;
        animation: ring-pulse 1.5s infinite;
    }

    @keyframes ring-pulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
        }

        50% {
            box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
        }
    }

    .call-card-header {
        padding: 16px;
        background: linear-gradient(135deg, #10B981, #059669);
        color: white;
    }

    .call-card-header .call-type {
        font-size: 12px;
        opacity: 0.9;
        margin-bottom: 4px;
    }

    .call-card-header .call-time {
        font-size: 14px;
        font-weight: 500;
    }

    .call-card-body {
        padding: 16px;
    }

    .caller-info {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .caller-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        object-fit: cover;
        background: #E5E7EB;
    }

    .caller-avatar.placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        background: #F3F4F6;
    }

    .caller-details h3 {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .caller-details p {
        font-size: 13px;
        color: #6B7280;
    }

    .call-actions {
        display: flex;
        gap: 12px;
    }

    .call-actions button {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-answer {
        background: #10B981;
        color: white;
    }

    .btn-answer:hover {
        background: #059669;
    }

    .btn-reject {
        background: #FEE2E2;
        color: #DC2626;
    }

    .btn-reject:hover {
        background: #FECACA;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6B7280;
    }

    .empty-state i {
        font-size: 64px;
        color: #D1D5DB;
        margin-bottom: 16px;
    }

    .empty-state h3 {
        font-size: 18px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
    }

    /* Video Call Modal */
    .video-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.9);
        z-index: 9999;
    }

    .video-modal.active {
        display: flex;
        flex-direction: column;
    }

    .video-container {
        flex: 1;
        position: relative;
        max-height: calc(100vh - 120px);
        /* Leave space for controls */
    }

    .remote-video {
        width: 100%;
        height: 100%;
        object-fit: contain;
        /* Changed from cover to contain */
        background: #1f2937;
    }

    .local-video-pip {
        position: absolute;
        top: 80px;
        right: 20px;
        width: 120px;
        aspect-ratio: 3/4;
        border-radius: 12px;
        overflow: hidden;
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: #374151;
        z-index: 10;
    }

    .local-video-pip video {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .video-controls {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 20px;
        padding-bottom: 30px;
        background: linear-gradient(transparent, rgba(0, 0, 0, 0.9));
        display: flex;
        justify-content: center;
        gap: 16px;
        z-index: 20;
    }

    .video-ctrl-btn {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: none;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .video-ctrl-btn.mute {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .video-ctrl-btn.mute.active {
        background: #EF4444;
    }

    .video-ctrl-btn.camera {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .video-ctrl-btn.camera.active {
        background: #EF4444;
    }

    .video-ctrl-btn.screen {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .video-ctrl-btn.screen.active {
        background: #3B82F6;
    }

    .video-ctrl-btn.end {
        background: #EF4444;
        color: white;
        width: 64px;
        height: 64px;
        font-size: 24px;
    }

    .video-ctrl-btn:hover {
        transform: scale(1.1);
    }

    .video-status {
        position: absolute;
        top: 20px;
        left: 20px;
        background: rgba(0, 0, 0, 0.6);
        padding: 8px 16px;
        border-radius: 20px;
        color: white;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        z-index: 10;
    }

    .video-status .dot {
        width: 8px;
        height: 8px;
        background: #10B981;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }

    /* Quick Actions in Video Modal */
    .video-quick-actions {
        position: absolute;
        top: 80px;
        left: 20px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        z-index: 10;
    }

    .video-quick-actions button {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        border: none;
        background: rgba(255, 255, 255, 0.9);
        font-size: 20px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .video-quick-actions button:hover {
        transform: scale(1.1);
        background: white;
    }

    .video-quick-actions button:active {
        transform: scale(0.95);
    }
</style>

<div class="video-calls-container">
    <div class="calls-header">
        <h1><i class="fas fa-video"></i> วิดีโอคอล - รับสาย</h1>
        <div class="status-badge online">
            <span class="pulse-dot"></span>
            <span>พร้อมรับสาย</span>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions-card"
        style="background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <h3
            style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 18px;">⚡</span> Quick Actions
        </h3>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
            <button onclick="sendGreeting()"
                style="padding: 14px 16px; border-radius: 12px; border: none; background: #FEF3C7; color: #92400E; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                <span>👋</span> ทักทาย
            </button>
            <button onclick="sendWaiting()"
                style="padding: 14px 16px; border-radius: 12px; border: none; background: #FEE2E2; color: #991B1B; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                <span>⏳</span> รอสักครู่
            </button>
            <button onclick="takeScreenshot()"
                style="padding: 14px 16px; border-radius: 12px; border: none; background: #E0E7FF; color: #3730A3; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                <span>📸</span> Screenshot
            </button>
            <button onclick="toggleRecording()" id="btn-record"
                style="padding: 14px 16px; border-radius: 12px; border: none; background: #DBEAFE; color: #1E40AF; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                <span>🔴</span> บันทึก
            </button>
        </div>
    </div>

    <div id="calls-list" class="calls-grid">
        <div class="empty-state">
            <i class="fas fa-phone-slash"></i>
            <h3>ไม่มีสายเรียกเข้า</h3>
            <p>รอลูกค้าโทรเข้ามา...</p>
        </div>
    </div>
</div>

<!-- Video Call Modal -->
<div id="video-modal" class="video-modal">
    <div class="video-container">
        <video id="remote-video" class="remote-video" autoplay playsinline></video>
        <div class="local-video-pip">
            <video id="local-video" autoplay playsinline muted></video>
        </div>
        <div class="video-status">
            <span class="dot"></span>
            <span id="call-status-text">กำลังเชื่อมต่อ...</span>
            <span id="call-timer" style="margin-left: 12px; display: none;">00:00</span>
        </div>

        <!-- Quick Actions Panel (in video modal) -->
        <div class="video-quick-actions" id="video-quick-actions">
            <button onclick="sendGreeting()" title="ทักทาย">👋</button>
            <button onclick="sendWaiting()" title="รอสักครู่">⏳</button>
            <button onclick="takeScreenshot()" title="Screenshot">📸</button>
            <button onclick="toggleRecording()" id="btn-record-modal" title="บันทึก">🔴</button>
        </div>

        <div class="video-controls">
            <button class="video-ctrl-btn mute" id="btn-mute" onclick="toggleMute()">
                <i class="fas fa-microphone"></i>
            </button>
            <button class="video-ctrl-btn end" onclick="endCurrentCall()">
                <i class="fas fa-phone-slash"></i>
            </button>
            <button class="video-ctrl-btn camera" id="btn-camera" onclick="toggleCamera()">
                <i class="fas fa-video"></i>
            </button>
            <button class="video-ctrl-btn screen" id="btn-screen" onclick="toggleScreenShare()" title="แชร์หน้าจอ">
                <i class="fas fa-desktop"></i>
            </button>
        </div>
    </div>
</div>

<script>
    const API_URL = '<?= rtrim(BASE_URL, "/") ?>/api/video-call.php';
    const LINE_ACCOUNT_ID = <?= (int) $lineAccountId ?>;

    const rtcConfig = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
            { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' }
        ]
    };

    let localStream = null;
    let peerConnection = null;
    let currentCallId = null;
    let signalPoll = null;
    let callTimer = null;
    let callSeconds = 0;
    let isMuted = false;
    let isCameraOff = false;
    let isScreenSharing = false;
    let pendingIceCandidates = [];
    let offerReceived = false;

    // Poll for incoming calls
    async function checkIncomingCalls() {
        try {
            const res = await fetch(`${API_URL}?action=check_calls&account_id=${LINE_ACCOUNT_ID}`);
            const data = await res.json();

            if (data.success && data.calls?.length > 0) {
                renderCalls(data.calls);
            } else {
                renderEmptyState();
            }
        } catch (e) {
            console.error('Check calls error:', e);
        }
    }

    function renderCalls(calls) {
        const container = document.getElementById('calls-list');
        container.innerHTML = calls.map(call => {
            // Format appointment info if available
            const aptCode = call.apt_code || call.appointment_id || null;
            const aptInfo = aptCode ? `
            <div style="background: #F0FDF4; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; font-size: 13px;">
                <div style="color: #059669; font-weight: 600; margin-bottom: 4px;">
                    📋 #${aptCode}
                </div>
                ${call.symptoms ? `<div style="color: #6B7280;">อาการ: ${call.symptoms}</div>` : ''}
                ${call.appointment_date ? `<div style="color: #6B7280;">📅 ${formatDate(call.appointment_date)} ${call.appointment_time || ''}</div>` : ''}
            </div>
        ` : '';

            return `
        <div class="call-card ${call.status === 'ringing' ? 'ringing' : ''}" data-call-id="${call.id}">
            <div class="call-card-header">
                <div class="call-type">📹 วิดีโอคอล</div>
                <div class="call-time">${formatTime(call.created_at)}</div>
            </div>
            <div class="call-card-body">
                ${aptInfo}
                <div class="caller-info">
                    ${call.picture_url
                    ? `<img src="${call.picture_url}" class="caller-avatar" alt="">`
                    : `<div class="caller-avatar placeholder">👤</div>`
                }
                    <div class="caller-details">
                        <h3>${call.display_name || 'ลูกค้า'}</h3>
                        <p>${call.phone || call.line_user_id?.substring(0, 10) + '...' || 'ไม่ระบุ'}</p>
                    </div>
                </div>
                <div class="call-actions">
                    <button class="btn-reject" onclick="rejectCall(${call.id})">
                        <i class="fas fa-phone-slash"></i> ปฏิเสธ
                    </button>
                    <button class="btn-answer" onclick="answerCall(${call.id})">
                        <i class="fas fa-phone"></i> รับสาย
                    </button>
                </div>
            </div>
        </div>
    `}).join('');
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function renderEmptyState() {
        document.getElementById('calls-list').innerHTML = `
        <div class="empty-state">
            <i class="fas fa-phone-slash"></i>
            <h3>ไม่มีสายเรียกเข้า</h3>
            <p>รอลูกค้าโทรเข้ามา...</p>
        </div>
    `;
    }

    function formatTime(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    }

    // Answer call
    async function answerCall(callId) {
        currentCallId = callId;

        // Show video modal
        document.getElementById('video-modal').classList.add('active');
        document.getElementById('call-status-text').textContent = 'กำลังเชื่อมต่อ...';

        try {
            // Get local media - allow to proceed without camera for testing
            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: { echoCancellation: true, noiseSuppression: true }
                });
                document.getElementById('local-video').srcObject = localStream;
            } catch (mediaError) {
                console.warn('📹 Could not get media, proceeding without:', mediaError.message);
                // Continue without local stream for testing
            }

            // Update call status to active
            await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'answer', call_id: callId })
            });

            // Setup peer connection
            await setupPeerConnection();

            // Start polling for signals
            startSignalPolling();

        } catch (e) {
            console.error('Answer call error:', e);
            alert('เกิดข้อผิดพลาด: ' + e.message);
            closeVideoModal();
        }
    }

    async function setupPeerConnection() {
        peerConnection = new RTCPeerConnection(rtcConfig);

        // Add local tracks
        localStream?.getTracks().forEach(track => {
            peerConnection.addTrack(track, localStream);
        });

        // Handle remote stream
        peerConnection.ontrack = (event) => {
            document.getElementById('remote-video').srcObject = event.streams[0];
            document.getElementById('call-status-text').textContent = 'เชื่อมต่อแล้ว';
            document.getElementById('call-timer').style.display = 'inline';
            if (!callTimer) startCallTimer();
        };

        // Handle ICE candidates
        peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal('ice-candidate', event.candidate);
            }
        };

        // Handle connection state
        peerConnection.oniceconnectionstatechange = () => {
            const state = peerConnection.iceConnectionState;

            if (state === 'connected' || state === 'completed') {
                document.getElementById('call-status-text').textContent = 'เชื่อมต่อแล้ว';
                document.getElementById('call-timer').style.display = 'inline';
                if (!callTimer) startCallTimer();
            } else if (state === 'failed' || state === 'disconnected') {
                document.getElementById('call-status-text').textContent = 'การเชื่อมต่อขาดหาย';
            }
        };
    }

    function startSignalPolling() {
        signalPoll = setInterval(async () => {
            if (!currentCallId) return;

            try {
                // Check call status
                const statusRes = await fetch(`${API_URL}?action=get_status&call_id=${currentCallId}`);
                const statusData = await statusRes.json();

                if (statusData.status === 'completed' || statusData.status === 'ended') {
                    endCurrentCall('ลูกค้าวางสายแล้ว');
                    return;
                }

                // Get signals
                const sigRes = await fetch(`${API_URL}?action=get_signals&call_id=${currentCallId}&for=admin`);
                const sigData = await sigRes.json();

                if (sigData.success && sigData.signals?.length > 0) {
                    for (const sig of sigData.signals) {
                        await handleSignal(sig);
                    }
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
        }, 500);
    }

    async function handleSignal(signal) {
        if (!peerConnection && signal.signal_type !== 'hangup' && signal.signal_type !== 'message') return;

        try {
            if (signal.signal_type === 'offer') {
                if (offerReceived) return;

                await peerConnection.setRemoteDescription(new RTCSessionDescription(signal.signal_data));
                offerReceived = true;

                // Create and send answer
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);
                await sendSignal('answer', answer);

                // Process pending ICE candidates
                for (const candidate of pendingIceCandidates) {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                }
                pendingIceCandidates = [];

            } else if (signal.signal_type === 'ice-candidate' && signal.signal_data) {
                if (!peerConnection.remoteDescription) {
                    pendingIceCandidates.push(signal.signal_data);
                } else {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(signal.signal_data));
                }
            } else if (signal.signal_type === 'hangup') {
                // Other party hung up
                endCurrentCall('ลูกค้าวางสายแล้ว');
            } else if (signal.signal_type === 'message') {
                // Received message from other party
                showIncomingMessage(signal.signal_data);
            }
        } catch (e) {
            console.error('Signal handling error:', e);
        }
    }

    // Show incoming message overlay
    function showIncomingMessage(data) {
        const text = data.text || data.message || 'ข้อความจากลูกค้า';

        // Create message overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 24px 48px;
        border-radius: 16px;
        font-size: 24px;
        z-index: 10001;
        animation: fadeInScale 0.3s ease;
        text-align: center;
        max-width: 80%;
    `;
        overlay.innerHTML = `<div style="font-size: 48px; margin-bottom: 12px;">${data.type === 'greeting' ? '👋' : '⏳'}</div>${text}`;
        document.body.appendChild(overlay);

        // Remove after 3 seconds
        setTimeout(() => {
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.3s';
            setTimeout(() => overlay.remove(), 300);
        }, 3000);
    }

    async function sendSignal(type, data) {
        await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'signal',
                call_id: currentCallId,
                signal_type: type,
                signal_data: data,
                from: 'admin'
            })
        });
    }

    // Reject call
    async function rejectCall(callId) {
        if (!confirm('ปฏิเสธสายนี้?')) return;

        try {
            await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reject', call_id: callId })
            });
            checkIncomingCalls();
        } catch (e) {
            console.error('Reject error:', e);
        }
    }

    // End current call
    async function endCurrentCall(message = 'สิ้นสุดการโทร') {
        const wasCallId = currentCallId;
        const duration = callSeconds;

        // Send hangup signal to other party first
        if (wasCallId && message !== 'ลูกค้าวางสายแล้ว') {
            try {
                await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'signal',
                        call_id: wasCallId,
                        signal_type: 'hangup',
                        signal_data: { reason: message },
                        from: 'admin'
                    })
                });
            } catch (e) {
                console.error('Failed to send hangup signal:', e);
            }
        }

        if (signalPoll) {
            clearInterval(signalPoll);
            signalPoll = null;
        }

        if (callTimer) {
            clearInterval(callTimer);
            callTimer = null;
        }

        if (peerConnection) {
            peerConnection.close();
            peerConnection = null;
        }

        if (wasCallId) {
            try {
                await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'end',
                        call_id: wasCallId,
                        duration: duration
                    })
                });
            } catch (e) { }
        }

        currentCallId = null;
        callSeconds = 0;
        offerReceived = false;
        pendingIceCandidates = [];

        closeVideoModal();
        alert(message + `\nระยะเวลา: ${formatDuration(duration)}`);
        checkIncomingCalls();
    }

    function closeVideoModal() {
        document.getElementById('video-modal').classList.remove('active');

        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
            localStream = null;
        }
    }

    function toggleMute() {
        isMuted = !isMuted;
        localStream?.getAudioTracks().forEach(track => track.enabled = !isMuted);

        const btn = document.getElementById('btn-mute');
        btn.classList.toggle('active', isMuted);
        btn.innerHTML = isMuted ? '<i class="fas fa-microphone-slash"></i>' : '<i class="fas fa-microphone"></i>';
    }

    function toggleCamera() {
        isCameraOff = !isCameraOff;
        localStream?.getVideoTracks().forEach(track => track.enabled = !isCameraOff);

        const btn = document.getElementById('btn-camera');
        btn.classList.toggle('active', isCameraOff);
        btn.innerHTML = isCameraOff ? '<i class="fas fa-video-slash"></i>' : '<i class="fas fa-video"></i>';
    }

    async function toggleScreenShare() {
        if (!peerConnection) return;

        const btn = document.getElementById('btn-screen');

        if (!isScreenSharing) {
            try {
                const stream = await navigator.mediaDevices.getDisplayMedia({ cursor: "always" });
                const screenTrack = stream.getVideoTracks()[0];

                // Replace video track in sender
                const sender = peerConnection.getSenders().find(s => s.track.kind === 'video');
                if (sender) {
                    await sender.replaceTrack(screenTrack);
                }

                // Update local video preview
                document.getElementById('local-video').srcObject = stream;

                // Handle stream end (user clicks stop sharing in browser UI)
                screenTrack.onended = () => {
                    if (isScreenSharing) toggleScreenShare(); // Revert to camera if still in sharing mode
                };

                isScreenSharing = true;
                btn.classList.add('active');
            } catch (err) {
                console.error("Error sharing screen: " + err);
            }
        } else {
            // Revert to camera
            try {
                // Determine which stream to use (camera)
                let videoTrack = localStream ? localStream.getVideoTracks()[0] : null;

                // If no local stream (e.g. valid camera not found initially), try to get it again
                if (!videoTrack) {
                    try {
                        const newStream = await navigator.mediaDevices.getUserMedia({ video: true });
                        videoTrack = newStream.getVideoTracks()[0];
                        // Update local stream ref
                        if (!localStream) localStream = newStream;
                        else localStream.addTrack(videoTrack);
                    } catch (e) { }
                }

                if (videoTrack) {
                    const sender = peerConnection.getSenders().find(s => s.track.kind === 'video');
                    if (sender) {
                        await sender.replaceTrack(videoTrack);
                    }
                    document.getElementById('local-video').srcObject = localStream;
                }

                isScreenSharing = false;
                btn.classList.remove('active');
            } catch (err) {
                console.error("Error reverting to camera: " + err);
            }
        }
    }

    function startCallTimer() {
        callSeconds = 0;
        callTimer = setInterval(() => {
            callSeconds++;
            document.getElementById('call-timer').textContent = formatDuration(callSeconds);
        }, 1000);
    }

    function formatDuration(seconds) {
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    }

    // ==================== Quick Actions ====================

    let isRecording = false;
    let mediaRecorder = null;
    let recordedChunks = [];

    // Send greeting message via signal
    async function sendGreeting() {
        if (!currentCallId) {
            showQuickActionToast('⚠️ ยังไม่มีสายที่กำลังโทร', 'warning');
            return;
        }

        try {
            await sendSignal('message', { type: 'greeting', text: '👋 สวัสดีครับ/ค่ะ ยินดีให้บริการ' });
            showQuickActionToast('👋 ส่งข้อความทักทายแล้ว');
        } catch (e) {
            showQuickActionToast('❌ ส่งข้อความไม่สำเร็จ', 'error');
        }
    }

    // Send waiting message via signal
    async function sendWaiting() {
        if (!currentCallId) {
            showQuickActionToast('⚠️ ยังไม่มีสายที่กำลังโทร', 'warning');
            return;
        }

        try {
            await sendSignal('message', { type: 'waiting', text: '⏳ กรุณารอสักครู่นะครับ/ค่ะ' });
            showQuickActionToast('⏳ ส่งข้อความรอสักครู่แล้ว');
        } catch (e) {
            showQuickActionToast('❌ ส่งข้อความไม่สำเร็จ', 'error');
        }
    }

    // Take screenshot of video call
    function takeScreenshot() {
        const remoteVideo = document.getElementById('remote-video');
        const localVideo = document.getElementById('local-video');

        // Try remote video first, then local video
        let videoEl = null;
        if (remoteVideo && remoteVideo.srcObject && remoteVideo.videoWidth > 0) {
            videoEl = remoteVideo;
        } else if (localVideo && localVideo.srcObject && localVideo.videoWidth > 0) {
            videoEl = localVideo;
        }

        if (!videoEl) {
            showQuickActionToast('⚠️ ยังไม่มีวิดีโอให้ถ่าย', 'warning');
            return;
        }

        try {
            const canvas = document.createElement('canvas');
            canvas.width = videoEl.videoWidth || 640;
            canvas.height = videoEl.videoHeight || 480;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

            // Download screenshot
            const link = document.createElement('a');
            link.download = `screenshot_${Date.now()}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();

            showQuickActionToast('📸 บันทึก Screenshot แล้ว');
        } catch (e) {
            console.error('Screenshot error:', e);
            showQuickActionToast('❌ ไม่สามารถถ่ายภาพได้', 'error');
        }
    }

    // Toggle recording
    function toggleRecording() {
        const btn = document.getElementById('btn-record');
        const btnModal = document.getElementById('btn-record-modal');

        if (!isRecording) {
            // Start recording
            const remoteVideo = document.getElementById('remote-video');
            const localVideo = document.getElementById('local-video');

            let stream = null;
            if (remoteVideo && remoteVideo.srcObject) {
                stream = remoteVideo.srcObject;
            } else if (localVideo && localVideo.srcObject) {
                stream = localVideo.srcObject;
            }

            if (!stream) {
                showQuickActionToast('⚠️ ยังไม่มีวิดีโอให้บันทึก', 'warning');
                return;
            }

            try {
                recordedChunks = [];
                mediaRecorder = new MediaRecorder(stream, { mimeType: 'video/webm' });

                mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) recordedChunks.push(e.data);
                };

                mediaRecorder.onstop = () => {
                    const blob = new Blob(recordedChunks, { type: 'video/webm' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.download = `recording_${Date.now()}.webm`;
                    link.href = url;
                    link.click();
                };

                mediaRecorder.start();
                isRecording = true;
                if (btn) {
                    btn.innerHTML = '<span>⏹️</span> หยุดบันทึก';
                    btn.style.background = '#FEE2E2';
                    btn.style.color = '#991B1B';
                }
                if (btnModal) {
                    btnModal.textContent = '⏹️';
                    btnModal.style.background = '#FEE2E2';
                }
                showQuickActionToast('🔴 เริ่มบันทึกวิดีโอ');
            } catch (e) {
                console.error('Recording error:', e);
                showQuickActionToast('❌ ไม่สามารถบันทึกได้: ' + e.message, 'error');
            }
        } else {
            // Stop recording
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            isRecording = false;
            if (btn) {
                btn.innerHTML = '<span>🔴</span> บันทึก';
                btn.style.background = '#DBEAFE';
                btn.style.color = '#1E40AF';
            }
            if (btnModal) {
                btnModal.textContent = '🔴';
                btnModal.style.background = 'rgba(255,255,255,0.9)';
            }
            showQuickActionToast('⏹️ หยุดบันทึกและดาวน์โหลดแล้ว');
        }
    }

    // Show toast for quick actions
    function showQuickActionToast(message, type = 'success') {
        const bgColors = {
            'success': '#1F2937',
            'warning': '#92400E',
            'error': '#991B1B'
        };

        const toast = document.createElement('div');
        toast.style.cssText = `
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        background: ${bgColors[type] || bgColors.success};
        color: white;
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 14px;
        z-index: 10000;
        animation: fadeInUp 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    `;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    // Start polling
    setInterval(checkIncomingCalls, 3000);
    checkIncomingCalls();

    // Initial call immediately
    document.addEventListener('DOMContentLoaded', function () {
        checkIncomingCalls();
    });
</script>

<style>
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translate(-50%, 20px);
        }

        to {
            opacity: 1;
            transform: translate(-50%, 0);
        }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>