<?php
/**
 * Check AI logs for debugging consecutive calls
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>AI Logs (Last 30)</h1>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";

$stmt = $db->query("
    SELECT created_at, source, message, data 
    FROM dev_logs 
    WHERE source LIKE '%AI%' 
       OR source LIKE '%Gemini%' 
       OR message LIKE '%Gemini%'
       OR message LIKE '%generateResponse%'
    ORDER BY created_at DESC 
    LIMIT 30
");

echo "<table border='1' cellpadding='5' style='font-size:11px'>";
echo "<tr style='background:#f0f0f0'><th>Time</th><th>Source</th><th>Message</th><th>Data</th></tr>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $bgColor = 'white';
    if (strpos($row['message'], 'error') !== false || strpos($row['message'], 'Error') !== false) {
        $bgColor = '#ffe0e0';
    } elseif (strpos($row['message'], 'response') !== false) {
        $bgColor = '#e0ffe0';
    }
    
    echo "<tr style='background:{$bgColor}'>";
    echo "<td nowrap>" . $row['created_at'] . "</td>";
    echo "<td>" . htmlspecialchars($row['source']) . "</td>";
    echo "<td>" . htmlspecialchars($row['message']) . "</td>";
    echo "<td><pre style='max-width:400px;overflow:auto;margin:0;font-size:10px;white-space:pre-wrap'>" . htmlspecialchars(mb_substr($row['data'] ?? '', 0, 300)) . "</pre></td>";
    echo "</tr>";
}
echo "</table>";

// Also show ai_chat_logs
echo "<h2>AI Chat Logs (Last 10)</h2>";
try {
    $stmt = $db->query("SELECT * FROM ai_chat_logs ORDER BY created_at DESC LIMIT 10");
    echo "<table border='1' cellpadding='5' style='font-size:11px'>";
    echo "<tr><th>Time</th><th>User Msg</th><th>AI Response</th><th>Time(ms)</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td nowrap>" . $row['created_at'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['user_message'] ?? '', 0, 50)) . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['ai_response'] ?? '', 0, 100)) . "</td>";
        echo "<td>" . ($row['response_time_ms'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
