<?php
/**
 * DataRightsService — PDPA data-subject-rights tests
 *
 * **Feature: pdpa-self-service-data-rights (phase-0 / compliance)**
 *
 * These cover the DB-free / pure core of the customer self-service PDPA flow
 * (withdraw consent, request deletion, export data) — proving the security &
 * shape guarantees without needing a database:
 *
 *  1. Confirmation code — well-formed (REYA-DEL-XXXXXXXX), unambiguous alphabet,
 *     and effectively unique across many draws.
 *  2. Export shape — required top-level keys present; profile is whitelisted so
 *     no unexpected/other-user columns leak; consult history, consents and
 *     orders are exactly what was passed in (no cross-user mixing).
 *  3. request_deletion is a SOFT flag — the generated SQL is UPDATE-only; the
 *     service NEVER emits a `DELETE FROM users` statement.
 *  4. Validation — resolveUser rejects empty/whitespace line_user_id without a
 *     DB round-trip.
 */

namespace Tests\PDPA;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../../modules/PDPA/Services/DataRightsService.php';

use Modules\PDPA\Services\DataRightsService;

class DataRightsServiceTest extends TestCase
{
    private const ITERATIONS = 200;

    // ── 1) Confirmation code ────────────────────────────────────────

    public function testConfirmationCodeIsWellFormed(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $code = DataRightsService::generateConfirmationCode();
            // REYA-DEL- + 8 chars from the unambiguous alphabet (no 0/O/1/I).
            $this->assertMatchesRegularExpression(
                '/^REYA-DEL-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/',
                $code,
                'confirmation code must be REYA-DEL- + 8 unambiguous chars'
            );
        }
    }

    public function testConfirmationCodesAreEffectivelyUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $seen[DataRightsService::generateConfirmationCode()] = true;
        }
        // 31^8 space → 500 draws colliding would be astronomically unlikely.
        $this->assertCount(500, $seen, 'confirmation codes must not collide across 500 draws');
    }

    // ── 2) Export shape ─────────────────────────────────────────────

    public function testExportShapeHasRequiredKeysAndNoLeakage(): void
    {
        $userRow = [
            'id'            => 42,
            'line_user_id'  => 'Uowner',
            'display_name'  => 'Owner',
            'phone'         => '0810000000',
            'email'         => 'owner@example.com',
            // Sensitive/internal columns that MUST NOT appear in the export:
            'reply_token'         => 'SECRET-TOKEN',
            'reply_token_expires' => '2026-01-01 00:00:00',
            'is_blocked'          => 1,
            'customer_score'      => 99,
        ];
        $consents    = [['consent_type' => 'health_data', 'is_accepted' => 1]];
        $consentLogs = [['consent_type' => 'health_data', 'action' => 'withdraw']];
        $chat        = [['role' => 'user', 'content' => 'มีอาการปวดหัว']];
        $orders      = [['id' => 7, 'order_number' => 'ORD-7', 'total_amount' => '100.00']];

        $export = DataRightsService::buildExportShape($userRow, $consents, $consentLogs, $chat, $orders);

        foreach (['export_meta', 'profile', 'consents', 'consent_history', 'chat_history', 'orders'] as $key) {
            $this->assertArrayHasKey($key, $export, "export must contain '$key'");
        }

        // Whitelist enforced: safe fields kept, internal fields stripped.
        $this->assertArrayHasKey('email', $export['profile']);
        $this->assertArrayHasKey('phone', $export['profile']);
        $this->assertArrayNotHasKey('reply_token', $export['profile'], 'reply_token must never be exported');
        $this->assertArrayNotHasKey('reply_token_expires', $export['profile']);
        $this->assertArrayNotHasKey('is_blocked', $export['profile']);
        $this->assertArrayNotHasKey('customer_score', $export['profile']);

        // Passed-in data is echoed back verbatim (no mixing / no drop).
        $this->assertSame($consents, $export['consents']);
        $this->assertSame($consentLogs, $export['consent_history']);
        $this->assertSame($chat, $export['chat_history']);
        $this->assertSame($orders, $export['orders']);
        $this->assertSame(42, $export['export_meta']['user_id']);
    }

    public function testExportNeverContainsOtherUsersData(): void
    {
        // Only the resolved owner's rows are ever passed to buildExportShape.
        // Prove the shape does not invent/borrow foreign rows: given ONLY the
        // owner's data, nothing else appears.
        $owner = ['id' => 1, 'line_user_id' => 'Uowner', 'display_name' => 'Owner'];
        $export = DataRightsService::buildExportShape($owner, [], [], [], []);

        $this->assertSame([], $export['consents']);
        $this->assertSame([], $export['chat_history']);
        $this->assertSame([], $export['orders']);
        $this->assertSame('Owner', $export['profile']['display_name']);
        $this->assertSame(1, $export['export_meta']['user_id']);
    }

    public function testNormaliseUserProfileOnlyKeepsWhitelistedKeys(): void
    {
        $row = [
            'id' => 5, 'email' => 'a@b.co', 'reply_token' => 'x', 'unread_count' => 3,
            'medical_conditions' => 'เบาหวาน', 'internal_secret' => 'nope',
        ];
        $profile = DataRightsService::normaliseUserProfile($row);

        $this->assertSame(['id' => 5, 'email' => 'a@b.co', 'medical_conditions' => 'เบาหวาน'], $profile);
        $this->assertArrayNotHasKey('reply_token', $profile);
        $this->assertArrayNotHasKey('unread_count', $profile);
        $this->assertArrayNotHasKey('internal_secret', $profile);
    }

    // ── 3) request_deletion is a SOFT flag (UPDATE-only, never DELETE) ─

    public function testMarkForDeletionOnlyUpdatesNeverDeletes(): void
    {
        $pdo = new RecordingPdo();

        // Build the service without invoking the real (PDO type-hinted) ctor,
        // then inject our recording fake via reflection.
        $ref = new ReflectionClass(DataRightsService::class);
        /** @var DataRightsService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);
        $accProp = $ref->getProperty('lineAccountId');
        $accProp->setAccessible(true);
        $accProp->setValue($service, 3);

        $code = $service->markForDeletion(42, 'Uowner', 'ไม่อยากใช้แล้ว', '127.0.0.1', 'jest');

        $this->assertMatchesRegularExpression('/^REYA-DEL-[A-Z2-9]{8}$/', $code);

        // Every statement the service issued:
        $sqlJoined = strtoupper(implode("\n", $pdo->statements));

        // MUST update the soft flag on users, and insert a request row.
        $this->assertStringContainsString('UPDATE USERS', $sqlJoined, 'must UPDATE users (soft flag)');
        $this->assertStringContainsString("DELETION_STATUS = 'REQUESTED'", $sqlJoined);
        $this->assertStringContainsString('INSERT INTO DATA_DELETION_REQUESTS', $sqlJoined);

        // MUST NEVER hard-delete the user (or any table).
        $this->assertStringNotContainsString('DELETE FROM USERS', $sqlJoined, 'must NEVER hard-delete users');
        $this->assertStringNotContainsString('DELETE FROM ', $sqlJoined, 'request_deletion must never emit any DELETE');
        $this->assertStringNotContainsString('DROP ', $sqlJoined);
        $this->assertStringNotContainsString('TRUNCATE', $sqlJoined);
    }

    // ── 4) Validation ───────────────────────────────────────────────

    public function testResolveUserRejectsEmptyIdentityWithoutDb(): void
    {
        // A fake that would EXPLODE if the service tried to touch the DB — proves
        // empty/whitespace ids short-circuit before any query.
        $pdo = new ExplodingPdo();
        $ref = new ReflectionClass(DataRightsService::class);
        /** @var DataRightsService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);
        $accProp = $ref->getProperty('lineAccountId');
        $accProp->setAccessible(true);
        $accProp->setValue($service, null);

        $this->assertNull($service->resolveUser(null));
        $this->assertNull($service->resolveUser(''));
        $this->assertNull($service->resolveUser('   '));
    }
}

/**
 * Minimal PDO fake that records every SQL string prepared/executed and never
 * touches a database. Enough for markForDeletion (begin/prepare/execute/commit).
 */
class RecordingPdo extends \PDO
{
    /** @var array<int,string> */
    public array $statements = [];

    public function __construct()
    {
        // Intentionally do NOT call parent::__construct — no real connection.
    }

    #[\ReturnTypeWillChange]
    public function beginTransaction(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function commit(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function rollBack(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function exec($statement): int
    {
        $this->statements[] = (string) $statement;
        return 0;
    }

    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        // ensureDeletionSchema probes with SELECT ... — pretend it exists so no
        // ALTER/CREATE fallback fires.
        $this->statements[] = (string) $statement;
        return new RecordingStatement('');
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->statements[] = (string) $query;
        return new RecordingStatement((string) $query);
    }
}

class RecordingStatement extends \PDOStatement
{
    private string $sql;

    public function __construct(string $sql)
    {
        $this->sql = $sql;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetch($mode = \PDO::FETCH_DEFAULT, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        return false;
    }

    #[\ReturnTypeWillChange]
    public function fetchAll($mode = \PDO::FETCH_DEFAULT, ...$args)
    {
        return [];
    }
}

/**
 * PDO fake whose every method throws — used to prove a code path NEVER touches
 * the database.
 */
class ExplodingPdo extends \PDO
{
    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        throw new \RuntimeException('DB must not be touched for empty identity');
    }

    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        throw new \RuntimeException('DB must not be touched for empty identity');
    }
}
