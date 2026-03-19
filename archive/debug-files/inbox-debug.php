<?php
/**
 * Debug Inbox API
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/InboxService.php';
    
    $db = Database::getInstance()->getConnection();
    
    // Check line_account_ids in users
    $stmt = $db->query("SELECT DISTINCT line_account_id, COUNT(*) as cnt FROM users GROUP BY line_account_id");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check messages
    $stmt = $db->query("SELECT DISTINCT line_account_id, COUNT(*) as cnt FROM messages GROUP BY line_account_id");
    $msgAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Test with first account that has data
    $testAccountId = $accounts[0]['line_account_id'] ?? 1;
    $inboxService = new InboxService($db, (int)$testAccountId);
    $result = $inboxService->getConversations([], 1, 5);
    
    echo json_encode([
        'success' => true,
        'user_accounts' => $accounts,
        'message_accounts' => $msgAccounts,
        'test_account_id' => $testAccountId,
        'data' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
