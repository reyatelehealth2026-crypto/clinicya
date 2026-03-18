<?php
/**
 * Rich Menu Debug API
 * ใช้ตรวจสอบและแก้ไขปัญหา Rich Menu
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'status';
$botId = $_GET['bot_id'] ?? 1;

// Get LineAPI
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($botId);

if (!$line) {
    echo json_encode(['error' => 'ไม่พบ LINE Account ID: ' . $botId]);
    exit;
}

switch ($action) {
    case 'status':
        // Get all rich menus from LINE
        $menus = $line->getRichMenuList();
        
        // Get default rich menu
        $default = $line->getDefaultRichMenu();
        
        // Get from DB
        $stmt = $db->prepare("SELECT * FROM rich_menus WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY is_default DESC");
        $stmt->execute([$botId]);
        $dbMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'line_menus' => $menus,
            'default_menu' => $default,
            'db_menus' => $dbMenus
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    case 'set_default':
        $richMenuId = $_GET['rich_menu_id'] ?? '';
        if (empty($richMenuId)) {
            echo json_encode(['error' => 'กรุณาระบุ rich_menu_id']);
            exit;
        }
        
        $result = $line->setDefaultRichMenu($richMenuId);
        echo json_encode([
            'action' => 'set_default',
            'rich_menu_id' => $richMenuId,
            'result' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    case 'cancel_default':
        $result = $line->cancelDefaultRichMenu();
        echo json_encode([
            'action' => 'cancel_default',
            'result' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        echo json_encode([
            'error' => 'Unknown action',
            'available_actions' => [
                'status' => 'ดูสถานะ Rich Menu ทั้งหมด',
                'set_default' => 'ตั้ง Default Rich Menu (ต้องระบุ rich_menu_id)',
                'cancel_default' => 'ยกเลิก Default Rich Menu'
            ]
        ]);
}
