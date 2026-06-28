<?php
/**
 * auth/google-start.php — kick off Google OAuth (self-serve signup / login).
 *
 * Generates a CSRF state token, stores it in the session, then redirects the
 * browser to Google's consent screen. Google returns to GOOGLE_REDIRECT_URI
 * (auth/google-callback.php).
 *
 * All Google OAuth happens on the ROOT domain (re-ya.com) because Google does
 * not allow wildcard redirect URIs — per-tenant subdomains cannot each own a
 * callback. The callback hands off to the tenant afterwards.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cfg = __DIR__ . '/../config/google_oauth.php';
if (!is_file($cfg)) {
    http_response_code(500);
    exit('Google OAuth not configured (missing config/google_oauth.php).');
}
require_once $cfg;

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
// Optional: remember where to send the user back to after auth.
$_SESSION['google_oauth_return'] = (string)($_GET['return'] ?? '');

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params, true, 302);
exit;
