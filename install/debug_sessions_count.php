<?php
/**
 * Debug triage sessions count
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Triage Sessions Count</h2>";

// Total count
$stmt = $db->query("SELECT COUNT(*) FROM triage_sessions");
$total = $stmt->fetchColumn();
echo "<p><strong>Total sessions in DB:</strong> {$total}</p>";

// Date range used in analytics
$startDate = date('Y-m-d', strtotime('-30 days'));
$endDate = date('Y-m-d');
echo "<p><strong>Date range:</strong> {$startDate} to {$endDate}</p>";

// Count with date filter
$stmt = $db->prepare("SELECT COUNT(*) FROM triage_sessions WHERE DATE(created_at) BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$filtered = $stmt->fetchColumn();
echo "<p><strong>Sessions in date range:</strong> {$filtered}</p>";

// Show all sessions with dates
echo "<h3>All Sessions:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>User ID</th><th>Status</th><th>Current State</th><th>Created At</th><th>In Range?</th></tr>";

$stmt = $db->query("SELECT id, user_id, status, current_state, created_at FROM triage_sessions ORDER BY id DESC");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sessions as $s) {
    $createdDate = date('Y-m-d', strtotime($s['created_at']));
    $inRange = ($createdDate >= $startDate && $createdDate <= $endDate) ? '✅' : '❌';
    echo "<tr>";
    echo "<td>{$s['id']}</td>";
    echo "<td>{$s['user_id']}</td>";
    echo "<td>" . ($s['status'] ?: 'NULL') . "</td>";
    echo "<td>" . ($s['current_state'] ?: 'NULL') . "</td>";
    echo "<td>{$s['created_at']}</td>";
    echo "<td>{$inRange}</td>";
    echo "</tr>";
}
echo "</table>";

// Sessions outside range
$stmt = $db->prepare("SELECT COUNT(*) FROM triage_sessions WHERE DATE(created_at) NOT BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$outside = $stmt->fetchColumn();
echo "<p><strong>Sessions outside date range:</strong> {$outside}</p>";
