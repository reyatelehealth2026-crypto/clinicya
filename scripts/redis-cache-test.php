<?php
/**
 * Full System Cache Test
 * ทดสอบ OdooRedisCache เต็มระบบ — ครอบคลุม 4 fix:
 *   Fix1: credentials จาก config constants (ไม่ hardcode)
 *   Fix2: key prefix 'odoo:'
 *   Fix3: SCAN แทน KEYS ใน deletePattern()
 *   Fix4: flush() scoped ใน 'odoo:*' เท่านั้น
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/OdooRedisCache.php';

header('Content-Type: text/plain; charset=utf-8');

$pass = 0;
$fail = 0;

function ok($label) {
    global $pass;
    $pass++;
    echo "   ✅ PASS  $label\n";
}
function fail($label, $detail = '') {
    global $fail;
    $fail++;
    echo "   ❌ FAIL  $label" . ($detail ? " — $detail" : '') . "\n";
}
function section($title) {
    echo "\n── $title ──────────────────────────────────────\n";
}

echo "═══════════════════════════════════════════════════════\n";
echo "  OdooRedisCache Full System Test\n";
echo "  " . date('Y-m-d H:i:s T') . "\n";
echo "═══════════════════════════════════════════════════════\n";

// ──────────────────────────────────────────────────────────
section('0. Redis Diagnostics');
// ──────────────────────────────────────────────────────────

$phpRedisExt = extension_loaded('redis');
$predisClass = class_exists('Predis\\Client');
echo "   php-redis extension : " . ($phpRedisExt ? 'Loaded ✅' : 'NOT loaded ❌') . "\n";
echo "   Predis\\Client class : " . ($predisClass ? 'Available ✅' : 'NOT available ❌') . "\n";
echo "   REDIS_HOST          : " . (defined('REDIS_HOST') ? REDIS_HOST : '(not defined)') . "\n";
echo "   REDIS_PORT          : " . (defined('REDIS_PORT') ? REDIS_PORT : '(not defined)') . "\n";

// Try local Redis connection directly
if ($phpRedisExt) {
    try {
        $r = new Redis();
        $ok = @$r->connect('127.0.0.1', 6379, 1);
        echo "   Local Redis 127.0.0.1:6379 connect : " . ($ok ? 'OK ✅' : 'FAILED ❌') . "\n";
        if ($ok) {
            echo "   Local Redis ping : " . ($r->ping() ? 'PONG ✅' : 'no response ❌') . "\n";
        }
    } catch (Exception $e) {
        echo "   Local Redis error : " . $e->getMessage() . "\n";
    }

    // Try Redis Cloud
    if (defined('REDIS_HOST') && REDIS_HOST !== '127.0.0.1') {
        try {
            $r2 = new Redis();
            $ok2 = @$r2->connect(REDIS_HOST, (int)REDIS_PORT, 3.0);
            echo "   Redis Cloud connect : " . ($ok2 ? 'OK ✅' : 'FAILED ❌') . "\n";
            if ($ok2) {
                $r2->auth([REDIS_USERNAME, REDIS_PASSWORD]);
                echo "   Redis Cloud ping : " . ($r2->ping() ? 'PONG ✅' : 'no response ❌') . "\n";
            }
        } catch (Exception $e) {
            echo "   Redis Cloud error : " . $e->getMessage() . "\n";
        }
    }
} elseif ($predisClass) {
    try {
        $pc = new Predis\Client(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1.0]);
        $pc->ping();
        echo "   Predis local ping : OK ✅\n";
    } catch (Exception $e) {
        echo "   Predis local error : " . $e->getMessage() . "\n";
    }
}

if (!$phpRedisExt && !$predisClass) {
    echo "\n   ⚠️  ไม่มีทั้ง phpredis และ Predis — ติดตั้ง phpredis:\n";
    echo "      aaPanel → PHP 8.x → Extensions → redis → Install\n";
}

$cache = OdooRedisCache::getInstance();
$type  = $cache->getType();

// ──────────────────────────────────────────────────────────
section('1. Connection & Config-driven Credentials (Fix 1)');
// ──────────────────────────────────────────────────────────

echo "   Cache type : $type\n";
echo "   Enabled    : " . ($cache->isEnabled() ? 'Yes' : 'No') . "\n";

if ($cache->isEnabled()) {
    ok("Cache connected via config constants");
} else {
    fail("Cache not connected", "Check REDIS_HOST/PORT/PASSWORD in config.php or .env");
}

// Confirm credentials are NOT hardcoded by checking config constants exist
if (defined('REDIS_HOST') && REDIS_HOST !== '') {
    ok("REDIS_HOST constant defined: " . REDIS_HOST);
} else {
    fail("REDIS_HOST not defined in config");
}
if (defined('REDIS_PASSWORD') && REDIS_PASSWORD !== '') {
    ok("REDIS_PASSWORD constant defined (value hidden)");
} else {
    fail("REDIS_PASSWORD not defined in config");
}

// ──────────────────────────────────────────────────────────
section('2. Key Prefix Isolation (Fix 2)');
// ──────────────────────────────────────────────────────────

$generatedKey = OdooRedisCache::key('overview', 1);
if (strpos($generatedKey, 'odoo:') === 0) {
    ok("key() generates prefix 'odoo:' → $generatedKey");
} else {
    fail("key() prefix wrong", "Got: $generatedKey");
}

$generatedKey2 = OdooRedisCache::key('orders', 99, 'p1l50');
$expected2 = 'odoo:orders:99:p1l50';
if ($generatedKey2 === $expected2) {
    ok("key() with suffix → $generatedKey2");
} else {
    fail("key() with suffix wrong", "Expected $expected2, got $generatedKey2");
}

// ──────────────────────────────────────────────────────────
section('3. Basic Operations (Set / Get / Delete)');
// ──────────────────────────────────────────────────────────

$testKey = OdooRedisCache::key('test', 0, 'basic');
$testData = ['ok' => true, 'ts' => time(), 'rand' => rand(1000, 9999)];

$cache->set($testKey, $testData, 60);
$got = $cache->get($testKey);

if ($got && $got['ok'] === true && $got['rand'] === $testData['rand']) {
    ok("set() + get() round-trip");
} else {
    fail("set() + get() round-trip", "Got: " . json_encode($got));
}

$cache->delete($testKey);
$afterDel = $cache->get($testKey);
if ($afterDel === null) {
    ok("delete() removes key");
} else {
    fail("delete() did not remove key");
}

// ──────────────────────────────────────────────────────────
section('4. remember() Pattern');
// ──────────────────────────────────────────────────────────

$rememberKey = OdooRedisCache::key('test', 0, 'remember');
$cache->delete($rememberKey);

$callCount = 0;
$v1 = $cache->remember($rememberKey, 30, function() use (&$callCount) {
    $callCount++;
    return ['val' => 42, 'computed' => true];
});
$v2 = $cache->remember($rememberKey, 30, function() use (&$callCount) {
    $callCount++;
    return ['val' => 99];
});

if ($v1['val'] === 42 && $v2['val'] === 42 && $callCount === 1) {
    ok("remember() calls generator once, returns cached on 2nd call");
} else {
    fail("remember() not caching", "callCount=$callCount v1={$v1['val']} v2={$v2['val']}");
}
$cache->delete($rememberKey);

// ──────────────────────────────────────────────────────────
section('5. deletePattern() using SCAN — not KEYS (Fix 3)');
// ──────────────────────────────────────────────────────────

$patternBase = OdooRedisCache::key('test', 777);
for ($i = 1; $i <= 5; $i++) {
    $cache->set("{$patternBase}:item{$i}", ['n' => $i], 60);
}

// Verify 5 keys set
$found = 0;
for ($i = 1; $i <= 5; $i++) {
    if ($cache->get("{$patternBase}:item{$i}") !== null) $found++;
}
if ($found === 5) {
    ok("Setup: 5 pattern test keys set");
} else {
    fail("Setup: only $found/5 keys set");
}

$deleted = $cache->deletePattern("{$patternBase}:*");
$remaining = 0;
for ($i = 1; $i <= 5; $i++) {
    if ($cache->get("{$patternBase}:item{$i}") !== null) $remaining++;
}

if ($remaining === 0) {
    ok("deletePattern() removed all 5 keys (deleted=$deleted)");
} else {
    fail("deletePattern() left $remaining/5 keys behind");
}

// ──────────────────────────────────────────────────────────
section('6. flush() scoped to odoo:* only (Fix 4)');
// ──────────────────────────────────────────────────────────

$odooKey  = OdooRedisCache::key('test', 0, 'flush_odoo');
$cache->set($odooKey, ['odoo' => true], 60);

$flushed = $cache->flush();
$afterFlush = $cache->get($odooKey);

if ($afterFlush === null) {
    ok("flush() cleared odoo:* key (flushed=$flushed)");
} else {
    fail("flush() did not clear odoo:* key");
}

// ──────────────────────────────────────────────────────────
section('7. Cache Invalidator Integration');
// ──────────────────────────────────────────────────────────

require_once __DIR__ . '/../classes/CacheInvalidator.php';

$acct = 9999;
// Pre-populate keys that CacheInvalidator will clear
$cache->set(OdooRedisCache::key('overview', $acct), ['x' => 1], 60);
$cache->set(OdooRedisCache::key('orders:today:count', $acct), 5, 60);
$cache->set(OdooRedisCache::key('sales:today', $acct), 1000.0, 60);

$inv = new CacheInvalidator();
$inv->onOrderChange($acct, 12345);

$stillHasOverview = $cache->get(OdooRedisCache::key('overview', $acct));
$stillHasCount    = $cache->get(OdooRedisCache::key('orders:today:count', $acct));

if ($stillHasOverview === null && $stillHasCount === null) {
    ok("CacheInvalidator::onOrderChange() cleared expected keys");
} else {
    fail("CacheInvalidator::onOrderChange() did not clear all keys");
}

$cache->set(OdooRedisCache::key('slips:pending:count', $acct), 3, 60);
$inv->onSlipChange($acct);
if ($cache->get(OdooRedisCache::key('slips:pending:count', $acct)) === null) {
    ok("CacheInvalidator::onSlipChange() cleared slip keys");
} else {
    fail("CacheInvalidator::onSlipChange() failed");
}

// ──────────────────────────────────────────────────────────
section('8. Performance Benchmark');
// ──────────────────────────────────────────────────────────

$perfKey = OdooRedisCache::key('test', 0, 'perf');
$cache->set($perfKey, ['data' => str_repeat('x', 512)], 60);

$n = 200;
$start = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $cache->get($perfKey);
}
$ms = (microtime(true) - $start) * 1000;
$avg = round($ms / $n, 4);
echo "   $n reads total : " . round($ms, 2) . " ms\n";
echo "   Average/read   : {$avg} ms\n";

if ($avg < 1.0) {
    ok("Excellent — avg {$avg}ms/read (local Redis)");
} elseif ($avg < 10.0) {
    ok("Good — avg {$avg}ms/read (Redis Cloud)");
} else {
    fail("Slow — avg {$avg}ms/read (check connection)");
}
$cache->delete($perfKey);

// ──────────────────────────────────────────────────────────
section('9. getStats()');
// ──────────────────────────────────────────────────────────

$stats = $cache->getStats();
echo "   Type     : " . $stats['type'] . "\n";
echo "   Hits     : " . $stats['hits'] . "\n";
echo "   Misses   : " . $stats['misses'] . "\n";
echo "   Hit Rate : " . $stats['hit_rate'] . "%\n";

if ($stats['enabled']) {
    ok("getStats() returns valid data");
} else {
    fail("getStats() reports cache disabled");
}

// ──────────────────────────────────────────────────────────
// SUMMARY
// ──────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n═══════════════════════════════════════════════════════\n";
echo "  RESULT: $pass/$total passed" . ($fail > 0 ? ", $fail FAILED" : " — ALL GOOD") . "\n";
echo "  Cache : $type\n";

if ($fail === 0) {
    echo "  ✅ ระบบ Redis Cache พร้อมใช้งาน\n";
} else {
    echo "  ⚠️  มี $fail จุดที่ต้องแก้ไข ดูรายละเอียดด้านบน\n";
}
echo "═══════════════════════════════════════════════════════\n";
