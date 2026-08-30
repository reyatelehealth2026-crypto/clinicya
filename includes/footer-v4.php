<?php
/**
 * REYA Admin V4 — Footer Component
 * Clean closing tags, enhanced widgets, shared utilities
 * @version 4.0.0
 */
?>
        </div><!-- /.reya-app__content -->
    </div><!-- /.reya-app__main -->
</div><!-- /.reya-app -->

<!-- Lazy Load Scripts -->
<script src="<?= htmlspecialchars($baseUrl ?? '') ?>assets/js/lazy-load.js" defer></script>
<script src="<?= htmlspecialchars($baseUrl ?? '') ?>assets/js/dashboard-notification.js" defer></script>

<!-- V4 Footer Styles -->
<link rel="stylesheet" href="/assets/css/footer-v4.css?v=4.0.0">

<!-- V4 Footer Scripts -->
<script src="/assets/js/footer-v4.js?v=4.0.0" defer></script>

<?php
// ─── Floating Action Buttons ───
// แสดง FAB ทั้ง Help และ AI Chat (ยกเว้นหน้า LIFF และ help เอง)
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$isLiffPage = str_contains($currentUri, '/liff');
$isHelpPage = str_contains($currentUri, '/help');
$showFab = !$isLiffPage && !$isHelpPage;

// ซ่อน AI Chat Widget ถ้าหน้านั้นกำหนดไว้
$hideAiChat = isset($hideAiChatWidget) && $hideAiChatWidget === true;
?>

<?php if ($showFab): ?>
<div class="fab-container">
    <!-- Help Button -->
    <a href="/help" id="floating-help-btn" class="fab fab--help"
       title="ศูนย์ช่วยเหลือ" aria-label="ศูนย์ช่วยเหลือ">
        <i class="fas fa-question" aria-hidden="true"></i>
    </a>

    <?php if (!$hideAiChat): ?>
    <!-- AI Chat Toggle -->
    <button type="button" id="ai-chat-toggle" class="fab fab--ai"
            title="AI Assistant" aria-label="เปิด AI Assistant">
        <i class="fas fa-robot" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$hideAiChat && $showFab): ?>
<!-- AI Chat Widget -->
<div id="ai-chat-widget" class="ai-widget" role="dialog" aria-label="AI Assistant Chat" aria-hidden="true">
    <div class="ai-widget__header">
        <div class="ai-widget__header-info">
            <div class="ai-widget__avatar" aria-hidden="true">
                <i class="fas fa-robot"></i>
            </div>
            <div>
                <div class="ai-widget__title">AI Assistant</div>
                <div class="ai-widget__subtitle">ถามอะไรก็ได้เกี่ยวกับระบบ</div>
            </div>
        </div>
        <div class="ai-widget__actions">
            <button type="button" id="ai-chat-help" class="ai-widget__btn" title="วิธีใช้งาน" aria-label="วิธีใช้งาน">
                <i class="fas fa-question-circle" aria-hidden="true"></i>
            </button>
            <button type="button" id="ai-chat-close" class="ai-widget__btn" title="ปิด" aria-label="ปิด chat">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div id="ai-chat-messages" class="ai-widget__messages" role="log" aria-live="polite" aria-atomic="false">
        <!-- Welcome Message -->
        <div class="ai-message">
            <div class="ai-message__avatar ai-message__avatar--ai" aria-hidden="true">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-message__bubble ai-message__bubble--ai">
                สวัสดีครับ! ผมช่วยคุณได้หลายอย่าง:<br><br>
                📊 <strong>ดูข้อมูล:</strong> สรุป, ยอดขาย, ออเดอร์, สินค้า, ลูกค้า<br>
                🚀 <strong>Actions:</strong> ยืนยันออเดอร์, อนุมัติสลิป, ปิดสินค้าหมด<br>
                🚨 <strong>Alerts:</strong> แจ้งเตือนปัญหาต่างๆ<br>
                🔍 <strong>ค้นหา:</strong> หาลูกค้า, หาออเดอร์, หาสินค้า<br><br>
                กดปุ่ม <strong>❓</strong> ด้านบนเพื่อดูคำสั่งทั้งหมด 😊
            </div>
        </div>
    </div>

    <div class="ai-widget__footer">
        <form id="ai-chat-form" class="ai-widget__form">
            <input type="text" id="ai-chat-input"
                   class="ai-widget__input"
                   placeholder="พิมพ์คำถาม..."
                   aria-label="พิมพ์คำถาม"
                   autocomplete="off">
            <button type="submit" class="ai-widget__send" aria-label="ส่งข้อความ">
                <i class="fas fa-paper-plane" aria-hidden="true"></i>
            </button>
        </form>

        <!-- Quick Actions Row 1 -->
        <div class="ai-widget__quick-actions" role="list">
            <button type="button" class="ai-quick-btn" data-msg="แจ้งเตือน" role="listitem">🚨 Alerts</button>
            <button type="button" class="ai-quick-btn" data-msg="สรุปวันนี้" role="listitem">📊 สรุป</button>
            <button type="button" class="ai-quick-btn" data-msg="ออเดอร์รอดำเนินการ" role="listitem">📦 ออเดอร์</button>
            <button type="button" class="ai-quick-btn" data-msg="สลิปรอตรวจ" role="listitem">🧾 สลิป</button>
        </div>

        <!-- Quick Actions Row 2 -->
        <div class="ai-widget__quick-actions" role="list">
            <button type="button" class="ai-quick-btn" data-msg="ยอดขายวันนี้" role="listitem">💰 ยอดขาย</button>
            <button type="button" class="ai-quick-btn" data-msg="สินค้าหมด" role="listitem">📦 สินค้าหมด</button>
            <button type="button" class="ai-quick-btn" data-msg="top ลูกค้า" role="listitem">🏆 Top</button>
            <button type="button" class="ai-quick-btn" data-msg="สถานะระบบ" role="listitem">🖥️ ระบบ</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ─── Admin Tour (Onboarding) ───
$tourLauncher = __DIR__ . '/onboarding/tour-launcher.php';
if (file_exists($tourLauncher)) {
    include $tourLauncher;
}
?>

</body>
</html>
