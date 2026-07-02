# Environment and Secrets

Never print secret values. This file lists keys and config locations only.

## PHP Config Files

- `config/config.php`: DB constants, app URLs, timezone, LINE fallback constants, Odoo flags excluded from this KB, Gemini, internal token, Redis.
- `config/config.example.php`, `config/config.sample.php`, `config/config_root.php`, `config/config_likesms.php`: examples/legacy variants.
- `config/google_oauth.example.php`: Google OAuth client id/secret/redirect example.
- `config/sso_config.php`: SSO secret and Next inbox URL.
- `config/notify_config.example.php`: notification email/Telegram/owner email placeholders.

## Required PHP Runtime Values

Confirmed constants in `config/config.php`:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`: required for PDO.
- `APP_NAME`, `APP_URL`, `BASE_URL`, `TIMEZONE`: application identity and timezone.
- `GEMINI_API_KEY`: optional/required depending on AI feature enablement.
- `INTERNAL_API_TOKEN`: env preferred, fallback derived from DB credentials.
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_PREFIX`.

Sensitive: DB credentials, Redis password, LINE tokens/secrets, Gemini API key, OAuth client secret, SSO secret, internal token.

## LINE Account Secrets

Primary source is database table `line_accounts.channel_access_token` and `line_accounts.channel_secret`. Fallback constants exist in `config/config.php`, but comments say LINE API settings are managed through admin.

## Mini App Environment Variables

From `line-mini-app/src/lib/config.ts`:

- `NEXT_PUBLIC_MINIAPP_NAME`
- `NEXT_PUBLIC_LINE_LIFF_ID`
- `NEXT_PUBLIC_LINE_CHANNEL_ID`
- `NEXT_PUBLIC_LINE_ACCOUNT_ID`
- `NEXT_PUBLIC_PHP_API_BASE_URL`
- `NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE`
- `NEXT_PUBLIC_SHOP_HIDE_INACTIVE`
- `NEXT_PUBLIC_SHOP_CATALOG_MODE`
- `NEXT_PUBLIC_SHOP_CATALOG_BUCKET`

These are public build-time values; tenant context is resolved at runtime and should not depend only on `NEXT_PUBLIC_LINE_ACCOUNT_ID`.

## Fastify Backend Environment Variables

From `backend/src/config/config.ts`:

- Required: `DATABASE_URL`, `JWT_SECRET`, `JWT_REFRESH_SECRET`.
- Optional/defaulted: `NODE_ENV`, `PORT`, `API_PREFIX`, `JWT_EXPIRES_IN`, `JWT_REFRESH_EXPIRES_IN`, `REDIS_URL`, `REDIS_PASSWORD`, `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW_MS`, `CORS_ORIGIN`, `LOG_LEVEL`, `WEBSOCKET_PORT`, `UPLOAD_DIR`, `MAX_FILE_SIZE`, `ALLOWED_FILE_TYPES`.
- External service keys present but Odoo excluded: `ODOO_API_URL`, `ODOO_API_KEY`.

From `backend/src/services/NotificationService.ts`:

- SMTP: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `ALERT_EMAIL_RECIPIENTS`.
- Slack: `SLACK_WEBHOOK_URL`, `SLACK_CHANNEL`.
- LINE Notify: `LINE_NOTIFY_TOKEN`.

## Security Finding

`config/config.php` contains committed literal DB credentials and Redis password fallback. Even if production overrides some env values, committed secret-like values are a supported finding and should be rotated/removed.

## Last Verified From Code

Verified on 2026-07-03 from `config/config.php`, `config/config.example.php`, `config/google_oauth.example.php`, `config/sso_config.php`, `config/notify_config.example.php`, `line-mini-app/src/lib/config.ts`, `backend/src/config/config.ts`, `backend/src/services/NotificationService.ts`, `database/schema_complete.sql`.
