<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

$search = $_GET['q'] ?? 'คูป';
echo "<h3>Searching for: $search</h3>";

// Search in name
$stmt = $db->prepare("SELECT id, name, generic_name FROM business_items WHERE name LIKE ? OR generic_name LIKE ? LIMIT 30");
$stmt->execute(["%$search%", "%$search%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found: " . count($results) . " items</p>";
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Generic</th></tr>";
foreach ($results as $r) {
    echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['generic_name']}</td></tr>";
}
echo "</table>";
