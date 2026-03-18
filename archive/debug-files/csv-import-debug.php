<?php
/**
 * Debug CSV Import - check what data is received
 */
header('Content-Type: application/json');

echo json_encode([
    'POST' => $_POST,
    'GET' => $_GET,
    'FILES' => isset($_FILES['csv_file']) ? [
        'name' => $_FILES['csv_file']['name'],
        'size' => $_FILES['csv_file']['size'],
        'error' => $_FILES['csv_file']['error']
    ] : 'No file',
    'action_post' => $_POST['action'] ?? 'not set',
    'action_get' => $_GET['action'] ?? 'not set',
    'to_cny' => $_POST['to_cny'] ?? 'not set',
    'to_business' => $_POST['to_business'] ?? 'not set'
]);
