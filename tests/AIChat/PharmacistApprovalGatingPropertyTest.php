<?php
/**
 * Property-Based Test: Pharmacist Approval Gating (issue #31)
 *
 * ai_pharmacy_settings.require_pharmacist_approval is saved by
 * ai-pharmacy-settings.php but was never read/enforced by TriageRouter — the
 * toggle had no effect. TriageRouter::requiresPharmacistApproval() now reads
 * it, and TriageRouter::finishWithProducts() branches on it: ON routes to a
 * `pending_approval` result instead of auto-presenting `products`.
 *
 * TriageRouter's settings readers use MySQL's NULL-safe `<=>` operator,
 * which SQLite doesn't support — so (matching the existing
 * AiSettingsTenantIsolationPropertyTest convention in this suite) we drive
 * the real TriageRouter methods with a mocked PDO/PDOStatement that returns
 * a controlled fetchColumn() value, rather than executing the query against
 * a real database.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

class PharmacistApprovalGatingPropertyTest extends TestCase
{
    /**
     * Build a TriageRouter without running its constructor (avoids wiring up
     * every collaborator service) but with the private $pdo / $lineAccountId
     * properties set via reflection — enough for the two settings-reader
     * methods under test. $pdo->prepare() returns a stub statement whose
     * fetchColumn() yields $columnValue (false === "no matching row",
     * mirroring real PDO behaviour).
     */
    private function makeRouter(mixed $columnValue, ?int $lineAccountId = null): \Modules\AIChat\Services\TriageRouter
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($columnValue);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $ref = new \ReflectionClass(\Modules\AIChat\Services\TriageRouter::class);
        $router = $ref->newInstanceWithoutConstructor();

        $pdoProp = $ref->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($router, $pdo);

        $accProp = $ref->getProperty('lineAccountId');
        $accProp->setAccessible(true);
        $accProp->setValue($router, $lineAccountId);

        return $router;
    }

    private function callPrivate(object $obj, string $method): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj);
    }

    /**
     * Property: no settings row for the tenant (fetchColumn() === false) →
     * approval gate is OFF (preserves the pre-existing auto-present
     * behaviour), and max questions falls back to 7 — matching the
     * historical fallback-on-missing-column behaviour now that the row
     * simply doesn't exist yet.
     */
    public function testMissingSettingsRowDefaultsToNoGatingAndSevenQuestions(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $acc = mt_rand(0, 1) === 0 ? null : mt_rand(1, 9999);
            $router = $this->makeRouter(false, $acc);

            $this->assertFalse(
                $this->callPrivate($router, 'requiresPharmacistApproval'),
                'no settings row should mean no approval gating'
            );
            $this->assertSame(
                7,
                $this->callPrivate($router, 'getMaxQuestionsPerSession'),
                'no settings row should fall back to 7 questions'
            );
        }
    }

    /**
     * Property: for any stored 0/1 value of require_pharmacist_approval, the
     * router's boolean reading matches it exactly.
     *
     * @dataProvider approvalFlagProvider
     */
    public function testRequiresPharmacistApprovalMatchesStoredFlag(string $flagValue, bool $expected): void
    {
        $router = $this->makeRouter($flagValue);

        $this->assertSame(
            $expected,
            $this->callPrivate($router, 'requiresPharmacistApproval')
        );
    }

    public function approvalFlagProvider(): array
    {
        $cases = [];
        // PDO::fetchColumn() returns the raw driver value; MySQL TINYINT(1)
        // columns come back as numeric strings via PDO.
        for ($i = 0; $i < 60; $i++) {
            $flag = mt_rand(0, 1);
            $cases["case_{$i}_flag{$flag}"] = [(string) $flag, $flag === 1];
        }
        // '0' must never be treated as truthy-empty in a way that flips the
        // gate on (guards against a `!== false` / loose-truthiness regression).
        $cases['explicit_zero'] = ['0', false];
        $cases['explicit_one'] = ['1', true];
        return $cases;
    }

    /**
     * Property: for any stored positive max_questions_per_session, the
     * router returns that exact value; non-positive/garbage values fall
     * back to 7 (mirrors the existing `$n > 0 ? $n : 7` guard).
     *
     * @dataProvider maxQuestionsProvider
     */
    public function testMaxQuestionsPerSessionReadsStoredValue(string $stored, int $expected): void
    {
        $router = $this->makeRouter($stored);

        $this->assertSame(
            $expected,
            $this->callPrivate($router, 'getMaxQuestionsPerSession')
        );
    }

    public function maxQuestionsProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 60; $i++) {
            $n = mt_rand(1, 20);
            $cases["positive_{$i}_{$n}"] = [(string) $n, $n];
        }
        // Non-positive/garbage values must fall back to 7.
        foreach ([0, -1, -5] as $j => $bad) {
            $cases["nonpositive_{$j}"] = [(string) $bad, 7];
        }
        return $cases;
    }

    /**
     * Property: TriageRouter::finishWithProducts() must not present products
     * directly when the tenant requires pharmacist approval — it must
     * return type=pending_approval, keep the products payload (so the
     * pharmacist dashboard/notification can still show what would have been
     * recommended), and never emit type=products for that same call.
     *
     * We exercise this via reflection on a router whose collaborators are
     * stubbed doubles, since finishWithProducts() is private and depends on
     * $this->sessions / $this->recommender / $this->notifier / $this->audit.
     */
    public function testFinishWithProductsRoutesToPendingApprovalWhenGateIsOn(): void
    {
        $router = $this->buildRouterForFinishWithProducts(
            requireApproval: true,
            symptoms: ['pain_head'],
            products: [['id' => 1, 'name' => 'Paracetamol']]
        );

        $ref = new \ReflectionMethod($router, 'finishWithProducts');
        $ref->setAccessible(true);
        $result = $ref->invoke($router, 42);

        $this->assertSame('pending_approval', $result['type']);
        $this->assertNotSame('products', $result['type']);
        $this->assertSame(42, $result['session_id']);
        $this->assertSame([['id' => 1, 'name' => 'Paracetamol']], $result['products']);
    }

    /**
     * Property: when the gate is OFF (default/behaviour-preserving path),
     * the exact same inputs still produce the original type=products result.
     */
    public function testFinishWithProductsStillReturnsProductsWhenGateIsOff(): void
    {
        $router = $this->buildRouterForFinishWithProducts(
            requireApproval: false,
            symptoms: ['pain_head'],
            products: [['id' => 1, 'name' => 'Paracetamol']]
        );

        $ref = new \ReflectionMethod($router, 'finishWithProducts');
        $ref->setAccessible(true);
        $result = $ref->invoke($router, 42);

        $this->assertSame('products', $result['type']);
        $this->assertSame(42, $result['session_id']);
        $this->assertSame([['id' => 1, 'name' => 'Paracetamol']], $result['products']);
    }

    /**
     * @param list<string>               $symptoms
     * @param list<array<string,mixed>>  $products
     */
    private function buildRouterForFinishWithProducts(bool $requireApproval, array $symptoms, array $products): \Modules\AIChat\Services\TriageRouter
    {
        // ai_pharmacy_settings reads: 1st call = auto_recommend (canRecommendProducts),
        // 2nd call = require_pharmacist_approval (requiresPharmacistApproval).
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('1', $requireApproval ? '1' : '0');

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $sessions = $this->createMock(\Modules\AIChat\Services\TriageSessionManager::class);
        $sessions->method('getCollectedSymptoms')->willReturn($symptoms);
        $sessions->expects($this->once())->method('complete');

        $recommender = $this->createMock(\Modules\AIChat\Services\ProductRecommender::class);
        $recommender->method('recommend')->willReturn($products);

        $notifier = $this->createMock(\Modules\AIChat\Services\PharmacistNotifier::class);

        $ref = new \ReflectionClass(\Modules\AIChat\Services\TriageRouter::class);
        $router = $ref->newInstanceWithoutConstructor();

        $set = function (string $prop, $value) use ($ref, $router): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($router, $value);
        };
        $set('pdo', $pdo);
        $set('lineAccountId', null);
        $set('sessions', $sessions);
        $set('recommender', $recommender);
        $set('notifier', $notifier);
        $set('audit', null);
        $set('currentUserId', 1);

        return $router;
    }
}
