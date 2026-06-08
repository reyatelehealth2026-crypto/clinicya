<?php
/**
 * Model: Conversation History
 * จัดการประวัติการสนทนาของลูกค้า
 */

namespace Modules\AIChat\Models;

use Modules\Core\Database;

class ConversationHistory
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * ดึงประวัติการสนทนาล่าสุด
     */
    public function getRecentHistory(int $userId, int $limit = 10): array
    {
        $sql = "
            SELECT 
                CASE WHEN direction = 'incoming' THEN 'user' ELSE 'assistant' END as role,
                content,
                message_type,
                created_at
            FROM messages 
            WHERE user_id = ? 
                AND message_type = 'text'
                AND content IS NOT NULL 
                AND content != ''
            ORDER BY created_at DESC 
            LIMIT ?
        ";
        
        $messages = $this->db->fetchAll($sql, [$userId, $limit]);
        
        // Reverse เพื่อให้เรียงจากเก่าไปใหม่
        $messages = array_reverse($messages);
        
        // กรองข้อความที่ไม่ต้องการ
        $history = [];
        foreach ($messages as $msg) {
            $content = $msg['content'];
            
            // Skip ข้อความสั้นเกินไป
            if (mb_strlen($content) < 2) continue;
            
            // Skip ข้อความที่เป็น command
            if (preg_match('/^\[.*\]/', $content)) continue;
            
            $history[] = [
                'role' => $msg['role'],
                'content' => $content,
                'created_at' => $msg['created_at']
            ];
        }
        
        return $history;
    }
    
    /**
     * ดึงข้อมูลลูกค้า (ข้อมูลสุขภาพ)
     */
    public function getCustomerInfo(int $userId): ?array
    {
        $info = $this->db->fetchOne(
            "SELECT display_name, phone, medical_conditions, drug_allergies, current_medications
             FROM users WHERE id = ?",
            [$userId]
        );
        if (!$info) {
            return null;
        }

        // ประวัติการรับยา/ซื้อ (จาก dispensing_records.items JSON) — ให้ AI รู้ว่าเคยรับยาอะไรไปบ้าง
        $info['purchase_history'] = '';
        try {
            $rows = $this->db->fetchAll(
                "SELECT items FROM dispensing_records WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
                [$userId]
            );
            $names = [];
            foreach ($rows as $r) {
                $items = json_decode((string) ($r['items'] ?? ''), true);
                if (is_array($items)) {
                    foreach ($items as $it) {
                        if (!is_array($it)) continue;
                        $nm = $it['name'] ?? $it['product_name'] ?? $it['drug_name'] ?? null;
                        if ($nm) {
                            $names[] = trim((string) $nm);
                        }
                    }
                }
            }
            $names = array_values(array_unique(array_filter($names)));
            $info['purchase_history'] = implode(', ', array_slice($names, 0, 8));
        } catch (\Throwable $e) {
            // dispensing_records may be absent in some tenants — ignore
        }

        // สิ่งที่ AI เคยตรวจจับจากบทสนทนา (แพ้ยา/โรคประจำตัว) — ให้ "จำ" ลูกค้าข้ามครั้ง
        $info['ai_notes'] = '';
        try {
            $rows = $this->db->fetchAll(
                "SELECT note FROM user_notes WHERE user_id = ? AND note LIKE '[AI]%' ORDER BY created_at DESC LIMIT 6",
                [$userId]
            );
            $info['ai_notes'] = implode('; ', array_map(static fn ($r) => (string) $r['note'], $rows));
        } catch (\Throwable $e) {
            // user_notes may be absent — ignore
        }

        return $info;
    }

    /**
     * ตรวจจับข้อมูลสุขภาพสำคัญจากข้อความลูกค้า (แพ้ยา / โรคประจำตัว) แล้วบันทึกเป็น note
     * แบบ advisory ใน user_notes ([AI] ...) เพื่อให้ AI และเภสัชกรจำลูกค้าได้ข้ามครั้ง.
     * เก็บเป็น note เท่านั้น — ไม่เขียนทับฟิลด์ทางการ (drug_allergies ฯลฯ) เพื่อความปลอดภัย.
     */
    public function recordHealthMentions(int $userId, string $message): void
    {
        if ($userId <= 0) {
            return;
        }
        $msg = trim($message);
        if ($msg === '' || mb_strlen($msg) > 600) {
            return;
        }

        $found = [];
        // แพ้ยา / แพ้ <x> — ข้ามกรณี "ไม่แพ้"
        if (mb_strpos($msg, 'ไม่แพ้') === false
            && preg_match('/แพ้\s*(?:ยา)?\s*([ก-๙A-Za-z0-9\.\+\-\/]{2,40})/u', $msg, $m)) {
            $found[] = 'แพ้: ' . trim($m[1]);
        }
        // โรคประจำตัวที่พบบ่อย
        $conditions = ['เบาหวาน', 'ความดัน', 'โรคหัวใจ', 'โรคไต', 'หอบหืด', 'ไทรอยด์', 'เกาต์', 'ไขมันในเลือด', 'ลมชัก', 'โรคตับ', 'ไมเกรน'];
        foreach ($conditions as $c) {
            if (mb_strpos($msg, $c) !== false) {
                $found[] = 'โรคประจำตัว: ' . $c;
            }
        }
        if (empty($found)) {
            return;
        }

        foreach (array_unique($found) as $note) {
            $tagged = '[AI] ' . $note;
            try {
                $dup = $this->db->fetchOne(
                    "SELECT id FROM user_notes WHERE user_id = ? AND note = ? LIMIT 1",
                    [$userId, $tagged]
                );
                if ($dup) {
                    continue;
                }
                $this->db->insert('user_notes', ['user_id' => $userId, 'note' => $tagged]);
            } catch (\Throwable $e) {
                // user_notes missing / insert failed — non-fatal
            }
        }
    }
}
