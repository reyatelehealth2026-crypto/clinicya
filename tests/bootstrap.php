<?php
/**
 * PHPUnit Bootstrap File
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Bangkok');

// Autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Define test constants
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Test App');
}
// Classes that talk to Gemini build their API_BASE from this at class-load
// time, so it has to exist before any of them is required — the suite does not
// load config/config.php.
if (!defined('GEMINI_API_BASE')) {
    define('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com');
}
