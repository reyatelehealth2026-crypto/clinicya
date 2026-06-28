<?php
/**
 * ProductFlexTemplates - สร้าง Flex Message สำหรับแสดงสินค้ายา
 */

namespace Modules\AIChat\Templates;

class ProductFlexTemplates
{
    private $baseUrl;
    
    public function __construct($baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?: 'https://likesms.net/v1';
    }
    
    /**
     * สร้าง Flex Message แสดงสินค้าหลายรายการ (Carousel)
     */
    public function createProductCarousel(array $products, string $title = 'สินค้าแนะนำ'): array
    {
        if (empty($products)) {
            return $this->createTextMessage('ไม่พบสินค้าที่เกี่ยวข้องค่ะ');
        }
        
        $bubbles = [];
        foreach (array_slice($products, 0, 10) as $product) {
            $bubbles[] = $this->createProductBubble($product);
        }
        
        return [
            'type' => 'flex',
            'altText' => $title,
            'contents' => [
                'type' => 'carousel',
                'contents' => $bubbles
            ]
        ];
    }
    
    /**
     * สร้าง Bubble สำหรับสินค้าเดี่ยว - ขนาดกว้างขึ้น เตี้ยลง
     */
    public function createProductBubble(array $product): array
    {
        $name = $product['name'] ?? 'ไม่ระบุชื่อ';
        $price = number_format($product['price'] ?? 0, 0);
        $salePrice = isset($product['sale_price']) && $product['sale_price'] > 0 
            ? number_format($product['sale_price'], 0) 
            : null;
        $sku = $product['sku'] ?? $product['barcode'] ?? '';
        $genericName = $product['generic_name'] ?? '';
        $stock = $product['stock'] ?? 0;
        $unit = $product['unit'] ?? 'ชิ้น';
        
        // รูปภาพ
        $imageUrl = $this->getProductImage($product);
        
        // Shop URL — new Mini App (not the retired /liff-shop.php SPA)
        $liffShopUrl = $this->baseUrl . '/miniapp/shop/';
        
        // CNY Product Detail URL
        $cnyDetailUrl = 'https://www.cnypharmacy.com/Pre-product/' . urlencode($sku);
        
        // สร้าง body contents - กระชับขึ้น
        $bodyContents = [
            [
                'type' => 'text',
                'text' => mb_substr($name, 0, 35),
                'weight' => 'bold',
                'size' => 'sm',
                'wrap' => true,
                'maxLines' => 2
            ]
        ];
        
        // ตัวยาสำคัญ
        if ($genericName) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => mb_substr($genericName, 0, 25),
                'size' => 'xxs',
                'color' => '#888888'
            ];
        }
        
        // ราคา - compact
        $priceText = $salePrice ? "฿{$salePrice}" : "฿{$price}";
        $bodyContents[] = [
            'type' => 'text',
            'text' => $priceText . "/{$unit}",
            'weight' => 'bold',
            'size' => 'md',
            'color' => $salePrice ? '#ff5551' : '#06C755',
            'margin' => 'sm'
        ];
        
        // สถานะสต็อก
        $stockText = $stock > 0 ? "✅ มีสินค้า" : "❌ หมด";
        $bodyContents[] = [
            'type' => 'text',
            'text' => $stockText,
            'size' => 'xxs',
            'color' => $stock > 0 ? '#06C755' : '#ff5551'
        ];
        
        // Footer - ปุ่มเล็กๆ แนวนอน
        $footerContents = [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'contents' => []
        ];
        
        // ปุ่มสั่งซื้อ -> ไป LIFF Shop
        if ($stock > 0) {
            $footerContents['contents'][] = [
                'type' => 'button',
                'style' => 'primary',
                'color' => '#06C755',
                'height' => 'sm',
                'action' => [
                    'type' => 'uri',
                    'label' => '🛒 สั่งซื้อ',
                    'uri' => $liffShopUrl
                ]
            ];
        }
        
        // ปุ่มข้อมูลยา -> ไป CNY Pharmacy
        $footerContents['contents'][] = [
            'type' => 'button',
            'style' => 'secondary',
            'height' => 'sm',
            'action' => [
                'type' => 'uri',
                'label' => '📋 ข้อมูลยา',
                'uri' => $cnyDetailUrl
            ]
        ];
        
        return [
            'type' => 'bubble',
            'size' => 'kilo', // ขนาดกว้างขึ้น (kilo > micro)
            'hero' => [
                'type' => 'image',
                'url' => $imageUrl,
                'size' => 'full',
                'aspectRatio' => '4:3', // กว้างขึ้น เตี้ยลง
                'aspectMode' => 'cover'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'spacing' => 'xs',
                'paddingAll' => '10px'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [$footerContents],
                'paddingAll' => '10px'
            ]
        ];
    }
    
    /**
     * สร้าง Flex Message แสดงรายละเอียดสินค้าเดี่ยว
     */
    public function createProductDetail(array $product): array
    {
        $name = $product['name'] ?? 'ไม่ระบุชื่อ';
        $price = number_format($product['price'] ?? 0, 0);
        $sku = $product['sku'] ?? $product['barcode'] ?? '';
        $genericName = $product['generic_name'] ?? '';
        $usage = $product['usage_instructions'] ?? 'ไม่ระบุ';
        $description = $product['description'] ?? '';
        $stock = $product['stock'] ?? 0;
        $unit = $product['unit'] ?? 'ชิ้น';
        $imageUrl = $this->getProductImage($product);
        
        // Clean description (remove HTML)
        $description = strip_tags($description);
        $description = mb_substr($description, 0, 200);
        
        $bodyContents = [
            [
                'type' => 'text',
                'text' => $name,
                'weight' => 'bold',
                'size' => 'lg',
                'wrap' => true
            ],
            [
                'type' => 'text',
                'text' => '฿' . $price . '/' . $unit,
                'weight' => 'bold',
                'size' => 'xl',
                'color' => '#06C755',
                'margin' => 'md'
            ]
        ];
        
        if ($sku) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => '📦 SKU: ' . $sku,
                'size' => 'sm',
                'color' => '#8c8c8c',
                'margin' => 'md'
            ];
        }
        
        if ($genericName) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => '💊 ตัวยา: ' . $genericName,
                'size' => 'sm',
                'color' => '#8c8c8c',
                'wrap' => true
            ];
        }
        
        // วิธีใช้
        if ($usage && $usage !== 'ไม่ระบุ') {
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg'
            ];
            $bodyContents[] = [
                'type' => 'text',
                'text' => '📝 วิธีใช้:',
                'weight' => 'bold',
                'size' => 'sm',
                'margin' => 'lg'
            ];
            $bodyContents[] = [
                'type' => 'text',
                'text' => mb_substr($usage, 0, 150),
                'size' => 'sm',
                'color' => '#555555',
                'wrap' => true
            ];
        }
        
        // สถานะสต็อก
        $stockText = $stock > 0 ? "✅ มีสินค้า ({$stock} {$unit})" : "❌ สินค้าหมด";
        $bodyContents[] = [
            'type' => 'text',
            'text' => $stockText,
            'size' => 'sm',
            'color' => $stock > 0 ? '#06C755' : '#ff5551',
            'margin' => 'lg'
        ];
        
        // Footer buttons
        $footerContents = [];
        
        if ($stock > 0) {
            $footerContents[] = [
                'type' => 'button',
                'style' => 'primary',
                'color' => '#06C755',
                'action' => [
                    'type' => 'message',
                    'label' => '🛒 สั่งซื้อเลย',
                    'text' => "สั่งซื้อ {$name} 1 {$unit}"
                ]
            ];
        }
        
        $footerContents[] = [
            'type' => 'button',
            'style' => 'secondary',
            'action' => [
                'type' => 'message',
                'label' => '💬 สอบถามเพิ่มเติม',
                'text' => "สอบถามเกี่ยวกับ {$name}"
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => "รายละเอียด: {$name}",
            'contents' => [
                'type' => 'bubble',
                'hero' => [
                    'type' => 'image',
                    'url' => $imageUrl,
                    'size' => 'full',
                    'aspectRatio' => '4:3',
                    'aspectMode' => 'cover'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $footerContents,
                    'spacing' => 'sm'
                ]
            ]
        ];
    }
    
    /**
     * สร้าง Flex Message ตอบคำถาม AI พร้อมสินค้าแนะนำ
     */
    public function createAIResponseWithProducts(string $aiResponse, array $products): array
    {
        $messages = [];
        
        // ข้อความตอบจาก AI
        $messages[] = [
            'type' => 'text',
            'text' => $aiResponse,
            'sender' => [
                'name' => '💊 เภสัชกร AI',
                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png'
            ]
        ];
        
        // ถ้ามีสินค้าแนะนำ ส่ง Carousel
        if (!empty($products)) {
            $messages[] = $this->createProductCarousel($products, 'สินค้าแนะนำสำหรับคุณ');
        }
        
        return $messages;
    }
    
    /**
     * ดึง URL รูปภาพสินค้า
     */
    private function getProductImage(array $product): string
    {
        // 1. ใช้ image_url จาก database
        if (!empty($product['image_url'])) {
            $url = $product['image_url'];
            
            // ถ้าเป็น relative path ให้เติม base URL
            if (strpos($url, 'http') !== 0) {
                $url = $this->baseUrl . '/' . ltrim($url, '/');
            }
            
            return $url;
        }
        
        // 2. ใช้ photo_path จาก CNY API
        if (!empty($product['photo_path'])) {
            return $product['photo_path'];
        }
        
        // 3. Placeholder ตามประเภทสินค้า
        $name = mb_strtolower($product['name'] ?? '');
        
        if (strpos($name, 'vitamin') !== false || strpos($name, 'วิตามิน') !== false) {
            return 'https://cdn-icons-png.flaticon.com/512/2927/2927347.png';
        }
        if (strpos($name, 'cream') !== false || strpos($name, 'ครีม') !== false) {
            return 'https://cdn-icons-png.flaticon.com/512/3163/3163186.png';
        }
        if (strpos($name, 'syrup') !== false || strpos($name, 'น้ำ') !== false) {
            return 'https://cdn-icons-png.flaticon.com/512/2936/2936690.png';
        }
        
        // Default: ไอคอนยาทั่วไป
        return 'https://cdn-icons-png.flaticon.com/512/3140/3140343.png';
    }
    
    /**
     * สร้าง Text Message ธรรมดา
     */
    private function createTextMessage(string $text): array
    {
        return [
            'type' => 'text',
            'text' => $text
        ];
    }
}
