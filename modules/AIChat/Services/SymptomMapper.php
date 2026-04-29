<?php
/**
 * SymptomMapper — แปลงข้อความ free-text ของผู้ใช้ให้กลายเป็น condition_code
 * ที่ใช้กับ triage_questions
 *
 * วิธี: keyword/regex dictionary (Thai + บางคำอังกฤษ).
 * Trade-off ที่จงใจ: เลือก dictionary เพื่อให้ตรวจสอบและเทียบกับยา/อาการได้
 * (ไม่ใช้ embedding) — เภสัชกรแก้ keyword ได้โดยไม่ต้องเทรนโมเดลใหม่.
 */

namespace Modules\AIChat\Services;

class SymptomMapper
{
    /**
     * @var array<string, list<string>> map ของ condition_code → keyword patterns
     * Order สำคัญ: ตัวที่มี match score สูงกว่าจะถูกเลือก
     */
    private const DICTIONARY = [
        'fever'        => ['ไข้', 'ตัวร้อน', 'ครั่นเนื้อ', 'fever', 'อุณหภูมิสูง'],
        'common_cold'  => ['หวัด', 'น้ำมูก', 'คัดจมูก', 'จาม', 'เจ็บคอ', 'cold', 'flu'],
        'cough'        => ['ไอ', 'cough', 'เสมหะ', 'ไอแห้ง', 'ไอมีเสมหะ'],
        'headache'     => ['ปวดหัว', 'ปวดศีรษะ', 'ไมเกรน', 'migraine', 'headache'],
        'gi'           => ['ปวดท้อง', 'ท้องเสีย', 'ถ่ายเหลว', 'อาเจียน', 'คลื่นไส้', 'ท้องอืด', 'diarrhea'],
        'allergy'      => ['แพ้', 'ภูมิแพ้', 'ลมพิษ', 'ผื่นแพ้', 'allergy', 'คันตา', 'จามบ่อย'],
        'skin_rash'    => ['ผื่น', 'ผิวหนังอักเสบ', 'ผิวลอก', 'eczema', 'rash'],
        'insomnia'     => ['นอนไม่หลับ', 'หลับยาก', 'insomnia', 'นอนหลับ ๆ ตื่น ๆ'],
        'pain_general' => ['ปวด', 'เจ็บ', 'ปวดเมื่อย', 'ปวดข้อ', 'ปวดกล้ามเนื้อ'],
    ];

    /**
     * ตรวจจับ condition_code จากข้อความ. คืน null ถ้าไม่เจอสัญญาณ symptom.
     */
    public function mapMessageToCondition(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        $bestCode = null;
        $bestScore = 0;

        foreach (self::DICTIONARY as $code => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if ($kw === '') {
                    continue;
                }
                if (mb_stripos($text, mb_strtolower($kw)) !== false) {
                    // คำเฉพาะทาง (>= 4 ตัว) ให้น้ำหนักมากกว่าคำกว้าง
                    $score += mb_strlen($kw) >= 4 ? 2 : 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCode = $code;
            }
        }

        return $bestScore > 0 ? $bestCode : null;
    }

    /**
     * หา condition_codes ทั้งหมดที่ match (multi-symptom case).
     *
     * @return list<string>
     */
    public function mapAllConditions(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $matched = [];
        foreach (self::DICTIONARY as $code => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_stripos($text, mb_strtolower($kw)) !== false) {
                    $matched[] = $code;
                    break;
                }
            }
        }
        return array_values(array_unique($matched));
    }

    /**
     * ตรวจว่าข้อความมี "สัญญาณ symptom" ใด ๆ — ใช้เป็น gate ก่อนเข้า triage flow.
     */
    public function hasSymptomSignal(string $text): bool
    {
        return $this->mapMessageToCondition($text) !== null;
    }
}
