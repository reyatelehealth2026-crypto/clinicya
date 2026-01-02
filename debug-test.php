<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: PHP OK<br>";

require_once 'config/config.php';
echo "Step 2: Config OK<br>";

require_once 'config/database.php';
echo "Step 3: Database class OK<br>";

$db = Database::getInstance()->getConnection();
echo "Step 4: Database connection OK<br>";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Step 5: Session OK<br>";

require_once 'includes/auth_check.php';
echo "Step 6: Auth check OK, role = " . ($currentUser['role'] ?? 'NOT SET') . "<br>";

// Read header.php content and eval line by line
$headerContent = file_get_contents('includes/header.php');
$lines = explode("\n", $headerContent);
$totalLines = count($lines);
echo "Step 7: Header has {$totalLines} lines<br>";

// Try to find where it breaks by including header
echo "Step 8: Including header...<br>";
flush();

// Check if header.php has syntax errors by trying to include it
$output = shell_exec('php -l includes/header.php 2>&1');
echo "PHP Lint: " . htmlspecialchars($output) . "<br>";

// Now try actual include
require_once 'includes/header.php';
echo "Step 9: Header included OK<br>";
