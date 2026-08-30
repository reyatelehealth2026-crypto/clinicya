<?php
/**
 * LandingContentService
 * เก็บ/โหลดเนื้อหา section ของหน้า Landing (Hero / About / Features / Services / CTA)
 *
 * ใช้ตาราง landing_settings (key-value, scoped by line_account_id)
 * ค่าที่เป็น array จะ serialize เป็น JSON ลงใน setting_value
 */
class LandingContentService
{
    private PDO $db;
    private ?int $lineAccountId;

    /** ค่า default ที่ตรงกับ index.php เดิม — ใช้เป็น fallback เสมอ */
    public const DEFAULTS = [
        'hero_title'         => 'ปรึกษาเภสัชกรและสั่งยากับ {shop} ได้ในไม่กี่ขั้นตอน',
        'hero_subtitle'      => 'คุยกับทีมร้านยา ตรวจสอบสินค้า และติดตามออเดอร์ผ่าน LINE/LIFF ในประสบการณ์เดียวที่ออกแบบมาสำหรับคนไข้ไทย',
        'hero_trust_items'   => [
            ['icon' => 'fa-user-md',     'label' => 'เภสัชกรดูแล'],
            ['icon' => 'fa-truck-fast',  'label' => 'จัดส่งถึงบ้าน'],
            ['icon' => 'fa-receipt',     'label' => 'ติดตามออเดอร์ได้'],
        ],
        'hero_cta_primary_label'   => 'เริ่มใช้งานผ่าน LINE',
        'hero_cta_primary_icon'    => 'fab fa-line',
        'hero_cta_secondary_label' => 'ดูสินค้าแนะนำ',
        'hero_cta_secondary_icon'  => 'fas fa-shopping-bag',
        'hero_cta_secondary_link'  => '#featured-products',
        'hero_cta_tertiary_label'  => 'ดูบริการของเรา',
        'hero_cta_tertiary_icon'   => 'fas fa-briefcase-medical',
        'hero_cta_tertiary_link'   => '#services',

        'about_heading'    => 'แนะนำบริการของ {shop}',
        'about_paragraphs' => [
            '{shop} คือแพลตฟอร์มเครือข่ายร้านขายยาออนไลน์ ซึ่งเป็นทางเลือกในการดูแลสุขภาพแบบเข้าถึงง่ายและรวดเร็ว เพราะคุณสามารถปรึกษาเภสัชกรออนไลน์ได้ทันทีผ่านแชต โทร หรือวิดีโอคอล ไม่ว่าจะเป็นอาการเจ็บป่วยเล็กน้อย คำถามเกี่ยวกับการใช้ยา หรือข้อสงสัยด้านสุขภาพอื่นๆ ทีมเภสัชกรร้านยาของเราพร้อมให้คำแนะนำที่เหมาะสมเฉพาะบุคคล',
            'เราให้บริการครอบคลุมทั้งยาสามัญประจำบ้าน ยาที่จำหน่ายในร้านยาโดยเภสัชกร ยาตามใบสั่งแพทย์ และผลิตภัณฑ์เสริมอาหาร โดยทุกรายการผ่านการดูแลจากทีมเภสัชกร เพื่อประสิทธิภาพในการรักษาอาการเจ็บป่วยของแต่ละบุคคล สามารถสั่งยาออนไลน์ได้เลย พร้อมมีบริการส่ง Delivery ให้ถึงหน้าบ้านของคุณ',
            'นอกจากนี้ ยังมีบริการทางการแพทย์ออนไลน์อีกมากมาย ไม่ว่าจะเป็นการปรึกษาแพทย์ ปรึกษาจิตแพทย์ รวมถึงค้นหาร้านขายยาใกล้ฉัน สนใจใช้บริการรูปแบบใด อ่านรายละเอียดเพิ่มเติมจากทางด้านล่างนี้ได้เลย',
        ],
        'about_cta_label' => 'อ่านต่อ',
        'about_cta_link'  => '#services',
        'about_icon'      => 'fa-hand-holding-medical',

        'features_heading'    => 'คุณสมบัติเด่นของแพลตฟอร์ม',
        'features_subheading' => '{shop} เป็นแพลตฟอร์มร้านยาออนไลน์ที่มีความโดดเด่นด้านการให้บริการ ช่วยยกระดับคุณภาพชีวิตในหลากหลายประการ',
        'features_cards' => [
            ['icon' => 'fa-bolt',         'title' => 'สะดวกและรวดเร็ว',        'desc' => 'สั่งซื้อยาออนไลน์ได้ทุกที่ ไม่ต้องเสียเวลาเดินทางไปร้านขายยา'],
            ['icon' => 'fa-truck',        'title' => 'บริการจัดส่งทั่วประเทศ',   'desc' => 'ภายในพื้นที่กรุงเทพฯ และปริมณฑลรับยาได้ภายใน 1 ชั่วโมง'],
            ['icon' => 'fa-user-md',      'title' => 'เภสัชกรผู้ชำนาญการ',     'desc' => 'ให้คำปรึกษาและแนะนำการใช้ยาที่ถูกต้องและปลอดภัย'],
            ['icon' => 'fa-pills',        'title' => 'สินค้าหลากหลาย',        'desc' => 'มีให้เลือกมากมาย ทั้งผลิตภัณฑ์ยา อาหารเสริม ครบทุกความต้องการด้านสุขภาพ'],
            ['icon' => 'fa-shield-alt',   'title' => 'ระบบรักษาความปลอดภัย', 'desc' => 'มั่นใจได้ในความปลอดภัยของข้อมูลส่วนตัวและการสั่งซื้อยา'],
            ['icon' => 'fa-circle-check', 'title' => 'บริการครบวงจร',         'desc' => 'ทั้งการปรึกษา การสั่งซื้อ และการจัดส่ง ในแพลตฟอร์มเดียว'],
        ],

        'services_heading'    => 'บริการของเรา',
        'services_subheading' => 'ครบครันทุกบริการด้านสุขภาพ',
        'services_cards' => [
            ['icon' => 'fa-shopping-bag',  'title' => 'ร้านค้าออนไลน์',    'desc' => 'เลือกซื้อยาและผลิตภัณฑ์สุขภาพได้ง่ายๆ พร้อมจัดส่งถึงบ้าน', 'action' => 'เลือกสินค้า', 'liff_path' => '#/shop'],
            ['icon' => 'fa-user-md',       'title' => 'ปรึกษาเภสัชกร',     'desc' => 'พูดคุยกับเภสัชกรผู้เชี่ยวชาญ ได้คำแนะนำที่ถูกต้อง',          'action' => 'เริ่มปรึกษา',  'liff_path' => '#/ai-chat'],
            ['icon' => 'fa-calendar-check','title' => 'นัดหมายออนไลน์',    'desc' => 'จองคิวล่วงหน้า ไม่ต้องรอคิว สะดวกรวดเร็ว',               'action' => 'จองคิว',      'liff_path' => '#/appointments'],
        ],

        'cta_heading'      => 'พร้อมเริ่มต้นแล้วหรือยัง?',
        'cta_paragraph'    => 'ไม่ว่าคุณจะอยู่ในกรุงเทพฯ หรือต่างจังหวัด {shop} พร้อมเป็นร้านขายยาออนไลน์ที่อยู่เคียงข้างคุณ โดยสามารถเข้าถึงยาและคำแนะนำด้านสุขภาพที่มีคุณภาพได้อย่างทันท่วงที',
        'cta_button_label' => 'เปิดแอปเลย',
        'cta_button_icon'  => 'fab fa-line',
    ];

    /** key ที่ value เป็น array (เก็บเป็น JSON) */
    private const JSON_KEYS = [
        'hero_trust_items',
        'about_paragraphs',
        'features_cards',
        'services_cards',
    ];

    /** prefix ใน landing_settings เพื่อไม่ชนกับ key อื่น */
    private const PREFIX = 'content_';

    public function __construct(PDO $db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    /** ค่าทั้งหมด (merge ระหว่าง defaults + ค่าใน DB) */
    public function getAll(): array
    {
        $out = self::DEFAULTS;
        try {
            $sql = "SELECT setting_key, setting_value FROM landing_settings WHERE line_account_id "
                 . ($this->lineAccountId ? '= ?' : 'IS NULL');
            $stmt = $this->db->prepare($sql);
            $stmt->execute($this->lineAccountId ? [$this->lineAccountId] : []);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            return $out;
        }

        foreach ($rows as $key => $val) {
            if (strpos($key, self::PREFIX) !== 0) continue;
            $shortKey = substr($key, strlen(self::PREFIX));
            if (!array_key_exists($shortKey, self::DEFAULTS)) continue;

            if (in_array($shortKey, self::JSON_KEYS, true)) {
                $decoded = json_decode((string) $val, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $out[$shortKey] = $decoded;
                }
            } else {
                if ($val !== null && $val !== '') {
                    $out[$shortKey] = (string) $val;
                }
            }
        }
        return $out;
    }

    /** แทนที่ placeholder {shop} ในทุก string ของ content array */
    public function render(array $content, string $shopName): array
    {
        $sub = function ($v) use (&$sub, $shopName) {
            if (is_string($v)) return str_replace('{shop}', $shopName, $v);
            if (is_array($v))  return array_map($sub, $v);
            return $v;
        };
        return array_map($sub, $content);
    }

    /** บันทึกหลายค่า (mixed strings/arrays) */
    public function saveMany(array $values): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($values as $shortKey => $val) {
            if (!array_key_exists($shortKey, self::DEFAULTS)) continue;
            $storeVal = in_array($shortKey, self::JSON_KEYS, true)
                ? json_encode(is_array($val) ? $val : [], JSON_UNESCAPED_UNICODE)
                : (string) $val;
            $stmt->execute([$this->lineAccountId, self::PREFIX . $shortKey, $storeVal]);
        }
    }

    public function reset(): void
    {
        try {
            // Snapshot current content BEFORE delete (safety net for "ย้อนคืนล่าสุด")
            $this->snapshotCurrent();

            $sql = "DELETE FROM landing_settings WHERE setting_key LIKE ? AND line_account_id "
                 . ($this->lineAccountId ? '= ?' : 'IS NULL');
            $stmt = $this->db->prepare($sql);
            $params = [self::PREFIX . '%'];
            if ($this->lineAccountId) $params[] = $this->lineAccountId;
            $stmt->execute($params);
        } catch (Exception $e) {
            // ignore
        }
    }

    /** Ensure the backup table exists (lightweight safety table — auto-create OK). */
    private function ensureBackupTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS landing_settings_backup (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT NULL,
                snapshot_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_account_created (line_account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Snapshot current content_* rows as a single JSON row. */
    public function snapshotCurrent(): void
    {
        try {
            $this->ensureBackupTable();
            $current = $this->getAll();
            $json = json_encode($current, JSON_UNESCAPED_UNICODE);
            $stmt = $this->db->prepare(
                "INSERT INTO landing_settings_backup (line_account_id, snapshot_json) VALUES (?, ?)"
            );
            $stmt->execute([$this->lineAccountId, $json]);
        } catch (Exception $e) {
            // ignore — backup is best-effort
        }
    }

    /** Restore the latest snapshot (returns true on success). */
    public function restoreLatestBackup(): bool
    {
        try {
            $this->ensureBackupTable();
            $sql = "SELECT snapshot_json FROM landing_settings_backup
                    WHERE line_account_id " . ($this->lineAccountId ? '= ?' : 'IS NULL') . "
                    ORDER BY created_at DESC, id DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($this->lineAccountId ? [$this->lineAccountId] : []);
            $row = $stmt->fetchColumn();
            if (!$row) return false;
            $data = json_decode((string) $row, true);
            if (!is_array($data)) return false;
            // Only restore keys we know about
            $clean = [];
            foreach ($data as $k => $v) {
                if (array_key_exists($k, self::DEFAULTS)) $clean[$k] = $v;
            }
            if (empty($clean)) return false;
            $this->saveMany($clean);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
