<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->query("
        (SELECT id, event_type, order_id, line_user_id,
                JSON_KEYS(payload) AS pk,
                SUBSTRING(payload, 1, 800) AS pp
         FROM odoo_webhooks_log
         WHERE event_type LIKE 'invoice.%'
         ORDER BY id DESC LIMIT 3)
        UNION ALL
        (SELECT id, event_type, order_id, line_user_id,
                JSON_KEYS(payload) AS pk,
                SUBSTRING(payload, 1, 800) AS pp
         FROM odoo_webhooks_log
         WHERE event_type LIKE 'sale.order.%'
         ORDER BY id DESC LIMIT 3)
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
