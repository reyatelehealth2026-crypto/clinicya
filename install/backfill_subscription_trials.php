<?php
/**
 * Backfill tenant_subscriptions for every non-terminated tenant.
 *
 * Existing live tenants are GRANDFATHERED as ACTIVE:
 *   - trial_ends_at = NULL  (no trial countdown)
 *   - next_due_date = CURDATE() + 30 days
 *
 * ALTERNATIVE: to put new/unconverted tenants on a 14-day trial instead,
 * set trial_ends_at = DATE_ADD(CURDATE(), INTERVAL 14 DAY) and
 * next_due_date likewise.
 *
 * Run once on server:
 *   php install/backfill_subscription_trials.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::platform()->getConnection();

// Fetch all non-terminated tenants that have no subscription row yet.
$stmt = $db->query("
    SELECT t.id, t.slug, t.plan_id, t.status, t.owner_email, t.created_at
    FROM   tenants t
    LEFT JOIN tenant_subscriptions s ON s.tenant_id = t.id
    WHERE  t.status <> 'terminated'
    AND    s.tenant_id IS NULL
");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total   = count($tenants);
$created = 0;
$skipped = 0;
$errors  = 0;

echo "=== Backfill tenant_subscriptions ===\n\n";
echo "Tenants without a subscription row: {$total}\n\n";

if ($total === 0) {
    echo "Nothing to do — all tenants already have a subscription row.\n";
    exit(0);
}

$insert = $db->prepare("
    INSERT INTO tenant_subscriptions
        (tenant_id, start_date, billing_cycle, next_due_date, trial_ends_at,
         last_paid_date, amount_thb, billing_contact_email, auto_suspend_enabled, created_at)
    VALUES
        (:tenant_id, :start_date, 'monthly', DATE_ADD(CURDATE(), INTERVAL 30 DAY),
         NULL, NULL, :amount_thb, :billing_contact_email, 0, NOW())
    ON DUPLICATE KEY UPDATE tenant_id = tenant_id
");

foreach ($tenants as $t) {
    try {
        // Resolve plan price; default to 0 if plan_id is null or not found.
        $amount = 0;
        if (!empty($t['plan_id'])) {
            $ps = $db->prepare('SELECT price_monthly_thb FROM plans WHERE id = ? LIMIT 1');
            $ps->execute([$t['plan_id']]);
            $row = $ps->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $amount = (float) $row['price_monthly_thb'];
            }
        }

        // Use tenant's own created_at as start_date; fall back to today.
        $startDate = !empty($t['created_at'])
            ? (new \DateTime($t['created_at']))->format('Y-m-d')
            : date('Y-m-d');

        $insert->execute([
            ':tenant_id'             => $t['id'],
            ':start_date'            => $startDate,
            ':amount_thb'            => $amount,
            ':billing_contact_email' => $t['owner_email'] ?? '',
        ]);

        $affected = $insert->rowCount();
        if ($affected > 0) {
            $created++;
            echo sprintf("  [%s] created (plan=%s, amount=%.2f)\n",
                $t['slug'] ?? $t['id'], $t['plan_id'] ?? 'none', $amount);
        } else {
            // ON DUPLICATE KEY UPDATE fired — row existed despite the LEFT JOIN.
            $skipped++;
            echo sprintf("  [%s] skipped (already exists)\n", $t['slug'] ?? $t['id']);
        }
    } catch (\Throwable $e) {
        $errors++;
        echo sprintf("  [%s] ERROR: %s\n", $t['slug'] ?? $t['id'], $e->getMessage());
    }
}

echo "\n=== Done: {$total} tenants checked, {$created} created, {$skipped} skipped, {$errors} errors ===\n";
exit($errors > 0 ? 1 : 0);
