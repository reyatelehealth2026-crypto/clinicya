<?php
class OdooSyncService {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function syncWebhook($payload, $eventType, $webhookId = null) {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = array_merge($payload, $payload['data']);
        }
        if (!is_array($payload) || empty($eventType)) return false;
        
        // ตรงนี้คือหัวใจสำคัญ: ไม่ว่าจะ event ไหน ถ้าเป็น BDO ให้เรียก API ดึงค่าล่าสุดมาอัปเดตเสมอ
        if (str_starts_with($eventType, 'bdo.')) return $this->syncBdoFromApi($payload, $eventType, $webhookId);
        
        return false;
    }

    private function syncBdoFromApi($payload, $eventType, $webhookId) {
        $bdoId = (int) ($payload['bdo_id'] ?? 0);
        if (!$bdoId) return false;

        require_once __DIR__ . '/OdooAPIClient.php';
        require_once __DIR__ . '/BdoContextManager.php';
        $api = new OdooAPIClient($this->db);
        $bdoContextManager = new BdoContextManager($this->db);

        // ดึงจาก API เท่านั้น
        $freshData = $api->getBdoDetail(null, $bdoId);
        
        if ($freshData && isset($freshData['data'])) {
            $data = $freshData['data'];
            $bdoInfo = $data['bdo'];
            $summary = $data['summary'] ?? [];

            // อัปเดตตารางหลัก odoo_bdos
            $stmt = $this->db->prepare("
                UPDATE odoo_bdos 
                SET amount_total = ?, state = ?, updated_at = NOW() 
                WHERE bdo_id = ?
            ");
            $stmt->execute([
                $summary['net_to_pay'] ?? $bdoInfo['amount_net_to_pay'] ?? $bdoInfo['amount_total'],
                $bdoInfo['state'],
                $bdoId
            ]);

            // อัปเดต Context (พร้อมข้อมูลเต็ม)
            $data['line_account_id'] = 3;
            $bdoContextManager->openContext($data);
            return true;
        }
        return false;
    }
}
