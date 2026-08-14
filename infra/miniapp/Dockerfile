# infra/miniapp/Dockerfile
#
# Image for the `next-miniapp` upstream — the LINE Mini App (line-mini-app/),
# the deployed LIFF experience. Without it the strangler edge's /miniapp route
# has nothing to proxy to and returns 502.
#
# Design notes (read before editing):
#
#  - line-mini-app is NOT part of the pnpm workspace. pnpm-workspace.yaml globs
#    only apps/* and packages/*; this app lives at the repo root with its own
#    package-lock.json (whitelisted in .gitignore). So: npm ci, not pnpm — and
#    unlike infra/admin/Dockerfile there are no sibling workspace packages to
#    copy in.
#
#  - The runtime is nginx, not Node. next.config.js sets `output: 'export'`,
#    which emits a static `out/` tree; there is no server to start. See
#    infra/miniapp/nginx.conf for how basePath and trailingSlash map onto disk.
#
#  - Every NEXT_PUBLIC_* below is a BUILD-time input. A static export inlines
#    them into the emitted JS, so they cannot be changed by compose
#    `environment:` later — changing one means rebuilding this image.
#
#  - NEXT_PUBLIC_PHP_API_BASE_URL is intentionally left EMPTY by default.
#    line-mini-app/src/lib/config.ts's resolvePhpApiBaseUrl() runs in the
#    browser and returns window.location.origin for any *.re-ya.com host that
#    is not the bare root — so when the app is served from a tenant subdomain
#    (or the trial hostname) it calls that same origin, which is what lets PHP's
#    subdomain routing pin the right tenant DB. Baking a fixed origin here would
#    override that and point every tenant at one backend. Only set it when the
#    app is served from a host that is NOT a re-ya.com subdomain.

# ---------------------------------------------------------------------------
# Stage 1: builder
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS builder
WORKDIR /app

# Manifests first so this layer caches independently of source edits.
COPY line-mini-app/package.json line-mini-app/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY line-mini-app/ ./

ARG NEXT_PUBLIC_PHP_API_BASE_URL=""
ARG NEXT_PUBLIC_REYA_BASE_DOMAIN="re-ya.com"
ARG NEXT_PUBLIC_LINE_LIFF_ID=""
ARG NEXT_PUBLIC_LINE_CHANNEL_ID=""
ARG NEXT_PUBLIC_LINE_ACCOUNT_ID="1"
ARG NEXT_PUBLIC_MINIAPP_NAME="Re-Ya LINE Mini App"
ARG NEXT_PUBLIC_SHOP_CATALOG_MODE="all"
ARG NEXT_PUBLIC_SHOP_CATALOG_BUCKET=""
ARG NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE=""
ARG NEXT_PUBLIC_SHOP_HIDE_INACTIVE=""
ARG NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES=""

ENV NEXT_PUBLIC_PHP_API_BASE_URL=$NEXT_PUBLIC_PHP_API_BASE_URL \
    NEXT_PUBLIC_REYA_BASE_DOMAIN=$NEXT_PUBLIC_REYA_BASE_DOMAIN \
    NEXT_PUBLIC_LINE_LIFF_ID=$NEXT_PUBLIC_LINE_LIFF_ID \
    NEXT_PUBLIC_LINE_CHANNEL_ID=$NEXT_PUBLIC_LINE_CHANNEL_ID \
    NEXT_PUBLIC_LINE_ACCOUNT_ID=$NEXT_PUBLIC_LINE_ACCOUNT_ID \
    NEXT_PUBLIC_MINIAPP_NAME=$NEXT_PUBLIC_MINIAPP_NAME \
    NEXT_PUBLIC_SHOP_CATALOG_MODE=$NEXT_PUBLIC_SHOP_CATALOG_MODE \
    NEXT_PUBLIC_SHOP_CATALOG_BUCKET=$NEXT_PUBLIC_SHOP_CATALOG_BUCKET \
    NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE=$NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE \
    NEXT_PUBLIC_SHOP_HIDE_INACTIVE=$NEXT_PUBLIC_SHOP_HIDE_INACTIVE \
    NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES=$NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES \
    NEXT_TELEMETRY_DISABLED=1

RUN npm run build

# Fail the build rather than ship an empty site if `output: 'export'` is ever
# removed from next.config.js — without it `out/` is never produced and the
# COPY below would silently yield a 404-only container.
RUN test -f out/index.html || (echo "ERROR: out/index.html missing — is output:'export' still set in next.config.js?" && exit 1)

# ---------------------------------------------------------------------------
# Stage 2: runner — plain nginx serving the static export.
# ---------------------------------------------------------------------------
FROM nginx:alpine AS runner

ENV TZ=Asia/Bangkok

# The stock welcome page is a separate file from the config — replacing
# conf.d/default.conf alone leaves it serving 200 on `GET /`.
RUN rm -f /usr/share/nginx/html/index.html /usr/share/nginx/html/50x.html

COPY infra/miniapp/nginx.conf /etc/nginx/conf.d/default.conf

# out/ -> /miniapp/ : the emitted tree has no miniapp/ directory of its own, but
# the HTML references /miniapp/_next/... (assetPrefix). See nginx.conf's header.
COPY --from=builder /app/out /usr/share/nginx/html/miniapp

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- http://localhost:3000/__miniapp-health || exit 1
