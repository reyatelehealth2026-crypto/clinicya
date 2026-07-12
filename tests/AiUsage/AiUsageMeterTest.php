<?php
/**
 * AiUsageMeter Test
 *
 * Property-based, DB-free tests for the per-tenant AI usage counter
 * (Phase 3 · #19). PDO is mocked so no real database is required; each
 * property runs over 100+ randomised inputs.
 *
 * @spec ai-usage-metering
 */

require_once __DIR__ . '/../../classes/AiUsageMeter.php';

use PHPUnit\Framework\TestCase;

class AiUsageMeterTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Build a mocked PDO whose prepare() returns a stub PDOStatement that
     * records the SQL passed to prepare() and the params passed to execute().
     *
     * @param array<int,array<string,mixed>> $fetchAllResult rows returned by fetchAll()
     */
    private function mockedPdo(array &$capturedSql, array &$capturedParams, array $fetchAllResult = []): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams[] = $params;
                return true;
            });
        $stmt->method('fetchAll')->willReturn($fetchAllResult);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function ($sql) use (&$capturedSql, $stmt) {
                $capturedSql[] = $sql;
                return $stmt;
            });
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    /** @return array<int,array{0:int|null,1:string,2:string}> */
    public function tenantProviderModelProvider(): array
    {
        $providers = ['gemini', 'openai'];
        $models = ['gemini-flash-latest', 'gemini-2.5-flash', 'gpt-4o-mini'];
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $lineAccountId = mt_rand(0, 1) ? mt_rand(1, 9999) : null;
            $provider = $providers[array_rand($providers)];
            $model = $models[array_rand($models)];
            $cases["case_{$i}"] = [$lineAccountId, $provider, $model];
        }
        return $cases;
    }

    /**
     * Property: increment() always issues an INSERT ... ON DUPLICATE KEY UPDATE
     * against ai_usage_counters, binding the exact tenant/provider/model passed in,
     * for any tenant id (including NULL = unscoped) and any provider/model string.
     *
     * @dataProvider tenantProviderModelProvider
     */
    public function testIncrementBindsExactTenantProviderModel(?int $lineAccountId, string $provider, string $model): void
    {
        $sql = [];
        $params = [];
        $pdo = $this->mockedPdo($sql, $params);

        AiUsageMeter::increment($pdo, $lineAccountId, $provider, $model);

        $this->assertNotEmpty($sql, 'increment() must call prepare()');
        $insertSql = end($sql);
        $this->assertStringContainsString('INSERT INTO ai_usage_counters', $insertSql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $insertSql);
        $this->assertStringContainsString('calls = calls + 1', $insertSql);

        $this->assertNotEmpty($params, 'increment() must call execute()');
        $boundParams = end($params);
        $this->assertSame($lineAccountId, $boundParams[':acc']);
        $this->assertSame($provider, $boundParams[':provider']);
        $this->assertSame($model, $boundParams[':model']);
        $this->assertSame(date('Y-m-d'), $boundParams[':day'], 'day must be today (Asia/Bangkok)');
    }

    /**
     * Property: increment() never throws even when the PDO layer explodes —
     * a metering failure must never break the AI call it is counting.
     */
    public function testIncrementNeverThrowsOnDbFailure(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('exec')->willThrowException(new \PDOException('boom ' . $i));
            $pdo->method('prepare')->willThrowException(new \PDOException('boom-prepare ' . $i));

            $lineAccountId = mt_rand(0, 1) ? mt_rand(1, 999) : null;

            try {
                AiUsageMeter::increment($pdo, $lineAccountId, 'gemini', 'gemini-flash-latest');
                $this->assertTrue(true); // reached without throwing
            } catch (\Throwable $e) {
                $this->fail('increment() must never throw, got: ' . $e->getMessage());
            }
        }
    }

    /**
     * Property: getUsage() always scopes by the given tenant using NULL-safe
     * equality (<=>), so both a real tenant id and NULL (unscoped) filter correctly,
     * and result rows pass straight through from fetchAll().
     *
     * @dataProvider tenantProviderModelProvider
     */
    public function testGetUsageScopesByTenantWithNullSafeEquality(?int $lineAccountId, string $provider, string $model): void
    {
        $rows = [
            ['day' => '2026-07-01', 'provider' => $provider, 'model' => $model, 'calls' => 3],
            ['day' => '2026-07-02', 'provider' => $provider, 'model' => $model, 'calls' => 5],
        ];
        $sql = [];
        $params = [];
        $pdo = $this->mockedPdo($sql, $params, $rows);

        $result = AiUsageMeter::getUsage($pdo, $lineAccountId);

        $usageSql = end($sql);
        $this->assertStringContainsString('line_account_id <=> :acc', $usageSql);

        $usageParams = end($params);
        $this->assertSame($lineAccountId, $usageParams[':acc']);

        $this->assertSame($rows, $result);
    }

    /** Property: an optional date range adds both bounds to the query when both are given. */
    public function testGetUsageAppliesDateRangeWhenProvided(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $from = sprintf('2026-%02d-01', mt_rand(1, 6));
            $to = sprintf('2026-%02d-28', mt_rand(7, 12));

            $sql = [];
            $params = [];
            $pdo = $this->mockedPdo($sql, $params, []);

            AiUsageMeter::getUsage($pdo, mt_rand(1, 999), $from, $to);

            $usageSql = end($sql);
            $this->assertStringContainsString('day >= :from', $usageSql);
            $this->assertStringContainsString('day <= :to', $usageSql);

            $usageParams = end($params);
            $this->assertSame($from, $usageParams[':from']);
            $this->assertSame($to, $usageParams[':to']);
        }
    }

    /**
     * Property: getTotalCalls() sums the `calls` column of whatever getUsage()
     * returns, for any random set of rows.
     */
    public function testGetTotalCallsSumsAllRows(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $n = mt_rand(0, 10);
            $rows = [];
            $expectedSum = 0;
            for ($j = 0; $j < $n; $j++) {
                $calls = mt_rand(0, 500);
                $rows[] = ['day' => '2026-07-0' . (1 + $j % 9), 'provider' => 'gemini', 'model' => 'gemini-flash-latest', 'calls' => $calls];
                $expectedSum += $calls;
            }

            $sql = [];
            $params = [];
            $pdo = $this->mockedPdo($sql, $params, $rows);

            $total = AiUsageMeter::getTotalCalls($pdo, mt_rand(1, 999));

            $this->assertSame($expectedSum, $total);
        }
    }

    /** Property: getUsage()/getTotalCalls() never throw and degrade to [] / 0 on DB failure. */
    public function testGetUsageAndGetTotalCallsNeverThrowOnDbFailure(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('exec')->willThrowException(new \PDOException('boom ' . $i));
            $pdo->method('prepare')->willThrowException(new \PDOException('boom-prepare ' . $i));

            $lineAccountId = mt_rand(0, 1) ? mt_rand(1, 999) : null;

            $usage = AiUsageMeter::getUsage($pdo, $lineAccountId);
            $this->assertSame([], $usage);

            $total = AiUsageMeter::getTotalCalls($pdo, $lineAccountId);
            $this->assertSame(0, $total);
        }
    }
}
