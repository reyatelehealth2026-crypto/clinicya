<?php
/**
 * TriageQuestionEngine — ดึงคำถาม Yes/No ถัดไปจาก triage_questions
 *
 * Lookup priority: line_account_id เฉพาะร้าน → fallback NULL (global default)
 * เพื่อให้ admin override default ได้ต่อ tenant.
 */

namespace Modules\AIChat\Services;

class TriageQuestionEngine
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงคำถามถัดไปสำหรับ condition + ข้ามคำถามที่ถามไปแล้ว.
     *
     * @param list<int> $askedIds
     * @return array<string, mixed>|null
     */
    public function getNextQuestion(?int $lineAccountId, string $conditionCode, array $askedIds): ?array
    {
        $askedIds = array_values(array_filter(array_map('intval', $askedIds), static fn($v) => $v > 0));

        if ($lineAccountId !== null) {
            $row = $this->fetchNextForAccount($lineAccountId, $conditionCode, $askedIds);
            if ($row !== null) {
                return $this->normalizeRow($row);
            }
        }

        $row = $this->fetchNextForAccount(null, $conditionCode, $askedIds);
        return $row !== null ? $this->normalizeRow($row) : null;
    }

    /**
     * ดึงคำถามตาม id โดยตรง — ใช้กับ next_if_yes / next_if_no.
     *
     * @return array<string, mixed>|null
     */
    public function getQuestionById(int $questionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM triage_questions WHERE id = :id AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $questionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    /**
     * @param list<int> $askedIds
     * @return array<string, mixed>|null
     */
    private function fetchNextForAccount(?int $lineAccountId, string $conditionCode, array $askedIds): ?array
    {
        $accCondition = $lineAccountId === null ? 'line_account_id IS NULL' : 'line_account_id = :acc';

        // Defensive dedup: ดึง candidates หลายแถวก่อน แล้วใน PHP คัดให้เหลือ
        // text เดียวต่อ question_th — กันถามซ้ำแม้ DB มี duplicate rows ค้างอยู่
        $sql = "SELECT * FROM triage_questions
                WHERE $accCondition
                  AND condition_code = :cond
                  AND is_active = 1";

        if (!empty($askedIds)) {
            $placeholders = [];
            foreach ($askedIds as $i => $_) {
                $placeholders[] = ':a' . $i;
            }
            $sql .= ' AND id NOT IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC LIMIT 50';

        $stmt = $this->pdo->prepare($sql);
        if ($lineAccountId !== null) {
            $stmt->bindValue(':acc', $lineAccountId, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':cond', $conditionCode, \PDO::PARAM_STR);
        foreach ($askedIds as $i => $qid) {
            $stmt->bindValue(':a' . $i, $qid, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // คัดข้อความที่ถามไปแล้ว (อิงจาก question_th ของ askedIds)
        // และกันคำถามที่ candidate-set เองมี text ซ้ำกันด้วย — เลือก id เล็กสุดของ text นั้น
        $askedTexts = $this->fetchAskedTextsByIds($askedIds);
        $seenTexts = [];
        foreach ($rows as $row) {
            $qth = trim((string) ($row['question_th'] ?? ''));
            $key = $this->normalizeQuestionText($qth);
            if ($key === '') continue;
            if (in_array($key, $askedTexts, true)) continue;
            if (in_array($key, $seenTexts, true)) continue;
            $seenTexts[] = $key;
            return $row;
        }
        return null;
    }

    /**
     * ดึง normalized text ของคำถามที่ id อยู่ใน askedIds
     * @param list<int> $askedIds
     * @return list<string>
     */
    private function fetchAskedTextsByIds(array $askedIds): array
    {
        if (empty($askedIds)) return [];
        $placeholders = [];
        foreach ($askedIds as $i => $_) { $placeholders[] = ':b' . $i; }
        $sql = "SELECT question_th FROM triage_questions WHERE id IN (" . implode(',', $placeholders) . ")";
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($askedIds as $i => $qid) {
                $stmt->bindValue(':b' . $i, (int) $qid, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $texts = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($texts as $t) {
            $n = $this->normalizeQuestionText((string) $t);
            if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    /** เทียบข้อความแบบ tolerant — strip whitespace, "?" ปลายประโยค, "ไหม" trailing */
    private function normalizeQuestionText(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/[\s\?\.\!]+$/u', '', $t) ?? $t;
        $t = preg_replace('/ไหม$/u', '', $t) ?? $t;
        return mb_strtolower(trim($t));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $options = [];
        $type = (string) ($row['answer_type'] ?? 'yes_no');

        if ($type === 'yes_no') {
            $options = [
                ['value' => 'yes', 'label' => 'ใช่'],
                ['value' => 'no',  'label' => 'ไม่ใช่'],
            ];
        } elseif ($type === 'scale_1_10') {
            for ($i = 1; $i <= 10; $i++) {
                $options[] = ['value' => (string) $i, 'label' => (string) $i];
            }
        } elseif ($type === 'multi_choice' && !empty($row['options_json'])) {
            $decoded = json_decode((string) $row['options_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $opt) {
                    if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                        $options[] = ['value' => (string) $opt['value'], 'label' => (string) $opt['label']];
                    }
                }
            }
        }

        $symptomCodes = [];
        if (!empty($row['recommend_symptom_codes'])) {
            $symptomCodes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $row['recommend_symptom_codes'])
            ), static fn($v) => $v !== ''));
        }

        return [
            'id'                      => (int) ($row['id'] ?? 0),
            'condition_code'          => (string) ($row['condition_code'] ?? ''),
            'question_th'             => (string) ($row['question_th'] ?? ''),
            'question_en'             => (string) ($row['question_en'] ?? ''),
            'answer_type'             => $type,
            'options'                 => $options,
            'next_if_yes'             => isset($row['next_if_yes']) && $row['next_if_yes'] !== null ? (int) $row['next_if_yes'] : null,
            'next_if_no'              => isset($row['next_if_no']) && $row['next_if_no'] !== null ? (int) $row['next_if_no'] : null,
            'red_flag_if_yes'         => !empty($row['red_flag_if_yes']),
            'recommend_symptom_codes' => $symptomCodes,
        ];
    }
}
