<?php
// Disable (is_active=0) giveaway / non-sellable master products: LG GF + ของแถม/สมนาคุณ.
// Prints counts BEFORE disabling + samples of broader patterns for review.
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require '/home/zrismpsz/public_html/config/config.php';
$db = Database::getInstance()->getConnection();
$pdb = 'zrismpsz_reya_platform';
$c = function ($w) use ($db, $pdb) { return (int) $db->query("SELECT COUNT(*) FROM `$pdb`.master_products WHERE $w")->fetchColumn(); };

echo "total_active_before=" . $c("is_active=1") . "\n";

// Patterns to DISABLE (specific, safe)
$disable = [
    "name LIKE '%LG GF%'",
    "name LIKE '%LG-GF%'",
    "name LIKE '%ของแถม%'",
    "name LIKE '%สมนาคุณ%'",
    "name LIKE '%สนาคุณ%'",
    "name LIKE '%ของสมนาคุณ%'",
];
echo "=== will disable (matches) ===\n";
$totalDisable = 0;
foreach ($disable as $w) {
    $n = $c("is_active=1 AND ($w)");
    $totalDisable += $n;
    echo sprintf("  %-26s = %d\n", $w, $n);
}
echo "=== samples to be disabled ===\n";
$whereDisable = "is_active=1 AND (" . implode(' OR ', $disable) . ")";
foreach ($db->query("SELECT DISTINCT sku,name FROM `$pdb`.master_products WHERE $whereDisable LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  [" . $r['sku'] . "] " . $r['name'] . "\n";
}

// REPORT-ONLY broader patterns (do NOT auto-disable — for the user to confirm)
echo "=== REVIEW (not disabled — broader 'LG'/'GF') ===\n";
foreach ([
    "name has LG"  => "name LIKE '%LG%'",
    "name has GF"  => "name LIKE '%GF%'",
    "category LG"  => "category LIKE '%LG%'",
    "category GF"  => "category LIKE '%GF%'",
] as $lbl => $w) {
    echo sprintf("  %-12s = %d\n", $lbl, $c("is_active=1 AND ($w)"));
}
echo "  -- sample 'LG'/'GF' in name --\n";
foreach ($db->query("SELECT DISTINCT sku,name FROM `$pdb`.master_products WHERE is_active=1 AND (name LIKE '%LG%' OR name LIKE '%GF%') AND name NOT LIKE '%LG GF%' LIMIT 25")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  [" . $r['sku'] . "] " . $r['name'] . "\n";
}

// APPLY disable
$upd = $db->exec("UPDATE `$pdb`.master_products SET is_active=0 WHERE $whereDisable");
echo "DISABLED_ROWS=" . (int) $upd . "\n";
echo "total_active_after=" . $c("is_active=1") . "\n";
echo "DONE\n";
