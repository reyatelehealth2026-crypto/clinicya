<?php
/**
 * Service: Gemini API
 * เชื่อมต่อและเรียกใช้ Google Gemini API
 */

namespace Modules\AIChat\Services;

use Modules\AIChat\Models\AISettings;

class GeminiAPI
{
    private AISettings $settings;
    
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct(AISettings $settings)
    {
        $this->settings = $settings;
    }
    
    /**
     * ส่งข้อความไปยัง Gemini API แบบ Multi-turn Conversation
     */
    public function sendMessage(string $systemPrompt, array $conversationHistory): array
    {
        $apiKey = $this->settings->getApiKey();
        $model = $this->settings->getModel();
        
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'No API Key'];
        }
        
        $url = self::API_BASE . $model . ':generateContent?key=' . $apiKey;
        
        // สร้าง contents array สำหรับ multi-turn conversation
        $contents = $this->buildContents($systemPrompt, $conversationHistory);
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $this->settings->getTemperature(),
                'maxOutputTokens' => $this->settings->getMaxTokens(),
                'topP' => 0.9,
                'topK' => 20,
                // gemini-2.5-* spends output tokens on internal "thinking", which left
                // almost nothing for the visible reply (answers cut off mid-sentence).
                // Disable it so the full answer fits the token budget and stays fast/direct.
                'thinkingConfig' => ['thinkingBudget' => 0]
            ],
            'safetySettings' => $this->getSafetySettings()
        ];
        
        return $this->callAPI($url, $data);
    }
    
    private function buildContents(string $systemPrompt, array $history): array
    {
        $contents = [];
        
        // System prompt เป็น user message แรก
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $systemPrompt]]
        ];
        
        // Model acknowledgment
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => 'เข้าใจแล้วค่ะ จะทำตามกฎเหล็กทุกข้อ']]
        ];
        
        // เพิ่มประวัติการสนทนา
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]]
            ];
        }
        
        return $contents;
    }
    
    private function getSafetySettings(): array
    {
        return [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE']
        ];
    }
    
    private function callAPI(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? 'HTTP ' . $httpCode;
            return ['success' => false, 'error' => $errorMsg];
        }
        
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if ($text) {
            $text = trim($text);
            $text = preg_replace('/^\*\*|\*\*$/m', '', $text);
            
            return ['success' => true, 'text' => $text];
        }
        
        return ['success' => false, 'error' => 'No response text'];
    }
    
    /**
     * ทดสอบ API Key
     */
    public function testApiKey(): array
    {
        $apiKey = $this->settings->getApiKey();
        
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'No API Key'];
        }
        
        $url = self::API_BASE . 'gemini-2.5-flash:generateContent?key=' . $apiKey;
        
        $data = [
            'contents' => [
                ['parts' => [['text' => 'Reply OK']]]
            ]
        ];
        
        return $this->callAPI($url, $data);
    }
}
