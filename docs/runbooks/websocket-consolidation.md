# WebSocket consolidation — event/room/auth/side-effect inventory

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 8
(Odoo stack), Phase 10 (cron → BullMQ worker), Phase 11 (platform admin +
provisioning), Phase 13 (decommission PHP), §6 risk #6 (dual cron
execution — the same coexistence-hazard shape this doc's Phase 13 checklist
guards against for websockets), §7 (verification / per-tenant canary ramp).
Owner: mig-worker (`apps/worker/src/realtime/**` — this round's wsConsolidate
batch). Cross-reference: `docs/runbooks/worker-realtime-relay-smoke.md`
(infra-owned; the prior batch's Docker smoke-test runbook for the
`join_account` / `inbox_updates` relay this batch builds on top of — **not
edited by this batch**).

## 0. What this round is / is not

This batch ports the **DB-free, low-risk remainder** of
`websocket-server.js`'s event surface into `apps/worker/src/realtime/`
(`socketServer.ts` + the new `typing.ts`), so that module becomes a full
behavioral superset of `websocket-server.js`'s inbox-relevant surface —
**except** for auth, the `/video-call` namespace, and `sync`/
`getUpdatesSince` (all explicitly deferred, §2 below) — ready for traffic to
be flipped onto it in a later phase. `websocket-dashboard-server.js` is
deferred **as a whole unit** to Phase 8 (§3 below). Neither legacy file is
edited, deleted, or stopped by this batch — both keep running unchanged.

Status legend used in every row below:

| Tag | Meaning |
|---|---|
| **(a) already covered** | Ported in an earlier batch; unaffected by this round. Frozen files: `apps/worker/src/realtime/inboxRelay.ts`, `apps/worker/src/redis.ts`, `apps/worker/src/shutdown.ts`. |
| **(b) ported this round** | Added in `apps/worker/src/realtime/socketServer.ts` / `typing.ts` by this batch. |
| **(c) deliberately deferred** | Not ported. Reason given per row — never a silent drop. |
| **N/A** | No apps/worker analog exists or is planned this round (e.g. a DB pool this module doesn't have). |

---

## 1. `websocket-server.js` — default namespace (`/`, `path: '/socket.io/'`)

| # | Event / endpoint | Room / scope | Auth check | Side effect | Status | Notes |
|---|---|---|---|---|---|---|
| 1 | Connection handshake | — | `authenticateToken(socket.handshake.auth.token)` — `SELECT id, username, line_account_id, role FROM admin_users WHERE session_token = ? AND session_expires > NOW()`; disconnects socket on failure | Stores `socket.userId`/`username`/`lineAccountId`; auto-joins `account_<line_account_id>`; adds to `connections` Map | **(c) deferred** | Needs `@reya/auth`'s tenant-aware session verification wired in — apps/worker has no `@reya/auth` dependency yet, and a bare `admin_users.session_token` lookup can't identify which tenant DB to query under db-per-tenant (ADR-001). The room-JOIN mechanic itself (not the auth) is already covered — see `join_account` row below, an explicit-join adaptation for a model with no auth. |
| 2 | `connected` (server→client emit on successful auth) | socket only | (same as #1) | Confirms `{userId, username, lineAccountId, timestamp}` | **(c) deferred** | Bundled with #1 — every field requires the authenticated `user` object. |
| 3 | `join_account` — **apps/worker's own explicit event, not a literal legacy event name** | joins `account_<lineAccountId>` | none this round | `socket.join(room)` | **(a) already covered** | `socketServer.ts`, frozen shape this round (`{lineAccountId: number}` → `account_<id>`). Functionally replaces legacy's implicit auth-time auto-join with an explicit client-initiated join, since there is no auth to derive the account from. **Stable wire contract — do not rename.** |
| 4 | `connections` Map (`Map<lineAccountId, Set<socketId>>`) bookkeeping | — | — | Powers legacy `/health`'s `connections.byAccount` breakdown | **(b) ported, adapted** | Not re-implemented as a duplicate Map. `socketServer.ts`'s `/health`+`/status` reuse Socket.io/engine.io's own internal counters (`io.engine.clientsCount`, `io.sockets.adapter.rooms.size`) instead — sufficient for the `connections: {total, rooms}` shape this round's brief asks for. The per-account `byAccount` breakdown is dropped (not required by the documented shape); can be reintroduced from `io.sockets.adapter.rooms` directly in a later batch without new bookkeeping. |
| 5 | `typing` | broadcast to `account_<lineAccountId>` (`socket.to(room)`, sender excluded) | none beyond connection-time auth | Mutates `typingIndicators` Map; emits `{user_id, is_typing, admin_id, admin_username, timestamp}` to the room | **(b) ported, adapted** | `typing.ts` + `socketServer.ts`. Payload now carries an **explicit** `lineAccountId` (legacy used implicit `socket.lineAccountId`, since a legacy socket only ever belongs to one account room post-auth; an apps/worker socket may join several). Broadcast **drops `admin_id`/`admin_username`** — no authenticated identity exists to populate them with this round; documented as a deliberate parity gap, not an oversight, in both `socketServer.ts` and `typing.ts`'s doc comments. |
| 6 | `sync` + `getUpdatesSince(lineAccountId, since)` | socket only | connection-time auth | `SELECT ... FROM messages m JOIN users u ...` (tenant DB), emits `sync_response` | **(c) deferred** | Two independent reasons: (1) needs the same tenant-DB resolution auth would provide; (2) **functionally redundant** with Phase 4's already-shipped `InboxService` cursor-pagination REST endpoint (`api/inbox-v2.php?action=getConversations`) — verified by a repo-wide grep this round (`sync_response`, `getUpdatesSince`, `emit('sync'`) across `apps/**` and `line-mini-app/**`: zero hits. No current client bypasses the REST cursor API in favor of this WS event. |
| 7 | `ping` | socket only | none | Emits `pong: {timestamp: Date.now()}` | **(b) ported, byte-for-byte** | `socketServer.ts`. |
| 8 | `disconnect` — typing cleanup half | notifies `account_<lineAccountId>` per conversation the socket was typing in | — | Removes the socket's `typingIndicators` entries; `io.to(room).emit('typing', {..., is_typing:false})` | **(b) ported, adapted** | `typing.ts`'s `clearSocket()` + `socketServer.ts`'s `disconnect` handler. Keyed by `socket.id` instead of legacy's authenticated `socket.userId` — see row 5's note; same reasoning applies. |
| 9 | `disconnect` — `connections` Map cleanup half | — | — | Removes socket from `connections` Map, logs count | **(b) ported, adapted** | See row 4 — superseded by reading `io.engine.clientsCount` live, no explicit cleanup needed since there's no duplicate Map to clean up. |
| 10 | socket-level `error` event | socket only | — | `console.error` only, no wire side effect | **not ported this round** | Trivial logging-only handler with zero data/behavior impact; out of this round's explicit scope (not listed in the brief's (b) list). Safe, low-risk addition for a later pass — flagging here so it's not silently forgotten, per this doc's "nothing left unmentioned" mandate. |
| 11 | Redis `inbox_updates` subscribe → `new_message` + `conversation_update` relay to `account_<line_account_id>` | — | — | Relays `classes/WebSocketNotifier.php`'s publishes | **(a) already covered** | `inboxRelay.ts`, frozen/unmodified this round (regression-guarded by the untouched `tests/realtime/inboxRelay.test.ts`). |
| 12 | Redis subscriber `error` event | — | — | `console.error` | **(a) already covered, adapted** | `redis.ts`'s no-op `'error'` listener prevents the same uncaught-exception crash; logs silently instead of via `console.error` — an intentional simplification documented in that (frozen, not-this-round) file, not a gap introduced here. |
| 13 | `GET /health` (Express) | — | none | `{status, timestamp, uptime, connections:{total, byAccount}, redis, database}` | **(b) ported, adapted** | `socketServer.ts`'s `attachHttpRoutes()`, registered on the SAME dedicated `httpServer` Socket.io is attached to (see §4's ordering note). Shape simplified to `{status, uptime, timestamp, connections:{total, rooms}, typingIndicators, redis}` — see §4 for the full documented shape and why `/health` and `/status` deliberately return the identical shape this round. `database` field dropped — no DB wiring in this module this round. |
| 14 | `GET /status` (Express) | — | none | `{status, version, timestamp, clients, rooms, typingIndicators}` | **(b) ported, adapted** | Same handler as row 13 this round (merged shape); `version` field dropped, `typingIndicators` count now sourced from `typing.ts`'s `TypingIndicatorTracker.size`. |
| 15 | `/video-call` namespace — `presence:join`, `signal:offer`/`answer`/`ice`/`hangup`/`message`, `disconnect`→`presence:leave` | joins `room_id` string room (raw, not `account_<id>`-shaped) | `authenticateToken()` (same as row 1) | Relays WebRTC signaling; `persistAudit()` INSERTs into `video_call_signals` (skips `ice` — high volume) | **(c) deferred — RE-AFFIRMED** | Re-affirms the prior batch's explicit lower-priority deferral. Still no consumer found in `apps/**` or `line-mini-app/**` this round (verified by grep this round, not just carried forward from memory): `line-mini-app/src/components/miniapp/VideoCallClient.tsx` + `src/lib/video-call-api.ts` exist and implement the video-call UI, but they talk to the **REST polling** `api/video-call.php` (`POST`/`GET` against `/api/video-call.php`), i.e. exactly the 3-second-polling fallback path this legacy namespace's own top-of-file comment says it "replaces... for clients that opt in via the `use_ws_video_signaling` feature flag" — no such opt-in exists in the current mini-app code. Also DB-coupled (`persistAudit`) and auth-gated, both independently out of this round's DB-free/no-auth scope. |
| 16 | Top-level `gracefulShutdown()` — HTTP/Socket.io close order | — | — | `server.close()`; `io.emit('server_shutdown', ...)`; 1s delay; `io.fetchSockets()` + `disconnect(true)` each; `io.close()`; `pool.end()`; `redisClient.quit()` + `redisSubscriber.quit()` | **(a) + (b), split** | The SIGTERM/SIGINT drain **order** across the worker/health-server/realtime-server/redis-subscriber is already covered by `shutdown.ts` (frozen, not touched this round — `runShutdown()`'s existing `worker.close()` → `healthServer.close()` → `closeRealtimeServer()` → `redisSubscriber.quit()` sequence). **This round adds** the `server_shutdown`-broadcast-before-close portion specifically, inside `RealtimeServer.close()` (`io.emit('server_shutdown', {message, timestamp})` then a grace delay then `io.close()`) — see §4 for the exact payload shape and the grace-period value chosen. The `pool.end()` step has **no analog** (N/A — this module has no DB pool this round). |
| 17 | Startup console banner / env logging | — | — | Cosmetic `console.log` only | **not ported** | Zero behavior implication; out of scope. |

---

## 2. `websocket-dashboard-server.js` — deferred as a UNIT to Phase 8

Every row below is **(c) deliberately deferred to Phase 8** (Odoo stack —
already mig-worker's own listed phase), as a single unit, not
piecemeal. Reason: this file's only current consumer is
`frontend/src/hooks/useDashboardRealtime.ts` (+ `useWebSocket.ts`,
+ `frontend/src/components/dashboard/DashboardOverview.tsx`) — verified by
grep this round — and `frontend/` is, per the migration plan's own stated
facts (`docs/plans/2026-07-12-nextjs-full-migration-plan.md` line 9), mostly
mocked and disabled in production, slated for retirement after its UI kit is
harvested. Every one of this file's real data-producing side effects is
100% Odoo-cache-table SQL. Shipping a relay for a doomed, inert consumer
ahead of its own data source is not real risk reduction now — the whole
surface moves together once Phase 8 lands the Odoo stack for real.

| # | Event / endpoint | Room / namespace | Auth check | Side effect |
|---|---|---|---|---|
| 1 | Connection handshake (separate `httpServer`/port, `path: '/dashboard-socket.io/'`) | — | `authenticateToken()` — JWT (`jsonwebtoken`, token prefix `eyJ`) **or** session-token fallback against `admin_users` |Disconnects on failure |
| 2 | `connected` emit | socket only | (above) | `{userId, username, lineAccountId, role, timestamp, initialData: getDashboardMetrics()}` |
| 3 | `getDashboardMetrics(lineAccountId)` | — | — | SQL against `odoo_orders`, `odoo_slip_uploads`, `odoo_webhooks_log` (order/payment/webhook/customer aggregates) |
| 4 | `subscribe_dashboard` | joins `${lineAccountId}_${metric}` sub-rooms | connection-time auth | Emits `subscription_confirmed` |
| 5 | `request_dashboard_data` | socket only | connection-time auth | Re-runs `getDashboardMetrics()`, emits `dashboard_data` |
| 6 | `ping` → `pong` | socket only | none | Same shape as row 7 of §1 — trivially reusable from `apps/worker`'s already-ported `ping`/`pong` handler once this file's stack is actually built in Phase 8 |
| 7 | `disconnect` | — | — | `dashboardConnections` Map cleanup |
| 8 | socket-level `error` | socket only | — | `console.error` only |
| 9 | Redis `dashboard_updates` subscribe → relay | `dashboard_<line_account_id>` | — | Emits `data.type` (arbitrary event name) with `{...payload, timestamp}` |
| 10 | `@socket.io/redis-adapter` wiring (`io.adapter(createAdapter(redisClient, redisSubscriber))` on `redisClient`'s `'connect'`) | — | — | Horizontal-scaling multi-instance room fan-out |
| 11 | `broadcastDashboardUpdate(lineAccountId, type, data)` | — | — | `PUBLISH dashboard_updates` |
| 12 | `startPeriodicDashboardUpdates()` — 30s `setInterval` | all active dashboard accounts | — | Re-runs `getDashboardMetrics()` per account, calls #11 — **not DB-free**, excluded from this round's "DB-free, low-risk remainder" scope by construction |
| 13 | `GET /health` | — | none | `{status, timestamp, uptime, connections:{total, dashboard, byAccount}, redis, database}` |
| 14 | `GET /status` | — | none | `{status, version, timestamp, clients, dashboardClients, rooms}` |
| 15 | `module.exports {broadcastDashboardUpdate, getDashboardMetrics}` | — | — | Importable by other legacy Node entry points (none found calling it outside this file this round) |
| 16 | Top-level `gracefulShutdown()` | — | — | Same shape as §1 row 16 plus `clearInterval(dashboardUpdateInterval)` |

---

## 3. Wire shapes this round adds — for infra's smoke-test extension

`infra/e2e/worker-realtime-relay-smoke.mjs` is infra-owned (see that file's
runbook, `docs/runbooks/worker-realtime-relay-smoke.md` — not edited by this
batch). The exact new shapes it needs to assert against, all served by
`apps/worker/src/realtime/socketServer.ts` on `WORKER_REALTIME_PORT`:

**`typing` (client → server, then server → other room members)**
```jsonc
// client emits:
{ "lineAccountId": 42, "user_id": "U1234...", "is_typing": true }
// every OTHER socket in room account_42 receives event "typing":
{ "user_id": "U1234...", "is_typing": true, "timestamp": 1755000000000 }
// (no admin_id / admin_username — see §1 row 5)
```

**`ping` → `pong`**
```jsonc
// client emits "ping" (no payload)
// server replies with event "pong":
{ "timestamp": 1755000000000 }
```

**`server_shutdown`** (emitted to every connected client when `RealtimeServer.close()` runs, before the socket is actually torn down)
```jsonc
{ "message": "Server is shutting down", "timestamp": 1755000000000 }
```

**`GET /health` and `GET /status`** — same dedicated `httpServer` Socket.io is attached to, both return this identical shape, `200 application/json`:
```jsonc
{
  "status": "ok",
  "uptime": 12.34,
  "timestamp": 1755000000000,
  "connections": { "total": 2, "rooms": 3 },
  "typingIndicators": 0,
  "redis": "connected" // | "disconnected" | "unknown" (default when no getRedisStatus dep is wired)
}
```
No `database` field this round (see §1 rows 13-14). `index.ts` wires
`redis` to the real `getRedisSubscriberClient()` connection's live
`.status` (`'ready'` → `'connected'`, anything else → `'disconnected'`); a
`createRealtimeServer()` call with no `deps` (every existing test in
`tests/realtime/inboxRelay.test.ts`, and most of this batch's own
`tests/realtime/socketServer.test.ts`) reports `'unknown'`.

Ordering note relevant to any infra script that also wants to hit
`/health`/`/status`: the route is registered on `httpServer` **before**
`new SocketIOServer(httpServer, ...)` constructs engine.io's own request
listener — this is required (not incidental) for the two to coexist on one
port; see `socketServer.ts`'s `attachHttpRoutes()` doc comment for the
engine.io source citation this was verified against.

---

## 4. Phase 13 decommission gate — must ALL be true before `websocket-server.js` / `websocket-dashboard-server.js` are switched off

1. **Auth parity landed.** Either `@reya/auth`'s tenant-aware session
   verification is wired into `apps/worker/src/realtime/socketServer.ts`
   (replacing this round's no-auth `join_account`/`typing` model with one
   that identifies which tenant DB + admin user a socket belongs to), **or**
   an explicit accepted-risk sign-off is recorded here (who approved, date,
   scope of the accepted gap) if the decision is to ship without full parity.
2. **`/video-call` namespace** is either ported into `apps/worker` (signaling
   + `video_call_signals` audit insert, tenant-scoped) **or** a product
   decision is recorded to drop WS signaling entirely in favor of the
   REST-polling path `line-mini-app` already uses (§1 row 15) — not left
   ambiguous.
3. **Dashboard realtime** (§2, all 16 rows) is folded into a **completed**
   Phase 8 batch — not merely "Phase 8 has started." If `frontend/`'s
   retirement (harvest-then-delete, per the plan) lands first and
   `useDashboardRealtime.ts` has no successor consumer by the time Phase 8
   ships, record that decision here explicitly rather than silently building
   a relay nothing calls.
4. **`sync`/`getUpdatesSince`** — reconfirm at decommission time (not just at
   this round's writing) that no client has since started relying on it
   instead of the REST cursor-pagination endpoint. Re-run the grep this
   doc's §1 row 6 describes.
5. **`infra/nginx/routes.json`'s `/ws` upstream flipped** and soaked through
   a full per-tenant canary ramp per plan §7 bullet 3 (demo tenant → 1 real
   tenant → 10% → 50% → 100%, ≥3 days per step).
6. **Zero real traffic observed on the legacy Express ports** (`WEBSOCKET_PORT`
   default 3000 for `websocket-server.js`, `DASHBOARD_WEBSOCKET_PORT` default
   3001 for `websocket-dashboard-server.js`) for an agreed soak window after
   the routes.json flip — access-log-verified, not assumed from the flip
   alone.

Only once all six are true does Phase 13's PHP-decommission sweep get to
also drop these two Express/Socket.io processes.
