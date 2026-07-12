<?php
/**
 * Property-Based Test: Consultation Audit Hash Chain
 *
 * **Feature: telepharmacy-audit-trail (issue #15)**
 *
 * The consultation_audit trail must be tamper-evident: each row hashes the
 * previous row's content_hash (SHA-256 chain). These properties prove the
 * pure hashing core that makes any later edit/deletion detectable, without
 * needing a database.
 *
 * Properties:
 *  1. Determinism    — same inputs always yield the same hash.
 *  2. Canonicalisation — key order does not change the hash (JSON re-ordering
 *     by MySQL must not break verification).
 *  3. Tamper-evidence — changing ANY field changes the hash.
 *  4. Chain integrity — a valid chain verifies; mutating one link fails.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

use Modules\AIChat\Services\ConsultationAudit;

class ConsultationAuditHashChainPropertyTest extends TestCase
{
    private const ITERATIONS = 150;

    private const EVENT_TYPES = [
        'triage_question', 'ai_recommendation', 'red_flag', 'escalation',
        'pharmacist_approve', 'consent_granted', 'consent_missing',
    ];
    private const ACTOR_TYPES = ['customer', 'ai', 'pharmacist', 'system'];

    /** @return array<string,mixed> */
    private function randomPayload(): array
    {
        $payload = [];
        $n = mt_rand(0, 5);
        for ($i = 0; $i < $n; $i++) {
            $key = 'k' . mt_rand(0, 20);
            $type = mt_rand(0, 3);
            $payload[$key] = match ($type) {
                0 => mt_rand(-1000, 1000),
                1 => 'v' . mt_rand(0, 9999),
                2 => (bool) mt_rand(0, 1),
                default => ['nested' => mt_rand(0, 99), 'sym' => ['a' . mt_rand(0, 9), 'b' . mt_rand(0, 9)]],
            };
        }
        return $payload;
    }

    private function randomTs(): string
    {
        return sprintf(
            '2026-%02d-%02d %02d:%02d:%02d.%06d',
            mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59), mt_rand(0, 999999)
        );
    }

    /** Property 1: same inputs → same hash (deterministic), and it's a 64-char hex sha256. */
    public function testHashIsDeterministicAndWellFormed(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $prev    = mt_rand(0, 1) ? hash('sha256', 'prev' . $i) : null;
            $event   = self::EVENT_TYPES[array_rand(self::EVENT_TYPES)];
            $actor   = self::ACTOR_TYPES[array_rand(self::ACTOR_TYPES)];
            $actorId = mt_rand(0, 1) ? mt_rand(1, 9999) : null;
            $canon   = ConsultationAudit::canonicalize($this->randomPayload());
            $ts      = $this->randomTs();

            $h1 = ConsultationAudit::computeHash($prev, $event, $actor, $actorId, $canon, $ts);
            $h2 = ConsultationAudit::computeHash($prev, $event, $actor, $actorId, $canon, $ts);

            $this->assertSame($h1, $h2, 'hash must be deterministic');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1, 'hash must be sha256 hex');
        }
    }

    /** Property 2: canonicalisation is key-order independent → hash stable under key reordering. */
    public function testCanonicalizationIsKeyOrderIndependent(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payload = $this->randomPayload();
            if (count($payload) < 2) {
                $payload = ['b' => 1, 'a' => 2, 'c' => ['z' => 1, 'y' => 2]];
            }
            $shuffled = $payload;
            $keys = array_keys($shuffled);
            shuffle($keys);
            $reordered = [];
            foreach ($keys as $k) {
                $reordered[$k] = $shuffled[$k];
            }

            $this->assertSame(
                ConsultationAudit::canonicalize($payload),
                ConsultationAudit::canonicalize($reordered),
                'canonical form must ignore associative key order'
            );
        }
    }

    /** Property 3: changing any single field changes the hash. */
    public function testTamperingAnyFieldChangesHash(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $prev    = hash('sha256', 'p' . $i);
            $event   = 'triage_question';
            $actor   = 'ai';
            $actorId = 42;
            $canon   = ConsultationAudit::canonicalize(['q' => mt_rand(1, 100)]);
            $ts      = $this->randomTs();
            $base    = ConsultationAudit::computeHash($prev, $event, $actor, $actorId, $canon, $ts);

            $this->assertNotSame($base, ConsultationAudit::computeHash($prev . 'x', $event, $actor, $actorId, $canon, $ts));
            $this->assertNotSame($base, ConsultationAudit::computeHash($prev, 'escalation', $actor, $actorId, $canon, $ts));
            $this->assertNotSame($base, ConsultationAudit::computeHash($prev, $event, 'pharmacist', $actorId, $canon, $ts));
            $this->assertNotSame($base, ConsultationAudit::computeHash($prev, $event, $actor, $actorId + 1, $canon, $ts));
            $this->assertNotSame($base, ConsultationAudit::computeHash($prev, $event, $actor, $actorId, $canon . ' ', $ts));
            $this->assertNotSame($base, ConsultationAudit::computeHash($prev, $event, $actor, $actorId, $canon, $ts . '1'));
        }
    }

    /**
     * Property 4: a chain built by linking prev_hash → content_hash verifies;
     * mutating the payload of any middle link invalidates the recomputed hash
     * (mirrors ConsultationAudit::verifyChain without a DB).
     */
    public function testChainIntegrityAndBreakOnMutation(): void
    {
        for ($iter = 0; $iter < 40; $iter++) {
            $len = mt_rand(3, 8);
            $rows = [];
            $prev = null;
            for ($j = 0; $j < $len; $j++) {
                $canon = ConsultationAudit::canonicalize($this->randomPayload() + ['seq' => $j]);
                $ts = $this->randomTs();
                $event = self::EVENT_TYPES[array_rand(self::EVENT_TYPES)];
                $hash = ConsultationAudit::computeHash($prev, $event, 'ai', null, $canon, $ts);
                $rows[] = ['prev' => $prev, 'event' => $event, 'canon' => $canon, 'ts' => $ts, 'hash' => $hash];
                $prev = $hash;
            }

            $this->assertTrue($this->verify($rows), 'freshly built chain must verify');

            // Mutate a random middle row's canonical payload → chain must fail.
            $victim = mt_rand(0, $len - 1);
            $rows[$victim]['canon'] .= '/*tamper*/';
            $this->assertFalse($this->verify($rows), 'mutated chain must fail verification');
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function verify(array $rows): bool
    {
        $expectedPrev = null;
        foreach ($rows as $row) {
            if ($row['prev'] !== $expectedPrev) {
                return false;
            }
            $recomputed = ConsultationAudit::computeHash(
                $row['prev'], (string) $row['event'], 'ai', null, (string) $row['canon'], (string) $row['ts']
            );
            if (!hash_equals((string) $row['hash'], $recomputed)) {
                return false;
            }
            $expectedPrev = $row['hash'];
        }
        return true;
    }
}
