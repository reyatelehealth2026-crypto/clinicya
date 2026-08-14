#!/usr/bin/env bash
#
# infra/scripts/vps-import-from-cpanel.sh
#
# Pull the live data off cPanel shared hosting and load it into the VPS trial
# stack: every zrismpsz_* database plus the uploads/ tree.
#
# Run this ON THE VPS, from inside the repo, after infra/scripts/vps-bootstrap.sh
# has the stack running. Going cPanel -> VPS directly avoids hauling the whole
# dump through your laptop.
#
#   bash infra/scripts/vps-import-from-cpanel.sh --dry-run          # plan only
#   bash infra/scripts/vps-import-from-cpanel.sh --dbs zrismpsz_reya_t_0001
#   bash infra/scripts/vps-import-from-cpanel.sh                    # everything
#
# READ-ONLY ON CPANEL. Every remote operation is a read: mysqldump with
# --single-transaction, rsync pulling, grep of config.php. Nothing writes to,
# deletes from, or restarts anything on the production host. Production keeps
# serving traffic throughout — this is a copy, not a cutover.
#
# ⚠️  The data you are about to copy is real: patient records, and live LINE /
#     Telegram / SMTP / Facebook / TikTok credentials. Once it lands, this stack
#     can push messages to real customers. Run database/trial-safe-mode.sql
#     against every imported tenant DB BEFORE opening the admin UI —
#     docs/runbooks/vps-trial-stack.md §7b. This script reminds you at the end
#     and refuses to be the thing that forgets.

set -euo pipefail

# --- Remote (cPanel) — documented in CLAUDE.md ------------------------------
REMOTE_HOST="${REMOTE_HOST:-118.27.146.16}"
REMOTE_USER="${REMOTE_USER:-zrismpsz}"
REMOTE_PORT="${REMOTE_PORT:-9922}"          # NOT 22 — cPanel runs sshd on 9922
REMOTE_ROOT="${REMOTE_ROOT:-/home/zrismpsz/public_html}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519_cny}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="$REPO_ROOT/infra/compose/.env.vps"
COMPOSE_FILE="$REPO_ROOT/infra/compose/docker-compose.vps.yml"
DUMP_DIR="${DUMP_DIR:-/var/backups/clinicya-import}"

DRY_RUN=0
ONLY_DBS=""
SKIP_UPLOADS=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)      DRY_RUN=1; shift ;;
    --dbs)          ONLY_DBS="${2:?--dbs needs a space-separated list}"; shift 2 ;;
    --skip-uploads) SKIP_UPLOADS=1; shift ;;
    -h|--help)      sed -n '3,30p' "$0" | sed -E 's/^# ?//'; exit 0 ;;
    *)              echo "unknown flag: $1" >&2; exit 2 ;;
  esac
done

c_ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
c_warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
c_err()  { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }
step()   { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die()    { c_err "$*"; exit 1; }

SSH=(ssh -i "$SSH_KEY" -p "$REMOTE_PORT" -o BatchMode=yes -o ConnectTimeout=15
     -o StrictHostKeyChecking=accept-new "$REMOTE_USER@$REMOTE_HOST")

dc() { docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"; }

# ---------------------------------------------------------------------------
step "1/6  Preflight"
# ---------------------------------------------------------------------------
[[ -f "$COMPOSE_FILE" ]] || die "not inside the repo"
[[ -f "$ENV_FILE" ]]     || die "$ENV_FILE missing — run infra/scripts/vps-bootstrap.sh first"
[[ -r "$SSH_KEY" ]]      || die "ssh key not readable at $SSH_KEY (override with SSH_KEY=...)"
command -v rsync >/dev/null 2>&1 || die "rsync not installed — apt install -y rsync"

# shellcheck disable=SC1090
MARIADB_ROOT_PASSWORD="$(grep -E '^MARIADB_ROOT_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
[[ -n "$MARIADB_ROOT_PASSWORD" ]] || die "MARIADB_ROOT_PASSWORD empty in $ENV_FILE"

dc ps --status running --services 2>/dev/null | grep -qx mariadb \
  || die "the mariadb service is not running — start the stack first"
c_ok "local stack up, mariadb running"

"${SSH[@]}" true 2>/dev/null \
  || die "cannot ssh to $REMOTE_USER@$REMOTE_HOST:$REMOTE_PORT with $SSH_KEY"
c_ok "ssh to cPanel OK ($REMOTE_USER@$REMOTE_HOST:$REMOTE_PORT)"

# ---------------------------------------------------------------------------
step "2/6  Read DB credentials from the remote config (never printed)"
# ---------------------------------------------------------------------------
# Greps the literal define()s rather than executing config.php — executing it
# would emit whatever that file echoes and pollute the value.
remote_define() {
  "${SSH[@]}" "grep -m1 \"define('$1'\" '$REMOTE_ROOT/config/config.php' \
     | sed -E \"s/.*define\\('$1'[[:space:],]*'([^']*)'.*/\\1/\"" 2>/dev/null
}
R_DB_USER="$(remote_define DB_USER)"
R_DB_PASS="$(remote_define DB_PASS)"
[[ -n "$R_DB_USER" && -n "$R_DB_PASS" ]] \
  || die "could not read DB_USER/DB_PASS from $REMOTE_ROOT/config/config.php"
c_ok "credentials read for user '${R_DB_USER}' (password not shown)"

# MYSQL_PWD keeps the password off the remote process list, where `ps` would
# expose it to any other user on a shared host. Never use -p<pass> here.
remote_mysql() { "${SSH[@]}" "MYSQL_PWD='$R_DB_PASS' mysql -u'$R_DB_USER' --batch --skip-column-names -e \"$1\""; }

# ---------------------------------------------------------------------------
step "3/6  Discover databases"
# ---------------------------------------------------------------------------
if [[ -n "$ONLY_DBS" ]]; then
  DBS="$ONLY_DBS"
  c_ok "using the explicit --dbs list"
else
  DBS="$(remote_mysql "SHOW DATABASES" | grep -E '^zrismpsz_' | grep -vE '^(information_schema|performance_schema|mysql|sys)$' || true)"
fi
[[ -n "$DBS" ]] || die "no zrismpsz_* databases visible to '$R_DB_USER'"

printf '\n  %-34s %10s\n' "DATABASE" "SIZE"
TOTAL_MB=0
for db in $DBS; do
  mb="$(remote_mysql "SELECT ROUND(SUM(data_length+index_length)/1024/1024) FROM information_schema.TABLES WHERE table_schema='$db'" 2>/dev/null || echo '?')"
  [[ "$mb" == "NULL" || -z "$mb" ]] && mb=0
  [[ "$mb" =~ ^[0-9]+$ ]] && TOTAL_MB=$((TOTAL_MB + mb))
  printf '  %-34s %8s MB\n' "$db" "$mb"
done
printf '  %-34s %8s MB\n\n' "TOTAL" "$TOTAL_MB"

AVAIL_MB=$(df -BM --output=avail "$(dirname "$DUMP_DIR")" 2>/dev/null | tail -1 | tr -dc '0-9')
if [[ -n "$AVAIL_MB" ]] && (( AVAIL_MB < TOTAL_MB * 3 )); then
  c_warn "only ${AVAIL_MB}MB free at $(dirname "$DUMP_DIR") — dump + import wants roughly 3x ${TOTAL_MB}MB"
fi

if (( DRY_RUN )); then
  step "Dry run — nothing was dumped, transferred, or imported."
  echo "  Would dump the databases above into $DUMP_DIR and import them."
  (( SKIP_UPLOADS )) || echo "  Would rsync $REMOTE_ROOT/uploads/ into the php_uploads volume."
  exit 0
fi

# ---------------------------------------------------------------------------
step "4/6  Dump + import  (mysqldump --single-transaction: read-only on cPanel)"
# ---------------------------------------------------------------------------
mkdir -p "$DUMP_DIR"; chmod 700 "$DUMP_DIR"
c_warn "dumps land in $DUMP_DIR (mode 700) and contain real customer data — delete them when done"

for db in $DBS; do
  printf '\n  --- %s ---\n' "$db"
  out="$DUMP_DIR/${db}.sql.gz"

  # --single-transaction gives a consistent snapshot without locking, so
  # production writes are never blocked. --routines/--triggers/--events keep
  # stored logic. No --master-data: this is a copy, not a replica.
  "${SSH[@]}" "MYSQL_PWD='$R_DB_PASS' mysqldump -u'$R_DB_USER' \
      --single-transaction --quick --routines --triggers --events \
      --default-character-set=utf8mb4 --no-tablespaces '$db' | gzip -1" > "$out"
  [[ -s "$out" ]] || die "dump of $db is empty"
  c_ok "dumped  $(du -h "$out" | cut -f1)"

  docker exec -i clinicya-vps-mariadb \
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
    -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  gzip -dc "$out" | docker exec -i clinicya-vps-mariadb \
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --default-character-set=utf8mb4 "$db"
  c_ok "imported"

  # The app user in the container is created by compose for one database only;
  # every additional imported schema needs an explicit grant or PHP gets
  # "Access denied" the moment it switches tenants.
  APP_USER="$(grep -E '^MARIADB_USER=' "$ENV_FILE" | cut -d= -f2- || true)"
  APP_USER="${APP_USER:-zrismpsz_clinicya}"
  docker exec -i clinicya-vps-mariadb \
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
    -e "GRANT ALL PRIVILEGES ON \`$db\`.* TO '$APP_USER'@'%'; FLUSH PRIVILEGES;"
  c_ok "granted to $APP_USER"
done

# ---------------------------------------------------------------------------
step "5/6  Verify — table counts, source vs destination"
# ---------------------------------------------------------------------------
FAIL=0
printf '\n  %-34s %8s %8s\n' "DATABASE" "SRC" "DEST"
for db in $DBS; do
  src="$(remote_mysql "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema='$db'")"
  dst="$(docker exec -i clinicya-vps-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
        --batch --skip-column-names \
        -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema='$db'" 2>/dev/null || echo 0)"
  if [[ "$src" == "$dst" ]]; then printf '  %-34s %8s %8s  ok\n' "$db" "$src" "$dst"
  else printf '  %-34s %8s %8s  MISMATCH\n' "$db" "$src" "$dst"; FAIL=1; fi
done
(( FAIL )) && c_err "table counts differ — investigate before trusting this import"

# ---------------------------------------------------------------------------
step "6/6  Uploads"
# ---------------------------------------------------------------------------
if (( SKIP_UPLOADS )); then
  c_warn "skipped (--skip-uploads)"
else
  STAGE="$DUMP_DIR/uploads"
  mkdir -p "$STAGE"
  # Pull only. There is no reverse direction anywhere in this script.
  rsync -az --delete --info=stats1 \
    -e "ssh -i $SSH_KEY -p $REMOTE_PORT -o StrictHostKeyChecking=accept-new" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_ROOT/uploads/" "$STAGE/"
  docker cp "$STAGE/." clinicya-vps-php:/var/www/html/uploads/
  docker exec clinicya-vps-php chown -R www-data:www-data /var/www/html/uploads
  c_ok "uploads synced into the php_uploads volume"
fi

cat <<'NEXT'

==> Imported.

  ⚠️  DO NOT OPEN THE ADMIN UI YET.

  The databases you just imported carry live LINE access tokens, Telegram bot
  tokens, SMTP passwords, and Facebook/TikTok tokens. Clicking dispense or
  broadcast now would reach real customers. Sever them first, per tenant DB:

    for db in $(docker exec clinicya-vps-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" \
                  --batch --skip-column-names -e "SHOW DATABASES" | grep '^zrismpsz_reya_t_'); do
      docker exec -i clinicya-vps-mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$db" \
        < database/trial-safe-mode.sql
    done

  Then:
    - point config/config.php's DB_PASS at MARIADB_PASSWORD from .env.vps
    - runbook §11 for a real functional trial (test OA under a NEW provider + HTTPS)
    - delete the dumps when you no longer need them:  rm -rf /var/backups/clinicya-import

  Production on cPanel was only ever read from, and is still serving normally.
NEXT
