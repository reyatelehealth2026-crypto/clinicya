<?php
/**
 * Service: Prompt Builder
 * สร้าง System Prompt - แนะนำยาทันทีเมื่อลูกค้าบอกอาการ
 */

namespace Modules\AIChat\Services;

use Modules\AIChat\Models\AISettings;
use Modules\Core\Database;

class PromptBuilder
{
    private AISettings $settings;
    private ContextAnalyzer $contextAnalyzer;
    private ?string $lastUserMessage = null;
    
    public function __construct(AISettings $settings)
    {
        $this->settings = $settings;
        $this->contextAnalyzer = new ContextAnalyzer();
    }
    
    /**
     * สร้าง System Prompt สำหรับ AI
     */
    public function build(array $conversationHistory, ?array $customerInfo = null): string
    {
        $parts = [];
        
        // เก็บข้อความล่าสุดของ user
        $this->lastUserMessage = '';
        foreach (array_reverse($conversationHistory) as $msg) {
            if ($msg['role'] === 'user') {
                $this->lastUserMessage = $msg['content'];
                break;
            }
        }
        
        // วิเคราะห์อาการจากข้อความ
        $extractedInfo = $this->contextAnalyzer->analyze($conversationHistory);
        
        // 1. กฎหลัก - แนะนำยาทันที
        $parts[] = $this->buildRules();
        
        // 2. บทบาท
        $parts[] = $this->buildRole();
        
        // 3. สินค้าในคลัง (สำคัญมาก!)
        $products = $this->getAllProducts();
        if ($products) {
            $parts[] = "[สินค้าในคลัง - ใช้แนะนำลูกค้า]\n" . $products;
        }
        
        // 4. สินค้าที่เกี่ยวข้องกับอาการ
        if (!empty($extractedInfo['อาการ'])) {
            $symptoms = explode(', ', $extractedInfo['อาการ']);
            $relatedProducts = $this->searchProductsBySymptom($symptoms);
            if ($relatedProducts) {
                $parts[] = $relatedProducts;
            }
        }
        
        // 5. ข้อมูลลูกค้า
        if ($customerInfo) {
            $parts[] = $this->buildCustomerInfo($customerInfo);
        }
        
        // 6. คำสั่งสำหรับการตอบ
        $parts[] = $this->buildInstruction($extractedInfo);
        
        return implode("\n\n", $parts);
    }
    
    private function buildRules(): string
    {
        return "
[หลักการตอบ - ผู้ช่วยเภสัชกร ปรึกษาก่อนเสนอขาย - ทำตามทุกข้อ]

1. คุณคือผู้ช่วยเภสัชกร ให้คำปรึกษาสุขภาพและยาเบื้องต้นที่ \"ถูกต้องและปลอดภัย\" ก่อนเสมอ แล้วค่อยเสนอสินค้า
2. เมื่อลูกค้าบอกอาการ: (ก) อธิบายสั้นๆ ว่าอาการนี้ดูแลอย่างไร + ตัวยาที่เหมาะ (ชื่อสามัญ) + วิธีใช้/ข้อควรระวังคร่าวๆ จากนั้น (ข) เสนอสินค้าในคลังที่ \"ตรงกับอาการจริงๆ\" พร้อมราคา
3. ความปลอดภัยสำคัญสุด: เสนอเฉพาะยาที่ตรงกับอาการเท่านั้น ห้ามจับคู่ยามั่ว (เช่น ห้ามเสนอยาแก้ไอให้คนปวดหัว) ถ้าในคลังไม่มียาที่เหมาะ ให้คำแนะนำทั่วไป + บอกตัวยาที่ควรใช้ ห้ามตอบแค่ \"ไม่มียาในคลัง\"
4. จำบริบทการสนทนา: ถ้าลูกค้าถามต่อ (เช่น วิธีใช้ กินยังไง ขนาดเท่าไหร่) ให้ตอบต่อยอดจากยาที่เพิ่งแนะนำ ห้ามตอบว่าไม่มีในคลัง
5. อาการรุนแรง/เรื้อรัง/เกินขอบเขตยาสามัญ (เช่น เจ็บหน้าอก หายใจลำบาก ตั้งครรภ์ เด็กเล็ก) → แนะนำให้ปรึกษาเภสัชกรหรือพบแพทย์
6. รู้จักลูกค้า: ถ้ามีข้อมูลลูกค้า (ชื่อ โรคประจำตัว แพ้ยา ยาที่ใช้อยู่) ให้นำมาปรับคำแนะนำเฉพาะตัวเสมอ เช่น เรียกชื่อ, เลี่ยง/เตือนยาที่ขัดกับโรคประจำตัวหรือยาที่ใช้อยู่, ห้ามแนะนำยาที่ลูกค้าแพ้ ทำให้ลูกค้ารู้สึกว่าเราจำและใส่ใจเขา
7. ตอบเป็นภาษาธรรมชาติ อบอุ่น กระชับ ห้ามใช้ bullet (*), ตัวเลขข้อ, หรือ **ตัวหนา**

[Flow การตอบ]
ลูกค้าบอกอาการ → ให้คำแนะนำที่ถูกต้องปลอดภัย → เสนอสินค้าในคลังที่ตรงอาการ (ถ้ามี) พร้อมราคา → ถามว่าสนใจรับไหม → รอเภสัชกรยืนยัน
";
    }
    
    private function buildRole(): string
    {
        $systemPrompt = $this->settings->getSystemPrompt();
        
        if (empty($systemPrompt)) {
            $systemPrompt = 'คุณคือผู้ช่วยเภสัชกรที่ให้คำปรึกษาด้านยาอย่างถูกต้องและปลอดภัย ใส่ใจอาการของลูกค้าก่อน แล้วจึงแนะนำยาที่เหมาะสมจริงๆ พร้อมเสนอสินค้าในคลัง ตอบสั้นกระชับ อบอุ่น เป็นกันเอง';
        }
        
        return "บทบาท: " . $systemPrompt;
    }
    
    private function buildCustomerInfo(array $info): string
    {
        $text = "[ข้อมูลลูกค้าคนนี้ - ใช้ปรับคำแนะนำให้เหมาะกับเขาโดยเฉพาะ ทำให้ลูกค้ารู้สึกว่าเราจำเขาได้]\n";

        if (!empty($info['display_name'])) {
            $text .= "- ชื่อ: {$info['display_name']} (เรียกชื่อลูกค้าอย่างเป็นกันเอง)\n";
        }
        if (!empty($info['medical_conditions'])) {
            $text .= "- โรคประจำตัว: {$info['medical_conditions']} (พิจารณาเสมอ เลี่ยงยาที่มีข้อห้ามกับโรคนี้ และเตือนลูกค้าถ้าเกี่ยวข้อง)\n";
        }
        if (!empty($info['drug_allergies'])) {
            $text .= "- แพ้ยา: {$info['drug_allergies']} (ห้ามแนะนำยากลุ่มนี้เด็ดขาด!)\n";
        }
        if (!empty($info['current_medications'])) {
            $text .= "- ยาที่ใช้อยู่ตอนนี้: {$info['current_medications']} (ระวังยาตีกัน/ซ้ำซ้อน เตือนลูกค้าถ้าจำเป็น)\n";
        }
        if (!empty($info['purchase_history'])) {
            $text .= "- เคยรับ/ซื้อยาล่าสุด: {$info['purchase_history']} (อ้างอิงได้อย่างเป็นธรรมชาติ เช่น \"คราวก่อนคุณรับ...ไป\")\n";
        }
        if (!empty($info['ai_notes'])) {
            $text .= "- สิ่งที่ลูกค้าเคยแจ้งไว้: {$info['ai_notes']} (จำไว้และพิจารณาเสมอ เช่น เลี่ยงยาที่เคยแจ้งว่าแพ้)\n";
        }

        // ชวนกรอกโปรไฟล์ถ้ายังไม่มีข้อมูลสุขภาพ — เพื่อแนะนำได้ปลอดภัย/เฉพาะตัวขึ้น
        $hasProfile = !empty($info['medical_conditions']) || !empty($info['drug_allergies']) || !empty($info['current_medications']);
        if (!$hasProfile) {
            $text .= "- (ลูกค้ายังไม่ได้กรอกโปรไฟล์สุขภาพ → ถ้าจังหวะเหมาะ ชวนให้แจ้งโรคประจำตัว/ยาที่ใช้ประจำ/ประวัติแพ้ยา สั้นๆ ครั้งเดียว เพื่อแนะนำยาได้ปลอดภัยและตรงกับคุณมากขึ้น อย่าตื๊อ)\n";
        }

        return $text;
    }
    
    private function buildInstruction(array $extractedInfo): string
    {
        // ถ้ามีอาการ → ปรึกษาก่อนแล้วค่อยเสนอสินค้าที่ตรงจริง
        if (!empty($extractedInfo['อาการ'])) {
            return "[คำสั่ง]: ลูกค้ามีอาการ \"{$extractedInfo['อาการ']}\" — ให้คำปรึกษาที่ถูกต้องและปลอดภัยก่อน (ตัวยาที่เหมาะกับอาการนี้จริงๆ วิธีใช้ ข้อควรระวัง) แล้วจึงเสนอเฉพาะสินค้าในคลังที่ตรงกับอาการนี้พร้อมราคา ถ้าไม่มีสินค้าที่เหมาะ ให้คำแนะนำทั่วไปโดยไม่เสนอยาที่ไม่เกี่ยวข้อง ตอบเป็นประโยคธรรมชาติ";
        }

        return "[คำสั่ง]: ตอบคำถามต่อเนื่องจากบริบทการสนทนาก่อนหน้า (เช่น วิธีใช้ยาที่เพิ่งแนะนำ) หรือถามอาการเพิ่มเติมอย่างสุภาพ ห้ามตอบว่าไม่มียาในคลังกับคำถามที่ต่อเนื่อง";
    }
    
    /**
     * ดึงสินค้าทั้งหมดจาก database
     */
    private function getAllProducts(): ?string
    {
        try {
            $db = Database::getInstance();
            
            // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
            $products = $db->fetchAll("
                SELECT name, price, generic_name, description
                FROM business_items 
                WHERE is_active = 1
                ORDER BY name ASC 
                LIMIT 50
            ");
            
            if (empty($products)) return null;
            
            $text = '';
            foreach ($products as $p) {
                $text .= "- {$p['name']}: {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $text .= " ({$p['generic_name']})";
                }
                $text .= "\n";
            }
            
            return $text;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * ค้นหาสินค้าตามอาการ
     */
    private function searchProductsBySymptom(array $symptoms, int $limit = 5): ?string
    {
        if (empty($symptoms)) return null;
        
        try {
            $db = Database::getInstance();
            
            // Map อาการ → keywords สินค้า
            $keywordMap = [
                'ปวดหัว' => ['พาราเซตามอล', 'paracetamol', 'ไทลินอล', 'แก้ปวด', 'sara'],
                'ปวดกล้ามเนื้อ' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'mydocalm', 'tolperisone', 'แก้ปวด'],
                'ปวดหลัง' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด', 'ibuprofen'],
                'ปวดคอ' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด'],
                'ปวดเมื่อย' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด'],
                'ไข้' => ['พาราเซตามอล', 'ลดไข้', 'ทิฟฟี่', 'tiffy'],
                'หวัด' => ['ทิฟฟี่', 'tiffy', 'decolgen', 'แก้หวัด'],
                'ไอ' => ['แก้ไอ', 'ไซรัป', 'ทิฟฟี่', 'cough'],
                'เจ็บคอ' => ['แก้เจ็บคอ', 'ลูกอม', 'strepsils', 'difflam'],
                'แพ้' => ['แก้แพ้', 'ซีร์เทค', 'cetirizine', 'loratadine'],
                'คัน' => ['แก้แพ้', 'ซีร์เทค', 'ยาทา', 'calamine'],
                'ท้องเสีย' => ['ท้องเสีย', 'smecta', 'ผงเกลือแร่', 'ors'],
                'ท้องผูก' => ['ยาระบาย', 'dulcolax', 'senokot'],
                'กรดไหลย้อน' => ['ลดกรด', 'antacid', 'omeprazole'],
                'ปวดท้อง' => ['buscopan', 'แก้ปวดท้อง', 'antacid'],
            ];
            
            $searchTerms = [];
            foreach ($symptoms as $symptom) {
                $symptom = mb_strtolower(trim($symptom));
                foreach ($keywordMap as $key => $terms) {
                    if (mb_strpos($symptom, $key) !== false) {
                        $searchTerms = array_merge($searchTerms, $terms);
                    }
                }
            }
            
            if (empty($searchTerms)) return null;
            
            // Build search query - ไม่ filter ตาม line_account_id
            $conditions = [];
            $params = [];
            foreach (array_unique($searchTerms) as $term) {
                $conditions[] = "(name LIKE ? OR description LIKE ? OR generic_name LIKE ?)";
                $params[] = "%{$term}%";
                $params[] = "%{$term}%";
                $params[] = "%{$term}%";
            }
            $params[] = $limit;
            
            $sql = "SELECT name, price, generic_name 
                    FROM business_items 
                    WHERE is_active = 1 
                    AND (" . implode(' OR ', $conditions) . ")
                    LIMIT ?";
            
            $products = $db->fetchAll($sql, $params);
            
            if (empty($products)) return null;
            
            $text = "[ยาที่แนะนำสำหรับอาการนี้ - ใช้ข้อมูลนี้ตอบลูกค้า!]\n";
            foreach ($products as $p) {
                $text .= "• {$p['name']}: {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $text .= " (ตัวยา: {$p['generic_name']})";
                }
                $text .= "\n";
            }
            
            return $text;
        } catch (\Exception $e) {
            return null;
        }
    }
}
