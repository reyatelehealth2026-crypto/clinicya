# Architecture

## Style and Layers

Confirmed style: hybrid PHP monolith plus shared LINE Mini App plus modern Fastify service. The PHP monolith is the main system of record for LINE, shop, inbox, admin pages, APIs, database writes, and cron. The mini app is a Next.js 15 client that calls PHP endpoints using runtime tenant context. The Fastify backend registers JWT-protected API groups and WebSocket services using Prisma/Redis.

Major layers:

- Presentation: PHP admin pages such as `inbox-v2.php`, root `*.php` pages, and `line-mini-app/src/app/*`.
- PHP API layer: `api/*.php`, using action switches and JSON/SSE/multipart contracts.
- Service layer: `classes/*`, `modules/AIChat/*`, `app/*`.
- Data layer: `config/database.php`, `classes/Database.php`, `modules/Core/Database.php`, MySQL schema files.
- Integration layer: `classes/LineAPI.php`, Gemini adapters, OAuth/SSO, Redis, email/Slack/LINE notifications.
- Jobs: `cron/*.php`, `worker/notification-worker.php`.

## Entry Points

- `webhook.php?account={id}`: LINE webhook entry point with signature validation and account resolution.
- `auth/login.php`: tenant admin login.
- `admin/platform-login.php`: platform user login.
- `inbox-v2.php`: active CRM inbox and same-page AJAX handler.
- `api/checkout.php`: mini app shop/cart/order/payment endpoint.
- `api/ai-chat.php`: SSE AI chat endpoint.
- `backend/src/server.ts`: Fastify server and WebSocket setup.
- `cron/*.php`: scheduled job scripts.

## Component Diagram

```mermaid
flowchart TB
  subgraph Client
    LINEChat[LINE chat]
    Mini[LINE Mini App]
    AdminUI[PHP Admin UI]
  end
  subgraph PHP
    Webhook[webhook.php]
    AdminPages[root/admin PHP pages]
    Api[api/*.php]
    Classes[classes/*]
    AI[modules/AIChat/*]
    Jobs[cron/*.php worker/*]
  end
  subgraph Node
    Fastify[backend/src/server.ts]
    Routes[backend/src/routes/*]
    WS[DashboardWebSocketServer]
  end
  DB[(MySQL)]
  Redis[(Redis)]
  LineAPI[LINE API]
  Gemini[Gemini API]

  LINEChat --> Webhook
  Mini --> Api
  AdminUI --> AdminPages
  AdminPages --> Classes
  Webhook --> Classes
  Api --> Classes
  Api --> AI
  Jobs --> Classes
  Classes --> DB
  AI --> DB
  Classes --> LineAPI
  AI --> Gemini
  Fastify --> Routes
  Routes --> DB
  WS --> Redis
```

## Key Sequence: LINE Message

```mermaid
sequenceDiagram
  participant LINE
  participant Webhook as webhook.php
  participant Account as LineAccountManager
  participant Bot as BusinessBot/AI
  participant DB as MySQL
  participant API as LINE API
  LINE->>Webhook: event body + X-Line-Signature
  Webhook->>Account: resolve by ?account or signature
  Account->>DB: read line_accounts
  Webhook->>Bot: process message/postback
  Bot->>DB: read/write users, messages, cart, states
  Bot->>API: replyMessage or pushMessage
```

## Key Sequence: Mini App Checkout

```mermaid
sequenceDiagram
  participant Mini as line-mini-app
  participant Config as config.ts
  participant Checkout as api/checkout.php
  participant DB as MySQL
  Mini->>Config: resolve API base + line_account_id
  Mini->>Checkout: action=products/cart/create_order/upload_slip
  Checkout->>DB: users, business_items, cart, transactions, payment_slips
  Checkout-->>Mini: JSON response
```

## Inferred Facts

The repo is in a transition toward tenant-aware database routing. Evidence: `modules/Core/Database.php` supports `forTenant()` and `platform()`, while `classes/Database.php` warns that `config/database.php` may still win depending on load order.

## Last Verified From Code

Verified on 2026-07-03 from `webhook.php`, `classes/LineAccountManager.php`, `classes/BusinessBot.php`, `classes/LineAPI.php`, `line-mini-app/src/lib/config.ts`, `line-mini-app/src/lib/shop-api.ts`, `api/checkout.php`, `backend/src/server.ts`, `backend/src/routes/index.ts`, `modules/Core/Database.php`, `classes/Database.php`.
