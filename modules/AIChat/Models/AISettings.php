<?php
/**
 * Model: AI Settings
 * จัดการข้อมูลการตั้งค่า AI Chat ในฐานข้อมูล
 */

namespace Modules\AIChat\Models;

use Modules\Core\Database;

class AISettings
{
    private Database $db;
    private ?int $lineAccountId;
    private array $settings = [];
    
    // ค่าเริ่มต้น
    private const DEFAULT_SETTINGS = [
        'is_enabled' => false,
        'model' => 'gemini-2.5-flash',
        'temperature' => 0.5,
        'max_tokens' => 2048,
        'response_style' => 'friendly',
        'fallback_message' => 'ขออภัยค่ะ ไม่เข้าใจคำถาม กรุณาติดต่อเจ้าหน้าที่',
        'system_prompt' => '',
        'business_info' => '',
        'product_knowledge' => '',
        'sender_name' => '',
        'sender_icon' => '',
        'quick_reply_buttons' => ''
    ];
    
    public function __construct(?int $lineAccountId = null)
    {
        $this->db = Database::getInstance();
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }
    
    /**
     * โหลดการตั้งค่าจากฐานข้อมูล
     */
    private function loadSettings(): void
    {
        $this->settings = self::DEFAULT_SETTINGS;

        if (!$this->lineAccountId) {
            return;
        }

        $result = $this->db->fetchOne(
            "SELECT * FROM ai_chat_settings WHERE line_account_id = ?",
            [$this->lineAccountId]
        );

        $hadRow = false;
        if ($result) {
            $this->settings = array_merge($this->settings, $result);
            $hadRow = true;
        }

        // Fallback: the Gemini key often lives only in the legacy `ai_settings` table
        // (where the LINE Mini App finds it). Without this, OA chat replies
        // "AI ยังไม่ได้เปิดใช้งาน" even though the same tenant's Mini App works fine.
        // Mirror the Mini App's broad key lookup so both paths behave the same.
        if (empty($this->settings['gemini_api_key'])) {
            $fallbackKey = $this->resolveFallbackGeminiKey();
            if ($fallbackKey !== '') {
                $this->settings['gemini_api_key'] = $fallbackKey;
                // No explicit ai_chat_settings row → enable when a platform key exists.
                // An existing row's is_enabled flag is always respected (admin's choice).
                if (!$hadRow) {
                    $this->settings['is_enabled'] = true;
                }
            }
        }
    }

    /**
     * Resolve a usable Gemini API key from the broader settings sources, mirroring
     * api/ai-chat.php (ai_settings → ai_chat_settings → config GEMINI_API_KEY).
     */
    private function resolveFallbackGeminiKey(): string
    {
        foreach (['ai_settings', 'ai_chat_settings'] as $table) {
            try {
                $row = $this->db->fetchOne(
                    "SELECT gemini_api_key FROM {$table} WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> '' LIMIT 1",
                    []
                );
                if ($row && !empty(trim((string) ($row['gemini_api_key'] ?? '')))) {
                    return trim((string) $row['gemini_api_key']);
                }
            } catch (\Throwable $e) {
                // table may not exist in this tenant — keep trying
            }
        }
        if (defined('GEMINI_API_KEY') && constant('GEMINI_API_KEY') !== '') {
            return (string) constant('GEMINI_API_KEY');
        }
        return '';
    }
    
    /**
     * ตรวจสอบว่า AI เปิดใช้งานหรือไม่
     */
    public function isEnabled(): bool
    {
        return (bool) $this->settings['is_enabled'] && !empty($this->settings['gemini_api_key']);
    }
    
    public function getApiKey(): string
    {
        return $this->settings['gemini_api_key'] ?? '';
    }
    
    public function getModel(): string
    {
        return $this->settings['model'] ?? 'gemini-2.5-flash';
    }
    
    public function getSystemPrompt(): string
    {
        return $this->settings['system_prompt'] ?? '';
    }
    
    public function getResponseStyle(): string
    {
        return $this->settings['response_style'] ?? 'friendly';
    }
    
    public function getBusinessInfo(): string
    {
        return $this->settings['business_info'] ?? '';
    }
    
    public function getProductKnowledge(): string
    {
        return $this->settings['product_knowledge'] ?? '';
    }
    
    public function getFallbackMessage(): string
    {
        return $this->settings['fallback_message'] ?? self::DEFAULT_SETTINGS['fallback_message'];
    }
    
    public function getTemperature(): float
    {
        return (float) ($this->settings['temperature'] ?? 0.5);
    }
    
    public function getMaxTokens(): int
    {
        return (int) ($this->settings['max_tokens'] ?? 2048);
    }
    
    public function getSenderName(): string
    {
        return $this->settings['sender_name'] ?? '';
    }
    
    public function getSenderIcon(): string
    {
        return $this->settings['sender_icon'] ?? '';
    }
    
    public function getQuickReplyButtons(): array
    {
        $buttons = $this->settings['quick_reply_buttons'] ?? '';
        if (empty($buttons)) {
            return [];
        }
        return json_decode($buttons, true) ?: [];
    }
    
    public function getAll(): array
    {
        return $this->settings;
    }
    
    public function getLineAccountId(): ?int
    {
        return $this->lineAccountId;
    }
}
