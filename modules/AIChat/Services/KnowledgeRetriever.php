<?php
/**
 * KnowledgeRetriever — RAG retrieval for AI Chat
 *
 * อ่าน ai_knowledge_base, score ด้วย Thai keyword overlap + condition_code match,
 * คืน top-K chunks ให้ inject เข้า system prompt ของ LLM.
 *
 * Strategy: LIKE keyword scoring (portable, ไม่ต้องพึ่ง FULLTEXT/ngram)
 *   - chunk ที่ condition_codes ตรง → +30 score
 *   - keyword overlap → +5/keyword
 *   - title/content substring match → +1-8/match
 *   - priority field เป็น tiebreaker
 */

namespace Modules\AIChat\Services;

class KnowledgeRetriever
{
    private \PDO $pdo;
    private const MAX_CHARS_PER_CHUNK = 1500;
    private const DEFAULT_LIMIT = 4;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param list<string> $conditionCodes
     * @return list<array<string, mixed>>
     */
    public function retrieve(?int $lineAccountId, string $query, array $conditionCodes = [], int $limit = self::DEFAULT_LIMIT): array
    {
        $tokens = $this->tokenize($query);
        if (empty($tokens) && empty($conditionCodes)) {
            return [];
        }

        $rows = $this->loadCandidates($lineAccountId, $tokens, $conditionCodes, 50);
        // Fallback: ถ้าไม่เจอด้วย scope ปัจจุบัน ลองดึงข้าม account ทั้งหมด เพื่อกัน account-id mismatch ระหว่าง import กับ query
        if (empty($rows)) {
            $rows = $this->loadCandidatesAcrossAllAccounts($tokens, $conditionCodes, 50);
        }
        if (empty($rows)) {
            return [];
        }

        $scored = [];
        foreach ($rows as $r) {
            $score = $this->scoreChunk($r, $tokens, $conditionCodes);
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['row' => $r, 'score' => $score];
        }

        usort($scored, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($scored, 0, $limit);
        $out = [];
        foreach ($top as $s) {
            $r = $s['row'];
            $content = (string) $r['content'];
            if (mb_strlen($content) > self::MAX_CHARS_PER_CHUNK) {
                $content = mb_substr($content, 0, self::MAX_CHARS_PER_CHUNK) . '…';
            }
            $out[] = [
                'id'           => (int) $r['id'],
                'source'       => (string) ($r['source'] ?? ''),
                'title'        => (string) ($r['title'] ?? ''),
                'heading_path' => (string) ($r['heading_path'] ?? ''),
                'content'      => $content,
                'score'        => (float) $s['score'],
            ];
        }
        return $out;
    }

    /**
     * Inject retrieved chunks into a system prompt suffix.
     *
     * @param list<array<string, mixed>> $chunks
     */
    public function buildPromptContext(array $chunks): string
    {
        if (empty($chunks)) {
            return '';
        }
        $parts = [];
        foreach ($chunks as $i => $c) {
            $head = ($c['heading_path'] ?? '') ?: ($c['title'] ?? '');
            $parts[] = sprintf(
                "[%d. %s — %s]\n%s",
                $i + 1,
                $c['source'] ?? '',
                $head,
                $c['content'] ?? ''
            );
        }
        return "=== ความรู้ทางคลินิกที่เกี่ยวข้อง (RAG) ===\n"
            . implode("\n\n", $parts)
            . "\n=== จบส่วนความรู้ ===\n"
            . "ใช้ข้อมูลข้างต้นเพื่อช่วยตอบให้แม่นยำ — อ้างอิงเฉพาะที่มีในนี้ ห้ามแต่งข้อมูลเพิ่ม.";
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $conditionCodes
     * @return list<array<string, mixed>>
     */
    /**
     * Cross-tenant fallback — ดึง chunks ทั้งหมด (ไม่ filter line_account_id)
     * เพื่อกัน case ที่ chunks ถูก import ใต้ account-id ต่างจาก runtime query
     *
     * @param list<string> $tokens
     * @param list<string> $conditionCodes
     * @return list<array<string, mixed>>
     */
    private function loadCandidatesAcrossAllAccounts(array $tokens, array $conditionCodes, int $maxRows): array
    {
        $where = ['is_active = 1'];
        $orParts = [];
        $bindings = [];
        $i = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) continue;
            $kc = ':xc' . $i; $kk = ':xk' . $i; $kt = ':xt' . $i;
            $orParts[] = "(content LIKE $kc OR keywords LIKE $kk OR title LIKE $kt)";
            $bindings[$kc] = '%' . $t . '%';
            $bindings[$kk] = '%' . $t . '%';
            $bindings[$kt] = '%' . $t . '%';
            $i++;
        }
        foreach ($conditionCodes as $cc) {
            $key = ':xcc' . $i++;
            $orParts[] = "condition_codes LIKE $key";
            $bindings[$key] = '%' . $cc . '%';
        }
        if (!empty($orParts)) {
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }
        $sql = "SELECT id, source, title, heading_path, content, keywords, condition_codes, priority
                FROM ai_knowledge_base
                WHERE " . implode(' AND ', $where) . "
                ORDER BY priority DESC
                LIMIT " . (int) $maxRows;
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bindings as $k => $v) {
                $stmt->bindValue($k, $v, \PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadCandidates(?int $lineAccountId, array $tokens, array $conditionCodes, int $maxRows): array
    {
        // ใช้ literal SQL สำหรับ line_account_id เพื่อกัน PDO null binding ที่อาจแปลง NULL → '' ทำให้ <=> ไม่ match
        $where = ['is_active = 1'];
        if ($lineAccountId === null) {
            $where[] = 'line_account_id IS NULL';
        } else {
            $where[] = '(line_account_id = ' . (int) $lineAccountId . ' OR line_account_id IS NULL)';
        }

        $orParts = [];
        $bindings = [];
        $i = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) continue;
            // unique placeholder ต่อ field กัน PDO native prepares ห้าม reuse
            $kc = ':tc' . $i;
            $kk = ':tk' . $i;
            $kt = ':tt' . $i;
            $orParts[] = "(content LIKE $kc OR keywords LIKE $kk OR title LIKE $kt)";
            $bindings[$kc] = '%' . $t . '%';
            $bindings[$kk] = '%' . $t . '%';
            $bindings[$kt] = '%' . $t . '%';
            $i++;
        }
        foreach ($conditionCodes as $cc) {
            $key = ':c' . $i++;
            $orParts[] = "condition_codes LIKE $key";
            $bindings[$key] = '%' . $cc . '%';
        }
        if (!empty($orParts)) {
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }

        $sql = "SELECT id, source, title, heading_path, content, keywords, condition_codes, priority
                FROM ai_knowledge_base
                WHERE " . implode(' AND ', $where) . "
                ORDER BY (line_account_id IS NOT NULL) DESC, priority DESC
                LIMIT " . (int) $maxRows;

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bindings as $k => $v) {
                $stmt->bindValue($k, $v, \PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $tokens
     * @param list<string> $conditionCodes
     */
    private function scoreChunk(array $row, array $tokens, array $conditionCodes): float
    {
        $score = (float) ($row['priority'] ?? 50) * 0.1;

        $cc = (string) ($row['condition_codes'] ?? '');
        foreach ($conditionCodes as $code) {
            if ($code !== '' && mb_stripos($cc, $code) !== false) {
                $score += 30;
            }
        }

        $haystack = mb_strtolower(
            ((string) ($row['title'] ?? '')) . ' '
            . ((string) ($row['keywords'] ?? '')) . ' '
            . ((string) ($row['heading_path'] ?? '')) . ' '
            . ((string) ($row['content'] ?? ''))
        );
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) continue;
            $matches = mb_substr_count($haystack, mb_strtolower($t));
            if ($matches > 0) {
                $score += min(8.0, (float) $matches) * (mb_strlen($t) >= 4 ? 5 : 2);
            }
        }
        return $score;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $parts = preg_split('/[\s,.;:?!\(\)\[\]\{\}"\'`\/\\\\]+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) >= 2) $out[] = $p;
        }
        // Thai มักไม่มี space — สร้าง sliding window 3-4 chars จาก contiguous Thai runs
        // เพื่อให้ "ปวดหัวมาก" match chunk ที่มี "ปวดหัว"
        if (preg_match_all('/[\x{0E00}-\x{0E7F}]+/u', $text, $m)) {
            foreach ($m[0] as $run) {
                $len = mb_strlen($run);
                if ($len <= 4) continue;
                foreach ([4, 3] as $win) {
                    if ($len < $win) continue;
                    for ($i = 0; $i <= $len - $win; $i++) {
                        $sub = mb_substr($run, $i, $win);
                        if (!in_array($sub, $out, true)) {
                            $out[] = $sub;
                        }
                    }
                }
            }
        }
        // จำกัดจำนวน token เพื่อกัน SQL ระเบิด
        if (count($out) > 40) {
            $out = array_slice($out, 0, 40);
        }
        return $out;
    }

    // ====================================================================
    // Admin: import / chunk / replace
    // ====================================================================

    /**
     * Import a markdown file by chunking on H1/H2/H3 headings.
     * Re-import is idempotent: clears existing rows for (source, line_account_id) first.
     */
    public function importMarkdownFile(?int $lineAccountId, string $absolutePath, string $sourceLabel): int
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return 0;
        }
        $raw = (string) file_get_contents($absolutePath);
        if ($raw === '') return 0;
        return $this->importMarkdownText($lineAccountId, $raw, $sourceLabel);
    }

    public function importMarkdownText(?int $lineAccountId, string $markdown, string $sourceLabel): int
    {
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $chunks = [];
        $current = ['title' => '', 'path' => [], 'body' => ''];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/u', $line, $m)) {
                if (trim($current['body']) !== '') $chunks[] = $current;
                $current = ['title' => trim($m[1]), 'path' => [trim($m[1])], 'body' => ''];
                continue;
            }
            if (preg_match('/^##\s+(.+)$/u', $line, $m)) {
                if (trim($current['body']) !== '') $chunks[] = $current;
                $current = [
                    'title' => trim($m[1]),
                    'path'  => [($current['path'][0] ?? ''), trim($m[1])],
                    'body'  => '',
                ];
                continue;
            }
            if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
                if (trim($current['body']) !== '') $chunks[] = $current;
                $current = [
                    'title' => trim($m[1]),
                    'path'  => [($current['path'][0] ?? ''), ($current['path'][1] ?? ''), trim($m[1])],
                    'body'  => '',
                ];
                continue;
            }
            $current['body'] .= $line . "\n";
        }
        if (trim($current['body']) !== '') $chunks[] = $current;

        try {
            $del = $this->pdo->prepare(
                "DELETE FROM ai_knowledge_base WHERE source = :s AND (line_account_id <=> :acc)"
            );
            $del->execute([':s' => $sourceLabel, ':acc' => $lineAccountId]);
        } catch (\Throwable $e) {}

        $ins = $this->pdo->prepare(
            "INSERT INTO ai_knowledge_base
             (line_account_id, source, title, heading_path, content, keywords, condition_codes, priority, is_active)
             VALUES (:acc, :s, :t, :hp, :c, :k, :cc, :p, 1)"
        );

        $count = 0;
        foreach ($chunks as $ch) {
            $body = trim($ch['body']);
            if (mb_strlen($body) < 80) continue;
            $pieces = $this->splitLongBody($body, 1500);
            foreach ($pieces as $piece) {
                $pathStr = implode(' > ', array_filter($ch['path']));
                $keywords = $this->extractKeywords($ch['title'] . ' ' . $piece);
                $cc = $this->guessConditionCodes($ch['title'] . ' ' . $piece);
                $ins->execute([
                    ':acc' => $lineAccountId,
                    ':s'   => $sourceLabel,
                    ':t'   => mb_substr((string) $ch['title'], 0, 250),
                    ':hp'  => mb_substr($pathStr, 0, 500),
                    ':c'   => $piece,
                    ':k'   => mb_substr($keywords, 0, 1000),
                    ':cc'  => $cc !== '' ? $cc : null,
                    ':p'   => 50,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<string>
     */
    private function splitLongBody(string $body, int $maxLen): array
    {
        if (mb_strlen($body) <= $maxLen) return [$body];
        $parts = [];
        $remaining = $body;
        while (mb_strlen($remaining) > $maxLen) {
            $cut = $maxLen;
            $headSlice = mb_substr($remaining, 0, $maxLen);
            $nl = mb_strrpos($headSlice, "\n");
            if ($nl !== false && $nl > $maxLen * 0.5) $cut = $nl;
            $parts[] = trim(mb_substr($remaining, 0, $cut));
            $remaining = trim(mb_substr($remaining, $cut));
        }
        if ($remaining !== '') $parts[] = $remaining;
        return $parts;
    }

    private function extractKeywords(string $text): string
    {
        $text = mb_strtolower($text);
        $candidates = [
            'ไข้', 'หวัด', 'ไอ', 'น้ำมูก', 'เจ็บคอ', 'ปวดหัว', 'ปวดศีรษะ',
            'ปวดท้อง', 'ท้องเสีย', 'อาเจียน', 'คลื่นไส้', 'แพ้', 'ผื่น',
            'นอนไม่หลับ', 'หายใจลำบาก', 'เจ็บหน้าอก', 'หัวใจ', 'ความดัน',
            'เบาหวาน', 'มะเร็ง', 'หอบ', 'ไมเกรน', 'ลมพิษ', 'ภูมิแพ้',
            'paracetamol', 'ibuprofen', 'amoxicillin', 'อาการ', 'โรค',
        ];
        $found = [];
        foreach ($candidates as $kw) {
            if (mb_stripos($text, $kw) !== false) $found[] = $kw;
        }
        return implode(',', array_unique($found));
    }

    private function guessConditionCodes(string $text): string
    {
        $map = [
            'fever'        => ['ไข้', 'ตัวร้อน'],
            'common_cold'  => ['หวัด', 'น้ำมูก', 'คัดจมูก'],
            'cough'        => ['ไอ', 'เสมหะ'],
            'headache'     => ['ปวดหัว', 'ปวดศีรษะ', 'ไมเกรน'],
            'gi'           => ['ปวดท้อง', 'ท้องเสีย', 'อาเจียน'],
            'allergy'      => ['แพ้', 'ภูมิแพ้', 'ลมพิษ'],
            'skin_rash'    => ['ผื่น', 'ผิวหนัง'],
            'insomnia'     => ['นอนไม่หลับ'],
        ];
        $matched = [];
        foreach ($map as $code => $kws) {
            foreach ($kws as $kw) {
                if (mb_stripos($text, $kw) !== false) {
                    $matched[] = $code;
                    break;
                }
            }
        }
        return implode(',', array_unique($matched));
    }
}
