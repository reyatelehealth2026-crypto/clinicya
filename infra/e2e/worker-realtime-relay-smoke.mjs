#!/usr/bin/env node
// infra/e2e/worker-realtime-relay-smoke.mjs
//
// Companion to infra/e2e/worker-smoke.mjs (same conventions, same
// harness-common.mjs helpers) — proves, on a REAL container built from the
// REAL committed infra/worker/Dockerfile, that apps/worker's new realtime
// relay (realtime/socketServer.ts + realtime/inboxRelay.ts, landed by the
// companion wsInboxRelay/mig-worker batch) is wired end-to-end:
//
//   1. the image builds (deps -> builder -> runner, pnpm workspace-aware) —
//      the SAME Dockerfile worker-smoke.mjs already proves, rebuilt here
//      under this script's own image tag;
//   2. the running container reaches a REAL Redis (GET /health's existing
//      redis_reachable PING signal — unchanged by the realtime batch);
//   3. a REAL socket.io-client (borrowed from apps/worker's own resolved
//      node_modules, never added as this script's own dependency) connects
//      to the container's dedicated realtime port and joins room
//      `account_<lineAccountId>` via the `join_account` wire event;
//   4. a synthetic message PUBLISHed directly to Redis's `inbox_updates`
//      channel (shaped exactly like classes/WebSocketNotifier.php::
//      notifyNewMessage()'s payload) is relayed straight through to that
//      connected client as a real `new_message` event (payload deep-equal
//      to the published `message` sub-object, unmodified) and a real
//      `conversation_update` event (fields derived exactly the way
//      apps/worker/src/realtime/inboxRelay.ts / the legacy
//      websocket-server.js derive them, including the 100-char
//      `last_message_preview` truncation).
//
// See docs/runbooks/worker-realtime-relay-smoke.md for what this batch does
// and does NOT prove (no auth, no /video-call namespace, no real UI client,
// no MariaDB) and how to read this script's output.
//
// Reuses infra/e2e/docker-compose.yml (UNMODIFIED — same file worker-smoke.mjs
// and every other infra/e2e/*.mjs script already use) and
// infra/e2e/lib/harness-common.mjs (compose lifecycle / process helpers —
// same shared module every other script here uses), under this script's OWN
// project name ('reya-e2e-worker-realtime-smoke', distinct from
// worker-smoke.mjs's 'reya-e2e-worker-smoke' so `docker ps -a` filtering
// never collides) — but brings up ONLY `redis` (`docker compose ... up -d
// redis`, no `mariadb`, no `php`, no `--build`): this smoke test proves a
// Redis-pub/sub-to-Socket.io round trip only, never touches MariaDB at all
// (the worker process only touches MySQL lazily, inside the heartbeat job's
// handler, which this test never needs to fire).
//
// infra/e2e/docker-compose.yml's container names/ports are FIXED (not
// templated per-project — see that file's own comments), so — same
// pre-existing constraint every other script in this directory already has
// with each other, including worker-smoke.mjs — this harness CANNOT run
// concurrently with run.mjs/parity.mjs/api-parity.mjs/rollback-drill.mjs/
// worker-smoke.mjs. Sequential use only.
//
// Single command to run this smoke test:
//   node infra/e2e/worker-realtime-relay-smoke.mjs
//
// Always tears down — `docker rm -f`/`docker rmi -f` the worker
// container/image built by this run, THEN `docker compose down -v` (redis)
// — in a finally block, even on a thrown error. `docker ps -a` / `docker
// images` show zero leftover containers/images from this script's project
// name / image tag afterward.
//
// ADDITIVE (mig-infra verification round, 2026-08-14): the SAME
// already-running worker container proven above (no second docker build) is
// further exercised for the typing/ping/health/status wire surface the
// companion wsConsolidate batch adds in realtime/typing.ts +
// realtime/socketServer.ts — new steps `typing_broadcast_received`,
// `ping_pong_received`, `health_endpoint_ok`, `status_endpoint_ok`. See
// docs/runbooks/websocket-consolidation.md §3 for the exact wire shapes
// asserted and docs/runbooks/worker-realtime-relay-smoke.md for the updated
// scope note. `infra/nginx/routes.json`'s `/ws` entry is NOT flipped by this
// round — see that file's `/ws` note.

import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  REPO_ROOT,
  HarnessError,
  createStepTracker,
  run,
  runOrThrow,
  makeComposeArgs,
  composeDown as sharedComposeDown,
  waitContainerHealthy as sharedWaitContainerHealthy,
  sleep,
} from './lib/harness-common.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml'); // infra/e2e/docker-compose.yml — UNMODIFIED, shared with every other script here.
const PROJECT = 'reya-e2e-worker-realtime-smoke';

const WORKER_DOCKERFILE = path.join(REPO_ROOT, 'infra/worker/Dockerfile');
const WORKER_IMAGE_TAG = 'clinicya-worker:realtime-smoke';
const WORKER_CONTAINER_NAME = 'e2e-worker-realtime-smoke-container';

// Distinct from every other host port already claimed by
// infra/e2e/docker-compose.yml/docker-compose.dev.yml/
// infra/compose/docker-compose.strangler.yml/worker-smoke.mjs's own
// WORKER_HEALTH_HOST_PORT (3000, 3001, 3306, 3307, 4000, 6379, 8080, 8091,
// 16379, 18092, 18199) — verified free at implementation time via a
// re-grep of all four files.
const WORKER_HEALTH_HOST_PORT = 18200;
const WORKER_REALTIME_HOST_PORT = 18201;

// Fixed by infra/e2e/docker-compose.yml itself (not templated per-project —
// see that file's own header comment) — same host-mapped port every other
// infra/e2e/*.mjs script already connects to Redis on from the host side.
const REDIS_HOST_PORT = 16379;

// Long enough that the heartbeat job's background DB-connection failures
// (this container never gets a real DB_HOST) don't spam the container log
// during this short-lived test — does not affect pass/fail either way,
// since GET /health only pings Redis, not MySQL (see health/server.ts).
const WORKER_HEARTBEAT_INTERVAL_MS = 3_600_000;

// This script's own poll budgets — deliberately generous multiples of the
// expected (sub-100ms, loopback) latency, same documented-budget style
// worker-smoke.mjs's HEARTBEAT_ROW_TIMEOUT_MS comment uses, not a tight
// bound tuned to the happy path.
const SOCKET_CONNECT_TIMEOUT_MS = 20_000;
const REALTIME_EVENT_TIMEOUT_MS = 15_000;
// Fixed settle delay between emitting `join_account` and publishing the
// synthetic Redis message — gives the server's socket.join() (fired inside
// the 'join_account' handler; see apps/worker/src/realtime/socketServer.ts)
// time to land before the relay's io.to(room).emit(...) needs that room
// membership to exist. 500ms is a large multiple of the actual expected
// round trip (a single loopback WebSocket frame + an in-process Set.add) —
// this is a black-box smoke test with no visibility into the server's own
// room state, so a fixed settle delay is the pragmatic choice rather than
// polling for a signal the wire contract doesn't expose.
const JOIN_ACCOUNT_SETTLE_MS = 500;

const LINE_ACCOUNT_ID = 4242;
const INBOX_UPDATES_CHANNEL = 'inbox_updates'; // classes/WebSocketNotifier.php's private $channelName — fixed wire contract, not a secret.

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;

const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

function composeDown(env) {
  return sharedComposeDown(composeArgs, env);
}

async function waitContainerHealthy(step, containerName, timeoutMs = 90_000) {
  return sharedWaitContainerHealthy(tracker, containerName, step, timeoutMs);
}

// ---------------------------------------------------------------------------
// Step: bring up ONLY redis (no mariadb, no php, no --build) — see module
// doc comment.
// ---------------------------------------------------------------------------
function composeUpRedisOnly(env) {
  console.error('[worker-realtime-relay-smoke] docker compose up -d redis ...');
  runOrThrow(tracker, 'compose_up', 'docker', composeArgs('up', '-d', 'redis'), { env });
  markOk('compose_up');
}

// ---------------------------------------------------------------------------
// Docker build/run for the worker image itself — same shape as
// worker-smoke.mjs's buildWorkerImage()/resolveComposeNetwork()/
// runWorkerContainer(), not shared via harness-common.mjs because these are
// each script's own image tag/container name/env, not generic compose
// lifecycle mechanics.
// ---------------------------------------------------------------------------

function buildWorkerImage() {
  console.error(`[worker-realtime-relay-smoke] docker build -f ${WORKER_DOCKERFILE} -t ${WORKER_IMAGE_TAG} ...`);
  runOrThrow(tracker, 'docker_build_worker_image', 'docker', [
    'build',
    '-f',
    WORKER_DOCKERFILE,
    '-t',
    WORKER_IMAGE_TAG,
    '.',
  ]);
  markOk('docker_build_worker_image');
}

/** Resolves the ACTUAL docker-compose-generated network name for this project (looked up, not assumed — same as worker-smoke.mjs's resolveComposeNetwork()). */
function resolveComposeNetwork() {
  const result = run('docker', [
    'network',
    'ls',
    '--filter',
    `label=com.docker.compose.project=${PROJECT}`,
    '--format',
    '{{.Name}}',
  ]);
  const name = (result.stdout || '').trim().split('\n').filter(Boolean)[0];
  if (!name) {
    fail('resolve_compose_network', 'Could not resolve the compose-generated network name', {
      stdout: result.stdout,
      stderr: result.stderr,
    });
  }
  markOk('resolve_compose_network', name);
  return name;
}

function runWorkerContainer(network) {
  runOrThrow(tracker, 'docker_run_worker', 'docker', [
    'run',
    '-d',
    '--name',
    WORKER_CONTAINER_NAME,
    '--network',
    network,
    '-p',
    `${WORKER_HEALTH_HOST_PORT}:8099`,
    '-p',
    `${WORKER_REALTIME_HOST_PORT}:8100`,
    '-e',
    'REDIS_URL=redis://e2e-redis:6379',
    // Non-empty placeholders only — @reya/config's zod envSchema requires
    // DB_USER/DB_PASS to be non-empty strings, but this container never
    // actually needs to reach MySQL (see module doc comment) — DB_HOST is
    // left at its own 'localhost' default (packages/config/src/env.ts).
    '-e',
    'DB_USER=e2e_realtime_smoke_placeholder_user',
    '-e',
    `DB_PASS=${randomBytes(12).toString('hex')}`,
    '-e',
    'WORKER_HEALTH_PORT=8099',
    '-e',
    'WORKER_REALTIME_PORT=8100',
    '-e',
    `WORKER_HEARTBEAT_INTERVAL_MS=${WORKER_HEARTBEAT_INTERVAL_MS}`,
    WORKER_IMAGE_TAG,
  ]);
  markOk('docker_run_worker');
}

// ---------------------------------------------------------------------------
// Redis reachability via the worker's OWN /health endpoint (same
// status:'ok' PING signal worker-smoke.mjs's redis_reachable step already
// exercises — unchanged by the realtime batch).
// ---------------------------------------------------------------------------
async function waitRedisReachableViaHealth(timeoutMs = 60_000) {
  const started = Date.now();
  for (;;) {
    try {
      const resp = await fetch(`http://127.0.0.1:${WORKER_HEALTH_HOST_PORT}/health`);
      if (resp.status === 200) {
        const body = await resp.json();
        if (body.status === 'ok') {
          markOk('redis_reachable', body);
          return body;
        }
      }
      // 503 with {status:'redis_unreachable'} is health/server.ts's own
      // explicit signal — surface it verbatim while we keep polling.
    } catch {
      // container/health server not listening yet — keep polling.
    }
    if (Date.now() - started > timeoutMs) {
      fail('redis_reachable', `/health never reported status:'ok' (real Redis PING) within ${timeoutMs}ms`);
    }
    await sleep(500);
  }
}

// ---------------------------------------------------------------------------
// socket.io-client / ioredis — borrowed from apps/worker's OWN resolved
// node_modules via createRequire (pnpm workspaces do not hoist to the
// repo-root node_modules), the exact same pattern
// worker-smoke.mjs's loadBullmqFromWorkerNodeModules() already uses for
// bullmq. This script does NOT add socket.io-client or ioredis as its own
// dependency.
// ---------------------------------------------------------------------------
function requireFromWorkerNodeModules(pkg) {
  const req = createRequire(path.join(REPO_ROOT, 'apps/worker/package.json'));
  return req(pkg);
}

function connectSocketClient(ioClientFactory, port, timeoutMs) {
  return new Promise((resolve, reject) => {
    const socket = ioClientFactory(`http://127.0.0.1:${port}`, {
      reconnection: true,
      reconnectionAttempts: Infinity,
      reconnectionDelay: 250,
      timeout: 5_000, // per-attempt connection timeout — the outer setTimeout below is the real budget.
    });
    const timer = setTimeout(() => {
      socket.close();
      reject(new Error(`socket.io-client did not connect to 127.0.0.1:${port} within ${timeoutMs}ms`));
    }, timeoutMs);
    socket.once('connect', () => {
      clearTimeout(timer);
      resolve(socket);
    });
  });
}

/** Resolves the next `eventName` emission from `socket`, or rejects with a diagnostic Error after `timeoutMs`. */
function waitForSocketEvent(socket, eventName, timeoutMs) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      reject(new Error(`Timed out after ${timeoutMs}ms waiting for a '${eventName}' event`));
    }, timeoutMs);
    socket.once(eventName, (payload) => {
      clearTimeout(timer);
      resolve(payload);
    });
  });
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  // infra/e2e/docker-compose.yml interpolates EVERY service's environment
  // block up front regardless of which service names are passed to `up`
  // (verified empirically — see worker-smoke.mjs's own composeEnv comment
  // for the same finding) — so the mariadb/php-only vars below are still
  // required even though this script only ever brings up `redis`. Every
  // value is a disposable, in-memory-only placeholder; none is ever written
  // to a tracked file (this repo's secrets-discipline guardrail) and
  // MariaDB/PHP are never actually started, so their real content is
  // irrelevant.
  const composeEnv = {
    ...process.env,
    E2E_MARIADB_ROOT_PASSWORD: randomBytes(24).toString('base64url'),
    E2E_APP_DB_NAME: 'e2e_realtime_smoke_unused_db',
    E2E_APP_DB_USER: 'e2e_realtime_smoke_unused_user',
    E2E_APP_DB_PASSWORD: randomBytes(18).toString('base64url'),
    E2E_SESSION_BRIDGE_HMAC_SECRET: randomBytes(32).toString('hex'),
  };

  let result = 'FAIL';
  let workerContainerStarted = false;
  let imageBuilt = false;
  let socketClient = null;
  let redisPublisher = null;
  let typingSenderClient = null;

  try {
    if (!existsSync(WORKER_DOCKERFILE)) {
      fail('docker_build_worker_image', `${WORKER_DOCKERFILE} does not exist`);
    }

    composeUpRedisOnly(composeEnv);
    // NOT the acceptance-required `container_healthy` step (that one names
    // the WORKER container below) — this is just the redis container's own
    // infra healthcheck, same distinction worker-smoke.mjs's own comment
    // draws for its identically-named step.
    await waitContainerHealthy('redis_container_infra_healthy', 'e2e-redis');

    buildWorkerImage();
    imageBuilt = true;

    const network = resolveComposeNetwork();
    runWorkerContainer(network);
    workerContainerStarted = true;

    await waitContainerHealthy('container_healthy', WORKER_CONTAINER_NAME);
    await waitRedisReachableViaHealth();

    const ioClientModule = requireFromWorkerNodeModules('socket.io-client');
    const RedisCtor = requireFromWorkerNodeModules('ioredis');

    // --- client_connected / join_account_sent ---
    socketClient = await connectSocketClient(ioClientModule.io, WORKER_REALTIME_HOST_PORT, SOCKET_CONNECT_TIMEOUT_MS)
      .then((socket) => {
        markOk('client_connected', { port: WORKER_REALTIME_HOST_PORT });
        return socket;
      })
      .catch((err) => {
        fail('client_connected', err.message);
      });

    // Set up listeners BEFORE the publish below so no relayed event can be
    // missed to a race between "subscribe" and "publish".
    const newMessageWait = waitForSocketEvent(socketClient, 'new_message', REALTIME_EVENT_TIMEOUT_MS);
    const conversationUpdateWait = waitForSocketEvent(socketClient, 'conversation_update', REALTIME_EVENT_TIMEOUT_MS);

    socketClient.emit('join_account', { lineAccountId: LINE_ACCOUNT_ID });
    markOk('join_account_sent', { lineAccountId: LINE_ACCOUNT_ID });
    await sleep(JOIN_ACCOUNT_SETTLE_MS);

    // --- synthetic_publish ---
    // Shaped exactly like classes/WebSocketNotifier.php::notifyNewMessage()'s
    // payload. `content` is 150 chars (> 100) specifically so
    // last_message_preview's truncation is actually exercised.
    const publishedMessage = {
      id: 1,
      user_id: 99,
      content: 'a'.repeat(150),
      direction: 'incoming',
      type: 'text',
      created_at: '2026-07-14 10:00:00',
      is_read: 0,
    };
    const publishedPayload = {
      type: 'new_message',
      line_account_id: LINE_ACCOUNT_ID,
      message: publishedMessage,
      unread_count: 3,
      timestamp: Math.floor(Date.now() / 1000), // matches PHP's time() — not read by the relay (see inboxRelay.ts), included only for payload fidelity.
    };

    redisPublisher = new RedisCtor(`redis://127.0.0.1:${REDIS_HOST_PORT}`);
    redisPublisher.on('error', () => {}); // same required no-op listener pattern apps/worker/src/redis.ts documents — avoid an uncaught 'error' crash.
    await redisPublisher.publish(INBOX_UPDATES_CHANNEL, JSON.stringify(publishedPayload));
    markOk('synthetic_publish', publishedPayload);

    // --- new_message_received ---
    let receivedNewMessage;
    try {
      receivedNewMessage = await newMessageWait;
    } catch (err) {
      fail('new_message_received', err.message, { published: publishedMessage, received: null });
    }
    try {
      assert.deepStrictEqual(receivedNewMessage, publishedMessage);
    } catch (err) {
      fail(
        'new_message_received',
        "received 'new_message' payload did not deep-equal the published message sub-object",
        { published: publishedMessage, received: receivedNewMessage, diff: err.message }
      );
    }
    markOk('new_message_received', receivedNewMessage);

    // --- conversation_update_received ---
    let receivedConversationUpdate;
    try {
      receivedConversationUpdate = await conversationUpdateWait;
    } catch (err) {
      fail('conversation_update_received', err.message, { published: publishedPayload, received: null });
    }
    const expectedPreview = publishedMessage.content.substring(0, 100);
    const mismatches = [];
    if (receivedConversationUpdate.user_id !== 99) {
      mismatches.push(`user_id: expected 99, got ${JSON.stringify(receivedConversationUpdate.user_id)}`);
    }
    if (receivedConversationUpdate.last_message_at !== publishedMessage.created_at) {
      mismatches.push(
        `last_message_at: expected ${JSON.stringify(publishedMessage.created_at)}, got ${JSON.stringify(
          receivedConversationUpdate.last_message_at
        )}`
      );
    }
    if (receivedConversationUpdate.last_message_preview !== expectedPreview) {
      mismatches.push(
        `last_message_preview: expected first-100-chars (len ${expectedPreview.length}), got ${JSON.stringify(
          receivedConversationUpdate.last_message_preview
        )}`
      );
    }
    if (receivedConversationUpdate.unread_count !== 3) {
      mismatches.push(`unread_count: expected 3, got ${JSON.stringify(receivedConversationUpdate.unread_count)}`);
    }
    if (!Number.isFinite(receivedConversationUpdate.timestamp)) {
      mismatches.push(`timestamp: expected a finite number, got ${JSON.stringify(receivedConversationUpdate.timestamp)}`);
    }
    if (mismatches.length > 0) {
      fail('conversation_update_received', "received 'conversation_update' payload did not match derivation rules", {
        published: publishedPayload,
        received: receivedConversationUpdate,
        mismatches,
      });
    }
    markOk('conversation_update_received', receivedConversationUpdate);

    // -------------------------------------------------------------------
    // ADDITIVE (mig-infra verification round, 2026-08-14): exercises the
    // typing/ping/health/status wire surface wsConsolidate's
    // apps/worker/src/realtime/typing.ts + socketServer.ts changes add on
    // top of the frozen join_account/inbox_updates contract proven above —
    // see docs/runbooks/websocket-consolidation.md §3 for the exact wire
    // shapes these steps assert against, and
    // docs/runbooks/worker-realtime-relay-smoke.md for the read-me-first
    // scope note. SAME already-running WORKER_CONTAINER_NAME container as
    // every step above — no second docker build/run.
    // -------------------------------------------------------------------

    // --- typing_broadcast_received ---
    // realtime/socketServer.ts's 'typing' handler uses `socket.to(room)`
    // (sender excluded), so a SECOND client must join account_<id> and emit
    // 'typing' — the original `socketClient` (already joined via
    // join_account above) is the one expected to observe the broadcast.
    typingSenderClient = await connectSocketClient(ioClientModule.io, WORKER_REALTIME_HOST_PORT, SOCKET_CONNECT_TIMEOUT_MS).catch(
      (err) => {
        fail('typing_broadcast_received', `second socket.io-client (typing sender) failed to connect: ${err.message}`);
      }
    );
    const typingWait = waitForSocketEvent(socketClient, 'typing', REALTIME_EVENT_TIMEOUT_MS);
    typingSenderClient.emit('join_account', { lineAccountId: LINE_ACCOUNT_ID });
    await sleep(JOIN_ACCOUNT_SETTLE_MS);

    const sentTypingPayload = { lineAccountId: LINE_ACCOUNT_ID, user_id: 'U-e2e-typing-smoke', is_typing: true };
    typingSenderClient.emit('typing', sentTypingPayload);

    let receivedTyping;
    try {
      receivedTyping = await typingWait;
    } catch (err) {
      fail('typing_broadcast_received', err.message, { sent: sentTypingPayload, received: null });
    }
    const typingMismatches = [];
    if (receivedTyping.user_id !== sentTypingPayload.user_id) {
      typingMismatches.push(`user_id: expected ${JSON.stringify(sentTypingPayload.user_id)}, got ${JSON.stringify(receivedTyping.user_id)}`);
    }
    if (receivedTyping.is_typing !== true) {
      typingMismatches.push(`is_typing: expected true, got ${JSON.stringify(receivedTyping.is_typing)}`);
    }
    if (!Number.isFinite(receivedTyping.timestamp)) {
      typingMismatches.push(`timestamp: expected a finite number, got ${JSON.stringify(receivedTyping.timestamp)}`);
    }
    // Deliberate parity gap vs legacy — no authenticated identity exists this
    // round (docs/runbooks/websocket-consolidation.md §1 row 5) — the relay
    // must NOT invent admin_id/admin_username fields.
    if (Object.prototype.hasOwnProperty.call(receivedTyping, 'admin_id') || Object.prototype.hasOwnProperty.call(receivedTyping, 'admin_username')) {
      typingMismatches.push('unexpected admin_id/admin_username field on the typing broadcast');
    }
    if (typingMismatches.length > 0) {
      fail('typing_broadcast_received', "received 'typing' payload did not match the documented wire shape", {
        sent: sentTypingPayload,
        received: receivedTyping,
        mismatches: typingMismatches,
      });
    }
    markOk('typing_broadcast_received', receivedTyping);

    try {
      typingSenderClient.close();
    } catch {
      // ignore — best-effort, same posture as this script's other socket teardowns
    }
    typingSenderClient = null;

    // --- ping_pong_received ---
    const pongWait = waitForSocketEvent(socketClient, 'pong', REALTIME_EVENT_TIMEOUT_MS);
    socketClient.emit('ping');
    let receivedPong;
    try {
      receivedPong = await pongWait;
    } catch (err) {
      fail('ping_pong_received', err.message);
    }
    if (!Number.isFinite(receivedPong.timestamp)) {
      fail('ping_pong_received', "received 'pong' payload did not carry a finite numeric timestamp", { received: receivedPong });
    }
    markOk('ping_pong_received', receivedPong);

    // --- health_endpoint_ok / status_endpoint_ok ---
    // Both live on the SAME dedicated realtime httpServer Socket.io is
    // attached to (WORKER_REALTIME_HOST_PORT) — a DIFFERENT endpoint from
    // the separate Express health server on WORKER_HEALTH_HOST_PORT that
    // the pre-existing `redis_reachable` step above already exercises. See
    // docs/runbooks/websocket-consolidation.md §3.
    async function checkStatusShapeEndpoint(step, urlPath) {
      let resp;
      try {
        resp = await fetch(`http://127.0.0.1:${WORKER_REALTIME_HOST_PORT}${urlPath}`);
      } catch (err) {
        fail(step, `GET ${urlPath} on the realtime port failed: ${err.message}`);
      }
      if (resp.status !== 200) {
        fail(step, `GET ${urlPath} returned HTTP ${resp.status}, expected 200`);
      }
      const contentType = resp.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        fail(step, `GET ${urlPath} returned Content-Type ${JSON.stringify(contentType)}, expected application/json`);
      }
      const body = await resp.json();
      const shapeMismatches = [];
      if (body.status !== 'ok') shapeMismatches.push(`status: expected 'ok', got ${JSON.stringify(body.status)}`);
      if (!Number.isFinite(body.uptime)) shapeMismatches.push(`uptime: expected a finite number, got ${JSON.stringify(body.uptime)}`);
      if (!Number.isFinite(body.timestamp)) shapeMismatches.push(`timestamp: expected a finite number, got ${JSON.stringify(body.timestamp)}`);
      if (!body.connections || !Number.isFinite(body.connections.total) || !Number.isFinite(body.connections.rooms)) {
        shapeMismatches.push(`connections: expected {total:number, rooms:number}, got ${JSON.stringify(body.connections)}`);
      }
      if (!Number.isFinite(body.typingIndicators)) {
        shapeMismatches.push(`typingIndicators: expected a finite number, got ${JSON.stringify(body.typingIndicators)}`);
      }
      // By this point in the run the worker's own redis subscriber has
      // already relayed the synthetic_publish above end-to-end
      // (new_message_received/conversation_update_received both already
      // passed), so its ioredis connection is provably 'ready' — index.ts
      // wires that straight into getRedisStatus (see
      // docs/runbooks/websocket-consolidation.md §3).
      if (body.redis !== 'connected') {
        shapeMismatches.push(`redis: expected 'connected' (the real subscriber already relayed a message this run), got ${JSON.stringify(body.redis)}`);
      }
      if (Object.prototype.hasOwnProperty.call(body, 'database')) {
        shapeMismatches.push("unexpected 'database' field — no DB wiring in this module this round");
      }
      if (shapeMismatches.length > 0) {
        fail(step, `GET ${urlPath} body did not match the documented shape`, { received: body, mismatches: shapeMismatches });
      }
      markOk(step, body);
    }

    await checkStatusShapeEndpoint('health_endpoint_ok', '/health');
    await checkStatusShapeEndpoint('status_endpoint_ok', '/status');

    result = 'PASS';
  } catch (err) {
    result = 'FAIL';
    if (!(err instanceof HarnessError)) {
      tracker.setFailedAt('unexpected_error');
      steps.unexpected_error = { ok: false, message: String(err && err.stack ? err.stack : err) };
    }
  } finally {
    // Best-effort, in dependency order — never throw past this point.
    if (socketClient) {
      try {
        socketClient.close();
      } catch {
        // ignore
      }
    }
    if (typingSenderClient) {
      // Normally already closed+nulled right after typing_broadcast_received
      // above — this only fires if that step (or something after it) threw
      // before reaching that close() call.
      try {
        typingSenderClient.close();
      } catch {
        // ignore
      }
    }
    if (redisPublisher) {
      try {
        redisPublisher.disconnect();
      } catch {
        // ignore
      }
    }
    if (workerContainerStarted) {
      run('docker', ['rm', '-f', WORKER_CONTAINER_NAME]);
    }
    if (imageBuilt) {
      run('docker', ['rmi', '-f', WORKER_IMAGE_TAG]);
    }
    composeDown(composeEnv);
  }

  const output = { result, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
