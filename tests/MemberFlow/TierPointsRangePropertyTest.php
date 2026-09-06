<?php
/**
 * Property-Based Test: ช่วงแต้มของแต่ละ tier
 *
 * **Validates: classes/TierService.php::pointsRangeForTier()**
 *
 * `loyalty_points.tier` เป็นคอลัมน์ที่ไม่มีใครเขียนแล้ว หน้าที่กรองด้วย tier
 * (users.php, AdvancedCRM::buildSegmentQuery) จึงเลิกอ่านคอลัมน์นั้นแล้วหันมา
 * กรองด้วย "ช่วงแต้ม" ที่ pointsRangeForTier คืนมาแทน
 *
 * ถ้าช่วงที่คืนมาไม่ตรงกับที่ calculateTier() ตัดสิน ตัวกรองจะคืนลูกค้าผิดกลุ่ม
 * แบบเงียบ ๆ — broadcast ยิงผิดคน แต่ระบบไม่ error เลย
 *
 * Property 1: ทุกจำนวนแต้ม ต้องตกอยู่ในช่วงของ tier ที่ calculateTier() ให้มา
 * Property 2: tier ที่ไม่รู้จักต้องคืน null (ผู้เรียกจะได้ตีเป็น "ไม่ตรงใคร"
 *             ไม่ใช่ "ตรงทุกคน") และชื่อ tier ต้องไม่แคร์ตัวพิมพ์
 * Property 3: ช่วงของทุก tier ต้องต่อกันสนิท ไม่ทับกัน ไม่มีรู — และต้องอ่าน
 *             จาก tier_settings ที่ร้านตั้งเอง ไม่ใช่ค่า default อย่างเดียว
 */

namespace Tests\MemberFlow;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/TierService.php';

/** statement ปลอม คืนแถว tier_settings ที่กำหนดไว้ */
class FakeTierStatement
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function execute($params = [])
    {
        return true;
    }

    public function fetchAll($mode = null)
    {
        return $this->rows;
    }
}

/** PDO ปลอม — คืน tier ที่ร้านตั้งไว้ หรือโยน exception เพื่อบังคับให้ใช้ค่า default */
class FakeTierPdo
{
    private $rows;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function prepare($sql)
    {
        if ($this->rows === null) {
            throw new \Exception('no database in unit test');
        }
        return new FakeTierStatement($this->rows);
    }
}

class TierPointsRangePropertyTest extends TestCase
{
    /** lineAccountId ต่างกันทุกเคส เพราะ TierService::$tierCache เป็น static */
    private static $accountSeq = 9000;

    private function service($rows = null)
    {
        return new \TierService(new FakeTierPdo($rows), self::$accountSeq++);
    }

    /**
     * Property 1: แต้มทุกค่าต้องอยู่ในช่วงของ tier ที่ calculateTier ตัดสินให้
     */
    public function testEveryBalanceFallsInsideItsOwnTierRange()
    {
        $svc = $this->service();

        $points = [0, 1, 999, 1000, 1001, 4999, 5000, 14999, 15000, 15001];
        for ($i = 0; $i < 200; $i++) {
            $points[] = random_int(0, 50000);
        }

        foreach ($points as $p) {
            $code = $svc->calculateTier($p)['tier_code'];
            $range = $svc->pointsRangeForTier($code);

            $this->assertNotNull($range, "tier '{$code}' from calculateTier({$p}) has no range");
            $this->assertGreaterThanOrEqual(
                $range['min'],
                $p,
                "{$p} points was called '{$code}' but sits below that tier's minimum"
            );
            if ($range['max'] !== null) {
                $this->assertLessThan(
                    $range['max'],
                    $p,
                    "{$p} points was called '{$code}' but sits at or above the next tier"
                );
            }
        }
    }

    /**
     * Property 2: tier ที่ไม่รู้จัก → null และชื่อไม่แคร์ตัวพิมพ์/ช่องว่าง
     */
    public function testUnknownTierReturnsNullAndLookupIsCaseInsensitive()
    {
        $svc = $this->service();

        foreach (['', 'diamond', 'ทอง', 'gold ranking', '0'] as $unknown) {
            $this->assertNull(
                $svc->pointsRangeForTier($unknown),
                "unknown tier '{$unknown}' must not resolve to a range"
            );
        }

        $expected = $svc->pointsRangeForTier('gold');
        $this->assertNotNull($expected);
        $this->assertSame($expected, $svc->pointsRangeForTier('GOLD'));
        $this->assertSame($expected, $svc->pointsRangeForTier('  Gold  '));
    }

    /**
     * Property 3: ช่วงต่อกันสนิท ไม่ทับ ไม่มีรู — และอ่าน tier ที่ร้านตั้งเอง
     */
    public function testRangesPartitionThePointsLineForConfiguredTiers()
    {
        $configured = [
            ['tier_name' => 'Starter', 'tier_code' => 'starter', 'min_points' => 0],
            ['tier_name' => 'Regular', 'tier_code' => 'regular', 'min_points' => 250],
            ['tier_name' => 'VIP', 'tier_code' => 'vip', 'min_points' => 2500],
        ];

        foreach ([null, $configured] as $rows) {
            $svc = $this->service($rows);
            $tiers = $svc->getTiers();

            if ($rows !== null) {
                $this->assertSame(
                    ['starter', 'regular', 'vip'],
                    array_column($tiers, 'tier_code'),
                    'configured tier_settings rows must win over the built-in defaults'
                );
            }

            $previousMax = 0;
            foreach ($tiers as $index => $tier) {
                $range = $svc->pointsRangeForTier($tier['tier_code']);
                $this->assertNotNull($range);

                $this->assertSame(
                    $previousMax,
                    $range['min'],
                    "tier '{$tier['tier_code']}' leaves a gap or overlap below it"
                );

                $isLast = !isset($tiers[$index + 1]);
                if ($isLast) {
                    $this->assertNull($range['max'], 'the top tier must have no upper bound');
                } else {
                    $this->assertGreaterThan(
                        $range['min'],
                        $range['max'],
                        "tier '{$tier['tier_code']}' has an empty range"
                    );
                    $previousMax = $range['max'];
                }
            }
        }
    }
}
