<?php
/**
 * AI Chat - Medical Copilot AI (Consolidated)
 * รวม: AI Chat + AI Chatbot + AI Settings + AI Studio
 * 
 * Tabs:
 * - chat: แชทกับ AI ทั่วไป
 * - chatbot: ตั้งค่า AI Chatbot (OpenAI)
 * - settings: ตั้งค่า AI ตอบแชทอัตโนมัติ (Gemini)
 * - studio: AI Studio (สร้างรูป, Flex, แคปชั่น, แปลภาษา)
 * 
 * @package FileConsolidation
 * @version 2.0.0
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/components/tabs.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toast.php';

if (!isset($_SESSION['admin_user']['id'])) {
    header('Location: /auth/login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'Medical Copilot AI';

// Define tabs
$tabs = [
    'chat' => ['label' => 'AI Chat', 'icon' => 'fas fa-comments'],
    'chatbot' => ['label' => 'Chatbot', 'icon' => 'fas fa-robot'],
    'settings' => ['label' => 'ตั้งค่า', 'icon' => 'fas fa-cog'],
    'studio' => ['label' => 'AI Studio', 'icon' => 'fas fa-magic'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'chat');

require_once 'includes/header.php';
echo getPageHeaderStyles();
echo getToastStyles();
echo getTabsStyles();
?>

<?= renderToastContainer() ?>
<?= renderPageHeader('Medical Copilot AI', 'AI ช่วยเหลือสำหรับร้านยาและธุรกิจ', null, [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'Medical Copilot AI', 'href' => null]]) ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
            case 'chatbot':
                include 'includes/ai-chat/chatbot.php';
                break;

            case 'settings':
                include 'includes/ai-chat/settings.php';
                break;

            case 'studio':
                include 'includes/ai-chat/studio.php';
                break;

            case 'chat':
            default:
                include 'includes/ai-chat/chat.php';
                break;
        }
        ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
