# VPS Trial Stack — run PHP + Next.js side by side on a fresh VPS

**Purpose:** stand up the whole system on a VPS to try it, **without touching the
live cPanel site**. No DNS change, no TLS, no traffic flip.

**Not this document:** the real production cutover. That is
`docs/runbooks/phase0-cutover-rollback.md`.

Everything below was verified by actually building the images and running the
stack (see "Verification evidence" at the end for what was proven and what was not).

---

## สรุปภาษาไทย

รันของใหม่คู่กับของเก่าบน VPS เพื่อ "ลองดู" — cPanel ยังรับ traffic จริงเหมือนเดิม
ไม่แตะ DNS ไม่ต้องมี TLS ถ้าพังก็ไม่มีใครเดือดร้อน

ขั้นตอนย่อ: เตรียม VPS → clone repo → `composer install` → เอา dump มาลง →
`docker compose up` → ทดสอบด้วย `curl --resolve`

จุดที่ต้องระวังที่สุด: **`cron` ไม่ได้เปิดโดยอัตโนมัติ** เพราะตารางเวลาใน
`infra/php/crontab` เป็นการเดา ถ้าเปิดทับ dump ของจริง มันอาจยิง broadcast
ไปหาลูกค้าจริง

---

## What comes up

| Service | Image source | Role |
|---|---|---|
| `mariadb` | `mariadb:10.11` | master + tenant DBs, binlog on from first boot |
| `redis` | `redis:7-alpine` | PHP sessions, worker queues |
| `php` | `infra/php/Dockerfile` | the monolith, code bind-mounted unchanged |
| `next-admin` | `infra/admin/Dockerfile` | the Next.js admin — UI **and** its API routes |
| `worker` | `infra/worker/Dockerfile` | BullMQ jobs + realtime relay |
| `ws` | `infra/ws/Dockerfile` | legacy `websocket-server.js` (what production runs today) |
| `nginx-edge` | `nginx:alpine` | strangler edge, sets `X-Served-By` |
| `cron` | `infra/php/Dockerfile` | **profile-gated, off by default** — see step 7 |

Not included: `next-miniapp`. `line-mini-app` has no Dockerfile in this repo and
is deployed separately today, so **`/miniapp` returns 502 from this stack**.
That is expected. Every other route works.

---

## 1. VPS prerequisites

- Ubuntu 22.04 or 24.04, root or sudo.
- **8 GB RAM minimum.** MariaDB must hold the master DB plus every tenant DB;
  the Next build peaks around 2 GB on its own.
- Disk: at least 3× the size of your uncompressed dump, plus ~5 GB for images.
- Firewall: allow 22 and whatever `EDGE_HTTP_PORT` you pick. **Do not open 3306.**

```bash
curl -fsSL https://get.docker.com | sh
docker --version && docker compose version   # need compose v2
```

## 2. Get the code

```bash
git clone https://github.com/reyatelehealth2026-crypto/clinicya.git
cd clinicya
git checkout claude/nextjs-migration-plan-alspee
```

## 3. `composer install` on the host — do not skip

The PHP image deliberately does **not** bake in application code or `vendor/`
(`infra/php/Dockerfile` explains why). The repo is bind-mounted, so `vendor/`
must exist on the host before the container starts.

```bash
composer install --no-dev --prefer-dist
php -r 'require "vendor/autoload.php"; var_dump(class_exists("Predis\\Client"));'
```

That must print `bool(true)`. If it prints `false`, sessions will silently fall
back to file-based storage — `infra/php/session-redis-handler.php` fails open by
design, so a misconfigured container looks healthy while holding session state
on local disk.

Drop `--no-dev` if you also want to run `composer test` on the VPS.

## 4. `config/config.php`

The file is tracked in git (despite matching a `.gitignore` line — it was
committed before that rule, and `.gitignore` does not untrack existing files).
Confirm its DB constants match what you put in `.env.vps` in the next step.

`DB_HOST='localhost'` is correct here and needs no change: PDO reads that as
"connect via unix socket", and the compose file shares a socket volume between
`php` and `mariadb` with `pdo_mysql.default_socket` pointed at it. That is what
lets unmodified PHP reach the DB container.

The Next stack does **not** use the socket — `@reya/db` connects over TCP to
host `mariadb`. Both are already wired in the compose file.

## 5. Secrets

```bash
cp infra/compose/.env.vps.example infra/compose/.env.vps
chmod 600 infra/compose/.env.vps
openssl rand -hex 32        # -> SESSION_BRIDGE_HMAC_SECRET
```

Fill in `MARIADB_ROOT_PASSWORD`, `MARIADB_PASSWORD`, `SESSION_BRIDGE_HMAC_SECRET`.
Compose refuses to start without them rather than booting with empty credentials.

Use **fresh** passwords, not the cPanel production ones — this stack sits on a
public IP during the trial. `infra/compose/.env.vps` is gitignored; keep it that way.

## 6. Load data

Two options.

**First-boot auto-import** — drop dumps in `database/vps-seed/`; MariaDB's
entrypoint runs them once, on an empty data volume only:

```bash
mkdir -p database/vps-seed
cp /path/to/master.sql database/vps-seed/00-master.sql
cp /path/to/tenant-0001.sql database/vps-seed/10-tenant-0001.sql
```

**Manual import** (works any time, and is what you will use for re-imports):

```bash
docker compose --env-file infra/compose/.env.vps \
  -f infra/compose/docker-compose.vps.yml up -d mariadb
docker exec -i clinicya-vps-mariadb \
  mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" < master.sql
```

Take dumps from cPanel with `mysqldump --single-transaction` per database.

Uploads go into the `php_uploads` volume:

```bash
docker compose ... up -d php
docker cp /path/to/uploads/. clinicya-vps-php:/var/www/html/uploads/
```

## 7. Start

```bash
docker compose --env-file infra/compose/.env.vps \
  -f infra/compose/docker-compose.vps.yml up -d --build
```

First build takes 10–20 minutes (Next production build dominates).

**`cron` stays off.** It is behind a compose profile because
`infra/php/crontab`'s schedules are inferred, not the real cPanel crontab — its
own header says so. Starting it against a freshly imported production dump can
fire real broadcasts and reminders at real customers. Only after reconciling
the schedules (`phase0-cutover-rollback.md` item 5):

```bash
docker compose ... --profile cron up -d cron
```

## 8. Verify

```bash
docker compose --env-file infra/compose/.env.vps \
  -f infra/compose/docker-compose.vps.yml ps
```

All services should reach `healthy`. Then:

```bash
E=http://<vps-ip>

# edge is alive
curl -sS -o /dev/null -w '%{http_code}\n' $E/__edge-health          # 200

# default route is still PHP
curl -sSI $E/ | grep -i x-served-by                                  # php

# the Next stack answers, and the per-tenant canary works.
# NOTE the hostname shape: the edge derives the tenant slug with
#   ~^tenant-(?<slug>[a-z0-9-]+)\.re-ya\.com$
# so the canary slug "demo-tenant" means host tenant-demo-tenant.re-ya.com.
curl -sSI -H 'Host: tenant-demo-tenant.re-ya.com' $E/admin-preview | grep -i x-served-by   # next
curl -sSI -H 'Host: tenant-0001.re-ya.com'        $E/admin-preview | grep -i x-served-by   # php

# a real tenant hostname, without touching DNS
curl -sS --resolve tenant-0001.re-ya.com:80:<vps-ip> \
     http://tenant-0001.re-ya.com/ -o /dev/null -w '%{http_code}\n'
```

`/admin-preview` returning **404 with `X-Served-By: next`** is correct — the
route is a canary placeholder with no page behind it. The header is what you are
testing, not the status.

Direct container checks:

```bash
docker exec clinicya-vps-next-admin curl -fsS http://localhost:3000/api/health
docker exec clinicya-vps-worker     curl -fsS http://localhost:8099/health
```

## 9. Day-to-day

```bash
# logs
docker compose ... logs -f next-admin

# after editing PHP (bind-mounted — no rebuild needed)
# ...nothing. Just reload the page.

# after editing Next/worker source
docker compose ... up -d --build next-admin

# after editing infra/nginx/routes.json
node infra/nginx/generate-routes.mjs
docker compose ... exec nginx-edge nginx -s reload

# DB from your laptop (never expose 3306 publicly)
ssh -L 3307:127.0.0.1:3307 user@vps
```

## 10. Tear down

```bash
docker compose ... down          # keep data
docker compose ... down -v       # DESTROY volumes: DB, uploads, redis
```

---

## What this stack still cannot do

| Gap | Impact | Where it is tracked |
|---|---|---|
| No TLS | HTTP only. Fine for a trial; a real cutover needs a wildcard cert via DNS-01. | `phase0-cutover-rollback.md` §6 |
| `/miniapp` → 502 | `line-mini-app` has no Dockerfile here. | this file, §"What comes up" |
| `TenantProvisioning` is cPanel-`uapi`-only | Cannot create a **new** tenant on this stack. Existing imported tenants work. | Codex handoff §4 / A1 |
| `cron` schedules inferred | Left off by default. | `phase0-cutover-rollback.md` item 5 |
| No CI | Nothing gates a broken build. | Codex handoff §4 / A4 |

---

## Verification evidence

Performed against this repo, with a real Docker daemon:

- `docker compose -f infra/compose/docker-compose.vps.yml config` → valid;
  7 services by default, `cron` correctly excluded behind its profile; all build
  contexts and bind mounts resolve to the repo root.
- `infra/admin/Dockerfile` built end to end. The container boots, reports
  **healthy**, `GET /api/health` returns `{"status":"ok","servedBy":"next"}`, and
  `/_next/static/chunks/*.js` returns **200** — confirming the separate
  `.next/static` COPY is correct (Next leaves static assets out of the
  standalone bundle; omitting that COPY yields a site that renders HTML and 404s
  every asset).
- Stack brought up (`mariadb`, `redis`, `php`, `next-admin`, `nginx-edge`); all
  reached healthy.
- Edge routing confirmed live: `/__edge-health` → 200; `/` → `X-Served-By: php`;
  `/admin-preview` → `next` for `tenant-demo-tenant.re-ya.com` and `php` for
  `tenant-0001.re-ya.com`; `/miniapp` → 502 as documented.

Not verified:

- `worker` and `ws` images were not built in the authoring environment — its
  proxy intercepts TLS and the build containers lack its CA, so `corepack`/`npm`
  cannot reach the npm registry. `infra/admin/Dockerfile` was proven by building
  a temporary CA-injected variant; `infra/worker/Dockerfile` is pre-existing and
  unchanged. On a normal VPS with direct internet these build without any of
  that. **Build both before trusting this runbook end to end.**
- No import of a real production dump was performed. Steps 3–6 are written from
  the artifacts, not from a rehearsed migration.
- `/` returned HTTP 500 in the smoke test — correct for an empty database, but it
  means no PHP page was actually exercised against real data.
