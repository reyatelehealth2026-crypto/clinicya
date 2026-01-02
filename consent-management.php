<?php
/**
 * Redirect stub - This file has been consolidated
 * Redirects to: settings.php?tab=consent
 */
require_once __DIR__ . '/includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
header("Location: settings.php?tab=consent", true, 301);
exit;
