<?php
/**
 * LINE Content API
 * ดึงรูปภาพ/วิดีโอ/ไฟล์จาก LINE Message API
 * รองรับ thumbnail สำหรับ mobile - Requirements: 9.4
 */
header('Access-Control-Allow-Origin: *');

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LineAPI.php';
require_once '../classes/LineAccountManager.php';

$messageId = $_GET['id'] ?? null;
$accountId = $_GET['account'] ?? null;
$thumbnail = isset($_GET['thumb']) && $_GET['thumb'] == '1';
$maxWidth = intval($_GET['w'] ?? 300); // Default thumbnail width
$maxHeight = intval($_GET['h'] ?? 300); // Default thumbnail height

if (!$messageId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Message ID required']);
    exit;
}

// Limit max dimensions for security
$maxWidth = min($maxWidth, 800);
$maxHeight = min($maxHeight, 800);

try {
    $db = Database::getInstance()->getConnection();
    
    // Check cache first (for thumbnails)
    $cacheDir = __DIR__ . '/../uploads/cache/thumbnails/';
    $cacheFile = $cacheDir . md5($messageId . '_' . $maxWidth . '_' . $maxHeight) . '.jpg';
    
    if ($thumbnail && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        // Serve cached thumbnail
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($cacheFile));
        header('Cache-Control: public, max-age=86400');
        header('X-Cache: HIT');
        readfile($cacheFile);
        exit;
    }
    
    // Get LINE API instance
    $line = null;
    if ($accountId) {
        $manager = new LineAccountManager($db);
        $line = $manager->getLineAPI($accountId);
    } else {
        // Try to get default account
        $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
        $account = $stmt->fetch();
        if ($account) {
            $manager = new LineAccountManager($db);
            $line = $manager->getLineAPI($account['id']);
        } else {
            $line = new LineAPI();
        }
    }
    
    // Get content from LINE (returns binary data)
    $content = $line->getMessageContent($messageId);
    
    if ($content && strlen($content) > 100) {
        // Detect content type from binary data
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->buffer($content) ?: 'image/jpeg';
        
        // Generate thumbnail for mobile if requested - Requirements: 9.4
        if ($thumbnail && strpos($contentType, 'image/') === 0) {
            $thumbnailContent = generateThumbnail($content, $maxWidth, $maxHeight, $cacheDir, $cacheFile);
            if ($thumbnailContent) {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . strlen($thumbnailContent));
                header('Cache-Control: public, max-age=86400');
                header('X-Cache: MISS');
                echo $thumbnailContent;
                exit;
            }
        }
        
        // Return original content
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: public, max-age=86400');
        echo $content;
    } else {
        // Return placeholder image
        http_response_code(404);
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
            <rect fill="#f3f4f6" width="200" height="200"/>
            <text x="100" y="100" text-anchor="middle" fill="#9ca3af" font-size="14">รูปภาพไม่พร้อมใช้งาน</text>
        </svg>';
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Generate thumbnail from image content - Requirements: 9.4
 * @param string $content Original image binary data
 * @param int $maxWidth Maximum width
 * @param int $maxHeight Maximum height
 * @param string $cacheDir Cache directory path
 * @param string $cacheFile Cache file path
 * @return string|false Thumbnail binary data or false on failure
 */
function generateThumbnail($content, $maxWidth, $maxHeight, $cacheDir, $cacheFile) {
    // Check if GD library is available
    if (!function_exists('imagecreatefromstring')) {
        return false;
    }
    
    try {
        // Create image from string
        $sourceImage = @imagecreatefromstring($content);
        if (!$sourceImage) {
            return false;
        }
        
        // Get original dimensions
        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);
        
        // Calculate new dimensions maintaining aspect ratio
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        
        // Only resize if image is larger than max dimensions
        if ($ratio >= 1) {
            imagedestroy($sourceImage);
            return false; // Return original, no need to resize
        }
        
        $newWidth = intval($origWidth * $ratio);
        $newHeight = intval($origHeight * $ratio);
        
        // Create thumbnail
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        
        // Resize
        imagecopyresampled(
            $thumbnail, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $origWidth, $origHeight
        );
        
        // Output to buffer
        ob_start();
        imagejpeg($thumbnail, null, 80); // 80% quality for good balance
        $thumbnailContent = ob_get_clean();
        
        // Save to cache
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (is_writable(dirname($cacheFile)) || is_writable($cacheDir)) {
            @file_put_contents($cacheFile, $thumbnailContent);
        }
        
        // Cleanup
        imagedestroy($sourceImage);
        imagedestroy($thumbnail);
        
        return $thumbnailContent;
        
    } catch (Exception $e) {
        error_log('Thumbnail generation error: ' . $e->getMessage());
        return false;
    }
}
