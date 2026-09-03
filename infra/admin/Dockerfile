# infra/admin/Dockerfile
#
# Production image for apps/admin — the Next.js admin app (UI *and* its API
# Route Handlers; in App Router there is no separate backend process, so this
# one container is both "หน้าบ้าน" and "หลังบ้าน" of the new stack).
#
# Lives in infra/ (not apps/admin/) to match the existing precedent set by
# infra/php/Dockerfile and infra/worker/Dockerfile: infra-owned Dockerfiles,
# build context = repo root.
#
# Design notes (read before editing):
#
#  - apps/admin/next.config.ts sets `output: 'standalone'`, so `next build`
#    emits a self-contained bundle at apps/admin/.next/standalone containing a
#    trimmed node_modules plus the workspace layout. VERIFIED against a real
#    build of this repo: the entrypoint is
#    `<standalone>/apps/admin/server.js`, and Next auto-detects
#    outputFileTracingRoot as the repo root (the pnpm workspace root) with no
#    config change needed — inside this image that resolves to /app.
#
#  - @reya/* workspace packages and kysely do NOT appear as separate
#    node_modules entries in the standalone output; Next bundles them into the
#    server chunks (kysely is in `transpilePackages`). Only genuinely external
#    runtime deps are traced out (ioredis, next, sharp, ...). So there is
#    nothing extra to COPY for the workspace packages — but their SOURCE is
#    still required in the builder stage, which is why every package.json and
#    source tree apps/admin depends on is copied below.
#
#  - Debian slim, not alpine: Next pulls in `sharp` for image optimisation and
#    the traced variant is glibc-flavoured. Installing on a musl base works
#    only if the musl variant resolves at install time — a footgun that costs
#    a confusing runtime crash rather than a build error. glibc avoids it.
#    (infra/worker/Dockerfile can stay on alpine; it has no sharp.)
#
#  - Static assets are NOT inside the standalone bundle by design (Next's
#    documented behaviour) — `.next/static` is copied in explicitly below.
#    Skipping this yields a site that renders HTML but 404s every CSS/JS
#    chunk, which looks like a broken app rather than a missing COPY.
#
#  - There is no apps/admin/public directory in this repo (checked); if one is
#    ever added, add a matching COPY here or its files will 404 in production.

# ---------------------------------------------------------------------------
# Stage 1: deps — resolve the workspace dependency graph once, frozen.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS deps
WORKDIR /app

RUN corepack enable && corepack prepare pnpm@10.33.0 --activate

# Manifests only — this layer invalidates on dependency changes, not on every
# source edit. The package list must match apps/admin/package.json's
# "workspace:*" dependencies; adding a new @reya/* dep to apps/admin means
# adding its package.json here too.
COPY pnpm-lock.yaml pnpm-workspace.yaml package.json ./
COPY packages/auth/package.json      packages/auth/package.json
COPY packages/config/package.json    packages/config/package.json
COPY packages/contracts/package.json packages/contracts/package.json
COPY packages/core/package.json      packages/core/package.json
COPY packages/db/package.json        packages/db/package.json
COPY packages/line/package.json      packages/line/package.json
COPY packages/tenant/package.json    packages/tenant/package.json
COPY apps/admin/package.json         apps/admin/package.json

RUN pnpm install --frozen-lockfile

# ---------------------------------------------------------------------------
# Stage 2: builder — real source, then build admin + everything it depends on.
# `--filter admin...` (trailing dots) pulls in the transitive workspace deps,
# the same shape turbo.json's `dependsOn: ["^build"]` pipeline assumes.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS builder
WORKDIR /app

RUN corepack enable && corepack prepare pnpm@10.33.0 --activate

COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/package.json /app/pnpm-lock.yaml /app/pnpm-workspace.yaml ./
COPY --from=deps /app/packages ./packages
COPY --from=deps /app/apps ./apps

COPY packages/auth      packages/auth
COPY packages/config    packages/config
COPY packages/contracts packages/contracts
COPY packages/core      packages/core
COPY packages/db        packages/db
COPY packages/line      packages/line
COPY packages/tenant    packages/tenant
COPY apps/admin         apps/admin

# next build reads NEXT_PUBLIC_* at build time and inlines them into the
# client bundle — they cannot be supplied later via compose `environment:`.
# Declared as build args so docker-compose.vps.yml can pass them through.
ARG NEXT_PUBLIC_REALTIME_URL=""
ENV NEXT_PUBLIC_REALTIME_URL=$NEXT_PUBLIC_REALTIME_URL

ENV NEXT_TELEMETRY_DISABLED=1
RUN pnpm --filter admin... build

# ---------------------------------------------------------------------------
# Stage 3: runner — standalone bundle only, non-root.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS runner
WORKDIR /app

# curl is used by the compose healthcheck; --no-install-recommends keeps the
# layer small.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Same uid/gid convention as infra/worker/Dockerfile.
RUN groupadd --system --gid 1001 nodejs \
    && useradd --system --uid 1001 --gid nodejs nodejs

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    TZ=Asia/Bangkok

# The standalone bundle already contains its own trimmed node_modules and the
# workspace directory layout — copying its CONTENTS to /app reproduces the
# tracing root Next baked into server.js.
COPY --from=builder --chown=nodejs:nodejs /app/apps/admin/.next/standalone ./
# Static chunks live outside the standalone bundle — see the header note.
COPY --from=builder --chown=nodejs:nodejs /app/apps/admin/.next/static ./apps/admin/.next/static

USER nodejs

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
  CMD curl -fsS http://localhost:3000/api/health -o /dev/null || exit 1

CMD ["node", "apps/admin/server.js"]
