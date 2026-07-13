#!/usr/bin/env node
/**
 * infra/nginx/generate-routes.mjs
 *
 * Renders infra/nginx/routes.json (the strangler edge's route manifest —
 * plan §1.5) into a standalone nginx config: two-tier host×path maps
 * (host -> tenant slug -> upstream) for per-tenant canary, an
 * `X-Served-By: php|next` header on every response, and the load-bearing
 * .htaccess behaviors that make sense to enforce at the edge (debug-script
 * blocking, liff-*.php -> /miniapp/ redirects, security headers). Clean-URL
 * (extensionless .php) resolution is deliberately NOT reimplemented here —
 * it stays in Apache via infra/php/apache-vhost.conf's AllowOverride All,
 * since that's a filesystem-dependent behavior only the php_backend
 * container can resolve correctly.
 *
 * Usage:
 *   node infra/nginx/generate-routes.mjs [routes.json] [output.conf]
 *   node infra/nginx/generate-routes.mjs --validate-only [routes.json]
 *
 * Defaults: infra/nginx/routes.json -> infra/nginx/generated/strangler-edge.conf
 *
 * Deploy = edit routes.json, re-run this script, reload nginx
 * (`nginx -s reload`). Revert one route = revert its line in routes.json +
 * regenerate + reload — no other file changes needed.
 *
 * No external dependencies (deliberately — avoids requiring network access
 * to npm/packagist at generation time; this is a pure-Node script using
 * only built-ins).
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

const UPSTREAMS = {
  php_backend: 'php:80',
  next_admin: 'next-admin:3000',
  next_miniapp: 'next-miniapp:3000',
  ws: 'ws:3001',
};

const DEFAULT_UPSTREAM = 'php_backend';

// .htaccess liff-*.php -> /miniapp/ redirects (see repo root .htaccess).
// Kept as an explicit static list (not derived from routes.json) since it's
// a finite, already-frozen mapping being ported verbatim, not a strangler
// route decision.
const LIFF_REDIRECTS = [
  ['liff-app.php', '/miniapp/'],
  ['liff-appointment.php', '/miniapp/appointments/'],
  ['liff-my-appointments.php', '/miniapp/appointments/'],
  ['liff-checkout.php', '/miniapp/cart/'],
  ['liff-member-card.php', '/miniapp/profile/'],
  ['liff-settings.php', '/miniapp/profile/'],
  ['liff-my-orders.php', '/miniapp/orders/'],
  ['liff-order-detail.php', '/miniapp/orders/'],
  ['liff-pharmacy-consult.php', '/miniapp/ai-chat/'],
  ['liff-symptom-assessment.php', '/miniapp/ai-chat/'],
  ['liff-points-history.php', '/miniapp/rewards/history/'],
  ['liff-points-rules.php', '/miniapp/rewards/'],
  ['liff-redeem-points.php', '/miniapp/rewards/'],
  ['liff-product-detail.php', '/miniapp/shop/'],
  ['liff-promotions.php', '/miniapp/shop/'],
  ['liff-shop.php', '/miniapp/shop/'],
  ['liff-shop-v3.php', '/miniapp/shop/'],
  ['liff-register.php', '/miniapp/register/'],
  ['liff-video-call.php', '/miniapp/video/'],
  ['liff-video-call-pro.php', '/miniapp/video/'],
  ['liff-wishlist.php', '/miniapp/wishlist/'],
  ['liff-consent.php', '/miniapp/'],
  ['liff-main.php', '/miniapp/'],
];
// liff-share.php has no Mini App equivalent yet — stays on the legacy LIFF
// SPA, so it is NOT in the redirect list above (matches .htaccess).

// ---------------------------------------------------------------------
// Minimal, dependency-free JSON Schema (draft-07 subset) validator. Only
// implements the keywords routes.schema.json actually uses: type, enum,
// const, pattern, required, properties, additionalProperties, items,
// minItems, oneOf. This is intentionally not a general-purpose validator —
// it exists so CI can validate routes.json against routes.schema.json
// without a network-installed dependency (ajv, etc).
// ---------------------------------------------------------------------
function validateAgainstSchema(value, schema, path = '$') {
  const errors = [];

  const typeOf = (v) => (Array.isArray(v) ? 'array' : v === null ? 'null' : typeof v);

  function check(v, s, p) {
    if (s.const !== undefined) {
      if (v !== s.const) errors.push(`${p}: expected const ${JSON.stringify(s.const)}, got ${JSON.stringify(v)}`);
      return;
    }
    if (s.enum) {
      if (!s.enum.includes(v)) errors.push(`${p}: ${JSON.stringify(v)} is not one of ${JSON.stringify(s.enum)}`);
      return;
    }
    if (s.oneOf) {
      const matches = s.oneOf.filter((sub) => {
        const subErrors = [];
        const before = errors.length;
        check(v, sub, p);
        const added = errors.splice(before);
        subErrors.push(...added);
        return subErrors.length === 0;
      });
      if (matches.length !== 1) {
        errors.push(`${p}: value must match exactly one schema in oneOf (matched ${matches.length})`);
      }
      return;
    }
    if (s.type) {
      const actual = typeOf(v);
      if (s.type !== actual) {
        errors.push(`${p}: expected type ${s.type}, got ${actual}`);
        return;
      }
    }
    if (s.type === 'string' && s.pattern) {
      if (!new RegExp(s.pattern).test(v)) {
        errors.push(`${p}: ${JSON.stringify(v)} does not match pattern ${s.pattern}`);
      }
    }
    if (s.type === 'array') {
      if (s.minItems !== undefined && v.length < s.minItems) {
        errors.push(`${p}: expected at least ${s.minItems} item(s), got ${v.length}`);
      }
      if (s.items) {
        v.forEach((item, i) => check(item, s.items, `${p}[${i}]`));
      }
    }
    if (s.type === 'object') {
      if (s.required) {
        for (const key of s.required) {
          if (!(key in v)) errors.push(`${p}: missing required property "${key}"`);
        }
      }
      if (s.properties) {
        for (const [key, subSchema] of Object.entries(s.properties)) {
          if (key in v) check(v[key], subSchema, `${p}.${key}`);
        }
      }
      if (s.additionalProperties === false && s.properties) {
        for (const key of Object.keys(v)) {
          if (!(key in s.properties)) errors.push(`${p}: unexpected property "${key}"`);
        }
      }
    }
  }

  check(value, schema, path);
  return errors;
}

function loadJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

function slugForPath(path) {
  if (path === '/') return 'root';
  return (
    path
      .replace(/^\//, '')
      .replace(/[^a-zA-Z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'root'
  );
}

function nginxLocationPattern(path) {
  // "/" must stay a plain prefix match: it is the strangler default that
  // catches every path not claimed by a more specific location. nginx's
  // longest-prefix-match already gives `location /miniapp` etc. priority
  // over the shorter `location /`, so an exact-match `= /` would proxy
  // only the literal URL "/" and drop the rest of the PHP surface.
  return path;
}

function renderRouteBlock(route) {
  const slug = slugForPath(route.path);
  const varName = `upstream_${slug}`;
  const hasMap = route.tenants !== 'all';
  const lines = [];

  if (hasMap) {
    lines.push(`    # route: ${route.path} -> ${route.upstream} (per-tenant canary: ${JSON.stringify(route.tenants)})`);
    if (route.note) lines.push(`    # note: ${route.note}`);
    lines.push(`    map $tenant_slug $${varName} {`);
    for (const slugName of route.tenants) {
      lines.push(`        ${slugName}    ${route.upstream};`);
    }
    lines.push(`        default    ${DEFAULT_UPSTREAM};`);
    lines.push('    }');
  }

  return { slug, varName, hasMap, mapLines: lines };
}

function renderLocation(route, varName) {
  const loc = nginxLocationPattern(route.path);
  const assign =
    route.tenants === 'all' ? `${route.upstream};` : `$${varName};`;

  return [
    `    location ${loc} {`,
    `        set $upstream_name ${assign}`,
    '        proxy_http_version 1.1;',
    '        proxy_set_header Host $host;',
    '        proxy_set_header X-Real-IP $remote_addr;',
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
    '        proxy_set_header X-Forwarded-Proto $scheme;',
    '        proxy_set_header Connection "";',
    '        # WebSocket upgrade support (harmless no-op for http upstreams).',
    '        proxy_set_header Upgrade $http_upgrade;',
    '        proxy_set_header Connection $connection_upgrade;',
    '',
    '        # Edge is authoritative for these — strip anything the upstream',
    '        # already set so we never emit duplicates.',
    '        proxy_hide_header X-Frame-Options;',
    '        proxy_hide_header X-Content-Type-Options;',
    '        proxy_hide_header X-XSS-Protection;',
    '',
    '        proxy_pass http://$svc_addr;',
    '    }',
  ].join('\n');
}

function render(routes, sourcePath) {
  const rootRoute = routes.find((r) => r.path === '/');
  if (!rootRoute) {
    throw new Error(
      'routes.json must contain exactly one entry with path "/" as the default catch-all (strangler default = php_backend, plan §1.5).'
    );
  }

  const generatedAt = new Date().toISOString();

  const upstreamMapLines = Object.entries(UPSTREAMS)
    .map(([name, addr]) => `    ${name.padEnd(13)} ${addr};`)
    .join('\n');

  const servedByMapLines = `    php_backend   php;\n    default       next;`;

  const routeBlocks = routes.map(renderRouteBlock);
  const mapSection = routeBlocks
    .filter((b) => b.hasMap)
    .map((b) => b.mapLines.join('\n'))
    .join('\n\n');

  const locationSection = routes
    .map((route) => {
      const block = routeBlocks.find((b) => b.slug === slugForPath(route.path));
      return renderLocation(route, block.varName);
    })
    .join('\n\n');

  const liffRedirectLines = LIFF_REDIRECTS.map(
    ([file, target]) =>
      `    location = /${file} { return 302 ${target}$is_args$args; }`
  ).join('\n');

  return `# =====================================================================
# AUTO-GENERATED by infra/nginx/generate-routes.mjs — DO NOT EDIT BY HAND
#
# Source manifest : ${sourcePath}
# Generated at    : ${generatedAt}
# Regenerate      : node infra/nginx/generate-routes.mjs
#
# Route flip / rollback mechanism (plan §1.5): edit one entry in
# routes.json, re-run the generator, reload nginx. Rolling a route back is
# reverting that one JSON line + regenerate + reload — nothing else in this
# file needs to change.
# =====================================================================

worker_processes auto;
events {
    worker_connections 1024;
}

http {
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;

    # Docker embedded DNS — lets the edge resolve upstream service names at
    # REQUEST time (via the $svc_addr variable below) instead of at config
    # load time. This is what lets nginx -t / reload succeed even if a
    # backend container is briefly down or not yet started, and is required
    # for the two-tier host×path canary maps to pick a different upstream
    # per request without needing separate static upstream{} blocks.
    resolver 127.0.0.11 valid=10s ipv6=off;

    # WebSocket Connection header helper (Upgrade -> "upgrade", else "close").
    map $http_upgrade $connection_upgrade {
        default upgrade;
        ''      close;
    }

    # --- Tier 0: upstream name -> service address. Edit topology here, not
    #     per-route. ---
    map $upstream_name $svc_addr {
${upstreamMapLines}
        default       ${UPSTREAMS[DEFAULT_UPSTREAM]};
    }

    # X-Served-By: php|next (plan §1.5: "ทุก response ติด X-Served-By: php|next")
    map $upstream_name $served_by {
${servedByMapLines}
    }

    # --- Tier 1: host -> tenant slug. Production hostnames are
    #     tenant-XXXX.re-ya.com (subdomain routing, ADR-001); anything else
    #     (root domain, reserved subdomains) has no tenant scope. ---
    map $host $tenant_slug {
        ~^tenant-(?<slug>[a-z0-9-]+)\\.re-ya\\.com$   $slug;
        default                                       "";
    }

    # --- Tier 2: per-route tenant -> upstream maps (only emitted for routes
    #     with an explicit per-tenant canary list; "all" routes are a plain
    #     \`set\` in their location block instead). ---
${mapSection}

    server {
        listen 80;
        server_name _;

        # --- Ported from repo-root .htaccess: security headers ---
        # (edge is authoritative — locations below proxy_hide_header the
        # same names so nothing duplicates them).
        add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options SAMEORIGIN always;
        add_header X-XSS-Protection "1; mode=block" always;
        add_header X-Served-By $served_by always;

        # --- Ported from .htaccess: block ad-hoc / one-off debug & data-fix
        #     scripts at web root (check_*, debug_*, fix_*, final_*, sync_*,
        #     get_*, verify_*). Root-anchored, matches the .htaccess intent
        #     exactly (api/sync_*.php etc. are NOT matched). ---
        location ~ ^/(check_|debug_|fix_|final_|sync_|get_|verify_)[^/]*\\.php$ {
            return 403;
        }

        # --- Ported from .htaccess: liff-*.php -> /miniapp/ redirects.
        #     302 (not 301) so clients don't cache the mapping while
        #     channels migrate — QSA-equivalent via $is_args$args. ---
${liffRedirectLines}
        # liff-share.php has no Mini App equivalent yet — intentionally
        # left off this list, same as .htaccess (stays on legacy /liff/).

        # Health check for the compose healthcheck / smoke tests.
        location = /__edge-health {
            access_log off;
            default_type text/plain;
            return 200 "edge-ok\\n";
        }

        # --- Route manifest locations (from routes.json) ---
${locationSection}
    }
}
`;
}

function main() {
  const args = process.argv.slice(2);
  const validateOnly = args.includes('--validate-only');
  const positional = args.filter((a) => !a.startsWith('--'));

  const routesPath = resolve(positional[0] ?? resolve(__dirname, 'routes.json'));
  const outputPath = resolve(
    positional[1] ?? resolve(__dirname, 'generated', 'strangler-edge.conf')
  );
  const schemaPath = resolve(__dirname, 'routes.schema.json');

  const schema = loadJson(schemaPath);
  const routes = loadJson(routesPath);

  const errors = validateAgainstSchema(routes, schema);
  if (errors.length > 0) {
    console.error(`✗ ${routesPath} failed validation against ${schemaPath}:`);
    for (const e of errors) console.error(`  - ${e}`);
    process.exit(1);
  }
  console.log(`✓ ${routesPath} validates against routes.schema.json (${routes.length} route(s))`);

  if (validateOnly) return;

  const conf = render(routes, 'infra/nginx/routes.json');
  mkdirSync(dirname(outputPath), { recursive: true });
  writeFileSync(outputPath, conf, 'utf8');
  console.log(`✓ wrote ${outputPath}`);
}

main();
