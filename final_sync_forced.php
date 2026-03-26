<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

$bdoId = 46926; // BDO ID จริง
$lineUserId = 'U4c7ffa843bfda6f40f774193aacc0757';

echo "Fetching fresh data for BDO ID: $bdoId..." . PHP_EOL;
$freshData = $api->getBdoDetail($lineUserId, $bdoId);

if ($freshData) {
    // เพิ่มค่า line_account_id = 3 เข้าไปใน data ก่อนเปิด Context
    $freshData['line_account_id'] = 3;
    
    // ใช้สคริปต์นี้เพื่ออัปเดตโดยตรง
    try {
        // อัปเดตตารางตรงๆ เพื่อเลี่ยง Error จากคลาสที่จำกัด
        $stmt = $db->prepare("
            UPDATE odoo_bdo_context 
            SET financial_summary_json = ?, line_account_id = 3
            WHERE bdo_id = ?
        ");
        $stmt->execute([
            json_encode($freshData['financial_summary'] ?? []),
            $bdoId
        ]);
        echo "Success: Context updated with line_account_id = 3";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo "Failed: Could not fetch fresh BDO data from Odoo";
}
