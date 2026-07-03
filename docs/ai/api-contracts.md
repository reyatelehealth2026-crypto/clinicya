# API Contracts

## PHP API Pattern

Most PHP APIs are file-based endpoints under `api/`. Contracts are often selected by an `action` parameter from query string, form data, or JSON body. Evidence: `api/checkout.php`, `api/rewards.php`, `api/member.php`, `api/appointments.php`, `api/medication-reminders.php`, `api/points-claim.php`.

## Mini App Commerce: `api/checkout.php`

Discoverable actions:

- `GET action=products`: returns product list, categories, brands, paging fields, transfer info. Called by `line-mini-app/src/lib/shop-api.ts::fetchProducts`.
- `GET action=product_detail`: returns one product. Called by `fetchProductDetail`.
- `GET action=payment_info`: returns transfer bank/PromptPay data. Called by `fetchPaymentInfo`.
- `GET action=cart`: returns cart items, subtotal, shipping, total. Called by `fetchCart`.
- `POST action=add_to_cart`, `update_cart`, `remove_from_cart`, `clear_cart`: mutate cart. Called by mini app shop API functions.
- `POST action=create_order`: creates a transaction/order from server-side cart. Called by `createShopOrder`.
- `POST multipart action=upload_slip`: uploads a payment slip file and optional decoded QR data. Called by `uploadPaymentSlip`.
- `GET action=get_order`: returns order detail plus items and transfer info. Called by `fetchOrderDetail`.
- `POST action=validate_promo`: validates promo code. Called by `validatePromo`.
- `GET action=last_address`: returns prior delivery address. Called by `fetchLastAddress`.
- `GET action=promptpay_qr`: returns QR image URL/content path. Called by `promptPayQrSrc`.

Auth: mini app API calls rely on `line_user_id` and `line_account_id` parameters, not a server-verified LIFF token in the inspected client contract. Sensitive admin-only mutations are unknown for this endpoint.

Error behavior: action switch returns JSON; specific status codes vary by handler and were not exhaustively cataloged.

## Member, Rewards, Points

- `api/member.php`: actions include register/check/card/tiers/update profile handlers from functions `handleRegister`, `handleCheck`, `handleGetCard`, `handleGetTiers`, `handleUpdateProfile`.
- `api/rewards.php`: actions include reward list, redeem, and my redemptions via `handleGetRewards`, `handleRedeem`, `handleMyRedemptions`.
- `api/points-history.php`: default action `history`.
- `api/points-claim.php`: create/give/lookup/merge/claim/status handlers.

Calling frontend: `line-mini-app/src/lib/member-api.ts`, `rewards-api.ts`, `points-claim-api.ts`.

## AI Chat

- `api/ai-chat.php`: `Content-Type: text/event-stream`; mini app sends JSON `{message, history, mode: "consult", line_user_id?, line_account_id?}` and parses `data:` SSE lines with `token`, `error`, or `structured`. Evidence: `line-mini-app/src/lib/ai-chat-api.ts`.
- `api/ai-chat-history.php`: supports history load and clear; mini app optionally sends `Authorization: Bearer` if an access token exists. Evidence: `line-mini-app/src/lib/ai-chat-history-api.ts`.

## Appointments and Reminders

- `api/appointments.php`: actions for pharmacists, pharmacist detail, available slots, book, today appointments, my appointments, detail, cancel, rate.
- `api/medication-reminders.php`: default action `list`; supports JSON input and actions through switch.
- `api/video-call.php`: GET actions and POST body actions for call lifecycle/history.

## Admin Inbox

- `inbox-v2.php`: same-page AJAX POST gated by `X-Requested-With` in frontend calls. Actions include send message, AI reply generation, tags, notes, medical info, dispense, image/PDF upload, and other CRM operations.
- `api/inbox-v2.php`: cursor/delta API used for conversation loading per AGENTS.md and repo code.

## Fastify Backend

Registered under `config.API_PREFIX` default `/api/v1` in `backend/src/config/config.ts` and `backend/src/routes/index.ts`.

Routes:

- `/auth`: login, refresh, logout, profile. Evidence: `backend/src/routes/auth.ts`.
- `/dashboard`: overview, metrics, charts. Evidence: `backend/src/routes/dashboard.ts`.
- `/orders`: list, detail, status update, timeline, search, statistics. Evidence: `backend/src/routes/orders.ts`.
- `/payments`: slips, upload, bulk, amount, match, delete, pending, auto-match, statistics. Evidence: `backend/src/routes/payments.ts`.
- `/customers`: list, detail, orders, LINE link, statistics. Evidence: `backend/src/routes/customers.ts`.
- `/audit`: logs, resource trail, security event, report, stats, cleanup. Evidence: `backend/src/routes/audit.ts`.
- `/security`: metrics, alerts, acknowledge, block/unblock IP, blocked IPs, rate limit stats. Evidence: `backend/src/routes/security.ts`.
- Health routes: `/health`, `/ready`, `/live`, `/metrics`. Evidence: `backend/src/routes/health.ts`.

Auth: most non-health route groups use JWT middleware `authenticate`. Evidence: route files and `backend/src/middleware/auth.ts`.

## Unknowns

This file is not a complete catalog of every `api/*.php` endpoint. It documents high-value, inspected endpoints and marks uninspected routes as unknown.

## Last Verified From Code

Verified on 2026-07-03 from `api/checkout.php`, `api/member.php`, `api/rewards.php`, `api/points-history.php`, `api/points-claim.php`, `api/appointments.php`, `api/medication-reminders.php`, `api/video-call.php`, `api/ai-chat.php`, `api/ai-chat-history.php`, `inbox-v2.php`, `line-mini-app/src/lib/*.ts`, `backend/src/routes/*.ts`, `backend/src/config/config.ts`.
