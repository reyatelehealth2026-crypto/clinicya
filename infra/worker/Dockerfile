# infra/worker/Dockerfile
#
# Phase 8/10 scaffolding image for apps/worker (plan §1.1 "worker/ — Node
# เดียว: BullMQ workers + cron scheduler + WebSocket รวม"; §4.6 — this is the
# THIRD member of the Node blue/green set, alongside apps/admin and
# apps/miniapp).
#
# Lives here (infra/worker/Dockerfile), NOT apps/worker/Dockerfile, on
# purpose: this batch's allowed paths are infra/**, not apps/worker/** —
# apps/worker's own source is owned by the concurrent mig-worker batch (see
# docs/runbooks/worker-scaffold-boot-drain.md). This mirrors the existing
# infra/php/Dockerfile precedent — infra-owned, infra-located Dockerfiles,
# build context = repo root, one level up from the app/runtime they package.
#
# Design notes (read before editing):
#
#  - This is a pnpm-workspace-aware 3-stage build (deps / builder / runner),
#    same overall shape as backend/Dockerfile (Node 20-alpine, non-root
#    runner, `node -e "fetch(...)"` healthcheck idiom) but adapted for pnpm
#    workspaces + Turborepo instead of a single-package `npm ci`:
#      - backend/Dockerfile's `deps` stage does a *production-only* install
#        that gets thrown away (builder re-installs everything anyway) —
#        with pnpm we can avoid that redundant double install: `deps` does
#        ONE full `pnpm install --frozen-lockfile` (dev+prod — the builder
#        stage needs devDependencies like `typescript`/`tsc` to compile),
#        and `builder` reuses `deps`' node_modules directly via
#        `COPY --from=deps` instead of re-running install.
#      - `runner` copies the built `apps/worker/dist` output AND the
#        resolved node_modules tree from `builder` as-is (dev+prod deps
#        both present) rather than doing a THIRD prod-only install or a
#        `pnpm deploy`-pruned bundle. That is intentionally the simplest
#        correct thing for THIS scaffolding batch — a leaner `pnpm deploy`
#        -based pruned runtime bundle (dropping devDependencies from the
#        shipped image) is a reasonable follow-up, not required here.
#
#  - `deps` COPYs only the package.json manifests actually needed to
#    resolve+install apps/worker's dependency graph — root
#    pnpm-lock.yaml/pnpm-workspace.yaml/package.json plus
#    packages/config, packages/db, packages/tenant, and apps/worker (the
#    plan's documented dependency set: apps/worker imports @reya/config,
#    @reya/db, @reya/tenant — see packages/tenant/package.json's own
#    "dependencies" for the same chain). `pnpm install --frozen-lockfile`
#    validates the copied manifests against pnpm-lock.yaml, so this list
#    MUST be kept in sync with whatever apps/worker/package.json actually
#    declares as workspace ("workspace:*") dependencies — if a future
#    apps/worker adds e.g. `@reya/contracts` or `@reya/auth`, add that
#    package's package.json to this COPY list too (and re-run
#    `pnpm install` at the repo root so pnpm-lock.yaml stays current).
#
#  - CMD path (`apps/worker/dist/index.js`) and EXPOSE 8099 follow this
#    batch's documented contract (WORKER_HEALTH_PORT=8099, entrypoint
#    apps/worker/dist/index.js — see docs/runbooks/worker-scaffold-boot-drain.md
#    and infra/compose/docker-compose.worker.yml). apps/worker did not exist
#    yet in this worktree when this Dockerfile was written (mig-worker's
#    companion batch lands it) — the dist output path assumes the SAME
#    tsc-project-references convention already used by packages/config,
#    packages/db, packages/tenant (`"build": "tsc -b"`, `"main":
#    "./dist/index.js"`, i.e. a flat `dist/index.js` entry point relative to
#    the package directory, not a nested `dist/apps/worker/src/index.js`).
#    If apps/worker's actual `tsc -b`/bundler output lands somewhere else
#    (e.g. a bundler that emits `dist/server.js`, or ESM-only output), fix
#    the COPY/CMD paths below to match the real build output — verify with
#    `docker build -f infra/worker/Dockerfile --target builder -t
#    clinicya-worker:builder-check .` then `docker run --rm
#    clinicya-worker:builder-check find apps/worker/dist` before trusting
#    this comment.
#
#  - WORKER_HEALTH_PORT=8099 is set as an image-level default (ENV) so a
#    bare `docker run` (e.g. infra/e2e/worker-smoke.mjs, which does not go
#    through docker-compose.worker.yml) gets a working healthcheck without
#    having to pass the var explicitly. docker-compose.worker.yml also sets
#    it explicitly (self-documenting), which simply overrides this same
#    default with the same value.
#
#  - WORKER_HEALTH_PORT/WORKER_HEARTBEAT_INTERVAL_MS/WORKER_SHUTDOWN_TIMEOUT_MS
#    are NOT yet part of packages/config's zod envSchema
#    (packages/config/src/env.ts) — see this runbook's "forward notes for
#    mig-orc" section. apps/worker is expected to read them directly off
#    process.env (with its own defaults) until a future mig-kernel batch
#    formalizes them in envSchema.

# ---------------------------------------------------------------------------
# Stage 1: deps — resolve the whole pnpm workspace's dependency graph once,
# frozen against the committed lockfile (no implicit upgrades in CI/prod
# builds).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS deps
WORKDIR /app

# corepack ships with Node 20 but the shim still needs to fetch the exact
# pnpm version pinned by root package.json's "packageManager" field the
# first time it runs — pin it explicitly here too so this build is
# reproducible even if that field ever drifts.
RUN corepack enable && corepack prepare pnpm@10.33.0 --activate

# Manifests only (no source yet) — maximizes Docker layer-cache hits: this
# layer only invalidates when a package.json or the lockfile changes, not on
# every source edit.
COPY pnpm-lock.yaml pnpm-workspace.yaml package.json ./
COPY packages/config/package.json packages/config/package.json
COPY packages/db/package.json packages/db/package.json
COPY packages/tenant/package.json packages/tenant/package.json
COPY apps/worker/package.json apps/worker/package.json

RUN pnpm install --frozen-lockfile

# ---------------------------------------------------------------------------
# Stage 2: builder — bring in real source, build apps/worker AND its
# workspace package dependencies (`pnpm --filter worker...` — the trailing
# `...` pulls in every workspace package `worker` depends on, transitively,
# same Turborepo-aware filter syntax `turbo.json`'s `dependsOn: ["^build"]`
# pipeline already assumes).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS builder
WORKDIR /app

RUN corepack enable && corepack prepare pnpm@10.33.0 --activate

COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/package.json /app/pnpm-lock.yaml /app/pnpm-workspace.yaml ./
COPY --from=deps /app/packages/config/package.json packages/config/package.json
COPY --from=deps /app/packages/db/package.json packages/db/package.json
COPY --from=deps /app/packages/tenant/package.json packages/tenant/package.json
COPY --from=deps /app/apps/worker/package.json apps/worker/package.json

COPY packages/config packages/config
COPY packages/db packages/db
COPY packages/tenant packages/tenant
COPY apps/worker apps/worker

RUN pnpm --filter worker... build

# ---------------------------------------------------------------------------
# Stage 3: runner — non-root, only the built output + resolved
# node_modules tree (see the "leaner pnpm deploy" note above for why this
# isn't pruned to prod-only deps yet).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS runner
WORKDIR /app

# Same uid/gid convention as backend/Dockerfile's `nodejs` user.
RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nodejs

ENV NODE_ENV=production \
    WORKER_HEALTH_PORT=8099

COPY --from=builder --chown=nodejs:nodejs /app/node_modules ./node_modules
COPY --from=builder --chown=nodejs:nodejs /app/package.json /app/pnpm-workspace.yaml ./
COPY --from=builder --chown=nodejs:nodejs /app/packages/config packages/config
COPY --from=builder --chown=nodejs:nodejs /app/packages/db packages/db
COPY --from=builder --chown=nodejs:nodejs /app/packages/tenant packages/tenant
COPY --from=builder --chown=nodejs:nodejs /app/apps/worker apps/worker

USER nodejs

EXPOSE 8099

# Same `node -e "fetch(...)"` idiom backend/Dockerfile already uses — no
# extra curl/wget binary needed in the runner image.
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
  CMD node -e "fetch('http://localhost:8099/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "apps/worker/dist/index.js"]
