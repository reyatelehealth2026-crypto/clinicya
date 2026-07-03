<?php
/**
 * Property-Based Test: Structured SSE event contract
 *
 * **Feature: aichat-sse-consistency (Phase 1 · Workstream A, item 3)**
 *
 * api/ai-chat.php emits every structured event as {"structured": <builder()>}.
 * These builders (in includes/ai-chat-context.php) are the single source of
 * truth for the wire contract consumed by line-mini-app. These properties pin
 * the exact shapes so the safety-critical emergency / drug-interaction cards
 * cannot silently drift between backend and client.
 *
 * Contract mirror: line-mini-app/src/types/ai-chat.ts
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ai-chat-context.php';

class StructuredEventContractPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /** state event: exact keys + discriminator. */
    public function testStateEventShape(): void
    {
        $e = aiChatBuildStateEvent('escalate', 'ส่งต่อเภสัชกร');
        $this->assertSame(['type', 'state', 'label_th'], array_keys($e));
        $this->assertSame('state', $e['type']);
        $this->assertSame('escalate', $e['state']);
        $this->assertSame('ส่งต่อเภสัชกร', $e['label_th']);
    }

    /**
     * emergency event: fixed keys, severity normalised to critical|warning,
     * symptoms filtered + re-indexed (a valid JSON array), recommendation trimmed.
     */
    public function testEmergencyEventShapeAndNormalisation(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $symptoms = [];
            $actions = [];
            $n = mt_rand(0, 5);
            for ($j = 0; $j < $n; $j++) {
                // Inject empties to exercise filtering.
                $symptoms[] = mt_rand(0, 2) === 0 ? '' : 'sym' . mt_rand(0, 99);
                $actions[] = mt_rand(0, 2) === 0 ? '' : 'act' . mt_rand(0, 99);
            }
            $sev = ['critical', 'warning', 'bogus', ''][array_rand(['critical', 'warning', 'bogus', ''])];

            $e = aiChatBuildEmergencyEvent($symptoms, $actions, $sev);

            $this->assertSame(['type', 'severity', 'symptoms', 'recommendation'], array_keys($e));
            $this->assertSame('emergency', $e['type']);
            $this->assertContains($e['severity'], ['critical', 'warning'], 'severity must be normalised');
            if ($sev !== 'warning') {
                $this->assertSame('critical', $e['severity'], 'non-warning severity defaults to critical');
            }

            // symptoms: no empties, and a proper list (0..n-1 keys) => encodes as JSON array
            $this->assertSame(array_values($e['symptoms']), $e['symptoms']);
            foreach ($e['symptoms'] as $s) {
                $this->assertNotSame('', $s);
            }

            // recommendation: trimmed, and never contains an empty joined segment (no "\n\n")
            $this->assertSame(trim($e['recommendation']), $e['recommendation']);
            $this->assertStringNotContainsString("\n\n", $e['recommendation']);
        }
    }

    /** drug_interactions event: fixed keys, warnings re-indexed to a JSON array. */
    public function testDrugInteractionsEventShape(): void
    {
        // Non-contiguous keys must be re-indexed so JSON encodes an array, not an object.
        $warnings = [5 => ['product' => 'A'], 9 => ['product' => 'B']];
        $e = aiChatBuildDrugInteractionsEvent($warnings);
        $this->assertSame(['type', 'warnings'], array_keys($e));
        $this->assertSame('drug_interactions', $e['type']);
        $this->assertSame([['product' => 'A'], ['product' => 'B']], $e['warnings']);

        $empty = aiChatBuildDrugInteractionsEvent([]);
        $this->assertSame([], $empty['warnings']);
    }

    /** user_context event: has_* flags reflect the filtered lists. */
    public function testUserContextEventFlags(): void
    {
        $withData = aiChatBuildUserContextEvent([
            'display_name'        => 'Somchai',
            'drug_allergies'      => [['drug_name' => 'penicillin']],
            'current_medications' => [['medication_name' => 'metformin']],
        ]);
        $this->assertSame('user_context', $withData['type']);
        $this->assertTrue($withData['has_allergies']);
        $this->assertTrue($withData['has_medications']);

        $empty = aiChatBuildUserContextEvent([]);
        $this->assertFalse($empty['has_allergies']);
        $this->assertFalse($empty['has_medications']);
        $this->assertSame([], $empty['allergies']);
    }

    /** Every builder's discriminator is a type the mini-app union knows. */
    public function testAllTypesAreKnownToClient(): void
    {
        $known = ['state', 'emergency', 'drug_interactions', 'user_context'];
        $events = [
            aiChatBuildStateEvent('recommend', 'แนะนำ'),
            aiChatBuildEmergencyEvent(['x'], ['y']),
            aiChatBuildDrugInteractionsEvent([]),
            aiChatBuildUserContextEvent([]),
        ];
        foreach ($events as $e) {
            $this->assertContains($e['type'], $known);
        }
    }
}
