<?php
/**
 * ConsentGuard - ตรวจความยินยอม PDPA สำหรับข้อมูลสุขภาพก่อนเก็บประวัติอาการ
 *
 * PDPA มาตรา 26: ประวัติอาการ/สุขภาพเป็นข้อมูลอ่อนไหว ต้องมี consent ก่อนเก็บ.
 * การซัก triage เก็บประวัติอาการลง triage_sessions จึงต้องเช็ค consent ชนิด
 * 'health_data' (มีอยู่แล้วใน user_consents ผ่าน api/consent.php).
 *
 * ใช้แบบ advisory: คืนสถานะให้ผู้เรียกไปบันทึก audit + ส่ง hint ให้ UI เตือน
 * ขอ consent — ไม่บังคับตัดการสนทนา (การบังคับ + UI dialog เป็นงาน follow-up).
 */

namespace Modules\AIChat\Services;

class ConsentGuard
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ผู้ใช้ (users.id) ให้ consent 'health_data' ที่ยัง active อยู่หรือไม่.
     * fail-open: ถ้าตาราง/คอลัมน์ไม่พร้อม คืน true เพื่อไม่รบกวนผู้ใช้.
     */
    public function hasHealthDataConsent(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT is_accepted FROM user_consents
                 WHERE user_id = :uid AND consent_type = 'health_data'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return false; // ไม่เคยบันทึก consent สำหรับข้อมูลสุขภาพ
            }
            return !empty($row['is_accepted']);
        } catch (\Throwable $e) {
            error_log('[ConsentGuard] check failed: ' . $e->getMessage());
            return true; // fail-open — ไม่ block เมื่อ DB ไม่พร้อม
        }
    }
}
