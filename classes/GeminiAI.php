<?php
/**
 * GeminiAI - Helper class to interact with Google's Gemini API
 * ใช้สำหรับสร้างข้อความ Broadcast และรูปภาพสินค้า
 */
class GeminiAI {
    private $apiKey;
    private $model;
    private $systemPrompt;

    public function __construct($apiKey = null, $db = null, $botId = null) {
        // รับ API Key จาก parameter หรือ database หรือ config
        if ($apiKey) {
            $this->apiKey = $apiKey;
        } elseif ($db) {
            // ดึงจากฐานข้อมูล
            $this->loadSettingsFromDB($db, $botId);
        } elseif (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            $this->apiKey = GEMINI_API_KEY;
        }
        
        if (empty($this->apiKey)) {
            throw new Exception("กรุณาตั้งค่า Gemini API Key ในหน้าตั้งค่า AI");
        }
    }
    
    /**
     * โหลดการตั้งค่าจากฐานข้อมูล
     */
    private function loadSettingsFromDB($db, $botId = null) {
        try {
            if ($botId) {
                $stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$botId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
                $stmt->execute();
            }
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->apiKey = $row['gemini_api_key'] ?? null;
                $this->model = 'gemini-flash-latest';
                $this->systemPrompt = $row['system_prompt'] ?? null;
            }
        } catch (Exception $e) {
            // ถ้าตารางไม่มี ก็ข้ามไป
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
                $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$botId]);
            } else {
                $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
                $stmt->execute();
            }
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * ส่ง Request ไปยัง Google API
     */
    private function makeRequest($model, $data, $apiVersion = 'v1beta', $method = 'generateContent') {
        $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:{$method}?key=" . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Curl Error: " . $error);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown API Error';
            throw new Exception("API Error ($httpCode): " . $msg);
        }

        return $result;
    }

    /**
     * สร้างข้อความ Broadcast (Text Generation)
     * Model: gemini-pro (stable) หรือ gemini-1.5-flash (ถ้ามีสิทธิ์)
     */
    public function generateBroadcast($topic, $tone = 'friendly', $target = 'general') {
        $prompt = "เขียนข้อความ Broadcast สำหรับ LINE Official Account (สั้น กระชับ น่าสนใจ มี emoji):
        - หัวข้อ: $topic
        - กลุ่มเป้าหมาย: $target
        - น้ำเสียง: $tone
        - ความยาว: ไม่เกิน 300 ตัวอักษร
        - Call to Action: กระตุ้นให้คลิกดูสินค้า";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500,
            ]
        ];

        // ลอง models ตามลำดับพร้อม API version ที่เหมาะสม
        $modelConfigs = [
            ['model' => 'gemini-flash-latest', 'version' => 'v1beta'],
            ['model' => 'gemini-2.0-flash', 'version' => 'v1beta'],
            ['model' => 'gemini-1.5-flash', 'version' => 'v1beta'],
            ['model' => 'gemini-1.5-pro', 'version' => 'v1beta'],
            ['model' => 'gemini-pro', 'version' => 'v1beta'],
            ['model' => 'gemini-pro', 'version' => 'v1'],
        ];
        $lastError = '';
        
        foreach ($modelConfigs as $config) {
            try {
                $result = $this->makeRequest($config['model'], $data, $config['version']);
                
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    return [
                        'success' => true, 
                        'text' => trim($result['candidates'][0]['content']['parts'][0]['text']),
                        'model' => $config['model']
                    ];
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                // ถ้าเป็น 404 (model not found) ให้ลอง model ถัดไป
                if (strpos($lastError, '404') !== false || strpos($lastError, 'not found') !== false) {
                    continue;
                }
                // ถ้าเป็น rate limit (429) ให้แจ้งผู้ใช้
                if (strpos($lastError, '429') !== false || strpos($lastError, 'quota') !== false) {
                    return ['success' => false, 'error' => '⏳ API ถูกใช้งานบ่อยเกินไป กรุณารอ 1-2 นาทีแล้วลองใหม่'];
                }
                // ถ้าเป็น error อื่น ให้หยุดเลย
                return ['success' => false, 'error' => $lastError];
            }
        }
        
        return ['success' => false, 'error' => 'ไม่สามารถเชื่อมต่อ Gemini API ได้ กรุณาตรวจสอบ API Key - ' . $lastError];
    }

    /**
     * สร้างรูปภาพ (Image Generation)
     * หมายเหตุ: ต้องใช้ Model imagen-3.0-generate-001
     */
    public function generateProductImage($description) {
        // หมายเหตุ: ปัจจุบัน Imagen API บน Gemini อาจต้องการสิทธิ์ Access เพิ่มเติม
        // หากยังเข้าถึง Imagen ไม่ได้ แนะนำให้ใช้ Text-to-Image เจ้าอื่นชั่วคราว หรือใช้ Gemini บรรยายภาพแทน
        
        $prompt = "Professional product photography of " . $description . ", high quality, studio lighting, white background, 4k resolution";

        // โครงสร้าง Request สำหรับ Imagen (Google Cloud Vertex AI style - อาจต้องปรับตาม Document ล่าสุดของ Public API)
        // เพื่อความชัวร์ในเบื้องต้น เราจะใช้ Prompt Engineering ให้ Gemini ช่วยคิด Prompt สร้างภาพให้แทน
        // หากต้องการสร้างภาพจริงๆ ต้องยิงไปที่ Endpoint Imagen โดยตรง ซึ่งซับซ้อนกว่านิดหน่อย
        
        // สำหรับ Demo Code นี้ ผมจะจำลองการขอ Prompt สำหรับนำไปใช้กับ Midjourney/Stable Diffusion 
        // หรือถ้าคุณมีสิทธิ์ใช้ Imagen ให้แก้ URL ใน makeRequest
        
        return $this->generateBroadcast("Prompt สำหรับสร้างรูปภาพสินค้า: $description", "descriptive", "AI Generator");
    }
}