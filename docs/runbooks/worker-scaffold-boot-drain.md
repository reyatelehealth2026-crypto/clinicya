# apps/worker scaffolding — boot, heartbeat fan-out, health, drain

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` §1.1
(monorepo layout — `apps/worker`), Phase 8 (Odoo stack, future), Phase 10
(cron 33 jobs → BullMQ, future), §4.6 (CI/CD blue-green — Node set only).
Owner: mig-infra (Docker/compose/e2e wiring, this runbook) / mig-worker
(`apps/worker/**` source). Cross-reference: `docs/runbooks/phase0-cutover-rollback.md`
(the PHP/MariaDB/Redis singleton layer this worker sits alongside),
`docs/runbooks/phase3-batch1-miniapp-api-parity.md` (same JSON-line-output /
documented-limits convention this batch's harness follows).

## Scope note — read first (same documented-limits pattern every other
`infra/e2e/*.mjs` harness in this repo uses)

This batch wires the `apps/worker` Node process (built by a companion
mig-worker batch — pure scaffolding: BullMQ queue + typed job registry +
tenant-fanout primitive + DLQ + health endpoint + graceful drain, **no real
cron-job business logic ported yet**) into the Docker topology and proves it
for real: `infra/worker/Dockerfile`, `infra/compose/docker-compose.worker.yml`,
and `infra/e2e/worker-smoke.mjs`.

**What `infra/e2e/worker-smoke.mjs` proves**, on a REAL container built from
the REAL committed `infra/worker/Dockerfile` (never mocks, never a stubbed
`apps/worker`):

1. The image builds — pnpm-workspace-aware 3-stage build (deps → builder →
   runner), `apps/worker/dist/index.js` is a real compiled entry point.
2. The running container reaches a REAL Redis (`ioredis` PING via the
   worker's own `GET /health`) and a REAL MariaDB 10.11 (real `mysql2` pools
   opened by `@reya/db`'s `getMasterDb()`/`getTenantDb()`).
3. The one real job this scaffold ships — the repeatable
   `worker-heartbeat` BullMQ job (`apps/worker/src/jobs/heartbeat.ts`) —
   fans out over REAL seeded tenant DBs via
   `forEachActiveTenant()` (`apps/worker/src/tenant/forEachActiveTenant.ts`)
   and writes a REAL `activity_logs` row (`action='worker.heartbeat'`,
   `extra_data` containing the matching `tenantId`) per **active** tenant —
   for **two** seeded active tenants, and explicitly **not** for a third,
   seeded **suspended** tenant (proves `forEachActiveTenant()`'s
   `WHERE status = 'active'` is doing real filtering work, not merely
   "happening" to only see active rows because no other kind exists in the
   harness's DB).
4. `GET /health` reports real BullMQ queue-state (plan §5.3's named metric —
   waiting/active/delayed/failed/completed counts, oldest-waiting age), not
   just a bare `200 OK`.
5. `SIGTERM` drains an in-flight job — its DB write lands — **before** the
   process exits (`apps/worker/src/shutdown.ts`'s `Worker#close()` await),
   and the container exits within `WORKER_SHUTDOWN_TIMEOUT_MS`'s hard-kill
   fallback budget: never instant-killed mid-write, never hung forever.

**What this batch does NOT prove** (later batches' job, not this one's):

- **None of Phase 10's real 33 PHP `cron/*.php` jobs.** This scaffold ships
  exactly one trivial proof-of-life job (`worker-heartbeat`). No cron
  business logic has been ported.
- **No `cron-manifest.json` single-ownership mechanics.** The plan's Phase
  10 design (a single manifest rendering both crond's schedule and BullMQ's
  registry so a job is never double-owned, plus the Redis
  `cron:{job}:{window}` lock for dangerous jobs) does not exist yet —
  `apps/worker/src/jobs/types.ts`'s `JobTrigger` type only has `'repeat'`
  and `'manual'`, deliberately not yet a cron-string form.
- **No actual blue/green color-flip.** `docker-compose.worker.yml` labels
  `worker` as a member of the Node blue/green set (plan §4.6:
  admin/miniapp/worker) so that membership is machine-checkable, but no
  color split (`worker-blue`/`worker-green`) exists at this scaffolding
  stage — that is a future CI/CD batch.
- **No real Odoo (Phase 8) or cron (Phase 10) job registrations.** Those
  batches add real `JobDefinition`s to the registry this batch stood up;
  none of that logic lives here.

If you need a broader/live check, that is a separate, later verification
pass — this harness's JSON output says `"result":"PASS"` for the scaffold
boot/fan-out/health/drain mechanics only, never "Phase 8: PASS" or
"Phase 10: PASS" in any larger sense.

---

## 1. How to run the smoke test

```bash
node infra/e2e/worker-smoke.mjs
```

Single command, no flags required. It will (in order):

1. `docker compose -p reya-e2e-worker-smoke -f infra/e2e/docker-compose.yml
   up -d mariadb redis` — the SAME compose file `run.mjs`/`parity.mjs`/
   `api-parity.mjs`/`rollback-drill.mjs` use, **unmodified**, but only the
   `mariadb`+`redis` services (no `--build`, no `php` — this smoke test has
   no use for the PHP monolith). Because that file's container
   names/ports are fixed (not templated per-project), **this harness
   cannot run concurrently** with any of the other four `infra/e2e/*.mjs`
   scripts — same pre-existing sequential-only constraint they already have
   with each other.
2. Seed the master DB (the same six committed migrations
   `run.mjs`/`parity.mjs`/`api-parity.mjs` each already carry their own copy
   of), then — unlike every other harness here, which seeds ONE tenant —
   provision **three** separate tenant DBs (the real ~280-table
   `database/migration_2026-05-25_tenant_template.sql`, applied once per
   tenant, the same "CREATE DATABASE; USE db; \<template\>;" flow
   `05-app-db.sql.tmpl` already establishes) and seed
   `infra/e2e/seed/60-worker-smoke-tenants.sql.tmpl`'s three
   `master.tenants` rows: two `status='active'`, one `status='suspended'`.
3. `docker build -f infra/worker/Dockerfile -t clinicya-worker:smoke .`
   (repo root context — the real, committed Dockerfile, no shortcuts).
4. `docker run` that image on the harness's own compose-generated network
   (resolved via `docker network ls --filter
   label=com.docker.compose.project=...`, not assumed), with
   `REDIS_URL`/`DB_HOST`/`DB_USER`/`DB_PASS` pointed at the harness's own
   `e2e-redis`/`e2e-mariadb` containers, `WORKER_HEARTBEAT_INTERVAL_MS`
   shortened to 4s and `WORKER_SHUTDOWN_TIMEOUT_MS` to 20s (scaffold-smoke
   speed, not apps/worker's own 60s/25s production defaults — see §3 below)
   and its health port published to the host at `18099` so this harness's
   Node process (which runs on the host, like every other `infra/e2e/*.mjs`
   script) can poll it directly.
5. Poll `GET /health` until it reports `status:'ok'` (proves the container's
   OWN Redis client, not just the redis container's own healthcheck, is
   reachable), then poll both active tenants' `activity_logs` tables until a
   fresh `worker.heartbeat` row with the matching `tenantId` appears, then
   assert the suspended tenant's `activity_logs` has **zero** such rows.
6. Assert `GET /health`'s `worker-main` queue entry has sane numeric fields
   and `completed >= 1`.
7. Enqueue one extra manual `worker-heartbeat` job directly via `bullmq`'s
   `Queue` (imported from `apps/worker`'s own resolved `node_modules` via
   `createRequire` — this harness does not add `bullmq` as a dependency of
   its own), poll `/health` for the main queue's `active` count to flip to
   `>= 1` (BullMQ marks a job `'active'` the instant the `Worker` dequeues
   it, before the handler's async body runs), and send `docker kill -s
   SIGTERM` at that exact moment — deterministic in-flight timing instead of
   racing the natural repeat schedule.
8. Confirm the in-flight job's write actually lands (via the `/health`
   completed-count increasing while the container is still running, or —
   if the container has already exited by the time we poll — via a fresh
   `activity_logs` row timestamped at/after the signal), THEN confirm the
   container exits, within budget.
9. Print one JSON line: `{"result": "PASS"|"FAIL", "steps": {...},
   "failedAt": "..."|null}` — same shape `run.mjs`/`parity.mjs`/
   `api-parity.mjs`/`rollback-drill.mjs` already use. `steps` includes (at
   minimum) `compose_up`, `redis_reachable`, `container_healthy`,
   `heartbeat_active_tenant_1_row`, `heartbeat_active_tenant_2_row`,
   `heartbeat_suspended_tenant_no_row`, `health_endpoint_reports_queue_depth`,
   `sigterm_drains_inflight_job`, `container_exits_within_budget`.
10. **Always tears down** — `docker rm -f`/`docker rmi -f` the worker
    container/image this run built, then `docker compose down -v` for
    `mariadb`/`redis` — in a `finally` block, even on a thrown error. `docker
    ps -a` shows zero leftover containers/networks from this script's
    project name (`reya-e2e-worker-smoke`) afterward, pass or fail.

### Reading a FAIL

Look at `failedAt` first — it names the step that threw. Every step's entry
in `steps` is `{ok: true, detail?: ...}` or `{ok: false, message: ...,
detail?: ...}`; a failed step's `detail` carries whatever diagnostic context
was available at the point of failure (raw SQL output, HTTP status, queue
counts, etc.) — read that before re-running.

---

## 2. Bringing `worker` up for manual/local dev (not the smoke test)

`infra/compose/docker-compose.worker.yml` is a **third additive overlay** —
same "layer on top, never edit an existing compose file in place" discipline
`docker-compose.strangler.yml`'s own header comment documents, extended one
layer further. It supplies **only** the `worker` service; `redis` comes from
`docker-compose.dev.yml` and `mariadb` comes from
`infra/compose/docker-compose.strangler.yml` — this file has no opinion on
how either of those is built, only that all three layers are passed
together:

```bash
docker compose --env-file infra/compose/.env \
  -f docker-compose.dev.yml \
  -f infra/compose/docker-compose.strangler.yml \
  -f infra/compose/docker-compose.worker.yml \
  up -d worker
```

(`infra/compose/.env` — copy from `infra/compose/.env.example` and fill in
real values, same as the strangler overlay already documents; never commit
the real `.env` file.)

Validate the 3-file overlay's compose syntax/var-interpolation without a
live boot:

```bash
docker compose --env-file infra/compose/.env \
  -f docker-compose.dev.yml \
  -f infra/compose/docker-compose.strangler.yml \
  -f infra/compose/docker-compose.worker.yml \
  config
```

---

## 3. Forward notes for mig-orc

Two real gaps were flagged during this batch's build — neither is this
batch's to fix (allowed paths: `infra/compose/**`, `infra/worker/**`,
`infra/e2e/worker-smoke.mjs` + its own new seed file, `docs/runbooks/**`
only) — routed here for whichever future batch owns them:

**(a) `WORKER_HEALTH_PORT` / `WORKER_HEARTBEAT_INTERVAL_MS` /
`WORKER_SHUTDOWN_TIMEOUT_MS` are NOT yet in `packages/config`'s zod
`envSchema`** (`packages/config/src/env.ts`). `apps/worker/src/env.ts` reads
all three directly off `process.env` with its own local defaults (8099 /
60_000ms / 25_000ms — see that file's own doc comment, which already flags
this exact gap by name: *"FLAGGED FOR A FUTURE packages/config BATCH
(mig-orc: route to mig-kernel)"*). A future mig-kernel batch should formalize
these three in the shared schema alongside `REDIS_URL` so every consumer
(this worker, and eventually `apps/admin` if it ever needs to read the
worker's health port) gets the same validated/typed shape. This runbook's
own compose file (`infra/compose/docker-compose.worker.yml`) and smoke
harness (`infra/e2e/worker-smoke.mjs`) both currently set these three
directly via `docker run`/`environment:` env vars, working around the schema
gap the same way `apps/worker/src/env.ts` itself does — not a blocker for
this batch, but the duplication (default values now live in three places:
`apps/worker/src/env.ts`, this runbook's text, and
`infra/worker/Dockerfile`'s own `ENV WORKER_HEALTH_PORT=8099`) is exactly
the kind of drift risk formalizing the schema would remove.

**(b) `dev_logs` schema-governance gap.**
`database/migration_2026-05-25_tenant_template.sql`'s own header comment
claims platform-level tables including `dev_logs` live in the **master** DB
— but no migration that actually creates `dev_logs` exists yet in
`packages/db/migrations/master`, nor is it in the generated
`packages/db/src/generated/master-db.d.ts` types. `apps/worker/src/jobs/heartbeat.ts`
deliberately targets the tenant DB's real, already-generated `activity_logs`
table instead (see that file's own doc comment, which flags this same gap)
— this batch's smoke test and compose wiring never touch `dev_logs` either.
Flagged here as a genuine schema-governance gap for mig-kernel, discovered
during (but unrelated to) this scaffolding batch.

---

## 4. Files this batch touches

- `infra/worker/Dockerfile` — new. 3-stage (deps/builder/runner), pnpm
  workspace-aware, Node 20-alpine, non-root (uid 1001), `EXPOSE 8099`,
  `HEALTHCHECK` via the same `node -e "fetch(...)"` idiom
  `backend/Dockerfile` already uses, `CMD ["node",
  "apps/worker/dist/index.js"]`. Build context = repo root (mirrors the
  `infra/php/Dockerfile` precedent: infra-owned, infra-located Dockerfile
  for an app whose source lives elsewhere). A leaner `pnpm deploy`-pruned
  runtime bundle (dropping devDependencies from the shipped image) is
  flagged inline as a reasonable follow-up, not required for this
  scaffolding batch.
- `infra/compose/docker-compose.worker.yml` — new. Additive overlay, adds
  only the `worker` service (see §2 above).
- `infra/e2e/worker-smoke.mjs` — new. See §1 above.
- `infra/e2e/seed/60-worker-smoke-tenants.sql.tmpl` — new. Three scratch
  `master.tenants` rows (two active, one suspended) — see that file's own
  header comment.
- `docs/runbooks/worker-scaffold-boot-drain.md` — this file.

Explicitly **not** touched: `apps/worker/**` (owned by the companion
mig-worker batch — this batch only wires and proves what already landed
there), `docker-compose.{dev,prod,blue,green}.yml`,
`infra/compose/docker-compose.strangler.yml`, and every other existing
`infra/e2e/*.mjs`/`infra/e2e/seed/*` file (`run.mjs`, `parity.mjs`,
`api-parity.mjs`, `rollback-drill.mjs`, `lib/harness-common.mjs`, and every
seed file numbered below 60).
