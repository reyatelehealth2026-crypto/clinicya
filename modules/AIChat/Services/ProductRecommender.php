<?php
/**
 * ProductRecommender — Hybrid product recommender
 *
 * Step 1 (rule-based): JOIN product_symptom_map x products สำหรับ symptom codes ที่ผู้ใช้มี
 *   filter ปลอดภัย: requires_prescription=0, ai_recommendable=1, is_active=1
 *   prefer line_account_id เฉพาะร้าน → fallback global (NULL)
 *
 * Step 2 (AI re-rank, optional): ถ้ามี gemini key ให้ Gemini เลือก top N พร้อมเหตุผล
 *   ถ้า Gemini พังหรือไม่มี key → ใช้ผลจาก step 1 (graceful degrade)
 */

namespace Modules\AIChat\Services;

class ProductRecommender
{
    private \PDO $pdo;

    /** @var list<string> */
    private array $geminiKeys;

    /**
     * @param list<string> $geminiKeys
     */
    public function __construct(\PDO $pdo, array $geminiKeys = [])
    {
        $this->pdo = $pdo;
        $this->geminiKeys = array_values(array_filter($geminiKeys, static fn($k) => is_string($k) && trim($k) !== ''));
    }

    /**
     * @param list<string> $symptomCodes
     * @param list<string> $userAllergies
     * @return list<array<string, mixed>>
     */
    public function recommend(?int $lineAccountId, array $symptomCodes, array $userAllergies = [], int $limit = 5): array
    {
        $symptomCodes = array_values(array_filter(array_map('strval', $symptomCodes), static fn($v) => $v !== ''));
        if (empty($symptomCodes)) {
            return [];
        }

        $candidates = $this->lookupCandidates($lineAccountId, $symptomCodes, 20);

        if (!empty($userAllergies)) {
            $candidates = $this->filterAllergies($candidates, $userAllergies);
        }

        if (empty($candidates)) {
            return [];
        }

        $reranked = $this->aiRerank($candidates, $symptomCodes, $userAllergies, $limit);
        if (!empty($reranked)) {
            return $reranked;
        }

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Public for settings preview / sandbox use.
     *
     * @param list<string> $symptomCodes
     * @return list<array<string, mixed>>
     */
    public function lookupCandidates(?int $lineAccountId, array $symptomCodes, int $maxRows): array
    {
        $symptomCodes = array_values(array_filter(array_map('strval', $symptomCodes), static fn($v) => $v !== ''));
        if (empty($symptomCodes)) {
            return [];
        }

        $placeholders = [];
        foreach ($symptomCodes as $i => $_) {
            $placeholders[] = ':sc' . $i;
        }
        // ระบบใช้ business_items เป็นตารางสินค้าหลัก
        // ใช้ COALESCE เผื่อคอลัมน์ pharmacy ยังไม่ถูก migrate
        $sql = "SELECT
                    p.id, p.name, p.description, p.price, p.sale_price, p.image_url,
                    psm.symptom_code, psm.weight, psm.is_first_line, psm.notes
                FROM product_symptom_map psm
                INNER JOIN business_items p ON p.id = psm.product_id
                WHERE psm.symptom_code IN (" . implode(',', $placeholders) . ")
                  AND (psm.line_account_id = :acc OR psm.line_account_id IS NULL)
                  AND p.is_active = 1
                ORDER BY
                    (psm.line_account_id IS NOT NULL) DESC,
                    psm.is_first_line DESC,
                    psm.weight DESC
                LIMIT " . (int) $maxRows;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':acc', $lineAccountId, $lineAccountId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        foreach ($symptomCodes as $i => $code) {
            $stmt->bindValue(':sc' . $i, $code, \PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $bestPerProduct = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            if (!isset($bestPerProduct[$pid])) {
                $bestPerProduct[$pid] = [
                    'id'                  => $pid,
                    'name'                => (string) ($r['name'] ?? ''),
                    'description'         => (string) ($r['description'] ?? ''),
                    'price'               => (float) ($r['price'] ?? 0),
                    'sale_price'          => $r['sale_price'] !== null ? (float) $r['sale_price'] : null,
                    'image_url'           => (string) ($r['image_url'] ?? ''),
                    'drug_type'           => '',
                    'requires_pharmacist' => false,
                    'active_ingredient'   => '',
                    'strength'             => '',
                    'dosage_form'         => '',
                    'usage_instructions'  => '',
                    'matched_symptoms'    => [],
                    'best_weight'         => 0,
                    'is_first_line'       => false,
                    'notes'               => '',
                ];
            }
            $bestPerProduct[$pid]['matched_symptoms'][] = (string) $r['symptom_code'];
            if ((int) $r['weight'] > $bestPerProduct[$pid]['best_weight']) {
                $bestPerProduct[$pid]['best_weight'] = (int) $r['weight'];
            }
            if (!empty($r['is_first_line'])) {
                $bestPerProduct[$pid]['is_first_line'] = true;
            }
            if (!empty($r['notes']) && empty($bestPerProduct[$pid]['notes'])) {
                $bestPerProduct[$pid]['notes'] = (string) $r['notes'];
            }
        }
        return array_values($bestPerProduct);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<string> $allergies
     * @return list<array<string, mixed>>
     */
    private function filterAllergies(array $candidates, array $allergies): array
    {
        $allergyTokens = [];
        foreach ($allergies as $a) {
            $a = trim(mb_strtolower((string) $a));
            if ($a !== '' && $a !== 'ไม่แพ้' && $a !== 'ไม่มี' && $a !== 'none' && $a !== 'no') {
                $allergyTokens[] = $a;
            }
        }
        if (empty($allergyTokens)) {
            return $candidates;
        }

        return array_values(array_filter($candidates, static function (array $c) use ($allergyTokens): bool {
            $haystack = mb_strtolower((string) ($c['active_ingredient'] ?? '')) . ' '
                      . mb_strtolower((string) ($c['name'] ?? ''));
            foreach ($allergyTokens as $tok) {
                if (mb_stripos($haystack, $tok) !== false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<string> $symptoms
     * @param list<string> $allergies
     * @return list<array<string, mixed>>
     */
    private function aiRerank(array $candidates, array $symptoms, array $allergies, int $limit): array
    {
        if (empty($this->geminiKeys) || empty($candidates)) {
            return [];
        }

        $compactList = [];
        foreach ($candidates as $i => $c) {
            $compactList[] = sprintf(
                "%d. id=%d | %s | %s | weight=%d%s",
                $i + 1,
                (int) $c['id'],
                (string) $c['name'],
                (string) ($c['active_ingredient'] ?: '-'),
                (int) ($c['best_weight'] ?? 0),
                !empty($c['is_first_line']) ? ' | first-line' : ''
            );
        }

        $prompt = "คุณเป็น AI ผู้ช่วยเภสัชกร ช่วยจัดอันดับสินค้าที่เหมาะกับอาการของผู้ป่วย\n\n"
            . "อาการที่ผู้ป่วยมี (symptom codes): " . implode(', ', $symptoms) . "\n"
            . "ยาที่แพ้: " . (empty($allergies) ? '-' : implode(', ', $allergies)) . "\n\n"
            . "รายการยาที่มีในร้าน:\n" . implode("\n", $compactList) . "\n\n"
            . "ตอบเป็น JSON เท่านั้น (ห้ามมี markdown, ห้ามอธิบาย) ตามรูป:\n"
            . '{"picks":[{"id":<product_id>,"reason":"เหตุผลสั้น 1 ประโยค"}]}' . "\n"
            . "เลือกได้สูงสุด " . (int) $limit . " ตัว เรียงจากเหมาะสุด → น้อยสุด.";

        $payload = json_encode([
            'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 600, 'temperature' => 0.2],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [];
        }

        foreach ($this->geminiKeys as $key) {
            $resp = $this->callGemini($key, $payload);
            if ($resp === '') {
                continue;
            }
            $picks = $this->extractPicks($resp);
            if (empty($picks)) {
                continue;
            }
            return $this->mergePicksWithCandidates($picks, $candidates, $limit);
        }
        return [];
    }

    private function callGemini(string $key, string $payload): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . urlencode($key);
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code >= 400) {
            return '';
        }
        $parsed = json_decode($body, true);
        $text = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return is_string($text) ? $text : '';
    }

    /**
     * @return list<array{id:int, reason:string}>
     */
    private function extractPicks(string $aiText): array
    {
        $aiText = trim($aiText);
        if ($aiText === '') {
            return [];
        }
        $aiText = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $aiText) ?? $aiText;
        $start = strpos($aiText, '{');
        $end = strrpos($aiText, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $json = substr($aiText, $start, $end - $start + 1);
        $parsed = json_decode($json, true);
        if (!is_array($parsed) || empty($parsed['picks']) || !is_array($parsed['picks'])) {
            return [];
        }
        $picks = [];
        foreach ($parsed['picks'] as $p) {
            if (is_array($p) && isset($p['id'])) {
                $picks[] = [
                    'id'     => (int) $p['id'],
                    'reason' => isset($p['reason']) ? (string) $p['reason'] : '',
                ];
            }
        }
        return $picks;
    }

    /**
     * @param list<array{id:int, reason:string}> $picks
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function mergePicksWithCandidates(array $picks, array $candidates, int $limit): array
    {
        $byId = [];
        foreach ($candidates as $c) {
            $byId[(int) $c['id']] = $c;
        }
        $out = [];
        foreach ($picks as $p) {
            if (count($out) >= $limit) {
                break;
            }
            $id = (int) $p['id'];
            if (!isset($byId[$id])) {
                continue;
            }
            $cand = $byId[$id];
            $cand['reason'] = $p['reason'] !== '' ? $p['reason'] : (string) ($cand['notes'] ?? '');
            $out[] = $cand;
        }
        return $out;
    }
}
