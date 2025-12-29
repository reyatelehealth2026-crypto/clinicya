<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

echo "<h3>Latest Triage Sessions with Full Data</h3>";

$stmt = $db->query("
    SELECT ts.id, ts.user_id, ts.status, ts.current_state, ts.triage_data, ts.created_at,
           u.display_name
    FROM triage_sessions ts
    LEFT JOIN users u ON ts.user_id = u.id
    ORDER BY ts.created_at DESC
    LIMIT 5
");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sessions as $s) {
    echo "<div style='border:1px solid #ccc; margin:10px; padding:10px;'>";
    echo "<h4>Session #{$s['id']} - {$s['display_name']}</h4>";
    echo "<p>Status: {$s['status']} | State: {$s['current_state']} | Created: {$s['created_at']}</p>";
    echo "<p><strong>triage_data:</strong></p>";
    echo "<pre style='background:#f5f5f5; padding:10px; overflow:auto;'>" . htmlspecialchars($s['triage_data'] ?? 'NULL') . "</pre>";
    
    $data = json_decode($s['triage_data'] ?? '{}', true);
    echo "<p><strong>Parsed:</strong></p>";
    echo "<ul>";
    echo "<li>symptoms: " . json_encode($data['symptoms'] ?? 'NOT SET') . "</li>";
    echo "<li>severity: " . ($data['severity'] ?? 'NOT SET') . "</li>";
    echo "<li>duration: " . ($data['duration'] ?? 'NOT SET') . "</li>";
    echo "<li>red_flags: " . json_encode($data['red_flags'] ?? 'NOT SET') . "</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<h3>AI Triage Assessments (Last 5)</h3>";
try {
    $stmt = $db->query("SELECT * FROM ai_triage_assessments ORDER BY created_at DESC LIMIT 5");
    $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Found: " . count($assessments) . " assessments</p>";
    foreach ($assessments as $a) {
        echo "<div style='border:1px solid #0a0; margin:10px; padding:10px;'>";
        echo "<p>ID: {$a['id']} | User: {$a['user_id']} | Severity: {$a['severity_level']} | Created: {$a['created_at']}</p>";
        echo "<p>Symptoms: " . htmlspecialchars($a['symptoms'] ?? '-') . "</p>";
        echo "<p>Duration: " . htmlspecialchars($a['duration'] ?? '-') . "</p>";
        echo "<p>AI Assessment: " . htmlspecialchars(substr($a['ai_assessment'] ?? '-', 0, 200)) . "</p>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>ai_triage_assessments table error: " . $e->getMessage() . "</p>";
}

