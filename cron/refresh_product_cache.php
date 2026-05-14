<?php
// Refresh top products cache from inbox-product-check API
// Run via cron every 30 min: /www/server/php/83/bin/php /home/zrismpsz/public_html/cron/refresh_product_cache.php

$url = 'https://cny.re-ya.com/api/inbox-product-check.php?action=overview&days=7';
$out = @file_get_contents($url, false, stream_context_create([
    'http' => ['timeout' => 15],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]));

if ($out) {
    $data = json_decode($out, true);
    if (!empty($data['products'])) {
        $top = array_slice($data['products'], 0, 10);
        $cacheDir = __DIR__ . '/../cache';
        @mkdir($cacheDir, 0777, true);
        file_put_contents(
            $cacheDir . '/inbox_products_7.json',
            json_encode(['products' => $top, 'updated_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)
        );
        echo date('H:i:s') . " Saved " . count($top) . " products\n";
    }
} else {
    echo "Failed to fetch\n";
}
