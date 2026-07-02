<?php
/**
 * LandingV2Config - จัดการ config หน้าเว็บร้าน (storefront) โฉมใหม่ v2
 *
 * เก็บใน landing_settings (key-value ต่อ line_account_id) 2 คีย์:
 *   - landing_v2_draft     : ร่างที่แอดมินแก้อยู่ (เห็นเฉพาะ preview)
 *   - landing_v2_published : ตัวจริงที่ผู้เยี่ยมชมเห็น (มีคีย์นี้ = เปิดใช้ v2)
 *
 * publish = copy draft -> published, unpublish = ลบ published (กลับหน้าเดิมทันที)
 */

class LandingV2Config
{
    public const DRAFT_KEY = 'landing_v2_draft';
    public const PUBLISHED_KEY = 'landing_v2_published';

    /** ธีมที่รองรับ (slug => ชื่อไทย) — เพิ่มธีมใหม่ = เพิ่มที่นี่ + token block ใน landing-v2.css */
    public const THEMES = [
        'mint'     => 'มินต์คลีน',
        'latte'    => 'ลาเต้',
        'forest'   => 'ฟอเรสต์',
        'galaxy'   => 'กาแลคซี่',
        'sunshine' => 'ซันชาย',
        'ocean'    => 'โอเซียน',
    ];

    /** hero ที่รองรับ (slug => ชื่อไทย) */
    public const HEROES = [
        'shop'    => 'เน้นหน้าร้าน',
        'product' => 'เน้นสินค้า',
    ];

    /** ช่องรูปหน้าร้าน (slot => ป้ายกำกับ) เก็บชื่อไฟล์ใน bucket shop_photos */
    public const PHOTO_SLOTS = [
        'main'    => 'รูปหน้าร้าน',
        'consult' => 'มุมให้คำปรึกษา',
        'shelf'   => 'ชั้นวางสินค้า',
    ];

    private PDO $db;

    /**
     * หน้าเว็บร้านเป็นของ "ร้าน" (หนึ่ง subdomain = หนึ่งหน้า) ไม่ใช่ราย LINE OA
     * จึงเก็บ config ที่ scope line_account_id = NULL เสมอ — admin กับหน้า public
     * อ่าน/เขียนแถวเดียวกันไม่ว่าจะเลือก OA ไหนอยู่ (param เก็บไว้เผื่ออนาคต)
     */
    public function __construct(PDO $db, ?int $lineAccountId = null)
    {
        $this->db = $db;
    }

    /** ค่าเริ่มต้นของ config */
    public static function defaults(): array
    {
        return [
            'version'  => 2,
            'theme'    => 'mint',
            'hero'     => 'shop',
            'headline' => '',
            'tagline'  => '',
            'photos'   => ['main' => '', 'consult' => '', 'shelf' => ''],
            'show'     => [
                'services'    => true,
                'products'    => true,
                'faq'         => true,
                'articles'    => true,
                'custom_html' => true,
            ],
        ];
    }

    /** ร่างปัจจุบัน (ถ้าไม่เคยบันทึกจะได้ค่าเริ่มต้น) */
    public function getDraft(): array
    {
        $stored = $this->getSetting(self::DRAFT_KEY);
        return $stored !== null ? self::sanitize($stored) : self::defaults();
    }

    /** config ที่เผยแพร่แล้ว — null = ยังไม่เปิดใช้ v2 (ใช้หน้าเดิม) */
    public function getPublished(): ?array
    {
        $stored = $this->getSetting(self::PUBLISHED_KEY);
        return $stored !== null ? self::sanitize($stored) : null;
    }

    public function isPublished(): bool
    {
        return $this->getPublished() !== null;
    }

    public function saveDraft(array $config): void
    {
        $this->putSetting(self::DRAFT_KEY, self::sanitize($config));
    }

    /** เผยแพร่ร่างปัจจุบันให้ผู้เยี่ยมชมเห็น */
    public function publish(): void
    {
        $this->putSetting(self::PUBLISHED_KEY, $this->getDraft());
    }

    /**
     * ปิดใช้ v2 — ผู้เยี่ยมชมกลับไปเห็นหน้าเดิมทันที (ร่างยังอยู่)
     * ลบทุก scope (รวมแถว seed ตอน provision และแถวเก่าที่เคยผูก OA)
     * เพื่อให้ปุ่มย้อนกลับทำงานได้จริงเสมอ
     */
    public function unpublish(): void
    {
        $stmt = $this->db->prepare("DELETE FROM landing_settings WHERE setting_key = ?");
        $stmt->execute([self::PUBLISHED_KEY]);
    }

    /**
     * กรอง config ให้เหลือเฉพาะคีย์/ค่าที่ระบบรู้จัก กันค่าแปลกปลอมจากฟอร์มหรือ DB เก่า
     */
    public static function sanitize(array $config): array
    {
        $clean = self::defaults();

        if (isset($config['theme']) && isset(self::THEMES[$config['theme']])) {
            $clean['theme'] = $config['theme'];
        }
        if (isset($config['hero']) && isset(self::HEROES[$config['hero']])) {
            $clean['hero'] = $config['hero'];
        }
        $clean['headline'] = mb_substr(trim((string) ($config['headline'] ?? '')), 0, 120);
        $clean['tagline']  = mb_substr(trim((string) ($config['tagline'] ?? '')), 0, 200);

        foreach (self::PHOTO_SLOTS as $slot => $label) {
            $filename = (string) ($config['photos'][$slot] ?? '');
            // เก็บเฉพาะชื่อไฟล์ (รูปแบบเดียวกับ TenantFileStorage) กัน path traversal
            $clean['photos'][$slot] = preg_match('/\A[A-Za-z0-9._-]+\z/', $filename) ? $filename : '';
        }

        foreach ($clean['show'] as $section => $default) {
            if (array_key_exists($section, (array) ($config['show'] ?? []))) {
                $clean['show'][$section] = (bool) $config['show'][$section];
            }
        }

        return $clean;
    }

    // ── internal ─────────────────────────────────────────────

    private function getSetting(string $key): ?array
    {
        try {
            // อ่านแถวใหม่สุดก่อน (id DESC) — ถ้ามีแถวซ้ำจากอดีต แถวล่าสุดชนะ
            // และยังอ่านแถวที่เคยผูก OA ได้ (fallback) เพื่อ config เก่าไม่หาย
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM landing_settings
                 WHERE setting_key = ?
                 ORDER BY line_account_id IS NULL DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$key]);
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null || $raw === '') {
                return null;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function putSetting(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        // เขียนที่ scope NULL เสมอ — MySQL unique key ไม่กันแถวซ้ำเมื่อ
        // line_account_id เป็น NULL จึงใช้ select-then-update แทน ON DUPLICATE KEY
        $sel = $this->db->prepare(
            "SELECT id FROM landing_settings
             WHERE setting_key = ? AND line_account_id IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $sel->execute([$key]);
        $existingId = $sel->fetchColumn();
        if ($existingId !== false) {
            $upd = $this->db->prepare("UPDATE landing_settings SET setting_value = ? WHERE id = ?");
            $upd->execute([$json, (int) $existingId]);
            return;
        }

        $ins = $this->db->prepare(
            "INSERT INTO landing_settings (line_account_id, setting_key, setting_value) VALUES (NULL, ?, ?)"
        );
        $ins->execute([$key, $json]);
    }
}
