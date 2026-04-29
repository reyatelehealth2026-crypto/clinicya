<?php
/**
 * Property-Based Test: SymptomMapper accuracy
 *
 * Property: For any Thai/English message containing a known symptom keyword,
 * SymptomMapper::mapMessageToCondition SHALL return the matching condition_code,
 * and hasSymptomSignal SHALL return true.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

class SymptomMapperPropertyTest extends TestCase
{
    /** @var \Modules\AIChat\Services\SymptomMapper */
    private $mapper;

    protected function setUp(): void
    {
        $this->mapper = new \Modules\AIChat\Services\SymptomMapper();
    }

    /**
     * Property: empty / whitespace input → null + hasSymptomSignal=false
     */
    public function testEmptyInputReturnsNull(): void
    {
        $this->assertNull($this->mapper->mapMessageToCondition(''));
        $this->assertNull($this->mapper->mapMessageToCondition('   '));
        $this->assertFalse($this->mapper->hasSymptomSignal(''));
    }

    /**
     * Property: keyword-laden Thai messages map to expected condition_code.
     *
     * @dataProvider provideThaiMessages
     */
    public function testThaiKeywordMessagesMapCorrectly(string $message, string $expected): void
    {
        $result = $this->mapper->mapMessageToCondition($message);
        $this->assertSame(
            $expected,
            $result,
            "message='$message' should map to '$expected', got '" . ($result ?? 'null') . "'"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function provideThaiMessages(): array
    {
        return [
            'fever direct'    => ['มีไข้สูงมา 2 วัน',                  'fever'],
            'fever variant'   => ['ตัวร้อน อุณหภูมิสูง',                'fever'],
            'cold runny nose' => ['เป็นหวัด น้ำมูกใส คัดจมูก',          'common_cold'],
            'cough'           => ['ไอแห้งมาตลอด',                      'cough'],
            'headache'        => ['ปวดหัวมาก ปวดศีรษะข้างเดียว',        'headache'],
            'gi diarrhea'     => ['ปวดท้อง ท้องเสียถ่ายเหลว',           'gi'],
            'allergy'         => ['ภูมิแพ้ ผื่นแพ้ คันตา',              'allergy'],
            'skin rash'       => ['ผื่นที่แขน ผิวหนังอักเสบ',           'skin_rash'],
            'insomnia'        => ['นอนไม่หลับมา 1 สัปดาห์',             'insomnia'],
        ];
    }

    /**
     * Property: hasSymptomSignal aligns with mapMessageToCondition.
     *
     * @dataProvider provideThaiMessages
     */
    public function testHasSymptomSignalIsTrueForKnownKeywords(string $message): void
    {
        $this->assertTrue($this->mapper->hasSymptomSignal($message));
    }

    /**
     * Property: ข้อความไม่เกี่ยวกับสุขภาพ → ไม่ match
     */
    public function testNonSymptomMessagesReturnNull(): void
    {
        $samples = [
            'สวัสดีครับ',
            'ของยังไม่ส่ง',
            'พรุ่งนี้ฝนตกไหม',
            'ขอบคุณค่ะ',
            'hello world',
        ];
        foreach ($samples as $s) {
            $this->assertNull(
                $this->mapper->mapMessageToCondition($s),
                "message '$s' should not map"
            );
            $this->assertFalse(
                $this->mapper->hasSymptomSignal($s),
                "message '$s' should not have signal"
            );
        }
    }

    /**
     * Property: mapAllConditions returns multi-symptom set
     */
    public function testMapAllConditionsCapturesMultipleSymptoms(): void
    {
        $multi = $this->mapper->mapAllConditions('มีไข้ + ไอ + น้ำมูก');
        $this->assertContains('fever', $multi);
        $this->assertContains('cough', $multi);
        $this->assertContains('common_cold', $multi);
    }

    /**
     * Property: more specific keyword (longer) wins over generic short keyword
     */
    public function testSpecificKeywordPrioritized(): void
    {
        $result = $this->mapper->mapMessageToCondition('นอนไม่หลับ ปวดเล็กน้อย');
        $this->assertSame('insomnia', $result);
    }
}
