# Go-Live Readiness Audit — What Actually Gates Flipping Real Traffic

**Date:** 2026-08-14
**Scope:** Evidence-based survey of what must be true before ANY production traffic moves from PHP to Next.js, and in what order.
**Method:** Direct inspection of the repository at `origin/main` (commit `d60d757`, merged today) — file reads, `git log`, and one live check (`docker info`) run inside this container. No production/VPS/DNS access was available or used. Every claim below cites a file, line count, or command output; anything that couldn't be verified this way is labeled **UNVERIFIED** with the evidence that would settle it.

---

## For the non-engineer owner: the answer in one page

**Production has not moved at all.** Every PHP page and every API endpoint your customers and staff use today is still served exactly as it was before this migration started — by cPanel shared hosting at `/home/zrismpsz/public_html`, deployed by SSH + `git pull` (`force_deploy_testry.sh`, `deploy_testry_branch.sh`, `deploy-to-emp.sh`). Nothing in 33 days of migration work has touched that. **This is the single fact that governs everything else in this document: no route can be flipped to Next.js until Phase 0 (moving production onto a Docker server with an nginx front door) actually happens on the real server** — and Phase 0 today exists only as tested code in the repository, not as anything running in production. See §1.

A large amount of real engineering has happened — roughly 30+ admin pages and API surfaces have been rewritten in Next.js with matching tests — but **every one of those rewrites is still switched off**. The file that controls routing (`infra/nginx/routes.json`) explicitly points every single page at the old PHP system; nothing has been flipped even 1%, and — more fundamentally — that routing file has no server to run on yet in production, so even the entries in it that say "goes to Next.js" (the mini-app API surface) are inert. See §1 and §3.

Two full sections of the admin app that staff use daily — **Inventory & Stock** and **Pharmacy** (dispensing, tracking, appointments, video calls) — have **zero Next.js code written for them at all**. Together these represent roughly 20,000+ lines of PHP with no Next.js counterpart yet. If a pharmacy tenant were flipped to Next.js today, staff would hit a 404 or an empty shell the moment they clicked "Inventory" or "Pharmacy" in the nav. See §4.

The recommended path (§5) is: fix the two known blocking defects and finish Phase 0's real-infra checklist first (this is 100% infrastructure work, no app logic), rehearse it twice, then flip nothing bigger than a demo tenant on the two lowest-risk surfaces the harnesses already cover, then build out Inventory/Pharmacy before any tenant with pharmacy operations can go live. Realistically this is **weeks of infrastructure work before the first flip is even possible**, not something that can happen this week no matter how much more porting code gets written, because the porting code has nowhere to run in production yet.

---

## 1. Phase 0 reality check — is production actually on Docker/VPS, or still on shared hosting?

**Finding: Phase 0 is 100% built-and-locally-tested in the repository, 0% cut over. Production is still cPanel shared hosting.** This is not a matter of interpretation — the runbook that documents Phase 0's own work says so explicitly, and the deploy scripts in the repo corroborate it independently.

### Direct evidence Phase 0 hasn't touched production

- `docs/runbooks/phase0-cutover-rollback.md:9-20` ("Scope note (read first)"): *"This runbook was authored inside a container with **no VPS, DNS, or live production/tenant-DB access**. Every artifact ... was built and verified **locally** ... Nothing here has touched production. Items marked **[REAL-INFRA]** cannot be executed or rehearsed until mig-orchestrator hands this runbook to an agent/operator with actual VPS + DNS + production-DB-read access."*
- Every `[REAL-INFRA]` checkbox in that runbook is unchecked — items 0 (pre-flight), most of item 1 (extension/session verification is checked, but the two DB-connectivity decision items are open), all of items 2's two mandatory import rehearsals, all of item 3 (uploads rsync), all of item 4 (provisioning), most of item 5 (real cron schedule), all of item 6 (the actual DNS cutover), and all of item 7's rollback rehearsal.
- Independently, production's real deploy mechanism is still git-pull-to-shared-hosting:
  - `force_deploy_testry.sh`: `git reset --hard HEAD; git checkout main; git pull origin main` — no Docker anywhere.
  - `deploy_testry_branch.sh`: `git stash; git checkout testry; git pull origin testry`.
  - `DEPLOY_INSTRUCTIONS.md:22-24`: `cd /home/zrismpsz/public_html/cny.re-ya.com && bash force_deploy_testry.sh` — matches CLAUDE.md's documented server path.
  - `deploy-to-emp.sh:6`: `ssh -p 9922 zrismpsz@z129720-ri35sm.ps09.zwhhosting.com` then `cd ~/public_html/emp.re-ya.net && git pull origin master` — a cPanel-style shared host, not a container registry push.

### Specific Phase 0 gaps still open (verified against current `HEAD`, not just the runbook's own account)

| Gap | Evidence |
|---|---|
| Tenant provisioning has no `strategy=mysql` branch yet (the one PHP code change Phase 0 is allowed to make, per the plan §2 item 4) | `grep -n "strategy" classes/TenantProvisioning.php` → **zero matches**. Still cPanel-`uapi`-only. |
| `composer.lock` doesn't actually contain `predis/predis` | `grep -c predis composer.lock` → **0**. `composer.json` requires it (line 7) but the lock file predates it, so a fresh `composer install` silently leaves the Redis session handler in file-session fallback mode — defeating the "stateless container" design the whole session bridge depends on. |
| `config/config.php` still hardcodes `DB_HOST='localhost'` (unix socket) | `config/config.php:21`. Works only via the shared-Docker-volume socket trick the strangler compose file wires around it locally; a real decision on this is flagged **`[DECISION NEEDED from mig-orchestrator]`** in the runbook and has not been made. |
| Real cPanel crontab was never read | `infra/php/crontab`'s 33 entries are **inferred from filenames**, not the real schedule — `docs/ai/background-jobs.md:5`: *"Real scheduler entries were not inspected."* |

### What Phase 0 has genuinely proven, locally

To be fair to the work done: `infra/php/Dockerfile`, the MariaDB 10.11 service, the cron sidecar image, and `infra/nginx/routes.json`'s generator all passed real local checks (`docker build`, `nginx -t`, a live `redis:7-alpine` session round-trip, a live cron firing test) — see the runbook's "Local artifact index" table (`phase0-cutover-rollback.md:376-389`). Phase 1's session bridge went further: a full local E2E harness (`infra/e2e/run.mjs`) stood up real MariaDB + Redis + the real PHP image and proved login → bridge → PHP-page-loads-without-bouncing-to-login → logout, end to end, catching and fixing two real bugs in the process (detailed in §3). That harness's last recorded run reported `{"result":"PASS","failedAt":null}` (commit `c8937a7`'s message). This is real evidence the *mechanism* works — but it was run once, on an earlier commit, in an environment with a working Docker daemon that this container does not have (see §2), and it exercised exactly one page and one tenant, not the "5 heavy PHP pages" the Phase 1 acceptance criterion in the plan actually requires.

### Why this must be first

`infra/nginx/routes.json` is the file that controls which stack (`php_backend` vs `next_admin`) serves each path. It only has power over production traffic once there is a real nginx edge running in front of both stacks in production — and per the evidence above, that edge doesn't exist outside this repository yet. Every "flip" recorded in that file today, including the one path (`/api/miniapp`) whose note says it points at `next_admin` "unconditionally, for every tenant," is **inert** in production: there is no server anywhere applying `infra/nginx/generated/strangler-edge.conf` to real DNS-routed traffic. Confirmed independently at the client layer too: `line-mini-app/src/lib/php-bridge.ts:22-27` documents that its per-endpoint override map (`NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES`) is empty by default, so "every existing call site ... resolves exactly as before the override map existed" — i.e. straight to PHP. **No amount of additional page-porting work changes this.** Phase 0's real-infra checklist is the actual bottleneck, not code volume.

---

## 2. Verification gaps blocking a flip — what has NO live parity evidence

Confirmed directly in this container: `docker version` succeeds (client v29.3.1) but `docker info` fails with `Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?` — so none of the live harnesses (`infra/e2e/parity.mjs`, `infra/e2e/api-parity.mjs`, `infra/e2e/rollback-drill.mjs`, `packages/core/tests-live/genDocNumber.concurrency.ts`) could be run here, against `HEAD` or otherwise.

Beyond this container's own limitation, there is a repo-wide gap: **no CI workflow exists at all** — `.github/workflows/` doesn't exist in this checkout. Every one of these harnesses is scripted to be CI-runnable but nothing actually invokes them automatically on merge; the only evidence any of them ever ran is a hand-pasted JSON result line in a commit message or runbook, whenever a session happened to have Docker access. That means "has this harness been run against the current code" is not a question the repo can answer for itself — it has to be reconstructed batch by batch, which is what this section does.

### What genuinely has live-run evidence, and how stale it is

- **Session bridge mechanism** (Phase 1): ran once, PASS, at commit `c8937a7` — several commits and roughly a day+ of subsequent Phase 2-6 work behind current `HEAD`. Two real bugs were found and fixed by that run (a `session_regenerate_id()` cookie-orphaning bug and a MariaDB strict-mode datetime-format rejection) — both fixes are present in current `HEAD` (`internal/session-bridge.php:191`'s comment; `packages/auth/src/sessionStore.ts:79`'s `toMySqlDateTime()`), but the harness has not been re-run since to confirm the fixes still hold against everything merged after.
- **Phase 2 (users/dashboard/analytics/activity-logs/loyalty-members/templates/groups/line-groups/articles/pharmacists/settings tabs) and Phase 4 batch 1 (inbox reads)**: each batch's runbook documents a `parity.mjs` extension with specific page-pairs, but the actual pass/fail output is not preserved in the runbooks the way Phase 1's harness result was — these are **documented as built, not confirmed as passed against current `HEAD`**.
- **Phase 3 (mini-app APIs incl. checkout) and its rollback drill**: `infra/e2e/api-parity.mjs` and `infra/e2e/rollback-drill.mjs` exist and are scoped in detail (16 endpoint×action pairs; the checkout flip mechanism specifically), but same caveat — no preserved current-`HEAD` pass evidence, and cannot be re-run here.
- **`genDocNumber` 50-concurrent test** (Phase 5, `packages/core/tests-live/genDocNumber.concurrency.ts`): this is the property test proving the Buddhist-era document-number sequence never collides or skips under concurrent load — the exact property the risk register (§6 item 9 of the plan) calls out as the mitigation for two-stacks-issuing-numbers-at-once. It requires Docker and cannot run here; **no evidence in any runbook that it has ever been run against current `HEAD`**.

### Merged surfaces with confirmed **zero** live parity evidence — not "couldn't verify," but never attempted

Two of the most recent merges (both from today, `4d51c80` and `7df420e`) are explicit about this in their own commit messages:

- **LINE broadcast admin surface** (`4d51c80`, ports `broadcast.php` — 2,356 LOC of tab partials, the entire "การตลาด LINE" primary nav destination): *"Every send path is mocked in tests — no test can reach the LINE API."* No `parity.mjs` entry was added.
- **Shop order list + detail** (`7df420e`, ports `shop/orders.php` 847 LOC + `shop/order-detail.php` 1,314 LOC, the "ออเดอร์" primary nav destination staff use daily): same pattern — unit/mock tests only, `routes.json`'s notes for `/shop/orders` and `/shop/order-detail` don't reference any parity harness entry.

Both commit messages also state plainly: *"This surface appears in no phase's retire list in the migration plan ... a genuine gap in the original plan."* — these were done as orchestrator-directed gap-fills outside the plan's numbered phases, which is a legitimate reason but means their verification bar was set ad hoc rather than by the plan's own §7 gate.

### High-risk surfaces (per the plan's own definition — Phase 3 checkout, Phase 5 dispense/documents, Phase 6 webhook, Phase 7 AI SSE) and their evidence status

| Surface | Code status | Live evidence status |
|---|---|---|
| Checkout (`api/miniapp/checkout`) | Ported (`8973e83`) | Harness exists (`api-parity.mjs`, `rollback-drill.mjs`); no preserved current-`HEAD` PASS output; **cannot re-run here (no Docker)** |
| Dispense chain | Ported (`f3242ba`, route lives at `apps/admin/src/app/api/inbox/actions/dispense/route.ts`) | `docs/runbooks/phase5-dispense-parity.md` documents the port in detail; live-run confirmation not preserved; **cannot re-run here** |
| Documents/VAT + `genDocNumber` | Ported (`f3242ba`) | The one test that actually proves the collision-safety property (`genDocNumber.concurrency.ts`) needs Docker; **cannot re-run here**; no preserved PASS evidence in any runbook |
| LINE webhook (Phase 6) | **Not started** — only the auto-reply matcher was ported ahead of time (`4012dab`, `packages/line/src/auto-reply.ts`); `webhook.php` itself is still 5,616 lines, unmodified, 100% PHP | N/A — nothing to verify yet |
| AI SSE pipeline (Phase 7) | **Not started** — no `modules/AIChat` port exists anywhere under `packages/` or `apps/` | N/A — nothing to verify yet |

**What would settle this precisely:** re-running `node infra/e2e/parity.mjs`, `node infra/e2e/api-parity.mjs`, `node infra/e2e/rollback-drill.mjs`, and `pnpm --filter @reya/core test:live` (which wraps `genDocNumber.concurrency.ts`) against current `HEAD` in an environment with a working Docker daemon, and preserving each script's single JSON output line as committed evidence (the way Phase 1's harness result was preserved in a commit message) rather than only narrating "this batch ported X."

---

## 3. Coexistence hazards while both stacks run

Cross-checking the plan's risk register (`docs/plans/2026-07-12-nextjs-full-migration-plan.md` §6, items 1-10) against what's actually merged:

### Currently dormant, because nothing is actually flipped yet

Per §1, since no route has been flipped and Phase 0 hasn't cut production onto the container stack, **most of the risk register's items are not live risks in production today** — there is exactly one system of record (cPanel PHP) actually serving traffic. The register describes what becomes live the moment flips start, not the current state. Specifically:

- **Risk #1 (webhook events lost/duplicated on cutover):** moot today — `webhook.php` is still the only thing processing LINE events; there is no second consumer. `webhook_events` (the idempotency table the plan's mitigation depends on) exists in the committed schema (`database/install_complete_latest.sql`, `database/schema_complete.sql`) but nothing in `packages/` reads or writes it yet — confirmed via `grep -rln webhook_events packages/db` → no matches.
- **Risk #6 (cron double-execution):** moot today for the same reason — there is no `cron-manifest.json` anywhere in the repo (`find . -iname cron-manifest.json` → nothing), and `apps/worker/src/jobs/` contains only `heartbeat.ts`, `registry.ts`, and `types.ts` — scaffolding, not a single real cron job ported. The cPanel crontab remains the sole source of scheduled execution. This risk activates the moment Phase 0's cron sidecar (`infra/php/crontab`, already built locally) is deployed to run *alongside* an undisabled cPanel crontab — the Phase 0 runbook itself flags this exact sequencing hazard as an open `[REAL-INFRA]` item (`phase0-cutover-rollback.md:285-293`) and it is not yet resolved.
- **Risk #9 (document-number collision if both stacks issue numbers):** moot today because `/api/documents` stays `php_backend` unconditionally (`infra/nginx/routes.json`'s own note: *"upstream stays php_backend throughout all of Phase 5, and only mig-orchestrator ... decides if/when a canary ramp for this path ever starts"*) and, per §1, that routing file has no effect on production traffic regardless. The mitigation design (shared `document_sequences` table + `FOR UPDATE` on both sides) is implemented in `packages/core/src/genDocNumber.ts`, but per §2 its collision-safety property has no confirmed live-run evidence against current code.

### The one confirmed hazard that is NOT dormant — it blocks the *next* step, not a distant one

**Risk #2 (session bridge failure — one stack 401s while the other is authenticated)** is the exception: this is Phase 1 infrastructure that any future canary ramp depends on immediately, and it had two confirmed, reproduced bugs (not hypothetical) as recently as commit `c8937a7`:

1. `internal/session-bridge.php`'s `login-sync` action called `session_regenerate_id(true)`, which silently orphaned the Node-issued session id the browser actually carries — a browser presenting the cookie Next.js set would bounce back to `auth/login.php` on every still-PHP page. **Fixed** (removed; `internal/session-bridge.php:191` now carries a comment explaining why it's absent).
2. `packages/auth/src/sessionStore.ts` wrote raw JS `Date#toISOString()` strings into MariaDB `TIMESTAMP` columns, which strict-mode MariaDB rejects outright on every `login()` call against a real database — invisible to the package's own tests because they mock `mysql2` entirely. **Fixed** (`toMySqlDateTime()` conversion, `sessionStore.ts:79`).

Both fixes are present in current `HEAD`. Both were caught only because a live harness ran against a real database — not by any unit test. This is the strongest argument in this whole audit for re-running the live harnesses before trusting any of the code that has been merged since without live verification (§2): the one time this repo's tests were checked against real MySQL semantics, it found two showstopper bugs that all the mocked tests had missed.

### Other coexistence items worth flagging even though dormant today

- **File storage coexistence** (plan §4.2, "two stacks share one volume"): no `packages/` code touches `TenantFileStorage`'s bucket layout yet — not exercised.
- **`internal/.htaccess` CIDR gate**: currently scoped to the E2E harness's own throwaway Docker subnet (`172.30.99.0/24`) — explicitly documented as **not** the production decision (`phase0-cutover-rollback.md:500-506`: *"do not assume 172.30.99.0/24 means anything [in production]"*). The real production CIDR for `internal/` has not been decided.
- **`nginx/routes.json` coverage gaps**: several newly-created Next-only paths (e.g. `/api/inbox/actions/dispense`) have no corresponding entry in `routes.json` at all — today this is harmless (the catch-all `/` → `php_backend` means such requests would 404 against PHP if anyone reached them, and nobody can, since the pages linking to them are themselves unflipped), but the route manifest will need new entries added before some of the already-ported surfaces could even be canary-tested, not just flipped.

---

## 4. Unported surfaces that would strand a user mid-session

Cross-checked `apps/admin/src/nav/manifest.ts`'s 9 primary nav destinations (`buildPrimaryNav()`, 8 entries, plus the `PRIMARY_NAV_FOOTER` settings entry) against `find apps/admin/src/app/(tenant) -maxdepth 1 -type d`.

| # | Nav key | Href | Match prefixes | Next.js dir exists? |
|---|---|---|---|---|
| 1 | overview | `/dashboard?tab=executive` | `/dashboard`, `/odoo-dashboard`, `/analytics` | Yes — `dashboard/`, `analytics/` (odoo-dashboard: no, gated off entirely by Odoo kill-switch) |
| 2 | inbox | `/inbox-v2` | `/inbox`, `/messages` | Partial — `inbox/` exists, but note the href literally says `/inbox-v2`, a path that does not exist under `apps/admin` (the Next port lives at `/inbox`); this mismatch is currently harmless only because nothing routes there yet (see below) |
| 3 | orders | `/shop/orders` | `/shop/orders`, `/pos` | Partial — `shop/orders`, `shop/order-detail` exist; `/pos` (489 LOC) does not |
| 4 | **inventory** | `/inventory` | `/inventory`, `/procurement`, `/accounting` | **No Next.js directory at all** |
| 5 | **pharmacy** | `/pharmacy` | `/pharmacy`, `/dispense-tracking`, `/appointments-admin`, `/pharmacist-video-calls` | **No Next.js directory at all** (only the unrelated `pharmacists/` staff-directory page exists — a different destination, already ported in Phase 2 tail) |
| 6 | patients | `/users` | `/users`, `/user-tags`, `/membership`, `/loyalty-members` | Partial — `users/`, `user-detail/`, `loyalty-members/` exist; `/user-tags`, `/membership` (413 LOC) do not |
| 7 | marketing | `/broadcast` | `/broadcast`, `/drip-campaigns`, `/rich-menu`, `/templates`, `/liff-settings` | Partial — `broadcast/`, `templates/` exist; `/drip-campaigns` (559 LOC), `/rich-menu` (101 LOC) do not; `liff-settings.php` doesn't even exist in PHP (dead nav link already) |
| 8 | reports | `/analytics` | `/activity-logs`, `/scheduled`, `/triage-analytics` | Partial — `activity-logs/` exists; `/scheduled` (91 LOC), `/triage-analytics` (298 LOC) do not |
| 9 | settings (footer) | `/settings` | `/settings`, `/admin-users`, `/admin/`, `/consent-management` | Partial — `settings/` exists (6 of 7 live tabs; `notifications` tab still PHP-only per `routes.json`'s own note); `/admin-users` (878 LOC), `/admin/` (platform super-admin, 15 files / 5,008 LOC), `/consent-management` (11 LOC — likely itself a redirect stub) do not |

### Quantifying the two total gaps (inventory, pharmacy)

- **Inventory & Stock**: `inventory/` (12 files, **4,832 LOC**) + `includes/inventory/` (23 files, **11,591 LOC**) + `procurement.php` (131 LOC) + `accounting.php` (140 LOC) + `includes/accounting/` (4 files, **3,355 LOC**) = **~20,050 LOC**, zero Next.js code.
- **Pharmacy**: `pharmacy.php` hub (94 LOC) + `includes/pharmacy/{interactions,dashboard,dispense}.php` (**1,506 LOC**, excluding the already-ported `pharmacists.php` tab) + `dispense-tracking.php` (518 LOC) + `appointments-admin.php` (498 LOC) + `pharmacist-video-calls.php` (**1,622 LOC**) = **~4,238 LOC**, zero Next.js code.

Both match the task's stated "known gaps" and are confirmed, not assumed — `find apps/admin -type d -iname inventory -o -type d -iname pharmacy` returns nothing.

### What this means concretely

If any tenant were flipped to the Next admin UI today — even hypothetically, ignoring §1's finding that there's no server to flip on — clicking "สินค้า & คลัง" (Inventory & Stock) or "งานเภสัช" (Pharmacy) in the primary nav would hit a route that doesn't exist in `apps/admin` at all. For a pharmacy tenant, Inventory and Pharmacy are not peripheral — they're core daily-use surfaces (stock levels, dispensing, drug interactions, appointments). **No tenant that actively uses these can be flipped to the Next admin UI until they're built**, regardless of how the rest of the flip sequencing goes.

The `/inbox-v2` href mismatch (item 2) is a smaller, easy-to-fix loose end worth flagging to whichever agent eventually wires up the live nav: the manifest data was ported verbatim from PHP's href literal and never updated to point at the Next inbox's actual path (`/inbox`) — currently inert since nothing routes there, but it will need fixing before Inbox itself is ever flip-ready.

---

## 5. Recommended sequence — the shortest credible path to a first real flip

This is ordered by hard dependency, not by convenience. Each step names what blocks it.

1. **Finish Phase 0's real-infra checklist on an actual VPS.** This is the literal precondition for step 2 through the end — no route flip is *possible*, not just inadvisable, until it's done (§1). Concretely: provision the VPS, resolve the three open decisions in `phase0-cutover-rollback.md` (§2's DB_HOST socket-vs-TCP path, the `strategy=mysql` provisioning code change, the `composer.lock`/predis refresh), run both required import rehearsals, rehearse the rollback drill for real (not just design it), and only then do the actual DNS cutover. Owner: `mig-infra` (build) + `mig-orchestrator` (go/no-go) per the runbook's own stated ownership. This is pure infrastructure work — it does not require any more PHP-to-Next porting to start.
2. **Re-run the live harnesses against current `HEAD` in an environment with a working Docker daemon**, before trusting anything merged since Phase 1 batch 3's last confirmed PASS: `infra/e2e/run.mjs` (session bridge), `infra/e2e/parity.mjs`, `infra/e2e/api-parity.mjs`, `infra/e2e/rollback-drill.mjs`, `packages/core`'s `genDocNumber` concurrency test. Given that the one and only time this happened it caught two showstopper bugs invisible to mocked tests (§3), treat "the mocked tests pass" as insufficient evidence on its own for any surface this audit marked as lacking live evidence (§2) — especially the two gap-fill surfaces (broadcast, shop/orders) that were never wired into a parity harness at all.
3. **Only after 1 and 2**, the plan's own canary ramp (demo tenant → 1 real tenant → 10% → 50% → 100%, §7.3) becomes meaningful. Start it on the lowest-risk, best-evidenced surface — the Phase 2 read-mostly admin pages (`/users`, `/dashboard`, `/analytics`, etc.) — not on checkout, dispense, or the webhook, which the plan itself classifies as high-risk and gates behind `mig-orchestrator` co-sign specifically because of correctness/compliance/revenue exposure.
4. **Build out Inventory & Pharmacy (§4) before flipping any tenant that actively uses them.** These are two of nine primary nav destinations with literally zero Next.js code; a tenant using either would be stranded mid-session the moment they're flipped. This can happen in parallel with steps 1-2 (it's pure Next.js build work, independent of infra), but it must land before those specific tenants — likely most real pharmacy tenants — can go live, even after Phase 0 is done.
5. **Do not flip the webhook (Phase 6) or AI SSE (Phase 7) until their own phases actually start.** Both are currently 0% ported (only the auto-reply matcher is prepped ahead of time for Phase 6). The plan's own risk register ranks the webhook cutover as the single highest LINE-event-loss risk and requires a 2-week shadow-mode soak before any account is flipped — that clock hasn't started because there's no webhook port to shadow yet.

**Honest effort estimate:** the plan's own estimate for Phase 0 alone is 3-5 person-weeks (`docs/plans/2026-07-12-nextjs-full-migration-plan.md` §5), and that was written before this audit found three still-open decisions/gaps inside it. 33 calendar days of intensive parallel-agent work (plan created 2026-07-12, most recent merge today 2026-08-14) have produced real, tested Next.js code for roughly half of the admin surface — but **zero of it is reachable by a real user yet**, because the infrastructure prerequisite hasn't shipped. The realistic shortest path to a first flipped real tenant is: Phase 0 real-infra completion (days, once a VPS + DNS window are actually available — the artifacts are ready, this is execution time not design time) + harness re-verification (a day, given working Docker) + one canary ramp cycle on a low-risk surface (the plan's own ramp steps require "≥3 days per step" minimum, §7.3) — call it **2-3 weeks minimum from the moment VPS/DNS access is granted**, assuming no new blocking bugs turn up in re-verification (which is not a safe assumption given §3's track record).

---

## Appendix: commands used for this audit

All run against `origin/main` at `d60d757` inside `/tmp/.../worktrees/wt-golive-survey` (no production/VPS/DNS access):

```
git log --oneline, git for-each-ref (branch/merge timeline)
docker version / docker info (confirms no daemon in this container)
grep -rn "strategy" classes/TenantProvisioning.php
grep -c predis composer.lock
grep -n DB_HOST config/config.php
find apps/admin/src/app/(tenant) -maxdepth 1 -type d
find infra/e2e, find .github/workflows
wc -l on every unported PHP file cited in §4
```
