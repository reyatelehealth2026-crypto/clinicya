<?php
/**
 * Compatibility redirect for mistakenly nested admin Learning Hub URLs.
 *
 * Browsers can resolve relative links from /admin/learning-hub/ to this path.
 * Keep query parameters so /admin/learning-hub/learning-hub.php?edit=1 lands on
 * the real editor route instead of a server error.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/admin/learning-hub.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 302);
exit;
