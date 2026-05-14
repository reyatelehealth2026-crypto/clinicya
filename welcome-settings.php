<?php
/**
 * Welcome Message Settings - ตั้งค่าข้อความต้อนรับ
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/components/form-section.php';
require_once __DIR__ . '/includes/components/field.php';
require_once __DIR__ . '/includes/components/toggle.php';
require_once __DIR__ . '/includes/components/sticky-save-bar.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ข้อความต้อนรับ';

require_once 'includes/header.php';
?>
<script src="assets/js/flex-preview.js"></script>
<?= getFormSectionStyles() ?>
<?= getFieldStyles() ?>
<?= getToggleStyles() ?>
<?= getStickySaveBarStyles() ?>
<?php

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $messageType = $_POST['message_type'];
        $textContent = $_POST['text_content'] ?? '';
        $flexContent = $_POST['flex_content'] ?? '';
        
        // Check if settings exist for this bot
        $stmt = $db->prepare("SELECT id FROM welcome_settings WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL)");
        $stmt->execute([$currentBotId, $currentBotId]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $db->prepare("UPDATE welcome_settings SET is_enabled = ?, message_type = ?, text_content = ?, flex_content = ? WHERE id = ?");
            $stmt->execute([$isEnabled, $messageType, $textContent, $flexContent, $exists['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO welcome_settings (line_account_id, is_enabled, message_type, text_content, flex_content) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$currentBotId, $isEnabled, $messageType, $textContent, $flexContent]);
        }
        
        $success = true;
    }
}

// Get current settings
$stmt = $db->prepare("SELECT * FROM welcome_settings WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL) LIMIT 1");
$stmt->execute([$currentBotId, $currentBotId]);
$settings = $stmt->fetch();

if (!$settings) {
    $settings = [
        'is_enabled' => 0,
        'message_type' => 'text',
        'text_content' => "สวัสดีค่ะ ยินดีต้อนรับ! 🎉\n\nขอบคุณที่เพิ่มเราเป็นเพื่อน\nหากต้องการความช่วยเหลือ สามารถพิมพ์ข้อความมาได้เลยค่ะ",
        'flex_content' => ''
    ];
}

// Default Flex template
$defaultFlex = json_encode([
    'type' => 'bubble',
    'hero' => [
        'type' => 'image',
        'url' => 'https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png',
        'size' => 'full',
        'aspectRatio' => '20:13',
        'aspectMode' => 'cover'
    ],
    'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            ['type' => 'text', 'text' => 'ยินดีต้อนรับ! 🎉', 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
            ['type' => 'text', 'text' => 'ขอบคุณที่เพิ่มเราเป็นเพื่อน', 'size' => 'sm', 'color' => '#666666', 'margin' => 'md', 'wrap' => true],
            ['type' => 'separator', 'margin' => 'lg'],
            ['type' => 'text', 'text' => '📌 บริการของเรา', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
            [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'md',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => '• สินค้าคุณภาพ', 'size' => 'sm', 'color' => '#666666'],
                    ['type' => 'text', 'text' => '• จัดส่งรวดเร็ว', 'size' => 'sm', 'color' => '#666666'],
                    ['type' => 'text', 'text' => '• บริการหลังการขาย', 'size' => 'sm', 'color' => '#666666']
                ]
            ]
        ]
    ],
    'footer' => [
        'type' => 'box',
        'layout' => 'vertical',
        'spacing' => 'sm',
        'contents' => [
            ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ดูสินค้า', 'text' => 'shop'], 'style' => 'primary', 'color' => '#06C755'],
            ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📞 ติดต่อเรา', 'text' => 'ติดต่อ'], 'style' => 'secondary']
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if (empty($settings['flex_content'])) {
    $settings['flex_content'] = $defaultFlex;
}
?>

<?php if (isset($success)): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าเรียบร้อยแล้ว
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Settings Form -->
    <div>
        <form method="POST" id="welcomeForm">
            <input type="hidden" name="action" value="save">

            <?php
            // --- Section: ตั้งค่าข้อความต้อนรับ ---
            ob_start();
            echo renderToggle(
                'is_enabled',
                'เปิดใช้งานข้อความต้อนรับ',
                (bool) $settings['is_enabled'],
                'ส่งข้อความอัตโนมัติเมื่อมีคนเพิ่มเพื่อน',
                ['color' => 'emerald']
            );

            // Message type radio buttons (raw — no renderField equivalent for radio groups)
            echo '<div class="field-group">';
            echo '<label class="field-label">ประเภทข้อความ</label>';
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            echo '<label class="toggle-label-row" style="flex:1;min-width:140px;cursor:pointer;">';
            echo '<div class="toggle-text"><span class="toggle-label"><i class="fas fa-font" style="margin-right:6px;"></i>ข้อความธรรมดา</span></div>';
            echo '<input type="radio" name="message_type" value="text" ' . ($settings['message_type'] === 'text' ? 'checked' : '') . ' class="toggle-input sr-only" onchange="toggleMessageType()">';
            echo '<span class="toggle-thumb" aria-hidden="true"></span></label>';
            echo '<label class="toggle-label-row" style="flex:1;min-width:140px;cursor:pointer;">';
            echo '<div class="toggle-text"><span class="toggle-label"><i class="fas fa-puzzle-piece" style="margin-right:6px;"></i>Flex Message</span></div>';
            echo '<input type="radio" name="message_type" value="flex" ' . ($settings['message_type'] === 'flex' ? 'checked' : '') . ' class="toggle-input sr-only" onchange="toggleMessageType()">';
            echo '<span class="toggle-thumb" aria-hidden="true"></span></label>';
            echo '</div></div>';

            // Text content section
            echo '<div id="textSection"' . ($settings['message_type'] !== 'text' ? ' style="display:none"' : '') . '>';
            echo renderField('text_content', 'ข้อความต้อนรับ', 'textarea', $settings['text_content'], [
                'id'          => 'textContent',
                'placeholder' => 'พิมพ์ข้อความต้อนรับ...',
                'rows'        => 6,
                'help'        => 'รองรับ Emoji และขึ้นบรรทัดใหม่ได้',
            ]);
            echo '</div>';

            // Flex content section
            echo '<div id="flexSection"' . ($settings['message_type'] !== 'flex' ? ' style="display:none"' : '') . '>';
            echo '<div class="field-group">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
            echo '<label class="field-label" style="margin-bottom:0">Flex Message JSON</label>';
            echo '<div style="display:flex;gap:12px;font-size:var(--text-sm);">';
            echo '<button type="button" onclick="loadTemplate(\'welcome\')" style="color:var(--color-emerald-600);background:none;border:none;cursor:pointer;text-decoration:underline;">โหลด Template</button>';
            echo '<a href="flex-builder.php" target="_blank" style="color:var(--color-primary-600);">Flex Builder →</a>';
            echo '</div></div>';
            echo '<textarea name="flex_content" id="flexContent" rows="15" class="field-input" style="font-family:var(--font-mono);font-size:var(--text-sm);">' . htmlspecialchars($settings['flex_content']) . '</textarea>';
            echo '<p class="field-help">ใส่ JSON ของ Flex Message (bubble หรือ carousel)</p>';
            echo '</div></div>';

            $sectionBody = ob_get_clean();
            echo renderFormSection('ตั้งค่าข้อความต้อนรับ', 'fas fa-hand-sparkles', '', $sectionBody);
            ?>

            <?= renderStickySaveBar('welcomeForm', 'บันทึกการตั้งค่า') ?>
        </form>
    </div>

    <!-- Preview -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4 flex items-center">
            <i class="fas fa-eye text-blue-500 mr-2"></i>
            ตัวอย่างข้อความ
        </h3>

        <div class="bg-gray-100 rounded-lg p-4 min-h-[400px]">
            <!-- Chat Preview -->
            <div class="max-w-sm mx-auto">
                <!-- System message -->
                <div class="text-center mb-4">
                    <span class="text-xs bg-gray-300 text-gray-600 px-3 py-1 rounded-full">ผู้ใช้เพิ่มเพื่อน</span>
                </div>

                <!-- Bot message -->
                <div id="previewArea" class="flex justify-start">
                    <div class="max-w-[280px]">
                        <div id="textPreview" class="bg-white rounded-2xl rounded-tl-none px-4 py-3 shadow">
                            <p class="text-sm whitespace-pre-wrap"><?= nl2br(htmlspecialchars($settings['text_content'])) ?></p>
                        </div>
                        <div id="flexPreview" class="hidden">
                            <!-- Visual Flex Preview -->
                            <div id="flexPreviewContainer"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Button -->
        <div class="mt-4">
            <p class="text-sm text-gray-500 mb-2">ทดสอบส่งข้อความต้อนรับไปยังตัวเอง</p>
            <button type="button" onclick="testWelcome()" class="w-full py-2 border border-green-500 text-green-500 rounded-lg hover:bg-green-50">
                <i class="fas fa-paper-plane mr-2"></i>ทดสอบส่ง
            </button>
        </div>
    </div>
</div>

<!-- Flex Templates -->
<?php
ob_start();
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">';
echo '<button type="button" onclick="loadTemplate(\'simple\')" class="toggle-label-row" style="flex-direction:column;align-items:flex-start;padding:16px;cursor:pointer;text-align:left;">';
echo '<span class="toggle-label">🎉 แบบเรียบง่าย</span>';
echo '<span class="toggle-desc">ข้อความต้อนรับพื้นฐาน</span>';
echo '</button>';
echo '<button type="button" onclick="loadTemplate(\'shop\')" class="toggle-label-row" style="flex-direction:column;align-items:flex-start;padding:16px;cursor:pointer;text-align:left;">';
echo '<span class="toggle-label">🛒 แบบร้านค้า</span>';
echo '<span class="toggle-desc">มีปุ่มดูสินค้า</span>';
echo '</button>';
echo '<button type="button" onclick="loadTemplate(\'service\')" class="toggle-label-row" style="flex-direction:column;align-items:flex-start;padding:16px;cursor:pointer;text-align:left;">';
echo '<span class="toggle-label">💼 แบบบริการ</span>';
echo '<span class="toggle-desc">แนะนำบริการ</span>';
echo '</button>';
echo '</div>';
$templatesBody = ob_get_clean();
echo renderFormSection('Template สำเร็จรูป', 'fas fa-palette', '', $templatesBody);
?>

<script>
const templates = {
    simple: {
        type: 'bubble',
        body: {
            type: 'box',
            layout: 'vertical',
            contents: [
                {type: 'text', text: 'ยินดีต้อนรับ! 🎉', weight: 'bold', size: 'xl', color: '#06C755'},
                {type: 'text', text: 'ขอบคุณที่เพิ่มเราเป็นเพื่อน', size: 'sm', color: '#666666', margin: 'md', wrap: true},
                {type: 'text', text: 'หากต้องการความช่วยเหลือ สามารถพิมพ์ข้อความมาได้เลยค่ะ', size: 'sm', color: '#666666', margin: 'md', wrap: true}
            ]
        }
    },
    shop: {
        type: 'bubble',
        hero: {
            type: 'image',
            url: 'https://developers-resource.landpress.line.me/fx/img/01_1_cafe.png',
            size: 'full',
            aspectRatio: '20:13',
            aspectMode: 'cover'
        },
        body: {
            type: 'box',
            layout: 'vertical',
            contents: [
                {type: 'text', text: 'ยินดีต้อนรับสู่ร้านของเรา! 🛒', weight: 'bold', size: 'lg', color: '#06C755'},
                {type: 'text', text: 'ขอบคุณที่เพิ่มเราเป็นเพื่อน', size: 'sm', color: '#666666', margin: 'md'},
                {type: 'separator', margin: 'lg'},
                {type: 'text', text: '🎁 สิทธิพิเศษสำหรับเพื่อนใหม่', weight: 'bold', size: 'sm', margin: 'lg'},
                {type: 'text', text: '• ส่วนลด 10% ออเดอร์แรก', size: 'sm', color: '#FF6B6B', margin: 'sm'},
                {type: 'text', text: '• ส่งฟรีเมื่อซื้อครบ 500 บาท', size: 'sm', color: '#666666', margin: 'sm'}
            ]
        },
        footer: {
            type: 'box',
            layout: 'vertical',
            spacing: 'sm',
            contents: [
                {type: 'button', action: {type: 'message', label: '🛒 ดูสินค้า', text: 'shop'}, style: 'primary', color: '#06C755'},
                {type: 'button', action: {type: 'message', label: '📞 ติดต่อเรา', text: 'ติดต่อ'}, style: 'secondary'}
            ]
        }
    },
    service: {
        type: 'bubble',
        body: {
            type: 'box',
            layout: 'vertical',
            contents: [
                {type: 'text', text: 'ยินดีต้อนรับ! 💼', weight: 'bold', size: 'xl', color: '#06C755'},
                {type: 'text', text: 'ขอบคุณที่สนใจบริการของเรา', size: 'sm', color: '#666666', margin: 'md'},
                {type: 'separator', margin: 'lg'},
                {type: 'text', text: '📌 บริการของเรา', weight: 'bold', size: 'sm', margin: 'lg'},
                {
                    type: 'box',
                    layout: 'vertical',
                    margin: 'md',
                    spacing: 'sm',
                    contents: [
                        {type: 'text', text: '✅ ให้คำปรึกษาฟรี', size: 'sm', color: '#666666'},
                        {type: 'text', text: '✅ บริการรวดเร็ว', size: 'sm', color: '#666666'},
                        {type: 'text', text: '✅ ราคาเป็นกันเอง', size: 'sm', color: '#666666'}
                    ]
                }
            ]
        },
        footer: {
            type: 'box',
            layout: 'vertical',
            spacing: 'sm',
            contents: [
                {type: 'button', action: {type: 'message', label: '📋 ดูบริการ', text: 'บริการ'}, style: 'primary', color: '#06C755'},
                {type: 'button', action: {type: 'uri', label: '📞 โทรหาเรา', uri: 'tel:0812345678'}, style: 'secondary'}
            ]
        }
    }
};

function toggleMessageType() {
    const type = document.querySelector('input[name="message_type"]:checked').value;
    document.getElementById('textSection').classList.toggle('hidden', type !== 'text');
    document.getElementById('flexSection').classList.toggle('hidden', type !== 'flex');
    document.getElementById('textPreview').classList.toggle('hidden', type !== 'text');
    document.getElementById('flexPreview').classList.toggle('hidden', type !== 'flex');
    
    // Update flex preview
    if (type === 'flex') {
        updateFlexPreview();
    }
}

function updateFlexPreview() {
    const jsonStr = document.getElementById('flexContent')?.value?.trim();
    if (!jsonStr) {
        document.getElementById('flexPreviewContainer').innerHTML = '<div class="text-center text-gray-400 py-4"><i class="fas fa-puzzle-piece text-2xl mb-2"></i><p class="text-sm">ใส่ JSON เพื่อดู Preview</p></div>';
        return;
    }
    try {
        const json = JSON.parse(jsonStr);
        FlexPreview.render('flexPreviewContainer', json);
    } catch (e) {
        document.getElementById('flexPreviewContainer').innerHTML = '<div class="text-center text-red-400 py-4"><i class="fas fa-exclamation-circle text-2xl mb-2"></i><p class="text-sm">JSON ไม่ถูกต้อง</p></div>';
    }
}

function loadTemplate(name) {
    if (templates[name]) {
        document.getElementById('flexContent').value = JSON.stringify(templates[name], null, 2);
        document.querySelector('input[name="message_type"][value="flex"]').checked = true;
        toggleMessageType();
    }
}

function testWelcome() {
    alert('ฟีเจอร์ทดสอบจะส่งข้อความไปยัง LINE ของคุณ\n\nกรุณาบันทึกการตั้งค่าก่อน แล้วเพิ่มบอทเป็นเพื่อนใหม่เพื่อทดสอบ');
}

// Update preview on text change
document.getElementById('textContent')?.addEventListener('input', function(e) {
    document.querySelector('#textPreview p').innerHTML = e.target.value.replace(/\n/g, '<br>');
});

// Update flex preview on change
document.getElementById('flexContent')?.addEventListener('input', updateFlexPreview);

// Initialize
toggleMessageType();
</script>

<?php require_once 'includes/footer.php'; ?>
