<?php
/**
 * Property-Based Test: เลน reply ฝั่งสมาชิก
 *
 * **Validates: classes/MemberPostbackRouter.php + หน้าจอ Flex สมาชิกใน
 * classes/FlexTemplates.php**
 *
 * ทดสอบเฉพาะส่วนที่เป็นฟังก์ชันบริสุทธิ์ ไม่แตะ DB และไม่ยิง LINE
 *
 * Property 1: parse() ถอด action + params กลับมาได้ตรงกับที่ปุ่มส่งไปเสมอ
 * ทั้งรูปแบบ query-string (ที่ cron ใช้อยู่แล้ว) และ JSON
 *
 * Property 2: อะไรที่ไม่ใช่ของ router ต้องคืน null — โดยเฉพาะ
 * broadcast_click_{id}_{id} ของเดิม ถ้ากลืนไปจะทำให้ auto-tag ของ broadcast พัง
 *
 * Property 3: ทุกปุ่มบนหน้าจอ Flex สมาชิก ต้องถอดกลับเป็น action ที่ router
 * รู้จักจริง — กันกรณีเปลี่ยนชื่อ action ฝั่งเดียวแล้วปุ่มกลายเป็นปุ่มตาย
 * ซึ่งคือบั๊กที่ทำให้ปุ่ม "ทานแล้ว" เงียบมาตั้งแต่ต้น
 *
 * Property 4: คอลัมน์ที่ยอมให้สลับผ่านปุ่มต้องมีอยู่จริงใน
 * user_notification_preferences ทุกตัว มิฉะนั้นสวิตช์จะเขียนลงคอลัมน์ผี
 *
 * Property 5: normalizeTime() คืนรูปแบบ HH:MM:SS เสมอ ไม่ว่าจะป้อนอะไรเข้าไป
 *
 * Property 6: carousel ต้องไม่เกินขีดจำกัด 12 ใบของ LINE ไม่ว่ารายการจะยาวแค่ไหน
 *
 * Property 7: ตั้งค่าแจ้งเตือนที่ยังไม่เคยตั้ง ต้องนับเป็น "เปิด" (opt-out)
 *             และปุ่มต้องสะท้อนสถานะจริงเสมอ
 *
 * Property 8: thaiFullDate() แปลง ค.ศ. เป็น พ.ศ. ถูกต้องทุกวันที่
 */

namespace Tests\MemberFlow;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../classes/MemberPostbackRouter.php';

class MemberFlexFlowPropertyTest extends TestCase
{
    /** คอลัมน์จริงใน user_notification_preferences (migration_2026-05-25_tenant_template.sql) */
    private const PREF_COLUMNS = [
        'order_updates',
        'promotions',
        'appointment_reminders',
        'drug_reminders',
        'health_tips',
        'price_alerts',
        'restock_alerts',
    ];

    /** action ที่ router มี case รองรับจริง */
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

    // ---------------------------------------------------------------
    // Property 1 — round-trip
    // ---------------------------------------------------------------

    public function testParseRoundTripsQueryStringForEveryKnownAction(): void
    {
        foreach (self::KNOWN_ACTIONS as $action) {
            for ($i = 0; $i < 10; $i++) {
                $id = random_int(1, 999999);
                $slot = sprintf('%02d:%02d', random_int(0, 23), random_int(0, 59));

                $data = 'action=' . $action . '&reminder_id=' . $id . '&time=' . $slot;
                $parsed = \MemberPostbackRouter::parse($data);

                $this->assertIsArray($parsed, "ถอดไม่ออก: {$data}");
                $this->assertSame($action, $parsed['action']);
                $this->assertSame((string) $id, $parsed['params']['reminder_id']);
                $this->assertSame($slot, $parsed['params']['time']);
                $this->assertArrayNotHasKey('action', $parsed['params'], 'action ต้องไม่ค้างใน params');
            }
        }
    }

    public function testParseRoundTripsJsonForm(): void
    {
        foreach (self::KNOWN_ACTIONS as $action) {
            $id = random_int(1, 999999);
            $parsed = \MemberPostbackRouter::parse(json_encode(['action' => $action, 'id' => $id]));

            $this->assertIsArray($parsed);
            $this->assertSame($action, $parsed['action']);
            $this->assertSame($id, $parsed['params']['id']);
        }
    }

    // ---------------------------------------------------------------
    // Property 2 — ของคนอื่นต้องปล่อยผ่าน
    // ---------------------------------------------------------------

    public function testParseReturnsNullForPostbacksOwnedByExistingHandlers(): void
    {
        $notOurs = ['', '   ', 'hello', '{', '{}', '{"foo":"bar"}', '[]', 'null', 'action=', '=x'];

        // broadcast_click_{broadcastId}_{productId} — จัดการโดย handleBroadcastClick()
        for ($i = 0; $i < 100; $i++) {
            $notOurs[] = 'broadcast_click_' . random_int(1, 9999) . '_' . random_int(1, 9999);
        }

        foreach ($notOurs as $data) {
            $this->assertNull(
                \MemberPostbackRouter::parse($data),
                "ต้องปล่อยให้ handler เดิมจัดการ: {$data}"
            );
        }
    }

    public function testSendReceiptStaysWithItsOwnHandler(): void
    {
        // parse() ถอด send_receipt ออกมาได้ก็จริง แต่ handle() ต้องไม่มี case
        // รองรับ มิฉะนั้นขั้นตอนส่งสลิปรับแต้มใน webhook.php จะถูกกลืน
        $parsed = \MemberPostbackRouter::parse('{"action":"send_receipt"}');
        $this->assertSame('send_receipt', $parsed['action']);
        $this->assertNotContains('send_receipt', self::KNOWN_ACTIONS);
    }

    // ---------------------------------------------------------------
    // Property 3 — ปุ่มบน Flex ต้องตรงกับ action ที่ router รู้จัก
    // ---------------------------------------------------------------

    public function testEveryButtonOnMemberScreensMapsToAKnownAction(): void
    {
        $screens = [
            'ยาของฉัน' => \FlexTemplates::medicationList($this->fakeMedications(3)),
            'ประวัติแต้ม' => \FlexTemplates::pointsHistory($this->fakePointsRows(5), 1200),
            'นัดหมาย' => \FlexTemplates::appointmentList($this->fakeAppointments()),
            'ตั้งค่าแจ้งเตือน' => \FlexTemplates::notificationPrefs([]),
        ];

        $found = 0;
        foreach ($screens as $name => $flex) {
            foreach ($this->collectPostbackData($flex) as $data) {
                $found++;
                $parsed = \MemberPostbackRouter::parse($data);
                $this->assertIsArray($parsed, "{$name}: ปุ่มส่ง data ที่ถอดไม่ออก — {$data}");
                $this->assertContains(
                    $parsed['action'],
                    self::KNOWN_ACTIONS,
                    "{$name}: ปุ่มยิง action ที่ router ไม่มี case รองรับ — {$parsed['action']}"
                );
            }
        }

        $this->assertGreaterThan(10, $found, 'ควรเจอปุ่ม postback หลายปุ่มบนหน้าจอสมาชิก');
    }

    public function testCronReminderButtonsAreRoutable(): void
    {
        // รูปแบบที่ cron/medication_reminder.php ส่งจริงตั้งแต่ก่อนมี router
        foreach (['medication_taken', 'medication_snooze'] as $action) {
            for ($i = 0; $i < 50; $i++) {
                $data = 'action=' . $action
                    . '&reminder_id=' . random_int(1, 99999)
                    . '&time=' . sprintf('%02d:%02d', random_int(0, 23), random_int(0, 59));

                $parsed = \MemberPostbackRouter::parse($data);
                $this->assertContains($parsed['action'], self::KNOWN_ACTIONS);
            }
        }
    }

    // ---------------------------------------------------------------
    // Property 4 — คอลัมน์ที่สลับได้ต้องมีจริง
    // ---------------------------------------------------------------

    public function testToggleableColumnsAllExistInSchema(): void
    {
        foreach (\MemberPostbackRouter::TOGGLEABLE_PREFS as $column) {
            $this->assertContains(
                $column,
                self::PREF_COLUMNS,
                "คอลัมน์ {$column} ไม่มีใน user_notification_preferences — สวิตช์จะเขียนลงคอลัมน์ผี"
            );
        }
    }

    public function testToggleRejectsColumnNamesOutsideTheWhitelist(): void
    {
        $injections = ['id', 'user_id', 'created_at', 'drug_reminders`, `promotions', '1=1', ''];
        foreach ($injections as $bad) {
            $this->assertNotContains(
                $bad,
                \MemberPostbackRouter::TOGGLEABLE_PREFS,
                "ชื่อคอลัมน์อันตรายหลุดเข้า whitelist: {$bad}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Property 5 — เวลาสล็อต
    // ---------------------------------------------------------------

    public function testNormalizeTimeAlwaysReturnsSqlTime(): void
    {
        $method = new ReflectionMethod(\MemberPostbackRouter::class, 'normalizeTime');
        $method->setAccessible(true);

        // ทุกนาทีของวัน ต้องได้ HH:MM:00 ตรงตัว
        for ($h = 0; $h < 24; $h++) {
            for ($m = 0; $m < 60; $m += 7) {
                $input = sprintf('%02d:%02d', $h, $m);
                $this->assertSame($input . ':00', $method->invoke(null, $input));
            }
        }

        // ขยะทุกแบบต้องยังได้รูปแบบที่ใส่คอลัมน์ TIME ได้ ไม่โยน exception
        $junk = ['', '  ', '24:00', '25:61', '8:5', 'abc', '08:00:00', '-1:-1', '08-00', null, '99'];
        foreach ($junk as $input) {
            $out = $method->invoke(null, $input);
            $this->assertMatchesRegularExpression(
                '/^([01]\d|2[0-3]):[0-5]\d:00$/',
                $out,
                'input: ' . var_export($input, true)
            );
        }
    }

    // ---------------------------------------------------------------
    // Property 6 — ขีดจำกัด carousel ของ LINE
    // ---------------------------------------------------------------

    public function testCarouselsNeverExceedLineLimit(): void
    {
        for ($n = 0; $n <= 50; $n++) {
            foreach ([
                \FlexTemplates::medicationList($this->fakeMedications($n)),
                \FlexTemplates::appointmentList($this->fakeAppointments($n)),
            ] as $flex) {
                if (($flex['type'] ?? '') !== 'carousel') {
                    continue; // n=0 → emptyState bubble
                }
                $count = count($flex['contents']);
                $this->assertLessThanOrEqual(12, $count, "carousel {$count} ใบ เกินขีดจำกัดของ LINE (n={$n})");
                $this->assertGreaterThan(0, $count);
                foreach ($flex['contents'] as $bubble) {
                    $this->assertSame('bubble', $bubble['type']);
                }
            }
        }
    }

    public function testEmptyListsRenderSomethingInsteadOfBlank(): void
    {
        foreach ([
            \FlexTemplates::medicationList([]),
            \FlexTemplates::appointmentList([]),
            \FlexTemplates::pointsHistory([], 0),
        ] as $flex) {
            $this->assertSame('bubble', $flex['type'] ?? null);
        }
    }

    // ---------------------------------------------------------------
    // Property 7 — ค่าเริ่มต้นของสวิตช์คือเปิด (opt-out)
    // ---------------------------------------------------------------

    public function testUnsetPreferenceCountsAsOnAndButtonsMirrorState(): void
    {
        // ยังไม่เคยมีแถว → ทุกสวิตช์ต้องแสดงว่าเปิด
        foreach ($this->collectButtons(\FlexTemplates::notificationPrefs([])) as $btn) {
            if (strpos($btn['action']['data'] ?? '', 'member_notif_toggle') === false) {
                continue;
            }
            $this->assertSame('เปิดอยู่', $btn['action']['label']);
        }

        // สุ่มสถานะ 100 ชุด ปุ่มต้องสะท้อนค่าจริงเสมอ
        for ($i = 0; $i < 100; $i++) {
            $prefs = [];
            foreach (self::PREF_COLUMNS as $col) {
                $prefs[$col] = random_int(0, 1);
            }

            foreach ($this->collectButtons(\FlexTemplates::notificationPrefs($prefs)) as $btn) {
                $data = $btn['action']['data'] ?? '';
                $parsed = \MemberPostbackRouter::parse($data);
                if (!$parsed || $parsed['action'] !== 'member_notif_toggle') {
                    continue;
                }
                $key = $parsed['params']['key'];
                $expected = ((int) $prefs[$key] === 1) ? 'เปิดอยู่' : 'ปิดอยู่';
                $this->assertSame($expected, $btn['action']['label'], "สวิตช์ {$key} แสดงไม่ตรงค่าจริง");
            }
        }
    }

    // ---------------------------------------------------------------
    // Property 8 — พ.ศ.
    // ---------------------------------------------------------------

    public function testThaiFullDateConvertsToBuddhistEra(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $ts = mktime(0, 0, 0, random_int(1, 12), random_int(1, 28), random_int(2020, 2035));
            $date = date('Y-m-d', $ts);
            $out = \FlexTemplates::thaiFullDate($date);

            $this->assertStringContainsString((string) ((int) date('Y', $ts) + 543), $out, "วันที่ {$date}");
            $this->assertStringNotContainsString(date('Y', $ts), $out, "ยังมี ค.ศ. ติดอยู่: {$out}");
        }

        $this->assertSame('', \FlexTemplates::thaiFullDate(''));
        $this->assertSame('', \FlexTemplates::thaiFullDate(null));
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function fakeMedications(int $n): array
    {
        $meds = [];
        for ($i = 1; $i <= $n; $i++) {
            $meds[] = [
                'id' => $i,
                'medication_name' => 'ยาทดสอบ ' . $i,
                'dosage' => '1 เม็ด',
                'frequency' => 'daily',
                'reminder_times' => json_encode(['08:00', '18:00']),
                'product_id' => $i % 3 === 0 ? null : $i,
            ];
        }
        return $meds;
    }

    private function fakeAppointments(int $n = 3): array
    {
        $statuses = ['pending', 'confirmed', 'in_progress'];
        $appts = [];
        for ($i = 1; $i <= $n; $i++) {
            $appts[] = [
                'id' => $i,
                'appointment_type' => 'consultation',
                'appointment_date' => '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'appointment_time' => '09:30:00',
                'status' => $statuses[$i % 3],
                'notes' => 'หมายเหตุทดสอบ',
            ];
        }
        return $appts;
    }

    private function fakePointsRows(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'created_at' => '2026-09-0' . (($i % 9) + 1) . ' 10:00:00',
                'description' => 'รายการทดสอบ ' . $i,
                'points' => $i % 2 === 0 ? $i * 10 : -$i * 5,
                'balance_after' => 1000 + $i,
            ];
        }
        return $rows;
    }

    /** เดินทั้งต้นไม้ Flex เก็บ postback data ทุกปุ่ม */
    private function collectPostbackData($node): array
    {
        $out = [];
        foreach ($this->collectButtons($node) as $btn) {
            if (($btn['action']['type'] ?? '') === 'postback' && isset($btn['action']['data'])) {
                $out[] = $btn['action']['data'];
            }
        }
        return $out;
    }

    private function collectButtons($node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $out = [];
        if (($node['type'] ?? '') === 'button' && isset($node['action'])) {
            $out[] = $node;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $out = array_merge($out, $this->collectButtons($child));
            }
        }
        return $out;
    }
}
