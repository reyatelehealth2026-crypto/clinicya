#!/usr/bin/env node
// infra/e2e/lib/rollback-drill-client.mjs
//
// mig-verify finding "rollback-untested" (Phase 3 batch 1 re-review): proves
// the ACTUAL client-side canary/flip mechanic documented in
// docs/runbooks/phase3-batch1-miniapp-api-parity.md §7/§8 — flipping
// `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES` for ONE real endpoint
// (`GET /api/health-profile.php`) to point at the real
// `/api/miniapp/health-profile` Next route, then reverting it — observed
// end-to-end against REAL, LIVE, already-running php:8.2-apache and Next
// standalone-server processes (started by infra/e2e/rollback-drill.mjs,
// same containers/build api-parity.mjs uses), never mocks.
//
// WHY THIS FILE EXISTS (not just more unit tests): php-bridge.test.ts and
// config.ts's own module doc already prove `resolveEndpointTarget()`/
// `buildPhpRequestUrl()` parse the override env var correctly IN ISOLATION
// (no network, no real servers) — that is necessary but not sufficient per
// the plan's §7 item 6 ("every phase must actually drill flipping back on
// canary before ramp"). This script closes that gap: it imports
// line-mini-app/src/lib/config.ts DIRECTLY AND UNMODIFIED (relative path,
// explicit .ts extension — config.ts is deliberately free of any `@/` alias
// import, same "importable by the project's plain node runner" property
// php-bridge.test.ts's own doc comment already relies on) and drives it with
// `node --experimental-strip-types` (type-stripping only, no transform) so
// the exact bytes of the real override-resolution logic run, not a
// reimplementation.
//
// The three network calls below reimplement php-bridge.ts's `phpGet()`
// body verbatim (fetch + parse-as-text-then-JSON, tolerating a non-JSON
// content-type header) rather than importing phpGet itself, ONLY because
// php-bridge.ts imports config.ts via the `@/lib/config` path alias that
// only Next's bundler resolves (documented limitation — see
// php-bridge.test.ts's own header comment, "these test buildPhpRequestUrl
// ... rather than phpGet/phpPost themselves" for the identical, pre-existing
// reason). `buildPhpRequestUrl` is exactly what phpGet calls to build the
// request URL — this file exercises 100% of phpGet's decision logic, only
// the trailing fetch-and-parse plumbing is a byte-for-byte copy instead of
// an import.
//
// Usage: node --experimental-strip-types rollback-drill-client.mjs
// Env (all required, set by infra/e2e/rollback-drill.mjs):
//   PHP_BASE_URL, NEXT_BASE_URL   — real running stacks, e.g. http://127.0.0.1:18092
//   DRILL_LINE_USER_ID            — pre-seeded, existing users row
//   DRILL_LINE_ACCOUNT_ID         — tenant-resolving line_account_id
// Prints one JSON line: {result, steps:[{step, url, status, headers, body}], ...}

import { buildPhpRequestUrl } from '../../../line-mini-app/src/lib/config.ts';

const PHP_BASE_URL = process.env.PHP_BASE_URL;
const NEXT_BASE_URL = process.env.NEXT_BASE_URL;
const LINE_USER_ID = process.env.DRILL_LINE_USER_ID;
const LINE_ACCOUNT_ID = Number(process.env.DRILL_LINE_ACCOUNT_ID);

if (!PHP_BASE_URL || !NEXT_BASE_URL || !LINE_USER_ID || !LINE_ACCOUNT_ID) {
  console.error('[rollback-drill-client] missing required env (PHP_BASE_URL/NEXT_BASE_URL/DRILL_LINE_USER_ID/DRILL_LINE_ACCOUNT_ID)');
  process.exit(2);
}

// Real target of this drill: the legacy path health-api.ts's getHealthProfile()
// calls, with the SAME default endpointKey phpGet computes when no explicit
// key is passed (`` `GET ${path}` ``) — see php-bridge.ts's phpGet doc comment.
const LEGACY_PATH = '/api/health-profile.php';
const ENDPOINT_KEY = `GET ${LEGACY_PATH}`;
const QUERY = { action: 'get', line_user_id: LINE_USER_ID, line_account_id: LINE_ACCOUNT_ID };

// Byte-for-byte copy of php-bridge.ts's parseResponse() — see module doc above.
async function parseResponse(response) {
  const text = await response.text();
  try {
    return { json: JSON.parse(text), text };
  } catch {
    const contentType = response.headers.get('content-type') || 'unknown';
    const snippet = text.slice(0, 200).replace(/\s+/g, ' ').trim();
    throw new Error(`PHP API returned non-JSON response (status=${response.status}, content-type=${contentType}): ${snippet}`);
  }
}

// Byte-for-byte copy of php-bridge.ts's phpGet(), minus the `@/lib/config`
// alias import (uses the relative import above instead) — see module doc.
async function phpGet(path, params, endpointKey) {
  const url = buildPhpRequestUrl(path, params, endpointKey);
  const response = await fetch(url, { method: 'GET', cache: 'no-store' });
  const parsed = await parseResponse(response);
  return {
    url,
    status: response.status,
    headers: {
      'access-control-allow-methods': response.headers.get('access-control-allow-methods'),
      'access-control-allow-headers': response.headers.get('access-control-allow-headers'),
      'content-type': response.headers.get('content-type'),
    },
    body: parsed.json,
  };
}

async function main() {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = PHP_BASE_URL;
  const steps = [];

  // ---- Step 1: BASELINE — no override configured (the out-of-the-box,
  // pre-Phase-3 state). Must resolve to the legacy PHP endpoint.
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES;
  const baseline = await phpGet(LEGACY_PATH, QUERY, ENDPOINT_KEY);
  steps.push({ step: 'baseline_no_override', target: 'php', ...baseline });

  // ---- Step 2: FLIP — mig-orchestrator's real canary move: configure
  // NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES to redirect ONLY this one
  // endpoint key at the real, live /api/miniapp/health-profile Next route.
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    [ENDPOINT_KEY]: { origin: NEXT_BASE_URL, path: '/api/miniapp/health-profile' },
  });
  const flipped = await phpGet(LEGACY_PATH, QUERY, ENDPOINT_KEY);
  steps.push({ step: 'flipped_to_next', target: 'next', ...flipped });

  // ---- Step 3: REVERT — mig-orchestrator's rollback move: unset the
  // override entirely. Must resolve BACK to the legacy PHP endpoint, and the
  // response must match step 1 exactly (proves revert restores the original
  // behaviour, not merely "doesn't crash").
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES;
  const reverted = await phpGet(LEGACY_PATH, QUERY, ENDPOINT_KEY);
  steps.push({ step: 'reverted_to_php', target: 'php', ...reverted });

  console.log(JSON.stringify({ steps }));
}

main().catch((err) => {
  console.error('[rollback-drill-client] FATAL', err && err.stack ? err.stack : err);
  process.exit(1);
});
