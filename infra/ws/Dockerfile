# infra/ws/Dockerfile
#
# Packages the LEGACY realtime server (websocket-server.js at the repo root)
# so the strangler edge's `ws` upstream has something to answer on.
#
# Why the legacy server and not apps/worker's realtime relay:
#   infra/nginx/routes.json's `/ws` entry records that apps/worker's relay is
#   verified-capable but **NOT YET FLIPPED** — it is waiting on a canary ramp
#   order. Backing `ws` with the worker here would be that flip in all but
#   name, made silently through container topology instead of through the
#   reviewable route manifest. So this stack runs exactly what production runs
#   today. When the flip is ordered, point the `ws` service at apps/worker
#   with WORKER_REALTIME_PORT=3001 and delete this file.
#
# Port: infra/nginx/generate-routes.mjs hardcodes the `ws` upstream as
# `ws:3001`, but websocket-server.js defaults to WEBSOCKET_PORT || 3000 —
# hence the explicit ENV below. Changing one without the other yields a 502
# on /ws that looks like a crashed container.
#
# Dependencies: `npm ci` installs devDependencies too, deliberately.
# websocket-server.js does `require('mysql2/promise')` at line 13 while the
# root package.json lists mysql2 under devDependencies — `--omit=dev` would
# produce an image that crashes on boot with MODULE_NOT_FOUND. Fixing that
# misclassification means editing the legacy root package.json, which is out
# of scope here.

FROM node:20-bookworm-slim AS deps
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM node:20-bookworm-slim AS runner
WORKDIR /app

RUN groupadd --system --gid 1001 nodejs \
    && useradd --system --uid 1001 --gid nodejs nodejs

ENV NODE_ENV=production \
    WEBSOCKET_PORT=3001 \
    WEBSOCKET_HOST=0.0.0.0 \
    TZ=Asia/Bangkok

COPY --from=deps --chown=nodejs:nodejs /app/node_modules ./node_modules
COPY --chown=nodejs:nodejs package.json ./
COPY --chown=nodejs:nodejs websocket-server.js ./

USER nodejs

EXPOSE 3001

HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
  CMD node -e "require('net').connect(3001,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))"

CMD ["node", "websocket-server.js"]
