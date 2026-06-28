<?php
/**
 * google_oauth.example.php — template for Google OAuth (self-serve signup).
 *
 * Copy to  config/google_oauth.php  ON THE SERVER ONLY (it is gitignored) and
 * fill in the real values from Google Cloud Console → APIs & Services →
 * Credentials → OAuth 2.0 Client ID (Web application).
 *
 * The Authorized redirect URI in the Google console MUST exactly match
 * GOOGLE_REDIRECT_URI below (including the .php and https).
 */
declare(strict_types=1);

define('GOOGLE_CLIENT_ID',     'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://re-ya.com/auth/google-callback.php');
