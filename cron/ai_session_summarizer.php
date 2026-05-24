<?php
/**
 * AI Session Summarizer (cron)
 *
 * Sweeps the last 24h of triage_sessions where status IN ('completed','escalated')
 * AND chief_complaint IS NULL/empty, and fills chief_complaint via Gemini.
 *
 * Idempotent: only picks up sessions still missing a summary, processes at most
 * 50 per run to keep runs short, logs each pass to dev_logs.
 *
 * Install (not auto-installed):
 *   # crontab -e
 *   * /15 * * * * php /home/zrismpsz/public_html/cron/ai_session_summarizer.php >> /var/log/ai_session_summarizer.log 2>&1
 *
 * Manual run:
 *   php cron/ai_session_summarizer.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Allow web hit for debugging, but cap to admins via header guard.
    http_response_code(403);
    echo "cron only\n";
    exit;
}

define('AI_CHAT_SUMMARY_HELPER_ONLY', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/ai-chat-summary.php';

$startedAt = date('Y-m-d H:i:s');
$db = Database::getInstance()->getConnection();

$limit = 50;
$sql = "SELECT id
        FROM triage_sessions
        WHERE status IN ('completed', 'escalated')
          AND (chief_complaint IS NULL OR chief_complaint = '')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY id DESC
        LIMIT {$limit}";

$ids = [];
try {
    $stmt = $db->query($sql);
    if ($stmt !== false) {
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[ai_session_summarizer] query failed: " . $e->getMessage() . "\n");
    exit(1);
}

$processed = 0;
$succeeded = 0;
$failed = 0;
foreach ($ids as $sessionId) {
    $processed++;
    try {
        $summary = summary_run_for_session($db, (int) $sessionId);
        if (is_string($summary) && $summary !== '') {
            $succeeded++;
            echo "OK session={$sessionId} summary=" . mb_substr($summary, 0, 80) . "\n";
        } else {
            $failed++;
            echo "SKIP session={$sessionId} (no summary)\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        echo "ERR session={$sessionId} {$e->getMessage()}\n";
    }
}

$endedAt = date('Y-m-d H:i:s');
$message = sprintf(
    'AI session summarizer run: scanned=%d processed=%d succeeded=%d failed=%d (%s → %s)',
    count($ids),
    $processed,
    $succeeded,
    $failed,
    $startedAt,
    $endedAt
);
echo $message . "\n";

try {
    $log = $db->prepare(
        'INSERT INTO dev_logs (log_type, source, message, data, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $log->execute([
        'info',
        'cron/ai_session_summarizer.php',
        $message,
        json_encode([
            'scanned'   => count($ids),
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'session_ids' => $ids,
        ], JSON_UNESCAPED_UNICODE),
    ]);
} catch (\Throwable $e) {
    // dev_logs is optional — never fail the cron because of audit logging.
    fwrite(STDERR, '[ai_session_summarizer] dev_logs insert failed: ' . $e->getMessage() . "\n");
}

exit(0);
