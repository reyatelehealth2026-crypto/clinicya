<?php
/**
 * AiStudioImage — Google "Nano Banana" image generation client.
 *
 * Wraps the Gemini image models (`gemini-3.1-flash-image`, `gemini-3-pro-image`,
 * `gemini-2.5-flash-image`) via the `generateContent` REST endpoint. Supports a
 * text prompt plus optional reference images (product-in-scene, edit, poster).
 *
 * HTTP transport is injectable so the builders/parsers can be unit-tested
 * without the network. Plain global class — matches the other classes/ services.
 *
 * @spec ai-studio-image-upgrade
 */
class AiStudioImage
{
    /** Allowed models, newest first. */
    public const MODELS = [
        'gemini-3.1-flash-image',  // Nano Banana 2 (default)
        'gemini-3-pro-image',      // Nano Banana Pro
        'gemini-2.5-flash-image',  // Nano Banana
    ];

    /** @var callable|null fn(string $url, array $payload): array{status:int, body:string} */
    private $httpClient;

    /** @var int */
    private $timeout;

    public function __construct(?callable $httpClient = null, int $timeout = 90)
    {
        $this->httpClient = $httpClient;
        $this->timeout = $timeout;
    }

    public static function allowedModels(): array
    {
        return self::MODELS;
    }

    public static function defaultModel(): string
    {
        return self::MODELS[0];
    }

    public static function resolveModel(?string $model): string
    {
        $model = trim((string) $model);
        return in_array($model, self::MODELS, true) ? $model : self::defaultModel();
    }

    public function endpoint(string $model, string $apiKey): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);
    }

    /**
     * Build the exact generateContent REST body.
     *
     * @param array $refs each ['mime'=>string,'data'=>base64]
     * @param array $opts ['aspectRatio'=>string,'imageSize'=>string]
     */
    public static function buildRequest(string $prompt, array $refs = [], array $opts = []): array
    {
        $parts = [['text' => $prompt]];
        foreach ($refs as $ref) {
            if (empty($ref['data'])) {
                continue;
            }
            $parts[] = ['inline_data' => [
                'mime_type' => $ref['mime'] ?? 'image/png',
                'data' => $ref['data'],
            ]];
        }

        return [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'responseFormat' => ['image' => [
                    'aspectRatio' => $opts['aspectRatio'] ?? '1:1',
                    'imageSize' => $opts['imageSize'] ?? '2K',
                ]],
            ],
        ];
    }

    /**
     * Extract generated images (or an error) from a generateContent response.
     * Tolerant of REST snake_case and SDK camelCase.
     *
     * @return array{images:array<int,array{mime:string,data:string}>, text:?string, error:?string}
     */
    public static function parseResponse(array $json): array
    {
        if (!empty($json['error']['message'])) {
            return ['images' => [], 'text' => null, 'error' => (string) $json['error']['message']];
        }

        $images = [];
        $text = null;
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;
            if (is_array($inline) && !empty($inline['data'])) {
                $images[] = [
                    'mime' => $inline['mime_type'] ?? $inline['mimeType'] ?? 'image/png',
                    'data' => (string) $inline['data'],
                ];
            } elseif (isset($part['text']) && $text === null) {
                $text = (string) $part['text'];
            }
        }

        $error = null;
        if (!$images) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            $error = $block
                ? ('ถูกบล็อก: ' . $block)
                : ($text ?? 'ไม่ได้รูปจากโมเดล (อาจถูกปฏิเสธหรือ prompt ไม่ชัด)');
        }

        return ['images' => $images, 'text' => $text, 'error' => $error];
    }

    /**
     * Generate image(s). Never throws — returns ok=false with a message.
     *
     * @return array{ok:bool, images:array, text:?string, error:?string}
     */
    public function generate(string $model, string $prompt, array $refs, array $opts, string $apiKey): array
    {
        $model = self::resolveModel($model);
        $request = self::buildRequest($prompt, $refs, $opts);

        try {
            $client = $this->httpClient ?? [$this, 'defaultHttpPost'];
            $res = $client($this->endpoint($model, $apiKey), $request);
        } catch (\Throwable $e) {
            return ['ok' => false, 'images' => [], 'text' => null, 'error' => 'เชื่อมต่อ Google ไม่ได้: ' . $e->getMessage()];
        }

        $status = $res['status'] ?? 0;
        $json = json_decode($res['body'] ?? '', true);

        if ($status !== 200) {
            $msg = is_array($json) ? ($json['error']['message'] ?? '') : '';
            return ['ok' => false, 'images' => [], 'text' => null, 'error' => 'Google HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '')];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'images' => [], 'text' => null, 'error' => 'ผลลัพธ์จาก Google อ่านไม่ได้'];
        }

        $parsed = self::parseResponse($json);
        return [
            'ok' => !empty($parsed['images']),
            'images' => $parsed['images'],
            'text' => $parsed['text'],
            'error' => $parsed['error'],
        ];
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
