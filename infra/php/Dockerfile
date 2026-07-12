# infra/php/Dockerfile
#
# Phase 0 replatform image for the PHP monolith (see
# docs/plans/2026-07-12-nextjs-full-migration-plan.md §2.1 and
# docs/runbooks/phase0-cutover-rollback.md).
#
# Design notes (read before editing):
#
#  - This is a *lift-and-shift* image: it packages the PHP 8.2 + Apache
#    runtime and the extensions the app needs, but does NOT bake in
#    application code or `vendor/`. Phase 0 is explicitly "ยกมาทั้งดุ้นไม่แก้โค้ด"
#    (move as-is, no code changes) — code arrives via a bind mount
#    (see infra/compose/docker-compose.strangler.yml, service `php`,
#    volume `.:/var/www/html`) populated by the same rsync/composer-install
#    flow described in the runbook, not by `COPY` + `composer install`
#    inside this build. This also keeps the image buildable offline /
#    behind restrictive egress (no packagist/pecl network calls at build
#    time) — every RUN below only touches apt (HTTP, mirrors) and the PHP
#    source tarball that already ships inside php:8.2-apache.
#
#  - Session storage: composer.json already requires `predis/predis`
#    (`composer ต้องการ >=8.0 + predis` — plan §2.1), so sessions are moved
#    to Redis via a small SessionHandlerInterface implementation
#    (infra/php/session-redis-handler.php) loaded through
#    `auto_prepend_file`, NOT the PECL `redis` extension. This avoids a
#    second compiled extension, needs no app code changes, and keeps the
#    container stateless (no local session files) — required later for
#    the Next.js session bridge (plan §1.4). If Redis is unreachable or
#    vendor/ isn't mounted yet, the handler no-ops and PHP silently falls
#    back to file sessions instead of fataling every request.
#
#  - AllowOverride All is set so the existing root .htaccess (extensionless
#    URLs, liff-*.php redirects, debug-script blocking, security headers,
#    mod_php ini overrides) keeps working completely unmodified.

FROM php:8.2-apache

LABEL org.opencontainers.image.title="clinicya-php" \
      org.opencontainers.image.description="PHP 8.2 + Apache replatform image (Phase 0 strangler)" \
      org.opencontainers.image.source="https://github.com/reyatelehealth2026-crypto/clinicya"

# --- System packages needed to compile the extensions below. Debian/Ubuntu
#     apt mirrors are plain HTTP, so this step needs no HTTPS egress. ---
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        unzip \
        git \
        rsync \
        cron \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions required by the app (classes/Database.php -> pdo_mysql;
#     FlexTemplates / label rendering -> gd; LineAPI/OdooAPIClient -> curl;
#     Thai text handling -> mbstring; document/export zips -> zip; prod perf
#     -> opcache). docker-php-ext-install compiles from the PHP source
#     tarball already baked into this base image (/usr/src/php.tar.xz) —
#     no PECL/packagist network access required. ---
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        curl \
        mbstring \
        zip \
        opcache

# --- Apache modules: mod_rewrite for clean URLs / liff redirects
#     (.htaccess), mod_headers for security headers, mod_expires/mod_deflate
#     to match the caching/compression behavior already declared in
#     .htaccess. ---
RUN a2enmod rewrite headers expires deflate

# --- Vhost: DocumentRoot + AllowOverride All so .htaccess keeps working
#     unmodified. See infra/php/apache-vhost.conf. ---
COPY infra/php/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# --- php.ini: mirrors the <IfModule mod_php.c> block in the repo's root
#     .htaccess (upload size, execution time, memory, Asia/Bangkok tz,
#     UTF-8) plus opcache tuning. Kept as a separate conf.d file (zz- prefix
#     so it loads after the base php.ini-production settings). ---
COPY infra/php/php.ini /usr/local/etc/php/conf.d/zz-clinicya.ini

# --- Redis-backed session handler (see design note above). The ini only
#     wires auto_prepend_file; the handler itself decides at runtime
#     whether Predis/Redis are actually available. ---
COPY infra/php/session-redis.ini /usr/local/etc/php/conf.d/zz-session-redis.ini
COPY infra/php/session-redis-handler.php /opt/clinicya/session-redis-handler.php

# --- Cron sidecar (plan §2 item 5: 33 jobs, ofelia/crond) ---
# This same image also runs as the `cron` compose service (command
# overridden to `cron -f`) — one image, two roles, so the cron container
# always runs the exact PHP/extension set the web container does. The
# schedules in infra/php/crontab are INFERRED, not the real cPanel
# crontab — see that file's header and
# docs/runbooks/phase0-cutover-rollback.md item 5.
COPY infra/php/crontab /etc/cron.d/clinicya-cron
RUN chmod 0644 /etc/cron.d/clinicya-cron \
    && mkdir -p /var/log/clinicya-cron \
    && chown www-data:www-data /var/log/clinicya-cron

ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html

EXPOSE 80

# No CMD override — inherit php:8.2-apache's `apache2-foreground`. The
# `cron` compose service overrides `command:` to run `cron -f` instead.
