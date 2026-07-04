<?php
/**
 * DataRightsService — บริการสิทธิของเจ้าของข้อมูล (PDPA data-subject rights)
 *
 * ให้ลูกค้าใช้สิทธิตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) ได้เอง
 * ผ่าน LINE Mini App โดยไม่ต้องอีเมลหาแอดมิน:
 *   1. ถอนความยินยอม (withdraw consent)
 *   2. ขอลบบัญชี/ข้อมูล (request deletion — SOFT flag เท่านั้น, ไม่ลบแถวจริง)
 *   3. ดาวน์โหลด/ส่งออกข้อมูลของตัวเอง (export data)
 *
 * หลักความปลอดภัย (สำคัญที่สุด):
 *   ทุก action ต้อง resolve users.id ฝั่ง server จาก (line_user_id, line_account_id)
 *   เท่านั้น — ห้ามเชื่อ user_id ที่ client ส่งมาเด็ดขาด เพื่อกัน IDOR / การอ่าน
 *   ข้อมูลข้ามผู้ใช้/ข้าม tenant.
 *
 * การออกแบบให้ทดสอบได้:
 *   - เมท็อดที่เป็น "ตรรกะบริสุทธิ์" (generateConfirmationCode, buildExportShape,
 *     normaliseUserProfile) เป็น static ไม่ต้องใช้ DB — ทดสอบได้โดยไม่ต้องต่อฐานข้อมูล.
 *   - เมท็อดที่แตะ DB (resolveUser, markForDeletion, ...) แยกออกชัดเจน และ
 *     markForDeletion() ใช้ UPDATE เท่านั้น — ไม่มี DELETE ต่อ users เลย.
 *
 * PHP 8.0 compatible.
 */

namespace Modules\PDPA\Services;

use PDO;

class DataRightsService
{
    private PDO $db;
    private ?int $lineAccountId;

    public function __construct(PDO $db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    // ── Pure logic (DB-free, testable) ──────────────────────────────

    /**
     * สร้างรหัสยืนยันคำขอลบข้อมูลแบบสุ่ม (8 ตัวอักษร A-Z + 0-9, ตัด glyph กำกวม).
     * รูปแบบผลลัพธ์: REYA-DEL-XXXXXXXX
     */
    public static function generateConfirmationCode(): string
    {
        // ตัด 0/O/1/I ออกเพื่อลดความสับสนเวลาลูกค้าอ่าน/พิมพ์
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, $len - 1)];
        }
        return 'REYA-DEL-' . $code;
    }

    /**
     * คัดเฉพาะฟิลด์โปรไฟล์ที่ปลอดภัยจะส่งกลับให้เจ้าของข้อมูล.
     * รับ associative array ของแถว users (จาก DB) แล้ว whitelist คอลัมน์.
     *
     * @param array<string,mixed> $userRow
     * @return array<string,mixed>
     */
    public static function normaliseUserProfile(array $userRow): array
    {
        $allow = [
            'id', 'line_account_id', 'line_user_id', 'display_name', 'real_name',
            'first_name', 'last_name', 'phone', 'email', 'birthday', 'gender',
            'address', 'district', 'province', 'postal_code', 'member_id',
            'is_registered', 'total_orders', 'total_spent', 'available_points',
            'medical_conditions', 'drug_allergies', 'current_medications',
            'blood_type', 'weight', 'height', 'created_at', 'registered_at',
            'consent_privacy', 'consent_terms', 'consent_health_data', 'consent_date',
            'deletion_status', 'deletion_requested_at',
        ];
        $out = [];
        foreach ($allow as $key) {
            if (array_key_exists($key, $userRow)) {
                $out[$key] = $userRow[$key];
            }
        }
        return $out;
    }

    /**
     * ประกอบ payload ของ export ให้เป็นรูปทรงมาตรฐาน. เป็น pure function —
     * รับข้อมูลที่ดึงมาแล้วทั้งหมด ไม่แตะ DB — เพื่อให้ทดสอบรูปทรง/การไม่ปนข้อมูล
     * คนอื่นได้ง่าย.
     *
     * @param array<string,mixed>              $userRow      แถว users ของเจ้าของข้อมูล
     * @param array<int,array<string,mixed>>   $consents     user_consents ของเจ้าของ
     * @param array<int,array<string,mixed>>   $consentLogs  consent_logs ของเจ้าของ
     * @param array<int,array<string,mixed>>   $chatHistory  ประวัติแชท AI ของเจ้าของ
     * @param array<int,array<string,mixed>>   $orders       ออเดอร์ของเจ้าของ
     * @return array<string,mixed>
     */
    public static function buildExportShape(
        array $userRow,
        array $consents,
        array $consentLogs,
        array $chatHistory,
        array $orders
    ): array {
        return [
            'export_meta' => [
                'generated_at' => date('c'),
                'standard'     => 'PDPA (Thailand) — ข้อมูลส่วนบุคคลของเจ้าของข้อมูลเท่านั้น',
                'user_id'      => isset($userRow['id']) ? (int) $userRow['id'] : null,
            ],
            'profile'         => self::normaliseUserProfile($userRow),
            'consents'        => array_values($consents),
            'consent_history' => array_values($consentLogs),
            'chat_history'    => array_values($chatHistory),
            'orders'          => array_values($orders),
        ];
    }

    // ── DB-backed methods ───────────────────────────────────────────

    /**
     * Resolve users.id + full row จาก (line_user_id, line_account_id).
     * นี่คือด่านความปลอดภัยหลัก — ทุก action เรียกผ่านนี้เท่านั้น.
     *
     * ถ้ารู้ line_account_id จะ scope ด้วย AND line_account_id = ? (กันข้าม tenant);
     * ถ้าไม่รู้ (null) จะ fallback เป็น line_user_id อย่างเดียว (เหมือน member.php).
     *
     * @return array<string,mixed>|null
     */
    public function resolveUser(?string $lineUserId): ?array
    {
        if ($lineUserId === null) {
            return null;
        }
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return null;
        }

        try {
            if ($this->lineAccountId !== null) {
                $stmt = $this->db->prepare(
                    'SELECT * FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1'
                );
                $stmt->execute([$lineUserId, $this->lineAccountId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }
            // Fallback: line_user_id only (tenant DB already scopes to one tenant).
            $stmt = $this->db->prepare('SELECT * FROM users WHERE line_user_id = ? LIMIT 1');
            $stmt->execute([$lineUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('[DataRightsService] resolveUser: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ถอนความยินยอม — mirror ตรรกะจาก api/consent.php::handleWithdrawConsent
     * (UPDATE user_consents + INSERT consent_logs). ใช้ user_id ที่ resolve แล้ว.
     */
    public function withdrawConsent(int $userId, string $consentType, ?string $ip, ?string $ua): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_consents
                SET is_accepted = 0, withdrawn_at = NOW(), updated_at = NOW()
              WHERE user_id = ? AND consent_type = ?'
        );
        $stmt->execute([$userId, $consentType]);

        $stmt = $this->db->prepare(
            "INSERT INTO consent_logs (line_account_id, user_id, consent_type, action, consent_version, ip_address, user_agent)
             VALUES (?, ?, ?, 'withdraw', '1.0', ?, ?)"
        );
        $stmt->execute([$this->lineAccountId ?? 1, $userId, $consentType, $ip, $ua]);
    }

    /**
     * SOFT delete: mark ผู้ใช้ว่าขอลบข้อมูล + บันทึกคำขอลง data_deletion_requests.
     *
     * **ไม่มี DELETE FROM users เด็ดขาด** — เป็นการ UPDATE flag เท่านั้น
     * เพราะข้อมูลบางส่วน (ภาษี, audit trail) ต้องเก็บตามกฎหมาย และการลบจริง
     * ให้เจ้าหน้าที่ดำเนินการภายใน 30 วันตามนโยบายใน data-deletion.php.
     *
     * คืน confirmation code ที่สร้างและเก็บไว้.
     */
    public function markForDeletion(int $userId, string $lineUserId, ?string $reason, ?string $ip, ?string $ua): string
    {
        $this->ensureDeletionSchema();

        $code = self::generateConfirmationCode();

        $this->db->beginTransaction();
        try {
            // SOFT flag บนแถว users — UPDATE เท่านั้น
            $stmt = $this->db->prepare(
                "UPDATE users
                    SET deletion_status = 'requested', deletion_requested_at = NOW()
                  WHERE id = ?"
            );
            $stmt->execute([$userId]);

            // Ledger row พร้อม confirmation code
            $stmt = $this->db->prepare(
                "INSERT INTO data_deletion_requests
                    (line_account_id, user_id, line_user_id, confirmation_code, status, reason, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, 'requested', ?, ?, ?)"
            );
            $stmt->execute([$this->lineAccountId, $userId, $lineUserId, $code, $reason, $ip, $ua]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $code;
    }

    /**
     * ดึงข้อมูลทั้งหมด "ของเจ้าของเท่านั้น" แล้วประกอบเป็นรูปทรง export.
     * ทุก query ผูกกับ $userId ที่ resolve แล้ว (ไม่รับ user_id จาก client).
     *
     * @param array<string,mixed> $userRow
     * @return array<string,mixed>
     */
    public function buildExportForUser(array $userRow): array
    {
        $userId = (int) ($userRow['id'] ?? 0);

        $consents    = $this->fetchOwnConsents($userId);
        $consentLogs = $this->fetchOwnConsentLogs($userId);
        $chatHistory = $this->fetchOwnChatHistory($userId);
        $orders      = $this->fetchOwnOrders($userId);

        return self::buildExportShape($userRow, $consents, $consentLogs, $chatHistory, $orders);
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchOwnConsents(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT consent_type, consent_version, is_accepted, accepted_at, withdrawn_at, updated_at
                   FROM user_consents WHERE user_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[DataRightsService] fetchOwnConsents: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchOwnConsentLogs(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT consent_type, action, consent_version, created_at
                   FROM consent_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 200'
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[DataRightsService] fetchOwnConsentLogs: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchOwnChatHistory(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT role, content, session_id, created_at
                   FROM ai_conversation_history WHERE user_id = ? ORDER BY created_at ASC LIMIT 500'
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[DataRightsService] fetchOwnChatHistory: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchOwnOrders(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT t.id, t.order_number, t.total_amount, t.status, t.created_at,
                        GROUP_CONCAT(ti.product_name SEPARATOR ', ') AS products
                   FROM transactions t
              LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
                  WHERE t.user_id = ?
               GROUP BY t.id
               ORDER BY t.created_at DESC
                  LIMIT 200"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[DataRightsService] fetchOwnOrders: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resilience fallback: สร้างคอลัมน์/ตารางที่ migration เพิ่ง add ถ้ายังไม่มี.
     * แหล่งจริงคือ database/migration_2026-07-04_pdpa_data_rights.sql.
     */
    private function ensureDeletionSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $this->db->query('SELECT deletion_status FROM users LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $this->db->exec(
                    "ALTER TABLE users
                        ADD COLUMN deletion_status ENUM('none','requested','processing','completed') NOT NULL DEFAULT 'none',
                        ADD COLUMN deletion_requested_at DATETIME NULL"
                );
            } catch (\Throwable $alter) {
                error_log('[DataRightsService] ensureDeletionSchema(users): ' . $alter->getMessage());
            }
        }

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS data_deletion_requests (
                    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    line_account_id   INT NULL,
                    user_id           INT NOT NULL,
                    line_user_id      VARCHAR(50) NOT NULL,
                    confirmation_code VARCHAR(20) NOT NULL,
                    status            ENUM('requested','processing','completed','cancelled') NOT NULL DEFAULT 'requested',
                    reason            TEXT NULL,
                    ip_address        VARCHAR(45) NULL,
                    user_agent        TEXT NULL,
                    requested_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    processed_at      DATETIME NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_confirmation_code (confirmation_code),
                    KEY idx_ddr_user (user_id),
                    KEY idx_ddr_account (line_account_id),
                    KEY idx_ddr_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('[DataRightsService] ensureDeletionSchema(table): ' . $e->getMessage());
        }
    }
}
