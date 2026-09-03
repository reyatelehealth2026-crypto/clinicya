<?php
/**
 * NotificationGate — ประตูเดียวก่อนส่งข้อความ push หาลูกค้า
 *
 * ก่อนมีคลาสนี้ cron แจ้งเตือน 8 ตัวเรียก LineAPI::pushMessage() ตรงเข้าหาลูกค้าเอง
 * แต่ละตัวตัดสินใจกันเอง มีเพียง 4 ตัวที่อ่าน user_notification_preferences —
 * ลูกค้าจึงถูกเตือนตอนตีสามได้ โดนซ้อนหลายข้อความในวันเดียวได้ และพิสูจน์ไม่ได้ว่า
 * "ลูกค้าถอนความยินยอมแล้ว" ถูกเคารพจริง ซึ่งเป็นความเสี่ยง PDPA เพราะเป็นข้อมูลสุขภาพ
 *
 * ## ทำไมไม่ใช้ NotificationRouter ที่มีอยู่แล้ว
 *
 * รีโปนี้มีระบบแจ้งเตือนอยู่แล้วหนึ่งชุด (NotificationRouter + PreferencesManager +
 * Queue + Batcher + Logger) แต่ทั้งชุด**ผูกกับตาราง `odoo_notification_*`**
 * และ pipeline ของ Odoo delivery — `route($deliveryId, ...)`,
 * `findLineUser($odooPartnerId)` — โดยตัวมันเอง**สร้างข้อความขึ้นมาเอง**
 * จาก event type
 *
 * สายเตือนลูกค้าต่างออกไปทุกจุด: คีย์ด้วย `users.id` ไม่ใช่ line_user_id,
 * อ่านสวิตช์จาก `user_notification_preferences` ซึ่งเป็นคนละตาราง, และ cron
 * แต่ละตัว**สร้าง Flex มาเรียบร้อยแล้ว** เหลือแค่ต้องการด่านตรวจก่อนส่ง
 *
 * จึงแยกเป็นคลาสนี้แทนการดัดของเดิม การรวมสองระบบให้เหลือชุดเดียว
 * (รวม `odoo_notification_log` เข้ากับ `notification_log`) เป็นงานตามหลัง
 * ที่ควรทำตอนถอด Odoo ออก ไม่ใช่ตอนนี้
 *
 * ## ขอบเขต — เลน push เท่านั้น
 *
 * Gate คุมเฉพาะข้อความที่ **ร้านเป็นคนเริ่ม** (cron → pushMessage ซึ่งเสียเงิน
 * และมีโควตา) การตอบกลับปุ่มที่ลูกค้ากดบน Flex ใช้ reply token ซึ่งฟรีและไม่จำกัด
 * (LineAPI::sendMessage() — "Try reply token first (FREE!)") **ต้องไม่ผ่าน Gate**
 * เพราะลูกค้าเป็นคนขอเอง การบล็อกจะเท่ากับปิดปากระบบตอนลูกค้ากำลังถามอยู่
 *
 * ## นโยบายเป็นราย event ไม่ใช่กฎเดียวรวด
 *
 * quiet hours กับเพดานต่อวันใช้ไม่ได้กับทุกอย่าง — ลูกค้าที่ตั้งเตือนทานยา
 * "ก่อนนอน 21:00" ต้องได้รับข้อความนั้น แม้ 21:00 จะอยู่ในช่วงห้ามรบกวนก็ตาม
 * เพราะเขาเป็นคนเลือกเวลานั้นเอง เช่นเดียวกับการเตือนนัดหมายก่อนถึงเวลา
 * ดู self::POLICY สำหรับนโยบายของแต่ละ event
 *
 * @see database/migration_2026-09-02_notification_gate.sql
 */
class NotificationGate
{
    /** เพดานข้อความ push ต่อลูกค้าต่อวัน (event ที่ยกเว้นไม่ถูกนับ) */
    const DAILY_PUSH_CAP = 2;

    /** ช่วงห้ามรบกวนเริ่มต้น ใช้เมื่อลูกค้าไม่ได้ตั้งเอง */
    const DEFAULT_QUIET_START = '21:00';
    const DEFAULT_QUIET_END = '08:00';

    /** ระยะเวลากันการส่งซ้ำสำหรับ dedupe_key เดียวกัน (ชั่วโมง) */
    const DEDUPE_HOURS = 24;

    /**
     * นโยบายรายเหตุการณ์
     *
     * - `pref`      คอลัมน์ใน user_notification_preferences ที่ลูกค้าใช้ปิดสวิตช์
     * - `quiet`     true = เคารพช่วงห้ามรบกวน / false = ส่งได้ตลอดเวลา
     * - `cap`       true = นับรวมเพดานต่อวัน / false = ไม่นับ
     *
     * event ที่ยกเว้นทั้งสองอย่างคือ event ที่ **ลูกค้าเป็นคนกำหนดเวลาเอง**
     * หรือผูกกับเวลาจริงที่เลื่อนไม่ได้ — บล็อกแล้วฟีเจอร์พังทันที
     */
    const POLICY = [
        // ลูกค้าตั้งเวลาเอง เช่น "ก่อนนอน 21:00" — บล็อกไม่ได้
        'medication_dose' => ['pref' => 'drug_reminders', 'quiet' => false, 'cap' => false],
        // ผูกกับเวลานัดจริง นัด 08:00 ต้องเตือน 07:50 ซึ่งอยู่ในช่วงห้ามรบกวน
        'appointment' => ['pref' => 'appointment_reminders', 'quiet' => false, 'cap' => false],
        // ข้อความธุรกรรม ลูกค้าคาดหวังว่าต้องได้รับ
        'order_update' => ['pref' => 'order_updates', 'quiet' => false, 'cap' => false],

        // ต่อไปนี้ร้านเป็นคนเริ่ม เลื่อนได้ จึงผ่านทุกด่าน
        'medication_refill' => ['pref' => 'drug_reminders', 'quiet' => true, 'cap' => true],
        'reorder' => ['pref' => 'drug_reminders', 'quiet' => true, 'cap' => true],
        'reward_expiry' => ['pref' => 'promotions', 'quiet' => true, 'cap' => true],
        'restock' => ['pref' => 'restock_alerts', 'quiet' => true, 'cap' => true],
        'wishlist' => ['pref' => 'price_alerts', 'quiet' => true, 'cap' => true],
        'promotion' => ['pref' => 'promotions', 'quiet' => true, 'cap' => true],
        'health_tip' => ['pref' => 'health_tips', 'quiet' => true, 'cap' => true],
    ];

    /** เหตุผลที่บันทึกลง notification_log.reason */
    const REASON_OK = 'ok';
    const REASON_PREF_OFF = 'pref_off';
    const REASON_QUIET_HOURS = 'quiet_hours';
    const REASON_DUPLICATE = 'duplicate';
    const REASON_DAILY_CAP = 'daily_cap';
    const REASON_NO_LINE_USER = 'no_line_user';
    const REASON_SEND_FAILED = 'send_failed';

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // นโยบาย — ฟังก์ชันบริสุทธิ์ ทดสอบได้โดยไม่ต้องมี DB
    // ------------------------------------------------------------------

    /**
     * นโยบายของ event หนึ่ง — event ที่ไม่รู้จักถือเป็นข้อความที่ร้านเริ่มเอง
     * (เข้มที่สุด) เพื่อไม่ให้การพิมพ์ชื่อผิดกลายเป็นช่องหลบ Gate
     */
    public static function policyFor($eventType)
    {
        return self::POLICY[$eventType]
            ?? ['pref' => null, 'quiet' => true, 'cap' => true];
    }

    /**
     * เวลาปัจจุบันอยู่ในช่วงห้ามรบกวนหรือไม่
     *
     * รองรับช่วงที่ข้ามเที่ยงคืน (21:00–08:00) ซึ่งเป็นค่าเริ่มต้น
     * ช่วงที่เริ่มเท่ากับจบถือว่า "ไม่มีช่วงห้ามรบกวน" ไม่ใช่ "ห้ามทั้งวัน"
     *
     * @param string $now   HH:MM หรือ HH:MM:SS
     * @param string $start HH:MM หรือ HH:MM:SS (คอลัมน์ TIME คืนค่าแบบหลัง)
     * @param string $end   HH:MM หรือ HH:MM:SS
     */
    public static function isQuietHours($now, $start, $end)
    {
        $n = self::toMinutes($now);
        $s = self::toMinutes($start);
        $e = self::toMinutes($end);

        if ($n === null || $s === null || $e === null) {
            return false; // ค่าเสียหาย — ไม่บล็อกลูกค้าเพราะข้อมูลตั้งค่าพัง
        }
        if ($s === $e) {
            return false;
        }
        if ($s < $e) {
            return $n >= $s && $n < $e;      // ช่วงปกติภายในวันเดียว
        }
        return $n >= $s || $n < $e;          // ช่วงข้ามเที่ยงคืน
    }

    /** แปลง "HH:MM" หรือ "HH:MM:SS" เป็นจำนวนนาทีจากเที่ยงคืน */
    private static function toMinutes($hhmm)
    {
        if (!is_string($hhmm) || !preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    // ------------------------------------------------------------------
    // ทางเข้าหลัก
    // ------------------------------------------------------------------

    /**
     * ส่งข้อความ push หาลูกค้าหนึ่งคน หลังผ่านด่านทั้งหมด
     *
     * @param array $ctx  ต้องมี: user_id, line_user_id, channel_access_token,
     *                    event_type, messages (array ของ LINE message objects)
     *                    ไม่บังคับ: line_account_id, dedupe_key
     * @return array{sent:bool, reason:string}
     */
    public function send(array $ctx)
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        $lineUserId = trim((string) ($ctx['line_user_id'] ?? ''));
        $eventType = (string) ($ctx['event_type'] ?? '');
        $accountId = isset($ctx['line_account_id']) ? (int) $ctx['line_account_id'] : null;
        $dedupeKey = isset($ctx['dedupe_key']) ? (string) $ctx['dedupe_key'] : null;

        if ($lineUserId === '' || empty($ctx['channel_access_token'])) {
            return $this->deny($accountId, $userId, $lineUserId, $eventType, $dedupeKey, self::REASON_NO_LINE_USER);
        }

        $verdict = $this->evaluate($userId, $eventType, $dedupeKey);
        if ($verdict !== self::REASON_OK) {
            return $this->deny($accountId, $userId, $lineUserId, $eventType, $dedupeKey, $verdict);
        }

        try {
            require_once __DIR__ . '/LineAPI.php';
            $line = new LineAPI($ctx['channel_access_token']);
            $ok = $line->pushMessage($lineUserId, $ctx['messages']);
        } catch (Exception $e) {
            $this->log($accountId, $userId, $lineUserId, $eventType, $dedupeKey, 'failed', self::REASON_SEND_FAILED, $e->getMessage());
            return ['sent' => false, 'reason' => self::REASON_SEND_FAILED];
        }

        if (!$ok) {
            $this->log($accountId, $userId, $lineUserId, $eventType, $dedupeKey, 'failed', self::REASON_SEND_FAILED, null);
            return ['sent' => false, 'reason' => self::REASON_SEND_FAILED];
        }

        $this->log($accountId, $userId, $lineUserId, $eventType, $dedupeKey, 'sent', self::REASON_OK, null);
        return ['sent' => true, 'reason' => self::REASON_OK];
    }

    /**
     * ตัดสินว่าควรส่งหรือไม่ โดยไม่ส่งจริง — ใช้ตอนอยากเช็คก่อนสร้าง Flex
     * ที่มีต้นทุน (เช่นต้อง query สินค้าเพิ่ม)
     *
     * @return string เหตุผล — self::REASON_OK แปลว่าส่งได้
     */
    public function evaluate($userId, $eventType, $dedupeKey = null)
    {
        $policy = self::policyFor($eventType);
        $prefs = $this->loadPreferences($userId);

        // 1) ลูกค้าปิดสวิตช์ประเภทนี้ไว้
        if ($policy['pref'] !== null && array_key_exists($policy['pref'], $prefs)) {
            if ((int) $prefs[$policy['pref']] === 0) {
                return self::REASON_PREF_OFF;
            }
        }

        // 2) ช่วงห้ามรบกวน
        if ($policy['quiet']) {
            $start = $prefs['quiet_hours_start'] ?? self::DEFAULT_QUIET_START;
            $end = $prefs['quiet_hours_end'] ?? self::DEFAULT_QUIET_END;
            if (self::isQuietHours(date('H:i'), $start, $end)) {
                return self::REASON_QUIET_HOURS;
            }
        }

        // 3) เคยส่งเรื่องเดียวกันไปแล้วในรอบ 24 ชม.
        if ($dedupeKey !== null && $dedupeKey !== '' && $this->alreadySent($userId, $eventType, $dedupeKey)) {
            return self::REASON_DUPLICATE;
        }

        // 4) เพดานต่อวัน
        if ($policy['cap'] && $this->countedToday($userId) >= self::DAILY_PUSH_CAP) {
            return self::REASON_DAILY_CAP;
        }

        return self::REASON_OK;
    }

    // ------------------------------------------------------------------
    // การเข้าถึงข้อมูล — ทุกตัวออกแบบให้ fail-open
    // ------------------------------------------------------------------

    /**
     * ตั้งค่าแจ้งเตือนของลูกค้า
     *
     * fail-open โดยตั้งใจ: ถ้าอ่านตารางไม่ได้ ให้คืน array ว่างซึ่งแปลว่า
     * "ไม่มีสวิตช์ไหนถูกปิด" — เพราะการที่ตารางล่มไม่ควรทำให้ลูกค้าพลาด
     * ข้อความเตือนทานยา ส่วนกรณีลูกค้าตั้งใจปิดสวิตช์จะมีแถวอยู่จริงเสมอ
     */
    private function loadPreferences($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /** เคยส่ง dedupe_key นี้สำเร็จภายใน DEDUPE_HOURS หรือยัง */
    private function alreadySent($userId, $eventType, $dedupeKey)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 1 FROM notification_log
                WHERE user_id = ? AND event_type = ? AND dedupe_key = ?
                  AND decision = 'sent'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                LIMIT 1
            ");
            $stmt->execute([$userId, $eventType, $dedupeKey, self::DEDUPE_HOURS]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            return false; // ตารางยังไม่มี — ยอมให้ส่งดีกว่าเงียบ
        }
    }

    /** จำนวนข้อความที่นับรวมเพดาน ซึ่งส่งไปแล้ววันนี้ */
    private function countedToday($userId)
    {
        $capped = [];
        foreach (self::POLICY as $type => $p) {
            if ($p['cap']) {
                $capped[] = $type;
            }
        }
        if (empty($capped)) {
            return 0;
        }

        try {
            $in = implode(',', array_fill(0, count($capped), '?'));
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM notification_log
                WHERE user_id = ? AND decision = 'sent'
                  AND DATE(created_at) = CURDATE()
                  AND event_type IN ($in)
            ");
            $stmt->execute(array_merge([$userId], $capped));
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function deny($accountId, $userId, $lineUserId, $eventType, $dedupeKey, $reason)
    {
        $this->log($accountId, $userId, $lineUserId, $eventType, $dedupeKey, 'skipped', $reason, null);
        return ['sent' => false, 'reason' => $reason];
    }

    /**
     * บันทึกทุกการตัดสินใจ ทั้งที่ส่งและไม่ส่ง
     *
     * นี่คือสิ่งที่ตอบคำถาม PDPA ว่า "ทำไมลูกค้ารายนี้ถึงได้รับข้อความ"
     * และ "ตอนที่เขาปิดสวิตช์แล้ว ระบบเคารพจริงไหม" ได้
     */
    private function log($accountId, $userId, $lineUserId, $eventType, $dedupeKey, $decision, $reason, $detail)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notification_log
                    (line_account_id, user_id, line_user_id, event_type, dedupe_key, decision, reason, detail, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$accountId, $userId, $lineUserId ?: null, $eventType, $dedupeKey, $decision, $reason, $detail]);
        } catch (Exception $e) {
            // การบันทึกล้มเหลวต้องไม่ทำให้การแจ้งเตือนล้มเหลวตาม
            try {
                $s = $this->db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('error', ?, ?, ?, NOW())");
                $s->execute(['NotificationGate::log', $e->getMessage(), json_encode([
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'decision' => $decision,
                ], JSON_UNESCAPED_UNICODE)]);
            } catch (Exception $ignore) {
            }
        }
    }
}
