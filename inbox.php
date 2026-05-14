<?php
// inbox.php — compatibility shim
//
// The legacy v1 inbox was removed in commit dc28718 ("refactor: Remove unused
// test, debug, and archived files"), but external bookmarks, OA rich-menu
// entries, and saved links still point at /inbox.php. Without this shim
// those requests 404 (re-ya.com/inbox.php) and the v2 fallback inside
// inbox-v2.php used to redirect back here, creating either a dead end or a
// redirect loop.
//
// This shim forwards every hit on /inbox.php to /inbox-v2.php (preserving
// the original query string). inbox-v2.php itself now falls back to
// messages.php when v2_enabled='0', so this shim cannot loop.

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'inbox-v2.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
