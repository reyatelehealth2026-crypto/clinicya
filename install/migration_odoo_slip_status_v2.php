<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    $db->exec("
        ALTER TABLE odoo_slip_uploads
        MODIFY COLUMN status ENUM('new', 'pending', 'matched', 'payment_created', 'posted', 'done', 'failed')
        DEFAULT 'new'
        COMMENT 'Matching status'
    ");

    $db->exec("
        UPDATE odoo_slip_uploads
        SET status = 'new'
        WHERE status = 'pending'
    ");

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
