<?php
/**
 * Fix Location Message Encoding
 * แก้ไขปัญหาภาษาไทยแสดงเป็นตัวอักษรเพี้ยนใน location messages
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Fix Location Message Encoding ===\n\n";

try {
    // Set connection to UTF-8
    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");
    
    // Get all location messages
    $stmt = $db->query("
        SELECT id, content, created_at 
        FROM messages 
        WHERE message_type = 'location' 
        AND content LIKE '%[location]%'
        ORDER BY created_at DESC
        LIMIT 100
    ");
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fixed = 0;
    $skipped = 0;
    
    echo "Found " . count($messages) . " location messages\n\n";
    
    foreach ($messages as $msg) {
        $content = $msg['content'];
        
        // Check if content has encoding issues (contains � or weird characters)
        if (mb_check_encoding($content, 'UTF-8')) {
            // Try to detect if it's double-encoded or has issues
            $decoded = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            
            if ($decoded !== $content) {
                // Update the message
                $updateStmt = $db->prepare("UPDATE messages SET content = ? WHERE id = ?");
                $updateStmt->execute([$decoded, $msg['id']]);
                
                echo "✓ Fixed message ID {$msg['id']}\n";
                echo "  Before: " . substr($content, 0, 100) . "\n";
                echo "  After:  " . substr($decoded, 0, 100) . "\n\n";
                $fixed++;
            } else {
                $skipped++;
            }
        } else {
            // Content is not valid UTF-8, try to fix it
            // This might be Latin1 or Windows-1252 encoded
            $fixed_content = mb_convert_encoding($content, 'UTF-8', 'auto');
            
            if ($fixed_content !== $content) {
                $updateStmt = $db->prepare("UPDATE messages SET content = ? WHERE id = ?");
                $updateStmt->execute([$fixed_content, $msg['id']]);
                
                echo "✓ Fixed encoding for message ID {$msg['id']}\n";
                echo "  Before: " . substr($content, 0, 100) . "\n";
                echo "  After:  " . substr($fixed_content, 0, 100) . "\n\n";
                $fixed++;
            } else {
                $skipped++;
            }
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Total messages: " . count($messages) . "\n";
    echo "Fixed: $fixed\n";
    echo "Skipped (already correct): $skipped\n";
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
