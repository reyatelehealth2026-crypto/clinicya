<?php
/**
 * Shop Products - Redirect to Inventory
 * This file has been moved to inventory/index.php?tab=products
 */

// Preserve query parameters
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$params = [];
parse_str($queryString, $params);
$params['tab'] = 'products';

header('Location: /inventory?' . http_build_query($params));
exit;
