<?php
/**
 * Property-Based Test: การ์ด Flex ฝั่ง self-service ผู้ป่วย
 *
 * **Validates: การ์ดคลินิกใน classes/FlexTemplates.php**
 * (patientMainMenu, pharmacyHoursCard, refillRequestCard, clinicalTriageCard)
 *
 * ทดสอบเฉพาะฟังก์ชันบริสุทธิ์ ไม่แตะ DB และไม่ยิง LINE
 *
 * Property 1: ทุกการ์ดต้องไม่มีอีโมจิตกแต่งเลย ไม่ว่าจะป้อนอินพุตแบบใด
 *             (AC1 ของสเปกสแกนแค่ตัวไฟล์ ซึ่งพลาดข้อความที่ประกอบตอนรันไทม์)
 *
 * Property 2: โครงสร้างที่ส่งออกต้องเป็น Flex ที่ถูกสเปกเสมอ — ทุก node มี type,
 *             box มี layout + contents, ปุ่มมี action ที่ถูกต้อง
 *
 * Property 3: ปุ่มที่พาไป Mini App ต้องเป็น https://liff.line.me/... เท่านั้น
 *             และเมื่อไม่ได้ตั้ง LIFF ID ปุ่มนั้นต้องหายไป ไม่ใช่ชี้ไป URL เสีย
 *
 * Property 4: ทุกปุ่ม postback ต้องถอดเป็น action ที่ MemberPostbackRouter รู้จักจริง
 *             กันปุ่มตายแบบเดียวกับที่เคยเกิดกับปุ่ม "ทานแล้ว"
 *
 * Property 5: pharmacyHoursCard ต้องสะท้อนสถานะเปิด/ปิดตรงกับอินพุตเสมอ และ
 *             ต้องไม่แอบอ้างช่วงเวลาทำการ เพราะ shop_settings ไม่มีคอลัมน์เก็บ
 */

namespace Tests\PatientSelfService;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/FlexTemplates.php';

class ClinicalFlexPropertyTest extends TestCase
{
    /** action ที่ MemberPostbackRouter::handle() มี case รองรับจริง */
    private const KNOWN_ACTIONS = [
        'medication_taken',
        'medication_snooze',
        'member_medications',
        'member_med_stop',
        'member_med_refill',
        'member_points_history',
        'member_appointments',
        'member_appt_confirm',
        'member_appt_cancel',
        'member_notif_prefs',
        'member_notif_toggle',
        'member_card',
        'member_points',
        'member_rewards',
    ];

    /** node ที่ LINE ยอมรับใน Flex container */
    private const VALID_TYPES = [
        'bubble', 'carousel', 'box', 'text', 'button', 'separator',
        'image', 'icon', 'span', 'filler', 'video',
    ];

    /**
     * property ที่มี type เป็นของตัวเองแต่ไม่ใช่ content node
     * action ตรวจแยกในบล็อกของมันเอง / background เป็นสเปกพื้นหลัง ไม่ใช่ลูก
     */
    private const NON_CONTENT_KEYS = ['action', 'background'];

    private const VALID_ACTION_TYPES = [
        'message', 'postback', 'uri', 'camera', 'cameraRoll', 'location', 'datetimepicker',
    ];

    // ------------------------------------------------------------------
    // เครื่องมือ
    // ------------------------------------------------------------------

    /** อินพุตกวน ๆ ที่ค่าจริงเป็นได้ — รวมค่าว่างและอักขระที่ทำให้ JSON พัง */
    private function fuzzString($i)
    {
        $pool = [
            '', ' ', 'ร้านยาสุขภาพดี', 'CNY Pharmacy', '  เว้นวรรคหน้าหลัง  ',
            'ภก. สมชาย ใจดี', '<script>alert(1)</script>', 'ยาว' . str_repeat('ก', 200),
            '0812345678', '02-123-4567', "บรรทัด\nใหม่", '"quote"', '\\backslash',
        ];
        return $pool[$i % count($pool)];
    }

    /** เดินทุก node ของ Flex tree แล้วเรียก callback */
    private function walk($node, callable $fn, $path = 'root')
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['type']) && is_string($node['type'])) {
            $fn($node, $path);
        }
        foreach ($node as $key => $child) {
            if (is_array($child) && !in_array($key, self::NON_CONTENT_KEYS, true)) {
                $this->walk($child, $fn, $path . '.' . $key);
            }
        }
    }

    /** สตริงทั้งหมดที่ผู้ป่วยมองเห็น */
    private function visibleStrings($node)
    {
        $out = [];
        $this->walk($node, function ($n) use (&$out) {
            if (isset($n['text']) && is_string($n['text'])) {
                $out[] = $n['text'];
            }
            if (isset($n['action']['label'])) {
                $out[] = (string) $n['action']['label'];
            }
        });
        return $out;
    }

    /**
     * การ์ดทุกใบที่ต้องผ่านทุก property — ทั้งกรณีมีและไม่มี LIFF
     *
     * @return array<string, array{0: array, 1: bool}> [เคส => [bubble, มี LIFF ไหม]]
     */
    private function allCards()
    {
        $liff = 'https://liff.line.me/1234567890-abcdefgh';
        $cards = [];

        for ($i = 0; $i < 13; $i++) {
            $name = $this->fuzzString($i);
            $phone = $this->fuzzString($i + 3);

            $cards["menu.liff.$i"] = [
                \FlexTemplates::patientMainMenu(
                    ['shop_name' => $name, 'is_open' => ($i % 2 === 0)],
                    ['home' => $liff, 'health' => $liff . '/health']
                ),
                true,
            ];
            $cards["menu.noliff.$i"] = [
                \FlexTemplates::patientMainMenu(
                    ['shop_name' => $name, 'is_open' => ($i % 2 === 1)],
                    ['home' => null, 'health' => null]
                ),
                false,
            ];
            $cards["hours.$i"] = [
                \FlexTemplates::pharmacyHoursCard(
                    $i % 2 === 0,
                    $name,
                    [
                        'phone' => $phone,
                        'address' => $this->fuzzString($i + 1),
                        'pharmacist_license' => $this->fuzzString($i + 2),
                    ]
                ),
                false,
            ];
            $cards["refill.liff.$i"] = [
                \FlexTemplates::refillRequestCard(
                    $i % 3 === 0 ? [] : array_fill(0, $i, ['medication_name' => $name, 'dosage' => $phone]),
                    $liff . '/health'
                ),
                true,
            ];
            $cards["refill.noliff.$i"] = [
                \FlexTemplates::refillRequestCard([['medication_name' => $name, 'dosage' => '']], null),
                false,
            ];
            $cards["triage.liff.$i"] = [\FlexTemplates::clinicalTriageCard($liff . '/ai-chat'), true];
            $cards["triage.noliff.$i"] = [\FlexTemplates::clinicalTriageCard(null), false];
        }

        return $cards;
    }

    // ------------------------------------------------------------------
    // Property 1 — ไม่มีอีโมจิตกแต่ง
    // ------------------------------------------------------------------

    public function testNoDecorativeEmojiInAnyClinicalCard()
    {
        $emojiPattern = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{20E3}]/u';

        foreach ($this->allCards() as $case => $entry) {
            foreach ($this->visibleStrings($entry[0]) as $text) {
                $this->assertSame(
                    0,
                    preg_match($emojiPattern, $text),
                    "พบอีโมจิในการ์ด [$case]: " . $text
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Property 2 — โครงสร้าง Flex ถูกสเปก
    // ------------------------------------------------------------------

    public function testEveryCardIsStructurallyValidFlex()
    {
        foreach ($this->allCards() as $case => $entry) {
            $card = $entry[0];

            $this->assertSame('bubble', $card['type'] ?? null, "[$case] ต้องเป็น bubble");
            $this->assertNotFalse(json_encode($card), "[$case] ต้อง encode เป็น JSON ได้");

            $this->walk($card, function ($node, $path) use ($case) {
                $this->assertContains(
                    $node['type'],
                    self::VALID_TYPES,
                    "[$case] $path มี type ที่ LINE ไม่รู้จัก: {$node['type']}"
                );

                if ($node['type'] === 'box') {
                    $this->assertArrayHasKey('layout', $node, "[$case] $path box ต้องมี layout");
                    $this->assertContains($node['layout'], ['vertical', 'horizontal', 'baseline'], "[$case] $path layout ไม่ถูกต้อง");
                    $this->assertArrayHasKey('contents', $node, "[$case] $path box ต้องมี contents");
                    $this->assertIsArray($node['contents'], "[$case] $path contents ต้องเป็น array");
                }

                if ($node['type'] === 'text') {
                    $this->assertArrayHasKey('text', $node, "[$case] $path text ต้องมีคีย์ text");
                    $this->assertIsString($node['text'], "[$case] $path text ต้องเป็นสตริง");
                    // LINE ปฏิเสธทั้งข้อความถ้ามี text ว่างแม้แต่ node เดียว
                    $this->assertNotSame('', $node['text'], "[$case] $path text ว่าง LINE จะปฏิเสธทั้งข้อความ");
                }

                if ($node['type'] === 'button') {
                    $this->assertArrayHasKey('action', $node, "[$case] $path button ต้องมี action");
                }

                if (isset($node['action'])) {
                    $action = $node['action'];
                    $this->assertIsArray($action, "[$case] $path action ต้องเป็น array");
                    $this->assertContains($action['type'] ?? '', self::VALID_ACTION_TYPES, "[$case] $path action.type ไม่ถูกต้อง");
                    $this->assertNotSame('', (string) ($action['label'] ?? ''), "[$case] $path action ต้องมี label");
                    // LINE จำกัด label ไว้ 20 ตัวอักษร
                    $this->assertLessThanOrEqual(20, mb_strlen((string) $action['label']), "[$case] $path label ยาวเกิน 20 ตัวอักษร");

                    if ($action['type'] === 'message') {
                        $this->assertNotSame('', (string) ($action['text'] ?? ''), "[$case] $path message action ต้องมี text");
                    }
                    if ($action['type'] === 'uri') {
                        $this->assertNotSame('', (string) ($action['uri'] ?? ''), "[$case] $path uri action ต้องมี uri");
                    }
                    if ($action['type'] === 'postback') {
                        $this->assertNotSame('', (string) ($action['data'] ?? ''), "[$case] $path postback ต้องมี data");
                    }
                }
            });
        }
    }

    // ------------------------------------------------------------------
    // Property 3 — deep link เข้า Mini App
    // ------------------------------------------------------------------

    public function testMiniAppLinksAreLiffUrlsAndVanishWithoutLiffId()
    {
        foreach ($this->allCards() as $case => $entry) {
            list($card, $hasLiff) = $entry;

            $uris = [];
            $this->walk($card, function ($node) use (&$uris) {
                if (($node['action']['type'] ?? '') === 'uri') {
                    $uris[] = (string) $node['action']['uri'];
                }
            });

            foreach ($uris as $uri) {
                $this->assertMatchesRegularExpression(
                    '#^(https://liff\.line\.me/|tel:)#',
                    $uri,
                    "[$case] uri ต้องเป็น LIFF หรือ tel: เท่านั้น ได้: $uri"
                );
            }

            if (!$hasLiff) {
                $liffUris = array_values(array_filter(
                    $uris,
                    function ($u) {
                        return strpos($u, 'https://liff.line.me/') === 0;
                    }
                ));
                $this->assertSame([], $liffUris, "[$case] ไม่ได้ตั้ง LIFF ID แต่ยังมีปุ่มชี้ไป LIFF");
            }
        }
    }

    // ------------------------------------------------------------------
    // Property 4 — ปุ่ม postback ต้องมีปลายทางจริง
    // ------------------------------------------------------------------

    public function testEveryPostbackResolvesToAKnownAction()
    {
        foreach ($this->allCards() as $case => $entry) {
            $this->walk($entry[0], function ($node) use ($case) {
                if (($node['action']['type'] ?? '') !== 'postback') {
                    return;
                }
                parse_str((string) $node['action']['data'], $params);
                $this->assertArrayHasKey('action', $params, "[$case] postback data ไม่มี action");
                $this->assertContains(
                    $params['action'],
                    self::KNOWN_ACTIONS,
                    "[$case] ปุ่มชี้ไป action ที่ router ไม่รู้จัก: {$params['action']}"
                );
            });
        }
    }

    // ------------------------------------------------------------------
    // Property 5 — สถานะเปิด/ปิด
    // ------------------------------------------------------------------

    public function testHoursCardAlwaysReportsTheStatusItWasGiven()
    {
        foreach ([true, false, 1, 0, '1', ''] as $i => $input) {
            $card = \FlexTemplates::pharmacyHoursCard($input, 'ภก. ทดสอบ', []);
            $strings = implode(' | ', $this->visibleStrings($card));

            if ($input) {
                $this->assertStringContainsString('เปิดให้บริการ', $strings, "อินพุต #$i ควรรายงานว่าเปิด");
                $this->assertStringNotContainsString('ปิดให้บริการชั่วคราว', $strings, "อินพุต #$i ไม่ควรรายงานว่าปิด");
            } else {
                $this->assertStringContainsString('ปิดให้บริการชั่วคราว', $strings, "อินพุต #$i ควรรายงานว่าปิด");
            }
        }
    }

    // ------------------------------------------------------------------
    // Property 6 — เส้นทางคีย์เวิร์ดต้องไม่ขาดตอน
    //
    // ตรวจจากซอร์สโดยตรง ไม่โหลดคลาส เพื่อไม่ต้องพึ่ง DB/LineAPI
    // ------------------------------------------------------------------

    /**
     * ทุกตาราง routing ใน processMessage รวมกัน
     * เพิ่มตารางใหม่ต้องมาต่อชื่อไว้ที่นี่ ไม่งั้นเทสต์จะไม่คุ้มตารางนั้น
     */
    private function patientKeywordMap()
    {
        // recursive เพราะ method เดียวกันโผล่ได้ทั้งสองตาราง (showOrders)
        // array_merge ปกติจะทับคีย์เวิร์ดชุดแรกทิ้ง ทำให้เทสต์มองไม่เห็น
        return array_merge_recursive(
            $this->keywordMap('patientKeywords'),
            $this->keywordMap('shopKeywords')
        );
    }

    /** ดึงคู่ method => คีย์เวิร์ด จากตาราง routing ตัวหนึ่งใน BusinessBot::processMessage */
    private function keywordMap($varName)
    {
        $src = file_get_contents(__DIR__ . '/../../classes/BusinessBot.php');
        $this->assertNotFalse($src, 'อ่าน BusinessBot.php ไม่ได้');

        $start = strpos($src, '$' . $varName . ' = [');
        $this->assertNotFalse($start, "ไม่พบตาราง \${$varName} — routing ถูกย้ายหรือลบไปแล้ว");
        $end = strpos($src, '];', $start);
        $block = substr($src, $start, $end - $start);

        preg_match_all("/'([A-Za-z]+)'\s*=>\s*\[([^\]]*)\]/u", $block, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'แยกคู่ method => คีย์เวิร์ด ไม่ได้');

        $map = [];
        foreach ($m as $row) {
            preg_match_all("/'([^']+)'/u", $row[2], $words);
            $map[$row[1]] = $words[1];
        }
        return $map;
    }

    /**
     * ทุก method ที่ตารางเรียกผ่าน $this->$method() ต้องมีอยู่จริง
     * การ dispatch ด้วยชื่อเป็นสตริงทำให้ rename แล้วพังเงียบ ๆ เทสต์นี้คือตัวกัน
     */
    public function testEveryRoutedMethodExistsOnBusinessBot()
    {
        $src = file_get_contents(__DIR__ . '/../../classes/BusinessBot.php');

        foreach (array_keys($this->patientKeywordMap()) as $method) {
            $this->assertMatchesRegularExpression(
                '/public function ' . preg_quote($method, '/') . '\s*\(/',
                $src,
                "ตาราง routing ชี้ไป {$method}() ที่ไม่มีอยู่ (หรือไม่ public) ใน BusinessBot"
            );
        }
    }

    /**
     * ทุกคีย์เวิร์ดที่บอทรับ ต้องถูก whitelist ใน webhook.php ด้วย
     * ไม่งั้น AI จะแย่งตอบก่อนที่ข้อความจะไปถึง BusinessBot
     */
    public function testEveryRoutedKeywordIsWhitelistedInWebhook()
    {
        $webhook = file_get_contents(__DIR__ . '/../../webhook.php');
        $this->assertNotFalse($webhook, 'อ่าน webhook.php ไม่ได้');

        // คีย์เวิร์ดที่ webhook เดิมกันไว้ให้ระบบอื่นอยู่แล้ว
        $preExisting = ['เมนู', 'menu', 'ช่วยเหลือ', 'help', '?'];

        foreach ($this->patientKeywordMap() as $method => $words) {
            foreach ($words as $word) {
                if (in_array($word, $preExisting, true)) {
                    continue;
                }
                $this->assertStringContainsString(
                    "'" . $word . "'",
                    $webhook,
                    "คีย์เวิร์ด '{$word}' ของ {$method}() ไม่ได้ถูก whitelist ใน webhook.php — AI จะแย่งตอบ"
                );
            }
        }
    }

    /**
     * 'ปรึกษาเภสัชกร' เต็มคำต้องยังเป็นคำสั่งส่งต่อเภสัชกรจริง
     * ไม่ถูกคีย์เวิร์ด triage 'ปรึกษาเภสัช' กลืนไป
     */
    public function testHumanHandoffKeywordSurvives()
    {
        $webhook = file_get_contents(__DIR__ . '/../../webhook.php');

        $this->assertStringContainsString(
            "\$stopAICommands = ['ปรึกษาเภสัชกร'",
            $webhook,
            "'ปรึกษาเภสัชกร' หลุดจาก \$stopAICommands — ผู้ป่วยจะขอคุยกับคนจริงไม่ได้"
        );

        $map = $this->patientKeywordMap();
        foreach ($map as $method => $words) {
            $this->assertNotContains(
                'ปรึกษาเภสัชกร',
                $words,
                "{$method}() แย่งคำสั่งส่งต่อเภสัชกรตัวจริงไป"
            );
        }
    }

    /** ไม่มีคอลัมน์เก็บเวลาทำการ — การ์ดต้องไม่แสดงช่วงเวลาที่ระบบไม่มีข้อมูล */
    public function testHoursCardNeverInventsOpeningTimes()
    {
        $card = \FlexTemplates::pharmacyHoursCard(true, 'ภก. ทดสอบ', ['phone' => '021234567']);
        $strings = implode(' | ', $this->visibleStrings($card));

        $this->assertSame(
            0,
            preg_match('/\d{1,2}[:.]\d{2}\s*(-|–|ถึง)\s*\d{1,2}[:.]\d{2}/u', $strings),
            'การ์ดแสดงช่วงเวลาทำการ ทั้งที่ shop_settings ไม่มีคอลัมน์เก็บ: ' . $strings
        );
    }
}
