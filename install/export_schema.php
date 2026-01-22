<?php
/**
 * Database Schema Export Tool
 * 
 * Export empty table structure (CREATE TABLE statements) from existing database
 * สำหรับ export โครงสร้างตารางเปล่าๆ จากฐานข้อมูลที่มีอยู่
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Try to load config
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Check if running from CLI or Web
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
}

// Database credentials - from config or manual input
$dbHost = defined('DB_HOST') ? DB_HOST : ($_POST['host'] ?? 'localhost');
$dbName = defined('DB_NAME') ? DB_NAME : ($_POST['name'] ?? '');
$dbUser = defined('DB_USER') ? DB_USER : ($_POST['user'] ?? '');
$dbPass = defined('DB_PASS') ? DB_PASS : ($_POST['pass'] ?? '');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Show form if no config and no POST
if (empty($dbName) && empty($_POST)) {
    showForm();
    exit;
}

// If action is export
if ($action === 'export' || !empty($dbName)) {
    // Use POST data if available
    if (!empty($_POST['name'])) {
        $dbHost = $_POST['host'];
        $dbName = $_POST['name'];
        $dbUser = $_POST['user'];
        $dbPass = $_POST['pass'];
    }

    try {
        exportSchema($dbHost, $dbName, $dbUser, $dbPass);
    } catch (Exception $e) {
        if ($isCLI) {
            echo "Error: " . $e->getMessage() . "\n";
        } else {
            echo '<div style="padding: 20px; background: #fee2e2; color: #991b1b; border-radius: 10px; margin: 20px;">';
            echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
            echo '</div>';
            showForm();
        }
    }
}

function exportSchema($host, $name, $user, $pass)
{
    $mysqli = new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');

    // Get all tables
    $tables = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }

    $tableCount = count($tables);

    // Check if download requested
    $download = isset($_GET['download']) || isset($_POST['download']);

    if ($download) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="schema_export_' . date('Ymd_His') . '.sql"');
    } else {
        // Show info page first
        showExportPage($name, $tableCount, $host);
        return;
    }

    // Generate SQL
    $sql = "-- =============================================\n";
    $sql .= "-- LINE Telepharmacy CRM - Database Schema Export\n";
    $sql .= "-- Exported from: {$name}\n";
    $sql .= "-- Export date: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Total tables: {$tableCount}\n";
    $sql .= "-- =============================================\n\n";

    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET CHARACTER SET utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tables as $table) {
        $sql .= "-- ---------------------------------------------\n";
        $sql .= "-- Table: `{$table}`\n";
        $sql .= "-- ---------------------------------------------\n";

        // Get CREATE TABLE statement
        $result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
        $row = $result->fetch_array();
        $createSql = $row[1];

        // Add IF NOT EXISTS
        $createSql = preg_replace('/CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createSql, 1);

        $sql .= $createSql . ";\n\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    $mysqli->close();

    echo $sql;
}

function showExportPage($dbName, $tableCount, $host)
{
    ?>
    <!DOCTYPE html>
    <html lang="th">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Schema Export</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Prompt', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 40px 20px;
            }

            .container {
                max-width: 600px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                overflow: hidden;
            }

            .header {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }

            .header h1 {
                font-size: 1.8rem;
                margin-bottom: 10px;
            }

            .header p {
                opacity: 0.9;
            }

            .content {
                padding: 30px;
            }

            .stats {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
            }

            .stat-card {
                background: #f0f9ff;
                border-radius: 12px;
                padding: 20px;
                text-align: center;
            }

            .stat-card .number {
                font-size: 2.5rem;
                font-weight: 700;
                color: #0284c7;
            }

            .stat-card .label {
                color: #64748b;
                font-size: 0.9rem;
            }

            .info-box {
                background: #f8fafc;
                border-radius: 10px;
                padding: 15px;
                margin-bottom: 20px;
            }

            .info-box p {
                margin: 5px 0;
                color: #475569;
            }

            .info-box strong {
                color: #1e293b;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 14px 28px;
                border: none;
                border-radius: 10px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.3s;
            }

            .btn-primary {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
            }

            .btn-secondary {
                background: #e2e8f0;
                color: #475569;
            }

            .actions {
                display: flex;
                gap: 15px;
                justify-content: center;
                flex-wrap: wrap;
            }

            .note {
                margin-top: 20px;
                padding: 15px;
                background: #fef3c7;
                border-radius: 10px;
                color: #92400e;
                font-size: 0.9rem;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-database"></i> Database Schema Export</h1>
                <p>Export โครงสร้างตารางเปล่าๆ (ไม่มีข้อมูล)</p>
            </div>
            <div class="content">
                <div class="stats">
                    <div class="stat-card">
                        <div class="number">
                            <?= $tableCount ?>
                        </div>
                        <div class="label">ตาราง</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><i class="fas fa-check-circle" style="color: #10B981;"></i></div>
                        <div class="label">พร้อม Export</div>
                    </div>
                </div>

                <div class="info-box">
                    <p><strong>Database:</strong>
                        <?= htmlspecialchars($dbName) ?>
                    </p>
                    <p><strong>Host:</strong>
                        <?= htmlspecialchars($host) ?>
                    </p>
                    <p><strong>Export Date:</strong>
                        <?= date('Y-m-d H:i:s') ?>
                    </p>
                </div>

                <div class="actions">
                    <a href="?action=export&download=1" class="btn btn-primary">
                        <i class="fas fa-download"></i> ดาวน์โหลด SQL
                    </a>
                    <a href="wizard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> กลับ
                    </a>
                </div>

                <div class="note">
                    <strong><i class="fas fa-info-circle"></i> หมายเหตุ:</strong><br>
                    ไฟล์ที่ export จะมีเฉพาะ CREATE TABLE statements เท่านั้น<br>
                    ไม่มีข้อมูล (data) รวมอยู่ด้วย สามารถนำไปใช้ติดตั้งระบบใหม่ได้
                </div>
            </div>
        </div>
    </body>

    </html>
    <?php
}

function showForm()
{
    ?>
    <!DOCTYPE html>
    <html lang="th">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Schema Export - Config</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Prompt', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 40px 20px;
            }

            .container {
                max-width: 500px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                overflow: hidden;
            }

            .header {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }

            .content {
                padding: 30px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-group label {
                display: block;
                font-weight: 500;
                margin-bottom: 8px;
            }

            .form-control {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
                font-size: 1rem;
                font-family: inherit;
            }

            .form-control:focus {
                outline: none;
                border-color: #10B981;
            }

            .btn {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
            }

            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
            }
        </style>
    </head>

    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-database"></i> Schema Export</h1>
                <p>กรอกข้อมูลฐานข้อมูลที่ต้องการ export</p>
            </div>
            <div class="content">
                <form method="POST" action="?action=export">
                    <div class="form-group">
                        <label>Database Host</label>
                        <input type="text" name="host" class="form-control" value="localhost" required>
                    </div>
                    <div class="form-group">
                        <label>Database Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="user" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="pass" class="form-control">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-cog"></i> ดึงข้อมูลตาราง
                    </button>
                </form>
            </div>
        </div>
    </body>

    </html>
    <?php
}
?>