<?php
/**
 * Property-Based Test: NotificationGate policy layer
 *
 * **Validates: ประตูกลางก่อนส่ง push หาลูกค้า — classes/NotificationGate.php**
 *
 * ทดสอบเฉพาะชั้นนโยบายซึ่งเป็นฟังก์ชันบริสุทธิ์ ไม่แตะ DB และไม่ยิง LINE
 *
 * Property 1: isQuietHours() ถูกต้องสำหรับทุกนาทีของวัน (1,440 กรณี) ทั้งช่วง
 * ปกติภายในวันเดียวและช่วงที่ข้ามเที่ยงคืน โดยเทียบกับนิยามอ้างอิงที่คำนวณ
 * แยกจากโค้ดที่ทดสอบ
 *
 * Property 2: event ที่ลูกค้าเป็นคนกำหนดเวลาเอง หรือผูกกับเวลาจริงที่เลื่อนไม่ได้
 * (medication_dose, appointment, order_update) ต้องไม่ถูกช่วงห้ามรบกวนหรือ
 * เพดานต่อวันบล็อก — ลูกค้าที่ตั้งเตือน "ก่อนนอน 21:00" ต้องได้รับข้อความนั้น
 *
 * Property 3: event ที่ไม่รู้จัก (พิมพ์ผิด/ของใหม่ที่ลืมประกาศ) ต้องได้นโยบาย
 * เข้มที่สุดเสมอ ไม่ใช่ผ่านฉลุย — การพิมพ์ชื่อผิดต้องไม่กลายเป็นช่องหลบ Gate
 *
 * Property 4: ทุก event ใน POLICY ต้องอ้างคอลัมน์ที่มีอยู่จริงใน
 * user_notification_preferences มิฉะนั้นสวิตช์ที่ลูกค้ากดปิดจะไม่มีผล
 *
 * Property 5: ช่วงห้ามรบกวนที่ตั้งค่าเสียหาย ต้องไม่บล็อกลูกค้า (fail-open)
 */

namespace Tests\NotificationGate;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/NotificationGate.php';

class NotificationGatePolicyPropertyTest extends TestCase
{
    /** คอลัมน์จริงใน user_notification_preferences (install_complete_latest.sql) */
    private const PREF_COLUMNS = [
        'order_updates',
        'promotions',
        'appointment_reminders',
        'drug_reminders',
        'health_tips',
        'price_alerts',
        'restock_alerts',
    ];

    /** event ที่ห้ามถูกบล็อกด้วยเวลาหรือเพดาน */
    private const ALWAYS_DELIVERABLE = ['medication_dose', 'appointment', 'order_update'];

    /**
     * Property 1 — isQuietHours() ตรงกับนิยามอ้างอิงทุกนาทีของวัน
     */
    public function testQuietHoursMatchesReferenceForEveryMinuteOfDay(): void
    {
        $ranges = [
            ['21:00', '08:00'], // ค่าเริ่มต้น — ข้ามเที่ยงคืน
            ['22:30', '06:15'], // ข้ามเที่ยงคืน ไม่ลงตัวชั่วโมง
            ['09:00', '17:00'], // ภายในวันเดียว
            ['00:00', '07:00'], // เริ่มตรงเที่ยงคืน
            ['23:59', '00:01'], // ช่วงแคบมากคร่อมเที่ยงคืน
        ];

        foreach ($ranges as [$start, $end]) {
            $s = $this->minutes($start);
            $e = $this->minutes($end);

            for ($m = 0; $m < 1440; $m++) {
                $now = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

                // นิยามอ้างอิง เขียนแยกจากโค้ดที่ทดสอบโดยตั้งใจ
                $expected = ($s < $e) ? ($m >= $s && $m < $e) : ($m >= $s || $m < $e);

                $this->assertSame(
                    $expected,
                    \NotificationGate::isQuietHours($now, $start, $end),
                    "ช่วง {$start}-{$end} ที่เวลา {$now}"
                );
            }
        }
    }

    /**
     * Property 1b — ช่วงที่เริ่มเท่ากับจบ แปลว่า "ไม่มีช่วงห้ามรบกวน"
     * ไม่ใช่ "ห้ามทั้งวัน" มิฉะนั้นการตั้งค่าพลาดครั้งเดียวจะปิดระบบทั้งหมด
     */
    public function testEmptyRangeNeverBlocks(): void
    {
        for ($m = 0; $m < 1440; $m += 7) {
            $now = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $this->assertFalse(\NotificationGate::isQuietHours($now, '21:00', '21:00'));
            $this->assertFalse(\NotificationGate::isQuietHours($now, '00:00', '00:00'));
        }
    }

    /**
     * Property 2 — event ที่ลูกค้ากำหนดเวลาเอง ต้องส่งได้เสมอ
     */
    public function testCustomerScheduledEventsAreNeverTimeOrCapBlocked(): void
    {
        foreach (self::ALWAYS_DELIVERABLE as $event) {
            $policy = \NotificationGate::policyFor($event);

            $this->assertFalse(
                $policy['quiet'],
                "{$event} ต้องไม่ถูกช่วงห้ามรบกวนบล็อก — ลูกค้าตั้งเวลานั้นเอง"
            );
            $this->assertFalse(
                $policy['cap'],
                "{$event} ต้องไม่ถูกนับรวมเพดานต่อวัน"
            );
            $this->assertNotNull(
                $policy['pref'],
                "{$event} ต้องยังเคารพสวิตช์ที่ลูกค้ากดปิดเองอยู่"
            );
        }
    }

    /**
     * Property 3 — event ที่ไม่รู้จักต้องได้นโยบายเข้มที่สุด
     */
    public function testUnknownEventFallsBackToStrictestPolicy(): void
    {
        $unknown = [
            'medication_dosee',      // พิมพ์ผิด
            'MEDICATION_DOSE',       // ตัวพิมพ์ไม่ตรง
            'new_campaign_blast',    // ของใหม่ที่ลืมประกาศ
            '',
            'appointment ',          // มีช่องว่างต่อท้าย
        ];

        foreach ($unknown as $event) {
            $policy = \NotificationGate::policyFor($event);

            $this->assertTrue($policy['quiet'], "'{$event}' ต้องเคารพช่วงห้ามรบกวน");
            $this->assertTrue($policy['cap'], "'{$event}' ต้องถูกนับรวมเพดาน");
        }
    }

    /**
     * Property 4 — ทุก pref ที่อ้างต้องมีคอลัมน์รองรับจริง
     */
    public function testEveryPolicyReferencesARealPreferenceColumn(): void
    {
        $this->assertNotEmpty(\NotificationGate::POLICY);

        foreach (\NotificationGate::POLICY as $event => $policy) {
            $this->assertArrayHasKey('pref', $policy, "{$event} ขาดคีย์ pref");
            $this->assertArrayHasKey('quiet', $policy, "{$event} ขาดคีย์ quiet");
            $this->assertArrayHasKey('cap', $policy, "{$event} ขาดคีย์ cap");

            $this->assertContains(
                $policy['pref'],
                self::PREF_COLUMNS,
                "{$event} อ้างคอลัมน์ '{$policy['pref']}' ที่ไม่มีใน user_notification_preferences — สวิตช์ของลูกค้าจะไม่มีผล"
            );

            $this->assertIsBool($policy['quiet']);
            $this->assertIsBool($policy['cap']);
        }
    }

    /**
     * Property 5 — ค่าตั้งค่าเสียหายต้องไม่บล็อก (fail-open)
     *
     * ตารางที่พังหรือค่าที่กรอกผิดไม่ควรทำให้ลูกค้าพลาดข้อความเตือนทานยา
     */
    public function testMalformedQuietHoursNeverBlock(): void
    {
        $garbage = ['', 'abc', '25:00', '21:99', '9', null, '21:00:00:00', '-1:00'];

        foreach ($garbage as $bad) {
            $this->assertFalse(
                \NotificationGate::isQuietHours('22:00', $bad, '08:00'),
                'start ที่เสียหายต้องไม่บล็อก: ' . var_export($bad, true)
            );
            $this->assertFalse(
                \NotificationGate::isQuietHours('22:00', '21:00', $bad),
                'end ที่เสียหายต้องไม่บล็อก: ' . var_export($bad, true)
            );
            $this->assertFalse(
                \NotificationGate::isQuietHours($bad, '21:00', '08:00'),
                'now ที่เสียหายต้องไม่บล็อก: ' . var_export($bad, true)
            );
        }
    }

    /**
     * Property 5b — รูปแบบ HH:MM:SS ที่มาจากคอลัมน์ TIME ของ MySQL ต้องใช้ได้
     *
     * quiet_hours_start เป็นชนิด TIME จึงอ่านกลับมาเป็น '21:00:00' ไม่ใช่ '21:00'
     */
    public function testAcceptsMysqlTimeColumnFormat(): void
    {
        $this->assertTrue(\NotificationGate::isQuietHours('22:00', '21:00:00', '08:00:00'));
        $this->assertTrue(\NotificationGate::isQuietHours('03:30', '21:00:00', '08:00:00'));
        $this->assertFalse(\NotificationGate::isQuietHours('12:00', '21:00:00', '08:00:00'));
        $this->assertFalse(\NotificationGate::isQuietHours('08:00', '21:00:00', '08:00:00'));
        $this->assertTrue(\NotificationGate::isQuietHours('07:59', '21:00:00', '08:00:00'));
    }

    private function minutes(string $hhmm): int
    {
        [$h, $i] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $i;
    }
}
