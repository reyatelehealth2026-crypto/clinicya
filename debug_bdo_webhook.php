<?php
require_once "/www/wwwroot/cny.re-ya.com/config/config.php";
require_once "/www/wwwroot/cny.re-ya.com/config/database.php";
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT payload FROM odoo_webhooks_log WHERE event_type = \"bdo.confirmed\" ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $index => $row) {
        echo "--- Payload #" . ($index + 1) . " ---\n";
        $data = json_decode($row["payload"], true);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

