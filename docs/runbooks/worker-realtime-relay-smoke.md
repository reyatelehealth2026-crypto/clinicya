# apps/worker realtime relay smoke — Redis pub/sub -> Socket.io

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` §1.1
(monorepo layout — `apps/worker`), §4.6 (CI/CD blue-green — Node set only).
Owner: mig-infra (Docker/e2e wiring, this runbook) / mig-worker
(`apps/worker/**` source — the wsInboxRelay batch that adds
`realtime/socketServer.ts` + `realtime/inboxRelay.ts`; the later
wsConsolidate batch that adds `realtime/typing.ts` and the typing/ping/
health/status surface this runbook's 2026-08-14 update covers). Cross-reference:
`docs/runbooks/worker-scaffold-boot-drain.md` (the companion runbook for the
BullMQ heartbeat scaffold this batch's harness is modeled on — **not edited
by this batch**, this is a new, separate runbook file per that precedent);
`docs/runbooks/websocket-consolidation.md` (wsConsolidate-owned — the
event/room/auth/side-effect inventory and exact wire shapes §3 of that doc
documents for infra's smoke-test extension, **not edited by this round**).

**UPDATE 2026-08-14 (mig-infra verification round):** extends this harness
with four additive steps — `typing_broadcast_received`, `ping_pong_received`,
`health_endpoint_ok`, `status_endpoint_ok` — exercising the wsConsolidate
batch's `realtime/typing.ts` + `realtime/socketServer.ts` additions on the
SAME already-running worker container the pre-existing steps prove (no
second `docker build`/`docker run`). `infra/nginx/routes.json`'s `/ws` entry
gets a status-only note update recording this verification; `upstream`
stays `'ws'` — no traffic flip. See "What this proves" / "Sandbox network
note" below for this round's detail, and §1's numbered run-through for where
the new steps land in the sequence.

## Scope note — read first (same documented-limits pattern every other
`infra/e2e/*.mjs` harness in this repo uses)

This batch wires apps/worker's new realtime relay into the Docker topology
and proves it for real: `infra/e2e/worker-realtime-relay-smoke.mjs`, plus two
small additive edits (`infra/worker/Dockerfile`'s `EXPOSE 8100`,
`infra/compose/docker-compose.worker.yml`'s `WORKER_REALTIME_PORT` env var).

**What `infra/e2e/worker-realtime-relay-smoke.mjs` proves**, on a REAL
container built from the REAL committed `infra/worker/Dockerfile` (never
mocks, never a stubbed `apps/worker`):

1. The image builds — the same pnpm-workspace-aware 3-stage build (deps ->
   builder -> runner) `infra/e2e/worker-smoke.mjs` already proves, rebuilt
   here under this script's own image tag (`clinicya-worker:realtime-smoke`).
2. The running container reaches a REAL Redis (`GET /health`'s existing
   `status:'ok'` PING signal — `health/server.ts` is unchanged by the
   realtime batch, so this is the exact same check `worker-smoke.mjs`
   already exercises).
3. A REAL `socket.io-client` (borrowed from `apps/worker`'s own resolved
   `node_modules` via `createRequire` — never added as this script's own
   dependency) connects to the container's dedicated realtime port
   (`WORKER_REALTIME_PORT`, default 8100) and joins Socket.io room
   `account_<lineAccountId>` via the `join_account` wire event —
   `{ lineAccountId: 4242 }`, the exact contract
   `apps/worker/src/realtime/socketServer.ts` documents as stable.
4. A synthetic message `PUBLISH`ed directly to Redis's `inbox_updates`
   channel — shaped exactly like `classes/WebSocketNotifier.php::
   notifyNewMessage()`'s payload, with a 150-char `message.content` so the
   100-char `last_message_preview` truncation is actually exercised, not
   accidentally passing on a short string — is relayed straight through to
   that connected client as:
   - a real `new_message` event whose payload deep-equals the published
     `message` sub-object **verbatim** (no reshaping — `assert.deepStrictEqual`);
   - a real `conversation_update` event with `user_id`, `last_message_at`,
     `last_message_preview` (first 100 chars of the published content),
     `unread_count`, and a finite numeric `timestamp` — the exact derivation
     `apps/worker/src/realtime/inboxRelay.ts` (and, before it,
     `websocket-server.js`'s own handler) implements.
5. **(2026-08-14 update)** On that SAME connected client/container, the
   wsConsolidate batch's typing/ping/health/status surface
   (`apps/worker/src/realtime/typing.ts` + the additions to
   `realtime/socketServer.ts` — see `docs/runbooks/websocket-consolidation.md`
   §3 for the exact shapes):
   - a SECOND `socket.io-client` joins the same `account_<lineAccountId>`
     room and emits `typing` — the original client (excluded from its own
     broadcasts by `socket.to(room)`) receives a real `typing` event with
     `user_id`/`is_typing`/`timestamp` and, provably, **no**
     `admin_id`/`admin_username` fields (the documented no-auth parity
     gap — asserted, not just assumed);
   - the original client emits `ping` (no payload) and receives a real
     `pong` with a finite `timestamp`;
   - `GET /health` and `GET /status` on the container's realtime port (the
     SAME dedicated `httpServer` Socket.io is attached to — a different
     endpoint from the separate Express `/health` on `WORKER_HEALTH_PORT`
     step 2 above already proves) both return `200 application/json` with
     the documented `{status, uptime, timestamp, connections:{total,rooms},
     typingIndicators, redis}` shape, `redis` asserted to be the literal
     string `'connected'` (provable at this point in the run because the
     synthetic Redis publish above already round-tripped through the real
     subscriber), and no stray `database` field.

**What this batch does NOT prove** (later/other batches' job, not this
one's):

- **No authentication.** `realtime/socketServer.ts`'s `join_account` handler
  has no auth check in this round (the legacy `authenticateToken()` DB
  lookup is explicitly out of scope for the wsInboxRelay/wsConsolidate
  batches) — this smoke test joins the room with a bare `{ lineAccountId }`,
  and emits `typing` with a bare client-supplied `user_id`, same as the real
  server currently accepts from anyone. The 2026-08-14 update's new steps
  exercise MORE of the wire surface but do not change this — still no auth
  anywhere in this harness.
- **No `/video-call` namespace.** Only the default Socket.io namespace /
  the `inbox_updates` relay path (plus, as of 2026-08-14, `typing`/`ping`/
  `/health`/`/status` on that same namespace/port) is exercised.
- **No canary ramp / traffic flip.** This harness proves the relay is
  *capable*; it does not flip `infra/nginx/routes.json`'s `/ws` upstream and
  never will on its own — that stays mig-orchestrator's call, gated on the
  auth-parity + soak items in `docs/runbooks/websocket-consolidation.md` §4.
- **No real UI client.** This is a bare `socket.io-client` instance driven
  from a Node script, not `line-mini-app`'s or the admin dashboard's actual
  Socket.io client code, and not a browser.
- **No MariaDB.** This harness brings up ONLY the `redis` service from
  `infra/e2e/docker-compose.yml` — no `mariadb`, no `php`. The worker
  container gets non-empty placeholder `DB_USER`/`DB_PASS` values and a
  long `WORKER_HEARTBEAT_INTERVAL_MS` purely to keep the (never-firing,
  never-reachable) heartbeat job's background DB-connection failures out of
  the container log; `GET /health` only pings Redis (see
  `apps/worker/src/health/server.ts`), so this is never on the pass/fail
  path. The "no MariaDB needed" assumption from this batch's brief held —
  no `infra/e2e/seed/*` file was added.
- **None of `worker-scaffold-boot-drain.md`'s BullMQ heartbeat proof
  (fan-out, `SIGTERM` drain, queue-depth reporting).** That is
  `infra/e2e/worker-smoke.mjs`'s job, unmodified and untouched by this
  batch — this harness is a separate, additive proof of the realtime relay
  only.

If you need a broader/live check, that is a separate, later verification
pass — this harness's JSON output says `"result":"PASS"` for the Redis
pub/sub -> Socket.io relay mechanics only, never a broader "realtime: PASS"
claim (auth, `/video-call`, or a real UI client).

---

## 1. How to run the smoke test

```bash
node infra/e2e/worker-realtime-relay-smoke.mjs
```

Single command, no flags required. It will (in order):

1. `docker compose -p reya-e2e-worker-realtime-smoke -f infra/e2e/docker-compose.yml
   up -d redis` — the SAME compose file `worker-smoke.mjs` and every other
   `infra/e2e/*.mjs` script use, **unmodified**, but only the `redis`
   service (no `mariadb`, no `php`, no `--build`). infra/e2e/docker-compose.yml
   interpolates every service's environment block up front regardless of
   which service names are passed to `up`, so the `mariadb`/`php`-only env
   vars (`E2E_APP_DB_NAME` etc.) are still generated as disposable,
   in-memory-only placeholders — never written to any tracked file, never
   actually used by a running `mariadb`/`php` container.
2. `docker build -f infra/worker/Dockerfile -t clinicya-worker:realtime-smoke
   .` (repo root context — the real, committed Dockerfile, no shortcuts).
3. `docker run` that image on the harness's own compose-generated network
   (resolved via `docker network ls --filter
   label=com.docker.compose.project=...`, not assumed), with `REDIS_URL`
   pointed at the harness's own `e2e-redis`, `DB_USER`/`DB_PASS` set to
   non-empty placeholders (never reached — see scope note above),
   `WORKER_HEALTH_PORT`/`WORKER_REALTIME_PORT` published to the host at
   `18200`/`18201` respectively (distinct from every other host port
   `infra/e2e/docker-compose.yml`/`docker-compose.dev.yml`/
   `infra/compose/docker-compose.strangler.yml`/`worker-smoke.mjs`'s own
   `18199` already claim — see the script's own port-choice comment), and
   `WORKER_HEARTBEAT_INTERVAL_MS` set to one hour purely to quiet
   background log noise.
4. Poll `GET /health` until it reports `status:'ok'` (real Redis PING via
   the container's own client).
5. Load `socket.io-client` and `ioredis` from `apps/worker`'s own resolved
   `node_modules` (via `createRequire` — same borrowing pattern
   `worker-smoke.mjs`'s `loadBullmqFromWorkerNodeModules()` already uses for
   `bullmq`), connect a real Socket.io client to
   `http://127.0.0.1:18201`, emit `join_account` with
   `{ lineAccountId: 4242 }`, then wait a fixed 500ms settle delay (this is
   a black-box test with no visibility into the server's own room-join
   completion — see the script's own comment on why a fixed delay, not a
   poll, is used here).
6. `PUBLISH` a synthetic message on Redis's `inbox_updates` channel (via the
   harness's own host-mapped Redis port, `16379` — fixed by
   `infra/e2e/docker-compose.yml`, not templated per-project), shaped
   exactly like `classes/WebSocketNotifier.php::notifyNewMessage()`'s
   payload with a 150-char `content`.
7. Assert the connected client receives a `new_message` event
   deep-equal to the published `message` sub-object, and a
   `conversation_update` event whose fields match the relay's documented
   derivation rules exactly (100-char truncation included), each bounded by
   a generous (15s) timeout with full diagnostic context (what was
   published vs. what was/wasn't received) on failure.
8. **(2026-08-14 update)** On the SAME already-running container/client (no
   second `docker build`/`docker run`): connect a SECOND `socket.io-client`,
   have it `join_account` the same `{ lineAccountId: 4242 }` room and emit
   `typing`, and assert the original client — excluded from its own
   broadcasts by `socket.to(room)` — receives a `typing` event with
   `user_id`/`is_typing`/`timestamp` and no `admin_id`/`admin_username`
   (`typing_broadcast_received`); emit `ping` on the original client and
   assert a `pong` with a finite `timestamp` (`ping_pong_received`); `GET
   /health` and `GET /status` on the realtime port (`18201`, not the
   `18200` Express health port step 4 already covers) and assert each
   returns `200 application/json` with the documented
   `{status, uptime, timestamp, connections, typingIndicators, redis}` shape,
   `redis` asserted `'connected'`, no `database` field
   (`health_endpoint_ok` / `status_endpoint_ok`).
9. Print one JSON line: `{"result": "PASS"|"FAIL", "steps": {...},
    "failedAt": "..."|null}` — same shape every other `infra/e2e/*.mjs`
    script here already uses. `steps` includes (at minimum) `compose_up`,
    `redis_container_infra_healthy`, `docker_build_worker_image`,
    `docker_run_worker`, `container_healthy`, `redis_reachable`,
    `client_connected`, `join_account_sent`, `synthetic_publish`,
    `new_message_received`, `conversation_update_received`, and, as of the
    2026-08-14 update, `typing_broadcast_received`, `ping_pong_received`,
    `health_endpoint_ok`, `status_endpoint_ok`.
10. **Always tears down** — closes the test's own `socket.io-client`s
    (including the 2026-08-14 update's second typing-sender client) and
    `ioredis` publisher connections, `docker rm -f`/`docker rmi -f` the
    worker container/image this run built, then `docker compose down -v` for
    `redis` — in a `finally` block, even on a thrown error. `docker ps -a` /
    `docker images` show zero leftover containers/images from this script's
    project name (`reya-e2e-worker-realtime-smoke`) / image tag
    (`clinicya-worker:realtime-smoke`) afterward, pass or fail.

### Reading a FAIL

Look at `failedAt` first — it names the step that threw. Every step's entry
in `steps` is `{ok: true, detail?: ...}` or `{ok: false, message: ...,
detail?: ...}`; a failed step's `detail` carries whatever diagnostic context
was available at the point of failure (what was published, what was/wasn't
received, raw HTTP/queue output, etc.) — read that before re-running.

### Sequencing — must run after the wsInboxRelay batch's package.json edit

This script borrows `socket.io-client` (and `ioredis`) from `apps/worker`'s
own resolved `node_modules` via `createRequire` rooted at
`apps/worker/package.json`. That only resolves once the companion
wsInboxRelay/mig-worker batch's `apps/worker/package.json` edit (adding
`socket.io-client` as a devDependency) has landed **and** `pnpm install` has
run in this worktree — otherwise `createRequire(...).require('socket.io-client')`
throws `MODULE_NOT_FOUND`. This batch's own build was sequenced after that
edit landed (see "Files this batch touches" below) — confirmed by
`apps/worker/node_modules/socket.io-client` (`4.8.3`) and
`apps/worker/node_modules/ioredis` (`5.10.1`) resolving cleanly before this
harness was written.

### Sandbox network note (this session only — not part of the deliverable)

In the sandboxed session this batch was authored in, this repo's Docker
daemon sits behind a TLS-intercepting egress proxy (see
`/root/.ccr/README.md`'s own "docker build / docker run" section) that a
bare `RUN` step inside a build container cannot reach without extra CA
trust the committed `infra/worker/Dockerfile` intentionally does not carry
(this batch's Dockerfile edit is additive-only — `EXPOSE 8100` — no new
`COPY`/`RUN` lines were added). `docker build -f infra/worker/Dockerfile ...`
therefore fails at the `corepack prepare pnpm@...` step in *that specific
sandbox* with `SELF_SIGNED_CERT_IN_CHAIN`, purely a session-local network
policy artifact, not a defect in the Dockerfile or in
`apps/worker/src/realtime/**`. This was verified by building an
**out-of-tree, never-committed** copy of the Dockerfile (with two
sandbox-only CA-trust lines, referencing `/root/.ccr`'s CA bundle via
Docker's `--build-context` flag so nothing was ever written into the
git-tracked working tree) and running this exact harness's logic against
that image end-to-end — full `PASS`, all steps green, `new_message`/
`conversation_update` assertions verified byte-for-byte (including the
100-char truncation). The committed script/Dockerfile are unmodified by
that verification; a normal CI/production Docker build (no such
interception) is expected to run `docker build -f infra/worker/Dockerfile ...`
exactly as this script invokes it.

#### 2026-08-14 update (mig-infra verification round) — DIFFERENT failure mode this time

This round's sandbox hit an **earlier** failure than the one documented
above: `docker info` reported

```
Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?
```

(exit code 1) — the Docker **client** binary and CLI plugins (`buildx`,
`compose`) are present and report their own versions fine; there is simply
no `dockerd` process listening on the socket (`ps aux | grep docker` showed
nothing, despite a stale `/var/run/docker.sock` file existing on disk). This
is NOT the same failure as the prior entry above: that one reached a live
daemon and got as far as `docker build`'s `corepack prepare` step failing on
TLS trust; this run never got past `docker compose up`/`docker build`'s very
first call to the daemon at all — `docker compose up -d redis` failed
immediately with `unable to get image 'redis:7-alpine': Cannot connect to
the Docker daemon...`.

Per this round's own acceptance criteria, no attempt was made to fabricate a
PASS or silently skip the live run. `node infra/e2e/worker-realtime-relay-smoke.mjs`
was run once anyway (for confirmatory evidence only, not as a substitute for
a real live pass) and — exactly as expected with no daemon — failed cleanly
at the first docker call:

```json
{"result":"FAIL","steps":{"compose_up":{"ok":false,"message":"docker exited 1","detail":{"cmd":"docker","args":["compose","-p","reya-e2e-worker-realtime-smoke","-f","infra/e2e/docker-compose.yml","up","-d","redis"],"stdout":"","stderr":"unable to get image 'redis:7-alpine': Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n"}}},"failedAt":"compose_up"}
```

The script's `finally` block ran `docker compose down -v` regardless (also a
no-op against no daemon) — no containers/images were ever created, so
`docker ps -a` / `docker images` are trivially empty (both also fail with
the same "cannot connect" error rather than listing anything). None of the
four new steps (`typing_broadcast_received`, `ping_pong_received`,
`health_endpoint_ok`, `status_endpoint_ok`) executed, since they run deep
inside `main()` after a real container is already up — the harness never
got there. A deliberate attempt to start `dockerd` in this sandbox to work
around the outage was blocked by this session's own permission policy
(auto-mode classifier denial) — consistent with this being a genuine
environment limitation to report, not a gap to route around. **This
verification round's live-Docker requirement is therefore unmet in this
specific sandbox** — the code changes (smoke-test steps, routes.json note,
this runbook) are believed correct by inspection against
`docs/runbooks/websocket-consolidation.md` §3's documented wire shapes and
`apps/worker/tests/realtime/socketServer.test.ts`'s existing Vitest coverage
of the same behavior, but have NOT been proven end-to-end against a real
container in this round — that remains outstanding until a sandbox with a
reachable Docker daemon runs this script for real.

---

## 2. Manual/local dev — `WORKER_REALTIME_PORT`

`infra/compose/docker-compose.worker.yml`'s `worker` service now sets
`WORKER_REALTIME_PORT: 8100` explicitly in its `environment:` block
(self-documenting — the image already defaults to the same value via
`apps/worker/src/env.ts`'s `DEFAULT_WORKER_REALTIME_PORT`). No other part of
that file changed — see `docs/runbooks/worker-scaffold-boot-drain.md`
section 2 for the full 3-layer `docker compose ... up -d worker` invocation
and `config` validation command, both unaffected by this addition.

---

## 3. Files this batch touches

- `infra/e2e/worker-realtime-relay-smoke.mjs` — new. See §1 above.
- `infra/compose/docker-compose.worker.yml` — additive edit. Adds
  `WORKER_REALTIME_PORT: 8100` to the `worker` service's `environment:`
  block only.
- `infra/worker/Dockerfile` — additive edit. Adds `EXPOSE 8100` alongside
  the existing `EXPOSE 8099`. Build stages, `COPY` list, and `CMD`
  unchanged.
- `docs/runbooks/worker-realtime-relay-smoke.md` — this file (new;
  `docs/runbooks/worker-scaffold-boot-drain.md` itself is NOT edited, per
  that file's own one-runbook-per-batch precedent).

Explicitly **not** touched: `apps/worker/src/**` / `apps/worker/tests/**`
(owned by the companion wsInboxRelay/mig-worker batch —
`realtime/socketServer.ts`, `realtime/inboxRelay.ts`, and their tests all
landed from that batch; this batch only builds the Docker image FROM that
source and drives it over the network as a black box), `websocket-server.js`,
`classes/WebSocketNotifier.php`, `infra/e2e/worker-smoke.mjs`,
`infra/e2e/docker-compose.yml`, `infra/e2e/lib/harness-common.mjs`,
`infra/e2e/seed/*`, and every other existing `infra/e2e/*.mjs` script
(`run.mjs`, `parity.mjs`, `api-parity.mjs`, `rollback-drill.mjs`).

### 2026-08-14 update (mig-infra verification round) — files THIS round touches

- `infra/e2e/worker-realtime-relay-smoke.mjs` — additive edit only: four new
  steps (`typing_broadcast_received`, `ping_pong_received`,
  `health_endpoint_ok`, `status_endpoint_ok`) inserted after
  `conversation_update_received` and before `result = 'PASS'`, reusing the
  existing `composeArgs`/`waitContainerHealthy`/step-tracker/`fail()`
  conventions and the SAME already-running worker container — no second
  `docker build`/`docker run`, no new compose service. Existing steps/logic
  unmodified.
- `docs/runbooks/worker-realtime-relay-smoke.md` — this file: "What this
  proves" / "What this batch does NOT prove", the numbered run-through, and
  the "Sandbox network note" section updated (see the dated entry above).
- `infra/nginx/routes.json` — status-only `note` string update on the single
  existing `/ws` entry (owned by this same round, documented in
  `docs/runbooks/websocket-consolidation.md`, not itself part of this
  runbook's file list historically but flagged here for completeness).
  `upstream`/`tenants` unchanged — no flip.

Still **not** touched this round: `apps/worker/**` (wsConsolidate's
exclusive surface — read in full to write the new steps, not edited),
`infra/nginx/generate-routes.mjs`, `infra/nginx/routes.schema.json`,
`infra/worker/Dockerfile`, `infra/compose/**`, `infra/e2e/docker-compose.yml`,
`infra/e2e/lib/**`, every other `infra/e2e/*.mjs` script,
`docs/runbooks/websocket-consolidation.md` (wsConsolidate-owned),
`websocket-server.js`, `websocket-dashboard-server.js`.
