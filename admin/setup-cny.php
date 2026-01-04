<?php
/**
 * Setup CNY Products - Create table and sync data
 * This page can be accessed directly without login for initial setup
 */
session_start();

// Allow access for setup
$setupKey = $_GET['key'] ?? '';
$validKey = 'cny2024'; // Simple key for initial setup

if ($setupKey !== $validKey && !isset($_SESSION['user_id'])) {
    die('Access denied. Add ?key=cny2024 to URL or login first.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$step = $_GET['step'] ?? 'check';
$output = '';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>CNY Products Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">
                <i class="fas fa-cog text-blue-500 mr-3"></i>
                CNY Products Setup
            </h1>

            <?php if ($step === 'check'): ?>
                <?php
                // Check if table exists
                $tableExists = false;
                try {
                    $db->query("SELECT 1 FROM cny_products LIMIT 1");
                    $tableExists = true;
                    $stmt = $db->query("SELECT COUNT(*) FROM cny_products");
                    $productCount = $stmt->fetchColumn();
                } catch (PDOException $e) {
                    $tableExists = false;
                }
                ?>

                <div class="space-y-4 mb-6">
                    <div class="flex items-center p-4 <?= $tableExists ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200' ?> border rounded-lg">
                        <i class="fas <?= $tableExists ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-yellow-500' ?> text-2xl mr-4"></i>
                        <div>
                            <div class="font-semibold">Database Table</div>
                            <div class="text-sm text-gray-600">
                                <?php if ($tableExists): ?>
                                    ✓ Table exists with <?= number_format($productCount) ?> products
                                <?php else: ?>
                                    ✗ Table not created yet
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <?php if (!$tableExists): ?>
                    <a href="?key=<?= $setupKey ?>&step=migrate" 
                       class="block w-full text-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                        <i class="fas fa-database mr-2"></i>
                        Step 1: Create Database Table
                    </a>
                    <?php else: ?>
                    <div class="block w-full text-center px-6 py-3 bg-gray-300 text-gray-600 rounded-lg font-semibold cursor-not-allowed">
                        <i class="fas fa-check mr-2"></i>
                        Step 1: Table Already Created
                    </div>
                    <?php endif; ?>

                    <a href="?key=<?= $setupKey ?>&step=sync" 
                       class="block w-full text-center px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">
                        <i class="fas fa-sync-alt mr-2"></i>
                        Step 2: Sync Products from API
                    </a>

                    <a href="../shop/products-cny.php" 
                       class="block w-full text-center px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-semibold">
                        <i class="fas fa-arrow-right mr-2"></i>
                        Go to Products Page
                    </a>
                </div>

            <?php elseif ($step === 'migrate'): ?>
                <?php
                set_time_limit(60);
                ob_start();
                
                try {
                    echo "Creating cny_products table...\n";
                    
                    $sql = file_get_contents(__DIR__ . '/../database/migration_cny_products.sql');
                    $db->exec($sql);
                    
                    echo "✓ Table created successfully!\n";
                    echo "\nNext: Click 'Sync Products' to populate data.\n";
                    
                } catch (Exception $e) {
                    echo "✗ Error: " . $e->getMessage() . "\n";
                }
                
                $output = ob_get_clean();
                ?>

                <div class="bg-gray-900 text-green-400 p-6 rounded-lg mb-6 font-mono text-sm whitespace-pre-wrap">
<?= htmlspecialchars($output) ?>
                </div>

                <a href="?key=<?= $setupKey ?>&step=check" 
                   class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Setup
                </a>

            <?php elseif ($step === 'sync'): ?>
                <?php
                set_time_limit(300);
                ob_start();
                
                include __DIR__ . '/../cron/sync_cny_products.php';
                
                $output = ob_get_clean();
                ?>

                <div class="bg-gray-900 text-green-400 p-6 rounded-lg mb-6 font-mono text-sm whitespace-pre-wrap overflow-auto max-h-96">
<?= htmlspecialchars($output) ?>
                </div>

                <div class="space-y-3">
                    <a href="?key=<?= $setupKey ?>&step=check" 
                       class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Setup
                    </a>
                    <a href="../shop/products-cny.php" 
                       class="inline-block ml-3 px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">
                        <i class="fas fa-arrow-right mr-2"></i>
                        View Products
                    </a>
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>
