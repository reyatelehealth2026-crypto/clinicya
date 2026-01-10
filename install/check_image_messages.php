<?php
/**
 * Check image messages in database
 */
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>📷 Image Messages Check</h2>";
    
    // Get recent image messages
    $stmt = $db->query("
        SELECT id, user_id, message_type, LEFT(content, 150) as content_preview, created_at 
        FROM messages 
        WHERE message_type = 'image' 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Recent Image Messages:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Content</th><th>Created</th></tr>";
    
    foreach ($images as $img) {
        $content = htmlspecialchars($img['content_preview']);
        echo "<tr>";
        echo "<td>{$img['id']}</td>";
        echo "<td>{$img['user_id']}</td>";
        echo "<td style='max-width:400px;word-break:break-all;'>{$content}</td>";
        echo "<td>{$img['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check uploads folder
    echo "<h3>Uploads Folder Check:</h3>";
    $uploadDir = __DIR__ . '/../uploads/line_images/';
    if (is_dir($uploadDir)) {
        echo "✅ Folder exists: {$uploadDir}<br>";
        echo "Writable: " . (is_writable($uploadDir) ? '✅ Yes' : '❌ No') . "<br>";
        
        $files = glob($uploadDir . '*');
        echo "Files count: " . count($files) . "<br>";
        if (count($files) > 0) {
            echo "<br>Recent files:<br>";
            $recentFiles = array_slice($files, -5);
            foreach ($recentFiles as $f) {
                echo "- " . basename($f) . "<br>";
            }
        }
    } else {
        echo "❌ Folder does not exist: {$uploadDir}";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
