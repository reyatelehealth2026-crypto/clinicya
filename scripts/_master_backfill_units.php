<?php
/**
 * Phase 2: backfill master_products.product_price from the CNY grouped API.
 *
 * Source: https://www.cnypharmacy.com/api/getDataProductIsGroup?page=N&limit=M
 *   per product: product_unit[]  = {id, unit:"ขวด[60ML]", unit_num:"1.00"}
 *                product_price[]  = [{product_price:[{price_level_id, product_unit_id, price}]}]
 *
 * We normalise to the flat shape api/master-catalog.php::buildUnits() understands:
 *   [{"unit":"ขวด[60ML]","unit_num":"1.00","price":64.00,"customer_group":"GEN"}]
 * and UPDATE master_products by leading-zero-normalised sku.
 *
 * Usage (server):
 *   php scripts/_master_backfill_units.php dry   # page 1 only, no writes, prints samples
 *   php scripts/_master_backfill_units.php       # full run
 */
declare(strict_types=1);
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

@set_time_limit(0);
$DRY      = in_array('dry', $argv, true);
$LIMIT    = 200;          // API caps its own page size (~25); we just paginate until empty
$MAX_PAGE = 1000;         // safety guard against an infinite loop
$API      = 'https://www.cnypharmacy.com/api/getDataProductIsGroup';

$pdo = Database::platform()->getConnection();

// normalise sku: strip leading zeros ("0003" -> "3", "776" -> "776"); empty -> "0"
$norm = static function ($s): string {
    $s = ltrim(trim((string) $s), '0');
    return $s === '' ? '0' : $s;
};

// master sku -> id map
$map = [];
foreach ($pdo->query('SELECT id, sku FROM master_products')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[$norm($r['sku'])] = (int) $r['id'];
}
echo 'master skus: ' . count($map) . "\n";

$upd = $pdo->prepare('UPDATE master_products SET product_price = ?, updated_at = NOW() WHERE id = ?');

$fetch = static function (string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$body) return null;
    $d = json_decode($body, true);
    return is_array($d) ? $d : null;
};

// build flat units array for one grouped product
$build = static function (array $p): array {
    // price by product_unit_id (prefer price_level_id=1 retail; else first)
    $priceByUnit = [];
    foreach (($p['product_price'] ?? []) as $wrap) {
        foreach (($wrap['product_price'] ?? []) as $pr) {
            $uid = (int) ($pr['product_unit_id'] ?? 0);
            if ($uid === 0) continue;
            $lvl = (int) ($pr['price_level_id'] ?? 0);
            if ($lvl === 1 || !isset($priceByUnit[$uid])) {
                $priceByUnit[$uid] = (float) ($pr['price'] ?? 0);
            }
        }
    }
    $units = [];
    foreach (($p['product_unit'] ?? []) as $u) {
        $name = trim((string) ($u['unit'] ?? ''));
        if ($name === '') continue;
        $uid  = (int) ($u['id'] ?? 0);
        $units[] = [
            'unit'           => $name,
            'unit_num'       => (string) ($u['unit_num'] ?? '1'),
            'price'          => $priceByUnit[$uid] ?? 0,
            'customer_group' => 'GEN',
        ];
    }
    return $units;
};

$page = 1; $matched = 0; $unmatched = 0; $withUnits = 0; $multiUnit = 0;
while (true) {
    $d = $fetch(sprintf('%s?page=%d&limit=%d', $API, $page, $LIMIT));
    $prods = $d['product'] ?? [];
    if (!is_array($prods) || empty($prods)) break;

    foreach ($prods as $p) {
        $pd  = $p['product_data'][0] ?? null;
        if (!$pd) continue;
        $sku = $norm($pd['sku'] ?? '');
        $id  = $map[$sku] ?? 0;
        if (!$id) { $unmatched++; continue; }

        $units = $build($p);
        if ($DRY) {
            if ($matched < 5) {
                echo "  sku={$sku} id={$id} units=" . json_encode($units, JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            $upd->execute([json_encode($units, JSON_UNESCAPED_UNICODE), $id]);
        }
        $matched++;
        if (!empty($units)) $withUnits++;
        if (count($units) > 1) $multiUnit++;
    }
    $line = "page {$page}: got " . count($prods) . " | matched={$matched} unmatched={$unmatched} multiUnit={$multiUnit}";
    echo $line . "\n";
    fwrite(STDERR, $line . "\n"); // unbuffered progress for tail monitoring
    if ($DRY) break;
    if (++$page > $MAX_PAGE) { echo "MAX_PAGE_REACHED\n"; break; }
}

echo "\n" . ($DRY ? 'DRY_DONE' : 'BACKFILL_DONE')
   . " matched={$matched} unmatched={$unmatched} withUnits={$withUnits} multiUnit={$multiUnit}\n";
