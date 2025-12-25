<?php
/**
 * OnboardingPromptBuilder - สร้าง Prompt สำหรับ AI
 */

namespace Modules\Onboarding;

class OnboardingPromptBuilder {
    
    private $knowledgeBase;
    
    public function __construct() {
        $this->knowledgeBase = new SystemKnowledgeBase();
    }
    
    /**
     * Build system prompt with context
     */
    public function buildSystemPrompt(array $setupStatus, array $context = []): string {
        $completionPercent = $this->calculateCompletion($setupStatus);
        $pendingItems = $this->getPendingItems($setupStatus);
        $completedItems = $this->getCompletedItems($setupStatus);
        
        $businessType = $context['business_type'] ?? 'retail';
        $currentPage = $context['current_page'] ?? null;
        
        $prompt = <<<PROMPT
คุณคือ "Kiro Assistant" ผู้ช่วย AI สำหรับระบบ LINE CRM SaaS ที่ช่วยผู้ใช้ตั้งค่าและใช้งานระบบ

## บทบาทของคุณ
- ช่วยนำทางผู้ใช้ในการตั้งค่าระบบ
- อธิบายฟีเจอร์ต่างๆ อย่างเข้าใจง่าย
- ให้คำแนะนำที่เหมาะกับธุรกิจของผู้ใช้
- ช่วยแก้ปัญหาเบื้องต้น

## สถานะการตั้งค่าปัจจุบัน
- ความคืบหน้า: {$completionPercent}%
- รายการที่ทำเสร็จแล้ว: {$completedItems}
- รายการที่ยังไม่ได้ทำ: {$pendingItems}

## ประเภทธุรกิจ
{$businessType}

## หลักการตอบ
1. ตอบเป็นภาษาไทยเสมอ
2. ใช้ภาษาที่เป็นมิตรและเข้าใจง่าย
3. ให้คำแนะนำที่ปฏิบัติได้จริง
4. ถ้าผู้ใช้ถามเรื่องที่ยังไม่ได้ตั้งค่า ให้แนะนำวิธีตั้งค่า
5. ใช้ emoji เพื่อให้อ่านง่าย แต่ไม่มากเกินไป
6. ถ้าต้องการให้ผู้ใช้ไปหน้าอื่น ให้บอก URL ที่ชัดเจน

## ฟีเจอร์หลักของระบบ
- Inbox: จัดการข้อความจากลูกค้า
- Users: จัดการข้อมูลลูกค้า
- Shop: ร้านค้าออนไลน์
- Broadcast: ส่งข้อความหาลูกค้าหลายคน
- Rich Menu: เมนูลัดใน LINE
- Auto Reply: ตอบกลับอัตโนมัติ
- AI Chat: AI ตอบแชท
- Loyalty: ระบบแต้มสะสม
- Analytics: สถิติและรายงาน

## รูปแบบการตอบ
- ใช้หัวข้อและ bullet points เพื่อความชัดเจน
- ถ้าเป็นขั้นตอน ให้ใส่ตัวเลขกำกับ
- ถ้ามี URL ให้ใส่ในรูปแบบ [ชื่อ](URL)
- ถ้าต้องการให้ผู้ใช้ทำอะไร ให้บอกชัดเจน
PROMPT;

        if ($currentPage) {
            $prompt .= "\n\n## หน้าปัจจุบันของผู้ใช้\n{$currentPage}";
        }
        
        return $prompt;
    }
    
    /**
     * Build user prompt with knowledge
     */
    public function buildUserPrompt(string $message, array $relevantKnowledge = []): string {
        $prompt = $message;
        
        if (!empty($relevantKnowledge)) {
            $prompt .= "\n\n---\nข้อมูลอ้างอิง:\n";
            foreach ($relevantKnowledge as $topic => $knowledge) {
                $prompt .= "\n### {$knowledge['title']}\n{$knowledge['content']}\n";
            }
        }
        
        return $prompt;
    }
    
    /**
     * Extract intent from message
     */
    public function extractIntent(string $message): array {
        $message = mb_strtolower($message);
        
        $intents = [
            'greeting' => ['สวัสดี', 'หวัดดี', 'hello', 'hi', 'ดี'],
            'help' => ['ช่วย', 'help', 'ไม่เข้าใจ', 'ยังไง', 'อย่างไร'],
            'setup_line' => ['line', 'ไลน์', 'เชื่อมต่อ', 'connect', 'token', 'webhook'],
            'setup_shop' => ['ร้าน', 'shop', 'สินค้า', 'product', 'ขาย'],
            'setup_liff' => ['liff', 'ลิฟ'],
            'setup_payment' => ['ชำระ', 'payment', 'จ่าย', 'โอน', 'บัญชี', 'promptpay'],
            'setup_rich_menu' => ['rich menu', 'เมนู', 'menu'],
            'setup_auto_reply' => ['auto reply', 'ตอบอัตโนมัติ', 'ตอบกลับ'],
            'setup_ai' => ['ai', 'เอไอ', 'gemini', 'แชทบอท', 'chatbot'],
            'setup_broadcast' => ['broadcast', 'ส่งข้อความ', 'ประกาศ'],
            'setup_loyalty' => ['แต้ม', 'point', 'loyalty', 'สะสม'],
            'feature_info' => ['ฟีเจอร์', 'feature', 'ทำอะไรได้', 'มีอะไร'],
            'navigation' => ['ไปที่', 'หน้า', 'เปิด', 'ที่ไหน', 'อยู่ตรงไหน'],
            'troubleshoot' => ['ไม่ทำงาน', 'error', 'ปัญหา', 'ผิดพลาด', 'ไม่ได้'],
            'status' => ['สถานะ', 'status', 'ความคืบหน้า', 'progress', 'เหลืออะไร'],
            'tips' => ['tips', 'แนะนำ', 'เคล็ดลับ', 'ควรทำ']
        ];
        
        $detectedIntents = [];
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($message, $keyword) !== false) {
                    $detectedIntents[] = $intent;
                    break;
                }
            }
        }
        
        // Extract entities
        $entities = $this->extractEntities($message);
        
        return [
            'intents' => array_unique($detectedIntents),
            'entities' => $entities,
            'primary_intent' => $detectedIntents[0] ?? 'general'
        ];
    }
    
    /**
     * Extract entities from message
     */
    private function extractEntities(string $message): array {
        $entities = [];
        
        // Feature names
        $features = ['inbox', 'users', 'shop', 'broadcast', 'rich menu', 'auto reply', 'ai', 'loyalty', 'analytics'];
        foreach ($features as $feature) {
            if (mb_strpos(mb_strtolower($message), $feature) !== false) {
                $entities['feature'] = $feature;
                break;
            }
        }
        
        // Business types
        $businessTypes = ['ร้านยา', 'pharmacy', 'ร้านค้า', 'retail', 'บริการ', 'service', 'ร้านอาหาร', 'restaurant'];
        foreach ($businessTypes as $type) {
            if (mb_strpos(mb_strtolower($message), $type) !== false) {
                $entities['business_type'] = $type;
                break;
            }
        }
        
        return $entities;
    }
    
    /**
     * Get relevant knowledge for message
     */
    public function getRelevantKnowledge(string $message): array {
        $intent = $this->extractIntent($message);
        $knowledge = [];
        
        $intentToTopic = [
            'setup_line' => 'line_connection',
            'setup_shop' => 'shop',
            'setup_liff' => 'liff',
            'setup_rich_menu' => 'rich_menu',
            'setup_auto_reply' => 'auto_reply',
            'setup_ai' => 'ai_chat',
            'setup_broadcast' => 'broadcast',
            'setup_loyalty' => 'loyalty'
        ];
        
        foreach ($intent['intents'] as $intentName) {
            if (isset($intentToTopic[$intentName])) {
                $topic = $intentToTopic[$intentName];
                $topicKnowledge = $this->knowledgeBase->getKnowledge($topic);
                if ($topicKnowledge) {
                    $knowledge[$topic] = $topicKnowledge;
                }
            }
        }
        
        // Also search by keyword
        $searchResults = $this->knowledgeBase->searchKnowledge($message);
        foreach ($searchResults as $key => $item) {
            if (!isset($knowledge[$key])) {
                $knowledge[$key] = $item;
            }
        }
        
        return $knowledge;
    }
    
    /**
     * Calculate completion percentage
     */
    private function calculateCompletion(array $setupStatus): int {
        $total = 0;
        $completed = 0;
        
        foreach ($setupStatus as $category => $items) {
            foreach ($items as $item) {
                $total++;
                if ($item['completed'] ?? false) {
                    $completed++;
                }
            }
        }
        
        return $total > 0 ? round(($completed / $total) * 100) : 0;
    }
    
    /**
     * Get pending items as string
     */
    private function getPendingItems(array $setupStatus): string {
        $pending = [];
        foreach ($setupStatus as $category => $items) {
            foreach ($items as $item) {
                if (!($item['completed'] ?? false)) {
                    $pending[] = $item['label'] ?? $item['key'] ?? 'Unknown';
                }
            }
        }
        return implode(', ', $pending) ?: 'ไม่มี';
    }
    
    /**
     * Get completed items as string
     */
    private function getCompletedItems(array $setupStatus): string {
        $completed = [];
        foreach ($setupStatus as $category => $items) {
            foreach ($items as $item) {
                if ($item['completed'] ?? false) {
                    $completed[] = $item['label'] ?? $item['key'] ?? 'Unknown';
                }
            }
        }
        return implode(', ', $completed) ?: 'ไม่มี';
    }
    
    /**
     * Build welcome message
     */
    public function buildWelcomeMessage(string $userName, int $completionPercent, ?array $nextAction): string {
        $greeting = "สวัสดีครับ คุณ{$userName}! 👋\n\n";
        $greeting .= "ยินดีต้อนรับสู่ระบบ LINE CRM ผมคือ Kiro Assistant พร้อมช่วยคุณตั้งค่าและใช้งานระบบครับ\n\n";
        
        if ($completionPercent < 100) {
            $greeting .= "📊 ความคืบหน้าการตั้งค่า: {$completionPercent}%\n\n";
            
            if ($nextAction) {
                $greeting .= "📌 แนะนำให้ทำต่อ: **{$nextAction['label']}**\n";
                $greeting .= "{$nextAction['description']}\n";
                $greeting .= "👉 [ไปตั้งค่า]({$nextAction['url']})\n\n";
            }
        } else {
            $greeting .= "🎉 ยินดีด้วย! คุณตั้งค่าระบบครบถ้วนแล้ว\n\n";
            $greeting .= "ลองสำรวจฟีเจอร์ขั้นสูงเพิ่มเติมได้เลยครับ\n\n";
        }
        
        $greeting .= "ถามผมได้เลยครับ ผมพร้อมช่วยเหลือ! 😊";
        
        return $greeting;
    }
}
