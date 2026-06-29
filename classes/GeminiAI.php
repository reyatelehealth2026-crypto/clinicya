<?php
/**
 * GeminiAI - Helper class to interact with Google's Gemini API
 *
 * All generateContent calls in this class use one central model constant.
 * The request layer also tries AUTO, IPv6, and IPv4 transport routes when
 * Google reports a location-routing error, and writes safe diagnostics to error_log.
 */
class GeminiAI {
    /**
     * Central model switch for EVERY text / vision call in this class.
     * Do not hard-code Gemini model names in individual menu methods.
     */
    private const LATEST_MODEL = 'gemini-3.5-flash';
    private const API_VERSION  = 'v1beta';
    private const RECEIPT_VISION_MODELS = [
        'gemini-3.5-flash',
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-flash-latest',
        'gemini-3.1-flash',
        'gemini-3.1-flash-lite',
        'gemini-2.0-flash',
    ];

    private $apiKey;
    private $model;
    private $systemPrompt;
    private $apiKeySource = 'unknown';

    public function __construct($apiKey = null, $db = null, $botId = null) {
        // รับ API Key จาก parameter หรือ database หรือ config
        if ($apiKey) {
            $this->apiKey = $apiKey;
            $this->apiKeySource = 'constructor';
        } elseif ($db) {
            $this->loadSettingsFromDB($db, $botId);
        } elseif (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            $this->apiKey = GEMINI_API_KEY;
            $this->apiKeySource = 'GEMINI_API_KEY constant';
        }

        // Force all systems in this class to use the current central model.
        $this->model = self::LATEST_MODEL;

        if (empty($this->apiKey)) {
            throw new Exception('กรุณาตั้งค่า Gemini API Key ในหน้าตั้งค่า AI');
        }
    }

    /**
     * Returns the single model used by all generateContent calls.
     */
    public function getActiveModel() {
        return self::LATEST_MODEL;
    }

    /**
     * โหลดการตั้งค่าจากฐานข้อมูล
     */
    private function loadSettingsFromDB($db, $botId = null) {
        try {
            if ($botId) {
                $stmt = $db->prepare('SELECT * FROM ai_settings WHERE line_account_id = ? LIMIT 1');
                $stmt->execute([$botId]);
            } else {
                $stmt = $db->prepare('SELECT * FROM ai_settings WHERE line_account_id IS NULL LIMIT 1');
                $stmt->execute();
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->apiKey = $row['gemini_api_key'] ?? null;
                $this->apiKeySource = 'ai_settings.line_account_id=' . ($botId ?: 'NULL');
                // Intentionally do not read any saved legacy model value.
                $this->model = self::LATEST_MODEL;
                $this->systemPrompt = $row['system_prompt'] ?? null;
            }
        } catch (Throwable $e) {
            // ถ้าตารางไม่มี ก็ข้ามไป และปล่อยให้ constructor ใช้ config ต่อ
            error_log('GeminiAI settings load error: ' . $e->getMessage());
        }
    }

    /**
     * ตั้งค่า System Prompt
     */
    public function setSystemPrompt($prompt) {
        $this->systemPrompt = $prompt;
    }

    /**
     * ดึง API Key จากฐานข้อมูล (static method)
     */
    public static function getApiKeyFromDB($db, $botId = null) {
        try {
            if ($botId) {
                $stmt = $db->prepare('SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1');
                $stmt->execute([$botId]);
            } else {
                $stmt = $db->prepare('SELECT gemini_api_key FROM ai_settings WHERE line_account_id IS NULL LIMIT 1');
                $stmt->execute();
            }
            return $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            error_log('GeminiAI API key load error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ส่ง Request ไปยัง Google API
     * ทุก generateContent request จะถูกบังคับให้ใช้ self::LATEST_MODEL ที่นี่
     * เพื่อไม่ให้เมนูใดหลุดไปใช้โมเดลเก่าโดยไม่ตั้งใจ
     */
    /**
     * Execute one HTTP request through a specific cURL IP-routing mode.
     * The API key is sent in a header so it does not appear in the URL.
     */
    private function executeRequestAttempt($url, $encodedPayload, $ipResolve, $routeLabel) {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'http_code' => 0,
                'response'  => null,
                'error'     => 'ไม่สามารถเริ่มต้นการเชื่อมต่อ cURL ได้',
                'remote_ip' => '',
                'route'     => $routeLabel,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encodedPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_IPRESOLVE      => $ipResolve,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $remoteIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'response'  => $response,
            'error'     => $error ?: null,
            'remote_ip' => $remoteIp,
            'route'     => $routeLabel,
        ];
    }

    /**
     * ส่ง Request ไปยัง Google API
     *
     * For "User location is not supported" responses, tries auto routing,
     * IPv6, then IPv4. This both provides a possible workaround and records
     * which path/key source was actually used, without exposing the API key.
     */
    private function makeRequest($model, $data, $apiVersion = self::API_VERSION, $method = 'generateContent', $forceCentralModel = true) {
        if ($method === 'generateContent' && $forceCentralModel) {
            $model = self::LATEST_MODEL;
        }

        $encodedPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            throw new Exception('JSON Encode Error: ' . json_last_error_msg());
        }

        $url = 'https://generativelanguage.googleapis.com/' . rawurlencode($apiVersion)
            . '/models/' . rawurlencode($model) . ':' . rawurlencode($method);

        $keyFingerprint = substr(hash('sha256', (string) $this->apiKey), 0, 12);

        $routes = [
            ['label' => 'auto', 'resolve' => CURL_IPRESOLVE_WHATEVER],
            ['label' => 'ipv6', 'resolve' => CURL_IPRESOLVE_V6],
            ['label' => 'ipv4', 'resolve' => CURL_IPRESOLVE_V4],
        ];

        $lastFailure = null;

        foreach ($routes as $route) {
            $attempt = $this->executeRequestAttempt(
                $url,
                $encodedPayload,
                $route['resolve'],
                $route['label']
            );

            if ($attempt['error']) {
                error_log(
                    'Gemini transport attempt: route=' . $attempt['route']
                    . ', model=' . $model
                    . ', key_fp=' . $keyFingerprint
                    . ', key_source=' . $this->apiKeySource
                    . ', curl_error=' . $attempt['error']
                );
                $lastFailure = [
                    'http_code' => 0,
                    'message'   => 'Curl Error: ' . $attempt['error'],
                ];
                continue;
            }

            $result = json_decode((string) $attempt['response'], true);
            $message = is_array($result)
                ? ($result['error']['message'] ?? '')
                : '';

            error_log(
                'Gemini transport attempt: route=' . $attempt['route']
                . ', model=' . $model
                . ', key_fp=' . $keyFingerprint
                . ', key_source=' . $this->apiKeySource
                . ', http=' . $attempt['http_code']
                . ', google_ip=' . ($attempt['remote_ip'] ?: 'unknown')
                . ($message !== '' ? ', message=' . $message : '')
            );

            if ($attempt['http_code'] >= 200 && $attempt['http_code'] < 300) {
                if (!is_array($result)) {
                    throw new Exception('Google API returned invalid JSON response');
                }
                return $result;
            }

            $lastFailure = [
                'http_code' => $attempt['http_code'],
                'message'   => $message !== '' ? $message : 'Unknown API Error',
            ];

            $isLocationFailure = stripos($lastFailure['message'], 'User location is not supported') !== false;

            // For normal API errors, changing IP protocol cannot help.
            if (!$isLocationFailure) {
                throw new Exception(
                    'Google HTTP ' . $lastFailure['http_code'] . ': ' . $lastFailure['message']
                );
            }
        }

        if ($lastFailure) {
            throw new Exception(
                'Google HTTP ' . $lastFailure['http_code'] . ': ' . $lastFailure['message']
            );
        }

        throw new Exception('Google API request failed without a response');
    }

    /**
     * Extract plain text from the first Gemini candidate.
     */
    private function extractCandidateText(array $result) {
        $parts = $result['candidates'][0]['content']['parts'] ?? [];
        $text = '';

        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return trim($text);
    }

    /**
     * OCR fallback for shared-host webhook calls when Google Gemini rejects the
     * server location. Uses OCR.Space because it accepts simple HTTPS uploads
     * from this cPanel host without local OCR binaries.
     */
    private function analyzeReceiptImageWithOcrSpace($imageData, $mimeType) {
        $apiKey = defined('OCR_SPACE_API_KEY') && OCR_SPACE_API_KEY
            ? OCR_SPACE_API_KEY
            : 'helloworld';

        $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        $ocrAttempts = [
            ['language' => 'tha', 'engine' => '2'],
            ['language' => 'eng', 'engine' => '2'],
            ['language' => 'tha', 'engine' => '1'],
            ['language' => 'eng', 'engine' => '1'],
        ];
        $lastError = '';

        foreach ($ocrAttempts as $ocrAttempt) {
            $language = $ocrAttempt['language'];
            $engine = $ocrAttempt['engine'];
            $payload = [
                'apikey' => $apiKey,
                'language' => $language,
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'scale' => 'true',
                'OCREngine' => $engine,
                'base64Image' => $base64Image,
            ];

            $ch = curl_init('https://api.ocr.space/parse/image');
            if ($ch === false) {
                $lastError = 'cannot init OCR.Space curl';
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                $lastError = 'curl: ' . $curlError;
                continue;
            }

            $json = json_decode((string) $body, true);
            if ($httpCode < 200 || $httpCode >= 300 || !is_array($json)) {
                $lastError = 'http=' . $httpCode;
                continue;
            }

            if (!empty($json['IsErroredOnProcessing'])) {
                $lastError = is_array($json['ErrorMessage'] ?? null)
                    ? implode('; ', $json['ErrorMessage'])
                    : (string) ($json['ErrorMessage'] ?? 'OCR processing error');
                continue;
            }

            $text = '';
            foreach (($json['ParsedResults'] ?? []) as $result) {
                $text .= "\n" . (string) ($result['ParsedText'] ?? '');
            }

            $parsed = $this->parseReceiptOcrText($text);
            if (!empty($parsed['is_receipt']) && (float) $parsed['total_amount'] > 0) {
                error_log('analyzeReceiptImage OCR.Space fallback success: language=' . $language . ', engine=' . $engine);
                return $parsed;
            }

            $lastError = 'no receipt total parsed; language=' . $language . '; engine=' . $engine . '; text_len=' . strlen($text);
        }

        error_log('analyzeReceiptImage OCR.Space fallback failed: ' . $lastError);
        return null;
    }

    private function parseReceiptOcrText($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $normalized = preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $text));
        $lower = mb_strtolower($normalized, 'UTF-8');

        $receiptWords = ['receipt', 'tax invoice', 'invoice', 'bill', 'total', 'amount', 'cash', 'change', 'ใบเสร็จ', 'ใบกำกับ', 'รวม', 'ยอด', 'ชำระ', 'เงินสด'];
        $looksLikeReceipt = false;
        foreach ($receiptWords as $word) {
            if (mb_strpos($lower, mb_strtolower($word, 'UTF-8')) !== false) {
                $looksLikeReceipt = true;
                break;
            }
        }

        $totalAmount = $this->extractReceiptTotalAmount($normalized);
        if (!$looksLikeReceipt && $totalAmount <= 0) {
            return ['is_receipt' => false, 'shop_name' => null, 'receipt_number' => null, 'total_amount' => 0, 'date' => null];
        }

        return [
            'is_receipt' => $totalAmount > 0,
            'shop_name' => $this->extractReceiptShopName($normalized),
            'receipt_number' => $this->extractReceiptNumber($normalized),
            'total_amount' => $totalAmount,
            'date' => $this->extractReceiptDate($normalized),
        ];
    }

    private function extractReceiptTotalAmount($text) {
        $lines = preg_split('/\n+/', (string) $text) ?: [];
        $candidates = [];
        $orderedAmounts = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (!preg_match_all('/(?:฿|THB|บาท)?\s*([0-9]{1,3}(?:[, ]?[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]{2,}(?:\.[0-9]{1,2})?)/iu', $line, $matches)) {
                continue;
            }

            $lower = mb_strtolower($line, 'UTF-8');
            $weight = 1;
            foreach (['grand total', 'net total', 'total', 'amount due', 'dues', 'ยอดสุทธิ', 'ยอดรวม', 'รวมทั้งสิ้น', 'รวมเงิน', 'ชำระเงิน', 'รวม'] as $keyword) {
                if (mb_strpos($lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
                    $weight = 10;
                    break;
                }
            }
            foreach (['change', 'cash', 'เงินทอน', 'รับเงิน', 'ทอน'] as $keyword) {
                if (mb_strpos($lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
                    $weight = -5;
                    break;
                }
            }

            foreach ($matches[1] as $raw) {
                $amount = (float) str_replace([',', ' '], '', $raw);
                if ($amount >= 10) {
                    $orderedAmounts[] = $amount;
                    $candidates[] = ['amount' => $amount, 'weight' => $weight];
                }
            }
        }

        if (!$candidates) {
            return 0.0;
        }

        // Tendered cash minus change is a reliable receipt pattern and must
        // run before repeated-number detection because OCR can repeat cash too.
        $countAmounts = count($orderedAmounts);
        if ($countAmounts >= 2) {
            for ($i = 0; $i < $countAmounts; $i++) {
                for ($j = $i + 1; $j < $countAmounts; $j++) {
                    $net = round($orderedAmounts[$i] - $orderedAmounts[$j], 2);
                    if ($net <= 0) {
                        continue;
                    }
                    foreach ($orderedAmounts as $amount) {
                        if (abs($amount - $net) < 0.01) {
                            return (float) $amount;
                        }
                    }
                }
            }
        }

        // Column OCR often returns labels first and the TOTAL column numbers
        // later. Net totals commonly appear twice before cash/change values.
        $amountCounts = [];
        foreach ($orderedAmounts as $amount) {
            $key = number_format($amount, 2, '.', '');
            $amountCounts[$key] = ($amountCounts[$key] ?? 0) + 1;
        }
        $repeatedTotals = [];
        foreach ($amountCounts as $key => $count) {
            $amount = (float) $key;
            if ($count >= 2 && $amount > 0) {
                $repeatedTotals[] = $amount;
            }
        }
        if ($repeatedTotals) {
            rsort($repeatedTotals, SORT_NUMERIC);
            return (float) $repeatedTotals[0];
        }

        usort($candidates, static function ($a, $b) {
            if ($a['weight'] === $b['weight']) {
                return $b['amount'] <=> $a['amount'];
            }
            return $b['weight'] <=> $a['weight'];
        });

        return (float) $candidates[0]['amount'];
    }

    private function extractReceiptShopName($text) {
        $lines = preg_split('/\n+/', (string) $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !preg_match('/^[0-9\s:\/\-.#]+$/', $line)) {
                return mb_substr($line, 0, 120, 'UTF-8');
            }
        }
        return null;
    }

    private function extractReceiptNumber($text) {
        if (preg_match('/(?:receipt\s*(?:no|number)?|invoice\s*(?:no|number)?|เลข(?:ที่)?|หมายเลข)\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{2,})/iu', (string) $text, $m)) {
            return mb_substr(trim($m[1]), 0, 100, 'UTF-8');
        }
        return null;
    }

    private function extractReceiptDate($text) {
        if (preg_match('/\b(20[0-9]{2})[-\/.]([01]?[0-9])[-\/.]([0-3]?[0-9])\b/u', (string) $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/\b([0-3]?[0-9])[-\/.]([01]?[0-9])[-\/.]((?:20)?[0-9]{2})\b/u', (string) $text, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }
            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    /**
     * สร้างข้อความ Broadcast (Text Generation)
     * Uses the same latest Gemini model as receipt analysis and every other call.
     */
    public function generateBroadcast($topic, $tone = 'friendly', $target = 'general') {
        $prompt = "เขียนข้อความ Broadcast สำหรับ LINE Official Account (สั้น กระชับ น่าสนใจ มี emoji):\n"
            . "- หัวข้อ: {$topic}\n"
            . "- กลุ่มเป้าหมาย: {$target}\n"
            . "- น้ำเสียง: {$tone}\n"
            . "- ความยาว: ไม่เกิน 300 ตัวอักษร\n"
            . "- Call to Action: กระตุ้นให้คลิกดูสินค้า";

        if (!empty($this->systemPrompt)) {
            $prompt = $this->systemPrompt . "\n\n" . $prompt;
        }

        $data = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500,
            ],
        ];

        try {
            $result = $this->makeRequest(self::LATEST_MODEL, $data);
            $text = $this->extractCandidateText($result);

            if ($text === '') {
                return [
                    'success' => false,
                    'error' => 'Gemini ไม่ส่งข้อความกลับมา',
                    'model' => self::LATEST_MODEL,
                ];
            }

            return [
                'success' => true,
                'text'    => $text,
                'model'   => self::LATEST_MODEL,
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            error_log('generateBroadcast error model=' . self::LATEST_MODEL . ': ' . $message);

            if (stripos($message, '429') !== false || stripos($message, 'quota') !== false) {
                return ['success' => false, 'error' => '⏳ API ถูกใช้งานบ่อยเกินไป กรุณารอ 1-2 นาทีแล้วลองใหม่'];
            }

            return ['success' => false, 'error' => $message];
        }
    }

    /**
     * วิเคราะห์ใบเสร็จด้วย Gemini Vision
     * Uses the exact same central model as every other Gemini task.
     * คืนค่า array: {is_receipt, shop_name, receipt_number, total_amount, date} หรือ null ถ้าล้มเหลว
     */
    public function analyzeReceiptImage($imageData, $mimeType = 'image/jpeg') {
        if (!is_string($imageData) || $imageData === '') {
            error_log('analyzeReceiptImage error: empty image data');
            return null;
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            $mimeType = 'image/jpeg';
        }

        $prompt = 'วิเคราะห์รูปนี้ว่าเป็นใบเสร็จรับเงิน/ใบกำกับภาษี/บิลซื้อของหรือไม่ '
            . 'แล้วตอบเป็น JSON ล้วนๆ ไม่มี Markdown และไม่มีข้อความอื่น:\n'
            . '{"is_receipt":true,"shop_name":"ชื่อร้าน","receipt_number":"เลขที่","total_amount":0.00,"date":"YYYY-MM-DD"}\n'
            . 'กฎ:\n'
            . '- is_receipt=false ถ้าไม่ใช่ใบเสร็จ\n'
            . '- total_amount คือยอดสุทธิที่ลูกค้าชำระ เป็นตัวเลขเท่านั้น\n'
            . '- receipt_number และ date ให้เป็น null เมื่ออ่านไม่พบ\n'
            . '- ห้ามเดาข้อมูลที่อ่านไม่ชัด';

        $data = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => base64_encode($imageData),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'maxOutputTokens'  => 256,
                'responseMimeType' => 'application/json',
            ],
        ];

        foreach (self::RECEIPT_VISION_MODELS as $model) {
            try {
                $result = $this->makeRequest($model, $data, self::API_VERSION, 'generateContent', false);
                $text = $this->extractCandidateText($result);
                $text = preg_replace('/^```(?:json)?\\s*|\\s*```$/m', '', $text);
                $parsed = json_decode($text, true);

                if (!is_array($parsed)) {
                    error_log('analyzeReceiptImage error: Gemini returned invalid JSON. model=' . $model);
                    continue;
                }

                error_log('analyzeReceiptImage Gemini success: model=' . $model);
                return [
                    'is_receipt'     => !empty($parsed['is_receipt']),
                    'shop_name'      => isset($parsed['shop_name']) && $parsed['shop_name'] !== '' ? trim((string) $parsed['shop_name']) : null,
                    'receipt_number' => isset($parsed['receipt_number']) && $parsed['receipt_number'] !== '' ? trim((string) $parsed['receipt_number']) : null,
                    'total_amount'   => isset($parsed['total_amount']) && is_numeric($parsed['total_amount']) ? (float) $parsed['total_amount'] : 0.0,
                    'date'           => isset($parsed['date']) && $parsed['date'] !== '' ? trim((string) $parsed['date']) : null,
                ];
            } catch (Throwable $e) {
                error_log('analyzeReceiptImage error model=' . $model . ': ' . $e->getMessage());
            }
        }

        return $this->analyzeReceiptImageWithOcrSpace($imageData, $mimeType);
    }

    /**
     * สร้าง Prompt สำหรับรูปภาพสินค้า
     * This method delegates to generateBroadcast(), so it also uses the central model.
     */
    public function generateProductImage($description) {
        return $this->generateBroadcast(
            'Prompt สำหรับสร้างรูปภาพสินค้า: ' . $description,
            'descriptive',
            'AI Generator'
        );
    }
}
