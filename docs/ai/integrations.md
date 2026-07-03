# Integrations

## LINE Messaging API and LIFF

Confirmed: `classes/LineAPI.php` wraps reply, push, rich menu, loading, content, and signature validation operations. `webhook.php` validates `X-Line-Signature` using `LineAPI::validateSignature()` after resolving account credentials from `line_accounts`. `LineAccountManager::validateAndGetAccount()` computes HMAC SHA-256 over the body with each account secret.

Credential sources: `line_accounts.channel_access_token`, `line_accounts.channel_secret`, fallback constants `LINE_CHANNEL_ACCESS_TOKEN`, `LINE_CHANNEL_SECRET` in `config/config.php`.

Failure handling: `webhook.php::sendMessageWithFallback()` tries reply first, then push. `LineAPI::sendMessage()` also logs fallback behavior. `cron/send_scheduled.php` fails if no channel token exists.

## Gemini AI

Confirmed: `config/config.php` defines `GEMINI_API_KEY` from env. `modules/AIChat/Models/AISettings.php` resolves keys from DB/config fallback. `modules/AIChat/Services/GeminiAPI.php`, `GeminiChatAdapter.php`, `PharmacyAIAdapter.php`, and `MIMSPharmacistAI.php` call Gemini-backed flows.

Failure modes: missing API key disables or falls back depending on adapter/settings; exact runtime response varies by module.

## Redis

Confirmed: PHP config defines Redis constants. Fastify plugins use `REDIS_URL`/`REDIS_PASSWORD`. `backend/src/websocket/server.ts` uses Redis for scaling and JWT-authenticated sockets.

Security note: `config/config.php` contains a hardcoded Redis password fallback. Treat as sensitive exposure.

## Google OAuth and SSO

Confirmed: `auth/google-callback.php` verifies OAuth state, exchanges code at `https://oauth2.googleapis.com/token`, verifies `id_token`, maps to platform user/tenant, then uses `TenantSso` token handoff to tenant subdomain. `auth/sso-consume.php` verifies the token and prevents cross-tenant replay by matching subdomain tenant.

Credential/config keys: `config/google_oauth.example.php`, `config/sso_config.php`.

## Notifications

Confirmed modern backend notification channels: SMTP, Slack webhook, LINE Notify placeholder in `backend/src/services/NotificationService.ts`. PHP side sends LINE push notifications in cron/reminders and AI pharmacist notification modules.

## Webhooks and Queues

Confirmed: LINE webhook at `webhook.php`. Webhook logging/retry infrastructure appears in `classes/WebhookLoggingService.php`, `cron/webhook_retry_processor.php`, `cron/webhook_statistics_calculator.php`, and `cron/cleanup-dlq.php`.

Odoo webhook integrations are excluded from this documentation by request.

## Failure Modes

- LINE reply token expiry triggers push fallback, increasing quota use.
- Invalid LINE signature returns HTTP 400 in `webhook.php`.
- Missing LINE account token causes scheduled send failure in `cron/send_scheduled.php`.
- Gemini key missing or invalid affects AI consultation output.
- Redis unavailable may degrade Fastify WebSocket/realtime behavior; exact fallback varies by backend service.

## Last Verified From Code

Verified on 2026-07-03 from `classes/LineAPI.php`, `classes/LineAccountManager.php`, `webhook.php`, `config/config.php`, `modules/AIChat/Models/AISettings.php`, `modules/AIChat/Services/GeminiAPI.php`, `auth/google-callback.php`, `auth/sso-consume.php`, `config/sso_config.php`, `backend/src/services/NotificationService.ts`, `backend/src/websocket/server.ts`, `classes/WebhookLoggingService.php`, `cron/webhook_retry_processor.php`.
