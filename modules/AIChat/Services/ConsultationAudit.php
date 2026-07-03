<?php
/**
 * ConsultationAudit - บันทึกการตรวจสอบย้อนกลับ (audit trail) ของการปรึกษาเภสัชกร AI
 *
 * Telepharmacy compliance (issue #15): อย. ต้องการให้กระบวนการ tele-consult
 * "ตรวจสอบได้ตลอดกระบวนการ". ทุก turn / red flag / escalation / consent /
 * การรีวิวของเภสัชกร ถูกบันทึกลงตาราง consultation_audit แบบ APPEND-ONLY และ
 * ผูกกันด้วย SHA-256 hash chain ต่อ session — ถ้ามีการแก้ไข/ลบแถวย้อนหลัง
 * chain จะพัง ตรวจจับได้ทันที (tamper-evident).
 *
 * หลักการ:
 *  - เขียนอย่างเดียว (INSERT) ห้าม UPDATE/DELETE เด็ดขาด.
 *  - การ log ต้องไม่ทำให้ flow การปรึกษาพัง — ทุก error ถูกกลืนและ log ไว้ที่
 *    error_log แทนที่จะ throw.
 */

namespace Modules\AIChat\Services;

class ConsultationAudit
{
    private \PDO $pdo;
    private ?int $lineAccountId;

    /** @var bool ป้องกัน ensureTable() ทำงานซ้ำในหนึ่ง process */
    private static bool $tableEnsured = false;

    public function __construct(\PDO $pdo, ?int $lineAccountId = null)
    {
        $this->pdo = $pdo;
        $this->lineAccountId = $lineAccountId;
    }

    /**
     * บันทึกหนึ่งเหตุการณ์ลง audit trail (append-only, hash-chained).
     * ไม่ throw — ถ้าล้มเหลวจะ log ไว้เฉย ๆ เพื่อไม่ให้กระทบการสนทนา.
     *
     * @param array<string,mixed> $payload
     */
    public function log(
        string $eventType,
        string $actorType,
        ?int $sessionId,
        ?int $userId,
        array $payload = [],
        ?int $actorId = null
    ): void {
        try {
            $this->ensureTable();

            $prevHash  = $this->getPrevHash($sessionId);
            $canonical = self::canonicalize($payload);
            // microsecond timestamp — เก็บลง created_at ตรง ๆ เพื่อให้ verifyChain
            // คำนวณ hash ซ้ำได้ตรงกับตอนบันทึก
            $ts   = date('Y-m-d H:i:s.u');
            $hash = self::computeHash($prevHash, $eventType, $actorType, $actorId, $canonical, $ts);

            $stmt = $this->pdo->prepare(
                "INSERT INTO consultation_audit
                    (line_account_id, session_id, user_id, actor_type, actor_id,
                     event_type, payload, content_hash, prev_hash, created_at)
                 VALUES (:acc, :sid, :uid, :atype, :aid, :etype, :payload, :hash, :prev, :ts)"
            );
            $stmt->execute([
                ':acc'     => $this->lineAccountId,
                ':sid'     => $sessionId,
                ':uid'     => $userId,
                ':atype'   => $actorType,
                ':aid'     => $actorId,
                ':etype'   => $eventType,
                ':payload' => $canonical,
                ':hash'    => $hash,
                ':prev'    => $prevHash,
                ':ts'      => $ts,
            ]);
        } catch (\Throwable $e) {
            error_log('[ConsultationAudit] log failed: ' . $e->getMessage());
        }
    }

    /**
     * content_hash ล่าสุดของ session (หรือของ account เมื่อยังไม่มี session)
     * เพื่อผูกเป็น hash chain.
     */
    private function getPrevHash(?int $sessionId): ?string
    {
        try {
            if ($sessionId !== null) {
                $stmt = $this->pdo->prepare(
                    "SELECT content_hash FROM consultation_audit
                     WHERE session_id = :sid ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([':sid' => $sessionId]);
            } else {
                // ก่อนเริ่ม session — ผูกกับ event ล่าสุดของ account เดียวกัน
                $stmt = $this->pdo->prepare(
                    "SELECT content_hash FROM consultation_audit
                     WHERE session_id IS NULL AND (line_account_id <=> :acc)
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([':acc' => $this->lineAccountId]);
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (string) $row['content_hash'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ตรวจสอบ hash chain ของ session — คืน true ถ้าทุกแถวยังไม่ถูกแก้ไข.
     * ใช้ในหน้า admin / test เพื่อพิสูจน์ความสมบูรณ์ต่อ อย.
     */
    public function verifyChain(int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT event_type, actor_type, actor_id, payload, content_hash, prev_hash, created_at
             FROM consultation_audit WHERE session_id = :sid ORDER BY id ASC"
        );
        $stmt->execute([':sid' => $sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expectedPrev = null;
        foreach ($rows as $row) {
            if (($row['prev_hash'] ?? null) !== $expectedPrev) {
                return false; // chain link ขาด
            }
            // canonicalize ใหม่จาก payload ที่ decode แล้ว — กัน MySQL จัดรูป JSON ใหม่
            $payload   = json_decode((string) ($row['payload'] ?? '{}'), true);
            $canonical = self::canonicalize(is_array($payload) ? $payload : []);
            $recomputed = self::computeHash(
                $row['prev_hash'] !== null ? (string) $row['prev_hash'] : null,
                (string) $row['event_type'],
                (string) $row['actor_type'],
                $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                $canonical,
                (string) $row['created_at']
            );
            if (!hash_equals((string) $row['content_hash'], $recomputed)) {
                return false; // เนื้อหาถูกแก้ไข
            }
            $expectedPrev = (string) $row['content_hash'];
        }
        return true;
    }

    /**
     * แปลง payload เป็น JSON แบบ canonical (เรียง key ทุกระดับ) เพื่อให้ hash
     * เสถียร ไม่ขึ้นกับลำดับ key หรือการจัดรูปของ MySQL.
     *
     * @param array<string,mixed> $payload
     */
    public static function canonicalize(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (is_array($value)) {
                // เรียงเฉพาะ associative array (ไม่ยุ่งกับ list เพื่อรักษาลำดับ)
                $isList = array_keys($value) === range(0, count($value) - 1);
                if (!$isList) {
                    ksort($value);
                }
                foreach ($value as &$v) {
                    $sort($v);
                }
                unset($v);
            }
        };
        $sort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    /**
     * คำนวณ content_hash — pure function เพื่อให้ทดสอบได้และ verify ซ้ำได้.
     */
    public static function computeHash(
        ?string $prevHash,
        string $eventType,
        string $actorType,
        ?int $actorId,
        string $canonicalPayload,
        string $ts
    ): string {
        $material = implode('|', [
            $prevHash ?? '',
            $eventType,
            $actorType,
            $actorId === null ? '' : (string) $actorId,
            $canonicalPayload,
            $ts,
        ]);
        return hash('sha256', $material);
    }

    /**
     * สร้างตารางถ้ายังไม่มี (resilience fallback ตาม pattern เดิมของ repo).
     * แหล่งจริงคือ database/migration_2026-07-04_consultation_audit_pdpa.sql
     */
    private function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }
        self::$tableEnsured = true;
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS consultation_audit (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                line_account_id INT NULL,
                session_id      BIGINT UNSIGNED NULL,
                user_id         BIGINT NULL,
                actor_type      ENUM('customer','ai','pharmacist','system') NOT NULL,
                actor_id        INT NULL,
                event_type      VARCHAR(40) NOT NULL,
                payload         JSON NULL,
                content_hash    CHAR(64) NOT NULL,
                prev_hash       CHAR(64) NULL,
                created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY idx_session (session_id, id),
                KEY idx_account_created (line_account_id, created_at),
                KEY idx_user (user_id),
                KEY idx_event (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
