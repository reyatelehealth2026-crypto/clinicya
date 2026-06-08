<?php
/**
 * fix_master_images.php — overwrite blurry 56x56 master thumbnails with full-res
 * originals pulled server-to-server from manager.cnypharmacy.com.
 *
 * WHY: master images were extracted from embedded Excel thumbnails (56x56). The
 * real 1000x1000 photos live on manager.cnypharmacy.com. Both the master picker
 * AND every tenant product that already imported point at the SAME file
 * uploads/master/{code}.jpg — so overwriting the file fixes everything at once,
 * with zero DB changes.
 *
 * USAGE (browser, HTTP — no SSH needed):
 *   1. Upload this file + master_image_map.json into the SAME folder on the server
 *      (e.g. /home/zrismpsz/public_html/_imgfix/).
 *   2. Dry run first:  https://re-ya.com/_imgfix/fix_master_images.php?key=SECRET&dry=1
 *   3. Real run:       https://re-ya.com/_imgfix/fix_master_images.php?key=SECRET
 *      (batched — re-open with &offset=500 etc, or just re-run; it's resumable)
 *   4. DELETE this folder when done.
 *
 * Idempotent + resumable: skips files already full-res (>5KB). Safe to re-run.
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

// ---- config ----
const SECRET       = 'CHANGE_ME_8f3a2c';   // <-- change this; must match ?key=
const MIN_OK_BYTES = 3000;                  // reject 404 HTML / tiny junk
const FULLRES_MIN  = 5000;                  // file already this big => already fixed, skip
const FETCH_TIMEOUT= 25;

$key    = $_GET['key']    ?? '';
$dry    = isset($_GET['dry']);
$all    = isset($_GET['all']);             // also create MISSING files (default: only overwrite existing thumbs)
$force  = isset($_GET['force']);           // re-download even if target already full-res (>5KB) — for correcting wrong images
$limit  = isset($_GET['limit'])  ? max(1, (int)$_GET['limit'])  : 4000;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if (!hash_equals(SECRET, (string)$key)) {
    http_response_code(403);
    exit("forbidden: pass ?key=SECRET\n");
}

// ---- locate uploads/master ----
$candidates = [
    __DIR__ . '/uploads/master',
    dirname(__DIR__) . '/uploads/master',
    dirname(__DIR__, 2) . '/uploads/master',
    '/home/zrismpsz/public_html/uploads/master',
];
$masterDir = null;
foreach ($candidates as $c) {
    if (is_dir($c)) { $masterDir = realpath($c); break; }
}
if (!$masterDir) {
    exit("ERROR: could not find uploads/master. Tried:\n  " . implode("\n  ", $candidates) . "\n");
}
if (!$dry && !is_writable($masterDir)) {
    exit("ERROR: $masterDir is not writable (chmod 755/775).\n");
}

// ---- load map ----
$mapFile = __DIR__ . '/master_image_map.json';
if (!is_file($mapFile)) exit("ERROR: master_image_map.json not found next to this script.\n");
$map = json_decode(file_get_contents($mapFile), true);
if (!is_array($map)) exit("ERROR: master_image_map.json is not valid JSON.\n");

$hasGD = function_exists('imagecreatefromstring');

echo "=== fix_master_images " . ($dry ? "[DRY RUN]" : "[LIVE]") . " ===\n";
echo "masterDir = $masterDir\n";
echo "mapped codes = " . count($map) . " | GD=" . ($hasGD ? 'yes' : 'no')
   . " | mode=" . ($all ? 'all(+create missing)' : 'overwrite-existing-only')
   . " | window offset=$offset limit=$limit\n\n";

function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => FETCH_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'reya-imgfix/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? '' : $body];
}

$stats = ['ok'=>0, 'skip_fullres'=>0, 'skip_nofile'=>0, 'fail_fetch'=>0, 'fail_invalid'=>0, 'processed'=>0];
$i = -1;
foreach ($map as $code => $url) {
    $i++;
    if ($i < $offset) continue;
    if ($stats['processed'] >= $limit) { echo "\n-- batch limit reached; resume with &offset=" . ($offset + $limit) . " --\n"; break; }
    $stats['processed']++;

    $target = $masterDir . '/' . $code . '.jpg';
    $exists = is_file($target);

    if (!$exists && !$all) { $stats['skip_nofile']++; continue; }
    if (!$force && $exists && filesize($target) >= FULLRES_MIN) { $stats['skip_fullres']++; continue; }

    if ($dry) { echo sprintf("DRY would fix %-6s <- %s\n", $code, $url); $stats['ok']++; continue; }

    [$http, $body] = fetch($url);
    if ($http !== 200 || strlen($body) < MIN_OK_BYTES) {
        echo sprintf("FAIL fetch %-6s http=%d bytes=%d %s\n", $code, $http, strlen($body), $url);
        $stats['fail_fetch']++; continue;
    }
    $info = @getimagesizefromstring($body);
    if (!$info || $info[0] < 120) {   // reject non-images / tiny
        echo sprintf("FAIL invalid %-6s (%s)\n", $code, $info ? "{$info[0]}x{$info[1]}" : 'not-image');
        $stats['fail_invalid']++; continue;
    }

    // normalise to real JPEG when GD present (handles PNG sources cleanly)
    $out = $body;
    if ($hasGD) {
        $im = @imagecreatefromstring($body);
        if ($im) {
            ob_start(); imagejpeg($im, null, 90); $out = ob_get_clean(); imagedestroy($im);
        }
    }
    $tmp = $target . '.tmp' . getmypid();
    if (file_put_contents($tmp, $out) === false || !@rename($tmp, $target)) {
        @unlink($tmp);
        echo sprintf("FAIL write %-6s\n", $code); $stats['fail_invalid']++; continue;
    }
    @chmod($target, 0644);
    $stats['ok']++;
    if ($stats['ok'] % 100 === 0) echo "... fixed {$stats['ok']} (last code $code {$info[0]}x{$info[1]})\n";
}

echo "\n=== DONE ===\n";
foreach ($stats as $k => $v) echo str_pad($k, 14) . " = $v\n";
$next = $offset + $stats['processed'];
if ($next < count($map)) echo "\nMore remain. Next batch: &offset=$next\n";
else echo "\nAll codes processed.\n";
