<?php
/**
 * AiStudioFlex — builds LINE Flex Messages (single bubble or carousel) with
 * Gemini, using optional uploaded reference images both as vision context and
 * as the hero image embedded in each bubble.
 *
 * Pure logic + injectable HTTP transport so builders/parsers are unit-tested
 * without the network. Plain global class — matches AiStudioImage.
 *
 * @spec ai-studio-flex-upgrade
 */
class AiStudioFlex
{
    public const MAX_BUBBLES = 10;
    public const MAX_REFS = 10;
    public const MODEL = 'gemini-flash-latest';

    /** @var callable|null fn(string $url, array $payload): array{status:int, body:string} */
    private $httpClient;

    /** @var int */
    private $timeout;

    public function __construct(?callable $httpClient = null, int $timeout = 60)
    {
        $this->httpClient = $httpClient;
        $this->timeout = $timeout;
    }

    /** Clamp any input into the valid 1..MAX_BUBBLES range. */
    public static function clampBubbleCount($count): int
    {
        $n = (int) $count;
        if ($n < 1) {
            return 1;
        }
        if ($n > self::MAX_BUBBLES) {
            return self::MAX_BUBBLES;
        }
        return $n;
    }

    public function endpoint(string $apiKey): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode(self::MODEL) . ':generateContent?key=' . urlencode($apiKey);
    }

    /**
     * System instruction telling Gemini exactly what Flex structure to emit.
     *
     * @param string[] $heroUrls hosted public URLs of the uploaded references
     */
    public static function buildSystemPrompt(string $type, string $color, int $bubbleCount, array $heroUrls = []): string
    {
        $color = $color !== '' ? $color : '#06C755';
        $type = $type !== '' ? $type : 'custom';
        $bubbleCount = self::clampBubbleCount($bubbleCount);

        $structure = $bubbleCount > 1
            ? "ตอบเป็น JSON ของ \"carousel\" object ที่มี contents เป็น array ของ bubble จำนวน {$bubbleCount} ใบพอดี: { \"type\":\"carousel\", \"contents\":[ {bubble1}, {bubble2}, ... ] }."
            : "ตอบเป็น JSON ของ \"bubble\" object เพียงใบเดียว: { \"type\":\"bubble\", ... }.";

        $heroLine = '';
        if (!empty($heroUrls)) {
            $list = implode("\n", array_map(static fn ($u) => '- ' . $u, $heroUrls));
            $heroLine = "\nผู้ใช้แนบรูปสินค้ามาให้ใช้เป็นภาพ hero ของแต่ละ bubble ตามลำดับ (ระบบจะใส่ url ของ hero ให้เองภายหลัง). ใช้ข้อมูลจากรูปเพื่อเขียนข้อความให้ตรงกับสินค้าจริง. รูปที่แนบ:\n{$list}";
        }

        return "คุณคือผู้เชี่ยวชาญ LINE Flex Message. สร้าง Flex ที่ถูกต้องตามสเปก LINE "
            . "(ห้ามใส่ field altText หรือ type:flex — เอาเฉพาะ object โครงสร้างด้านล่าง).\n"
            . $structure . "\n"
            . "ใช้สีหลัก {$color} กับหัวข้อและปุ่ม. ประเภทเนื้อหา: {$type}. ข้อความทั้งหมดเป็นภาษาไทย.\n"
            . "แต่ละ bubble ควรมี body (box layout vertical) และ footer ที่มีปุ่ม action (type uri หรือ message). "
            . "ถ้าเป็นสินค้า/โปรโมทให้เน้นราคาให้ชัด."
            . $heroLine . "\n"
            . "ตอบกลับเป็น JSON ที่ parse ได้เท่านั้น ห้ามมีคำอธิบายหรือ markdown fence.";
    }

    /**
     * System instruction for editing an existing Flex from a natural-language command.
     * The user message carries the current Flex JSON plus the edit instruction.
     */
    public static function buildEditSystemPrompt(): string
    {
        return "คุณคือผู้เชี่ยวชาญ LINE Flex Message. ผู้ใช้จะให้ Flex JSON ปัจจุบันพร้อมคำสั่งแก้ไขเป็นภาษาไทย. "
            . "แก้ไขตามคำสั่งเท่านั้น และคงเนื้อหา/โครงสร้างส่วนที่ไม่เกี่ยวข้องไว้ตามเดิม. "
            . "ห้ามเปลี่ยนค่า url ของรูปภาพ (image.url) เว้นแต่ถูกสั่งให้เปลี่ยนหรือเพิ่มรูปใหม่. "
            . "ผลลัพธ์ต้องถูกต้องตามสเปก LINE — เป็น object ชนิด bubble หรือ carousel เท่านั้น "
            . "(ห้ามใส่ field altText หรือ type:flex). ข้อความเป็นภาษาไทย. "
            . "ตอบกลับเป็น Flex JSON ฉบับเต็มที่แก้แล้ว parse ได้เท่านั้น ห้ามมีคำอธิบายหรือ markdown fence.";
    }

    /** The six copy fields the model may write — never any price or product field. */
    public const COPY_FIELDS = ['title', 'intro', 'ctaLabel', 'badgeText', 'footerText', 'closingText'];

    /**
     * System instruction for the Hybrid "copy" mode: the model writes ONLY the
     * marketing wording for a product Flex. Prices/SKUs come from the database and
     * are assembled by the deterministic builder, so the model must not emit them.
     */
    public static function buildCopySystemPrompt(string $type, string $theme): string
    {
        $type = $type !== '' ? $type : 'product';
        $theme = $theme !== '' ? $theme : 'promotion';

        return "คุณคือนักเขียนคำโฆษณาการตลาดสำหรับร้านยาบน LINE. "
            . "ผู้ใช้จะให้รายชื่อสินค้าและบริบท. เขียน 'คำโปรย' การตลาดภาษาไทยที่กระชับ น่าซื้อ และสุภาพ. "
            . "ประเภทเนื้อหา: {$type}. ธีม: {$theme}.\n"
            . "ตอบกลับเป็น JSON object เท่านั้น มีคีย์เหล่านี้: "
            . "title (หัวข้อสั้น), intro (เกริ่นนำ 1 ประโยค), ctaLabel (ข้อความบนปุ่ม สั้นมาก), "
            . "badgeText (ป้ายสั้น ตัวพิมพ์ใหญ่ภาษาอังกฤษได้), footerText (บรรทัดปิดท้ายการ์ด), "
            . "closingText (ข้อความปิดท้ายชวนทักแชท).\n"
            . "ห้ามใส่ราคา ตัวเลข รหัสสินค้า หรือชื่อสินค้าตรง ๆ ในคำตอบ — เขียนเฉพาะคำการตลาดเท่านั้น. "
            . "ห้ามมีคำอธิบายหรือ markdown fence ตอบเป็น JSON ที่ parse ได้อย่างเดียว.";
    }

    /** Parse model text into a whitelisted copy object (string fields only). Null on failure. */
    public static function parseCopyJson(string $text): ?array
    {
        $cleaned = trim((string) preg_replace('/```json|```/i', '', $text));
        if ($cleaned === '') {
            return null;
        }
        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            return null;
        }
        $copy = [];
        foreach (self::COPY_FIELDS as $k) {
            if (isset($parsed[$k]) && is_string($parsed[$k])) {
                $copy[$k] = trim($parsed[$k]);
            }
        }
        return $copy !== [] ? $copy : null;
    }

    /**
     * Generate marketing copy only (Hybrid mode). Never throws.
     *
     * @return array{ok:bool, copy:?array, error:?string}
     */
    public function generateCopy(string $userPrompt, string $system, string $apiKey): array
    {
        $request = self::buildRequest($userPrompt, $system, []);
        try {
            $client = $this->httpClient ?? [$this, 'defaultHttpPost'];
            $res = $client($this->endpoint($apiKey), $request);
        } catch (\Throwable $e) {
            return ['ok' => false, 'copy' => null, 'error' => 'เชื่อมต่อ Google ไม่ได้: ' . $e->getMessage()];
        }

        $status = $res['status'] ?? 0;
        $json = json_decode($res['body'] ?? '', true);
        if ($status !== 200) {
            $msg = is_array($json) ? ($json['error']['message'] ?? '') : '';
            return ['ok' => false, 'copy' => null, 'error' => 'Google HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '')];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'copy' => null, 'error' => 'ผลลัพธ์จาก Google อ่านไม่ได้'];
        }

        $copy = self::parseCopyJson(self::extractText($json));
        if ($copy === null) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            return ['ok' => false, 'copy' => null, 'error' => $block ? ('ถูกบล็อก: ' . $block) : 'โมเดลไม่ได้ตอบเป็น JSON ที่ใช้ได้'];
        }
        return ['ok' => true, 'copy' => $copy, 'error' => null];
    }

    /**
     * Build the generateContent REST body (text + optional vision images, JSON output).
     *
     * @param array $visionRefs each ['mime'=>string,'data'=>base64]
     */
    public static function buildRequest(string $userPrompt, string $system, array $visionRefs = []): array
    {
        $parts = [['text' => $userPrompt]];
        foreach ($visionRefs as $ref) {
            if (empty($ref['data'])) {
                continue;
            }
            $parts[] = ['inline_data' => [
                'mime_type' => $ref['mime'] ?? 'image/png',
                'data' => $ref['data'],
            ]];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.8,
                'responseMimeType' => 'application/json',
            ],
        ];
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        return $payload;
    }

    /** Concatenate the text parts of a generateContent response. */
    public static function extractText(array $json): string
    {
        $text = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text'])) {
                $text .= $p['text'];
            }
        }
        return trim($text);
    }

    /** Parse model text into a Flex object (bubble or carousel). Null on failure. */
    public static function parseFlexJson(string $text): ?array
    {
        $cleaned = trim((string) preg_replace('/```json|```/i', '', $text));
        if ($cleaned === '') {
            return null;
        }
        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            return null;
        }
        // Accept a full flex message envelope or a {bubble:...} wrapper.
        if (($parsed['type'] ?? null) === 'flex' && isset($parsed['contents']) && is_array($parsed['contents'])) {
            $parsed = $parsed['contents'];
        }
        if (isset($parsed['bubble']) && is_array($parsed['bubble'])) {
            $parsed = $parsed['bubble'];
        }
        return is_array($parsed) ? $parsed : null;
    }

    /** Coerce a parsed Flex object into exactly $count bubbles. */
    public static function normalizeToBubbleCount(array $flex, int $count): array
    {
        $count = self::clampBubbleCount($count);

        if (($flex['type'] ?? null) === 'carousel' && isset($flex['contents']) && is_array($flex['contents'])) {
            $bubbles = array_values(array_filter($flex['contents'], 'is_array'));
        } else {
            $bubbles = [$flex];
        }
        if (empty($bubbles)) {
            $bubbles = [$flex];
        }

        if ($count === 1) {
            return $bubbles[0];
        }

        // Pad by cloning the last bubble, then trim to exactly $count.
        while (count($bubbles) < $count) {
            $bubbles[] = $bubbles[count($bubbles) - 1];
        }
        $bubbles = array_slice($bubbles, 0, $count);

        return ['type' => 'carousel', 'contents' => array_values($bubbles)];
    }

    /** Inject hero images (hosted URLs) into each bubble, cycling URLs. No-op if no URLs. */
    public static function injectHeroImages(array $flex, array $heroUrls): array
    {
        $heroUrls = array_values(array_filter($heroUrls, static fn ($u) => is_string($u) && $u !== ''));
        if (empty($heroUrls)) {
            return $flex;
        }

        $makeHero = static fn (string $url): array => [
            'type' => 'image',
            'url' => $url,
            'size' => 'full',
            'aspectRatio' => '20:13',
            'aspectMode' => 'cover',
        ];

        if (($flex['type'] ?? null) === 'carousel' && isset($flex['contents']) && is_array($flex['contents'])) {
            $n = count($heroUrls);
            foreach ($flex['contents'] as $i => &$bubble) {
                if (is_array($bubble)) {
                    $bubble['hero'] = $makeHero($heroUrls[$i % $n]);
                }
            }
            unset($bubble);
            return $flex;
        }

        $flex['hero'] = $makeHero($heroUrls[0]);
        return $flex;
    }

    /**
     * Generate the Flex object. Never throws.
     *
     * @param array $visionRefs each ['mime'=>string,'data'=>base64]
     * @return array{ok:bool, flex:?array, error:?string}
     */
    public function generate(string $userPrompt, string $system, array $visionRefs, string $apiKey): array
    {
        $request = self::buildRequest($userPrompt, $system, $visionRefs);

        try {
            $client = $this->httpClient ?? [$this, 'defaultHttpPost'];
            $res = $client($this->endpoint($apiKey), $request);
        } catch (\Throwable $e) {
            return ['ok' => false, 'flex' => null, 'error' => 'เชื่อมต่อ Google ไม่ได้: ' . $e->getMessage()];
        }

        $status = $res['status'] ?? 0;
        $json = json_decode($res['body'] ?? '', true);

        if ($status !== 200) {
            $msg = is_array($json) ? ($json['error']['message'] ?? '') : '';
            return ['ok' => false, 'flex' => null, 'error' => 'Google HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '')];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'flex' => null, 'error' => 'ผลลัพธ์จาก Google อ่านไม่ได้'];
        }

        $flex = self::parseFlexJson(self::extractText($json));
        if ($flex === null) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            return ['ok' => false, 'flex' => null, 'error' => $block ? ('ถูกบล็อก: ' . $block) : 'โมเดลไม่ได้ตอบเป็น JSON ที่ใช้ได้'];
        }

        return ['ok' => true, 'flex' => $flex, 'error' => null];
    }

    /** Default cURL transport (production). */
    private function defaultHttpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException($err !== '' ? $err : 'cURL failed');
        }
        return ['status' => $status, 'body' => $body];
    }
}
