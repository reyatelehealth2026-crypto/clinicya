<?php
/**
 * Redirect stub - This file has been consolidated
 * Redirects to: settings.php?tab=email
 */
require_once __DIR__ . '/includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
header("Location: settings.php?tab=email", true, 301);
exit;
