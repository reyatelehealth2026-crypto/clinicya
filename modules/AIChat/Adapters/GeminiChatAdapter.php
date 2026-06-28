<?php
/**
 * GeminiChat Adapter
 * เชื่อมต่อ Module ใหม่กับระบบเก่า (Backward Compatible)
 * 
 * ใช้แทน classes/GeminiChat.php โดยไม่ต้องแก้ไข webhook.php
 */

namespace Modules\AIChat\Adapters;

// โหลด Module files
require_once __DIR__ . '/../Autoloader.php';
loadAIChatModule();

use Modules\AIChat\Services\ContextAnalyzer;
use Modules\AIChat\Services\PromptBuilder;
use Modules\AIChat\Services\GeminiAPI;
use Modules\AIChat\Models\AISettings;
use Modules\AIChat\Models\ConversationHistory;

class GeminiChatAdapter
{
    private AISettings $settings;
    private ConversationHistory $historyModel;
    private ContextAnalyzer $contextAnalyzer;
    private PromptBuilder $promptBuilder;
    private GeminiAPI $geminiAPI;
    private $db;
    private ?int $lineAccountId;
    
    public function __construct($db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        
        // สร้าง instances
        $this->settings = new AISettings($lineAccountId);
        $this->historyModel = new ConversationHistory();
        $this->contextAnalyzer = new ContextAnalyzer();
        $this->promptBuilder = new PromptBuilder($this->settings);
        $this->geminiAPI = new GeminiAPI($this->settings);
    }
    
    /**
     * ตรวจสอบว่า AI เปิดใช้งานหรือไม่
     */
    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }
    
    /**
     * ดึงประวัติการสนทนา
     */
    public function getConversationHistory(int $userId, int $limit = 10): array
    {
        return $this->historyModel->getRecentHistory($userId, $limit);
    }
    
    /**
     * สร้างการตอบกลับ (Compatible กับ GeminiChat เก่า)
     */
    public function generateResponse(string $userMessage, ?int $userId = null, array $conversationHistory = []): ?string
    {
        if (!$userId) {
            return null;
        }
        
        $result = $this->generateResponseWithMessage($userMessage, $userId);
        
        if ($result['success']) {
            return $result['response'];
        }
        
        return $this->settings->getFallbackMessage();
    }
    
    /**
     * สร้างการตอบกลับพร้อม LINE Message Object
     */
    public function generateResponseWithMessage(string $userMessage, int $userId): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'error' => 'AI is not enabled'
            ];
        }
        
        try {
            // 0. ตรวจจับ+จดจำข้อมูลสุขภาพสำคัญจากข้อความ (แพ้ยา/โรคประจำตัว) เป็น note advisory
            $this->historyModel->recordHealthMentions($userId, $userMessage);

            // 1. ดึงประวัติการสนทนา
            $history = $this->historyModel->getRecentHistory($userId, 10);
            
            // 2. เพิ่มข้อความใหม่เข้าไปใน history
            $fullHistory = $history;
            $fullHistory[] = ['role' => 'user', 'content' => $userMessage];
            
            // 3. ดึงข้อมูลลูกค้า
            $customerInfo = $this->historyModel->getCustomerInfo($userId);
            
            // 4. สร้าง System Prompt
            $systemPrompt = $this->promptBuilder->build($fullHistory, $customerInfo);
            
            // 5. เรียก Gemini API
            $result = $this->geminiAPI->sendMessage($systemPrompt, $fullHistory);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'response' => $this->settings->getFallbackMessage(),
                    'error' => $result['error']
                ];
            }
            
            // 6. สร้าง LINE Message พร้อม Sender และ Quick Reply
            $message = $this->buildLINEMessage($result['text']);
            
            return [
                'success' => true,
                'response' => $result['text'],
                'message' => $message
            ];
            
        } catch (\Exception $e) {
            error_log("GeminiChatAdapter error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => $this->settings->getFallbackMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * สร้าง LINE Message Object พร้อม Sender และ Quick Reply
     */
    private function buildLINEMessage(string $text): array
    {
        $message = [
            'type' => 'text',
            'text' => $text
        ];
        
        // เพิ่ม Sender
        $senderName = $this->settings->getSenderName();
        if (!empty($senderName)) {
            $message['sender'] = ['name' => $senderName];
            
            $senderIcon = $this->settings->getSenderIcon();
            if (!empty($senderIcon)) {
                $message['sender']['iconUrl'] = $senderIcon;
            }
        }
        
        // เพิ่ม Quick Reply - รองรับหลาย action types
        $quickReplyButtons = $this->settings->getQuickReplyButtons();
        if (!empty($quickReplyButtons)) {
            $items = [];
            foreach ($quickReplyButtons as $btn) {
                if (empty($btn['label'])) continue;
                
                $item = ['type' => 'action'];
                $actionType = $btn['type'] ?? 'message';
                
                switch ($actionType) {
                    case 'message':
                        if (empty($btn['text'])) continue 2;
                        $item['action'] = [
                            'type' => 'message',
                            'label' => $btn['label'],
                            'text' => $btn['text']
                        ];
                        break;
                        
                    case 'uri':
                        if (empty($btn['uri'])) continue 2;
                        $item['action'] = [
                            'type' => 'uri',
                            'label' => $btn['label'],
                            'uri' => $btn['uri']
                        ];
                        break;
                        
                    case 'postback':
                        if (empty($btn['data'])) continue 2;
                        $item['action'] = [
                            'type' => 'postback',
                            'label' => $btn['label'],
                            'data' => $btn['data']
                        ];
                        if (!empty($btn['displayText'])) {
                            $item['action']['displayText'] = $btn['displayText'];
                        }
                        break;
                        
                    case 'datetimepicker':
                        if (empty($btn['data'])) continue 2;
                        $item['action'] = [
                            'type' => 'datetimepicker',
                            'label' => $btn['label'],
                            'data' => $btn['data'],
                            'mode' => $btn['mode'] ?? 'datetime'
                        ];
                        break;
                        
                    case 'camera':
                        $item['action'] = [
                            'type' => 'camera',
                            'label' => $btn['label']
                        ];
                        break;
                        
                    case 'cameraRoll':
                        $item['action'] = [
                            'type' => 'cameraRoll',
                            'label' => $btn['label']
                        ];
                        break;
                        
                    case 'location':
                        $item['action'] = [
                            'type' => 'location',
                            'label' => $btn['label']
                        ];
                        break;
                        
                    default:
                        continue 2;
                }
                
                $items[] = $item;
            }
            
            if (!empty($items)) {
                $message['quickReply'] = ['items' => array_slice($items, 0, 13)];
            }
        }
        
        return $message;
    }
    
    /**
     * ดึงข้อมูลสุขภาพลูกค้า
     */
    public function getUserMedicalInfo(int $userId): ?array
    {
        return $this->historyModel->getCustomerInfo($userId);
    }
    
    /**
     * ทดสอบ API Key
     */
    public function testApiKey(): array
    {
        return $this->geminiAPI->testApiKey();
    }
}
