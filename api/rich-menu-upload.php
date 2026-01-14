<?php
/**
 * Rich Menu Image Upload API
 * รับ base64 image และ upload ไป LINE โดยตรง
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/LineAPI.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['richMenuId']) || empty($input['imageData'])) {
        throw new Exception('Missing richMenuId or imageData');
    }
    
    $richMenuId = $input['richMenuId'];
    $imageData = $input['imageData'];
    
    // Get LINE API
    $stmt = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        throw new Exception('No active LINE account');
    }
    
    // Decode base64 image
    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageData, $matches)) {
        $imageType = $matches[1];
        $imageContent = base64_decode($matches[2]);
    } else {
        throw new Exception('Invalid image data format');
    }
    
    // บีบอัดเพิ่มถ้าไฟล์ยังใหญ่เกิน 400KB
    $fileSize = strlen($imageContent);
    if ($fileSize > 400000) {
        $img = imagecreatefromstring($imageContent);
        if ($img) {
            ob_start();
            imagejpeg($img, null, 65); // quality 65
            $imageContent = ob_get_clean();
            imagedestroy($img);
            $imageType = 'jpeg';
            error_log("Rich Menu API: compressed to " . round(strlen($imageContent) / 1024) . "KB");
        }
    }
    
    $contentType = ($imageType === 'png') ? 'image/png' : 'image/jpeg';
    
    // Upload directly to LINE API (bypass nginx)
    $url = 'https://api-data.line.me/v2/bot/richmenu/' . $richMenuId . '/content';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $account['channel_access_token'],
        'Content-Type: ' . $contentType,
        'Content-Length: ' . strlen($imageContent)
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $imageContent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        error_log("Rich Menu uploaded: " . round(strlen($imageContent) / 1024) . "KB");
        echo json_encode(['success' => true, 'message' => 'Upload successful']);
    } else {
        error_log("LINE Rich Menu Upload failed: HTTP {$httpCode}, error: {$error}, response: {$response}");
        throw new Exception("LINE API error: HTTP {$httpCode} - " . ($response ?: $error));
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
